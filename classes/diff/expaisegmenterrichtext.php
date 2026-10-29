<?php
/**
 * File containing the expAiSegmenterRichText class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Rich text (ezxmltext storage XML, or HTML): structure-aware blocks (doc/diff.md).
 *
 * - Blocks: paragraph, header, list item, table cell (and the HTML p, h1-h6, li, td, th, dt, dd, caption): the
 *   innermost block element with inline content of its own. A paragraph that only wraps a list or a table is not a
 *   block; its items and cells are. Blocks inside a block-level custom tag are of type "custom".
 * - Inline markup (link, strong, emphasize, line, custom inline tags, a, em, ...) stays in the block. In the coded
 *   text it becomes numbered placeholders: <1>our shop</1>. Atoms (embed, embed-inline, img, anchor, br) become
 *   <2/> and are never translated or dropped; their readable text is [[embed object 12]].
 * - A block's hash covers its type, its normalised text and the shape of its markup (element names and attributes,
 *   e.g. a link's url_id), so a changed link with the same text is a change of markup only (same text_hash).
 * - merge() works on the DOM of the base value: a modified block gets the inline content of the proposed block, an
 *   inserted block is placed after the block before it (lists and tables are created where the neighbour is not in
 *   one), a deleted block is removed with the containers it leaves empty. Table cells cannot be inserted or removed
 *   on their own (mergeable false).
 * - Blocks of stored rich text carry "protected": the custom tags, embeds, anchors, ... of their inline content
 *   (expAiRichTextTokens::inlineCounts()); a hunk whose proposal has fewer of them "loses" them (expAiDiff).
 */
class expAiSegmenterRichText implements expAiSegmenter
{
    protected static $blockNames = array( 'paragraph' => 1, 'header' => 1, 'p' => 1, 'h1' => 1, 'h2' => 1, 'h3' => 1, 'h4' => 1, 'h5' => 1, 'h6' => 1,
                                          'li' => 1, 'td' => 1, 'th' => 1, 'dt' => 1, 'dd' => 1, 'caption' => 1 );
    protected static $containerNames = array( 'section' => 1, 'ul' => 1, 'ol' => 1, 'dl' => 1, 'table' => 1, 'tbody' => 1, 'thead' => 1, 'tfoot' => 1,
                                              'tr' => 1, 'div' => 1, 'blockquote' => 1 );
    protected static $atomNames = array( 'embed' => 1, 'embed-inline' => 1, 'img' => 1, 'anchor' => 1, 'br' => 1, 'object' => 1, 'hr' => 1 );

    /** @var expAiRichTextTokens|null what counts as a protected element (read once per instance) */
    protected $tokens = null;

    /** parsed documents by hash of the value (one call of compose() reads the same value many times) */
    protected $documents = array();

    public function segment( $value, array $options = array() )
    {
        $language = isset( $options['language'] ) ? (string) $options['language'] : 'eng';
        $document = $this->parse( $value );
        if ( !$document )
            return array();
        $blocks = array();
        foreach ( $this->blockNodes( $document ) as $node )
            $blocks[] = $this->describe( $node, count( $blocks ), $language );
        return $blocks;
    }

