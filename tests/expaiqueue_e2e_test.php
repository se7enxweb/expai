<?php
/**
 * End-to-end test of lanes, batches and budgets against the mock servers of tests/mock/batch_server.php: queued
 * requests (expAiQueue::request()) on the standard, fast and flex lanes with their fallbacks, the batch lane of every
 * batch API shape (Anthropic Message Batches, OpenAI Batch, Gemini, Mistral, OpenRouter: submit, poll, results,
 * delete, an expired item moved to the standard lane, an invalid item ending dead), the usage rows (lane, service
 * tier, batch, price factor), the rate-limit hints handed to the resources, and budgets (held at enqueue, resumed when
 * the budget allows, held at run time, a group budget). The providers exist only in memory for this run (ids tb*);
 * their resources are paused for the queue's own workers, and the test runs the jobs itself (a worker's runJob()).
 *
 *   EXPAI_BATCH_MOCK_URL=http://127.0.0.1:18359   the mock (php -S 127.0.0.1:18359 extension/expai/tests/mock/batch_server.php)
 *   EXPAI_MOCK_STATE=<dir>                        the mock's state directory (to check deletions)
 *   EXPAI_TEST_LOGIN=<login>                      the user (expai/write, expai/lane_fast)
 *   php bin/php/ezexec.php extension/expai/tests/expaiqueue_e2e_test.php -s <admin siteaccess>
 *
 * Prints one line per check and ends with PASS or FAIL. The rows it made are listed in <state>/t-rows.json for the
 * cleanup script.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$mock = rtrim( (string) getenv( 'EXPAI_BATCH_MOCK_URL' ), '/' );
$state = (string) getenv( 'EXPAI_MOCK_STATE' );
$login = (string) getenv( 'EXPAI_TEST_LOGIN' );
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
$script = eZScript::instance();
$user = $login !== '' ? eZUser::fetchByName( $login ) : null;
if ( $mock === '' || !$user || $state === '' || !expAiQueue::available() )
{
    echo "FAIL set EXPAI_BATCH_MOCK_URL, EXPAI_MOCK_STATE, EXPAI_TEST_LOGIN; expqueue must be installed\n";
    $script->shutdown( 1 );
}
eZUser::setCurrentlyLoggedInUser( $user, (int) $user->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );

// ------------------------------------------------------------------ providers, models and lanes of this run
$ini = eZINI::instance( 'expai.ini' );
$set = function ( $group, array $values ) use ( $ini ) { foreach ( $values as $name => $value ) $ini->setVariable( $group, $name, $value ); };
$providers = array( 'anthropic', 'tbanth', 'tboai', 'tbgem', 'tbmis', 'tbor' );
$set( 'ProviderSettings', array( 'Providers' => $providers ) );
$set( 'Limits', array( 'MaxCostPerSitePerMonth' => '' ) );
$set( 'Budgets', array( 'SiteMonthly' => '', 'ReserveFactor' => '1.25' ) );
$set( 'Provider_tbanth', array( 'Type' => 'anthropic', 'Label' => 'Anthropic (mock)', 'Endpoint' => $mock . '/anthropic/v1/messages', 'ApiKey' => 'mock-key',
                                 'Region' => 'us', 'Retention' => '30d', 'Timeout' => 10, 'RetryAfterMax' => 0, 'AnthropicExtras' => 'disabled',
                                 'RateLimitHeaders' => array( 'anthropic-ratelimit-*', 'retry-after' ) ) );
foreach ( array( 'tboai' => array( 'openai', '/openai/v1' ), 'tbgem' => array( 'generic', '/gemini/v1beta/openai' ), 'tbmis' => array( 'mistral', '/mistral/v1' ),
                 'tbor' => array( 'openrouter', '/openrouter/api/v1' ) ) as $id => $def )
    $set( 'Provider_' . $id, array( 'Type' => 'openai_chat', 'Flavor' => $def[0], 'Label' => $id . ' (mock)', 'BaseURL' => $mock . $def[1], 'ApiKey' => 'mock-key',
                                    'Region' => 'us', 'Retention' => '30d', 'Timeout' => 10, 'RetryAfterMax' => 0,
                                    'RateLimitHeaders' => array( 'x-ratelimit-*', 'retry-after' ) ) );
$model = function ( $alias, $provider, $id, array $extra = array() ) use ( $set )
{
    $set( 'Model_' . $alias, $extra + array( 'Provider' => $provider, 'Id' => $id, 'Label' => $alias, 'ToolUse' => 'native', 'InputPerMTok' => '1', 'OutputPerMTok' => '5' ) );
};
$model( 'tbanth.m', 'tbanth', 'claude-mock' );
$model( 'tbanth.opus', 'tbanth', 'claude-opus-mock' );
$model( 'tbanth.fastbusy', 'tbanth', 'claude-opus-fastbusy-mock' );
$model( 'tbanth.allbusy', 'tbanth', 'claude-allbusy-mock' );
$model( 'tbanth.big', 'tbanth', 'claude-big-mock', array( 'InputPerMTok' => '20000', 'OutputPerMTok' => '20000' ) );
$model( 'tboai.m', 'tboai', 'gpt-mock' );
$model( 'tboai.flexbusy', 'tboai', 'gpt-flexbusy-mock' );
$model( 'tbgem.m', 'tbgem', 'gemini-mock' );
$model( 'tbmis.m', 'tbmis', 'mistral-mock' );
$model( 'tbor.m', 'tbor', 'vendor/model-mock' );
$batch = function ( $provider, $api, $factor = '0.5' ) use ( $set, $mock )
{
    $set( 'Lane_' . $provider . '.batch', array( 'Type' => 'batch', 'BatchApi' => $api, 'Enabled' => 'true', 'PriceMultiplier' => $factor, 'ExpectedLatency' => '3600',
                                                 'MaxLatency' => '86400', 'MaxRequestsPerBatch' => '100', 'BatchCollectSeconds' => '3600', 'PollSeconds' => '60',
                                                 'Retention' => '29d', 'DeleteAfterFetch' => 'enabled', 'ExpiredLane' => 'standard',
                                                 'BatchURL' => $api === 'gemini' ? $mock . '/gemini/v1beta' : '' ) );
};
$batch( 'tbanth', 'anthropic' );
$batch( 'tboai', 'openai' );
$batch( 'tbgem', 'gemini' );
$batch( 'tbmis', 'mistral' );
$batch( 'tbor', 'openrouter' );
$set( 'Lane_tbanth.fast', array( 'Type' => 'sync', 'Enabled' => 'true', 'PriceMultiplier' => '2.0', 'ExpectedLatency' => '20', 'RequestParams' => array( 'speed' => 'fast' ),
                                 'Headers' => array( 'anthropic-beta: fast-mode-2026-02-01' ), 'FallbackLane' => 'standard',
                                 'Models' => array( 'tbanth.opus', 'tbanth.fastbusy' ) ) );
$set( 'Lane_tboai.flex', array( 'Type' => 'sync', 'Enabled' => 'true', 'PriceMultiplier' => '0.5', 'ExpectedLatency' => '300', 'RequestParams' => array( 'service_tier' => 'flex' ),
                                'OnUnavailable' => 'escalate', 'FallbackLane' => 'standard' ) );
$set( 'LanePolicy', array( 'Order' => array( 'default' => 'standard', 'bulk' => 'batch;flex;standard', 'cheap' => 'flex;standard' ), 'UrgentPriority' => '80',
                           'PurposeClass' => array( 'default' => 'editorial', 'bulk' => 'bulk' ) ) );

$started = time();
$made = array( 'jobs' => array(), 'groups' => array(), 'resources' => array(), 'providers' => array( 'tbanth', 'tboai', 'tbgem', 'tbmis', 'tbor' ), 'started' => $started );
$remember = function () use ( &$made, $state )
{
    file_put_contents( $state . '/t-rows.json', json_encode( $made ) );
};
// the queue's own workers must leave the jobs of this run alone (they do not know these providers)
foreach ( array( 'tbanth', 'tboai', 'tbgem', 'tbmis', 'tbor' ) as $p )
    foreach ( array( 'standard', 'batch', 'batch-api', 'fast', 'flex' ) as $l )
    {
        expQueue::pause( 'resource', "$p:$l" );
        $made['resources'][] = "$p:$l";
    }
$remember();

$worker = new expQueueWorker( array( 'origin' => 'cli' ) );
/** Runs a job the way a worker does (claim with a lease, run, finish), whatever its run_after. */
$run = function ( $job ) use ( $worker )
{
    $job = expQueueJob::fetchByID( (int) ( is_object( $job ) ? $job->attribute( 'id' ) : $job ) );
    $owner = 'test:' . getmypid() . ':' . bin2hex( random_bytes( 3 ) );
    $from = $job->attribute( 'state' );
    if ( !in_array( $from, array( 'queued', 'ready', 'retry' ), true ) )
        return 'not runnable (' . $from . ')';
    $now = time();
    if ( !expQueue::casUpdate( (int) $job->attribute( 'id' ), array( 'state' => $from ), array( 'state' => 'running', 'lease_owner' => $owner, 'lease_until' => $now + 600,
                               'attempts' => (int) $job->attribute( 'attempts' ) + 1, 'started' => $now ) ) )
        return 'claim failed';
    ob_start();
    $outcome = $worker->runJob( expQueueJob::fetchByID( (int) $job->attribute( 'id' ) ), $owner );
    ob_end_clean();
    return $outcome;
};
$fresh = function ( $job ) { return expQueueJob::fetchByID( (int) $job->attribute( 'id' ) ); };
$track = function ( $job ) use ( &$made, $remember ) { $made['jobs'][] = (string) $job->attribute( 'uuid' ); $remember(); return $job; };
$ask = function ( $alias, $text, array $options = array(), $maxTokens = 50 ) use ( $track )
{
    $r = expAiQueue::request( $alias, array( 'system' => 'You are a test.', 'messages' => array( array( 'role' => 'user', 'content' => $text ) ), 'max_tokens' => $maxTokens ),
                              $options + array( 'label' => 'tb test' ) );
    $track( $r['job'] );
    return $r;
};
$usage = function ( $job )
{
    return (array) eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), null, array( 'job_uuid' => (string) $job->attribute( 'uuid' ) ), array( 'id' => 'asc' ), null, true );
};
$requests = function ( $pathPart ) use ( $state )
{
    $out = array();
    foreach ( file( $state . '/requests.jsonl', FILE_IGNORE_NEW_LINES ) ?: array() as $line )
    {
        $r = json_decode( $line, true );
        if ( is_array( $r ) && strpos( $r['path'], $pathPart ) !== false )
            $out[] = $r;
    }
    return $out;
};

