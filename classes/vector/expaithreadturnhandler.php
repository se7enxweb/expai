<?php
/**
 * File containing the expAiThreadTurnHandler class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The job type expai.thread.turn: one turn of a thread (the editor's message and every step until the model answers
 * in words) run by the queue worker instead of the browser. The worker runs it as the job's user (the thread's owner);
 * the steps are the same as in the browser (expAiAssistant::step(): one model call, the tools as the user, the
 * conversation stored before each call), and every step writes the thread's events, so the browser follows it by
 * polling or the event stream.
 *
 * Not expai.request: that job type is one model call without tools; a turn is a loop of calls with tool runs between.
 *
 * Payload: thread_id, run_id, node_id, message ('' to continue an interrupted turn), options (alias, read_only, mode,
 * scope_note, action). A rerun after a crash never sends the message twice: once the conversation holds it, the turn
 * goes on with "continue". Stop (expaivector::stop) cancels the job: honored between two steps.
 */
class expAiThreadTurnHandler extends expQueueHandlerBase
{
    public function validate( array $payload )
    {
        if ( empty( $payload['thread_id'] ) || empty( $payload['node_id'] ) )
            return 'thread_id and node_id are required';
        return null;
    }

    public function describe( expQueueJob $job )
    {
        $p = $job->payloadArray();
        return 'Thread turn ' . ( isset( $p['thread_id'] ) ? (int) $p['thread_id'] : 0 ) . ( !empty( $p['options']['alias'] ) ? ' (' . $p['options']['alias'] . ')' : '' );
    }

    public function run( expQueueJob $job, expQueueContext $context )
    {
        $p = $job->payloadArray();
        $thread = expAiThread::fetchByID( (int) $p['thread_id'] );
        if ( !$thread )
            throw new expQueuePermanentException( 'the thread is gone', 'thread' );
        $user = eZUser::currentUser();
        // the worker runs the job as its user: it must still be the owner, with the policies of the run
        if ( !$thread->isOwner( $user->attribute( 'contentobject_id' ) ) || !expAiServerFunctions::hasPolicy( 'write' )
             || ( $thread->attribute( 'origin' ) !== expAiThread::ORIGIN_PANEL && !expAiThreadAccess::hasVector( $user ) ) )
        {
            self::finish( $thread, 'failed', ezpI18n::tr( 'extension/expai/vector', 'The owner of this thread no longer has access to it.' ) );
            throw new expQueuePermanentException( 'the owner may not run the thread any more', 'access' );
        }
        $conversation = self::conversationOf( $thread, $p );
        if ( !$conversation )
            throw new expQueuePermanentException( 'the thread has no conversation', 'thread' );
        $config = expAiConfig::instance();
        // the master switch (#370 M2): held with the reason until expai is on again (the cron part expai resumes it)
        if ( class_exists( 'expAiGate' ) && ( $off = expAiGate::switchedOff( $config, isset( $p['siteaccess'] ) ? (string) $p['siteaccess'] : null ) ) !== '' )
            return self::hold( $thread, $off );
        $options = isset( $p['options'] ) && is_array( $p['options'] ) ? $p['options'] : array();
        // an approved plan (doc/plan-mode.md): one message per item, each a turn of its own, in this one job
        $messages = isset( $options['messages'] ) && is_array( $options['messages'] ) ? array_values( array_filter( array_map( 'strval', $options['messages'] ), 'strlen' ) ) : array();
        $planID = isset( $options['plan_id'] ) ? (int) $options['plan_id'] : 0;
        unset( $options['messages'], $options['plan_id'] );
        if ( !$messages )
            $messages = array( isset( $p['message'] ) ? (string) $p['message'] : '' );
        $checkpoint = $context->checkpoint();
        $first = is_array( $checkpoint ) && isset( $checkpoint['index'] ) ? (int) $checkpoint['index'] : 0;
        // between the turns of a plan the thread stays "running" (the browser follows it until the last item ends)
        expAiThread::$holdRunning = count( $messages ) > 1;
        try
        {
            $out = $this->turns( $job, $context, $thread, $conversation, $config, $options, $messages, $first, $checkpoint, $planID, $p );
            if ( $out instanceof expQueueResult )
                return $out;
        }
        finally
        {
            expAiThread::$holdRunning = false;
        }
        if ( count( $messages ) > 1 && ( $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ) )
        {
            $fresh->setState( $fresh->restingState() );
            $fresh->touch();
            $fresh->store();
        }
        return $out;
    }

