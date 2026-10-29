<?php
/**
 * File containing the expAiWebHtml class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * HTML to readable Markdown for the model: the title, headings (#), paragraphs, lists, quotes, code, tables as rows
 * and links as [text](absolute URL). Scripts, styles, forms, frames, images and hidden elements are dropped; when the
 * page has a <main> or a single <article>, only that part is read (navigation and footers stay out). Nothing is
 * executed and no further request is made.
 */
class expAiWebHtml
{
    /** @var string[] elements whose content is never read */
    protected static $skip = array( 'script', 'style', 'noscript', 'template', 'svg', 'math', 'iframe', 'frame', 'frameset', 'object',
                                    'embed', 'canvas', 'form', 'input', 'button', 'select', 'textarea', 'img', 'picture', 'video',
                                    'audio', 'source', 'link', 'meta', 'head', 'dialog' );

    /** @var string */
    protected $base;

    /** @var int */
    protected $links = 0;

    /**
     * @param string $html
     * @param string $url the address the page came from (relative links are resolved against it or <base href>)
     * @return array title, text (Markdown), links (count)
     */
    public static function toMarkdown( $html, $url )
    {
        $converter = new self();
        return $converter->convert( (string) $html, (string) $url );
    }

    protected function convert( $html, $url )
    {
        $this->base = $url;
        $this->links = 0;
        if ( trim( $html ) === '' )
            return array( 'title' => '', 'text' => '', 'links' => 0 );
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        // the text is UTF-8 already (the fetcher converted it): a charset of the page's own meta element would make
        // libxml decode it a second time, so it goes
        $html = (string) preg_replace( '/<meta\b[^>]*charset\s*=[^>]*>/i', '', $html );
        // no network, no entities: LIBXML_NONET; the encoding hint makes DOMDocument read UTF-8
        $document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        $title = '';
        $titles = $document->getElementsByTagName( 'title' );
        if ( $titles->length )
            $title = self::clean( $titles->item( 0 )->textContent );
        foreach ( $document->getElementsByTagName( 'base' ) as $base )
        {
            $resolved = expAiWebUrl::resolve( $url, $base->getAttribute( 'href' ) );
            if ( $resolved !== null )
                $this->base = $resolved;
            break;
        }

        $root = null;
        $mains = $document->getElementsByTagName( 'main' );
        if ( $mains->length === 1 )
            $root = $mains->item( 0 );
        else
        {
            $articles = $document->getElementsByTagName( 'article' );
            if ( $articles->length === 1 )
                $root = $articles->item( 0 );
        }
        if ( !$root )
        {
            $bodies = $document->getElementsByTagName( 'body' );
            $root = $bodies->length ? $bodies->item( 0 ) : $document->documentElement;
        }
        $text = $root ? $this->blocks( $root ) : '';
        $text = preg_replace( "/[ \t]+\n/", "\n", $text );
        $text = trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
        return array( 'title' => $title, 'text' => $text, 'links' => $this->links );
    }

    /** @return string the Markdown of the children of a node */
    protected function blocks( DOMNode $node )
    {
        $out = '';
        foreach ( $node->childNodes as $child )
            $out .= $this->node( $child );
        return $out;
    }

    protected function node( DOMNode $node )
    {
        if ( $node instanceof DOMText )
            return preg_replace( '/\s+/u', ' ', $node->textContent );
        if ( !$node instanceof DOMElement )
            return '';
        $tag = strtolower( $node->tagName );
        if ( in_array( $tag, self::$skip, true ) || $node->hasAttribute( 'hidden' ) || strtolower( $node->getAttribute( 'aria-hidden' ) ) === 'true'
             || preg_match( '/display\s*:\s*none/i', $node->getAttribute( 'style' ) ) )
            return '';
        switch ( $tag )
        {
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $inner = self::clean( $this->blocks( $node ) );
                return $inner === '' ? '' : "\n\n" . str_repeat( '#', (int) $tag[1] ) . ' ' . $inner . "\n\n";
            case 'p': case 'div': case 'section': case 'article': case 'main': case 'header': case 'footer': case 'nav': case 'aside':
            case 'figure': case 'figcaption': case 'details': case 'summary': case 'dl': case 'address':
                return "\n\n" . trim( $this->blocks( $node ) ) . "\n\n";
            case 'br':
                return "\n";
            case 'hr':
                return "\n\n---\n\n";
            case 'ul': case 'ol':
                $lines = array();
                $n = 0;
                foreach ( $node->childNodes as $item )
                {
                    if ( !$item instanceof DOMElement || strtolower( $item->tagName ) !== 'li' )
                        continue;
                    $inner = trim( preg_replace( "/\n{2,}/", "\n", $this->blocks( $item ) ) );
                    if ( $inner === '' )
                        continue;
                    $lines[] = ( $tag === 'ol' ? ( ++$n ) . '. ' : '- ' ) . str_replace( "\n", "\n  ", $inner );
                }
                return $lines ? "\n\n" . implode( "\n", $lines ) . "\n\n" : '';
            case 'li':
                return "\n- " . trim( $this->blocks( $node ) ) . "\n";
            case 'dt':
                return "\n**" . self::clean( $this->blocks( $node ) ) . "**\n";
            case 'dd':
                return ': ' . trim( $this->blocks( $node ) ) . "\n";
            case 'blockquote':
                $inner = trim( $this->blocks( $node ) );
                return $inner === '' ? '' : "\n\n> " . str_replace( "\n", "\n> ", $inner ) . "\n\n";
            case 'pre':
                $code = rtrim( $node->textContent );
                return $code === '' ? '' : "\n\n```\n" . str_replace( '```', '` ` `', $code ) . "\n```\n\n";
            case 'code': case 'kbd': case 'samp':
                $code = trim( $node->textContent );
                return $code === '' ? '' : '`' . str_replace( '`', "'", $code ) . '`';
            case 'strong': case 'b':
                $inner = trim( $this->blocks( $node ) );
                return $inner === '' ? '' : '**' . $inner . '**';
            case 'em': case 'i':
                $inner = trim( $this->blocks( $node ) );
                return $inner === '' ? '' : '*' . $inner . '*';
            case 'a':
                $inner = self::clean( $this->blocks( $node ) );
                $href = expAiWebUrl::resolve( $this->base, $node->getAttribute( 'href' ) );
                if ( $href === null || $inner === '' )
                    return $inner === '' ? '' : ' ' . $inner . ' ';
                $href = preg_replace( '/#.*$/', '', $href );
                $this->links++;
                return ' [' . str_replace( array( '[', ']' ), array( '(', ')' ), $inner ) . '](' . str_replace( array( ' ', ')' ), array( '%20', '%29' ), $href ) . ') ';
            case 'table':
                $rows = array();
                foreach ( $node->getElementsByTagName( 'tr' ) as $row )
                {
                    $cells = array();
                    foreach ( $row->childNodes as $cell )
                        if ( $cell instanceof DOMElement && in_array( strtolower( $cell->tagName ), array( 'td', 'th' ), true ) )
                            $cells[] = str_replace( '|', '/', self::clean( $this->blocks( $cell ) ) );
                    if ( $cells )
                        $rows[] = '| ' . implode( ' | ', $cells ) . ' |';
                }
                return $rows ? "\n\n" . implode( "\n", $rows ) . "\n\n" : '';
            default:
                return $this->blocks( $node );
        }
    }

    /** @return string one line, single spaces */
    protected static function clean( $text )
    {
        return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
    }
}
