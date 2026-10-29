<?php
/**
 * File containing the expAiUsage class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Usage tracking: recording a request (expAiUsageRecorder calls record()), the daily token limit, prices and cost,
 * the filtered list of expai/log with its totals and summaries, the "My usage" figures, pruning.
 */
class expAiUsage
{
    /** The most rows the log page aggregates for its totals and summaries */
    const AGGREGATE_LIMIT = 20000;

    /**
     * Stores one request.
     * @param array $row fields of expAiUsageLog
     * @return expAiUsageLog
     */
    public static function record( array $row, $audit = true )
    {
        $row += array( 'created' => time() );
        $log = new expAiUsageLog( self::cutRow( $row ) );
        $log->store();
        // the audit trail (doc/audit-trail.md): who asked which model, never what
        if ( $audit && class_exists( 'expAiAudit' ) )
            expAiAudit::request( $log );
        return $log;
    }

    /**
     * Settles a reservation (a row written before the request with reserved_tokens and reserved_micros, see
     * expAiLimits): the row gets the real numbers of the request, and its reservation goes.
     * @return expAiUsageLog
     */
    public static function settle( expAiUsageLog $log, array $row )
    {
        foreach ( self::cutRow( $row ) as $field => $value )
            $log->setAttribute( $field, $value );
        if ( expAiUsageLog::reservationColumns() )
        {
            $log->setAttribute( 'reserved_tokens', 0 );
            $log->setAttribute( 'reserved_micros', 0 );
        }
        $log->store();
        if ( class_exists( 'expAiAudit' ) )
            expAiAudit::request( $log );
        return $log;
    }

    /** @return array the row with its text fields cut to their columns */
    protected static function cutRow( array $row )
    {
        foreach ( array( 'action' => 40, 'model' => 100, 'stop_reason' => 30, 'error_class' => 60, 'error_message' => 255 ) as $field => $max )
            if ( isset( $row[$field] ) )
                $row[$field] = expAiText::cut( (string) $row[$field], $max );
        return $row;
    }

    /**
     * Conditions on expai_usage_log without the reservations of requests under way (expAiLimits): every reader of
     * requests (latencies, health, turns, threads) uses it; only the limits count reservations.
     * @param array $conds eZPersistentObject conditions
     * @return array
     */
    public static function requestsOnly( array $conds )
    {
        return expAiUsageLog::reservationColumns() ? $conds + array( 'reserved_tokens' => 0 ) : $conds;
    }

    /**
     * Removes the reservations older than expAiLimits::RESERVATION_SECONDS: a process that ended (killed, a fatal
     * error) without settling its request. They no longer count anyway; this keeps the table clean.
     * @return int rows removed
     */
    public static function removeStaleReservations( $now = null )
    {
        if ( !expAiUsageLog::reservationColumns() )
            return 0;
        $before = ( $now === null ? time() : (int) $now ) - expAiLimits::RESERVATION_SECONDS;
        $conds = array( 'reserved_tokens' => array( '>', 0 ), 'created' => array( '<', $before ) );
        $count = (int) eZPersistentObject::count( expAiUsageLog::definition(), $conds );
        if ( $count > 0 )
            eZPersistentObject::removeObject( expAiUsageLog::definition(), $conds );
        return $count;
    }

    /** the least seconds between two clean-ups of stale reservations of one process (cleanStaleReservations()) */
    const STALE_CLEANUP_SECONDS = 60;

    /** @var int when this process last removed stale reservations */
    protected static $lastStaleCleanup = 0;

    /**
     * removeStaleReservations() before a request, at most once every STALE_CLEANUP_SECONDS per process: a stale
     * reservation is not counted anyway (reservationState()), so the clean-up need not cost a count and a delete on
     * every request. The prune of the cronjob removes them every time.
     * @return int rows removed (0 when skipped)
     */
    public static function cleanStaleReservations( $now = null )
    {
        $now = $now === null ? time() : (int) $now;
        if ( self::$lastStaleCleanup > 0 && $now - self::$lastStaleCleanup < self::STALE_CLEANUP_SECONDS )
            return 0;
        self::$lastStaleCleanup = $now;
        return self::removeStaleReservations( $now );
    }