    /** The turns of the job: one per message */
    protected function turns( expQueueJob $job, expQueueContext $context, expAiThread $thread, $conversation, expAiConfig $config, array $options,
                              array $messages, $first, $checkpoint, $planID, array $p )
    {
        $max = $config->int( 'AssistantSettings', 'MaxToolIterations', 10, 1, 50 ) + 2;
        $steps = 0;
        $status = 'done';
        for ( $i = $first; $i < count( $messages ); $i++ )
        {
            $message = $messages[$i];
            // a rerun: the message is in the conversation already
            if ( $i === $first && is_array( $checkpoint ) && !empty( $checkpoint['sent'] ) )
                $message = '';
            $status = 'continue';
            $turnSteps = 0;
            while ( $status === 'continue' && $turnSteps < $max )
            {
                if ( $context->isCancelled() )
                {
                    $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
                    // switched to another model meanwhile (expAiSlowModel): the new lead run is not this job's
                    if ( $fresh && !empty( $p['run_id'] ) && (int) $fresh->attribute( 'lead_run_id' ) !== (int) $p['run_id'] )
                        throw new expQueueCancelledException( 'the turn went on with another model' );
                    self::finish( $fresh, 'stopped' );
                    if ( $planID && $fresh )
                        expAiPlanner::complete( $fresh );
                    throw new expQueueCancelledException( 'stopped by the editor' );
                }
                $assistant = ( new expAiAssistant( $config ) )->useConversation( $conversation, $options );
                $sending = $message !== '';
                try
                {
                    if ( $message !== '' )
                    {
                        $context->saveCheckpoint( array( 'index' => $i, 'sent' => 1 ) );
                        $result = $assistant->step( (int) $p['node_id'], $message );
                        $message = '';
                    }
                    else
                        $result = $assistant->step( (int) $p['node_id'], null );
                }
                catch ( expAiVetoException $e )
                {
                    // refused by the veto hook (#370 M2): the step showed the reason in the thread; not tried again
                    if ( $planID && ( $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ) )
                        expAiPlanner::complete( $fresh );
                    return expQueueResult::fail( 'refused: ' . strtr( $e->getMessage(), $e->arguments() ), 'veto' );
                }
                catch ( expAiException $e )
                {
                    // another step of the conversation runs (a second tab, the panel): nothing was sent or written,
                    // the turn runs again shortly with its message (the checkpoint goes back to "not sent")
                    if ( expAiConversationBusyException::retryable( $e ) )
                    {
                        if ( $sending )
                            $context->saveCheckpoint( array( 'index' => $i, 'sent' => 0 ) );
                        return expQueueResult::defer( expAiConversationBusyException::RETRY_SECONDS, 'the conversation is busy with another step' );
                    }
                    // switched off while the turn ran: held, the thread waits again (the conversation keeps its place)
                    if ( strpos( (string) $e->detail(), 'master switch' ) === 0 )
                        return self::hold( expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ), strtr( $e->getMessage(), $e->arguments() ) );
                    // the step marked the thread failed with the reason; the editor decides whether to try again
                    if ( $planID && ( $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ) )
                        expAiPlanner::complete( $fresh );
                    throw new expQueuePermanentException( strtr( $e->getMessage(), $e->arguments() ), 'model' );
                }
                $steps++;
                $turnSteps++;
                $status = isset( $result['status'] ) ? (string) $result['status'] : 'done';
                $last = $result['steps'] ? end( $result['steps'] ) : null;
                $context->progress( $steps, null, ( count( $messages ) > 1 ? ( $i + 1 ) . '/' . count( $messages ) . ' ' : '' )
                                                  . ( $last && isset( $last['text'] ) ? (string) $last['text'] : '' ) );
                // the conversation was stored by the step: read it fresh for the next one
                $conversation = self::conversationOf( $thread, $p ) ?: $conversation;
            }
            if ( $i + 1 < count( $messages ) )
                $context->saveCheckpoint( array( 'index' => $i + 1, 'sent' => 0 ) );
        }
        if ( $planID && ( $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ) )
            expAiPlanner::complete( $fresh );
        return array( 'steps' => $steps, 'status' => $status, 'turns' => count( $messages ) - $first );
    }

    /**
     * The conversation of the job's run (payload run_id), else the thread's lead conversation (jobs queued before
     * run_id was set). After a switch of model the lead is another run: the job stays in its own conversation.
     * @return expAiConversation|null
     */
    protected static function conversationOf( expAiThread $thread, array $p )
    {
        $run = !empty( $p['run_id'] ) ? expAiThreadRun::fetchByID( (int) $p['run_id'] ) : null;
        if ( $run && (int) $run->attribute( 'thread_id' ) === (int) $thread->attribute( 'id' ) && (int) $run->attribute( 'conversation_id' ) )
        {
            $conversation = eZPersistentObject::fetchObject( expAiConversation::definition(), null, array( 'id' => (int) $run->attribute( 'conversation_id' ) ) );
            if ( $conversation instanceof expAiConversation )
                return $conversation;
        }
        return $thread->conversation();
    }

    /**
     * The job waits while Exponential Intelligence is switched off: held with "switched off: <reason>" (expAiGate
     * resumes such jobs), the thread queued again with a status event that says why.
     * @return expQueueResult
     */
    public static function hold( $thread, $reason )
    {
        if ( $thread instanceof expAiThread )
        {
            $thread->setState( 'queued' );
            $thread->addEvent( 'status', array( 'state' => 'queued', 'held' => ezpI18n::tr( 'extension/expai/error', (string) $reason ) ) );
            $run = $thread->leadRun();
            if ( $run )
            {
                $run->setAttribute( 'state', 'queued' );
                $run->setAttribute( 'modified', time() );
                $run->store();
            }
            $thread->touch();
            $thread->store();
        }
        return expQueueResult::hold( expAiGate::HELD_PREFIX . $reason );
    }

    /** The thread and its lead run end in a state (stopped, failed) with an optional error event */
    public static function finish( $thread, $state, $error = '' )
    {
        if ( !$thread instanceof expAiThread )
            return;
        if ( $error !== '' )
            $thread->addEvent( 'error', array( 'message' => $error ) );
        $thread->setState( $state );
        $run = $thread->leadRun();
        if ( $run )
        {
            $run->setAttribute( 'state', $state );
            $run->setAttribute( 'finished', time() );
            $run->store();
        }
        $thread->touch();
        $thread->store();
    }
}
