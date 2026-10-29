<?php
/**
 * expAiSlowModel: which models are slow (flag, background setting, managed server, measured latency), the status of a
 * running turn on one, and switching that turn to the site default.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectorcoretestcase.php';

class expAiSlowModelTest extends expAiVectorCoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ( array( 'Provider' => 'xtb', 'Id' => 'slow-stub', 'Label' => 'Slow stub', 'ToolUse' => 'native', 'Slow' => 'true' ) as $name => $value )
            $this->ini( 'Model_xtb.slow', $name, $value );
    }

    /** @return string an alias no usage row names yet (the latency is remembered per process and alias) */
    private function latencyAlias( array $milliseconds )
    {
        $alias = 'xtb.lat' . bin2hex( random_bytes( 4 ) );
        foreach ( $milliseconds as $ms )
        {
            $row = new expAiUsageLog( array( 'provider' => 'xtb', 'model' => 'stub', 'model_alias' => $alias, 'latency_ms' => $ms, 'created' => time() ) );
            $row->store();
        }
        return $alias;
    }

    public function testSecondsPerStepIsTheMedianOfTheLastRequests(): void
    {
        $this->assertNull( expAiSlowModel::secondsPerStep( 'xtb.never' . bin2hex( random_bytes( 4 ) ) ) );
        $this->assertSame( 2.0, expAiSlowModel::secondsPerStep( $this->latencyAlias( array( 1000, 3000, 2000, 0 ) ) ), 'odd count, rows without a latency left out' );
        $this->assertSame( 2.5, expAiSlowModel::secondsPerStep( $this->latencyAlias( array( 1000, 2000, 3000, 4000 ) ) ), 'even count' );
    }

    public function testIsSlow(): void
    {
        $this->assertFalse( expAiSlowModel::isSlow( 'no.model' ) );
        $this->assertFalse( expAiSlowModel::isSlow( 'xtb.stub' ) );
        $this->assertTrue( expAiSlowModel::isSlow( 'xtb.slow' ), 'marked Slow' );
        $this->ini( 'Model_xtb.stub', 'RunInBackground', 'always' );
        $this->assertTrue( expAiSlowModel::isSlow( 'xtb.stub' ), 'RunInBackground=always' );
        $this->ini( 'Model_xtb.stub', 'RunInBackground', '' );
        $this->ini( 'Provider_xtb', 'Managed', 'ollama' );
        $this->assertTrue( expAiSlowModel::isSlow( 'xtb.stub' ), 'a managed local server' );
        $this->ini( 'Provider_xtb', 'Managed', '' );

        $alias = $this->latencyAlias( array( 30000, 25000, 40000 ) );
        foreach ( array( 'Provider' => 'xtb', 'Id' => 'stub', 'Label' => 'Measured' ) as $name => $value )
            $this->ini( 'Model_' . $alias, $name, $value );
        $this->ini( 'VectorSettings', 'SlowStepSeconds', '20' );
        $this->assertTrue( expAiSlowModel::isSlow( $alias ), 'measured at 30 s per step' );
        $this->ini( 'VectorSettings', 'SlowStepSeconds', '60' );
        $this->assertFalse( expAiSlowModel::isSlow( $alias ) );
    }

    public function testLabelAndTarget(): void
    {
        $this->assertSame( 'Stub', expAiSlowModel::label( 'xtb.stub' ) );
        $this->assertSame( 'not.allowed', expAiSlowModel::label( 'not.allowed' ) );
        $this->assertSame( 'xtb.stub', expAiSlowModel::target( 'xtb.slow' ) );
        $this->assertSame( '', expAiSlowModel::target( 'xtb.stub' ), 'the default itself' );
        $this->ini( 'Model_xtb.stub', 'Slow', 'true' );
        $this->assertSame( '', expAiSlowModel::target( 'xtb.slow' ), 'a slow default is no way out' );
    }

    public function testStatusOfARunningTurn(): void
    {
        $thread = $this->thread( array( 'title' => 'status', 'model_alias' => 'xtb.slow' ) );
        $this->assertNull( expAiSlowModel::status( $thread ), 'nothing runs' );
        $thread->setState( 'running' );
        $thread->store();
        $conversation = $thread->conversation();
        $conversation->setAttribute( 'model_alias', 'xtb.slow' );
        $conversation->setHistory( array( array( 'user' => 'Go', 'steps' => array( array( 'text' => 'one' ) ), 'time' => 1700000000 ) ) );
        $conversation->store();
        $status = expAiSlowModel::status( $thread );
        $this->assertSame( 'xtb.slow', $status['alias'] );
        $this->assertTrue( $status['slow'] );
        $this->assertSame( 2, $status['step'] );
        $this->assertSame( 1700000000, $status['since'] );
        $this->assertSame( 'xtb.stub', $status['switch_to'] );
        $this->assertSame( 'Stub', $status['switch_label'] );
        $this->assertSame( 'Switch to Stub', $status['button'] );
        if ( $status['per_step'] === null )
            // a model the user may not choose is named by its alias
            $this->assertSame( 'Running on xtb.slow, a slow model (step 2)', $status['text'] );
        else
            $this->assertStringStartsWith( 'Running on xtb.slow - about ', $status['text'] );

        $conversation->setAttribute( 'model_alias', 'xtb.stub' );
        $conversation->store();
        $thread->setAttribute( 'model_alias', '' );
        $this->assertNull( expAiSlowModel::status( $thread ), 'a model that is not slow' );

        $run = $thread->leadRun();
        $run->setAttribute( 'conversation_id', 0 );
        $run->store();
        $this->assertNull( expAiSlowModel::status( $thread ), 'no conversation' );
    }

    public function testSwitchToRefusals(): void
    {
        $thread = $this->thread( array( 'title' => 'switch refusals', 'model_alias' => 'xtb.slow' ) );
        $this->assertSame( 'Nothing runs in this thread.', self::message( function () use ( $thread ) { expAiSlowModel::switchTo( $thread ); } ) );
        $thread->setState( 'running' );
        $this->assertSame( 'This model is not available.', self::message( function () use ( $thread ) { expAiSlowModel::switchTo( $thread, 'no.model' ); } ) );
        $thread->setAttribute( 'model_alias', 'xtb.stub' );
        $this->assertSame( 'The thread already runs on this model.', self::message( function () use ( $thread ) { expAiSlowModel::switchTo( $thread, 'xtb.stub' ); } ) );
        $run = $thread->leadRun();
        $run->setAttribute( 'conversation_id', 0 );
        $run->store();
        $this->assertSame( 'This thread has no conversation any more.', self::message( function () use ( $thread ) { expAiSlowModel::switchTo( $thread ); } ) );
    }

    public function testSwitchAPanelTurnThatWaitsAndQueueItAgain(): void
    {
        $thread = $this->thread( array( 'title' => 'switch panel', 'model_alias' => 'xtb.slow', 'origin' => expAiThread::ORIGIN_PANEL, 'node_id' => 2,
                                        'scope' => array( 'nodes' => array( 2 ) ) ) );
        $thread->setState( 'running' );
        $thread->store();
        $old = $thread->conversation();
        $old->setAttribute( 'model_alias', 'xtb.slow' );
        $old->setHistory( array( array( 'user' => 'First question', 'reply' => 'First answer', 'steps' => array(), 'drafts' => array(), 'time' => time() - 60 ),
                                 array( 'user' => 'Second question', 'reply' => '', 'steps' => array(), 'drafts' => array(), 'time' => time() ) ) );
        $old->setMessages( array( array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => 'First question' ) ) ),
                                  array( 'role' => 'assistant', 'content' => array( array( 'type' => 'text', 'text' => 'First answer' ) ) ),
                                  array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => 'Second question' ) ) ) ) );
        $old->setScopeNote( 'Only node 2.' );
        $old->store();
        $oldRunID = (int) $thread->attribute( 'lead_run_id' );
        $this->ini( 'VectorSettings', 'Background', 'always' );
        $this->inTransaction();
        $out = expAiSlowModel::switchTo( $thread );
        $this->assertSame( 'xtb.stub', $out['alias'] );
        $this->assertSame( 'xtb.slow', $out['from'] );
        $this->assertTrue( $out['pending'] );
        $this->assertTrue( $out['background'], 'queued again on the new model' );
        $this->assertTrue( expAiThread::isUuid( $out['job'] ) );
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertNotSame( $oldRunID, (int) $fresh->attribute( 'lead_run_id' ) );
        $this->assertSame( 'stopped', expAiThreadRun::fetchByID( $oldRunID )->attribute( 'state' ) );
        $this->assertSame( 'xtb.stub', $fresh->attribute( 'model_alias' ) );
        $new = $fresh->conversation();
        $this->assertSame( 'Only node 2.', $new->scopeNote() );
        $this->assertCount( 2, $new->history() );
        $this->assertTrue( $new->awaitsAnswer() );
        $this->assertStringContainsString( 'Second question', json_encode( $new->messages() ) );
        $this->assertSame( 'xtb.stub', eZPreferences::value( 'expai_model' ), 'the Intelligence tab runs on the new model' );
        $this->assertSame( 'queued', $fresh->attribute( 'state' ) );
    }

    private static function message( callable $fn )
    {
        try
        {
            $fn();
        }
        catch ( expAiException $e )
        {
            return strtr( $e->getMessage(), $e->arguments() );
        }
        return '';
    }
}
