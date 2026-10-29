<?php
/**
 * Mock servers of the lanes and batch APIs, as a router of PHP's built-in web server (no Exponential bootstrap):
 *
 *   EXPAI_MOCK_STATE=<dir> php -S 127.0.0.1:18359 extension/expai/tests/mock/batch_server.php
 *
 * Synchronous lanes:
 *   POST /anthropic/v1/messages                 Messages; speed=fast is answered with usage.speed fast (a model id
 *                                               containing "fastbusy" gets 429 with retry-after 0 instead);
 *                                               usage.service_tier standard; anthropic-ratelimit-* headers
 *   POST /openai/v1/chat/completions            Chat Completions; service_tier flex is echoed (a model containing
 *                                               "flexbusy" gets 429 "Resource Unavailable", as the flex guide says)
 * Batch APIs (shapes of the providers' documentation read 2026-10-11, see classes/lanes/expaibatch*.php):
 *   /anthropic/v1/messages/batches[/{id}[/results|/cancel]]   POST create, GET, GET results (JSONL), POST cancel, DELETE
 *   /openai/v1/files, /openai/v1/batches[/{id}[/cancel]], /openai/v1/files/{id}/content, DELETE /openai/v1/files/{id}
 *   /gemini/v1beta/models/{model}:batchGenerateContent, GET|DELETE /gemini/v1beta/batches/{id}, POST ...:cancel
 *   /mistral/v1/batch/jobs[/{id}[/cancel]] (GET ?inline=true has outputs), /mistral/v1/files/{id}/content
 *   /openrouter/api/v1/batches[/{id}[/cancel]]  (results in the batch object once it is completed)
 *
 * A batch is in progress on the first status request and ended on the second. Each request's outcome is chosen by
 * its last user message: "[errored]" an invalid request, "[retry]" a server error, "[expired]" expired, "[canceled]"
 * cancelled; anything else succeeds with "MOCK ANSWER: <the first 60 characters>" (or a JSON object when the request
 * had an output schema). A missing key or the key "invalid" gets 401. Every request is appended to
 * <state>/requests.jsonl without its key headers, and every batch is a JSON file in <state>/batches/.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$state = getenv( 'EXPAI_MOCK_STATE' ) ?: dirname( __DIR__, 4 ) . '/var/tmp/expai-batch-mock';
@mkdir( $state . '/batches', 0770, true );
@mkdir( $state . '/files', 0770, true );
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$query = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_QUERY );
$raw = (string) file_get_contents( 'php://input' );
$headers = array_change_key_case( function_exists( 'getallheaders' ) ? getallheaders() : array(), CASE_LOWER );

$log = $headers;
unset( $log['authorization'], $log['x-api-key'], $log['x-goog-api-key'] );
file_put_contents( $state . '/requests.jsonl', json_encode( array( 'method' => $method, 'path' => $uri, 'query' => $query, 'headers' => $log,
    'body' => strlen( $raw ) > 20000 ? substr( $raw, 0, 20000 ) : $raw ) ) . "\n", FILE_APPEND | LOCK_EX );

function out( $status, $data, array $extra = array(), $type = 'application/json' )
{
    http_response_code( $status );
    header( 'Content-Type: ' . $type );
    foreach ( $extra as $line )
        header( $line );
    echo is_string( $data ) ? $data : json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    exit;
}

function key_of( array $headers )
{
    if ( isset( $headers['x-api-key'] ) )
        return $headers['x-api-key'];
    if ( isset( $headers['x-goog-api-key'] ) )
        return $headers['x-goog-api-key'];
    if ( isset( $headers['authorization'] ) && preg_match( '/^Bearer\s+(.+)$/', $headers['authorization'], $m ) )
        return $m[1];
    return '';
}

$key = key_of( $headers );
if ( $key === '' || $key === 'invalid' )
    out( 401, array( 'type' => 'error', 'error' => array( 'type' => 'authentication_error', 'message' => 'invalid x-api-key' ) ) );

/** @return string the text of the last user message of a request in any shape */
function last_user_text( array $r )
{
    if ( isset( $r['contents'] ) )
    {
        $last = end( $r['contents'] );
        return isset( $last['parts'][0]['text'] ) ? (string) $last['parts'][0]['text'] : '';
    }
    $text = '';
    foreach ( isset( $r['messages'] ) ? $r['messages'] : array() as $m )
        if ( isset( $m['role'] ) && $m['role'] === 'user' )
            $text = is_string( $m['content'] ) ? $m['content'] : ( isset( $m['content'][0]['text'] ) ? $m['content'][0]['text'] : json_encode( $m['content'] ) );
    return (string) $text;
}

