<?php
/**
 * Test of "Test prompt" and "Load model" of expai/models as jobs (expAiModelTest, the job type expai.model.test,
 * doc/local-models.md "Test prompt and Load model") and of the web budget (expAiWebBudget): Ollama's own answer is
 * read into the time to the first token and the tokens per second; a test runs through the usage recorder and its
 * result carries the figures, the answer and the cost; a failure is a result, not an exception; the handler checks
 * its payload and writes the result; queueing twice while a job waits gives the same job (one per model and action);
 * a load is refused for a model that is not on a local server; the web budget refuses slow and local models in a web
 * request and lets them through elsewhere. Settings change only in memory; no request leaves the server (the
 * providers are stubs). The usage rows and the job the test writes are removed or cancelled at the end.
 *
 * Run from the installation root (EXPAI_TEST_LOGIN, default admin):
 *   php bin/php/ezexec.php extension/expai/tests/expaimodeltest_test.php -s admin
 * It prints one line per check and ends with PASS or FAIL (exit code 0 or 1).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** A provider of the registry (Type=custom) that answers without leaving the server */
class expAiModelTestStubProvider implements expAiProvider
{
    public static $calls = 0;
    public static $fail = false;
    protected $settings;
    public function __construct( array $settings = array() ) { $this->settings = $settings; }
    public function send( array $request )
    {
        ++self::$calls;
        if ( self::$fail )
            throw new expAiException( 'Exponential Intelligence could not be reached.', 'curl error 7' );
        usleep( 20000 );
        return array( 'content' => array( array( 'type' => 'text', 'text' => 'The sea is <b>wide</b>.' ) ), 'stop_reason' => 'end_turn', 'model' => 'stub-1',
                      'tokens' => array( 'input' => 30, 'output' => 8 ) );
    }
    public function modelName() { return 'stub-1'; }
    public function providerId() { return 'mtstub'; }
    public function modelAlias() { return 'mtstub.one'; }
}

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
$error = function ( $callable )
{
    try
    {
        $callable();
        return '';
    }
    catch ( expAiException $e )
    {
        return strtr( $e->getMessage(), $e->arguments() );
    }
};

$login = getenv( 'EXPAI_TEST_LOGIN' ) ?: 'admin';
$user = eZUser::fetchByName( $login );
if ( $user )
    $user->loginCurrent();

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
$providers = $ini->variable( 'ProviderSettings', 'Providers' );
$set( 'ProviderSettings', 'Providers', array_merge( is_array( $providers ) ? $providers : array(), array( 'mtstub' ) ) );
$set( 'Provider_mtstub', 'Type', 'custom' );
$set( 'Provider_mtstub', 'Class', 'expAiModelTestStubProvider' );
$set( 'Provider_mtstub', 'Region', 'eu' );
$set( 'Provider_mtstub', 'Retention', 'none' );
$set( 'Provider_mtstub', 'ApiKeyHeader', 'none' );
$set( 'Model_mtstub.one', 'Provider', 'mtstub' );
$set( 'Model_mtstub.one', 'Id', 'stub-1' );
$set( 'Model_mtstub.one', 'Label', 'Stub model' );
$set( 'Model_mtstub.one', 'InputPerMTok', '0' );
$set( 'Model_mtstub.one', 'OutputPerMTok', '0' );
// no worker is started by this test
$set( 'VectorSettings', 'KickWorker', 'disabled' );
$config = expAiConfig::instance();
$usageIDs = array();

// ---- Ollama's own answer ------------------------------------------------------------------------------------------
$answer = expAiOllamaNativeProvider::parseAnswer( array( 'model' => 'exp-qwen3.5:4b', 'message' => array( 'role' => 'assistant', 'content' => '<think>x</think>Waves roll in.' ),
    'done' => true, 'done_reason' => 'stop', 'total_duration' => 70500000000, 'load_duration' => 58000000000, 'prompt_eval_count' => 40,
    'prompt_eval_duration' => 2000000000, 'eval_count' => 20, 'eval_duration' => 10000000000 ) );
