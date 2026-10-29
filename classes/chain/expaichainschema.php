<?php
/**
 * File containing the expAiChainSchema class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The tables of AI chains, added to expAiSchema::definition() (bin/php/expaischema.php --install creates and
 * upgrades them on every engine like the other expai tables):
 *
 *   expai_chain_run   one run of a chain: who, where, the brief, the state, the snapshot of the definition, the
 *                     document so far, cost and tokens
 *   expai_chain_step  one step of a run (and iteration): the queue job, model, lane, verdict, score, tokens, cost,
 *                     the step's output (capped)
 */
class expAiChainSchema
{
    /**
     * @param array $schema the generic schema of expAiSchema
     * @return array the schema with the chain tables
     */
    public static function extend( array $schema )
    {
        $int = array( 'length' => 11, 'type' => 'int', 'not_null' => '1', 'default' => 0 );
        $varchar = function ( $length, $default = '' )
        {
            return array( 'length' => $length, 'type' => 'varchar', 'not_null' => '1', 'default' => $default );
        };
        $text = array( 'type' => 'longtext', 'default' => false );
        $primary = array( 'type' => 'primary', 'fields' => array( 'id' ) );
        $schema['expai_chain_run'] = array(
            'name' => 'expai_chain_run',
            'fields' => array(
                'brief' => $text,
                'chain' => $varchar( 40 ),
                'chain_hash' => $varchar( 40 ),
                'class_identifier' => $varchar( 50 ),
                'cost_micros' => expAiSchema::BIGINT,
                'created' => $int,
                'current_step' => $varchar( 40 ),
                'data' => $text,
                'finished' => $int,
                'group_uuid' => $varchar( 36 ),
                'id' => array( 'type' => 'auto_increment', 'default' => false ),
                'input_tokens' => $int,
                'item' => $int,
                'item_count' => $int,
                'label' => $varchar( 150 ),
                'language' => $varchar( 20 ),
                'last_error' => $varchar( 255 ),
                'modified' => $int,
                'node_id' => $int,
                'object_id' => $int,
                'output_tokens' => $int,
                'snapshot' => $text,
                'state' => $varchar( 20, 'running' ),
                'user_id' => $int,
                'uuid' => $varchar( 36 ),
                'version' => $int,
            ),
            'indexes' => array(
                'PRIMARY' => $primary,
                'expai_chain_run_uuid' => array( 'type' => 'non-unique', 'fields' => array( 'uuid' ) ),
                'expai_chain_run_state' => array( 'type' => 'non-unique', 'fields' => array( 'state', 'user_id' ) ),
            ),
        );
        $schema['expai_chain_step'] = array(
            'name' => 'expai_chain_step',
            'fields' => array(
                'cost_micros' => expAiSchema::BIGINT,
                'created' => $int,
                'finished' => $int,
                'id' => array( 'type' => 'auto_increment', 'default' => false ),
                'input_hash' => $varchar( 40 ),
                'input_tokens' => $int,
                'issue_count' => $int,
                'iteration' => $int,
                'job_uuid' => $varchar( 36 ),
                'lane' => $varchar( 16 ),
                'language' => $varchar( 20 ),
                'model' => $varchar( 100 ),
                'note' => $varchar( 255 ),
                'output' => $text,
                'output_tokens' => $int,
                'provider' => $varchar( 40 ),
                'run_id' => $int,
                'score' => array( 'length' => 11, 'type' => 'int', 'not_null' => '1', 'default' => -1 ),
                'service_tier' => $varchar( 20 ),
                'state' => $varchar( 20, 'queued' ),
                'step' => $varchar( 40 ),
                'step_type' => $varchar( 20 ),
                'usage_log_id' => $int,
                'verdict' => $varchar( 10 ),
            ),
            'indexes' => array(
                'PRIMARY' => $primary,
                'expai_chain_step_run' => array( 'type' => 'non-unique', 'fields' => array( 'run_id' ) ),
                'expai_chain_step_job' => array( 'type' => 'non-unique', 'fields' => array( 'job_uuid' ) ),
            ),
        );
        return $schema;
    }
}