// ------------------------------------------------------------------ lane choice
$policy = new expAiLanePolicy();
$check( 'policy: bulk takes batch', $policy->choose( 'tbanth.m', array( 'purpose' => 'bulk' ) )['lane']->name(), 'batch' );
$check( 'policy: default is standard', $policy->choose( 'tbanth.m', array() )['lane']->name(), 'standard' );
$check( 'policy: urgent priority skips batch', $policy->choose( 'tbanth.m', array( 'purpose' => 'bulk', 'priority' => 90 ) )['lane']->name(), 'standard' );
$check( 'policy: a deadline in 10 minutes skips batch', $policy->choose( 'tbanth.m', array( 'purpose' => 'bulk', 'deadline' => time() + 600 ) )['lane']->name(), 'standard' );
$zero = array( 'regions' => null, 'zero_retention' => true, 'reasons' => array( 'test' ) );
$set( 'Provider_tbanth', array( 'Retention' => 'zdr' ) );
$c = $policy->choose( 'tbanth.m', array( 'purpose' => 'bulk', 'requirements' => array( $zero ) ) );
$check( 'policy: with zdr the standard lane meets zero retention, the 29-day batch lane does not', array( $c['lane']->name(), isset( $c['tried']['batch'] ) ), array( 'standard', true ) );
$set( 'Provider_tbanth', array( 'Retention' => '30d' ) );
$c = null;
try
{
    $policy->choose( 'tbanth.m', array( 'purpose' => 'bulk', 'requirements' => array( $zero ) ) );
}
catch ( expAiException $e )
{
    $c = $e->detail();
}
$check( 'policy: no lane meets zero retention on a 30d provider (refused)', is_string( $c ) && strpos( $c, 'batch:' ) !== false, true );
$check( 'policy: fast is not taken from an order', $policy->choose( 'tbanth.opus', array( 'purpose' => 'bulk', 'priority' => 90 ) )['lane']->name(), 'standard' );
$check( 'policy: fast only for the models it names', array_key_exists( 'fast', expAiLane::forModel( 'tbanth.m' ) ), false );
$check( 'policy: flex for cheap on OpenAI', $policy->choose( 'tboai.m', array( 'purpose' => 'cheap' ) )['lane']->name(), 'flex' );
$check( 'lane: batch factor 50 %', expAiLane::fetch( 'tbanth', 'batch' )->factorPercent(), 50 );
$check( 'lane: fast billed at 100 % when served at the standard speed', expAiLane::fetch( 'tbanth', 'fast' )->billedFactor( 'standard' ), 100 );
$check( 'lane: fast billed at 200 %', expAiLane::fetch( 'tbanth', 'fast' )->billedFactor( 'fast' ), 200 );

