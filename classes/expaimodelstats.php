<?php
/**
 * File containing the expAiModelStats class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The track record of each model for the "Models" card of expai/dashboard: from the usage log of a period (one user
 * or the site) the requests, the success rate, the errors by kind, how often the fallback had to replace the model,
 * median and 95th percentile latency per request, the median output speed (output tokens per second) and time per
 * turn, the cost, the median latency per day; with the newest "Test prompt" result of expai/models (the job type
 * expai.model.test) and the newest probe (expai_probe_result).
 *
 * One query service shared by everything that shows these figures: forPeriod() reads the period's rows once, keeps
 * the result for the request and, for [ModelStats] CacheSeconds, in a small cache file, so the card costs a file read
 * on most page views. aggregate() is the pure part (rows in, figures out) and is what the tests feed with fixtures.
 *
 * Error kinds (errorKind()): timeout (no answer within the time limit), unreachable (the connection failed), http
 * (the provider answered with an error status), veto (refused by a rule of the site or by the model), limit (a
 * usage limit or the provider's rate limit), budget (a budget or the web budget), other.
 */
class expAiModelStats
{
    /** The columns of expai_usage_log the figures need */
    const FIELDS = array( 'id', 'created', 'model_alias', 'model', 'provider', 'region', 'error_class', 'error_message', 'http_status',
                          'latency_ms', 'input_tokens', 'output_tokens', 'cache_write_tokens', 'cache_read_tokens', 'reported_cost_micros',
                          'price_factor', 'requested_model_alias', 'fallback_reason', 'conversation_id', 'turn', 'thread_id', 'run_id' );

    /** The error kinds, in the order the card lists them */
    const KINDS = array( 'timeout', 'unreachable', 'http', 'veto', 'limit', 'budget', 'other' );

    /** @var array the results of this request, by cache key */
    protected static $memo = array();

    /**
     * The figures of a period for a user (false: the site).
     * @param string $period today | 7d | 30d | month (expAiUsage::periodRange())
     * @return array models (list, see aggregate(), each with test and probe), days (Y-m-d of the period), range,
     *               slow_seconds, cached (bool), truncated (bool)
     */
    public static function forPeriod( $userID, $period, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        // per siteaccess too: labels, prices and the cost setting may differ between siteaccesses
        $access = isset( $GLOBALS['eZCurrentAccess']['name'] ) ? (string) $GLOBALS['eZCurrentAccess']['name'] : '';
        $key = md5( json_encode( array( $userID === false ? 'site' : (int) $userID, (string) $period, $access ) ) );
        if ( isset( self::$memo[$key] ) )
            return self::$memo[$key];
        $ttl = $config->int( 'ModelStats', 'CacheSeconds', 60, 0, 3600 );
        $cached = $ttl > 0 ? self::readCache( $key, $ttl ) : null;
        if ( $cached !== null )
            return self::$memo[$key] = $cached + array( 'cached' => true );
        $result = self::compute( $userID, $period, $config );
        if ( $ttl > 0 )
            self::writeCache( $key, $result );
        return self::$memo[$key] = $result + array( 'cached' => false );
    }

    /** Forgets the results of this request (the tests, and after a change that must show at once) */
    public static function reset()
    {
        self::$memo = array();
    }

