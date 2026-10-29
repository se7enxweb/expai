<?php
/**
 * File containing the expAiModelTest class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The "Test prompt" and "Load model" actions of expai/models as jobs of the job queue (job type expai.model.test,
 * classes/queue/expaimodeltesthandler.php). A local model can take a minute to load and writes a few tokens per
 * second, far longer than a web server lets one request run, so the page only queues the job, starts a worker when
 * none runs, and asks for the state every few seconds (expaimodel::status) until the result is there.
 *
 *   test   one short prompt; the result has the time to the first token (local servers: model load plus reading
 *          the prompt, as Ollama reports it), tokens per second in and out, the total time, the answer, the cost
 *   load   loads a model of a local server (Managed=ollama) into memory and keeps it there ([ModelTest]
 *          LoadKeepAlive); the page shows until when, from Ollama's /api/ps
 *
 * One job per model and action at a time: queueing again while one waits or runs returns that job (an idempotency
 * key of the model, the action and the last job before it). The result stays the job's result and is shown on the
 * page for [ModelTest] ShowResultMinutes.
 */
class expAiModelTest
{
    const JOB_TYPE = 'expai.model.test';
    const ACTIONS = array( 'test', 'load' );

    /** the prompt of the test */
    const SYSTEM = 'You are a test of the connection. Answer briefly.';
    const PROMPT = 'Write one short sentence about the sea.';

    /**
     * The fewest output tokens a request to a reasoning model gets: such a model spends output tokens on thinking
     * before it writes, and with a small budget it returns an answer without words (or a cut-off one).
     */
    const REASONING_MIN_TOKENS = 1000;

    /**
     * Is the model a reasoning model? Its capabilities name reasoning_effort, it has an Effort, or its settings say
     * Reasoning=true.
     * @param array|null $model a model of the registry (expAiProviderRegistry::getModel())
     * @return bool
     */
    public static function isReasoningModel( $model )
    {
        if ( !is_array( $model ) )
            return false;
        if ( isset( $model['capabilities'] ) && in_array( 'reasoning_effort', (array) $model['capabilities'], true ) )
            return true;
        if ( isset( $model['effort'] ) && !in_array( strtolower( trim( (string) $model['effort'] ) ), array( '', 'none' ), true ) )
            return true;
        $reasoning = isset( $model['settings']['Reasoning'] ) ? strtolower( trim( (string) $model['settings']['Reasoning'] ) ) : '';
        return in_array( $reasoning, array( 'true', 'enabled', '1', 'yes', 'on' ), true );
    }

    /**
     * The output budget of a request: the given number of tokens, at least REASONING_MIN_TOKENS for a reasoning model.
     * @param string $alias the model alias ('' or an unknown alias: the given number)
     * @return int
     */
    public static function outputBudget( $alias, $tokens, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $model = (string) $alias !== '' ? $config->registry()->getModel( (string) $alias ) : null;
        return self::isReasoningModel( $model ) ? max( self::REASONING_MIN_TOKENS, (int) $tokens ) : (int) $tokens;
    }

    /** @return bool the job queue can take the tests */
    public static function queueAvailable()
    {
        return class_exists( 'expAiQueue' ) && expAiQueue::available() && class_exists( 'expQueueConfig' )
            && (bool) expQueueConfig::instance()->jobType( self::JOB_TYPE );
    }

    /** @return bool the model's provider is a local server managed by expai/models */
    public static function managed( $alias, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $model = $config->registry()->getModel( (string) $alias );
        $provider = $model ? $config->registry()->getProvider( $model['provider'] ) : null;
        return $provider && isset( $provider['Managed'] ) && strtolower( (string) $provider['Managed'] ) === 'ollama';
    }

