#!/usr/bin/env php
<?php
/**
 * ./console ext:expai:translation-sync — Decides, queues or skips the update of translations after the source changed.
 *
 *   --object=<id|remote id>   the object (required)
 *   --languages=ger-DE,...    only these translations
 *   --dry-run                 decide and print only: nothing is stored or queued
 *   --approve                 queue the patch or full translation of --languages (as an editor's approval would)
 *   --mode=patch|full         with --approve: force the mode
 *   --skip                    mark --languages as up to date with the current source version
 *   --status                  print the stored decisions and job states
 *   --diff                    print the hunks of the source-language change per translation
 *   --provider=<id>           the translation service that must translate (llm, deepl, ...: a [TranslationProvider_<id>]
 *                             group); without it the rules of [TranslationSettings] choose (doc/translation-providers.md)
 *   --login=<user>            the user (drafts are written and jobs run as this user; required for --approve)
 *   --json                    the report as JSON
 *
 * Without --approve, --skip or --status it does what the workflow event does after a publish: decide for every
 * translation, store the decisions, and queue the patches that are below the thresholds of expai.ini [TranslationSync].
 * Every line names the translation service. When no translation service can take a translation (state no_provider or
 * blocked) the reason is printed and the script exits with 2.
 *
 * -s <siteaccess> is mandatory: the script prints the siteaccess and its database (stderr) and does not run
 * where expai is not active (expAiScript).
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 * @description Translation sync: decide, queue, skip or show the updates of translations
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array( 'description' => "Translation sync of Exponential Intelligence: decide, queue, skip or show the updates of translations\n",
                                     'use-session' => false, 'use-modules' => true, 'use-extensions' => true ) );
$script->startup();
$options = $script->getOptions( '[object:][languages:][dry-run][approve][mode:][skip][status][diff][provider:][login:][json]', '', array(
    'object' => 'the object: id or remote id', 'languages' => 'only these translations, separated by commas',
    'dry-run' => 'decide and print only', 'approve' => 'queue the update of --languages', 'mode' => 'with --approve: patch or full',
    'skip' => 'mark --languages as up to date', 'status' => 'print the stored decisions', 'diff' => 'print the hunks of the source change',
    'provider' => 'the translation service that must translate (llm, deepl, ...)', 'login' => 'the user', 'json' => 'print the report as JSON' ) );
expAiScript::requireSiteAccess( $script, $options );
$script->initialize();
expAiScript::announce( $script );

$fail = function ( $text ) use ( $cli, $script )
{
    $cli->error( 'FAIL ' . $text );
    $script->shutdown( 1 );
};
if ( $options['login'] )
{
    $user = eZUser::fetchByName( (string) $options['login'] );
    if ( !$user )
        $fail( 'no user ' . $options['login'] );
    eZUser::setCurrentlyLoggedInUser( $user, (int) $user->attribute( 'contentobject_id' ) );
}
$ref = (string) $options['object'];
if ( $ref === '' )
    $fail( '--object is required' );
$object = ctype_digit( $ref ) ? eZContentObject::fetch( (int) $ref ) : eZContentObject::fetchByRemoteID( $ref );
if ( !$object instanceof eZContentObject )
    $fail( 'no object ' . $ref );
$languages = $options['languages'] ? array_filter( array_map( 'trim', explode( ',', (string) $options['languages'] ) ) ) : array();
$sync = new expAiTranslationSync();
if ( !$sync->enabled() )
    $fail( 'the translation sync is disabled ([TranslationSync] Enabled)' );
$json = (bool) $options['json'];
$report = array( 'object_id' => (int) $object->attribute( 'id' ), 'name' => (string) $object->attribute( 'name' ),
                 'source' => $sync->sourceLanguage( $object ), 'current_version' => (int) $object->attribute( 'current_version' ), 'languages' => array() );
$provider = (string) $options['provider'];
if ( $provider !== '' && !preg_match( '/^[a-z0-9_]{1,30}$/', $provider ) )
    $fail( '--provider is the id of a translation service (a-z, 0-9, _)' );
$line = function ( $code, array $row )
{
    $unserved = isset( $row['state'] ) && in_array( $row['state'], array( 'no_provider', 'blocked' ), true );
    return sprintf( '%-8s %-11s %-6s ratio %5s  blocks %3d  units %3d  estimate %-10s by %-14s %s%s',
                    $code, isset( $row['state'] ) ? $row['state'] : '', isset( $row['decision'] ) ? $row['decision'] : '',
                    isset( $row['change_ratio'] ) ? round( 100 * (float) $row['change_ratio'] ) . '%' : '-',
                    isset( $row['blocks_changed'] ) ? (int) $row['blocks_changed'] : 0, isset( $row['units'] ) ? (int) $row['units'] : 0,
                    isset( $row['estimate_micros'] ) ? expAiBudget::money( (int) $row['estimate_micros'] ) : '',
                    !empty( $row['provider'] ) ? $row['provider'] : '-',
                    $unserved && !empty( $row['provider_reason'] ) ? $row['provider_reason'] : ( isset( $row['reason'] ) ? $row['reason'] : '' ),
                    !empty( $row['job_uuid'] ) ? '  job ' . $row['job_uuid'] : '' );
};

try
{
    if ( $options['approve'] || $options['skip'] )
    {
        if ( !$languages )
            $fail( '--languages is required with --approve and --skip' );
        if ( !eZUser::currentUser()->isRegistered() )
            $fail( '--login is required with --approve and --skip' );
        foreach ( $languages as $language )
            $report['languages'][$language] = $options['skip'] ? $sync->skip( $object, $language )
                                                                : $sync->approve( $object, $language, (string) $options['mode'], array( 'provider' => $provider ) );
    }
    elseif ( $options['status'] )
    {
        $status = $sync->status( $object );
        foreach ( $status['languages'] as $language => $row )
            if ( !$languages || in_array( $language, $languages, true ) )
                $report['languages'][$language] = $row;
    }
    else
        $report['languages'] = $sync->check( $object, array( 'languages' => $languages, 'dry_run' => (bool) $options['dry-run'], 'trigger' => 'cli', 'provider' => $provider ) );

    if ( $options['diff'] )
        foreach ( array_keys( $report['languages'] ) as $language )
        {
            $plan = $sync->plan( $object, $language, $provider !== '' ? array( 'provider' => $provider ) : array() );
            $report['diff'][$language] = $plan['diff']->toArray();
        }
}
catch ( expAiException $e )
{
    $fail( strtr( $e->getMessage(), $e->arguments() ) . ( $e->detail() !== '' ? ' (' . $e->detail() . ')' : '' ) );
}
catch ( expQueueException $e )
{
    $fail( $e->getMessage() );
}

if ( $json )
    $cli->output( json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
else
{
    $cli->output( 'Object ' . $report['object_id'] . ' "' . $report['name'] . '", source ' . $report['source'] . ' version ' . $report['current_version']
                  . ( $options['dry-run'] ? ' (dry run: nothing stored or queued)' : '' ) );
    foreach ( $report['languages'] as $language => $row )
        $cli->output( $line( $language, $row ) );
    if ( !$report['languages'] )
        $cli->output( 'No translations to keep in step.' );
    foreach ( isset( $report['diff'] ) ? $report['diff'] : array() as $language => $diff )
    {
        $cli->output( '' );
        $cli->output( 'Source changes for ' . $language . ' (v' . $diff['info']['version_a'] . ' -> v' . $diff['info']['version_b'] . '):' );
        foreach ( $diff['attributes'] as $identifier => $attribute )
            foreach ( $attribute['hunks'] as $hunk )
            {
                $cli->output( '  ' . $identifier . ' ' . $hunk['id'] . ' ' . $hunk['op'] );
                foreach ( array_filter( explode( "\n", $hunk['base_text'] ) ) as $text )
                    $cli->output( '    - ' . expAiText::cut( $text, 160 ) );
                foreach ( array_filter( explode( "\n", $hunk['prop_text'] ) ) as $text )
                    $cli->output( '    + ' . expAiText::cut( $text, 160 ) );
            }
    }
}
// no translation service could take a translation: reported above, and not a success
$unserved = 0;
foreach ( $report['languages'] as $row )
    if ( isset( $row['state'] ) && in_array( $row['state'], array( 'no_provider', 'blocked' ), true ) )
        ++$unserved;
if ( $unserved )
    $cli->error( 'FAIL ' . $unserved . ' translation(s) without a translation service' );
$script->shutdown( $unserved ? 2 : 0 );
