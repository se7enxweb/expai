<?php
/**
 * File containing the expAiScript class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The siteaccess of a command line script of expai (bin/php). One tree can serve several sites, each with its own
 * database; a script that fell back to the default siteaccess would read or write the wrong one without a word. So:
 *
 *  - requireSiteAccess(), between getOptions() and initialize(): -s <siteaccess> is mandatory (modes that touch no
 *    database, such as --print, are exempt);
 *  - announce(), after initialize(): stops when the siteaccess does not exist (a mistyped -s would run on the
 *    default settings), prints the siteaccess and the database in use (to stderr, so that the output of a script
 *    stays machine-readable) and stops when expai is not active in that siteaccess.
 *
 * The order of a script is always startup(), getOptions(), requireSiteAccess(), initialize(), announce().
 */
class expAiScript
{
    /**
     * @param array $options the result of eZScript::getOptions()
     * @return string why the script may not run, '' when it may
     */
    public static function missingSiteAccess( $options, $exempt = false )
    {
        if ( $exempt )
            return '';
        $siteAccess = is_array( $options ) && isset( $options['siteaccess'] ) ? trim( (string) $options['siteaccess'] ) : '';
        return $siteAccess === '' ? 'the siteaccess is mandatory: -s <siteaccess> (or --siteaccess=<siteaccess>)' : '';
    }

    /**
     * @param string $siteAccess the siteaccess of -s
     * @param string[] $available the names of [SiteAccessSettings] AvailableSiteAccessList[] (eZSiteAccess::exists())
     * @param bool $hasSettings the siteaccess has a settings directory (eZSiteAccess::findPathToSiteAccess())
     * @return string why the script may not run ('' when the siteaccess exists): the kernel runs a mistyped -s on
     *                the default settings without a word
     */
    public static function unknownSiteAccess( $siteAccess, array $available, $hasSettings )
    {
        if ( !in_array( (string) $siteAccess, $available, true ) )
            return "there is no siteaccess '$siteAccess' (not in [SiteAccessSettings] AvailableSiteAccessList[])";
        if ( !$hasSettings )
            return "the siteaccess '$siteAccess' has no settings directory";
        return '';
    }

    /**
     * @param string[] $activeExtensions eZExtension::activeExtensions() of the siteaccess
     * @return string why the script may not run, '' when expai is active
     */
    public static function inactive( array $activeExtensions, $siteAccess )
    {
        return in_array( 'expai', $activeExtensions, true ) ? '' : "expai is not active in the siteaccess '$siteAccess'";
    }

    /**
     * Stops the script when the siteaccess is missing. Call it between getOptions() and initialize().
     * @param bool $exempt the chosen mode touches no database
     */
    public static function requireSiteAccess( eZScript $script, $options, $exempt = false )
    {
        $message = self::missingSiteAccess( $options, $exempt );
        if ( $message === '' )
            return;
        eZCLI::instance()->error( 'FAIL ' . $message );
        $script->shutdown( 1 );
    }

    /**
     * Prints the siteaccess and the database and stops when expai is not active there. Call it after initialize().
     * @param bool $exempt the chosen mode touches no database (nothing is printed or checked)
     */
    public static function announce( eZScript $script, $exempt = false )
    {
        if ( $exempt )
            return;
        $siteAccess = (string) $script->usedSiteAccess();
        $available = array();
        foreach ( eZSiteAccess::siteAccessList() as $item )
            $available[] = (string) $item['name'];
        $message = self::unknownSiteAccess( $siteAccess, $available, eZSiteAccess::findPathToSiteAccess( $siteAccess ) !== false );
        if ( $message !== '' )
        {
            eZCLI::instance()->error( 'FAIL ' . $message );
            $script->shutdown( 1 );
        }
        $db = eZDB::instance();
        fwrite( STDERR, sprintf( "expai: siteaccess %s, database %s\n", $siteAccess, (string) $db->DB ) );
        $message = self::inactive( eZExtension::activeExtensions(), $siteAccess );
        if ( $message === '' )
            return;
        eZCLI::instance()->error( 'FAIL ' . $message );
        $script->shutdown( 1 );
    }
}