    /**
     * Queues a test or a load of a model and starts a worker when none runs.
     * @return array job (expQueueJob), duplicate (bool), kicked (bool)
     * @throws expAiException
     */
    public static function start( $alias, $action = 'test', ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $alias = $config->registry()->resolveAlias( (string) $alias );
        if ( $alias === '' )
            throw new expAiException( 'This model is not available.' );
        if ( !in_array( $action, self::ACTIONS, true ) )
            throw new expAiException( 'Unknown action.' );
        if ( $action === 'load' && !self::managed( $alias, $config ) )
            throw new expAiException( 'Only models of a local model server can be loaded.' );
        if ( !self::queueAvailable() )
            throw new expAiException( 'The job queue is not available.', 'the extension expqueue is not active, its tables are missing or the job type ' . self::JOB_TYPE . ' is not registered' );
        $access = class_exists( 'expAiGate' ) ? (string) expAiGate::siteAccess() : '';
        try
        {
            $job = expQueue::withLock( function () use ( $alias, $action, $access )
            {
                $last = self::latestJob( $alias, $action, self::recentJobs() );
                // the key names the job before it: a second click while one waits or runs is the same job
                $key = 'expai.model.' . $action . ':' . $alias . ':' . ( $last ? (int) $last->attribute( 'id' ) : 0 );
                if ( $last && $last->isActive() )
                {
                    $last->duplicate = true;
                    return $last;
                }
                $payload = array( 'alias' => $alias, 'action' => $action );
                if ( $access !== '' )
                    $payload['siteaccess'] = $access;
                return expQueue::enqueue( self::JOB_TYPE, $payload, array( 'class' => 'interactive', 'idempotency_key' => $key,
                                                                           'tags' => array( 'model:' . $alias, 'action:' . $action ) ) );
            } );
        }
        catch ( expQueueException $e )
        {
            throw new expAiException( 'The job could not be queued: %reason', 'expqueue: ' . $e->getMessage(), 0, array( '%reason' => expAiText::cut( $e->getMessage(), 200 ) ) );
        }
        $kicked = false;
        try
        {
            $kicked = !empty( $job->duplicate ) ? false : expAiThreadRunner::kick();
        }
        catch ( Throwable $e )
        {
            // the job is queued: the polling page starts a worker (nudge()), the cron part of expqueue is the safety net
            eZDebug::writeWarning( 'expai: no worker started for the model test: ' . $e->getMessage(), __METHOD__ );
        }
        eZLog::write( sprintf( 'model %s %s queued as job %s by user %d%s', $action, $alias, $job->attribute( 'uuid' ), (int) eZUser::currentUserID(),
                               !empty( $job->duplicate ) ? ' (already queued)' : '' ), 'expai-models.log' );
        return array( 'job' => $job, 'duplicate' => !empty( $job->duplicate ), 'kicked' => $kicked );
    }

    /**
     * Starts one more worker for the class interactive when a job of the type has waited longer than 10 seconds (asked
     * by the polling page). expAiThreadRunner::kick() starts none while any worker is alive, but that worker may be
     * busy with other work, or about to leave after its --max-seconds; without this a test waits for the cron part.
     * At most one start per 15 seconds ([VectorSettings] KickWorker, PhpCli as there).
     * @param expQueueJob[] $jobs recentJobs()
     * @return bool a worker was started
     */
    public static function nudge( array $jobs, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $waiting = false;
        foreach ( $jobs as $job )
            if ( in_array( (string) $job->attribute( 'state' ), array( 'ready', 'retry' ), true ) && (int) $job->attribute( 'created' ) < time() - 10 )
                $waiting = true;
        if ( !$waiting || !$config->flag( 'VectorSettings', 'KickWorker', true ) )
            return false;
        $dir = eZSys::cacheDirectory() . '/expai';
        if ( !is_dir( $dir ) )
            eZDir::mkdir( $dir, false, true );
        $handle = @fopen( "$dir/model-test-kick.lock", 'c+' );
        if ( !$handle || !flock( $handle, LOCK_EX | LOCK_NB ) )
            return false;
        $last = (int) stream_get_contents( $handle );
        $start = $last <= time() - 15;
        if ( $start )
        {
            ftruncate( $handle, 0 );
            rewind( $handle );
            fwrite( $handle, (string) time() );
        }
        flock( $handle, LOCK_UN );
        fclose( $handle );
        if ( !$start )
            return false;
        $php = (string) $config->get( 'VectorSettings', 'PhpCli', '' );
        if ( $php === '' )
            $php = PHP_SAPI === 'cli' ? PHP_BINARY : PHP_BINDIR . '/php';
        $root = realpath( eZSys::rootDir() ?: getcwd() );
        $script = $root . '/extension/expqueue/bin/php/work.php';
        $access = preg_replace( '/[^A-Za-z0-9_-]/', '', class_exists( 'expAiGate' ) ? (string) expAiGate::siteAccess() : '' );
        if ( !is_file( $script ) || !is_executable( $php ) )
            return false;
        $command = 'cd ' . escapeshellarg( $root ) . ' && nohup setsid ' . escapeshellarg( $php ) . ' ' . escapeshellarg( $script )
                 . ' --until-empty --max-seconds=60 --class=interactive --origin=cli --allow-root-user'
                 . ( $access !== '' ? ' -s ' . escapeshellarg( $access ) : '' ) . ' > /dev/null 2>&1 &';
        $proc = @proc_open( array( '/bin/sh', '-c', $command ), array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes );
        if ( !is_resource( $proc ) )
            return false;
        proc_close( $proc );
        eZLog::write( 'a model test waited longer than 10 s: one more worker started', 'expai-models.log' );
        return true;
    }

