<?php
/**
 * expAiThreadMigration: panel conversations of earlier builds become threads. Everything runs in a transaction that
 * is rolled back (the migration works on every conversation without a thread, and this site has none of its own).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectorcoretestcase.php';

class expAiThreadMigrationTest extends expAiVectorCoreTestCase
{
    private function conversation( $nodeID, $messages, $modified )
    {
        $conversation = new expAiConversation( array( 'user_id' => $this->userID, 'node_id' => (int) $nodeID, 'messages' => $messages,
                                                      'model_alias' => 'xtb.stub', 'provider' => 'xtb', 'created' => $modified - 60, 'modified' => $modified ) );
        $conversation->store();
        return $conversation;
    }

    public function testPanelConversationsBecomeThreadsOnceAndTheirRowsAreLinked(): void
    {
        if ( expAiThreadMigration::pending() > 0 )
            $this->markTestSkipped( 'the site has panel conversations without a thread: the migration is not run on them from a test' );
        $this->inTransaction();
        $this->ini( 'AssistantSettings', 'ConversationLifetime', '3600' );
        $this->ini( 'ThreadSettings', 'TitleLength', '20' );
        $old = $this->conversation( 2, json_encode( array( 'history' => array( array( 'user' => "Please fix\nthe title of this page", 'assistant' => 'Done.' ) ) ) ), time() - 7200 );
        $blocks = $this->conversation( 2, json_encode( array( array( 'role' => 'user', 'content' => array( array( 'type' => 'image' ), array( 'type' => 'text', 'text' => 'Shorten it' ) ) ) ) ), time() );
        $empty = $this->conversation( 2, '[]', time() );
        $vector = $this->conversation( 0, '[]', time() );
        $usage = new expAiUsageLog( array( 'conversation_id' => (int) $old->attribute( 'id' ), 'provider' => 'xtb', 'model' => 'stub', 'created' => time() ) );
        $usage->store();
        $log = expAiDraftLog::record( array( 'contentobject_id' => 1, 'version' => 1, 'user_id' => $this->userID, 'conversation_id' => (int) $old->attribute( 'id' ),
                                             'node_id' => 2, 'action' => 'update' ) );
        $this->assertSame( 3, expAiThreadMigration::pending() );

        $dry = expAiThreadMigration::run( true );
        $this->assertSame( 4, $dry['candidates'] );
        $this->assertSame( 3, $dry['created'] );
        $this->assertSame( 1, $dry['skipped'], 'a conversation without a node is no panel conversation' );
        $this->assertSame( 3, expAiThreadMigration::pending(), 'a dry run writes nothing' );
        $byID = array_column( $dry['threads'], null, 'conversation_id' );
        $this->assertSame( 'settled', $byID[(int) $old->attribute( 'id' )]['shelf'], 'older than ConversationLifetime' );
        $this->assertSame( 1, $byID[(int) $old->attribute( 'id' )]['usage_rows'] );
        $this->assertSame( 1, $byID[(int) $old->attribute( 'id' )]['draft_rows'] );
        $this->assertSame( 'active', $byID[(int) $blocks->attribute( 'id' )]['shelf'] );

        $report = expAiThreadMigration::run( false, 10 );
        $this->assertSame( 3, $report['created'] );
        $this->assertSame( 0, expAiThreadMigration::pending() );
        $old = eZPersistentObject::fetchObject( expAiConversation::definition(), null, array( 'id' => (int) $old->attribute( 'id' ) ) );
        $thread = expAiThread::fetchByID( (int) $old->attribute( 'thread_id' ) );
        $this->assertSame( expAiThread::ORIGIN_PANEL, $thread->attribute( 'origin' ) );
        $this->assertSame( 'direct', $thread->attribute( 'review_policy' ) );
        $this->assertSame( 'settled', $thread->attribute( 'shelf' ) );
        $this->assertSame( array( 2 ), $thread->scope()['nodes'] );
        $this->assertSame( 2, (int) $thread->attribute( 'node_id' ) );
        $this->assertLessThanOrEqual( 20, mb_strlen( (string) $thread->attribute( 'title' ) ) );
        $this->assertStringStartsWith( 'Please fix the', (string) $thread->attribute( 'title' ), 'the first request on one line' );
        $this->assertSame( (int) $thread->attribute( 'lead_run_id' ), (int) $old->attribute( 'run_id' ) );
        $this->assertSame( 'xtb.stub', $thread->attribute( 'model_alias' ) );
        $usage = eZPersistentObject::fetchObject( expAiUsageLog::definition(), null, array( 'id' => (int) $usage->attribute( 'id' ) ) );
        $this->assertSame( (int) $thread->attribute( 'id' ), (int) $usage->attribute( 'thread_id' ) );
        $this->assertSame( (int) $old->attribute( 'run_id' ), (int) $usage->attribute( 'run_id' ) );
        $log = eZPersistentObject::fetchObject( expAiDraftLog::definition(), null, array( 'id' => (int) $log->attribute( 'id' ) ) );
        $this->assertSame( (int) $thread->attribute( 'id' ), (int) $log->attribute( 'thread_id' ) );

        $blocks = eZPersistentObject::fetchObject( expAiConversation::definition(), null, array( 'id' => (int) $blocks->attribute( 'id' ) ) );
        $this->assertSame( 'Shorten it', expAiThread::fetchByID( (int) $blocks->attribute( 'thread_id' ) )->attribute( 'title' ), 'the first text block of the first user message' );
        $empty = eZPersistentObject::fetchObject( expAiConversation::definition(), null, array( 'id' => (int) $empty->attribute( 'id' ) ) );
        $this->assertSame( '', expAiThread::fetchByID( (int) $empty->attribute( 'thread_id' ) )->attribute( 'title' ) );

        // a second run changes nothing; a lost link back to an existing thread is set again
        $again = expAiThreadMigration::run();
        $this->assertSame( 0, $again['created'] );
        $this->assertSame( 1, $again['candidates'] );
        $threadID = (int) $empty->attribute( 'thread_id' );
        eZPersistentObject::updateObjectList( array( 'definition' => expAiConversation::definition(), 'update_fields' => array( 'thread_id' => 0 ),
                                                     'conditions' => array( 'id' => (int) $empty->attribute( 'id' ) ) ) );
        $relink = expAiThreadMigration::run();
        $this->assertSame( 1, $relink['linked'] );
        $this->assertSame( 0, $relink['created'] );
        $empty = eZPersistentObject::fetchObject( expAiConversation::definition(), null, array( 'id' => (int) $empty->attribute( 'id' ) ) );
        $this->assertSame( $threadID, (int) $empty->attribute( 'thread_id' ) );
    }
}
