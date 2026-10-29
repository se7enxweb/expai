<?php
/**
 * The write gate (expAiProbe::writeRefusal() with the stored probe results), the per-task details of a probe result,
 * and the reachability of the providers (expAiProviderHealth) for a managed local server and a loopback server. The
 * probe rows are written in a transaction that is rolled back.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiProbeGateTest extends expAiProviderTestCase
{
    private function models( $localUrl = 'http://127.0.0.1:1' )
    {
        return $this->config( array(
            'ProviderSettings' => array( 'Providers' => array( 'xtep', 'xtepl', 'xtepr' ) ),
            'Provider_xtep' => array( 'Type' => 'openai_chat', 'BaseURL' => 'https://probe.example/v1', 'ApiKey' => 'k' ),
            'Provider_xtepl' => array( 'Type' => 'openai_chat', 'Flavor' => 'ollama', 'Managed' => 'ollama', 'BaseURL' => $localUrl . '/v1', 'KeyOptional' => 'true' ),
            'Provider_xtepr' => array( 'Type' => 'openai_chat', 'BaseURL' => 'http://example.invalid/v1', 'Managed' => 'ollama' ),
            'Model_xtep.gated' => array( 'Provider' => 'xtep', 'Id' => 'gated-1', 'ToolUse' => 'native', 'WriteTools' => 'enabled', 'WriteGate' => 'probe' ),
            'Model_xtep.trusted' => array( 'Provider' => 'xtep', 'Id' => 'trusted-1', 'ToolUse' => 'native', 'WriteTools' => 'enabled', 'WriteGate' => 'trusted' ),
            'Model_xtep.prompted' => array( 'Provider' => 'xtep', 'Id' => 'p-1', 'ToolUse' => 'prompted', 'WriteTools' => 'enabled' ),
            'Model_xtep.reader' => array( 'Provider' => 'xtep', 'Id' => 'r-1', 'ToolUse' => 'native', 'WriteTools' => 'disabled' ),
            'ProbeSettings' => array( 'WritePassRate' => 90 ),
        ) );
    }

    /** @return array the task results of a probe row: a read task and the mandatory tasks, all passed */
    private static function details()
    {
        $details = array( array( 'id' => 'read', 'ok' => true ) );
        foreach ( array( 'use_result', 'injection', 'create_draft', 'update_draft' ) as $id )
            $details[] = array( 'id' => $id, 'ok' => true );
        return $details;
    }

    private static function probeRow( $alias, $model, $rate, $mode = 'live' )
    {
        $row = new expAiProbeResult( array( 'model_alias' => $alias, 'provider' => 'xtep', 'model' => $model, 'tool_mode' => 'native', 'probe_mode' => $mode,
                                            'tasks' => 10, 'passed' => (int) ( $rate / 10 ), 'pass_rate' => $rate, 'details' => json_encode( self::details() ),
                                            'user_id' => 14, 'created' => time() ) );
        $row->store();
        return $row;
    }

    public function testTheWriteGateNamesWhyAModelMayNotWrite(): void
    {
        $this->requireDatabase();
        $this->inTransaction();
        $config = $this->models();
        $this->assertSame( 'unknown model', expAiProbe::writeRefusal( 'nothing', $config ) );
        $this->assertSame( 'the model has no native tool calling (ToolUse=prompted)', expAiProbe::writeRefusal( 'xtep.prompted', $config ) );
        $this->assertSame( 'WriteTools is not enabled for the model', expAiProbe::writeRefusal( 'xtep.reader', $config ) );
        $this->assertSame( '', expAiProbe::writeRefusal( 'xtep.trusted', $config ) );
        $this->assertStringStartsWith( 'the model has not passed the probe (bin/php/expaiprobe.php --model=xtep.gated --live)', expAiProbe::writeRefusal( 'xtep.gated', $config ) );
        $this->assertFalse( expAiProbe::writeAllowed( 'xtep.gated', $config ) );

        self::probeRow( 'xtep.gated', 'gated-0', 100 );
        $this->assertSame( 'the probe was run with another model id', expAiProbe::writeRefusal( 'xtep.gated', $config ) );
        self::probeRow( 'xtep.gated', 'gated-1', 80 );
        $this->assertSame( 'the probe pass rate 80% is below 90%', expAiProbe::writeRefusal( 'xtep.gated', $config ) );
        self::probeRow( 'xtep.gated', 'gated-1', 100, 'dry' );
        $this->assertSame( 'the probe pass rate 80% is below 90%', expAiProbe::writeRefusal( 'xtep.gated', $config ), 'only a live probe counts' );
        $passed = self::probeRow( 'xtep.gated', 'gated-1', 95 );
        $this->assertSame( '', expAiProbe::writeRefusal( 'xtep.gated', $config ) );
        $this->assertTrue( expAiProbe::writeAllowed( 'xtep.gated', $config ) );
        $this->assertSame( (int) $passed->attribute( 'id' ), (int) expAiProbe::latest( 'xtep.gated' )->attribute( 'id' ) );
        $this->assertSame( self::details(), $passed->results() );
        $passed->setAttribute( 'details', 'not json' );
        $this->assertSame( array(), $passed->results() );
    }

    public function testTheHealthOfAManagedLocalServer(): void
    {
        expAiProviderHealth::reset();
        $this->cleanup( static function () { expAiProviderHealth::reset(); } );
        $www = expAiTestMockServer::tmp( 'xte-health' );
        $this->cleanup( static function () use ( $www ) { expAiTestMockServer::removeTree( $www ); } );
        mkdir( $www . '/up/api', 0775, true );
        file_put_contents( $www . '/up/api/version', '{"version":"1"}' );
        $server = expAiTestMockServer::start( '', $www );

        $this->assertSame( expAiProviderHealth::OK, expAiProviderHealth::state( $this->models( $server['url'] . '/up' ), 'xtepl' ) );
        $this->assertSame( expAiProviderHealth::STOPPED, expAiProviderHealth::state( $this->models( $server['url'] . '/down' ), 'xtepl' ), 'a 404: no model server there' );
        $probe = stream_socket_server( 'tcp://127.0.0.1:0' );
        $port = (int) substr( strrchr( stream_socket_get_name( $probe, false ), ':' ), 1 );
        fclose( $probe );
        $closed = $this->models( 'http://127.0.0.1:' . $port );
        $this->assertSame( expAiProviderHealth::STOPPED, expAiProviderHealth::state( $closed, 'xtepl' ) );
        $this->assertSame( expAiProviderHealth::OK, expAiProviderHealth::state( $closed, 'xtepr' ), 'a managed server that is not on this machine is not checked' );
        $this->assertSame( expAiProviderHealth::OK, expAiProviderHealth::state( $closed, 'nothing' ) );

        expAiProviderHealth::remember( $closed, 'xtepl', expAiProviderHealth::OK );
        $this->assertSame( expAiProviderHealth::OK, expAiProviderHealth::state( $this->models( 'http://127.0.0.1:' . $port ), 'xtepl' ), 'remembered for its time' );
        $this->assertSame( expAiProviderHealth::STOPPED, expAiProviderHealth::downState( array( 'Managed' => 'Ollama ' ) ) );
        $this->assertSame( expAiProviderHealth::UNREACHABLE, expAiProviderHealth::downState( array() ) );
    }
}
