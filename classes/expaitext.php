<?php
/**
 * File containing the expAiText class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Text helpers: shortening, the HTML subset the assistant writes rich text in, and a plain HTML view of stored
 * ezxmltext for the assistant to read.
 */
class expAiText
{
    /** the characters extractJson() reads at most while it looks for balanced objects (2 MB of answer once over) */
    const EXTRACT_JSON_MAX_STEPS = 2000000;

    /** Tags the assistant may use in rich text (ezxmltext), with the attributes kept */
    public static $allowedTags = array( 'p' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(), 'strong' => array(),
                                        'b' => array(), 'em' => array(), 'i' => array(), 'ul' => array(), 'ol' => array(),
                                        'li' => array(), 'a' => array( 'href' ), 'br' => array() );

    /** @return string at most $max characters (multibyte safe), with an ellipsis when cut */
    public static function cut( $text, $max )
    {
        $text = (string) $text;
        if ( $max <= 0 || mb_strlen( $text, 'UTF-8' ) <= $max )
            return $text;
        return rtrim( mb_substr( $text, 0, max( 1, $max - 1 ), 'UTF-8' ) ) . "\u{2026}";
    }

    /** @return string one line, no control characters */
    public static function oneLine( $text )
    {
        return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
    }

    /** Harmony and chat template tokens that end the message they follow */
    public static $endTokens = array( '<|end|>', '<|return|>', '<|call|>', '<|start|>', '<|im_end|>', '<|im_start|>', '<|endoftext|>', '<|eot_id|>' );

    /** Special tokens of chat templates that are never part of an answer (only these, never any other <|x|> text) */
    public static $specialTokens = array( '<|im_start|>', '<|im_end|>', '<|endoftext|>', '<|eot_id|>', '<|end|>', '<|return|>', '<|call|>',
                                          '<|start|>', '<|message|>', '<|channel|>', '<|constrain|>' );

    /**
     * The answer of a model without its reasoning. Only real reasoning markup is handled; a text that merely mentions
     * a tag or a token keeps it. Invalid UTF-8 is replaced first. The rules:
     * - Harmony: only when the text has its structure (<|channel|> and <|message|>). Then only the last final channel
     *   counts: the text after <|channel|>final ... <|message|> up to the first end token (expAiText::$endTokens) or
     *   the end of the text; the known special tokens (expAiText::$specialTokens) left in it are removed. A Harmony
     *   text without a final channel has no answer ('').
     * - Think tags: complete <think>...</think> blocks at the start of the text (after whitespace) are removed. An
     *   opening <think> at the start that is never closed means the model did not answer (''). A <think> later in the
     *   text is plain text.
     * - A closing </think> without an opening tag (some chat templates write the opening tag themselves) ends the
     *   reasoning when no <think> comes before it and a line break follows it: the answer is the text after it. Alone
     *   on its last line with nothing after it, it means reasoning without an answer (''). Anywhere else (e.g. in a
     *   sentence) it is plain text.
     * - Known special tokens at the end of a text without Harmony structure are removed (a leaked <|im_end|>).
     * When the regular expression engine fails (e.g. its backtrack limit on a very long text), the text is returned
     * as it is, trimmed: a limit of the engine never turns an answer into ''.
     * @return string the answer, trimmed; '' when there is none
     */
    public static function stripReasoning( $text )
    {
        $text = mb_scrub( (string) $text, 'UTF-8' );
        $original = trim( $text );
        if ( strpos( $text, '<|channel|>' ) !== false && strpos( $text, '<|message|>' ) !== false )
        {
            $final = self::harmonyFinal( $text );
            if ( $final === false )
                return $original;
            if ( $final === null )
                return '';
            $text = str_replace( self::$specialTokens, '', $final );
        }
        // complete think blocks at the start
        while ( ( $found = preg_match( '~^\s*<think\b[^>]*>~i', $text, $m ) ) === 1 )
        {
            $close = self::closingThink( $text, strlen( $m[0] ) );
            if ( $close === false )
                return $original;
            // still thinking when the answer ended: no answer
            if ( $close === null )
                return '';
            $text = substr( $text, $close[0] + $close[1] );
        }
        if ( $found === false )
            return $original;
        // a closing tag whose opening tag was part of the prompt
        $close = self::closingThink( $text, 0 );
        if ( $close === false )
            return $original;
        if ( $close !== null && stripos( substr( $text, 0, $close[0] ), '<think' ) === false )
        {
            $before = substr( $text, 0, $close[0] );
            $after = substr( $text, $close[0] + $close[1] );
            if ( trim( $after ) !== '' && preg_match( '~^[ \t]*\r?\n~', $after ) )
                $text = $after;
            elseif ( trim( $after ) === '' && ( trim( $before ) === '' || preg_match( '~\n[ \t]*$~', $before ) ) )
                return '';
        }
        // a special token leaked at the end
        $text = rtrim( $text );
        do
        {
            $length = strlen( $text );
            foreach ( self::$specialTokens as $token )
                if ( substr( $text, -strlen( $token ) ) === $token )
                    $text = rtrim( substr( $text, 0, -strlen( $token ) ) );
        }
        while ( strlen( $text ) !== $length );
        return trim( $text );
    }

