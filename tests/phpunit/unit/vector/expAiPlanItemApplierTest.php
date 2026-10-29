<?php
/**
 * expAiPlanItemApplier: a batch answer of one plan item is staged as changes of the item's child run, never written
 * into content; the failures end the run and are permanent for the queue.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectortestcase.php';

class expAiPlanItemApplierTest extends expAiVectorTestCase
{
    /** @var eZContentObjectTreeNode */
    private $folderNode;

    /** @var eZContentObject */
    private $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        if ( !expAiThread::available() || !class_exists( 'expQueueJob' ) )
            $this->markTestSkipped( 'needs the thread tables and expqueue' );
        $this->asUser( 'admin' );
        $this->folderNode = $this->folder( 'expai unit test plan item' );
        $this->article = $this->publish( (int) $this->folderNode->attribute( 'node_id' ),
            array( 'title' => 'Item article', 'intro' => self::xml( array( 'First paragraph.' ) ) ) );
        $this->inTransaction();
    }

    /** @return array( expAiThread, expAiThreadRun child run ) */
    private function threadWithItem( $state = 'running' )
    {
        $thread = $this->thread( array( 'scope' => array( 'subtrees' => array( (int) $this->folderNode->attribute( 'node_id' ) ) ), 'run_mode' => 'write' ) );
        $run = new expAiThreadRun( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'parent_run_id' => (int) $thread->attribute( 'lead_run_id' ),
                                          'kind' => 'item', 'state' => $state, 'label' => 'item', 'lane' => 'batch', 'created' => time(), 'modified' => time() ) );
        $run->store();
        return array( $thread, $run );
    }

    private function apply( array $answer, array $params )
    {
        return ( new expAiPlanItemApplier() )->apply( new expQueueJob( array() ), $answer, $params, $this->createStub( expQueueContext::class ) );
    }

    private function params( expAiThread $thread, expAiThreadRun $run, array $more = array() )
    {
        return $more + array( 'thread_id' => (int) $thread->attribute( 'id' ), 'run_id' => (int) $run->attribute( 'id' ), 'action' => 'update',
                              'object_id' => (int) $this->article->attribute( 'id' ), 'language' => (string) $this->article->attribute( 'initial_language_code' ),
                              'fields' => array( 'title' ), 'languages' => 1 );
    }

    private function assertPermanent( callable $fn, $class )
    {
        try
        {
            $fn();
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertSame( $class, $e->errorClass );
            return $e;
        }
        $this->fail( "no permanent failure ($class)" );
    }

    public function testAThreadOrRunThatIsGoneIsAPermanentFailure(): void
    {
        list( $thread, $run ) = $this->threadWithItem();
        $this->assertPermanent( function () { $this->apply( array(), array( 'thread_id' => 999999999, 'run_id' => 1 ) ); }, 'thread' );
        list( $other ) = $this->threadWithItem();
        $this->assertPermanent( function () use ( $other, $run ) { $this->apply( array(), array( 'thread_id' => (int) $other->attribute( 'id' ), 'run_id' => (int) $run->attribute( 'id' ) ) ); }, 'thread' );
    }

    public function testAnEndedRunStagesNothingButTakesTheCost(): void
    {
        list( $thread, $run ) = $this->threadWithItem( 'stopped' );
        $usage = new expAiUsageLog( array( 'provider' => 'unit', 'model' => 'unit', 'created' => time(), 'user_id' => 14 ) );
        $usage->store();
        $result = $this->apply( array( 'usage_log_id' => (int) $usage->attribute( 'id' ) ), $this->params( $thread, $run ) );
        $this->assertSame( array( 'staged' => 0, 'note' => 'the run ended stopped' ), $result );
        $row = eZPersistentObject::fetchObject( expAiUsageLog::definition(), null, array( 'id' => (int) $usage->attribute( 'id' ) ) );
        $this->assertSame( (int) $thread->attribute( 'id' ), (int) $row->attribute( 'thread_id' ) );
        $this->assertSame( (int) $run->attribute( 'id' ), (int) $row->attribute( 'run_id' ) );
    }

    public function testAJobThatDoesNotRunAsTheOwnerFailsTheRun(): void
    {
        list( $thread, $run ) = $this->threadWithItem();
        $this->userWithRole( array( array( 'content', 'read' ) ) );
        $this->assertPermanent( function () use ( $thread, $run ) { $this->apply( array( 'value' => array( 'fields' => array( 'title' => 'x' ) ) ), $this->params( $thread, $run ) ); }, 'access' );
        $this->assertSame( 'failed', (string) expAiThreadRun::fetchByID( (int) $run->attribute( 'id' ) )->attribute( 'state' ) );
    }

    public function testAnAnswerWithoutFieldsFailsTheRunWithTheReason(): void
    {
        list( $thread, $run ) = $this->threadWithItem();
        $this->assertPermanent( function () use ( $thread, $run ) { $this->apply( array( 'value' => 'not json', 'stop_reason' => 'max_tokens' ), $this->params( $thread, $run ) ); }, 'answer' );
        $fresh = expAiThreadRun::fetchByID( (int) $run->attribute( 'id' ) );
        $this->assertSame( 'failed', (string) $fresh->attribute( 'state' ) );
        $errors = array();
        foreach ( expAiThreadEvent::fetchAfter( (int) $thread->attribute( 'id' ) ) as $event )
            if ( $event->attribute( 'kind' ) === 'error' )
                $errors[] = $event->payload()['message'];
        $this->assertSame( array( 'the answer was cut off at its length limit' ), $errors );
        // without the length limit: the other reason
        list( $thread, $run ) = $this->threadWithItem();
        $this->assertPermanent( function () use ( $thread, $run ) { $this->apply( array( 'value' => array( 'summary' => 'no fields' ) ), $this->params( $thread, $run ) ); }, 'answer' );
        $errors = array();
        foreach ( expAiThreadEvent::fetchAfter( (int) $thread->attribute( 'id' ) ) as $event )
            if ( $event->attribute( 'kind' ) === 'error' )
                $errors[] = $event->payload()['message'];
        $this->assertSame( array( 'the answer is not the JSON that was asked for' ), $errors );
    }

    public function testTheChangesOfTheAnswerAreStagedForTheAllowedFieldsOnly(): void
    {
        list( $thread, $run ) = $this->threadWithItem();
        $result = $this->apply( array( 'value' => array( 'changes' => array( array( 'field' => 'title', 'value' => 'Item article (new)' ),
                                                                             array( 'field' => 'intro', 'value' => '<p>not allowed</p>' ), 'junk' ),
                                                         'summary' => 'Renamed the article.' ) ), $this->params( $thread, $run ) );
        $this->assertSame( 1, $result['staged'] );
        $this->assertSame( array( 'title' ), $result['fields'] );
        $changes = expAiChange::fetchForThread( (int) $thread->attribute( 'id' ) );
        $this->assertCount( 1, $changes );
        $this->assertSame( 'title', (string) $changes[0]->attribute( 'attribute_identifier' ) );
        $this->assertSame( (int) $run->attribute( 'id' ), (int) $changes[0]->attribute( 'run_id' ) );
        $this->assertSame( (int) $this->article->attribute( 'current_version' ), (int) eZContentObject::fetch( (int) $this->article->attribute( 'id' ) )->attribute( 'current_version' ),
                           'nothing written into content' );
        // the one language was the last: the run is done, with the events of the step and the summary
        $this->assertSame( 'done', (string) expAiThreadRun::fetchByID( (int) $run->attribute( 'id' ) )->attribute( 'state' ) );
        $texts = array();
        foreach ( expAiThreadEvent::fetchAfter( (int) $thread->attribute( 'id' ) ) as $event )
            $texts[] = $event->attribute( 'kind' ) . ':' . ( isset( $event->payload()['text'] ) ? $event->payload()['text'] : '' );
        $this->assertContains( 'text:Renamed the article.', $texts );
        $this->assertNotEmpty( preg_grep( '/^step_end:.*title/', $texts ) );
    }

    public function testARunWithMoreLanguagesGoesOn(): void
    {
        list( $thread, $run ) = $this->threadWithItem( 'queued' );
        $result = $this->apply( array( 'value' => array( 'fields' => array() ) ), $this->params( $thread, $run, array( 'languages' => 2 ) ) );
        $this->assertSame( 0, $result['staged'], 'no allowed field: nothing staged' );
        $fresh = expAiThreadRun::fetchByID( (int) $run->attribute( 'id' ) );
        $this->assertSame( 'running', (string) $fresh->attribute( 'state' ) );
        $this->assertSame( 1, (int) $fresh->attribute( 'progress_done' ) );
        $this->assertGreaterThan( 0, (int) $fresh->attribute( 'started' ) );
    }

    public function testAnItemWhoseTargetIsGoneFailsTheRun(): void
    {
        list( $thread, $run ) = $this->threadWithItem();
        $e = $this->assertPermanent( function () use ( $thread, $run ) {
            $this->apply( array( 'value' => array( 'fields' => array( 'title' => 'x' ) ) ), $this->params( $thread, $run, array( 'object_id' => 999999999 ) ) ); }, 'stage' );
        $this->assertStringContainsString( 'Object 999999999 does not exist', $e->getMessage() );
        list( $thread, $run ) = $this->threadWithItem();
        $e = $this->assertPermanent( function () use ( $thread, $run ) {
            $this->apply( array( 'value' => array( 'fields' => array( 'title' => 'x' ) ) ),
                          $this->params( $thread, $run, array( 'action' => 'create', 'parent_node_id' => 999999999, 'class' => 'article' ) ) ); }, 'stage' );
        $this->assertStringContainsString( 'Node 999999999 does not exist', $e->getMessage() );
        $this->assertSame( 'failed', (string) expAiThreadRun::fetchByID( (int) $run->attribute( 'id' ) )->attribute( 'state' ) );
    }

    public function testACreateItemIsStagedAsANewObject(): void
    {
        list( $thread, $run ) = $this->threadWithItem();
        $result = $this->apply( array( 'value' => array( 'fields' => array( 'title' => 'A new article' ) ) ),
                                $this->params( $thread, $run, array( 'action' => 'create', 'parent_node_id' => (int) $this->folderNode->attribute( 'node_id' ), 'class' => 'article' ) ) );
        $this->assertSame( 1, $result['staged'] );
        $changes = expAiChange::fetchForThread( (int) $thread->attribute( 'id' ) );
        $this->assertCount( 1, $changes );
        $this->assertTrue( $changes[0]->isNew() );
    }
}
