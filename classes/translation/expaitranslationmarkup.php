<?php
/**
 * File containing the expAiTranslationMarkup class.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The placeholders of the coded form for a machine translation service, and back.
 *
 * The translation sync codes a block as XML-escaped text with numbered placeholders (expAiSegmenterRichText):
 * <1>text</1> wraps text in a link or emphasis, <2/> is an embedded object or a line break. <1> is not a valid XML
 * or HTML name, so a service gets its own markup:
 *
 *   style xml  (DeepL, tag_handling=xml)       <x i="1">text</x>, <x i="2"/>; a term never translated: <k>term</k>
 *   style html (LibreTranslate, format=html)   <span data-x="1">text</span>, <br data-x="2">;
 *                                               a term never translated: <span translate="no" class="notranslate">term</span>
 *
 * fromMarkup() turns the answer back into the coded form and checks it against the source: every placeholder of the
 * source exactly once, in the same form (wrapping or embedded), properly nested, no unknown or new one. A text that
 * fails is refused (expAiTranslationException, permanent), so a broken text never reaches a draft.
 */
class expAiTranslationMarkup
{
    const STYLE_XML = 'xml';
    const STYLE_HTML = 'html';

    /** the tag of a term that is never translated (DeepL ignore_tags) */
    const IGNORE_TAG = 'k';

    /**
     * The coded text in the markup of a service.
     * @param string $coded XML-escaped text with numbered placeholders
     * @param string $style xml | html
     * @param string[] $terms terms that are never translated (wrapped where they stand as whole words)
     * @return string
     */
    public static function toMarkup( $coded, $style = self::STYLE_XML, array $terms = array() )
    {
        $out = '';
        $html = $style === self::STYLE_HTML;
        foreach ( preg_split( '~(</?\d+/?>)~', (string) $coded, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) as $token )
        {
            if ( preg_match( '~^<(/?)(\d+)(/?)>$~', $token, $m ) )
            {
                if ( $m[1] === '/' )
                    $out .= $html ? '</span>' : '</x>';
                elseif ( $m[3] === '/' )
                    $out .= $html ? '<br data-x="' . $m[2] . '">' : '<x i="' . $m[2] . '"/>';
                else
                    $out .= $html ? '<span data-x="' . $m[2] . '">' : '<x i="' . $m[2] . '">';
                continue;
            }
            $out .= self::wrapTerms( $token, $style, $terms );
        }
        return $out;
    }