    /**
     * The value as a DOM: ezxmltext (starts with <?xml or <section) or HTML (wrapped in <div id="exp-ai-root">).
     * @return DOMDocument|null
     */
    public function parse( $value )
    {
        $value = trim( (string) $value );
        if ( $value === '' )
            return null;
        $document = new DOMDocument( '1.0', 'UTF-8' );
        $document->preserveWhiteSpace = true;
        $previous = libxml_use_internal_errors( true );
        if ( preg_match( '/^(<\?xml|<section\b)/', $value ) )
            $ok = $document->loadXML( $value, LIBXML_NONET );
        else
            $ok = $document->loadHTML( '<?xml encoding="UTF-8"?><div id="exp-ai-root">' . $value . '</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        return $ok && $document->documentElement ? $document : null;
    }

    /** @return bool whether a document is HTML (not ezxmltext) */
    protected function isHtml( DOMDocument $document )
    {
        return $document->documentElement->localName === 'html';
    }

    /** @return DOMElement the element whose children are the top level (the section, or the HTML wrapper) */
    protected function root( DOMDocument $document )
    {
        if ( $this->isHtml( $document ) )
        {
            foreach ( $document->getElementsByTagName( 'div' ) as $div )
                if ( $div->getAttribute( 'id' ) === 'exp-ai-root' )
                    return $div;
        }
        return $document->documentElement;
    }

    /** @return string the value of a document */
    public function serialize( DOMDocument $document )
    {
        if ( $this->isHtml( $document ) )
        {
            $html = '';
            foreach ( $this->root( $document )->childNodes as $child )
                $html .= $document->saveHTML( $child );
            return $html;
        }
        if ( class_exists( 'eZXMLTextType' ) )
            return eZXMLTextType::domString( $document );
        return $document->saveXML();
    }

    /** @return bool */
    protected function isSectionLike( DOMNode $node )
    {
        return $node instanceof DOMElement && ( $node->localName === 'section' || $node->getAttribute( 'id' ) === 'exp-ai-root'
                                               || $node->localName === 'body' || $node->localName === 'html' );
    }

    /** @return bool a block-level custom tag (one with block children) */
    protected function isBlockCustom( DOMElement $element )
    {
        if ( $element->localName !== 'custom' )
            return false;
        foreach ( $element->childNodes as $child )
            if ( $child instanceof DOMElement && ( isset( self::$blockNames[$child->localName] ) || isset( self::$containerNames[$child->localName] ) ) )
                return true;
        return false;
    }

    /** @return bool an element that holds blocks rather than inline content */
    protected function isContainer( DOMNode $node )
    {
        if ( !$node instanceof DOMElement )
            return false;
        return isset( self::$containerNames[$node->localName] ) || isset( self::$blockNames[$node->localName] ) || $this->isBlockCustom( $node );
    }

    /** @return DOMNode[] the inline children of a block (its own content, without nested lists, tables, blocks) */
    public function ownInline( DOMElement $block )
    {
        $own = array();
        foreach ( $block->childNodes as $child )
            if ( !$this->isContainer( $child ) )
                $own[] = $child;
        return $own;
    }

    /** @return bool whether a block element has inline content of its own */
    protected function hasOwnContent( DOMElement $element )
    {
        foreach ( $this->ownInline( $element ) as $child )
        {
            if ( $child instanceof DOMText && trim( $child->nodeValue ) !== '' )
                return true;
            if ( $child instanceof DOMElement )
                return true;
        }
        return false;
    }

    /**
     * The block elements in document order (the same order as segment()).
     * @return DOMElement[]
     */
    public function blockNodes( DOMDocument $document )
    {
        $out = array();
        $this->collect( $this->root( $document ), $out );
        return $out;
    }

    protected function collect( DOMNode $node, array &$out )
    {
        foreach ( $node->childNodes as $child )
        {
            if ( !$child instanceof DOMElement )
                continue;
            if ( isset( self::$blockNames[$child->localName] ) && $this->hasOwnContent( $child ) )
                $out[] = $child;
            if ( $this->isContainer( $child ) )
                $this->collect( $child, $out );
        }
    }

    /** @return string the block type of a block element */
    protected function typeOf( DOMElement $element )
    {
        if ( $element->localName === 'header' || preg_match( '/^h[1-6]$/', $element->localName ) )
            return 'heading';
        for ( $node = $element; $node && !$this->isSectionLike( $node ); $node = $node->parentNode )
        {
            if ( !$node instanceof DOMElement )
                continue;
            if ( in_array( $node->localName, array( 'li', 'dt', 'dd' ), true ) )
                return 'list_item';
            if ( in_array( $node->localName, array( 'td', 'th', 'caption' ), true ) )
                return 'table_cell';
            if ( $node !== $element && $this->isBlockCustom( $node ) )
                return 'custom';
        }
        return 'paragraph';
    }

    /** @return DOMElement the element inserted or removed for a block: a list item for the paragraph of an item */
    public function unitOf( DOMElement $block )
    {
        $parent = $block->parentNode;
        if ( $parent instanceof DOMElement && in_array( $parent->localName, array( 'li', 'dt', 'dd' ), true ) )
        {
            $blocks = 0;
            foreach ( $parent->childNodes as $child )
                if ( $child instanceof DOMElement && isset( self::$blockNames[$child->localName] ) )
                    ++$blocks;
            if ( $blocks === 1 )
                return $parent;
        }
        return $block;
    }

    /** @return bool whether a block can be inserted or removed on its own */
    protected function isMergeable( DOMElement $block )
    {
        return $this->typeOf( $block ) !== 'table_cell';
    }

    /** @return string the readable text of an atom */
    protected function atomText( DOMElement $element )
    {
        $name = $element->localName;
        if ( $name === 'br' )
            return "\n";
        foreach ( array( 'object_id' => 'object', 'node_id' => 'node', 'src' => '', 'href' => '', 'name' => '' ) as $attribute => $label )
            if ( $element->hasAttribute( $attribute ) )
                return '[[' . $name . ( $label !== '' ? ' ' . $label : '' ) . ' ' . $element->getAttribute( $attribute ) . ']]';
        return '[[' . $name . ']]';
    }

    /** @return bool */
    protected function isAtom( DOMNode $node )
    {
        return $node instanceof DOMElement && isset( self::$atomNames[$node->localName] );
    }

    /**
     * The coded text of a block (placeholders) and the elements behind the placeholders.
     * @return array( string text, DOMElement[] map, string readable text, string markup shape, string[] atoms )
     */
    public function encode( DOMElement $block )
    {
        $map = array();
        $atoms = array();
        $shape = '';
        $readable = '';
        $coded = $this->encodeNodes( $this->ownInline( $block ), $map, $atoms, $shape, $readable );
        return array( trim( $coded ), $map, $readable, $shape, $atoms );
    }

    protected function encodeNodes( array $nodes, array &$map, array &$atoms, &$shape, &$readable )
    {
        $out = '';
        foreach ( $nodes as $node )
        {
            if ( $node instanceof DOMText )
            {
                $out .= htmlspecialchars( $node->nodeValue, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
                $readable .= $node->nodeValue;
                continue;
            }
            if ( !$node instanceof DOMElement )
                continue;
            $id = count( $map ) + 1;
            $map[$id] = $node;
            $attributes = array();
            foreach ( $node->attributes as $attribute )
                $attributes[] = $attribute->nodeName . '=' . $attribute->nodeValue;
            sort( $attributes );
            $shape .= '<' . $node->localName . ( $attributes ? ' ' . implode( ' ', $attributes ) : '' ) . '>';
            if ( $this->isAtom( $node ) )
            {
                $text = $this->atomText( $node );
                if ( $node->localName !== 'br' )
                    $atoms[] = trim( $text, '[]' );
                $readable .= $node->localName === 'br' ? ' ' : ' ' . $text . ' ';
                $out .= '<' . $id . '/>';
                continue;
            }
            $children = array();
            foreach ( $node->childNodes as $child )
                $children[] = $child;
            $out .= '<' . $id . '>' . $this->encodeNodes( $children, $map, $atoms, $shape, $readable ) . '</' . $id . '>';
            if ( $node->localName === 'line' )
                $readable .= ' ';
            $shape .= '</' . $node->localName . '>';
        }
        return $out;
    }

    /**
     * Nodes of a coded text, built in $document with the elements of $map.
     * @return DOMNode[]
     * @throws expAiException when the placeholders are wrong (an unknown or repeated number, wrong nesting, a missing atom)
     */
    public function decode( $text, array $map, DOMDocument $document )
    {
        $tokens = preg_split( '~(</?\d+/?>)~', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
        $rootFragment = $document->createDocumentFragment();
        $stack = array( array( 0, $rootFragment ) );
        $used = array();
        foreach ( $tokens as $token )
        {
            if ( preg_match( '~^<(/?)(\d+)(/?)>$~', $token, $m ) )
            {
                $id = (int) $m[2];
                if ( !isset( $map[$id] ) )
                    throw new expAiException( 'The translation does not fit the markup of the text.', 'unknown placeholder ' . $id );
                if ( $m[1] === '/' )
                {
                    $top = array_pop( $stack );
                    if ( !$stack || $top[0] !== $id )
                        throw new expAiException( 'The translation does not fit the markup of the text.', 'placeholder ' . $id . ' closed out of order' );
                    continue;
                }
                if ( isset( $used[$id] ) )
                    throw new expAiException( 'The translation does not fit the markup of the text.', 'placeholder ' . $id . ' used twice' );
                $used[$id] = true;
                $parent = $stack[count( $stack ) - 1][1];
                if ( $m[3] === '/' || $this->isAtom( $map[$id] ) )
                {
                    $parent->appendChild( $document->importNode( $map[$id], true ) );
                    continue;
                }
                $element = $document->importNode( $map[$id], true );
                while ( $element->firstChild )
                    $element->removeChild( $element->firstChild );
                $parent->appendChild( $element );
                $stack[] = array( $id, $element );
                continue;
            }
            $value = html_entity_decode( $token, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $stack[count( $stack ) - 1][1]->appendChild( $document->createTextNode( $value ) );
        }
        if ( count( $stack ) !== 1 )
            throw new expAiException( 'The translation does not fit the markup of the text.', 'placeholder ' . $stack[count( $stack ) - 1][0] . ' not closed' );
        foreach ( $map as $id => $element )
            if ( $this->isAtom( $element ) && $element->localName !== 'br' && !isset( $used[$id] ) )
                throw new expAiException( 'The translation does not fit the markup of the text.', 'the embedded element ' . $id . ' is missing' );
        $nodes = array();
        foreach ( $rootFragment->childNodes as $child )
            $nodes[] = $child;
        return $nodes;
    }

    /** Replaces the inline content of a block (nested lists and tables stay where they are). */
    public function replaceInline( DOMElement $block, array $nodes )
    {
        $own = $this->ownInline( $block );
        $before = null;
        foreach ( $block->childNodes as $child )
            if ( $this->isContainer( $child ) )
            {
                $before = $child;
                break;
            }
        foreach ( $own as $child )
            $block->removeChild( $child );
        foreach ( $nodes as $node )
        {
            if ( $node->ownerDocument !== $block->ownerDocument )
                $node = $block->ownerDocument->importNode( $node, true );
            if ( $before )
                $block->insertBefore( $node, $before );
            else
                $block->appendChild( $node );
        }
    }

    /** @return array one block */
    protected function describe( DOMElement $node, $index, $language )
    {
        list( $coded, , $readable, $shape, $atoms ) = $this->encode( $node );
        $type = $this->typeOf( $node );
        $level = $node->localName === 'header' ? (string) $node->getAttribute( 'level' ) : ( preg_match( '/^h([1-6])$/', $node->localName, $m ) ? $m[1] : '' );
        $attributes = array();
        foreach ( $node->attributes as $attribute )
            $attributes[] = $attribute->nodeName . '=' . $attribute->nodeValue;
        sort( $attributes );
        $block = expAiSegmenterBase::block( $index, $type, expAiSentences::normalize( $readable ), $coded,
                                            $node->localName . $level . '[' . implode( ' ', $attributes ) . ']' . $shape, $language, $atoms,
                                            $this->isMergeable( $node ) );
        // the elements of stored rich text a proposal must not drop (custom tags, embeds, ...: expAiDiff "loses")
        if ( !$this->isHtml( $node->ownerDocument ) && class_exists( 'expAiRichTextTokens' ) )
        {
            $this->tokens = $this->tokens ?: new expAiRichTextTokens();
            $block['protected'] = $this->tokens->inlineCounts( $this->ownInline( $node ) );
        }
        return $block;
    }

    public function merge( $baseValue, $propValue, array $ops, array $options = array() )
    {
        $base = $this->parse( $baseValue );
        $prop = $this->parse( $propValue );
        if ( !$base )
            return (string) $propValue;
        $baseNodes = $this->blockNodes( $base );
        $propNodes = $prop ? $this->blockNodes( $prop ) : array();
        $anchor = null;
        $removed = array();
        foreach ( $ops as $n => $op )
        {
            $accept = !empty( $op['accept'] );
            $b = isset( $op['base'] ) && $op['base'] !== null ? $baseNodes[$op['base']] : null;
            $p = isset( $op['prop'] ) && $op['prop'] !== null ? $propNodes[$op['prop']] : null;
            switch ( $op['op'] )
            {
                case 'equal':
                    $anchor = $this->unitOf( $b );
                    break;
                case 'modify':
                    if ( $accept )
                        $b = $this->replaceBlock( $b, $p );
                    $anchor = $this->unitOf( $b );
                    break;
                case 'delete':
                    if ( !$accept )
                    {
                        $anchor = $this->unitOf( $b );
                        break;
                    }
                    if ( !$this->isMergeable( $b ) )
                        throw new expAiException( 'This change cannot be taken on its own.', 'a table cell cannot be removed alone' );
                    $removed[] = $this->unitOf( $b );
                    break;
                case 'insert':
                    if ( !$accept )
                        break;
                    if ( !$this->isMergeable( $p ) )
                        throw new expAiException( 'This change cannot be taken on its own.', 'a table cell cannot be inserted alone' );
                    $unit = $this->unitOf( $p );
                    if ( $anchor )
                        $anchor = $this->place( $unit, $anchor, 'after' );
                    else
                    {
                        $next = null;
                        for ( $k = $n + 1; $k < count( $ops ); ++$k )
                            if ( isset( $ops[$k]['base'] ) && $ops[$k]['base'] !== null )
                            {
                                $next = $this->unitOf( $baseNodes[$ops[$k]['base']] );
                                break;
                            }
                        $anchor = $next ? $this->place( $unit, $next, 'before' ) : $this->place( $unit, null, 'append', $this->root( $base ) );
                    }
                    break;
            }
        }
        foreach ( $removed as $unit )
            $this->remove( $unit );
        return $this->serialize( $base );
    }

    /** The proposed block's content and attributes in the base block (the element itself when the kind differs). */
    protected function replaceBlock( DOMElement $b, DOMElement $p )
    {
        if ( $b->localName !== $p->localName )
        {
            $new = $b->ownerDocument->importNode( $p, true );
            $b->parentNode->replaceChild( $new, $b );
            return $new;
        }
        while ( $b->attributes->length )
            $b->removeAttributeNode( $b->attributes->item( 0 ) );
        foreach ( $p->attributes as $attribute )
        {
            if ( $attribute->namespaceURI )
                $b->setAttributeNS( $attribute->namespaceURI, $attribute->nodeName, $attribute->nodeValue );
            else
                $b->setAttribute( $attribute->nodeName, $attribute->nodeValue );
        }
        $this->replaceInline( $b, $this->ownInline( $p ) );
        return $b;
    }

    /** @return DOMElement the top-level ancestor of a node (a child of a section) */
    protected function topOf( DOMNode $node )
    {
        while ( $node->parentNode && !$this->isSectionLike( $node->parentNode ) )
            $node = $node->parentNode;
        return $node;
    }

    /**
     * Places a unit of the proposed document next to a node of the base document: as a sibling where both have the
     * same kind of parent (an item into the same list), else its top-level ancestor, pruned to the path down to it.
     * @return DOMNode the node placed (the next anchor)
     */
    protected function place( DOMElement $unit, ?DOMNode $anchor, $mode, ?DOMElement $root = null )
    {
        if ( $mode === 'append' )
        {
            $node = $root->ownerDocument->importNode( $this->pruned( $unit ), true );
            $root->appendChild( $node );
            return $node;
        }
        $document = $anchor->ownerDocument;
        if ( $anchor->parentNode && $unit->parentNode && $anchor->parentNode->localName === $unit->parentNode->localName )
        {
            $node = $document->importNode( $unit, true );
            $target = $anchor;
        }
        else
        {
            $node = $document->importNode( $this->pruned( $unit ), true );
            $target = $this->topOf( $anchor );
        }
        if ( $mode === 'before' )
            $target->parentNode->insertBefore( $node, $target );
        elseif ( $target->nextSibling )
            $target->parentNode->insertBefore( $node, $target->nextSibling );
        else
            $target->parentNode->appendChild( $node );
        return $node;
    }

    /** @return DOMElement a copy of the top-level ancestor of a unit with only the path down to the unit */
    protected function pruned( DOMElement $unit )
    {
        $path = array();
        $node = $unit;
        while ( $node->parentNode && !$this->isSectionLike( $node->parentNode ) )
        {
            $position = 0;
            for ( $sibling = $node->previousSibling; $sibling; $sibling = $sibling->previousSibling )
                ++$position;
            array_unshift( $path, $position );
            $node = $node->parentNode;
        }
        $copy = $node->cloneNode( true );
        $cursor = $copy;
        foreach ( $path as $position )
        {
            $keep = $cursor->childNodes->item( $position );
            foreach ( iterator_to_array( $cursor->childNodes ) as $child )
                if ( $child !== $keep )
                    $cursor->removeChild( $child );
            $cursor = $keep;
        }
        return $copy;
    }

    /** Removes a unit and the containers it leaves empty. */
    protected function remove( DOMNode $unit )
    {
        $parent = $unit->parentNode;
        if ( !$parent )
            return;
        $parent->removeChild( $unit );
        while ( $parent && !$this->isSectionLike( $parent ) && $parent->parentNode )
        {
            foreach ( $parent->childNodes as $child )
                if ( $child instanceof DOMElement || ( $child instanceof DOMText && trim( $child->nodeValue ) !== '' ) )
                    return;
            $up = $parent->parentNode;
            $up->removeChild( $parent );
            $parent = $up;
        }
    }

    public function compose( $value, array $texts, array $options = array() )
    {
        $document = $this->parse( $value );
        if ( !$document )
            return (string) $value;
        $nodes = $this->blockNodes( $document );
        foreach ( $texts as $index => $text )
        {
            if ( !isset( $nodes[$index] ) )
                continue;
            $coded = is_array( $text ) ? (string) $text['text'] : (string) $text;
            $from = is_array( $text ) && isset( $text['from'] ) ? $text['from'] : array( null, $index );
            $sourceNode = $from[0] === null ? $nodes[$index] : $this->nodeOf( (string) $from[0], (int) $from[1] );
            if ( !$sourceNode )
                throw new expAiException( 'The translation does not fit the markup of the text.', 'no block ' . (int) $from[1] . ' to take the markup from' );
            list( , $map ) = $this->encode( $sourceNode );
            $this->replaceInline( $nodes[$index], $this->decode( $coded, $map, $document ) );
        }
        return $this->serialize( $document );
    }

    /** @return DOMElement|null block $index of a value (documents parsed once per instance) */
    protected function nodeOf( $value, $index )
    {
        $key = md5( $value );
        if ( !isset( $this->documents[$key] ) )
        {
            $document = $this->parse( $value );
            $this->documents[$key] = $document ? $this->blockNodes( $document ) : array();
        }
        return isset( $this->documents[$key][$index] ) ? $this->documents[$key][$index] : null;
    }
}
