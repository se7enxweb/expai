<?php
/**
 * File containing the expAiThreadRun class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * One agent run of a thread (table expai_thread_run). Every thread has a lead run (the conversation the editor talks
 * to); fan-out runs of an approved plan are children (parent_run_id, plan_item). Each run has its own
 * expai_conversation row: parallel runs cannot share one message list. job_uuid links a run to its expqueue job once
 * runs execute in the queue; in foreground mode (the browser drives the steps, as in the panel) it is empty.
 */
class expAiThreadRun extends eZPersistentObject
{
    public static function definition()
    {
        $int = function ( $name, $default = 0 ) { return array( 'name' => $name, 'datatype' => 'integer', 'default' => $default, 'required' => true ); };
        $str = function ( $name, $default = '' ) { return array( 'name' => $name, 'datatype' => 'string', 'default' => $default, 'required' => true ); };
        return array(
            'fields' => array(
                'id' => $int( 'ID' ),
                'thread_id' => $int( 'ThreadID' ),
                'parent_run_id' => $int( 'ParentRunID' ),
                'plan_item' => $int( 'PlanItem', -1 ),
                'job_uuid' => $str( 'JobUUID' ),
                'conversation_id' => $int( 'ConversationID' ),
                'kind' => $str( 'Kind', 'turn' ),
                'state' => $str( 'State', 'idle' ),
                'label' => $str( 'Label' ),
                'progress_done' => $int( 'ProgressDone' ),
                'progress_total' => $int( 'ProgressTotal' ),
                'model_alias' => $str( 'ModelAlias' ),
                'lane' => $str( 'Lane' ),
                'cost_micros' => $int( 'CostMicros' ),
                'tokens' => $int( 'Tokens' ),
                'started' => $int( 'Started' ),
                'finished' => $int( 'Finished' ),
                'created' => $int( 'Created' ),
                'modified' => $int( 'Modified' ),
            ),
            'keys' => array( 'id' ),
            'increment_key' => 'id',
            'class_name' => 'expAiThreadRun',
            'sort' => array( 'id' => 'asc' ),
            'name' => 'expai_thread_run',
        );
    }

    /** @return expAiThreadRun|null */
    public static function fetchByID( $id )
    {
        if ( !(int) $id )
            return null;
        $object = eZPersistentObject::fetchObject( self::definition(), null, array( 'id' => (int) $id ) );
        return $object instanceof self ? $object : null;
    }

    /** @return expAiThreadRun[] the runs of a thread, oldest first */
    public static function fetchForThread( $threadID )
    {
        return (array) eZPersistentObject::fetchObjectList( self::definition(), null, array( 'thread_id' => (int) $threadID ), array( 'id' => 'asc' ) );
    }

    /** @var string[] the states of a run that still works or waits to */
    public static $active = array( 'queued', 'running', 'waiting' );

    /** @return bool the run still works or waits to (queued, running, waiting for an item it depends on) */
    public function isActive()
    {
        return in_array( (string) $this->attribute( 'state' ), self::$active, true );
    }

    /** @return bool a child run of an approved plan (doc/parallel-runs.md) */
    public function isChild()
    {
        return (int) $this->attribute( 'parent_run_id' ) > 0;
    }

    /**
     * @param int $afterRunID only the runs made after this run id (the runs of the current plan; 0 all)
     * @return expAiThreadRun[] the child runs of a thread (fan-out of approved plans), oldest first
     */
    public static function fetchChildren( $threadID, $afterRunID = 0 )
    {
        $list = (array) eZPersistentObject::fetchObjectList( self::definition(), null, array( 'thread_id' => (int) $threadID, 'parent_run_id' => array( '>', 0 ) ),
                                                             array( 'id' => 'asc' ), array( 'offset' => 0, 'length' => 500 ) );
        return $afterRunID ? array_values( array_filter( $list, function ( $r ) use ( $afterRunID ) { return (int) $r->attribute( 'id' ) > (int) $afterRunID; } ) ) : $list;
    }

    /** @return string how a run is named to the model and the editor: "run 3 (Update: Main Street, eng-GB)" */
    public function shortName()
    {
        return 'run ' . (int) $this->attribute( 'id' ) . ( (string) $this->attribute( 'label' ) !== '' ? ' (' . expAiText::cut( (string) $this->attribute( 'label' ), 80 ) . ')' : '' );
    }

    /** Takes the run's cost and tokens from the usage log rows with its run_id */
    public function refreshCost()
    {
        $micros = 0;
        $tokens = 0;
        // thread_id first: the usage log is indexed by thread
        $rows = eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), null, expAiUsage::requestsOnly( array( 'thread_id' => (int) $this->attribute( 'thread_id' ), 'run_id' => (int) $this->attribute( 'id' ) ) ),
                                                      array( 'id' => 'asc' ), array( 'offset' => 0, 'length' => 2000 ), false );
        foreach ( (array) $rows as $row )
        {
            $tokens += (int) $row['input_tokens'] + (int) $row['output_tokens'] + (int) $row['cache_write_tokens'] + (int) $row['cache_read_tokens'];
            $cost = expAiUsage::cost( $row['model'], $row['input_tokens'], $row['output_tokens'], $row['cache_write_tokens'], $row['cache_read_tokens'],
                                      isset( $row['model_alias'] ) ? (string) $row['model_alias'] : '', isset( $row['reported_cost_micros'] ) ? (int) $row['reported_cost_micros'] : 0 );
            if ( $cost !== null )
                $micros += (int) round( $cost * 1000000 );
        }
        // cost_micros is 64 bits wide (expAiSchema::BIGINT)
        $this->setAttribute( 'cost_micros', $micros );
        $this->setAttribute( 'tokens', min( 2147483647, $tokens ) );
    }

    /** @return expAiThreadRun|null the run of a conversation */
    public static function fetchForConversation( $conversationID )
    {
        $list = eZPersistentObject::fetchObjectList( self::definition(), null, array( 'conversation_id' => (int) $conversationID ), array( 'id' => 'asc' ),
                                                     array( 'offset' => 0, 'length' => 1 ) );
        return $list ? $list[0] : null;
    }
}
