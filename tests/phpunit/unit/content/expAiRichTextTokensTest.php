<?php
/**
 * Rich text with placeholders (expAiRichTextTokens, doc/configuration.md "[RichTextSettings]"): what the model reads,
 * the round trip through the kernel's input parser back to the stored elements (formulas, MathML attributes, tables, embeds, internal links,
 * attributes, heading levels, the rest of a long field), the answers that are refused (a placeholder dropped or
 * invented), the best effort a review gets, the hunks that would drop an element and the last check before a merged
 * value is written. No database: no http links, a new object without id.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

class expAiRichTextTokensTest extends expAiTestCase
{
    private static function xml( $inner )
    {
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<section xmlns:image="http://ez.no/namespaces/ezpublish3/image/" xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" '
             . 'xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/">' . $inner . '</section>' . "\n";
    }

    /** a lesson as the importer writes it: a heading, formulas, MathML, an internal link, an embed, a table, a list */
    private static function lesson()
    {
        return self::xml( '<section><section><header>Pythagoras</header>'
            . '<paragraph>The area is <custom name="math_latex">a^2+b^2=c^2</custom> and <custom name="math_mathml" custom:mathml="&lt;math&gt;&lt;mi&gt;x&lt;/mi&gt;&lt;/math&gt;">x</custom>.</paragraph>'
            . '<paragraph align="center">See <link node_id="12">the proof</link>.</paragraph>'
            . '<paragraph><embed view="embed" size="medium" object_id="6722"/></paragraph>'
            . '<paragraph><table class="list" width="100%"><tr><td><paragraph>side <custom name="math_latex">a</custom></paragraph></td></tr></table></paragraph>'
            . '<paragraph><ul><li><paragraph>one</paragraph></li><li><paragraph>two</paragraph></li></ul></paragraph>'
            . '</section></section>' );
    }

    private function writer( array $settings = array() )
    {
        return new expAiContentWriter( $this->config( $settings ) );
    }

    private static function object()
    {
        return new eZContentObject( array( 'id' => 0 ) );
    }

    public function testTheModelReadsTheSubsetWithPlaceholders(): void
    {
        $read = ( new expAiRichTextTokens( $this->config() ) )->tokenize( self::lesson() );
        $this->assertSame( '<h2>Pythagoras</h2><p>The area is ⟦C1⟧ and ⟦C2⟧.</p><p>⟦K1⟧See ⟦L1⟧.</p><p>⟦E1⟧</p><p>⟦T1⟧</p><ul><li>one</li><li>two</li></ul>', $read['text'] );
        $this->assertSame( array( 'C1', 'C2', 'K1', 'L1', 'E1', 'T1' ), array_keys( $read['tokens'] ) );
        $this->assertSame( 'custom:math_latex', $read['tokens']['C1']['kind'] );
        $this->assertStringNotContainsString( 'a^2', $read['text'], 'the formula never reaches the model as text' );
        $this->assertStringNotContainsString( '6722', $read['text'], 'nor the id of an embedded object' );
    }

    public function testFormulasMathmlTablesAndEmbedsSurviveTheRoundTrip(): void
    {
        $base = self::lesson();
        $tokens = new expAiRichTextTokens( $this->config() );
        $answer = str_replace( array( 'The area is', 'two' ), array( 'The surface is', 'zwei' ), $tokens->forModel( $base ) );
        $xml = $this->writer()->richTextToXml( self::object(), $answer, $urls, $messages, array( $base ), $guard );
        $this->assertIsString( $xml, implode( ' ', (array) $messages ) );
        $this->assertTrue( $guard['ok'] );
        $this->assertStringContainsString( 'The surface is <custom name="math_latex">a^2+b^2=c^2</custom>', $xml, 'the formula is back in place' );
        $this->assertStringContainsString( 'custom:mathml="&lt;math&gt;&lt;mi&gt;x&lt;/mi&gt;&lt;/math&gt;"', $xml, 'the MathML attribute is kept' );
        $this->assertStringContainsString( '<embed view="embed" size="medium" object_id="6722"/>', $xml );
        $this->assertStringContainsString( '<table class="list" width="100%"><tr><td><paragraph>side <custom name="math_latex">a</custom></paragraph></td></tr></table>', $xml );
        $this->assertStringContainsString( '<paragraph align="center">See <link node_id="12">the proof</link>.</paragraph>', $xml, 'attributes and the internal link' );
        $this->assertStringContainsString( '<section><section><header>Pythagoras</header>', $xml, 'the heading keeps its level' );
        $this->assertStringContainsString( 'zwei', $xml );
        $this->assertSame( array(), $tokens->loss( $base, $xml ) );
    }

    public function testADroppedPlaceholderIsRefusedAndTheReviewSeesWhatItLoses(): void
    {
        $base = self::lesson();
        $tokens = new expAiRichTextTokens( $this->config() );
        // the model wrote the formula paragraph as plain text
        $answer = str_replace( '⟦C1⟧', 'a^2+b^2=c^2', $tokens->forModel( $base ) );
        $writer = $this->writer();
        $this->assertFalse( $writer->richTextToXml( self::object(), $answer, $urls, $messages, array( $base ), $guard ) );
        $this->assertFalse( $guard['ok'] );
        $this->assertSame( array( 'C1' ), $guard['problems']['missing'] );
        $this->assertStringContainsString( '⟦C1⟧', $guard['reason_model'] );

        // the best effort for the review: everything else restored, and the hunk that drops the formula cannot be taken
        $proposal = $writer->richTextToXml( self::object(), $answer, $urls, $messages, array( $base ), $guard, true );
        $this->assertIsString( $proposal );
        $this->assertSame( array( 'custom:math_latex' => 1 ), $tokens->loss( $base, $proposal ) );
        $diff = expAiDiff::compareValues( 'ezxmltext', $base, $proposal, array( 'attribute' => 'body' ) );
        $this->assertCount( 1, $diff['hunks'] );
        $this->assertSame( array( 'custom:math_latex' => 1 ), $diff['hunks'][0]['loses'], 'a change of markup only that drops the formula' );

        $hunks = expAiChangeStager::hunksFor( 'ezxmltext', $base, $proposal, 'eng-GB', 'body' );
        $change = new expAiChange( array( 'decision' => 'pending' ) );
        $change->setHunks( $hunks );
        try
        {
            $change->decide( $hunks[0]['id'], 'accepted', 14 );
            $this->fail( 'a hunk that loses a formula was accepted' );
        }
        catch ( expAiException $e )
        {
            $this->assertStringContainsString( 'cannot be accepted', $e->getMessage() );
        }
        $change->decide( '', 'accepted', 14 );
        $this->assertSame( 'rejected', $change->hunks()[0]['decision'], 'accepting the whole field rejects the part that loses' );
        $this->assertNotSame( '', $change->present()['hunks'][0]['loses'] );
    }

    public function testAnInventedOrRepeatedPlaceholderIsRefused(): void
    {
        $base = self::lesson();
        $text = ( new expAiRichTextTokens( $this->config() ) )->forModel( $base );
        $writer = $this->writer();
        $this->assertFalse( $writer->richTextToXml( self::object(), $text . '<p>⟦C7⟧</p>', $urls, $messages, array( $base ), $guard ) );
        $this->assertSame( array( 'C7' ), $guard['problems']['invented'] );
        $this->assertFalse( $writer->richTextToXml( self::object(), $text . '<p>⟦E1⟧</p>', $urls, $messages, array( $base ), $guard ) );
        $this->assertSame( array( 'E1' ), $guard['problems']['duplicated'] );
        // a new value has no placeholders at all
        $this->assertFalse( $writer->richTextToXml( self::object(), '<p>New ⟦C1⟧</p>', $urls, $messages, array( '' ), $guard ) );
        $this->assertSame( array( 'C1' ), $guard['problems']['invented'] );
        // the best effort removes them and leaves no empty paragraph
        $xml = $writer->richTextToXml( self::object(), $text . '<p>⟦C7⟧</p>', $urls, $messages, array( $base ), $guard, true );
        $this->assertStringNotContainsString( '⟦', $xml );
        $this->assertStringNotContainsString( '<paragraph/>', $xml );
    }

    public function testATranslationTakesItsPlaceholdersFromTheSourceLanguage(): void
    {
        $source = self::lesson();
        $text = ( new expAiRichTextTokens( $this->config() ) )->forModel( $source );
        $answer = str_replace( array( 'The area is', 'See' ), array( 'Die Fläche ist', 'Siehe' ), $text );
        // the language does not exist yet (empty base), the main language is the second source
        $xml = $this->writer()->richTextToXml( self::object(), $answer, $urls, $messages, array( '', $source ), $guard );
        $this->assertIsString( $xml, $guard['reason_model'] );
        $this->assertStringContainsString( 'Die Fläche ist <custom name="math_latex">a^2+b^2=c^2</custom>', $xml );
        // a translation without the placeholders would drop the formulas of the source
        $this->assertFalse( $this->writer()->richTextToXml( self::object(), '<p>Die Fläche ist a^2+b^2=c^2.</p>', $urls, $messages, array( '', $source ), $guard ) );
        $this->assertArrayHasKey( 'custom:math_latex', $guard['loss'] );
    }

    public function testTheRestOfALongFieldIsKeptUnchanged(): void
    {
        $inner = '';
        for ( $i = 0; $i < 40; $i++ )
            $inner .= '<paragraph>Paragraph ' . $i . ' ' . str_repeat( 'lorem ipsum ', 10 ) . ( $i === 30 ? '<custom name="math_latex">z^2</custom>' : '' ) . '</paragraph>';
        $base = self::xml( $inner );
        $settings = array( 'RichTextSettings' => array( 'MaxLength' => 1000 ) );
        $tokens = new expAiRichTextTokens( $this->config( $settings ) );
        $text = $tokens->forModel( $base );
        $this->assertStringEndsWith( '<p>⟦R1⟧</p>', $text );
        $this->assertLessThan( 1400, mb_strlen( $text ), 'the model reads about MaxLength characters' );
        $this->assertStringNotContainsString( 'Paragraph 30', $text );

        $writer = $this->writer( $settings );
        $answer = str_replace( 'Paragraph 0 ', 'First paragraph ', $text );
        $xml = $writer->richTextToXml( self::object(), $answer, $urls, $messages, array( $base ), $guard );
        $this->assertIsString( $xml, $guard['reason_model'] );
        $this->assertStringContainsString( 'First paragraph', $xml );
        $this->assertStringContainsString( 'Paragraph 39', $xml, 'the tail is there' );
        $this->assertStringContainsString( '<custom name="math_latex">z^2</custom>', $xml, 'with its formula' );
        $this->assertSame( 40, substr_count( $xml, '<paragraph>' ) );

        // an answer without the rest is not written; the review still gets the whole tail
        $short = str_replace( '<p>⟦R1⟧</p>', '', $answer );
        $this->assertFalse( $writer->richTextToXml( self::object(), $short, $urls, $messages, array( $base ), $guard ) );
        $this->assertSame( array( 'R1' ), $guard['problems']['missing'] );
        $kept = $writer->richTextToXml( self::object(), $short, $urls, $messages, array( $base ), $guard, true );
        $this->assertStringContainsString( 'Paragraph 39', $kept );
        $this->assertSame( array(), $tokens->loss( $base, $kept ) );
    }

    public function testTheRestBelowNestedSectionsKeepsItsHeadingLevels(): void
    {
        $inner = '<section><section><header>One</header>';
        for ( $i = 0; $i < 12; $i++ )
            $inner .= '<paragraph>Text ' . $i . ' ' . str_repeat( 'dolor sit ', 12 ) . '</paragraph>';
        $inner .= '</section><section><header>Two</header><paragraph>Last</paragraph></section></section>';
        $base = self::xml( $inner );
        $settings = array( 'RichTextSettings' => array( 'MaxLength' => 1000 ) );
        $text = ( new expAiRichTextTokens( $this->config( $settings ) ) )->forModel( $base );
        $this->assertStringEndsWith( '<p>⟦R1⟧</p><p>⟦R2⟧</p>', $text, 'the rest of the section, then the rest of the one around it' );
        $xml = $this->writer( $settings )->richTextToXml( self::object(), $text, $urls, $messages, array( $base ), $guard );
        $this->assertIsString( $xml, $guard['reason_model'] );
        $this->assertStringContainsString( '</section><section><header>Two</header><paragraph>Last</paragraph></section></section>', $xml );
    }

    public function testTheLastCheckBeforeWritingRefusesAMergeThatLosesStructure(): void
    {
        $base = self::lesson();
        $proposal = str_replace( '<custom name="math_latex">a^2+b^2=c^2</custom>', 'a^2+b^2=c^2', $base );
        $diff = expAiDiff::compare( array( 'values' => array( 'body' => array( 'datatype' => 'ezxmltext', 'value' => $base ) ) ),
                                    array( 'values' => array( 'body' => array( 'datatype' => 'ezxmltext', 'value' => $proposal ) ) ) );
        $ids = array_column( $diff->hunks(), 'id' );
        $this->assertNotEmpty( $ids );
        try
        {
            expAiDiff::apply( $diff, $ids, array( 'structure_guard' => true ) );
            $this->fail( 'a merge that drops a formula passed the check' );
        }
        catch ( expAiException $e )
        {
            $this->assertStringContainsString( 'Nothing was written', $e->getMessage() );
        }
        $merged = expAiDiff::apply( $diff, $ids );
        $this->assertArrayHasKey( 'body', $merged['values'], 'without the check the merge is made (the review shows it)' );
    }

    public function testWithoutPlaceholdersTheOldViewStays(): void
    {
        $config = $this->config( array( 'RichTextSettings' => array( 'Placeholders' => 'disabled' ) ) );
        $this->assertFalse( expAiRichTextTokens::handles( 'ezxmltext', $config ) );
        $this->assertTrue( expAiRichTextTokens::handles( 'ezxmltext', $this->config() ) );
        $this->assertFalse( expAiRichTextTokens::handles( 'ezstring', $this->config() ) );
        $xml = ( new expAiContentWriter( $config ) )->richTextToXml( self::object(), '<p>Plain</p>', $urls, $messages, array( self::lesson() ), $guard );
        $this->assertStringContainsString( '<paragraph>Plain</paragraph>', $xml );
    }

    public function testProtectedElementsComeFromTheSettings(): void
    {
        $config = $this->config( array( 'RichTextSettings' => array( 'Protected' => array( 'custom' => 'C', 'strong' => 'S' ) ) ) );
        $read = ( new expAiRichTextTokens( $config ) )->tokenize( self::xml( '<paragraph>A <strong>bold</strong> word</paragraph>' ) );
        $this->assertSame( '<p>A ⟦S1⟧ word</p>', $read['text'], 'an element the subset knows, kept whole when the settings say so' );
        $this->assertSame( 'strong', $read['tokens']['S1']['kind'] );
        $this->assertSame( 'S', expAiRichTextTokens::protectedElements( $config )['strong'] );
        $this->assertSame( '1 custom tag math_latex, 2 tables', expAiRichTextTokens::describe( array( 'custom:math_latex' => 1, 'table' => 2 ), false ) );
    }

    public function testTheTargetValueKeepsItsOwnPlaceholders(): void
    {
        // German has two formulas, English one; the model kept only the first placeholder of the German text
        $german = self::xml( '<paragraph>Erst <custom name="math_latex">de_1</custom>, dann <custom name="math_latex">de_2</custom>.</paragraph>' );
        $english = self::xml( '<paragraph>First <custom name="math_latex">en_1</custom>.</paragraph>' );
        $answer = '<p>Zuerst ⟦C1⟧.</p>';
        $tokens = new expAiRichTextTokens( $this->config() );
        $read = $tokens->resolve( $answer, array( $german, $english ) );
        $this->assertSame( $german, $read['source'], 'the target value is used, not the language whose placeholders happen to fit' );
        $this->assertSame( array( 'C2' ), $read['problems']['missing'] );

        $writer = $this->writer();
        $this->assertFalse( $writer->richTextToXml( self::object(), $answer, $urls, $messages, array( $german, $english ), $guard ) );
        $this->assertSame( array( 'C2' ), $guard['problems']['missing'] );
        $proposal = $writer->richTextToXml( self::object(), $answer, $urls, $messages, array( $german, $english ), $guard, true );
        $this->assertStringContainsString( '<custom name="math_latex">de_1</custom>', $proposal );
        $this->assertStringNotContainsString( 'en_1', $proposal, 'no English element in the German text' );
        $diff = expAiDiff::compareValues( 'ezxmltext', $german, $proposal, array( 'attribute' => 'body' ) );
        $this->assertSame( array( 'custom:math_latex' => 1 ), $diff['hunks'][0]['loses'], 'the review cannot accept the part that drops de_2' );

        // a target without placeholders of its own (new, empty, plain text) takes them from the other language
        $plain = self::xml( '<paragraph>Nur Text.</paragraph>' );
        $read = $tokens->resolve( '<p>Zuerst ⟦C1⟧.</p>', array( $plain, $english ) );
        $this->assertSame( $english, $read['source'] );
    }

    public function testBracketsOfTheStoredTextAreNeverPlaceholders(): void
    {
        // text that looks like a placeholder, without an element behind it
        $base = self::xml( '<paragraph>Write ⟦X1⟧ here.</paragraph>' );
        $tokens = new expAiRichTextTokens( $this->config() );
        $text = $tokens->forModel( $base );
        $this->assertSame( '<p>Write ⟦Q1⟧X1⟦Q2⟧ here.</p>', $text );
        $writer = $this->writer();
        $xml = $writer->richTextToXml( self::object(), str_replace( 'Write', 'Type', $text ), $urls, $messages, array( $base ), $guard );
        $this->assertIsString( $xml, $guard['reason_model'] );
        $this->assertStringContainsString( '<paragraph>Type ⟦X1⟧ here.</paragraph>', $xml, 'the brackets come back as text' );
        // the model may drop them like any other word
        $xml = $writer->richTextToXml( self::object(), '<p>Type here.</p>', $urls, $messages, array( $base ), $guard );
        $this->assertStringContainsString( '<paragraph>Type here.</paragraph>', $xml );

        // the same text next to a real X1: dropping the element is refused, the literal text is never taken for it
        $base = self::xml( '<paragraph>Write ⟦X1⟧ <foo bar="1"/> here.</paragraph>' );
        $text = $tokens->forModel( $base );
        $this->assertSame( '<p>Write ⟦Q1⟧X1⟦Q2⟧ ⟦X1⟧ here.</p>', $text );
        $dropped = str_replace( ' ⟦X1⟧ here', ' here', $text );
        $this->assertFalse( $writer->richTextToXml( self::object(), $dropped, $urls, $messages, array( $base ), $guard ) );
        $this->assertSame( array( 'X1' ), $guard['problems']['missing'] );
        $proposal = $writer->richTextToXml( self::object(), $dropped, $urls, $messages, array( $base ), $guard, true );
        $this->assertStringContainsString( 'Write ⟦X1⟧ here.', $proposal, 'the literal text stays text' );
        $this->assertStringNotContainsString( '<foo', $proposal );
        $this->assertSame( array( 'element:foo' => 1 ), $tokens->loss( $base, $proposal ) );
    }

    public function testRestagingKeepsTheEditorsDecisionOnAPartThatLoses(): void
    {
        $loses = array( 'loses' => array( 'custom:math_latex' => 1 ) );
        $this->assertSame( 'rejected', expAiChangeStager::carriedDecision( $loses, array( 'decision' => 'rejected' ), 'pending' ) );
        $this->assertSame( 'pending', expAiChangeStager::carriedDecision( $loses, array( 'decision' => 'pending' ), 'pending' ) );
        $this->assertSame( 'pending', expAiChangeStager::carriedDecision( $loses, array( 'decision' => 'accepted' ), 'pending' ), 'never accepted' );
        $this->assertSame( 'pending', expAiChangeStager::carriedDecision( $loses, null, 'accepted' ) );
        $this->assertSame( 'accepted', expAiChangeStager::carriedDecision( array( 'loses' => array() ), array( 'decision' => 'accepted' ), 'pending' ) );

        $base = self::lesson();
        $proposal = str_replace( '<custom name="math_latex">a^2+b^2=c^2</custom>', 'a^2+b^2=c^2', $base );
        $hunks = expAiChangeStager::hunksFor( 'ezxmltext', $base, $proposal, 'eng-GB', 'body' );
        $this->assertNotEmpty( $hunks[0]['loses'] );
        $hunks[0]['decision'] = 'rejected';
        $again = expAiChangeStager::hunksFor( 'ezxmltext', $base, $proposal, 'eng-GB', 'body', $hunks );
        $this->assertSame( 'rejected', $again[0]['decision'], 'staging the same proposal again keeps the rejection' );
    }

    public function testAnEmptySectionIsNoBaseline(): void
    {
        // what the kernel stores for a rich text field that was never filled (eZXMLTextType::initializeObjectAttribute())
        $empty = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<section xmlns:image="http://ez.no/namespaces/ezpublish3/image/" '
               . 'xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/"/>' . "\n";
        $this->assertTrue( expAiRichTextTokens::isEmpty( $empty ) );
        $this->assertTrue( expAiRichTextTokens::isEmpty( '' ) );
        $this->assertFalse( expAiRichTextTokens::isEmpty( self::lesson() ) );
        $source = self::lesson();
        $writer = $this->writer();
        $this->assertFalse( $writer->richTextToXml( self::object(), '<p>Die Fläche ist a^2+b^2=c^2.</p>', $urls, $messages, array( $empty, $source ), $guard ),
                            'a translation into an empty field may not drop the formulas of the source' );
        $this->assertArrayHasKey( 'custom:math_latex', $guard['loss'] );
        $text = ( new expAiRichTextTokens( $this->config() ) )->forModel( $source );
        $this->assertIsString( $writer->richTextToXml( self::object(), $text, $urls, $messages, array( $empty, $source ), $guard ) );
    }
}