$check( 'ollama: text without the reasoning', $answer['content'][0]['text'], 'Waves roll in.' );
$check( 'ollama: tokens in and out', $answer['tokens'], array( 'input' => 40, 'output' => 20 ) );
$check( 'ollama: time to the first token = load + prompt', $answer['timings']['first_token'], 60000 );
$check( 'ollama: load and eval in ms', array( $answer['timings']['load'], $answer['timings']['eval'] ), array( 58000, 10000 ) );
$check( 'ollama: done_reason length is max_tokens', expAiOllamaNativeProvider::parseAnswer( array( 'message' => array( 'content' => 'a' ), 'done_reason' => 'length' ) )['stop_reason'], 'max_tokens' );
$check( 'ollama: an answer without message is refused', $error( function () { expAiOllamaNativeProvider::parseAnswer( array( 'error' => 'x' ) ); } ), 'The answer of Exponential Intelligence could not be read.' );

$http = new class
{
    public $calls = array();
    public $status = 200;
    public $body = '';
    public function request( $url, array $headers, $body, array $options = array() )
    {
        $this->calls[] = array( 'url' => $url, 'body' => json_decode( $body, true ) );
        return array( $this->status, $this->body, array() );
    }
};
$native = new expAiOllamaNativeProvider( array( 'BaseURL' => 'http://127.0.0.1:11434/v1', 'Model' => 'exp-qwen3.5:4b', 'ProviderId' => 'local', 'ModelAlias' => 'local.x',
                                                'Effort' => 'none', 'Capabilities' => array( 'reasoning_effort' ) ), $http );
$http->body = json_encode( array( 'model' => 'exp-qwen3.5:4b', 'message' => array( 'content' => 'Hi.' ), 'prompt_eval_count' => 5, 'eval_count' => 2,
                                  'load_duration' => 1000000, 'prompt_eval_duration' => 1000000, 'eval_duration' => 1000000 ) );
$sent = $native->send( array( 'system' => 'S', 'messages' => array( array( 'role' => 'user', 'content' => 'U' ) ), 'max_tokens' => 50 ) );
$check( 'native: POST to /api/chat of the root', $http->calls[0]['url'], 'http://127.0.0.1:11434/api/chat' );
$check( 'native: system first, no streaming, num_predict, thinking off', array( $http->calls[0]['body']['messages'][0]['role'], $http->calls[0]['body']['stream'],
        $http->calls[0]['body']['options']['num_predict'], $http->calls[0]['body']['think'] ), array( 'system', false, 50, false ) );
$check( 'native: the answer', $sent['content'][0]['text'], 'Hi.' );
$http->body = json_encode( array( 'done' => true, 'done_reason' => 'load', 'load_duration' => 61000000000 ) );
$loaded = $native->load( '45m' );
$check( 'load: /api/generate with an empty prompt and keep_alive', array( substr( $http->calls[1]['url'], -13 ), $http->calls[1]['body']['prompt'], $http->calls[1]['body']['keep_alive'] ),
        array( '/api/generate', '', '45m' ) );
$check( 'load: the load time', $loaded['load_ms'], 61000 );
$http->body = json_encode( array( 'done' => true, 'done_reason' => 'load' ) );
$check( 'load: no load_duration means it was already in memory', $native->load( '30m' )['load_ms'], 0 );
array_pop( $http->calls );
$native->load( '1; rm' );
$check( 'load: a strange keep_alive becomes 30m', $http->calls[2]['body']['keep_alive'], '30m' );
$http->status = 404;
$http->body = json_encode( array( 'error' => 'model "x" not found, try pulling it first' ) );
$check( 'native: an error of the server names its reason', $error( function () use ( $native ) { $native->send( array( 'messages' => array( array( 'role' => 'user', 'content' => 'U' ) ) ) ); } ),
        'The local model server refused the request: model "x" not found, try pulling it first' );
$check( 'native: only on the loopback address', $error( function () { new expAiOllamaNativeProvider( array( 'BaseURL' => 'http://192.0.2.1:11434/v1' ) ); } ),
        'Exponential Intelligence is not configured correctly.' );
$ps = array( 'models' => array( array( 'name' => 'exp-qwen3.5:4b', 'size' => 3640634899, 'expires_at' => '2026-10-12T00:12:44.522967115-07:00' ) ) );
$state = expAiOllamaNativeProvider::loadedIn( $ps, 'exp-qwen3.5:4b' );
$check( 'ps: loaded, until, size', array( $state['loaded'], $state['until'], $state['size_mb'] ), array( true, strtotime( '2026-10-12T07:12:44Z' ), 3472 ) );
$check( 'ps: another model is not loaded', expAiOllamaNativeProvider::loadedIn( $ps, 'other:1b' )['loaded'], false );

