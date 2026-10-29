<?php
/**
 * The rich text segmenter (expAiSegmenterRichText) and the sentence splitter (expAiSentences) beyond the script
 * tests: HTML values, block-level custom tags, atoms, translations that do not fit the markup of their block, table
 * cells that cannot be inserted or removed alone, and abbreviations of a language set in the settings.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

class expAiRichTextSegmenterTest extends expAiTestCase
{
    private static function xml( $inner )
    {
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<section xmlns:image="http://ez.no/namespaces/ezpublish3/image/" xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" '
             . 'xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/">' . $inner . '</section>' . "\n";
    }

    private function refused( callable $fn, $needle )
    {
        try
        {
            $fn();
        }
        catch ( expAiException $e )
        {
            $this->assertStringContainsString( $needle, $e->getMessage() . ' ' . $e->detail() );
            return;
        }
        $this->fail( "no refusal containing '$needle'" );
    }

    public function testHtmlValuesAndTheirBlocks(): void
    {
        $segmenter = new expAiSegmenterRichText();
        $this->assertSame( array(), $segmenter->segment( '' ) );
        $blocks = $segmenter->segment( '<h2>Opening</h2><p>We bake <a href="https://example.org">bread</a>.<br>Daily.</p><ul><li>Rolls</li></ul><table><tr><td>Cell</td></tr></table>' );
        $this->assertSame( array( 'heading', 'paragraph', 'list_item', 'table_cell' ), array_column( $blocks, 'type' ) );
        $this->assertStringContainsString( '<1>bread</1>', $blocks[1]['coded'] );
        $composed = $segmenter->compose( '<p>We bake <a href="https://example.org">bread</a>.</p>', array( 0 => 'Wir backen <1>Brot</1>.' ) );
        $this->assertSame( '<p>Wir backen <a href="https://example.org">Brot</a>.</p>', $composed, 'an HTML value stays HTML' );
        $this->assertSame( '', $segmenter->compose( '', array( 0 => 'x' ) ), 'an empty value has nothing to compose' );
    }

    public function testCustomBlocksAndAtoms(): void
    {
        $segmenter = new expAiSegmenterRichText();
        $value = self::xml( '<paragraph><custom name="factbox"><paragraph>A fact.</paragraph></custom></paragraph>'
                          . '<paragraph>See <embed object_id="12" size="medium"/> and <custom name="quote">inline</custom>.<line>Second line</line></paragraph>' );
        $blocks = $segmenter->segment( $value );
        $this->assertSame( 'custom', $blocks[0]['type'], 'a paragraph inside a block-level custom tag' );
        $this->assertStringContainsString( '[[embed object 12]]', $blocks[1]['text'] );

        // translations that do not fit the markup of the block
        $index = 1;
        $coded = $blocks[$index]['coded'];
        $this->assertMatchesRegularExpression( '~<\d+/>~', $coded, 'the embed is an atom placeholder' );
        $this->refused( function () use ( $segmenter, $value, $index ) { $segmenter->compose( $value, array( $index => 'Siehe <99/>.' ) ); }, 'unknown placeholder 99' );
        preg_match( '~<(\d+)>~', $coded, $open );
        $id = $open[1];
        $this->refused( function () use ( $segmenter, $value, $index, $id ) { $segmenter->compose( $value, array( $index => "<$id>a</$id><$id>b</$id>" ) ); }, 'used twice' );
        $this->refused( function () use ( $segmenter, $value, $index, $id ) { $segmenter->compose( $value, array( $index => "<$id>open" ) ); }, 'not closed' );
        $this->refused( function () use ( $segmenter, $value, $index, $id ) { $segmenter->compose( $value, array( $index => "a</$id>" ) ); }, 'closed out of order' );
        $this->refused( function () use ( $segmenter, $value, $index ) { $segmenter->compose( $value, array( $index => array( 'text' => 'x', 'from' => array( $value, 40 ) ) ) ); },
                        'no block 40' );
        // a block index the value does not have is ignored
        $this->assertStringContainsString( 'A fact.', $segmenter->compose( $value, array( 9 => 'nothing' ) ) );
    }

    public function testTableCellsAreNotInsertedOrRemovedAlone(): void
    {
        $base = self::xml( '<table><tr><td><paragraph>One</paragraph></td><td><paragraph>Two</paragraph></td></tr></table>' );
        $prop = self::xml( '<table><tr><td><paragraph>One</paragraph></td><td><paragraph>Two</paragraph></td><td><paragraph>Three</paragraph></td></tr></table>' );
        $config = $this->config();
        $diff = expAiDiff::compare( array( 'values' => array( 'body' => array( 'datatype' => 'ezxmltext', 'value' => $base ) ) ),
                                    array( 'values' => array( 'body' => array( 'datatype' => 'ezxmltext', 'value' => $prop ) ) ), array( 'config' => $config ) );
        $hunks = $diff->hunkIds();
        $this->assertNotEmpty( $hunks );
        $this->refused( function () use ( $diff, $hunks ) { expAiDiff::apply( $diff, $hunks ); }, 'cannot be inserted alone' );
        $back = expAiDiff::compare( array( 'values' => array( 'body' => array( 'datatype' => 'ezxmltext', 'value' => $prop ) ) ),
                                    array( 'values' => array( 'body' => array( 'datatype' => 'ezxmltext', 'value' => $base ) ) ), array( 'config' => $config ) );
        $this->refused( function () use ( $back ) { expAiDiff::apply( $back, $back->hunkIds() ); }, 'cannot be removed alone' );

        // a paragraph that becomes a heading: the element is replaced
        $heading = expAiDiff::compare( array( 'values' => array( 'body' => array( 'datatype' => 'ezxmltext', 'value' => self::xml( '<paragraph>Opening hours</paragraph>' ) ) ) ),
                                       array( 'values' => array( 'body' => array( 'datatype' => 'ezxmltext', 'value' => self::xml( '<header level="2">Opening hours</header>' ) ) ) ),
                                       array( 'config' => $config ) );
        $merged = expAiDiff::apply( $heading, $heading->hunkIds() );
        $this->assertStringContainsString( '<header level="2">Opening hours</header>', $merged['values']['body'] );
        // a list item inserted where the base has no list, a block removed with the list it leaves empty
        $list = expAiDiff::compare( array( 'values' => array( 'body' => array( 'datatype' => 'ezxmltext', 'value' => self::xml( '<paragraph>Intro</paragraph><paragraph><ul><li><paragraph>Gone</paragraph></li></ul></paragraph>' ) ) ) ),
                                    array( 'values' => array( 'body' => array( 'datatype' => 'ezxmltext', 'value' => self::xml( '<paragraph>Intro</paragraph><paragraph><ol><li><paragraph>New item</paragraph></li></ol></paragraph>' ) ) ) ),
                                    array( 'config' => $config ) );
        $listed = expAiDiff::apply( $list, $list->hunkIds() );
        $this->assertStringContainsString( 'New item', $listed['values']['body'] );
        $this->assertStringNotContainsString( 'Gone', $listed['values']['body'] );
    }

    public function testSentencesAndAbbreviations(): void
    {
        $config = $this->config( array( 'DiffSettings' => array( 'Abbreviations' => array( 'eng' => 'approx; Corp.' ) ) ) );
        $abbreviations = expAiSentences::abbreviations( 'eng-US', $config );
        $this->assertContains( 'approx', $abbreviations );
        $this->assertContains( 'corp', $abbreviations );
        $this->assertSame( array( 'It costs approx. 5 euros.', 'Fine.' ), expAiSentences::split( 'It costs approx. 5 euros. Fine.', 'eng', $abbreviations ) );
        $this->assertSame( array( 'Am 3. Oktober ist zu.', 'Danach offen.' ), expAiSentences::split( 'Am 3. Oktober ist zu. Danach offen.', 'ger-DE' ) );
        $this->assertSame( array( 'Das ist z. B. gut.', 'Ja.' ), expAiSentences::split( 'Das ist z. B. gut. Ja.', 'ger' ) );
        $this->assertSame( array( 'Chapter 3. then more.' ), expAiSentences::split( 'Chapter 3. then more.', 'eng' ) );
        $this->assertSame( array( 'J. Smith wrote it.', 'Done.' ), expAiSentences::split( 'J. Smith wrote it. Done.', 'eng' ) );
        $this->assertSame( array(), expAiSentences::split( '   ' ) );
    }
}
