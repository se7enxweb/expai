<?php
/**
 * expAiThreadRunner: which turns run in the background, queueing them, the panel's background turn, reconciling a
 * thread with a job that ended, and Stop. Every test that queues a job does so inside a transaction that is rolled
 * back: no worker of the site ever sees the job.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectorcoretestcase.php';

class expAiThreadRunnerTest extends expAiVectorCoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if ( !expAiThreadRunner::queueAvailable() )
            $this->markTestSkipped( 'the job queue (expqueue) with the type expai.thread.turn is not installed' );
    }

    public function testInBackgroundFollowsTheSettingAndTheModel(): void
    {
        $this->ini( 'VectorSettings', 'Background', 'never' );
        $this->assertFalse( expAiThreadRunner::inBackground( 'xtb.stub' ) );
        $this->ini( 'VectorSettings', 'Background', 'always' );
        $this->assertTrue( expAiThreadRunner::inBackground( 'xtb.stub' ) );

        $this->ini( 'VectorSettings', 'Background', 'auto' );
        $this->assertFalse( expAiThreadRunner::inBackground( 'no.such.model' ), 'an unknown model runs in the request' );
        $this->assertFalse( expAiThreadRunner::inBackground( 'xtb.stub' ), 'a remote model is not slow' );
        foreach ( array( 'always' => true, 'enabled' => true, 'true' => true, 'never' => false, 'disabled' => false, 'false' => false ) as $value => $expected )
        {
            $this->ini( 'Model_xtb.stub', 'RunInBackground', $value );
            $this->assertSame( $expected, expAiThreadRunner::inBackground( 'xtb.stub' ), "RunInBackground=$value" );
        }
        $this->ini( 'Model_xtb.stub', 'RunInBackground', 'auto' );
        $this->ini( 'Model_xtb.stub', 'Slow', 'true' );
        $this->assertTrue( expAiThreadRunner::inBackground( 'xtb.stub' ), 'a slow model' );
        $this->assertTrue( expAiThreadRunner::inBackground( '' ), 'no alias: the effective model' );
    }

    public function testKickStartsNoWorkerWhenSwitchedOff(): void
    {
        $this->assertFalse( expAiThreadRunner::kick( 2, 10 ) );
    }

    public function testEnqueueQueuesTheTurnAndMarksTheThread(): void
    {
        $thread = $this->thread( array( 'title' => 'enqueue' ) );
        $this->inTransaction();
        $job = expAiThreadRunner::enqueue( $thread, 2, 'Write a summary.', array( 'alias' => 'xtb.stub', 'mode' => 'write' ) );
        $this->assertInstanceOf( 'expQueueJob', $job );
        $payload = $job->payloadArray();
        $this->assertSame( (int) $thread->attribute( 'id' ), $payload['thread_id'] );
        $this->assertSame( (int) $thread->attribute( 'lead_run_id' ), $payload['run_id'] );
        $this->assertSame( 'Write a summary.', $payload['message'] );
        $this->assertSame( array( 'alias' => 'xtb.stub', 'mode' => 'write' ), $payload['options'] );
        $this->assertSame( expAiThreadRunner::JOB_TYPE, $job->attribute( 'type' ) );
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertSame( 'queued', $fresh->attribute( 'state' ) );
        $run = $fresh->leadRun();
        $this->assertSame( 'queued', $run->attribute( 'state' ) );
        $this->assertSame( (string) $job->attribute( 'uuid' ), $run->attribute( 'job_uuid' ) );

        // Stop cancels the job of the lead run
        $this->assertNotNull( expAiThreadRunner::cancel( $fresh ) );
        $this->assertSame( 'cancelled', expQueue::fetch( (string) $job->attribute( 'uuid' ) )->attribute( 'state' ) );
        // the job ended without an answer: the thread is set to stopped
        $this->assertTrue( expAiThreadRunner::reconcile( $fresh ) );
        $this->assertSame( 'stopped', expAiThread::fetchByID( (int) $thread->attribute( 'id' ) )->attribute( 'state' ) );
    }

    public function testReconcileMarksAThreadFailedWhoseJobDied(): void
    {
        $thread = $this->thread( array( 'title' => 'reconcile' ) );
        $this->assertFalse( expAiThreadRunner::reconcile( $thread ), 'an idle thread' );
        $this->assertNull( expAiThreadRunner::cancel( $thread ), 'no job to cancel' );
        $this->inTransaction();
        $job = expAiThreadRunner::enqueue( $thread, 2, 'Go.', array() );
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertFalse( expAiThreadRunner::reconcile( $fresh ), 'the job still waits' );

        $db = eZDB::instance();
        $db->query( "UPDATE expqueue_job SET state = 'dead', last_error = 'worker lost the model' WHERE id = " . (int) $job->attribute( 'id' ) );
        $this->assertTrue( expAiThreadRunner::reconcile( $fresh ) );
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertSame( 'failed', $fresh->attribute( 'state' ) );
        $errors = array_values( array_filter( expAiThreadEvent::fetchAfter( (int) $fresh->attribute( 'id' ), 0, 50 ), function ( $e ) { return $e->attribute( 'kind' ) === 'error'; } ) );
        $this->assertStringContainsString( 'worker lost the model', $errors[0]->payload()['message'] );
        $this->assertFalse( expAiThreadRunner::reconcile( $fresh ), 'a failed thread is left alone' );

        // a job that ended done leaves a running thread as it is (the turn reports its own end)
        $fresh->setState( 'running' );
        $fresh->store();
        $run = $fresh->leadRun();
        $run->setAttribute( 'state', 'running' );
        $run->store();
        $db->query( "UPDATE expqueue_job SET state = 'done' WHERE id = " . (int) $job->attribute( 'id' ) );
        $this->assertFalse( expAiThreadRunner::reconcile( $fresh ) );
        // a lead run without a job
        $run->setAttribute( 'job_uuid', '' );
        $run->store();
        $this->assertFalse( expAiThreadRunner::reconcile( expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ) );
    }

    public function testThePanelQueuesTheTurnOfASlowModel(): void
    {
        $article = $this->article( 'XTB panel turn' );
        $nodeID = (int) $article->mainNodeID();
        $this->ini( 'VectorSettings', 'Background', 'never' );
        $this->assertNull( expAiThreadRunner::panelTurn( $nodeID, 'Hello' ), 'a turn that runs in the request' );

        $this->ini( 'VectorSettings', 'Background', 'always' );
        $this->ini( 'AssistantSettings', 'MaxMessageLength', '20' );
        $this->inTransaction();
        try
        {
            expAiThreadRunner::panelTurn( $nodeID, '   ' );
            $this->fail( 'an empty message was queued' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'Please write a message.', self::text( $e ) );
        }
        try
        {
            expAiThreadRunner::panelTurn( $nodeID, str_repeat( 'x', 21 ) );
            $this->fail( 'a long message was queued' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'The message is too long (at most 20 characters).', self::text( $e ) );
        }
        $out = expAiThreadRunner::panelTurn( $nodeID, 'Summarize it.' );
        $this->assertSame( 'queued', $out['status'] );
        $this->assertNotSame( '', $out['notice'] );
        $conversation = expAiConversation::fetchCurrent( $this->userID, $nodeID );
        $this->assertInstanceOf( 'expAiConversation', $conversation );
        $thread = expAiThread::fetchForConversation( $conversation );
        $this->assertSame( $out['thread'], $thread->attribute( 'uuid' ) );
        $this->assertSame( expAiThread::ORIGIN_PANEL, $thread->attribute( 'origin' ) );
        $this->assertSame( 'queued', $thread->attribute( 'state' ) );
        $job = expQueue::fetch( $out['job'] );
        $this->assertTrue( $job->payloadArray()['options']['panel'] );
        $this->assertSame( 'Summarize it.', $job->payloadArray()['message'] );
        $this->assertSame( array(), expAiVectorCoreStubProvider::$requests, 'nothing was sent in the request' );
    }
}
