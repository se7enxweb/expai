<?php
/**
 * The batch API shapes of the batch lane (Anthropic Message Batches, OpenAI Batch, Gemini, Mistral, OpenRouter) and
 * the lane settings around them, with an HTTP stub in the providers' documented answer shapes: the edge cases the
 * mock end-to-end test does not reach (missing ids, failed uploads, error files, items that fail to parse, deletes
 * that fail, the configured endpoints). Batches exist in memory only (id 0: no queue job names them).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiBatchShapesTest extends expAiProviderTestCase
{
    private function laneConfig( $type, $api, array $provider = array(), array $lane = array() )
    {
        return $this->config( array(
            'ProviderSettings' => array( 'Providers' => array( 'xteb' ) ),
            'Provider_xteb' => $provider + array( 'Type' => $type, 'BaseURL' => 'https://batch.example/v1', 'ApiKey' => 'test-not-a-key', 'Label' => 'Batch T' ),
            'Model_xteb.m' => array( 'Provider' => 'xteb', 'Id' => 'models/m-1' ),
            'Lane_xteb.batch' => $lane + array( 'Enabled' => 'true', 'Type' => 'batch', 'BatchApi' => $api, 'PriceMultiplier' => '0.5' ),
        ) );
    }

    private function api( $type, $api, array $answers, array $provider = array(), array $lane = array() )
    {
        $config = $this->laneConfig( $type, $api, $provider, $lane );
        $http = new expAiProviderTestHttp( $answers );
        return array( expAiBatchApi::forLane( expAiLane::fetch( 'xteb', 'batch', $config ), 'xteb.m', $config, $http ), $http );
    }

    private static function batch( array $row = array() )
    {
        return new expAiBatch( $row + array( 'id' => 0, 'remote_id' => 'b1', 'output_ref' => '', 'input_ref' => '', 'state' => 'ended' ) );
    }

    private function expectDetail( callable $call, $detail )
    {
        try
        {
            $call();
            $this->fail( "no exception ($detail)" );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( $detail, $e->detail() );
        }
    }

    private static function items()
    {
        return array( 'job-1' => array( 'system' => 'sys', 'messages' => array( array( 'role' => 'user', 'content' => 'hi' ),
                                                                              array( 'role' => 'assistant', 'content_json' => '[{"type":"text","text":"yo"}]' ) ),
                                        'max_tokens' => 50, 'output_schema' => array( 'type' => 'object' ) ) );
    }

    private static function chat( $text )
    {
        return array( 'choices' => array( array( 'message' => array( 'content' => $text ), 'finish_reason' => 'stop' ) ), 'usage' => array( 'prompt_tokens' => 3, 'completion_tokens' => 1 ) );
    }

    public function testForLaneNeedsABatchLaneAndAModel(): void
    {
        $this->requireDatabase();
        $config = $this->laneConfig( 'openai_chat', 'openai' );
        $this->expectDetail( function () use ( $config ) { expAiBatchApi::forLane( expAiLane::fetch( 'xteb', 'standard', $config ), 'xteb.m', $config ); },
                             'lane xteb:standard has no known BatchApi ()' );
        $this->expectDetail( function () use ( $config ) { expAiBatchApi::forLane( expAiLane::fetch( 'xteb', 'batch', $config ), 'nothing', $config ); },
                             'no model nothing in the provider registry' );
        list( $api ) = $this->api( 'openai_chat', 'openai', array() );
        $this->assertSame( array( 'openai', 'xteb', 'models/m-1' ), array( $api->shape(), $api->providerId(), $api->modelId() ) );
        $this->assertTrue( expAiBatchApi::permanentItemError( 422, '' ) );
        $this->assertTrue( expAiBatchApi::permanentItemError( 500, 'invalid_request_error: x' ) );
        $this->assertFalse( expAiBatchApi::permanentItemError( 500, 'overloaded' ) );
    }

    public function testKeysHeadersAndErrorsOfTheBatchApis(): void
    {
        $this->requireDatabase();
        list( $api, $http ) = $this->api( 'openai_chat', 'openai', array( array( 200, '{"id":"b9","status":"completed"}' ) ),
                                          array( 'ApiKeyHeader' => 'none', 'Headers' => array( 'X-Run: xte', 'Authorization: stolen', "X-Bad: a\nb" ) ) );
        $api->status( self::batch() );
        $this->assertSame( array( 'X-Run: xte' ), $http->requests[0][1] );
        list( $api ) = $this->api( 'openai_chat', 'openai', array(), array( 'ApiKey' => '' ) );
        $this->expectDetail( function () use ( $api ) { $api->status( self::batch() ); }, 'ApiKey of provider xteb is not set' );
        list( $api ) = $this->api( 'openai_chat', 'openai', array(), array( 'ApiKeyHeader' => 'bad header' ) );
        $this->expectDetail( function () use ( $api ) { $api->status( self::batch() ); }, 'ApiKeyHeader is not a header name' );
        list( $api ) = $this->api( 'openai_chat', 'openai', array(), array( 'ApiKeyHeader' => 'api-key' ) );
        try
        {
            list( $api, $http ) = $this->api( 'openai_chat', 'openai', array( array( 429, '{"message":"slow down sk-abcdefghijkl"}', array( 'retry-after' => '9' ) ) ), array( 'ApiKeyHeader' => 'api-key' ) );
            $api->status( self::batch( array( 'remote_id' => 'b 1' ) ) );
            $this->fail( 'no error' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'provider xteb batch GET /v1/batches/b%201: HTTP 429: slow down [redacted]', $e->detail() );
            $this->assertSame( array( '%provider' => 'Batch T' ), $e->arguments() );
            $this->assertSame( array( 'retry-after' => '9' ), $e->rateLimits );
            $this->assertContains( 'api-key: test-not-a-key', $http->requests[0][1] );
        }
        list( $api ) = $this->api( 'openai_chat', 'openai', array( array( 500, '{"error":"plain text error"}' ) ) );
        $this->expectDetail( function () use ( $api ) { $api->cancel( self::batch() ); }, 'provider xteb batch POST /v1/batches/b1/cancel: HTTP 500: plain text error' );
    }

    public function testOpenAiUploadsAFileAndRemovesItWhenTheBatchIsRefused(): void
    {
        $this->requireDatabase();
        list( $api, $http ) = $this->api( 'openai_chat', 'openai', array( array( 200, '{"id":"file-1"}' ), array( 200, '{"id":"batch_1","status":"validating","expires_at":1900000000}' ) ) );
        $submitted = $api->submit( self::items() );
        $this->assertSame( array( 'remote_id' => 'batch_1', 'input_ref' => 'file-1', 'state' => 'in_progress', 'remote_status' => 'validating', 'expires_at' => 1900000000 ), $submitted );
        $this->assertStringStartsWith( 'multipart/form-data; boundary=----expai', $http->requests[0][3]['content_type'] );
        $this->assertStringContainsString( '"custom_id":"job-1","method":"POST","url":"/v1/chat/completions"', $http->requests[0][2] );
        $this->assertSame( array( 'input_file_id' => 'file-1', 'endpoint' => '/v1/chat/completions', 'completion_window' => '24h', 'metadata' => array( 'source' => 'expai' ) ), $http->json( 1 ) );

        list( $api ) = $this->api( 'openai_chat', 'openai', array( array( 200, '{}' ) ) );
        $this->expectDetail( function () use ( $api ) { $api->submit( self::items() ); }, 'no file id in the answer' );
        list( $api, $http ) = $this->api( 'openai_chat', 'openai', array( array( 200, '{"id":"file-2"}' ), array( 400, '{"error":{"message":"bad batch"}}' ), array( 200, '{}' ) ) );
        $this->expectDetail( function () use ( $api ) { $api->submit( self::items() ); }, 'provider xteb batch POST /v1/batches: HTTP 400: bad batch' );
        $this->assertSame( array( 'DELETE', 'https://batch.example/v1/files/file-2' ), array( $http->requests[2][3]['method'], $http->requests[2][0] ), 'the uploaded file is removed again' );
        list( $api ) = $this->api( 'openai_chat', 'openai', array( array( 200, '{"id":"file-3"}' ), array( 200, '{}' ) ) );
        $this->expectDetail( function () use ( $api ) { $api->submit( self::items() ); }, 'no batch id in the answer' );
    }

    public function testOpenAiResultsReadTheOutputAndTheErrorFile(): void
    {
        $this->requireDatabase();
        $output = json_encode( array( 'custom_id' => 'a', 'response' => array( 'status_code' => 200, 'body' => self::chat( 'Answer A' ) ) ) ) . "\n"
                . json_encode( array( 'custom_id' => 'b', 'response' => array( 'status_code' => 200, 'body' => array( 'nothing' => true ) ) ) ) . "\n"
                . "not json\n";
        $errors = json_encode( array( 'custom_id' => 'c', 'error' => array( 'code' => 'batch_expired', 'message' => 'This request expired' ) ) ) . "\n"
                . json_encode( array( 'custom_id' => 'd', 'response' => array( 'status_code' => 400, 'body' => array( 'error' => array( 'message' => 'bad input' ) ) ) ) ) . "\n"
                . json_encode( array( 'custom_id' => 'e', 'response' => array( 'status_code' => 0 ) ) ) . "\n";
        list( $api, $http ) = $this->api( 'openai_chat', 'openai', array( array( 200, $output ), array( 200, $errors ), array( 200, '' ), array( 404, '' ), array( 200, '' ) ) );
        $results = $api->results( self::batch( array( 'output_ref' => 'out-1,err-1', 'input_ref' => 'in-1', 'state' => 'expired' ) ) );
        $this->assertSame( 'Answer A', $results['a']['answer']['content'][0]['text'] );
        $this->assertSame( 'batch', $results['a']['answer']['service_tier'] );
        $this->assertSame( array( 'errored', 200 ), array( $results['b']['type'], $results['b']['status'] ) );
        $this->assertSame( array( 'expired', 'This request expired' ), array( $results['c']['type'], $results['c']['error'] ) );
        $this->assertSame( array( 'errored', 'bad input', 400 ), array( $results['d']['type'], $results['d']['error'], $results['d']['status'] ) );
        $this->assertSame( array( 'HTTP 0', 500 ), array( $results['e']['error'], $results['e']['status'] ) );
        $this->assertSame( 'application/binary', $http->requests[0][3]['accept'] );
        // the input file goes; a file that cannot be deleted is reported as not deleted
        $this->assertFalse( $api->delete( self::batch( array( 'output_ref' => 'out-1,err-1', 'input_ref' => 'in-1' ) ) ) );
        $this->assertSame( 'https://batch.example/v1/files/in-1', $http->requests[2][0] );
        $status = $this->api( 'openai_chat', 'openai', array( array( 200, json_encode( array( 'status' => 'failed', 'failed_at' => 1800000000, 'error_file_id' => 'err-2',
                                                                                                'request_counts' => array( 'total' => 5, 'completed' => 1, 'failed' => 3 ) ) ) ) ) )[0]
            ->status( self::batch() );
        $this->assertSame( array( 'failed', ',err-2', 1800000000, 1 ), array( $status['state'], $status['output_ref'], $status['ended'], $status['counts']['processing'] ) );
    }

    public function testGeminiInlineBatches(): void
    {
        $this->requireDatabase();
        list( $api, $http ) = $this->api( 'openai_chat', 'gemini', array( array( 200, '{"name":"batches/abc","metadata":{"state":"BATCH_STATE_PENDING"}}' ) ),
                                          array( 'Headers' => array( 'X-Trace: 1' ) ), array( 'BatchURL' => 'https://gemini.example/v1beta/' ) );
        $submitted = $api->submit( self::items() );
        $this->assertSame( array( 'batches/abc', 'in_progress', 'BATCH_STATE_PENDING' ), array( $submitted['remote_id'], $submitted['state'], $submitted['remote_status'] ) );
        $this->assertSame( 'https://gemini.example/v1beta/models/m-1:batchGenerateContent', $http->requests[0][0] );
        $this->assertSame( array( 'x-goog-api-key: test-not-a-key', 'X-Trace: 1' ), $http->requests[0][1] );
        $request = $http->json()['batch']['input_config']['requests']['requests'][0];
        $this->assertSame( 'job-1', $request['metadata']['key'] );
        $this->assertSame( array( array( 'role' => 'user', 'parts' => array( array( 'text' => 'hi' ) ) ), array( 'role' => 'model', 'parts' => array( array( 'text' => 'yo' ) ) ) ),
                           $request['request']['contents'] );
        $this->assertSame( array( 'maxOutputTokens' => 50, 'responseMimeType' => 'application/json', 'responseJsonSchema' => array( 'type' => 'object' ) ), $request['request']['generationConfig'] );

        list( $api ) = $this->api( 'openai_chat', 'gemini', array( array( 200, '{"name":"operations/x"}' ) ) );
        $this->expectDetail( function () use ( $api ) { $api->submit( self::items() ); }, 'no batch name in the answer' );
        list( $api ) = $this->api( 'openai_chat', 'gemini', array(), array( 'ApiKey' => '' ) );
        $this->expectDetail( function () use ( $api ) { $api->submit( self::items() ); }, 'ApiKey of provider xteb is not set' );

        $done = array( 'done' => true, 'metadata' => array( 'endTime' => '2026-10-12T10:00:00Z', 'batchStats' => array( 'pendingRequestCount' => 0 ) ),
                       'response' => array( 'inlinedResponses' => array( 'inlinedResponses' => array(
                           array( 'metadata' => array( 'key' => 'k1' ), 'response' => array( 'candidates' => array( array( 'content' => array( 'parts' => array(
                               array( 'text' => 'thinking', 'thought' => true ), array( 'text' => 'Final' ) ) ), 'finishReason' => 'MAX_TOKENS' ) ),
                               'usageMetadata' => array( 'promptTokenCount' => 10, 'cachedContentTokenCount' => 4, 'candidatesTokenCount' => 3, 'thoughtsTokenCount' => 2 ),
                               'modelVersion' => 'm-1-001' ) ),
                           array( 'metadata' => array( 'key' => 'k2' ), 'error' => array( 'code' => 400, 'message' => 'bad request' ) ),
                           array( 'response' => array( 'candidates' => array( array( 'finishReason' => 'SAFETY' ) ) ) ) ) ) ) );
        list( $api ) = $this->api( 'openai_chat', 'gemini', array( array( 200, json_encode( $done ) ), array( 200, json_encode( $done ) ) ) );
        $status = $api->status( self::batch( array( 'remote_id' => 'batches/abc' ) ) );
        $this->assertSame( array( 'ended', array( 'succeeded' => 2, 'errored' => 1, 'expired' => 0, 'canceled' => 0, 'processing' => 0 ), strtotime( '2026-10-12T10:00:00Z' ) ),
                           array( $status['state'], $status['counts'], $status['ended'] ) );
        $results = $api->results( self::batch( array( 'remote_id' => 'batches/abc' ) ) );
        $this->assertSame( array( 'k1', 'k2' ), array_keys( $results ), 'an item without key and without a known job is left out' );
        $answer = $results['k1']['answer'];
        $this->assertSame( array( 'Final', 'max_tokens', 'm-1-001' ), array( $answer['content'][0]['text'], $answer['stop_reason'], $answer['model'] ) );
        $this->assertSame( array( 'input' => 6, 'output' => 5, 'cache_write' => 0, 'cache_read' => 4, 'reasoning' => 2, 'reported_cost' => null ), $answer['tokens'] );
        $this->assertSame( array( 'errored', 'bad request', 400 ), array( $results['k2']['type'], $results['k2']['error'], $results['k2']['status'] ) );
        $this->assertSame( 'refusal', $api->answer( array( 'candidates' => array( array( 'finishReason' => 'SAFETY' ) ) ) )['stop_reason'] );
        $this->assertSame( 'other', $api->answer( array( 'candidates' => array( array( 'finishReason' => 'OTHER' ) ) ) )['stop_reason'] );

        // the states of the documentation, and an answer that only says "done"
        $state = function ( array $data ) { list( $api ) = $this->api( 'openai_chat', 'gemini', array( array( 200, json_encode( $data ) ) ) ); return $api->status( self::batch() )['state']; };
        $this->assertSame( 'canceled', $state( array( 'state' => 'JOB_STATE_CANCELLED' ) ) );
        $this->assertSame( 'expired', $state( array( 'metadata' => array( 'state' => 'BATCH_STATE_EXPIRED' ) ) ) );
        $this->assertSame( 'in_progress', $state( array() ) );
        $this->assertSame( 'ended', $state( array( 'done' => true, 'output' => array( 'inlinedResponses' => array() ) ) ) );

        list( $api, $http ) = $this->api( 'openai_chat', 'gemini', array( array( 200, '{}' ), array( 200, '{}' ) ) );
        $this->assertTrue( $api->cancel( self::batch( array( 'remote_id' => 'batches/abc' ) ) ) );
        $this->assertTrue( $api->delete( self::batch( array( 'remote_id' => 'batches/abc' ) ) ) );
        $this->assertSame( array( 'https://generativelanguage.googleapis.com/v1beta/batches/abc:cancel', 'https://generativelanguage.googleapis.com/v1beta/batches/abc' ),
                           array( $http->requests[0][0], $http->requests[1][0] ) );
    }

    public function testMistralInlineJobsAndTheirFiles(): void
    {
        $this->requireDatabase();
        list( $api, $http ) = $this->api( 'openai_chat', 'mistral', array( array( 200, '{"id":"job-9","status":"QUEUED"}' ) ), array( 'BaseURL' => 'https://mistral.example/v1/chat/completions' ) );
        $this->assertSame( 'in_progress', $api->submit( self::items() )['state'] );
        $this->assertSame( 'https://mistral.example/v1/batch/jobs', $http->requests[0][0] );
        $body = $http->json();
        $this->assertSame( array( 'models/m-1', '/v1/chat/completions', 24 ), array( $body['model'], $body['endpoint'], $body['timeout_hours'] ) );
        $this->assertArrayNotHasKey( 'model', $body['requests'][0]['body'] );

        $tooMany = array();
        for ( $i = 0; $i <= expAiBatchMistral::INLINE_MAX; $i++ )
            $tooMany['j' . $i] = array( 'system' => '', 'messages' => array() );
        list( $api ) = $this->api( 'openai_chat', 'mistral', array() );
        $this->expectDetail( function () use ( $api, $tooMany ) { $api->submit( $tooMany ); }, 'more than ' . expAiBatchMistral::INLINE_MAX . ' inline requests in one Mistral batch' );
        list( $api ) = $this->api( 'openai_chat', 'mistral', array( array( 200, '{"status":"QUEUED"}' ) ) );
        $this->expectDetail( function () use ( $api ) { $api->submit( self::items() ); }, 'no job id in the answer' );

        // no inline outputs: the output and error files are read
        $file = json_encode( array( 'custom_id' => 'a', 'response' => array( 'status_code' => 200, 'body' => self::chat( 'From the file' ) ) ) ) . "\n"
              . json_encode( array( 'custom_id' => 'b', 'response' => array( 'status_code' => 200, 'body' => array( 'x' => 1 ) ) ) ) . "\n"
              . json_encode( array( 'custom_id' => 'c', 'error' => array( 'message' => 'rate limited' ) ) ) . "\n" . json_encode( array( 'no' => 'id' ) ) . "\n";
        list( $api, $http ) = $this->api( 'openai_chat', 'mistral', array( array( 200, '{"status":"SUCCESS"}' ), array( 200, $file ), array( 200, '' ) ) );
        $results = $api->results( self::batch( array( 'output_ref' => 'f-out,f-err' ) ) );
        $this->assertSame( 'From the file', $results['a']['answer']['content'][0]['text'] );
        $this->assertSame( array( 'errored', 'errored', 'rate limited', 500 ), array( $results['b']['type'], $results['c']['type'], $results['c']['error'], $results['c']['status'] ) );
        $this->assertSame( 'https://batch.example/v1/files/f-out/content', $http->requests[1][0] );

        list( $api, $http ) = $this->api( 'openai_chat', 'mistral', array( array( 200, '{}' ), array( 500, '' ), array( 200, '{}' ), array( 200, '{}' ) ) );
        $this->assertTrue( $api->cancel( self::batch() ) );
        $this->assertTrue( $api->delete( self::batch( array( 'output_ref' => 'f-out,' ) ) ), 'a file that cannot be deleted does not stop the delete' );
        $this->assertSame( 'https://batch.example/v1/batch/jobs/b1', $http->requests[2][0] );
        $status = $this->api( 'openai_chat', 'mistral', array( array( 200, json_encode( array( 'status' => 'TIMEOUT_EXCEEDED', 'total_requests' => 4, 'succeeded_requests' => 1,
                                                                                                 'failed_requests' => 1, 'output_file' => 'o', 'completed_at' => 1700000000 ) ) ) ) )[0]
            ->status( self::batch() );
        $this->assertSame( array( 'expired', 'o,', 2, 1700000000 ), array( $status['state'], $status['output_ref'], $status['counts']['processing'], $status['ended'] ) );
    }

    public function testOpenRouterBatches(): void
    {
        $this->requireDatabase();
        list( $api ) = $this->api( 'openai_chat', 'openrouter', array( array( 200, '{}' ) ) );
        $this->expectDetail( function () use ( $api ) { $api->submit( self::items() ); }, 'no batch id in the answer' );
        $data = array( 'status' => 'completed', 'finalized_at' => 1700000001, 'request_counts' => array( 'total' => 3, 'completed' => 1, 'failed' => 1 ),
                       'results' => array( 'junk',
                           array( 'custom_id' => 'a', 'response' => array( 'status_code' => 200, 'body' => self::chat( 'OR answer' ) ) ),
                           array( 'custom_id' => 'b', 'response' => self::chat( 'bare body' ) ),
                           array( 'custom_id' => 'c', 'response' => array( 'status_code' => 200, 'body' => array( 'choices' => array( 'not an array' ) ) ) ),
                           array( 'custom_id' => 'd', 'error' => array( 'code' => 429, 'message' => 'busy' ) ) ) );
        list( $api, $http ) = $this->api( 'openai_chat', 'openrouter', array( array( 200, json_encode( $data ) ), array( 200, json_encode( $data ) ), array( 200, '{}' ), array( 200, '{}' ) ) );
        $status = $api->status( self::batch() );
        $this->assertSame( array( 'ended', 1, 1700000001 ), array( $status['state'], $status['counts']['processing'], $status['ended'] ) );
        $results = $api->results( self::batch() );
        $this->assertSame( array( 'OR answer', 'bare body' ), array( $results['a']['answer']['content'][0]['text'], $results['b']['answer']['content'][0]['text'] ) );
        $this->assertSame( 'errored', $results['c']['type'] );
        $this->assertSame( array( 'errored', 'busy', 429 ), array( $results['d']['type'], $results['d']['error'], $results['d']['status'] ) );
        $this->assertTrue( $api->cancel( self::batch() ) );
        $this->assertTrue( $api->delete( self::batch() ) );
        $this->assertSame( array( 'POST', 'DELETE' ), array( $http->requests[2][3]['method'], $http->requests[3][3]['method'] ) );
    }

    public function testAnthropicMessageBatches(): void
    {
        $this->requireDatabase();
        list( $api, $http ) = $this->api( 'anthropic', 'anthropic', array( array( 200, '{"id":"msgbatch_1","processing_status":"in_progress","expires_at":"2026-10-13T10:00:00Z"}' ) ),
                                          array( 'BaseURL' => '', 'Endpoint' => 'https://anthropic.example/v1/messages', 'AnthropicWorkspaceId' => 'ws_xte',
                                                 'RefusalFallback' => 'enabled', 'AnthropicExtras' => 'enabled' ) );
        $submitted = $api->submit( self::items() );
        $this->assertSame( array( 'msgbatch_1', 'in_progress', strtotime( '2026-10-13T10:00:00Z' ) ), array( $submitted['remote_id'], $submitted['state'], $submitted['expires_at'] ) );
        $this->assertSame( 'https://anthropic.example/v1/messages/batches', $http->requests[0][0] );
        $this->assertContains( 'anthropic-workspace-id: ws_xte', $http->requests[0][1] );
        $this->assertArrayNotHasKey( 'fallbacks', $http->json()['requests'][0]['params'], 'the refusal fallback is a beta of the synchronous API' );
        list( $api ) = $this->api( 'anthropic', 'anthropic', array( array( 200, '{}' ) ), array( 'BaseURL' => '' ) );
        $this->expectDetail( function () use ( $api ) { $api->submit( self::items() ); }, 'no batch id in the answer' );

        // the results URL of another host is never fetched
        $lines = json_encode( array( 'custom_id' => 'a', 'result' => array( 'type' => 'succeeded', 'message' => array( 'content' => array( array( 'type' => 'text', 'text' => 'A' ) ),
                    'stop_reason' => 'end_turn', 'usage' => array( 'input_tokens' => 4, 'output_tokens' => 2, 'service_tier' => 'batch' ) ) ) ) ) . "\n"
               . json_encode( array( 'custom_id' => 'b', 'result' => array( 'type' => 'errored', 'error' => array( 'type' => 'error', 'error' => array( 'type' => 'invalid_request_error', 'message' => 'bad' ) ) ) ) ) . "\n"
               . json_encode( array( 'custom_id' => 'c', 'result' => array( 'type' => 'errored', 'error' => array( 'type' => 'overloaded_error' ) ) ) ) . "\n"
               . json_encode( array( 'custom_id' => 'd', 'result' => array( 'type' => 'canceled' ) ) ) . "\n"
               . json_encode( array( 'custom_id' => 'e', 'result' => array( 'type' => 'expired' ) ) ) . "\n" . json_encode( array( 'custom_id' => 'f' ) ) . "\n";
        list( $api, $http ) = $this->api( 'anthropic', 'anthropic', array( array( 200, $lines ), array( 200, '{}' ), array( 200, '{}' ) ), array( 'BaseURL' => '' ) );
        $results = $api->results( self::batch( array( 'output_ref' => 'https://evil.example/results' ) ) );
        $this->assertSame( 'https://api.anthropic.com/v1/messages/batches/b1/results', $http->requests[0][0] );
        $this->assertSame( array( 'A', 4, 'batch' ), array( $results['a']['answer']['content'][0]['text'], $results['a']['answer']['tokens']['input'], $results['a']['answer']['service_tier'] ) );
        $this->assertSame( array( 'errored', 400, 'invalid_request_error: bad' ), array( $results['b']['type'], $results['b']['status'], $results['b']['error'] ) );
        $this->assertSame( 500, $results['c']['status'] );
        $this->assertSame( array( 'canceled', 'expired' ), array( $results['d']['type'], $results['e']['type'] ) );
        $this->assertArrayNotHasKey( 'f', $results );
        $this->assertTrue( $api->cancel( self::batch() ) );
        $this->assertTrue( $api->delete( self::batch() ) );
        $this->assertSame( array( 'https://api.anthropic.com/v1/messages/batches/b1/cancel', 'https://api.anthropic.com/v1/messages/batches/b1' ),
                           array( $http->requests[1][0], $http->requests[2][0] ) );
    }
}
