<?php
/**
 * What a reviewer of a thread who is not its owner sees of objects it may not read (expAiVector): plan items without
 * name, instruction or fields, no staged changes, exchanges without the drafts on such objects. The structures are
 * made by the code that makes them in a running site (expAiPlanner::newItem(), expAiPlan, expAiChange, the draft key
 * of expAiAssistant), so that a renamed field cannot let everything through; what cannot be told is withheld.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectortestcase.php';

class expAiVectorVisibilityTest extends expAiVectorTestCase
{
    /** @return array the items of a plan as stored and read back (expAiPlan::items()) */
    private static function storedItems( array $toolInput )
    {
        $items = array();
        foreach ( $toolInput as $n => $in )
        {
            $item = expAiPlanner::newItem( $in, $n );
            $item['target_name'] = isset( $in['name'] ) ? $in['name'] : '';
            $items[] = $item;
        }
        $plan = new expAiPlan( array( 'thread_id' => 1, 'state' => 'proposed' ) );
        $plan->setItems( $items );
        return $plan->items();
    }

    public function testPlanItemsOfUnreadableTargetsKeepOnlyTheirShape(): void
    {
        $items = self::storedItems( array(
            array( 'action' => 'update', 'object_id' => 10, 'name' => 'Salaries 2026', 'summary' => 'Raise the figure to 90000', 'fields' => array( 'body' ) ),
            array( 'action' => 'create', 'parent_node_id' => 20, 'class' => 'article', 'name' => 'News', 'summary' => 'A new article', 'fields' => array( 'title' ) ),
            array( 'action' => 'update', 'object_id' => 11, 'name' => 'Public page', 'summary' => 'Fix a typo', 'fields' => array( 'title' ) ),
            array( 'action' => 'update', 'node_id' => 30, 'name' => 'Team', 'summary' => 'Rename', 'fields' => array( 'title' ) ),
            array( 'action' => 'update', 'name' => 'Somewhere', 'summary' => 'No target at all', 'fields' => array( 'title' ) ),
        ) );
        $objects = function ( $id ) { return $id === 11; };
        $nodes = function ( $id ) { return $id === 30; };
        $out = expAiVector::visiblePlanItems( $items, false, $objects, $nodes );
        $this->assertSame( array( '#10', '', array(), true, 'update' ),
                           array( $out[0]['target_name'], $out[0]['summary'], $out[0]['fields'], $out[0]['withheld'], $out[0]['action'] ) );
        $this->assertSame( array( '#20', '', true ), array( $out[1]['target_name'], $out[1]['summary'], $out[1]['withheld'] ) );
        $this->assertSame( $items[2], $out[2], 'a readable object is unchanged' );
        $this->assertSame( $items[3], $out[3], 'a readable node is unchanged' );
        $this->assertSame( array( '', '', true ), array( $out[4]['target_name'], $out[4]['summary'], $out[4]['withheld'] ), 'no target: withheld' );
        $this->assertSame( $items, expAiVector::visiblePlanItems( $items, true, $objects, $nodes ), 'the owner sees the plan as it is' );
    }

    public function testStagedChangesOfUnreadableObjectsAreHidden(): void
    {
        $objects = function ( $id ) { return $id === 11; };
        $nodes = function ( $id ) { return $id === 30; };
        $change = function ( array $row ) { return new expAiChange( $row + array( 'thread_id' => 1, 'contentobject_id' => 0, 'new_key' => '', 'parent_node_id' => 0,
                                                                                  'language_code' => 'eng-GB', 'attribute_identifier' => 'title' ) ); };
        $this->assertTrue( expAiVector::changeHidden( $change( array( 'contentobject_id' => 10 ) ), $objects, $nodes ) );
        $this->assertFalse( expAiVector::changeHidden( $change( array( 'contentobject_id' => 11 ) ), $objects, $nodes ) );
        $this->assertTrue( expAiVector::changeHidden( $change( array( 'new_key' => 'a', 'parent_node_id' => 20 ) ), $objects, $nodes ) );
        $this->assertFalse( expAiVector::changeHidden( $change( array( 'new_key' => 'a', 'parent_node_id' => 30 ) ), $objects, $nodes ) );
        $this->assertTrue( expAiVector::changeHidden( $change( array( 'new_key' => 'a' ) ), $objects, $nodes ), 'a new object without a parent: hidden' );
    }

    public function testExchangesLoseTheDraftsOfUnreadableObjects(): void
    {
        // the key as expAiAssistant writes it into an exchange: "<object id>:<version>"
        $key = function ( $objectID, $version ) { return $objectID . ':' . $version; };
        $history = array( 'exchanges' => array(
            array( 'id' => 1, 'user' => 'u', 'reply' => 'r', 'drafts' => array( array( 'key' => $key( 10, 3 ), 'name' => 'Salaries 2026' ), array( 'key' => 'broken', 'name' => 'Bonus' ),
                                                                                array( 'name' => 'No key' ) ),
                   'outcomes' => array( 'Salaries 2026: published as version 3' ) ),
            array( 'id' => 2, 'user' => 'u', 'reply' => 'r', 'drafts' => array() ),
        ), 'has_older' => false );
        $out = expAiVector::visibleHistory( $history, false, function ( $id ) { return $id === 11; } );
        $this->assertSame( array(), $out['exchanges'][0]['drafts'] );
        $this->assertSame( array(), $out['exchanges'][0]['outcomes'], 'the notes named the withheld drafts' );
        $this->assertSame( 3, $out['exchanges'][0]['withheld'], 'a draft whose key cannot be read is withheld too' );
        $this->assertSame( array(), $out['exchanges'][1]['drafts'] );
        $this->assertSame( $history, expAiVector::visibleHistory( $history, true, function ( $id ) { return false; } ), 'the owner sees the history as it is' );
        $this->assertSame( array( 'exchanges' => array() ), expAiVector::visibleHistory( array( 'exchanges' => array() ) ) );
        $this->assertStringContainsString( "\$key = \$log->attribute( 'contentobject_id' ) . ':' . \$log->attribute( 'version' );",
                                           (string) file_get_contents( EXPAI_TEST_CHECKOUT . '/classes/expaiassistant.php' ), 'the key of a draft in an exchange changed' );
    }

    /** @return array an event as the browser gets it, made the way expAiThreadEvent::append() makes it */
    private static function event( $kind, array $payload, $runID = 0 )
    {
        $event = new expAiThreadEvent( array( 'id' => 5, 'thread_id' => 1, 'run_id' => $runID, 'kind' => $kind,
                                              'payload' => expAiThreadEvent::encode( $payload ), 'created' => 1800000000 ) );
        return $event->present();
    }

    public function testEventsOfTheStreamAndThePoll(): void
    {
        $objects = function ( $id ) { return $id === 11; };
        // the payloads as expAiThread::afterStep(), expAiChangeStager and expAiThreadItemHandler write them
        $draftUnreadable = self::event( 'draft', array( 'object_id' => 10, 'version' => 3, 'language' => 'eng-GB', 'action' => 'update' ) );
        $draftReadable = self::event( 'draft', array( 'object_id' => 11, 'version' => 2, 'language' => 'eng-GB', 'action' => 'update' ) );
        $changeNew = self::event( 'change', array( 'object_id' => 0, 'new_key' => 'a1', 'language' => 'eng-GB', 'fields' => array( 'title' ) ) );
        $held = self::event( 'held', array( 'object_id' => 10, 'language' => 'eng-GB', 'fields' => array( 'title' => 'held by run 4' ) ), 4 );
        $step = self::event( 'step_end', array( 'text' => 'Read node "Salaries 2026"', 'ok' => true, 'writes' => false, 'run_id' => 4 ), 4 );
        $text = self::event( 'text', array( 'text' => 'The salary is 90000.' ) );
        $error = self::event( 'error', array( 'message' => 'Object "Salaries 2026" is locked' ) );
        $status = self::event( 'status', array( 'state' => 'review', 'from' => 'running' ) );
        $cost = self::event( 'cost', array( 'micros' => 1200, 'tokens' => 300, 'requests' => 2 ) );
        $question = self::event( 'question', array( 'text' => 'Shall I change "Salaries 2026"?' ) );

        $this->assertSame( array( 'withheld' => true ), expAiVector::visibleEvent( $draftUnreadable, false, $objects )['payload'] );
        $this->assertSame( $draftReadable, expAiVector::visibleEvent( $draftReadable, false, $objects ) );
        $this->assertSame( array( 'withheld' => true ), expAiVector::visibleEvent( $changeNew, false, $objects )['payload'], 'a new object cannot be checked' );
        $this->assertSame( array( 'withheld' => true ), expAiVector::visibleEvent( $held, false, $objects )['payload'] );
        $heldReadable = self::event( 'held', array( 'object_id' => 11, 'language' => 'eng-GB', 'fields' => array( 'title' => 'held by run 4 (Salaries 2026)' ) ), 4 );
        $this->assertSame( array( 'title' => 'held by another run' ), expAiVector::visibleEvent( $heldReadable, false, $objects )['payload']['fields'] );
        $this->assertSame( array( 'text' => '', 'ok' => true, 'writes' => false, 'withheld' => true, 'run_id' => 4 ),
                           expAiVector::visibleEvent( $step, false, $objects )['payload'] );
        $this->assertSame( array( 'withheld' => true ), expAiVector::visibleEvent( $text, false, $objects )['payload'] );
        $this->assertSame( array( 'withheld' => true ), expAiVector::visibleEvent( $error, false, $objects )['payload'] );
        $this->assertSame( array( 'withheld' => true ), expAiVector::visibleEvent( $question, false, $objects )['payload'], 'other kinds fail closed' );
        $this->assertSame( $status, expAiVector::visibleEvent( $status, false, $objects ) );
        $this->assertSame( $cost, expAiVector::visibleEvent( $cost, false, $objects ) );
        foreach ( array( $draftUnreadable, $step, $text, $question ) as $event )
            $this->assertSame( $event, expAiVector::visibleEvent( $event, true, $objects ), 'the owner sees every event' );
        // every kind the stream knows is decided: kept as it is only for the kinds without content
        // every kind the stream knows: a word that is not a state, id, count or cost never reaches an approver
        foreach ( expAiThreadEvent::$kinds as $kind )
        {
            $out = expAiVector::visibleEvent( self::event( $kind, array( 'text' => 'secret', 'message' => 'secret', 'summary' => 'secret', 'object_id' => 10 ) ), false, $objects );
            $this->assertStringNotContainsString( 'secret', json_encode( $out ), "kind $kind" );
        }
    }

    public function testTheHistoryOfAnApproverHasNoWords(): void
    {
        $history = array( 'exchanges' => array( array( 'id' => 1, 'user' => 'Raise the salary of Jane', 'reply' => 'Done: 90000', 'snippet' => 'Done: 90000',
                                                       'user_snippet' => 'Raise the salary', 'notice' => 'answered by another model',
                                                       'steps' => array( array( 'text' => 'Read node "Salaries 2026"', 'ok' => true, 'writes' => false ) ),
                                                       'drafts' => array() ) ),
                          'has_older' => false, 'records' => array( '1' => array( 'steps' => array( array( 'tools' => 'get_node' ) ) ) ) );
        $out = expAiVector::visibleHistory( $history, false, function ( $id ) { return true; } );
        $this->assertSame( array(), $out['records'] );
        $exchange = $out['exchanges'][0];
        $this->assertSame( array( '', '', '', '', '' ), array( $exchange['user'], $exchange['reply'], $exchange['snippet'], $exchange['user_snippet'], $exchange['notice'] ) );
        $this->assertSame( array( array( 'text' => '', 'ok' => true, 'writes' => false, 'withheld' => true ) ), $exchange['steps'] );
        $this->assertTrue( $exchange['texts_withheld'] );
        $this->assertStringNotContainsString( 'Salaries', json_encode( $out ) );
    }

    public function testRunsCheckpointsAndPlanMetaOfAnApprover(): void
    {
        $runs = array( 'runs' => array( array( 'id' => 4, 'item' => 0, 'label' => 'Salaries 2026', 'state' => 'done', 'last' => 'Set to 90000' ) ), 'done' => 1, 'total' => 1 );
        $out = expAiVector::visibleRuns( $runs, false );
        $this->assertSame( array( '', '', true, 'done' ), array( $out['runs'][0]['label'], $out['runs'][0]['last'], $out['runs'][0]['withheld'], $out['runs'][0]['state'] ) );
        $this->assertSame( $runs, expAiVector::visibleRuns( $runs, true ) );
        $checkpoints = array( array( 'id' => 1, 'turn' => 2, 'label' => 'Raise the salary of Jane', 'created' => 1 ) );
        $this->assertSame( '', expAiVector::visibleCheckpoints( $checkpoints, false )[0]['label'] );
        $this->assertSame( $checkpoints, expAiVector::visibleCheckpoints( $checkpoints, true ) );

        $plan = new expAiPlan( array( 'id' => 3, 'thread_id' => 1, 'state' => 'proposed', 'created' => 1, 'approved' => 0 ) );
        $plan->setItems( array( expAiPlanner::newItem( array( 'action' => 'update', 'object_id' => 10, 'summary' => 'Raise to 90000' ), 0 ) ) );
        $plan->setEstimate( array( 'expected_micros' => 5, 'meta' => array( 'assumptions' => array( 'Jane earns 80000' ), 'questions' => array(), 'risks' => array() ) ) );
        $present = $plan->present();
        $out = expAiVector::visiblePlan( $present, false, function ( $id ) { return false; }, function ( $id ) { return false; } );
        $this->assertStringNotContainsString( '80000', json_encode( $out ) );
        $this->assertStringNotContainsString( '90000', json_encode( $out ) );
        $this->assertTrue( $out['items'][0]['withheld'] );
        $this->assertSame( $present, expAiVector::visiblePlan( $present, true ) );
    }

    public function testTheTitleOfAThreadIsTheOwnersOnly(): void
    {
        $thread = new expAiThread( array( 'id' => 1, 'uuid' => 'u1', 'user_id' => 14, 'title' => 'Raise the salary of Jane', 'state' => 'idle', 'shelf' => 'active',
                                          'modified' => time(), 'origin' => 'vector', 'run_mode' => 'write', 'plan_id' => 0 ) );
        $owner = expAiVector::listRow( $thread, 14 );
        $this->assertSame( array( 'Raise the salary of Jane', false ), array( $owner['title'], $owner['withheld'] ) );
        $approver = expAiVector::listRow( $thread, 15 );
        $this->assertTrue( $approver['withheld'] );
        $this->assertTrue( $approver['untitled'] );
        $this->assertStringNotContainsString( 'Jane', json_encode( $approver ) );
    }
}
