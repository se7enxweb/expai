<?php
/**
 * Loaded by script_prepend.php in a script test that expAiScriptRunner runs, while the suite measures coverage: when
 * Xdebug is loaded and EXPAI_COVERAGE_FILE is set, the lines (and with EXPAI_COVERAGE_BRANCH=1 the branches) the
 * script runs in the directories of EXPAI_COVERAGE_INCLUDE are written to that file when the script ends, and the
 * test that ran it adds them to the suite's coverage. Without those it does nothing.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

( static function ()
{
    $file = (string) getenv( 'EXPAI_COVERAGE_FILE' );
    if ( $file === '' || !function_exists( 'xdebug_start_code_coverage' ) )
        return;
    $dirs = array_values( array_filter( explode( PATH_SEPARATOR, (string) getenv( 'EXPAI_COVERAGE_INCLUDE' ) ) ) );
    if ( $dirs && function_exists( 'xdebug_set_filter' ) )
        xdebug_set_filter( XDEBUG_FILTER_CODE_COVERAGE, XDEBUG_PATH_INCLUDE, $dirs );
    $flags = XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE | ( getenv( 'EXPAI_COVERAGE_BRANCH' ) === '1' ? XDEBUG_CC_BRANCH_CHECK : 0 );
    xdebug_start_code_coverage( $flags );
    // registered from a shutdown function, so it runs after the script's own shutdown functions
    register_shutdown_function( static function () use ( $file )
    {
        register_shutdown_function( static function () use ( $file )
        {
            $data = xdebug_get_code_coverage();
            xdebug_stop_code_coverage( false );
            // per process: a job a worker forks runs this too, and must not overwrite its parent's lines
            file_put_contents( $file . '.' . getmypid(), serialize( $data ) );
        } );
    } );
} )();