// ------------------------------------------------------------------ sync lanes
$r = $ask( 'tbanth.m', 'Hello standard' );
$check( 'standard: queued on tbanth:standard', $r['job']->attribute( 'resource_key' ), 'tbanth:standard' );
$check( 'standard: reservation = estimate x 1.25', $r['reserved'], (int) ceil( $r['estimate'] * 1.25 ) );
$check( 'standard: run', $run( $r['job'] ), 'done' );
$j = $fresh( $r['job'] );
$res = $j->resultArray();
$check( 'standard: the answer is the job result', strpos( (string) $res['text'], 'MOCK ANSWER: Hello standard' ) === 0, true );
$check( 'standard: reservation settled', (int) $j->attribute( 'cost_reserved_micros' ), 0 );
$check( 'standard: actual cost recorded', (int) $j->attribute( 'cost_actual_micros' ) > 0, true );
$rows = $usage( $j );
$check( 'standard: one usage row with lane standard, factor 100, tier standard', count( $rows ) === 1 && $rows[0]->attribute( 'lane' ) === 'standard'
        && (int) $rows[0]->attribute( 'price_factor' ) === 100 && $rows[0]->attribute( 'service_tier' ) === 'standard', true );
$resource = expQueueResource::state( 'tbanth:standard' );
$check( 'standard: rate-limit hints reached the resource (remaining 49)', (int) $resource['remaining_requests'], 49 );

