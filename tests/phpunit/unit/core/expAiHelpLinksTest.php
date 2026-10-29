<?php
/**
 * expAiHelpLinks: the extension's documentation is in the help center (exphelp, Exponential 6.0.15 and later); the
 * page names become the page ids there and every link leads straight to it. Reads files and needs no database.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

class expAiHelpLinksTest extends PHPUnit\Framework\TestCase
{
    private static function extension()
    {
        return dirname( __DIR__, 4 );
    }

    public static function setUpBeforeClass(): void
    {
        if ( !class_exists( 'expAiHelpLinks', false ) )
            require_once self::extension() . '/classes/expaihelplinks.php';
    }

    public function testOldPageNamesBecomeHelpCenterIds(): void
    {
        $this->assertSame( 'ext/expai/README.md', expAiHelpLinks::helpId( 'readme' ) );
        $this->assertSame( 'ext/expai/INSTALL.md', expAiHelpLinks::helpId( 'install' ) );
        $this->assertSame( 'ext/expai/USAGE.md', expAiHelpLinks::helpId( 'USAGE' ) );
        $this->assertSame( 'ext/expai/index.md', expAiHelpLinks::helpId( 'index' ) );
        $this->assertSame( 'ext/expai/vector.md', expAiHelpLinks::helpId( 'vector' ) );
        $this->assertSame( 'ext/expai/full-view-panel.md', expAiHelpLinks::helpId( 'full-view-panel' ) );
        $this->assertSame( 'ext/expai/changelog/0.0.1.0.md', expAiHelpLinks::helpId( 'changelog-0.0.1.0' ) );
        foreach ( array( '', '../etc/passwd', 'a/b', 'x.y', '.hidden', 'a b', str_repeat( 'a', 80 ), 'javascript:alert(1)' ) as $bad )
            $this->assertNull( expAiHelpLinks::helpId( $bad ), $bad );
    }

    public function testAddresses(): void
    {
        $this->assertSame( 'exphelp/extensions/expai', expAiHelpLinks::url() );
        $this->assertSame( 'exphelp/extensions/expai', expAiHelpLinks::url( '../x' ) );
        $this->assertSame( 'exphelp/viewer/ext/expai/chains.md', expAiHelpLinks::url( 'chains' ) );
    }

    /** ezinfo.php and extension.xml carry the same version */
    public function testVersionFieldsAgree(): void
    {
        if ( !class_exists( 'expaiInfo', false ) )
            require_once self::extension() . '/ezinfo.php';
        $info = expaiInfo::info();
        $xml = simplexml_load_file( self::extension() . '/extension.xml' );
        $this->assertSame( $info['Version'], (string) $xml->metadata->version );
    }

    public function testLinksLeadStraightToTheHelpCenter(): void
    {
        $this->assertSame( 'exphelp/extensions/expai', expAiHelpLinks::link() );
        $this->assertSame( 'exphelp/viewer/ext/expai/vector.md', expAiHelpLinks::link( 'vector' ) );
        $this->assertSame( 'exphelp/viewer/ext/expai/dashboard.md', expAiHelpLinks::link( 'dashboard' ) );
        $this->assertSame( 'exphelp/viewer/ext/expai/README.md', expAiHelpLinks::link( 'readme' ) );
        $this->assertSame( 'exphelp/extensions/expai', expAiHelpLinks::link( 'no-such-page' ) );
        $this->assertSame( 'exphelp/extensions/expai', expAiHelpLinks::link( 'javascript:alert(1)' ) );
        $this->assertSame( 'exphelp/viewer/ext/expai/README.md', expAiHelpLinks::link( 'README' ) );
        $this->assertSame( 'exphelp/extensions/expai', expAiHelpLinks::link( '../etc/passwd' ) );
    }

    /** The old reader is gone: no redirect view, no GitHub fallback, no menu fallback for a kernel without the help center */
    public function testTheOldReaderIsRemoved(): void
    {
        $root = self::extension();
        $this->assertFileDoesNotExist( $root . '/modules/expai/help.php' );
        $this->assertStringNotContainsString( "\$ViewList['help']", (string) file_get_contents( $root . '/modules/expai/module.php' ) );
        foreach ( array( 'githubUrl', 'menuFallback', 'helpCenter', 'target', 'kernelHasHelpCenter' ) as $method )
            $this->assertFalse( method_exists( 'expAiHelpLinks', $method ), $method );
        $this->assertFalse( defined( 'expAiHelpLinks::GITHUB_REF' ) );
        $this->assertFalse( defined( 'expAiHelpLinks::REDIRECT' ) );
        $this->assertStringNotContainsString( 'menuFallback', (string) file_get_contents( $root . '/settings/site.ini.append.php' ) );
    }

    /** Every "?" link names a page the extension has, and nothing renders the documentation in the extension itself */
    public function testHelpLinksNameExistingPagesAndNoViewerIsLeft(): void
    {
        $root = self::extension();
        $pages = array();
        foreach ( glob( $root . '/design/admin/templates/{,*/,*/*/}*.tpl', GLOB_BRACE ) ?: array() as $file )
            if ( preg_match_all( "/help_link\\.tpl' page='([a-z0-9-]+)'/", (string) file_get_contents( $file ), $m ) )
                $pages = array_merge( $pages, $m[1] );
        $this->assertNotEmpty( $pages );
        foreach ( array_unique( $pages ) as $page )
            $this->assertFileExists( $root . '/doc/' . $page . '.md', "the \"?\" link to $page" );
        $this->assertFileDoesNotExist( $root . '/classes/expaihelp.php' );
        $this->assertFileDoesNotExist( $root . '/design/admin/templates/expai/help.tpl' );
        // the links are built by expAiHelpLinks::link() (expai_help_url); no template names a page address itself
        $this->assertStringContainsString( 'expai_help_url( $page )|ezurl', (string) file_get_contents( $root . '/design/admin/templates/expai/parts/help_link.tpl' ) );
        $this->assertStringContainsString( 'expai_help_url()|ezurl', (string) file_get_contents( $root . '/design/admin/templates/expai/dashboard.tpl' ) );
        foreach ( glob( $root . '/design/admin/templates/{,*/,*/*/}*.tpl', GLOB_BRACE ) ?: array() as $file )
            $this->assertDoesNotMatchRegularExpression( "#'expai/help#", (string) file_get_contents( $file ), $file );
        foreach ( glob( $root . '/design/admin/templates/{,*/,*/*/}*.tpl', GLOB_BRACE ) ?: array() as $file )
            $this->assertDoesNotMatchRegularExpression( "#'exphelp/#", (string) file_get_contents( $file ), $file );
    }
}
