<?php
/**
 * expAiChange (decisions and counts), expAiPlan (as the browser sees it), expAiPlanEstimate (frame and context sizes,
 * the calibration from finished plans, the site budget) and expAiChangeApplier (what it refuses and skips).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectorcoretestcase.php';

class expAiChangeAndPlanTest extends expAiVectorCoreTestCase
{
    private static function change( array $row, array $hunks = array() )
    {
        $change = new expAiChange( $row + array( 'language_code' => 'eng-GB', 'attribute_identifier' => 'title', 'datatype' => 'ezstring', 'decision' => 'pending',
                                                 'created' => time(), 'modified' => time() ) );
        $change->setHunks( $hunks );
        return $change;
    }

    public function testDecisionsOfAChange(): void
    {
        $this->assertNull( expAiChange::fetchByID( 0 ) );
        $change = self::change( array() );
        $this->assertSame( 'pending', $change->deriveDecision(), 'no hunks' );
        foreach ( array( 'applied', 'stale' ) as $kept )
        {
            $change->setAttribute( 'decision', $kept );
            $this->assertSame( $kept, $change->deriveDecision() );
        }
        $change = self::change( array(), array( array( 'id' => 'a' ), array( 'id' => 'b', 'decision' => 'odd' ) ) );
        $this->assertSame( 2, $change->decide( '', 'accepted', $this->userID ) );
        $this->assertSame( 'accepted', $change->attribute( 'decision' ) );
        $this->assertSame( 0, $change->decide( 'a', 'accepted', $this->userID ), 'already accepted' );
        $this->assertSame( 1, $change->decide( 'b', 'rejected', $this->userID ) );
        $this->assertSame( 'partial', $change->attribute( 'decision' ) );
        $this->assertSame( array( 'a' ), $change->acceptedHunkIDs() );
        $change->decide( 'a', 'rejected', $this->userID );
        $this->assertSame( 'rejected', $change->attribute( 'decision' ) );
        $this->assertSame( $this->userID, (int) $change->attribute( 'decided_by' ) );
        try
        {
            $change->decide( '', 'maybe', $this->userID );
            $this->fail( 'an unknown decision was taken' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'Unknown decision.', $e->getMessage() );
        }

        // a conflict stays one until the editor keeps the current value for the whole change
        $conflict = self::change( array( 'decision' => 'conflict' ), array( array( 'id' => 'a' ) ) );
        $conflict->decide( 'a', 'accepted', $this->userID );
        $this->assertSame( 'conflict', $conflict->attribute( 'decision' ) );
        $conflict->decide( '', 'rejected', $this->userID );
        $this->assertSame( 'rejected', $conflict->attribute( 'decision' ) );
    }

    public function testCountsOfAThreadsChanges(): void
    {
        $thread = $this->thread( array( 'title' => 'counts' ) );
        $id = (int) $thread->attribute( 'id' );
        self::change( array( 'thread_id' => $id ), array( array( 'id' => 'a' ), array( 'id' => 'b', 'decision' => 'accepted' ) ) )->store();
        self::change( array( 'thread_id' => $id, 'decision' => 'conflict' ), array( array( 'id' => 'c' ) ) )->store();
        self::change( array( 'thread_id' => $id, 'decision' => 'partial' ), array( array( 'id' => 'd', 'decision' => 'accepted' ), array( 'id' => 'e', 'decision' => 'rejected' ) ) )->store();
        self::change( array( 'thread_id' => $id, 'decision' => 'applied' ) )->store();
        self::change( array( 'thread_id' => $id, 'decision' => 'stale' ) )->store();
        $counts = expAiChange::counts( $id );
        $this->assertSame( 1, $counts['pending_hunks'], 'the hunks of a conflict are not counted as pending' );
        $this->assertSame( 3, $counts['open'] );
        $this->assertSame( 1, $counts['accepted'] );
        $this->assertSame( 1, $counts['applied'] );
        $this->assertSame( 1, $counts['conflict'] );
    }

    public function testThePlanForTheBrowser(): void
    {
        $plan = new expAiPlan( array( 'id' => 7, 'state' => 'proposed', 'created' => 100, 'approved' => 0 ) );
        $plan->setItems( array( array( 'index' => 0, 'selected' => true ) ) );
        $plan->setEstimate( array( 'expected_micros' => 5, 'meta' => array( 'risks' => array( 'r' ) ) ) );
        $this->assertSame( array( 'id' => 7, 'state' => 'proposed', 'items' => array( array( 'index' => 0, 'selected' => true ) ),
                                  'estimate' => array( 'expected_micros' => 5, 'meta' => array( 'risks' => array( 'r' ) ) ), 'created' => 100, 'approved' => 0,
                                  'meta' => array( 'risks' => array( 'r' ) ) ), $plan->present() );
        $plan->setAttribute( 'estimate_json', 'not json' );
        $plan->setAttribute( 'items_json', 'not json' );
        $this->assertSame( array(), $plan->present()['meta'] );
        $this->assertSame( array(), $plan->items() );
        $this->assertNull( expAiPlan::fetchByID( 0 ) );
    }

    public function testEstimateSizesFromTheThreadAndTheTarget(): void
    {
        $estimate = new expAiPlanEstimate( expAiConfig::instance() );
        $this->ini( 'VectorSettings', 'PlanFrameTokens', '1234' );
        $thread = $this->thread( array( 'title' => 'frame' ) );
        $this->assertSame( 1234, $estimate->frameTokens( $thread ), 'no stored frame' );
        $this->assertSame( 1234, $estimate->frameTokens() );
        $conversation = $thread->conversation();
        $conversation->setFrame( str_repeat( 'x', 4000 ), array() );
        $conversation->store();
        $tokens = $estimate->frameTokens( expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) );
        $this->assertGreaterThan( 500, $tokens );
        $this->assertLessThan( 4000, $tokens );

        $article = $this->article( 'XTB estimate article', array( str_repeat( 'A long paragraph. ', 100 ) ) );
        $byObject = $estimate->contextTokens( array( 'target' => array( 'object_id' => (int) $article->attribute( 'id' ) ) ) );
        $byNode = $estimate->contextTokens( array( 'target' => array( 'node_id' => (int) $article->mainNodeID() ) ) );
        $this->assertSame( $byObject, $byNode, 'the object of a node' );
        $this->assertGreaterThan( 300, $byObject );
    }

    public function testCalibrationFromTheLastFinishedPlans(): void
    {
        $thread = $this->thread( array( 'title' => 'calibration' ) );
        $this->inTransaction();
        // the newest finished plans are this test's: twice the expected cost each
        foreach ( range( 1, 20 ) as $n )
        {
            $plan = new expAiPlan( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'state' => 'done', 'created' => time(), 'modified' => time() ) );
            $plan->setEstimate( array( 'expected_micros' => 1000, 'actual_micros' => $n % 2 ? 2000 : 2200 ) );
            $plan->store();
        }
        $calibration = ( new expAiPlanEstimate( expAiConfig::instance() ) )->calibration();
        $this->assertSame( 20, $calibration['from'] );
        $this->assertSame( 2.1, $calibration['factor'], 'the median ratio' );
    }

    public function testTheSiteBudgetInTheLimitsOfAPlan(): void
    {
        $this->ini( 'Budgets', 'SiteMonthly', '0.000001' );
        $limits = ( new expAiPlanEstimate( expAiConfig::instance() ) )->limits( 5000000, $this->userID );
        $this->assertFalse( $limits['ok'] );
        $this->assertStringStartsWith( 'This plan needs about ', $limits['message'] );
        $this->assertStringContainsString( 'the site budget has', $limits['message'] );
        $site = array_values( array_filter( $limits['rows'], function ( $r ) { return $r['key'] === 'site_budget'; } ) );
        $this->assertFalse( $site[0]['fits'] );
        $this->ini( 'Budgets', 'SiteMonthly', '' );
        $this->ini( 'Limits', 'MaxCostPerSitePerMonth', '' );
        $this->assertTrue( ( new expAiPlanEstimate( expAiConfig::instance() ) )->limits( 0, $this->userID )['ok'] );
    }

    public function testTheApplierSkipsOtherChangesAndReportsWhatItCannotWrite(): void
    {
        $article = $this->article( 'XTB applier article' );
        $thread = $this->thread( array( 'title' => 'applier', 'scope' => array( 'nodes' => array( (int) $article->mainNodeID() ) ) ) );
        $id = (int) $thread->attribute( 'id' );
        $accepted = array( array( 'id' => 'a', 'decision' => 'accepted', 'prop' => array( 0 ), 'base' => array( 0 ) ) );
        $gone = self::change( array( 'thread_id' => $id, 'contentobject_id' => 2147483000, 'decision' => 'accepted', 'proposed' => 'x' ), $accepted );
        $gone->store();
        $nothing = self::change( array( 'thread_id' => $id, 'contentobject_id' => (int) $article->attribute( 'id' ), 'decision' => 'partial', 'proposed' => 'x' ),
                                 array( array( 'id' => 'b', 'decision' => 'rejected' ) ) );
        $nothing->store();
        $orphan = self::change( array( 'thread_id' => $id, 'new_key' => 'abcdef0123456789', 'parent_node_id' => 2147483000, 'class_identifier' => 'article',
                                       'decision' => 'accepted', 'proposed' => 'x' ), $accepted );
        $orphan->store();
        $applier = new expAiChangeApplier( $thread );
        $out = $applier->apply( array( (int) $gone->attribute( 'id' ) ) );
        $this->assertSame( array(), $out['drafts'] );
        $this->assertCount( 1, $out['errors'], 'only the change asked for' );
        $this->assertSame( 'Object 2147483000 does not exist or you may not read it.', $out['errors'][0]['message'] );
        $out = $applier->apply();
        $messages = array_column( $out['errors'], 'message' );
        sort( $messages );
        $this->assertSame( array( 'Node 2147483000 does not exist or you may not read it.', 'Object 2147483000 does not exist or you may not read it.' ), $messages,
                           'a change without accepted hunks is not applied at all' );
        $this->assertSame( array(), $out['applied'] );
    }
}
