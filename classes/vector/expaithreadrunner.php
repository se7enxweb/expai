<?php
/**
 * File containing the expAiThreadRunner class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Background runs of threads (doc/vector.md "Background runs"). Owner decision: a turn whose model is slow (a local
 * model, or one marked so) runs as a queue job, not in one web request that the web server ends after its time
 * limit. The browser follows it by its events.
 *
 *   [VectorSettings] Background=auto | always | never
 *     auto    in the background when the model is slow: [Model_<alias>] RunInBackground=always (or never), else the
 *             model's Slow flag (local models are slow unless Slow=false)
 *   KickWorker=enabled  without a running worker, start one detached worker for the class "interactive"
 *                       (ext:expqueue:work --until-empty) right after the job is queued; the cron part of expqueue
 *                       stays the safety net
 *   PhpCli=             the PHP command line binary for that worker (empty: the one of this PHP)
 */
class expAiThreadRunner
{
    const JOB_TYPE = 'expai.thread.turn';

    /** @return bool the job queue can take thread turns */
    public static function queueAvailable()
    {
        return class_exists( 'expQueue' ) && class_exists( 'expQueueConfig' ) && expQueueConfig::instance()->jobType( self::JOB_TYPE )
            && class_exists( 'expAiQueue' ) && expAiQueue::available();
    }

    /** @return bool the turn of a model runs in the background */
    public static function inBackground( $alias, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $mode = (string) $config->get( 'VectorSettings', 'Background', 'auto' );
        if ( $mode === 'never' || !self::queueAvailable() )
            return false;
        if ( $mode === 'always' )
            return true;
        $model = $config->registry()->getModel( (string) $alias ?: $config->effectiveModel() );
        if ( !$model )
            return false;
        $own = isset( $model['settings']['RunInBackground'] ) ? strtolower( (string) $model['settings']['RunInBackground'] ) : 'auto';
        if ( $own === 'always' || $own === 'enabled' || $own === 'true' )
            return true;
        if ( $own === 'never' || $own === 'disabled' || $own === 'false' )
            return false;
        return !empty( $model['slow'] );
    }

    /**
     * Queues a turn of the thread and starts a worker when none runs.
     * @return expQueueJob
     */
    public static function enqueue( expAiThread $thread, $nodeID, $message, array $options )
    {
        $run = $thread->leadRun();
        $job = expQueue::enqueue( self::JOB_TYPE, array( 'thread_id' => (int) $thread->attribute( 'id' ), 'run_id' => $run ? (int) $run->attribute( 'id' ) : 0,
                                                         'node_id' => (int) $nodeID, 'message' => (string) $message, 'options' => $options,
                                                         // the master switch is checked for the siteaccess the turn was queued from
                                                         'siteaccess' => class_exists( 'expAiGate' ) ? expAiGate::siteAccess() : '' ),
                                  array( 'class' => 'interactive', 'user_id' => (int) $thread->attribute( 'user_id' ),
                                         'tags' => array( 'thread:' . $thread->attribute( 'uuid' ) ) ) );
        if ( $run )
        {
            $run->setAttribute( 'job_uuid', (string) $job->attribute( 'uuid' ) );
            $run->setAttribute( 'state', 'queued' );
            $run->setAttribute( 'modified', time() );
            $run->store();
        }
        $thread->setState( 'queued', array( 'job' => (string) $job->attribute( 'uuid' ) ) );
        $thread->touch();
        $thread->store();
        self::kick();
        return $job;
    }

