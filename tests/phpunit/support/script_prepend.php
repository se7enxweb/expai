<?php
/**
 * Prepended (auto_prepend_file) to every script test that expAiScriptRunner runs:
 *  - EXPAI_TEST_AUDIT_DIR: the kernel's audit writes into that directory (expAiTestAudit), never into the site's
 *    audit trail. The audit settings class is loaded by this file's autoloader, which points it there at once; a
 *    script that sets up an audit of its own (expaiaudit_test.php) replaces it as before.
 *  - with coverage: tests/phpunit/support/coverage_prepend.php.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

( static function ()
{
    $audit = (string) getenv( 'EXPAI_TEST_AUDIT_DIR' );
    if ( $audit !== '' )
    {
        spl_autoload_register( static function ( $class ) use ( $audit )
        {
            if ( strtolower( $class ) !== 'expauditconfig' || !is_file( 'kernel/classes/audit/expauditconfig.php' ) )
                return;
            require_once 'kernel/classes/audit/expauditconfig.php';
            require_once __DIR__ . '/expaitestaudit.php';
            expAiTestAudit::isolate( $audit, true );
        } );
    }
} )();

require __DIR__ . '/coverage_prepend.php';
