<?php
/**
 * File containing the expAiRequestHandler class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The job type expai.request: one call to a model on a lane (expAiQueue::request() queues it).
 *
 * A sync lane (standard, flex, fast) calls the provider in the worker: budget check (held when it does not fit),
 * data policy check, the call through expAiUsageRecorder (usage row with lane, service tier, job uuid, price factor),
 * the rate-limit headers handed to the job's resource, the answer saved in the checkpoint before anything else, the
 * reservation settled. Busy (429/529/503) and "resource unavailable" answers are retried with the provider's
 * retry-after, or escalated to the lane's FallbackLane; 401/403 pause the resource; a request the provider refuses
 * (400, 404, 413) is not retried.
 *
 * A batch lane leaves the call to expai.batch.submit: the job waits (deferred, without using an attempt) until a
 * submit job has put it into a provider batch (external_ref expai-batch:<id>), and until expai.batch.poll has stored
 * its result in the checkpoint and woken it. An item that expired or was cancelled at the provider moves to the
 * lane's ExpiredLane (standard) and runs there.
 *
 * In both cases the stored answer is applied last (an applier of the producer, or only the job's result), so a rerun
 * after a crash never pays twice.
 */
class expAiRequestHandler extends expQueueHandlerBase
{
    public function validate( array $payload )
    {
        if ( empty( $payload['alias'] ) || empty( $payload['lane'] ) || empty( $payload['request']['messages'] ) )
            return 'alias, lane and request.messages are required';
        return null;
    }

    public function describe( expQueueJob $job )
    {
        $p = $job->payloadArray();
        return trim( ( isset( $p['label'] ) && $p['label'] !== '' ? $p['label'] . ': ' : '' ) . ( isset( $p['alias'] ) ? $p['alias'] : '' )
                     . ' on ' . ( isset( $p['lane'] ) ? $p['lane'] : '' ) . ( !empty( $p['purpose'] ) ? ' (' . $p['purpose'] . ')' : '' ) );
    }

    public function run( expQueueJob $job, expQueueContext $context )
    {
        $p = $job->payloadArray();
        $config = expAiConfig::instance();
        $checkpoint = $context->checkpoint();
        if ( is_array( $checkpoint ) && isset( $checkpoint['answer'] ) )
            return $this->apply( $job, $context, $checkpoint['answer'], $p );

        $lane = expAiLane::fetch( $this->providerOf( $p['alias'], $config ), (string) $p['lane'], $config );
        if ( !$lane || !$lane->usable() )
            throw new expQueuePermanentException( 'the lane ' . $p['lane'] . ' of ' . $p['alias'] . ' is not configured or not enabled', 'lane' );

        // the master switch (#370 M2): held, with the reason, until expai is switched on and the job is released
        if ( class_exists( 'expAiGate' ) && ( $off = expAiGate::switchedOff( $config, isset( $p['siteaccess'] ) ? (string) $p['siteaccess'] : null ) ) !== '' )
            return expQueueResult::hold( expAiGate::HELD_PREFIX . $off );

        if ( $lane->isBatch() )
            return $this->waitForBatch( $job, $context, $lane, $p );

        // the budget, again: other requests may have cost more than they reserved
        $budget = new expAiBudget( $config );
        $refusal = $budget->refusal( (int) $job->attribute( 'cost_reserved_micros' ), (int) $job->attribute( 'group_id' ), (int) $job->attribute( 'id' ) );
        if ( $refusal !== null )
            return expQueueResult::hold( 'budget: ' . $refusal['message'] );
        $this->assertPolicy( $lane, $p );

        $provider = $lane->provider( $p['alias'], $config );
        $recorder = new expAiUsageRecorder( $provider, array(
            'user_id' => (int) $job->attribute( 'user_id' ), 'node_id' => isset( $p['node_id'] ) ? (int) $p['node_id'] : 0,
            'action' => expAiText::cut( 'queue' . ( !empty( $p['purpose'] ) ? '.' . $p['purpose'] : '' ), 40 ),
            'lane' => $lane->name(), 'job_uuid' => (string) $job->attribute( 'uuid' ), 'price_factor' => $lane->factorPercent() )
            + ( !empty( $p['siteaccess'] ) ? array( 'siteaccess' => (string) $p['siteaccess'] ) : array() ) );
        $context->heartbeat();
        try
        {
            $answer = $recorder->send( $p['request'] );
        }
        catch ( expAiVetoException $e )
        {
            // refused by the veto hook (#370 M2): not tried again
            return expQueueResult::fail( 'refused: ' . strtr( $e->getMessage(), $e->arguments() ), 'veto' );
        }
        catch ( expAiException $e )
        {
            self::tag( $recorder->last, $lane, $job, '' );
            return $this->failure( $job, $context, $lane, $p, $e );
        }
        self::tag( $recorder->last, $lane, $job, self::serviceTier( $answer ) );
        $context->rateLimit( self::hints( isset( $answer['rate_limits'] ) ? (array) $answer['rate_limits'] : array(), 200 ) );
        $stored = self::storedAnswer( $answer, $lane->name(), $recorder->last );
        $context->saveCheckpoint( array( 'answer' => $stored ) );
        $budget->settle( (int) $job->attribute( 'id' ), (int) $job->attribute( 'group_id' ), $stored['cost_micros'] );
        $context->cost( (int) $job->attribute( 'cost_actual_micros' ) + $stored['cost_micros'] );
        return $this->apply( $job, $context, $stored, $p );
    }

