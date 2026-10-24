<?php
/**
 * File containing the expAiTranslationRunHandler class.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The job type expai.translation.run: one translation of the translation sync by a machine translation service
 * (doc/translation-providers.md; the llm service queues expai.request jobs instead). Queued by
 * expAiTranslationSync::queueRun() with the applier's parameters and the service's id.
 *
 *   1. a stored answer (checkpoint) is applied at once: a rerun after a crash never pays twice
 *   2. the master switch holds the job while expai is off (expAiGate)
 *   3. the budget, again: other requests may have cost more than they reserved (held, resumed by the cron part)
 *   4. the service must exist and be configured; the data policy is checked again for the object
 *   5. the plan is made again exactly as the applier makes it, and a patch over a translation edited meanwhile is
 *      refused before anything is sent
 *   6. the translation through expAiTranslationRecorder (gate, cost limits, usage row, audit), the answer stored in
 *      the checkpoint, the reservation settled
 *   7. the answer is written into a draft by the translation sync's applier (expAiTranslationSync::applyAnswer())
 *
 * A busy service, a server error or a timeout is retried (with the service's retry-after); the requests of the
 * translation that were answered before are kept in the checkpoint and not sent again (a timeout after the request
 * was sent may still have been billed by the service). A refused key, a used-up
 * quota, an unsupported pair or a text whose placeholders came back wrong fails at once, and the translation's state
 * becomes failed with the reason. Nothing falls back to another service at this point.
 */
class expAiTranslationRunHandler extends expQueueHandlerBase
{
    public function validate( array $payload )
    {
        if ( empty( $payload['provider'] ) || empty( $payload['apply_params']['object_id'] ) || empty( $payload['apply_params']['target'] )
             || empty( $payload['apply_params']['mode'] ) )
            return 'provider and apply_params (object_id, target, mode) are required';
        return null;
    }

    public function describe( expQueueJob $job )
    {
        $p = $job->payloadArray();
        return isset( $p['label'] ) && $p['label'] !== '' ? (string) $p['label']
             : 'Translation by ' . ( isset( $p['provider'] ) ? $p['provider'] : '?' ) . ' of object ' . ( isset( $p['apply_params']['object_id'] ) ? (int) $p['apply_params']['object_id'] : 0 );
    }

    /** @return expAiConfig */
    protected function config()
    {
        return expAiConfig::instance();
    }

    /** @return expAiTranslationSync */
    protected function sync( expAiConfig $config )
    {
        return new expAiTranslationSync( $config );
    }

    /** @return expAiBudget */
    protected function budget( expAiConfig $config )
    {
        return new expAiBudget( $config );
    }

    /** @return expAiTranslationRecorder */
    protected function recorder( expAiTranslationProvider $service, array $context )
    {
        return new expAiTranslationRecorder( $service, $context );
    }

    /** @return eZContentObject|null */
    protected function object( $objectID )
    {
        $object = expAiObjects::fetch( (int) $objectID );
        return $object instanceof eZContentObject ? $object : null;
    }

