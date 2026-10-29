<?php
/**
 * File containing the expAiRichTextTokens class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Rich text (ezxmltext) for a model without losing structure (doc/configuration.md "[RichTextSettings]"). The model
 * reads and writes the small HTML subset of expAiText (p, h1-h6, strong, em, ul, ol, li, a href, br); everything the
 * subset cannot express is replaced by a placeholder, the same format as the assistant of exp_oe_tiptap uses:
 *
 *   ⟦C1⟧ custom tag (math_latex, math_mathml ... with all its attributes and content)   ⟦E1⟧ embed, embed-inline
 *   ⟦T1⟧ table   ⟦P1⟧ literal   ⟦A1⟧ anchor   ⟦L1⟧ link the subset cannot keep (to a node or object, an anchor,
 *   tel:, ...)   ⟦B1⟧ list with attributes   ⟦X1⟧ any other element   ([RichTextSettings] Protected[])
 *   ⟦K1⟧ at the start of a paragraph, heading, list item, strong, em or link: the attributes of that element
 *   ⟦R1⟧ the rest of a field longer than [RichTextSettings] MaxLength, kept unchanged
 *   ⟦Q1⟧ a ⟦ or ⟧ of the stored text itself (so text that looks like a placeholder is never taken for one)
 *
 * The placeholders are numbered per letter in document order, so the same stored value always gives the same
 * placeholders: the map behind them is not kept anywhere, it is rebuilt from the value the answer is written into
 * (or from the other languages of the object, for a translation; resolve()). restore() puts the original elements
 * back into the document the kernel's input parser made of the answer; check() lists what is missing, repeated,
 * invented or misplaced, and loss() compares the structure of two values (counts per kind), so nothing is written
 * that has fewer custom tags, embeds, tables, ... than the value it replaces.
 */
class expAiRichTextTokens
{
    const OPEN = "\u{27E6}";
    const CLOSE = "\u{27E7}";

    /** A placeholder: ⟦ letter number ⟧ */
    const PATTERN = '/\x{27E6}([A-Z])(\d{1,5})\x{27E7}/u';

    /** the protected elements and their letters when [RichTextSettings] Protected[] is not set */
    public static $defaultProtected = array( 'custom' => 'C', 'embed' => 'E', 'embed-inline' => 'E', 'table' => 'T', 'literal' => 'P', 'anchor' => 'A' );

    /** inline elements the subset expresses */
    protected static $inlineNames = array( 'strong' => 'strong', 'emphasize' => 'em' );

    /** @var expAiConfig */
    protected $config;

    /** @var array element => letter */
    protected $protected;

    /** @var int characters of text the model reads at most (0: no limit) */
    protected $max;

    /** @var array the tokens of the value being read: key => entry */
    protected $tokens = array();

    /** @var int[] letter => last number */
    protected $counters = array();

    /** @var int characters written so far */
    protected $length = 0;

    /** @var bool the rest of the value is behind R tokens */
    protected $cut = false;

    /**
     * @param int|null $max characters the model reads at most; null: [RichTextSettings] MaxLength
     */
    public function __construct( ?expAiConfig $config = null, $max = null )
    {
        $this->config = $config ?: expAiConfig::instance();
        $this->protected = self::protectedElements( $this->config );
        $this->max = $max === null ? self::maxLength( $this->config ) : max( 0, (int) $max );
    }

    /** @return bool [RichTextSettings] Placeholders: rich text goes to the model with placeholders */
    public static function enabled( ?expAiConfig $config = null )
    {
        return ( $config ?: expAiConfig::instance() )->flag( 'RichTextSettings', 'Placeholders', true );
    }

    /** @return string[] the datatypes whose stored value is ezxmltext ([RichTextSettings] Datatypes[]) */
    public static function datatypes( ?expAiConfig $config = null )
    {
        $list = ( $config ?: expAiConfig::instance() )->arr( 'RichTextSettings', 'Datatypes' );
        return $list ?: array( 'ezxmltext' );
    }

    /** @return bool the datatype is written with placeholders */
    public static function handles( $datatype, ?expAiConfig $config = null )
    {
        return self::enabled( $config ) && in_array( (string) $datatype, self::datatypes( $config ), true );
    }

