<?php
/**
 * File containing the expAiSlowModel class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A running turn on a slow model (doc/parallel-runs.md "Slow models"): Vector and the Intelligence tab show
 * "Running on <model> - about N s per step (step k)" with the time elapsed, and offer "Switch to <default model>".
 *
 * A model is slow when its provider is a managed local server (Managed=ollama), the model is marked slow
 * ([Model_<alias>] Slow, RunInBackground=always), or its measured time per step (the median latency of its last
 * requests in the usage log) is at least [VectorSettings] SlowStepSeconds (20).
 *
 * Switching stops the turn's job and goes on with the same message on the other model, in a new lead run whose
 * conversation carries the earlier exchanges as a short summary (expAiAssistant::compact(), as any change of model).
 * The old run keeps its conversation: a step of the slow model that was already under way ends there and changes
 * nothing of the thread.
 */
class expAiSlowModel
{
    /** @var array alias => seconds per step (per process) */
    protected static $latency = array();

    /** @return float|null the median latency in seconds of the model's last 20 requests (null without data) */
    public static function secondsPerStep( $alias )
    {
        $alias = (string) $alias;
        if ( array_key_exists( $alias, self::$latency ) )
            return self::$latency[$alias];
        $rows = eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), array( 'latency_ms' ), expAiUsage::requestsOnly( array( 'model_alias' => $alias ) ),
                                                      array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => 20 ), false );
        $values = array();
        foreach ( (array) $rows as $row )
            if ( (int) $row['latency_ms'] > 0 )
                $values[] = (int) $row['latency_ms'] / 1000;
        if ( !$values )
            return self::$latency[$alias] = null;
        sort( $values );
        $mid = (int) floor( count( $values ) / 2 );
        return self::$latency[$alias] = round( count( $values ) % 2 ? $values[$mid] : ( $values[$mid - 1] + $values[$mid] ) / 2, 1 );
    }

    /** @return bool the model is slow (managed local server, marked slow, or measured at SlowStepSeconds or more) */
    public static function isSlow( $alias, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $model = $config->registry()->getModel( (string) $alias );
        if ( !$model )
            return false;
        if ( !empty( $model['slow'] ) )
            return true;
        $own = isset( $model['settings']['RunInBackground'] ) ? strtolower( (string) $model['settings']['RunInBackground'] ) : '';
        if ( in_array( $own, array( 'always', 'enabled', 'true' ), true ) )
            return true;
        $settings = $config->registry()->settingsFor( (string) $alias );
        if ( is_array( $settings ) && expAiProviderHealth::isManaged( $settings ) )
            return true;
        $seconds = self::secondsPerStep( $alias );
        return $seconds !== null && $seconds >= $config->int( 'VectorSettings', 'SlowStepSeconds', 20, 1 );
    }

    /** @return string the label of a model ("Claude Haiku 5.5"), the alias without one */
    public static function label( $alias, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $models = $config->allowedModels();
        return isset( $models[$alias] ) ? (string) $models[$alias] : (string) $alias;
    }

    /** @return string the model a switch goes to: the site default when the user may use it and it is not slow, '' none */
    public static function target( $current, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $default = (string) $config->model();
        if ( $default !== '' && $default !== (string) $current && $config->mayUseModel( $default ) && !self::isSlow( $default, $config ) )
            return $default;
        return '';
    }

    /**
     * The status of a thread's running turn for the browser: null unless it works on a slow model.
     * @return array|null alias, label, slow, per_step (seconds or null), step, since (timestamp), switch_to, switch_label
     */
    public static function status( expAiThread $thread, ?expAiConfig $config = null )
    {
        if ( !in_array( $thread->attribute( 'state' ), array( 'queued', 'running' ), true ) )
            return null;
        $config = $config ?: expAiConfig::instance();
        $run = $thread->leadRun();
        $conversation = $thread->conversation();
        if ( !$run || !$conversation || expAiThreadFanout::planOf( $thread ) && expAiThreadFanout::present( $thread )['active'] )
            return null;
        $alias = (string) $conversation->attribute( 'model_alias' ) ?: ( (string) $thread->attribute( 'model_alias' ) ?: $config->effectiveModel() );
        if ( !self::isSlow( $alias, $config ) )
            return null;
        $history = $conversation->history();
        $last = $history ? end( $history ) : null;
        $target = self::target( $alias, $config );
        $perStep = self::secondsPerStep( $alias );
        $step = $last ? count( (array) $last['steps'] ) + 1 : 1;
        $label = self::label( $alias, $config );
        // the texts for both the Intelligence tab and Vector (the browser adds the time elapsed)
        $text = $perStep !== null
            ? ezpI18n::tr( 'extension/expai/vector', 'Running on %model - about %seconds s per step (step %step)', null,
                           array( '%model' => $label, '%seconds' => (string) (int) round( $perStep ), '%step' => $step ) )
            : ezpI18n::tr( 'extension/expai/vector', 'Running on %model, a slow model (step %step)', null, array( '%model' => $label, '%step' => $step ) );
        return array( 'alias' => $alias, 'label' => $label, 'slow' => true, 'per_step' => $perStep, 'step' => $step,
                      'since' => $last && isset( $last['time'] ) ? (int) $last['time'] : (int) $run->attribute( 'modified' ),
                      'switch_to' => $target, 'switch_label' => $target !== '' ? self::label( $target, $config ) : '', 'text' => $text,
                      'button' => $target !== '' ? ezpI18n::tr( 'extension/expai/vector', 'Switch to %model', null, array( '%model' => self::label( $target, $config ) ) ) : '',
                      'switched' => ezpI18n::tr( 'extension/expai/vector', 'The turn goes on with %model.', null, array( '%model' => self::label( $target, $config ) ) ) );
    }

    /**
     * Stops the running turn and goes on with the same message on another model: a new lead run with a conversation
     * that carries the exchanges so far (compacted), the thread on that model. For a thread of the Intelligence tab
     * the user's model becomes that model too (the panel turns use it).
     * @param string $alias the model to go on with ('' the site default)
     * @return array alias, background (the turn was queued again), job
     * @throws expAiException
     */
    public static function switchTo( expAiThread $thread, $alias = '', ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        if ( !in_array( $thread->attribute( 'state' ), array( 'queued', 'running', 'stopped', 'failed' ), true ) )
            throw new expAiException( 'Nothing runs in this thread.' );
        $old = $thread->conversation();
        $oldRun = $thread->leadRun();
        if ( !$old || !$oldRun )
            throw new expAiException( 'This thread has no conversation any more.' );
        $from = (string) $old->attribute( 'model_alias' ) ?: (string) $thread->attribute( 'model_alias' );
        $alias = (string) $alias !== '' ? ( $config->registry()->resolveAlias( (string) $alias ) ?: (string) $alias ) : self::target( $from, $config );
        if ( $alias === '' || !$config->mayUseModel( $alias ) )
            throw new expAiException( 'This model is not available.' );
        if ( $alias === $from )
            throw new expAiException( 'The thread already runs on this model.' );
        // the job of the turn is cancelled: a step under way ends in the old conversation and changes nothing more
        expAiThreadRunner::cancel( $thread );
        $now = time();
        $db = eZDB::instance();
        $db->begin();
        try
        {
            $oldRun->setAttribute( 'state', 'stopped' );
            $oldRun->setAttribute( 'finished', $now );
            $oldRun->store();
            $history = $old->history();
            $keep = max( 1, (int) floor( $config->int( 'AssistantSettings', 'ContextWindowTurns', 8, 2, 100 ) / 2 ) );
            $done = $history;
            $pending = $old->awaitsAnswer() && $done ? array_pop( $done ) : null;
            $messages = $done ? expAiAssistant::compact( $done, $keep ) : array();
            if ( $pending )
                $messages[] = array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => (string) $pending['user'] ) ) );
            $conversation = new expAiConversation( array( 'user_id' => (int) $old->attribute( 'user_id' ), 'node_id' => (int) $old->attribute( 'node_id' ),
                                                          'messages' => '[]', 'created' => $now, 'modified' => $now ) );
            // the exchanges as the editor saw them (the one under way keeps its steps; the new model continues it)
            $conversation->setHistory( $history );
            $conversation->setMessages( $messages );
            $conversation->setWindowTurns( min( count( $done ), $keep ) + ( $pending ? 1 : 0 ) );
            if ( (string) $old->scopeNote() !== '' )
                $conversation->setScopeNote( $old->scopeNote() );
            $conversation->setAttribute( 'thread_id', (int) $thread->attribute( 'id' ) );
            $conversation->store();
            $run = new expAiThreadRun( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'conversation_id' => (int) $conversation->attribute( 'id' ),
                                              'kind' => 'turn', 'state' => 'idle', 'label' => (string) $thread->attribute( 'title' ), 'model_alias' => $alias,
                                              'lane' => (string) $thread->attribute( 'lane' ), 'created' => $now, 'modified' => $now ) );
            $run->store();
            $conversation->setAttribute( 'run_id', (int) $run->attribute( 'id' ) );
            $conversation->store();
            $model = $config->registry()->getModel( $alias );
            $thread = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ?: $thread;
            $thread->setAttribute( 'lead_run_id', (int) $run->attribute( 'id' ) );
            $thread->setAttribute( 'model_alias', $alias );
            $thread->setAttribute( 'provider', $model ? (string) $model['provider'] : '' );
            $thread->setState( 'stopped', array( 'switched' => array( 'from' => $from, 'to' => $alias ) ) );
            $thread->touch();
            $thread->store();
            $db->commit();
        }
        catch ( Throwable $e )
        {
            if ( $db->transactionCounter() > 0 )
                $db->rollback();
            throw $e;
        }
        // the Intelligence tab runs on the user's model
        if ( $thread->attribute( 'origin' ) === expAiThread::ORIGIN_PANEL )
            eZPreferences::setValue( 'expai_model', $alias );
        $out = array( 'alias' => $alias, 'label' => self::label( $alias, $config ), 'from' => $from, 'background' => false, 'job' => '', 'pending' => (bool) $pending );
        // a model that is slow itself goes on in the queue; else the browser continues the turn (continue=1)
        if ( $pending && expAiThreadRunner::inBackground( $alias, $config ) )
        {
            $scope = $thread->scope();
            $nodeID = (int) $thread->attribute( 'node_id' ) ?: ( $scope['nodes'] ? $scope['nodes'][0] : ( $scope['subtrees'] ? $scope['subtrees'][0] : 0 ) );
            $panel = $thread->attribute( 'origin' ) === expAiThread::ORIGIN_PANEL;
            $options = $panel ? array( 'alias' => $alias, 'read_only' => false, 'mode' => 'write', 'scope_note' => '', 'action' => 'panel', 'panel' => true )
                              : array( 'alias' => $alias, 'read_only' => $thread->attribute( 'run_mode' ) === 'plan', 'mode' => (string) $thread->attribute( 'run_mode' ),
                                       'staged' => $thread->attribute( 'review_policy' ) === 'staged', 'scope_note' => expAiThreadPrompts::scopeNote( $scope ), 'action' => 'vector' );
            $job = expAiThreadRunner::enqueue( $thread, $nodeID, '', $options );
            $out['background'] = true;
            $out['job'] = (string) $job->attribute( 'uuid' );
        }
        return $out;
    }
}
