<?php
/**
 * expai/models (policy expai/model_manage): the providers and models of the registry (region, retention, key
 * present - never the key -, tool mode, write gate, the latest probe), warnings about misplaced keys, the MCP
 * settings, and per local server (Managed=ollama) its state with Start / Stop / Pull / Remove (written as requests
 * for the root-owned helper, never run by the web process). "Test prompt" (time to the first token, tokens per
 * second) and "Load model" run as jobs (expAiModelTest), never in this request.
 * The POST forms carry the form token (ezformtoken).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** @var eZModule $Module */
$Module = $Params['Module'];
$config = expAiConfig::instance();
$registry = $config->registry();
$notice = '';
$error = '';
$test = null;

if ( $Module->isCurrentAction( 'LocalAction' ) )
{
    $action = (string) $Module->actionParameter( 'Action' );
    $model = (string) $Module->actionParameter( 'Model' );
    $managed = expAiLocalModel::managedProviders( $config );
    try
    {
        if ( !$managed )
            throw new expAiException( 'No local model server is configured.' );
        $id = ( new expAiLocalModel( $config, $managed[0] ) )->request( $action, $model );
        $notice = ezpI18n::tr( 'extension/expai/models', 'The request %id was handed to the local model helper.', null, array( '%id' => substr( $id, 0, 8 ) ) );
        if ( class_exists( 'expAiAudit' ) )
            expAiAudit::modelAction( 'local_' . $action, $model );
    }
    catch ( expAiException $e )
    {
        $error = $e->translatedMessage();
        if ( class_exists( 'expAiAudit' ) )
            expAiAudit::modelAction( 'local_' . $action, $model, get_class( $e ) );
    }
}
// Test prompt and Load model (doc/local-models.md "Test prompt and Load model"): queued as a job of expai.model.test,
// never run in this request, which the web server would end long before a local model has loaded. expai_models.js
// does the same through expaimodel::test|load and follows the job; this is the form without JavaScript.
if ( $Module->isCurrentAction( 'TestPrompt' ) || $Module->isCurrentAction( 'LoadModel' ) )
{
    $action = $Module->isCurrentAction( 'LoadModel' ) ? 'load' : 'test';
    $alias = $registry->resolveAlias( (string) $Module->actionParameter( 'Model' ) );
    try
    {
        if ( $alias === '' )
            throw new expAiException( 'This model is not available.' );
        if ( expAiModelTest::queueAvailable() )
        {
            $started = expAiModelTest::start( $alias, $action, $config );
            $notice = expAiModelServerFunctions::notice( $config, $alias, $action, $started['duplicate'] );
            if ( class_exists( 'expAiAudit' ) && empty( $started['duplicate'] ) )
                expAiAudit::modelAction( $action, $alias );
        }
        elseif ( $action === 'test' )
        {
            // without the queue only a model that answers within the web budget is tested here
            expAiWebBudget::assertFits( $alias, 'test', $config );
            $result = expAiModelTest::run( $alias, 'test', array(), $config );
            $test = array( 'alias' => $alias, 'phase' => !empty( $result['ok'] ) ? 'done' : 'failed', 'action' => 'test', 'active' => false,
                           'finished' => time(), 'seconds' => isset( $result['total_ms'] ) ? (int) round( $result['total_ms'] / 1000 ) : 0,
                           'result' => $result, 'error' => empty( $result['ok'] ) ? ezpI18n::tr( 'extension/expai/error', $result['error'], null, $result['error_args'] ) : '' );
            if ( array_key_exists( 'cost_amount', $result ) )
                $test['result']['cost'] = $result['cost_amount'] !== null ? expAiCost::money( $result['cost_amount'] ) : '';
            foreach ( array( 'ttft', 'total', 'load' ) as $name )
                $test['result'][$name . '_s'] = isset( $result[$name . '_ms'] ) ? round( $result[$name . '_ms'] / 1000, 1 ) : null;
        }
        else
            throw new expAiException( 'The job queue is not available.' );
    }
    catch ( expAiException $e )
    {
        $error = $e->translatedMessage();
        if ( class_exists( 'expAiAudit' ) )
            expAiAudit::modelAction( $action, $alias !== '' ? $alias : (string) $Module->actionParameter( 'Model' ), get_class( $e ) );
    }
}

