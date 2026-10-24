<?php
/**
 * expAiTranslationLocale: the code a translation service expects for an eZ locale (LanguageMap, the HTTP locale, the
 * rules of DeepL and LibreTranslate), and '' for a language the service does not translate. DB-less (the locale files
 * of share/locale).
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiTranslationLocaleTest extends expAiProviderTestCase
{
    public function testDeeplTakesTheBaseLanguageAsSourceAndAVariantAsTarget(): void
    {
        $this->assertSame( 'DE', expAiTranslationLocale::forProvider( 'ger-DE', 'deepl', 'source' ) );
        $this->assertSame( 'DE', expAiTranslationLocale::forProvider( 'ger-DE', 'deepl', 'target' ) );
        $this->assertSame( 'EN', expAiTranslationLocale::forProvider( 'eng-GB', 'deepl', 'source' ) );
        $this->assertSame( 'EN-GB', expAiTranslationLocale::forProvider( 'eng-GB', 'deepl', 'target' ) );
        $this->assertSame( 'EN-US', expAiTranslationLocale::forProvider( 'eng-US', 'deepl', 'target' ) );
        $this->assertSame( 'PT-BR', expAiTranslationLocale::forProvider( 'por-BR', 'deepl', 'target' ) );
        $this->assertSame( 'PT-PT', expAiTranslationLocale::forProvider( 'por-PT', 'deepl', 'target' ) );
        $this->assertSame( 'PT', expAiTranslationLocale::forProvider( 'por-BR', 'deepl', 'source' ) );
        $this->assertSame( 'ZH-HANS', expAiTranslationLocale::forProvider( 'chi-CN', 'deepl', 'target' ) );
        $this->assertSame( 'ZH-HANT', expAiTranslationLocale::forProvider( 'chi-TW', 'deepl', 'target' ) );
        $this->assertSame( 'NB', expAiTranslationLocale::forProvider( 'nor-NO', 'deepl', 'target' ) );
    }

    public function testALanguageTheServiceDoesNotKnowIsNotTranslated(): void
    {
        foreach ( array( 'tlh-QO', 'kal-GL', 'lat-VA', 'pus-AF', 'xxx-YY', 'not a locale', '' ) as $locale )
            $this->assertSame( '', expAiTranslationLocale::forProvider( $locale, 'deepl', 'target' ), $locale );
        // the service's own list replaces the built-in one
        $this->assertSame( '', expAiTranslationLocale::forProvider( 'fre-FR', 'deepl', 'target', array( 'Languages' => array( 'de', 'en' ) ) ) );
        $this->assertSame( 'LA', expAiTranslationLocale::forProvider( 'lat-VA', 'deepl', 'target', array( 'Languages' => array( 'la' ) ) ) );
    }

    public function testTheLanguageMapComesFirst(): void
    {
        $settings = array( 'LanguageMap' => array( 'esl-MX' => 'ES-419', 'tlh-QO:source' => 'TLH', 'eng-GB:target' => 'EN-US' ) );
        $this->assertSame( 'ES-419', expAiTranslationLocale::forProvider( 'esl-MX', 'deepl', 'target', $settings ) );
        $this->assertSame( 'ES-419', expAiTranslationLocale::forProvider( 'esl-MX', 'deepl', 'source', $settings ) );
        $this->assertSame( 'TLH', expAiTranslationLocale::forProvider( 'tlh-QO', 'deepl', 'source', $settings ) );
        $this->assertSame( '', expAiTranslationLocale::forProvider( 'tlh-QO', 'deepl', 'target', $settings ), 'mapped for the source only' );
        $this->assertSame( 'EN-US', expAiTranslationLocale::forProvider( 'eng-GB', 'deepl', 'target', $settings ) );
        $this->assertSame( 'EN', expAiTranslationLocale::forProvider( 'eng-GB', 'deepl', 'source', $settings ) );
    }

    public function testLibreTranslateAndOtherServices(): void
    {
        $this->assertSame( 'de', expAiTranslationLocale::forProvider( 'ger-DE', 'libretranslate', 'source' ) );
        $this->assertSame( 'pt', expAiTranslationLocale::forProvider( 'por-BR', 'libretranslate', 'target' ) );
        $this->assertSame( 'zt', expAiTranslationLocale::forProvider( 'chi-TW', 'libretranslate', 'target' ) );
        $this->assertSame( 'tlh', expAiTranslationLocale::forProvider( 'tlh-QO', 'libretranslate', 'target' ), 'without a list LibreTranslate is asked' );
        $this->assertSame( '', expAiTranslationLocale::forProvider( 'tlh-QO', 'libretranslate', 'target', array( 'Languages' => array( 'de', 'en' ) ) ) );
        // another service: the HTTP locale; the class decides the rules when the id does not
        $this->assertSame( 'de-DE', expAiTranslationLocale::forProvider( 'ger-DE', 'mymt', 'target' ) );
        $this->assertSame( 'DE', expAiTranslationLocale::forProvider( 'ger-DE', 'mydeepl', 'source', array( 'Class' => 'expAiTranslationProviderDeepl' ) ) );
        $this->assertSame( 'de', expAiTranslationLocale::forProvider( 'ger-DE', 'x', 'source', array( 'LanguageRules' => 'libretranslate' ) ) );
        $this->assertSame( array( 'de', 'DE' ), expAiTranslationLocale::httpParts( 'ger-DE' ) );
        $this->assertSame( array( '', '' ), expAiTranslationLocale::httpParts( 'xxx-YY' ) );
    }
}
