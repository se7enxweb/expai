<?php
/**
 * File containing the expAiModelTestHandler class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The job type expai.model.test: the "Test prompt" (action test) and "Load model" (action load) of expai/models,
 * run by the worker as the user who clicked (expAiModelTest). The outcome is always the job's result, a failure of
 * the model included (ok false, error), so the page can show it; nothing is retried: a test that failed is a result.
 * Held while the master switch is off for the siteaccess it was queued from.
 */
class expAiModelTestHandler extends expQueueHandlerBase
{
    public function validate( array $payload )
    {
        if ( empty( $payload['alias'] ) || !is_string( $payload['alias'] ) )
            return 'alias is required';
        if ( !isset( $payload['action'] ) || !in_array( $payload['action'], expAiModelTest::ACTIONS, true ) )
            return 'action is test or load';
        return null;
    }

    public function describe( expQueueJob $job )
    {
        $p = $job->payloadArray();
        return ( isset( $p['action'] ) && $p['action'] === 'load' ? 'Load model ' : 'Test prompt of ' ) . ( isset( $p['alias'] ) ? (string) $p['alias'] : '' );
    }

    public function run( expQueueJob $job, expQueueContext $context )
    {
        $p = $job->payloadArray();
        $config = expAiConfig::instance();
        $access = isset( $p['siteaccess'] ) ? (string) $p['siteaccess'] : null;
        if ( class_exists( 'expAiGate' ) && ( $off = expAiGate::switchedOff( $config, $access ) ) !== '' )
            return expQueueResult::hold( expAiGate::HELD_PREFIX . $off );
        $context->heartbeat();
        $context->progress( 0, 1, $p['action'] === 'load' ? 'loading the model' : 'waiting for the answer' );
        $result = expAiModelTest::run( (string) $p['alias'], (string) $p['action'], array(
            'user_id' => (int) $job->attribute( 'user_id' ), 'siteaccess' => (string) $access, 'job_uuid' => (string) $job->attribute( 'uuid' ) ), $config );
        if ( !empty( $result['usage_log_id'] ) )
        {
            $row = eZPersistentObject::fetchObject( expAiUsageLog::definition(), null, array( 'id' => (int) $result['usage_log_id'] ) );
            if ( $row instanceof expAiUsageLog )
            {
                $row->setAttribute( 'job_uuid', (string) $job->attribute( 'uuid' ) );
                $row->store();
            }
        }
        $context->progress( 1, 1 );
        return expQueueResult::done( $result, !empty( $result['ok'] ) ? '' : 'failed: ' . strtr( (string) $result['error'], (array) $result['error_args'] ) );
    }
}