    /**
     * @return int the characters of a rich text field the model reads: [RichTextSettings] MaxLength, else the length of
     *             the edit form ([EditViewSettings] MaxFieldLength)
     */
    public static function maxLength( expAiConfig $config )
    {
        $fallback = class_exists( 'expAiPromptFieldAction' ) ? expAiPromptFieldAction::maxValueLength( $config ) : 20000;
        return $config->int( 'RichTextSettings', 'MaxLength', $fallback, 1000, 200000 );
    }

    /** @return array element => letter: [RichTextSettings] Protected[] (element=letter), else the defaults */
    public static function protectedElements( expAiConfig $config )
    {
        $list = $config->get( 'RichTextSettings', 'Protected', array() );
        $out = array();
        foreach ( is_array( $list ) ? $list : array() as $element => $letter )
        {
            $letter = strtoupper( trim( (string) $letter ) );
            if ( is_string( $element ) && $element !== '' && preg_match( '/^[A-JM-PS-WYZ]$/', $letter ) )
                $out[strtolower( $element )] = $letter;
        }
        return $out ?: self::$defaultProtected;
    }

    /* ------------------------------------------------------------- reading */

    /**
     * The text the model reads: the value in the HTML subset with placeholders.
     * @return string
     */
    public function forModel( $xml )
    {
        $read = $this->tokenize( $xml );
        return $read['text'];
    }

    /**
     * @return array text (the subset with placeholders), tokens (key => entry: letter, kind, nodes, parent, depth,
     *               block; K entries: element, attributes), document (the DOM the nodes belong to; null for no value)
     */
    public function tokenize( $xml )
    {
        $this->tokens = array();
        $this->counters = array();
        $this->length = 0;
        $this->cut = false;
        $document = self::load( $xml );
        if ( !$document )
            return array( 'text' => '', 'tokens' => array(), 'document' => null );
        $text = $this->sectionChildren( $document->documentElement, 0 );
        return array( 'text' => trim( $text ), 'tokens' => $this->tokens, 'document' => $document );
    }