$providers = array();
$recentTests = expAiModelTest::recentJobs();
// #392 the exchange rates of the currencies the models price in
$exchange = class_exists( 'expAiExchangeRates' ) ? expAiExchangeRates::inUse( $config ) : array();
foreach ( array_keys( $registry->providers() ) as $id )
{
    $p = $registry->describeProvider( $id );
    foreach ( $p['models'] as $i => $m )
    {
        $live = expAiProbe::latest( $m['alias'], 'live' );
        $mock = expAiProbe::latest( $m['alias'], 'mock' );
        $p['models'][$i]['probe_live'] = $live ? array( 'pass_rate' => (int) $live->attribute( 'pass_rate' ), 'passed' => (int) $live->attribute( 'passed' ),
                                                        'tasks' => (int) $live->attribute( 'tasks' ), 'created' => (int) $live->attribute( 'created' ) ) : false;
        $p['models'][$i]['probe_mock'] = $mock ? array( 'pass_rate' => (int) $mock->attribute( 'pass_rate' ), 'created' => (int) $mock->attribute( 'created' ) ) : false;
        $p['models'][$i]['writes'] = expAiProbe::writeAllowed( $m['alias'], $config );
        $p['models'][$i]['write_refusal'] = expAiProbe::writeRefusal( $m['alias'], $config );
        $p['models'][$i]['cost'] = expAiCost::costClass( $m['alias'], $config );
        // #385 the context window of the model ([Model_<alias>] ContextTokens), abbreviated (1M)
        $p['models'][$i]['context'] = !empty( $m['context_tokens'] ) ? expAiCost::tokens( $m['context_tokens'] ) : '';
        // the newest test and load of the model (expai.model.test jobs), and whether a local model is loaded
        $p['models'][$i]['run'] = expAiModelTest::forModel( $m['alias'], $config, null, $recentTests );
        if ( $test && $test['alias'] === $m['alias'] )
            $p['models'][$i]['run']['test'] = $test;
    }
    // #392 the currency the provider's models price in and the rate they are converted with (ECB or the settings)
    $p['exchange'] = false;
    foreach ( $p['models'] as $m )
        foreach ( $exchange as $row )
            if ( !$p['exchange'] && isset( $m['price_currency'] ) && $m['price_currency'] === $row['currency'] )
                $p['exchange'] = array( 'currency' => $row['currency'], 'site' => $row['site'], 'date' => $row['date'], 'source' => $row['source'],
                                        'rate' => $row['rate'] !== null ? expAiCost::number( $row['rate'], 4 ) : '' );
    $providers[] = $p;
}
$local = array();
foreach ( expAiLocalModel::managedProviders( $config ) as $id )
{
    $model = new expAiLocalModel( $config, $id );
    $local[] = $model->status() + array( 'models' => $model->allowedModels() );
}
$mcpServers = array();
foreach ( $config->arr( 'McpSettings', 'Servers' ) as $id )
    $mcpServers[] = array( 'id' => $id, 'label' => (string) $config->get( 'McpServer_' . $id, 'Label', $id ),
                           'host' => (string) parse_url( (string) $config->get( 'McpServer_' . $id, 'URL', '' ), PHP_URL_HOST ),
                           'tools' => $config->arr( 'McpServer_' . $id, 'AllowedTools' ),
                           'region' => expAiProviderRegistry::cleanRegion( $config->get( 'McpServer_' . $id, 'Region', '' ) ),
                           'token' => trim( (string) $config->get( 'McpServer_' . $id, 'Token', '' ) ) !== '' );

$tpl = eZTemplate::factory();
$tpl->setVariable( 'providers', $providers );
$tpl->setVariable( 'default_alias', $registry->defaultAlias() );
$tpl->setVariable( 'key_problems', $registry->keyPlacementProblems() );
$tpl->setVariable( 'local', $local );
$tpl->setVariable( 'threshold', expAiProbe::threshold( $config ) );
$tpl->setVariable( 'bridge', array( 'enabled' => $config->flag( 'McpBridge', 'Enabled', false ), 'tools' => $config->arr( 'McpBridge', 'Tools' ),
                                    'available' => class_exists( 'expMcpContentTool' ) && expAiConfig::extensionActive( 'expmcp' ) ) );
$tpl->setVariable( 'mcp_servers', $mcpServers );
$tpl->setVariable( 'policy_active', ( new expAiDataPolicy( $config, array() ) )->active() );
$tpl->setVariable( 'notice', $notice );
$tpl->setVariable( 'error', $error );
$tpl->setVariable( 'poll_ms', 1000 * $config->int( 'ModelTest', 'PollSeconds', 3, 2, 30 ) );

$Result = array();
$Result['content'] = $tpl->fetch( 'design:expai/models.tpl' );
$Result['path'] = array( array( 'url' => 'expai/dashboard', 'text' => ezpI18n::tr( 'extension/expai/models', 'Exponential Intelligence' ) ),
                         array( 'url' => false, 'text' => ezpI18n::tr( 'extension/expai/models', 'Models' ) ) );