    /** @return expQueueJob[] the newest jobs of the type, newest first (fetch once per page, hand to forModel()) */
    public static function recentJobs( $limit = 40 )
    {
        return self::queueAvailable() ? expQueue::listJobs( array( 'type' => self::JOB_TYPE ), 0, $limit ) : array();
    }

    /**
     * @param expQueueJob[]|null $jobs recentJobs() (null: fetch them)
     * @return expQueueJob|null the newest job of a model and action
     */
    public static function latestJob( $alias, $action, $jobs = null )
    {
        foreach ( $jobs === null ? self::recentJobs() : $jobs as $job )
        {
            $p = $job->payloadArray();
            if ( isset( $p['alias'], $p['action'] ) && $p['alias'] === (string) $alias && $p['action'] === (string) $action )
                return $job;
        }
        return null;
    }

    /**
     * What the page shows of a job.
     * @return array|null phase (waiting, running, done, failed), uuid, action, alias, seconds (since it was queued, or
     *                    what it took), created, finished, result (of the handler), error (translated), held
     */
    public static function describe( ?expQueueJob $job = null )
    {
        if ( !$job )
            return null;
        $state = (string) $job->attribute( 'state' );
        $p = $job->payloadArray();
        $result = $job->resultArray();
        $result = is_array( $result ) ? $result : array();
        if ( array_key_exists( 'cost_amount', $result ) )
            $result['cost'] = $result['cost_amount'] !== null ? expAiCost::money( $result['cost_amount'] ) : '';
        foreach ( array( 'ttft_ms', 'total_ms', 'load_ms' ) as $name )
            $result[str_replace( '_ms', '_s', $name )] = isset( $result[$name] ) && $result[$name] !== null ? round( $result[$name] / 1000, 1 ) : null;
        $phase = 'waiting';
        if ( $state === 'running' )
            $phase = 'running';
        elseif ( $state === 'done' )
            $phase = !empty( $result['ok'] ) ? 'done' : 'failed';
        elseif ( in_array( $state, array( 'dead', 'cancelled' ), true ) )
            $phase = 'failed';
        $error = '';
        if ( $phase === 'failed' )
            $error = isset( $result['error'] ) && $result['error'] !== ''
                   ? ezpI18n::tr( 'extension/expai/error', (string) $result['error'], null, isset( $result['error_args'] ) ? (array) $result['error_args'] : array() )
                   : ( $state === 'cancelled' ? ezpI18n::tr( 'extension/expai/modeltest', 'The job was cancelled.' )
                                              : ezpI18n::tr( 'extension/expai/modeltest', 'The job failed: %reason', null, array( '%reason' => expAiText::cut( (string) $job->attribute( 'last_error' ), 300 ) ) ) );
        $created = (int) $job->attribute( 'created' );
        $finished = (int) $job->attribute( 'finished' );
        return array( 'uuid' => (string) $job->attribute( 'uuid' ), 'state' => $state, 'phase' => $phase,
                      'action' => isset( $p['action'] ) ? (string) $p['action'] : '', 'alias' => isset( $p['alias'] ) ? (string) $p['alias'] : '',
                      'active' => $job->isActive(), 'created' => $created, 'finished' => $finished,
                      'seconds' => $finished ? max( 0, $finished - $created ) : max( 0, time() - $created ),
                      'held' => $state === 'held' ? expAiText::cut( (string) $job->attribute( 'last_error' ), 300 ) : '',
                      'result' => $result, 'error' => $error );
    }