// ---- a test through the usage recorder ----------------------------------------------------------------------------
$check( 'stub model is in the registry', (bool) $config->registry()->getModel( 'mtstub.one' ), true );
$check( 'stub model is not managed', expAiModelTest::managed( 'mtstub.one', $config ), false );
$result = expAiModelTest::run( 'mtstub.one', 'test', array( 'user_id' => (int) eZUser::currentUserID() ), $config );
if ( !empty( $result['usage_log_id'] ) )
    $usageIDs[] = (int) $result['usage_log_id'];
$check( 'test: ok', $result['ok'], true );
$check( 'test: the answer text is stored as given (escaped when shown)', $result['text'], 'The sea is <b>wide</b>.' );
$check( 'test: tokens', array( $result['input'], $result['output'] ), array( 30, 8 ) );
$check( 'test: no first token time from a provider that answers in one piece', $result['ttft_ms'], null );
$check( 'test: tokens/s out from the total time', $result['out_tps'] > 0 && $result['total_ms'] >= 20, true );
$check( 'test: a usage row of action test', $result['usage_log_id'] > 0
        && (string) eZPersistentObject::fetchObject( expAiUsageLog::definition(), null, array( 'id' => $result['usage_log_id'] ) )->attribute( 'action' ) === 'test', true );
$check( 'test: cost known (price 0)', $result['cost_amount'], 0.0 );
expAiModelTestStubProvider::$fail = true;
$failed = expAiModelTest::run( 'mtstub.one', 'test', array(), $config );
expAiModelTestStubProvider::$fail = false;
$check( 'test: a failure is a result', array( $failed['ok'], $failed['error'] ), array( false, 'Exponential Intelligence could not be reached.' ) );
foreach ( eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), array( 'id' ), array( 'model_alias' => 'mtstub.one' ), null, null, false ) as $row )
    $usageIDs[] = (int) $row['id'];
$check( 'test: an unknown model', expAiModelTest::run( 'nope.none', 'test', array(), $config )['error'], 'This model is not available.' );
$check( 'load: refused for a model that is not on a local server', $error( function () use ( $config ) { expAiModelTest::start( 'mtstub.one', 'load', $config ); } ),
        'Only models of a local model server can be loaded.' );
$check( 'start: unknown action', $error( function () use ( $config ) { expAiModelTest::start( 'mtstub.one', 'drop', $config ); } ), 'Unknown action.' );

// ---- the handler -------------------------------------------------------------------------------------------------
$handler = new expAiModelTestHandler();
$check( 'handler: payload needs alias', $handler->validate( array( 'action' => 'test' ) ), 'alias is required' );
$check( 'handler: payload needs test or load', $handler->validate( array( 'alias' => 'a', 'action' => 'x' ) ), 'action is test or load' );
$check( 'handler: a good payload', $handler->validate( array( 'alias' => 'a', 'action' => 'load' ) ), null );
$job = new expQueueJob( array( 'id' => 0, 'uuid' => 'mtstub-test', 'type' => expAiModelTest::JOB_TYPE, 'state' => 'running', 'user_id' => (int) eZUser::currentUserID(),
                               'payload' => json_encode( array( 'alias' => 'mtstub.one', 'action' => 'test' ) ), 'timeout' => 60 ) );
$check( 'handler: describe', $handler->describe( $job ), 'Test prompt of mtstub.one' );
$outcome = $handler->run( $job, new expQueueContext( $job, 'mtstub-owner' ) );
$check( 'handler: done with the result', array( $outcome->outcome, $outcome->data['ok'], $outcome->data['text'] ), array( 'done', true, 'The sea is <b>wide</b>.' ) );
if ( !empty( $outcome->data['usage_log_id'] ) )
    $usageIDs[] = (int) $outcome->data['usage_log_id'];
$set( 'AISettings', 'Enabled', 'false' );
$check( 'handler: held while expai is switched off', $handler->run( $job, new expQueueContext( $job, 'mtstub-owner' ) )->outcome, 'hold' );
$set( 'AISettings', 'Enabled', 'true' );

