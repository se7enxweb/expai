<?php
/**
 * A router of PHP's built-in web server for the queue handler tests (expAiQueueHandlersTest): a small Anthropic
 * Messages and Message Batches API whose answers the test chooses through files in its state directory
 * (EXPAI_MOCK_STATE): "mode" (ok, e400, e401, e503) and the batch's "status.json" and "results.jsonl". Every
 * request is appended to requests.jsonl. No Exponential bootstrap.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$state = (string) getenv( 'EXPAI_MOCK_STATE' );
$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$method = $_SERVER['REQUEST_METHOD'];
file_put_contents( $state . '/requests.jsonl', json_encode( array( 'method' => $method, 'path' => $path, 'body' => file_get_contents( 'php://input' ) ) ) . "\n", FILE_APPEND | LOCK_EX );
$mode = is_file( $state . '/mode' ) ? trim( (string) file_get_contents( $state . '/mode' ) ) : 'ok';

header( 'Content-Type: application/json' );
if ( $mode === 'e400' )
{
    http_response_code( 400 );
    echo '{"type":"error","error":{"type":"invalid_request_error","message":"bad request"}}';
    return true;
}
if ( $mode === 'e401' )
{
    http_response_code( 401 );
    echo '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}';
    return true;
}
if ( $mode === 'e503' )
{
    http_response_code( 503 );
    header( 'retry-after: 2' );
    header( 'anthropic-ratelimit-tokens-remaining: 77' );
    echo '{"type":"error","error":{"type":"overloaded_error","message":"overloaded"}}';
    return true;
}
if ( $method === 'POST' && preg_match( '~/v1/messages$~', $path ) )
    echo json_encode( array( 'id' => 'msg_1', 'content' => array( array( 'type' => 'text', 'text' => '{"ok":true}' ) ), 'stop_reason' => 'end_turn', 'model' => 'claude-xte',
                             'usage' => array( 'input_tokens' => 5, 'output_tokens' => 2, 'service_tier' => 'standard' ) ) );
elseif ( $method === 'POST' && preg_match( '~/v1/messages/batches$~', $path ) )
    echo '{"id":"msgbatch_xte","processing_status":"in_progress"}';
elseif ( $method === 'GET' && preg_match( '~/batches/msgbatch_xte/results$~', $path ) )
{
    header( 'Content-Type: application/binary' );
    echo is_file( $state . '/results.jsonl' ) ? file_get_contents( $state . '/results.jsonl' ) : '';
}
elseif ( $method === 'GET' && preg_match( '~/batches/msgbatch_xte$~', $path ) )
    echo is_file( $state . '/status.json' ) ? file_get_contents( $state . '/status.json' ) : '{"processing_status":"in_progress"}';
elseif ( $method === 'POST' && preg_match( '~/cancel$~', $path ) )
    echo '{"processing_status":"canceling"}';
elseif ( $method === 'DELETE' )
    echo '{}';
else
{
    http_response_code( 404 );
    echo '{"type":"error","error":{"type":"not_found_error","message":"' . $path . '"}}';
}
return true;