    /**
     * Wraps the terms in a piece of escaped text in the tag the service leaves alone.
     * @return string
     */
    public static function wrapTerms( $text, $style, array $terms )
    {
        $alternatives = array();
        foreach ( $terms as $term )
        {
            $term = trim( (string) $term );
            if ( $term !== '' )
                $alternatives[] = preg_quote( htmlspecialchars( $term, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' ), '~' );
        }
        if ( !$alternatives )
            return (string) $text;
        // the longest first, so that "Exponential Intelligence" is wrapped as one term before "Exponential"
        usort( $alternatives, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
        $open = $style === self::STYLE_HTML ? self::HTML_TERM_OPEN : '<' . self::IGNORE_TAG . '>';
        $close = $style === self::STYLE_HTML ? '</span>' : '</' . self::IGNORE_TAG . '>';
        // an entity (&amp; &lt; &#39; ...) is matched first and kept as it is: a term never matches inside one
        $result = preg_replace_callback( '~(&#?\w+;)|(?<![\p{L}\p{N}_])(?:' . implode( '|', array_unique( $alternatives ) ) . ')(?![\p{L}\p{N}_])~u',
            function ( $m ) use ( $open, $close ) { return isset( $m[1] ) && $m[1] !== '' ? $m[0] : $open . $m[0] . $close; }, (string) $text );
        return $result === null ? (string) $text : $result;
    }

    /** the element of a term that is never translated in HTML (DeepL tag_handling=html and LibreTranslate honour translate="no") */
    const HTML_TERM_OPEN = '<span translate="no" class="notranslate">';

    /**
     * Wraps the terms in the text of HTML (the editor's HTML of the field action), never inside a tag.
     * @return string
     */
    public static function wrapTermsInHtml( $html, array $terms )
    {
        if ( !array_filter( array_map( 'trim', array_map( 'strval', $terms ) ), 'strlen' ) )
            return (string) $html;
        $out = '';
        foreach ( preg_split( '~(<[^<>]*>)~', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) as $part )
            $out .= $part[0] === '<' && substr( $part, -1 ) === '>' ? $part : self::wrapTerms( $part, self::STYLE_HTML, $terms );
        return $out;
    }

    /** @return string HTML without the elements wrapTermsInHtml() added (their text stays) */
    public static function unwrapTermsInHtml( $html )
    {
        $result = preg_replace( '~<span\s+translate\s*=\s*["\']no["\']\s+class\s*=\s*["\']notranslate["\']\s*>(.*?)</span>~su', '$1', (string) $html );
        return $result === null ? (string) $html : $result;
    }

    /** @return string text without C0 control characters other than tab, line feed and carriage return */
    public static function stripControls( $text )
    {
        return (string) preg_replace( '~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~', '', (string) $text );
    }

    /**
     * The placeholders of a coded text.
     * @return array number => pair | atom
     */
    public static function placeholders( $coded )
    {
        $out = array();
        preg_match_all( '~<(/?)(\d+)(/?)>~', (string) $coded, $matches, PREG_SET_ORDER );
        foreach ( $matches as $m )
            if ( $m[1] !== '/' )
                $out[(int) $m[2]] = $m[3] === '/' ? 'atom' : 'pair';
        return $out;
    }

    /**
     * The answer of a service in the coded form, checked against the source.
     * @param string $text the translated text in the service's markup
     * @param string $source the coded source text
     * @param string $style xml | html
     * @return string the coded translation
     * @throws expAiTranslationException (permanent) when a placeholder is unknown, repeated, renumbered, dropped,
     *                                   of the other form or wrongly nested, or other markup came back
     */
    public static function fromMarkup( $text, $source, $style = self::STYLE_XML )
    {
        $expected = self::placeholders( $source );
        $html = $style === self::STYLE_HTML;
        $tokens = preg_split( '~(<[^<>]*>)~', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
        $out = '';
        $stack = array();
        $used = array();
        foreach ( $tokens as $token )
        {
            if ( $token[0] !== '<' || substr( $token, -1 ) !== '>' )
            {
                // control characters (a NUL, an escape) never reach a draft
                $out .= htmlspecialchars( self::stripControls( html_entity_decode( $token, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
                continue;
            }
            list( $type, $number ) = self::classify( $token, $html );
            switch ( $type )
            {
                case 'open':
                case 'atom':
                    if ( !isset( $expected[$number] ) )
                        self::fail( 'unknown placeholder ' . $number );
                    if ( isset( $used[$number] ) )
                        self::fail( 'placeholder ' . $number . ' used twice' );
                    if ( ( $type === 'atom' ) !== ( $expected[$number] === 'atom' ) )
                        self::fail( 'placeholder ' . $number . ' came back in the other form' );
                    $used[$number] = true;
                    if ( $type === 'atom' )
                    {
                        $out .= '<' . $number . '/>';
                        break;
                    }
                    $stack[] = $number;
                    $out .= '<' . $number . '>';
                    break;
                case 'term':
                    $stack[] = 'term';
                    break;
                case 'term_atom':
                    break;
                case 'close':
                case 'close_term':
                    if ( !$stack )
                        self::fail( 'a closing tag without an opening one' );
                    $top = array_pop( $stack );
                    // html: </span> closes either kind; xml: </x> a placeholder, </k> a term
                    if ( $type === 'close_term' && $top !== 'term' || $type === 'close' && !$html && $top === 'term' )
                        self::fail( 'tags closed out of order' );
                    if ( $top !== 'term' )
                        $out .= '</' . $top . '>';
                    break;
                default:
                    self::fail( 'unexpected markup ' . substr( preg_replace( '~\s+~', ' ', $token ), 0, 40 ) );
            }
        }
        if ( $stack )
            self::fail( 'placeholder ' . end( $stack ) . ' not closed' );
        $missing = array_diff( array_keys( $expected ), array_keys( $used ) );
        if ( $missing )
            self::fail( 'placeholder ' . implode( ', ', $missing ) . ' missing' );
        return trim( $out );
    }

    /**
     * @return array( type, number ): open, atom, close, term, term_atom, close_term or unknown
     */
    protected static function classify( $tag, $html )
    {
        if ( $html )
        {
            if ( preg_match( '~^<\s*/\s*span\s*>$~i', $tag ) )
                return array( 'close', 0 );
            if ( preg_match( '~^<\s*span\b([^>]*?)/?\s*>$~i', $tag, $m ) )
            {
                if ( preg_match( '~\bdata-x\s*=\s*["\']?(\d+)~i', $m[1], $n ) )
                    return array( 'open', (int) $n[1] );
                if ( preg_match( '~\btranslate\s*=\s*["\']?no\b~i', $m[1] ) )
                    return array( 'term', 0 );
                return array( 'unknown', 0 );
            }
            if ( preg_match( '~^<\s*br\b([^>]*)>$~i', $tag, $m ) && preg_match( '~\bdata-x\s*=\s*["\']?(\d+)~i', $m[1], $n ) )
                return array( 'atom', (int) $n[1] );
            return array( 'unknown', 0 );
        }
        if ( preg_match( '~^<\s*x\s+i\s*=\s*["\']?(\d+)["\']?\s*(/?)\s*>$~', $tag, $m ) )
            return array( $m[2] === '/' ? 'atom' : 'open', (int) $m[1] );
        if ( preg_match( '~^<\s*/\s*x\s*>$~', $tag ) )
            return array( 'close', 0 );
        if ( preg_match( '~^<\s*' . self::IGNORE_TAG . '\s*(/?)\s*>$~', $tag, $m ) )
            return array( $m[1] === '/' ? 'term_atom' : 'term', 0 );
        if ( preg_match( '~^<\s*/\s*' . self::IGNORE_TAG . '\s*>$~', $tag ) )
            return array( 'close_term', 0 );
        return array( 'unknown', 0 );
    }

    /** @throws expAiTranslationException */
    protected static function fail( $detail )
    {
        throw new expAiTranslationException( 'The translation does not fit the markup of the text.', $detail, 0, array(), false );
    }
}
