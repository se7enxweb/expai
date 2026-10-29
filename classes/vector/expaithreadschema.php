<?php
/**
 * File containing the expAiThreadSchema class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The tables of threads and Vector (doc/threads.md, doc/database.md), in the kernel's generic schema format. They are
 * merged into expAiSchema::definition(), so share/db_schema.dba, sql/<engine>/schema.sql and
 * bin/php/expaischema.php --install (create and upgrade) cover them for every engine.
 *
 *   expai_thread        one unit of work: owner, title, origin (panel, vector, package, chain), scope, mode, model, lane,
 *                       state, shelf, cached cost
 *   expai_thread_run    one agent run of a thread (the lead run, later fan-out children); its own conversation
 *   expai_thread_event  the append-only stream of a thread (status, steps, text, drafts, cost, errors)
 *   expai_followup      messages queued while a run works
 *   expai_checkpoint    the state at the start of a turn (restore)
 *   expai_plan          a proposed or approved plan of a thread
 *   expai_change        a staged proposal: one object x language x attribute
 *
 * and the columns thread_id/run_id on expai_conversation and expai_usage_log, thread_id/change_id on
 * expai_draft_log. Names stay within Oracle's limits (30 characters, no reserved words: run_mode, position, note).
 */
class expAiThreadSchema
{
    /**
     * @param array $schema the base tables of expAiSchema::definition()
     * @return array the schema with the thread tables and columns
     */
    public static function extend( array $schema )
    {
        $int = array( 'length' => 11, 'type' => 'int', 'not_null' => '1', 'default' => 0 );
        $varchar = function ( $length, $default = '' )
        {
            return array( 'length' => $length, 'type' => 'varchar', 'not_null' => '1', 'default' => $default );
        };
        $text = array( 'type' => 'longtext', 'default' => false );
        $id = array( 'type' => 'auto_increment', 'default' => false );
        $primary = array( 'type' => 'primary', 'fields' => array( 'id' ) );
        $index = function ( array $fields ) { return array( 'type' => 'non-unique', 'fields' => $fields ); };

        // the columns of the existing tables that link them to a thread
        $columns = array(
            'expai_conversation' => array( array( 'run_id', 'thread_id' ), 'expai_conversation_thread' ),
            'expai_usage_log' => array( array( 'run_id', 'thread_id' ), 'expai_usage_log_thread' ),
            'expai_draft_log' => array( array( 'change_id', 'thread_id' ), 'expai_draft_log_thread' ),
        );
        foreach ( $columns as $table => $add )
        {
            if ( !isset( $schema[$table] ) )
                continue;
            foreach ( $add[0] as $field )
                $schema[$table]['fields'][$field] = $int;
            ksort( $schema[$table]['fields'] );
            $schema[$table]['indexes'][$add[1]] = $index( array( 'thread_id' ) );
        }

        $schema['expai_thread'] = array(
            'name' => 'expai_thread',
            'fields' => array(
                'cost_micros' => expAiSchema::BIGINT,
                'created' => $int,
                'id' => $id,
                'lane' => $varchar( 16, 'auto' ),
                'last_event_id' => $int,
                'lead_run_id' => $int,
                'model_alias' => $varchar( 100 ),
                'modified' => $int,
                'node_id' => $int,
                'origin' => $varchar( 16, 'vector' ),
                'plan_id' => $int,
                'position' => $int,
                'provider' => $varchar( 40 ),
                'requests' => $int,
                'reserved_micros' => expAiSchema::BIGINT,
                'review_policy' => $varchar( 12, 'staged' ),
                'run_mode' => $varchar( 10, 'write' ),
                'scope_json' => $text,
                'seen_event_id' => $int,
                'shelf' => $varchar( 10, 'active' ),
                'state' => $varchar( 16, 'idle' ),
                'title' => $varchar( 255 ),
                'tokens' => $int,
                'user_id' => $int,
                'uuid' => $varchar( 36 ),
            ),
            'indexes' => array(
                'PRIMARY' => $primary,
                'expai_thread_user_shelf' => $index( array( 'user_id', 'shelf', 'modified' ) ),
                'expai_thread_user_state' => $index( array( 'user_id', 'state' ) ),
                'expai_thread_uuid' => $index( array( 'uuid' ) ),
                'expai_thread_node' => $index( array( 'node_id' ) ),
            ),
        );
        $schema['expai_thread_run'] = array(
            'name' => 'expai_thread_run',
            'fields' => array(
                'conversation_id' => $int,
                'cost_micros' => expAiSchema::BIGINT,
                'created' => $int,
                'finished' => $int,
                'id' => $id,
                'job_uuid' => $varchar( 36 ),
                'kind' => $varchar( 12, 'turn' ),
                'label' => $varchar( 255 ),
                'lane' => $varchar( 16 ),
                'model_alias' => $varchar( 100 ),
                'modified' => $int,
                'parent_run_id' => $int,
                'plan_item' => array( 'length' => 11, 'type' => 'int', 'not_null' => '1', 'default' => -1 ),
                'progress_done' => $int,
                'progress_total' => $int,
                'started' => $int,
                'state' => $varchar( 16, 'idle' ),
                'thread_id' => $int,
                'tokens' => $int,
            ),
            'indexes' => array(
                'PRIMARY' => $primary,
                'expai_thread_run_thread' => $index( array( 'thread_id', 'id' ) ),
                'expai_thread_run_conv' => $index( array( 'conversation_id' ) ),
            ),
        );
        $schema['expai_thread_event'] = array(
            'name' => 'expai_thread_event',
            'fields' => array(
                'created' => $int,
                'id' => $id,
                'kind' => $varchar( 20 ),
                'payload' => $text,
                'run_id' => $int,
                'thread_id' => $int,
            ),
            'indexes' => array(
                'PRIMARY' => $primary,
                'expai_thread_event_thread' => $index( array( 'thread_id', 'id' ) ),
                'expai_thread_event_created' => $index( array( 'created' ) ),
            ),
        );
        $schema['expai_followup'] = array(
            'name' => 'expai_followup',
            'fields' => array(
                'body' => $text,
                'created' => $int,
                'delivery' => $varchar( 8, 'queue' ),
                'id' => $id,
                'modified' => $int,
                'position' => $int,
                'state' => $varchar( 10, 'waiting' ),
                'thread_id' => $int,
            ),
            'indexes' => array(
                'PRIMARY' => $primary,
                'expai_followup_thread' => $index( array( 'thread_id', 'state' ) ),
            ),
        );
        $schema['expai_checkpoint'] = array(
            'name' => 'expai_checkpoint',
            'fields' => array(
                'changes_json' => $text,
                'conversation_len' => $int,
                'created' => $int,
                'drafts_json' => $text,
                'id' => $id,
                'label' => $varchar( 255 ),
                'thread_id' => $int,
                'turn' => $int,
            ),
            'indexes' => array(
                'PRIMARY' => $primary,
                'expai_checkpoint_thread' => $index( array( 'thread_id', 'turn' ) ),
                'expai_checkpoint_created' => $index( array( 'created' ) ),
            ),
        );
        $schema['expai_plan'] = array(
            'name' => 'expai_plan',
            'fields' => array(
                'approved' => $int,
                'approved_by' => $int,
                'created' => $int,
                'estimate_json' => $text,
                'id' => $id,
                'items_json' => $text,
                'modified' => $int,
                'state' => $varchar( 12, 'proposed' ),
                'thread_id' => $int,
            ),
            'indexes' => array(
                'PRIMARY' => $primary,
                'expai_plan_thread' => $index( array( 'thread_id' ) ),
            ),
        );
        $schema['expai_change'] = array(
            'name' => 'expai_change',
            'fields' => array(
                'applied_version' => $int,
                'attribute_identifier' => $varchar( 100 ),
                'base_hash' => $varchar( 64 ),
                'base_version' => $int,
                'class_identifier' => $varchar( 100 ),
                'contentobject_id' => $int,
                'created' => $int,
                'datatype' => $varchar( 40 ),
                'decided' => $int,
                'decided_by' => $int,
                'decision' => $varchar( 10, 'pending' ),
                'hunks_json' => $text,
                'id' => $id,
                'language_code' => $varchar( 20 ),
                'modified' => $int,
                'new_key' => $varchar( 40 ),
                'parent_node_id' => $int,
                'proposed' => $text,
                'run_id' => $int,
                'thread_id' => $int,
            ),
            'indexes' => array(
                'PRIMARY' => $primary,
                'expai_change_thread' => $index( array( 'thread_id', 'decision' ) ),
                'expai_change_object' => $index( array( 'contentobject_id', 'language_code' ) ),
                'expai_change_created' => $index( array( 'created' ) ),
            ),
        );
        return $schema;
    }

    /** @return string[] the tables of threads (for checks: they exist before the thread code runs) */
    public static function tables()
    {
        return array( 'expai_thread', 'expai_thread_run', 'expai_thread_event', 'expai_followup', 'expai_checkpoint', 'expai_plan', 'expai_change' );
    }
}
