<?php
/**
 * File containing the expAiThreadItemHandler class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The job type expai.thread.item: one item of an approved plan as a child run of the thread (doc/parallel-runs.md),
 * side by side with the other items. The worker runs it as the thread's owner, in the child run's own conversation,
 * in write mode with staged review: the same steps as a turn (expAiAssistant::step(): one model call, the tools as the
 * user), every step an event of the thread with the run's id.
 *
 * An item that waits for other items (depends_on) is held by the queue behind its last dependency; while another one
 * still works it is deferred; it starts with what they staged as its source. When a dependency did not finish, the
 * item is not run. Stop cancels the plan's job group: honored between two steps.
 *
 * Payload: thread_id, run_id, plan_id, item (index), node_id, message, options, siteaccess.
 */
class expAiThreadItemHandler extends expQueueHandlerBase
{
    public function validate( array $payload )
    {
        if ( empty( $payload['thread_id'] ) || empty( $payload['run_id'] ) || empty( $payload['node_id'] ) )
            return 'thread_id, run_id and node_id are required';
        return null;
    }

    public function describe( expQueueJob $job )
    {
        $p = $job->payloadArray();
        return 'Thread ' . ( isset( $p['thread_id'] ) ? (int) $p['thread_id'] : 0 ) . ', plan item ' . ( isset( $p['item'] ) ? (int) $p['item'] + 1 : 0 );
    }

