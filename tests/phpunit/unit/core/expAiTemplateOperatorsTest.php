<?php
/**
 * The template operator expai_json: JSON for <script type="application/json"> blocks. No database.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';
require_once EXPAI_TEST_CHECKOUT . '/autoloads/expaitemplateoperators.php';

class expAiTemplateOperatorsTest extends expAiCoreTestCase
{
    public function testTheOperatorIsRegistered(): void
    {
        $operators = new expAiTemplateOperators();
        $this->assertContains( 'expai_json', $operators->operatorList() );
        $this->assertArrayHasKey( 'expai_json', $operators->namedParameterList() );
    }

    public function testMarkupInAValueCannotEndTheScriptElement(): void
    {
        $json = expAiTemplateOperators::json( array( 'label' => '</script><script>alert("x")</script> & <!-- \'q\'' ) );
        foreach ( array( '<', '>', '&', "'" ) as $char )
            $this->assertStringNotContainsString( $char, $json, "'$char' is escaped" );
        $decoded = json_decode( $json, true );
        $this->assertSame( '</script><script>alert("x")</script> & <!-- \'q\'', $decoded['label'], 'the value survives the round trip' );
    }

    public function testBrokenUtf8IsSubstitutedNotDropped(): void
    {
        $json = expAiTemplateOperators::json( array( 'a' => "ok\xC3(", 'b' => 'fine' ) );
        $decoded = json_decode( $json, true );
        $this->assertIsArray( $decoded, 'one broken string does not empty the whole block' );
        $this->assertSame( 'fine', $decoded['b'] );
        $this->assertStringContainsString( "\u{FFFD}", $decoded['a'] );
    }

    public function testAnUnencodableValueGivesNull(): void
    {
        $this->assertSame( 'null', expAiTemplateOperators::json( NAN ) );
    }

    public function testModifyWritesTheJson(): void
    {
        $operators = new expAiTemplateOperators();
        $value = array( 'n' => 1 );
        $operators->modify( null, 'expai_json', array(), '', '', $value, array() );
        $this->assertSame( '{"n":1}', $value );
    }

    public function testNoTemplateAddsAVersionQueryToAnAssetAddress(): void
    {
        // the packer puts the change time into the name of the packed file; a ?v= on a link is not used (doc/architecture.md)
        $this->assertNotContains( 'expai_version', ( new expAiTemplateOperators() )->operatorList() );
        $templates = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( EXPAI_TEST_CHECKOUT . '/design', FilesystemIterator::SKIP_DOTS ) );
        foreach ( $templates as $file )
            if ( substr( $file->getFilename(), -4 ) === '.tpl' )
                $this->assertDoesNotMatchRegularExpression( '/\.(js|css)[^"\'\s>]*\?v=/', (string) file_get_contents( $file->getPathname() ), $file->getPathname() );
    }

    public function testOnlyUnknownIsTranslatedInARetention(): void
    {
        foreach ( array( 'none', 'zdr', '30d', '' ) as $retention )
            $this->assertSame( $retention, expAiTemplateOperators::retention( $retention ) );
        $this->assertNotSame( '', expAiTemplateOperators::retention( 'Unknown' ) );
    }
}