    /** @return DOMDocument|null a stored value */
    public static function load( $xml )
    {
        $xml = trim( (string) $xml );
        if ( $xml === '' )
            return null;
        $document = new DOMDocument( '1.0', 'UTF-8' );
        $previous = libxml_use_internal_errors( true );
        $ok = $document->loadXML( $xml, LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        return $ok && $document->documentElement && $document->documentElement->localName === 'section' ? $document : null;
    }

    /** @return string a new token for nodes, with its entry */
    protected function token( $letter, array $entry )
    {
        $this->counters[$letter] = isset( $this->counters[$letter] ) ? $this->counters[$letter] + 1 : 1;
        $key = $letter . $this->counters[$letter];
        $this->tokens[$key] = $entry + array( 'letter' => $letter, 'key' => $key );
        return self::OPEN . $key . self::CLOSE;
    }

    /** @return string the kind of a protected element for the structure counts (custom:<name>, embed, table, ...) */
    public function kindOf( DOMElement $element )
    {
        $name = $element->localName;
        if ( $name === 'custom' )
            return 'custom:' . ( $element->getAttribute( 'name' ) !== '' ? $element->getAttribute( 'name' ) : '?' );
        if ( $name === 'embed-inline' )
            return 'embed';
        if ( isset( $this->protected[$name] ) || in_array( $name, array( 'embed', 'table', 'literal', 'anchor' ), true ) )
            return $name;
        if ( $name === 'link' )
            return 'link';
        return 'element:' . $name;
    }

    /** @return string the letter of a protected element */
    protected function letterOf( DOMElement $element )
    {
        if ( isset( $this->protected[$element->localName] ) )
            return $this->protected[$element->localName];
        if ( $element->localName === 'link' )
            return 'L';
        if ( in_array( $element->localName, array( 'ul', 'ol' ), true ) )
            return 'B';
        return 'X';
    }

    /** @return bool an element that stands for a block (a paragraph child that is not inline text) */
    protected function isBlock( DOMElement $element )
    {
        if ( in_array( $element->localName, array( 'table', 'literal', 'ul', 'ol', 'section', 'header' ), true ) )
            return true;
        if ( $element->localName === 'custom' )
            foreach ( $element->childNodes as $child )
                if ( $child instanceof DOMElement && in_array( $child->localName, array( 'paragraph', 'section', 'table', 'ul', 'ol' ), true ) )
                    return true;
        return false;
    }

    /** @return string a whole element as a token */
    protected function whole( DOMElement $element, $depth )
    {
        return $this->token( $this->letterOf( $element ), array(
            'kind' => $this->kindOf( $element ), 'nodes' => array( $element ), 'parent' => $element->parentNode ? $element->parentNode->localName : '',
            'depth' => $depth, 'block' => $this->isBlock( $element ) ) );
    }

    /**
     * @return array the attributes of an element as ( namespace, qualified name, value ), without those in $skip and
     *               without namespace declarations
     */
    protected static function attributesOf( DOMElement $element, array $skip = array() )
    {
        $out = array();
        foreach ( $element->attributes as $attribute )
            if ( !in_array( $attribute->nodeName, $skip, true ) )
                $out[] = array( (string) $attribute->namespaceURI, $attribute->nodeName, $attribute->nodeValue );
        return $out;
    }

    /** @return string a K token for the attributes of an element ('' when it has none) */
    protected function keyToken( DOMElement $element, array $skip = array() )
    {
        $attributes = self::attributesOf( $element, $skip );
        if ( !$attributes )
            return '';
        return $this->token( 'K', array( 'kind' => 'attribute', 'element' => $element->localName, 'attributes' => $attributes, 'nodes' => array() ) );
    }

    /** @return string the children of a section: headers, paragraphs, sections; the rest behind R tokens past the limit */
    protected function sectionChildren( DOMElement $section, $depth )
    {
        $out = '';
        $children = array();
        foreach ( $section->childNodes as $child )
            if ( $child instanceof DOMElement )
                $children[] = $child;
        foreach ( $children as $i => $child )
        {
            if ( $this->cut || ( $this->max > 0 && $this->length >= $this->max ) )
            {
                // the rest of this section, unchanged
                $this->cut = true;
                $out .= '<p>' . $this->token( 'R', array( 'kind' => 'rest', 'nodes' => array_slice( $children, $i ), 'parent' => 'section',
                                                          'depth' => $depth, 'block' => true ) ) . '</p>';
                break;
            }
            switch ( $child->localName )
            {
                case 'section':
                    $out .= $this->sectionChildren( $child, $depth + 1 );
                    break;
                case 'header':
                    if ( $depth < 1 || $depth > 6 )
                    {
                        $html = '<p>' . $this->whole( $child, $depth ) . '</p>';
                        break;
                    }
                    $html = '<h' . $depth . '>' . $this->keyToken( $child ) . $this->inline( $child->childNodes, $depth ) . '</h' . $depth . '>';
                    break;
                case 'paragraph':
                    $html = $this->paragraph( $child, $depth );
                    break;
                default:
                    $html = '<p>' . $this->whole( $child, $depth ) . '</p>';
            }
            if ( $child->localName !== 'section' )
            {
                // the text the model reads (a nested section counts its own children)
                $this->length += mb_strlen( strip_tags( $html ), 'UTF-8' );
                $out .= $html;
            }
        }
        return $out;
    }

    /** @return string a paragraph: its inline content as <p>, lists as lists */
    protected function paragraph( DOMElement $paragraph, $depth )
    {
        $out = '';
        $run = $this->keyToken( $paragraph );
        $flush = function () use ( &$run, &$out )
        {
            if ( trim( strip_tags( $run, '<br>' ) ) !== '' || preg_match( '/' . self::OPEN . '/u', $run ) )
                $out .= '<p>' . $run . '</p>';
            $run = '';
        };
        $lines = array();
        foreach ( $paragraph->childNodes as $child )
        {
            if ( $child instanceof DOMElement && in_array( $child->localName, array( 'ul', 'ol' ), true ) && !self::attributesOf( $child ) )
            {
                $flush();
                $out .= $this->listHtml( $child, $depth );
                continue;
            }
            if ( $child instanceof DOMElement && $child->localName === 'line' )
            {
                $lines[] = $child;
                $run .= ( count( $lines ) > 1 ? '<br />' : '' ) . $this->inline( $child->childNodes, $depth );
                continue;
            }
            $run .= $this->inline( array( $child ), $depth );
        }
        $flush();
        return $out;
    }

    /** @return string a list without attributes */
    protected function listHtml( DOMElement $list, $depth )
    {
        $out = '<' . $list->localName . '>';
        foreach ( $list->childNodes as $item )
        {
            if ( !$item instanceof DOMElement )
                continue;
            if ( $item->localName !== 'li' )
            {
                $out .= '<li>' . $this->whole( $item, $depth ) . '</li>';
                continue;
            }
            $paragraphs = array();
            foreach ( $item->childNodes as $child )
                if ( $child instanceof DOMElement )
                    $paragraphs[] = $child;
            $inner = '';
            foreach ( $paragraphs as $child )
            {
                if ( $child->localName === 'paragraph' )
                {
                    $html = $this->paragraph( $child, $depth );
                    // one paragraph in an item: its text without the <p>
                    $inner .= count( $paragraphs ) === 1 && preg_match( '~^<p>(.*)</p>$~s', $html, $m ) && strpos( $m[1], '<p>' ) === false ? $m[1] : $html;
                }
                else
                    $inner .= $this->whole( $child, $depth );
            }
            $out .= '<li>' . $this->keyToken( $item ) . $inner . '</li>';
        }
        return $out . '</' . $list->localName . '>';
    }

    /** @return bool a link the subset keeps: an http(s) or mailto address through url_id */
    protected function linkUrl( DOMElement $link )
    {
        if ( !$link->hasAttribute( 'url_id' ) || $link->hasAttribute( 'object_id' ) || $link->hasAttribute( 'node_id' ) || $link->hasAttribute( 'anchor_name' ) )
            return '';
        $url = class_exists( 'eZURL' ) ? eZURL::fetch( (int) $link->getAttribute( 'url_id' ) ) : null;
        $address = $url ? trim( (string) $url->attribute( 'url' ) ) : '';
        return preg_match( '~^(https?://[^\s"<>]+|mailto:[^\s"<>]+)$~i', $address ) ? $address : '';
    }

    /**
     * Text for the model. A ⟦ or ⟧ of the stored text becomes a Q token that stands for the character itself, so a
     * text that looks like a placeholder is never taken for one and comes back unchanged.
     * @return string
     */
    protected function text( $value )
    {
        $out = '';
        foreach ( preg_split( '/([\x{27E6}\x{27E7}])/u', (string) $value, -1, PREG_SPLIT_DELIM_CAPTURE ) as $i => $piece )
            $out .= $i % 2 ? $this->token( 'Q', array( 'kind' => 'escape', 'text' => $piece, 'nodes' => array(), 'parent' => '', 'depth' => 0, 'block' => false ) )
                           : htmlspecialchars( $piece, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        return $out;
    }

    /** @return string inline content */
    protected function inline( $nodes, $depth )
    {
        $out = '';
        foreach ( $nodes as $node )
        {
            if ( $node instanceof DOMText )
            {
                $out .= $this->text( $node->nodeValue );
                continue;
            }
            if ( !$node instanceof DOMElement )
                continue;
            $name = $node->localName;
            if ( isset( self::$inlineNames[$name] ) && !isset( $this->protected[$name] ) )
            {
                $tag = self::$inlineNames[$name];
                $out .= '<' . $tag . '>' . $this->keyToken( $node ) . $this->inline( $node->childNodes, $depth ) . '</' . $tag . '>';
                continue;
            }
            if ( $name === 'link' && !isset( $this->protected['link'] ) && ( $url = $this->linkUrl( $node ) ) !== '' )
            {
                $out .= '<a href="' . htmlspecialchars( $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '">' . $this->keyToken( $node, array( 'url_id' ) )
                      . $this->inline( $node->childNodes, $depth ) . '</a>';
                continue;
            }
            if ( $name === 'line' )
            {
                $out .= $this->inline( $node->childNodes, $depth ) . '<br />';
                continue;
            }
            $out .= $this->whole( $node, $depth );
        }
        return $out;
    }

    /* ---------------------------------------------------------- structure */

    /**
     * The structure of a value: how many protected elements of each kind it has (custom:<name>, embed, table, literal,
     * anchor, link, element:<name>) and how many elements carry attributes (attribute).
     * @return int[] kind => count
     */
    public function counts( $xml )
    {
        $reader = new self( $this->config, 0 );
        $read = $reader->tokenize( $xml );
        $out = array();
        foreach ( $read['tokens'] as $entry )
            if ( $entry['letter'] !== 'Q' )
                $out[$entry['kind']] = isset( $out[$entry['kind']] ) ? $out[$entry['kind']] + 1 : 1;
        ksort( $out );
        return $out;
    }

    /**
     * The protected elements among inline nodes of a stored value (the inline content of one block, for the diff):
     * strong, emphasize, line and links through url_id are looked into, anything else counts as a whole.
     * @param DOMNode[] $nodes
     * @return int[] kind => count
     */
    public function inlineCounts( $nodes )
    {
        $out = array();
        foreach ( $nodes as $node )
        {
            if ( !$node instanceof DOMElement )
                continue;
            $name = $node->localName;
            $open = ( isset( self::$inlineNames[$name] ) || $name === 'line' ) && !isset( $this->protected[$name] );
            if ( $name === 'link' && !isset( $this->protected['link'] ) && $node->hasAttribute( 'url_id' )
                 && !$node->hasAttribute( 'object_id' ) && !$node->hasAttribute( 'node_id' ) && !$node->hasAttribute( 'anchor_name' ) )
                $open = true;
            if ( $open )
            {
                foreach ( $this->inlineCounts( $node->childNodes ) as $kind => $n )
                    $out[$kind] = ( isset( $out[$kind] ) ? $out[$kind] : 0 ) + $n;
                continue;
            }
            $kind = $this->kindOf( $node );
            $out[$kind] = ( isset( $out[$kind] ) ? $out[$kind] : 0 ) + 1;
        }
        return $out;
    }

    /** @return int[] kind => how many fewer $after has than $before (empty: nothing is lost) */
    public function loss( $before, $after )
    {
        $a = $this->counts( $before );
        $b = $this->counts( $after );
        $out = array();
        foreach ( $a as $kind => $n )
        {
            $left = isset( $b[$kind] ) ? $b[$kind] : 0;
            if ( $left < $n )
                $out[$kind] = $n - $left;
        }
        return $out;
    }

    /** @return string[] the placeholders of a text, each as often as it occurs */
    public static function keys( $text )
    {
        preg_match_all( self::PATTERN, (string) $text, $m );
        return array_map( function ( $l, $n ) { return $l . $n; }, $m[1], $m[2] );
    }

    /**
     * What is wrong with the placeholders of an answer.
     * @param array $tokens the tokens of the value it is written into (tokenize())
     * @return array missing, duplicated, invented (keys); all empty when the answer has each token exactly once
     */
    public static function check( $text, array $tokens )
    {
        $found = array_count_values( self::keys( $text ) );
        $out = array( 'missing' => array(), 'duplicated' => array(), 'invented' => array(), 'misplaced' => array() );
        foreach ( $found as $key => $n )
        {
            if ( !isset( $tokens[$key] ) )
                $out['invented'][] = $key;
            elseif ( $n > 1 )
                $out['duplicated'][] = $key;
        }
        // a Q token is a character of the text: the answer may leave it out like any other word
        foreach ( $tokens as $key => $entry )
            if ( !isset( $found[$key] ) && $entry['letter'] !== 'Q' )
                $out['missing'][] = $key;
        return $out;
    }

    /** @return bool no problem in a check() result */
    public static function clean( array $problems )
    {
        foreach ( $problems as $list )
            if ( $list )
                return false;
        return true;
    }

    /**
     * The value whose placeholders an answer uses. The first source (the value the answer is written into) whenever it
     * has placeholders of its own: its problems are reported, never papered over with another language's elements.
     * Only a first source without placeholders (a new or empty value, a translation) lets a later source whose tokens
     * are exactly the answer's step in; else the first later source with tokens (the problems are reported against
     * it), else the first source.
     * @param string[] $sources stored values: the value written into first, then the other languages
     * @return array the tokenize() result of the source, with source (its value) and problems (check())
     */
    public function resolve( $text, array $sources )
    {
        $sources = array_values( array_unique( array_map( 'strval', $sources ) ) );
        $fallback = null;
        foreach ( $sources ?: array( '' ) as $i => $source )
        {
            $read = $this->tokenize( $source ) + array( 'source' => $source );
            $read['problems'] = self::check( $text, $read['tokens'] );
            if ( self::clean( $read['problems'] ) || ( $i === 0 && self::structural( $read['tokens'] ) ) )
                return $read;
            if ( $fallback === null || ( !self::structural( $fallback['tokens'] ) && self::structural( $read['tokens'] ) ) )
                $fallback = $read;
        }
        return $fallback;
    }

    /** @return bool tokens that stand for structure (not only escaped characters of the text) */
    protected static function structural( array $tokens )
    {
        foreach ( $tokens as $entry )
            if ( $entry['letter'] !== 'Q' )
                return true;
        return false;
    }

    /** @return bool a stored value without content: '', not ezxmltext, or a section with no element and no text */
    public static function isEmpty( $xml )
    {
        $document = self::load( $xml );
        if ( !$document )
            return true;
        foreach ( $document->documentElement->childNodes as $child )
            if ( $child instanceof DOMElement || trim( $child->nodeValue ) !== '' )
                return false;
        return true;
    }

    /**
     * Puts the original elements back in place of the placeholders of a document the input parser made of an answer.
     * Unknown and repeated placeholders are removed. An R token whose paragraph is missing is appended at the end
     * (the rest of a field is never dropped) with $keepRest.
     * @param array $read the tokenize() result of the value the answer is written into
     * @return array the problems (check() keys, plus misplaced: a placeholder that cannot stand where the answer put it)
     */
    public function restore( DOMDocument $document, array $read, $keepRest = true )
    {
        $problems = array( 'missing' => array(), 'duplicated' => array(), 'invented' => array(), 'misplaced' => array() );
        $tokens = $read['tokens'];
        $used = array();
        $deferred = array();
        $xpath = new DOMXPath( $document );
        $texts = array();
        foreach ( $xpath->query( '//text()' ) as $text )
            if ( strpos( $text->nodeValue, self::OPEN ) !== false )
                $texts[] = $text;
        foreach ( $texts as $text )
        {
            $parent = $text->parentNode;
            $pieces = preg_split( self::PATTERN, $text->nodeValue, -1, PREG_SPLIT_DELIM_CAPTURE );
            $fragment = $document->createDocumentFragment();
            for ( $i = 0; $i < count( $pieces ); $i++ )
            {
                if ( $i % 3 === 0 )
                {
                    if ( $pieces[$i] !== '' )
                        $fragment->appendChild( $document->createTextNode( $pieces[$i] ) );
                    continue;
                }
                $key = $pieces[$i] . $pieces[$i + 1];
                $i++;
                if ( !isset( $tokens[$key] ) )
                {
                    $problems['invented'][] = $key;
                    continue;
                }
                if ( isset( $used[$key] ) )
                {
                    $problems['duplicated'][] = $key;
                    continue;
                }
                $used[$key] = true;
                $entry = $tokens[$key];
                if ( $entry['letter'] === 'Q' )
                {
                    $fragment->appendChild( $document->createTextNode( $entry['text'] ) );
                    continue;
                }
                if ( $entry['letter'] === 'K' )
                {
                    if ( !$this->setAttributes( $parent, $entry ) )
                        $problems['misplaced'][] = $key;
                    continue;
                }
                if ( $entry['parent'] === 'section' )
                {
                    // stands for blocks of a section: the paragraph that holds only the placeholder is replaced later
                    $deferred[] = array( $text, $key );
                    $fragment->appendChild( $document->createTextNode( self::OPEN . $key . self::CLOSE ) );
                    continue;
                }
                if ( !empty( $entry['block'] ) && self::insideInline( $parent ) )
                    $problems['misplaced'][] = $key;
                foreach ( $entry['nodes'] as $node )
                    $fragment->appendChild( $document->importNode( $node, true ) );
            }
            $parent->replaceChild( $fragment, $text );
            // a paragraph left empty by a removed placeholder goes
            if ( $parent->localName === 'paragraph' && trim( $parent->textContent ) === '' && $parent->parentNode )
            {
                $empty = true;
                foreach ( $parent->childNodes as $child )
                    $empty = $empty && !$child instanceof DOMElement;
                if ( $empty )
                    $parent->parentNode->removeChild( $parent );
            }
        }
        foreach ( $deferred as $item )
            if ( !$this->placeSection( $document, $tokens[$item[1]], $item[1] ) )
            {
                $problems['misplaced'][] = $item[1];
                unset( $used[$item[1]] );
            }
        foreach ( $tokens as $key => $entry )
        {
            if ( isset( $used[$key] ) || $entry['letter'] === 'Q' )
                continue;
            if ( $entry['letter'] === 'R' && $keepRest )
                $this->appendRest( $document, $entry );
            if ( !in_array( $key, $problems['misplaced'], true ) )
                $problems['missing'][] = $key;
        }
        foreach ( $problems as $name => $list )
            $problems[$name] = array_values( array_unique( $list ) );
        return $problems;
    }

    /** @return bool the node lies in an inline element (strong, emphasize, link, header, line, custom) */
    protected static function insideInline( DOMNode $node )
    {
        for ( ; $node instanceof DOMElement; $node = $node->parentNode )
        {
            if ( in_array( $node->localName, array( 'strong', 'emphasize', 'link', 'header', 'line', 'custom' ), true ) )
                return true;
            if ( in_array( $node->localName, array( 'paragraph', 'section', 'li' ), true ) )
                return false;
        }
        return false;
    }

    /** @return bool the attributes of a K entry set on the nearest element of its name around $node */
    protected function setAttributes( DOMNode $node, array $entry )
    {
        for ( ; $node instanceof DOMElement; $node = $node->parentNode )
        {
            if ( $node->localName !== $entry['element'] )
                continue;
            foreach ( $entry['attributes'] as $attribute )
            {
                if ( $attribute[0] !== '' )
                    $node->setAttributeNS( $attribute[0], $attribute[1], $attribute[2] );
                else
                    $node->setAttribute( $attribute[1], $attribute[2] );
            }
            return true;
        }
        return false;
    }

    /** @return int the sections around a node (the root section counts 0) */
    protected static function depthOf( DOMNode $section )
    {
        $depth = 0;
        for ( $node = $section->parentNode; $node instanceof DOMElement; $node = $node->parentNode )
            if ( $node->localName === 'section' )
                $depth++;
        return $depth;
    }

    /**
     * Replaces the paragraph that holds only the placeholder of section-level blocks by the blocks, at their depth: a
     * deeper paragraph places them after the section of their depth that holds it (headings keep their levels).
     * @return bool false when the placeholder does not stand alone in a paragraph of a section
     */
    protected function placeSection( DOMDocument $document, array $entry, $key )
    {
        $token = self::OPEN . $key . self::CLOSE;
        $xpath = new DOMXPath( $document );
        $found = null;
        foreach ( $xpath->query( '//text()' ) as $text )
            if ( strpos( $text->nodeValue, $token ) !== false )
            {
                $found = $text;
                break;
            }
        if ( !$found )
            return false;
        $paragraph = $found->parentNode;
        $alone = $paragraph instanceof DOMElement && $paragraph->localName === 'paragraph' && trim( $paragraph->textContent ) === $token
                 && $paragraph->parentNode instanceof DOMElement && $paragraph->parentNode->localName === 'section';
        if ( $alone )
            foreach ( $paragraph->childNodes as $child )
                if ( $child instanceof DOMElement )
                    $alone = false;
        $section = $alone ? $paragraph->parentNode : null;
        $depth = $section ? self::depthOf( $section ) : 0;
        if ( !$alone || $depth < (int) $entry['depth'] )
        {
            // the placeholder text never stays in the value
            $found->nodeValue = str_replace( $token, '', $found->nodeValue );
            return false;
        }
        $anchor = $paragraph;
        while ( $depth > (int) $entry['depth'] )
        {
            $anchor = $section;
            $section = $section->parentNode;
            $depth--;
        }
        $next = $anchor === $paragraph ? $paragraph : $anchor->nextSibling;
        foreach ( $entry['nodes'] as $node )
        {
            $copy = $document->importNode( $node, true );
            if ( $next )
                $section->insertBefore( $copy, $next );
            else
                $section->appendChild( $copy );
        }
        $paragraph->parentNode->removeChild( $paragraph );
        return true;
    }

    /** Appends the rest of a field (an R entry) at the end, in the section of its depth */
    protected function appendRest( DOMDocument $document, array $entry )
    {
        $section = $document->documentElement;
        for ( $depth = 0; $depth < (int) $entry['depth']; $depth++ )
        {
            $last = null;
            foreach ( $section->childNodes as $child )
                if ( $child instanceof DOMElement && $child->localName === 'section' )
                    $last = $child;
            if ( !$last )
                break;
            $section = $last;
        }
        foreach ( $entry['nodes'] as $node )
            $section->appendChild( $document->importNode( $node, true ) );
    }

    /* ------------------------------------------------------------- texts */

    /**
     * The structure a value would lose, for the editor: "1 custom tag math_latex, 2 embedded objects".
     * @param int[] $loss kind => count (loss())
     * @return string
     */
    public static function describe( array $loss, $translate = true )
    {
        $parts = array();
        foreach ( $loss as $kind => $n )
        {
            $name = '';
            if ( strpos( $kind, ':' ) !== false )
                list( $kind, $name ) = explode( ':', $kind, 2 );
            $texts = array(
                'custom' => array( '%count custom tag %name', '%count custom tags %name' ),
                'embed' => array( '%count embedded object', '%count embedded objects' ),
                'table' => array( '%count table', '%count tables' ),
                'literal' => array( '%count literal block', '%count literal blocks' ),
                'anchor' => array( '%count anchor', '%count anchors' ),
                'link' => array( '%count link the answer cannot keep', '%count links the answer cannot keep' ),
                'attribute' => array( '%count formatting attribute', '%count formatting attributes' ),
                'rest' => array( 'the rest of the field', 'the rest of the field' ),
                'element' => array( '%count element %name', '%count elements %name' ),
            );
            $pair = isset( $texts[$kind] ) ? $texts[$kind] : $texts['element'];
            $text = $pair[$n === 1 ? 0 : 1];
            $arguments = array( '%count' => (int) $n, '%name' => $name !== '' ? $name : $kind );
            $parts[] = $translate && class_exists( 'ezpI18n' ) ? ezpI18n::tr( 'extension/expai/richtext', $text, null, $arguments ) : strtr( $text, $arguments );
        }
        return implode( ', ', $parts );
    }

    /**
     * Why an answer was not written, for the model (English) or the editor (translated).
     * @param array $problems check() / restore() problems
     * @param int[] $loss structure the answer would lose (loss())
     * @return string '' when nothing is wrong
     */
    public static function reason( array $problems, array $loss = array(), $translate = true )
    {
        $tr = function ( $text, array $arguments = array() ) use ( $translate )
        {
            return $translate && class_exists( 'ezpI18n' ) ? ezpI18n::tr( 'extension/expai/richtext', $text, null, $arguments ) : strtr( $text, $arguments );
        };
        $list = function ( array $keys ) { return implode( ' ', array_map( function ( $k ) { return self::OPEN . $k . self::CLOSE; }, $keys ) ); };
        $parts = array();
        if ( !empty( $problems['missing'] ) )
            $parts[] = $tr( 'Placeholders missing: %list.', array( '%list' => $list( $problems['missing'] ) ) );
        if ( !empty( $problems['duplicated'] ) )
            $parts[] = $tr( 'Placeholders repeated: %list.', array( '%list' => $list( $problems['duplicated'] ) ) );
        if ( !empty( $problems['invented'] ) )
            $parts[] = $tr( 'Placeholders that are not in the text: %list.', array( '%list' => $list( $problems['invented'] ) ) );
        if ( !empty( $problems['misplaced'] ) )
            $parts[] = $tr( 'Placeholders in a place where their element cannot stand: %list.', array( '%list' => $list( $problems['misplaced'] ) ) );
        if ( $loss )
            $parts[] = $tr( 'The text would lose: %items.', array( '%items' => self::describe( $loss, $translate ) ) );
        if ( !$parts )
            return '';
        return $tr( 'The rich text keeps its structure only when every placeholder comes back exactly once and unchanged.' ) . ' ' . implode( ' ', $parts );
    }
}