// ---- one job per model and action --------------------------------------------------------------------------------
if ( expAiModelTest::queueAvailable() )
{
    // the dispatcher lock is held, so no worker takes the job while the test looks at it
    expQueue::withLock( function () use ( $config, $check )
    {
        $first = expAiModelTest::start( 'mtstub.one', 'test', $config );
        $second = expAiModelTest::start( 'mtstub.one', 'test', $config );
        $check( 'queue: the first click queues a job of the class interactive', array( $first['duplicate'], (string) $first['job']->attribute( 'class' ) ), array( false, 'interactive' ) );
        $check( 'queue: a second click while it waits is the same job', array( $second['duplicate'], (string) $second['job']->attribute( 'uuid' ) ),
                array( true, (string) $first['job']->attribute( 'uuid' ) ) );
        $check( 'queue: no worker started (KickWorker off)', $first['kicked'], false );
        $check( 'queue: no extra worker for a job that waits less than 10 s', expAiModelTest::nudge( array( $first['job'] ), $config ), false );
        $view = expAiModelTest::forModel( 'mtstub.one', $config );
        $check( 'queue: the page sees a waiting test', array( $view['active'], $view['test']['phase'], $view['loaded'] ), array( true, 'waiting', null ) );
        expQueue::cancel( $first['job'], 'expaimodeltest_test' );
        $view = expAiModelTest::forModel( 'mtstub.one', $config );
        $check( 'queue: a cancelled test is shown as failed', array( $view['active'], $view['test']['phase'] ), array( false, 'failed' ) );
        $third = expAiModelTest::start( 'mtstub.one', 'test', $config );
        $check( 'queue: after it ended a new job is queued', $third['duplicate'] === false && $third['job']->attribute( 'uuid' ) !== $first['job']->attribute( 'uuid' ), true );
        expQueue::cancel( $third['job'], 'expaimodeltest_test' );
    } );
}
else
    echo "skip  queue checks: the job queue is not available\n";

// ---- the web budget ----------------------------------------------------------------------------------------------
$local = '';
foreach ( $config->registry()->models() as $alias => $model )
    if ( expAiModelTest::managed( $alias, $config ) )
        $local = $alias;
$check( 'budget: a fast model fits', expAiWebBudget::reason( 'mtstub.one', $config, null ), '' );
if ( $local !== '' )
{
    $check( 'budget: a local model is slow', expAiWebBudget::reason( $local, $config, null ), 'slow' );
    $set( 'WebBudget', 'SlowModels', 'allow' );
    $check( 'budget: SlowModels=allow lets it through', expAiWebBudget::reason( $local, $config, null ), '' );
    $set( 'WebBudget', 'SlowModels', 'queue' );
}
$set( 'Model_mtstub.one', 'RunInBackground', 'always' );
$check( 'budget: RunInBackground=always', expAiWebBudget::reason( 'mtstub.one', $config, null ), 'setting' );
$check( 'budget: not refused outside a web request', $error( function () use ( $config ) { expAiWebBudget::assertFits( 'mtstub.one', 'field', $config ); } ), '' );
$_SERVER['REQUEST_METHOD'] = 'POST';
$check( 'budget: refused in a web request', strpos( $error( function () use ( $config ) { expAiWebBudget::assertFits( 'mtstub.one', 'field', $config ); } ),
                                                     'The model Stub model is too slow for field actions here' ), 0 );
unset( $_SERVER['REQUEST_METHOD'] );
$set( 'Model_mtstub.one', 'RunInBackground', '' );
$check( 'budget: measured slower than the budget', expAiWebBudget::reason( 'mtstub.one', $config, 40.0 ), 'measured' );
$check( 'budget: measured within the budget', expAiWebBudget::reason( 'mtstub.one', $config, 3.0 ), '' );

// ---- clean up ----------------------------------------------------------------------------------------------------
foreach ( array_unique( $usageIDs ) as $id )
    eZPersistentObject::removeObject( expAiUsageLog::definition(), array( 'id' => $id ) );
foreach ( $saved as $key => $was )
{
    list( $group, $name ) = explode( '/', $key, 2 );
    if ( $was[0] )
        $ini->setVariable( $group, $name, $was[1] );
    else
        $ini->removeSetting( $group, $name );
}
echo ( $failures ? 'FAIL' : 'PASS' ) . " ($checks checks, $failures failed)\n";
$script = eZScript::instance();
$script->shutdown( $failures ? 1 : 0 );