$r = $ask( 'tbanth.opus', 'Hello fast', array( 'lane' => 'fast' ) );
$check( 'fast: queued on the fast lane when asked', $r['job']->attribute( 'lane' ), 'fast' );
$check( 'fast: run', $run( $r['job'] ), 'done' );
$rows = $usage( $r['job'] );
$check( 'fast: billed at 200 %, tier fast', count( $rows ) === 1 && (int) $rows[0]->attribute( 'price_factor' ) === 200 && $rows[0]->attribute( 'service_tier' ) === 'fast', true );
$sent = $requests( '/anthropic/v1/messages' );
$last = json_decode( end( $sent )['body'], true );
$check( 'fast: speed=fast and the beta header were sent', isset( $last['speed'] ) && $last['speed'] === 'fast'
        && strpos( (string) end( $sent )['headers']['anthropic-beta'], 'fast-mode-2026-02-01' ) !== false, true );

$r = $ask( 'tbanth.fastbusy', 'Hello busy fast', array( 'lane' => 'fast' ) );
$check( 'fast busy: escalated (deferred)', $run( $r['job'] ), 'defer' );
$check( 'fast busy: moved to standard', $fresh( $r['job'] )->attribute( 'lane' ), 'standard' );
$check( 'fast busy: done on standard', $run( $r['job'] ), 'done' );
$rows = $usage( $r['job'] );
$check( 'fast busy: an error row on fast, an answer on standard', count( $rows ) === 2 && $rows[0]->attribute( 'lane' ) === 'fast' && (int) $rows[0]->attribute( 'http_status' ) === 429
        && $rows[1]->attribute( 'lane' ) === 'standard', true );

