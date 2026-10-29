<?php
/**
 * The extensions expai works with (expqueue, expservices, expmcp) are used only when they are active in the current
 * siteaccess, not merely because their classes can be loaded (expAiConfig::extensionActive()).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiExtensionActiveTest extends expAiCoreTestCase
{
    public function testAnExtensionCountsOnlyWhenItIsActive(): void
    {
        $this->assertTrue( expAiConfig::extensionActive( 'expqueue', array( 'ezjscore', 'expqueue' ) ) );
        $this->assertFalse( expAiConfig::extensionActive( 'expqueue', array( 'ezjscore', 'expqueuex' ) ) );
        $this->assertFalse( expAiConfig::extensionActive( 'expqueue', array() ) );
        $this->assertSame( in_array( 'expai', eZExtension::activeExtensions(), true ), expAiConfig::extensionActive( 'expai' ) );
    }

    public function testTheDependenciesFollowTheActiveExtensions(): void
    {
        if ( !expAiConfig::extensionActive( 'expservices' ) )
            $this->assertFalse( expAiServiceTools::available(), 'expservices is not active, its classes must not be used' );
        if ( !expAiConfig::extensionActive( 'expmcp' ) )
        {
            $seam = expAiMcp::$bridgeSeam;
            expAiMcp::$bridgeSeam = null;
            $this->cleanup( static function () use ( $seam ) { expAiMcp::$bridgeSeam = $seam; } );
            $this->assertFalse( expAiMcp::hasBridgeSeam(), 'expmcp is not active, its bridge must not be used' );
        }
        // the queue asks the database only when expqueue is active
        if ( !expAiConfig::extensionActive( 'expqueue' ) )
            $this->assertFalse( expAiQueue::available(), 'expqueue is not active, the queue must not be used' );
        foreach ( array( 'classes/queue/expaiqueue.php' => 'expqueue', 'classes/tools/expaiservicetools.php' => 'expservices', 'classes/mcp/expaimcp.php' => 'expmcp' ) as $file => $extension )
            $this->assertStringContainsString( "expAiConfig::extensionActive( '$extension' )", (string) file_get_contents( EXPAI_TEST_CHECKOUT . '/' . $file ), $file );
    }
}