    /** @return string the provider of a model alias */
    protected function providerOf( $alias, expAiConfig $config )
    {
        $model = $config->registry()->getModel( (string) $alias );
        if ( !$model )
            throw new expQueuePermanentException( 'the model ' . $alias . ' is not in the provider registry', 'model' );
        return $model['provider'];
    }

    /** The data policy of the content of the request, for this lane. */
    protected function assertPolicy( expAiLane $lane, array $p )
    {
        if ( empty( $p['objects'] ) )
            return;
        $policy = $lane->dataPolicy();
        foreach ( (array) $p['objects'] as $objectID )
        {
            $object = expAiObjects::fetch( (int) $objectID );
            if ( !$object instanceof eZContentObject )
                continue;
            if ( !$policy->allowsObject( $object ) )
                throw new expQueuePermanentException( 'the data policy refuses object ' . (int) $objectID . ' on ' . $lane->key() . ': '
                                                      . $policy->describe( $policy->requirement( $object ) ), 'data_policy' );
        }
    }

    /**
     * A failed call: retry, escalate, hold or give up.
     * @return expQueueResult
     */
    protected function failure( expQueueJob $job, expQueueContext $context, expAiLane $lane, array $p, expAiException $e )
    {
        $status = (int) $e->getCode();
        $detail = $e->detail();
        $hints = self::hints( is_array( $e->rateLimits ) ? $e->rateLimits : array(), $status );
        if ( $status )
            $context->rateLimit( $hints );
        if ( strpos( $detail, 'limit ' ) === 0 )
            return expQueueResult::hold( 'budget: ' . strtr( $e->getMessage(), $e->arguments() ) );
        $busy = in_array( $status, array( 429, 503, 529 ), true );
        $fallback = $lane->get( 'fallback' );
        if ( $busy && $fallback !== '' && ( $lane->name() !== 'flex' || $lane->get( 'on_unavailable' ) === 'escalate' ) )
        {
            $next = expAiLane::fetch( $lane->get( 'provider' ), $fallback );
            if ( $next && $next->usable() && !$next->isBatch() )
            {
                $this->moveToLane( $job, $p, $next, 'the lane ' . $lane->name() . ' was busy (HTTP ' . $status . ')' );
                $context->log( 'moved to the lane ' . $next->name() . ' after HTTP ' . $status . ' on ' . $lane->name() );
                return expQueueResult::defer( 1, 'escalated to the lane ' . $next->name() );
            }
        }
        if ( $busy || $status >= 500 || $status === 408 || $status === 0 )
            return expQueueResult::retry( $lane->key() . ': ' . $detail, isset( $hints['retry_after'] ) ? (int) $hints['retry_after'] : 0, $status ? 'http_' . $status : 'transport' );
        if ( $status === 401 || $status === 403 || $status === 402 )
            return expQueueResult::retry( $lane->key() . ': ' . $detail, 300, 'http_' . $status );
        return expQueueResult::fail( $lane->key() . ': ' . $detail, $status ? 'http_' . $status : 'refused' );
    }