function outcome( array $r )
{
    $text = last_user_text( $r );
    foreach ( array( 'errored', 'retry', 'expired', 'canceled' ) as $kind )
        if ( strpos( $text, '[' . $kind . ']' ) !== false )
            return $kind;
    return 'succeeded';
}

function answer_text( array $r )
{
    $text = last_user_text( $r );
    $schema = isset( $r['output_config']['format'] ) || isset( $r['response_format'] ) || isset( $r['generationConfig']['responseJsonSchema'] );
    if ( $schema )
        return json_encode( array( 'ok' => true, 'echo' => mb_substr( $text, 0, 40 ) ) );
    return 'MOCK ANSWER: ' . mb_substr( $text, 0, 60 );
}

function anthropic_message( array $r, $tier = 'standard', $speed = null )
{
    $usage = array( 'input_tokens' => (int) ceil( strlen( json_encode( $r ) ) / 4 ), 'output_tokens' => 12, 'cache_creation_input_tokens' => 0,
                    'cache_read_input_tokens' => 0, 'service_tier' => $tier );
    if ( $speed )
        $usage['speed'] = $speed;
    return array( 'id' => 'msg_mock' . substr( md5( json_encode( $r ) . microtime() ), 0, 10 ), 'type' => 'message', 'role' => 'assistant',
                  'model' => isset( $r['model'] ) ? $r['model'] : 'mock', 'content' => array( array( 'type' => 'text', 'text' => answer_text( $r ) ) ),
                  'stop_reason' => 'end_turn', 'stop_sequence' => null, 'usage' => $usage );
}

function chat_completion( array $r, $tier = 'default' )
{
    return array( 'id' => 'chatcmpl-mock' . substr( md5( microtime() ), 0, 8 ), 'object' => 'chat.completion', 'created' => time(),
                  'model' => isset( $r['model'] ) ? $r['model'] : 'mock', 'service_tier' => $tier,
                  'choices' => array( array( 'index' => 0, 'message' => array( 'role' => 'assistant', 'content' => answer_text( $r ) ), 'finish_reason' => 'stop' ) ),
                  'usage' => array( 'prompt_tokens' => (int) ceil( strlen( json_encode( $r ) ) / 4 ), 'completion_tokens' => 12, 'total_tokens' => 0 ) );
}

function gemini_response( array $r )
{
    return array( 'candidates' => array( array( 'content' => array( 'role' => 'model', 'parts' => array( array( 'text' => answer_text( $r ) ) ) ), 'finishReason' => 'STOP' ) ),
                  'usageMetadata' => array( 'promptTokenCount' => (int) ceil( strlen( json_encode( $r ) ) / 4 ), 'candidatesTokenCount' => 10, 'thoughtsTokenCount' => 2,
                                            'totalTokenCount' => 0 ), 'modelVersion' => 'gemini-mock' );
}

function save_batch( $state, array $b )
{
    file_put_contents( $state . '/batches/' . preg_replace( '/[^A-Za-z0-9_-]/', '_', $b['id'] ) . '.json', json_encode( $b ) );
}

function load_batch( $state, $id )
{
    $file = $state . '/batches/' . preg_replace( '/[^A-Za-z0-9_-]/', '_', $id ) . '.json';
    if ( !is_file( $file ) )
        out( 404, array( 'error' => array( 'type' => 'not_found_error', 'message' => 'no batch ' . $id ) ) );
    return json_decode( file_get_contents( $file ), true );
}

/** One status request: the first says in progress, the second ended. */
function poll( $state, array &$b )
{
    if ( empty( $b['deleted'] ) && empty( $b['ended'] ) )
    {
        $b['polls'] = isset( $b['polls'] ) ? $b['polls'] + 1 : 1;
        if ( $b['polls'] >= 2 || !empty( $b['cancelled'] ) )
            $b['ended'] = time();
        save_batch( $state, $b );
    }
}

