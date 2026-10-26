<?php
/**
 * expAiTranslationProviderRegistry: the order of the rules of [TranslationSettings], the default without the group,
 * none, an unknown class, a missing key and an unsupported pair leading to the fallback, the data policy leading to
 * the next service or to blocked, terms, glossaries, the list of services; and the key placement check of the
 * service groups. DB-less (a content object double for the data policy).
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiTranslationProviderRegistryTest extends expAiProviderTestCase
{
    /** @return expAiConfig the shipped settings with a fake machine translation service and $more */
    private function settings( array $more = array() )
    {
        $config = $this->config( array( 'TranslationProvider_fake' => array( 'Class' => 'expAiFakeTranslationProvider', 'Label' => 'Fake MT', 'Region' => 'eu',
                                                                               'Retention' => 'none', 'PricePerMChars' => '10',
                                                                               'Languages' => array( 'de', 'en', 'fr', 'it', 'es', 'pl', 'da' ) ),
                                        'DataPolicy' => array( 'DefaultRegions' => array(), 'ClassRegions' => array(), 'SectionRegions' => array(), 'ZeroRetention' => 'disabled' ) ) );
        foreach ( $more as $group => $values )
            foreach ( $values as $name => $value )
                $config->ini()->setVariable( $group, $name, $value );
        return $config;
    }

    /** @return eZContentObject an article in section 1 that never touches the database */
    private static function article()
    {
        return new class( array( 'id' => 77, 'section_id' => 1, 'name' => 'XT article' ) ) extends eZContentObject
        {
            public function attribute( $attr, $noFunction = false )
            {
                if ( $attr === 'class_identifier' )
                    return 'article';
                return parent::attribute( $attr, true );
            }
        };
    }

    public function testWithoutTheGroupTheModelTranslatesAsBefore(): void
    {
        $config = $this->settings();
        $config->ini()->removeGroup( 'TranslationSettings' );
        $registry = new expAiTranslationProviderRegistry( $config );
        $this->assertFalse( $registry->hasSettings() );
        $this->assertSame( array( 'llm', 'default' ), $registry->chosenId( 'ger-DE', 'eng-GB', 'patch' ) );
        $this->assertSame( 'llm', $registry->fallbackId() );
        $choice = $registry->forPair( 'ger-DE', 'eng-GB', 'patch' );
        $this->assertSame( array( 'ok', 'llm', false ), array( $choice['state'], $choice['provider']->id(), $choice['fallback'] ) );
        $this->assertInstanceOf( 'expAiTranslationProviderLlm', $choice['provider'] );
        $this->assertNull( $choice['provider']->dataPolicy(), 'the model providers keep their own policy (lanes, queue)' );

        // the shipped settings: llm by default, every rule empty
        $shipped = new expAiTranslationProviderRegistry( $this->config() );
        $this->assertSame( array( 'llm', 'Provider' ), $shipped->chosenId( 'ger-DE', 'eng-GB', 'full' ) );
        $this->assertSame( array(), $shipped->rules() );
    }

    public function testTheMostSpecificRuleWins(): void
    {
        $registry = new expAiTranslationProviderRegistry( $this->settings( array( 'TranslationSettings' => array( 'Provider' => 'llm', 'ProviderFor' => array(
            '*:*:full' => 'fake', 'ger-DE:eng-GB' => 'deepl', 'ger-DE:eng-GB:patch' => 'libretranslate', '*:jpn-JP' => 'LLM', '*:fre-FR:field' => 'none',
            'ger-DE:*' => 'fake', '' => 'ignored', 'eng-US:*:patch' => ' ' ) ) ) ) );
        $this->assertSame( array( 'libretranslate', 'ProviderFor[ger-DE:eng-GB:patch]' ), $registry->chosenId( 'ger-DE', 'eng-GB', 'patch' ) );
        $this->assertSame( array( 'deepl', 'ProviderFor[ger-DE:eng-GB]' ), $registry->chosenId( 'ger-DE', 'eng-GB', 'full' ) );
        $this->assertSame( array( 'llm', 'ProviderFor[*:jpn-JP]' ), $registry->chosenId( 'ger-DE', 'jpn-JP', 'full' ), 'before <source>:* and *:*:<purpose>; lower case' );
        $this->assertSame( array( 'none', 'ProviderFor[*:fre-FR:field]' ), $registry->chosenId( 'eng-US', 'fre-FR', 'field' ) );
        $this->assertSame( array( 'fake', 'ProviderFor[ger-DE:*]' ), $registry->chosenId( 'ger-DE', 'ita-IT', 'patch' ) );
        $this->assertSame( array( 'fake', 'ProviderFor[*:*:full]' ), $registry->chosenId( 'eng-US', 'ita-IT', 'full' ) );
        $this->assertSame( array( 'llm', 'Provider' ), $registry->chosenId( 'eng-US', 'ita-IT', 'patch' ), 'an empty rule does not count' );
        $this->assertSame( array( 'ger-DE:eng-GB', 'ger-DE:eng-GB:patch', '*:jpn-JP', '*:*:full', '*:fre-FR:field', 'ger-DE:*' ),
                           array_values( array_intersect( array( 'ger-DE:eng-GB', 'ger-DE:eng-GB:patch', '*:jpn-JP', '*:*:full', '*:fre-FR:field', 'ger-DE:*' ), array_keys( $registry->rules() ) ) ) );
        $this->assertArrayNotHasKey( 'eng-US:*:patch', $registry->rules() );
    }

    public function testNoneMeansNoTranslation(): void
    {
        $registry = new expAiTranslationProviderRegistry( $this->settings( array( 'TranslationSettings' => array( 'Provider' => 'none', 'Fallback' => 'llm' ) ) ) );
        $choice = $registry->forPair( 'ger-DE', 'eng-GB', 'patch' );
        $this->assertSame( array( null, 'no_provider' ), array( $choice['provider'], $choice['state'] ) );
        $this->assertSame( 'No translation service is installed for ger-DE -> eng-GB ([TranslationSettings] Provider=none)', $choice['reason'], 'none does not fall back' );
        $this->assertFalse( $registry->availableFor( 'field' ), 'Translate is left out of the edit form' );
    }

    public function testConfigurationProblemsFallBack(): void
    {
        $config = $this->settings( array( 'TranslationProvider_bad' => array( 'Class' => 'expAiNoSuchTranslationClass' ),
                                          'TranslationProvider_odd' => array( 'Class' => 'expAiTranslationRequest' ),
                                          'TranslationSettings' => array( 'Fallback' => 'llm', 'ProviderFor' => array(
                                              '*:eng-GB' => 'bad', '*:fre-FR' => 'deepl', '*:tlh-QO' => 'fake', '*:ita-IT' => 'nogroup', '*:esl-ES' => 'odd',
                                              '*:dan-DK' => 'Not-An-Id', '*:pol-PL' => 'fake' ) ) ) );
        $registry = new expAiTranslationProviderRegistry( $config );
        foreach ( array( 'eng-GB' => 'bad: the class expAiNoSuchTranslationClass of [TranslationProvider_bad] does not implement expAiTranslationProvider',
                         'fre-FR' => 'deepl: no API key is set ([TranslationProvider_deepl] ApiKey in settings/override)',
                         'tlh-QO' => 'fake: does not translate ger-DE into tlh-QO',
                         'ita-IT' => 'nogroup: there is no [TranslationProvider_nogroup] group',
                         'esl-ES' => 'odd: the class expAiTranslationRequest of [TranslationProvider_odd] does not implement expAiTranslationProvider',
                         'dan-DK' => 'not-an-id: not a valid translation service id' ) as $target => $reason )
        {
            $choice = $registry->forPair( 'ger-DE', $target, 'full' );
            $this->assertSame( array( 'ok', 'llm', true, 'Fallback' ), array( $choice['state'], $choice['provider']->id(), $choice['fallback'], $choice['chosen_by'] ), $target );
            $this->assertSame( 'the fallback llm translates (' . $reason . ')', $choice['reason'], $target );
        }
        $usable = $registry->forPair( 'ger-DE', 'pol-PL', 'full' );
        $this->assertSame( array( 'ok', 'fake', false ), array( $usable['state'], $usable['provider']->id(), $usable['fallback'] ) );

        // Fallback=none: reported, not translated
        $config->ini()->setVariable( 'TranslationSettings', 'Fallback', 'none' );
        $strict = new expAiTranslationProviderRegistry( $config );
        $choice = $strict->forPair( 'ger-DE', 'fre-FR', 'patch' );
        $this->assertSame( array( null, 'no_provider' ), array( $choice['provider'], $choice['state'] ) );
        $this->assertStringStartsWith( 'No translation service is installed for ger-DE -> fre-FR (deepl: no API key', $choice['reason'] );
        // a fallback that cannot translate either: both reasons
        $config->ini()->setVariable( 'TranslationSettings', 'Fallback', 'bad' );
        $both = ( new expAiTranslationProviderRegistry( $config ) )->forPair( 'ger-DE', 'fre-FR', 'patch' );
        $this->assertSame( 'no_provider', $both['state'] );
        $this->assertStringContainsString( '; bad: the class', $both['reason'] );
        // a forced service: no rule, no fallback
        $forced = $registry->forPair( 'ger-DE', 'eng-GB', 'patch', null, 'deepl' );
        $this->assertSame( array( null, 'no_provider', 'option' ), array( $forced['provider'], $forced['state'], $forced['chosen_by'] ) );
        $this->assertSame( 'fake', $registry->forPair( 'ger-DE', 'eng-GB', 'patch', null, 'FAKE' )['provider']->id() );
    }

    public function testTheDataPolicyTriesTheNextServiceOrBlocks(): void
    {
        $config = $this->settings( array( 'TranslationSettings' => array( 'Provider' => 'fake', 'Fallback' => 'llm' ),
                                          'DataPolicy' => array( 'ClassRegions' => array( 'article' => 'local' ) ) ) );
        $registry = new expAiTranslationProviderRegistry( $config );
        $choice = $registry->forPair( 'ger-DE', 'eng-GB', 'full', self::article() );
        $this->assertSame( array( 'ok', 'llm', true ), array( $choice['state'], $choice['provider']->id(), $choice['fallback'] ) );
        $this->assertStringContainsString( 'fake: the data policy keeps the object away from it: regions local required', $choice['reason'] );
        $this->assertSame( 'fake', $registry->forPair( 'ger-DE', 'eng-GB', 'full' )['provider']->id(), 'without an object no policy applies' );

        $config->ini()->setVariable( 'TranslationSettings', 'Fallback', 'none' );
        $blocked = ( new expAiTranslationProviderRegistry( $config ) )->forPair( 'ger-DE', 'eng-GB', 'full', self::article() );
        $this->assertSame( array( null, 'blocked' ), array( $blocked['provider'], $blocked['state'] ) );
        $this->assertStringStartsWith( 'The data policy keeps this content away from the translation service for ger-DE -> eng-GB (fake: ', $blocked['reason'] );

        // a local service meets every rule
        $config->ini()->setVariable( 'TranslationProvider_fake', 'Region', 'local' );
        $this->assertSame( 'ok', ( new expAiTranslationProviderRegistry( $config ) )->forPair( 'ger-DE', 'eng-GB', 'full', self::article() )['state'] );
    }

    public function testAvailabilityTermsGlossaryAndTheList(): void
    {
        $file = tempnam( sys_get_temp_dir(), 'expai-glossary' );
        file_put_contents( $file, "# a comment\nBäckerei\tbakery\n\nBrötchen\t rolls \nno tab here\n" );
        $this->cleanup( static function () use ( $file ) { unlink( $file ); } );
        $config = $this->settings( array( 'TranslationSettings' => array( 'Provider' => 'none', 'Fallback' => 'none', 'ProviderFor' => array( '*:*:full' => 'fake' ),
                                                                           'DoNotTranslate' => array( 'Exponential', ' Exponential ', 'Velocity' ),
                                                                           'Glossary' => array( 'ger-DE:eng-GB' => $file, 'ger-DE:fre-FR' => 'extension/expai/no/such.tsv' ) ) ) );
        $registry = new expAiTranslationProviderRegistry( $config );
        $this->assertFalse( $registry->availableFor( 'field' ), 'only full translations have a service' );
        $this->assertTrue( $registry->availableFor( 'full' ) );
        $this->assertSame( array( 'Exponential', 'Velocity' ), $registry->terms() );
        $this->assertSame( array( 'Bäckerei' => 'bakery', 'Brötchen' => 'rolls' ), $registry->glossary( 'ger-DE', 'eng-GB' ) );
        $this->assertSame( array(), $registry->glossary( 'ger-DE', 'fre-FR' ) );
        $this->assertSame( array(), $registry->glossary( 'ger-DE', 'ita-IT' ) );

        $list = array();
        foreach ( $registry->describe() as $row )
            $list[$row['id']] = array( $row['kind'], $row['configured'], $row['region'] );
        $this->assertSame( array( 'llm', true, 'us' ), array( $list['llm'][0], $list['llm'][1], $list['llm'][2] ) );
        $this->assertSame( array( 'mt', false, 'eu' ), $list['deepl'] );
        $this->assertSame( array( 'mt', true, 'local' ), $list['libretranslate'] );
        $this->assertSame( array( 'mt', true, 'eu' ), $list['fake'] );
    }

    public function testTheKeysOfSearchAndTranslationServicesAreCheckedWhereTheyAreSet(): void
    {
        foreach ( array( 'AISettings', 'Provider_openai', 'McpServer_docs', 'SearchProvider_brave', 'TranslationProvider_deepl' ) as $group )
            $this->assertTrue( expAiProviderRegistry::keyGroup( $group ), $group );
        foreach ( array( 'TranslationSettings', 'SearchSettings', 'Model_x', 'WebSettings' ) as $group )
            $this->assertFalse( expAiProviderRegistry::keyGroup( $group ), $group );
    }
}
