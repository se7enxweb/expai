<?php
/**
 * End-to-end test of AI chains against the mock of tests/mock/chain_server.php: full runs of the shipped chain
 * "article" through the job queue, every step on the mock provider (models tch.opus, tch.sonnet, tch.haiku replace
 * the Anthropic models for this run only):
 *
 *   1. write -> review fails (55, a high issue) -> revise -> review passes (90) -> SEO and QA -> checkpoint: one
 *      draft (never published, labelled, logged as source chain), scores, costs and tokens per step; approve ->
 *      translate (handed to the translation sync, skipped: the draft is not published) -> done; a rerun of a
 *      recorded job changes nothing
 *   2. a text that never passes: three reviews, two revisions (MaxIterations=3), then escalated to the checkpoint
 *      with the reasons; stop -> stopped, nothing queued any more
 *   3. a broken link: flagged at the checkpoint; "ask for changes" -> revise with the editor's note -> review -> SEO
 *      -> checkpoint again, the same draft object
 *   4. the run budget: a tiny MaxCostPerRun holds the run before its first call; continue raises it and holds again
 *
 * The provider exists only in memory (id tch); its resource is paused for the queue's own workers, and the test runs
 * the jobs itself (a worker's runJob()).
 *
 *   EXPAI_CHAIN_MOCK_URL=http://127.0.0.1:18361  EXPAI_MOCK_STATE=<dir>  EXPAI_TEST_LOGIN=<login>  EXPAI_TEST_NODE=<node>
 *   php bin/php/ezexec.php extension/expai/tests/expaichain_e2e_test.php -s <admin siteaccess>
 *
 * Prints one line per check and ends with PASS or FAIL. The rows it made are listed in <state>/t-rows.json for the
 * cleanup script.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$mock = rtrim( (string) getenv( 'EXPAI_CHAIN_MOCK_URL' ), '/' );
$state = (string) getenv( 'EXPAI_MOCK_STATE' );
$login = (string) getenv( 'EXPAI_TEST_LOGIN' );
$nodeID = (int) getenv( 'EXPAI_TEST_NODE' );
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
if ( $mock === '' || !$user || $state === '' || !$nodeID || !expAiQueue::available() )
{
    echo "FAIL set EXPAI_CHAIN_MOCK_URL, EXPAI_MOCK_STATE, EXPAI_TEST_LOGIN, EXPAI_TEST_NODE; expqueue must be installed\n";
    $script->shutdown( 1 );
}
eZUser::setCurrentlyLoggedInUser( $user, (int) $user->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );

// ------------------------------------------------------------------ the mock provider and the chain's models
$ini = eZINI::instance( 'expai.ini' );
$set = function ( $group, array $values ) use ( $ini ) { foreach ( $values as $name => $value ) $ini->setVariable( $group, $name, $value ); };
$set( 'AISettings', array( 'Enabled' => 'true' ) );
$set( 'ProviderSettings', array( 'Providers' => array( 'anthropic', 'tch' ) ) );
$set( 'Limits', array( 'MaxCostPerSitePerMonth' => '' ) );
$set( 'Budgets', array( 'SiteMonthly' => '' ) );
$set( 'Provider_tch', array( 'Type' => 'anthropic', 'Label' => 'Anthropic (chain mock)', 'Endpoint' => $mock . '/anthropic/v1/messages', 'ApiKey' => 'mock-key',
                             'Region' => 'us', 'Retention' => '30d', 'Timeout' => 10, 'RetryAfterMax' => 0, 'AnthropicExtras' => 'disabled' ) );
foreach ( array( 'opus' => array( 4, 20 ), 'sonnet' => array( 2, 10 ), 'haiku' => array( 1, 5 ) ) as $name => $price )
    $set( 'Model_tch.' . $name, array( 'Provider' => 'tch', 'Id' => 'claude-' . $name . '-chainmock', 'Label' => 'tch ' . $name, 'ToolUse' => 'native',
                                        'InputPerMTok' => (string) $price[0], 'OutputPerMTok' => (string) $price[1] ) );
$set( 'ChainStep_article.write', array( 'Model' => 'tch.opus' ) );
$set( 'ChainStep_article.revise', array( 'Model' => 'tch.opus' ) );
$set( 'ChainStep_article.review', array( 'Model' => 'tch.sonnet' ) );
$set( 'ChainStep_article.seo', array( 'Model' => 'tch.haiku' ) );
$set( 'ChainSettings', array( 'TranslateHandler' => 'expAiChainTranslateSync' ) );


$made = array( 'runs' => array(), 'groups' => array(), 'jobs' => array(), 'providers' => array( 'tch' ), 'node' => $nodeID, 'started' => time() );
$remember = function () use ( &$made, $state ) { file_put_contents( $state . '/t-rows.json', json_encode( $made ) ); };
expQueue::pause( 'resource', 'tch:standard' );
$made['resources'] = array( 'tch:standard' );
$remember();

$worker = new expQueueWorker( array( 'origin' => 'cli' ) );
/** Runs a job the way a worker does (claim with a lease, run, finish), whatever its run_after. */
$runJob = function ( $job ) use ( $worker )
{
    $job = expQueueJob::fetchByID( (int) $job->attribute( 'id' ) );
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
/** Runs the queued jobs of a run until none is left; @return string[] the step labels of the jobs run */
$drive = function ( expAiChainRun $run, $max = 20 ) use ( $runJob, &$made, $remember, $user )
{
    $ran = array();
    $group = expQueue::resolveGroup( (string) $run->attribute( 'group_uuid' ) );
    for ( $i = 0; $i < $max; ++$i )
    {
        $next = null;
        foreach ( expQueue::listJobs( array( 'group_id' => (int) $group->attribute( 'id' ) ), 0, 50 ) as $job )
            if ( in_array( $job->attribute( 'state' ), array( 'queued', 'ready', 'retry' ), true ) )
                $next = $job;
        if ( !$next )
            break;
        $made['jobs'][] = (string) $next->attribute( 'uuid' );
        $remember();
        $p = $next->payloadArray();
        $ran[] = $p['apply_params']['step'] . ':' . $runJob( $next );
        // the worker switched to the job's user; the test goes on as its own
        eZUser::setCurrentlyLoggedInUser( $user, (int) $user->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
    }
    return $ran;
};
$fresh = function ( expAiChainRun $run ) { return expAiChainRun::fetch( (int) $run->attribute( 'id' ) ); };
$track = function ( expAiChainRun $run ) use ( &$made, $remember )
{
    $made['runs'][] = (int) $run->attribute( 'id' );
    $made['groups'][] = (string) $run->attribute( 'group_uuid' );
    $remember();
    return $run;
};
$stepsOf = function ( expAiChainRun $run )
{
    return array_map( function ( $s ) { return $s->attribute( 'step' ) . ':' . $s->attribute( 'state' ) . ( $s->attribute( 'verdict' ) !== '' ? ':' . $s->attribute( 'verdict' ) : '' ); }, $run->steps() );
};
$engine = new expAiChainEngine();
$requests = function () use ( $state )
{
    $out = array();
    foreach ( file( $state . '/requests.jsonl', FILE_IGNORE_NEW_LINES ) ?: array() as $line )
        $out[] = json_decode( $line, true );
    return $out;
};

// ------------------------------------------------------------------ 1. the full chain
$runs = $engine->start( 'article', $nodeID, 'Spring events for families, friendly, about 300 words.', 1 );
$run = $track( $runs[0] );
$check( 'start: one run, running, at write', array( count( $runs ), $run->attribute( 'state' ), $run->attribute( 'current_step' ) ), array( 1, 'running', 'write' ) );
$group = expQueue::resolveGroup( (string) $run->attribute( 'group_uuid' ) );
$check( 'start: a job group with the run budget ($1.00)', $group ? (int) $group->attribute( 'budget_micros' ) : 0, 1000000 );
$first = $run->steps()[0];
$job = expQueue::fetch( (string) $first->attribute( 'job_uuid' ) );
$check( 'start: the write job is an expai.request of class chain on tch:standard with the applier chain',
        $job ? array( $job->attribute( 'type' ), $job->attribute( 'class' ), $job->attribute( 'resource_key' ), $job->payloadArray()['apply'] ) : null,
        array( 'expai.request', 'chain', 'tch:standard', 'chain' ) );
$check( 'start: the request carries the output schema', isset( $job->payloadArray()['request']['output_schema']['properties']['fields'] ), true );
$check( 'start: the write prompt has the brief and the tree context', strpos( $job->payloadArray()['request']['messages'][0]['content'], 'Spring events for families' ) !== false
        && strpos( $job->payloadArray()['request']['messages'][0]['content'], 'get_tree_context:' ) !== false, true );
$ran = $drive( $run );
$check( 'run 1: the jobs ran in order', $ran, array( 'write:done', 'review:done', 'revise:done', 'review:done', 'seo:done' ) );
$run = $fresh( $run );
$check( 'run 1: waits for an editor at the checkpoint', array( $run->attribute( 'state' ), $run->attribute( 'current_step' ) ), array( 'waiting_human', 'checkpoint' ) );
$check( 'run 1: steps and verdicts', $stepsOf( $run ), array( 'write:done', 'review:done:fail', 'revise:done', 'review:done:pass', 'seo:done:pass', 'checkpoint:waiting' ) );
$scores = array_map( function ( $s ) { return (int) $s->attribute( 'score' ); }, array_values( array_filter( $run->steps(), function ( $s ) { return $s->attribute( 'step' ) === 'review'; } ) ) );
$check( 'run 1: review scores 55 then 90', $scores, array( 55, 90 ) );
$models = array_map( function ( $s ) { return $s->attribute( 'model' ); }, array_slice( $run->steps(), 0, 5 ) );
$check( 'run 1: Opus writes, Sonnet reviews, Opus revises, Haiku SEO', $models, array( 'tch.opus', 'tch.sonnet', 'tch.opus', 'tch.sonnet', 'tch.haiku' ) );
$costs = array_map( function ( $s ) { return (int) $s->attribute( 'cost_micros' ) > 0 && (int) $s->attribute( 'input_tokens' ) > 0; }, array_slice( $run->steps(), 0, 5 ) );
$check( 'run 1: every model step has tokens and a cost', $costs, array( true, true, true, true, true ) );
$sum = array_sum( array_map( function ( $s ) { return (int) $s->attribute( 'cost_micros' ); }, $run->steps() ) );
$check( 'run 1: the run cost is the sum of its steps', (int) $run->attribute( 'cost_micros' ), $sum );
$check( 'run 1: the group spent what the run cost', (int) expQueue::resolveGroup( (string) $run->attribute( 'group_uuid' ) )->attribute( 'spent_micros' ), $sum );
$usageRows = (array) eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), null, array( 'job_uuid' => (string) $first->attribute( 'job_uuid' ) ), null, null, true );
$check( 'run 1: the write step has its usage row (action queue.chain, lane standard)', count( $usageRows ) === 1 && $usageRows[0]->attribute( 'action' ) === 'queue.chain'
        && $usageRows[0]->attribute( 'lane' ) === 'standard', true );
