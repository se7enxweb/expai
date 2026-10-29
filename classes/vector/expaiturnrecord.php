<?php
/**
 * File containing the expAiTurnRecord class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The record of each turn of a thread (doc/parallel-runs.md "The record of a turn"), from the usage log: the model that
 * actually answered (and the one asked for when the fallback replaced it, with the reason), each step (request) with
 * its duration, the tools it called, tokens and cost, the turn's totals and its result: done, failed, timed out,
 * stopped, replaced (the editor switched the model, or the fallback answered), working. Shown collapsed, one line per
 * turn, in the Intelligence tab and in Vector, with links to the thread's requests in the log (and each request), the
 * job of a turn that ran in the background, and the thread in Vector.
 */
class expAiTurnRecord
{
    /** @return string a duration: "850 ms", "4.2 s", "2 min 05 s" */
    public static function duration( $ms )
    {
        $ms = (int) $ms;
        if ( $ms < 1000 )
            return $ms . ' ms';
        if ( $ms < 60000 )
            return round( $ms / 1000, 1 ) . ' s';
        return floor( $ms / 60000 ) . ' min ' . sprintf( '%02d', (int) round( ( $ms % 60000 ) / 1000 ) ) . ' s';
    }

    /** @var array|null the texts of the record and the watch (once per request) */
    protected static $texts = null;

    /** @return array the link and heading texts of the record and the watch, translated */
    public static function texts()
    {
        if ( self::$texts === null )
            self::$texts = array( 'watch' => ezpI18n::tr( 'extension/expai/vector', 'Watch this run' ), 'log' => ezpI18n::tr( 'extension/expai/vector', 'Requests in the log' ),
                                  'job' => ezpI18n::tr( 'extension/expai/vector', 'Job' ), 'vector' => ezpI18n::tr( 'extension/expai/vector', 'Open in Vector' ),
                                  'request' => ezpI18n::tr( 'extension/expai/vector', 'Request' ), 'record' => ezpI18n::tr( 'extension/expai/vector', 'Record of this turn' ) );
        return self::$texts;
    }

    /** @return bool the user may open the job pages of the queue */
    protected static function mayViewJobs()
    {
        if ( !class_exists( 'expQueue' ) || !expAiConfig::extensionActive( 'expqueue' ) )
            return false;
        $access = eZUser::currentUser()->hasAccessTo( 'expqueue', 'view' );
        return isset( $access['accessWord'] ) && $access['accessWord'] !== 'no';
    }

