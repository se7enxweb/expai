<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * propose_plan (Vector threads only, doc/plan-mode.md): stores a structured plan for the editor to approve. It reads
 * and writes no content: the items are checked against the thread's scope and the editor's permissions (refused items
 * are kept with the reason), estimated (expAiPlanEstimate) and shown in Vector's Plan tab. After approval the items run
 * one by one in write mode with staged review.
 */
class expAiToolProposePlan extends expAiTool
{
    public function description()
    {
        return 'Proposes a plan for bulk work (more than one object or language) for the editor to approve before anything is staged. '
             . 'One item per object and action: create (a new object under parent_node_id, with class), update (object_id or node_id), '
             . 'translate (object_id or node_id into languages). languages: language codes such as eng-GB; fields: the field identifiers '
             . 'the item writes; summary: the instruction for that item in one sentence; depends_on: earlier items it waits for. '
             . 'Read what you need first (list_children, get_node). '
             . 'After this call stop and summarize the plan in two sentences: the editor approves, edits or rejects it in the Plan tab.';
    }

    public function inputSchema()
    {
        return array( 'type' => 'object', 'properties' => array(
            'items' => array( 'type' => 'array', 'description' => 'The steps of the plan, one per object and action',
                'items' => array( 'type' => 'object', 'properties' => array(
                    'action' => array( 'type' => 'string', 'enum' => array( 'create', 'update', 'translate' ) ),
                    'object_id' => array( 'type' => 'integer', 'description' => 'update/translate: the object' ),
                    'node_id' => array( 'type' => 'integer', 'description' => 'update/translate: a node of the object (instead of object_id)' ),
                    'parent_node_id' => array( 'type' => 'integer', 'description' => 'create: where the new object goes' ),
                    'class' => array( 'type' => 'string', 'description' => 'create: the class identifier' ),
                    'languages' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                    'fields' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                    'summary' => array( 'type' => 'string', 'description' => 'What to do for this item, one sentence' ),
                    'depends_on' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ),
                                           'description' => 'Numbers (1 = the first item) of earlier items this item must wait for. A translation waits for the update of its source object automatically; items without dependencies run side by side.' ),
                ), 'required' => array( 'action', 'languages', 'summary' ) ) ),
            'assumptions' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'What you assumed' ),
            'questions' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Open questions for the editor' ),
            'risks' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'What could go wrong' ),
        ), 'required' => array( 'items' ), 'additionalProperties' => false );
    }

    public function execute( array $input, expAiToolContext $context )
    {
        $thread = $context->conversation ? expAiThread::fetchForConversation( $context->conversation ) : null;
        if ( !$thread || $thread->attribute( 'origin' ) === expAiThread::ORIGIN_PANEL )
            throw new expAiException( 'Plans can only be proposed in a Vector thread.' );
        $plan = expAiPlanner::propose( $thread, $input, $context->model, $context->config );
        $items = $plan->items();
        $refused = array_values( array_filter( $items, function ( $i ) { return $i['refused'] !== ''; } ) );
        $estimate = $plan->estimate();
        $this->count = count( $items );
        return array( 'status' => 'plan stored for the editor\'s approval; nothing is staged or written yet. Stop here and summarize the plan.',
                      'plan_id' => (int) $plan->attribute( 'id' ), 'items' => count( $items ), 'runnable' => count( $plan->runnableItems() ),
                      'refused' => array_map( function ( $i ) { return array( 'item' => (int) $i['index'] + 1, 'reason' => $i['refused'] ); }, $refused ),
                      'estimate' => array( 'expected' => expAiBudget::money( (int) $estimate['expected_micros'] ), 'tokens' => (int) $estimate['tokens'] ) );
    }

    /** @var int items of the last plan */
    protected $count = 0;

    public function stepText( array $input )
    {
        return 'Proposed a plan with %count item(s)';
    }

    public function stepArguments( array $input, $result )
    {
        return array( '%count' => isset( $result['items'] ) ? (int) $result['items'] : ( isset( $input['items'] ) && is_array( $input['items'] ) ? count( $input['items'] ) : 0 ) );
    }
}
