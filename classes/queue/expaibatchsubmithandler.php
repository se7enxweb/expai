<?php
/**
 * File containing the expAiBatchSubmitHandler class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The job type expai.batch.submit (class system): collects the waiting expai.request jobs of one model and batch
 * lane, at most MaxRequestsPerBatch, submits them as one provider batch (custom_id = job uuid), records the batch in
 * expai_batch, marks the jobs (external_ref expai-batch:<id>, asleep until the poll wakes them) and queues
 * expai.batch.poll. More waiting jobs than fit: another submit job is queued at once.
 *
 * Crash safety: the jobs are taken with a compare-and-set (only a job that waits and belongs to no batch), the
 * provider's batch id is written to the checkpoint right after the submit, so a rerun records that batch instead of
 * submitting the requests again; when the submit fails, the jobs are released.
 */
class expAiBatchSubmitHandler extends expQueueHandlerBase
{
    public function validate( array $payload )
    {
        return empty( $payload['alias'] ) || empty( $payload['lane'] ) ? 'alias and lane are required' : null;
    }

    public function describe( expQueueJob $job )
    {
        $p = $job->payloadArray();
        return 'Submit a batch of ' . ( isset( $p['alias'] ) ? $p['alias'] : '' ) . ' on ' . ( isset( $p['lane'] ) ? $p['lane'] : '' );
    }

    public function run( expQueueJob $job, expQueueContext $context )
    {
        $p = $job->payloadArray();
        $config = expAiConfig::instance();
        $model = $config->registry()->getModel( (string) $p['alias'] );
        if ( !$model )
            throw new expQueuePermanentException( 'the model ' . $p['alias'] . ' is not in the provider registry', 'model' );
        $lane = expAiLane::fetch( $model['provider'], (string) $p['lane'], $config );
        if ( !$lane || !$lane->isBatch() || !$lane->usable() )
            throw new expQueuePermanentException( 'the lane ' . $p['lane'] . ' is not a usable batch lane', 'lane' );
        // the master switch (#370 M2): no batch is sent while expai is off; the job is held with the reason
        if ( class_exists( 'expAiGate' ) && ( $off = expAiGate::switchedOff( $config ) ) !== '' )
            return expQueueResult::hold( expAiGate::HELD_PREFIX . $off );
        $mark = 'expai-submit:' . $job->attribute( 'uuid' );
        $db = eZDB::instance();

        // a rerun after the provider accepted the batch: record it, do not submit again
        $checkpoint = $context->checkpoint();
        if ( is_array( $checkpoint ) && !empty( $checkpoint['remote_id'] ) )
            return $this->record( $job, $context, $lane, $p, $checkpoint, $mark );

        // the jobs taken by an earlier attempt of this submit job, then new ones
        $taken = $this->taken( $mark );
        $max = (int) $lane->get( 'max_per_batch' );
        if ( count( $taken ) < $max )
        {
            $rows = $db->arrayQuery( "SELECT id, payload FROM expqueue_job WHERE type = '" . expAiQueue::TYPE_REQUEST . "' AND resource_key = '"
                                     . $db->escapeString( $lane->key() ) . "' AND state IN ( 'queued', 'ready', 'retry' ) AND external_ref = '' AND checkpoint = '' ORDER BY priority DESC, id",
                                     array( 'offset' => 0, 'limit' => $max * 3 ) );
            foreach ( is_array( $rows ) ? $rows : array() as $row )
            {
                if ( count( $taken ) >= $max )
                    break;
                $row = array_change_key_case( $row, CASE_LOWER );
                $payload = json_decode( (string) $row['payload'], true );
                if ( !is_array( $payload ) || !isset( $payload['alias'] ) || $payload['alias'] !== $p['alias'] )
                    continue;
                $id = (int) $row['id'];
                foreach ( array( 'retry', 'ready', 'queued' ) as $state )
                    if ( expQueue::casUpdate( $id, array( 'state' => $state, 'external_ref' => '' ), array( 'state' => 'retry', 'external_ref' => $mark,
                                                                                                          'run_after' => time() + 3600 ) ) )
                    {
                        $taken[$id] = $payload;
                        break;
                    }
            }
        }
        if ( !$taken )
            return array( 'submitted' => 0, 'message' => 'no request waits for ' . $lane->key() . ' and ' . $p['alias'] );

        $items = array();
        $uuids = array();
        foreach ( $taken as $id => $payload )
        {
            $uuid = $this->uuidOf( $id );
            $uuids[$id] = $uuid;
            $items[$uuid] = $payload['request'];
        }
        $context->heartbeat();
        try
        {
            $api = expAiBatchApi::forLane( $lane, $p['alias'], $config );
            $submitted = $api->submit( $items );
        }
        catch ( expAiException $e )
        {
            $this->release( $mark, 60 );
            $status = (int) $e->getCode();
            $context->rateLimit( expAiRequestHandler::hints( is_array( $e->rateLimits ) ? $e->rateLimits : array(), $status ) );
            if ( in_array( $status, array( 400, 404, 413, 422 ), true ) )
                throw new expQueuePermanentException( 'the provider refused the batch: ' . $e->detail(), 'http_' . $status );
            throw new expQueueRetryableException( 'the batch could not be submitted: ' . $e->detail(), 0, $status ? 'http_' . $status : 'transport' );
        }
        $submitted['count'] = count( $items );
        $submitted['model'] = $api->modelId();
        $submitted['shape'] = $api->shape();
        $context->saveCheckpoint( $submitted );
        return $this->record( $job, $context, $lane, $p, $submitted, $mark );
    }