function counts( array $b )
{
    $c = array( 'succeeded' => 0, 'errored' => 0, 'expired' => 0, 'canceled' => 0, 'processing' => 0 );
    foreach ( $b['items'] as $item )
    {
        if ( empty( $b['ended'] ) )
            $c['processing']++;
        else
        {
            $o = !empty( $b['cancelled'] ) ? 'canceled' : outcome( $item['request'] );
            $c[$o === 'retry' ? 'errored' : $o]++;
        }
    }
    return $c;
}

$body = json_decode( $raw, true );
$body = is_array( $body ) ? $body : array();

// ---------------------------------------------------------------- Anthropic
if ( $uri === '/anthropic/v1/messages' && $method === 'POST' && strpos( (string) ( isset( $body['model'] ) ? $body['model'] : '' ), 'busy' ) !== false && empty( $body['speed'] ) && strpos( $body['model'], 'allbusy' ) !== false )
    out( 529, array( 'type' => 'error', 'error' => array( 'type' => 'overloaded_error', 'message' => 'Overloaded' ) ), array( 'retry-after: 7' ) );
if ( $uri === '/anthropic/v1/messages' && $method === 'POST' )
{
    $fast = isset( $body['speed'] ) && $body['speed'] === 'fast';
    if ( $fast && strpos( (string) $body['model'], 'fastbusy' ) !== false )
        out( 429, array( 'type' => 'error', 'error' => array( 'type' => 'rate_limit_error', 'message' => 'fast mode capacity exceeded' ) ), array( 'retry-after: 0' ) );
    out( 200, anthropic_message( $body, 'standard', $fast ? 'fast' : 'standard' ),
         array( 'anthropic-ratelimit-requests-remaining: 49', 'anthropic-ratelimit-requests-limit: 50', 'anthropic-ratelimit-requests-reset: ' . gmdate( 'Y-m-d\TH:i:s\Z', time() + 60 ) ) );
}
if ( preg_match( '~^/anthropic/v1/messages/batches(?:/([A-Za-z0-9_]+))?(?:/(results|cancel))?$~', $uri, $m ) )
{
    $id = isset( $m[1] ) ? $m[1] : '';
    $sub = isset( $m[2] ) ? $m[2] : '';
    if ( $id === '' && $method === 'POST' )
    {
        if ( empty( $body['requests'] ) )
            out( 400, array( 'type' => 'error', 'error' => array( 'type' => 'invalid_request_error', 'message' => 'requests: required' ) ) );
        $items = array();
        foreach ( $body['requests'] as $r )
        {
            if ( !isset( $r['custom_id'] ) || !preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $r['custom_id'] ) || !isset( $r['params']['model'], $r['params']['max_tokens'], $r['params']['messages'] ) )
                out( 400, array( 'type' => 'error', 'error' => array( 'type' => 'invalid_request_error', 'message' => 'invalid request ' . json_encode( array_keys( $r ) ) ) ) );
            $items[] = array( 'custom_id' => $r['custom_id'], 'request' => $r['params'] );
        }
        $b = array( 'id' => 'msgbatch_mock' . bin2hex( random_bytes( 6 ) ), 'shape' => 'anthropic', 'items' => $items, 'created' => time(), 'polls' => 0 );
        save_batch( $state, $b );
        out( 200, array( 'id' => $b['id'], 'type' => 'message_batch', 'processing_status' => 'in_progress', 'request_counts' => counts( $b ),
                         'created_at' => gmdate( 'c' ), 'expires_at' => gmdate( 'c', time() + 86400 ), 'ended_at' => null, 'results_url' => null ) );
    }
    $b = load_batch( $state, $id );
    if ( $method === 'DELETE' )
    {
        if ( empty( $b['ended'] ) )
            out( 400, array( 'type' => 'error', 'error' => array( 'type' => 'invalid_request_error', 'message' => 'a batch in progress cannot be deleted' ) ) );
        $b['deleted'] = time();
        save_batch( $state, $b );
        out( 200, array( 'id' => $b['id'], 'type' => 'message_batch_deleted' ) );
    }
    if ( !empty( $b['deleted'] ) )
        out( 404, array( 'type' => 'error', 'error' => array( 'type' => 'not_found_error', 'message' => 'deleted' ) ) );
    if ( $sub === 'cancel' )
    {
        $b['cancelled'] = true;
        save_batch( $state, $b );
        out( 200, array( 'id' => $b['id'], 'processing_status' => 'canceling', 'request_counts' => counts( $b ) ) );
    }
    if ( $sub === 'results' )
    {
        if ( empty( $b['ended'] ) )
            out( 400, array( 'type' => 'error', 'error' => array( 'type' => 'invalid_request_error', 'message' => 'not ended' ) ) );
        $lines = array();
        foreach ( $b['items'] as $item )
        {
            $o = !empty( $b['cancelled'] ) ? 'canceled' : outcome( $item['request'] );
            if ( $o === 'succeeded' )
                $result = array( 'type' => 'succeeded', 'message' => anthropic_message( $item['request'], 'batch' ) );
            elseif ( $o === 'errored' )
                $result = array( 'type' => 'errored', 'error' => array( 'type' => 'error', 'error' => array( 'type' => 'invalid_request_error', 'message' => 'mock: invalid request' ) ) );
            elseif ( $o === 'retry' )
                $result = array( 'type' => 'errored', 'error' => array( 'type' => 'error', 'error' => array( 'type' => 'api_error', 'message' => 'mock: overloaded' ) ) );
            else
                $result = array( 'type' => $o );
            $lines[] = json_encode( array( 'custom_id' => $item['custom_id'], 'result' => $result ) );
        }
        out( 200, implode( "\n", $lines ) . "\n", array(), 'application/binary' );
    }
    poll( $state, $b );
    $ended = !empty( $b['ended'] );
    out( 200, array( 'id' => $b['id'], 'type' => 'message_batch', 'processing_status' => $ended ? 'ended' : 'in_progress', 'request_counts' => counts( $b ),
                     'created_at' => gmdate( 'c', $b['created'] ), 'expires_at' => gmdate( 'c', $b['created'] + 86400 ), 'ended_at' => $ended ? gmdate( 'c', $b['ended'] ) : null,
                     'results_url' => $ended ? 'http://' . $_SERVER['HTTP_HOST'] . '/anthropic/v1/messages/batches/' . $b['id'] . '/results' : null ) );
}

