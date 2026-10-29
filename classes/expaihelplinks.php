<?php
/**
 * The addresses of the extension's documentation.
 *
 * The documentation is read in the help center of the administration (exphelp, Exponential 6.0.15 and later,
 * doc/index.md "Help in the administration interface"), which reads, renders and searches the documentation of every
 * active extension (ids ext/expai/<path below doc/>, and ext/expai/README.md, INSTALL.md, USAGE.md for the files at
 * the top of the extension). The extension needs that help center: on an older kernel the links lead nowhere useful.
 *
 * The links of the extension lead straight to the help center: the menu entry EI: Help (settings/menu.ini.append.php)
 * to its index, exphelp/extensions/expai, the dashboard's Help link and the "?" links to their page,
 * exphelp/viewer/ext/expai/<page>.md (link(), the template operator expai_help_url). The page names are: readme,
 * install and usage for the files at the top of the extension, index for doc/index.md, changelog-<version> for
 * doc/changelog/<version>.md, any other name for doc/<name>.md. A name of a page the extension does not have leads to
 * the index.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */
class expAiHelpLinks
{
    /** The extension's folder in the help center */
    const PREFIX = 'ext/expai/';

    /** The extension's index in the help center (its pages and a search limited to them) */
    const INDEX = 'exphelp/extensions/expai';

    /**
     * The help center's page id of a page name, or null for a name that is none.
     *
     * @param string $name readme, install, usage, index, changelog-<version>, or a doc/*.md name without .md
     * @return string|null
     */
    public static function helpId( $name )
    {
        $name = strtolower( trim( (string) $name ) );
        if ( !preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}$/', $name ) )
            return null;
        $top = array( 'readme' => 'README.md', 'install' => 'INSTALL.md', 'usage' => 'USAGE.md' );
        if ( isset( $top[$name] ) )
            return self::PREFIX . $top[$name];
        if ( preg_match( '/^changelog-([0-9][0-9a-z.-]*)$/', $name, $m ) )
            return self::PREFIX . 'changelog/' . $m[1] . '.md';
        if ( !preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $name ) )
            return null;
        return self::PREFIX . $name . '.md';
    }

    /**
     * The file of a page name below the extension's directory (README.md, doc/vector.md, doc/changelog/0.0.1.0.md),
     * or null for a name that is none.
     *
     * @param string $name see helpId()
     * @return string|null
     */
    public static function file( $name )
    {
        $id = self::helpId( $name );
        if ( $id === null )
            return null;
        $path = substr( $id, strlen( self::PREFIX ) );
        return in_array( $path, array( 'README.md', 'INSTALL.md', 'USAGE.md' ), true ) ? $path : 'doc/' . $path;
    }

    /**
     * The page name itself when the extension has that page, otherwise '' (the index).
     *
     * @param string $name see helpId()
     * @return string
     */
    public static function existing( $name )
    {
        $file = (string) $name === '' ? null : self::file( $name );
        return $file !== null && is_file( dirname( __DIR__ ) . '/' . $file ) ? strtolower( trim( (string) $name ) ) : '';
    }

    /**
     * The module address of a page in the help center's viewer (for |ezurl), or the extension's index for an empty
     * or unknown name.
     *
     * @param string $name see helpId()
     * @return string
     */
    public static function url( $name = '' )
    {
        $id = (string) $name === '' ? null : self::helpId( $name );
        return $id === null ? self::INDEX : 'exphelp/viewer/' . $id;
    }

    /**
     * The module address a link of the extension uses (for |ezurl): the page in the help center
     * (exphelp/viewer/ext/expai/<page>.md, or exphelp/extensions/expai for an empty name). A page the extension does
     * not have leads to the index.
     *
     * @param string $name see helpId()
     * @return string
     */
    public static function link( $name = '' )
    {
        return self::url( self::existing( $name ) );
    }
}
