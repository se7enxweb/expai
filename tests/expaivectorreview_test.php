<?php
/**
 * Tests of Vector's staged review and plan mode (doc/review.md, doc/plan-mode.md) on the database of the installation,
 * without any model call: staging through the write tools (update_draft, create_draft), hunk decisions, apply into
 * drafts, conflicts and restage, hunk edits, the bulk guard, permissions, propose_plan (validation, refused items,
 * estimate, edit, limits) and the approval of a plan (the job is cancelled at once).
 *
 * It creates a folder with two articles under the node EXPAI_TEST_PARENT (default 43, Media), as the user
 * EXPAI_TEST_LOGIN (default admin), and moves that folder to the trash at the end, with the drafts and threads it made.
 *
 *   php bin/php/ezexec.php extension/expai/tests/expaivectorreview_test.php -s <admin siteaccess>
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
if ( !$user || !expAiThread::available() )
{
    echo "FAIL the user $login or the thread tables are missing\n";
    eZScript::instance()->shutdown( 1 );
}
eZUser::setCurrentlyLoggedInUser( $user, (int) $user->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
$userID = (int) $user->attribute( 'contentobject_id' );
$ini = eZINI::instance( 'expai.ini' );
$ini->setVariable( 'VectorSettings', 'KickWorker', 'disabled' );
// the plan runs as one job here (the turns one after the other); parallel runs: expaivectorparallel_test.php
$ini->setVariable( 'VectorSettings', 'ParallelRuns', 'disabled' );
$config = expAiConfig::instance();
$xml = function ( array $paragraphs )
{
    $out = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<section xmlns:image="http://ez.no/namespaces/ezpublish3/image/" xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/">';
    foreach ( $paragraphs as $p )
        $out .= '<paragraph>' . htmlspecialchars( $p, ENT_XML1 ) . '</paragraph>';
    return $out . "</section>\n";
};

// ------------------------------------------------------------------ test content
$folder = eZContentFunctions::createAndPublishObject( array( 'class_identifier' => 'folder', 'parent_node_id' => $parentID, 'creator_id' => $userID,
                                                            'attributes' => array( 'name' => 'T360 Vector review test ' . date( 'Y-m-d H:i:s' ) ) ) );
$check( $folder instanceof eZContentObject, 'test folder created' );
$folderNode = $folder->mainNode();
$articles = array();
foreach ( array( 'Opening hours Main Street', 'Opening hours Station' ) as $title )
    $articles[] = eZContentFunctions::createAndPublishObject( array( 'class_identifier' => 'article', 'parent_node_id' => (int) $folderNode->attribute( 'node_id' ), 'creator_id' => $userID,
        'attributes' => array( 'title' => $title, 'intro' => $xml( array( 'Our office is open for you.', 'We are glad to see you.', 'Opening hours are Monday to Friday, 9 to 5.' ) ) ) ) );
$check( count( array_filter( $articles ) ) === 2, 'two test articles published' );
$a1 = $articles[0];
$v1 = (int) $a1->attribute( 'current_version' );
$lang = (string) $a1->attribute( 'initial_language_code' );

$threads = array();
$newThread = function ( array $scope, $title ) use ( $userID, &$threads, $config )
{
    $t = expAiThread::create( array( 'user_id' => $userID, 'title' => $title, 'scope' => $scope, 'run_mode' => 'write', 'review_policy' => 'staged',
                                     'model_alias' => $config->effectiveModel() ) );
    $threads[] = $t;
    return $t;
};
$contextOf = function ( expAiThread $t ) use ( $config, $folderNode )
{
    $c = new expAiToolContext( $config, (int) $folderNode->attribute( 'node_id' ), $t->conversation() );
    $c->staged = true;
    return $c;
};

try
{
    // ------------------------------------------------------------------ staging through update_draft
    $thread = $newThread( array( 'subtrees' => array( (int) $folderNode->attribute( 'node_id' ) ) ), 'T360 test staged review' );
    $ctx = $contextOf( $thread );
    $update = new expAiToolUpdateDraft( 'update_draft' );
    $r = $update->execute( array( 'object_id' => (int) $a1->attribute( 'id' ), 'fields' => array(
        'title' => 'Opening hours Main Street (new)',
        'intro' => '<p>Our office is now open earlier for you.</p><p>We are glad to see you.</p><p>From 1 November: Monday to Friday, 8 to 5.</p>' ) ), $ctx );
    $check( count( $r['change_ids'] ) === 2 && $r['staged'] === array( 'title', 'intro' ), 'update_draft staged two fields: ' . implode( ',', $r['staged'] ) );
    $check( strpos( $update->stepText( array() ), 'review' ) !== false, 'the step says "for review"' );
    $a1 = eZContentObject::fetch( (int) $a1->attribute( 'id' ) );
    $check( (int) $a1->attribute( 'current_version' ) === $v1 && count( (array) $a1->versions( true, array( 'conditions' => array( 'status' => 0 ) ) ) ) === 0,
            'nothing written: no new version, no draft' );
    $intro = null;
    $title = null;
    foreach ( expAiChange::fetchForThread( $thread->attribute( 'id' ) ) as $c )
        ${$c->attribute( 'attribute_identifier' )} = $c;
    $hunks = $intro->hunks();
    $check( count( $hunks ) === 2, 'intro: two hunks (first and third paragraph): ' . count( $hunks ) );
    $check( $intro->attribute( 'decision' ) === 'pending' && $intro->attribute( 'base_version' ) === $v1, 'pending, base = the published version' );
    $check( strpos( (string) $intro->attribute( 'proposed' ), '<paragraph>' ) !== false, 'the proposal is stored as rich text XML' );
    $check( $hunks[0]['base_text'] === 'Our office is open for you.' && $hunks[0]['prop_text'] === 'Our office is now open earlier for you.', 'hunk texts are readable text' );

    // re-stage the same field: decisions of unchanged hunks stay
    $intro->decide( $hunks[0]['id'], 'accepted', $userID );
    $intro->store();
    $update->execute( array( 'object_id' => (int) $a1->attribute( 'id' ), 'fields' => array(
        'intro' => '<p>Our office is now open earlier for you.</p><p>We are glad to see you.</p><p>From 1 November: Monday to Friday, 8 to 6.</p>' ) ), $ctx );
    $intro = expAiChange::fetchByID( $intro->attribute( 'id' ) );
    $h = $intro->hunks();
    $check( count( expAiChange::fetchForThread( $thread->attribute( 'id' ) ) ) === 2 && $h[0]['decision'] === 'accepted' && $h[1]['decision'] === 'pending',
            're-staging replaces the proposal and keeps the decision of the unchanged hunk' );
    $check( $intro->attribute( 'decision' ) === 'pending', 'one hunk still pending: the change is pending' );

    // bulk guard: a second object without an approved plan
    $expect( function () use ( $update, $articles, $ctx ) { $update->execute( array( 'object_id' => (int) $articles[1]->attribute( 'id' ), 'fields' => array( 'title' => 'X' ) ), $ctx ); },
             'propose_plan', 'a second object is bulk work' );
    // the same values as published: nothing to stage
    $same = $update->execute( array( 'object_id' => (int) $a1->attribute( 'id' ), 'fields' => array( 'short_title' => '' ) ), $ctx );
    $check( $same['unchanged'] === array( 'short_title' ) && !$same['change_ids'], 'a value equal to the published one is not staged' );
    $expect( function () use ( $update, $a1, $ctx ) { $update->execute( array( 'object_id' => (int) $a1->attribute( 'id' ), 'fields' => array( 'nofield' => 'x' ) ), $ctx ); },
             'Unknown field', 'an unknown field is a tool error' );
    $skip = $update->execute( array( 'object_id' => (int) $a1->attribute( 'id' ), 'fields' => array( 'image' => '12', 'title' => 'Opening hours Main Street (new)' ) ), $ctx );
    $check( isset( $skip['skipped']['image'] ), 'a relation is skipped (not written by the assistant)' );

    // edit a hunk of the title (ezstring)
    $title = expAiChange::fetchByID( $title->attribute( 'id' ) );
    $th = $title->hunks();
    expAiChangeStager::editHunk( $title, $th[0]['id'], 'Opening hours: Main Street' );
    $title = expAiChange::fetchByID( $title->attribute( 'id' ) );
    $check( $title->attribute( 'proposed' ) === 'Opening hours: Main Street' && $title->attribute( 'decision' ) === 'accepted' && $title->hunks()[0]['edited'],
            'an edited hunk is the new proposal, accepted' );
    $expect( function () use ( $intro, $h ) { expAiChangeStager::editHunk( $intro, $h[0]['id'], 'x' ); }, 'cannot be edited', 'rich text hunks are not edited here' );

    // decisions: accept the first intro hunk, reject the second
    $intro->decide( $h[1]['id'], 'rejected', $userID );
    $intro->store();
    $check( $intro->attribute( 'decision' ) === 'partial', 'one accepted, one rejected: partial' );
    $counts = expAiChange::counts( $thread->attribute( 'id' ) );
    $check( $counts['pending_hunks'] === 0 && $counts['accepted'] === 2, 'counts: no pending hunk, two changes to apply' );

    // permissions: the anonymous user cannot apply
    $anonymous = eZUser::fetch( eZUser::anonymousId() );
    eZUser::setCurrentlyLoggedInUser( $anonymous, (int) $anonymous->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
    $res = ( new expAiChangeApplier( expAiThread::fetchByID( $thread->attribute( 'id' ) ) ) )->apply();
    $check( !$res['applied'] && count( $res['errors'] ) === 1, 'a user without edit permission cannot apply: ' . ( $res['errors'] ? $res['errors'][0]['message'] : '-' ) );
    $check( !expAiThreadAccess::canWrite( $thread ) && !expAiThreadAccess::canRead( $thread ), 'another user can neither change nor read the thread' );
    eZUser::setCurrentlyLoggedInUser( $user, $userID, eZUser::NO_SESSION_REGENERATE );

    // apply
    $res = ( new expAiChangeApplier( expAiThread::fetchByID( $thread->attribute( 'id' ) ) ) )->apply();
    $check( count( $res['applied'] ) === 2 && count( $res['drafts'] ) === 1 && !$res['errors'], 'applied two changes into one draft' );
    $draft = $res['drafts'][0];
    $a1 = eZContentObject::fetch( (int) $a1->attribute( 'id' ) );
    $check( (int) $a1->attribute( 'current_version' ) === $v1, 'the published version is unchanged (never published)' );
    $dm = $a1->fetchDataMap( $draft['version'], $draft['language'] );
    $introDraft = (string) $dm['intro']->attribute( 'data_text' );
    $check( strpos( $introDraft, 'now open earlier' ) !== false && strpos( $introDraft, '9 to 5' ) !== false && strpos( $introDraft, '8 to 6' ) === false,
            'the draft has the accepted hunk and the published text of the rejected one' );
    $check( (string) $dm['title']->attribute( 'data_text' ) === 'Opening hours: Main Street', 'the draft has the edited title' );
    $log = expAiDraftLog::fetchByVersion( $a1->attribute( 'id' ), $draft['version'] );
    $check( $log && $log->attribute( 'source' ) === 'vector' && (int) $log->attribute( 'thread_id' ) === (int) $thread->attribute( 'id' ) && (int) $log->attribute( 'change_id' ) > 0,
            'draft log: source vector, thread and change' );
    $title = expAiChange::fetchByID( $title->attribute( 'id' ) );
    $check( $title->attribute( 'decision' ) === 'applied' && (int) $title->attribute( 'applied_version' ) === (int) $draft['version'], 'changes marked applied with the version' );
    $kinds = array_map( function ( $e ) { return $e->attribute( 'kind' ); }, expAiThreadEvent::fetchAfter( $thread->attribute( 'id' ), 0, 100 ) );
    $check( in_array( 'change', $kinds, true ) && in_array( 'draft', $kinds, true ), 'events change and draft' );

    // a second apply into the same draft (the thread's draft is reused)
    $update->execute( array( 'object_id' => (int) $a1->attribute( 'id' ), 'fields' => array( 'short_title' => 'Main Street' ) ), $ctx );
    $short = expAiChange::fetchTarget( $thread->attribute( 'id' ), $a1->attribute( 'id' ), '', $lang, 'short_title' );
    $short->decide( '', 'accepted', $userID );
    $short->store();
    $res2 = ( new expAiChangeApplier( expAiThread::fetchByID( $thread->attribute( 'id' ) ) ) )->apply();
    $check( $res2['drafts'] && (int) $res2['drafts'][0]['version'] === (int) $draft['version'], 'a later apply writes into the same draft' );

    // conflict: the published value changes after staging
    $update->execute( array( 'object_id' => (int) $a1->attribute( 'id' ), 'fields' => array( 'intro' => '<p>Completely new intro.</p>' ) ), $ctx );
    $c3 = expAiChange::fetchTarget( $thread->attribute( 'id' ), $a1->attribute( 'id' ), '', $lang, 'intro' );
    $c3->decide( '', 'accepted', $userID );
    $c3->store();
    eZContentFunctions::updateAndPublishObject( $a1, array( 'attributes' => array( 'intro' => $xml( array( 'Changed by a colleague.' ) ) ) ) );
    $check( $c3->baseChanged(), 'the base changed since staging' );
    $res3 = ( new expAiChangeApplier( expAiThread::fetchByID( $thread->attribute( 'id' ) ) ) )->apply();
    $c3 = expAiChange::fetchByID( $c3->attribute( 'id' ) );
    $check( $res3['conflicts'] === array( (int) $c3->attribute( 'id' ) ) && $c3->attribute( 'decision' ) === 'conflict' && !$res3['applied'], 'apply reports a conflict and writes nothing' );
    expAiChangeStager::restage( $c3 );
    $c3 = expAiChange::fetchByID( $c3->attribute( 'id' ) );
    $check( $c3->attribute( 'decision' ) !== 'conflict' && !$c3->baseChanged() && strpos( $c3->hunks()[0]['base_text'], 'colleague' ) !== false,
            'restage compares with the current version' );

    // ------------------------------------------------------------------ a new object (its own thread)
    $thread2 = $newThread( array( 'nodes' => array( (int) $folderNode->attribute( 'node_id' ) ) ), 'T360 test new object' );
    $ctx2 = $contextOf( $thread2 );
    $create = new expAiToolCreateDraft( 'create_draft' );
    $objectsBefore = (int) eZPersistentObject::count( eZContentObject::definition() );
    $rc = $create->execute( array( 'parent_node_id' => (int) $folderNode->attribute( 'node_id' ), 'class_identifier' => 'article', 'language' => $lang,
                                   'fields' => array( 'title' => 'Opening hours Harbour', 'intro' => '<p>The harbour office opens in November.</p>' ) ), $ctx2 );
    $check( count( $rc['change_ids'] ) === 2 && preg_match( '/^[a-f0-9]{16}$/', $rc['new_key'] ) && (int) eZPersistentObject::count( eZContentObject::definition() ) === $objectsBefore,
            'create_draft staged a new object (no object created yet)' );
    $rc2 = $create->execute( array( 'parent_node_id' => (int) $folderNode->attribute( 'node_id' ), 'class_identifier' => 'article', 'new_key' => $rc['new_key'],
                                    'fields' => array( 'title' => 'Opening hours: Harbour' ) ), $ctx2 );
    $check( $rc2['new_key'] === $rc['new_key'] && count( expAiChange::fetchNew( $thread2->attribute( 'id' ), $rc['new_key'] ) ) === 2, 'new_key revises the same proposal' );
    $expect( function () use ( $create, $ctx2 ) { $create->execute( array( 'parent_node_id' => 2, 'class_identifier' => 'article', 'fields' => array( 'title' => 'x' ) ), $ctx2 ); },
             'outside the scope', 'a parent outside the scope is refused' );
    foreach ( expAiChange::fetchNew( $thread2->attribute( 'id' ), $rc['new_key'] ) as $c )
    {
        $c->decide( '', 'accepted', $userID );
        $c->store();
    }
    $rn = ( new expAiChangeApplier( expAiThread::fetchByID( $thread2->attribute( 'id' ) ) ) )->apply();
    $check( count( $rn['drafts'] ) === 1 && $rn['drafts'][0]['created'] && !$rn['errors'], 'the new object became a draft' );
    $newObject = eZContentObject::fetch( $rn['drafts'][0]['object_id'] );
    $check( $newObject && (int) $newObject->attribute( 'status' ) === eZContentObject::STATUS_DRAFT, 'the new object is a draft, not published' );
    $nlog = expAiDraftLog::fetchByVersion( $newObject->attribute( 'id' ), 1 );
    $check( $nlog && $nlog->attribute( 'action' ) === 'create' && $nlog->attribute( 'source' ) === 'vector', 'draft log of the new object: create, vector' );
    $ndm = $newObject->fetchDataMap( 1, $lang );
    $check( (string) $ndm['title']->attribute( 'data_text' ) === 'Opening hours: Harbour' && strpos( (string) $ndm['intro']->attribute( 'data_text' ), 'harbour office' ) !== false,
            'the draft of the new object has the fields' );

    // ------------------------------------------------------------------ plan mode
    $thread3 = $newThread( array( 'subtrees' => array( (int) $folderNode->attribute( 'node_id' ) ), 'languages' => array( $lang ) ), 'T360 test plan' );
    $ctx3 = $contextOf( $thread3 );
    $ctx3->staged = false;
    $propose = new expAiToolProposePlan( 'propose_plan' );
    $rp = $propose->execute( array( 'items' => array(
        array( 'action' => 'update', 'object_id' => (int) $articles[0]->attribute( 'id' ), 'languages' => array( $lang ), 'fields' => array( 'intro' ), 'summary' => 'Shorter intro' ),
        array( 'action' => 'update', 'node_id' => (int) $articles[1]->mainNodeID(), 'languages' => array( $lang ), 'fields' => array( 'title', 'intro' ), 'summary' => 'New hours' ),
        array( 'action' => 'create', 'parent_node_id' => (int) $folderNode->attribute( 'node_id' ), 'class' => 'article', 'languages' => array( $lang ), 'fields' => array( 'title', 'intro' ), 'summary' => 'Harbour' ),
        array( 'action' => 'update', 'node_id' => 2, 'languages' => array( $lang ), 'summary' => 'Outside' ),
        array( 'action' => 'translate', 'object_id' => (int) $articles[0]->attribute( 'id' ), 'languages' => array( 'ger-DE' ), 'summary' => 'German' ),
    ), 'assumptions' => array( 'Hours from the contact page' ), 'risks' => array( 'Wrong hours' ) ), $ctx3 );
    $plan = expAiPlan::fetchByID( $rp['plan_id'] );
    $check( $plan && $plan->attribute( 'state' ) === 'proposed' && $rp['items'] === 5 && $rp['runnable'] === 3, 'propose_plan stored 5 items, 3 runnable' );
    $items = $plan->items();
    $check( $items[3]['refused'] !== '' && $items[4]['refused'] !== '', 'outside the scope and a language outside the scope are refused: ' . $items[3]['refused'] . ' / ' . $items[4]['refused'] );
    $check( (int) $items[1]['target']['object_id'] === (int) $articles[1]->attribute( 'id' ) && $items[1]['target_name'] === 'Opening hours Station', 'a node target resolves to its object and name' );
    $e = $plan->estimate();
    $check( $e['low_micros'] <= $e['expected_micros'] && $e['expected_micros'] <= $e['high_micros'] && $e['tokens'] > 0 && count( $e['items'] ) === 3,
            'estimate: low ' . $e['low_micros'] . ' <= expected ' . $e['expected_micros'] . ' <= high ' . $e['high_micros'] . ', ' . $e['tokens'] . ' tokens' );
    $probe = ( new expAiBudget( $config ) )->estimate( $e['alias'], 1000000, 1000000 );
    echo '      model ' . $e['alias'] . ': 1M in + 1M out = ' . $probe['micros'] . " micros\n";
    $check( $e['known'] === ( $e['expected_micros'] > 0 ) || ( $e['known'] && $probe['micros'] === 0 ),'priced when the model has a price: ' . ( $e['known'] ? 'known' : 'unknown' ) );
    $priced = '';
    foreach ( array_keys( $config->allowedModels() ) as $a )
        if ( strpos( $a, 'haiku' ) !== false )
            $priced = $a;
    if ( $priced !== '' )
    {
        $ep = ( new expAiPlanEstimate( $config ) )->estimate( $plan->runnableItems(), $priced, 'auto' );
        $batch = array_values( array_filter( $ep['lanes'], function ( $l ) { return $l['batch']; } ) );
        $std = array_values( array_filter( $ep['lanes'], function ( $l ) { return $l['name'] === 'standard'; } ) );
        $check( $ep['known'] && $ep['expected_micros'] > 0 && $ep['low_micros'] < $ep['high_micros'], "$priced: priced, " . expAiBudget::money( $ep['low_micros'] ) . ' - ' . expAiBudget::money( $ep['high_micros'] ) );
        $check( !$batch || !$std || $batch[0]['expected'] < $std[0]['expected'], 'a batch lane is cheaper than standard (' . count( $ep['lanes'] ) . ' lane(s))' );
    }
    $check( isset( $e['meta']['assumptions'][0] ) && $e['meta']['risks'] === array( 'Wrong hours' ), 'assumptions and risks kept' );
    $kinds = array_map( function ( $ev ) { return $ev->attribute( 'kind' ); }, expAiThreadEvent::fetchAfter( $thread3->attribute( 'id' ), 0, 100 ) );
    $check( in_array( 'plan', $kinds, true ), 'a plan event' );
    $check( expAiThread::fetchByID( $thread3->attribute( 'id' ) )->restingState() === 'review', 'a proposed plan: the thread needs the editor' );

    // the estimate maths
    $est = new expAiPlanEstimate( $config );
    $t1 = $est->itemTokens( array( 'action' => 'update', 'fields' => array( 'intro' ) ), 3, 6000, 200 );
    $check( $t1['in'] === 3 * ( 6000 + 1500 + 200 ) + (int) ceil( 2 * ( 200 + $t1['out'] ) / 2 ) && $t1['out'] === 320 + 360, 'item tokens: rounds x (frame + history + context) + growth' );
    $check( $est->rounds( 'translate' ) === 2 && $est->rounds( 'create' ) === 3, 'expected rounds per action' );

    // edit: deselect an item and rewrite an instruction
    expAiPlanner::edit( $plan, $thread3, array( 0, 2 ), array( 2 => 'Harbour office opening hours' ), $config );
    $e2 = $plan->estimate();
    $check( count( $plan->runnableItems() ) === 2 && $e2['expected_micros'] <= $e['expected_micros'] && $plan->items()[2]['summary'] === 'Harbour office opening hours',
            'deselecting an item lowers the estimate; the instruction is edited' );

    // limits: a monthly cost limit below the estimate refuses the approval
    $ini->setVariable( 'Limits', 'MaxCostPerUserPerMonth', '0.000001' );
    $limits = $est->limits( max( 1, (int) $e2['expected_micros'] ), $userID );
    $check( !$limits['ok'] && $limits['message'] !== '', 'a limit that the plan would cross: ' . $limits['message'] );
    $expect( function () use ( $thread3, $plan, $config ) { expAiPlanner::approve( $thread3, $plan, array( 0, 2 ), array(), $config ); }, '', 'approval refused by the limit' );
    $plan = expAiPlan::fetchByID( $plan->attribute( 'id' ) );
    $check( $plan->attribute( 'state' ) === 'proposed', 'the plan stays proposed' );
    $ini->setVariable( 'Limits', 'MaxCostPerUserPerMonth', '' );

    // approval: the plan runs (queued and cancelled at once here: no model call)
    $run = expAiPlanner::approve( expAiThread::fetchByID( $thread3->attribute( 'id' ) ), $plan, array( 0, 2 ), array( 2 => 'Harbour office opening hours' ), $config );
    $t3 = expAiThread::fetchByID( $thread3->attribute( 'id' ) );
    $plan = expAiPlan::fetchByID( $plan->attribute( 'id' ) );
    $check( $plan->attribute( 'state' ) === 'running' && (int) $t3->attribute( 'plan_id' ) === (int) $plan->attribute( 'id' ) && $t3->attribute( 'run_mode' ) === 'write'
            && $t3->attribute( 'review_policy' ) === 'staged' && (int) $t3->attribute( 'reserved_micros' ) === (int) $run['reserved'], 'approved: running, write mode, staged, reserved ' . $run['reserved'] );
    $check( $run['items'] === 2, 'two items run' );
    if ( $run['mode'] === 'background' )
    {
        $job = expQueue::fetch( $run['job'] );
        $payload = $job->payloadArray();
        $check( count( $payload['options']['messages'] ) === 2 && !empty( $payload['options']['staged'] ) && (int) $job->attribute( 'cost_reserved_micros' ) === (int) $run['reserved'],
                'the job has one message per item, staged, and the reservation' );
        $check( strpos( $payload['options']['messages'][1], 'item 2 of 2: create' ) !== false && strpos( $payload['options']['messages'][1], 'Harbour office opening hours' ) !== false,
                'item message: ' . expAiText::cut( $payload['options']['messages'][1], 90 ) );
        expAiThreadRunner::cancel( $t3 );
    }
    else
        $check( count( expAiFollowup::waiting( $t3->attribute( 'id' ) ) ) === 2, 'no queue: two follow-ups wait' );
    $check( expAiChangeStager::approvedPlan( $t3 ) !== null, 'the thread has an approved plan: bulk staging allowed' );
    $ctx3b = $contextOf( $t3 );
    $u3 = $update->execute( array( 'object_id' => (int) $articles[1]->attribute( 'id' ), 'fields' => array( 'title' => 'Station hours' ) ), $ctx3b );
    $u4 = $update->execute( array( 'object_id' => (int) $articles[0]->attribute( 'id' ), 'fields' => array( 'title' => 'Main hours' ) ), $ctx3b );
    $check( $u3['change_ids'] && $u4['change_ids'], 'with an approved plan two objects can be staged' );
    expAiPlanner::complete( expAiThread::fetchByID( $t3->attribute( 'id' ) ) );
    $plan = expAiPlan::fetchByID( $plan->attribute( 'id' ) );
    $check( $plan->attribute( 'state' ) === 'done' && isset( $plan->estimate()['actual_micros'] ) && (int) expAiThread::fetchByID( $t3->attribute( 'id' ) )->attribute( 'reserved_micros' ) === 0,
            'complete: done, actual cost recorded, reservation released' );
    $cal = $est->calibration();
    $check( $cal['factor'] >= 0.5 && $cal['factor'] <= 3.0, 'calibration factor ' . $cal['factor'] . ' from ' . $cal['from'] . ' plan(s)' );

    // rejection of a new proposal
    $rp2 = $propose->execute( array( 'items' => array( array( 'action' => 'update', 'object_id' => (int) $articles[0]->attribute( 'id' ), 'languages' => array( $lang ), 'summary' => 'x' ) ) ), $ctx3 );
    $p2 = expAiPlan::fetchByID( $rp2['plan_id'] );
    expAiPlanner::reject( expAiThread::fetchByID( $thread3->attribute( 'id' ) ), $p2 );
    $check( expAiPlan::fetchByID( $p2->attribute( 'id' ) )->attribute( 'state' ) === 'rejected', 'a plan can be rejected' );
    $expect( function () use ( $thread3, $p2, $config ) { expAiPlanner::approve( expAiThread::fetchByID( $thread3->attribute( 'id' ) ), expAiPlan::fetchByID( $p2->attribute( 'id' ) ), null, array(), $config ); },
             'no longer', 'a rejected plan cannot be approved' );
    // ------------------------------------------------------------------ a background turn meets the master switch and the veto (#370 M2)
    if ( class_exists( 'expAiGate' ) && class_exists( 'expQueueContext' ) && $priced !== '' )
    {
        $thread4 = $newThread( array( 'nodes' => array( (int) $articles[0]->mainNodeID() ) ), 'T360 test plan gate' );
        $thread4->setAttribute( 'model_alias', $priced );
        $thread4->store();
        // a job in memory only: no worker can take it
        $job = new expQueueJob( array( 'id' => 0, 'uuid' => expAiThread::newUuid(), 'type' => expAiThreadRunner::JOB_TYPE, 'group_id' => 0,
                                       'payload' => json_encode( array( 'thread_id' => (int) $thread4->attribute( 'id' ), 'node_id' => (int) $articles[0]->mainNodeID(),
                                                                        'message' => 'Say OK.', 'options' => array( 'alias' => $priced, 'mode' => 'write', 'staged' => true, 'action' => 'vector' ) ) ) ) );
        $handler = new expAiThreadTurnHandler();
        $enabled = $ini->variable( 'AISettings', 'Enabled' );
        $ini->setVariable( 'AISettings', 'Enabled', 'false' );
        $res = expQueueResult::from( $handler->run( $job, new expQueueContext( $job, 't360v4' ) ) );
        $t4 = expAiThread::fetchByID( $thread4->attribute( 'id' ) );
        $events = expAiThreadEvent::fetchAfter( $t4->attribute( 'id' ), 0, 50 );
        $held = array_filter( $events, function ( $e ) { $p = $e->payload(); return $e->attribute( 'kind' ) === 'status' && !empty( $p['held'] ); } );
        $check( $res->outcome === expQueueResult::HOLD && strpos( $res->message, expAiGate::HELD_PREFIX ) === 0 && $t4->attribute( 'state' ) === 'queued' && $held,
                'switched off: the turn is held (' . $res->message . '), the thread waits with the reason' );
        $ini->setVariable( 'AISettings', 'Enabled', $enabled );
        $ini->setVariable( 'RequestVeto', 'Rules', array( 't360v4' ) );
        $ini->setVariable( 'RequestVeto_t360v4', 'Reason', 'No AI today (test).' );
        $res = expQueueResult::from( $handler->run( $job, new expQueueContext( $job, 't360v4' ) ) );
        $t4 = expAiThread::fetchByID( $thread4->attribute( 'id' ) );
        $errors = array_filter( expAiThreadEvent::fetchAfter( $t4->attribute( 'id' ), 0, 50 ), function ( $e ) { return $e->attribute( 'kind' ) === 'error'; } );
        $last = $errors ? end( $errors )->payload() : array();
        $check( $res->outcome === expQueueResult::FAIL && $res->errorClass === 'veto' && $t4->attribute( 'state' ) === 'failed'
                && isset( $last['message'] ) && strpos( $last['message'], 'No AI today' ) !== false, 'a veto fails the turn for good, the reason is in the thread: ' . ( isset( $last['message'] ) ? $last['message'] : '-' ) );
        $ini->setVariable( 'RequestVeto', 'Rules', array() );
    }

    $panelCtx = new expAiToolContext( $config, (int) $folderNode->attribute( 'node_id' ), null );
    $expect( function () use ( $propose, $panelCtx ) { $propose->execute( array( 'items' => array() ), $panelCtx ); }, 'Vector thread', 'propose_plan only in Vector threads' );
}
catch ( Throwable $e )
{
    $check( false, 'unexpected ' . get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
}

// ------------------------------------------------------------------ clean up
foreach ( $threads as $t )
{
    $fresh = expAiThread::fetchByID( $t->attribute( 'id' ) );
    if ( !$fresh )
        continue;
    foreach ( (array) eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null, array( 'thread_id' => (int) $fresh->attribute( 'id' ) ) ) as $log )
    {
        $object = eZContentObject::fetch( (int) $log->attribute( 'contentobject_id' ) );
        if ( $object && (int) $object->attribute( 'status' ) === eZContentObject::STATUS_DRAFT )
            $object->purge();
        $log->remove();
    }
    $fresh->removeAll( true );
}
if ( isset( $folderNode ) && $folderNode )
    eZContentObjectTreeNode::removeSubtrees( array( (int) $folderNode->attribute( 'node_id' ) ), true );
echo ( $failures ? 'FAIL' : 'PASS' ) . " expai vector review and plan: $failures failure(s)\n";
eZScript::instance()->shutdown( $failures ? 1 : 0 );