    /**
     * The state of a model for the page: the newest test and load (finished ones only for ShowResultMinutes), and for
     * a model of a local server whether it is loaded and until when.
     * @param array|null $ps a decoded /api/ps answer of the model's server (null: ask it)
     * @param expQueueJob[]|null $jobs recentJobs() (null: fetch them)
     * @return array alias, managed, test, load (describe() or null), loaded (null when not managed: loaded, until,
     *               size_mb), active (a job waits or runs)
     */
    public static function forModel( $alias, ?expAiConfig $config = null, $ps = null, $jobs = null )
    {
        $jobs = $jobs === null ? self::recentJobs() : $jobs;
        $config = $config ?: expAiConfig::instance();
        $keep = $config->int( 'ModelTest', 'ShowResultMinutes', 60, 0 ) * 60;
        $out = array( 'alias' => (string) $alias, 'managed' => self::managed( $alias, $config ), 'test' => null, 'load' => null, 'loaded' => null, 'active' => false,
                      'queue' => self::queueAvailable() );
        foreach ( self::ACTIONS as $action )
        {
            $d = self::describe( self::latestJob( $alias, $action, $jobs ) );
            if ( $d && !$d['active'] && $d['finished'] < time() - $keep )
                $d = null;
            $out[$action] = $d;
            $out['active'] = $out['active'] || ( $d && $d['active'] );
        }
        if ( $out['managed'] )
        {
            $model = $config->registry()->getModel( (string) $alias );
            if ( is_array( $ps ) )
                $out['loaded'] = expAiOllamaNativeProvider::loadedIn( $ps, $model['id'] ) + array( 'reachable' => true );
            else
            {
                $native = expAiOllamaNativeProvider::forAlias( $config, $alias );
                $out['loaded'] = $native ? $native->loadedState() : null;
            }
        }
        return $out;
    }

    /**
     * Runs a test or a load (in the worker; without the queue, a test of a model that fits into the web budget runs
     * in the page request).
     * @param array $context user_id, siteaccess, job_uuid (for the usage row)
     * @param expAiProvider|null $provider the provider to test (tests hand in a stub)
     * @return array the result: ok, action, alias, model, and for a test text, input, output, ttft_ms, total_ms,
     *               load_ms, in_tps, out_tps, cost_amount (null when unknown); for a load load_ms,
     *               total_ms, until; on failure error and error_args (English, translated when shown)
     */
    public static function run( $alias, $action, array $context = array(), ?expAiConfig $config = null, ?expAiProvider $provider = null )
    {
        $config = $config ?: expAiConfig::instance();
        $base = array( 'ok' => false, 'action' => (string) $action, 'alias' => (string) $alias, 'model' => '', 'at' => time() );
        try
        {
            if ( !$config->registry()->getModel( (string) $alias ) )
                throw new expAiException( 'This model is not available.' );
            return $action === 'load' ? self::load( $alias, $config ) + $base : self::test( $alias, $context, $config, $provider ) + $base;
        }
        catch ( expAiException $e )
        {
            return array( 'error' => $e->getMessage(), 'error_args' => $e->arguments(), 'detail' => expAiText::cut( $e->detail(), 300 ) ) + $base;
        }
    }

