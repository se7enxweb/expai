<?php
/**
 * File containing the expAiQueueKick class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Starts detached queue workers right after a job is queued, so the job does not wait for the cron part of expqueue
 * (doc/parallel-runs.md "Workers"). Shared by every producer of expai that queues work an editor waits for: thread
 * turns and plan items (Vector, the Intelligence tab), model tests, field actions.
 *
 * One worker runs one job at a time. A worker is started when ready jobs of the class wait and no live worker is
 * free to take them: none runs, or every live one is busy (a long turn of a slow model must not hold up a short model
 * test). Bounded: at most [VectorSettings] MaxKickedWorkers live workers in all (the heartbeats of expqueue_worker,
 * cron and systemd workers included); workers started in the last 20 seconds count as live until their first
 * heartbeat. Each started worker leaves as soon as nothing is ready (--until-empty) or after $maxSeconds.
 *
 *   [VectorSettings] KickWorker=enabled   start workers at all (the cron part of expqueue stays the safety net)
 *                    MaxKickedWorkers=4   live workers at most
 *                    PhpCli=              the PHP command line binary (empty: the one of this PHP)
 *                    WorkerAllowRoot=disabled  pass --allow-root-user to the worker (only for a site whose PHP runs
 *                                         as root; a worker as root writes files the web server's user cannot).
 *                                         Disabled, a process running as root (Velocity, a root cron) starts the
 *                                         worker as the owner of the installation's var/ directory (runuser), and
 *                                         starts none, with a warning, where that is not possible
 *
 * The start record (var/<site>/cache/expai/worker-kick.json) is shared by PHP-FPM (the site's user) and Velocity or
 * the command line: it is created with mode 0660 (owner and group), and a record that cannot be opened by the
 * process's user is replaced by one of its own (worker-kick-<uid>.json).
 *
 * The worker is detached with setsid where the system has it (Linux; not macOS) and with nohup alone elsewhere. The
 * shell reports the process id of what it put in the background. That detects only that the shell itself could not
 * be started; a worker that dies right after (a wrong PHP binary, a refused root user) is not noticed here, and the
 * cron part of expqueue stays the safety net.
 */
class expAiQueueKick
{
    /** Seconds a started worker counts as live before its first heartbeat */
    const START_GRACE = 20;

    /** @var string why the last start failed ('' none) */
    protected static $lastError = '';

    /** @return int live workers at most */
    public static function maxWorkers( ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        return $config->int( 'VectorSettings', 'MaxKickedWorkers', 4, 1, 16 );
    }

    /**
     * How many workers to start now: ready jobs that no free worker takes, within the bound.
     * @param int $wanted jobs the caller just queued that may run at once (1 for a turn, the items of a plan)
     * @param int $live live workers (heartbeats plus recent starts)
     * @param int $idle live workers without a current job
     * @param int $ready ready jobs of the class (the caller's included)
     * @return int
     */
    public static function toStart( $wanted, $live, $idle, $ready, $max )
    {
        $waiting = max( (int) $wanted, (int) $ready ) - (int) $idle;
        return max( 0, min( (int) $max - (int) $live, $waiting ) );
    }

    /** @return array( int live, int idle ) the workers with a heartbeat, plus the starts of the last seconds */
    public static function workers( array $recentStarts )
    {
        $live = class_exists( 'expQueueWorker' ) ? expQueueWorker::live() : array();
        $idle = 0;
        foreach ( $live as $row )
            if ( !(int) ( isset( $row['current_job'] ) ? $row['current_job'] : 0 ) )
                $idle++;
        // started but not yet beating: live and free
        $pending = max( 0, count( $recentStarts ) - self::startedSince( $live, $recentStarts ? min( $recentStarts ) : time() ) );
        return array( count( $live ) + $pending, $idle + $pending );
    }

    /** @return int live workers that started at or after a time (they are the recent starts that already beat) */
    protected static function startedSince( array $live, $since )
    {
        $n = 0;
        foreach ( $live as $row )
            if ( (int) $row['started'] >= (int) $since - 1 )
                $n++;
        return $n;
    }

    /** @return int ready jobs of a queue class */
    public static function readyJobs( $class )
    {
        if ( !class_exists( 'expQueue' ) )
            return 0;
        return (int) expQueue::count( array( 'state' => 'ready', 'class' => (string) $class ) );
    }

