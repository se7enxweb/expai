<?php
/**
 * File containing the expAiTranslationLocale class.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The language code a translation service expects for an eZ locale, in this order:
 *
 *   1. LanguageMap[<eZ locale>]=<code> in the service's [TranslationProvider_<id>] group (also LanguageMap[<eZ
 *      locale>:source] and LanguageMap[<eZ locale>:target] when the two roles differ);
 *   2. the HTTP locale of eZLocale (ger-DE: de-DE);
 *   3. the rules of the service: DeepL takes the base language as source (DE, EN) and needs a variant for some
 *      targets (EN-GB or EN-US, PT-PT or PT-BR, ZH-HANS or ZH-HANT); LibreTranslate takes the base language in lower
 *      case (de, en).
 *
 * '' means the service does not translate that language: supports() is then false, and the translation service
 * rules fall back ([TranslationSettings] Fallback). Locales without a locale file (or with a code the service does not
 * list, e.g. tlh-QO, kal-GL) give ''.
 */
class expAiTranslationLocale
{
    /**
     * The languages DeepL translates (base codes), used unless the service group lists its own (Languages[]).
     * Checked against the DeepL documentation of the supported languages; a language added later is one line in
     * Languages[] of [TranslationProvider_deepl].
     */
    const DEEPL_LANGUAGES = array( 'AR', 'BG', 'CS', 'DA', 'DE', 'EL', 'EN', 'ES', 'ET', 'FI', 'FR', 'HE', 'HU', 'ID', 'IT', 'JA', 'KO', 'LT',
                                   'LV', 'NB', 'NL', 'PL', 'PT', 'RO', 'RU', 'SK', 'SL', 'SV', 'TH', 'TR', 'UK', 'VI', 'ZH' );

    /** the countries whose English is British English for DeepL (EN-GB); every other English is EN-US */
    const BRITISH_ENGLISH = array( 'GB', 'IE', 'AU', 'NZ', 'IN', 'ZA', 'SG', 'HK', 'MT', 'KE', 'NG', 'GH', 'PK' );

    /** the countries whose Chinese is written in traditional characters (ZH-HANT) */
    const TRADITIONAL_CHINESE = array( 'TW', 'HK', 'MO' );

    /**
     * @param string $ezLocale ger-DE
     * @param string $providerId deepl, libretranslate, or another id (generic rules: the HTTP locale)
     * @param string $role source | target
     * @param array $settings the service's group (LanguageMap[], Languages[])
     * @return string the service's code, '' when it does not translate the language
     */
    public static function forProvider( $ezLocale, $providerId, $role = 'target', array $settings = array() )
    {
        $ezLocale = (string) $ezLocale;
        $map = isset( $settings['LanguageMap'] ) && is_array( $settings['LanguageMap'] ) ? $settings['LanguageMap'] : array();
        foreach ( array( $ezLocale . ':' . $role, $ezLocale ) as $key )
            if ( isset( $map[$key] ) && trim( (string) $map[$key] ) !== '' )
                return trim( (string) $map[$key] );
        list( $language, $country ) = self::httpParts( $ezLocale );
        if ( $language === '' )
            return '';
        $listed = isset( $settings['Languages'] ) && is_array( $settings['Languages'] )
                ? array_values( array_filter( array_map( 'strtolower', array_map( 'trim', array_map( 'strval', $settings['Languages'] ) ) ), 'strlen' ) ) : array();
        switch ( self::family( $providerId, $settings ) )
        {
            case 'deepl':
                return self::deepl( $language, $country, $role, $listed );
            case 'libretranslate':
                if ( $listed && !in_array( $language, $listed, true ) )
                    return '';
                // LibreTranslate names traditional Chinese zt
                return $language === 'zh' && in_array( $country, self::TRADITIONAL_CHINESE, true ) ? 'zt' : $language;
            default:
                $code = $language . ( $country !== '' ? '-' . $country : '' );
                if ( $listed && !in_array( $language, $listed, true ) && !in_array( strtolower( $code ), $listed, true ) )
                    return '';
                return $code;
        }
    }

    /**
     * The rules of a service: [TranslationProvider_<id>] LanguageRules=deepl | libretranslate | generic, else by the
     * class or the id.
     * @return string deepl | libretranslate | generic
     */
    public static function family( $providerId, array $settings = array() )
    {
        $rules = isset( $settings['LanguageRules'] ) ? strtolower( trim( (string) $settings['LanguageRules'] ) ) : '';
        if ( in_array( $rules, array( 'deepl', 'libretranslate', 'generic' ), true ) )
            return $rules;
        $class = isset( $settings['Class'] ) ? strtolower( (string) $settings['Class'] ) : '';
        if ( $class === 'expaitranslationproviderdeepl' || $providerId === 'deepl' )
            return 'deepl';
        if ( $class === 'expaitranslationproviderlibretranslate' || $providerId === 'libretranslate' )
            return 'libretranslate';
        return 'generic';
    }

    /**
     * The language and country of an eZ locale, from its HTTP locale (ger-DE: de, DE; nor-NO: nb, NO).
     * @return array( string language (lower case), string country (upper case) ); ( '', '' ) for an unknown locale
     */
    public static function httpParts( $ezLocale )
    {
        $ezLocale = (string) $ezLocale;
        if ( !preg_match( '/^[a-z]{2,3}-[A-Z]{2}(@\w+)?$/', $ezLocale ) )
            return array( '', '' );
        $locale = eZLocale::instance( $ezLocale );
        if ( !$locale || !$locale->isValid() )
            return array( '', '' );
        $http = (string) $locale->httpLocaleCode();
        $parts = explode( '-', $http, 2 );
        $language = strtolower( trim( $parts[0] ) );
        $country = isset( $parts[1] ) ? strtoupper( trim( $parts[1] ) ) : strtoupper( (string) $locale->countryCode() );
        if ( !preg_match( '/^[a-z]{2,3}$/', $language ) )
            return array( '', '' );
        return array( $language, $country );
    }

    /** @return string the DeepL code of a language as source or target, '' when DeepL does not translate it */
    protected static function deepl( $language, $country, $role, array $listed )
    {
        $base = strtoupper( $language );
        $known = $listed ? array_map( 'strtoupper', $listed ) : self::DEEPL_LANGUAGES;
        if ( !in_array( $base, $known, true ) )
            return '';
        if ( $role === 'source' )
            return $base;
        switch ( $base )
        {
            case 'EN':
                return in_array( $country, self::BRITISH_ENGLISH, true ) ? 'EN-GB' : 'EN-US';
            case 'PT':
                return $country === 'BR' ? 'PT-BR' : 'PT-PT';
            case 'ZH':
                return in_array( $country, self::TRADITIONAL_CHINESE, true ) ? 'ZH-HANT' : 'ZH-HANS';
        }
        return $base;
    }
}
