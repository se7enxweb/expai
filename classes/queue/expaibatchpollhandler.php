<?php
/**
 * File containing the expAiBatchPollHandler class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The job type expai.batch.poll (class system): asks the provider for the state of one batch every PollSeconds
 * (deferred in between, without using up attempts). When the batch ended it fetches the results and, per request:
 *
 *   succeeded  a usage row (lane batch, price factor of the lane, batch id, job uuid), the reservation settled, the
 *              answer stored in the job's checkpoint and the job woken to apply it
 *   errored    a request the provider calls invalid ends dead; any other error: the job is released and waits for
 *              the next batch
 *   expired, canceled   the job moves to the lane's ExpiredLane (standard) and is woken
 *
 * Then the batch is deleted at the provider when DeleteAfterFetch is enabled (the data leaves the provider's batch
 * store before its retention ends). A batch whose requests were all cancelled is cancelled at the provider. A rerun
 * after a crash is safe: a job that has its answer already is skipped, the results are read again.
 */
class expAiBatchPollHandler extends expQueueHandlerBase
{
    public function validate( array $payload )
    {
        return empty( $payload['batch_id'] ) ? 'batch_id is required' : null;
    }

    public function describe( expQueueJob $job )
    {
        $p = $job->payloadArray();
        return 'Poll batch ' . ( isset( $p['batch_id'] ) ? (int) $p['batch_id'] : 0 );
    }

    public function run( expQueueJob $job, expQueueContext $context )
    {
        $p = $job->payloadArray();
        $batch = expAiBatch::fetch( (int) $p['batch_id'] );
        if ( !$batch )
            throw new expQueuePermanentException( 'batch ' . (int) $p['batch_id'] . ' is gone', 'not_found' );
        if ( $batch->attribute( 'state' ) === 'done' )
            return array( 'batch_id' => (int) $batch->attribute( 'id' ), 'message' => 'already done' );
        $config = expAiConfig::instance();
        $lane = expAiLane::fetch( (string) $batch->attribute( 'provider' ), (string) $batch->attribute( 'lane' ), $config );
        if ( !$lane || !$lane->isBatch() )
            throw new expQueuePermanentException( 'the lane ' . $batch->attribute( 'provider' ) . ':' . $batch->attribute( 'lane' ) . ' is not configured any more', 'lane' );
        $api = expAiBatchApi::forLane( $lane, (string) $batch->attribute( 'model_alias' ), $config );

        if ( $batch->isOpen() )
        {
            try
            {
                $status = $api->status( $batch );
            }
            catch ( expAiException $e )
            {
                $batch->touch( array( 'last_error' => expAiText::cut( $e->detail(), 255 ) ) );
                $context->rateLimit( expAiRequestHandler::hints( is_array( $e->rateLimits ) ? $e->rateLimits : array(), (int) $e->getCode() ) );
                throw new expQueueRetryableException( 'the batch state could not be read: ' . $e->detail(), 0, 'http_' . (int) $e->getCode() );
            }
            $counts = $status['counts'];
            $batch->touch( array( 'state' => $status['state'], 'remote_status' => substr( (string) $status['remote_status'], 0, 40 ),
                                  'succeeded' => $counts['succeeded'], 'errored' => $counts['errored'], 'expired' => $counts['expired'], 'canceled' => $counts['canceled'],
                                  'output_ref' => $status['output_ref'] !== '' ? substr( $status['output_ref'], 0, 120 ) : $batch->attribute( 'output_ref' ),
                                  'ended' => $status['ended'] ?: ( $batch->isOpen() ? 0 : time() ), 'polls' => (int) $batch->attribute( 'polls' ) + 1,
                                  'expires_at' => $status['expires_at'] ?: (int) $batch->attribute( 'expires_at' ), 'last_error' => '' ) );
            if ( $batch->isOpen() )
            {
                $this->cancelWhenAbandoned( $batch, $api, $context );
                return expQueueResult::defer( (int) $lane->get( 'poll_seconds' ), 'batch ' . $batch->attribute( 'id' ) . ' is ' . $status['remote_status'] );
            }
            if ( !$batch->attribute( 'ended' ) )
                $batch->touch( array( 'ended' => time() ) );
        }

        // the batch ended (or failed, expired, was cancelled): its results
        $results = array();
        if ( $batch->attribute( 'state' ) !== 'failed' || $batch->attribute( 'output_ref' ) !== '' )
        {
            try
            {
                $results = $api->results( $batch );
            }
            catch ( expAiException $e )
            {
                $batch->touch( array( 'last_error' => expAiText::cut( $e->detail(), 255 ) ) );
                throw new expQueueRetryableException( 'the batch results could not be read: ' . $e->detail(), 0, 'http_' . (int) $e->getCode() );
            }
        }
        $outcome = $this->applyResults( $batch, $lane, $results, $context );
        $batch->touch( array( 'results_fetched' => time() ) );

        $deleted = false;
        if ( $lane->get( 'delete_after_fetch' ) && !$batch->attribute( 'deleted' ) )
        {
            try
            {
                $deleted = $api->delete( $batch );
                if ( $deleted )
                    $batch->touch( array( 'deleted' => time() ) );
            }
            catch ( expAiException $e )
            {
                $context->log( 'the batch could not be deleted at the provider: ' . $e->detail() );
            }
        }
        $batch->touch( array( 'state' => 'done' ) );
        return $outcome + array( 'batch_id' => (int) $batch->attribute( 'id' ), 'remote_id' => (string) $batch->attribute( 'remote_id' ), 'deleted' => $deleted );
    }