    /** @return resource|null the start record, opened and locked; created writable for every user of the site */
    protected static function openRecord()
    {
        $dir = eZSys::cacheDirectory() . '/expai';
        if ( !is_dir( $dir ) )
            eZDir::mkdir( $dir, false, true );
        foreach ( array( "$dir/worker-kick.json", "$dir/worker-kick-" . ( function_exists( 'posix_geteuid' ) ? posix_geteuid() : getmyuid() ) . '.json' ) as $path )
        {
            $new = !is_file( $path );
            $handle = @fopen( $path, 'c+' );
            if ( !$handle )
                continue;
            if ( $new )
                @chmod( $path, 0660 );
            if ( flock( $handle, LOCK_EX ) )
                return $handle;
            fclose( $handle );
        }
        return null;
    }

    /**
     * Starts workers for the class when ready jobs wait that no free worker takes.
     * @param int $wanted jobs that may run at once now
     * @param int $maxSeconds how long a started worker takes new jobs
     * @param string $class the queue class the workers serve
     * @return int workers started
     */
    public static function kick( $wanted = 1, $maxSeconds = 60, $class = 'interactive' )
    {
        $config = expAiConfig::instance();
        if ( !$config->flag( 'VectorSettings', 'KickWorker', true ) || !class_exists( 'expQueueWorker' ) )
            return 0;
        $handle = self::openRecord();
        if ( !$handle )
        {
            eZDebug::writeWarning( 'expai: the worker start record cannot be written; no worker started', __METHOD__ );
            return 0;
        }
        $record = json_decode( (string) stream_get_contents( $handle ), true );
        $now = time();
        $starts = array_values( array_filter( isset( $record['starts'] ) ? (array) $record['starts'] : array(), function ( $t ) use ( $now ) { return (int) $t > $now - self::START_GRACE; } ) );
        list( $live, $idle ) = self::workers( $starts );
        $n = self::toStart( $wanted, $live, $idle, self::readyJobs( $class ), self::maxWorkers( $config ) );
        $started = $n > 0 ? self::start( $n, $maxSeconds, $class, $config ) : 0;
        for ( $i = 0; $i < $started; $i++ )
            $starts[] = $now;
        ftruncate( $handle, 0 );
        rewind( $handle );
        fwrite( $handle, json_encode( array( 'starts' => $starts, 'live' => $live, 'idle' => $idle, 'wanted' => (int) $wanted, 'to_start' => $n, 'started' => $started,
                                              'error' => self::$lastError, 'sapi' => PHP_SAPI, 'at' => $now ) ) );
        flock( $handle, LOCK_UN );
        fclose( $handle );
        return $started;
    }

    /**
     * Runs a shell command in the background and returns its process id: proc_open, else popen, else exec (a pool
     * worker of Velocity cannot always use proc_open). The shell prints $! of the command it put in the background; no
     * number means the shell could not be started. A number says nothing about the worker itself (it may exit at
     * once; a worker that finds nothing to do leaves at once by design, so its life is not checked).
     * @return int the process id, 0 when nothing was started
     */
    protected static function launch( $command )
    {
        $line = $command . ' & echo $!';
        $output = '';
        if ( function_exists( 'proc_open' ) )
        {
            $proc = @proc_open( array( '/bin/sh', '-c', $line ), array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes );
            if ( is_resource( $proc ) )
            {
                $output = (string) stream_get_contents( $pipes[1] );
                fclose( $pipes[1] );
                proc_close( $proc );
                return self::processId( $output );
            }
        }
        if ( function_exists( 'popen' ) )
        {
            $handle = @popen( $line, 'r' );
            if ( is_resource( $handle ) )
            {
                $output = (string) stream_get_contents( $handle );
                pclose( $handle );
                return self::processId( $output );
            }
        }
        if ( function_exists( 'exec' ) )
        {
            $out = array();
            $code = 1;
            @exec( $line, $out, $code );
            return $code === 0 ? self::processId( implode( "\n", $out ) ) : 0;
        }
        return 0;
    }

    /** @return int the last number the shell printed, 0 when there is none */
    protected static function processId( $output )
    {
        return preg_match( '/(\d+)\s*$/', (string) $output, $m ) ? (int) $m[1] : 0;
    }

