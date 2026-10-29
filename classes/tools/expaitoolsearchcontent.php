<?php
/**
 * File containing the expAiToolSearchContent class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * search_content: full-text search over the content, through the site's search engine (eZSearch: eZ Find's Solr
 * when ezfind is active, else the kernel's own search). The search runs as the current user, so it finds only what
 * the user may read; each hit is checked once more (canRead) and against the data policy of the provider the result
 * goes to: a hit the policy keeps away is left out and counted (withheld_by_data_policy), like the other read tools.
 * The search covers the open node's subtree unless the call names another subtree.
 */
class expAiToolSearchContent extends expAiTool
{
    /** the most hits one call returns */
    const MAX_LIMIT = 25;

    public function description()
    {
        return 'Searches the content by words (full text) and returns the matching items: node and object id, name, class, '
             . 'path and a short snippet. Use it to find existing content on a topic before writing, or items you do not know '
             . 'the place of. By default it searches below the open node; give subtree for another part of the site.';
    }

    public function inputSchema()
    {
        return array( 'type' => 'object', 'properties' => array(
            'query' => array( 'type' => 'string', 'description' => 'The words to search for' ),
            'class' => array( 'type' => 'string', 'description' => 'Only items of this content class (class identifier)' ),
            'subtree' => array( 'type' => 'integer', 'description' => 'Only below this node (default: the open node)' ),
            'offset' => array( 'type' => 'integer', 'description' => 'Skip this many hits (paging)' ),
            'limit' => array( 'type' => 'integer', 'description' => 'At most this many hits (at most ' . self::MAX_LIMIT . ')' ),
        ), 'required' => array( 'query' ), 'additionalProperties' => false );
    }

    public function execute( array $input, expAiToolContext $context )
    {
        $query = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';
        if ( $query === '' )
            throw new expAiException( 'Give the words to search for.' );
        $query = mb_substr( $query, 0, 200, 'UTF-8' );
        $limit = isset( $input['limit'] ) ? max( 1, min( self::MAX_LIMIT, (int) $input['limit'] ) ) : 10;
        $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
        $subtrees = isset( $input['subtree'] ) && (int) $input['subtree'] > 0 ? array( (int) $input['subtree'] ) : $this->defaultSubtrees( $context );
        foreach ( $subtrees as $subtree )
            if ( !$this->readableSubtree( $subtree ) )
                throw new expAiException( 'Node %id does not exist or you may not read it.', '', 0, array( '%id' => $subtree ) );
        $params = array( 'SearchSubTreeArray' => $subtrees, 'SearchLimit' => $limit, 'SearchOffset' => $offset );
        $class = isset( $input['class'] ) ? trim( (string) $input['class'] ) : '';
        if ( $class !== '' )
        {
            $classID = $this->classID( $class );
            if ( !$classID )
                throw new expAiException( 'There is no content class "%identifier".', '', 0, array( '%identifier' => $class ) );
            $params['SearchContentClassID'] = array( $classID );
        }
        $found = $this->search( $query, $params );
        $hits = array();
        $left = 0;
        // the results of this call kept away (the reader's counter adds up over the whole step)
        $withheld = 0;
        foreach ( isset( $found['SearchResult'] ) && is_array( $found['SearchResult'] ) ? $found['SearchResult'] : array() as $node )
        {
            if ( !is_object( $node ) || ( method_exists( $node, 'canRead' ) && !$node->canRead() ) )
            {
                $left++;
                continue;
            }
            $object = method_exists( $node, 'object' ) ? $node->object() : null;
            if ( !$object || !$this->allowed( $context, $object ) )
            {
                $context->reader->withheld++;
                $withheld++;
                continue;
            }
            $hits[] = array(
                'node_id' => (int) $node->attribute( 'node_id' ),
                'object_id' => (int) $node->attribute( 'contentobject_id' ),
                'name' => (string) $node->attribute( 'name' ),
                'class' => (string) $node->attribute( 'class_identifier' ),
                'path' => (string) $node->attribute( 'path_identification_string' ),
                'snippet' => $this->snippet( $node, $object, $context ),
            );
        }
        // the engine's count includes what was left out here: only whether more may exist is told
        $total = isset( $found['SearchCount'] ) ? (int) $found['SearchCount'] : 0;
        return array( 'query' => $query, 'subtrees' => $subtrees, 'offset' => $offset, 'hits' => $hits,
                      'more_may_exist' => $total > $offset + count( $hits ) + $left + $withheld );
    }

    /**
     * The search (eZSearch::search(): eZ Find when it is active, else the kernel's search).
     * @return array SearchResult (nodes), SearchCount
     */
    protected function search( $query, array $params )
    {
        $result = eZSearch::search( $query, $params );
        return is_array( $result ) ? $result : array();
    }

    /**
     * The subtrees searched without a subtree in the call: the open node; in a conversation without one (a Vector
     * thread) the thread's scope (its nodes and subtrees), else the content root.
     * @return int[]
     */
    protected function defaultSubtrees( expAiToolContext $context )
    {
        if ( (int) $context->nodeID > 0 )
            return array( (int) $context->nodeID );
        $thread = $context->conversation && class_exists( 'expAiThread' ) ? expAiThread::fetchForConversation( $context->conversation ) : null;
        if ( $thread )
        {
            $scope = $thread->scope();
            $ids = array_values( array_unique( array_map( 'intval', array_merge( $scope['subtrees'], $scope['nodes'] ) ) ) );
            if ( $ids )
                return $ids;
        }
        return array( (int) eZINI::instance( 'content.ini' )->variable( 'NodeSettings', 'RootNode' ) ?: 2 );
    }

    /** @return object|null the subtree's node when the user may read it */
    protected function readableSubtree( $nodeID )
    {
        $node = eZContentObjectTreeNode::fetch( (int) $nodeID );
        return $node instanceof eZContentObjectTreeNode && $node->canRead() ? $node : null;
    }

    /** @return int the id of a class identifier, 0 when there is none */
    protected function classID( $identifier )
    {
        return (int) eZContentClass::classIDByIdentifier( (string) $identifier );
    }

    /** @return bool the data policy lets the object go to the provider (the reader notes it as read) */
    protected function allowed( expAiToolContext $context, $object )
    {
        return $context->reader->allowed( $object );
    }

    /**
     * The snippet of a hit: the search engine's highlight (eZ Find), else the start of the first text field.
     * @return string at most 240 characters, plain text
     */
    protected function snippet( $node, $object, expAiToolContext $context )
    {
        $text = '';
        if ( method_exists( $node, 'hasAttribute' ) && $node->hasAttribute( 'highlight' ) )
            $text = (string) $node->attribute( 'highlight' );
        if ( trim( strip_tags( $text ) ) === '' && method_exists( $object, 'dataMap' ) )
        {
            foreach ( $object->dataMap() as $identifier => $attribute )
            {
                if ( !in_array( $attribute->attribute( 'data_type_string' ), array( 'ezxmltext', 'eztext' ), true ) || !$attribute->hasContent() )
                    continue;
                $text = (string) $context->reader->value( $attribute );
                break;
            }
        }
        return expAiText::cut( expAiText::oneLine( html_entity_decode( strip_tags( $text ), ENT_QUOTES, 'UTF-8' ) ), 240 );
    }

    public function stepText( array $input )
    {
        return 'Searched the content for "%query"';
    }

    public function stepArguments( array $input, $result )
    {
        return array( '%query' => expAiText::cut( expAiText::oneLine( isset( $input['query'] ) ? (string) $input['query'] : '' ), 60 ) );
    }
}
