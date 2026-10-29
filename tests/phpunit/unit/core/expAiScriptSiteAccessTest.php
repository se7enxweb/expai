<?php
/**
 * expAiScript: the command line scripts of bin/php need -s, print the siteaccess and database, and do not run where
 * expai is not active; every script calls it in the order startup(), getOptions(), requireSiteAccess(),
 * initialize(), announce().
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiScriptSiteAccessTest extends expAiCoreTestCase
{
    public function testTheSiteAccessIsMandatory(): void
    {
        $this->assertStringStartsWith( 'the siteaccess is mandatory', expAiScript::missingSiteAccess( array( 'siteaccess' => false ) ) );
        $this->assertStringStartsWith( 'the siteaccess is mandatory', expAiScript::missingSiteAccess( array( 'siteaccess' => '  ' ) ) );
        $this->assertStringStartsWith( 'the siteaccess is mandatory', expAiScript::missingSiteAccess( array() ) );
        $this->assertStringStartsWith( 'the siteaccess is mandatory', expAiScript::missingSiteAccess( false ) );
        $this->assertSame( '', expAiScript::missingSiteAccess( array( 'siteaccess' => 'site_admin' ) ) );
        $this->assertSame( '', expAiScript::missingSiteAccess( array( 'siteaccess' => false ), true ), 'a mode without a database is exempt' );
    }

    public function testExpaiMustBeActiveInTheSiteAccess(): void
    {
        $this->assertSame( '', expAiScript::inactive( array( 'ezjscore', 'expai' ), 'site_admin' ) );
        $this->assertSame( "expai is not active in the siteaccess 'site'", expAiScript::inactive( array( 'ezjscore', 'expaix' ), 'site' ) );
        $this->assertSame( "expai is not active in the siteaccess 'site'", expAiScript::inactive( array(), 'site' ) );
    }

    public function testEveryScriptChecksTheSiteAccessInTheRightOrder(): void
    {
        $scripts = glob( EXPAI_TEST_CHECKOUT . '/bin/php/*.php' );
        $this->assertNotEmpty( $scripts );
        foreach ( $scripts as $file )
        {
            $source = (string) file_get_contents( $file );
            $name = basename( $file );
            $positions = array();
            foreach ( array( '$script->startup();', '$script->getOptions(', 'expAiScript::requireSiteAccess( $script, $options',
                             '$script->initialize();', 'expAiScript::announce( $script' ) as $call )
            {
                $this->assertSame( 1, substr_count( $source, $call ), "$name: $call once" );
                $positions[] = strpos( $source, $call );
            }
            $sorted = $positions;
            sort( $sorted );
            $this->assertSame( $sorted, $positions, "$name: startup, getOptions, requireSiteAccess, initialize, announce" );
        }
    }
}
