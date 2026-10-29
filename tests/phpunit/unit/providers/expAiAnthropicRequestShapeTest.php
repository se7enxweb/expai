<?php
/**
 * The Anthropic request shapes that depend on the model: the server-side refusal fallback only for the models that
 * accept it, strict tool definitions where the schema allows them, the default longest answer, and the category of
 * a refusal (stop_details) in the answer. With an HTTP client that answers from canned responses: no network, no
 * database.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiAnthropicRequestShapeTest extends expAiProviderTestCase
{
    private static function provider( array $settings, ?expAiProviderTestHttp $http = null )
    {
        return new expAiAnthropicProvider( $settings + array( 'ApiKey' => 'test-not-a-key', 'Model' => 'claude-opus-5-5', 'Endpoint' => 'http://127.0.0.1:9/v1/messages',
                                                              'AnthropicExtras' => 'enabled', 'RefusalFallback' => 'enabled' ), $http ?: new expAiProviderTestHttp() );
    }

    public function testTheRefusalFallbackGoesOnlyToTheModelsThatAcceptIt(): void
    {
        foreach ( array( 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5', 'claude-fable-5-1', 'claude-sonnet-5-5-latest' ) as $model )
            $this->assertSame( 'default', self::provider( array( 'Model' => $model ) )->buildPayload( array( 'system' => '', 'messages' => array() ) )['fallbacks'] ?? null, $model );
        foreach ( array( 'claude-haiku-5-5', 'claude-haiku-4-5-20251001', 'claude-sonnet-4-6', 'some-other-model' ) as $model )
            $this->assertArrayNotHasKey( 'fallbacks', self::provider( array( 'Model' => $model ) )->buildPayload( array( 'system' => '', 'messages' => array() ) ), $model );
        // the capabilities decide for a model the list does not know, and can turn it off for one it knows
        $this->assertSame( 'default', self::provider( array( 'Model' => 'claude-future', 'Capabilities' => array( 'refusal_fallback' ) ) )
            ->buildPayload( array( 'system' => '', 'messages' => array() ) )['fallbacks'] );
        $this->assertArrayNotHasKey( 'fallbacks', self::provider( array( 'Model' => 'claude-opus-5-5', 'Capabilities' => array( 'no_refusal_fallback' ) ) )
            ->buildPayload( array( 'system' => '', 'messages' => array() ) ) );
        $this->assertArrayNotHasKey( 'fallbacks', self::provider( array( 'RefusalFallback' => 'disabled' ) )->buildPayload( array( 'system' => '', 'messages' => array() ) ) );
    }

    public function testNoFallbackHeaderForHaiku(): void
    {
        $http = new expAiProviderTestHttp( array( array( 200, json_encode( array( 'content' => array(), 'stop_reason' => 'end_turn', 'model' => 'claude-haiku-5-5' ) ) ) ) );
        self::provider( array( 'Model' => 'claude-haiku-5-5' ), $http )->send( array( 'system' => '', 'messages' => array() ) );
        foreach ( $http->requests[0][1] as $header )
            $this->assertStringStartsNotWith( 'anthropic-beta', $header );
    }

    public function testToolsGoStrictWhereTheSchemaAllows(): void
    {
        $plain = array( 'name' => 'get_node', 'description' => 'd', 'input_schema' => array( 'type' => 'object', 'properties' => array(
            'node_id' => array( 'type' => 'integer', 'description' => 'n' ), 'kind' => array( 'type' => 'string', 'enum' => array( 'a', 'b' ) ),
            'ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ) ) ) );
        $strict = expAiAnthropicProvider::strictTool( $plain );
        $this->assertTrue( $strict['strict'] );
        $this->assertFalse( $strict['input_schema']['additionalProperties'], 'the top level gets additionalProperties false' );

        // a map of fields (additionalProperties with a schema), a keyword strict mode does not know, a server tool: as they are
        $map = array( 'name' => 'update_draft', 'input_schema' => array( 'type' => 'object', 'properties' => array(
            'fields' => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'string' ) ) ), 'additionalProperties' => false ) );
        $this->assertSame( $map, expAiAnthropicProvider::strictTool( $map ) );
        $pattern = array( 'name' => 'x', 'input_schema' => array( 'type' => 'object', 'properties' => array( 'u' => array( 'type' => 'string', 'pattern' => '^a' ) ) ) );
        $this->assertSame( $pattern, expAiAnthropicProvider::strictTool( $pattern ) );
        $server = array( 'type' => 'web_search_20260209', 'name' => 'web_search' );
        $this->assertSame( $server, expAiAnthropicProvider::strictTool( $server ) );
        $open = array( 'name' => 'y', 'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => true ) );
        $this->assertSame( $open, expAiAnthropicProvider::strictTool( $open ), 'a schema that allows any field stays as it is' );

        // an empty properties list stays a JSON object
        $empty = expAiAnthropicProvider::strictTool( array( 'name' => 'z', 'input_schema' => array( 'type' => 'object', 'properties' => array() ) ) );
        $this->assertSame( '{"type":"object","properties":{},"additionalProperties":false}', json_encode( $empty['input_schema'] ) );

        $payload = self::provider( array() )->buildPayload( array( 'system' => '', 'messages' => array(), 'tools' => array( $plain, $map ) ) );
        $this->assertTrue( $payload['tools'][0]['strict'] );
        $this->assertArrayNotHasKey( 'strict', $payload['tools'][1] );
        $payload = self::provider( array( 'StrictTools' => 'disabled' ) )->buildPayload( array( 'system' => '', 'messages' => array(), 'tools' => array( $plain ) ) );
        $this->assertSame( array( $plain ), $payload['tools'] );
    }

    public function testStrictToolsStayWithinTheLimitsOfARequest(): void
    {
        $tool = function ( $name, $optional, $union = 0 )
        {
            $properties = array( 'id' => array( 'type' => 'integer' ) );
            for ( $i = 0; $i < $optional; $i++ )
                $properties['o' . $i] = array( 'type' => $i < $union ? array( 'string', 'integer' ) : 'string' );
            return array( 'name' => $name, 'input_schema' => array( 'type' => 'object', 'properties' => $properties, 'required' => array( 'id' ), 'additionalProperties' => false ) );
        };
        $strict = function ( array $tools ) { return array_map( function ( $t ) { return !empty( $t['strict'] ); }, $tools ); };
        $this->assertSame( array( true, true, false ), $strict( expAiAnthropicProvider::strictTools( array( $tool( 'a', 0 ), $tool( 'b', 0 ), $tool( 'c', 0 ) ), 2 ) ), 'at most 2 strict tools' );
        $this->assertSame( array( true, false, true ), $strict( expAiAnthropicProvider::strictTools( array( $tool( 'a', 10 ), $tool( 'b', 20 ), $tool( 'c', 14 ) ) ) ),
                           'optional parameters: 10 + 14 fit into 24, 20 more do not' );
        $this->assertSame( array( true, false ), $strict( expAiAnthropicProvider::strictTools( array( $tool( 'a', 2, 2 ), $tool( 'b', 2, 2 ) ), 20, 24, 3 ) ), 'parameters of more than one type' );
        $many = array();
        for ( $i = 0; $i < 30; $i++ )
            $many[] = $tool( 't' . $i, 0 );
        $this->assertSame( 20, count( array_filter( $strict( expAiAnthropicProvider::strictTools( $many ) ) ) ), 'the default: 20' );
        $payload = self::provider( array( 'StrictToolsMax' => 1 ) )->buildPayload( array( 'system' => '', 'messages' => array(), 'tools' => array( $tool( 'a', 0 ), $tool( 'b', 0 ) ) ) );
        $this->assertSame( array( true, false ), $strict( $payload['tools'] ) );
    }

    public function testTheDefaultLongestAnswerAndTheCategoryOfARefusal(): void
    {
        $http = new expAiProviderTestHttp( array( array( 200, json_encode( array( 'content' => array(), 'stop_reason' => 'refusal', 'model' => 'claude-opus-5-5',
            'stop_details' => array( 'type' => 'refusal', 'category' => 'cyber', 'explanation' => 'not shown' ) ) ) ) ) );
        $answer = self::provider( array(), $http )->send( array( 'system' => '', 'messages' => array() ) );
        $this->assertSame( 16000, $http->json()['max_tokens'] );
        $this->assertSame( 'cyber', $answer['stop_details']['category'] );
    }
}
