<?php
/**
 * File containing the expAiSentences class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Text normalisation for hashing and a language-aware sentence splitter (doc/diff.md).
 *
 * The splitter is deterministic on purpose (hunk ids must not change when a server gets another ICU version): a
 * boundary is sentence punctuation (. ! ? and the ellipsis), optional closing quotes or brackets, white space, and
 * then a capital letter, a digit or an opening quote. A full stop does not end a sentence after
 *   - an abbreviation of the language (built in for English and German, more in expai.ini [DiffSettings]
 *     Abbreviations[<language>]), also written with inner dots and spaces ("z. B.", "e.g.", "i. d. R."),
 *   - a single letter (initials: "J. R. R. Tolkien"),
 *   - a number in German (ordinals: "am 3. Oktober"), and a number followed by a lower-case word anywhere.
 */
class expAiSentences
{
    /** built-in abbreviations, lower case, without the final dot; inner dots kept ("z.b") */
    protected static $abbreviations = array(
        'eng' => array( 'mr', 'mrs', 'ms', 'dr', 'prof', 'sr', 'jr', 'st', 'vs', 'etc', 'e.g', 'i.e', 'inc', 'ltd', 'co', 'corp',
                        'no', 'nos', 'fig', 'figs', 'approx', 'dept', 'est', 'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep',
                        'sept', 'oct', 'nov', 'dec', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun', 'u.s', 'u.k', 'a.m', 'p.m',
                        'cf', 'al', 'ave', 'blvd', 'rd', 'mt', 'gen', 'gov', 'rev', 'vol', 'ed', 'eds', 'p', 'pp', 'ca', 'min', 'max' ),
        'ger' => array( 'z.b', 'd.h', 'u.a', 'usw', 'bzw', 'ca', 'dr', 'prof', 'nr', 'str', 's', 'vgl', 'ggf', 'evtl', 'inkl', 'zzgl',
                        'bspw', 'u.ä', 'o.ä', 'etc', 'sog', 'geb', 'hr', 'fr', 'abs', 'art', 'jh', 'jhd', 'mio', 'mrd', 'tel', 'z.t', 'i.d.r',
                        'u.u', 'v.a', 'u.v.m', 'm.e', 'bzgl', 'max', 'min', 'gegr', 'allg', 'betr', 'dt', 'engl', 'frz', 'ital', 'evang',
                        'kath', 'lt', 'mind', 'o.g', 'u.g', 'sek', 'std', 'tsd', 'urspr', 'zit', 'zw', 'a.d', 'i.a', 'i.r', 'n.chr',
                        'v.chr', 'jan', 'feb', 'mär', 'apr', 'jun', 'jul', 'aug', 'sep', 'sept', 'okt', 'nov', 'dez', 'mo', 'di', 'mi',
                        'do', 'sa', 'so', 'gmbh', 'co', 'e.v', 'pkt', 'ziff', 'anm', 'hrsg', 'aufl', 'bd', 'kap' ),
    );