$reqs = $requests();
$revise = array_values( array_filter( $reqs, function ( $r ) { return $r['step'] === 'Revise'; } ) );
$check( 'run 1: the revise prompt has the review issue', $revise && strpos( $revise[0]['user'], 'Say when and where the events take place.' ) !== false, true );
$check( 'run 1: every request had a schema', count( array_filter( $reqs, function ( $r ) { return empty( $r['has_schema'] ); } ) ), 0 );
$object = eZContentObject::fetch( (int) $run->attribute( 'object_id' ) );
$check( 'draft: one object written at the checkpoint', $object instanceof eZContentObject, true );
$version = $object ? $object->version( (int) $run->attribute( 'version' ) ) : null;
$check( 'draft: never published (object and version are drafts)', $object && $version ? array( (int) $object->attribute( 'status' ), (int) $version->attribute( 'status' ) ) : null,
        array( eZContentObject::STATUS_DRAFT, eZContentObjectVersion::STATUS_DRAFT ) );
$map = $object ? $object->fetchDataMap( (int) $run->attribute( 'version' ) ) : array();
$check( 'draft: title, the revised body and the short title of the SEO step', $map ? array( $map['title']->toString(), strpos( $map['body']->attribute( 'data_text' ), 'REVISED' ) !== false, $map['short_title']->toString() ) : null,
        array( 'Spring events for families', true, 'Spring for families' ) );
