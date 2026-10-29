<?php
/**
 * End-to-end test of the fallback to the default model through expAiAssistant::step() (the panel's run path, which
 * Vector's runs share) against the mock server of tests/mock/server.php: a stopped local server (Managed=ollama on a closed loopback
 * port), a provider whose name does not resolve (the failure comes from the request itself), a model the user may
 * not use, a default the data policy refuses (the next allowed model answers), no model left (a message naming why),
 * the switch turned off, and a failure that is not a reason (shown as before). Checks the result's notice, the
 * exchange's notice and the usage rows (requested vs used model, reason). The settings exist only in memory;
 * nothing is started or stopped.
 *
 *   EXPAI_MOCK_URL=http://127.0.0.1:18368   the mock server (php -S 127.0.0.1:18368 extension/expai/tests/mock/server.php)
 *   EXPAI_TEST_NODE=<node id>               a folder for the test (its conversations are removed again)
 *   EXPAI_TEST_LOGIN=<login>                the user the test runs as (needs expai/write)
 *   php bin/php/ezexec.php extension/expai/tests/expaifallback_e2e_test.php
 *
 * Prints one line per check and ends with PASS or FAIL (exit code 0 or 1).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$mock = rtrim( (string) getenv( 'EXPAI_MOCK_URL' ), '/' );
$nodeID = (int) getenv( 'EXPAI_TEST_NODE' );
$login = (string) getenv( 'EXPAI_TEST_LOGIN' );
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
$script = eZScript::instance();
$node = eZContentObjectTreeNode::fetch( $nodeID );
$user = $login !== '' ? eZUser::fetchByName( $login ) : null;
if ( $mock === '' || !$node || !$user )
{
    echo "FAIL set EXPAI_MOCK_URL, EXPAI_TEST_NODE and EXPAI_TEST_LOGIN\n";
    $script->shutdown( 1 );
}
eZUser::setCurrentlyLoggedInUser( $user, (int) $user->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
$userID = (int) $user->attribute( 'contentobject_id' );
$run = 'f' . bin2hex( random_bytes( 4 ) );

// ------------------------------------------------------------------ the providers of this run, in memory only
$ini = eZINI::instance( 'expai.ini' );
$set = function ( $group, array $values ) use ( $ini ) { foreach ( $values as $name => $value ) $ini->setVariable( $group, $name, $value ); };
$set( 'AISettings', array( 'Enabled' => 'true', 'AllowUserModelChoice' => 'enabled', 'FallbackToDefaultModel' => 'enabled', 'HealthCacheSeconds' => '30' ) );
$set( 'ProviderSettings', array( 'Providers' => array( 'hfmock', 'ormock', 'deadlocal', 'deadremote' ), 'DefaultModel' => 'hfmock.e2e',
                                 'ProvidersWithoutPolicy' => array( 'hfmock', 'ormock', 'deadlocal', 'deadremote' ) ) );
$set( 'Limits', array( 'MaxRequestsPerTurn' => '' ) );
$set( 'DataPolicy', array( 'ZeroRetention' => 'disabled' ) );
$set( 'McpBridge', array( 'Enabled' => 'disabled' ) );
$set( 'McpSettings', array( 'Servers' => array() ) );
$set( 'Provider_hfmock', array( 'Type' => 'openai_chat', 'Flavor' => 'huggingface', 'Label' => 'Hugging Face (mock)', 'BaseURL' => $mock . '/hf/v1',
    'ApiKey' => 'hf_mock_not_a_key', 'Region' => 'us', 'Retention' => 'unknown', 'Timeout' => 5, 'Headers' => array( 'X-Expai-Test-Run: ' . $run ) ) );
$set( 'Provider_ormock', array( 'Type' => 'openai_chat', 'Flavor' => 'openrouter', 'Label' => 'OpenRouter (mock)', 'BaseURL' => $mock . '/openrouter/api/v1',
    'ApiKey' => 'sk-or-mock-not-a-key', 'Region' => 'us', 'Retention' => 'zdr', 'Timeout' => 5, 'Headers' => array( 'X-Expai-Test-Run: ' . $run ) ) );
// a local server that does not run (a closed loopback port) and a provider whose host does not resolve
$set( 'Provider_deadlocal', array( 'Type' => 'openai_chat', 'Flavor' => 'ollama', 'Label' => 'Local (stopped)', 'BaseURL' => 'http://127.0.0.1:1/v1',
    'ApiKeyHeader' => 'none', 'Region' => 'local', 'Retention' => 'none', 'Managed' => 'ollama', 'Timeout' => 5 ) );
$set( 'Provider_deadremote', array( 'Type' => 'openai_chat', 'Label' => 'Remote (no such host)', 'BaseURL' => 'https://expai-fallback-test.invalid/v1',
    'ApiKey' => 'not-a-key', 'Region' => 'us', 'Retention' => 'unknown', 'Timeout' => 5, 'ConnectTimeout' => 3, 'RetryOnServerError' => 'disabled' ) );
$model = function ( $alias, $provider, $id, array $extra = array() ) use ( $set )
{
    $set( 'Model_' . $alias, $extra + array( 'Provider' => $provider, 'Id' => $id, 'Label' => $alias, 'ToolUse' => 'native', 'WriteTools' => 'disabled', 'MaxOutput' => 400 ) );
};
$model( 'hfmock.e2e', 'hfmock', 'mock/e2e:novita' );
$model( 'hfmock.down', 'hfmock', 'mock/down' );
$model( 'ormock.e2e', 'ormock', 'mock/e2e' );
$model( 'deadlocal.m', 'deadlocal', 'qwen:test' );
$model( 'deadremote.m', 'deadremote', 'remote/test' );
expAiProviderHealth::reset();
$config = expAiConfig::instance();
$check( 'registry: default is the mock', $config->model(), 'hfmock.e2e' );

// a panel turn of this run with the model the request asks for (the user's choice, a thread's model): the test
// assistant names it instead of reading the user's stored preference, which the test leaves alone
class expAiFallbackTestAssistant extends expAiAssistant
{
    public $requested = '';

    protected function requestedAlias()
    {
        return $this->requested;
    }
}
$turn = function ( $alias, $message = 'Please answer in a single word.' ) use ( $nodeID, $userID )
{
    expAiConversation::removeFor( $userID, $nodeID );
    $assistant = new expAiFallbackTestAssistant( expAiConfig::instance() );
    $assistant->requested = $alias;
    $results = array( $assistant->step( $nodeID, $message ) );
    while ( end( $results )['status'] === 'continue' && count( $results ) < 6 )
        $results[] = $assistant->step( $nodeID, null );
    return array( $results, expAiConversation::fetchCurrent( $userID, $nodeID ) );
};
$rows = function ( expAiConversation $conversation )
{
    return eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), array( 'model_alias', 'requested_model_alias', 'fallback_reason', 'error_message' ),
        array( 'conversation_id' => (int) $conversation->attribute( 'id' ) ), array( 'id' => 'asc' ), null, false );
};
$error = function ( $alias ) use ( $turn )
{
    try
    {
        $turn( $alias );
        return '(no error)';
    }
    catch ( expAiException $e )
    {
        return $e->translatedMessage();
    }
};

// ------------------------------------------------------------------ 1. a stopped local model: the default answers
list( $results, $conversation ) = $turn( 'deadlocal.m' );
$last = end( $results );
$check( 'stopped: the turn is answered', $last['status'], 'done' );
$check( 'stopped: the default answered', $last['fallback'] ? array( $last['fallback']['requested'], $last['fallback']['used'], $last['fallback']['reason'] ) : null,
        array( 'deadlocal.m', 'hfmock.e2e', 'stopped' ) );
$check( 'stopped: the notice', $last['fallback']['notice'], 'hfmock.e2e answered instead: deadlocal.m is stopped.' );
$check( 'stopped: the exchange keeps the notice', isset( $last['exchange']['notice'] ) ? $last['exchange']['notice'] : '', $last['fallback']['notice'] );
$usage = $rows( $conversation );
$check( 'stopped: no request went to the stopped server', array_values( array_unique( array_map( function ( $r ) { return $r['model_alias']; }, $usage ) ) ), array( 'hfmock.e2e' ) );
$check( 'stopped: the usage row names the requested model and the reason', $usage ? array( $usage[0]['requested_model_alias'], $usage[0]['fallback_reason'] ) : null,
        array( 'deadlocal.m', 'stopped' ) );
$check( 'stopped: the picker shows it', expAiConfig::instance()->modelStates()['deadlocal.m']['state'], 'stopped' );

// ------------------------------------------------------------------ 2. the request itself fails at the connection
list( $results, $conversation ) = $turn( 'deadremote.m' );
$last = end( $results );
$check( 'unreachable: answered after the failed request', array( $last['status'], $last['fallback']['reason'], $last['fallback']['used'] ), array( 'done', 'unreachable', 'hfmock.e2e' ) );
$usage = $rows( $conversation );
$check( 'unreachable: the failed request is logged for the requested model', $usage ? array( $usage[0]['model_alias'], expAiModelFallback::isConnectionFailureText( $usage[0]['error_message'] ) ) : null,
        array( 'deadremote.m', true ) );
$check( 'unreachable: the answer is logged for the default with the requested model', isset( $usage[1] ) ? array( $usage[1]['model_alias'], $usage[1]['requested_model_alias'], $usage[1]['fallback_reason'] ) : null,
        array( 'hfmock.e2e', 'deadremote.m', 'unreachable' ) );
$check( 'unreachable: marked for the pickers', expAiProviderHealth::state( expAiConfig::instance(), 'deadremote' ), 'unreachable' );
expAiProviderHealth::reset();

// ------------------------------------------------------------------ 3. a model the user may not use
$set( 'AISettings', array( 'AllowUserModelChoice' => 'disabled' ) );
list( $results, $conversation ) = $turn( 'ormock.e2e' );
$last = end( $results );
$check( 'not allowed: the default answered', array( $last['status'], $last['fallback']['reason'], $last['fallback']['used'] ), array( 'done', 'not_allowed', 'hfmock.e2e' ) );
$check( 'not allowed: the notice', $last['fallback']['notice'], 'hfmock.e2e answered instead: ormock.e2e is not allowed.' );
$set( 'AISettings', array( 'AllowUserModelChoice' => 'enabled' ) );

// ------------------------------------------------------------------ 4. the default may not take the content: the next allowed model
$set( 'DataPolicy', array( 'ZeroRetention' => 'enabled' ) );
list( $results, $conversation ) = $turn( 'deadlocal.m' );
$last = end( $results );
$check( 'default refused: the next allowed model answered', array( $last['status'], $last['fallback']['used'], $last['fallback']['reason'] ), array( 'done', 'ormock.e2e', 'stopped' ) );
$check( 'default refused: logged for that model', array_values( array_unique( array_map( function ( $r ) { return $r['model_alias']; }, $rows( $conversation ) ) ) ), array( 'ormock.e2e' ) );

// ------------------------------------------------------------------ 5. none left: a message naming why
$set( 'Provider_ormock', array( 'Retention' => 'unknown' ) );
$check( 'none left: the message names the model and why', $error( 'deadlocal.m' ), 'deadlocal.m is stopped, and no other model you may use can answer for this content.' );
$set( 'Provider_ormock', array( 'Retention' => 'zdr' ) );
$set( 'DataPolicy', array( 'ZeroRetention' => 'disabled' ) );

// ------------------------------------------------------------------ 6. switched off: as before
$set( 'AISettings', array( 'FallbackToDefaultModel' => 'disabled' ) );
$check( 'switched off: the failure as before', $error( 'deadlocal.m' ), 'Exponential Intelligence could not be reached.' );
$set( 'AISettings', array( 'FallbackToDefaultModel' => 'enabled' ) );

// ------------------------------------------------------------------ 7. not a reason: a provider error that is not the connection
$message = $error( 'hfmock.down' );
$check( 'provider error: shown as before, no fallback', strpos( $message, 'answered instead' ) === false && $message !== '(no error)', true );

expAiConversation::removeFor( $userID, $nodeID );
echo "cleaned up: the conversations of this run (usage rows stay: they are the log)\n";
echo ( $failures ? 'FAIL' : 'PASS' ) . " $checks checks, $failures failure(s)\n";
$script->shutdown( $failures ? 1 : 0 );