    /**
     * The records of a thread's lead turns (the exchanges of its conversation, also across a switch of model).
     * @return array exchange id => record { turn, model, model_label, requested, requested_label, fallback_reason, steps,
     *               ms, duration, tokens, cost, result, result_label, started, links }
     */
    public static function forThread( expAiThread $thread, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $threadID = (int) $thread->attribute( 'id' );
        $runs = array();
        foreach ( expAiThreadRun::fetchForThread( $threadID ) as $run )
            if ( !$run->isChild() )
                $runs[(int) $run->attribute( 'id' )] = $run;
        $rows = eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), null, expAiUsage::requestsOnly( array( 'thread_id' => $threadID ) ), array( 'id' => 'asc' ),
                                                      array( 'offset' => 0, 'length' => 2000 ) );
        $models = $config->allowedModels();
        $label = function ( $alias ) use ( $models ) { return isset( $models[$alias] ) ? (string) $models[$alias] : (string) $alias; };
        $money = function ( $micros ) { return expAiCost::enabled() ? expAiCost::money( (int) $micros / 1000000 ) : ''; };
        $jobs = self::mayViewJobs();
        $out = array();
        foreach ( (array) $rows as $row )
        {
            $runID = (int) $row->attribute( 'run_id' );
            // the lead's turns (child runs of a plan have their own rows in the plan's items)
            if ( $runID && !isset( $runs[$runID] ) )
                continue;
            $turn = (int) $row->attribute( 'turn' );
            if ( !isset( $out[$turn] ) )
                $out[$turn] = array( 'turn' => $turn, 'steps' => array(), 'ms' => 0, 'tokens' => 0, 'micros' => 0, 'started' => (int) $row->attribute( 'created' ),
                                     'requested' => '', 'fallback_reason' => '', 'error' => '', 'run_id' => $runID, 'runs' => array() );
            $r =& $out[$turn];
            $tokens = (int) $row->attribute( 'input_tokens' ) + (int) $row->attribute( 'output_tokens' ) + (int) $row->attribute( 'cache_write_tokens' ) + (int) $row->attribute( 'cache_read_tokens' );
            $cost = $row->attribute( 'cost' );
            $micros = $cost !== null ? (int) round( (float) $cost * 1000000 ) : 0;
            $ms = (int) $row->attribute( 'latency_ms' ) + (int) $row->attribute( 'tool_ms' );
            $alias = (string) $row->attribute( 'model_alias' ) ?: (string) $row->attribute( 'model' );
            $r['steps'][] = array( 'id' => (int) $row->attribute( 'id' ), 'model' => $label( $alias ), 'ms' => $ms, 'duration' => self::duration( $ms ),
                                   'tools' => (string) $row->attribute( 'tool_calls' ), 'tool_count' => (int) $row->attribute( 'tool_count' ),
                                   'tokens' => $tokens, 'cost' => $money( $micros ), 'error' => (string) $row->attribute( 'error_class' ),
                                   'stop_reason' => (string) $row->attribute( 'stop_reason' ), 'log' => 'expai/log/(request)/' . (int) $row->attribute( 'id' ) );
            $r['ms'] += $ms;
            $r['tokens'] += $tokens;
            $r['micros'] += $micros;
            $r['model'] = $alias;
            $r['runs'][$runID] = true;
            $r['run_id'] = $runID;
            if ( (string) $row->attribute( 'requested_model_alias' ) !== '' && (string) $row->attribute( 'requested_model_alias' ) !== $alias )
            {
                $r['requested'] = (string) $row->attribute( 'requested_model_alias' );
                $r['fallback_reason'] = (string) $row->attribute( 'fallback_reason' );
            }
            $r['error'] = (string) $row->attribute( 'error_class' );
            unset( $r );
        }
        $lead = (int) $thread->attribute( 'lead_run_id' );
        $working = in_array( $thread->attribute( 'state' ), array( 'queued', 'running' ), true );
        $last = $out ? max( array_keys( $out ) ) : 0;
        $results = array( 'done' => ezpI18n::tr( 'extension/expai/vector', 'done' ), 'failed' => ezpI18n::tr( 'extension/expai/vector', 'failed' ),
                          'timeout' => ezpI18n::tr( 'extension/expai/vector', 'timed out' ), 'stopped' => ezpI18n::tr( 'extension/expai/vector', 'stopped' ),
                          'replaced' => ezpI18n::tr( 'extension/expai/vector', 'replaced' ), 'working' => ezpI18n::tr( 'extension/expai/vector', 'working' ) );
        foreach ( $out as $turn => $r )
        {
            $run = isset( $runs[$r['run_id']] ) ? $runs[$r['run_id']] : null;
            if ( $r['error'] !== '' )
                $result = stripos( $r['error'], 'timeout' ) !== false ? 'timeout' : 'failed';
            elseif ( count( $r['runs'] ) > 1 || ( $run && (int) $run->attribute( 'id' ) !== $lead && $run->attribute( 'state' ) === 'stopped' ) )
                $result = 'replaced';
            elseif ( $turn === $last && $working )
                $result = 'working';
            elseif ( $turn === $last && $run && $run->attribute( 'state' ) === 'stopped' )
                $result = 'stopped';
            elseif ( $r['requested'] !== '' )
                $result = 'replaced';
            else
                $result = 'done';
            $job = $run && (string) $run->attribute( 'job_uuid' ) !== '' && $jobs ? 'expqueue/job/' . $run->attribute( 'job_uuid' ) : '';
            // the one line of the collapsed record, and the fallback in words
            $line = implode( ' · ', array_filter( array( $label( $r['model'] ),
                ezpI18n::tr( 'extension/expai/vector', '%count step(s)', null, array( '%count' => count( $r['steps'] ) ) ), self::duration( $r['ms'] ),
                ezpI18n::tr( 'extension/expai/vector', '%tokens tokens', null, array( '%tokens' => expAiCost::tokens( $r['tokens'] ) ) ), $money( $r['micros'] ), $results[$result] ), 'strlen' ) );
            $fallbackText = $r['requested'] !== '' ? ezpI18n::tr( 'extension/expai/vector', 'Asked for %requested; %model answered: %reason', null,
                array( '%requested' => $label( $r['requested'] ), '%model' => $label( $r['model'] ), '%reason' => $r['fallback_reason'] !== '' ? $r['fallback_reason'] : '-' ) ) : '';
            $out[$turn] = array( 'turn' => $turn, 'model' => $r['model'], 'model_label' => $label( $r['model'] ), 'requested' => $r['requested'],
                                 'requested_label' => $r['requested'] !== '' ? $label( $r['requested'] ) : '', 'fallback_reason' => $r['fallback_reason'],
                                 'steps' => $r['steps'], 'step_count' => count( $r['steps'] ), 'ms' => $r['ms'], 'duration' => self::duration( $r['ms'] ),
                                 'tokens' => $r['tokens'], 'tokens_text' => expAiCost::tokens( $r['tokens'] ), 'cost' => $money( $r['micros'] ),
                                 'result' => $result, 'result_label' => $results[$result], 'started' => $r['started'], 'line' => $line, 'fallback_text' => $fallbackText,
                                 'texts' => self::texts(),
                                 'links' => array( 'log' => 'expai/log/(thread)/' . $threadID, 'job' => $job,
                                                   'vector' => expAiThreadAccess::hasVector() ? 'expai/vector/(thread)/' . $thread->attribute( 'uuid' ) : '' ) );
        }
        return $out;
    }

    /**
     * "Watch this run": the live state of the thread's turn right after it was accepted, with its links.
     * @return array state (queued, running, done, failed, stopped), step, label, links { log, job, vector }
     */
    public static function watch( expAiThread $thread )
    {
        $state = (string) $thread->attribute( 'state' );
        $conversation = $thread->conversation();
        $history = $conversation ? $conversation->history() : array();
        $last = $history ? end( $history ) : null;
        $step = $last ? count( (array) $last['steps'] ) + ( in_array( $state, array( 'queued', 'running' ), true ) ? 1 : 0 ) : 0;
        $run = $thread->leadRun();
        $simple = in_array( $state, array( 'queued', 'running', 'failed', 'stopped' ), true ) ? $state : 'done';
        $labels = array( 'queued' => ezpI18n::tr( 'extension/expai/vector', 'queued' ),
                         'running' => ezpI18n::tr( 'extension/expai/vector', 'running step %step', null, array( '%step' => max( 1, $step ) ) ),
                         'done' => ezpI18n::tr( 'extension/expai/vector', 'done' ), 'failed' => ezpI18n::tr( 'extension/expai/vector', 'failed' ),
                         'stopped' => ezpI18n::tr( 'extension/expai/vector', 'stopped' ) );
        return array( 'state' => $simple, 'step' => $step, 'label' => $labels[$simple], 'texts' => self::texts(),
                      'links' => array( 'log' => 'expai/log/(thread)/' . (int) $thread->attribute( 'id' ),
                                        'job' => $run && (string) $run->attribute( 'job_uuid' ) !== '' && self::mayViewJobs() ? 'expqueue/job/' . $run->attribute( 'job_uuid' ) : '',
                                        'vector' => expAiThreadAccess::hasVector() ? 'expai/vector/(thread)/' . $thread->attribute( 'uuid' ) : '' ) );
    }
}