    /** @return int detached workers started (ext:expqueue:work --until-empty) */
    protected static function start( $count, $maxSeconds, $class, expAiConfig $config )
    {
        $php = (string) $config->get( 'VectorSettings', 'PhpCli', '' );
        if ( $php === '' )
            $php = PHP_SAPI === 'cli' ? PHP_BINARY : PHP_BINDIR . '/php';
        // the installation: eZSys::rootDir(), else the directory four levels above this file (under Velocity the
        // working directory of a pool worker is not the installation)
        $root = realpath( eZSys::rootDir() ?: getcwd() );
        if ( !$root || !is_file( $root . '/extension/expqueue/bin/php/work.php' ) )
            $root = realpath( __DIR__ . '/../../../..' );
        $script = $root . '/extension/expqueue/bin/php/work.php';
        $current = class_exists( 'eZSiteAccess' ) ? eZSiteAccess::current() : null;
        $access = is_array( $current ) && isset( $current['name'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $current['name'] ) : '';
        if ( !is_file( $script ) || !is_executable( $php ) )
        {
            self::$lastError = "no PHP binary or worker script ($php, $script)";
            eZDebug::writeWarning( 'expai: ' . self::$lastError, __METHOD__ );
            return 0;
        }
        $class = preg_replace( '/[^a-z0-9_]/', '', (string) $class );
        $allowRoot = $config->flag( 'VectorSettings', 'WorkerAllowRoot', false );
        list( $asUser, $refused ) = self::workerUser( $root, $allowRoot );
        if ( $refused !== '' )
        {
            self::$lastError = $refused;
            eZDebug::writeWarning( 'expai: ' . self::$lastError, __METHOD__ );
            return 0;
        }
        // setsid only where the system has it (macOS has not), else nohup alone; root only when the site says so; no
        // worker at all when the installation's directory cannot be entered
        $command = 'cd ' . escapeshellarg( $root ) . ' || exit 1; D=; command -v setsid > /dev/null 2>&1 && D=setsid; nohup $D '
                 . ( $asUser !== '' ? 'runuser -u ' . escapeshellarg( $asUser ) . ' -- ' : '' ) . escapeshellarg( $php ) . ' ' . escapeshellarg( $script )
                 . ' --until-empty --max-seconds=' . max( 30, min( 3600, (int) $maxSeconds ) ) . ( $class !== '' ? ' --class=' . $class : '' ) . ' --origin=cli'
                 . ( $allowRoot && $asUser === '' ? ' --allow-root-user' : '' )
                 // the siteaccess of this request: expai may be active for the admin siteaccesses only
                 . ( $access !== '' ? ' -s ' . escapeshellarg( $access ) : '' ) . ' > /dev/null 2>&1';
        $started = 0;
        for ( $i = 0; $i < $count; $i++ )
        {
            if ( !self::launch( $command ) )
            {
                self::$lastError = 'the shell for the worker process could not be started (no process id)';
                eZDebug::writeWarning( 'expai: ' . self::$lastError, __METHOD__ );
                break;
            }
            $started++;
        }
        return $started;
    }

    /**
     * Who runs the worker when this process is root and the site does not allow a root worker: the owner of the
     * installation's var/ directory (the user the web server writes as), through runuser.
     *
     * @param string $root the installation
     * @param bool $allowRoot [VectorSettings] WorkerAllowRoot
     * @param int|null $uid the user of this process (default posix_geteuid())
     * @return array( string user to run the worker as ('' = this process's user), string why no worker is started ('' = start) )
     */
    protected static function workerUser( $root, $allowRoot, $uid = null )
    {
        if ( $uid === null )
            $uid = function_exists( 'posix_geteuid' ) ? posix_geteuid() : -1;
        if ( (int) $uid !== 0 || $allowRoot )
            return array( '', '' );
        $owner = @fileowner( rtrim( (string) $root, '/' ) . '/var' );
        $info = $owner !== false && $owner !== 0 && function_exists( 'posix_getpwuid' ) ? posix_getpwuid( $owner ) : false;
        $name = is_array( $info ) && isset( $info['name'] ) ? (string) $info['name'] : '';
        if ( $name === '' || !preg_match( '/^[A-Za-z0-9._-]+$/', $name ) || !self::hasRunuser() )
            return array( '', 'this process runs as root and var/ belongs to root or runuser is missing: no worker is started (set [VectorSettings] WorkerAllowRoot=enabled to allow a root worker; the cron part of expqueue still runs the jobs)' );
        return array( $name, '' );
    }

    /** @return bool the system has runuser (util-linux) */
    protected static function hasRunuser()
    {
        foreach ( array( '/usr/sbin/runuser', '/sbin/runuser', '/usr/bin/runuser', '/bin/runuser' ) as $path )
            if ( is_executable( $path ) )
                return true;
        return false;
    }

}