    public function run( expQueueJob $job, expQueueContext $context )
    {
        $p = $job->payloadArray();
        $params = (array) $p['apply_params'];
        $config = $this->config();
        $sync = $this->sync( $config );
        $checkpoint = $context->checkpoint();
        if ( is_array( $checkpoint ) && isset( $checkpoint['answer'] ) )
            return $this->apply( $job, $context, $sync, $checkpoint['answer'], $params );

        // the master switch (#370 M2): held, with the reason, until expai is switched on and the job is released
        if ( class_exists( 'expAiGate' ) && ( $off = expAiGate::switchedOff( $config, isset( $p['siteaccess'] ) ? (string) $p['siteaccess'] : null ) ) !== '' )
            return expQueueResult::hold( expAiGate::HELD_PREFIX . $off );

        $budget = $this->budget( $config );
        $refusal = $budget->refusal( (int) $job->attribute( 'cost_reserved_micros' ), (int) $job->attribute( 'group_id' ), (int) $job->attribute( 'id' ) );
        if ( $refusal !== null )
            return expQueueResult::hold( 'budget: ' . $refusal['message'] );

        $object = $this->object( (int) $params['object_id'] );
        if ( !$object )
            throw new expQueuePermanentException( 'the object is gone', 'not_found' );
        $target = (string) $params['target'];
        $fail = function ( $message, $class ) use ( $object, $target, $sync )
        {
            $sync::saveState( (int) $object->attribute( 'id' ), $target, array( 'state' => 'failed', 'error' => $message ) );
            return expQueueResult::fail( $message, $class );
        };
        $service = $sync->providers()->get( (string) $p['provider'] );
        if ( !$service )
            return $fail( 'the translation service ' . $p['provider'] . ' is not available: ' . $sync->providers()->problem( (string) $p['provider'] ), 'provider' );
        if ( !$service->isConfigured() )
            return $fail( 'the translation service ' . $p['provider'] . ' is not configured: ' . $service->unavailableReason(), 'provider' );
        $policy = $service->dataPolicy();
        if ( $policy && !$policy->allowsObject( $object ) )
            return $fail( 'the data policy refuses object ' . (int) $object->attribute( 'id' ) . ' for ' . $service->id() . ': ' . $policy->describe( $policy->requirement( $object ) ), 'data_policy' );

        $plan = $sync->planForJob( $object, $params );
        if ( !$plan['units'] )
            return $fail( 'nothing to translate any more; check again', 'answer' );
        if ( $sync::changedSincePlan( $object, $plan, $params ) )
            return $fail( 'the translation was changed after the patch was planned; check again', 'conflict' );
        $request = $sync->translationRequest( $plan, (string) $params['mode'] );
        $request->objectIDs = array( (int) $object->attribute( 'id' ) );
        $request->nodeID = isset( $p['node_id'] ) ? (int) $p['node_id'] : 0;
        $request->userID = (int) $job->attribute( 'user_id' );
        $request->jobUUID = (string) $job->attribute( 'uuid' );
        $request->siteAccess = isset( $p['siteaccess'] ) ? (string) $p['siteaccess'] : '';
        // an earlier attempt failed after some requests of the translation: those are not sent and paid again
        if ( is_array( $checkpoint ) && isset( $checkpoint['partial'] ) )
            $request->resume = (array) $checkpoint['partial'];
        $recorder = $this->recorder( $service, array( 'user_id' => (int) $job->attribute( 'user_id' ), 'node_id' => $request->nodeID,
                                                      'action' => expAiText::cut( 'queue.' . ( isset( $p['purpose'] ) ? (string) $p['purpose'] : 'translate' ), 40 ),
                                                      'job_uuid' => (string) $job->attribute( 'uuid' ) )
                                                + ( $request->siteAccess !== '' ? array( 'siteaccess' => $request->siteAccess ) : array() ) );
        $context->heartbeat();
        try
        {
            $result = $recorder->translate( $request );
        }
        catch ( expAiVetoException $e )
        {
            return expQueueResult::fail( 'refused: ' . strtr( $e->getMessage(), $e->arguments() ), 'veto' );
        }
        catch ( expAiException $e )
        {
            if ( $e instanceof expAiTranslationException && !empty( $e->partial ) && expAiTranslationException::isTransient( $e ) )
                $context->saveCheckpoint( array( 'partial' => array( 'key' => $e->partial['key'], 'texts' => $e->partial['texts'] ) ) );
            return $this->failure( $e, $fail, $service );
        }
        if ( !$result->complete() )
        {
            // what was sent was billed: the cost counts, although no draft is written
            $budget->settle( (int) $job->attribute( 'id' ), (int) $job->attribute( 'group_id' ), (int) $result->costMicros );
            $context->cost( (int) $job->attribute( 'cost_actual_micros' ) + (int) $result->costMicros );
            $ids = array_keys( $result->errors );
            return $fail( $service->id() . ': the placeholders of ' . implode( ', ', $ids ) . ' came back wrong (' . implode( '; ', array_unique( $result->errors ) ) . ')', 'markup' );
        }
        $answer = $result->answer() + array( 'provider' => $service->id(), 'billed_chars' => (int) $result->billedChars, 'cost_micros' => (int) $result->costMicros,
                                             'usage_log_id' => $recorder->last ? (int) $recorder->last->attribute( 'id' ) : 0 );
        $context->saveCheckpoint( array( 'answer' => $answer ) );
        $budget->settle( (int) $job->attribute( 'id' ), (int) $job->attribute( 'group_id' ), (int) $result->costMicros );
        $context->cost( (int) $job->attribute( 'cost_actual_micros' ) + (int) $result->costMicros );
        return $this->apply( $job, $context, $sync, $answer, $params );
    }

    /**
     * A failed translation: held (a limit), retried (transient) or failed with the reason.
     * @param callable $fail function( message, class ): expQueueResult (stores the state failed)
     * @return expQueueResult
     */
    protected function failure( expAiException $e, $fail, expAiTranslationProvider $service )
    {
        $message = strtr( $e->getMessage(), $e->arguments() );
        $detail = $e->detail();
        if ( $e instanceof expAiLimitException )
            return expQueueResult::hold( 'budget: ' . $message );
        $status = (int) $e->getCode();
        $class = $status ? 'http_' . $status : 'transport';
        if ( expAiTranslationException::isTransient( $e ) )
            return expQueueResult::retry( $service->id() . ': ' . ( $detail !== '' ? $detail : $message ), $e instanceof expAiTranslationException ? (int) $e->retryAfter : 0, $class );
        return $fail( $service->id() . ': ' . $message . ( $detail !== '' ? ' (' . $detail . ')' : '' ), $status ? $class : 'refused' );
    }

    /**
     * Writes a stored answer into the draft (the translation sync's applier).
     * @return array the job's result
     */
    protected function apply( expQueueJob $job, expQueueContext $context, expAiTranslationSync $sync, array $answer, array $params )
    {
        $context->throwIfCancelled();
        return array( 'provider' => isset( $answer['provider'] ) ? (string) $answer['provider'] : '', 'model' => isset( $answer['model'] ) ? (string) $answer['model'] : '',
                      'billed_chars' => isset( $answer['billed_chars'] ) ? (int) $answer['billed_chars'] : 0,
                      'cost_micros' => isset( $answer['cost_micros'] ) ? (int) $answer['cost_micros'] : 0,
                      'usage_log_id' => isset( $answer['usage_log_id'] ) ? (int) $answer['usage_log_id'] : 0,
                      'applied' => $sync->applyAnswer( $job, $answer, $params ) );
    }
}
