<?php
/**
 * The mandatory tasks of the probe's write gate (expAiProbe::failedMandatory(), gateOpens()): the pass rate alone
 * does not open the gate, every task of [ProbeSettings] MandatoryTasks[] must have passed; and an answer with neither
 * words nor a tool call fails its task as "empty answer" (expAiProbe::run() with a scripted provider). No database,
 * no request leaves the server.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiProbeMandatoryTestProvider implements expAiProvider
{
    public $requests = array();

    public function send( array $request )
    {
        $this->requests[] = $request;
        // the model only thinks: no words, no tool call
        return array( 'content' => array( array( 'type' => 'thinking', 'thinking' => 'Let me think about it.' ) ), 'content_json' => '[]',
                      'stop_reason' => 'end_turn', 'model' => 'stub-model' );
    }

    public function modelName()
    {
        return 'stub-model';
    }

    public function providerId()
    {
        return 'xtd';
    }
}

/** the probe without the tool registry (which reads the current user's policies from the database) */
class expAiProbeMandatoryTestProbe extends expAiProbe
{
    public function toolsFor( $provider )
    {
        return array( array( 'name' => 'get_node', 'description' => 'Reads a node.', 'input_schema' => array( 'type' => 'object' ) ) );
    }
}

class expAiProbeMandatoryTest extends expAiCoreTestCase
{
    const TASKS = array( 'read_node', 'list_children', 'paging', 'class_definition', 'creatable', 'tree_context', 'create_draft', 'update_draft',
                         'no_tool', 'general_knowledge', 'two_nodes', 'german', 'use_result', 'injection', 'must_answer' );

    /** @return array the rows of a result in which the named tasks failed */
    private static function results( array $failed = array() )
    {
        $rows = array();
        foreach ( self::TASKS as $id )
            $rows[] = array( 'id' => $id, 'ok' => !in_array( $id, $failed, true ), 'reason' => in_array( $id, $failed, true ) ? 'empty answer' : '' );
        return $rows;
    }

    public function testTheDefaultMandatoryTasks(): void
    {
        $config = $this->useSettings();
        $this->assertSame( array( 'use_result', 'injection', 'create_draft', 'update_draft' ), expAiProbe::mandatoryTasks( $config ) );
        $config->ini()->removeSetting( 'ProbeSettings', 'MandatoryTasks' );
        $this->assertSame( array( 'use_result', 'injection', 'create_draft', 'update_draft' ), expAiProbe::mandatoryTasks( $config ), 'also without the setting' );
    }

    public function testFourteenOfFifteenWithUseResultFailedKeepsTheGateClosed(): void
    {
        $config = $this->useSettings();
        $results = self::results( array( 'use_result' ) );
        $rate = (int) floor( 100 * 14 / 15 );
        $this->assertSame( 93, $rate );
        $this->assertGreaterThanOrEqual( expAiProbe::threshold( $config ), $rate, 'the pass rate alone would open the gate' );
        $this->assertSame( array( 'use_result' ), expAiProbe::failedMandatory( $results, $config ) );
        $this->assertFalse( expAiProbe::gateOpens( $rate, $results, $config ) );
    }

    public function testTheGateNeedsThePassRateAndEveryMandatoryTask(): void
    {
        $config = $this->useSettings();
        $this->assertTrue( expAiProbe::gateOpens( 100, self::results(), $config ) );
        $this->assertTrue( expAiProbe::gateOpens( 93, self::results( array( 'german' ) ), $config ), 'a task that is not mandatory may fail' );
        $this->assertFalse( expAiProbe::gateOpens( 86, self::results( array( 'german', 'paging' ) ), $config ), 'below WritePassRate' );
        $this->assertSame( array( 'injection', 'update_draft' ), expAiProbe::failedMandatory( self::results( array( 'update_draft', 'injection' ) ), $config ),
                           'in the order of the setting' );
        $missing = array_values( array_filter( self::results(), function ( $row ) { return $row['id'] !== 'create_draft'; } ) );
        $this->assertSame( array( 'create_draft' ), expAiProbe::failedMandatory( $missing, $config ), 'a mandatory task missing from the result did not pass' );
    }

    public function testTheMandatoryTasksAreConfigurable(): void
    {
        $config = $this->useSettings( array( 'ProbeSettings' => array( 'MandatoryTasks' => array( 'german' ) ) ) );
        $this->assertSame( array( 'german' ), expAiProbe::mandatoryTasks( $config ) );
        $this->assertTrue( expAiProbe::gateOpens( 93, self::results( array( 'use_result' ) ), $config ) );
        $this->assertFalse( expAiProbe::gateOpens( 93, self::results( array( 'german' ) ), $config ) );

        $config = $this->useSettings( array( 'ProbeSettings' => array( 'MandatoryTasks' => array() ) ) );
        $this->assertSame( array(), expAiProbe::mandatoryTasks( $config ), 'an empty list: the pass rate alone' );
        $this->assertTrue( expAiProbe::gateOpens( 93, self::results( array( 'use_result' ) ), $config ) );
    }

    public function testAnEmptyAnswerIsItsOwnFailure(): void
    {
        $this->assertTrue( expAiProbe::isEmptyAnswer( array(), '' ) );
        $this->assertTrue( expAiProbe::isEmptyAnswer( array(), " \n" ) );
        $this->assertTrue( expAiProbe::isEmptyAnswer( array(), '<think>thoughts only</think>' ) );
        $this->assertFalse( expAiProbe::isEmptyAnswer( array(), 'ready' ) );
        $this->assertFalse( expAiProbe::isEmptyAnswer( array( array( 'id' => 't', 'name' => 'get_node', 'input' => array() ) ), '' ) );

        $config = $this->useSettings();
        $provider = new expAiProbeMandatoryTestProvider();
        $result = ( new expAiProbeMandatoryTestProbe( $config ) )->run( $provider, 2, 1, 'folder' );
        $this->assertCount( 15, $provider->requests, 'one request per task: no follow-up after an empty answer' );
        $this->assertSame( 0, $result['passed'] );
        $reasons = array_column( $result['results'], 'reason', 'id' );
        $this->assertSame( 'empty answer', $reasons['injection'], 'an empty answer is no pass, also where no call is the right call' );
        $this->assertSame( 'empty answer', $reasons['use_result'] );
        $this->assertSame( 'empty answer', $reasons['read_node'] );
        $this->assertSame( array_fill_keys( self::TASKS, 'empty answer' ), $reasons );
        $this->assertSame( array( 'use_result', 'injection', 'create_draft', 'update_draft' ), expAiProbe::failedMandatory( $result['results'], $config ) );
    }
}
