<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** list_creatable_classes: the classes the current user may create under a node */
class expAiToolListCreatableClasses extends expAiTool
{
    public function description()
    {
        return 'Lists the content classes you (as the editor) may create under a node, by identifier and name. '
             . 'Without parent_node_id: the open node.';
    }

    public function inputSchema()
    {
        return array( 'type' => 'object', 'properties' => array(
            'parent_node_id' => array( 'type' => 'integer', 'description' => 'The parent node (default: the open node)' ),
        ), 'additionalProperties' => false );
    }

    public function execute( array $input, expAiToolContext $context )
    {
        $node = $context->reader->readableNode( $this->nodeID( $input, $context, 'parent_node_id' ) );
        return array( 'parent_node_id' => (int) $node->attribute( 'node_id' ), 'classes' => $context->reader->creatableClasses( $node ) );
    }

    public function stepText( array $input )
    {
        return 'Checked which content you may create here';
    }
}