$log = $object ? expAiDraftLog::fetchByVersion( (int) $object->attribute( 'id' ), (int) $run->attribute( 'version' ) ) : null;
$check( 'draft: the AI marker (draft log, source chain, the Opus model)', $log ? array( $log->attribute( 'source' ), $log->attribute( 'action' ), $log->attribute( 'model' ) ) : null,
        array( 'chain', 'create', 'tch.opus' ) );
$data = $run->dataArray();
$check( 'run 1: SEO meta kept on the run', $data['seo']['title'], 'Spring events for families' );
$check( 'run 1: the link check passed', array( count( $data['links'] ), $data['links'][0]['ok'] ), array( 1, true ) );
// idempotent: the stored answer of the last job applied again changes nothing
$seoRow = $run->steps()[4];
$seoJob = expQueue::fetch( (string) $seoRow->attribute( 'job_uuid' ) );
$again = $engine->onAnswer( $seoJob, array( 'value' => array( 'fields' => array(), 'meta' => array(), 'issues' => array() ), 'tokens' => array(), 'cost_micros' => 999 ),
                            $seoJob->payloadArray()['apply_params'] );
$check( 'rerun of a recorded job: already recorded, no extra cost', array( $again['chain'], (int) $fresh( $run )->attribute( 'cost_micros' ) ), array( 'already recorded', $sum ) );
// the editor approves: translate (the translation sync: nothing to keep in step for an unpublished draft) -> done
$run = $engine->approve( $fresh( $run ) ) ? $fresh( $run ) : $fresh( $run );
$check( 'approve: done', $run->attribute( 'state' ), 'done' );
$check( 'approve: the checkpoint row closed with pass, translate skipped', array_slice( $stepsOf( $run ), 5 ), array( 'checkpoint:done:pass', 'translate:skipped' ) );
$check( 'approve: the translation sync says why it skipped', strpos( (string) array_slice( $run->steps(), -1 )[0]->attribute( 'note' ), 'not published yet' ) !== false, true );
$check( 'approve: nothing left in the queue for the run', $drive( $run ), array() );

