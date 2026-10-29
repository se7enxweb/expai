<?php
/**
 * expAiTurnRecord: the record of a thread's turns from its usage rows (steps, duration, tokens, result, fallback,
 * links) and "Watch this run".
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectortestcase.php';

class expAiTurnRecordTest extends expAiVectorTestCase
{
    public function testDurations(): void
    {
        $this->assertSame( '850 ms', expAiTurnRecord::duration( 850 ) );
        $this->assertSame( '0 ms', expAiTurnRecord::duration( 'x' ) );
        $this->assertSame( '4.2 s', expAiTurnRecord::duration( 4200 ) );
        $this->assertSame( '59.9 s', expAiTurnRecord::duration( 59900 ) );
        $this->assertSame( '2 min 05 s', expAiTurnRecord::duration( 125000 ) );
        $texts = expAiTurnRecord::texts();
        $this->assertSame( array( 'watch', 'log', 'job', 'vector', 'request', 'record' ), array_keys( $texts ) );
    }

    private function row( expAiThread $thread, $runID, $turn, array $values = array() )
    {
        $row = new expAiUsageLog( $values + array( 'thread_id' => (int) $thread->attribute( 'id' ), 'run_id' => $runID, 'turn' => $turn, 'provider' => 'unit',
                                                   'model' => 'unit-model', 'model_alias' => 'unit.alias', 'input_tokens' => 100, 'output_tokens' => 20,
                                                   'latency_ms' => 900, 'tool_ms' => 100, 'created' => time(), 'user_id' => 14 ) );
        $row->store();
        return $row;
    }

    public function testTheRecordOfEveryTurnWithItsResult(): void
    {
        $this->inTransaction();
        $this->asUser( 'admin' );
        $thread = $this->thread( array( 'title' => 'record' ) );
        $lead = (int) $thread->attribute( 'lead_run_id' );
        $old = new expAiThreadRun( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'kind' => 'turn', 'state' => 'stopped', 'created' => time(), 'modified' => time() ) );
        $old->store();
        $child = new expAiThreadRun( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'parent_run_id' => $lead, 'kind' => 'item', 'state' => 'done', 'created' => time(), 'modified' => time() ) );
        $child->store();
        $this->row( $thread, $lead, 1, array( 'error_class' => 'timeout' ) );
        $this->row( $thread, $lead, 2, array( 'error_class' => 'http_500' ) );
        $this->row( $thread, $lead, 3, array( 'requested_model_alias' => 'asked.model', 'fallback_reason' => 'local server stopped' ) );
        $this->row( $thread, (int) $old->attribute( 'id' ), 4 );
        $this->row( $thread, $lead, 4, array( 'cost' => 0.0125 ) );
        $this->row( $thread, (int) $old->attribute( 'id' ), 5 );
        $this->row( $thread, (int) $child->attribute( 'id' ), 6 );
        $this->row( $thread, $lead, 7, array( 'input_tokens' => 1000, 'output_tokens' => 500 ) );
        $this->row( $thread, $lead, 7 );

        $records = expAiTurnRecord::forThread( $thread, $this->config() );
        $this->assertSame( array( 1, 2, 3, 4, 5, 7 ), array_keys( $records ), 'the child run of a plan item has no record of the lead' );
        $this->assertSame( 'timeout', $records[1]['result'] );
        $this->assertSame( 'failed', $records[2]['result'] );
        $this->assertSame( 'replaced', $records[3]['result'] );
        $this->assertSame( 'asked.model', $records[3]['requested'] );
        $this->assertStringContainsString( 'local server stopped', $records[3]['fallback_text'] );
        $this->assertSame( 'replaced', $records[4]['result'], 'two runs in one turn: a switch of model' );
        $this->assertSame( 'replaced', $records[5]['result'], 'a stopped run that is not the lead was replaced' );
        $this->assertSame( 'done', $records[7]['result'] );
        $this->assertSame( 2, $records[7]['step_count'] );
        $this->assertSame( 2000, $records[7]['ms'] );
        $this->assertSame( '2 s', $records[7]['duration'] );
        $this->assertSame( 1500 + 120, $records[7]['tokens'] );
        $this->assertSame( 'expai/log/(request)/' . $records[7]['steps'][0]['id'], $records[7]['steps'][0]['log'] );
        $this->assertSame( 'expai/log/(thread)/' . (int) $thread->attribute( 'id' ), $records[7]['links']['log'] );
        $this->assertSame( 'expai/vector/(thread)/' . $thread->attribute( 'uuid' ), $records[7]['links']['vector'] );
        $this->assertStringContainsString( '2 step(s)', $records[7]['line'] );

        // the last turn while the thread works, and after it was stopped
        $thread->setAttribute( 'state', 'running' );
        $this->assertSame( 'working', expAiTurnRecord::forThread( $thread, $this->config() )[7]['result'] );
        $thread->setAttribute( 'state', 'stopped' );
        $leadRun = $thread->leadRun();
        $leadRun->setAttribute( 'state', 'stopped' );
        $leadRun->setAttribute( 'job_uuid', '11111111-2222-3333-4444-555555555555' );
        $leadRun->store();
        $last = expAiTurnRecord::forThread( $thread, $this->config() )[7];
        $this->assertSame( 'stopped', $last['result'] );
        $this->assertSame( class_exists( 'expQueue' ) ? 'expqueue/job/11111111-2222-3333-4444-555555555555' : '', $last['links']['job'], 'the administrator may view the jobs' );
    }

    public function testWatchThisRun(): void
    {
        $this->inTransaction();
        $this->asUser( 'admin' );
        $thread = $this->thread();
        $watch = expAiTurnRecord::watch( $thread );
        $this->assertSame( 'done', $watch['state'], 'an idle thread is shown as done' );
        $this->assertSame( 0, $watch['step'] );
        $thread->setAttribute( 'state', 'running' );
        $watch = expAiTurnRecord::watch( $thread );
        $this->assertSame( 'running', $watch['state'] );
        $this->assertSame( 'running step 1', $watch['label'] );
        $this->assertSame( '', $watch['links']['job'] );
        $thread->setAttribute( 'state', 'failed' );
        $this->assertSame( 'failed', expAiTurnRecord::watch( $thread )['label'] );
    }

    public function testAUserWithoutTheQueuePagesGetsNoJobLink(): void
    {
        $this->inTransaction();
        $this->asUser( 'admin' );
        $thread = $this->thread();
        $run = $thread->leadRun();
        $run->setAttribute( 'job_uuid', '11111111-2222-3333-4444-555555555555' );
        $run->store();
        $this->userWithRole( array( array( 'expai', 'write' ) ) );
        $watch = expAiTurnRecord::watch( $thread );
        $this->assertSame( '', $watch['links']['job'] );
        $this->assertSame( '', $watch['links']['vector'], 'no expai/vector: no link to Vector' );
    }
}