    /**
     * The Intelligence tab with a slow model: the turn of the user's conversation on the node goes to the queue (the
     * conversation and its thread are created first when there is none).
     * @param string|null $message null to continue an interrupted turn
     * @return array|null the answer for the panel (status queued, thread uuid), null when the turn runs in the request
     */
    public static function panelTurn( $nodeID, $message, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $alias = $config->effectiveModel();
        if ( !expAiThread::available() || !self::inBackground( $alias, $config ) )
            return null;
        $user = eZUser::currentUser();
        $conversation = expAiConversation::fetchCurrent( $user->attribute( 'contentobject_id' ), $nodeID );
        $message = $message === null ? '' : trim( (string) $message );
        if ( $message === '' && ( !$conversation || !$conversation->awaitsAnswer() ) )
            throw new expAiException( 'Please write a message.' );
        $max = $config->int( 'AssistantSettings', 'MaxMessageLength', 4000, 10 );
        if ( mb_strlen( $message, 'UTF-8' ) > $max )
            throw new expAiException( 'The message is too long (at most %max characters).', '', 0, array( '%max' => $max ) );
        // the node must be readable under the data policy of the model, as in the request
        $reader = new expAiContentReader( $config, expAiDataPolicy::forProvider( $config, $config->registry()->providerOf( $alias ) ) );
        $reader->setRequest( array( 'purpose' => 'panel' ) );
        $reader->readableNode( $nodeID );
        if ( !$conversation )
        {
            $conversation = expAiConversation::start( $user->attribute( 'contentobject_id' ), $nodeID );
            expAiThread::forPanelConversation( $conversation, $nodeID, $alias );
        }
        $thread = expAiThread::fetchForConversation( $conversation );
        if ( !$thread )
            return null;
        // the panel's own conversation: the user's model choice, write tools as in the panel
        $job = self::enqueue( $thread, $nodeID, $message, array( 'alias' => $alias, 'read_only' => false, 'mode' => 'write', 'scope_note' => '', 'action' => 'panel',
                                                                  'panel' => true ) );
        return array( 'status' => 'queued', 'job' => (string) $job->attribute( 'uuid' ), 'thread' => (string) $thread->attribute( 'uuid' ),
                      'watch' => expAiTurnRecord::watch( expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ?: $thread ),
                      'steps' => array(), 'text' => '', 'exchange' => null, 'drafts' => expAiAssistant::drafts( $user, $nodeID ),
                      'notice' => ezpI18n::tr( 'extension/expai/vector', 'This model is slow: the turn runs in the background. You may leave this page; the thread keeps working.' ) );
    }

    /**
     * A queued or running thread whose job ended without finishing the turn (dead, cancelled, or lost) is marked
     * failed or stopped, so the browser never waits for a job that will not run. Called by expaivector::events.
     * @return bool the thread changed
     */
    public static function reconcile( expAiThread $thread )
    {
        if ( !in_array( $thread->attribute( 'state' ), array( 'queued', 'running' ), true ) || !self::queueAvailable() )
            return false;
        $run = $thread->leadRun();
        if ( !$run || (string) $run->attribute( 'job_uuid' ) === '' || $run->attribute( 'state' ) !== 'queued' && $run->attribute( 'state' ) !== 'running' )
            return false;
        $job = expQueue::fetch( (string) $run->attribute( 'job_uuid' ) );
        if ( !$job || !$job->isFinal() )
            return false;
        $state = (string) $job->attribute( 'state' );
        if ( $state === 'done' )
            return false;
        expAiThreadTurnHandler::finish( $thread, $state === 'cancelled' ? 'stopped' : 'failed',
            $state === 'cancelled' ? '' : ezpI18n::tr( 'extension/expai/vector', 'The background job of this turn ended without an answer: %error', null,
                                                        array( '%error' => expAiText::cut( (string) $job->attribute( 'last_error' ), 200 ) ) ) );
        return true;
    }

    /** Cancels the queued or running job of the thread's lead run (honored between two steps) */
    public static function cancel( expAiThread $thread )
    {
        $run = $thread->leadRun();
        if ( $run && (string) $run->attribute( 'job_uuid' ) !== '' && self::queueAvailable() )
            return expQueue::cancel( (string) $run->attribute( 'job_uuid' ), 'stopped by the editor' );
        return null;
    }

    /**
     * Starts detached workers for interactive jobs (expAiQueueKick, shared with every producer): when the queued job
     * waits and no live worker is free (none runs, or each is busy with a long job), bounded by
     * [VectorSettings] MaxKickedWorkers. The items of an approved plan ask for several (doc/parallel-runs.md).
     * @param int $wanted jobs that may run at once now (1 for a turn, up to MaxParallelRunsPerThread for a plan)
     * @param int $maxSeconds how long a started worker takes new jobs (it leaves earlier when nothing is ready)
     * @return bool a worker was started
     */
    public static function kick( $wanted = 1, $maxSeconds = 60 )
    {
        return expAiQueueKick::kick( $wanted, $maxSeconds, 'interactive' ) > 0;
    }
}