// ---------------------------------------------------------------- OpenAI (and its chat endpoint for flex)
// every Chat Completions path (/openai/v1, /mistral/v1, /openrouter/api/v1, /gemini/v1beta/openai): the sync lanes
if ( preg_match( '~/chat/completions$~', $uri ) && $method === 'POST' )
{
    $flex = isset( $body['service_tier'] ) && $body['service_tier'] === 'flex';
    if ( $flex && strpos( (string) $body['model'], 'flexbusy' ) !== false )
        out( 429, array( 'error' => array( 'message' => 'Resource Unavailable', 'type' => 'resource_unavailable', 'code' => 'resource_unavailable' ) ) );
    out( 200, chat_completion( $body, $flex ? 'flex' : 'default' ), array( 'x-ratelimit-remaining-requests: 99', 'x-ratelimit-reset-requests: 1s' ) );
}
if ( $uri === '/openai/v1/files' && $method === 'POST' )
{
    if ( !isset( $_POST['purpose'] ) || $_POST['purpose'] !== 'batch' || !isset( $_FILES['file'] ) )
        out( 400, array( 'error' => array( 'message' => 'purpose=batch and file are required', 'type' => 'invalid_request_error' ) ) );
    $id = 'file-mock' . bin2hex( random_bytes( 6 ) );
    move_uploaded_file( $_FILES['file']['tmp_name'], $state . '/files/' . $id );
    out( 200, array( 'id' => $id, 'object' => 'file', 'purpose' => 'batch', 'bytes' => filesize( $state . '/files/' . $id ) ) );
}
if ( preg_match( '~^/openai/v1/files/(file-[A-Za-z0-9]+)(/content)?$~', $uri, $m ) )
{
    $file = $state . '/files/' . $m[1];
    if ( !is_file( $file ) )
        out( 404, array( 'error' => array( 'message' => 'no such file', 'type' => 'invalid_request_error' ) ) );
    if ( $method === 'DELETE' )
    {
        rename( $file, $file . '.deleted' );
        out( 200, array( 'id' => $m[1], 'object' => 'file', 'deleted' => true ) );
    }
    out( 200, file_get_contents( $file ), array(), 'application/octet-stream' );
}
if ( preg_match( '~^/openai/v1/batches(?:/(batch_[A-Za-z0-9]+))?(/cancel)?$~', $uri, $m ) )
{
    if ( empty( $m[1] ) && $method === 'POST' )
    {
        $input = isset( $body['input_file_id'] ) ? $state . '/files/' . preg_replace( '/[^A-Za-z0-9-]/', '', $body['input_file_id'] ) : '';
        if ( !$input || !is_file( $input ) || ( isset( $body['endpoint'] ) ? $body['endpoint'] : '' ) !== '/v1/chat/completions' || ( isset( $body['completion_window'] ) ? $body['completion_window'] : '' ) !== '24h' )
            out( 400, array( 'error' => array( 'message' => 'input_file_id, endpoint and completion_window 24h are required', 'type' => 'invalid_request_error' ) ) );
        $items = array();
        foreach ( preg_split( '/\n/', trim( file_get_contents( $input ) ) ) as $line )
        {
            $r = json_decode( $line, true );
            if ( !isset( $r['custom_id'], $r['method'], $r['url'], $r['body'] ) || $r['url'] !== '/v1/chat/completions' )
                out( 400, array( 'error' => array( 'message' => 'invalid line ' . substr( $line, 0, 80 ), 'type' => 'invalid_request_error' ) ) );
            $items[] = array( 'custom_id' => $r['custom_id'], 'request' => $r['body'] );
        }
        $b = array( 'id' => 'batch_mock' . bin2hex( random_bytes( 6 ) ), 'shape' => 'openai', 'items' => $items, 'created' => time(), 'polls' => 0,
                    'input_file_id' => $body['input_file_id'] );
        save_batch( $state, $b );
        out( 200, array( 'id' => $b['id'], 'object' => 'batch', 'status' => 'validating', 'input_file_id' => $b['input_file_id'],
                         'request_counts' => array( 'total' => count( $items ), 'completed' => 0, 'failed' => 0 ), 'expires_at' => time() + 86400 ) );
    }
    $b = load_batch( $state, $m[1] );
    if ( !empty( $m[2] ) )
    {
        $b['cancelled'] = true;
        save_batch( $state, $b );
        out( 200, array( 'id' => $b['id'], 'object' => 'batch', 'status' => 'cancelling' ) );
    }
    poll( $state, $b );
    $ended = !empty( $b['ended'] );
    $data = array( 'id' => $b['id'], 'object' => 'batch', 'status' => $ended ? ( !empty( $b['cancelled'] ) ? 'cancelled' : 'completed' ) : 'in_progress',
                   'input_file_id' => $b['input_file_id'], 'output_file_id' => null, 'error_file_id' => null );
    $ok = 0;
    $failed = 0;
    if ( $ended )
    {
        $outLines = array();
        $errLines = array();
        foreach ( $b['items'] as $item )
        {
            $o = !empty( $b['cancelled'] ) ? 'canceled' : outcome( $item['request'] );
            if ( $o === 'succeeded' )
            {
                $outLines[] = json_encode( array( 'id' => 'batch_req_' . bin2hex( random_bytes( 4 ) ), 'custom_id' => $item['custom_id'],
                                                  'response' => array( 'status_code' => 200, 'request_id' => 'req', 'body' => chat_completion( $item['request'], 'batch' ) ), 'error' => null ) );
                ++$ok;
            }
            elseif ( $o === 'errored' || $o === 'retry' )
            {
                $errLines[] = json_encode( array( 'id' => 'batch_req_' . bin2hex( random_bytes( 4 ) ), 'custom_id' => $item['custom_id'],
                                                  'response' => array( 'status_code' => $o === 'errored' ? 400 : 500, 'body' => array( 'error' => array( 'message' => 'mock ' . $o, 'type' => $o === 'errored' ? 'invalid_request_error' : 'server_error' ) ) ),
                                                  'error' => null ) );
                ++$failed;
            }
            // expired and cancelled requests have no line
        }
        if ( $outLines )
        {
            $data['output_file_id'] = 'file-mockout' . substr( $b['id'], 10 );
            file_put_contents( $state . '/files/' . $data['output_file_id'], implode( "\n", $outLines ) . "\n" );
        }
        if ( $errLines )
        {
            $data['error_file_id'] = 'file-mockerr' . substr( $b['id'], 10 );
            file_put_contents( $state . '/files/' . $data['error_file_id'], implode( "\n", $errLines ) . "\n" );
        }
        // a batch with expired requests ends as "expired" (its finished part is in the output file)
        foreach ( $b['items'] as $item )
            if ( outcome( $item['request'] ) === 'expired' && empty( $b['cancelled'] ) )
                $data['status'] = 'expired';
        $data['completed_at'] = $b['ended'];
    }
    $data['request_counts'] = array( 'total' => count( $b['items'] ), 'completed' => $ok, 'failed' => $failed );
    out( 200, $data );
}

