<?php
/**
 * The translation files: the kernel validates every translation.ts against schemas/translation/ts.rng and skips a
 * file that fails, so one misplaced </context> leaves every message of expai untranslated in that language.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

use PHPUnit\Framework\Attributes\DataProvider;

class expAiTranslationFilesTest extends expAiCoreTestCase
{
    /** @return array<string, array{string}> each translation.ts of the extension */
    public static function translationFiles(): array
    {
        $files = array();
        foreach ( glob( __DIR__ . '/../../../../translations/*/translation.ts' ) as $file )
            $files[basename( dirname( $file ) )] = array( $file );
        return $files;
    }

    #[DataProvider( 'translationFiles' )]
    public function testTheFileValidatesAgainstTheKernelSchema( string $file ): void
    {
        $schema = EXPAI_TEST_ROOT . '/schemas/translation/ts.rng';
        if ( !is_file( $schema ) )
            $this->markTestSkipped( 'the installation has no schemas/translation/ts.rng' );
        $doc = new DOMDocument();
        $this->assertTrue( $doc->load( $file ), 'the file is well formed' );
        $previous = libxml_use_internal_errors( true );
        $valid = $doc->relaxNGValidate( $schema );
        $errors = array_map( static function ( $e ) { return trim( $e->message ) . ' (line ' . $e->line . ')'; }, libxml_get_errors() );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        $this->assertTrue( $valid, implode( "\n", $errors ) );
    }

    #[DataProvider( 'translationFiles' )]
    public function testEveryContextIsATopLevelElement( string $file ): void
    {
        $doc = new DOMDocument();
        $doc->load( $file );
        $xpath = new DOMXPath( $doc );
        $this->assertSame( 0, $xpath->query( '//context/context' )->length, 'no context inside another one' );
        $this->assertSame( 1, $xpath->query( "/TS/context[name='extension/expai/audit']/message[source='Exponential Intelligence']" )->length,
                           'the audit source label is translated' );
    }
}