    /**
     * The results, per job.
     * @return array succeeded, errored, retried, moved, skipped
     */
    public function applyResults( expAiBatch $batch, expAiLane $lane, array $results, expQueueContext $context )
    {
        $out = array( 'succeeded' => 0, 'errored' => 0, 'retried' => 0, 'moved' => 0, 'skipped' => 0 );
        $budget = new expAiBudget();
        $db = eZDB::instance();
        $rows = $db->arrayQuery( "SELECT id FROM expqueue_job WHERE external_ref = '" . $db->escapeString( $batch->ref() ) . "' ORDER BY id" );
        $now = time();
        foreach ( is_array( $rows ) ? $rows : array() as $row )
        {
            $row = array_change_key_case( $row, CASE_LOWER );
            $job = expQueueJob::fetchByID( (int) $row['id'] );
            if ( !$job )
                continue;
            $uuid = (string) $job->attribute( 'uuid' );
            if ( $job->isFinal() || $job->attribute( 'checkpoint' ) !== '' || $job->attribute( 'state' ) === 'running' )
            {
                ++$out['skipped'];
                continue;
            }
            $p = $job->payloadArray();
            $item = isset( $results[$uuid] ) ? $results[$uuid] : array( 'type' => 'expired', 'answer' => null, 'error' => 'no result', 'status' => 0 );
            if ( $item['type'] === 'succeeded' && is_array( $item['answer'] ) )
            {
                $row = $this->record( $batch, $lane, $job, $p, $item['answer'] );
                $stored = expAiRequestHandler::storedAnswer( $item['answer'], $lane->name(), $row );
                $stored['batch_id'] = (int) $batch->attribute( 'id' );
                $budget->settle( (int) $job->attribute( 'id' ), (int) $job->attribute( 'group_id' ), $stored['cost_micros'] );
                $json = json_encode( array( 'answer' => $stored ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
                expQueue::casUpdate( (int) $job->attribute( 'id' ), array( 'state' => $job->attribute( 'state' ) ),
                                     array( 'checkpoint' => (string) $json, 'run_after' => $now, 'cost_actual_micros' => (int) $job->attribute( 'cost_actual_micros' ) + $stored['cost_micros'] ) );
                expQueueEvent::add( $job->attribute( 'id' ), $job->attribute( 'group_id' ), 'log', 'the batch ' . $batch->attribute( 'id' ) . ' answered', array( 'tokens' => $stored['tokens'] ) );
                ++$out['succeeded'];
            }
            elseif ( $item['type'] === 'errored' )
            {
                $this->recordError( $batch, $lane, $job, $p, $item );
                if ( expAiBatchApi::permanentItemError( $item['status'], $item['error'] ) )
                {
                    expQueue::casUpdate( (int) $job->attribute( 'id' ), array( 'state' => $job->attribute( 'state' ) ), array(
                        'state' => 'dead', 'finished' => $now, 'cost_reserved_micros' => 0, 'error_class' => 'batch_errored',
                        'last_error' => expQueueJob::shorten( 'the batch refused the request: ' . $item['error'], 255 ) ) );
                    expQueueEvent::add( $job->attribute( 'id' ), $job->attribute( 'group_id' ), 'dead', 'the batch refused the request: ' . $item['error'] );
                    expQueue::afterFinal( $job );
                    ++$out['errored'];
                }
                else
                {
                    // the next batch takes it again
                    expQueue::casUpdate( (int) $job->attribute( 'id' ), array( 'state' => $job->attribute( 'state' ) ), array(
                        'external_ref' => '', 'run_after' => $now, 'attempts' => (int) $job->attribute( 'attempts' ) + 1,
                        'last_error' => expQueueJob::shorten( 'batch item error: ' . $item['error'], 255 ) ) );
                    expQueueEvent::add( $job->attribute( 'id' ), $job->attribute( 'group_id' ), 'retry', 'the batch item failed, it waits for the next batch: ' . $item['error'] );
                    ++$out['retried'];
                }
            }
            else
            {
                $next = expAiLane::fetch( $lane->get( 'provider' ), $lane->get( 'expired_lane' ) ?: 'standard' );
                if ( $next && $next->usable() && !$next->isBatch() )
                {
                    expAiRequestHandler::moveToLane( $job, $p, $next, 'the batch item ' . $item['type'] );
                    expQueue::casUpdate( (int) $job->attribute( 'id' ), array( 'state' => $job->attribute( 'state' ) ), array( 'run_after' => $now ) );
                    expQueueEvent::add( $job->attribute( 'id' ), $job->attribute( 'group_id' ), 'log', 'the batch item ' . $item['type'] . ': moved to the lane ' . $next->name() );
                    ++$out['moved'];
                }
                else
                {
                    expQueue::casUpdate( (int) $job->attribute( 'id' ), array( 'state' => $job->attribute( 'state' ) ), array( 'external_ref' => '', 'run_after' => $now ) );
                    ++$out['retried'];
                }
            }
        }
        $context->progress( $out['succeeded'] + $out['errored'] + $out['retried'] + $out['moved'], max( 1, count( (array) $rows ) ) );
        return $out;
    }

    /** @return expAiUsageLog the usage row of one succeeded batch request */
    protected function record( expAiBatch $batch, expAiLane $lane, expQueueJob $job, array $p, array $answer )
    {
        $tokens = isset( $answer['tokens'] ) ? (array) $answer['tokens'] : array();
        $n = function ( $k ) use ( $tokens ) { return isset( $tokens[$k] ) ? (int) $tokens[$k] : 0; };
        $provider = expAiConfig::instance()->registry()->getProvider( (string) $batch->attribute( 'provider' ) );
        return expAiUsage::record( array(
            'user_id' => (int) $job->attribute( 'user_id' ), 'node_id' => isset( $p['node_id'] ) ? (int) $p['node_id'] : 0,
            'action' => expAiText::cut( 'queue' . ( !empty( $p['purpose'] ) ? '.' . $p['purpose'] : '' ), 40 ),
            'model' => isset( $answer['model'] ) && $answer['model'] !== '' ? (string) $answer['model'] : (string) $batch->attribute( 'model' ),
            'model_alias' => (string) $batch->attribute( 'model_alias' ), 'provider' => (string) $batch->attribute( 'provider' ),
            'region' => $provider ? (string) $provider['Region'] : '', 'retention' => substr( $lane->retention(), 0, 10 ),
            'input_tokens' => $n( 'input' ), 'output_tokens' => $n( 'output' ), 'cache_write_tokens' => $n( 'cache_write' ),
            'cache_read_tokens' => $n( 'cache_read' ), 'reasoning_tokens' => $n( 'reasoning' ),
            'reported_cost_micros' => isset( $tokens['reported_cost'] ) && is_numeric( $tokens['reported_cost'] ) && $tokens['reported_cost'] > 0
                                      ? (int) round( (float) $tokens['reported_cost'] * 1000000 ) : 0,
            'stop_reason' => isset( $answer['stop_reason'] ) ? (string) $answer['stop_reason'] : '', 'http_status' => 200,
            'latency_ms' => max( 0, ( (int) $batch->attribute( 'ended' ) ?: time() ) - (int) $batch->attribute( 'submitted' ) ) * 1000,
            'tool_mode' => 'none', 'lane' => $lane->name(), 'service_tier' => expAiRequestHandler::serviceTier( $answer ) ?: 'batch',
            'job_uuid' => (string) $job->attribute( 'uuid' ), 'batch_id' => (int) $batch->attribute( 'id' ), 'price_factor' => $lane->factorPercent() ) );
    }

    /** A usage row for a failed batch request (no tokens: the provider bills none). */
    protected function recordError( expAiBatch $batch, expAiLane $lane, expQueueJob $job, array $p, array $item )
    {
        expAiUsage::record( array(
            'user_id' => (int) $job->attribute( 'user_id' ), 'action' => expAiText::cut( 'queue' . ( !empty( $p['purpose'] ) ? '.' . $p['purpose'] : '' ), 40 ),
            'model' => (string) $batch->attribute( 'model' ), 'model_alias' => (string) $batch->attribute( 'model_alias' ), 'provider' => (string) $batch->attribute( 'provider' ),
            'http_status' => (int) $item['status'], 'error_class' => 'batch_errored', 'error_message' => expAiText::cut( (string) $item['error'], 255 ),
            'lane' => $lane->name(), 'service_tier' => 'batch', 'job_uuid' => (string) $job->attribute( 'uuid' ), 'batch_id' => (int) $batch->attribute( 'id' ),
            'price_factor' => $lane->factorPercent(), 'tool_mode' => 'none' ) );
    }

    /** Cancels the batch at the provider when every job of it was cancelled. */
    protected function cancelWhenAbandoned( expAiBatch $batch, expAiBatchApi $api, expQueueContext $context )
    {
        $counts = expQueue::countBy( 'state', array( 'external_ref' => $batch->ref() ) );
        $total = array_sum( $counts );
        if ( $total === 0 || ( isset( $counts['cancelled'] ) ? (int) $counts['cancelled'] : 0 ) < $total || $batch->attribute( 'state' ) === 'canceling' )
            return;
        try
        {
            $api->cancel( $batch );
            $batch->touch( array( 'state' => 'canceling' ) );
            $context->log( 'every request of batch ' . $batch->attribute( 'id' ) . ' was cancelled: the batch is cancelled at the provider' );
        }
        catch ( expAiException $e )
        {
            $context->log( 'the batch could not be cancelled: ' . $e->detail() );
        }
    }
}
