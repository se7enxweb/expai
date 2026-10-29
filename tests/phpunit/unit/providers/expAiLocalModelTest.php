<?php
/**
 * expAiLocalModel: the managed local providers, the status read from the server (a web server of files that answers
 * like Ollama's /api/version, /api/ps and /api/tags), the helper's status file, and the requests written to the
 * helper's spool directory. Nothing is started or stopped: the spool is a directory of the test.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiLocalModelTest extends expAiProviderTestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = expAiTestMockServer::tmp( 'xte-local' );
        $dir = $this->dir;
        $this->cleanup( static function () use ( $dir ) { expAiTestMockServer::removeTree( $dir ); } );
    }

    private function localConfig( $baseUrl, array $more = array() )
    {
        return parent::config( array(
            'ProviderSettings' => array( 'Providers' => array( 'xtelocal', 'xteremote' ) ),
            'Provider_xtelocal' => array( 'Type' => 'openai_chat', 'Flavor' => 'ollama', 'Managed' => 'Ollama', 'BaseURL' => $baseUrl, 'KeyOptional' => 'true' ),
            'Provider_xteremote' => array( 'Type' => 'openai_chat', 'BaseURL' => 'https://example.invalid/v1', 'ApiKey' => 'k' ),
            'Model_xtelocal.q' => array( 'Provider' => 'xtelocal', 'Id' => 'qwen3:4b' ),
            'LocalModelSettings' => $more + array( 'Models' => array( 'qwen3:4b', 'Bad Name!', 'llama3.2' ), 'StatusFile' => '', 'SpoolDirectory' => '' ),
        ) );
    }

    public function testOnlyProvidersManagedByOllamaAreLocal(): void
    {
        $this->assertSame( array( 'xtelocal' ), expAiLocalModel::managedProviders( $this->localConfig( 'http://127.0.0.1:1/v1' ) ) );
    }

    public function testTheRootUrlIsTheLoopbackServerWithoutTheApiPath(): void
    {
        $this->assertSame( 'http://127.0.0.1:11434', ( new expAiLocalModel( $this->localConfig( 'http://127.0.0.1:11434/v1/chat/completions' ), 'xtelocal' ) )->rootUrl() );
        $this->assertSame( 'http://localhost:11434', ( new expAiLocalModel( $this->localConfig( 'http://localhost:11434/v1/' ), 'xtelocal' ) )->rootUrl() );
        $this->assertSame( '', ( new expAiLocalModel( $this->localConfig( 'http://127.0.0.1:1/v1' ), 'xteremote' ) )->rootUrl(), 'not on this server' );
        $this->assertSame( '', ( new expAiLocalModel( $this->localConfig( 'http://127.0.0.1:1/v1' ), 'none' ) )->rootUrl() );
    }

    public function testStatusOfARunningServerWithTheHelpersFiles(): void
    {
        $www = $this->dir . '/www';
        mkdir( $www . '/api', 0775, true );
        file_put_contents( $www . '/api/version', '{"version":"0.12.3"}' );
        file_put_contents( $www . '/api/ps', json_encode( array( 'models' => array( array( 'name' => 'qwen3:4b', 'size' => 3145728, 'expires_at' => '2026-10-12T10:00:00Z' ) ) ) ) );
        file_put_contents( $www . '/api/tags', json_encode( array( 'models' => array( array( 'name' => 'qwen3:4b', 'size' => 2097152 ), array( 'name' => 'llama3.2' ) ) ) ) );
        $server = expAiTestMockServer::start( '', $www );
        file_put_contents( $this->dir . '/status.json', '{"helper":"0.1","ok":true}' );
        mkdir( $this->dir . '/spool' );
        file_put_contents( $this->dir . '/spool/a.json', '{}' );

        $status = ( new expAiLocalModel( $this->localConfig( $server['url'] . '/v1', array( 'StatusFile' => $this->dir . '/status.json', 'SpoolDirectory' => $this->dir . '/spool/' ) ), 'xtelocal' ) )->status();
        $this->assertTrue( $status['running'] );
        $this->assertSame( '0.12.3', $status['version'] );
        $this->assertSame( array( array( 'name' => 'qwen3:4b', 'size_mb' => 3, 'until' => '2026-10-12T10:00:00Z' ) ), $status['loaded'] );
        $this->assertSame( array( array( 'name' => 'qwen3:4b', 'size_mb' => 2 ), array( 'name' => 'llama3.2', 'size_mb' => 0 ) ), $status['installed'] );
        $this->assertSame( array( 'helper' => '0.1', 'ok' => true ), $status['helper'] );
        $this->assertTrue( $status['spool_writable'] );
        $this->assertSame( 1, $status['pending'] );
    }

    public function testStatusOfAStoppedServerAndAServerThatIsNotOllama(): void
    {
        $probe = stream_socket_server( 'tcp://127.0.0.1:0' );
        $port = (int) substr( strrchr( stream_socket_get_name( $probe, false ), ':' ), 1 );
        fclose( $probe );
        $status = ( new expAiLocalModel( $this->localConfig( 'http://127.0.0.1:' . $port . '/v1' ), 'xtelocal' ) )->status();
        $this->assertFalse( $status['running'] );
        $this->assertSame( array(), $status['loaded'] );
        $this->assertNull( $status['helper'] );
        $this->assertFalse( $status['spool_writable'] );
        $this->assertSame( 0, $status['pending'] );

        // a server that answers with something else than JSON is not running Ollama
        $www = $this->dir . '/plain';
        mkdir( $www . '/api', 0775, true );
        file_put_contents( $www . '/api/version', 'hello' );
        $server = expAiTestMockServer::start( '', $www );
        $this->assertFalse( ( new expAiLocalModel( $this->localConfig( $server['url'] ), 'xtelocal' ) )->status()['running'] );
        // a provider that is not on this server is never asked
        $this->assertFalse( ( new expAiLocalModel( $this->localConfig( 'http://127.0.0.1:1/v1' ), 'xteremote' ) )->status()['running'] );
    }

    public function testOnlyValidModelNamesAreAllowed(): void
    {
        $this->assertSame( array( 'qwen3:4b', 'llama3.2' ), ( new expAiLocalModel( $this->localConfig( 'http://127.0.0.1:1' ), 'xtelocal' ) )->allowedModels() );
    }

    public function testRequestsAreWrittenToTheSpoolForTheHelper(): void
    {
        $this->requireDatabase();
        $user = $this->asUser( 'admin' );
        mkdir( $this->dir . '/spool' );
        $local = new expAiLocalModel( $this->localConfig( 'http://127.0.0.1:1', array( 'SpoolDirectory' => $this->dir . '/spool' ) ), 'xtelocal' );
        $id = $local->request( 'pull', 'llama3.2' );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $id );
        $request = json_decode( file_get_contents( $this->dir . '/spool/' . $id . '.json' ), true );
        $this->assertSame( array( 'id' => $id, 'action' => 'pull', 'model' => 'llama3.2', 'requested_by_user_id' => (int) $user->attribute( 'contentobject_id' ) ),
                           array_intersect_key( $request, array_flip( array( 'id', 'action', 'model', 'requested_by_user_id' ) ) ) );
        $this->assertSame( array(), glob( $this->dir . '/spool/.*.tmp' ), 'the temporary file is renamed' );
        $this->assertNotSame( '', $local->request( 'refresh_status' ) );
        $this->assertCount( 2, glob( $this->dir . '/spool/*.json' ) );
    }

    public function testRequestsThatAreRefused(): void
    {
        $local = new expAiLocalModel( $this->localConfig( 'http://127.0.0.1:1', array( 'SpoolDirectory' => $this->dir . '/missing' ) ), 'xtelocal' );
        foreach ( array( array( 'format_disk', '', 'Unknown action.' ), array( 'pull', 'evil-model', 'This model is not on the list of allowed local models.' ),
                         array( 'remove', '', 'Please choose a model.' ),
                         array( 'start', '', 'The local model helper is not installed (the spool directory is missing or not writable).' ) ) as $case )
        {
            try
            {
                $local->request( $case[0], $case[1] );
                $this->fail( 'accepted ' . $case[0] );
            }
            catch ( expAiException $e )
            {
                $this->assertSame( $case[2], $e->getMessage() );
            }
        }
    }
}