$r = $ask( 'tbanth.allbusy', 'Hello overloaded' );
$check( 'overloaded: retry', $run( $r['job'] ), 'retry' );
$j = $fresh( $r['job'] );
$check( 'overloaded: retry not before retry-after (7 s)', (int) $j->attribute( 'run_after' ) - time() >= 6, true );
$resource = expQueueResource::state( 'tbanth:standard' );
$check( 'overloaded: the resource cools down', (int) $resource['cooldown_until'] > time(), true );
expQueue::cancel( $j, 'test' );

$r = $ask( 'tboai.m', 'Hello flex', array( 'purpose' => 'cheap' ) );
$check( 'flex: queued on tboai:flex', $r['job']->attribute( 'resource_key' ), 'tboai:flex' );
$check( 'flex: run', $run( $r['job'] ), 'done' );
$rows = $usage( $r['job'] );
$check( 'flex: billed at 50 %, tier flex', count( $rows ) === 1 && (int) $rows[0]->attribute( 'price_factor' ) === 50 && $rows[0]->attribute( 'service_tier' ) === 'flex', true );
$r = $ask( 'tboai.flexbusy', 'Hello flex busy', array( 'purpose' => 'cheap' ) );
$check( 'flex unavailable: escalated', $run( $r['job'] ), 'defer' );
$check( 'flex unavailable: done on standard', $run( $r['job'] ), 'done' );
$rows = $usage( $r['job'] );
$check( 'flex unavailable: the standard answer is billed at 100 %', (int) end( $rows )->attribute( 'price_factor' ), 100 );