    /**
     * @return string|null|false the message of the last final channel of a Harmony text; null when there is none;
     *                           false when the regular expression engine failed
     */
    protected static function harmonyFinal( $text )
    {
        $final = null;
        $offset = 0;
        while ( ( $position = strpos( $text, '<|channel|>', $offset ) ) !== false )
        {
            $offset = $position + strlen( '<|channel|>' );
            $isFinal = preg_match( '~\G\s*final\b~', $text, $m, 0, $offset );
            if ( $isFinal === false )
                return false;
            if ( !$isFinal )
                continue;
            $message = strpos( $text, '<|message|>', $offset );
            if ( $message === false )
                break;
            // the header of this channel ends before the next channel
            $next = strpos( $text, '<|channel|>', $offset );
            if ( $next !== false && $next < $message )
                continue;
            $start = $message + strlen( '<|message|>' );
            $end = strlen( $text );
            foreach ( self::$endTokens as $token )
            {
                $at = strpos( $text, $token, $start );
                if ( $at !== false && $at < $end )
                    $end = $at;
            }
            $final = substr( $text, $start, $end - $start );
            $offset = $end;
        }
        return $final;
    }

    /**
     * @return array|null|false the offset and length of the first </think> at or after $offset; null when there is
     *                          none; false when the regular expression engine failed
     */
    protected static function closingThink( $text, $offset )
    {
        $at = stripos( $text, '</think', $offset );
        if ( $at === false )
            return null;
        $found = preg_match( '~</think\s*>~i', $text, $m, PREG_OFFSET_CAPTURE, $at );
        if ( $found === false )
            return false;
        return $found ? array( $m[0][1], strlen( $m[0][0] ) ) : null;
    }

    /**
     * The JSON in the text of a model: the whole text, a ```json fenced block (then any other fenced block) that
     * decodes, otherwise the longest balanced {...} that decodes (braces inside JSON strings and escaped quotes are
     * skipped while counting). The whole text and a fenced block may also be a list ([...]); of several objects in
     * prose the longest wins, so a complete object inside a cut one (e.g. the first element of a cut list) can be
     * returned: callers do not read an answer cut off at the token limit. Prose around the object is ignored. The
     * search for balanced objects reads at most EXTRACT_JSON_MAX_STEPS characters in all, so a long answer full of
     * unbalanced braces cannot hold the request (each start scans to its closing brace: quadratic without a limit);
     * it then returns the best object found so far.
     * @return array|null the decoded object or list; null when there is none
     */
    public static function extractJson( $text )
    {
        $text = trim( (string) $text );
        if ( $text === '' )
            return null;
        $whole = json_decode( $text, true );
        if ( is_array( $whole ) )
            return $whole;
        if ( preg_match_all( '~```[ \t]*([A-Za-z]*)[^\n`]*\n?(.*?)```~s', $text, $fences, PREG_SET_ORDER ) )
        {
            // a block marked json first, then any other block
            foreach ( array( true, false ) as $marked )
                foreach ( $fences as $fence )
                    if ( ( strtolower( $fence[1] ) === 'json' ) === $marked )
                    {
                        $data = json_decode( trim( $fence[2] ), true );
                        if ( is_array( $data ) )
                            return $data;
                    }
        }
        $best = null;
        $bestLength = 0;
        $length = strlen( $text );
        $steps = self::EXTRACT_JSON_MAX_STEPS;
        $start = strpos( $text, '{' );
        while ( $start !== false && $steps > 0 )
        {
            $end = self::closingBrace( $text, $start, $length, $steps );
            if ( $end === false )
            {
                $start = strpos( $text, '{', $start + 1 );
                continue;
            }
            $data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
            if ( is_array( $data ) )
            {
                if ( $end - $start + 1 > $bestLength )
                {
                    $best = $data;
                    $bestLength = $end - $start + 1;
                }
                // the objects inside this one are shorter
                $start = strpos( $text, '{', $end + 1 );
            }
            else
                $start = strpos( $text, '{', $start + 1 );
        }
        return $best;
    }