// ---------------------------------------------------------------- Gemini
if ( preg_match( '~^/gemini/v1beta/models/([^/:]+):batchGenerateContent$~', $uri, $m ) && $method === 'POST' )
{
    $requests = isset( $body['batch']['input_config']['requests']['requests'] ) ? $body['batch']['input_config']['requests']['requests'] : null;
    if ( !is_array( $requests ) || !$requests )
        out( 400, array( 'error' => array( 'code' => 400, 'message' => 'batch.input_config.requests.requests is required', 'status' => 'INVALID_ARGUMENT' ) ) );
    $items = array();
    foreach ( $requests as $r )
    {
        if ( !isset( $r['request']['contents'] ) )
            out( 400, array( 'error' => array( 'code' => 400, 'message' => 'request.contents is required', 'status' => 'INVALID_ARGUMENT' ) ) );
        $items[] = array( 'custom_id' => isset( $r['metadata']['key'] ) ? $r['metadata']['key'] : '', 'request' => $r['request'] );
    }
    $b = array( 'id' => 'mock' . bin2hex( random_bytes( 6 ) ), 'shape' => 'gemini', 'items' => $items, 'created' => time(), 'polls' => 0, 'model' => $m[1] );
    save_batch( $state, $b );
    out( 200, array( 'name' => 'batches/' . $b['id'], 'metadata' => array( '@type' => 'type.googleapis.com/google.ai.generativelanguage.v1main.GenerateContentBatch',
                                                                           'model' => 'models/' . $m[1], 'state' => 'JOB_STATE_PENDING' ) ) );
}
if ( preg_match( '~^/gemini/v1beta/batches/([A-Za-z0-9_-]+)(:cancel)?$~', $uri, $m ) )
{
    $b = load_batch( $state, $m[1] );
    if ( $method === 'DELETE' )
    {
        $b['deleted'] = time();
        save_batch( $state, $b );
        out( 200, new stdClass() );
    }
    if ( !empty( $m[2] ) )
    {
        $b['cancelled'] = true;
        save_batch( $state, $b );
        out( 200, new stdClass() );
    }
    poll( $state, $b );
    $ended = !empty( $b['ended'] );
    $data = array( 'name' => 'batches/' . $b['id'], 'metadata' => array( 'state' => $ended ? ( !empty( $b['cancelled'] ) ? 'JOB_STATE_CANCELLED' : 'JOB_STATE_SUCCEEDED' ) : 'JOB_STATE_RUNNING' ) );
    if ( $ended && empty( $b['cancelled'] ) )
    {
        $data['done'] = true;
        $responses = array();
        foreach ( $b['items'] as $item )
        {
            $o = outcome( $item['request'] );
            if ( $o === 'succeeded' )
                $responses[] = array( 'response' => gemini_response( $item['request'] ), 'metadata' => array( 'key' => $item['custom_id'] ) );
            elseif ( $o === 'errored' || $o === 'retry' )
                $responses[] = array( 'error' => array( 'code' => $o === 'errored' ? 400 : 500, 'message' => 'mock ' . $o ), 'metadata' => array( 'key' => $item['custom_id'] ) );
            // expired: no entry
        }
        $data['response'] = array( '@type' => 'type.googleapis.com/google.ai.generativelanguage.v1main.GenerateContentBatchOutput',
                                   'inlinedResponses' => array( 'inlinedResponses' => $responses ) );
    }
    out( 200, $data );
}

