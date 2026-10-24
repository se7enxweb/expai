<?php
/**
 * expAiTranslationMarkup: the coded form of the translation sync (<1>...</1>, <2/>) in the markup of a machine
 * translation service and back, with the check of every placeholder. DB-less.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiTranslationMarkupTest extends expAiProviderTestCase
{
    const CODED = 'Der <1>Hund <2>schläft</2></1> &amp; träumt<3/> von 3 &lt; 4.';

    public function testTheRoundTripKeepsNestingAtomsAndEscapes(): void
    {
        $xml = expAiTranslationMarkup::toMarkup( self::CODED );
        $this->assertSame( 'Der <x i="1">Hund <x i="2">schläft</x></x> &amp; träumt<x i="3"/> von 3 &lt; 4.', $xml );
        $this->assertSame( self::CODED, expAiTranslationMarkup::fromMarkup( $xml, self::CODED ) );

        $html = expAiTranslationMarkup::toMarkup( self::CODED, expAiTranslationMarkup::STYLE_HTML );
        $this->assertSame( 'Der <span data-x="1">Hund <span data-x="2">schläft</span></span> &amp; träumt<br data-x="3"> von 3 &lt; 4.', $html );
        $this->assertSame( self::CODED, expAiTranslationMarkup::fromMarkup( $html, self::CODED, expAiTranslationMarkup::STYLE_HTML ) );
    }

    public function testATranslatedAnswerComesBackCoded(): void
    {
        // the service moved the words and wrote the entities its own way: the text is escaped as the sync escapes it
        $answer = 'The <x i="1">dog <x i = \'2\'>sleeps</x></x> &#38; dreams<x i="3" /> of 3 &lt; 4 &amp; "more".';
        $this->assertSame( 'The <1>dog <2>sleeps</2></1> &amp; dreams<3/> of 3 &lt; 4 &amp; "more".', expAiTranslationMarkup::fromMarkup( $answer, self::CODED ) );
        // a plain text without placeholders
        $this->assertSame( 'Tom &amp; Jerry', expAiTranslationMarkup::fromMarkup( 'Tom & Jerry', 'Tom &amp; Jerry' ) );
    }

    public function testTermsThatAreNeverTranslatedAreWrappedAndUnwrapped(): void
    {
        $terms = array( 'Exponential', 'Exponential Intelligence', 'R&D' );
        $coded = 'Exponential Intelligence von <1>Exponential</1>, kein Exponentialwachstum, R&amp;D.';
        $xml = expAiTranslationMarkup::toMarkup( $coded, expAiTranslationMarkup::STYLE_XML, $terms );
        $this->assertSame( '<k>Exponential Intelligence</k> von <x i="1"><k>Exponential</k></x>, kein Exponentialwachstum, <k>R&amp;D</k>.', $xml,
                           'whole words only, the longest term first, escaped as the text is' );
        $this->assertSame( $coded, expAiTranslationMarkup::fromMarkup( $xml, $coded ) );

        $html = expAiTranslationMarkup::toMarkup( '<1>Exponential</1>', expAiTranslationMarkup::STYLE_HTML, $terms );
        $this->assertSame( '<span data-x="1"><span translate="no" class="notranslate">Exponential</span></span>', $html );
        $this->assertSame( '<1>Exponential</1>', expAiTranslationMarkup::fromMarkup( $html, '<1>Exponential</1>', expAiTranslationMarkup::STYLE_HTML ) );
        $this->assertSame( 'no term', expAiTranslationMarkup::toMarkup( 'no term', expAiTranslationMarkup::STYLE_XML, array( '', '  ' ) ) );
    }

    public static function brokenAnswers(): array
    {
        return array(
            'dropped' => array( 'The <x i="1">dog <x i="2">sleeps</x></x> and dreams.', 'placeholder 3 missing' ),
            'repeated' => array( 'The <x i="1">dog</x> <x i="1">x</x> <x i="2">sleeps</x><x i="3"/>', 'placeholder 1 used twice' ),
            'renumbered' => array( 'The <x i="1">dog <x i="4">sleeps</x></x><x i="3"/>', 'unknown placeholder 4' ),
            'other form' => array( 'The <x i="1">dog <x i="2"/></x><x i="3"/>', 'placeholder 2 came back in the other form' ),
            'nesting' => array( 'The <x i="1">dog <x i="2">sleeps</x><x i="3"/>', 'placeholder 1 not closed' ),
            'stray close' => array( 'The </x><x i="1">dog <x i="2">sleeps</x></x><x i="3"/>', 'a closing tag without an opening one' ),
            'other markup' => array( 'The <b>dog</b> <x i="1"><x i="2">s</x></x><x i="3"/>', 'unexpected markup <b>' ),
        );
    }

    #[PHPUnit\Framework\Attributes\DataProvider( 'brokenAnswers' )]
    public function testABrokenAnswerIsRefused( string $answer, string $detail ): void
    {
        try
        {
            expAiTranslationMarkup::fromMarkup( $answer, self::CODED );
            $this->fail( 'accepted: ' . $answer );
        }
        catch ( expAiTranslationException $e )
        {
            $this->assertSame( 'The translation does not fit the markup of the text.', $e->getMessage() );
            $this->assertSame( $detail, $e->detail() );
            $this->assertFalse( $e->transient(), 'a broken text is permanent' );
        }
    }

    public function testTermsNeverMatchInsideAnEntity(): void
    {
        $this->assertSame( 'Tom &amp; Jerry &lt;3 &#39;x&#39; &quot;', expAiTranslationMarkup::toMarkup( 'Tom &amp; Jerry &lt;3 &#39;x&#39; &quot;', 'xml', array( 'amp', 'lt', '39', 'quot' ) ) );
        $this->assertSame( '<k>amp</k> &amp; <k>lt</k>', expAiTranslationMarkup::toMarkup( 'amp &amp; lt', 'xml', array( 'amp', 'lt' ) ), 'the words themselves are terms' );
        $this->assertSame( '<k>R&amp;D</k> &amp;', expAiTranslationMarkup::toMarkup( 'R&amp;D &amp;', 'xml', array( 'R&D' ) ), 'a term with an entity of its own' );
    }

    public function testTermsInHtmlAndControlCharacters(): void
    {
        $html = expAiTranslationMarkup::wrapTermsInHtml( '<a href="/Velocity" title="Velocity">Velocity</a> &amp; mehr', array( 'Velocity', 'amp' ) );
        $this->assertSame( '<a href="/Velocity" title="Velocity"><span translate="no" class="notranslate">Velocity</span></a> &amp; mehr', $html );
        $this->assertSame( '<a href="/Velocity" title="Velocity">Velocity</a> &amp; mehr', expAiTranslationMarkup::unwrapTermsInHtml( $html ) );
        $this->assertSame( '<p>x</p>', expAiTranslationMarkup::wrapTermsInHtml( '<p>x</p>', array() ) );
        $this->assertSame( "Der <1>Hund</1>\tschläft\n.", expAiTranslationMarkup::fromMarkup( "Der <x i=\"1\">H\x00und</x>\tschl\x1Bäft\n\x7F.", '<1>Hund</1>' ),
                           'control characters other than tab and newline are dropped' );
        $this->assertSame( "a\tb\r\nc", expAiTranslationMarkup::stripControls( "a\tb\r\n\x01c" ) );
    }

    public function testThePlaceholdersOfACodedText(): void
    {
        $this->assertSame( array( 1 => 'pair', 2 => 'pair', 3 => 'atom' ), expAiTranslationMarkup::placeholders( self::CODED ) );
        $this->assertSame( array(), expAiTranslationMarkup::placeholders( 'plain &amp; simple' ) );
    }
}
