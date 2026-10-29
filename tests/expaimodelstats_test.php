<?php
/**
 * Test of the dashboard's "Models" card (expAiModelStats, doc/dashboard.md "Models") and of "Watch this run"
 * (expAiRunWatch): the figures per model from fixtures of usage log rows (successes, timeouts, unreachable servers,
 * HTTP errors, vetoes, limits and budgets, fallbacks from one model to another, rows without an alias); the error
 * kinds; median and 95th percentile; time per turn; latency per day; the slow flag at the web budget; the success
 * rate never rounded up to 100 while a request failed; the address of the log for a model; the card rendered from the
 * fixtures (its links, the slow flag, the text equivalents of the bars); the log conditions for a
 * model alias and one request; the cache of a period (the second read comes from the file); the remembered request
 * of "Start writing" before it was sent. No row is written; the settings change only in memory.
 *
 * Run from the installation root (EXPAI_TEST_LOGIN, default admin):
 *   php bin/php/ezexec.php extension/expai/tests/expaimodelstats_test.php -s admin
 * It prints one line per check and ends with PASS or FAIL (exit code 0 or 1).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$failures = 0;
$checks = 0;
$check = function ( $label, $actual, $expected ) use ( &$failures, &$checks )
{
    ++$checks;
    $ok = $actual === $expected;
    if ( !$ok )
        ++$failures;
    echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $label . ( $ok ? '' : ' => ' . var_export( $actual, true ) . ' (expected ' . var_export( $expected, true ) . ')' ) . "\n";
};

$login = getenv( 'EXPAI_TEST_LOGIN' ) ?: 'admin';
$user = eZUser::fetchByName( $login );
if ( $user )
    $user->loginCurrent();

// ---- fixtures -----------------------------------------------------------------------------------------------------
$day1 = mktime( 12, 0, 0, 10, 1, 2026 );
$day2 = $day1 + 86400;
$id = 0;
$row = function ( array $values ) use ( &$id, $day1 )
{
    return $values + array( 'id' => ++$id, 'created' => $day1, 'model_alias' => 'fast.one', 'model' => 'fast-1', 'provider' => 'fastp', 'region' => 'eu',
                            'error_class' => '', 'error_message' => '', 'http_status' => 200, 'latency_ms' => 1000, 'input_tokens' => 100,
                            'output_tokens' => 50, 'cache_write_tokens' => 0, 'cache_read_tokens' => 0, 'reported_cost_micros' => 0, 'price_factor' => 100,
                            'requested_model_alias' => '', 'fallback_reason' => '', 'conversation_id' => 0, 'turn' => 0, 'thread_id' => 0, 'run_id' => 0 );
};
$rows = array(
    // fast.one: five successes (1, 2, 3, 4, 10 s), two of them one turn, one on the second day
    $row( array( 'latency_ms' => 1000, 'output_tokens' => 50, 'conversation_id' => 7, 'turn' => 1 ) ),
    $row( array( 'latency_ms' => 2000, 'output_tokens' => 100, 'conversation_id' => 7, 'turn' => 1 ) ),
    $row( array( 'latency_ms' => 3000, 'output_tokens' => 30 ) ),
    $row( array( 'latency_ms' => 4000, 'output_tokens' => 40, 'created' => $day2 ) ),
    $row( array( 'latency_ms' => 10000, 'output_tokens' => 0 ) ),
    // fast.one: a veto, a model refusal, a limit, a budget, an HTTP error
    $row( array( 'error_class' => 'veto', 'error_message' => 'refused (rule:x): no', 'http_status' => 0, 'latency_ms' => 0 ) ),
    $row( array( 'error_class' => 'refusal', 'latency_ms' => 500 ) ),
    $row( array( 'error_class' => 'expAiException', 'error_message' => 'The limit %limit is reached (limit day_tokens)', 'http_status' => 0, 'latency_ms' => 0 ) ),
    $row( array( 'error_class' => 'expAiException', 'error_message' => 'The model x is too slow (web budget: slow)', 'http_status' => 0, 'latency_ms' => 0 ) ),
    $row( array( 'error_class' => 'expAiException', 'error_message' => 'refused the request (provider fastp: HTTP 402)', 'http_status' => 402, 'latency_ms' => 300 ) ),
    // slow.local: two successes (40 s, 60 s), a timeout, an unreachable server, a rate limit
    $row( array( 'model_alias' => 'slow.local', 'model' => 'slow-4b', 'provider' => 'local', 'region' => 'local', 'latency_ms' => 40000, 'output_tokens' => 20, 'run_id' => 3 ) ),
    $row( array( 'model_alias' => 'slow.local', 'model' => 'slow-4b', 'provider' => 'local', 'region' => 'local', 'latency_ms' => 60000, 'output_tokens' => 30, 'run_id' => 3 ) ),
    $row( array( 'model_alias' => 'slow.local', 'model' => 'slow-4b', 'provider' => 'local', 'region' => 'local', 'error_class' => 'expAiException', 'http_status' => 0,
                 'error_message' => 'Exponential Intelligence did not answer in time. (provider local: timeout after 120 s, no answer)', 'latency_ms' => 120000 ) ),
    $row( array( 'model_alias' => 'slow.local', 'model' => 'slow-4b', 'provider' => 'local', 'region' => 'local', 'error_class' => 'expAiException', 'http_status' => 0,
                 'error_message' => 'Exponential Intelligence could not be reached. (provider local: curl error 7)', 'latency_ms' => 5 ) ),
    $row( array( 'model_alias' => 'slow.local', 'model' => 'slow-4b', 'provider' => 'local', 'region' => 'local', 'error_class' => 'expAiException', 'http_status' => 429,
                 'error_message' => 'Too many requests', 'latency_ms' => 50 ) ),
    // the fallback: fast.one answered three requests that asked for slow.local (two timeouts, one stopped)
    $row( array( 'requested_model_alias' => 'slow.local', 'fallback_reason' => 'timeout', 'latency_ms' => 1500 ) ),
    $row( array( 'requested_model_alias' => 'slow.local', 'fallback_reason' => 'timeout', 'latency_ms' => 1500 ) ),
    $row( array( 'requested_model_alias' => 'slow.local', 'fallback_reason' => 'stopped', 'latency_ms' => 1500 ) ),
    // an old row without an alias, and one with a model id that cannot be a view parameter
    $row( array( 'model_alias' => '', 'model' => 'claude-old', 'provider' => 'anthropic', 'region' => '', 'latency_ms' => 2000 ) ),
    $row( array( 'model_alias' => '', 'model' => 'org/model:fast', 'provider' => 'hf', 'latency_ms' => 800 ) ),
);
$registry = array( 'fast.one' => array( 'label' => 'Fast One', 'provider' => 'fastp' ), 'slow.local' => array( 'label' => 'Slow Local', 'provider' => 'local' ) );
$stats = expAiModelStats::aggregate( $rows, array( 'days' => array( date( 'Y-m-d', $day1 ), date( 'Y-m-d', $day2 ) ), 'slow_seconds' => 25, 'registry' => $registry ) );
$by = array();
foreach ( $stats as $m )
    $by[$m['key']] = $m;
$fast = $by['fast.one'];
$slow = $by['slow.local'];

// ---- the figures ---------------------------------------------------------------------------------------------------
$check( 'order: most requests first', array_map( function ( $m ) { return $m['key']; }, $stats ), array( 'fast.one', 'slow.local', 'claude-old', 'org/model:fast' ) );
$check( 'fast: label and provider from the registry, region of the newest row', array( $fast['label'], $fast['provider'], $fast['region'], $fast['known'] ), array( 'Fast One', 'fastp', 'eu', true ) );
$check( 'fast: requests, successes, errors', array( $fast['requests'], $fast['ok'], $fast['errors'] ), array( 13, 8, 5 ) );
$check( 'fast: error kinds (veto and refusal are refusals)', $fast['kinds'], array( 'http' => 1, 'veto' => 2, 'limit' => 1, 'budget' => 1 ) );
$check( 'fast: success rate', $fast['success_rate'], 61.5 );
$check( 'fast: median latency of the successful requests', $fast['latency_median_ms'], 1750 );
$check( 'fast: 95th percentile (nearest rank)', $fast['latency_p95_ms'], 10000 );
$check( 'fast: median tokens per second (rows with output only)', $fast['tps_median'], 33.3 );
$check( 'fast: a turn is the sum of its requests', array( $fast['turns'], $fast['turn_median_ms'] ), array( 12, 1500 ) );
$check( 'fast: answered for another model', array( $fast['stood_in'], $fast['replaced'] ), array( 3, 0 ) );
$check( 'fast: not slow', $fast['slow'], false );
$check( 'fast: latency per day (median, percent of the slowest day)', array_map( function ( $d ) { return array( $d['ms'], $d['percent'] ); }, $fast['per_day'] ),
        array( array( 1500, 38 ), array( 4000, 100 ) ) );
$check( 'fast: log key', $fast['log_key'], 'fast.one' );
$check( 'slow: errors by kind', $slow['kinds'], array( 'timeout' => 1, 'unreachable' => 1, 'limit' => 1 ) );
$check( 'slow: replaced by the fallback, by reason', array( $slow['replaced'], $slow['replaced_reasons'] ), array( 3, array( 'timeout' => 2, 'stopped' => 1 ) ) );
$check( 'slow: median above the web budget is slow, in whole seconds', array( $slow['slow'], $slow['slow_seconds'] ), array( true, 50 ) );
$check( 'slow: the requests of one thread run are one turn', array( $slow['turns'], $slow['turn_median_ms'] ), array( 4, 50025 ) );
$check( 'slow: no successful request on the second day', $slow['per_day'][1], array( 'day' => date( 'Y-m-d', $day2 ), 'ms' => null, 'percent' => 0 ) );
$check( 'old row: keyed by its model id, not in the registry', array( $by['claude-old']['label'], $by['claude-old']['known'], $by['claude-old']['log_key'] ), array( 'claude-old', false, 'claude-old' ) );
$check( 'a model id with a slash gets no log link', $by['org/model:fast']['log_key'], '' );
$check( 'all successful: 100', $by['claude-old']['success_rate'], 100 );
$nearly = array();
for ( $i = 0; $i < 1999; $i++ )
    $nearly[] = $row( array( 'model_alias' => 'n.one' ) );
$nearly[] = $row( array( 'model_alias' => 'n.one', 'error_class' => 'expAiException', 'error_message' => 'x' ) );
$check( 'one failure in 2000 is never shown as 100', expAiModelStats::aggregate( $nearly )[0]['success_rate'], 99.9 );
$check( 'no slow threshold: never slow', expAiModelStats::aggregate( array( $row( array( 'latency_ms' => 900000 ) ) ) )[0]['slow'], false );
$check( 'no rows: no models', expAiModelStats::aggregate( array() ), array() );

// ---- the card, rendered from the fixtures ------------------------------------------------------------------------
$tpl = eZTemplate::factory();
$tpl->setVariable( 'stats', array( 'models' => $stats, 'slow_seconds' => 25 ) );
$tpl->setVariable( 'cost_enabled', true );
$tpl->setVariable( 'details_path', '/(from)/2026-10-01/(to)/2026-10-02' );
$html = (string) $tpl->fetch( 'design:expai/parts/models_card.tpl' );
$check( 'card: a row per model', substr_count( $html, '<th scope="row"' ), 4 );
$check( 'card: the model links to its requests of the period', strpos( $html, 'expai/log/(model)/fast.one/(from)/2026-10-01/(to)/2026-10-02"' ) !== false, true );
$check( 'card: the errors link to the failed ones', strpos( $html, 'expai/log/(model)/fast.one/(from)/2026-10-01/(to)/2026-10-02/(errors)/1"' ) !== false, true );
$check( 'card: the slow model is flagged', strpos( $html, 'slow: ~50 s per step' ) !== false, true );
$check( 'card: no link for a model id with a slash', strpos( $html, '(model)/org' ) === false, true );
$check( 'card: text equivalent of the bars', substr_count( $html, 'Median latency per day:' ), 4 );

// ---- error kinds, median, percentile ------------------------------------------------------------------------------
$check( 'kind: success', expAiModelStats::errorKind( array( 'error_class' => '' ) ), '' );
$check( 'kind: budget class', expAiModelStats::errorKind( array( 'error_class' => 'budget' ) ), 'budget' );
$check( 'kind: batch error', expAiModelStats::errorKind( array( 'error_class' => 'batch_errored', 'http_status' => 0 ) ), 'http' );
$check( 'kind: anything else', expAiModelStats::errorKind( array( 'error_class' => 'expAiException', 'error_message' => 'odd', 'http_status' => 0 ) ), 'other' );
$check( 'median: odd and even', array( expAiModelStats::median( array( 3, 1, 2 ) ), expAiModelStats::median( array( 4, 1, 3, 2 ) ), expAiModelStats::median( array() ) ), array( 2, 2.5, null ) );
$check( 'p95 of 1..20 is 19', expAiModelStats::percentile( range( 1, 20 ), 95 ), 19 );

// ---- the log conditions of the card's links -----------------------------------------------------------------------
$conds = expAiUsage::conditions( array( 'model_alias' => 'fast.one', 'id' => 802, 'thread_id' => 9, 'errors' => true ) );
$check( 'log: model alias, one request, one thread, errors', array( $conds['model_alias'], $conds['id'], $conds['thread_id'], $conds['error_class'] ),
        array( 'fast.one', 802, 9, array( '!=', '' ) ) );

// ---- the cache of a period ----------------------------------------------------------------------------------------
$ini = eZINI::instance( 'expai.ini' );
$hadTtl = $ini->hasVariable( 'ModelStats', 'CacheSeconds' );
$oldTtl = $hadTtl ? $ini->variable( 'ModelStats', 'CacheSeconds' ) : null;
$ini->setVariable( 'ModelStats', 'CacheSeconds', '60' );
$config = expAiConfig::instance();
$userID = (int) eZUser::currentUserID();
expAiModelStats::reset();
$first = expAiModelStats::forPeriod( $userID, 'today', $config );
expAiModelStats::reset();
$second = expAiModelStats::forPeriod( $userID, 'today', $config );
$check( 'cache: the second read of a period comes from the file', $second['cached'], true );
$check( 'cache: with the same figures', $second['models'], $first['models'] );
$check( 'cache: one day for today', count( $second['days'] ), 1 );
$check( 'cache: the slow threshold is the web budget', $second['slow_seconds'], expAiWebBudget::seconds( $config ) );
if ( $hadTtl )
    $ini->setVariable( 'ModelStats', 'CacheSeconds', $oldTtl );
else
    $ini->removeSetting( 'ModelStats', 'CacheSeconds' );

// ---- Watch this run -----------------------------------------------------------------------------------------------
$http = eZHTTPTool::instance();
$root = (int) eZINI::instance( 'content.ini' )->variable( 'NodeSettings', 'RootNode' );
expAiRunWatch::remember( $root, time() + 3600 );
$watch = expAiRunWatch::describe( $userID );
$check( 'watch: remembered location', $watch ? $watch['node_id'] : 0, $root );
$check( 'watch: not sent yet, waiting, links to the Intelligence tab', $watch ? array( $watch['state'], $watch['active'], $watch['links']['panel'], $watch['links']['log'] ) : null,
        array( 'unsent', true, 'content/view/full/' . $root . '#exp-ai', '' ) );
$check( 'watch: rendered with the link', $watch ? strpos( expAiRunWatch::render( $watch ), 'Watch this run' ) !== false : false, true );
expAiRunWatch::remember( $root, time() - expAiRunWatch::KEEP - 10 );
$check( 'watch: forgotten after KEEP', expAiRunWatch::remembered(), null );
$http->removeSessionVariable( expAiRunWatch::SESSION );

echo ( $failures ? 'FAIL' : 'PASS' ) . " ($checks checks, $failures failed)\n";
$script = eZScript::instance();
$script->shutdown( $failures ? 1 : 0 );
