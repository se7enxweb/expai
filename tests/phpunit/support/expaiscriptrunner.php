<?php
/**
 * File containing the expAiScriptRunner class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Runs a script test of extension/expai/tests the way its header says (php bin/php/ezexec.php <script> ...) in a
 * process of its own, and reads its report: one "ok" or "FAIL" line per check and a closing PASS or FAIL line.
 *
 * While the suite measures coverage with Xdebug, the script runs with Xdebug too (tests/phpunit/support/
 * coverage_prepend.php) and what it ran in extension/expai/classes is added to the coverage of the test that ran it.
 * Set EXPAI_XDEBUG_SO to the Xdebug module when the PHP of the suite loads it with -d zend_extension (the scripts are
 * started with the same option); without it the scripts' PHP is expected to load Xdebug from its own settings.
 */
class expAiScriptRunner
{
    /**
     * @param string $script file name in extension/expai/tests (expaicost_test.php)
     * @param array $env name => value, added to the environment of the suite
     * @param array $arguments more arguments of ezexec.php (-s admin)
     * @param int $timeout seconds
     * @return array array( 'exit' => int, 'output' => string, 'ok' => int, 'fail' => string[], 'status' => string, 'checks' => int|null )
     */
    public static function run( $script, array $env = array(), array $arguments = array(), $timeout = 1200 )
    {
        // GNU timeout (gtimeout from coreutils on macOS, where timeout is missing); without it the script runs unlimited
        $limit = self::timeoutCommand();
        $command = $limit !== '' ? array( $limit, (string) (int) $timeout, PHP_BINARY ) : array( PHP_BINARY );
        $coverageFile = null;
        $coverage = self::coverage();
        if ( $coverage )
        {
            $so = (string) getenv( 'EXPAI_XDEBUG_SO' );
            if ( $so !== '' )
                array_push( $command, '-d', 'zend_extension=' . $so );
            array_push( $command, '-d', 'xdebug.mode=coverage' );
            $coverageFile = expAiTestMockServer::tmp( 'coverage' ) . '/raw.ser';
            $env['EXPAI_COVERAGE_FILE'] = $coverageFile;
            $env['EXPAI_COVERAGE_INCLUDE'] = EXPAI_TEST_CHECKOUT . '/classes';
            $env['EXPAI_COVERAGE_BRANCH'] = self::paths( $coverage ) ? '1' : '0';
        }
        // every script: the audit into a throwaway directory (script_prepend.php), and the coverage hook
        array_push( $command, '-d', 'auto_prepend_file=' . EXPAI_TEST_CHECKOUT . '/tests/phpunit/support/script_prepend.php' );
        $auditDir = expAiTestMockServer::tmp( 'audit' );
        $env['EXPAI_TEST_AUDIT_DIR'] = $auditDir;
        array_push( $command, 'bin/php/ezexec.php', 'extension/expai/tests/' . $script );
        foreach ( $arguments as $argument )
            $command[] = $argument;
        if ( function_exists( 'posix_geteuid' ) && posix_geteuid() === 0 )
            $command[] = '--allow-root-user';

        $environment = getenv();
        foreach ( $env as $name => $value )
            $environment[$name] = (string) $value;
        unset( $environment['XDEBUG_MODE'] );
        if ( $coverage )
            $environment['XDEBUG_MODE'] = 'coverage';

        $process = proc_open( $command, array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ),
                              $pipes, EXPAI_TEST_ROOT, $environment );
        if ( !is_resource( $process ) )
            throw new RuntimeException( "could not start $script" );
        $output = stream_get_contents( $pipes[1] );
        fclose( $pipes[1] );
        $exit = proc_close( $process );
        expAiTestMockServer::removeTree( $auditDir );

        if ( $coverageFile !== null )
        {
            // one file per process of the script (a worker may fork for a job)
            foreach ( (array) glob( $coverageFile . '.*' ) as $processFile )
            {
                $raw = unserialize( (string) file_get_contents( $processFile ) );
                if ( is_array( $raw ) && $raw )
                    $coverage->append( self::paths( $coverage )
                                       ? SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData::fromXdebugWithPathCoverage( $raw )
                                       : SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData::fromXdebugWithoutPathCoverage( $raw ) );
            }
            expAiTestMockServer::removeTree( dirname( $coverageFile ) );
        }

        // EXPAI_TEST_SCRIPT_LOG=<directory> keeps the output of every script (<name>.out)
        $log = (string) getenv( 'EXPAI_TEST_SCRIPT_LOG' );
        if ( $log !== '' && is_dir( $log ) )
            file_put_contents( rtrim( $log, '/' ) . '/' . basename( $script, '.php' ) . '.out', $output );

        return self::parse( (string) $output ) + array( 'exit' => (int) $exit, 'output' => (string) $output );
    }

    /**
     * @param string $output
     * @return array array( 'ok' => int, 'fail' => string[], 'status' => 'PASS'|'FAIL'|'', 'checks' => int|null )
     */
    public static function parse( $output )
    {
        $ok = 0;
        $fail = array();
        $status = '';
        $checks = null;
        foreach ( preg_split( '/\r?\n/', $output ) as $line )
        {
            $line = rtrim( $line );
            // a check prints "ok    <label>" or "FAIL  <label>"; the closing line is "PASS ..." or "FAIL ..."
            if ( strncmp( $line, 'ok    ', 6 ) === 0 )
                ++$ok;
            elseif ( strncmp( $line, 'FAIL  ', 6 ) === 0 )
                $fail[] = $line;
            elseif ( preg_match( '/^(PASS|FAIL)\b/', $line, $m ) )
                $status = $m[1];
            if ( preg_match( '/^(?:PASS|FAIL)?\s*\(?(\d+) checks\b/', $line, $m ) )
                $checks = (int) $m[1];
        }
        return array( 'ok' => $ok, 'fail' => $fail, 'status' => $status, 'checks' => $checks );
    }

    /**
     * @param SebastianBergmann\CodeCoverage\CodeCoverage $coverage
     * @return bool the suite collects branches and paths (--path-coverage): PHPUnit switches it on in the driver only,
     *              so the driver is asked
     */
    private static function paths( $coverage )
    {
        if ( $coverage->collectsBranchAndPathCoverage() )
            return true;
        $property = new ReflectionProperty( get_class( $coverage ), 'driver' );
        $driver = $property->getValue( $coverage );
        return $driver && $driver->collectsBranchAndPathCoverage();
    }

    /** @return SebastianBergmann\CodeCoverage\CodeCoverage|null the coverage the suite collects with Xdebug, if any */
    private static function coverage()
    {
        if ( !extension_loaded( 'xdebug' ) || !class_exists( 'PHPUnit\Runner\CodeCoverage' ) )
            return null;
        $runner = PHPUnit\Runner\CodeCoverage::instance();
        if ( !$runner->isActive() )
            return null;
        return $runner->codeCoverage();
    }

    /** @return string timeout or gtimeout from the PATH, '' when neither is installed */
    private static function timeoutCommand()
    {
        static $found = null;
        if ( $found === null )
        {
            $found = '';
            foreach ( array( 'timeout', 'gtimeout' ) as $name )
            {
                $path = trim( (string) shell_exec( 'command -v ' . $name . ' 2>/dev/null' ) );
                if ( $path !== '' && is_executable( $path ) )
                {
                    $found = $path;
                    break;
                }
            }
        }
        return $found;
    }
}
