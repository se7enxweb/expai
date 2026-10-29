<?php
/**
 * expAiThread: lookups, the lists of a user, panel threads, and the bookkeeping after a step (lead, child and
 * superseded runs).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectorcoretestcase.php';

class expAiThreadTest extends expAiVectorCoreTestCase
{
    /** @var int a user id no real user has: the lists below are this test's threads only */
    private $owner = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = 2147000000 + mt_rand( 1, 400000 );
    }

    private function own( array $row )
    {
        return $this->thread( $row + array( 'user_id' => $this->owner ) );
    }

    private static function kinds( expAiThread $thread )
    {
        return array_map( function ( $e ) { return $e->attribute( 'kind' ); }, expAiThreadEvent::fetchAfter( (int) $thread->attribute( 'id' ), 0, 200 ) );
    }

    public function testUuidsAndLookups(): void
    {
        $uuid = expAiThread::newUuid();
        $this->assertTrue( expAiThread::isUuid( $uuid ) );
        $this->assertSame( '4', $uuid[14], 'version 4' );
        $this->assertFalse( expAiThread::isUuid( 'not-a-uuid' ) );
        $this->assertFalse( expAiThread::isUuid( 42 ) );
        $this->assertNull( expAiThread::fetchByUuid( 'nope' ) );
        $this->assertNull( expAiThread::fetchByUuid( expAiThread::newUuid() ) );
        $thread = $this->own( array( 'title' => 'lookup' ) );
        $this->assertSame( (int) $thread->attribute( 'id' ), (int) expAiThread::fetchByUuid( $thread->attribute( 'uuid' ) )->attribute( 'id' ) );
        $this->assertNull( expAiThread::fetchByID( 2147483000 ) );
        $this->assertTrue( expAiThread::available() );
        $this->assertNull( expAiThread::fetchForConversation( null ) );
        $this->assertNull( expAiThread::fetchForConversation( new expAiConversation( array( 'thread_id' => 0 ) ) ) );
    }

    public function testTheListsOfAUser(): void
    {
        $a = $this->own( array( 'title' => 'Opening hours Main Street' ) );
        $b = $this->own( array( 'title' => 'Opening hours Station', 'origin' => expAiThread::ORIGIN_PANEL, 'node_id' => 2 ) );
        $c = $this->own( array( 'title' => 'Prices', 'shelf' => 'settled', 'state' => 'failed' ) );
        $ids = function ( $list ) { return array_map( function ( $t ) { return (int) $t->attribute( 'id' ); }, $list ); };
        $this->assertCount( 3, expAiThread::fetchForUser( $this->owner ) );
        $this->assertSame( array( (int) $c->attribute( 'id' ) ), $ids( expAiThread::fetchForUser( $this->owner, array( 'shelf' => 'settled' ) ) ) );
        $this->assertCount( 3, expAiThread::fetchForUser( $this->owner, array( 'shelf' => array( 'active', 'settled' ) ) ) );
        $this->assertSame( array( (int) $c->attribute( 'id' ) ), $ids( expAiThread::fetchForUser( $this->owner, array( 'state' => array( 'failed' ) ) ) ) );
        $this->assertSame( array( (int) $b->attribute( 'id' ) ), $ids( expAiThread::fetchForUser( $this->owner, array( 'node_id' => 2, 'origin' => expAiThread::ORIGIN_PANEL ) ) ) );
        $found = $ids( expAiThread::fetchForUser( $this->owner, array( 'search' => 'opening HOURS' ) ) );
        sort( $found );
        $this->assertSame( array( (int) $a->attribute( 'id' ), (int) $b->attribute( 'id' ) ), $found, 'the title is searched without case' );
        $this->assertCount( 1, expAiThread::fetchForUser( $this->owner, array( 'search' => 'opening', 'limit' => 1 ) ) );
        $this->assertCount( 1, expAiThread::fetchForUser( $this->owner, array( 'search' => 'opening', 'offset' => 1 ) ) );
        $this->assertCount( 3, expAiThread::fetchForUser( $this->owner, array( 'search' => 'o' ) ), 'one character is no search' );
        $this->assertCount( 2, expAiThread::fetchForUser( $this->owner, array( 'limit' => 2 ) ) );

        $this->assertSame( 3, expAiThread::countForUser( $this->owner ) );
        $this->assertSame( 2, expAiThread::countForUser( $this->owner, array( 'shelf' => 'active' ) ) );
        $this->assertSame( 3, expAiThread::countForUser( $this->owner, array( 'shelf' => array( 'active', 'settled' ) ) ) );
        $this->assertSame( 1, expAiThread::countForUser( $this->owner, array( 'state' => array( 'failed' ) ) ) );
    }

    public function testAPanelConversationSettlesTheEarlierPanelThreadOfTheNode(): void
    {
        $earlier = $this->own( array( 'title' => 'earlier panel', 'origin' => expAiThread::ORIGIN_PANEL, 'node_id' => 2, 'scope' => array( 'nodes' => array( 2 ) ) ) );
        $conversation = new expAiConversation( array( 'user_id' => $this->owner, 'node_id' => 2, 'messages' => '[]', 'created' => time(), 'modified' => time() ) );
        $conversation->store();
        $thread = expAiThread::forPanelConversation( $conversation, 2, 'xtb.stub' );
        $id = (int) $thread->attribute( 'id' );
        $this->cleanup( static function () use ( $id ) { if ( $t = expAiThread::fetchByID( $id ) ) $t->removeAll( true ); } );
        $this->assertSame( expAiThread::ORIGIN_PANEL, $thread->attribute( 'origin' ) );
        $this->assertSame( 'direct', $thread->attribute( 'review_policy' ) );
        $this->assertSame( 2, (int) $thread->attribute( 'node_id' ) );
        $this->assertSame( 'xtb.stub', $thread->attribute( 'model_alias' ) );
        $this->assertSame( (int) $conversation->attribute( 'id' ), (int) $thread->conversation()->attribute( 'id' ) );
        $this->assertSame( 'settled', expAiThread::fetchByID( (int) $earlier->attribute( 'id' ) )->attribute( 'shelf' ) );

        // "New conversation" in the panel: the panel threads of the node go
        expAiThread::removeForPanel( $this->owner, 2 );
        $this->assertNull( expAiThread::fetchByID( $id ) );
        $this->assertNull( expAiThread::fetchByID( (int) $earlier->attribute( 'id' ) ) );
    }

    public function testAfterStepOfTheLeadRun(): void
    {
        $thread = $this->own( array( 'title' => '' ) );
        $thread->setAttribute( 'title', '' );
        $thread->store();
        $conversation = $thread->conversation();
        $conversation->setAttribute( 'model_alias', 'xtb.stub' );
        $conversation->setAttribute( 'provider', 'xtb' );
        $conversation->store();
        $this->ini( 'ThreadSettings', 'TitleLength', '20' );
        expAiThread::afterStep( $conversation, array( 'status' => 'continue', 'text' => '', 'steps' => array( array( 'text' => 'Read the node', 'ok' => true ) ),
                                                      'exchange' => array( 'user' => "XTB what is\nin this long article about opening hours" ) ) );
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertSame( 'running', $fresh->attribute( 'state' ) );
        $this->assertStringStartsWith( 'XTB what is in', (string) $fresh->attribute( 'title' ), 'the first request becomes the title' );
        $this->assertLessThanOrEqual( 20, mb_strlen( (string) $fresh->attribute( 'title' ) ) );
        $this->assertSame( 'xtb.stub', $fresh->attribute( 'model_alias' ) );
        $this->assertSame( 'xtb', $fresh->attribute( 'provider' ) );
        $this->assertContains( 'step_end', self::kinds( $fresh ) );
        $this->assertSame( 'running', $fresh->leadRun()->attribute( 'state' ) );
        $this->assertSame( 0, (int) $fresh->leadRun()->attribute( 'finished' ) );

        expAiThread::afterStep( $conversation, array( 'status' => 'failed', 'error' => 'The provider said no.', 'steps' => array() ) );
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertSame( 'failed', $fresh->attribute( 'state' ) );
        $this->assertGreaterThan( 0, (int) $fresh->leadRun()->attribute( 'finished' ) );

        expAiThread::afterStep( $conversation, array( 'status' => 'stopped', 'steps' => array() ) );
        $this->assertSame( 'stopped', expAiThread::fetchByID( (int) $thread->attribute( 'id' ) )->attribute( 'state' ) );

        expAiThread::$holdRunning = true;
        expAiThread::afterStep( $conversation, array( 'status' => 'done', 'text' => 'Item done.', 'steps' => array() ) );
        expAiThread::$holdRunning = false;
        $this->assertSame( 'running', expAiThread::fetchByID( (int) $thread->attribute( 'id' ) )->attribute( 'state' ), 'more turns of a plan to go' );

        $log = new expAiDraftLog( array( 'contentobject_id' => 1, 'version' => 1, 'language_code' => 'eng-GB', 'action' => 'update' ) );
        expAiThread::afterStep( $conversation, array( 'status' => 'done', 'text' => 'Wrote a draft.', 'steps' => array() ), array( $log ) );
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertSame( 'review', $fresh->attribute( 'state' ), 'a draft waits for the editor' );
        $this->assertContains( 'draft', self::kinds( $fresh ) );
        expAiThread::afterStep( $conversation, array( 'status' => 'done', 'text' => 'Nothing more.', 'steps' => array() ) );
        $this->assertSame( 'idle', expAiThread::fetchByID( (int) $thread->attribute( 'id' ) )->attribute( 'state' ) );

        // a conversation without a thread changes nothing
        expAiThread::afterStep( new expAiConversation( array( 'thread_id' => 0 ) ), array( 'status' => 'failed' ) );
    }

    public function testAfterStepOfAChildRunAndOfARunThatIsNoLongerTheLead(): void
    {
        $thread = $this->own( array( 'title' => 'child steps' ) );
        list( $plan, $runs ) = $this->planWithRuns( $thread, array( array( 'state' => 'running' ) ) );
        $child = eZPersistentObject::fetchObject( expAiConversation::definition(), null, array( 'id' => (int) $runs[0]->attribute( 'conversation_id' ) ) );
        $child->setAttribute( 'model_alias', 'xtb.stub' );
        $log = new expAiDraftLog( array( 'contentobject_id' => 5, 'version' => 2, 'language_code' => 'eng-GB', 'action' => 'create' ) );
        expAiThread::afterStep( $child, array( 'status' => 'failed', 'error' => 'Child failed.', 'text' => 'Partial text',
                                               'steps' => array( array( 'text' => 'Step one', 'ok' => false ) ) ), array( $log ) );
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertSame( 'running', $fresh->attribute( 'state' ), 'the thread state is the plan\'s, not the child step\'s' );
        $events = expAiThreadEvent::fetchAfter( (int) $fresh->attribute( 'id' ), 0, 200 );
        $byKind = array();
        foreach ( $events as $event )
            if ( (int) $event->attribute( 'run_id' ) === (int) $runs[0]->attribute( 'id' ) )
                $byKind[$event->attribute( 'kind' )] = $event->payload();
        $this->assertSame( 'Step one', $byKind['step_end']['text'] );
        $this->assertSame( 'Partial text', $byKind['text']['text'] );
        $this->assertSame( 'Child failed.', $byKind['error']['message'] );
        $this->assertSame( 5, $byKind['draft']['object_id'] );
        $this->assertArrayHasKey( 'cost', $byKind );
        $run = expAiThreadRun::fetchByID( (int) $runs[0]->attribute( 'id' ) );
        $this->assertSame( 'xtb.stub', $run->attribute( 'model_alias' ) );
        $this->assertGreaterThan( 0, (int) $run->attribute( 'started' ) );

        // a run that is no longer the lead (the model was switched): its late step changes nothing
        $other = $this->own( array( 'title' => 'superseded' ) );
        $old = $other->conversation();
        $lead = new expAiThreadRun( array( 'thread_id' => (int) $other->attribute( 'id' ), 'kind' => 'turn', 'state' => 'idle', 'created' => time(), 'modified' => time() ) );
        $lead->store();
        $other->setAttribute( 'lead_run_id', (int) $lead->attribute( 'id' ) );
        $other->setState( 'stopped' );
        $other->store();
        expAiThread::afterStep( $old, array( 'status' => 'done', 'text' => 'Late answer.', 'steps' => array() ) );
        $fresh = expAiThread::fetchByID( (int) $other->attribute( 'id' ) );
        $this->assertSame( 'stopped', $fresh->attribute( 'state' ) );
        $this->assertNotContains( 'text', self::kinds( $fresh ) );
    }
}