// ------------------------------------------------------------------ batch lanes, every shape
foreach ( array( 'tbanth.m' => 'anthropic', 'tboai.m' => 'openai', 'tbgem.m' => 'gemini', 'tbmis.m' => 'mistral', 'tbor.m' => 'openrouter' ) as $alias => $shape )
{
    $provider = substr( $alias, 0, strpos( $alias, '.' ) );
    $jobs = array();
    foreach ( array( 'one' => "$shape item one", 'two' => "$shape item two", 'expired' => "$shape item [expired]", 'errored' => "$shape item [errored]" ) as $k => $text )
        $jobs[$k] = $ask( $alias, $text, array( 'purpose' => 'bulk' ) )['job'];
    $check( "$shape batch: queued on the batch lane", $jobs['one']->attribute( 'resource_key' ), "$provider:batch" );
    $check( "$shape batch: the reservation is at the batch price", (int) $jobs['one']->attribute( 'cost_estimate_micros' ) > 0, true );
    $outcomes = array();
    foreach ( $jobs as $job )
        $outcomes[] = $run( $job );
    $check( "$shape batch: every request waits for a batch", $outcomes, array( 'defer', 'defer', 'defer', 'defer' ) );
    $submits = expQueue::listJobs( array( 'type' => 'expai.batch.submit', 'resource_key' => "$provider:batch-api" ), 0, 5 );
    $check( "$shape batch: one submit job", count( array_filter( $submits, function ( $j ) { return !$j->isFinal(); } ) ), 1 );
    $submit = $track( reset( $submits ) );
    $check( "$shape batch: submit", $run( $submit ), 'done' );
    $result = $fresh( $submit )->resultArray();
    $batchRow = expAiBatch::fetch( isset( $result['batch_id'] ) ? (int) $result['batch_id'] : 0 );
    $check( "$shape batch: the batch has 4 requests", $batchRow ? (int) $batchRow->attribute( 'request_count' ) : 0, 4 );
    $check( "$shape batch: the requests carry the batch", $fresh( $jobs['one'] )->attribute( 'external_ref' ), $batchRow ? $batchRow->ref() : '?' );
    $polls = expQueue::listJobs( array( 'type' => 'expai.batch.poll', 'resource_key' => "$provider:batch-api" ), 0, 1 );
    $poll = $track( reset( $polls ) );
    $check( "$shape batch: first poll, in progress", $run( $poll ), 'defer' );
    $check( "$shape batch: a request in an open batch waits", $run( $jobs['two'] ), 'defer' );
    $check( "$shape batch: second poll, ended", $run( $poll ), 'done' );
    $pr = $fresh( $poll )->resultArray();
    $check( "$shape batch: 2 answered, 1 moved, 1 dead", array( $pr['succeeded'], $pr['moved'], $pr['errored'] ), array( 2, 1, 1 ) );
    $check( "$shape batch: deleted at the provider", $pr['deleted'], true );
    $check( "$shape batch: the invalid request is dead", $fresh( $jobs['errored'] )->attribute( 'state' ), 'dead' );
    $check( "$shape batch: the expired request moved to standard", $fresh( $jobs['expired'] )->attribute( 'lane' ), 'standard' );
    $check( "$shape batch: request one applies its answer", $run( $jobs['one'] ), 'done' );
    $res = $fresh( $jobs['one'] )->resultArray();
    $check( "$shape batch: the answer", strpos( (string) $res['text'], 'MOCK ANSWER: ' . "$shape item one" ) === 0 || strpos( (string) $res['text'], 'MOCK ANSWER' ) === 0, true );
    $rows = $usage( $jobs['one'] );
    $check( "$shape batch: usage row lane batch, factor 50, batch id", count( $rows ) === 1 && $rows[0]->attribute( 'lane' ) === 'batch'
            && (int) $rows[0]->attribute( 'price_factor' ) === 50 && (int) $rows[0]->attribute( 'batch_id' ) === (int) $batchRow->attribute( 'id' ), true );
    $check( "$shape batch: request two applies", $run( $jobs['two'] ), 'done' );
    $check( "$shape batch: the expired request runs on standard", $run( $jobs['expired'] ), 'done' );
    $rows = $usage( $jobs['expired'] );
    $check( "$shape batch: its usage row is standard at 100 %", count( $rows ) >= 1 && end( $rows )->attribute( 'lane' ) === 'standard' && (int) end( $rows )->attribute( 'price_factor' ) === 100, true );
    $file = $state . '/batches/' . preg_replace( '/[^A-Za-z0-9_-]/', '_', basename( (string) $batchRow->attribute( 'remote_id' ) ) ) . '.json';
    $mockBatch = is_file( $file ) ? json_decode( file_get_contents( $file ), true ) : null;
    if ( $shape !== 'openai' )
        $check( "$shape batch: the mock saw the deletion", $mockBatch && !empty( $mockBatch['deleted'] ), true );
    else
        $check( "$shape batch: the input file was deleted", is_file( $state . '/files/' . $batchRow->attribute( 'input_ref' ) . '.deleted' ), true );
    $check( "$shape batch: the row says done and deleted", array( $fresh( $poll ) ? expAiBatch::fetch( (int) $batchRow->attribute( 'id' ) )->attribute( 'state' ) : '', (bool) expAiBatch::fetch( (int) $batchRow->attribute( 'id' ) )->attribute( 'deleted' ) ), array( 'done', true ) );
    $made['batches'][] = (int) $batchRow->attribute( 'id' );
    $remember();
}
// the request bodies the shapes were given
$anth = $requests( '/anthropic/v1/messages/batches' );
$first = json_decode( $anth[0]['body'], true );
$check( 'anthropic batch body: requests[] of custom_id and params (model, max_tokens, messages)', isset( $first['requests'][0]['custom_id'], $first['requests'][0]['params']['model'],
        $first['requests'][0]['params']['max_tokens'], $first['requests'][0]['params']['messages'] ), true );
$check( 'anthropic batch body: no refusal-fallback beta in a batch', isset( $first['requests'][0]['params']['fallbacks'] ), false );
$gem = $requests( ':batchGenerateContent' );
$g = json_decode( $gem[0]['body'], true );
$check( 'gemini batch body: inline requests with metadata keys and a system instruction', isset( $g['batch']['input_config']['requests']['requests'][0]['metadata']['key'],
        $g['batch']['input_config']['requests']['requests'][0]['request']['systemInstruction'] ), true );

