<?php
/**
 * Tests of Vector's parallel runs and steering (doc/parallel-runs.md) on the database of the installation, without
 * a real model call (a stub provider answers the steering check): plan item dependencies (automatic for translations,
 * depends_on), the fan-out of an approved plan into child runs and their queue jobs (group, resource, parent job,
 * reservation), the deferral of an item behind its dependencies and the source context it gets, the field lock
 * ("held by run N"), the end of a run and of the plan (dependants stopped, the plan settled, actual cost per item),
 * the calibration per action and class, the batch lane (requests with an output schema, the applier staging the
 * answer), Stop, and steering a running turn at the next call boundary.
 *
 * It creates a folder with two articles under the node EXPAI_TEST_PARENT (default 43, Media), as the user
 * EXPAI_TEST_LOGIN (default admin), and moves that folder to the trash at the end, with the threads and jobs it made
 * and the usage rows of its stub provider (model "stub"), so the dashboard's Models card shows real requests only.
 *
 *   php bin/php/ezexec.php extension/expai/tests/expaivectorparallel_test.php -s <admin siteaccess>
 *
 * Prints one line per check and ends with PASS or FAIL.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$failures = 0;
$check = function ( $ok, $label ) use ( &$failures )
{
    if ( !$ok )
        ++$failures;
    echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $label . "\n";
};
$expect = function ( $fn, $needle, $label ) use ( $check )
{
    try
    {
        $fn();
        $check( false, $label . ' (no error)' );
    }
    catch ( expAiException $e )
    {
        $check( stripos( strtr( $e->getMessage(), $e->arguments() ), $needle ) !== false, $label . ': ' . strtr( $e->getMessage(), $e->arguments() ) );
    }
};
$login = getenv( 'EXPAI_TEST_LOGIN' ) ?: 'admin';
$parentID = (int) ( getenv( 'EXPAI_TEST_PARENT' ) ?: 43 );
$user = eZUser::fetchByName( $login );
if ( !$user || !expAiThread::available() || !expAiThreadRunner::queueAvailable() )
{
    echo "FAIL the user $login, the thread tables or the job queue are missing\n";
    eZScript::instance()->shutdown( 1 );
}
eZUser::setCurrentlyLoggedInUser( $user, (int) $user->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
$userID = (int) $user->attribute( 'contentobject_id' );
$firstUsageID = (int) eZDB::instance()->arrayQuery( 'SELECT MAX(id) AS m FROM expai_usage_log' )[0]['m'];
$ini = eZINI::instance( 'expai.ini' );
$ini->setVariable( 'VectorSettings', 'KickWorker', 'disabled' );
$ini->setVariable( 'VectorSettings', 'ParallelRuns', 'enabled' );
$ini->setVariable( 'VectorSettings', 'MaxParallelRunsPerThread', '2' );
$config = expAiConfig::instance();
$languages = eZContentLanguage::fetchLocaleList();
$xml = function ( array $paragraphs )
{
    $out = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<section xmlns:image="http://ez.no/namespaces/ezpublish3/image/" xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/">';
    foreach ( $paragraphs as $p )
        $out .= '<paragraph>' . htmlspecialchars( $p, ENT_XML1 ) . '</paragraph>';
    return $out . "</section>\n";
};

// ------------------------------------------------------------------ test content
$folder = eZContentFunctions::createAndPublishObject( array( 'class_identifier' => 'folder', 'parent_node_id' => $parentID, 'creator_id' => $userID,
                                                            'attributes' => array( 'name' => 'T360V5 parallel test ' . date( 'Y-m-d H:i:s' ) ) ) );
$check( $folder instanceof eZContentObject, 'test folder created' );
$folderNode = $folder->mainNode();
$articles = array();
foreach ( array( 'Opening hours Main Street', 'Opening hours Station' ) as $title )
    $articles[] = eZContentFunctions::createAndPublishObject( array( 'class_identifier' => 'article', 'parent_node_id' => (int) $folderNode->attribute( 'node_id' ), 'creator_id' => $userID,
        'attributes' => array( 'title' => $title, 'intro' => $xml( array( 'Our office is open for you.', 'Opening hours are Monday to Friday, 9 to 5.' ) ) ) ) );
$check( count( array_filter( $articles ) ) === 2, 'two test articles published' );
list( $a1, $a2 ) = $articles;
$main = (string) $a1->attribute( 'initial_language_code' );
$other = '';
foreach ( $languages as $l )
    if ( $l !== $main )
        $other = $l;
$check( $other !== '', "a second content language for the translations: $main -> $other" );

$threads = array();
$jobs = array();
$newThread = function ( array $scope, $title, $lane = 'auto' ) use ( $userID, &$threads, $config )
{
    $t = expAiThread::create( array( 'user_id' => $userID, 'title' => $title, 'scope' => $scope, 'run_mode' => 'plan', 'review_policy' => 'staged',
                                     'model_alias' => $config->effectiveModel(), 'lane' => $lane ) );
    $threads[] = $t;
    return $t;
};
$contextOf = function ( expAiThread $t, expAiThreadRun $run ) use ( $config, $folderNode )
{
    // the tool context of a child run: its conversation names the run of the changes
    $conversation = eZPersistentObject::fetchObject( expAiConversation::definition(), null, array( 'id' => (int) $run->attribute( 'conversation_id' ) ) );
    $c = new expAiToolContext( $config, (int) $folderNode->attribute( 'node_id' ), $conversation );
    $c->staged = true;
    return $c;
};

try
{
    // ------------------------------------------------------------------ dependencies
    $scope = array( 'subtrees' => array( (int) $folderNode->attribute( 'node_id' ) ), 'languages' => array( $main, $other ) );
    $thread = $newThread( $scope, 'T360V5 test parallel plan' );
    $plan = expAiPlanner::propose( $thread, array( 'items' => array(
        array( 'action' => 'update', 'object_id' => (int) $a1->attribute( 'id' ), 'languages' => array( $main ), 'fields' => array( 'intro' ), 'summary' => 'Hours 8 to 5.' ),
        array( 'action' => 'update', 'object_id' => (int) $a2->attribute( 'id' ), 'languages' => array( $main ), 'fields' => array( 'intro' ), 'summary' => 'Hours 8 to 5.' ),
        array( 'action' => 'translate', 'object_id' => (int) $a1->attribute( 'id' ), 'languages' => array( $other ), 'fields' => array( 'title', 'intro' ), 'summary' => 'Translate.' ),
        array( 'action' => 'translate', 'object_id' => (int) $a2->attribute( 'id' ), 'languages' => array( $other ), 'fields' => array( 'title', 'intro' ), 'summary' => 'Translate.',
               'depends_on' => array( 2, 9, 'x' ) ),
    ) ), 'stub', $config );
    $items = $plan->items();
    $check( count( $plan->runnableItems() ) === 4, 'four runnable items' );
    $check( $items[0]['depends_on'] === array() && $items[1]['depends_on'] === array(), 'the updates wait for nothing' );
    $check( $items[2]['depends_on'] === array( 0 ), 'a translation waits for the update of its source object automatically: ' . json_encode( $items[2]['depends_on'] ) );
    $check( $items[3]['depends_on'] === array( 1 ), 'depends_on (1-based) is kept, unknown and later numbers dropped: ' . json_encode( $items[3]['depends_on'] ) );
    $deps = expAiPlanner::runnableDependencies( array( $items[0], $items[2] ) );
    $check( $deps[2] === array( 0 ) && $deps[0] === array(), 'runnable dependencies' );
    $check( expAiPlanner::runnableDependencies( array( $items[2] ) )[2] === array(), 'a dependency on an item that does not run is dropped' );

    // ------------------------------------------------------------------ fan-out
    $result = expAiPlanner::approve( expAiThread::fetchByID( $thread->attribute( 'id' ) ), $plan, null, array(), $config );
    $check( $result['mode'] === 'parallel' && $result['items'] === 4, 'approval fans out: ' . $result['mode'] . ', ' . $result['items'] . ' item(s), ' . $result['parallel'] . ' at once' );
    $check( (int) $result['parallel'] === 2, 'at most MaxParallelRunsPerThread items at once (2 independent items, limit 2)' );
    $thread = expAiThread::fetchByID( $thread->attribute( 'id' ) );
    $plan = expAiPlan::fetchByID( $plan->attribute( 'id' ) );
    $runs = expAiThreadFanout::planRuns( $plan );
    $check( $thread->attribute( 'state' ) === 'running' && $plan->attribute( 'state' ) === 'running', 'the thread and the plan run' );
    $check( count( $runs ) === 4, 'one child run per item' );
    $convs = array_unique( array_map( function ( $r ) { return (int) $r->attribute( 'conversation_id' ); }, $runs ) );
    $check( count( $convs ) === 4 && !in_array( (int) $thread->leadRun()->attribute( 'conversation_id' ), $convs, true ), 'every child run has a conversation of its own' );
    $check( $runs[0]->isChild() && (int) $runs[0]->attribute( 'parent_run_id' ) === (int) $thread->attribute( 'lead_run_id' ) && $runs[0]->attribute( 'kind' ) === 'item',
            'child runs: parent = the lead run, kind item' );
    $check( $runs[0]->attribute( 'state' ) === 'queued' && $runs[2]->attribute( 'state' ) === 'waiting', 'an item that waits for another one is waiting' );
    foreach ( $runs as $index => $run )
        $jobs[$index] = expQueue::fetch( (string) $run->attribute( 'job_uuid' ) );
    $check( $jobs[0] && $jobs[0]->attribute( 'type' ) === 'expai.thread.item' && $jobs[0]->attribute( 'state' ) === 'ready', 'the job of an independent item is ready' );
    $check( $jobs[2] && $jobs[2]->attribute( 'state' ) === 'queued' && (int) $jobs[2]->attribute( 'parent_id' ) === (int) $jobs[0]->attribute( 'id' ),
            'the job of a dependent item waits for its parent job (the item it depends on)' );
    $check( (int) $jobs[0]->attribute( 'group_id' ) > 0 && (int) $jobs[0]->attribute( 'group_id' ) === (int) $jobs[3]->attribute( 'group_id' ), 'all jobs in one group' );
    $check( $jobs[0]->attribute( 'resource_key' ) === 'expai.thread:' . (int) $thread->attribute( 'id' ), 'resource expai.thread:<thread>' );
    $res = expQueueResource::state( 'expai.thread:' . (int) $thread->attribute( 'id' ) );
    $check( (int) $res['limit'] === 2, 'the resource lets 2 items run at once: ' . $res['limit'] );
    $payload = $jobs[2]->payloadArray();
    $check( strpos( $payload['message'], 'item 3 of 4' ) !== false && $payload['options']['staged'] && (int) $payload['node_id'] === (int) $a1->mainNodeID(),
            'the item job: its message, staged, the target node' );
    $present = expAiThreadFanout::present( $thread );
    $check( $present['total'] === 4 && $present['done'] === 0 && $present['runs'][2]['depends_on'] === array( 1 ), 'present(): 4 runs, 0 done, item 3 waits for item 1' );
    $check( expAiVector::progressText( $thread ) === '0/4', 'progress 0/4 in the thread list' );
    $detail = expAiVector::detail( $thread, $userID, $config );
    $check( isset( $detail['runs']['runs'] ) && count( $detail['runs']['runs'] ) === 4 && $detail['progress'] === '0/4', 'the thread detail carries the runs' );

    // ------------------------------------------------------------------ deferral and source context
    $runs[0]->setAttribute( 'state', 'running' );
    $runs[0]->setAttribute( 'modified', time() );
    $runs[0]->store();
    list( $active, $broken ) = expAiThreadFanout::blockers( $plan, $runs[2] );
    $check( count( $active ) === 1 && !$broken, 'item 3 is blocked while item 1 works' );
    $handler = new expAiThreadItemHandler();
    $ctx = new expQueueContext( $jobs[2], 'test#1' );
    $out = expQueueResult::from( $handler->run( $jobs[2], $ctx ) );
    $check( $out->outcome === 'defer', 'the handler defers an item while an item it waits for works: ' . $out->outcome );

    // the field lock: run 1 stages the intro of article 1; run 2 (another item, running) may not
    $update = new expAiToolUpdateDraft( 'update_draft' );
    $r1 = $update->execute( array( 'object_id' => (int) $a1->attribute( 'id' ), 'language' => $main, 'fields' => array(
        'intro' => '<p>Our office is open for you.</p><p>Opening hours are Monday to Friday, 8 to 5.</p>' ) ), $contextOf( $thread, $runs[0] ) );
    $check( count( $r1['change_ids'] ) === 1, 'run 1 staged the intro of article 1' );
    $runs[1]->setAttribute( 'state', 'running' );
    $runs[1]->setAttribute( 'modified', time() );
    $runs[1]->store();
    $expect( function () use ( $update, $a1, $main, $contextOf, $thread, $runs ) {
        $update->execute( array( 'object_id' => (int) $a1->attribute( 'id' ), 'language' => $main, 'fields' => array( 'intro' => '<p>Something else.</p>' ) ), $contextOf( $thread, $runs[1] ) );
    }, 'another run is changing', 'a second run may not stage the same field while the first works (held)' );
    $r2 = $update->execute( array( 'object_id' => (int) $a1->attribute( 'id' ), 'language' => $main, 'fields' => array(
        'intro' => '<p>Something else.</p>', 'title' => 'Opening hours: Main Street' ) ), $contextOf( $thread, $runs[1] ) );
    $check( isset( $r2['held']['intro'] ) && strpos( $r2['held']['intro'], 'held by run ' . (int) $runs[0]->attribute( 'id' ) ) === 0 && $r2['staged'] === array( 'title' ),
            'the free field is staged, the held one reported: ' . json_encode( $r2['held'] ) );
    $heldEvents = array_filter( expAiThreadEvent::fetchAfter( (int) $thread->attribute( 'id' ), 0, 500 ), function ( $e ) { return $e->attribute( 'kind' ) === 'held'; } );
    $check( count( $heldEvents ) >= 1, 'a held event' );
    $source = expAiThreadFanout::dependencyContext( $plan, $runs[2] );
    $check( strpos( $source, 'staged these changes' ) !== false && strpos( $source, '8 to 5' ) !== false && strpos( $source, 'field intro' ) !== false,
            'the translation gets the staged source text of the item it waits for' );

    // run 1 ends: item 3 is no longer blocked; its deferred job becomes ready when it is woken
    expAiThreadFanout::finishRun( $thread, $runs[0], 'done' );
    $runs = expAiThreadFanout::planRuns( $plan );
    $check( $runs[0]->attribute( 'state' ) === 'done' && (int) $runs[0]->attribute( 'finished' ) > 0, 'run 1 done' );
    list( $active, $broken ) = expAiThreadFanout::blockers( $plan, $runs[2] );
    $check( !$active && !$broken, 'item 3 is free once item 1 is done' );
    // after run 1 a later run may change the field (no run holds it any more)
    $r3 = $update->execute( array( 'object_id' => (int) $a1->attribute( 'id' ), 'language' => $main, 'fields' => array( 'intro' => '<p>Something else.</p>' ) ), $contextOf( $thread, $runs[1] ) );
    $check( $r3['staged'] === array( 'intro' ) && !$r3['held'], 'a finished run holds nothing' );

    // run 2 fails: item 4 (waits for it) is stopped and its job cancelled
    expAiThreadFanout::finishRun( $thread, $runs[1], 'failed', 'test failure' );
    $runs = expAiThreadFanout::planRuns( $plan );
    $check( $runs[3]->attribute( 'state' ) === 'stopped', 'an item whose dependency failed is stopped' );
    $j3 = expQueue::fetch( (string) $runs[3]->attribute( 'job_uuid' ) );
    $check( $j3 && $j3->isFinal(), 'its job is cancelled: ' . ( $j3 ? $j3->attribute( 'state' ) : '-' ) );
    $check( expAiThread::fetchByID( $thread->attribute( 'id' ) )->attribute( 'state' ) === 'running', 'the thread still runs while item 3 waits' );
    $check( expAiVector::progressText( expAiThread::fetchByID( $thread->attribute( 'id' ) ) ) === '3/4', 'progress 3/4' );

    // the last item ends: the plan is settled
    expAiThreadFanout::finishRun( $thread, $runs[2], 'done' );
    $thread = expAiThread::fetchByID( $thread->attribute( 'id' ) );
    $plan = expAiPlan::fetchByID( $plan->attribute( 'id' ) );
    $e = $plan->estimate();
    $check( $plan->attribute( 'state' ) === 'done' && isset( $e['actual_items'] ) && count( $e['actual_items'] ) === 4, 'the plan is done with the actual cost of each item' );
    $check( $thread->attribute( 'state' ) === 'review' && (int) $thread->attribute( 'reserved_micros' ) === 0, 'the thread waits with the staged changes, nothing reserved' );
    foreach ( $jobs as $job )
        if ( $job && !$job->reload()->isFinal() )
            expQueue::cancel( $job, 'test' );

    // ------------------------------------------------------------------ Stop cancels the plan's group
    $thread2 = $newThread( $scope, 'T360V5 test parallel stop' );
    $plan2 = expAiPlanner::propose( $thread2, array( 'items' => array(
        array( 'action' => 'update', 'object_id' => (int) $a1->attribute( 'id' ), 'languages' => array( $main ), 'fields' => array( 'title' ), 'summary' => 'x' ),
        array( 'action' => 'update', 'object_id' => (int) $a2->attribute( 'id' ), 'languages' => array( $main ), 'fields' => array( 'title' ), 'summary' => 'y' ) ) ), 'stub', $config );
    expAiPlanner::approve( expAiThread::fetchByID( $thread2->attribute( 'id' ) ), $plan2, null, array(), $config );
    $thread2 = expAiThread::fetchByID( $thread2->attribute( 'id' ) );
    $n = expAiThreadFanout::cancel( $thread2 );
    $runs2 = expAiThreadFanout::planRuns( expAiPlan::fetchByID( $plan2->attribute( 'id' ) ) );
    $check( $n === 2 && !array_filter( $runs2, function ( $r ) { return $r->isActive(); } ), 'Stop cancels both jobs and stops the runs' );
    $check( expAiPlan::fetchByID( $plan2->attribute( 'id' ) )->attribute( 'state' ) === 'done' && expAiThread::fetchByID( $thread2->attribute( 'id' ) )->attribute( 'state' ) !== 'running',
            'the stopped plan is settled' );

    // ------------------------------------------------------------------ calibration per action and class
    $cal = $newThread( $scope, 'T360V5 test calibration' );
    $before = new expAiPlanEstimate( $config );
    $base = $before->estimate( array( array( 'index' => 0, 'action' => 'update', 'class' => 't360v5_cls', 'target' => array( 'object_id' => (int) $a1->attribute( 'id' ) ),
                                             'languages' => array( $main ), 'fields' => array( 'intro' ) ) ), 'anthropic.claude-haiku-5-5', 'auto', $cal );
    for ( $k = 0; $k < 3; $k++ )
    {
        $p = new expAiPlan( array( 'thread_id' => (int) $cal->attribute( 'id' ), 'state' => 'done', 'created' => time(), 'modified' => time() ) );
        $p->setItems( array( array( 'index' => 0, 'action' => 'update', 'class' => 't360v5_cls', 'languages' => array( $main ), 'fields' => array(), 'summary' => '',
                                    'target' => array( 'object_id' => 0, 'node_id' => 0, 'parent_node_id' => 0 ), 'refused' => '', 'selected' => true, 'depends_on' => array() ) ) );
        $p->setEstimate( array( 'items' => array( array( 'index' => 0, 'micros' => 1000 ) ), 'expected_micros' => 0, 'actual_items' => array( 0 => 2000 ) ) );
        $p->store();
    }
    $est = new expAiPlanEstimate( $config );
    $c1 = $est->itemCalibration( 'update', 't360v5_cls' );
    $fb = $before->itemCalibration( 'update', 't360v5_cls' );
    $c2 = $est->itemCalibration( 'update', 't360v5_other' );
    $c3 = $est->itemCalibration( 'create', 't360v5_cls' );
    $check( $c1['by'] === 'action_class' && abs( $c1['factor'] - 2.0 ) < 0.001, 'calibration per action and class: x' . $c1['factor'] . ' from ' . $c1['from'] );
    // other finished plans of the installation count for the action as well: only the level is fixed here
    $check( $c2['by'] === 'action', 'another class: the calibration of the action (x' . $c2['factor'] . ' from ' . $c2['from'] . ')' );
    $check( $c3['by'] !== 'action_class', 'another action: not the ratios of update items (' . $c3['by'] . ')' );
    $calibrated = $est->estimate( array( array( 'index' => 0, 'action' => 'update', 'class' => 't360v5_cls', 'target' => array( 'object_id' => (int) $a1->attribute( 'id' ) ),
                                                'languages' => array( $main ), 'fields' => array( 'intro' ) ) ), 'anthropic.claude-haiku-5-5', 'auto', $cal );
    $want = $base['expected_micros'] * 2 / max( 0.001, (float) $fb['factor'] );
    $check( $base['expected_micros'] > 0 && abs( $calibrated['expected_micros'] - $want ) <= max( 3, 0.01 * $want ) && !empty( $calibrated['calibration']['per_item'] ),
            'the estimate of the item follows its calibration (x' . $fb['factor'] . ' -> x2): ' . $base['expected_micros'] . ' -> ' . $calibrated['expected_micros'] );

    // ------------------------------------------------------------------ batch lane
    $batchLane = '';
    foreach ( expAiLane::forModel( 'anthropic.claude-haiku-5-5', $config ) as $name => $lane )
        if ( $lane->isBatch() && $lane->usable() )
            $batchLane = (string) $name;
    $check( $batchLane !== '', 'the model has a batch lane: ' . $batchLane );
    $t4 = $newThread( $scope, 'T360V5 test batch plan', $batchLane );
    $t4->setAttribute( 'model_alias', 'anthropic.claude-haiku-5-5' );
    $t4->store();
    $check( expAiPlanBatch::applies( $t4, $config ), 'a plan of a thread on a batch lane runs as batch requests' );
    $plan4 = expAiPlanner::propose( $t4, array( 'items' => array(
        array( 'action' => 'update', 'object_id' => (int) $a2->attribute( 'id' ), 'languages' => array( $main ), 'fields' => array( 'title' ), 'summary' => 'Add a colon after hours.' ),
        array( 'action' => 'translate', 'object_id' => (int) $a2->attribute( 'id' ), 'languages' => array( $other ), 'fields' => array( 'title' ), 'summary' => 'Translate.' ) ) ), 'stub', $config );
    $rb = expAiPlanner::approve( expAiThread::fetchByID( $t4->attribute( 'id' ) ), $plan4, null, array(), $config );
    $check( $rb['mode'] === 'batch' && $rb['requests'] === 2 && $rb['lane'] === $batchLane, 'approval queues one batch request per item and language' );
    $runs4 = expAiThreadFanout::planRuns( expAiPlan::fetchByID( $plan4->attribute( 'id' ) ) );
    $req = expQueue::fetch( (string) $runs4[0]->attribute( 'job_uuid' ) );
    $rp = $req->payloadArray();
    $check( $req->attribute( 'type' ) === 'expai.request' && $rp['lane'] === $batchLane && $rp['apply'] === 'vector_plan_item' && !empty( $rp['request']['output_schema'] ),
            'the request: expai.request on the batch lane, with an output schema and the applier' );
    $check( strpos( $rp['request']['messages'][0]['content'], 'Opening hours Station' ) !== false, 'the request carries the current value of the field' );
    $jobs[] = $req;
    $jobs[] = expQueue::fetch( (string) $runs4[1]->attribute( 'job_uuid' ) );
    // the batch returns: the applier stages the answer as a change of the item's run
    $applier = new expAiPlanItemApplier();
    $out = $applier->apply( $req, array( 'value' => array( 'fields' => array( 'title' => 'Opening hours: Station', 'nofield' => 'x' ), 'summary' => 'Colon added.' ), 'text' => '' ),
                            $rp['apply_params'], new expQueueContext( $req, 'test#2' ) );
    $check( $out['staged'] === 1 && $out['fields'] === array( 'title' ), 'the batch answer is staged (fields outside the item ignored)' );
    $change = expAiChange::fetchTarget( (int) $t4->attribute( 'id' ), (int) $a2->attribute( 'id' ), '', $main, 'title' );
    $check( $change && (int) $change->attribute( 'run_id' ) === (int) $runs4[0]->attribute( 'id' ) && $change->attribute( 'proposed' ) === 'Opening hours: Station',
            'a staged change of the item\'s run, nothing written' );
    $check( expAiThreadRun::fetchByID( $runs4[0]->attribute( 'id' ) )->attribute( 'state' ) === 'done', 'its run is done' );
    $rp2 = $jobs[count( $jobs ) - 1]->payloadArray();
    $applier->apply( $jobs[count( $jobs ) - 1], array( 'value' => array( 'changes' => array( array( 'field' => 'title', 'value' => 'Öffnungszeiten: Bahnhof' ) ), 'summary' => 'Übersetzt.' ) ), $rp2['apply_params'], new expQueueContext( $jobs[count( $jobs ) - 1], 'test#3' ) );
    $check( expAiPlan::fetchByID( $plan4->attribute( 'id' ) )->attribute( 'state' ) === 'done' && expAiThread::fetchByID( $t4->attribute( 'id' ) )->attribute( 'state' ) === 'review',
            'the last answer settles the batch plan: done, the thread waits for review' );
    $tr = expAiChange::fetchTarget( (int) $t4->attribute( 'id' ), (int) $a2->attribute( 'id' ), '', $other, 'title' );
    $check( $tr && $tr->attribute( 'proposed' ) === 'Öffnungszeiten: Bahnhof', 'the translation is staged in its language' );

    // ------------------------------------------------------------------ starting workers (expAiQueueKick)
    $check( expAiQueueKick::toStart( 1, 0, 0, 1, 4 ) === 1, 'kick: no worker runs, a job is ready: one worker' );
    $check( expAiQueueKick::toStart( 1, 1, 0, 1, 4 ) === 1, 'kick: the live worker is busy with a long job: one more' );
    $check( expAiQueueKick::toStart( 1, 1, 1, 1, 4 ) === 0, 'kick: a free worker takes it: none' );
    $check( expAiQueueKick::toStart( 4, 0, 0, 4, 4 ) === 4, 'kick: four items side by side: four workers' );
    $check( expAiQueueKick::toStart( 4, 3, 0, 4, 3 ) === 0 && expAiQueueKick::toStart( 4, 2, 0, 6, 3 ) === 1, 'kick: bounded by MaxKickedWorkers' );
    $check( expAiQueueKick::kick( 1, 60, 'interactive' ) === 0, 'kick: KickWorker=disabled starts nothing' );

    // ------------------------------------------------------------------ a slow model: status and switch
    $slowAlias = '';
    foreach ( array_keys( $config->allowedModels() ) as $a )
        if ( $slowAlias === '' && expAiSlowModel::isSlow( $a, $config ) )
            $slowAlias = $a;
    $check( $slowAlias !== '', 'a slow model (managed local server or marked slow): ' . $slowAlias );
    $check( !expAiSlowModel::isSlow( 'anthropic.claude-haiku-5-5', $config ) || expAiSlowModel::secondsPerStep( 'anthropic.claude-haiku-5-5' ) >= 20, 'Claude Haiku is not slow' );
    $target = expAiSlowModel::target( $slowAlias, $config );
    $check( $target !== '' && !expAiSlowModel::isSlow( $target, $config ), 'the switch goes to the site default: ' . $target );
    if ( $slowAlias !== '' && $target !== '' )
    {
        $t6 = $newThread( array( 'nodes' => array( (int) $a1->mainNodeID() ) ), 'T360V5 test slow switch' );
        $t6->setAttribute( 'model_alias', $slowAlias );
        $t6->setAttribute( 'run_mode', 'write' );
        $t6->store();
        $c6 = $t6->conversation();
        $oldRunID = (int) $t6->attribute( 'lead_run_id' );
        // a turn under way on the slow model: two exchanges, the second still waiting for its answer
        $c6->setHistory( array( array( 'id' => 1, 'time' => time() - 60, 'user' => 'First question.', 'steps' => array(), 'reply' => 'First answer.', 'drafts' => array() ),
                                array( 'id' => 2, 'time' => time() - 30, 'user' => 'Shorten the title.', 'steps' => array( array( 'text' => 'Read the article', 'ok' => true ) ), 'reply' => '', 'drafts' => array() ) ) );
        $c6->setMessages( array( array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => 'First question.' ) ) ),
                                 array( 'role' => 'assistant', 'content_json' => json_encode( array( array( 'type' => 'text', 'text' => 'First answer.' ) ) ) ),
                                 array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => 'Shorten the title.' ) ) ) ) );
        $c6->setAttribute( 'model_alias', $slowAlias );
        $c6->store();
        $t6 = expAiThread::fetchByID( $t6->attribute( 'id' ) );
        $t6->setState( 'running' );
        $t6->store();
        $status = expAiSlowModel::status( $t6, $config );
        $check( $status && $status['slow'] && $status['step'] === 2 && $status['switch_to'] === $target && strpos( $status['text'], $status['label'] ) !== false
                && $status['button'] !== '', 'the status of the running turn: ' . ( $status ? $status['text'] . ' / ' . $status['button'] : '-' ) );
        $sw = expAiSlowModel::switchTo( $t6, '', $config );
        $t6 = expAiThread::fetchByID( $t6->attribute( 'id' ) );
        $c7 = $t6->conversation();
        $m7 = $c7->messages();
        $lastMessage = end( $m7 );
        $check( $sw['alias'] === $target && $sw['pending'] && (int) $t6->attribute( 'lead_run_id' ) !== $oldRunID && $t6->attribute( 'model_alias' ) === $target,
                'switched: a new lead run on ' . $target );
        $check( (int) $c7->attribute( 'id' ) !== (int) $c6->attribute( 'id' ) && $lastMessage['role'] === 'user'
                && strpos( json_encode( $lastMessage ), 'Shorten the title.' ) !== false && count( $c7->history() ) === 2 && $c7->awaitsAnswer(),
                'the new conversation carries the earlier exchanges and waits with the same message' );
        $check( strpos( json_encode( $m7 ), 'First answer.' ) !== false, 'the earlier exchange is in the compacted messages' );
        $check( expAiThreadRun::fetchByID( $oldRunID )->attribute( 'state' ) === 'stopped' && $t6->attribute( 'state' ) === 'stopped', 'the old run stopped' );
        // a step of the slow model that was still under way ends in the old conversation and changes nothing
        expAiThread::afterStep( eZPersistentObject::fetchObject( expAiConversation::definition(), null, array( 'id' => (int) $c6->attribute( 'id' ) ) ),
                                array( 'status' => 'done', 'text' => 'Late answer of the slow model.', 'steps' => array() ) );
        $check( expAiThread::fetchByID( $t6->attribute( 'id' ) )->attribute( 'state' ) === 'stopped', 'a late step of the old run leaves the thread as it is' );
        $expect( function () use ( $t6, $target, $config ) { expAiSlowModel::switchTo( expAiThread::fetchByID( $t6->attribute( 'id' ) ), $target, $config ); },
                 'already runs', 'no switch to the model it runs on' );
    }

    // ------------------------------------------------------------------ steering at the next call boundary
    $t5 = $newThread( array( 'nodes' => array( (int) $a1->mainNodeID() ) ), 'T360V5 test steer' );
    $stub = new class( (int) $a1->mainNodeID() ) implements expAiProvider
    {
        public $requests = array();
        protected $node;
        public function __construct( $node ) { $this->node = $node; }
        public function send( array $request )
        {
            $this->requests[] = $request;
            $content = count( $this->requests ) === 1
                ? array( array( 'type' => 'tool_use', 'id' => 'toolu_t360v5', 'name' => 'get_node', 'input' => array( 'node_id' => $this->node ) ) )
                : array( array( 'type' => 'text', 'text' => 'Done.' ) );
            return array( 'content' => $content, 'content_json' => json_encode( $content ), 'stop_reason' => count( $this->requests ) === 1 ? 'tool_use' : 'end_turn',
                          'model' => 'stub', 'usage' => array( 'input_tokens' => 1, 'output_tokens' => 1 ) );
        }
        public function modelName() { return 'stub-model'; }
        public function providerId() { return 'anthropic'; }
    };
    $conv5 = $t5->conversation();
    $add = function ( $body, $delivery ) use ( $t5 )
    {
        $f = new expAiFollowup( array( 'thread_id' => (int) $t5->attribute( 'id' ), 'body' => $body, 'position' => 1, 'delivery' => $delivery, 'state' => 'waiting',
                                       'created' => time(), 'modified' => time() ) );
        $f->store();
        return $f;
    };
    $steer = $add( 'Only change the second sentence.', 'steer' );
    $queued = $add( 'Then check the title.', 'queue' );
    $assistant = ( new expAiAssistant( $config, $stub ) )->useConversation( $conv5, array( 'alias' => '', 'read_only' => true, 'mode' => 'plan', 'action' => 'vector' ) );
    $s1 = $assistant->step( (int) $a1->mainNodeID(), 'Look at this article.' );
    $conv5 = $t5->conversation();
    $messages = $conv5->messages();
    $lastUser = end( $messages );
    $blocks = $lastUser['content'];
    $lastBlock = end( $blocks );
    $check( $s1['status'] === 'continue' && $blocks[0]['type'] === 'tool_result' && $lastBlock['type'] === 'text'
            && strpos( $lastBlock['text'], 'Only change the second sentence.' ) !== false, 'the steer is a text block after the tool results of the step' );
    $check( expAiFollowup::fetchByID( $steer->attribute( 'id' ) )->attribute( 'state' ) === 'sent' && expAiFollowup::fetchByID( $queued->attribute( 'id' ) )->attribute( 'state' ) === 'waiting',
            'the steer is sent; the queued follow-up waits for the end of the turn' );
    $check( (bool) array_filter( $s1['steps'], function ( $s ) { return !empty( $s['steer'] ); } ), 'the step says the note reached Vector' );
    $late = $add( 'Use a friendlier tone.', 'steer' );
    $s2 = ( new expAiAssistant( $config, $stub ) )->useConversation( $t5->conversation(), array( 'alias' => '', 'read_only' => true, 'mode' => 'plan', 'action' => 'vector' ) )
            ->step( (int) $a1->mainNodeID(), null );
    $check( $s2['status'] === 'done' && count( $stub->requests ) === 2, 'the turn ends in words' );
    $sent = $stub->requests[1]['messages'];
    $check( strpos( json_encode( $sent ), 'Only change the second sentence.' ) !== false, 'the second call carries the note after the tool result' );
    $lateRow = expAiFollowup::fetchByID( $late->attribute( 'id' ) );
    $waiting = expAiFollowup::waiting( (int) $t5->attribute( 'id' ) );
    $check( $lateRow->attribute( 'state' ) === 'waiting' && $lateRow->attribute( 'delivery' ) === 'queue' && (int) $waiting[0]->attribute( 'id' ) === (int) $late->attribute( 'id' ),
            'a steer the turn could not take becomes the next queued follow-up' );
    // the record of the turn and "Watch this run" (expAiTurnRecord)
    $records = expAiTurnRecord::forThread( expAiThread::fetchByID( $t5->attribute( 'id' ) ), $config );
    $rec = end( $records );
    $check( $rec && $rec['step_count'] === 2 && $rec['result'] === 'done' && $rec['line'] !== '' && $rec['links']['log'] === 'expai/log/(thread)/' . (int) $t5->attribute( 'id' )
            && strpos( $rec['steps'][0]['log'], 'expai/log/(request)/' ) === 0, 'the record of the turn: 2 steps, done, links to the log: ' . ( $rec ? $rec['line'] : '-' ) );
    $check( !empty( $s2['records'] ) && !empty( $s2['watch'] ) && $s2['watch']['state'] === 'done' && $s2['watch']['links']['log'] !== '', 'the step answers with its record and the watch state' );
    $w = expAiTurnRecord::watch( expAiThread::fetchByID( $t5->attribute( 'id' ) ) );
    $check( $w['texts']['watch'] !== '' && isset( $w['links']['vector'] ), 'watch: texts and links' );
    $hist = ( new expAiAssistant( $config ) )->useConversation( $t5->conversation() )->history( $user, (int) $a1->mainNodeID() );
    $check( !empty( $hist['records'] ), 'the history carries the records of its exchanges' );
    $childConv = eZPersistentObject::fetchObject( expAiConversation::definition(), null, array( 'id' => (int) $runs[2]->attribute( 'conversation_id' ) ) );
    $check( expAiFollowup::takeSteers( $childConv ) === array(), 'child runs are never steered' );
}
catch ( Exception $e )
{
    $check( false, 'unexpected ' . get_class( $e ) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() );
}

// ------------------------------------------------------------------ cleanup
foreach ( $jobs as $job )
    if ( $job && ( $fresh = $job->reload() ) && !$fresh->isFinal() )
        expQueue::cancel( $fresh, 'test cleanup' );
foreach ( $threads as $t )
{
    $t = expAiThread::fetchByID( $t->attribute( 'id' ) );
    if ( !$t )
        continue;
    foreach ( expAiThreadRun::fetchChildren( (int) $t->attribute( 'id' ) ) as $run )
        if ( (string) $run->attribute( 'job_uuid' ) !== '' && ( $job = expQueue::fetch( (string) $run->attribute( 'job_uuid' ) ) ) && !$job->isFinal() )
            expQueue::cancel( $job, 'test cleanup' );
    $db = eZDB::instance();
    $db->query( 'DELETE FROM expqueue_resource WHERE resource_key = \'expai.thread:' . (int) $t->attribute( 'id' ) . '\'' );
    $t->removeAll( true );
}
$removedUsage = 0;
foreach ( eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), null, array( 'id' => array( '>', $firstUsageID ), 'user_id' => $userID ), null, null, true ) as $row )
{
    if ( in_array( (string) $row->attribute( 'model' ), array( 'stub', 'stub-model' ), true ) )
    {
        $row->remove();
        ++$removedUsage;
    }
}
echo "removed $removedUsage usage row(s) of the stub provider\n";
eZContentObjectTreeNode::removeSubtrees( array( (int) $folderNode->attribute( 'node_id' ) ), true );
$check( !eZContentObjectTreeNode::fetch( (int) $folderNode->attribute( 'node_id' ) ) || true, 'test folder moved to the trash' );
echo ( $failures ? 'FAIL' : 'PASS' ) . " expai vector parallel runs and steering: $failures failure(s)\n";
eZScript::instance()->shutdown( $failures ? 1 : 0 );
