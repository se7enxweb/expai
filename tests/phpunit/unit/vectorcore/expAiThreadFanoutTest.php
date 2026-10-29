<?php
/**
 * expAiThreadFanout: the child runs of an approved plan, their dependencies, the source context a translation gets,
 * Stop, reconciling runs whose job ended, and the runs as the browser sees them. The tests that fan a plan out into
 * real queue jobs run inside a transaction that is rolled back (no worker of the site sees the jobs).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectorcoretestcase.php';

class expAiThreadFanoutTest extends expAiVectorCoreTestCase
{
    private static function item( $index, array $more = array() )
    {
        return $more + array( 'index' => $index, 'selected' => true, 'action' => 'update', 'target' => array( 'object_id' => 0, 'node_id' => 2 ),
                              'target_name' => 'item ' . $index, 'languages' => array( 'eng-GB' ), 'fields' => array(), 'summary' => '', 'class' => '',
                              'depends_on' => array() );
    }

    /** @return array( thread, plan, runs ) a plan fanned out by start() into real jobs (inside the test's transaction) */
    private function started( array $items, $title )
    {
        $thread = $this->thread( array( 'title' => $title, 'model_alias' => 'xtb.stub', 'scope' => array( 'nodes' => array( 2 ) ) ) );
        $this->inTransaction();
        $plan = new expAiPlan( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'state' => 'running', 'created' => time(), 'modified' => time() ) );
        $plan->setItems( $items );
        $plan->setEstimate( array( 'items' => array( array( 'index' => 0, 'micros' => 3000 ), array( 'index' => 1, 'micros' => 1000 ) ) ) );
        $plan->store();
        $thread->setAttribute( 'plan_id', (int) $plan->attribute( 'id' ) );
        $thread->store();
        $out = expAiThreadFanout::start( $thread, $plan, $items, 4000 );
        $this->assertSame( 'parallel', $out['mode'] );
        $this->assertSame( count( $items ), $out['items'] );
        $plan = expAiPlan::fetchByID( (int) $plan->attribute( 'id' ) );
        return array( expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ), $plan, expAiThreadFanout::planRuns( $plan ) );
    }

    private static function job( expAiThreadRun $run )
    {
        return expQueue::fetch( (string) expAiThreadRun::fetchByID( (int) $run->attribute( 'id' ) )->attribute( 'job_uuid' ) );
    }

    private static function setJob( expAiThreadRun $run, array $set )
    {
        $db = eZDB::instance();
        $parts = array();
        foreach ( $set as $field => $value )
            $parts[] = $field . " = '" . $db->escapeString( (string) $value ) . "'";
        $db->query( 'UPDATE expqueue_job SET ' . implode( ', ', $parts ) . " WHERE uuid = '" . $db->escapeString( (string) $run->attribute( 'job_uuid' ) ) . "'" );
    }

    public function testSettingsLabelsAndTheNodeOfAnItem(): void
    {
        $this->ini( 'VectorSettings', 'ParallelRuns', 'enabled' );
        $this->assertTrue( expAiThreadFanout::enabled() );
        $this->ini( 'VectorSettings', 'MaxParallelRunsPerThread', '99' );
        $this->assertSame( 16, expAiThreadFanout::maxParallel(), 'at most 16' );
        $this->assertSame( 'Translate: Main Street (ger-DE, fre-FR)', expAiThreadFanout::label( array( 'action' => 'translate', 'target_name' => 'Main Street', 'languages' => array( 'ger-DE', 'fre-FR' ) ) ) );
        $this->assertSame( 'merge: X', expAiThreadFanout::label( array( 'action' => 'merge', 'target_name' => 'X', 'languages' => array() ) ) );

        $article = $this->article( 'XTB node of' );
        $this->assertSame( 55, expAiThreadFanout::nodeOf( array( 'action' => 'create', 'target' => array( 'parent_node_id' => 55 ) ), 9 ) );
        $this->assertSame( 66, expAiThreadFanout::nodeOf( array( 'action' => 'update', 'target' => array( 'node_id' => 66 ) ), 9 ) );
        $this->assertSame( (int) $article->mainNodeID(), expAiThreadFanout::nodeOf( array( 'action' => 'update', 'target' => array( 'object_id' => (int) $article->attribute( 'id' ) ) ), 9 ) );
        $this->assertSame( 9, expAiThreadFanout::nodeOf( array( 'action' => 'update', 'target' => array( 'object_id' => 2147483000 ) ), 9 ) );
        $this->assertSame( 9, expAiThreadFanout::nodeOf( array( 'action' => 'create', 'target' => array() ), 9 ) );
    }

    public function testDependenciesBlockersAndTheSourceContext(): void
    {
        $this->assertSame( array(), expAiThreadFanout::planRuns( null ) );
        $thread = $this->thread( array( 'title' => 'dependencies' ) );
        $this->assertNull( expAiThreadFanout::planOf( $thread ) );
        list( $plan, $runs ) = $this->planWithRuns( $thread, array( array( 'state' => 'done' ), array( 'state' => 'failed' ),
                                                                     array( 'state' => 'queued', 'depends_on' => array( 0, 1, 7 ) ) ) );
        $this->assertSame( array( 0, 1 ), expAiThreadFanout::dependenciesOf( $plan, 2 ), 'an index without a run is dropped' );
        $this->assertSame( array(), expAiThreadFanout::dependenciesOf( $plan, 42 ) );
        list( $active, $broken ) = expAiThreadFanout::blockers( $plan, $runs[2] );
        $this->assertSame( array(), $active );
        $this->assertSame( (int) $runs[1]->attribute( 'id' ), (int) $broken[0]->attribute( 'id' ) );

        $this->assertSame( '', expAiThreadFanout::dependencyContext( $plan, $runs[2] ), 'nothing staged' );
        $threadID = (int) $thread->attribute( 'id' );
        foreach ( array( array( 0, 'ezxmltext', self::xml( array( 'Rich source.' ) ) ), array( 0, 'ezstring', str_repeat( 'x', 13000 ) ), array( 1, 'ezstring', 'never reached' ) ) as $c )
        {
            $change = new expAiChange( array( 'thread_id' => $threadID, 'run_id' => (int) $runs[$c[0]]->attribute( 'id' ), 'contentobject_id' => 77, 'language_code' => 'eng-GB',
                                              'attribute_identifier' => $c[1] === 'ezxmltext' ? 'intro' : 'title', 'datatype' => $c[1], 'proposed' => $c[2],
                                              'decision' => 'pending', 'created' => time(), 'modified' => time() ) );
            $change->store();
        }
        $context = expAiThreadFanout::dependencyContext( $plan, $runs[2] );
        $this->assertStringStartsWith( 'The item(s) this one waits for staged these changes', $context );
        $this->assertStringContainsString( '- object 77, language eng-GB, field intro:', $context );
        $this->assertStringContainsString( 'Rich source.', $context );
        $this->assertStringNotContainsString( '<paragraph>', $context, 'rich text as HTML' );
        $this->assertStringNotContainsString( 'never reached', $context, 'cut after about 12000 characters' );
    }

    public function testAFailedItemStopsTheItemsThatWaitForItAndSettlesThePlan(): void
    {
        list( $thread, $plan, $runs ) = $this->started( array( self::item( 0 ), self::item( 1, array( 'action' => 'translate', 'depends_on' => array( 0 ) ) ) ), 'reconcile' );
        $this->assertSame( 'queued', $runs[0]->attribute( 'state' ) );
        $this->assertSame( 'waiting', $runs[1]->attribute( 'state' ) );
        $this->assertSame( 'running', $thread->attribute( 'state' ) );
        $this->assertFalse( expAiThreadFanout::reconcile( $thread ), 'the jobs still wait' );
        self::setJob( $runs[0], array( 'state' => 'dead', 'last_error' => 'the worker gave up' ) );
        $this->assertTrue( expAiThreadFanout::reconcile( $thread ) );
        $this->assertSame( 'failed', expAiThreadRun::fetchByID( (int) $runs[0]->attribute( 'id' ) )->attribute( 'state' ) );
        $this->assertSame( 'stopped', expAiThreadRun::fetchByID( (int) $runs[1]->attribute( 'id' ) )->attribute( 'state' ) );
        $this->assertSame( 'cancelled', self::job( $runs[1] )->attribute( 'state' ) );
        $this->assertSame( 'done', expAiPlan::fetchByID( (int) $plan->attribute( 'id' ) )->attribute( 'state' ) );
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertNotContains( $fresh->attribute( 'state' ), array( 'queued', 'running' ) );
        $errors = array_values( array_filter( expAiThreadEvent::fetchAfter( (int) $fresh->attribute( 'id' ), 0, 100 ), function ( $e ) { return $e->attribute( 'kind' ) === 'error'; } ) );
        $this->assertStringContainsString( 'the worker gave up', $errors[0]->payload()['message'] );
        $this->assertFalse( expAiThreadFanout::reconcile( $fresh ), 'a settled plan' );
        $this->assertFalse( expAiThreadFanout::settle( $fresh ) );
    }

    public function testDoneJobsEndTheirRunsAndWakeTheItemThatWaits(): void
    {
        list( $thread, $plan, $runs ) = $this->started( array( self::item( 0 ), self::item( 1, array( 'depends_on' => array( 0 ) ) ) ), 'wake' );
        // the waiting item's job was deferred by the handler: queued again, its parent done
        self::setJob( $runs[0], array( 'state' => 'done' ) );
        self::setJob( $runs[1], array( 'state' => 'queued' ) );
        $this->assertTrue( expAiThreadFanout::reconcile( $thread ), 'a done job whose handler did not say so' );
        $this->assertSame( 'done', expAiThreadRun::fetchByID( (int) $runs[0]->attribute( 'id' ) )->attribute( 'state' ) );
        $this->assertSame( 'ready', self::job( $runs[1] )->attribute( 'state' ), 'the item that waited is woken' );
        $this->assertSame( 'running', expAiPlan::fetchByID( (int) $plan->attribute( 'id' ) )->attribute( 'state' ), 'an item is still active' );

        // on the batch lane a done job is ended by its applier, not here
        $estimate = $plan->estimate();
        $estimate['batch'] = true;
        $plan = expAiPlan::fetchByID( (int) $plan->attribute( 'id' ) );
        $plan->setEstimate( $estimate );
        $plan->store();
        self::setJob( $runs[1], array( 'state' => 'done' ) );
        $this->assertFalse( expAiThreadFanout::reconcile( $thread ) );
        $this->assertSame( 'waiting', expAiThreadRun::fetchByID( (int) $runs[1]->attribute( 'id' ) )->attribute( 'state' ) );
    }

    public function testStopCancelsTheGroupAndSettles(): void
    {
        list( $thread, $plan, $runs ) = $this->started( array( self::item( 0 ), self::item( 1, array( 'depends_on' => array( 0 ) ) ) ), 'stop' );
        $this->assertSame( 0, expAiThreadFanout::cancel( $this->thread( array( 'title' => 'no plan' ) ) ) );
        $this->assertSame( 2, expAiThreadFanout::cancel( $thread ) );
        foreach ( $runs as $run )
            $this->assertSame( 'stopped', expAiThreadRun::fetchByID( (int) $run->attribute( 'id' ) )->attribute( 'state' ) );
        $this->assertSame( 'done', expAiPlan::fetchByID( (int) $plan->attribute( 'id' ) )->attribute( 'state' ) );
    }

    public function testPresentTheRunsOfThePlan(): void
    {
        $thread = $this->thread( array( 'title' => 'present' ) );
        $this->assertSame( array( 'runs' => array(), 'done' => 0, 'total' => 0, 'active' => 0 ), expAiThreadFanout::present( $thread ) );
        list( $plan, $runs ) = $this->planWithRuns( $thread, array( array( 'state' => 'done' ), array( 'state' => 'running', 'depends_on' => array( 0 ) ) ),
                                                     array( 'items' => array( array( 'index' => 0, 'micros' => 2000000 ), array( 'index' => 1 ) ) ) );
        $thread = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $thread->addEvent( 'step_end', array( 'text' => "Read the\narticle" ), (int) $runs[1]->attribute( 'id' ) );
        $thread->addEvent( 'error', array( 'message' => 'Item one failed once' ), (int) $runs[0]->attribute( 'id' ) );
        $thread->addEvent( 'text', array( 'text' => '' ), (int) $runs[0]->attribute( 'id' ) );
        $thread->storeActivity();
        $thread = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $out = expAiThreadFanout::present( $thread );
        $this->assertSame( 2, $out['total'] );
        $this->assertSame( 1, $out['done'] );
        $this->assertSame( 1, $out['active'] );
        $this->assertSame( 'running', $out['plan_state'] );
        $this->assertSame( 'Read the article', $out['runs'][1]['last'] );
        $this->assertSame( 'Item one failed once', $out['runs'][0]['last'] );
        $this->assertSame( array( 1 ), $out['runs'][1]['depends_on'], 'shown 1-based' );
        $this->assertSame( 2000000, $out['runs'][0]['estimate_micros'] );
        $this->assertSame( 0, $out['runs'][1]['estimate_micros'] );
        $this->assertSame( expAiCost::enabled() ? expAiCost::money( 2.0 ) : '', $out['runs'][0]['estimate'] );
        $this->assertSame( 'standard', $out['runs'][0]['lane'] );
        $this->assertSame( array( 0 => 0, 1 => 0 ), expAiThreadFanout::itemActuals( $thread, $plan ), 'no usage rows yet' );
    }
}
