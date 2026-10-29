<?php
/**
 * PHPUnit bootstrap of the expai suite (doc/testing.md).
 *
 * Starts the Exponential installation this extension is installed in (EXPONENTIAL_ROOT names another one) the way
 * bin/php/ezexec.php does: eZScript with modules and extensions, no session, on the siteaccess EXPAI_TEST_SITEACCESS
 * (default admin). Mail goes to the file transport for the whole run, whatever the site's settings say. Where the
 * database cannot be reached the kernel is still loaded, and the tests that need the database skip themselves
 * (expAiTestInstallation::requireOrSkip()).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$checkout = dirname( __DIR__, 2 );
$root = getenv( 'EXPONENTIAL_ROOT' );
if ( !is_string( $root ) || $root === '' )
    $root = dirname( $checkout, 2 );
$root = rtrim( $root, '/' );
if ( !is_file( $root . '/autoload.php' ) )
{
    fwrite( STDERR, "expai tests: no Exponential root at '$root'. Set EXPONENTIAL_ROOT.\n" );
    exit( 1 );
}
define( 'EXPAI_TEST_CHECKOUT', $checkout );
define( 'EXPAI_TEST_ROOT', $root );

chdir( $root );
if ( is_file( $root . '/vendor/autoload.php' ) )
    require_once $root . '/vendor/autoload.php';
require_once $root . '/autoload.php';

// the suite's own classes: everything under tests/ (support/, the base test cases, the mock servers) through the
// kernel's tests autoload, as the extension array leaves tests/ out; on a kernel without it, support/ through a map of
// its own, before the kernel's autoload can map anything of the same name
$supportLoader = static function ( $class )
{
    static $map = null;
    if ( $map === null )
    {
        $map = array();
        foreach ( glob( __DIR__ . '/support/*.php' ) as $file )
        {
            if ( preg_match_all( '/^\s*(?:abstract\s+|final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', (string) file_get_contents( $file ), $m ) )
                foreach ( $m[1] as $name )
                    $map[strtolower( $name )] = $file;
        }
    }
    if ( isset( $map[strtolower( $class )] ) )
        require_once $map[strtolower( $class )];
};
if ( method_exists( 'ezpAutoloader', 'enableTests' ) )
{
    ezpAutoloader::enableTests( dirname( __DIR__ ) );
}
else
{
    spl_autoload_register( $supportLoader, true, true );
}

expAiTestInstallation::boot( getenv( 'EXPAI_TEST_SITEACCESS' ) ?: 'admin' );

// the audit of this process writes into a throwaway directory, never into the site's audit trail
define( 'EXPAI_TEST_AUDIT_DIR', expAiTestMockServer::tmp( 'audit' ) );
expAiTestAudit::isolate( EXPAI_TEST_AUDIT_DIR );
register_shutdown_function( static function () { restore_error_handler(); expAiTestMockServer::removeTree( EXPAI_TEST_AUDIT_DIR ); } );