    /**
     * Is a log row the reservation of a request under way (expAiLimits)? A reservation older than
     * expAiLimits::RESERVATION_SECONDS belongs to a process that ended without settling it: it is not counted.
     * @return string '' (a request), reserved (counted) or stale (not counted)
     */
    public static function reservationState( array $row, $now = null )
    {
        if ( (int) ( isset( $row['reserved_tokens'] ) ? $row['reserved_tokens'] : 0 ) <= 0 && (int) ( isset( $row['reserved_micros'] ) ? $row['reserved_micros'] : 0 ) <= 0 )
            return '';
        $now = $now === null ? time() : (int) $now;
        return (int) ( isset( $row['created'] ) ? $row['created'] : 0 ) >= $now - expAiLimits::RESERVATION_SECONDS ? 'reserved' : 'stale';
    }

    /** @return int tokens (input + output + cache write + cache read) of a user since midnight */
    public static function tokensToday( $userID )
    {
        $sum = 0;
        $start = mktime( 0, 0, 0 );
        foreach ( (array) eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), array( 'input_tokens', 'output_tokens', 'cache_write_tokens', 'cache_read_tokens' ),
                  self::requestsOnly( array( 'user_id' => (int) $userID, 'created' => array( '>=', $start ) ) ), null, null, false ) as $row )
            $sum += (int) $row['input_tokens'] + (int) $row['output_tokens'] + (int) $row['cache_write_tokens'] + (int) $row['cache_read_tokens'];
        return $sum;
    }

    /**
     * The estimated cost of tokens from [Pricing] (prices per million tokens), or null when a price that is needed is
     * not configured for the model.
     * @param int $factorPercent the lane's price factor of the request (100 standard, 50 batch, 200 fast); a cost the
     *                           provider reported is taken as it is
     * @return float|null
     */
    public static function cost( $model, $input, $output, $cacheWrite, $cacheRead, $alias = '', $reportedMicros = 0, $factorPercent = 100 )
    {
        if ( !expAiCost::enabled() )
            return null;
        $cost = expAiCost::of( $model, $input, $output, $cacheWrite, $cacheRead, $alias, $reportedMicros );
        if ( $cost['source'] === 'unknown' )
            return null;
        if ( $cost['approx'] )
            self::$approx = true;
        if ( $cost['source'] !== 'reported' && (int) $factorPercent > 0 && (int) $factorPercent !== 100 )
            return $cost['amount'] * (int) $factorPercent / 100;
        return $cost['amount'];
    }

    /** @var bool a cost computed since the last approxSeen() call used the fallback prices */
    protected static $approx = false;

    /**
     * #389 the note beside a summed cost when some requests had no price: "15 requests without a price", or ''.
     * @param int $count
     * @return string
     */
    public static function unpricedNote( $count )
    {
        $count = (int) $count;
        if ( $count <= 0 )
            return '';
        return $count === 1 ? ezpI18n::tr( 'extension/expai/usage', '1 request without a price' )
                            : ezpI18n::tr( 'extension/expai/usage', '%count requests without a price', null, array( '%count' => expAiCost::number( $count ) ) );
    }

    /** @return bool whether a cost since the last call used the fallback prices (and forget it) */
    public static function approxSeen()
    {
        $seen = self::$approx;
        self::$approx = false;
        return $seen;
    }

    /**
     * A query result as a list: a failed query (null or false, e.g. a column missing before an update) is logged and
     * read as no rows, so a page shows empty figures instead of failing.
     * @return array
     */
    protected static function rows( $result )
    {
        if ( is_array( $result ) )
            return $result;
        eZDebug::writeError( 'expai: a query on expai_usage_log failed; is the table up to date (bin/php/expaischema.php --check)?', __METHOD__ );
        return array();
    }

    /** @return array as_of, source, currency, multiplier of [Pricing] (for the "estimate" note) */
    public static function pricingInfo()
    {
        $config = expAiConfig::instance();
        return array( 'as_of' => (string) $config->get( 'Pricing', 'PricesAsOf', '' ), 'source' => (string) $config->get( 'Pricing', 'Source', '' ),
                      'currency' => self::currency(), 'multiplier' => (string) $config->get( 'Pricing', 'Multiplier', '1.0' ) );
    }

    /**
     * A row of the CSV export, safe to open in a spreadsheet: a text value that starts with = + - @, a tab or a
     * carriage return gets a leading ' so that it is shown as text and never run as a formula (a model name, an error
     * message or a tool name comes from outside). Numbers (also negative ones) stay as they are.
     * @param array $values
     * @return array
     */
    public static function csvRow( array $values )
    {
        return array_map( function ( $value )
        {
            $value = $value === null ? '' : (string) $value;
            if ( $value === '' || is_numeric( $value ) )
                return $value;
            return strpos( "=+-@\t\r", $value[0] ) !== false ? "'" . $value : $value;
        }, array_values( $values ) );
    }

    /** @return string the currency of the costs */
    public static function currency()
    {
        return (string) expAiConfig::instance()->get( 'Pricing', 'Currency', 'USD' );
    }

    /**
     * Conditions for eZPersistentObject from the log page's filters.
     * @param array $filters from, to (Unix time), user_id, node_id, conversation_id, thread_id, id (one request), action,
     *                       model (the model id), model_alias, session_key, provider, region, errors (bool)
     * @return array
     */
    public static function conditions( array $filters )
    {
        $conds = array();
        $from = isset( $filters['from'] ) ? (int) $filters['from'] : 0;
        $to = isset( $filters['to'] ) && (int) $filters['to'] > 0 ? (int) $filters['to'] : 2147483647;
        if ( $from > 0 || $to < 2147483647 )
            $conds['created'] = array( false, array( $from, $to ) );
        foreach ( array( 'user_id', 'node_id', 'conversation_id', 'thread_id', 'id' ) as $field )
            if ( !empty( $filters[$field] ) )
                $conds[$field] = (int) $filters[$field];
        // one row left out: the reservation of the request whose limits are being checked
        if ( !empty( $filters['exclude_id'] ) && empty( $filters['id'] ) )
            $conds['id'] = array( '!=', (int) $filters['exclude_id'] );
        // the reservations of requests under way only where they are asked for (the limits)
        if ( empty( $filters['with_reservations'] ) && expAiUsageLog::reservationColumns() )
            $conds['reserved_tokens'] = 0;
        foreach ( array( 'action', 'model', 'model_alias', 'session_key', 'provider', 'region' ) as $field )
            if ( isset( $filters[$field] ) && $filters[$field] !== '' )
                $conds[$field] = (string) $filters[$field];
        if ( !empty( $filters['errors'] ) )
            $conds['error_class'] = array( '!=', '' );
        // #389 (unpriced)/1: the requests of the models without a price that the provider did not report a cost for
        if ( !empty( $filters['unpriced'] ) )
        {
            $models = self::unpricedModels();
            if ( $models )
            {
                $conds['model'] = array( $models );
                $conds['reported_cost_micros'] = 0;
            }
            else
                $conds['id'] = 0;
        }
        return $conds;
    }

    /**
     * #389 the models (ids as logged) whose requests have no price: every pair of model and model alias of the log
     * that expAiUsage::cost() cannot price. None while cost is off (nothing is priced then, and nothing claims to be).
     * @return string[]
     */
    public static function unpricedModels()
    {
        if ( !expAiCost::enabled() )
            return array();
        $db = eZDB::instance();
        $models = array();
        foreach ( (array) $db->arrayQuery( 'SELECT DISTINCT model, model_alias FROM ' . $db->escapeString( expAiUsageLog::definition()['name'] ) ) as $row )
        {
            $model = (string) $row['model'];
            if ( $model !== '' && self::cost( $model, 1000, 1000, 0, 0, (string) $row['model_alias'], 0, 100 ) === null )
                $models[$model] = $model;
        }
        self::approxSeen();
        return array_values( $models );
    }

    /** @return expAiUsageLog[] one page */
    public static function fetchPage( array $filters, $sort, $direction, $offset, $limit )
    {
        $sortable = array( 'created', 'latency_ms', 'input_tokens', 'output_tokens', 'user_id', 'model', 'action' );
        $sort = in_array( $sort, $sortable, true ) ? $sort : 'created';
        $direction = $direction === 'asc' ? 'asc' : 'desc';
        return self::rows( eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), null, self::conditions( $filters ) ?: null,
            array( $sort => $direction, 'id' => $direction ), array( 'offset' => (int) $offset, 'length' => (int) $limit ) ) );
    }

    /** @return expAiUsageLog[] the requests of one turn */
    public static function fetchTurn( $conversationID, $turn, $action = '' )
    {
        $conds = self::requestsOnly( array( 'conversation_id' => (int) $conversationID, 'turn' => (int) $turn ) );
        return self::rows( eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), null, $conds, array( 'id' => 'asc' ) ) );
    }

    /**
     * Totals and summaries of the filtered set (at most AGGREGATE_LIMIT newest rows).
     * @return array totals, per_user, per_day, turns (rollup per conversation turn), truncated
     */
    public static function aggregate( array $filters )
    {
        $rows = self::rows( eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), null, self::conditions( $filters ) ?: null,
            array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => self::AGGREGATE_LIMIT ), false ) );
        $empty = array( 'requests' => 0, 'input' => 0, 'output' => 0, 'cache_write' => 0, 'cache_read' => 0, 'latency' => 0,
                        'errors' => 0, 'cost' => 0.0, 'cost_known' => true, 'tools' => 0, 'drafts' => 0, 'reasoning' => 0,
                        // #389 requests whose model has no price: the cost is the sum of the priced ones, with this count
                        'unpriced' => 0, 'unpriced_models' => array(),
                        // the reservations of the requests under way (expAiLimits): not requests yet
                        'reserved_tokens' => 0, 'reserved_cost' => 0.0 );
        $costEnabled = expAiCost::enabled();
        $perProvider = array();
        $totals = $empty;
        $perUser = array();
        $perDay = array();
        $now = time();
        $add = function ( array &$t, array $row ) use ( $costEnabled, $now )
        {
            $reservation = expAiUsage::reservationState( $row, $now );
            if ( $reservation !== '' )
            {
                if ( $reservation === 'reserved' )
                {
                    $t['reserved_tokens'] += (int) $row['reserved_tokens'];
                    $t['reserved_cost'] += (int) $row['reserved_micros'] / 1000000;
                }
                return;
            }
            $t['requests']++;
            $t['input'] += (int) $row['input_tokens'];
            $t['output'] += (int) $row['output_tokens'];
            $t['cache_write'] += (int) $row['cache_write_tokens'];
            $t['cache_read'] += (int) $row['cache_read_tokens'];
            $t['latency'] += (int) $row['latency_ms'];
            $t['tools'] += (int) $row['tool_count'];
            $t['drafts'] += (int) $row['drafts_written'];
            if ( $row['error_class'] !== '' && $row['error_class'] !== null )
                $t['errors']++;
            $cost = expAiUsage::cost( $row['model'], $row['input_tokens'], $row['output_tokens'], $row['cache_write_tokens'], $row['cache_read_tokens'],
                                      isset( $row['model_alias'] ) ? (string) $row['model_alias'] : '', isset( $row['reported_cost_micros'] ) ? (int) $row['reported_cost_micros'] : 0,
                                      isset( $row['price_factor'] ) ? (int) $row['price_factor'] : 100 );
            $t['reasoning'] += isset( $row['reasoning_tokens'] ) ? (int) $row['reasoning_tokens'] : 0;
            if ( $cost === null )
            {
                $t['cost_known'] = false;
                if ( $costEnabled )
                {
                    $t['unpriced']++;
                    $model = (string) $row['model'];
                    $t['unpriced_models'][$model] = ( isset( $t['unpriced_models'][$model] ) ? $t['unpriced_models'][$model] : 0 ) + 1;
                }
            }
            else
                $t['cost'] += $cost;
        };
        foreach ( $rows as $row )
        {
            $add( $totals, $row );
            $user = (int) $row['user_id'];
            if ( !isset( $perUser[$user] ) )
                $perUser[$user] = $empty + array( 'user_id' => $user );
            $add( $perUser[$user], $row );
            $provider = isset( $row['provider'] ) && $row['provider'] !== '' ? (string) $row['provider'] : 'anthropic';
            $region = isset( $row['region'] ) ? (string) $row['region'] : '';
            if ( !isset( $perProvider[$provider] ) )
                $perProvider[$provider] = $empty + array( 'provider' => $provider, 'regions' => array() );
            $add( $perProvider[$provider], $row );
            if ( $region !== '' )
                $perProvider[$provider]['regions'][$region] = true;
            $day = date( 'Y-m-d', (int) $row['created'] );
            if ( !isset( $perDay[$day] ) )
                $perDay[$day] = $empty + array( 'day' => $day );
            $add( $perDay[$day], $row );
        }
        $finish = function ( array $t )
        {
            $t['avg_latency'] = $t['requests'] ? (int) round( $t['latency'] / $t['requests'] ) : 0;
            $t['error_rate'] = $t['requests'] ? round( 100 * $t['errors'] / $t['requests'], 1 ) : 0;
            arsort( $t['unpriced_models'] );
            // a sum can be shown when cost is on and at least one request had a price
            $t['cost_partial'] = $t['unpriced'] > 0 && $t['unpriced'] < $t['requests'];
            return $t;
        };
        krsort( $perDay );
        uasort( $perUser, function ( $a, $b ) { return ( $b['input'] + $b['output'] ) <=> ( $a['input'] + $a['output'] ); } );
        expAiUsage::approxSeen();
        $totals['cost_approx'] = false;
        foreach ( $rows as $row )
            if ( expAiCost::enabled() && expAiCost::of( $row['model'], 0, 0, 0, 0, isset( $row['model_alias'] ) ? (string) $row['model_alias'] : '',
                                                         isset( $row['reported_cost_micros'] ) ? (int) $row['reported_cost_micros'] : 0 )['approx'] )
                $totals['cost_approx'] = true;
        return array( 'totals' => $finish( $totals ), 'per_user' => array_map( $finish, array_values( $perUser ) ),
                      'per_day' => array_map( $finish, array_values( $perDay ) ),
                      'per_provider' => array_map( function ( $t ) use ( $finish ) { $t['regions'] = implode( ', ', array_keys( $t['regions'] ) ); return $finish( $t ); }, array_values( $perProvider ) ),
                      'truncated' => count( $rows ) >= self::AGGREGATE_LIMIT );
    }

    /**
     * The turns (one editor message and the requests it led to; a field action is a turn of its own) of the filtered
     * set, newest first, with their rollup.
     * @return array list of { key, created, user_id, node_id, action, model, requests, input, output, cache_write, cache_read, latency, tools (name => count), drafts, errors, cost, cost_known, rows (expAiUsageLog[]) }
     */
    public static function turns( array $filters, $offset, $limit, $sort = 'created', $direction = 'desc' )
    {
        $rows = self::rows( eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), null, self::conditions( $filters ) ?: null,
            array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => self::AGGREGATE_LIMIT ) ) );
        $turns = array();
        foreach ( $rows as $row )
        {
            $key = (int) $row->attribute( 'conversation_id' ) > 0
                 ? 'c' . $row->attribute( 'conversation_id' ) . '-' . $row->attribute( 'turn' )
                 : 'r' . $row->attribute( 'id' );
            if ( !isset( $turns[$key] ) )
                $turns[$key] = array( 'key' => $key, 'created' => (int) $row->attribute( 'created' ), 'user_id' => (int) $row->attribute( 'user_id' ),
                                      'node_id' => (int) $row->attribute( 'node_id' ), 'action' => (string) $row->attribute( 'action' ),
                                      'model' => (string) $row->attribute( 'model' ), 'provider' => (string) $row->attribute( 'provider' ),
                                      'region' => (string) $row->attribute( 'region' ), 'retention' => (string) $row->attribute( 'retention' ),
                                      'reasoning' => 0, 'cost_reported' => false, 'requests' => 0, 'input' => 0, 'output' => 0,
                                      'cache_write' => 0, 'cache_read' => 0, 'latency' => 0, 'tool_ms' => 0, 'tools' => array(),
                                      'drafts' => 0, 'errors' => 0, 'cost' => 0.0, 'cost_known' => true, 'rows' => array() );
            $t =& $turns[$key];
            $t['created'] = min( $t['created'], (int) $row->attribute( 'created' ) );
            $t['requests']++;
            $t['input'] += (int) $row->attribute( 'input_tokens' );
            $t['output'] += (int) $row->attribute( 'output_tokens' );
            $t['cache_write'] += (int) $row->attribute( 'cache_write_tokens' );
            $t['cache_read'] += (int) $row->attribute( 'cache_read_tokens' );
            $t['latency'] += (int) $row->attribute( 'latency_ms' );
            $t['tool_ms'] += (int) $row->attribute( 'tool_ms' );
            $t['reasoning'] += (int) $row->attribute( 'reasoning_tokens' );
            if ( (int) $row->attribute( 'reported_cost_micros' ) > 0 )
                $t['cost_reported'] = true;
            $t['drafts'] += (int) $row->attribute( 'drafts_written' );
            if ( (string) $row->attribute( 'error_class' ) !== '' )
                $t['errors']++;
            foreach ( $row->tools() as $tool )
                $t['tools'][$tool['name']] = ( isset( $t['tools'][$tool['name']] ) ? $t['tools'][$tool['name']] : 0 ) + 1;
            $cost = $row->cost();
            if ( $cost === null )
                $t['cost_known'] = false;
            else
                $t['cost'] += $cost;
            array_unshift( $t['rows'], $row );
            unset( $t );
        }
        $keys = array( 'created' => 'created', 'latency_ms' => 'latency', 'input_tokens' => 'input', 'output_tokens' => 'output' );
        $key = isset( $keys[$sort] ) ? $keys[$sort] : 'created';
        $turns = array_values( $turns );
        usort( $turns, function ( $a, $b ) use ( $key, $direction ) { return $direction === 'asc' ? $a[$key] <=> $b[$key] : $b[$key] <=> $a[$key]; } );
        return array( 'turns' => array_slice( $turns, (int) $offset, (int) $limit ), 'count' => count( $turns ) );
    }

    /**
     * "My usage": tokens and cost of a user (or everyone with $userID false) today, in 7 and in 30 days.
     * @return array today, week, month: { requests, tokens, input, output, cost, cost_known }
     */
    public static function summary( $userID )
    {
        $out = array();
        foreach ( array( 'today' => mktime( 0, 0, 0 ), 'week' => time() - 7 * 86400, 'month' => time() - 30 * 86400 ) as $name => $since )
        {
            $filters = array( 'from' => $since );
            if ( $userID !== false )
                $filters['user_id'] = (int) $userID;
            $t = self::aggregate( $filters );
            $t = $t['totals'];
            $out[$name] = array( 'requests' => $t['requests'], 'tokens' => $t['input'] + $t['output'] + $t['cache_write'] + $t['cache_read'],
                                 'input' => $t['input'], 'output' => $t['output'], 'cache' => $t['cache_write'] + $t['cache_read'],
                                 'cost' => $t['cost'], 'cost_known' => $t['cost_known'], 'unpriced' => $t['unpriced'],
                                 'unpriced_models' => $t['unpriced_models'] );
        }
        return $out;
    }

    /**
     * The time range of a period and of the period before it.
     * @param string $period today | 7d | 30d | month
     * @return array from, to, prev_from, prev_to (Unix time)
     */
    public static function periodRange( $period )
    {
        $now = time();
        switch ( $period )
        {
            case '7d':
                return array( 'from' => $now - 7 * 86400, 'to' => $now, 'prev_from' => $now - 14 * 86400, 'prev_to' => $now - 7 * 86400 - 1 );
            case '30d':
                return array( 'from' => $now - 30 * 86400, 'to' => $now, 'prev_from' => $now - 60 * 86400, 'prev_to' => $now - 30 * 86400 - 1 );
            case 'month':
                $from = mktime( 0, 0, 0, (int) date( 'n' ), 1 );
                return array( 'from' => $from, 'to' => $now, 'prev_from' => mktime( 0, 0, 0, (int) date( 'n' ) - 1, 1 ), 'prev_to' => $from - 1 );
            default:
                $from = mktime( 0, 0, 0 );
                return array( 'from' => $from, 'to' => $now, 'prev_from' => $from - 86400, 'prev_to' => $from - 1 );
        }
    }

    /**
     * The figures of the dashboard's usage summary (the same numbers as expai/log) for a user (or the site with false):
     * requests, tokens in / out / cached, cost, drafts written, published or sent for approval, average latency, errors;
     * for the period and the period before, with the change in percent.
     * @return array period, range, current, previous, change (metric => percent or null)
     */
    public static function periodSummary( $userID, $period )
    {
        $range = self::periodRange( $period );
        $figures = function ( $from, $to ) use ( $userID )
        {
            $filters = array( 'from' => $from, 'to' => $to );
            if ( $userID !== false )
                $filters['user_id'] = (int) $userID;
            $agg = expAiUsage::aggregate( $filters );
            $t = $agg['totals'];
            $conds = array( 'created' => array( false, array( $from, $to ) ) );
            if ( $userID !== false )
                $conds['user_id'] = (int) $userID;
            $written = (int) eZPersistentObject::count( expAiDraftLog::definition(), $conds );
            $sentConds = array( 'modified' => array( false, array( $from, $to ) ), 'status' => array( array( 'published', 'pending' ) ) );
            if ( $userID !== false )
                $sentConds['user_id'] = (int) $userID;
            $sent = (int) eZPersistentObject::count( expAiDraftLog::definition(), $sentConds );
            return array( 'requests' => $t['requests'], 'input' => $t['input'], 'output' => $t['output'],
                          'cached' => $t['cache_write'] + $t['cache_read'], 'tokens' => $t['input'] + $t['output'] + $t['cache_write'] + $t['cache_read'],
                          'cost' => round( $t['cost'], 6 ), 'cost_known' => $t['cost_known'], 'cost_approx' => !empty( $t['cost_approx'] ),
                          'unpriced' => $t['unpriced'], 'unpriced_models' => $t['unpriced_models'],
                          'written' => $written, 'sent' => $sent, 'avg_latency' => $t['avg_latency'], 'errors' => $t['errors'] );
        };
        $current = $figures( $range['from'], $range['to'] );
        $previous = $figures( $range['prev_from'], $range['prev_to'] );
        $change = array();
        foreach ( $current as $metric => $value )
        {
            if ( $metric === 'cost_known' || $metric === 'cost_approx' || $metric === 'unpriced' || $metric === 'unpriced_models' )
                continue;
            $before = $previous[$metric];
            $change[$metric] = $before > 0 ? round( 100 * ( $value - $before ) / $before ) : null;
        }
        return array( 'period' => $period, 'range' => $range, 'current' => $current, 'previous' => $previous, 'change' => $change );
    }

    /**
     * The daily series of the mini chart: tokens or cost per day for the last $days days (oldest first).
     * @return array list of { day, value, percent (of the highest day) }, max
     */
    public static function dailySeries( $userID, $days, $metric )
    {
        $days = max( 1, min( 62, (int) $days ) );
        $from = mktime( 0, 0, 0, (int) date( 'n' ), (int) date( 'j' ) - $days + 1 );
        $filters = array( 'from' => $from );
        if ( $userID !== false )
            $filters['user_id'] = (int) $userID;
        $agg = self::aggregate( $filters );
        $byDay = array();
        foreach ( $agg['per_day'] as $d )
            $byDay[$d['day']] = $metric === 'cost' ? round( $d['cost'], 4 ) : $d['input'] + $d['output'] + $d['cache_write'] + $d['cache_read'];
        $series = array();
        $max = 0;
        for ( $i = 0; $i < $days; $i++ )
        {
            $day = date( 'Y-m-d', $from + $i * 86400 + 43200 );
            $value = isset( $byDay[$day] ) ? $byDay[$day] : 0;
            $max = max( $max, $value );
            $time = $from + $i * 86400 + 43200;
            $series[] = array( 'day' => $day, 'value' => $value, 'today' => $day === date( 'Y-m-d' ),
                               'label' => eZLocale::instance()->shortMonthName( (int) date( 'n', $time ) ) . ' ' . date( 'j', $time ) );
        }
        foreach ( $series as $i => $point )
            $series[$i]['percent'] = $max > 0 ? round( 100 * $point['value'] / $max ) : 0;
        return array( 'series' => $series, 'max' => $max, 'metric' => $metric, 'days' => $days );
    }

    /**
     * The notice for a slow model (Slow=true, e.g. a local model on a CPU): how long one request took on average
     * (the last 20 successful requests of the alias in the usage log), so editors know what to expect.
     * @return string translated, '' for a model that is not slow
     */
    public static function slowNotice( $alias, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $model = $config->registry()->getModel( $alias );
        if ( !$model || !$model['slow'] )
            return '';
        $rows = self::rows( eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), array( 'latency_ms' ),
            self::requestsOnly( array( 'model_alias' => (string) $alias, 'error_class' => '' ) ), array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => 20 ), false ) );
        if ( !$rows )
            return ezpI18n::tr( 'extension/expai/model', 'This model runs on this server and is slow: an answer can take several minutes.' );
        $avg = array_sum( array_map( function ( $r ) { return (int) $r['latency_ms']; }, $rows ) ) / count( $rows ) / 1000;
        return ezpI18n::tr( 'extension/expai/model', 'This model runs on this server and is slow: each step takes about %seconds seconds (measured), an answer with lookups several minutes.',
                            null, array( '%seconds' => (int) round( $avg ) ) );
    }

    /** @return int rows removed: older than [UsageSettings] RetentionDays (0 keeps everything) */
    public static function prune( $days = null )
    {
        self::removeStaleReservations();
        $days = $days === null ? expAiConfig::instance()->int( 'UsageSettings', 'RetentionDays', 365, 0 ) : (int) $days;
        if ( $days <= 0 )
            return 0;
        $before = time() - $days * 86400;
        $count = (int) eZPersistentObject::count( expAiUsageLog::definition(), array( 'created' => array( '<', $before ) ) );
        if ( $count )
            eZPersistentObject::removeObject( expAiUsageLog::definition(), array( 'created' => array( '<', $before ) ) );
        return $count;
    }
}
