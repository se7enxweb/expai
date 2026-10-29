<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** list_children: the children of a node the current user may read */
class expAiToolListChildren extends expAiTool
{
    public function description()
    {
        return 'Lists the children of a node (name, class, node and object id, last change), in the node\'s sort order. '
             . 'total counts the children of the given class; has_more says whether another page follows (offset + limit); withheld counts the children of this page that are not shown. '
             . 'Use it to see what exists already and to read examples of a class for style. Without node_id: the open node.';
    }

    public function inputSchema()
    {
        return array( 'type' => 'object', 'properties' => array(
            'node_id' => array( 'type' => 'integer', 'description' => 'The parent node (default: the open node)' ),
            'class_identifier' => array( 'type' => 'string', 'description' => 'Only children of this class' ),
            'offset' => array( 'type' => 'integer', 'description' => 'Skip this many (paging)' ),
            'limit' => array( 'type' => 'integer', 'description' => 'At most this many (capped by the server)' ),
        ), 'additionalProperties' => false );
    }

    public function execute( array $input, expAiToolContext $context )
    {
        $node = $context->reader->readableNode( $this->nodeID( $input, $context ) );
        $max = $context->config->int( 'ToolSettings', 'ListLimit', 25, 1, 200 );
        $limit = isset( $input['limit'] ) ? max( 1, min( $max, (int) $input['limit'] ) ) : $max;
        $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
        $class = isset( $input['class_identifier'] ) ? (string) $input['class_identifier'] : '';
        $withheldBefore = (int) $context->reader->withheld;
        $children = $context->reader->children( $node, $limit, $offset, $class );
        $withheld = (int) $context->reader->withheld - $withheldBefore;
        // total: the readable children of this class (the class filter counts, as it does for the page); the children
        // of this page that the data policy keeps away are not in the list and are named in withheld
        $total = self::total( $node, $class );
        return array( 'parent_node_id' => (int) $node->attribute( 'node_id' ),
                      'total' => $total, 'offset' => $offset, 'withheld' => $withheld,
                      'has_more' => $offset + count( $children ) + $withheld < $total,
                      'children' => $children );
    }

    /**
     * The children of a node the user may read, of one class when given: the same set the page of children is cut from.
     * @return int
     */
    public static function total( eZContentObjectTreeNode $node, $classIdentifier = '' )
    {
        $params = array( 'Depth' => 1, 'DepthOperator' => 'eq' );
        if ( (string) $classIdentifier !== '' )
        {
            $params['ClassFilterType'] = 'include';
            $params['ClassFilterArray'] = array( (string) $classIdentifier );
        }
        return (int) $node->subTreeCount( $params );
    }

    public function stepText( array $input )
    {
        return 'Listed the children of node %id';
    }

    public function stepArguments( array $input, $result )
    {
        return array( '%id' => isset( $result['parent_node_id'] ) ? $result['parent_node_id'] : '' );
    }
}