    /**
     * NFC, white space collapsed, typographic quotes, dashes and non-breaking spaces normalised.
     * @return string
     */
    public static function normalize( $text )
    {
        $text = (string) $text;
        if ( class_exists( 'Normalizer' ) )
        {
            $nfc = Normalizer::normalize( $text, Normalizer::FORM_C );
            if ( is_string( $nfc ) )
                $text = $nfc;
        }
        $text = strtr( $text, array( "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"', "\u{00AB}" => '"', "\u{00BB}" => '"',
                                     "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'", "\u{2039}" => "'", "\u{203A}" => "'",
                                     "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-', "\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2009}" => ' ',
                                     "\u{200B}" => '' ) );
        return trim( preg_replace( '/\s+/u', ' ', $text ) );
    }

    /** @return string the hash of a normalised text */
    public static function hash( $text )
    {
        return substr( sha1( self::normalize( $text ) ), 0, 16 );
    }

    /** @return int characters (UTF-8) */
    public static function chars( $text )
    {
        return mb_strlen( (string) $text, 'UTF-8' );
    }

    /** @return string the three-letter language family of a locale: ger-DE -> ger, eng-GB -> eng, de_DE -> ger */
    public static function family( $language )
    {
        $language = strtolower( (string) $language );
        $map = array( 'de' => 'ger', 'en' => 'eng', 'fr' => 'fre', 'es' => 'esl', 'it' => 'ita', 'nl' => 'dut', 'pl' => 'pol', 'pt' => 'por' );
        $head = preg_split( '/[-_]/', $language );
        $head = $head[0];
        if ( strlen( $head ) === 2 && isset( $map[$head] ) )
            return $map[$head];
        return $head === 'deu' ? 'ger' : $head;
    }

    /** @return string[] the abbreviations of a language (built in plus [DiffSettings] Abbreviations[<family>]) */
    public static function abbreviations( $language, ?expAiConfig $config = null )
    {
        $family = self::family( $language );
        $list = isset( self::$abbreviations[$family] ) ? self::$abbreviations[$family] : self::$abbreviations['eng'];
        $config = $config ?: expAiConfig::instance();
        $extra = $config->get( 'DiffSettings', 'Abbreviations', array() );
        if ( is_array( $extra ) && !empty( $extra[$family] ) )
            foreach ( explode( ';', (string) $extra[$family] ) as $word )
                if ( trim( $word ) !== '' )
                    $list[] = mb_strtolower( rtrim( trim( $word ), '.' ), 'UTF-8' );
        return array_values( array_unique( $list ) );
    }

    /**
     * Splits a text into sentences. The pieces joined with single spaces give the normalised text back.
     * @param string $text
     * @param string $language a locale (eng-GB, ger-DE) or family (eng, ger)
     * @return string[]
     */
    public static function split( $text, $language = 'eng', ?array $abbreviations = null )
    {
        $text = self::normalize( $text );
        if ( $text === '' )
            return array();
        $family = self::family( $language );
        $abbr = array_flip( $abbreviations !== null ? $abbreviations : self::abbreviations( $language ) );
        $sentences = array();
        $start = 0;
        // candidates: punctuation, closing quotes/brackets, a space, then the start of the next sentence
        if ( !preg_match_all( '/[.!?\x{2026}]+["\')\]]*(?=\s+["\'(\[]?[\p{Lu}\p{N}])/u', $text, $matches, PREG_OFFSET_CAPTURE ) )
            return array( $text );
        foreach ( $matches[0] as $match )
        {
            $end = $match[1] + strlen( $match[0] );
            $piece = substr( $text, $start, $end - $start );
            if ( $match[0][0] === '.' && strlen( rtrim( $match[0], "\"')]" ) ) === 1 && self::endsWithAbbreviation( $piece, $abbr, $family, substr( $text, $end ) ) )
                continue;
            $sentences[] = trim( $piece );
            $start = $end;
        }
        $rest = trim( substr( $text, $start ) );
        if ( $rest !== '' )
            $sentences[] = $rest;
        return array_values( array_filter( $sentences, 'strlen' ) );
    }

    /** whether the text before a full stop ends with an abbreviation, an initial or an ordinal */
    protected static function endsWithAbbreviation( $piece, array $abbr, $family, $after )
    {
        $body = substr( $piece, 0, -1 );
        if ( !preg_match( '/(\S+)$/u', $body, $m ) )
            return false;
        $last = mb_strtolower( trim( $m[1], "\"'([" ), 'UTF-8' );
        if ( $last === '' )
            return false;
        // a single letter: an initial or the part of an abbreviation such as "z. B."
        if ( preg_match( '/^\p{L}$/u', $last ) )
            return true;
        if ( isset( $abbr[$last] ) )
            return true;
        // "z. B" -> the last up to four tokens joined with dots
        if ( preg_match( '/((?:\p{L}{1,4}\.\s?){1,3}\p{L}{1,4})$/u', $body, $m2 ) )
        {
            $joined = mb_strtolower( preg_replace( '/\.\s+/u', '.', $m2[1] ), 'UTF-8' );
            if ( isset( $abbr[$joined] ) )
                return true;
        }
        // German ordinals: "am 3. Oktober"; a number followed by a lower-case word in any language
        if ( preg_match( '/^\d+$/', $last ) )
        {
            if ( $family === 'ger' )
                return true;
            if ( preg_match( '/^\s+\p{Ll}/u', $after ) )
                return true;
        }
        return false;
    }
}