// ---------------------------------------------------------------- Mistral
if ( preg_match( '~^/mistral/v1/batch/jobs(?:/([A-Za-z0-9-]+))?(/cancel)?$~', $uri, $m ) )
{
    if ( empty( $m[1] ) && $method === 'POST' )
    {
        if ( empty( $body['requests'] ) || empty( $body['model'] ) || ( isset( $body['endpoint'] ) ? $body['endpoint'] : '' ) !== '/v1/chat/completions' )
            out( 422, array( 'object' => 'error', 'message' => 'requests, model and endpoint are required', 'type' => 'invalid_request_error' ) );
        $items = array();
        foreach ( $body['requests'] as $r )
        {
            if ( !isset( $r['custom_id'], $r['body']['messages'] ) )
                out( 422, array( 'object' => 'error', 'message' => 'custom_id and body.messages are required', 'type' => 'invalid_request_error' ) );
            $items[] = array( 'custom_id' => $r['custom_id'], 'request' => $r['body'] + array( 'model' => $body['model'] ) );
        }
        $b = array( 'id' => 'mock-' . bin2hex( random_bytes( 6 ) ), 'shape' => 'mistral', 'items' => $items, 'created' => time(), 'polls' => 0 );
        save_batch( $state, $b );
        out( 200, array( 'id' => $b['id'], 'object' => 'batch', 'status' => 'QUEUED', 'model' => $body['model'], 'total_requests' => count( $items ) ) );
    }
    $b = load_batch( $state, $m[1] );
    if ( $method === 'DELETE' )
    {
        $b['deleted'] = time();
        save_batch( $state, $b );
        out( 200, array( 'id' => $b['id'], 'object' => 'batch', 'deleted' => true ) );
    }
    if ( !empty( $m[2] ) )
    {
        $b['cancelled'] = true;
        save_batch( $state, $b );
        out( 200, array( 'id' => $b['id'], 'status' => 'CANCELLATION_REQUESTED' ) );
    }
    poll( $state, $b );
    $ended = !empty( $b['ended'] );
    $data = array( 'id' => $b['id'], 'object' => 'batch', 'status' => $ended ? ( !empty( $b['cancelled'] ) ? 'CANCELLED' : 'SUCCESS' ) : 'RUNNING',
                   'total_requests' => count( $b['items'] ), 'succeeded_requests' => 0, 'failed_requests' => 0, 'output_file' => null, 'error_file' => null );
    if ( $ended && empty( $b['cancelled'] ) )
    {
        $outputs = array();
        foreach ( $b['items'] as $item )
        {
            $o = outcome( $item['request'] );
            if ( $o === 'succeeded' )
            {
                $outputs[] = array( 'id' => 'r' . bin2hex( random_bytes( 3 ) ), 'custom_id' => $item['custom_id'], 'response' => array( 'status_code' => 200, 'body' => chat_completion( $item['request'] ) ), 'error' => null );
                $data['succeeded_requests']++;
            }
            elseif ( $o === 'errored' || $o === 'retry' )
            {
                $outputs[] = array( 'id' => 'r' . bin2hex( random_bytes( 3 ) ), 'custom_id' => $item['custom_id'], 'response' => array( 'status_code' => $o === 'errored' ? 422 : 500,
                                    'body' => array( 'object' => 'error', 'message' => 'mock ' . $o ) ), 'error' => null );
                $data['failed_requests']++;
            }
        }
        if ( strpos( $query, 'inline=true' ) !== false )
            $data['outputs'] = $outputs;
        $data['output_file'] = 'out-' . $b['id'];
        file_put_contents( $state . '/files/out-' . $b['id'], implode( "\n", array_map( 'json_encode', $outputs ) ) . "\n" );
        $data['completed_at'] = $b['ended'];
    }
    out( 200, $data );
}
if ( preg_match( '~^/mistral/v1/files/([A-Za-z0-9-]+)(/content)?$~', $uri, $m ) )
{
    $file = $state . '/files/' . $m[1];
    if ( !is_file( $file ) )
        out( 404, array( 'object' => 'error', 'message' => 'no such file' ) );
    if ( $method === 'DELETE' )
    {
        rename( $file, $file . '.deleted' );
        out( 200, array( 'id' => $m[1], 'deleted' => true ) );
    }
    out( 200, file_get_contents( $file ), array(), 'application/octet-stream' );
}

