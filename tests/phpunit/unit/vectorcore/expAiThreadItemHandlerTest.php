<?php
/**
 * expAiThreadItemHandler (job type expai.thread.item): one item of an approved plan in its child run, with jobs in
 * memory only and the stub provider of the fixture.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectorcoretestcase.php';

class expAiThreadItemHandlerTest extends expAiVectorCoreTestCase
{
    /** @var int */
    private $nodeID = 0;

    private function job( array $payload )
    {
        return new expQueueJob( array( 'id' => 0, 'uuid' => expAiThread::newUuid(), 'type' => 'expai.thread.item', 'group_id' => 0, 'attempts' => 1,
                                       'payload' => json_encode( $payload ) ) );
    }

    /** @return array( thread, plan, runs ) a plan of two items, the second waiting for the first */
    private function plan( array $states, $title )
    {
        $article = $this->article( 'XTB item ' . $title );
        $this->nodeID = (int) $article->mainNodeID();
        $thread = $this->thread( array( 'title' => $title, 'model_alias' => 'xtb.stub', 'scope' => array( 'nodes' => array( $this->nodeID ) ) ) );
        list( $plan, $runs ) = $this->planWithRuns( $thread, array(
            array( 'state' => $states[0], 'target' => array( 'object_id' => (int) $article->attribute( 'id' ) ) ),
            array( 'state' => $states[1], 'depends_on' => array( 0 ), 'action' => 'translate', 'languages' => array( 'ger-DE' ) ) ) );
        return array( expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ), $plan, $runs );
    }

    private function payload( expAiThread $thread, expAiPlan $plan, expAiThreadRun $run, array $more = array() )
    {
        return $more + array( 'thread_id' => (int) $thread->attribute( 'id' ), 'run_id' => (int) $run->attribute( 'id' ), 'plan_id' => (int) $plan->attribute( 'id' ),
                              'item' => (int) $run->attribute( 'plan_item' ), 'node_id' => $this->nodeID, 'message' => 'Do the item.',
                              'options' => array( 'alias' => 'xtb.stub', 'read_only' => true, 'mode' => 'write', 'staged' => true, 'action' => 'vector' ) );
    }

    private function runItem( array $payload, ?expAiVectorCoreQueueContext &$context = null )
    {
        $job = $this->job( $payload );
        $context = $context ?: new expAiVectorCoreQueueContext( $job );
        return ( new expAiThreadItemHandler() )->run( $job, $context );
    }

    public function testValidateAndDescribe(): void
    {
        $handler = new expAiThreadItemHandler();
        $this->assertSame( 'thread_id, run_id and node_id are required', $handler->validate( array( 'thread_id' => 1, 'node_id' => 2 ) ) );
        $this->assertNull( $handler->validate( array( 'thread_id' => 1, 'run_id' => 3, 'node_id' => 2 ) ) );
        $this->assertSame( 'Thread 4, plan item 3', $handler->describe( $this->job( array( 'thread_id' => 4, 'item' => 2 ) ) ) );
        $this->assertSame( 'Thread 0, plan item 0', $handler->describe( $this->job( array() ) ) );
    }

    public function testAMissingRunOrARunOfAnotherThreadFailsForGood(): void
    {
        list( $thread, $plan, $runs ) = $this->plan( array( 'queued', 'queued' ), 'gone' );
        $other = $this->thread( array( 'title' => 'other' ) );
        $this->expectException( expQueuePermanentException::class );
        $this->runItem( array( 'thread_id' => (int) $other->attribute( 'id' ), 'run_id' => (int) $runs[0]->attribute( 'id' ), 'node_id' => $this->nodeID ) );
    }

    public function testARunThatEndedIsNotRunAgain(): void
    {
        list( $thread, $plan, $runs ) = $this->plan( array( 'done', 'queued' ), 'ended' );
        $result = expQueueResult::from( $this->runItem( $this->payload( $thread, $plan, $runs[0] ) ) );
        $this->assertSame( expQueueResult::CANCELLED, $result->outcome );
        $this->assertSame( 'the run ended done', $result->message );
    }

    public function testAnOwnerWithoutAccessFailsTheRun(): void
    {
        list( $thread, $plan, $runs ) = $this->plan( array( 'queued', 'queued' ), 'access' );
        $thread->setAttribute( 'user_id', $this->userID + 1000000 );
        $thread->store();
        try
        {
            $this->runItem( $this->payload( $thread, $plan, $runs[0] ) );
            $this->fail( 'ran for another user' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertSame( 'access', $e->errorClass );
        }
        $this->assertSame( 'failed', expAiThreadRun::fetchByID( (int) $runs[0]->attribute( 'id' ) )->attribute( 'state' ) );
        $this->assertSame( 'stopped', expAiThreadRun::fetchByID( (int) $runs[1]->attribute( 'id' ) )->attribute( 'state' ), 'the item that waits for it stops' );
    }

    public function testARunWithoutConversationFails(): void
    {
        list( $thread, $plan, $runs ) = $this->plan( array( 'queued', 'queued' ), 'no conversation' );
        $runs[0]->setAttribute( 'conversation_id', 0 );
        $runs[0]->store();
        $this->expectException( expQueuePermanentException::class );
        $this->expectExceptionMessage( 'the run has no conversation' );
        $this->runItem( $this->payload( $thread, $plan, $runs[0] ) );
    }

    public function testSwitchedOffTheItemIsHeld(): void
    {
        list( $thread, $plan, $runs ) = $this->plan( array( 'queued', 'queued' ), 'held' );
        $this->ini( 'AISettings', 'Enabled', 'false' );
        $result = expQueueResult::from( $this->runItem( $this->payload( $thread, $plan, $runs[0] ) ) );
        $this->assertSame( expQueueResult::HOLD, $result->outcome );
        $status = array_values( array_filter( expAiThreadEvent::fetchAfter( (int) $thread->attribute( 'id' ), 0, 100 ), function ( $e ) { return $e->attribute( 'kind' ) === 'status'; } ) );
        $payload = end( $status )->payload();
        $this->assertSame( (int) $runs[0]->attribute( 'id' ), $payload['run_id'] );
        $this->assertNotEmpty( $payload['held'] );
    }

    public function testAnItemWaitsForTheItemItDependsOnOrStopsWhenThatFailed(): void
    {
        list( $thread, $plan, $runs ) = $this->plan( array( 'running', 'queued' ), 'dependencies' );
        $result = expQueueResult::from( $this->runItem( $this->payload( $thread, $plan, $runs[1] ) ) );
        $this->assertSame( expQueueResult::DEFER, $result->outcome );
        $this->assertSame( 30, $result->seconds );
        $this->assertStringContainsString( 'waits for run ' . (int) $runs[0]->attribute( 'id' ), $result->message );
        $this->assertSame( 'waiting', expAiThreadRun::fetchByID( (int) $runs[1]->attribute( 'id' ) )->attribute( 'state' ) );

        $runs[0]->setAttribute( 'state', 'failed' );
        $runs[0]->store();
        $result = expQueueResult::from( $this->runItem( $this->payload( $thread, $plan, $runs[1] ) ) );
        $this->assertSame( expQueueResult::CANCELLED, $result->outcome );
        $this->assertSame( 'a dependency did not finish', $result->message );
        $this->assertSame( 'stopped', expAiThreadRun::fetchByID( (int) $runs[1]->attribute( 'id' ) )->attribute( 'state' ) );
        $this->assertSame( array(), expAiVectorCoreStubProvider::$requests );
    }

    public function testAnItemRunsWithTheChangesOfItsDependencyAsSource(): void
    {
        list( $thread, $plan, $runs ) = $this->plan( array( 'done', 'queued' ), 'source' );
        $change = new expAiChange( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'run_id' => (int) $runs[0]->attribute( 'id' ), 'contentobject_id' => 4242,
                                          'language_code' => 'eng-GB', 'attribute_identifier' => 'title', 'datatype' => 'ezstring',
                                          'proposed' => 'XTB the staged title', 'decision' => 'pending', 'created' => time(), 'modified' => time() ) );
        $change->store();
        expAiVectorCoreStubProvider::$replies = array(
            array( array( 'type' => 'tool_use', 'id' => 'toolu_xtb2', 'name' => 'get_node', 'input' => array( 'node_id' => $this->nodeID ) ) ),
            array( array( 'type' => 'text', 'text' => 'Translated.' ) ) );
        $context = null;
        $out = $this->runItem( $this->payload( $thread, $plan, $runs[1] ), $context );
        $this->assertSame( array( 'steps' => 2, 'status' => 'done', 'run_id' => (int) $runs[1]->attribute( 'id' ) ), $out );
        $first = json_encode( expAiVectorCoreStubProvider::$requests[0]['messages'] );
        $this->assertStringContainsString( 'XTB the staged title', $first, 'the staged value of the item it waits for is the source' );
        $this->assertStringContainsString( 'field title', $first );
        $this->assertSame( array( 'sent' => 1 ), $context->saved );
        $run = expAiThreadRun::fetchByID( (int) $runs[1]->attribute( 'id' ) );
        $this->assertSame( 'done', $run->attribute( 'state' ) );
        $this->assertSame( 2, (int) $run->attribute( 'progress_done' ) );
        $this->assertGreaterThan( 0, (int) $run->attribute( 'started' ) );
        // the last run ended: the plan is settled and the thread rests
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertNotContains( $fresh->attribute( 'state' ), array( 'running', 'queued' ) );
        $this->assertSame( 'done', expAiPlan::fetchByID( (int) $plan->attribute( 'id' ) )->attribute( 'state' ) );
    }

    public function testStopVetoAndAFailedModelCallEndTheRun(): void
    {
        list( $thread, $plan, $runs ) = $this->plan( array( 'queued', 'queued' ), 'end' );
        $job = $this->job( $this->payload( $thread, $plan, $runs[0] ) );
        $context = new expAiVectorCoreQueueContext( $job );
        $context->cancelled = true;
        try
        {
            ( new expAiThreadItemHandler() )->run( $job, $context );
            $this->fail( 'not stopped' );
        }
        catch ( expQueueCancelledException $e )
        {
            $this->assertSame( 'stopped by the editor', $e->getMessage() );
        }
        $this->assertSame( 'stopped', expAiThreadRun::fetchByID( (int) $runs[0]->attribute( 'id' ) )->attribute( 'state' ) );

        // the second item no longer waits (its dependency stopped): give it no dependency and a veto
        $plan->setItems( array_map( function ( $i ) { $i['depends_on'] = array(); return $i; }, $plan->items() ) );
        $plan->store();
        $runs[1]->setAttribute( 'state', 'queued' );
        $runs[1]->store();
        $this->ini( 'RequestVeto', 'Rules', array( 'xtb' ) );
        $this->ini( 'RequestVeto_xtb', 'Reason', 'No AI for items.' );
        $result = expQueueResult::from( $this->runItem( $this->payload( $thread, $plan, $runs[1] ) ) );
        $this->assertSame( expQueueResult::FAIL, $result->outcome );
        $this->assertSame( 'veto', $result->errorClass );
        $this->assertSame( 'failed', expAiThreadRun::fetchByID( (int) $runs[1]->attribute( 'id' ) )->attribute( 'state' ) );

        $this->ini( 'RequestVeto', 'Rules', array() );
        $runs[1]->setAttribute( 'state', 'queued' );
        $runs[1]->store();
        expAiVectorCoreStubProvider::$throw = new expAiException( 'The stub refuses.', 'stub' );
        try
        {
            $this->runItem( $this->payload( $thread, $plan, $runs[1] ) );
            $this->fail( 'a failed call did not fail the item' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertSame( 'model', $e->errorClass );
            $this->assertSame( 'The stub refuses.', $e->getMessage() );
        }
        $this->assertSame( 'failed', expAiThreadRun::fetchByID( (int) $runs[1]->attribute( 'id' ) )->attribute( 'state' ) );
    }
}
