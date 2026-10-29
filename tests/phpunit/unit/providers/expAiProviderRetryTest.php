<?php
/**
 * What the providers do when a request fails: which failures are retried (expAiProviderBase::post()), how long they
 * wait and the one deadline of all attempts, the retry without an optional field a server refused, the masking of
 * secrets in error texts, and an answer stream that broke off or carries an error. The HTTP client answers from
 * canned responses on a virtual clock: no network, no database, no sleeping.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiProviderRetryTest extends expAiProviderTestCase
{
    const OK = '{"choices":[{"message":{"content":"done"},"finish_reason":"stop"}]}';

    private static function openai( array $settings, expAiProviderTestHttp $http )
    {
        return new expAiOpenAiChatProvider( $settings + array( 'ApiKey' => 'test-not-a-key', 'Model' => 'm-1', 'BaseURL' => 'http://127.0.0.1:9/v1', 'ProviderId' => 'xteoai' ), $http );
    }

    private static function anthropic( array $settings, expAiProviderTestHttp $http )
    {
        return new expAiAnthropicProvider( $settings + array( 'ApiKey' => 'test-not-a-key', 'Model' => 'claude-x', 'Endpoint' => 'http://127.0.0.1:9/v1/messages' ), $http );
    }

    /** @return expAiException the failure of $call */
    private function failure( callable $call )
    {
        try
        {
            $call();
        }
        catch ( expAiException $e )
        {
            return $e;
        }
        $this->fail( 'no expAiException' );
    }

    // ------------------------------------------------------------------ HTTP statuses

    public function testTransientStatusesAreRetriedAndOthersAreNot(): void
    {
        foreach ( array( 408, 425, 429, 500, 502, 503, 504, 529 ) as $status )
        {
            $http = new expAiProviderTestHttp( array( array( $status, '{"error":{"message":"later"}}' ), array( 200, self::OK ) ) );
            $answer = self::openai( array(), $http )->send( array( 'messages' => array() ) );
            $this->assertSame( 1, $answer['retries'], "status $status" );
            $this->assertCount( 2, $http->requests, "status $status" );
        }
        foreach ( array( 400, 401, 403, 404, 413, 422, 501 ) as $status )
        {
            $http = new expAiProviderTestHttp( array( array( $status, '{"error":{"message":"no"}}' ) ) );
            $this->failure( function () use ( $http ) { self::openai( array(), $http )->send( array( 'messages' => array() ) ); } );
            $this->assertCount( 1, $http->requests, "status $status" );
            $this->assertSame( array(), $http->waits );
        }
    }

    public function testASlowServerErrorIsNotRetried(): void
    {
        foreach ( array( 408 => 0, 425 => 0, 500 => 0, 502 => 0, 504 => 0, 429 => 1, 503 => 1, 529 => 1 ) as $status => $retries )
        {
            // an attempt of 40 seconds on the virtual clock, within a timeout of 115 seconds
            $http = new class( array() ) extends expAiProviderTestHttp
            {
                public function timeout()
                {
                    return 115;
                }
            };
            $http->answers = array( function () use ( $http, $status ) { $http->clock += 40; return array( $status, '{"error":{"message":"gateway"}}' ); },
                                    array( 200, self::OK ) );
            if ( $retries )
                $this->assertSame( 1, self::openai( array(), $http )->send( array( 'messages' => array() ) )['retries'], "status $status" );
            else
                $this->assertSame( $status, $this->failure( function () use ( $http ) { self::openai( array(), $http )->send( array( 'messages' => array() ) ); } )->getCode(), "status $status" );
            $this->assertCount( 1 + $retries, $http->requests, "status $status" );
        }
        // a quick gateway timeout is retried
        $http = new class( array() ) extends expAiProviderTestHttp
        {
            public function timeout()
            {
                return 115;
            }
        };
        $http->answers = array( function () use ( $http ) { $http->clock += 3; return array( 504, '{}' ); }, array( 200, self::OK ) );
        $this->assertSame( 1, self::openai( array(), $http )->send( array( 'messages' => array() ) )['retries'] );
    }

    public function testA429WithoutRetryAfterBacksOffOneThenTwoSecondsWithJitter(): void
    {
        $http = new expAiProviderTestHttp( array( array( 429, '{}' ), array( 429, '{}' ), array( 200, self::OK ) ) );
        $answer = self::openai( array(), $http )->send( array( 'messages' => array() ) );
        $this->assertSame( 2, $answer['retries'] );
        $this->assertCount( 2, $http->waits );
        $this->assertGreaterThanOrEqual( 1.0, $http->waits[0] );
        $this->assertLessThanOrEqual( 1.25, $http->waits[0] );
        $this->assertGreaterThanOrEqual( 2.0, $http->waits[1] );
        $this->assertLessThanOrEqual( 2.25, $http->waits[1] );
    }

    public function testAtMostTwoRetries(): void
    {
        $http = new expAiProviderTestHttp( array_fill( 0, 4, array( 503, '{"error":{"message":"overloaded"}}' ) ) );
        $e = $this->failure( function () use ( $http ) { self::openai( array(), $http )->send( array( 'messages' => array() ) ); } );
        $this->assertCount( 3, $http->requests );
        $this->assertSame( 'provider xteoai: HTTP 503: overloaded (after 2 retries)', $e->detail() );
    }

    public function testRetryAfterInSecondsAndAsAnHttpDate(): void
    {
        $http = new expAiProviderTestHttp( array( array( 429, '{}', array( 'retry-after' => '3' ) ), array( 200, self::OK ) ) );
        self::openai( array(), $http )->send( array( 'messages' => array() ) );
        $this->assertSame( array( 3.0 ), $http->waits );

        $http = new expAiProviderTestHttp();
        $http->answers = array( array( 503, '{}', array( 'retry-after' => gmdate( 'D, d M Y H:i:s', (int) $http->clock + 2 ) . ' GMT' ) ), array( 200, self::OK ) );
        $this->assertSame( 1, self::openai( array(), $http )->send( array( 'messages' => array() ) )['retries'] );
        $this->assertEqualsWithDelta( 2.0, $http->waits[0], 0.001 );

        // a date in the past: at once
        $http = new expAiProviderTestHttp();
        $http->answers = array( array( 503, '{}', array( 'retry-after' => gmdate( 'D, d M Y H:i:s', (int) $http->clock - 30 ) . ' GMT' ) ), array( 200, self::OK ) );
        self::openai( array(), $http )->send( array( 'messages' => array() ) );
        $this->assertSame( array( 0.0 ), $http->waits );
    }

    public function testALongRetryAfterIsNotWaitedFor(): void
    {
        foreach ( array( '6', '60' ) as $after )
        {
            $http = new expAiProviderTestHttp( array( array( 429, '{}', array( 'retry-after' => $after ) ) ) );
            $this->failure( function () use ( $http ) { self::openai( array( 'RetryAfterMax' => 30 ), $http )->send( array( 'messages' => array() ) ); } );
            $this->assertCount( 1, $http->requests, "retry-after $after: a single wait is at most 5 seconds" );
        }
        $http = new expAiProviderTestHttp();
        $http->answers = array( array( 429, '{}', array( 'retry-after' => gmdate( 'D, d M Y H:i:s', (int) $http->clock + 120 ) . ' GMT' ) ) );
        $this->failure( function () use ( $http ) { self::openai( array(), $http )->send( array( 'messages' => array() ) ); } );
        $this->assertCount( 1, $http->requests );
    }

    public function testRetryOnServerErrorDisabledKeepsOnlyAShortRetryAfter(): void
    {
        $settings = array( 'RetryOnServerError' => 'disabled' );
        $http = new expAiProviderTestHttp( array( array( 500, '{}' ) ) );
        $this->failure( function () use ( $http, $settings ) { self::openai( $settings, $http )->send( array( 'messages' => array() ) ); } );
        $this->assertCount( 1, $http->requests );

        $http = new expAiProviderTestHttp( array( new expAiException( 'x', 'curl error 7' ) ) );
        $this->failure( function () use ( $http, $settings ) { self::openai( $settings, $http )->send( array( 'messages' => array() ) ); } );
        $this->assertCount( 1, $http->requests );

        $http = new expAiProviderTestHttp( array( array( 503, '{}', array( 'retry-after' => '1' ) ), array( 200, self::OK ) ) );
        $this->assertSame( 1, self::openai( $settings, $http )->send( array( 'messages' => array() ) )['retries'] );
    }

    // ------------------------------------------------------------------ transport errors

    public function testOnlyAConnectionThatWasNeverEstablishedIsRetried(): void
    {
        foreach ( array( 6, 7 ) as $errno )
        {
            $http = new expAiProviderTestHttp( array( new expAiException( 'x', 'curl error ' . $errno ), array( 200, self::OK ) ) );
            $this->assertSame( 1, self::openai( array(), $http )->send( array( 'messages' => array() ) )['retries'], "errno $errno" );
        }
        // a timeout, a broken-off answer: the model may have worked (and billed) already
        foreach ( array( 'curl error 28', 'curl error 18', 'curl error 52', 'curl error 56', 'timeout after 5 s, no answer' ) as $detail )
        {
            $http = new expAiProviderTestHttp( array( new expAiException( 'x', $detail ), array( 200, self::OK ) ) );
            $e = $this->failure( function () use ( $http ) { self::openai( array(), $http )->send( array( 'messages' => array() ) ); } );
            $this->assertSame( 'provider xteoai: ' . $detail, $e->detail() );
            $this->assertCount( 1, $http->requests, $detail );
        }
        // another error: retried only when the client reports that no connection was made
        $http = new expAiProviderTestHttp( array( new expAiException( 'x', 'curl error 35' ), array( 200, self::OK ) ) );
        $http->transfer = array( 'errno' => 35, 'connect_time' => 0.0, 'status' => 0 );
        $this->assertSame( 1, self::openai( array(), $http )->send( array( 'messages' => array() ) )['retries'] );
        $http = new expAiProviderTestHttp( array( new expAiException( 'x', 'curl error 35' ), array( 200, self::OK ) ) );
        $http->transfer = array( 'errno' => 35, 'connect_time' => 0.12, 'status' => 0 );
        $this->failure( function () use ( $http ) { self::openai( array(), $http )->send( array( 'messages' => array() ) ); } );
        $this->assertCount( 1, $http->requests );
        $http = new expAiProviderTestHttp( array( new expAiException( 'x', 'curl error 35' ), array( 200, self::OK ) ) );
        $this->failure( function () use ( $http ) { self::openai( array(), $http )->send( array( 'messages' => array() ) ); } );
        $this->assertCount( 1, $http->requests, 'without a connect time the error is not retried' );
    }

    public function testTheHttpClientReportsTheLastTransfer(): void
    {
        $http = new expAiHttpClient( 5, 2 );
        $this->assertSame( array( 'errno' => 0, 'connect_time' => null, 'status' => 0 ), $http->lastTransfer() );
        $start = microtime( true );
        $this->assertGreaterThanOrEqual( $start, $http->now() );
        $http->pause( 0 );
    }

    // ------------------------------------------------------------------ the deadline

    public function testAllAttemptsShareOneDeadline(): void
    {
        // the client's timeout is 5 s: a first attempt of 4 s leaves no room for a wait and another attempt
        $http = new expAiProviderTestHttp();
        $http->answers = array( function () use ( $http ) { $http->clock += 4; return array( 503, '{}' ); }, array( 200, self::OK ) );
        $this->failure( function () use ( $http ) { self::openai( array(), $http )->send( array( 'messages' => array() ) ); } );
        $this->assertCount( 1, $http->requests );

        // attempts of 1 s: one retry fits, the second does not; the retry gets the time that is left as its timeout
        $http = new expAiProviderTestHttp();
        $start = $http->clock;
        $slow = function () use ( $http ) { $http->clock += 1; return array( 503, '{}' ); };
        $http->answers = array( $slow, $slow, array( 200, self::OK ) );
        $e = $this->failure( function () use ( $http ) { self::openai( array(), $http )->send( array( 'messages' => array() ) ); } );
        $this->assertCount( 2, $http->requests );
        $this->assertStringEndsWith( '(after 1 retry)', $e->detail() );
        $this->assertSame( array(), $http->requests[0][3], 'the first attempt has the full timeout' );
        $this->assertSame( 2, $http->requests[1][3]['timeout'] );
        $this->assertLessThanOrEqual( $start + 5, $http->clock );
    }

    public function testAFollowUpRequestSharesTheDeadline(): void
    {
        $refused = function ( $took ) use ( &$http )
        {
            return function () use ( &$http, $took )
            {
                $http->clock += $took;
                return array( 400, json_encode( array( 'error' => array( 'message' => 'Unrecognized request argument supplied: reasoning_effort',
                                                                          'type' => 'invalid_request_error', 'param' => 'reasoning_effort' ) ) ) );
            };
        };
        $settings = array( 'Capabilities' => array( 'reasoning_effort' ), 'Effort' => 'low' );
        // the client's timeout is 5 s: a refusal after 2 s leaves 3 s for the request without the field
        $http = new expAiProviderTestHttp();
        $start = $http->clock;
        $http->answers = array( $refused( 2 ), array( 200, self::OK ) );
        $this->assertSame( 1, self::openai( $settings, $http )->send( array( 'messages' => array() ) )['retries'] );
        $this->assertSame( 3, $http->requests[1][3]['timeout'], 'the follow-up gets the time that is left, not a timeout of its own' );
        $this->assertLessThanOrEqual( $start + 5, $http->clock );

        // a refusal after 4.5 s leaves no room: the 400 stands, no second request
        $http = new expAiProviderTestHttp();
        $http->answers = array( $refused( 4.5 ), array( 200, self::OK ) );
        $this->assertSame( 400, $this->failure( function () use ( $http, $settings ) { self::openai( $settings, $http )->send( array( 'messages' => array() ) ); } )->getCode() );
        $this->assertCount( 1, $http->requests );
    }

    // ------------------------------------------------------------------ Anthropic

    public function testAnthropicRetriesTransientFailuresAndNamesTheProvider(): void
    {
        $ok = array( 200, json_encode( array( 'content' => array( array( 'type' => 'text', 'text' => 'hi' ) ), 'stop_reason' => 'end_turn', 'model' => 'claude-x' ) ) );
        $http = new expAiProviderTestHttp( array( array( 529, '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}' ), $ok ) );
        $answer = self::anthropic( array(), $http )->send( array( 'system' => '', 'messages' => array() ) );
        $this->assertSame( 1, $answer['retries'] );

        $http = new expAiProviderTestHttp( array( new expAiException( 'x', 'curl error 7' ), $ok ) );
        $this->assertSame( 1, self::anthropic( array(), $http )->send( array( 'system' => '', 'messages' => array() ) )['retries'] );

        // the retry of block_binding goes through the same retries
        $http = new expAiProviderTestHttp( array(
            array( 400, json_encode( array( 'error' => array( 'type' => 'invalid_request_error', 'message' => 'thinking block_binding mismatch' ) ) ) ),
            array( 503, '{}' ), $ok ) );
        $answer = self::anthropic( array( 'AnthropicExtras' => 'enabled' ), $http )->send( array( 'system' => '', 'messages' => array() ) );
        $this->assertSame( 2, $answer['retries'] );
        $this->assertCount( 3, $http->requests );
        $this->assertSame( 'drop_block', $http->json( 2 )['thinking']['block_binding']['prefix_mismatch_behavior'] );

        $http = new expAiProviderTestHttp( array( array( 401, '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key sk-ant-abcdefghij"}}' ) ) );
        $e = $this->failure( function () use ( $http ) { self::anthropic( array( 'Label' => 'Claude API' ), $http )->send( array( 'system' => '', 'messages' => array() ) ); } );
        $this->assertSame( 'provider anthropic: HTTP 401 authentication_error: invalid x-api-key [redacted]', $e->detail() );
        $this->assertSame( 'Claude API refused the request (access). Please tell the administrator.', strtr( $e->getMessage(), $e->arguments() ) );
    }

    // ------------------------------------------------------------------ 400 for an optional field

    public function testA400ForReasoningEffortIsRetriedWithoutIt(): void
    {
        $refused = array( 400, json_encode( array( 'error' => array( 'message' => 'Unrecognized request argument supplied: reasoning_effort',
                                                                      'type' => 'invalid_request_error', 'param' => 'reasoning_effort' ) ) ) );
        $http = new expAiProviderTestHttp( array( $refused, array( 200, self::OK ), array( 200, self::OK ) ) );
        $provider = self::openai( array( 'Capabilities' => array( 'reasoning_effort' ), 'Effort' => 'low' ), $http );
        $answer = $provider->send( array( 'messages' => array() ) );
        $this->assertSame( 'done', $answer['content'][0]['text'] );
        $this->assertSame( 1, $answer['retries'] );
        $this->assertSame( 'low', $http->json( 0 )['reasoning_effort'] );
        $this->assertArrayNotHasKey( 'reasoning_effort', $http->json( 1 ) );
        // remembered for the rest of the request
        $this->assertSame( 0, $provider->send( array( 'messages' => array() ) )['retries'] );
        $this->assertArrayNotHasKey( 'reasoning_effort', $http->json( 2 ) );

        // OpenRouter's reasoning object
        $http = new expAiProviderTestHttp( array( array( 400, '{"error":{"message":"Unknown parameter: reasoning"}}' ), array( 200, self::OK ) ) );
        self::openai( array( 'Flavor' => 'openrouter', 'Capabilities' => array( 'reasoning_effort' ), 'Effort' => 'high' ), $http )->send( array( 'messages' => array() ) );
        $this->assertArrayHasKey( 'reasoning', $http->json( 0 ) );
        $this->assertArrayNotHasKey( 'reasoning', $http->json( 1 ) );
    }

    public function testAnotherA400IsNotRetried(): void
    {
        $http = new expAiProviderTestHttp( array( array( 400, '{"error":{"message":"messages: too many"}}' ), array( 200, self::OK ) ) );
        $e = $this->failure( function () use ( $http ) { self::openai( array( 'Capabilities' => array( 'reasoning_effort' ), 'Effort' => 'low' ), $http )->send( array( 'messages' => array() ) ); } );
        $this->assertCount( 1, $http->requests );
        $this->assertSame( 400, $e->getCode() );
        // a field that is not in the request is not the reason
        $http = new expAiProviderTestHttp( array( array( 400, '{"error":{"message":"unsupported parameter reasoning_effort"}}' ) ) );
        $this->failure( function () use ( $http ) { self::openai( array(), $http )->send( array( 'messages' => array() ) ); } );
        $this->assertCount( 1, $http->requests );
    }

    public function testOnlyARefusedFieldIsDroppedNotARefusedValueOrAMention(): void
    {
        $provider = self::openai( array(), new expAiProviderTestHttp() );
        $payload = array( 'model' => 'm-1', 'reasoning_effort' => 'low', 'stream_options' => array( 'include_usage' => true ) );
        $dropped = array(
            '{"error":{"message":"Unsupported parameter: \'reasoning_effort\' is not supported with this model.","type":"invalid_request_error","param":"reasoning_effort","code":"unsupported_parameter"}}',
            '{"error":{"message":"Unrecognized request argument supplied: reasoning_effort"}}',
            '{"error":{"message":"bad request","param":"reasoning_effort"}}',
            '{"error":{"message":"bad request","code":"reasoning_effort"}}',
            '{"detail":[{"loc":["body","reasoning_effort"],"msg":"Extra inputs are not permitted","type":"extra_forbidden"}]}',
            '{"error":"Additional properties are not allowed (\'reasoning_effort\' was unexpected)"}',
            '{"message":"unknown field reasoning_effort"}',
        );
        foreach ( $dropped as $body )
            $this->assertSame( 'reasoning_effort', $provider->unsupportedParameter( $body, $payload ), $body );
        $kept = array(
            // a refused value: the field is known, the configuration is wrong
            '{"error":{"message":"Invalid value for reasoning_effort: \'extreme\'. Supported values are: \'low\', \'medium\', and \'high\'.","type":"invalid_request_error","param":"reasoning_effort","code":"invalid_value"}}',
            '{"error":{"message":"Unsupported value: \'reasoning_effort\' does not support \'minimal\' with this model.","param":"reasoning_effort","code":"unsupported_value"}}',
            // a mention without a refusal of the field
            '{"error":{"message":"reasoning_effort requires max_tokens of at least 1000"}}',
            '{"error":{"message":"The model is unknown"}}',
        );
        foreach ( $kept as $body )
            $this->assertSame( '', $provider->unsupportedParameter( $body, $payload ), $body );

        // a refused value is sent once, and fails
        $http = new expAiProviderTestHttp( array( array( 400, $kept[0] ), array( 200, self::OK ) ) );
        $e = $this->failure( function () use ( $http ) { self::openai( array( 'Capabilities' => array( 'reasoning_effort' ), 'Effort' => 'low' ), $http )->send( array( 'messages' => array() ) ); } );
        $this->assertCount( 1, $http->requests );
        $this->assertSame( 400, $e->getCode() );
    }

    public function testAStreamedRequestIsRetriedWithoutStreamOptions(): void
    {
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n";
        $http = new expAiProviderTestHttp( array( array( 400, '{"error":{"message":"stream_options is not supported"}}' ), array( 200, $sse ) ) );
        $answer = self::openai( array( 'Streaming' => 'enabled' ), $http )->send( array( 'messages' => array(), 'stream_timing' => true ) );
        $this->assertSame( 'Hi', $answer['content'][0]['text'] );
        $this->assertSame( 1, $answer['retries'] );
        $this->assertArrayHasKey( 'stream_options', $http->json( 0 ) );
        $this->assertArrayNotHasKey( 'stream_options', $http->json( 1 ) );
        $this->assertTrue( $http->json( 1 )['stream'] );
    }

    // ------------------------------------------------------------------ masking

    public function testSecretsAreMaskedInErrorTexts(): void
    {
        $cases = array(
            'bad key=abc123&x=1' => 'bad key=[redacted]&x=1',
            'url ?api_key=abc123 failed' => 'url ?api_key=[redacted] failed',
            'token=t0k password=pw secret=s3' => 'token=[redacted] password=[redacted] secret=[redacted]',
            'header Bearer%20abcdef1234' => 'header [redacted]',
            'sent Authorization%3A%20Bearer%20abcdef' => 'sent Authorization: [redacted]',
            'sent Authorization: Bearer abcdef' => 'sent Authorization: [redacted]',
            '{"api_key":"abc","x":"y"}' => '{"api_key":"[redacted]","x":"y"}',
            '{"Authorization": "Bearer xyz", "access_token": "a\"b"}' => '{"Authorization":"[redacted]", "access_token":"[redacted]"}',
            '{"max_tokens": "100"}' => '{"max_tokens": "100"}',
            'key sk-abcdefghijk and hf_abcdefghij' => 'key [redacted] and [redacted]',
            'Bearer abc.def' => '[redacted]',
        );
        foreach ( $cases as $text => $expected )
            $this->assertSame( $expected, expAiProviderBase::maskSecrets( $text ), $text );

        $http = new expAiProviderTestHttp( array( array( 401, '{"error":{"message":"invalid token=abcdef for {\"client_secret\":\"zzz\"}"}}' ) ) );
        $e = $this->failure( function () use ( $http ) { self::openai( array(), $http )->send( array( 'messages' => array() ) ); } );
        $this->assertStringNotContainsString( 'abcdef', $e->detail() );
        $this->assertStringNotContainsString( 'zzz', $e->detail() );
    }

    // ------------------------------------------------------------------ streams

    public function testAStreamWithoutDoneAndWithoutFinishReasonEndedEarly(): void
    {
        $cut = "data: {\"choices\":[{\"delta\":{\"content\":\"Half an ans\"}}]}\n\n";
        $e = $this->failure( function () use ( $cut ) { expAiOpenAiChatProvider::assembleStream( $cut ); } );
        $this->assertSame( 'The answer stream ended early.', $e->getMessage() );

        // a finish reason or [DONE] is enough
        $this->assertSame( 'stop', expAiOpenAiChatProvider::assembleStream( "data: {\"choices\":[{\"delta\":{\"content\":\"x\"},\"finish_reason\":\"stop\"}]}\n\n" )['choices'][0]['finish_reason'] );
        $this->assertSame( 'x', expAiOpenAiChatProvider::assembleStream( "data: {\"choices\":[{\"delta\":{\"content\":\"x\"}}]}\n\ndata: [DONE]\n\n" )['choices'][0]['message']['content'] );

        $http = new expAiProviderTestHttp( array( array( 200, $cut ) ) );
        $e = $this->failure( function () use ( $http ) { self::openai( array( 'Streaming' => 'enabled' ), $http )->send( array( 'messages' => array(), 'stream_timing' => true ) ); } );
        $this->assertSame( 'provider xteoai: stream ended without data: [DONE] and without a finish_reason', $e->detail() );
    }

    public function testAnErrorInTheStreamIsRaised(): void
    {
        $this->assertSame( array( 'error' => array( 'message' => 'overloaded' ) ),
                           expAiOpenAiChatProvider::assembleStream( "event: error\ndata: {\"message\":\"overloaded\"}\n\n" ) );
        $this->assertSame( array( 'error' => array( 'message' => 'gone' ) ), expAiOpenAiChatProvider::assembleStream( "event: error\ndata: gone\n\n" ) );

        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hal\"}}]}\n\ndata: {\"error\":{\"message\":\"Upstream overloaded\",\"code\":503}}\n\n";
        $http = new expAiProviderTestHttp( array( array( 200, $sse ) ) );
        $e = $this->failure( function () use ( $http ) { self::openai( array( 'Streaming' => 'enabled', 'Label' => 'Mock AI' ), $http )->send( array( 'messages' => array(), 'stream_timing' => true ) ); } );
        $this->assertSame( 503, $e->getCode() );
        $this->assertSame( 'provider xteoai: HTTP 503: Upstream overloaded', $e->detail() );
        $this->assertSame( 'Mock AI is busy or over its limit. Please try again later.', strtr( $e->getMessage(), $e->arguments() ) );
    }
}
