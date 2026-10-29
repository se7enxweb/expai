<?php
/**
 * expAiPlanner: plan items checked against the site, the scope and the editor's permissions, proposing, approving
 * (limits, background job, follow-ups without a queue), rejecting and completing a plan. Approvals that queue a job
 * run inside a transaction that is rolled back.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectorcoretestcase.php';

class expAiPlannerTest extends expAiVectorCoreTestCase
{
    /** @var eZContentObject */
    private $article;

    /** @var int */
    private $folderNodeID = 0;

    /** @var string */
    private $language = '';

    private function content()
    {
        $this->article = $this->article( 'XTB planner article' );
        $this->folderNodeID = (int) eZContentObjectTreeNode::fetch( $this->article->mainNodeID() )->attribute( 'parent_node_id' );
        $this->language = (string) $this->article->attribute( 'initial_language_code' );
    }

    private static function message( callable $fn )
    {
        try
        {
            $fn();
        }
        catch ( expAiException $e )
        {
            return $e->translatedMessage();
        }
        return '';
    }

    public function testItemsAreCheckedAndRefusedWithTheReason(): void
    {
        $this->content();
        $thread = $this->thread( array( 'title' => 'validate', 'model_alias' => 'xtb.stub',
                                        'scope' => array( 'subtrees' => array( $this->folderNodeID ), 'languages' => array( $this->language ) ) ) );
        $items = expAiPlanner::validateItems( $thread, array(
            array( 'action' => 'update', 'object_id' => (int) $this->article->attribute( 'id' ), 'languages' => array( $this->language ), 'fields' => array( 'title', 'bad field' ),
                   'summary' => "Shorter\ntitle" ),
            array( 'action' => 'update', 'object_id' => (int) $this->article->attribute( 'id' ) ),
            array( 'action' => 'update', 'object_id' => (int) $this->article->attribute( 'id' ), 'languages' => array( 'xxx-XX' ) ),
            array( 'action' => 'create', 'parent_node_id' => 2147483000, 'class' => 'article', 'languages' => array( $this->language ) ),
            array( 'action' => 'create', 'parent_node_id' => 2, 'class' => 'article', 'languages' => array( $this->language ) ),
            array( 'action' => 'create', 'parent_node_id' => $this->folderNodeID, 'class' => 'noclass', 'languages' => array( $this->language ) ),
            array( 'action' => 'create', 'node_id' => $this->folderNodeID, 'class' => 'article', 'languages' => array( $this->language ), 'depends_on' => array( 'x', 1, 9 ) ),
            array( 'action' => 'update', 'node_id' => (int) $this->article->mainNodeID(), 'languages' => array( $this->language ) ),
            array( 'action' => 'update', 'object_id' => 2147483000, 'languages' => array( $this->language ) ),
            array( 'action' => 'remove', 'node_id' => 2, 'languages' => array( $this->language ) ),
            'not an item',
        ) );
        $this->assertSame( '', $items[0]['refused'] );
        $this->assertSame( array( 'title' ), $items[0]['fields'], 'malformed field names are dropped' );
        $this->assertSame( 'Shorter title', $items[0]['summary'] );
        $this->assertSame( 'XTB planner article', $items[0]['target_name'] );
        $this->assertSame( 'article', $items[0]['class'] );
        $this->assertSame( (int) $this->article->mainNodeID(), $items[0]['target']['node_id'] );
        $this->assertSame( 'No language given.', $items[1]['refused'] );
        $this->assertSame( 'The language xxx-XX is not used on this site.', $items[2]['refused'] );
        $this->assertSame( 'The location does not exist or you may not read it.', $items[3]['refused'] );
        $this->assertSame( '#2147483000', $items[3]['target_name'] );
        $this->assertSame( 'The location lies outside the scope of this thread.', $items[4]['refused'] );
        $this->assertSame( 'There is no content class "noclass".', $items[5]['refused'] );
        $this->assertSame( '', $items[6]['refused'], 'a node_id is the parent of a new object' );
        $this->assertSame( $this->folderNodeID, $items[6]['target']['parent_node_id'] );
        $this->assertSame( array( 0 ), $items[6]['depends_on'], 'numbers are 1-based; later items and non-numbers are dropped' );
        $this->assertSame( '', $items[7]['refused'], 'an object by its node' );
        $this->assertSame( (int) $this->article->attribute( 'id' ), $items[7]['target']['object_id'] );
        $this->assertSame( 'The object does not exist or you may not read it.', $items[8]['refused'] );
        $this->assertSame( 'update', $items[9]['action'], 'an unknown action is an update' );
        $this->assertSame( 'The object lies outside the scope of this thread.', $items[9]['refused'] );
        $this->assertSame( 'No language given.', $items[10]['refused'] );

        $this->ini( 'VectorSettings', 'MaxPlanItems', '2' );
        $this->assertCount( 2, expAiPlanner::validateItems( $thread, array_fill( 0, 5, array() ) ) );

        $classes = $this->thread( array( 'title' => 'class scope', 'scope' => array( 'subtrees' => array( $this->folderNodeID ), 'classes' => array( 'folder' ) ) ) );
        $refused = expAiPlanner::validateItems( $classes, array( array( 'action' => 'create', 'parent_node_id' => $this->folderNodeID, 'class' => 'article', 'languages' => array( $this->language ) ) ) );
        $this->assertSame( 'The class "article" is not in the scope of this thread.', $refused[0]['refused'] );
        $languages = $this->thread( array( 'title' => 'language scope', 'scope' => array( 'languages' => array( 'nor-NO' ) ) ) );
        $refused = expAiPlanner::validateItems( $languages, array( array( 'action' => 'update', 'object_id' => (int) $this->article->attribute( 'id' ), 'languages' => array( $this->language ) ) ) );
        $this->assertSame( "The language {$this->language} is not in the scope of this thread.", $refused[0]['refused'] );
    }

    public function testTranslationsWaitForTheUpdateOfTheirSource(): void
    {
        $items = expAiPlanner::autoDependencies( array(
            array( 'action' => 'update', 'target' => array( 'object_id' => 5 ), 'languages' => array( 'eng-GB' ), 'depends_on' => array() ),
            array( 'action' => 'update', 'target' => array( 'object_id' => 6 ), 'languages' => array( 'eng-GB' ), 'depends_on' => array() ),
            array( 'action' => 'translate', 'target' => array( 'object_id' => 5 ), 'languages' => array( 'ger-DE' ), 'depends_on' => array() ),
            array( 'action' => 'translate', 'target' => array( 'object_id' => 5 ), 'languages' => array( 'eng-GB' ), 'depends_on' => array() ),
            array( 'action' => 'translate', 'target' => array(), 'languages' => array( 'ger-DE' ), 'depends_on' => array() ) ) );
        $this->assertSame( array( 0 ), $items[2]['depends_on'] );
        $this->assertSame( array(), $items[3]['depends_on'], 'not the language the update writes' );
        $this->assertSame( array(), $items[4]['depends_on'] );
        $this->assertSame( array( 0 => array(), 2 => array( 0 ) ),
                           expAiPlanner::runnableDependencies( array( array( 'index' => 0 ), array( 'index' => 2, 'depends_on' => array( 0, 1 ) ) ) ),
                           'a dependency on an item that does not run is dropped' );
    }

    public function testProposeSupersedesTheEarlierProposalAndRejectEndsIt(): void
    {
        $this->content();
        $thread = $this->thread( array( 'title' => 'propose', 'model_alias' => 'xtb.stub', 'scope' => array( 'subtrees' => array( $this->folderNodeID ) ) ) );
        $this->assertSame( 'A plan needs at least one item.', self::message( function () use ( $thread ) { expAiPlanner::propose( $thread, array() ); } ) );
        $item = array( 'action' => 'update', 'object_id' => (int) $this->article->attribute( 'id' ), 'languages' => array( $this->language ) );
        $first = expAiPlanner::propose( $thread, array( 'items' => array( $item ), 'risks' => array( 'A risk', array( 'not scalar' ), '' ) ), 'xtb.stub' );
        $this->assertSame( array( 'A risk' ), $first->estimate()['meta']['risks'] );
        $this->assertSame( 'xtb.stub', $first->estimate()['meta']['model'] );
        $second = expAiPlanner::propose( $thread, array( 'items' => array( $item, $item ) ) );
        $this->assertSame( 'superseded', expAiPlan::fetchByID( (int) $first->attribute( 'id' ) )->attribute( 'state' ) );
        $this->assertSame( (int) $second->attribute( 'id' ), (int) expAiPlan::fetchCurrent( (int) $thread->attribute( 'id' ) )->attribute( 'id' ) );

        $edited = expAiPlanner::edit( $second, $thread, array( 1 ), array( 1 => "New\ninstruction", 0 => array( 'x' ) ) );
        $this->assertFalse( $edited->items()[0]['selected'] );
        $this->assertSame( 'New instruction', $edited->items()[1]['summary'] );
        $this->assertCount( 1, $edited->runnableItems() );
        $this->assertSame( 'proposed', expAiPlan::fetchByID( (int) $second->attribute( 'id' ) )->attribute( 'state' ), 'edit does not store' );

        $thread->setState( 'review' );
        $thread->store();
        expAiPlanner::reject( $thread, $second );
        $this->assertSame( 'rejected', expAiPlan::fetchByID( (int) $second->attribute( 'id' ) )->attribute( 'state' ) );
        $this->assertSame( 'idle', expAiThread::fetchByID( (int) $thread->attribute( 'id' ) )->attribute( 'state' ) );
        $this->assertSame( 'This plan can no longer be rejected.', self::message( function () use ( $thread, $second ) { expAiPlanner::reject( $thread, $second ); } ) );
        $this->assertSame( 'This plan can no longer be approved.', self::message( function () use ( $thread, $second ) { expAiPlanner::approve( $thread, $second ); } ) );
    }

    public function testApproveRefusals(): void
    {
        $this->content();
        $thread = $this->thread( array( 'title' => 'approve refusals', 'model_alias' => 'xtb.stub', 'scope' => array( 'subtrees' => array( $this->folderNodeID ) ) ) );
        $item = array( 'action' => 'update', 'object_id' => (int) $this->article->attribute( 'id' ), 'languages' => array( $this->language ) );
        $plan = expAiPlanner::propose( $thread, array( 'items' => array( $item ) ) );
        $this->assertSame( 'Choose at least one item that can run.', self::message( function () use ( $thread, $plan ) { expAiPlanner::approve( $thread, $plan, array() ); } ) );
        $other = $this->thread( array( 'title' => 'other' ) );
        $this->assertSame( 'This plan can no longer be approved.', self::message( function () use ( $other, $plan ) { expAiPlanner::approve( $other, $plan ); } ) );
        $thread->setAttribute( 'model_alias', 'gone.model' );
        $this->assertSame( 'The model of this thread is no longer available to you. Choose another model.',
                           self::message( function () use ( $thread, $plan ) { expAiPlanner::approve( $thread, $plan ); } ) );
        $thread->setAttribute( 'model_alias', 'xtb.stub' );
        $this->ini( 'Model_xtb.stub', 'InputPerMTok', '100000' );
        $this->ini( 'Model_xtb.stub', 'OutputPerMTok', '100000' );
        $this->ini( 'Pricing', 'Enabled', 'true' );
        $this->ini( 'Limits', 'MaxCostPerUserPerDay', '0.000001' );
        $plan = expAiPlan::fetchByID( (int) $plan->attribute( 'id' ) );
        $this->assertStringStartsWith( 'This plan needs about ', self::message( function () use ( $thread, $plan ) { expAiPlanner::approve( $thread, $plan ); } ) );
        $this->assertSame( 'proposed', expAiPlan::fetchByID( (int) $plan->attribute( 'id' ) )->attribute( 'state' ) );
    }

    public function testApproveQueuesTheItemsAsOneJobAndCompleteRecordsTheCost(): void
    {
        $this->content();
        $thread = $this->thread( array( 'title' => 'approve', 'model_alias' => 'xtb.stub', 'run_mode' => 'plan', 'review_policy' => 'direct',
                                        'scope' => array( 'subtrees' => array( $this->folderNodeID ) ) ) );
        $item = array( 'action' => 'update', 'object_id' => (int) $this->article->attribute( 'id' ), 'languages' => array( $this->language ), 'summary' => 'Fix it' );
        $plan = expAiPlanner::propose( $thread, array( 'items' => array( $item, $item ) ) );
        $this->inTransaction();
        $out = expAiPlanner::approve( $thread, $plan, null, array( 0 => 'Fix the title' ) );
        $this->assertSame( 'background', $out['mode'] );
        $this->assertSame( 2, $out['items'] );
        $job = expQueue::fetch( $out['job'] );
        $payload = $job->payloadArray();
        $this->assertCount( 2, $payload['options']['messages'] );
        $this->assertStringContainsString( 'Approved plan, item 1 of 2: update object ' . (int) $this->article->attribute( 'id' ), $payload['options']['messages'][0] );
        $this->assertStringContainsString( 'Instruction: Fix the title', $payload['options']['messages'][0] );
        $this->assertSame( (int) $plan->attribute( 'id' ), $payload['options']['plan_id'] );
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertSame( 'write', $fresh->attribute( 'run_mode' ) );
        $this->assertSame( 'staged', $fresh->attribute( 'review_policy' ) );
        $this->assertSame( (int) $plan->attribute( 'id' ), (int) $fresh->attribute( 'plan_id' ) );
        $approved = expAiPlan::fetchByID( (int) $plan->attribute( 'id' ) );
        $this->assertSame( 'running', $approved->attribute( 'state' ) );
        $this->assertSame( $this->userID, (int) $approved->attribute( 'approved_by' ) );

        expAiPlanner::complete( $fresh );
        $done = expAiPlan::fetchByID( (int) $plan->attribute( 'id' ) );
        $this->assertSame( 'done', $done->attribute( 'state' ) );
        $this->assertSame( 0, $done->estimate()['actual_micros'] );
        $this->assertSame( 0, (int) expAiThread::fetchByID( (int) $thread->attribute( 'id' ) )->attribute( 'reserved_micros' ) );
        expAiPlanner::complete( $fresh );
        expAiPlanner::complete( $this->thread( array( 'title' => 'no plan' ) ) );
        $this->assertSame( 'done', expAiPlan::fetchByID( (int) $plan->attribute( 'id' ) )->attribute( 'state' ), 'only a running plan completes' );
    }

    public function testWithoutAQueueTheItemsWaitAsFollowUps(): void
    {
        $this->content();
        $thread = $this->thread( array( 'title' => 'no queue', 'model_alias' => 'xtb.stub', 'scope' => array( 'subtrees' => array( $this->folderNodeID ) ) ) );
        $item = array( 'action' => 'update', 'object_id' => (int) $this->article->attribute( 'id' ), 'languages' => array( $this->language ) );
        $plan = expAiPlanner::propose( $thread, array( 'items' => array( $item, $item ) ) );
        // the job type of thread turns is unknown to the queue for this test only
        $queue = expQueueConfig::instance();
        $types = $queue->jobTypes();
        $property = new ReflectionProperty( $queue, 'types' );
        $without = $types;
        unset( $without[expAiThreadRunner::JOB_TYPE] );
        $property->setValue( $queue, $without );
        $this->cleanup( static function () use ( $property, $queue, $types ) { $property->setValue( $queue, $types ); } );
        $this->assertFalse( expAiThreadRunner::queueAvailable() );
        $out = expAiPlanner::approve( $thread, $plan );
        $this->assertSame( array( 'mode' => 'foreground', 'job' => '', 'reserved' => $out['reserved'], 'items' => 2 ), $out );
        $waiting = expAiFollowup::waiting( (int) $thread->attribute( 'id' ) );
        $this->assertCount( 2, $waiting );
        $this->assertStringStartsWith( 'Approved plan, item 2 of 2', (string) $waiting[1]->attribute( 'body' ) );
    }
}
