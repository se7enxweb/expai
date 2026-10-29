<?php
/**
 * expAiQueueKick: how many workers a Vector run starts, the record of recent starts, and the start itself. No worker
 * is started: the "PHP binary" is /bin/true (the command line is built and run, and does nothing) or a path that does
 * not exist. The start record of the site is put back after each test.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiQueueKickTest extends expAiProviderTestCase
{
    /** @var string */
    private $record;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = eZSys::cacheDirectory() . '/expai';
        $saved = array();
        foreach ( array( "$dir/worker-kick.json", "$dir/worker-kick-" . posix_geteuid() . '.json' ) as $path )
            $saved[$path] = is_file( $path ) ? file_get_contents( $path ) : null;
        $this->record = "$dir/worker-kick.json";
        $this->cleanup( static function () use ( $saved )
        {
            foreach ( $saved as $path => $content )
                if ( $content !== null )
                    file_put_contents( $path, $content );
                elseif ( is_file( $path ) )
                    unlink( $path );
        } );
    }

    /** @return string a binary that does nothing and succeeds (/bin/true is /usr/bin/true on macOS) */
    private static function trueBinary(): string
    {
        return is_executable( '/bin/true' ) ? '/bin/true' : '/usr/bin/true';
    }

    public function testToStartFillsUpToTheLimitAndCountsIdleWorkers(): void
    {
        $this->assertSame( 2, expAiQueueKick::toStart( 2, 0, 0, 0, 4 ) );
        $this->assertSame( 3, expAiQueueKick::toStart( 1, 0, 0, 3, 4 ), 'ready jobs count too' );
        $this->assertSame( 1, expAiQueueKick::toStart( 3, 3, 0, 0, 4 ), 'never above the limit' );
        $this->assertSame( 0, expAiQueueKick::toStart( 2, 2, 2, 0, 4 ), 'idle workers take the jobs' );
        $this->assertSame( 0, expAiQueueKick::toStart( 1, 6, 0, 0, 4 ) );
        $this->assertSame( 4, expAiQueueKick::maxWorkers( $this->config( array( 'VectorSettings' => array( 'MaxKickedWorkers' => '' ) ) ) ) );
        $this->assertSame( 16, expAiQueueKick::maxWorkers( $this->config( array( 'VectorSettings' => array( 'MaxKickedWorkers' => 99 ) ) ) ) );
        $this->assertSame( 1, expAiQueueKick::maxWorkers( $this->config( array( 'VectorSettings' => array( 'MaxKickedWorkers' => 0 ) ) ) ) );
    }

    public function testWorkersStartedButNotYetBeatingCountAsLiveAndIdle(): void
    {
        $this->requireDatabase();
        list( $live, $idle ) = expAiQueueKick::workers( array() );
        list( $live2, $idle2 ) = expAiQueueKick::workers( array( time() + 3600, time() + 3600 ) );
        $this->assertSame( array( $live + 2, $idle + 2 ), array( $live2, $idle2 ), 'two starts in the future: no worker of them beats yet' );
        $this->assertSame( 2, self::invoke( 'expAiQueueKick', 'startedSince', array( array( array( 'started' => 100 ), array( 'started' => 99 ), array( 'started' => 50 ) ), 100 ) ) );
        $this->assertGreaterThanOrEqual( 0, expAiQueueKick::readyJobs( 'interactive' ) );
        $this->assertSame( 0, expAiQueueKick::readyJobs( 'xte-no-such-class' ) );
    }

    public function testKickDoesNothingWhenSwitchedOff(): void
    {
        $this->site( array( 'VectorSettings' => array( 'KickWorker' => 'false' ) ) );
        $before = is_file( $this->record ) ? file_get_contents( $this->record ) : null;
        $this->assertSame( 0, expAiQueueKick::kick( 3 ) );
        $this->assertSame( $before, is_file( $this->record ) ? file_get_contents( $this->record ) : null, 'the record is not touched' );
    }

    public function testKickStartsTheMissingWorkersAndRecordsTheStarts(): void
    {
        $this->requireDatabase();
        $this->site( array( 'VectorSettings' => array( 'KickWorker' => 'true', 'PhpCli' => self::trueBinary(), 'MaxKickedWorkers' => 16, 'WorkerAllowRoot' => 'enabled' ) ) );
        @mkdir( dirname( $this->record ), 0775, true );
        file_put_contents( $this->record, json_encode( array( 'starts' => array( time() - 3600 ) ) ) );
        list( $live, $idle ) = expAiQueueKick::workers( array() );
        $wanted = $idle + 2;
        $started = expAiQueueKick::kick( $wanted, 5, 'interactive' );
        $this->assertGreaterThanOrEqual( min( 2, 16 - $live ), $started );
        $record = json_decode( file_get_contents( $this->record ), true );
        $this->assertSame( $started, $record['started'] );
        $this->assertSame( $wanted, $record['wanted'] );
        $this->assertCount( $started, $record['starts'], 'the old start is forgotten, the new ones are kept' );
        $this->assertSame( 'cli', $record['sapi'] );

        // just started: they count as idle workers, so a second kick for as many starts none
        $this->assertSame( 0, expAiQueueKick::kick( $started, 5, 'interactive' ) );
    }

    public function testAMissingBinaryIsRecordedAsTheError(): void
    {
        $this->requireDatabase();
        $this->site( array( 'VectorSettings' => array( 'KickWorker' => 'true', 'PhpCli' => '/nonexistent/xte/php', 'MaxKickedWorkers' => 16 ) ) );
        @mkdir( dirname( $this->record ), 0775, true );
        file_put_contents( $this->record, '{}' );
        $this->assertSame( 0, expAiQueueKick::kick( 16 ) );
        $record = json_decode( file_get_contents( $this->record ), true );
        $this->assertStringStartsWith( 'no PHP binary or worker script (/nonexistent/xte/php', $record['error'] );
    }

    public function testLaunchRunsTheCommandInTheBackground(): void
    {
        $this->assertGreaterThan( 0, self::invoke( 'expAiQueueKick', 'launch', array( 'true' ) ), 'the shell reports the process id' );
        $this->assertSame( 0, self::invoke( 'expAiQueueKick', 'processId', array( '' ) ) );
        $this->assertSame( 4711, self::invoke( 'expAiQueueKick', 'processId', array( "warning\n4711\n" ) ) );
        $config = $this->config( array( 'VectorSettings' => array( 'PhpCli' => self::trueBinary(), 'WorkerAllowRoot' => 'enabled' ) ) );
        $this->assertSame( 2, self::invoke( 'expAiQueueKick', 'start', array( 2, 9999, 'bulk', $config ) ) );
    }

    public function testTheRootFlagIsPassedOnlyWhenTheSiteAllowsIt(): void
    {
        foreach ( array( 'disabled', 'enabled' ) as $allow )
        {
            $config = $this->config( array( 'VectorSettings' => array( 'PhpCli' => self::trueBinary(), 'WorkerAllowRoot' => $allow ) ) );
            $started = self::invoke( 'expAiQueueKick', 'start', array( 1, 60, 'bulk', $config ) );
            // as root with a root-owned var/ (a test checkout) and a root worker not allowed, none is started, with the reason
            if ( $started === 0 && $allow === 'disabled' && function_exists( 'posix_geteuid' ) && posix_geteuid() === 0 )
                $this->assertStringContainsString( 'runs as root', (string) ( new ReflectionProperty( 'expAiQueueKick', 'lastError' ) )->getValue() );
            else
                $this->assertSame( 1, $started, "WorkerAllowRoot=$allow" );
        }
        $source = file_get_contents( EXPAI_TEST_CHECKOUT . '/classes/queue/expaiqueuekick.php' );
        $this->assertStringContainsString( "'WorkerAllowRoot', false", $source );
        $this->assertStringContainsString( 'command -v setsid', $source );
        $this->assertStringNotContainsString( 'nohup setsid', $source );
    }

    public function testAWorkerKickedAsRootRunsAsTheOwnerOfVarOrNotAtAll(): void
    {
        $this->assertSame( array( '', '' ), self::invoke( 'expAiQueueKick', 'workerUser', array( '/nonexistent', false, 1000 ) ), 'not root: as this user' );
        $this->assertSame( array( '', '' ), self::invoke( 'expAiQueueKick', 'workerUser', array( '/nonexistent', true, 0 ) ), 'root allowed: as root' );
        list( $user, $refused ) = self::invoke( 'expAiQueueKick', 'workerUser', array( '/nonexistent', false, 0 ) );
        $this->assertSame( '', $user );
        $this->assertStringContainsString( 'WorkerAllowRoot=enabled', $refused, 'no owner to run as: no worker, and why' );
        $root = expAiTestMockServer::tmp( 'xte-kick' );
        $this->cleanup( static function () use ( $root ) { expAiTestMockServer::removeTree( $root ); } );
        mkdir( $root . '/var' );
        $owner = fileowner( $root . '/var' );
        list( $user, $refused ) = self::invoke( 'expAiQueueKick', 'workerUser', array( $root, false, 0 ) );
        if ( $owner === 0 )
            $this->assertNotSame( '', $refused, 'a root-owned var/ is no user to switch to' );
        elseif ( $refused === '' )
            $this->assertSame( posix_getpwuid( $owner )['name'], $user );
        $source = file_get_contents( EXPAI_TEST_CHECKOUT . '/classes/queue/expaiqueuekick.php' );
        $this->assertStringContainsString( "' || exit 1; D=;", $source, 'no worker when the installation cannot be entered' );
    }
}
