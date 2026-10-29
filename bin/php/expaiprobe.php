#!/usr/bin/env php
<?php
/**
 * The probe of a model's tool calling (expAiProbe): scripted tasks with the panel's tool definitions, graded on the
 * calls the model makes. The tools are never run; nothing is read from or written to the content. The result is
 * stored in expai_probe_result; a live result of at least [ProbeSettings] WritePassRate percent, with every task of
 * [ProbeSettings] MandatoryTasks[] passed, lets a model with ToolUse=native and WriteTools=enabled write drafts.
 *
 *   php extension/expai/bin/php/expaiprobe.php --model=<alias>                  dry run: the tasks and the first request body, no call
 *   php extension/expai/bin/php/expaiprobe.php --model=<alias> --live           calls the model, stores the result (mode live)
 *   php extension/expai/bin/php/expaiprobe.php --model=<alias> --base-url=<url> calls a test server instead (mode mock;
 *                                                                               never counts for the write gate)
 *   --login=<user>   the user the requests are logged for (default: the anonymous user)
 *   --timeout=<s>    seconds per request instead of the provider's Timeout (a slow local model on a CPU)
 *   --list           the models of the registry with their latest probe
 *
 * -s <siteaccess> is mandatory: the script prints the siteaccess and its database (stderr) and does not run
 * where expai is not active (expAiScript).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array( 'description' => "The tool-calling probe of Exponential Intelligence models\n",
                                     'use-session' => false, 'use-modules' => true, 'use-extensions' => true ) );
$script->startup();
$options = $script->getOptions( '[model:][live][base-url:][login:][list][timeout:]', '', array(
    'model' => 'The model alias ([Model_<alias>] or anthropic.<id>)',
    'live' => 'Call the model and store the result',
    'base-url' => 'Call this test server instead of the provider (stored as mode mock)',
    'login' => 'The user the requests are logged for',
    'timeout' => 'Seconds per request instead of the provider\'s Timeout',
    'list' => 'List the models and their latest probe' ) );
expAiScript::requireSiteAccess( $script, $options );
$script->initialize();
expAiScript::announce( $script );

$config = expAiConfig::instance();
$registry = $config->registry();
if ( $options['login'] )
{
    $user = eZUser::fetchByName( $options['login'] );
    if ( !$user )
    {
        $cli->error( 'FAIL no user ' . $options['login'] );
        $script->shutdown( 1 );
    }
    eZUser::setCurrentlyLoggedInUser( $user, (int) $user->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
}

if ( $options['list'] )
{
    foreach ( $registry->models() as $alias => $model )
    {
        $live = expAiProbe::latest( $alias, 'live' );
        $cli->output( sprintf( '%-40s %-10s %-9s writes=%-3s live probe: %s', $alias, $model['provider'], $model['tool_use'],
            expAiProbe::writeAllowed( $alias, $config ) ? 'yes' : 'no', $live ? $live->attribute( 'pass_rate' ) . '% ' . date( 'Y-m-d H:i', (int) $live->attribute( 'created' ) ) : '-' ) );
    }
    $script->shutdown( 0 );
}

$alias = $registry->resolveAlias( (string) $options['model'] );
if ( $alias === '' )
{
    $cli->error( 'FAIL unknown model ' . $options['model'] . ' (see --list)' );
    $script->shutdown( 1 );
}
$model = $registry->getModel( $alias );
$nodeID = (int) $config->get( 'ProbeSettings', 'NodeID', '' );
if ( $nodeID <= 0 )
    $nodeID = (int) eZINI::instance( 'content.ini' )->variable( 'NodeSettings', 'RootNode' );
$node = eZContentObjectTreeNode::fetch( $nodeID );
$objectID = $node ? (int) $node->attribute( 'contentobject_id' ) : 1;
$class = strtolower( (string) $config->get( 'ProbeSettings', 'ClassIdentifier', 'folder' ) );
$probe = new expAiProbe( $config );

if ( !$options['live'] && !$options['base-url'] )
{
    $cli->output( "dry run for $alias ({$model['provider']}, {$model['id']}, tools {$model['tool_use']}); no request is sent" );
    foreach ( $probe->tasks( $nodeID, $objectID, $class ) as $task )
        $cli->output( sprintf( '  %-18s %s', $task['id'], $task['message'] ) );
    $provider = $registry->providerFor( $alias );
    if ( method_exists( $provider, 'buildPayload' ) )
    {
        $tools = $probe->toolsFor( $provider );
        $payload = $provider->buildPayload( array( 'system' => '(instructions)', 'messages' => array( array( 'role' => 'user', 'content' => 'What is the name of node ' . $nodeID . '?' ) ), 'tools' => $tools ) );
        $cli->output( 'first request body (tools shortened): ' . substr( json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 0, 600 ) . ' ...' );
    }
    $cli->output( 'PASS dry run (use --live to call the model)' );
    $script->shutdown( 0 );
}

$settings = $registry->settingsFor( $alias );
$mode = 'live';
if ( $options['base-url'] )
{
    $settings['BaseURL'] = (string) $options['base-url'];
    $settings['Endpoint'] = (string) $options['base-url'];
    $mode = 'mock';
}
if ( $options['timeout'] && (int) $options['timeout'] > 0 )
    $settings['Timeout'] = min( 3600, (int) $options['timeout'] );
$types = expAiProviderRegistry::TYPES;
if ( !isset( $types[$settings['Type']] ) )
{
    $cli->error( 'FAIL the probe supports the built-in provider types only' );
    $script->shutdown( 1 );
}
$providerClass = $types[$settings['Type']];
$inner = new $providerClass( $settings );
if ( $model['tool_use'] === 'prompted' )
    $inner = new expAiPromptedToolProvider( $inner );
$recorder = new expAiUsageRecorder( $inner, array( 'action' => 'probe' ) );
$cli->output( "probe of $alias ({$model['provider']}, {$model['id']}, tools {$model['tool_use']}, $mode) on node $nodeID, object $objectID, class $class" );
$start = microtime( true );
$result = $probe->run( $recorder, $nodeID, $objectID, strtolower( (string) $config->get( 'ProbeSettings', 'ClassIdentifier', 'folder' ) ), function ( $line ) use ( $cli ) { $cli->output( '  ' . $line ); } );
$row = expAiProbe::store( $alias, $model['provider'], $model['id'], $model['tool_use'], $mode, $result );
// the gate needs the pass rate and every mandatory task ([ProbeSettings] MandatoryTasks[]); say which one blocked it
$blocked = expAiProbe::failedMandatory( $result['results'], $config );
foreach ( $blocked as $id )
    $cli->output( '  mandatory task ' . $id . ' did not pass: the write gate stays closed' );
$cli->output( sprintf( '%s %d/%d tasks (%d%%) in %.1F s, stored as probe result %d (%s); write gate: %s',
    expAiProbe::gateOpens( $result['pass_rate'], $result['results'], $config ) ? 'PASS' : ( $blocked ? 'BLOCKED' : 'BELOW' ), $result['passed'], $result['tasks'], $result['pass_rate'],
    microtime( true ) - $start, (int) $row->attribute( 'id' ), $mode, expAiProbe::writeAllowed( $alias, $config ) ? 'may write' : 'read only (' . expAiProbe::writeRefusal( $alias, $config ) . ')' ) );
$script->shutdown( 0 );