    /**
     * @param int $steps the characters the search may still read, reduced by what this call reads
     * @return int|false the position of the brace that closes the one at $start (braces in strings do not count)
     */
    protected static function closingBrace( $text, $start, $length, &$steps )
    {
        $depth = 0;
        $inString = false;
        for ( $i = $start; $i < $length; $i++ )
        {
            if ( --$steps < 0 )
                return false;
            $char = $text[$i];
            if ( $inString )
            {
                if ( $char === '\\' )
                    $i++;
                elseif ( $char === '"' )
                    $inString = false;
                continue;
            }
            if ( $char === '"' )
                $inString = true;
            elseif ( $char === '{' )
                $depth++;
            elseif ( $char === '}' && --$depth === 0 )
                return $i;
        }
        return false;
    }

    /**
     * The HTML subset of the assistant, cleaned: only the allowed tags, only http(s) and mailto links, no
     * attributes otherwise, no comments, scripts or styles.
     * @param bool $headings keep h1, h5 and h6 (rich text with placeholders: expAiRichTextTokens reads every heading
     *                       level as h1-h6); otherwise h1 becomes h2 and h5, h6 become h4
     * @return string
     */
    public static function sanitizeHtml( $html, $headings = false )
    {
        $html = (string) $html;
        if ( trim( $html ) === '' )
            return '';
        // Markdown habits of the model: **bold**, *em*, lines starting with "- "
        if ( !preg_match( '/<\s*(p|h[1-6]|ul|ol|li|strong|em|b|i|a|br)\b/i', $html ) )
            $html = self::textToHtml( $html );
        $document = new DOMDocument( '1.0', 'UTF-8' );
        $previous = libxml_use_internal_errors( true );
        $document->loadHTML( '<?xml encoding="UTF-8"?><div id="exp-ai-root">' . $html . '</div>', LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        $root = $document->getElementById( 'exp-ai-root' );
        if ( !$root )
            return '';
        $out = '';
        foreach ( iterator_to_array( $root->childNodes ) as $child )
            $out .= self::cleanNode( $child, $headings );
        // stray text at the top level goes into paragraphs
        return trim( $out );
    }

    protected static function cleanNode( DOMNode $node, $headings = false )
    {
        if ( $node instanceof DOMText )
            return htmlspecialchars( $node->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        if ( !$node instanceof DOMElement )
            return '';
        $name = strtolower( $node->nodeName );
        if ( in_array( $name, array( 'script', 'style', 'iframe', 'object', 'embed', 'img', 'svg', 'math', 'form', 'input', 'textarea', 'select', 'button' ), true ) )
            return '';
        if ( $name === 'h1' && !$headings )
            $name = 'h2';
        elseif ( in_array( $name, array( 'h5', 'h6' ), true ) && !$headings )
            $name = 'h4';
        $inner = '';
        foreach ( iterator_to_array( $node->childNodes ) as $child )
            $inner .= self::cleanNode( $child, $headings );
        if ( !isset( self::$allowedTags[$name] ) && !( $headings && in_array( $name, array( 'h1', 'h5', 'h6' ), true ) ) )
            return $inner;
        if ( $name === 'br' )
            return '<br />';
        if ( $name === 'b' )
            $name = 'strong';
        if ( $name === 'i' )
            $name = 'em';
        if ( $name === 'a' )
        {
            $href = trim( (string) $node->getAttribute( 'href' ) );
            if ( !preg_match( '~^(https?://[^\s"<>]+|mailto:[^\s"<>]+)$~i', $href ) )
                return $inner;
            return '<a href="' . htmlspecialchars( $href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '">' . $inner . '</a>';
        }
        if ( trim( strip_tags( $inner ) ) === '' && $name !== 'li' )
            return '';
        return "<$name>$inner</$name>";
    }

    /** @return string plain text (with simple Markdown) as HTML paragraphs and lists */
    public static function textToHtml( $text )
    {
        $esc = function ( $s )
        {
            $s = htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
            $s = preg_replace( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s );
            return preg_replace( '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '<em>$1</em>', $s );
        };
        $html = '';
        foreach ( preg_split( "/\n\s*\n/", str_replace( "\r", '', trim( (string) $text ) ) ) as $block )
        {
            $lines = explode( "\n", trim( $block ) );
            if ( preg_match( '/^#{1,3}\s+(.*)$/', $lines[0], $m ) && count( $lines ) === 1 )
                $html .= '<h2>' . $esc( $m[1] ) . '</h2>';
            elseif ( count( array_filter( $lines, function ( $l ) { return preg_match( '/^\s*[-*]\s+/', $l ); } ) ) === count( $lines ) )
                $html .= '<ul>' . implode( '', array_map( function ( $l ) use ( $esc ) { return '<li>' . $esc( preg_replace( '/^\s*[-*]\s+/', '', $l ) ) . '</li>'; }, $lines ) ) . '</ul>';
            else
                $html .= '<p>' . implode( '<br />', array_map( $esc, $lines ) ) . '</p>';
        }
        return $html;
    }

    /** @return string HTML as plain text (paragraphs separated by blank lines) */
    public static function htmlToText( $html )
    {
        $html = preg_replace( '~<\s*br\s*/?\s*>~i', "\n", (string) $html );
        $html = preg_replace( '~</\s*(p|h[1-6]|li|ul|ol|div)\s*>~i', "\n\n", $html );
        $text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( "/\n{3,}/", "\n\n", preg_replace( '/[ \t]+/', ' ', $text ) ) );
    }

    /**
     * Stored ezxmltext as a small HTML (paragraphs, headings, emphasis, lists, links) for the assistant to read.
     * @return string
     */
    public static function xmlTextToHtml( $xml )
    {
        $xml = (string) $xml;
        if ( trim( $xml ) === '' )
            return '';
        $document = new DOMDocument( '1.0', 'UTF-8' );
        $previous = libxml_use_internal_errors( true );
        $ok = $document->loadXML( $xml, LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( !$ok || !$document->documentElement )
            return '';
        return trim( self::xmlNodeToHtml( $document->documentElement ) );
    }

    protected static function xmlNodeToHtml( DOMNode $node )
    {
        if ( $node instanceof DOMText )
            return htmlspecialchars( $node->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        if ( !$node instanceof DOMElement )
            return '';
        $inner = '';
        foreach ( $node->childNodes as $child )
            $inner .= self::xmlNodeToHtml( $child );
        switch ( $node->localName )
        {
            case 'paragraph': return "<p>$inner</p>";
            case 'header': return '<h' . min( 4, max( 2, (int) $node->getAttribute( 'level' ) + 1 ) ) . ">$inner</h" . min( 4, max( 2, (int) $node->getAttribute( 'level' ) + 1 ) ) . '>';
            case 'strong': return "<strong>$inner</strong>";
            case 'emphasize': return "<em>$inner</em>";
            case 'ul': case 'ol': case 'li': return '<' . $node->localName . ">$inner</" . $node->localName . '>';
            case 'link':
                $url = '';
                if ( $node->hasAttribute( 'url_id' ) )
                {
                    $urlObject = eZURL::fetch( (int) $node->getAttribute( 'url_id' ) );
                    $url = $urlObject ? $urlObject->attribute( 'url' ) : '';
                }
                return $url !== '' ? '<a href="' . htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) . "\">$inner</a>" : $inner;
            case 'embed': case 'embed-inline': return '[embedded object ' . htmlspecialchars( $node->getAttribute( 'object_id' ) ?: $node->getAttribute( 'node_id' ), ENT_QUOTES, 'UTF-8' ) . ']';
            case 'line': return $inner . '<br />';
            default: return $inner;
        }
    }
}