    public function run( expQueueJob $job, expQueueContext $context )
    {
        $p = $job->payloadArray();
        $thread = expAiThread::fetchByID( (int) $p['thread_id'] );
        $run = expAiThreadRun::fetchByID( (int) $p['run_id'] );
        if ( !$thread || !$run || (int) $run->attribute( 'thread_id' ) !== (int) $thread->attribute( 'id' ) )
            throw new expQueuePermanentException( 'the thread or its run is gone', 'thread' );
        if ( !$run->isActive() )
            return expQueueResult::cancelled( 'the run ended ' . $run->attribute( 'state' ) );
        $user = eZUser::currentUser();
        if ( !$thread->isOwner( $user->attribute( 'contentobject_id' ) ) || !expAiServerFunctions::hasPolicy( 'write' ) || !expAiThreadAccess::hasVector( $user ) )
        {
            expAiThreadFanout::finishRun( $thread, $run, 'failed', ezpI18n::tr( 'extension/expai/vector', 'The owner of this thread no longer has access to it.' ) );
            throw new expQueuePermanentException( 'the owner may not run the thread any more', 'access' );
        }
        $conversation = eZPersistentObject::fetchObject( expAiConversation::definition(), null, array( 'id' => (int) $run->attribute( 'conversation_id' ) ) );
        if ( !$conversation instanceof expAiConversation )
        {
            expAiThreadFanout::finishRun( $thread, $run, 'failed', 'the run has no conversation' );
            throw new expQueuePermanentException( 'the run has no conversation', 'thread' );
        }
        $config = expAiConfig::instance();
        if ( class_exists( 'expAiGate' ) && ( $off = expAiGate::switchedOff( $config, isset( $p['siteaccess'] ) ? (string) $p['siteaccess'] : null ) ) !== '' )
        {
            $thread->addEvent( 'status', array( 'state' => 'queued', 'run_id' => (int) $run->attribute( 'id' ), 'held' => ezpI18n::tr( 'extension/expai/error', (string) $off ) ),
                               (int) $run->attribute( 'id' ) );
            $thread->storeActivity();
            return expQueueResult::hold( expAiGate::HELD_PREFIX . $off );
        }
        $plan = expAiPlan::fetchByID( isset( $p['plan_id'] ) ? (int) $p['plan_id'] : (int) $thread->attribute( 'plan_id' ) );
        $source = '';
        if ( $plan )
        {
            list( $active, $broken ) = expAiThreadFanout::blockers( $plan, $run );
            if ( $broken )
            {
                expAiThreadFanout::finishRun( $thread, $run, 'stopped', ezpI18n::tr( 'extension/expai/vector', 'Not run: the item it waits for (%run) did not finish.', null,
                                                                                     array( '%run' => $broken[0]->shortName() ) ) );
                return expQueueResult::cancelled( 'a dependency did not finish' );
            }
            if ( $active )
            {
                // another item it waits for still works: deferred (woken when that one is done)
                if ( $run->attribute( 'state' ) !== 'waiting' )
                {
                    $run->setAttribute( 'state', 'waiting' );
                    $run->store();
                }
                return expQueueResult::defer( 30, 'waits for ' . $active[0]->shortName() );
            }
            $source = expAiThreadFanout::dependencyContext( $plan, $run );
        }
        $options = isset( $p['options'] ) && is_array( $p['options'] ) ? $p['options'] : expAiPlanner::runOptions( $thread );
        $checkpoint = $context->checkpoint();
        $message = is_array( $checkpoint ) && !empty( $checkpoint['sent'] ) ? '' : trim( (string) $p['message'] . ( $source !== '' ? "\n\n" . $source : '' ) );
        if ( $run->attribute( 'state' ) !== 'running' )
        {
            $run->setAttribute( 'state', 'running' );
            if ( !(int) $run->attribute( 'started' ) )
                $run->setAttribute( 'started', time() );
            $run->setAttribute( 'modified', time() );
            $run->store();
            $thread->addEvent( 'run', array( 'run_id' => (int) $run->attribute( 'id' ), 'item' => (int) $run->attribute( 'plan_item' ), 'state' => 'running' ),
                               (int) $run->attribute( 'id' ) );
            $thread->storeActivity();
        }
        $max = $config->int( 'AssistantSettings', 'MaxToolIterations', 10, 1, 50 ) + 2;
        $steps = 0;
        $status = 'continue';
        while ( $status === 'continue' && $steps < $max )
        {
            if ( $context->isCancelled() )
            {
                expAiThreadFanout::finishRun( $thread, $run, 'stopped' );
                throw new expQueueCancelledException( 'stopped by the editor' );
            }
            $assistant = ( new expAiAssistant( $config ) )->useConversation( $conversation, $options );
            $sending = $message !== '';
            try
            {
                if ( $message !== '' )
                {
                    $context->saveCheckpoint( array( 'sent' => 1 ) );
                    $result = $assistant->step( (int) $p['node_id'], $message );
                    $message = '';
                }
                else
                    $result = $assistant->step( (int) $p['node_id'], null );
            }
            catch ( expAiVetoException $e )
            {
                $reason = strtr( $e->getMessage(), $e->arguments() );
                expAiThreadFanout::finishRun( $thread, $run, 'failed', $reason );
                return expQueueResult::fail( 'refused: ' . $reason, 'veto' );
            }
            catch ( expAiException $e )
            {
                // another step of the run's conversation holds it: nothing was sent or written, the item runs again
                // shortly with its message (the checkpoint goes back to "not sent")
                if ( expAiConversationBusyException::retryable( $e ) )
                {
                    if ( $sending )
                        $context->saveCheckpoint( array( 'sent' => 0 ) );
                    return expQueueResult::defer( expAiConversationBusyException::RETRY_SECONDS, 'the conversation is busy with another step' );
                }
                $reason = strtr( $e->getMessage(), $e->arguments() );
                if ( strpos( (string) $e->detail(), 'master switch' ) === 0 )
                    return expQueueResult::hold( expAiGate::HELD_PREFIX . $reason );
                expAiThreadFanout::finishRun( $thread, $run, 'failed', $e->translatedMessage() );
                throw new expQueuePermanentException( $reason, 'model' );
            }
            $steps++;
            $status = isset( $result['status'] ) ? (string) $result['status'] : 'done';
            $last = $result['steps'] ? end( $result['steps'] ) : null;
            $fresh = expAiThreadRun::fetchByID( (int) $run->attribute( 'id' ) ) ?: $run;
            $fresh->setAttribute( 'progress_done', min( max( (int) $fresh->attribute( 'progress_total' ), $steps ), $steps ) );
            $fresh->setAttribute( 'modified', time() );
            $fresh->store();
            $run = $fresh;
            $context->progress( $steps, (int) $run->attribute( 'progress_total' ) ?: null, $last && isset( $last['text'] ) ? (string) $last['text'] : '' );
            $conversation = eZPersistentObject::fetchObject( expAiConversation::definition(), null, array( 'id' => (int) $run->attribute( 'conversation_id' ) ) ) ?: $conversation;
        }
        expAiThreadFanout::finishRun( $thread, $run, 'done' );
        return array( 'steps' => $steps, 'status' => $status, 'run_id' => (int) $run->attribute( 'id' ) );
    }
}
