<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** get_tree_context: where a node sits: its ancestors, its siblings and the number of its children */
class expAiToolGetTreeContext extends expAiTool
{
    public function description()
    {
        return 'Shows where a node sits in the content tree: the path of its ancestors, its siblings and how many children it has. '
             . 'Without node_id: the open node.';
    }

    public function inputSchema()
    {
        return array( 'type' => 'object', 'properties' => array(
            'node_id' => array( 'type' => 'integer', 'description' => 'The node (default: the open node)' ),
        ), 'additionalProperties' => false );
    }

    public function execute( array $input, expAiToolContext $context )
    {
        $node = $context->reader->readableNode( $this->nodeID( $input, $context ) );
        $path = array();
        foreach ( $node->fetchPath() as $ancestor )
        {
            if ( $ancestor->canRead() && (int) $ancestor->attribute( 'depth' ) > 0 )
                $path[] = $context->reader->shortNode( $ancestor );
        }
        $siblings = array();
        $parent = $node->fetchParent();
        if ( $parent && $parent->canRead() )
        {
            foreach ( $context->reader->children( $parent, $context->config->int( 'ToolSettings', 'ListLimit', 25, 1, 200 ) ) as $sibling )
                if ( $sibling['node_id'] !== (int) $node->attribute( 'node_id' ) )
                    $siblings[] = $sibling;
        }
        return array( 'node' => $context->reader->shortNode( $node ), 'path' => $path, 'siblings' => $siblings,
                      'children_count' => expAiToolListChildren::total( $node ) );
    }

    public function stepText( array $input )
    {
        return 'Looked at the content tree around "%name"';
    }

    public function stepArguments( array $input, $result )
    {
        return array( '%name' => isset( $result['node']['name'] ) ? $result['node']['name'] : '' );
    }
}
