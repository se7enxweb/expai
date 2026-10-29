<?php
/**
 * File containing the expAiTestAudit class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Keeps the suite out of the site's audit trail: the kernel's audit (expAudit) writes into a throwaway directory
 * with keys of its own for the run, never into the live, hash-chained channel files of var/log/audit. The PHPUnit
 * process is pointed there before every test (a test of the audit may reset the settings), and the script tests get
 * the directory in EXPAI_TEST_AUDIT_DIR (tests/phpunit/support/script_prepend.php points them there).
 */
class expAiTestAudit
{
    /** @var array|null the keys of this run */
    private static $keys = null;

    /**
     * Points the audit at the directory (made when missing). Does nothing on a kernel without the audit.
     *
     * @param string $dir
     * @param bool $handlers the audit's shutdown and error handlers (a script test: true; the PHPUnit process: false)
     */
    public static function isolate( $dir, $handlers = false )
    {
        if ( !class_exists( 'expAuditConfig' ) )
            return;
        $dir = rtrim( $dir, '/' );
        @mkdir( $dir . '/log', 0750, true );
        @mkdir( $dir . '/keys', 0750, true );
        expAuditConfig::setOverride( array(
            'logDir' => $dir . '/log',
            'keyDir' => $dir . '/keys',
            'handlers' => (bool) $handlers,
            // expai's own settings of settings/audit.ini.append.php, so its events are recorded as on the site
            'AuditEventSettings/Branches' => array( 'expai' => 'expAiAuditBranch' ),
            'AuditChannelSettings/Channels' => array( 'content', 'access', 'system', 'commerce', 'read', 'ai' ),
            'AuditSourceSettings/Sources' => array( 'expai' => 'expAiAuditSource' ),
        ) );
        if ( class_exists( 'expAuditKeys' ) )
        {
            if ( self::$keys === null )
            {
                $id = 'k1-test-' . bin2hex( random_bytes( 4 ) );
                self::$keys = array( 'installation' => sprintf( '%s-%s-4%s-a%s-%s', bin2hex( random_bytes( 4 ) ), bin2hex( random_bytes( 2 ) ),
                                                                substr( bin2hex( random_bytes( 2 ) ), 1 ), substr( bin2hex( random_bytes( 2 ) ), 1 ),
                                                                bin2hex( random_bytes( 6 ) ) ),
                                     'active' => $id, 'signing' => array( $id => random_bytes( 32 ) ), 'pseudonym' => random_bytes( 32 ) );
            }
            expAuditKeys::setKnown( self::$keys, $dir . '/keys' );
        }
        if ( class_exists( 'expAuditTaxonomy' ) && method_exists( 'expAuditTaxonomy', 'reset' ) )
            expAuditTaxonomy::reset();
        if ( class_exists( 'expAudit' ) && method_exists( 'expAudit', 'resetAll' ) )
            expAudit::resetAll();
    }
}
