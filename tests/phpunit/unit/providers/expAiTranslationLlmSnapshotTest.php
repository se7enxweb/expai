<?php
/**
 * The translation service llm (expAiTranslationProviderLlm, doc/translation-providers.md) builds the request of the
 * translation sync byte for byte as the sync built it before translation services existed: the snapshot in
 * tests/fixtures/translation/llm-request-snapshot.json was written by the sync's own request() before the code moved
 * into the adapter. DB-less: the language names come from a language list set in memory.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiTranslationLlmSnapshotTest extends expAiProviderTestCase
{
    const SNAPSHOT = '/../../../fixtures/translation/llm-request-snapshot.json';

    /** @var array|null the language list of the process before the test */
    private $languages = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->languages = isset( $GLOBALS['eZContentLanguageList'] ) ? $GLOBALS['eZContentLanguageList'] : null;
        self::seedLanguages();
    }

    protected function tearDown(): void
    {
        if ( $this->languages === null )
            unset( $GLOBALS['eZContentLanguageList'] );
        else
            $GLOBALS['eZContentLanguageList'] = $this->languages;
        parent::tearDown();
    }

    /** The languages of the plans, in memory (eZContentLanguage::fetchList() reads them from there). */
    public static function seedLanguages()
    {
        $GLOBALS['eZContentLanguageList'] = array(
            2 => new eZContentLanguage( array( 'id' => 2, 'name' => 'German', 'locale' => 'ger-DE', 'disabled' => 0 ) ),
            4 => new eZContentLanguage( array( 'id' => 4, 'name' => 'English (United Kingdom)', 'locale' => 'eng-GB', 'disabled' => 0 ) ),
        );
    }

    /**
     * Plans as expAiTranslationSync::plan() makes them (only the keys request() reads): a patch with a changed
     * block, a new block, a title and keywords, and a full translation into a language without a name.
     * @return array name => array( plan, mode )
     */
    public static function plans()
    {
        $long = str_repeat( 'Ein langer Absatz davor, der gekürzt wird. ', 12 );
        $patch = array( 'source' => 'ger-DE', 'target' => 'eng-GB', 'units' => array(
            array( 'id' => 'u1', 'attribute' => 'title', 'index' => 0, 'kind' => 'text', 'old' => 'Alter Titel', 'new' => 'Neuer Titel',
                   'translation' => 'Old title', 'before' => '', 'after' => '' ),
            array( 'id' => 'u2', 'attribute' => 'body', 'index' => 1, 'kind' => 'block', 'old' => 'Der <1>Hund</1> schläft.',
                   'new' => 'Der <1>Hund</1> schläft &amp; träumt.<2/>', 'translation' => 'The <1>dog</1> sleeps.', 'before' => $long, 'after' => 'Danach.' ),
            array( 'id' => 'u3', 'attribute' => 'body', 'index' => 2, 'kind' => 'block', 'old' => '', 'new' => 'Ganz neu: 3 &lt; 4.',
                   'translation' => '', 'before' => 'Der Hund schläft und träumt.', 'after' => '' ),
            array( 'id' => 'u4', 'attribute' => 'tags', 'index' => 0, 'kind' => 'keywords', 'old' => 'Hund, Katze', 'new' => 'Hund, Katze, Maus',
                   'translation' => 'dog, cat', 'before' => '', 'after' => '' ),
        ) );
        $full = array( 'source' => 'ger-DE', 'target' => 'fre-FR', 'units' => array(
            array( 'id' => 'u1', 'attribute' => 'title', 'index' => 0, 'kind' => 'text', 'old' => '', 'new' => 'Neuer Titel', 'translation' => '', 'before' => '', 'after' => '' ),
            array( 'id' => 'u2', 'attribute' => 'body', 'index' => 0, 'kind' => 'block', 'old' => '', 'new' => str_repeat( 'Satz. ', 900 ), 'translation' => '', 'before' => '', 'after' => 'Ende.' ),
        ) );
        return array( 'patch' => array( $patch, 'patch' ), 'full' => array( $full, 'full' ) );
    }

    /** @return string the request as the snapshot stores it */
    private static function encode( array $requests )
    {
        return json_encode( $requests, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
    }

    public function testTheSyncBuildsTheRequestOfTheSnapshot(): void
    {
        $sync = new expAiTranslationSync( $this->config() );
        $out = array();
        foreach ( self::plans() as $name => $case )
            $out[$name] = $sync->request( $case[0], $case[1] );
        $this->assertSame( file_get_contents( __DIR__ . self::SNAPSHOT ), self::encode( $out ) );
    }

    public function testTheAdapterBuildsTheSameRequestFromAPlanAndFromATranslationRequest(): void
    {
        $config = $this->config();
        $llm = new expAiTranslationProviderLlm( 'llm', array(), $config );
        $snapshot = json_decode( file_get_contents( __DIR__ . self::SNAPSHOT ), true );
        foreach ( self::plans() as $name => $case )
        {
            $this->assertSame( self::encode( array( $snapshot[$name] ) ), self::encode( array( $llm->planRequest( $case[0], $case[1] ) ) ), $name . ': from the plan' );
            $request = expAiTranslationRequest::fromPlan( $case[0], $case[1] );
            $this->assertSame( self::encode( array( $snapshot[$name] ) ), self::encode( array( $llm->buildRequest( $request ) ) ), $name . ': from the translation request' );
        }
    }

    public function testTermsAndGlossariesAreAddedOnlyWhenConfigured(): void
    {
        $plans = self::plans();
        $config = $this->config( array( 'TranslationSettings' => array( 'DoNotTranslate' => array( 'Exponential', 'Velocity' ) ) ) );
        $llm = new expAiTranslationProviderLlm( 'llm', array(), $config );
        $request = $llm->planRequest( $plans['patch'][0], 'patch' );
        $snapshot = json_decode( file_get_contents( __DIR__ . self::SNAPSHOT ), true );
        $this->assertStringStartsWith( $snapshot['patch']['system'], $request['system'] );
        $this->assertStringEndsWith( "\n\nNever translate these terms; keep them exactly as written: Exponential, Velocity", $request['system'] );
        $this->assertSame( $snapshot['patch']['messages'], $request['messages'] );
    }
}