// ------------------------------------------------------------------ 2. never passes: escalated after three reviews
$run = $track( $engine->start( 'article', $nodeID, 'A text that never passes [neverpass]', 1 )[0] );
$ran = $drive( $run );
$check( 'run 2: three reviews, two revisions', $ran, array( 'write:done', 'review:done', 'revise:done', 'review:done', 'revise:done', 'review:done', 'seo:done' ) );
$run = $fresh( $run );
$check( 'run 2: escalated to the checkpoint', array( $run->attribute( 'state' ), $run->attribute( 'current_step' ) ), array( 'waiting_human', 'checkpoint' ) );
$flags = isset( $run->dataArray()['flags'] ) ? $run->dataArray()['flags'] : array();
$check( 'run 2: the editor sees why', count( $flags ) === 1 && strpos( $flags[0], 'Review: score >= 80 (was 55)' ) === 0, true );
$run = $engine->stop( $run, 'test stop' );
$check( 'run 2: stopped', $fresh( $run )->attribute( 'state' ), 'stopped' );
$check( 'run 2: approve after stop is refused', ( function () use ( $engine, $fresh, $run ) { try { $engine->approve( $fresh( $run ) ); } catch ( expAiException $e ) { return $e->detail(); } return ''; } )(), 'state stopped' );