// ---------------------------------------------------------------- OpenRouter
if ( preg_match( '~^/openrouter/api/v1/batches(?:/(batch_[A-Za-z0-9]+))?(/cancel)?$~', $uri, $m ) )
{
    if ( empty( $m[1] ) && $method === 'POST' )
    {
        if ( empty( $body['requests'] ) || empty( $body['model'] ) || ( isset( $body['endpoint'] ) ? $body['endpoint'] : '' ) !== '/v1/chat/completions' )
            out( 400, array( 'error' => array( 'code' => 400, 'message' => 'endpoint, model and requests are required' ) ) );
        $items = array();
        foreach ( $body['requests'] as $r )
            $items[] = array( 'custom_id' => $r['custom_id'], 'request' => $r['body'] + array( 'model' => $body['model'] ) );
        $b = array( 'id' => 'batch_ormock' . bin2hex( random_bytes( 6 ) ), 'shape' => 'openrouter', 'items' => $items, 'created' => time(), 'polls' => 0 );
        save_batch( $state, $b );
        out( 202, array( 'id' => $b['id'], 'status' => 'validating', 'request_counts' => array( 'total' => count( $items ), 'completed' => 0, 'failed' => 0 ) ) );
    }
    $b = load_batch( $state, $m[1] );
    if ( $method === 'DELETE' )
    {
        if ( empty( $b['ended'] ) )
            out( 409, array( 'error' => array( 'code' => 409, 'message' => 'only terminal batches can be deleted' ) ) );
        $b['deleted'] = time();
        save_batch( $state, $b );
        out( 200, array( 'id' => $b['id'], 'deleted' => true ) );
    }
    if ( !empty( $b['deleted'] ) )
        out( 410, array( 'error' => array( 'code' => 410, 'message' => 'gone' ) ) );
    if ( !empty( $m[2] ) )
    {
        $b['cancelled'] = true;
        save_batch( $state, $b );
        out( 200, array( 'id' => $b['id'], 'status' => 'cancelling' ) );
    }
    poll( $state, $b );
    $ended = !empty( $b['ended'] );
    $data = array( 'id' => $b['id'], 'status' => $ended ? ( !empty( $b['cancelled'] ) ? 'cancelled' : 'completed' ) : 'in_progress', 'created_at' => $b['created'],
                   'request_counts' => array( 'total' => count( $b['items'] ), 'completed' => 0, 'failed' => 0 ), 'results' => null );
    if ( $ended && empty( $b['cancelled'] ) )
    {
        $results = array();
        foreach ( $b['items'] as $item )
        {
            $o = outcome( $item['request'] );
            if ( $o === 'succeeded' )
            {
                $c = chat_completion( $item['request'] );
                $c['usage']['cost'] = 0.000021;
                $results[] = array( 'custom_id' => $item['custom_id'], 'response' => $c );
                $data['request_counts']['completed']++;
            }
            elseif ( $o === 'errored' || $o === 'retry' )
            {
                $results[] = array( 'custom_id' => $item['custom_id'], 'error' => array( 'code' => $o === 'errored' ? 400 : 502, 'message' => 'mock ' . $o ) );
                $data['request_counts']['failed']++;
            }
        }
        $data['results'] = $results;
        $data['finalized_at'] = $b['ended'];
    }
    out( 200, $data );
}

out( 404, array( 'error' => array( 'type' => 'not_found_error', 'message' => 'no mock route ' . $method . ' ' . $uri ) ) );
