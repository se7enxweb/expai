<?php
/**
 * File containing the expAiPlanItemApplier class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The applier vector_plan_item ([QueueSettings] Appliers[]): the answer of one item of an approved plan that ran on a
 * batch lane (expAiPlanBatch) is staged as changes of the item's child run, through expAiChangeStager as the write
 * tools would (scope, permissions, rich text through the input parser, "held by run N"); nothing is written into
 * content. The usage row of the request is linked to the thread and the run (the cost per item), the run ends, and
 * the plan is settled when its last item is staged.
 */
class expAiPlanItemApplier implements expAiRequestApplier
{
    public function apply( expQueueJob $job, array $answer, array $params, expQueueContext $context )
    {
        $thread = expAiThread::fetchByID( isset( $params['thread_id'] ) ? (int) $params['thread_id'] : 0 );
        $run = expAiThreadRun::fetchByID( isset( $params['run_id'] ) ? (int) $params['run_id'] : 0 );
        if ( !$thread || !$run || (int) $run->attribute( 'thread_id' ) !== (int) $thread->attribute( 'id' ) )
            throw new expQueuePermanentException( 'the thread or its run is gone', 'thread' );
        // the cost of the request belongs to the thread and the run
        if ( !empty( $answer['usage_log_id'] ) )
            eZPersistentObject::updateObjectList( array( 'definition' => expAiUsageLog::definition(),
                'update_fields' => array( 'thread_id' => (int) $thread->attribute( 'id' ), 'run_id' => (int) $run->attribute( 'id' ) ),
                'conditions' => array( 'id' => (int) $answer['usage_log_id'] ) ) );
        if ( !$run->isActive() )
            return array( 'staged' => 0, 'note' => 'the run ended ' . $run->attribute( 'state' ) );
        $user = eZUser::currentUser();
        if ( !$thread->isOwner( $user->attribute( 'contentobject_id' ) ) )
        {
            expAiThreadFanout::finishRun( $thread, $run, 'failed', ezpI18n::tr( 'extension/expai/vector', 'The owner of this thread no longer has access to it.' ) );
            throw new expQueuePermanentException( 'the job does not run as the owner of the thread', 'access' );
        }
        // "changes": [{field, value}] (the schema of expAiPlanBatch); "fields": {field: value} is read as well
        $fields = isset( $answer['value']['fields'] ) && is_array( $answer['value']['fields'] ) ? $answer['value']['fields'] : null;
        if ( isset( $answer['value']['changes'] ) && is_array( $answer['value']['changes'] ) )
        {
            $fields = array();
            foreach ( $answer['value']['changes'] as $row )
                if ( is_array( $row ) && isset( $row['field'], $row['value'] ) && is_scalar( $row['field'] ) && is_scalar( $row['value'] ) )
                    $fields[(string) $row['field']] = (string) $row['value'];
        }
        if ( $fields === null )
        {
            expAiThreadFanout::finishRun( $thread, $run, 'failed', isset( $answer['stop_reason'] ) && $answer['stop_reason'] === 'max_tokens'
                ? 'the answer was cut off at its length limit' : 'the answer is not the JSON that was asked for' );
            throw new expQueuePermanentException( 'the answer has no fields', 'answer' );
        }
        $allowed = isset( $params['fields'] ) ? array_map( 'strval', (array) $params['fields'] ) : array();
        $fields = array_intersect_key( array_filter( $fields, 'is_scalar' ), array_flip( $allowed ) );
        $config = expAiConfig::instance();
        // a conversation in memory only: the stager names the run of the changes by it
        $conversation = new expAiConversation( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'run_id' => (int) $run->attribute( 'id' ) ) );
        $tool = new expAiToolContext( $config, 0, $conversation );
        // the answers of a plan's batch (purpose queue.bulk, as the gate sees them)
        $tool->reader->setRequest( array( 'purpose' => 'queue.bulk' ) );
        $tool->staged = true;
        $stager = new expAiChangeStager( $tool, $thread );
        $language = (string) $params['language'];
        $staged = array();
        try
        {
            if ( !$fields )
                $result = array( 'change_ids' => array(), 'staged' => array() );
            elseif ( $params['action'] === 'create' )
            {
                $parent = eZContentObjectTreeNode::fetch( (int) $params['parent_node_id'] );
                if ( !$parent instanceof eZContentObjectTreeNode || !$parent->canRead() )
                    throw new expAiException( 'Node %id does not exist or you may not read it.', '', 0, array( '%id' => (int) $params['parent_node_id'] ) );
                $result = $stager->stageCreate( $parent, (string) $params['class'], $fields, $language );
            }
            else
            {
                $object = expAiObjects::fetch( (int) $params['object_id'] );
                if ( !$object instanceof eZContentObject || !$object->canRead() )
                    throw new expAiException( 'Object %id does not exist or you may not read it.', '', 0, array( '%id' => (int) $params['object_id'] ) );
                $result = $stager->stageUpdate( $object, $language, $fields );
            }
            $staged = isset( $result['staged'] ) ? $result['staged'] : array();
        }
        catch ( expAiException $e )
        {
            expAiThreadFanout::finishRun( $thread, $run, 'failed', $e->translatedMessage() );
            throw new expQueuePermanentException( strtr( $e->getMessage(), $e->arguments() ), 'stage' );
        }
        $thread = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ?: $thread;
        $text = isset( $answer['value']['summary'] ) && is_scalar( $answer['value']['summary'] ) ? expAiText::cut( (string) $answer['value']['summary'], 500 ) : '';
        $thread->addEvent( 'step_end', array( 'text' => ezpI18n::tr( 'extension/expai/vector', 'Batch answer staged for review: %fields', null,
                                                                     array( '%fields' => $staged ? implode( ', ', $staged ) : '-' ) ),
                                              'ok' => true, 'writes' => (bool) $staged, 'run_id' => (int) $run->attribute( 'id' ) ), (int) $run->attribute( 'id' ) );
        if ( $text !== '' )
            $thread->addEvent( 'text', array( 'text' => $text, 'run_id' => (int) $run->attribute( 'id' ) ), (int) $run->attribute( 'id' ) );
        $thread->refreshCost();
        $thread->storeActivity();
        // one request per language: the run ends with its last language
        $run = expAiThreadRun::fetchByID( (int) $run->attribute( 'id' ) ) ?: $run;
        $run->setAttribute( 'progress_done', (int) $run->attribute( 'progress_done' ) + 1 );
        if ( !(int) $run->attribute( 'started' ) )
            $run->setAttribute( 'started', time() );
        $run->setAttribute( 'state', 'running' );
        $run->setAttribute( 'modified', time() );
        $run->store();
        if ( (int) $run->attribute( 'progress_done' ) >= max( 1, (int) ( isset( $params['languages'] ) ? $params['languages'] : 1 ) ) )
            expAiThreadFanout::finishRun( $thread, $run, 'done' );
        return array( 'staged' => count( $staged ), 'fields' => $staged, 'run_id' => (int) $run->attribute( 'id' ) );
    }
}
