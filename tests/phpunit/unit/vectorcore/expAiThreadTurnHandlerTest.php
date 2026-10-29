<?php
/**
 * expAiThreadTurnHandler (job type expai.thread.turn): a turn of a thread run by the queue worker, here with jobs in
 * memory only and the stub provider of the fixture (no worker, no network).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectorcoretestcase.php';

class expAiThreadTurnHandlerTest extends expAiVectorCoreTestCase
{
    /** @return expQueueJob a turn job that exists in memory only */
    private function job( array $payload )
    {
        return new expQueueJob( array( 'id' => 0, 'uuid' => expAiThread::newUuid(), 'type' => expAiThreadRunner::JOB_TYPE, 'group_id' => 0,
                                       'attempts' => 1, 'payload' => json_encode( $payload ) ) );
    }

    /** @return array thread, node id */
    private function threadOnArticle( $title )
    {
        $article = $this->article( 'XTB turn ' . $title );
        $alias = $this->stubModel();
        $nodeID = (int) $article->mainNodeID();
        $thread = $this->thread( array( 'title' => $title, 'model_alias' => $alias, 'scope' => array( 'nodes' => array( $nodeID ) ) ) );
        return array( $thread, $nodeID );
    }

    private static function events( expAiThread $thread, $kind )
    {
        return array_values( array_filter( expAiThreadEvent::fetchAfter( (int) $thread->attribute( 'id' ), 0, 200 ),
                                           function ( $e ) use ( $kind ) { return $e->attribute( 'kind' ) === $kind; } ) );
    }

    public function testValidateAndDescribe(): void
    {
        $handler = new expAiThreadTurnHandler();
        $this->assertSame( 'thread_id and node_id are required', $handler->validate( array( 'thread_id' => 1 ) ) );
        $this->assertNull( $handler->validate( array( 'thread_id' => 1, 'node_id' => 2 ) ) );
        $this->assertSame( 'Thread turn 7 (a.b)', $handler->describe( $this->job( array( 'thread_id' => 7, 'options' => array( 'alias' => 'a.b' ) ) ) ) );
        $this->assertSame( 'Thread turn 0', $handler->describe( $this->job( array() ) ) );
    }

    public function testAThreadThatIsGoneFailsForGood(): void
    {
        $this->expectException( expQueuePermanentException::class );
        $this->expectExceptionMessage( 'the thread is gone' );
        $job = $this->job( array( 'thread_id' => 2147483000, 'node_id' => 2 ) );
        ( new expAiThreadTurnHandler() )->run( $job, new expAiVectorCoreQueueContext( $job ) );
    }

    public function testAnOwnerWithoutAccessEndsTheThreadFailed(): void
    {
        list( $thread, $nodeID ) = $this->threadOnArticle( 'not the owner' );
        $thread->setAttribute( 'user_id', $this->userID + 1000000 );
        $thread->store();
        $job = $this->job( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'node_id' => $nodeID, 'message' => 'Hello' ) );
        try
        {
            ( new expAiThreadTurnHandler() )->run( $job, new expAiVectorCoreQueueContext( $job ) );
            $this->fail( 'ran for another user' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertSame( 'access', $e->errorClass );
        }
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertSame( 'failed', $fresh->attribute( 'state' ) );
        $this->assertSame( 'failed', $fresh->leadRun()->attribute( 'state' ) );
        $error = self::events( $fresh, 'error' );
        $this->assertStringContainsString( 'no longer has access', $error[0]->payload()['message'] );
        $this->assertSame( 0, count( expAiVectorCoreStubProvider::$requests ), 'no model call' );
    }

    public function testAThreadWithoutConversationFailsForGood(): void
    {
        list( $thread, $nodeID ) = $this->threadOnArticle( 'no conversation' );
        $run = $thread->leadRun();
        $run->setAttribute( 'conversation_id', 0 );
        $run->store();
        $this->expectException( expQueuePermanentException::class );
        $this->expectExceptionMessage( 'the thread has no conversation' );
        $job = $this->job( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'node_id' => $nodeID ) );
        ( new expAiThreadTurnHandler() )->run( $job, new expAiVectorCoreQueueContext( $job ) );
    }

    public function testSwitchedOffTheTurnIsHeldWithTheReason(): void
    {
        list( $thread, $nodeID ) = $this->threadOnArticle( 'switched off' );
        $this->ini( 'AISettings', 'Enabled', 'false' );
        $job = $this->job( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'node_id' => $nodeID, 'message' => 'Hello' ) );
        $result = expQueueResult::from( ( new expAiThreadTurnHandler() )->run( $job, new expAiVectorCoreQueueContext( $job ) ) );
        $this->assertSame( expQueueResult::HOLD, $result->outcome );
        $this->assertStringStartsWith( expAiGate::HELD_PREFIX, $result->message );
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertSame( 'queued', $fresh->attribute( 'state' ) );
        $this->assertSame( 'queued', $fresh->leadRun()->attribute( 'state' ) );
        $status = self::events( $fresh, 'status' );
        $this->assertNotEmpty( end( $status )->payload()['held'] );
        $this->assertSame( array(), expAiVectorCoreStubProvider::$requests );
    }

    public function testATurnRunsUntilTheModelAnswersInWords(): void
    {
        list( $thread, $nodeID ) = $this->threadOnArticle( 'full turn' );
        expAiVectorCoreStubProvider::$replies = array(
            array( array( 'type' => 'tool_use', 'id' => 'toolu_xtb1', 'name' => 'get_node', 'input' => array( 'node_id' => $nodeID ) ) ),
            array( array( 'type' => 'text', 'text' => 'The article has two paragraphs.' ) ) );
        $job = $this->job( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'run_id' => (int) $thread->attribute( 'lead_run_id' ), 'node_id' => $nodeID,
                                  'message' => 'What is in it?', 'options' => array( 'alias' => '', 'read_only' => true, 'mode' => 'plan', 'action' => 'vector' ) ) );
        $context = new expAiVectorCoreQueueContext( $job );
        $out = ( new expAiThreadTurnHandler() )->run( $job, $context );
        $this->assertSame( array( 'steps' => 2, 'status' => 'done', 'turns' => 1 ), $out );
        $this->assertCount( 2, expAiVectorCoreStubProvider::$requests );
        $this->assertSame( array( 'index' => 0, 'sent' => 1 ), $context->saved, 'the message is marked sent before the first call' );
        $this->assertCount( 2, $context->progress );
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $texts = self::events( $fresh, 'text' );
        $this->assertSame( 'The article has two paragraphs.', end( $texts )->payload()['text'] );
        $this->assertNotContains( $fresh->attribute( 'state' ), array( 'running', 'queued', 'failed' ) );
    }

    public function testARerunDoesNotSendTheMessageAgain(): void
    {
        list( $thread, $nodeID ) = $this->threadOnArticle( 'rerun' );
        $job = $this->job( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'node_id' => $nodeID, 'message' => 'First run.',
                                  'options' => array( 'alias' => '', 'read_only' => true, 'mode' => 'plan', 'action' => 'vector' ) ) );
        // the first attempt sent the message, then the model call failed
        expAiVectorCoreStubProvider::$throw = new expAiException( 'The model is not available.', 'stub failure' );
        try
        {
            ( new expAiThreadTurnHandler() )->run( $job, new expAiVectorCoreQueueContext( $job ) );
            $this->fail( 'a failing model call did not fail the job' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertSame( 'model', $e->errorClass );
            $this->assertSame( 'The model is not available.', $e->getMessage() );
        }
        $conversation = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) )->conversation();
        $sent = substr_count( json_encode( $conversation->messages() ), 'First run.' );
        // the rerun with the checkpoint of the first attempt goes on without the message
        $out = ( new expAiThreadTurnHandler() )->run( $job, new expAiVectorCoreQueueContext( $job, array( 'index' => 0, 'sent' => 1 ) ) );
        $this->assertSame( 'done', $out['status'] );
        $last = end( expAiVectorCoreStubProvider::$requests );
        $this->assertSame( $sent, substr_count( json_encode( $last['messages'] ), 'First run.' ), 'the message is in the request as often as in the conversation' );
    }

    public function testCancelledBeforeAStepStopsTheThread(): void
    {
        list( $thread, $nodeID ) = $this->threadOnArticle( 'cancelled' );
        $job = $this->job( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'run_id' => (int) $thread->attribute( 'lead_run_id' ), 'node_id' => $nodeID, 'message' => 'Hi' ) );
        $context = new expAiVectorCoreQueueContext( $job );
        $context->cancelled = true;
        try
        {
            ( new expAiThreadTurnHandler() )->run( $job, $context );
            $this->fail( 'not cancelled' );
        }
        catch ( expQueueCancelledException $e )
        {
            $this->assertSame( 'stopped by the editor', $e->getMessage() );
        }
        $this->assertSame( 'stopped', expAiThread::fetchByID( (int) $thread->attribute( 'id' ) )->attribute( 'state' ) );
        $this->assertSame( array(), expAiVectorCoreStubProvider::$requests );

        // the turn went on with another model meanwhile: the job ends without touching the thread
        $thread = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $thread->setState( 'running' );
        $thread->store();
        $job = $this->job( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'run_id' => (int) $thread->attribute( 'lead_run_id' ) + 99999, 'node_id' => $nodeID, 'message' => 'Hi' ) );
        $context = new expAiVectorCoreQueueContext( $job );
        $context->cancelled = true;
        try
        {
            ( new expAiThreadTurnHandler() )->run( $job, $context );
            $this->fail( 'not cancelled' );
        }
        catch ( expQueueCancelledException $e )
        {
            $this->assertSame( 'the turn went on with another model', $e->getMessage() );
        }
        $this->assertSame( 'running', expAiThread::fetchByID( (int) $thread->attribute( 'id' ) )->attribute( 'state' ) );
    }

    public function testThePlanItemsRunAsTurnsOfOneJob(): void
    {
        list( $thread, $nodeID ) = $this->threadOnArticle( 'plan turns' );
        $plan = new expAiPlan( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'state' => 'running', 'created' => time(), 'modified' => time() ) );
        $plan->setItems( array( array( 'index' => 0, 'selected' => true ), array( 'index' => 1, 'selected' => true ) ) );
        $plan->store();
        $thread->setAttribute( 'plan_id', (int) $plan->attribute( 'id' ) );
        $thread->setState( 'running' );
        $thread->store();
        $states = array();
        expAiVectorCoreStubProvider::$replies = array(
            function ( $request ) use ( $thread, &$states ) { $states[] = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) )->attribute( 'state' ); return array( array( 'type' => 'text', 'text' => 'Item one done.' ) ); },
            function ( $request ) use ( $thread, &$states ) { $states[] = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) )->attribute( 'state' ); return array( array( 'type' => 'text', 'text' => 'Item two done.' ) ); } );
        $job = $this->job( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'node_id' => $nodeID, 'message' => '',
                                  'options' => array( 'alias' => '', 'read_only' => true, 'mode' => 'write', 'action' => 'vector',
                                                      'messages' => array( 'Item one.', '', 'Item two.' ), 'plan_id' => (int) $plan->attribute( 'id' ) ) ) );
        $context = new expAiVectorCoreQueueContext( $job );
        $out = ( new expAiThreadTurnHandler() )->run( $job, $context );
        $this->assertSame( array( 'steps' => 2, 'status' => 'done', 'turns' => 2 ), $out, 'an empty message is no item' );
        $this->assertSame( array( 'index' => 1, 'sent' => 1 ), $context->saved );
        $this->assertStringStartsWith( '2/2 ', end( $context->progress )[2], 'the progress names the item' );
        $this->assertFalse( expAiThread::$holdRunning, 'reset after the job' );
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertSame( $fresh->restingState(), $fresh->attribute( 'state' ), 'after the last item the thread rests' );
        $this->assertSame( 'done', expAiPlan::fetchByID( (int) $plan->attribute( 'id' ) )->attribute( 'state' ), 'the plan is completed' );
    }

    public function testAVetoFailsTheTurn(): void
    {
        list( $thread, $nodeID ) = $this->threadOnArticle( 'veto' );
        $this->ini( 'RequestVeto', 'Rules', array( 'xtb' ) );
        $this->ini( 'RequestVeto_xtb', 'Reason', 'No AI in this test.' );
        $job = $this->job( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'node_id' => $nodeID, 'message' => 'Hi',
                                  'options' => array( 'alias' => '', 'read_only' => true, 'mode' => 'plan', 'action' => 'vector' ) ) );
        $result = expQueueResult::from( ( new expAiThreadTurnHandler() )->run( $job, new expAiVectorCoreQueueContext( $job ) ) );
        $this->assertSame( expQueueResult::FAIL, $result->outcome );
        $this->assertSame( 'veto', $result->errorClass );
        $this->assertStringContainsString( 'No AI in this test.', $result->message );
        $this->assertSame( array(), expAiVectorCoreStubProvider::$requests );
    }

    public function testHoldAndFinishIgnoreAMissingThread(): void
    {
        $result = expAiThreadTurnHandler::hold( null, 'off' );
        $this->assertSame( expQueueResult::HOLD, $result->outcome );
        $this->assertSame( expAiGate::HELD_PREFIX . 'off', $result->message );
        expAiThreadTurnHandler::finish( null, 'failed', 'x' );
        $thread = $this->thread( array( 'title' => 'finish' ) );
        expAiThreadTurnHandler::finish( $thread, 'stopped' );
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertSame( 'stopped', $fresh->attribute( 'state' ) );
        $this->assertGreaterThan( 0, (int) $fresh->leadRun()->attribute( 'finished' ) );
        $this->assertSame( array(), self::events( $fresh, 'error' ), 'no error event without an error' );
    }
}