// ------------------------------------------------------------------ 3. a broken link, then "ask for changes"
$run = $track( $engine->start( 'article', $nodeID, 'Spring events [badlink]', 1 )[0] );
$drive( $run );
$run = $fresh( $run );
$flags = isset( $run->dataArray()['flags'] ) ? $run->dataArray()['flags'] : array();
$check( 'run 3: a broken link is flagged at the checkpoint', $run->attribute( 'state' ) === 'waiting_human' && count( $flags ) === 1 && strpos( $flags[0], 'links.broken == 0 (was 1)' ) !== false, true );
$objectID = (int) $run->attribute( 'object_id' );
$engine->requestChanges( $run, 'Add the dates of each event.' );
$ran = $drive( $run );
$check( 'run 3: changes -> revise, review, SEO, back at the checkpoint', $ran, array( 'revise:done', 'review:done', 'seo:done' ) );
$run = $fresh( $run );
$check( 'run 3: waiting again, the same draft object', array( $run->attribute( 'state' ), (int) $run->attribute( 'object_id' ) ), array( 'waiting_human', $objectID ) );
$reqs = $requests();
$last = array_values( array_filter( $reqs, function ( $r ) { return $r['step'] === 'Revise'; } ) );
$check( 'run 3: the revision got the editor\'s note', strpos( end( $last )['user'], 'Add the dates of each event.' ) !== false, true );
$check( 'run 3: two checkpoint rows, the first closed with fail', array_values( array_filter( $stepsOf( $run ), function ( $s ) { return strpos( $s, 'checkpoint' ) === 0; } ) ),
        array( 'checkpoint:done:fail', 'checkpoint:waiting' ) );
$engine->stop( $fresh( $run ), 'test stop' );

// ------------------------------------------------------------------ 4. the run budget
$set( 'Chain_article', array( 'MaxCostPerRun' => '0.000001' ) );
$run = $track( $engine->start( 'article', $nodeID, 'Budget test', 1 )[0] );
$run = $fresh( $run );
$check( 'run 4: held by the run budget before the first call', array( $run->attribute( 'state' ), $run->steps()[0]->attribute( 'state' ), $run->steps()[0]->attribute( 'job_uuid' ) ),
        array( 'held', 'held', '' ) );
$engine->resume( $run );
$run = $fresh( $run );
$check( 'run 4: continue raises the budget by one MaxCostPerRun, still too small: held again', array( $run->attribute( 'state' ), (int) $run->dataArray()['cap_extra'] ), array( 'held', 1 ) );
$engine->stop( $run, 'test stop' );
$set( 'Chain_article', array( 'MaxCostPerRun' => '1.00' ) );

// ------------------------------------------------------------------ 5. policy
$offered = array_map( function ( $c ) { return $c['id']; }, ( new expAiChainPolicy() )->chains() );
$check( 'policy: the chain limitation offers the configured chains', $offered, array_values( expAiChain::ids( expAiConfig::instance() ) ) );
$check( 'policy: the shipped chain is among them', in_array( 'article', $offered, true ), true );
$anonymous = eZUser::fetch( eZUser::anonymousId() );
$check( 'policy: anonymous may not use chains', expAiChainPolicy::mayUse( 'article', $anonymous ), false );
$check( 'policy: anonymous may not see a run', expAiChainPolicy::maySee( $run, $anonymous ), false );

expQueue::resume( 'resource', 'tch:standard' );
echo ( $failures ? 'FAIL' : 'PASS' ) . " expaichain e2e: $checks checks, $failures failure(s)\n";
$script->shutdown( $failures ? 1 : 0 );