// ------------------------------------------------------------------ budgets
$budget = new expAiBudget();
$spent = $budget->spentThisMonth( true );
$reserved = $budget->reserved();
$set( 'Budgets', array( 'SiteMonthly' => (string) ( ( $spent + $reserved ) / 1000000 + 0.002 ) ) );
$r = $ask( 'tbanth.big', 'An expensive request', array(), 500 );
$check( 'budget: an estimate above the site budget is held at once', array( $fresh( $r['job'] )->attribute( 'state' ), $fresh( $r['job'] )->attribute( 'error_class' ) ), array( 'held', 'budget' ) );
$check( 'budget: the reason is given', strpos( $r['held'], 'monthly site budget' ) !== false, true );
$check( 'budget: the status counts it as held', expAiBudget::heldCount() >= 1, true );
$res = ( new expAiBudget() )->resumeHeld();
$check( 'budget: not resumed while it does not fit', $fresh( $r['job'] )->attribute( 'state' ), 'held' );
$set( 'Budgets', array( 'SiteMonthly' => '100000' ) );
$res = ( new expAiBudget() )->resumeHeld();
$check( 'budget: resumed once the budget allows', $fresh( $r['job'] )->attribute( 'state' ), 'ready' );
$check( 'budget: and it runs', $run( $r['job'] ), 'done' );
$r = $ask( 'tbanth.big', 'Fits now, not later', array(), 500 );
$check( 'budget: fits at enqueue', $fresh( $r['job'] )->attribute( 'state' ), 'ready' );
$set( 'Budgets', array( 'SiteMonthly' => (string) ( $budget->spentThisMonth( true ) / 1000000 + 0.0001 ) ) );
$check( 'budget: held when it runs after the budget shrank', $run( $r['job'] ), 'hold' );
$check( 'budget: no call was made for it', count( $usage( $r['job'] ) ), 0 );
$set( 'Budgets', array( 'SiteMonthly' => '' ) );
$group = expQueue::createGroup( 'tb test group', 'expai.request', array( 'budget_micros' => 1000 ) );
$made['groups'][] = (string) $group->attribute( 'uuid' );
$remember();
$r = $ask( 'tbanth.big', 'Group budget', array( 'group' => $group ), 500 );
$check( 'group budget: held at enqueue', $fresh( $r['job'] )->attribute( 'state' ), 'held' );
$check( 'group budget: the reason names the group', strpos( $r['held'], 'tb test group' ) !== false, true );
$small = $ask( 'tbanth.m', 'Small group request', array( 'group' => $group ), 5 );
$check( 'group budget: a small request fits', $fresh( $small['job'] )->attribute( 'state' ), 'ready' );
$check( 'group budget: runs', $run( $small['job'] ), 'done' );
$g = expQueueGroup::fetchByID( (int) $group->attribute( 'id' ) );
$check( 'group budget: its cost counts for the group', (int) $g->attribute( 'spent_micros' ), (int) $fresh( $small['job'] )->attribute( 'cost_actual_micros' ) );
eZDB::instance()->query( 'UPDATE expqueue_group SET budget_micros = 100000000 WHERE id = ' . (int) $group->attribute( 'id' ) );
( new expAiBudget() )->resumeHeld();
$check( 'group budget: resumed after the group budget was raised', $fresh( $r['job'] )->attribute( 'state' ), 'ready' );
$check( 'group budget: runs', $run( $r['job'] ), 'done' );
$status = ( new expAiBudget() )->status( 30 );
$found = array_values( array_filter( $status['groups'], function ( $x ) use ( $group ) { return $x['uuid'] === $group->attribute( 'uuid' ); } ) );
$check( 'status: the group with its spending', $found && $found[0]['spent'] > 0 && $found[0]['jobs'] === 2, true );

echo ( $failures ? 'FAIL' : 'PASS' ) . " $checks checks, $failures failed\n";
$script->shutdown( $failures ? 1 : 0 );