    /** @return array job id => payload of the jobs a mark holds */
    protected function taken( $mark )
    {
        $db = eZDB::instance();
        $rows = $db->arrayQuery( "SELECT id, payload FROM expqueue_job WHERE external_ref = '" . $db->escapeString( $mark ) . "' ORDER BY id" );
        $out = array();
        foreach ( is_array( $rows ) ? $rows : array() as $row )
        {
            $row = array_change_key_case( $row, CASE_LOWER );
            $out[(int) $row['id']] = json_decode( (string) $row['payload'], true );
        }
        return $out;
    }

    /** @return string */
    protected function uuidOf( $id )
    {
        $rows = eZDB::instance()->arrayQuery( 'SELECT uuid FROM expqueue_job WHERE id = ' . (int) $id );
        $row = is_array( $rows ) && isset( $rows[0] ) ? array_change_key_case( $rows[0], CASE_LOWER ) : array( 'uuid' => '' );
        return (string) $row['uuid'];
    }

    /** Gives the jobs of a mark back to the queue (they wait for the next submit). */
    protected function release( $mark, $delay )
    {
        $db = eZDB::instance();
        $db->query( "UPDATE expqueue_job SET external_ref = '', run_after = " . ( time() + (int) $delay ) . " WHERE external_ref = '" . $db->escapeString( $mark ) . "'" );
    }

    /**
     * Records a submitted batch, marks its jobs and queues the poll.
     * @return array the result
     */
    protected function record( expQueueJob $job, expQueueContext $context, expAiLane $lane, array $p, array $submitted, $mark )
    {
        $existing = eZPersistentObject::fetchObject( expAiBatch::definition(), null, array( 'remote_id' => (string) $submitted['remote_id'],
                                                                                           'provider' => $lane->get( 'provider' ) ), true );
        $now = time();
        $batch = $existing ?: new expAiBatch( array(
            'created' => $now, 'modified' => $now, 'provider' => $lane->get( 'provider' ), 'lane' => $lane->name(), 'model_alias' => (string) $p['alias'],
            'model' => isset( $submitted['model'] ) ? (string) $submitted['model'] : '', 'batch_api' => (string) $lane->get( 'batch_api' ),
            'remote_id' => (string) $submitted['remote_id'], 'input_ref' => isset( $submitted['input_ref'] ) ? (string) $submitted['input_ref'] : '',
            'state' => isset( $submitted['state'] ) ? (string) $submitted['state'] : 'submitted',
            'remote_status' => isset( $submitted['remote_status'] ) ? substr( (string) $submitted['remote_status'], 0, 40 ) : '',
            'request_count' => isset( $submitted['count'] ) ? (int) $submitted['count'] : 0, 'submitted' => $now,
            'expires_at' => !empty( $submitted['expires_at'] ) ? (int) $submitted['expires_at'] : $now + (int) $lane->get( 'max_latency' ),
            'submit_job_uuid' => (string) $job->attribute( 'uuid' ), 'user_id' => (int) $job->attribute( 'user_id' ), 'retention' => $lane->retention() ) );
        if ( !$existing )
            $batch->store();
        $db = eZDB::instance();
        // asleep until the poll stores their result (the bound: the lane's MaxLatency plus an hour)
        $db->query( "UPDATE expqueue_job SET external_ref = '" . $db->escapeString( $batch->ref() ) . "', run_after = "
                    . ( $now + (int) $lane->get( 'max_latency' ) + 3600 ) . " WHERE external_ref = '" . $db->escapeString( $mark ) . "'" );
        expQueue::enqueue( expAiQueue::TYPE_POLL, array( 'batch_id' => (int) $batch->attribute( 'id' ) ), array(
            'idempotency_key' => 'expai.batch.poll:' . (int) $batch->attribute( 'id' ), 'not_before' => (int) $lane->get( 'poll_seconds' ),
            'resource' => $lane->get( 'provider' ) . ':batch-api', 'class' => 'system', 'user_id' => (int) $job->attribute( 'user_id' ),
            'max_attempts' => 20 ) );
        $context->log( 'submitted batch ' . $batch->attribute( 'id' ) . ' with ' . $batch->attribute( 'request_count' ) . ' requests', array( 'remote_id' => $batch->attribute( 'remote_id' ) ) );

        // more waiting requests than one batch takes: the next submit at once
        $rows = $db->arrayQuery( "SELECT COUNT(*) AS n FROM expqueue_job WHERE type = '" . expAiQueue::TYPE_REQUEST . "' AND resource_key = '"
                                 . $db->escapeString( $lane->key() ) . "' AND state IN ( 'queued', 'ready', 'retry' ) AND external_ref = '' AND checkpoint = ''" );
        $left = is_array( $rows ) && isset( $rows[0] ) ? (int) reset( $rows[0] ) : 0;
        if ( $left > 0 )
            expQueue::enqueue( expAiQueue::TYPE_SUBMIT, array( 'alias' => $p['alias'], 'lane' => $lane->name() ), array(
                'idempotency_key' => 'expai.batch.submit:' . $lane->key() . ':' . $p['alias'] . ':after:' . $batch->attribute( 'id' ),
                'resource' => $lane->get( 'provider' ) . ':batch-api', 'class' => 'system', 'user_id' => (int) $job->attribute( 'user_id' ) ) );
        return array( 'batch_id' => (int) $batch->attribute( 'id' ), 'remote_id' => (string) $batch->attribute( 'remote_id' ),
                      'requests' => (int) $batch->attribute( 'request_count' ), 'waiting_after' => $left );
    }
}
