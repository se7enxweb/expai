<?php
/**
 * File containing the expAiTestInstallation class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Starts the installation for the suite once per process and answers whether its database can be used. The tests that
 * need the database call requireOrSkip(); on a checkout without an installed site they are skipped with the reason.
 */
class expAiTestInstallation
{
    /** @var bool */
    private static $booted = false;

    /** @var string|null|false false: not checked yet, null: usable, string: why not */
    private static $reason = false;

    /** @var string */
    private static $siteAccess = 'admin';

    /**
     * @param string $siteAccess
     */
    public static function boot( $siteAccess )
    {
        if ( self::$booted )
            return;
        self::$booted = true;
        self::$siteAccess = (string) $siteAccess;

        $GLOBALS['eZDebugLogFileEnabled'] = false;
        try
        {
            $script = eZScript::instance( array( 'use-session' => false, 'use-modules' => true, 'use-extensions' => true ) );
            $script->startup();
            $script->setUseSiteAccess( self::$siteAccess );
            $script->initialize();
            eZExecution::setCleanExit();
        }
        catch ( Throwable $e )
        {
            self::$reason = 'the installation did not start: ' . trim( strtok( $e->getMessage(), "\n" ) );
        }
        self::forceFileMail();
        self::englishTexts();
        self::blockProviders();
    }

    /**
     * The tests compare the texts and numbers expai shows with their English source: whatever language the site
     * runs in (a ger-DE siteaccess translates every message and writes 1,2k), the run uses the untranslated texts
     * and the eng-GB locale. Set in memory, again before every test (a test may reset the INI instances).
     */
    public static function englishTexts()
    {
        if ( !class_exists( 'eZINI' ) )
            return;
        $ini = eZINI::instance( 'site.ini' );
        if ( $ini->variable( 'RegionalSettings', 'Locale' ) === 'eng-GB' && $ini->variable( 'RegionalSettings', 'TextTranslation' ) === 'disabled' )
            return;
        $ini->setVariable( 'RegionalSettings', 'Locale', 'eng-GB' );
        $ini->setVariable( 'RegionalSettings', 'TextTranslation', 'disabled' );
        if ( class_exists( 'eZTranslatorManager' ) )
            eZTranslatorManager::resetTranslations();
        if ( class_exists( 'ezpI18n' ) )
            ezpI18n::reset();
    }

    /**
     * Mail of the run goes to files below var/, never to a mail server: the override of site.ini wins over a
     * siteaccess, so the transport is set in memory after every setting is read.
     */
    public static function forceFileMail()
    {
        if ( class_exists( 'eZINI' ) )
            eZINI::instance( 'site.ini' )->setVariable( 'MailSettings', 'Transport', 'file' );
    }

    /**
     * No test reaches a real provider through the site's settings: in the site's expai.ini (the instance
     * expAiConfig::instance() reads) every key and token is emptied and every endpoint points at a closed loopback
     * port. A test that needs a provider sets its own in memory (a stub, or a mock server of tests/mock). Applied
     * once at the start and again before every test (a test may reset the INI instances).
     */
    public static function blockProviders()
    {
        if ( !class_exists( 'eZINI' ) )
            return;
        $ini = eZINI::instance( 'expai.ini' );
        foreach ( $ini->groups() as $group => $values )
        {
            if ( !preg_match( '/^(AISettings|Provider_|SearchProvider_|McpServer_)/', $group ) )
                continue;
            foreach ( (array) $values as $name => $value )
            {
                if ( !is_string( $value ) || $value === '' )
                    continue;
                if ( preg_match( '/(Key|Token|Secret)$/', $name ) )
                    $ini->setVariable( $group, $name, '' );
                elseif ( preg_match( '#^(https?|wss?)://(?!127\.0\.0\.1[:/]|localhost[:/])#i', $value ) )
                    $ini->setVariable( $group, $name, 'http://127.0.0.1:9/blocked-by-the-expai-tests' );
            }
        }
        // the site's own region rule (e.g. DefaultRegions[]=eu;local) would keep content from the mock providers of the
        // tests, which declare other regions; the shipped default has none, and a test of the data policy sets its own
        if ( $ini->hasVariable( 'DataPolicy', 'DefaultRegions' ) )
            $ini->setVariable( 'DataPolicy', 'DefaultRegions', array() );
    }

    /** @return string */
    public static function siteAccess()
    {
        return self::$siteAccess;
    }

    /**
     * @return string|null null when the database is usable, else why not
     */
    public static function unavailableReason()
    {
        if ( self::$reason !== false )
            return self::$reason;
        self::$reason = null;
        try
        {
            $db = eZDB::instance();
            if ( !$db->isConnected() )
                throw new RuntimeException( 'no database connection' );
            $rows = $db->arrayQuery( "SELECT COUNT(*) AS c FROM ezuser WHERE login = 'admin'" );
            if ( !is_array( $rows ) || !isset( $rows[0]['c'] ) || (int) $rows[0]['c'] < 1 )
                throw new RuntimeException( 'no installed site in the database (no admin user)' );
            // eZTableList() of some engines lists ez* tables only: ask the table itself
            $db->setErrorHandling( eZDB::ERROR_HANDLING_EXCEPTIONS );
            try
            {
                $count = $db->arrayQuery( 'SELECT COUNT(*) AS c FROM expai_usage_log' );
            }
            catch ( Throwable $e )
            {
                $count = false;
            }
            $db->setErrorHandling( eZDB::ERROR_HANDLING_STANDARD );
            if ( !is_array( $count ) )
                throw new RuntimeException( 'the expai tables are not installed (php extension/expai/bin/php/expaischema.php --install)' );
        }
        catch ( Throwable $e )
        {
            self::$reason = 'needs an installed site with the expai tables: ' . trim( strtok( $e->getMessage(), "\n" ) );
        }
        return self::$reason;
    }

    /**
     * Skips the running test (or, from setUpBeforeClass(), the class) unless the database is usable.
     */
    public static function requireOrSkip()
    {
        $reason = self::unavailableReason();
        if ( $reason !== null )
            PHPUnit\Framework\TestCase::markTestSkipped( $reason );
    }
}
