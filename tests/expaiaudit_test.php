<?php
/**
 * Test of what expai records in the audit trail (doc/audit-trail.md): through the real code paths (the usage recorder
 * with a stub provider, the veto, the master switch, the draft log) and the recorder's own calls, every event lands in
 * the channel "ai" with its object, target and fields, and no prompt, answer, request summary or provider error text
 * reaches the file; the chain of the channel is intact; the audit console's source of expai (expAiAuditSource) answers
 * the panel's links, an event's fields and links, the object links, the chart and the CSV columns of the channel "ai"
 * (exported into the throwaway directory), and the audit menu leaves "AI events" out for a user limited to other
 * channels.
 *
 * The audit is pointed at a throwaway directory (the kernel's test fixtures, var/tmp/audit-tests/), so the live audit
 * log is not touched; settings change only in memory; no request leaves the server. The usage and draft log rows the
 * test writes are removed at the end.
 *
 * Run from the installation root as a user with expai/write and expai/log (e.g. EXPAI_TEST_LOGIN=admin):
 *   php bin/php/ezexec.php extension/expai/tests/expaiaudit_test.php -s admin
 * It prints one line per check and ends with PASS or FAIL.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$failures = 0;
$checks = 0;
$check = function ( $label, $actual, $expected ) use ( &$failures, &$checks )
{
    ++$checks;
    $ok = $actual === $expected;
    if ( !$ok )
        ++$failures;
    echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $label . ( $ok ? '' : ' => ' . var_export( $actual, true ) . ' (expected ' . var_export( $expected, true ) . ')' ) . "\n";
};
$done = function () use ( &$failures, &$checks )
{
    echo "$checks checks, $failures failed\n";
    echo $failures ? "FAIL\n" : "PASS\n";
};

$root = getcwd();
$fixtures = $root . '/tests/tests/kernel/classes/audit/fixtures/expaudittestfixtures.php';
if ( !class_exists( 'expAudit' ) || !class_exists( 'expAuditSourceRegistry' ) || !is_file( $fixtures ) || !class_exists( 'expAiAudit' ) )
{
    $check( 'the kernel audit, its sources, its test fixtures and expAiAudit are there', false, true );
    $done();
    return;
}
require_once $fixtures;

$login = getenv( 'EXPAI_TEST_LOGIN' ) ?: 'admin';
$user = eZUser::fetchByName( $login );
if ( $user )
    $user->loginCurrent();
$userID = (int) eZUser::currentUserID();

// the audit in a throwaway directory, with expai's settings of settings/audit.ini.append.php
$dir = expAuditTestFixtures::setUp( 'expaiaudit', array(
    'AuditEventSettings/Branches' => array( 'expai' => 'expAiAuditBranch' ),
    'AuditChannelSettings/Channels' => array( 'content', 'access', 'system', 'commerce', 'read', 'ai' ),
    'AuditSourceSettings/Sources' => array( 'expai' => 'expAiAuditSource' ) ) );
expAuditSourceRegistry::reset();
$records = function ( $prefix = '' ) use ( $dir )
{
    expAudit::flushFinal();
    return array_values( array_filter( expAuditTestFixtures::events( $dir, 'ai' ), function ( $r ) use ( $prefix ) { return strpos( $r['name'], $prefix ) === 0; } ) );
};
$last = function ( $name ) use ( $records )
{
    $list = array_values( array_filter( $records( $name ), function ( $r ) use ( $name ) { return $r['name'] === $name; } ) );
    return $list ? end( $list ) : null;
};
$secret = 'T387-SECRET-' . bin2hex( random_bytes( 4 ) );

// settings in memory only, put back at the end
$ini = eZINI::instance( 'expai.ini' );
$saved = array();
$set = function ( $group, $name, $value ) use ( $ini, &$saved )
{
    $key = $group . '/' . $name;
    if ( !array_key_exists( $key, $saved ) )
        $saved[$key] = array( $ini->hasVariable( $group, $name ), $ini->hasVariable( $group, $name ) ? $ini->variable( $group, $name ) : null );
    $ini->setVariable( $group, $name, $value );
};
$restoreIni = function () use ( $ini, &$saved )
{
    foreach ( $saved as $key => $was )
    {
        list( $group, $name ) = explode( '/', $key, 2 );
        if ( $was[0] )
            $ini->setVariable( $group, $name, $was[1] );
        else
            $ini->removeSetting( $group, $name );
    }
};
$set( 'AISettings', 'Enabled', 'true' );
$set( 'AISettings', 'DisabledSiteAccesses', array() );
$set( 'RequestVeto', 'Rules', array() );
$firstUsageID = (int) eZDB::instance()->arrayQuery( 'SELECT MAX(id) AS m FROM expai_usage_log' )[0]['m'];
$firstDraftID = (int) eZDB::instance()->arrayQuery( 'SELECT MAX(id) AS m FROM expai_draft_log' )[0]['m'];

// a provider that never leaves the server; it fails when asked to
$stub = new class implements expAiProvider
{
    public $fail = '';
    public function send( array $request )
    {
        if ( $this->fail !== '' )
            throw new expAiException( 'The provider answered: %text', 'stub', 529, array( '%text' => $this->fail ) );
        return array( 'content' => array( array( 'type' => 'text', 'text' => 'answer ' . $request['marker'] ) ), 'stop_reason' => 'end_turn',
                      'model' => 'stub-model', 'usage' => array( 'input_tokens' => 120, 'output_tokens' => 30 ) );
    }
    public function modelName() { return 'stub-model'; }
    public function providerId() { return 'anthropic'; }
};
$send = function () use ( $stub, $secret )
{
    try
    {
        ( new expAiUsageRecorder( $stub, array( 'action' => 'panel', 'node_id' => 2 ) ) )
            ->send( array( 'marker' => $secret, 'messages' => array( array( 'role' => 'user', 'content' => 'prompt ' . $secret ) ) ) );
        return '';
    }
    catch ( expAiException $e )
    {
        return get_class( $e );
    }
};

// ---------------------------------------------------------------- the branch, the channel, the source
$check( 'the names are registered by the branch expai', expAuditTaxonomy::registry()['system.expai.request']['branch'], 'expai' );
$check( 'no problem with the branch', isset( expAuditTaxonomy::problems()['expai'] ), false );
$check( 'a request goes to the channel ai', expAuditTaxonomy::decide( 'system.expai.request' )['channel'], 'ai' );
$check( 'a draft goes to the channel ai', expAuditTaxonomy::decide( 'content.expai_draft.write' )['channel'], 'ai' );
$check( 'every name of the branch is on', count( array_filter( array_keys( ( new expAiAuditBranch() )->events() ), function ( $n ) { return !expAudit::isOn( $n ); } ) ), 0 );
$check( 'the source works', expAuditSourceRegistry::status()['expai']['problem'], '' );
$check( 'the source owns the channel ai', expAuditSourceRegistry::forChannel( 'ai' )[0], 'expai' );

// ---------------------------------------------------------------- a request
$check( 'the stub answers', $send(), '' );
$r = $last( 'system.expai.request' );
$usageID = (int) eZDB::instance()->arrayQuery( 'SELECT MAX(id) AS m FROM expai_usage_log' )[0]['m'];
$check( 'system.expai.request recorded', $r !== null, true );
$check( 'its object is the usage row, named after the model', array( $r['object']['type'], $r['object']['id'], $r['object']['name'] ), array( 'expai_request', $usageID, 'stub-model' ) );
$check( 'its target is the node', $r['target'], array( 'id' => 2, 'type' => 'node' ) );
$check( 'provider, model, purpose and tokens', array( $r['after']['provider'], $r['after']['model'], $r['after']['action'], $r['after']['usage']['in'], $r['after']['usage']['out'] ),
        array( 'anthropic', 'stub-model', 'panel', 120, 30 ) );
$check( 'the actor is recorded by the kernel (the fixtures fix it), no user id in the fields of an own request', array( isset( $r['actor']['user_id'] ), isset( $r['after']['user_id'] ) ), array( true, false ) );
$check( 'severity info, result success', array( $r['severity'], $r['result'] ), array( 'info', 'success' ) );

// ---------------------------------------------------------------- a failed request
$stub->fail = 'echo of the prompt ' . $secret;
$check( 'the stub fails', $send(), 'expAiException' );
$stub->fail = '';
$r = $last( 'system.expai.request.failed' );
$check( 'system.expai.request.failed recorded', $r !== null, true );
$check( 'failed, with the error class and the HTTP status only', array( $r['result'], $r['reason'], $r['error'] ), array( 'failed', 'error', array( 'message' => 'HTTP 529', 'ref' => 'expAiException' ) ) );
$check( 'severity warning', $r['severity'], 'warning' );

// ---------------------------------------------------------------- the veto and the master switch
$event = ezpEvent::getInstance();
$listener = $event->attach( expAiGate::EVENT, function ( $context ) { $context['veto'] = 'Refused by the audit test.'; return $context; } );
$check( 'the veto refuses', $send(), 'expAiVetoException' );
$event->detach( expAiGate::EVENT, $listener );
$r = $last( 'system.expai.request.refused' );
$check( 'system.expai.request.refused recorded for the veto', array( $r['result'], $r['reason'] ), array( 'refused', 'veto' ) );
$set( 'AISettings', 'Enabled', 'disabled' );
$check( 'the master switch refuses', $send(), 'expAiException' );
$set( 'AISettings', 'Enabled', 'true' );
$r = $last( 'system.expai.request.refused' );
$check( 'system.expai.request.refused recorded for the switch', array( $r['reason'], $r['after']['switched_off'], $r['object']['id'] ), array( 'switched_off', 'site', 0 ) );

// ---------------------------------------------------------------- a draft and its states
$draft = expAiDraftLog::record( array( 'contentobject_id' => 999999001, 'version' => 3, 'user_id' => $userID, 'node_id' => 2, 'action' => 'update',
                                       'source' => 'panel', 'model' => 'stub-model', 'provider' => 'anthropic', 'prompt_summary' => 'summary ' . $secret ) );
$r = $last( 'content.expai_draft.write' );
$check( 'content.expai_draft.write recorded', $r !== null, true );
$check( 'its object is the node with object and version', $r['object'], array( 'id' => 2, 'object_id' => 999999001, 'type' => 'node', 'version' => 3 ) );
$check( 'source, version, draft id', array( $r['after']['source'], $r['after']['version'], $r['after']['draft_id'] ), array( 'panel', 3, (int) $draft->attribute( 'id' ) ) );
$draft->setStatus( expAiDraftLog::STATUS_KEPT );
$r = $last( 'content.expai_draft.keep' );
$check( 'content.expai_draft.keep recorded with before and after', array( $r['before']['status'], $r['after']['status'] ), array( 'draft', 'kept' ) );
$draft->setStatus( expAiDraftLog::STATUS_PUBLISHED );
$check( 'content.expai_draft.publish recorded', $last( 'content.expai_draft.publish' )['severity'], 'notice' );
$count = count( $records( 'content.expai_draft.' ) );
$draft->setStatus( expAiDraftLog::STATUS_PUBLISHED );
$check( 'the same state again records nothing', count( $records( 'content.expai_draft.' ) ), $count );

// ---------------------------------------------------------------- the recorder's own calls
$job = new expQueueJob( array( 'id' => 0, 'uuid' => 't387-audit-test', 'type' => 'expai.request', 'group_id' => 5 ) );
expAiAudit::budgetHold( $job );
$check( 'system.expai.budget.hold', array( $last( 'system.expai.budget.hold' )['object']['id'], $last( 'system.expai.budget.hold' )['reason'] ), array( 't387-audit-test', 'budget' ) );
expAiAudit::budgetResume( array( 'resumed' => 2, 'still_held' => 1 ) );
$check( 'system.expai.budget.resume', $last( 'system.expai.budget.resume' )['after'], array( 'resumed' => 2, 'still_held' => 1 ) );
expAiAudit::mcpCall( 'docs', 'search', 2, 'success' );
expAiAudit::mcpCall( 'docs', 'search', 2, 'refused' );
$check( 'system.expai.mcp.call', $last( 'system.expai.mcp.call' )['object']['id'], 'docs:search' );
$check( 'system.expai.mcp.call.refused by the data policy', $last( 'system.expai.mcp.call.refused' )['reason'], 'data_policy' );
expAiAudit::modelAction( 'test', 'anthropic.haiku' );
$check( 'system.expai.model.action', array( $last( 'system.expai.model.action' )['object']['id'], $last( 'system.expai.model.action' )['after']['action'] ), array( 'anthropic.haiku', 'test' ) );
expAiAudit::chain( 'start', array( 41, 42 ), 2, 'article' );
$check( 'content.expai_chain.start with its runs', array( $last( 'content.expai_chain.start' )['object']['id'], $last( 'content.expai_chain.start' )['after']['runs'] ), array( 41, array( 41, 42 ) ) );
expAiAudit::policyMigration( array( array( 'id' => 7, 'role_id' => 2, 'role' => 'Editor', 'from' => 'expai/use', 'to' => 'expai/write' ) ) );
$check( 'system.expai.policy.migrate', $last( 'system.expai.policy.migrate' )['after']['policies'], 1 );

// ---------------------------------------------------------------- nothing of the content, an intact chain
$text = '';
foreach ( expAuditWriter::channelFiles( $dir . 'log', 'ai' ) as $f )
    $text .= file_get_contents( $dir . 'log/' . $f );
$check( 'no prompt, answer, summary or provider text in the channel', strpos( $text, $secret ), false );
$check( 'nothing of expai in the content channel', count( array_filter( expAuditTestFixtures::events( $dir, 'content' ), function ( $r ) { return strpos( $r['name'], 'expai' ) !== false; } ) ), 0 );
$check( 'the chain of the channel ai is intact', expAuditTestFixtures::verifier( $dir )->verifyChannel( 'ai' )['result'], 'intact' );

// ---------------------------------------------------------------- the console's source
$source = new expAiAuditSource();
$request = $last( 'system.expai.request' );
$detail = expAuditSourceRegistry::event( $request + array( 'channel' => 'ai' ) );
$check( 'the event card is labelled', $detail['label'], ezpI18n::tr( 'extension/expai/audit', 'Exponential Intelligence' ) );
$check( 'the event card names the model', in_array( 'stub-model', array_column( $detail['fields'], 'value' ), true ), true );
$check( 'the event card shows the tokens', in_array( ezpI18n::tr( 'extension/expai/audit', '%in in, %out out', null, array( '%in' => 120, '%out' => 30 ) ), array_column( $detail['fields'], 'value' ), true ), true );
$check( 'the event card links to the request in EI: Log', in_array( 'expai/log/(request)/' . $usageID, array_column( $detail['links'], 'url' ), true ), true );
$check( 'the Object line links to the request', expAuditConsole::linkFor( 'expai_request', (string) $usageID ), 'expai/log/(request)/' . $usageID );
$check( 'a queue job links to expqueue', expAuditConsole::linkFor( 'expqueue_job', 't387-audit-test' ), 'expqueue/job/t387-audit-test' );
$links = array_column( expAuditSourceRegistry::links( 'audit/console', array( 'user' => '14', 'object' => 'node:275', 'from' => '2026-10-01' ), null )[0]['links'], 'url' );
$check( 'the console links carry user, node and day into EI: Log', in_array( 'expai/log/(user)/14/(node)/275/(from)/2026-10-01', $links, true ), true );
$check( 'every link is a view path', count( array_filter( $links, function ( $l ) { return !expAuditSourceRegistry::isPath( $l ); } ) ), 0 );
$check( 'the chart has five series', count( expAuditSourceRegistry::charts( null )[0]['series'] ), 5 );
$check( 'a user limited to the content channel sees nothing of expai', expAuditSourceRegistry::readable( array( 'content' ) ), array() );
$check( 'nor its entry in the audit menu', in_array( 'expai_events', expAuditSourceRegistry::hiddenMenuLinks( 'Leftmenu_audit', array( 'content' ) ), true ), true );
$check( 'a user who may read the channel ai keeps it', expAuditSourceRegistry::hiddenMenuLinks( 'Leftmenu_audit', array( 'content', 'ai' ) ), array() );

// ---------------------------------------------------------------- the CSV export of the channel ai
$columns = array( 'provider', 'model', 'model_alias', 'region', 'purpose', 'lane', 'tokens_in', 'tokens_out', 'latency_ms', 'http_status',
                  'status', 'fallback_from', 'fallback_reason', 'cost_micros', 'currency' );
$check( 'the source declares its CSV columns', array_keys( expAuditSourceRegistry::exportColumns( 'ai' ) ), $columns );
$check( 'no columns for another channel', expAuditSourceRegistry::exportColumns( 'content' ), array() );
$csvFile = $dir . 'export-ai.csv';
( new expAuditExporter() )->export( $records( 'system.expai.' ), 'csv', $csvFile, array( 'channel' => 'ai' ) );
$csv = file_get_contents( $csvFile );
$lines = array_map( function ( $l ) { return str_getcsv( $l, ',', '"', '\\' ); }, array_values( array_filter( explode( "\n", $csv ), 'strlen' ) ) );
$header = $lines[0];
$check( 'the CSV of the channel ai has the generic columns, then the source\'s', array_slice( $header, -count( $columns ) ), $columns );
$check( 'and starts with the generic ones', array_slice( $header, 0, 4 ), array( 'time', 'id', 'channel', 'name' ) );
$row = null;
foreach ( array_slice( $lines, 1 ) as $l )
    if ( count( $l ) === count( $header ) && $l[array_search( 'id', $header, true )] === $request['id'] )
        $row = array_combine( $header, $l );
$check( 'the request\'s row carries model, purpose and tokens', $row ? array( $row['model'], $row['purpose'], $row['tokens_in'], $row['tokens_out'], $row['provider'] ) : null,
        array( 'stub-model', 'panel', '120', '30', 'anthropic' ) );
$check( 'no prompt or answer in the CSV', strpos( $csv, $secret ), false );
$check( 'no column for a text or a key', count( array_filter( $header, function ( $h ) { return preg_match( '/prompt|answer|text|key|token$|secret/', $h ); } ) ), 0 );
$plain = $dir . 'export-all.csv';
( new expAuditExporter() )->export( $records( 'system.expai.' ), 'csv', $plain, array() );
$check( 'without a channel filter the CSV has the generic columns only', count( str_getcsv( strtok( file_get_contents( $plain ), "\n" ), ',', '"', '\\' ) ), 18 );

// ---------------------------------------------------------------- cleanup
$restoreIni();
expAuditSourceRegistry::reset();
expAuditTestFixtures::tearDown();
$removed = 0;
foreach ( (array) eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), null, array( 'id' => array( '>', $firstUsageID ) ), null, null, true ) as $row )
    if ( (string) $row->attribute( 'model' ) === 'stub-model' )
    {
        $row->remove();
        ++$removed;
    }
foreach ( (array) eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null, array( 'id' => array( '>', $firstDraftID ), 'contentobject_id' => 999999001 ), null, null, true ) as $row )
{
    $row->remove();
    ++$removed;
}
echo "removed $removed usage and draft row(s) of the test; the audit files of the test are in " . substr( $dir, strlen( $root ) + 1 ) . "\n";
$done();