    /**
     * A long text for an SQL statement: a bound variable where the database binds them (Oracle: a string literal
     * ends at 4000 bytes, a bound value is a CLOB), the quoted literal elsewhere.
     * @param eZDBInterface|object $db
     * @return string the SQL of the value
     */
    public static function textValue( $db, $text, $name )
    {
        $text = (string) $text;
        if ( method_exists( $db, 'bindingType' ) && $db->bindingType() != eZDBInterface::BINDING_NO
             && ( method_exists( $db, 'countStringSize' ) ? $db->countStringSize( $text ) : strlen( $text ) ) > 2000 )
            return $db->bindVariable( $text, array( 'name' => (string) $name ) );
        return "'" . $db->escapeString( $text ) . "'";
    }

    /** Moves a job to another lane (payload, lane and resource), e.g. after its batch expired. */
    public static function moveToLane( expQueueJob $job, array $p, expAiLane $lane, $why )
    {
        $p['lane'] = $lane->name();
        $p['moved'][] = array( 'to' => $lane->name(), 'why' => expAiText::cut( (string) $why, 120 ), 'at' => time() );
        $json = json_encode( $p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $db = eZDB::instance();
        $db->query( "UPDATE expqueue_job SET payload = " . self::textValue( $db, $json, 'payload' ) . ", lane = '" . $db->escapeString( $lane->name() )
                    . "', resource_key = '" . $db->escapeString( $lane->key() ) . "', external_ref = '' WHERE id = " . (int) $job->attribute( 'id' ) );
        $job->setAttribute( 'payload', $json );
        $job->setAttribute( 'lane', $lane->name() );
        $job->setAttribute( 'resource_key', $lane->key() );
        $job->setAttribute( 'external_ref', '' );
    }

    /**
     * A batch lane: wait until a submit job takes the request and the poll job stores its result.
     * @return expQueueResult
     */
    protected function waitForBatch( expQueueJob $job, expQueueContext $context, expAiLane $lane, array $p )
    {
        $ref = (string) $job->attribute( 'external_ref' );
        if ( strpos( $ref, 'expai-submit:' ) === 0 )
            return expQueueResult::defer( 60, 'a submit job is taking it into a batch' );
        if ( $ref !== '' )
        {
            $batch = expAiBatch::fromRef( $ref );
            if ( $batch && $batch->isOpen() )
                return expQueueResult::defer( $lane->get( 'poll_seconds' ), 'in the batch ' . $batch->attribute( 'id' ) . ' (' . $batch->attribute( 'state' ) . ')' );
            // the batch ended without a result for this request (or is gone): run it on the expired lane
            $next = expAiLane::fetch( $lane->get( 'provider' ), $lane->get( 'expired_lane' ) ?: 'standard' );
            if ( !$next || !$next->usable() || $next->isBatch() )
                throw new expQueuePermanentException( 'the batch ended without a result and the lane ' . $lane->get( 'expired_lane' ) . ' cannot take it', 'batch_lost' );
            self::moveToLane( $job, $p, $next, 'the batch ended without a result' );
            return expQueueResult::defer( 1, 'moved to the lane ' . $next->name() );
        }
        // a submit job collects the waiting requests of this model and lane after BatchCollectSeconds
        $this->assertPolicy( $lane, $p );
        $collect = (int) $lane->get( 'collect_seconds' );
        $bucket = $collect > 0 ? (int) floor( time() / $collect ) : time();
        $submitOptions = array( 'idempotency_key' => 'expai.batch.submit:' . $lane->key() . ':' . $p['alias'] . ':' . $bucket,
                                'not_before' => max( 1, $collect ), 'resource' => $lane->get( 'provider' ) . ':batch-api', 'class' => 'system',
                                'user_id' => (int) $job->attribute( 'user_id' ) );
        $submit = expQueue::enqueue( expAiQueue::TYPE_SUBMIT, array( 'alias' => $p['alias'], 'lane' => $lane->name() ), $submitOptions );
        // the submit job of this period ran already (a request that came back, e.g. after a failed batch item)
        if ( !empty( $submit->duplicate ) && $submit->isFinal() )
            expQueue::enqueue( expAiQueue::TYPE_SUBMIT, array( 'alias' => $p['alias'], 'lane' => $lane->name() ),
                               array( 'idempotency_key' => $submitOptions['idempotency_key'] . ':' . $job->attribute( 'uuid' ) . ':' . (int) $job->attribute( 'attempts' ) ) + $submitOptions );
        return expQueueResult::defer( $collect + max( 30, $lane->get( 'poll_seconds' ) ), 'waits for a batch of ' . $lane->key() );
    }

    /**
     * The checkpointed answer: text, value, tokens and cost (never the request, never the full provider answer).
     * An answer cut off at the token limit (stop_reason max_tokens) has no value: the JSON in its text is cut, and a
     * complete object inside it (e.g. the first element of a list) is not the answer. Appliers then report the cut
     * instead of a missing part.
     * @return array
     */
    public static function storedAnswer( array $answer, $lane, $usageRow = null )
    {
        $text = '';
        foreach ( isset( $answer['content'] ) ? (array) $answer['content'] : array() as $block )
            if ( is_array( $block ) && isset( $block['type'], $block['text'] ) && $block['type'] === 'text' )
                $text .= $block['text'];
        $max = (int) expAiConfig::instance()->int( 'QueueSettings', 'MaxStoredAnswerBytes', 200000, 1000 );
        $stopReason = isset( $answer['stop_reason'] ) ? (string) $answer['stop_reason'] : '';
        $value = $stopReason === 'max_tokens' ? null : expAiText::extractJson( expAiText::stripReasoning( $text ) );
        $cost = $usageRow ? $usageRow->cost() : null;
        return array(
            // mb_strcut: a cut inside a multibyte character would make the stored JSON drop the whole text
            'text' => strlen( $text ) > $max ? mb_strcut( $text, 0, $max, 'UTF-8' ) : $text,
            'value' => is_array( $value ) ? $value : null,
            'stop_reason' => $stopReason,
            'model' => isset( $answer['model'] ) ? (string) $answer['model'] : '',
            'tokens' => isset( $answer['tokens'] ) ? (array) $answer['tokens'] : array(),
            'lane' => (string) $lane,
            'service_tier' => self::serviceTier( $answer ),
            'usage_log_id' => $usageRow ? (int) $usageRow->attribute( 'id' ) : 0,
            'cost_micros' => $cost !== null ? expAiBudget::micros( $cost ) : 0,
        );
    }

    /** Adds the lane, the tier, the job and the billed price factor to the usage row of a request. */
    public static function tag( $row, expAiLane $lane, expQueueJob $job, $tier )
    {
        if ( !$row instanceof expAiUsageLog )
            return;
        $row->setAttribute( 'lane', $lane->name() );
        $row->setAttribute( 'service_tier', substr( (string) $tier, 0, 20 ) );
        $row->setAttribute( 'job_uuid', (string) $job->attribute( 'uuid' ) );
        $row->setAttribute( 'price_factor', $lane->billedFactor( $tier ) );
        $row->store();
    }

    /** @return string what the provider reported about the tier that served the request ('' when nothing) */
    public static function serviceTier( array $answer )
    {
        if ( !empty( $answer['service_tier'] ) && is_string( $answer['service_tier'] ) )
            return substr( $answer['service_tier'], 0, 20 );
        $usage = isset( $answer['usage'] ) && is_array( $answer['usage'] ) ? $answer['usage'] : array();
        if ( isset( $usage['speed'] ) && is_string( $usage['speed'] ) && $usage['speed'] === 'fast' )
            return 'fast';
        if ( isset( $usage['service_tier'] ) && is_string( $usage['service_tier'] ) )
            return substr( $usage['service_tier'], 0, 20 );
        return '';
    }

    /**
     * The rate-limit hints for expqueue of kept response headers (Anthropic, OpenAI-style, retry-after).
     * @return array status, retry_after, remaining_requests, remaining_tokens, reset_at
     */
    public static function hints( array $headers, $status )
    {
        $h = array_change_key_case( $headers, CASE_LOWER );
        $hints = array( 'status' => (int) $status );
        if ( isset( $h['retry-after'] ) && is_numeric( $h['retry-after'] ) )
            $hints['retry_after'] = (int) ceil( (float) $h['retry-after'] );
        foreach ( array( 'anthropic-ratelimit-requests-remaining', 'x-ratelimit-remaining-requests' ) as $name )
            if ( isset( $h[$name] ) && is_numeric( $h[$name] ) )
                $hints['remaining_requests'] = (int) $h[$name];
        foreach ( array( 'anthropic-ratelimit-tokens-remaining', 'anthropic-ratelimit-input-tokens-remaining', 'x-ratelimit-remaining-tokens' ) as $name )
            if ( isset( $h[$name] ) && is_numeric( $h[$name] ) )
                $hints['remaining_tokens'] = (int) $h[$name];
        if ( isset( $h['anthropic-ratelimit-requests-reset'] ) && ( $t = strtotime( $h['anthropic-ratelimit-requests-reset'] ) ) )
            $hints['reset_at'] = $t;
        elseif ( isset( $h['x-ratelimit-reset-requests'] ) && preg_match( '/^(?:(\d+)h)?(?:(\d+)m)?(?:([\d.]+)s)?$/', $h['x-ratelimit-reset-requests'], $m ) )
            $hints['reset_in'] = (int) ceil( ( isset( $m[1] ) ? (int) $m[1] * 3600 : 0 ) + ( isset( $m[2] ) ? (int) $m[2] * 60 : 0 ) + ( isset( $m[3] ) ? (float) $m[3] : 0 ) );
        return $hints;
    }

    /**
     * Applies a stored answer: the producer's applier, then the job's result.
     * @return array the result
     */
    protected function apply( expQueueJob $job, expQueueContext $context, array $answer, array $p )
    {
        $result = array( 'text' => expAiText::cut( (string) $answer['text'], 4000 ), 'value' => $answer['value'], 'stop_reason' => $answer['stop_reason'],
                         'model' => $answer['model'], 'lane' => $answer['lane'], 'service_tier' => $answer['service_tier'], 'tokens' => $answer['tokens'],
                         'cost_micros' => (int) $answer['cost_micros'], 'usage_log_id' => (int) $answer['usage_log_id'] );
        if ( !empty( $answer['batch_id'] ) )
            $result['batch_id'] = (int) $answer['batch_id'];
        if ( !empty( $p['apply'] ) )
        {
            $applier = expAiQueue::applier( (string) $p['apply'] );
            if ( !$applier )
                throw new expQueuePermanentException( 'the applier ' . $p['apply'] . ' is not configured', 'applier' );
            $context->throwIfCancelled();
            $result['applied'] = $applier->apply( $job, $answer, isset( $p['apply_params'] ) ? (array) $p['apply_params'] : array(), $context );
        }
        return $result;
    }
}
