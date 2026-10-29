<?php
/**
 * expAiCheckpoint: when no checkpoint is written, previews that are blocked, a restore by someone other than the
 * owner, and cutting a conversation back when its messages changed since the checkpoint.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectorcoretestcase.php';

class expAiCheckpointTest extends expAiVectorCoreTestCase
{
    public function testNoCheckpointWhenSwitchedOffOrWithoutConversation(): void
    {
        $this->assertNull( expAiCheckpoint::fetchByID( 0 ) );
        $thread = $this->thread( array( 'title' => 'checkpoint off' ) );
        $this->ini( 'VectorSettings', 'Checkpoints', 'disabled' );
        $this->assertFalse( expAiCheckpoint::enabled() );
        $this->assertNull( expAiCheckpoint::write( $thread, 'Before: x' ) );
        $this->ini( 'VectorSettings', 'Checkpoints', 'enabled' );
        $run = $thread->leadRun();
        $run->setAttribute( 'conversation_id', 0 );
        $run->store();
        $this->assertNull( expAiCheckpoint::write( $thread, 'Before: x' ), 'no conversation' );
        $this->assertSame( array(), expAiCheckpoint::listFor( $thread ) );
    }

    public function testPreviewIsBlockedForAnotherThreadOrWhileTheThreadWorks(): void
    {
        $thread = $this->thread( array( 'title' => 'checkpoint preview' ) );
        $checkpoint = expAiCheckpoint::write( $thread, 'Before: first' );
        $this->assertInstanceOf( 'expAiCheckpoint', $checkpoint );
        $list = expAiCheckpoint::listFor( $thread, 5 );
        $this->assertSame( (int) $checkpoint->attribute( 'id' ), $list[0]['id'] );
        $this->assertSame( 'Before: first', $list[0]['label'] );
        $preview = $checkpoint->preview( $thread );
        $this->assertSame( '', $preview['blocked'] );
        $this->assertTrue( $preview['nothing'] );

        $other = $this->thread( array( 'title' => 'other thread' ) );
        $this->assertSame( 'This checkpoint belongs to another thread.', $checkpoint->preview( $other )['blocked'] );
        $thread->setState( 'running' );
        $this->assertSame( 'Stop the thread first: nothing may run while it is restored.', $checkpoint->preview( $thread )['blocked'] );
        try
        {
            $checkpoint->restore( $thread );
            $this->fail( 'restored while running' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'Stop the thread first: nothing may run while it is restored.', $e->translatedMessage() );
        }
        $thread->setAttribute( 'user_id', $this->userID + 1000000 );
        try
        {
            $checkpoint->restore( $thread );
            $this->fail( 'restored by someone else' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'Only the owner of this thread can restore it.', $e->getMessage() );
        }
    }

    public function testCuttingAChangedConversationSummarizesTheExchanges(): void
    {
        $thread = $this->thread( array( 'title' => 'cut' ) );
        $conversation = $thread->conversation();
        $history = array( array( 'user' => 'One', 'reply' => 'First reply', 'steps' => array(), 'drafts' => array(), 'time' => time() - 120 ),
                          array( 'user' => 'Two', 'reply' => 'Second reply', 'steps' => array(), 'drafts' => array(), 'time' => time() - 60 ) );
        $conversation->setHistory( $history );
        $conversation->setMessages( array( array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => 'changed since' ) ) ),
                                           array( 'role' => 'assistant', 'content' => array( array( 'type' => 'text', 'text' => 'x' ) ) ) ) );
        $conversation->setFrame( 'system text', array() );
        $conversation->store();
        expAiCheckpoint::cutConversation( $conversation, 2, array( 'history_len' => 1, 'messages_hash' => 'not the hash of these messages' ) );
        $fresh = $thread->conversation();
        $this->assertCount( 1, $fresh->history(), 'the exchanges up to the checkpoint' );
        $this->assertStringContainsString( 'One', json_encode( $fresh->messages() ) );
        $this->assertStringNotContainsString( 'changed since', json_encode( $fresh->messages() ) );
        $frame = $fresh->frame();
        $this->assertTrue( !is_array( $frame ) || (string) $frame['system'] === '', 'the instructions are built again' );

        // the same prefix: the messages are cut, nothing summarized
        $messages = array( array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => 'One' ) ) ),
                           array( 'role' => 'assistant', 'content' => array( array( 'type' => 'text', 'text' => 'First reply' ) ) ),
                           array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => 'Two' ) ) ) );
        $fresh->setHistory( $history );
        $fresh->setMessages( $messages );
        $fresh->store();
        expAiCheckpoint::cutConversation( $fresh, 2, array( 'history_len' => 1, 'messages_hash' => expAiCheckpoint::hashMessages( array_slice( $messages, 0, 2 ) ), 'window_turns' => 1 ) );
        $this->assertSame( array_slice( $messages, 0, 2 ), $thread->conversation()->messages() );
    }
}
