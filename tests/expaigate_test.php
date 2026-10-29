<?php
/**
 * Test of the master switch and the veto hook (#370 M2, doc/switch-and-veto.md): with [AISettings] Enabled off (and
 * with the current siteaccess in DisabledSiteAccesses[]) every entry point refuses before anything is sent (the
 * usage recorder that wraps every provider call, the queue, the ezjscore guards of the panel, the edit form and
 * Vector, the chain engine, the template fetch, the queued request handler holds its job); the veto refuses with its
 * reason through a rule of [RequestVeto] and through a listener of the ezpEvent filter expai/request/veto (also fail
 * closed), the refusal is logged in the usage log (error_class veto), a queued request meets it as a permanent
 * failure, and the cron part resumes jobs held by the switch. Settings change only in memory; no request leaves the
 * server (the provider is a stub that counts its calls). The usage rows the test writes are removed at the end.
 *
 * Run from the installation root as a user with expai/write (e.g. EXPAI_TEST_LOGIN=admin):
 *   php bin/php/ezexec.php extension/expai/tests/expaigate_test.php -s admin
 * It prints one line per check and ends with PASS or FAIL (exit code 0 or 1).
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
// the class and English message of the exception a callable throws, '' when none
$error = function ( $callable )
{
    try
    {
        $callable();
        return '';
    }
    catch ( expAiException $e )
    {
        return get_class( $e ) . ': ' . strtr( $e->getMessage(), $e->arguments() );
    }
};

$login = getenv( 'EXPAI_TEST_LOGIN' ) ?: 'admin';
$user = eZUser::fetchByName( $login );
if ( $user )
    $user->loginCurrent();
$userID = (int) eZUser::currentUserID();

// a provider that counts its calls and never leaves the server
$stub = new class implements expAiProvider
{
    public $calls = 0;
    public function send( array $request )
    {
        ++$this->calls;
        return array( 'content' => array( array( 'type' => 'text', 'text' => 'ok' ) ), 'stop_reason' => 'end_turn', 'model' => 'stub',
                      'usage' => array( 'input_tokens' => 1, 'output_tokens' => 1 ) );
    }
    public function modelName() { return 'stub-model'; }
    public function providerId() { return 'anthropic'; }
};

// settings in memory only: the live expai.ini instance of this process, put back at the end
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
$firstUsageID = (int) eZDB::instance()->arrayQuery( 'SELECT MAX(id) AS m FROM expai_usage_log' )[0]['m'];
$siteAccess = expAiGate::siteAccess();
$set( 'AISettings', 'Enabled', 'true' );
$set( 'AISettings', 'DisabledSiteAccesses', array() );
$set( 'RequestVeto', 'Rules', array() );
$config = expAiConfig::instance();
$guard = function ( $class ) { $m = new ReflectionMethod( $class, 'guard' ); $m->setAccessible( true ); return $m->invoke( null, false, true ); };
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['ezxform_token'] = class_exists( 'ezxFormToken' ) ? (string) ezxFormToken::getToken() : '';

// ---------------------------------------------------------------- the master switch
$check( 'on: switchedOff() is empty', expAiGate::switchedOff( $config ), '' );
$check( 'on: isEnabled()', $config->isEnabled(), true );
$check( 'on: fetch switched_on', ( new expAiGate() )->fetchSwitchedOn()['result'], true );
$check( 'on: the recorder sends', $error( function () use ( $stub ) { ( new expAiUsageRecorder( $stub, array( 'action' => 'test' ) ) )->send( array( 'messages' => array() ) ); } ), '' );
$check( 'on: the stub was called once', $stub->calls, 1 );

$set( 'AISettings', 'Enabled', 'disabled' );
$check( 'off: switchedOff() names the switch', expAiGate::switchedOff( $config ), 'Exponential Intelligence is switched off.' );
$check( 'off: isEnabled() is false', $config->isEnabled(), false );
$check( 'off: isConfigured() is false', $config->isConfigured(), false );
$check( 'off: fetch switched_on is false', ( new expAiGate() )->fetchSwitchedOn()['result'], false );
$check( 'off: the recorder refuses before sending', $error( function () use ( $stub ) { ( new expAiUsageRecorder( $stub, array( 'action' => 'panel' ) ) )->send( array( 'messages' => array() ) ); } ),
        'expAiException: Exponential Intelligence is switched off.' );
$check( 'off: the stub was not called', $stub->calls, 1 );
$check( 'off: the panel and edit form guard (ezjscore expai::*)', $error( function () use ( $guard ) { $guard( 'expAiServerFunctions' ); } ), 'expAiException: Exponential Intelligence is switched off.' );
if ( class_exists( 'expAiVectorServerFunctions' ) )
    $check( 'off: the Vector guard (ezjscore expaivector::*)', $error( function () use ( $guard ) { $guard( 'expAiVectorServerFunctions' ); } ), 'expAiException: Exponential Intelligence is switched off.' );
if ( class_exists( 'expAiChainEngine' ) && expAiQueue::available() )
    $check( 'off: a chain does not start', strpos( $error( function () { ( new expAiChainEngine() )->start( 'article', 2, 'test' ); } ), 'not configured correctly' ) !== false, true );
if ( expAiQueue::available() )
{
    $before = (int) eZDB::instance()->arrayQuery( "SELECT COUNT(*) AS c FROM expqueue_job WHERE type = 'expai.request'" )[0]['c'];
    $check( 'off: nothing is queued (translation sync, chains, bulk work)', $error( function () { expAiQueue::request( '', array( 'messages' => array( array( 'role' => 'user', 'content' => 'x' ) ), 'max_tokens' => 10 ), array( 'purpose' => 'test' ) ); } ),
            'expAiException: Exponential Intelligence is switched off.' );
    $after = (int) eZDB::instance()->arrayQuery( "SELECT COUNT(*) AS c FROM expqueue_job WHERE type = 'expai.request'" )[0]['c'];
    $check( 'off: no job was added', $after, $before );
}

// a queued request already waiting: held with the reason (job id 0: no row, no checkpoint)
$alias = $config->registry()->defaultAlias();
$model = $config->registry()->getModel( $alias );
$lane = $model ? expAiLane::fetch( $model['provider'], 'standard', $config ) : null;
if ( $lane && $lane->usable() && class_exists( 'expQueueJob' ) )
{
    $job = new expQueueJob( array( 'id' => 0, 'uuid' => 't370m-test', 'user_id' => $userID, 'group_id' => 0, 'cost_reserved_micros' => 0, 'cost_actual_micros' => 0,
                                   'payload' => json_encode( array( 'alias' => $alias, 'lane' => 'standard', 'purpose' => 'test', 'node_id' => 2,
                                                                    'request' => array( 'messages' => array( array( 'role' => 'user', 'content' => 'x' ) ), 'max_tokens' => 10 ) ) ) ) );
    $result = ( new expAiRequestHandler() )->run( $job, new expQueueContext( $job, 't370m' ) );
    $check( 'off: a queued request is held', $result->outcome, expQueueResult::HOLD );
    $check( 'off: the hold names the switch', $result->message, expAiGate::HELD_PREFIX . 'Exponential Intelligence is switched off.' );
}
else
    echo "skip  the queued request checks (no usable standard lane or no expqueue)\n";

// on, but off on this siteaccess
$set( 'AISettings', 'Enabled', 'true' );
if ( $siteAccess !== '' )
{
    $set( 'AISettings', 'DisabledSiteAccesses', array( $siteAccess ) );
    $check( 'siteaccess: switchedOff() names the site', expAiGate::switchedOff( $config ), 'Exponential Intelligence is switched off on this site.' );
    $check( 'siteaccess: isEnabled() is false', $config->isEnabled(), false );
    $check( 'siteaccess: the recorder refuses', $error( function () use ( $stub ) { ( new expAiUsageRecorder( $stub, array() ) )->send( array( 'messages' => array() ) ); } ),
            'expAiException: Exponential Intelligence is switched off on this site.' );
    $check( 'siteaccess: another siteaccess is on', expAiGate::switchedOff( $config, 'some_other_siteaccess' ), '' );
    $set( 'AISettings', 'DisabledSiteAccesses', array( 'some_other_siteaccess' ) );
    $check( 'siteaccess: a request queued from a switched-off siteaccess', expAiGate::switchedOff( $config, 'some_other_siteaccess' ), 'Exponential Intelligence is switched off on this site.' );
    $check( 'siteaccess: the current one is on', $config->isEnabled(), true );
    $set( 'AISettings', 'DisabledSiteAccesses', array() );
}
else
    echo "skip  the siteaccess checks (no current siteaccess)\n";

// ---------------------------------------------------------------- the veto: rules
$node = eZContentObjectTreeNode::fetch( 2 );
$section = (int) $node->object()->attribute( 'section_id' );
$set( 'RequestVeto', 'Rules', array( 't370m_section' ) );
$set( 'RequestVeto_t370m_section', 'Sections', array( (string) $section ) );
$set( 'RequestVeto_t370m_section', 'Reason', 'No AI in this section (test).' );
$context = expAiGate::context( array( 'node_id' => 2, 'provider' => 'anthropic', 'purpose' => 'panel' ) );
$check( 'context: class of node 2', $context['class_identifier'], (string) $node->object()->attribute( 'class_identifier' ) );
$check( 'context: section of node 2', $context['section_id'], $section );
$check( 'context: the user', $context['user'] instanceof eZUser && (int) $context['user']->attribute( 'contentobject_id' ) === $userID, true );
$check( 'context: the data class is known', is_array( $context['data_class'] ) && array_key_exists( 'zero_retention', $context['data_class'] ), true );
$check( 'rule: a request on node 2 is refused with the reason', $error( function () { expAiGate::check( array( 'node_id' => 2, 'provider' => 'anthropic', 'purpose' => 'panel' ) ); } ),
        'expAiVetoException: This request was refused: No AI in this section (test).' );
$check( 'rule: a request without content is not refused by a section rule', $error( function () { expAiGate::check( array( 'provider' => 'anthropic', 'purpose' => 'test' ) ); } ), '' );
$calls = $stub->calls;
$check( 'rule: the recorder refuses with the reason', $error( function () use ( $stub ) { ( new expAiUsageRecorder( $stub, array( 'node_id' => 2, 'action' => 'rewrite' ) ) )->send( array( 'messages' => array() ) ); } ),
        'expAiVetoException: This request was refused: No AI in this section (test).' );
$check( 'rule: the stub was not called', $stub->calls, $calls );
$logged = eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), null, array( 'id' => array( '>', $firstUsageID ), 'error_class' => 'veto' ), array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => 1 ), true );
$check( 'rule: the refusal is logged (error_class veto)', $logged ? (string) $logged[0]->attribute( 'error_message' ) : '', 'refused (rule:t370m_section): No AI in this section (test).' );
$check( 'rule: the log row names the purpose', $logged ? (string) $logged[0]->attribute( 'action' ) : '', 'rewrite' );

$set( 'RequestVeto_t370m_section', 'Purposes', array( 'queue.*' ) );
$check( 'rule: Purposes[] queue.* lets the panel through', $error( function () { expAiGate::check( array( 'node_id' => 2, 'provider' => 'anthropic', 'purpose' => 'panel' ) ); } ), '' );
$check( 'rule: Purposes[] queue.* refuses a queued purpose', strpos( $error( function () { expAiGate::check( array( 'node_id' => 2, 'provider' => 'anthropic', 'purpose' => 'queue.translation_sync' ) ); } ), 'expAiVetoException' ) === 0, true );
if ( expAiQueue::available() )
    $check( 'rule: the queue refuses to add a refused request', $error( function () { expAiQueue::request( '', array( 'messages' => array( array( 'role' => 'user', 'content' => 'x' ) ), 'max_tokens' => 10 ), array( 'purpose' => 'test', 'node_id' => 2 ) ); } ),
            'expAiVetoException: This request was refused: No AI in this section (test).' );
$set( 'RequestVeto_t370m_section', 'Purposes', array() );
if ( isset( $job ) )
{
    $result = ( new expAiRequestHandler() )->run( $job, new expQueueContext( $job, 't370m' ) );
    $check( 'rule: a queued request that meets a veto fails for good', array( $result->outcome, $result->errorClass ), array( expQueueResult::FAIL, 'veto' ) );
}
$set( 'RequestVeto_t370m_section', 'Sections', array( '999999' ) );
$check( 'rule: another section is not refused', $error( function () { expAiGate::check( array( 'node_id' => 2, 'provider' => 'anthropic', 'purpose' => 'panel' ) ); } ), '' );
$set( 'RequestVeto_t370m_section', 'Sections', array() );
$set( 'RequestVeto_t370m_section', 'Reason', 'Maintenance until 10:00 (test).' );
$check( 'rule: a rule without conditions refuses everything', $error( function () { expAiGate::check( array( 'purpose' => 'test' ) ); } ), 'expAiVetoException: This request was refused: Maintenance until 10:00 (test).' );
$set( 'RequestVeto_t370m_section', 'Providers', array( 'some_local_provider' ) );
$check( 'rule: Providers[] limits the rule', $error( function () { expAiGate::check( array( 'provider' => 'anthropic', 'purpose' => 'test' ) ); } ), '' );
$set( 'RequestVeto', 'Rules', array() );

// ---------------------------------------------------------------- the veto: the ezpEvent filter expai/request/veto
$seen = array();
$event = ezpEvent::getInstance();
$id = $event->attach( expAiGate::EVENT, function ( $context ) use ( &$seen )
{
    $seen = $context;
    if ( $context['purpose'] === 'compliance-test' )
        $context['veto'] = 'Blocked by the compliance listener (test).';
    return $context;
} );
$check( 'listener: an allowed request passes', $error( function () { expAiGate::check( array( 'node_id' => 2, 'provider' => 'anthropic', 'model' => 'm', 'model_alias' => 'anthropic.m', 'purpose' => 'panel' ) ); } ), '' );
$check( 'listener: it receives user, node, model, provider, purpose and data class',
        array( isset( $seen['user'] ), $seen['node_id'], $seen['model'], $seen['provider'], $seen['purpose'], isset( $seen['data_class'] ), $seen['veto'] ),
        array( true, 2, 'm', 'anthropic', 'panel', true, '' ) );
$check( 'listener: it refuses with its reason', $error( function () { expAiGate::check( array( 'node_id' => 2, 'provider' => 'anthropic', 'purpose' => 'compliance-test' ) ); } ),
        'expAiVetoException: This request was refused: Blocked by the compliance listener (test).' );
$event->detach( expAiGate::EVENT, $id );
$id = $event->attach( expAiGate::EVENT, function ( $context ) { return null; } );
$check( 'listener: one that returns no context refuses (fail closed)', $error( function () { expAiGate::check( array( 'purpose' => 'panel' ) ); } ),
        'expAiVetoException: This request was refused: A check of this site could not decide on this request.' );
$event->detach( expAiGate::EVENT, $id );
$check( 'listener: detached, the request passes', $error( function () { expAiGate::check( array( 'purpose' => 'panel' ) ); } ), '' );

// ---------------------------------------------------------------- the cron part resumes jobs held by the switch
$check( 'resumeHeld() answers (no test job is held)', array_keys( expAiGate::resumeHeld( $config, 1 ) ), array( 'resumed', 'still_held' ) );

// ---------------------------------------------------------------- cleanup: the settings, the usage rows of the test
$restoreIni();
$removed = 0;
foreach ( eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), null, array( 'id' => array( '>', $firstUsageID ), 'user_id' => $userID ), null, null, true ) as $row )
{
    if ( in_array( (string) $row->attribute( 'model' ), array( 'stub-model', 'stub' ), true )
         || ( (string) $row->attribute( 'error_class' ) === 'veto' && preg_match( '/\(test\)|listener returned no context/', (string) $row->attribute( 'error_message' ) ) ) )
    {
        $row->remove();
        ++$removed;
    }
}
echo "removed $removed usage row(s) of the test\n";
echo "$checks checks, $failures failed\n";
echo $failures ? "FAIL\n" : "PASS\n";
eZScript::instance()->shutdown( $failures ? 1 : 0 );
