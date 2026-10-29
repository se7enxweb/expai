<?php
/**
 * File containing the expAiQueueSchema class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The tables and columns of lanes, batches and budgets, added to expAiSchema::definition() (so
 * bin/php/expaischema.php --install creates and upgrades them on every engine like the other expai tables):
 *
 *   expai_batch     one provider batch (Anthropic Message Batches, OpenAI Batch, Gemini, Mistral, OpenRouter)
 *
 *   expai_usage_log + lane, service_tier (what the provider reported), job_uuid, batch_id, price_factor (percent of
 *                     the list price the request is billed at: 100 standard, 50 batch and flex, 200 fast)
 *   expai_draft_log + job_uuid, package_uuid (package_uuid: unused since content packages are the kernel's; kept, empty)
 */
class expAiQueueSchema
{
    /**
     * @param array $schema the generic schema of expAiSchema
     * @return array the schema with the tables and columns of this class
     */
    public static function extend( array $schema )
    {
        $int = array( 'length' => 11, 'type' => 'int', 'not_null' => '1', 'default' => 0 );
        $varchar = function ( $length, $default = '' )
        {
            return array( 'length' => $length, 'type' => 'varchar', 'not_null' => '1', 'default' => $default );
        };
        $primary = array( 'type' => 'primary', 'fields' => array( 'id' ) );
        if ( isset( $schema['expai_usage_log'] ) )
        {
            $schema['expai_usage_log']['fields'] += array(
                'batch_id' => $int,
                'job_uuid' => $varchar( 36 ),
                'lane' => $varchar( 16 ),
                'price_factor' => array( 'length' => 11, 'type' => 'int', 'not_null' => '1', 'default' => 100 ),
                'service_tier' => $varchar( 20 ),
            );
            ksort( $schema['expai_usage_log']['fields'] );
            $schema['expai_usage_log']['indexes'] += array(
                'expai_usage_log_job' => array( 'type' => 'non-unique', 'fields' => array( 'job_uuid' ) ),
            );
        }
        if ( isset( $schema['expai_draft_log'] ) )
        {
            $schema['expai_draft_log']['fields'] += array( 'job_uuid' => $varchar( 36 ), 'package_uuid' => $varchar( 36 ) );
            ksort( $schema['expai_draft_log']['fields'] );
            $schema['expai_draft_log']['indexes'] += array(
                'expai_draft_log_package' => array( 'type' => 'non-unique', 'fields' => array( 'package_uuid' ) ),
            );
        }
        $schema['expai_batch'] = array(
            'name' => 'expai_batch',
            'fields' => array(
                'batch_api' => $varchar( 20 ),
                'canceled' => $int,
                'created' => $int,
                'deleted' => $int,
                'ended' => $int,
                'errored' => $int,
                'expired' => $int,
                'expires_at' => $int,
                'id' => array( 'type' => 'auto_increment', 'default' => false ),
                'input_ref' => $varchar( 120 ),
                'lane' => $varchar( 16 ),
                'last_error' => $varchar( 255 ),
                'model' => $varchar( 100 ),
                'model_alias' => $varchar( 100 ),
                'modified' => $int,
                'output_ref' => $varchar( 120 ),
                'polls' => $int,
                'provider' => $varchar( 40 ),
                'remote_id' => $varchar( 120 ),
                'remote_status' => $varchar( 40 ),
                'request_count' => $int,
                'results_fetched' => $int,
                'retention' => $varchar( 10 ),
                'state' => $varchar( 20, 'submitted' ),
                'submit_job_uuid' => $varchar( 36 ),
                'submitted' => $int,
                'succeeded' => $int,
                'user_id' => $int,
            ),
            'indexes' => array(
                'PRIMARY' => $primary,
                'expai_batch_state' => array( 'type' => 'non-unique', 'fields' => array( 'state', 'provider' ) ),
            ),
        );
        return $schema;
    }
}