    /** @return array the result of a test prompt */
    protected static function test( $alias, array $context, expAiConfig $config, ?expAiProvider $provider = null )
    {
        $native = $provider ? null : expAiOllamaNativeProvider::forAlias( $config, $alias );
        $inner = $provider ?: ( $native ?: $config->registry()->providerFor( $alias ) );
        $recorder = new expAiUsageRecorder( $inner, array( 'action' => 'test' )
            + ( !empty( $context['user_id'] ) ? array( 'user_id' => (int) $context['user_id'] ) : array() )
            + ( !empty( $context['siteaccess'] ) ? array( 'siteaccess' => (string) $context['siteaccess'] ) : array() ) );
        $start = microtime( true );
        // #392 stream_timing: a provider that streams (Streaming=enabled) is timed to its first token. The budget is
        // that of a reasoning model for every model: the answer is one sentence, and a reasoning model thinks first
        $answer = $recorder->send( array( 'system' => self::SYSTEM, 'messages' => array( array( 'role' => 'user', 'content' => self::PROMPT ) ), 'max_tokens' => self::REASONING_MIN_TOKENS,
                                          'stream_timing' => true ) );
        $totalMs = (int) round( ( microtime( true ) - $start ) * 1000 );
        $text = '';
        foreach ( isset( $answer['content'] ) ? (array) $answer['content'] : array() as $block )
            if ( isset( $block['type'], $block['text'] ) && $block['type'] === 'text' )
                $text .= $block['text'];
        $tokens = isset( $answer['tokens'] ) ? (array) $answer['tokens'] : array();
        if ( !$tokens && isset( $answer['usage'] ) )
            $tokens = array( 'input' => isset( $answer['usage']['input_tokens'] ) ? $answer['usage']['input_tokens'] : 0,
                             'output' => isset( $answer['usage']['output_tokens'] ) ? $answer['usage']['output_tokens'] : 0 );
        $input = isset( $tokens['input'] ) ? (int) $tokens['input'] : 0;
        $output = isset( $tokens['output'] ) ? (int) $tokens['output'] : 0;
        $t = isset( $answer['timings'] ) && is_array( $answer['timings'] ) ? $answer['timings'] : array();
        $rate = function ( $count, $ms ) { return $count > 0 && $ms > 0 ? round( $count * 1000 / $ms, 1 ) : null; };
        $cost = $recorder->last ? $recorder->last->cost() : null;
        return array(
            'ok' => true,
            'model' => isset( $answer['model'] ) ? (string) $answer['model'] : '',
            'text' => expAiText::cut( trim( $text ), 600 ),
            'input' => $input, 'output' => $output,
            'total_ms' => $totalMs,
            // Ollama reports where the time went; a provider that streams is timed to its first token (#392); another
            // answers in one piece, so its first token is not known
            'ttft_ms' => isset( $t['first_token'] ) ? $t['first_token'] : null,
            'load_ms' => isset( $t['load'] ) ? $t['load'] : null,
            'in_tps' => isset( $t['prompt'] ) ? $rate( $input, $t['prompt'] ) : null,
            'out_tps' => isset( $t['eval'] ) ? $rate( $output, $t['eval'] ) : $rate( $output, $totalMs ),
            // the amount; describe() formats it in the viewer's locale
            'cost_amount' => $cost !== null ? (float) $cost : null,
            'usage_log_id' => $recorder->last ? (int) $recorder->last->attribute( 'id' ) : 0,
        );
    }

    /** @return array the result of a load */
    protected static function load( $alias, expAiConfig $config )
    {
        $native = expAiOllamaNativeProvider::forAlias( $config, $alias );
        if ( !$native )
            throw new expAiException( 'Only models of a local model server can be loaded.' );
        // the master switch: a load is no request to a model, but nothing of expai runs while it is off
        if ( class_exists( 'expAiGate' ) && ( $off = expAiGate::switchedOff( $config ) ) !== '' )
            throw new expAiException( 'Exponential Intelligence is switched off.', 'gate: ' . $off );
        $loaded = $native->load( (string) $config->get( 'ModelTest', 'LoadKeepAlive', '30m' ) );
        $state = $native->loadedState();
        return array( 'ok' => true, 'model' => $native->modelName(), 'load_ms' => $loaded['load_ms'], 'total_ms' => $loaded['total_ms'],
                      'until' => $state['until'], 'loaded' => $state['loaded'] );
    }
}
