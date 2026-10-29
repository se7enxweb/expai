<?php
/**
 * expAiModelTest (the Test and Load buttons of the Models page) and expAiModelServerFunctions (their ezjscore calls):
 * queued jobs and their description, the nudge of a waiting job, the state of a managed model, the test request and
 * the load of a local model against a web server that answers like Ollama. Workers are never started: KickWorker is
 * off, and where the start itself is tested the "PHP binary" is /bin/true. The jobs and usage rows of the test are
 * removed.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiProviderTestStubProvider implements expAiProvider
{
    public $answer;
    public $requests = array();
    public $alias;

    public function __construct( array $answer, $alias = 'xtem.cloud' )
    {
        $this->answer = $answer;
        $this->alias = $alias;
    }

    public function send( array $request )
    {
        $this->requests[] = $request;
        return $this->answer;
    }

    public function modelName()
    {
        return 'stub-model';
    }

    public function providerId()
    {
        return 'xtem';
    }

    public function modelAlias()
    {
        return $this->alias;
    }
}

class expAiModelTestTest extends expAiProviderTestCase
{
    /** @var string */
    private $www = '';

    private function siteModels( $localUrl = 'http://127.0.0.1:1' )
    {
        return $this->siteProviders(
            array( 'xtem' => array( 'Type' => 'openai_chat', 'BaseURL' => 'http://127.0.0.1:1/v1', 'ApiKey' => 'test-not-a-key', 'Region' => 'eu' ),
                   'xteml' => array( 'Type' => 'openai_chat', 'Flavor' => 'ollama', 'Managed' => 'ollama', 'BaseURL' => $localUrl . '/v1', 'KeyOptional' => 'true', 'Region' => 'local' ) ),
            array( 'xtem.cloud' => array( 'Provider' => 'xtem', 'Id' => 'stub-model', 'Label' => 'Cloud XTE' ),
                   'xteml.qwen' => array( 'Provider' => 'xteml', 'Id' => 'qwen3:4b', 'Label' => 'Local XTE' ) ),
            'xtem.cloud',
            array( 'VectorSettings' => array( 'KickWorker' => 'false' ), 'ModelTest' => array( 'ShowResultMinutes' => 60, 'LoadKeepAlive' => '10m' ) ) );
    }

    /** removes the queue jobs of the test's aliases */
    private function removeJobs()
    {
        $this->cleanup( static function ()
        {
            $db = eZDB::instance();
            foreach ( expQueue::listJobs( array( 'type' => expAiModelTest::JOB_TYPE ), 0, 200 ) as $job )
            {
                $p = $job->payloadArray();
                if ( isset( $p['alias'] ) && strpos( (string) $p['alias'], 'xtem' ) === 0 )
                {
                    $db->query( 'DELETE FROM expqueue_event WHERE job_id = ' . (int) $job->attribute( 'id' ) );
                    $db->query( 'DELETE FROM expqueue_job WHERE id = ' . (int) $job->attribute( 'id' ) );
                }
            }
        } );
    }

    private static function job( array $row, array $payload = array( 'alias' => 'xtem.cloud', 'action' => 'test' ), array $result = array() )
    {
        return new expQueueJob( $row + array( 'id' => 0, 'uuid' => '00000000-0000-4000-8000-000000000386', 'type' => expAiModelTest::JOB_TYPE, 'state' => 'ready',
                                              'payload' => json_encode( $payload ), 'result' => $result ? json_encode( $result ) : '', 'created' => time(), 'finished' => 0,
                                              'last_error' => '' ) );
    }

    private function ollama()
    {
        $www = expAiTestMockServer::tmp( 'xte-ollama' );
        $this->cleanup( static function () use ( $www ) { expAiTestMockServer::removeTree( $www ); } );
        foreach ( array( 'generate', 'ps', 'version' ) as $dir )
            mkdir( $www . '/api/' . $dir, 0775, true );
        file_put_contents( $www . '/api/generate/index.php', '<?php header( "Content-Type: application/json" ); echo json_encode( array( "load_duration" => 2500000000, "body" => json_decode( file_get_contents( "php://input" ), true ) ) );' );
        file_put_contents( $www . '/api/ps/index.php', '<?php echo json_encode( array( "models" => array( array( "name" => "qwen3:4b", "size" => 1048576, "expires_at" => "2030-01-01T00:00:00Z" ) ) ) );' );
        $server = expAiTestMockServer::start( '', $www );
        return $server['url'];
    }

    // ------------------------------------------------------------------ start, duplicate, refusals

    public function testStartQueuesOneJobAndASecondClickIsTheSameJob(): void
    {
        $this->requireDatabase();
        $this->asUser( 'admin' );
        $config = $this->siteModels();
        $this->removeJobs();
        if ( !expAiModelTest::queueAvailable() )
            $this->markTestSkipped( 'the job queue is not available' );

        $first = expAiModelTest::start( 'xtem.cloud', 'test', $config );
        $this->assertFalse( $first['duplicate'] );
        $this->assertFalse( $first['kicked'], 'KickWorker is off' );
        $this->assertSame( array( 'alias' => 'xtem.cloud', 'action' => 'test', 'siteaccess' => expAiGate::siteAccess() ), $first['job']->payloadArray() );
        $this->assertSame( 'interactive', (string) $first['job']->attribute( 'class' ) );

        $second = expAiModelTest::start( 'stub-model', 'test', $config );
        $this->assertTrue( $second['duplicate'], 'a waiting job is not queued again' );
        $this->assertSame( $first['job']->attribute( 'uuid' ), $second['job']->attribute( 'uuid' ) );

        $latest = expAiModelTest::latestJob( 'xtem.cloud', 'test' );
        $this->assertSame( $first['job']->attribute( 'uuid' ), $latest->attribute( 'uuid' ) );
        $this->assertNull( expAiModelTest::latestJob( 'xtem.cloud', 'load' ) );

        $state = expAiModelTest::forModel( 'xtem.cloud', $config );
        $this->assertTrue( $state['active'] );
        $this->assertSame( 'waiting', $state['test']['phase'] );
        $this->assertFalse( $state['managed'] );
        $this->assertNull( $state['loaded'] );
    }

    public function testStartRefusesUnknownModelsActionsAndLoadsOfCloudModels(): void
    {
        $config = $this->siteModels();
        foreach ( array( array( 'nothing', 'test', 'This model is not available.' ), array( 'xtem.cloud', 'delete', 'Unknown action.' ),
                         array( 'xtem.cloud', 'load', 'Only models of a local model server can be loaded.' ) ) as $case )
        {
            try
            {
                expAiModelTest::start( $case[0], $case[1], $config );
                $this->fail( 'accepted ' . implode( ' ', $case ) );
            }
            catch ( expAiException $e )
            {
                $this->assertSame( $case[2], $e->getMessage() );
            }
        }
        $this->assertTrue( expAiModelTest::managed( 'xteml.qwen', $config ) );
        $this->assertFalse( expAiModelTest::managed( 'xtem.cloud', $config ) );
        $this->assertFalse( expAiModelTest::managed( 'nothing', $config ) );
    }

    // ------------------------------------------------------------------ describe

    public function testDescribeNamesThePhaseTheTimesAndTheError(): void
    {
        $this->assertNull( expAiModelTest::describe( null ) );
        $created = time() - 30;
        $done = expAiModelTest::describe( self::job( array( 'state' => 'done', 'created' => $created, 'finished' => $created + 12 ), array( 'alias' => 'a', 'action' => 'test' ),
                                                     array( 'ok' => true, 'cost_amount' => 0.0123, 'ttft_ms' => 1500, 'total_ms' => null ) ) );
        $this->assertSame( array( 'done', 'test', 'a', false, 12 ), array( $done['phase'], $done['action'], $done['alias'], $done['active'], $done['seconds'] ) );
        $this->assertSame( 1.5, $done['result']['ttft_s'] );
        $this->assertNull( $done['result']['total_s'] );
        $this->assertNull( $done['result']['load_s'] );
        $this->assertSame( expAiCost::money( 0.0123 ), $done['result']['cost'] );
        $this->assertSame( '', $done['error'] );

        $this->assertSame( '', expAiModelTest::describe( self::job( array( 'state' => 'done' ), array(), array( 'ok' => true, 'cost_amount' => null ) ) )['result']['cost'] );

        $failed = expAiModelTest::describe( self::job( array( 'state' => 'done' ), array(), array( 'ok' => false, 'error' => 'This model is not available.' ) ) );
        $this->assertSame( array( 'failed', 'This model is not available.' ), array( $failed['phase'], $failed['error'] ) );

        $dead = expAiModelTest::describe( self::job( array( 'state' => 'dead', 'last_error' => 'worker crashed' ) ) );
        $this->assertSame( 'failed', $dead['phase'] );
        $this->assertSame( 'The job failed: worker crashed', $dead['error'] );

        $this->assertSame( 'The job was cancelled.', expAiModelTest::describe( self::job( array( 'state' => 'cancelled' ) ) )['error'] );
        $this->assertSame( 'running', expAiModelTest::describe( self::job( array( 'state' => 'running' ) ) )['phase'] );
        $held = expAiModelTest::describe( self::job( array( 'state' => 'held', 'last_error' => 'switched off' ) ) );
        $this->assertSame( array( 'waiting', 'switched off' ), array( $held['phase'], $held['held'] ) );
    }

    public function testForModelHidesOldResultsAndReadsTheLoadedStateOfALocalModel(): void
    {
        $config = $this->siteModels();
        $old = self::job( array( 'state' => 'done', 'created' => time() - 7200, 'finished' => time() - 7100 ), array( 'alias' => 'xteml.qwen', 'action' => 'test' ), array( 'ok' => true ) );
        $state = expAiModelTest::forModel( 'xteml.qwen', $config, array( 'models' => array( array( 'name' => 'qwen3:4b', 'size' => 2097152 ) ) ), array( $old ) );
        $this->assertNull( $state['test'], 'a result older than ShowResultMinutes is not shown' );
        $this->assertTrue( $state['managed'] );
        $this->assertSame( array( 'loaded' => true, 'until' => 0, 'size_mb' => 2, 'reachable' => true ), $state['loaded'] );

        $state = expAiModelTest::forModel( 'xteml.qwen', $config, null, array() );
        $this->assertSame( array( 'loaded' => false, 'until' => 0, 'size_mb' => 0, 'reachable' => false ), $state['loaded'], 'the server on port 1 is not reachable' );
    }

    // ------------------------------------------------------------------ nudge

    public function testNudgeStartsOneWorkerForAJobThatWaitsTooLong(): void
    {
        $this->requireDatabase();
        $lock = eZSys::cacheDirectory() . '/expai/model-test-kick.lock';
        $before = is_file( $lock ) ? file_get_contents( $lock ) : null;
        $this->cleanup( static function () use ( $lock, $before )
        {
            if ( $before !== null )
                file_put_contents( $lock, $before );
        } );
        $waiting = array( self::job( array( 'state' => 'ready', 'created' => time() - 60 ) ) );
        $config = $this->config( array( 'VectorSettings' => array( 'KickWorker' => 'true', 'PhpCli' => is_executable( '/bin/true' ) ? '/bin/true' : '/usr/bin/true' ) ) ); // /usr/bin/true on macOS

        $this->assertFalse( expAiModelTest::nudge( array( self::job( array( 'state' => 'ready', 'created' => time() ) ) ), $config ), 'a fresh job waits' );
        $this->assertFalse( expAiModelTest::nudge( $waiting, $this->config( array( 'VectorSettings' => array( 'KickWorker' => 'false' ) ) ) ) );

        @mkdir( dirname( $lock ), 0775, true );
        file_put_contents( $lock, '0' );
        $this->assertTrue( expAiModelTest::nudge( $waiting, $config ), '/bin/true stands in for the worker' );
        $this->assertFalse( expAiModelTest::nudge( $waiting, $config ), 'not again within 15 seconds' );

        file_put_contents( $lock, '0' );
        $this->assertFalse( expAiModelTest::nudge( $waiting, $this->config( array( 'VectorSettings' => array( 'KickWorker' => 'true', 'PhpCli' => '/nonexistent/php' ) ) ) ),
                            'no PHP binary: nothing is started' );
    }

    // ------------------------------------------------------------------ run: the test request and the load

    public function testRunTestsAModelAndRecordsTheUsage(): void
    {
        $this->requireDatabase();
        $this->asUser( 'admin' );
        $config = $this->siteModels();
        $this->inTransaction();
        $stub = new expAiProviderTestStubProvider( array( 'content' => array( array( 'type' => 'text', 'text' => ' The sea is wide. ' ), array( 'type' => 'tool_use' ) ),
                                                   'model' => 'stub-model-v2', 'usage' => array( 'input_tokens' => 20, 'output_tokens' => 8 ), 'stop_reason' => 'end_turn' ) );
        $result = expAiModelTest::run( 'xtem.cloud', 'test', array( 'user_id' => 14, 'siteaccess' => 'admin' ), $config, $stub );
        $this->assertTrue( $result['ok'], json_encode( $result ) );
        $this->assertSame( 'The sea is wide.', $result['text'] );
        $this->assertSame( array( 20, 8, 'stub-model-v2' ), array( $result['input'], $result['output'], $result['model'] ) );
        $this->assertNull( $result['ttft_ms'] );
        $this->assertGreaterThan( 0, $result['usage_log_id'] );
        $this->assertSame( expAiModelTest::SYSTEM, $stub->requests[0]['system'] );
        $this->assertSame( expAiModelTest::REASONING_MIN_TOKENS, $stub->requests[0]['max_tokens'] );
        $row = eZPersistentObject::fetchObject( expAiUsageLog::definition(), null, array( 'id' => $result['usage_log_id'] ) );
        $this->assertSame( array( 'test', 14, 'xtem' ), array( (string) $row->attribute( 'action' ), (int) $row->attribute( 'user_id' ), (string) $row->attribute( 'provider' ) ) );

        // the timings of a local model give the speeds
        $stub = new expAiProviderTestStubProvider( array( 'content' => array( array( 'type' => 'text', 'text' => 'x' ) ), 'tokens' => array( 'input' => 100, 'output' => 50 ),
                                                   'timings' => array( 'first_token' => 900, 'load' => 400, 'prompt' => 500, 'eval' => 1000 ) ) );
        $result = expAiModelTest::run( 'xtem.cloud', 'test', array(), $config, $stub );
        $this->assertSame( array( 900, 400, 200.0, 50.0 ), array( $result['ttft_ms'], $result['load_ms'], $result['in_tps'], $result['out_tps'] ) );
    }

    public function testRunReportsErrorsAsAResult(): void
    {
        $config = $this->siteModels();
        $result = expAiModelTest::run( 'nothing', 'test', array(), $config );
        $this->assertSame( array( false, 'This model is not available.' ), array( $result['ok'], $result['error'] ) );
        $result = expAiModelTest::run( 'xtem.cloud', 'load', array(), $config );
        $this->assertSame( 'Only models of a local model server can be loaded.', $result['error'] );
    }

    public function testRunLoadsALocalModelAndReadsWhetherItIsInMemory(): void
    {
        $config = $this->siteModels( $this->ollama() );
        $result = expAiModelTest::run( 'xteml.qwen', 'load', array(), $config );
        $this->assertTrue( $result['ok'], json_encode( $result ) );
        $this->assertSame( array( 'qwen3:4b', 2500, true, strtotime( '2030-01-01T00:00:00Z' ) ), array( $result['model'], $result['load_ms'], $result['loaded'], $result['until'] ) );

        // the master switch holds a load too
        $this->site( array( 'AISettings' => array( 'Enabled' => 'false' ) ) );
        $result = expAiModelTest::run( 'xteml.qwen', 'load', array(), expAiConfig::instance() );
        $this->assertSame( 'Exponential Intelligence is switched off.', $result['error'] );
    }

    // ------------------------------------------------------------------ the ezjscore functions of the Models page

    public function testServerFunctionsNeedAPostWithTheFormToken(): void
    {
        $this->requireDatabase();
        $this->asUser( 'admin' );
        $this->siteModels();
        $this->request( array(), 'GET' );
        $this->assertServerError( function () { expAiModelServerFunctions::status( array() ); }, 'Exponential Intelligence only accepts POST requests.' );
        if ( class_exists( 'ezxFormToken' ) && ezxFormToken::isEnabled() )
        {
            $this->request( array( 'ezxform_token' => 'wrong' ) );
            $this->assertServerError( function () { expAiModelServerFunctions::status( array() ); }, 'The form token is missing or wrong. Please reload the page.' );
        }
        $this->asUser( 'anonymous' );
        $this->request( array( 'ezxform_token' => class_exists( 'ezxFormToken' ) ? ezxFormToken::getToken() : '' ) );
        $this->assertServerError( function () { expAiModelServerFunctions::status( array() ); }, 'You do not have access to Exponential Intelligence.' );
    }

    public function testServerFunctionsStatusTestAndLoad(): void
    {
        $this->requireDatabase();
        $this->asUser( 'admin' );
        $config = $this->siteModels();
        $this->removeJobs();
        $token = class_exists( 'ezxFormToken' ) ? ezxFormToken::getToken() : '';

        $this->request( array( 'ezxform_token' => $token, 'models' => 'xtem.cloud, unknown, xtem.cloud,xteml.qwen' ) );
        // the template autoload of extension/recaptcha prints a line break when it is first loaded (not expai): load it here
        ob_start();
        eZTemplate::factory();
        ob_end_clean();
        $answer = expAiModelServerFunctions::status( array() );
        $this->assertSame( array( 'xtem.cloud', 'xteml.qwen' ), array_column( $answer['models'], 'alias' ) );
        $this->assertSame( 3000, $answer['poll'] );
        $this->assertSame( '', $answer['message'] );
        foreach ( $answer['models'] as $model )
            $this->assertIsString( $model['state_html'] );

        $this->request( array( 'ezxform_token' => $token, 'model' => 'unknown' ) );
        $this->assertServerError( function () { expAiModelServerFunctions::test( array() ); }, 'This model is not available.' );
        $this->request( array( 'ezxform_token' => $token, 'model' => 'xtem.cloud' ) );
        $this->assertServerError( function () { expAiModelServerFunctions::load( array() ); }, 'Only models of a local model server can be loaded.' );

        $answer = expAiModelServerFunctions::test( array() );
        $this->assertSame( 'The test of Cloud XTE runs in the background.', $answer['message'] );
        $this->assertTrue( $answer['models'][0]['active'] );
        $answer = expAiModelServerFunctions::test( array() );
        $this->assertSame( 'A test of Cloud XTE is already waiting or running.', $answer['message'] );

        $this->request( array( 'ezxform_token' => $token, 'model' => 'xteml.qwen' ) );
        $this->assertSame( 'The model Local XTE is being loaded in the background.', expAiModelServerFunctions::load( array() )['message'] );
        $this->assertSame( 'The model Local XTE is already being loaded.', expAiModelServerFunctions::notice( $config, 'xteml.qwen', 'load', true ) );
        $this->assertSame( 'The test of gone runs in the background.', expAiModelServerFunctions::notice( $config, 'gone', 'test', false ) );
    }

    private function assertServerError( callable $call, $message )
    {
        try
        {
            $call();
            $this->fail( "no exception ($message)" );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( $message, strtr( $e->getMessage(), $e->arguments() ) );
        }
    }
}
