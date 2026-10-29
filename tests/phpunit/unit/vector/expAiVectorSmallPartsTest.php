<?php
/**
 * The small parts of Vector: the prompts of a thread (expAiThreadPrompts), the values of the role editor
 * (expAiVectorPolicy), the template fetch of the user's threads (expAiThreadFunctionCollection), the retention
 * (expAiThreadRetention), the transport and stream slots (expAiVectorStream), follow-ups and steering
 * (expAiFollowup) and the batch requests of a plan (expAiPlanBatch).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectortestcase.php';

class expAiVectorSmallPartsTest extends expAiVectorTestCase
{
    /* ------------------------------------------------------------------ expAiThreadPrompts */

    public function testTheReadOnlyAndStagedNotes(): void
    {
        $plan = expAiThreadPrompts::readOnlyNote( array( 'mode' => 'plan' ) );
        $this->assertStringContainsString( 'propose_plan', $plan );
        $this->assertStringContainsString( 'Do not write anything', $plan );
        $read = expAiThreadPrompts::readOnlyNote( array( 'mode' => 'write' ) );
        $this->assertStringStartsWith( 'Read-only:', $read );
        $this->assertSame( $read, expAiThreadPrompts::readOnlyNote( array() ) );
        $this->assertStringContainsString( 'update_draft', expAiThreadPrompts::stagedNote() );
    }

    public function testTheScopeNoteNamesWhatTheUserMayRead(): void
    {
        $this->asUser( 'admin' );
        $this->assertSame( '', expAiThreadPrompts::scopeNote( expAiThread::cleanScope( array() ) ) );
        $note = expAiThreadPrompts::scopeNote( expAiThread::cleanScope( array( 'nodes' => array( 2, 999999999 ), 'subtrees' => array( 2 ),
                                                                               'classes' => array( 'article', 'folder' ), 'languages' => array( 'eng-GB' ) ) ) );
        $root = (string) eZContentObjectTreeNode::fetch( 2 )->attribute( 'name' );
        $this->assertStringContainsString( 'objects: "' . $root . '" (node 2), node 999999999', $note );
        $this->assertStringContainsString( 'subtrees (the node and everything below it): "' . $root . '" (node 2)', $note );
        $this->assertStringContainsString( 'content classes: article, folder', $note );
        $this->assertStringContainsString( 'languages: eng-GB', $note );
        $this->assertStringEndsWith( 'Stay within this scope.', $note );
    }

    /* ------------------------------------------------------------------ expAiVectorPolicy */

    public function testTheValuesOfTheLimitationsScopeAndReview(): void
    {
        $policy = new expAiVectorPolicy( array( 'ignored' ) );
        $this->assertSame( array( 'node', 'subtree', 'class' ), array_column( $policy->scopes(), 'id' ) );
        $this->assertSame( array( 'staged', 'direct' ), array_column( $policy->reviews(), 'id' ) );
        foreach ( array_merge( $policy->scopes(), $policy->reviews() ) as $row )
            $this->assertNotSame( '', $row['name'] );
        // the values the access check reads are the ids offered here
        $this->asUser( 'admin' );
        $all = expAiThreadAccess::choices();
        $this->assertSame( array_column( $policy->scopes(), 'id' ), $all['scope'] );
        $this->assertSame( array_column( $policy->reviews(), 'id' ), $all['review'] );
    }

    /* ------------------------------------------------------------------ expAiThreadFunctionCollection */

    public function testMyThreadsOfTheTemplateFetch(): void
    {
        $this->requireDatabase();
        $this->asUser( 'admin' );
        $this->inTransaction();
        $mine = $this->thread( array( 'title' => 'expai unit fetch review' ) );
        $mine->setAttribute( 'state', 'review' );
        $mine->store();
        $before = ( new expAiThreadFunctionCollection() )->fetchMyThreads( 50 )['result'];
        $row = null;
        foreach ( $before['rows'] as $r )
            if ( $r['uuid'] === $mine->attribute( 'uuid' ) )
                $row = $r;
        $this->assertNotNull( $row, 'the new thread is listed' );
        $this->assertSame( 'To review', $row['state_label'] );
        $this->assertGreaterThanOrEqual( 1, $before['needs'] );
        $this->assertLessThanOrEqual( 1, count( ( new expAiThreadFunctionCollection() )->fetchMyThreads( 0 )['result']['rows'] ), 'at least one, at most the limit' );

        // without expai/write: nothing
        $this->userWithRole( array( array( 'content', 'read' ) ) );
        $this->assertSame( array( 'result' => array( 'rows' => array(), 'needs' => 0 ) ), ( new expAiThreadFunctionCollection() )->fetchMyThreads() );
    }

    /* ------------------------------------------------------------------ expAiThreadRetention */

    public function testRetentionRemovesOldRowsOnly(): void
    {
        $this->requireDatabase();
        $this->asUser( 'admin' );
        $this->inTransaction();
        $thread = $this->thread();
        $id = (int) $thread->attribute( 'id' );
        $old = time() - 40 * 86400;
        $old1 = expAiThreadEvent::append( $id, 0, 'status', array() );
        $old1->setAttribute( 'created', $old );
        $old1->store();
        $new = expAiThreadEvent::append( $id, 0, 'status', array() );
        $waiting = new expAiFollowup( array( 'thread_id' => $id, 'body' => 'still waits', 'position' => 1, 'delivery' => 'queue', 'state' => 'waiting', 'created' => $old, 'modified' => $old ) );
        $waiting->store();
        $sent = new expAiFollowup( array( 'thread_id' => $id, 'body' => 'sent', 'position' => 2, 'delivery' => 'queue', 'state' => 'sent', 'created' => $old, 'modified' => $old ) );
        $sent->store();

        $config = $this->config( array( 'ThreadSettings' => array( 'EventKeepDays' => 30, 'ProposalKeepDays' => 0, 'CheckpointKeepDays' => 30 ) ) );
        $dry = expAiThreadRetention::prune( true, $config );
        $this->assertSame( array( 'expai_thread_event', 'expai_change', 'expai_checkpoint', 'expai_plan', 'expai_followup' ), array_keys( $dry ) );
        $this->assertGreaterThanOrEqual( 1, $dry['expai_thread_event'] );
        $this->assertGreaterThanOrEqual( 1, $dry['expai_followup'] );
        $this->assertSame( 0, $dry['expai_change'], '0 days keeps the rows' );
        $this->assertNotNull( expAiFollowup::fetchByID( (int) $sent->attribute( 'id' ) ), 'a dry run removes nothing' );

        // the real run (inside the test's transaction, which is rolled back)
        $done = expAiThreadRetention::prune( false, $config );
        $this->assertSame( $dry['expai_thread_event'], $done['expai_thread_event'] );
        $this->assertNull( expAiFollowup::fetchByID( (int) $sent->attribute( 'id' ) ) );
        $this->assertNotNull( expAiFollowup::fetchByID( (int) $waiting->attribute( 'id' ) ), 'a waiting follow-up stays' );
        $events = array_map( function ( $e ) { return (int) $e->attribute( 'id' ); }, expAiThreadEvent::fetchAfter( $id ) );
        $this->assertNotContains( (int) $old1->attribute( 'id' ), $events );
        $this->assertContains( (int) $new->attribute( 'id' ), $events );
        $this->assertSame( array( 'fields', 'keys', 'name', 'class_name' ), array_keys( expAiThreadRetention::tableDefinition( 'expai_plan' ) ) );
        $this->assertArrayHasKey( 'state', expAiThreadRetention::tableDefinition( 'expai_followup' )['fields'] );
        $this->assertArrayNotHasKey( 'state', expAiThreadRetention::tableDefinition( 'expai_change' )['fields'] );
    }

    /* ------------------------------------------------------------------ expAiVectorStream */

    public function testTheTransportFollowsTheSettingAndTheServer(): void
    {
        $this->assertSame( 'poll', expAiVectorStream::transport( $this->config( array( 'VectorSettings' => array( 'Transport' => 'poll' ) ) ) ) );
        // the command line counts as a server that streams (Velocity's workers)
        $this->assertSame( 'sse', expAiVectorStream::transport( $this->config( array( 'VectorSettings' => array( 'Transport' => 'sse' ) ) ) ) );
        $this->assertSame( 'sse', expAiVectorStream::transport( $this->config( array( 'VectorSettings' => array( 'Transport' => 'auto' ) ) ) ) );
    }

    public function testStreamSlotsAreLimited(): void
    {
        $this->assertFalse( expAiVectorStream::acquireSlot( $this->config( array( 'VectorSettings' => array( 'MaxStreams' => 0 ) ) ) ) );
        $config = $this->config( array( 'VectorSettings' => array( 'MaxStreams' => 2 ) ) );
        $held = array();
        $this->cleanup( static function () use ( &$held ) { foreach ( $held as $h ) expAiVectorStream::releaseSlot( $h ); } );
        // other processes (the site) may hold slots: take what is free, at most two
        while ( count( $held ) < 3 && ( $slot = expAiVectorStream::acquireSlot( $config ) ) )
            $held[] = $slot;
        $this->assertLessThanOrEqual( 2, count( $held ), 'never more than MaxStreams' );
        $this->assertFalse( expAiVectorStream::acquireSlot( $config ), 'all slots are taken' );
        if ( $held )
        {
            expAiVectorStream::releaseSlot( array_pop( $held ) );
            $again = expAiVectorStream::acquireSlot( $config );
            $this->assertIsResource( $again, 'a released slot can be taken again' );
            $held[] = $again;
        }
        expAiVectorStream::releaseSlot( 'not a handle' );
    }

    /* ------------------------------------------------------------------ expAiFollowup */

    public function testSteeringNotesAreTakenOnlyByTheLeadRun(): void
    {
        $this->requireDatabase();
        $this->asUser( 'admin' );
        $this->inTransaction();
        $this->assertSame( array(), expAiFollowup::takeSteers( null ) );
        $this->assertSame( array(), expAiFollowup::takeSteers( new expAiConversation( array( 'thread_id' => 0 ) ) ) );
        $thread = $this->thread();
        $id = (int) $thread->attribute( 'id' );
        $queued = new expAiFollowup( array( 'thread_id' => $id, 'body' => 'queued one', 'position' => 1, 'delivery' => 'queue', 'state' => 'waiting', 'created' => time(), 'modified' => time() ) );
        $queued->store();
        $steer = new expAiFollowup( array( 'thread_id' => $id, 'body' => 'steer it', 'position' => 2, 'delivery' => 'steer', 'state' => 'waiting', 'created' => time(), 'modified' => time() ) );
        $steer->store();
        $other = new expAiConversation( array( 'thread_id' => $id, 'run_id' => (int) $thread->attribute( 'lead_run_id' ) + 1000 ) );
        $this->assertSame( array(), expAiFollowup::takeSteers( $other ), 'another run is not steered' );

        $this->assertSame( array( 'steer it' ), expAiFollowup::takeSteers( $thread->conversation() ) );
        $this->assertSame( 'sent', (string) expAiFollowup::fetchByID( (int) $steer->attribute( 'id' ) )->attribute( 'state' ) );
        $this->assertSame( array( 'queued one' ), array_map( function ( $f ) { return $f->present()['body']; }, expAiFollowup::waiting( $id ) ) );

        // a steering note that could not reach the turn waits in front of the queue
        $late = new expAiFollowup( array( 'thread_id' => $id, 'body' => 'late steer', 'position' => 3, 'delivery' => 'steer', 'state' => 'waiting', 'created' => time(), 'modified' => time() ) );
        $late->store();
        $this->assertSame( 1, expAiFollowup::steersToQueue( $id ) );
        $present = array_map( function ( $f ) { return $f->present(); }, expAiFollowup::waiting( $id ) );
        $this->assertSame( array( 'late steer', 'queued one' ), array_column( $present, 'body' ) );
        $this->assertSame( array( 'queue', 'queue' ), array_column( $present, 'delivery' ) );
        $this->assertSame( 0, $present[0]['position'] );
    }

    /* ------------------------------------------------------------------ expAiPlanBatch */

    public function testTheBatchLaneOfAThread(): void
    {
        $this->requireDatabase();
        $this->asUser( 'admin' );
        $this->inTransaction();
        $config = $this->config();
        $auto = $this->thread( array( 'lane' => 'auto' ) );
        $this->assertNull( expAiPlanBatch::lane( $auto, $config ) );
        $this->assertFalse( expAiPlanBatch::applies( $auto, $config ) );
        $unknown = $this->thread( array( 'lane' => 'no-such-lane', 'model_alias' => $config->effectiveModel() ) );
        $this->assertNull( expAiPlanBatch::lane( $unknown, $config ), 'a lane the model does not have' );
    }

    public function testTheRequestOfAPlanItem(): void
    {
        $this->asUser( 'admin' );
        $config = $this->config();
        $this->assertRefused( function () use ( $config ) { expAiPlanBatch::request( array( 'action' => 'create', 'class' => 'no_such_class_x', 'fields' => array() ), 'eng-GB', $config ); },
                              'There is no content class "no_such_class_x"' );
        $this->assertRefused( function () use ( $config ) {
            expAiPlanBatch::request( array( 'action' => 'update', 'target' => array( 'object_id' => 999999999 ), 'fields' => array() ), 'eng-GB', $config ); },
            'Object 999999999 does not exist' );
        $this->assertRefused( function () use ( $config ) { expAiPlanBatch::request( array( 'action' => 'create', 'class' => 'folder', 'fields' => array( 'no_such_field' ) ), 'eng-GB', $config ); },
                              'Give the fields to write' );

        // a plan item as expAiPlanner keeps it
        $request = expAiPlanBatch::request( array( 'action' => 'create', 'class' => 'article', 'fields' => array(), 'summary' => 'An article about spring.',
                                                   'target' => array( 'object_id' => 0, 'parent_node_id' => 2 ) ), 'eng-GB', $config );
        $this->assertCount( 3, $request );
        $this->assertSame( array(), $request[2], 'a new object reads no object' );
        $this->assertStringContainsString( 'Write the fields of a new article in eng-GB. Instruction: An article about spring.', $request[0]['messages'][0]['content'] );
        $this->assertSame( array( 'changes', 'summary' ), $request[0]['output_schema']['required'] );
        $this->assertContains( 'title', $request[0]['output_schema']['properties']['changes']['items']['properties']['field']['enum'] );
        $this->assertSame( array( 'action' => 'create', 'object_id' => 0, 'parent_node_id' => 2, 'class' => 'article', 'language' => 'eng-GB' ),
                           array_intersect_key( $request[1], array_flip( array( 'action', 'object_id', 'parent_node_id', 'class', 'language' ) ) ) );
        $fields = expAiPlanBatch::fieldsOf( array( 'fields' => array() ), eZContentClass::fetchByIdentifier( 'article' ), $config );
        $this->assertArrayHasKey( 'title', $fields );
        $this->assertSame( 'ezstring', $fields['title'] );
        $only = expAiPlanBatch::fieldsOf( array( 'fields' => array( 'title' ) ), eZContentClass::fetchByIdentifier( 'article' ), $config );
        $this->assertSame( array( 'title' => 'ezstring' ), $only );
    }
}