    /** @return array forPeriod() without the caches */
    public static function compute( $userID, $period, expAiConfig $config )
    {
        $range = expAiUsage::periodRange( $period );
        $filters = array( 'from' => $range['from'], 'to' => $range['to'] );
        if ( $userID !== false )
            $filters['user_id'] = (int) $userID;
        $rows = eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), self::FIELDS, expAiUsage::conditions( $filters ),
                                                     array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => expAiUsage::AGGREGATE_LIMIT ), false );
        $rows = is_array( $rows ) ? $rows : array();
        $days = array();
        for ( $t = mktime( 0, 0, 0, (int) date( 'n', $range['from'] ), (int) date( 'j', $range['from'] ) ); $t <= $range['to']; $t = strtotime( '+1 day', $t ) )
            $days[] = date( 'Y-m-d', $t );
        $slow = expAiWebBudget::seconds( $config );
        $models = self::aggregate( $rows, array( 'days' => $days, 'slow_seconds' => $slow, 'registry' => $config->registry()->models() ) );
        $aliases = array_map( function ( $m ) { return $m['key']; }, $models );
        $tests = self::latestTests( $aliases );
        $probes = self::latestProbes( $aliases );
        foreach ( $models as $i => $m )
        {
            $models[$i]['test'] = isset( $tests[$m['key']] ) ? $tests[$m['key']] : null;
            $models[$i]['probe'] = isset( $probes[$m['key']] ) ? $probes[$m['key']] : null;
        }
        return array( 'models' => $models, 'days' => $days, 'range' => $range, 'slow_seconds' => $slow,
                      'truncated' => count( $rows ) >= expAiUsage::AGGREGATE_LIMIT, 'computed' => time() );
    }

    /**
     * The figures per model of a set of usage log rows (arrays with the columns of FIELDS).
     * @param array $options days (Y-m-d list for the latency per day), slow_seconds (a median above it flags the
     *                       model slow; 0: never), registry (alias => model of expAiProviderRegistry::models(), for
     *                       labels and providers)
     * @return array list, most requests first, of { key (the alias, or the model id of a row without one), known
     *               (an alias of the registry), label, provider, region, requests, ok, errors, success_rate (percent,
     *               at most 99.9 while a request failed, null without requests), kinds (kind => count, only those that occurred), replaced (requests the fallback
     *               answered with another model), replaced_reasons (reason => count), stood_in (requests this model
     *               answered for another), latency_median_ms, latency_p95_ms, tps_median, turn_median_ms, turns,
     *               cost, cost_known, slow (bool), slow_seconds (the median in whole seconds), per_day (list of
     *               { day, ms (median, null without a successful request), percent (of the slowest day) }),
     *               log_key (for expai/log/(model)/..., '' when it cannot be a view parameter) }
     */
    public static function aggregate( array $rows, array $options = array() )
    {
        $days = isset( $options['days'] ) ? (array) $options['days'] : array();
        $slowSeconds = isset( $options['slow_seconds'] ) ? (float) $options['slow_seconds'] : 0;
        $registry = isset( $options['registry'] ) ? (array) $options['registry'] : array();
        $out = array();
        $entry = function ( $key ) use ( &$out, $registry )
        {
            if ( !isset( $out[$key] ) )
                $out[$key] = array( 'key' => $key, 'known' => isset( $registry[$key] ),
                                    'label' => isset( $registry[$key] ) ? (string) $registry[$key]['label'] : $key,
                                    'provider' => isset( $registry[$key] ) ? (string) $registry[$key]['provider'] : '', 'region' => '',
                                    'requests' => 0, 'ok' => 0, 'errors' => 0, 'kinds' => array(), 'replaced' => 0, 'replaced_reasons' => array(),
                                    'stood_in' => 0, 'cost' => 0.0, 'cost_known' => true, 'latency' => array(), 'tps' => array(),
                                    'turn_ms' => array(), 'day_ms' => array(), 'newest' => 0, 'prompt' => array(), 'prompt_sum' => 0, 'cached_sum' => 0 );
        };
        foreach ( $rows as $row )
        {
            $key = (string) $row['model_alias'] !== '' ? (string) $row['model_alias'] : (string) $row['model'];
            if ( $key === '' )
                continue;
            $entry( $key );
            $m =& $out[$key];
            $m['requests']++;
            $created = (int) $row['created'];
            if ( $created >= $m['newest'] )
            {
                $m['newest'] = $created;
                if ( (string) $row['provider'] !== '' )
                    $m['provider'] = (string) $row['provider'];
                $m['region'] = (string) $row['region'];
            }
            $latency = (int) $row['latency_ms'];
            $kind = self::errorKind( $row );
            if ( $kind === '' )
            {
                $m['ok']++;
                $m['latency'][] = $latency;
                if ( (int) $row['output_tokens'] > 0 && $latency > 0 )
                    $m['tps'][] = (int) $row['output_tokens'] * 1000 / $latency;
                $m['day_ms'][date( 'Y-m-d', $created )][] = $latency;
                // #377 what the model read: the prompt of each request and the share its server had cached
                $prompt = (int) $row['input_tokens'] + (int) $row['cache_write_tokens'] + (int) $row['cache_read_tokens'];
                if ( $prompt > 0 )
                {
                    $m['prompt'][] = $prompt;
                    $m['prompt_sum'] += $prompt;
                    $m['cached_sum'] += (int) $row['cache_read_tokens'];
                }
            }
            else
            {
                $m['errors']++;
                $m['kinds'][$kind] = ( isset( $m['kinds'][$kind] ) ? $m['kinds'][$kind] : 0 ) + 1;
            }
            // what the editor waited for one turn: all requests of the turn (or of the thread run, or the request alone)
            if ( (int) $row['conversation_id'] > 0 )
                $turn = 'c' . (int) $row['conversation_id'] . '-' . (int) $row['turn'];
            elseif ( (int) $row['run_id'] > 0 )
                $turn = 'r' . (int) $row['run_id'];
            else
                $turn = 'q' . (int) $row['id'];
            $m['turn_ms'][$turn] = ( isset( $m['turn_ms'][$turn] ) ? $m['turn_ms'][$turn] : 0 ) + $latency;
            $cost = expAiUsage::cost( $row['model'], $row['input_tokens'], $row['output_tokens'], $row['cache_write_tokens'], $row['cache_read_tokens'],
                                      (string) $row['model_alias'], (int) $row['reported_cost_micros'],
                                      isset( $row['price_factor'] ) ? (int) $row['price_factor'] : 100 );
            if ( $cost === null )
                $m['cost_known'] = false;
            else
                $m['cost'] += $cost;
            unset( $m );
            // the fallback: this row answered instead of the model the request asked for
            $requested = isset( $row['requested_model_alias'] ) ? (string) $row['requested_model_alias'] : '';
            if ( $requested !== '' && $requested !== $key )
            {
                $out[$key]['stood_in']++;
                $entry( $requested );
                $reason = (string) $row['fallback_reason'] !== '' ? (string) $row['fallback_reason'] : 'other';
                $out[$requested]['replaced']++;
                $out[$requested]['replaced_reasons'][$reason] = ( isset( $out[$requested]['replaced_reasons'][$reason] ) ? $out[$requested]['replaced_reasons'][$reason] : 0 ) + 1;
            }
        }
        expAiUsage::approxSeen();
        $result = array();
        foreach ( $out as $m )
        {
            $median = self::median( $m['latency'] );
            $perDay = array();
            $max = 0;
            foreach ( $days as $day )
            {
                $ms = isset( $m['day_ms'][$day] ) ? self::median( $m['day_ms'][$day] ) : null;
                $max = max( $max, (int) $ms );
                $perDay[] = array( 'day' => $day, 'ms' => $ms );
            }
            foreach ( $perDay as $i => $point )
                $perDay[$i]['percent'] = $max > 0 && $point['ms'] !== null ? (int) round( 100 * $point['ms'] / $max ) : 0;
            $kinds = array();
            foreach ( self::KINDS as $kind )
                if ( !empty( $m['kinds'][$kind] ) )
                    $kinds[$kind] = $m['kinds'][$kind];
            arsort( $m['replaced_reasons'] );
            $tps = self::median( $m['tps'] );
            $result[] = array(
                'key' => $m['key'], 'known' => $m['known'], 'label' => $m['label'], 'provider' => $m['provider'], 'region' => $m['region'],
                'requests' => $m['requests'], 'ok' => $m['ok'], 'errors' => $m['errors'],
                // never rounded up to 100 while a request failed
                'success_rate' => $m['requests'] ? ( $m['errors'] ? min( 99.9, round( 100 * $m['ok'] / $m['requests'], 1 ) ) : 100 ) : null,
                'kinds' => $kinds, 'replaced' => $m['replaced'], 'replaced_reasons' => $m['replaced_reasons'], 'stood_in' => $m['stood_in'],
                'latency_median_ms' => $median, 'latency_p95_ms' => self::percentile( $m['latency'], 95 ),
                'tps_median' => $tps === null ? null : round( $tps, 1 ),
                // #377 the median prompt (tokens) and the share of all prompt tokens read from the server's cache
                'prompt_median' => self::median( $m['prompt'] ),
                'cache_percent' => $m['prompt_sum'] > 0 ? (int) round( 100 * $m['cached_sum'] / $m['prompt_sum'] ) : null,
                'turn_median_ms' => self::median( array_values( $m['turn_ms'] ) ), 'turns' => count( $m['turn_ms'] ),
                'cost' => round( $m['cost'], 6 ), 'cost_known' => $m['cost_known'],
                'slow' => $slowSeconds > 0 && $median !== null && $median > $slowSeconds * 1000,
                'slow_seconds' => $median !== null ? (int) round( $median / 1000 ) : 0,
                'per_day' => $perDay,
                'log_key' => strpos( $m['key'], '/' ) === false ? str_replace( '%3A', ':', rawurlencode( $m['key'] ) ) : '',
            );
        }
        usort( $result, function ( $a, $b )
        {
            return array( $b['requests'], $b['replaced'], $a['key'] ) <=> array( $a['requests'], $a['replaced'], $b['key'] );
        } );
        return $result;
    }

    /**
     * The kind of a failed request.
     * @param array $row error_class, error_message, http_status
     * @return string '' for a successful request, else one of KINDS
     */
    public static function errorKind( array $row )
    {
        $class = isset( $row['error_class'] ) ? (string) $row['error_class'] : '';
        if ( $class === '' )
            return '';
        $message = isset( $row['error_message'] ) ? (string) $row['error_message'] : '';
        $status = isset( $row['http_status'] ) ? (int) $row['http_status'] : 0;
        if ( $class === 'veto' || $class === 'refusal' )
            return 'veto';
        if ( $class === 'budget' || strpos( $message, 'budget:' ) !== false )
            return 'budget';
        if ( $status === 429 || strpos( $message, '(limit ' ) !== false || strpos( $message, 'The limit ' ) === 0 )
            return 'limit';
        if ( strpos( $message, 'timeout after' ) !== false || strpos( $message, 'did not answer in time' ) !== false )
            return 'timeout';
        if ( expAiModelFallback::isConnectionFailureText( $message ) || strpos( $message, 'could not be reached' ) !== false )
            return 'unreachable';
        if ( $status >= 400 || $class === 'batch_errored' )
            return 'http';
        return 'other';
    }

    /** @return float|int|null the median of a list of numbers, null for none */
    public static function median( array $values )
    {
        $n = count( $values );
        if ( !$n )
            return null;
        sort( $values );
        $mid = intdiv( $n, 2 );
        return $n % 2 ? $values[$mid] : ( $values[$mid - 1] + $values[$mid] ) / 2;
    }

    /** @return float|int|null the nearest-rank percentile of a list of numbers, null for none */
    public static function percentile( array $values, $p )
    {
        $n = count( $values );
        if ( !$n )
            return null;
        sort( $values );
        return $values[max( 0, min( $n - 1, (int) ceil( $p / 100 * $n ) - 1 ) )];
    }

    /**
     * The newest finished test prompt of each alias (expAiModelTest, the job queue).
     * @return array alias => { ok, uuid, finished, ttft_s, out_tps, total_s, usage_log_id }
     */
    public static function latestTests( array $aliases )
    {
        if ( !$aliases || !class_exists( 'expAiModelTest' ) || !expAiModelTest::queueAvailable() )
            return array();
        $out = array();
        foreach ( expAiModelTest::recentJobs( 100 ) as $job )
        {
            $p = $job->payloadArray();
            if ( !isset( $p['alias'], $p['action'] ) || $p['action'] !== 'test' || isset( $out[$p['alias']] ) || !in_array( $p['alias'], $aliases, true ) || $job->isActive() )
                continue;
            $r = $job->resultArray();
            $r = is_array( $r ) ? $r : array();
            $seconds = function ( $name ) use ( $r ) { return isset( $r[$name] ) && $r[$name] !== null ? round( $r[$name] / 1000, 1 ) : null; };
            $out[$p['alias']] = array( 'ok' => !empty( $r['ok'] ), 'uuid' => (string) $job->attribute( 'uuid' ), 'finished' => (int) $job->attribute( 'finished' ),
                                       'ttft_s' => $seconds( 'ttft_ms' ), 'total_s' => $seconds( 'total_ms' ),
                                       'out_tps' => isset( $r['out_tps'] ) ? $r['out_tps'] : null,
                                       'usage_log_id' => isset( $r['usage_log_id'] ) ? (int) $r['usage_log_id'] : 0 );
        }
        return $out;
    }

    /**
     * The newest probe of each alias (bin/php/expaiprobe.php).
     * @return array alias => { pass_rate, passed, tasks, mode (live | mock), created }
     */
    public static function latestProbes( array $aliases )
    {
        if ( !$aliases )
            return array();
        $rows = eZPersistentObject::fetchObjectList( expAiProbeResult::definition(), array( 'model_alias', 'pass_rate', 'passed', 'tasks', 'probe_mode', 'created' ),
                                                     array( 'model_alias' => array( $aliases ) ), array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => 500 ), false );
        $out = array();
        foreach ( is_array( $rows ) ? $rows : array() as $row )
            if ( !isset( $out[$row['model_alias']] ) )
                $out[$row['model_alias']] = array( 'pass_rate' => (int) $row['pass_rate'], 'passed' => (int) $row['passed'], 'tasks' => (int) $row['tasks'],
                                                   'mode' => (string) $row['probe_mode'], 'created' => (int) $row['created'] );
        return $out;
    }

    /** @return string the cache file of a key */
    protected static function cacheFile( $key )
    {
        return eZSys::cacheDirectory() . '/expai/model-stats/' . $key . '.json';
    }

    /** @return array|null a cached result younger than $ttl seconds */
    protected static function readCache( $key, $ttl )
    {
        $file = self::cacheFile( $key );
        $mtime = @filemtime( $file );
        if ( !$mtime || $mtime < time() - $ttl )
            return null;
        $data = json_decode( (string) @file_get_contents( $file ), true );
        return is_array( $data ) && isset( $data['models'] ) ? $data : null;
    }

    /**
     * Writes a result (atomically; readable and replaceable by the web server and the command line user, which may
     * differ). A failure only costs the next request a computation.
     */
    protected static function writeCache( $key, array $result )
    {
        $file = self::cacheFile( $key );
        $dir = dirname( $file );
        if ( !is_dir( $dir ) && !eZDir::mkdir( $dir, false, true ) )
            return;
        $tmp = $file . '.' . getmypid() . '.tmp';
        if ( @file_put_contents( $tmp, json_encode( $result, JSON_PRESERVE_ZERO_FRACTION ) ) === false )
            return;
        @chmod( $tmp, 0666 );
        if ( !@rename( $tmp, $file ) )
            @unlink( $tmp );
    }
}
