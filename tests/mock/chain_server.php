<?php
/**
 * A mock of the Anthropic Messages API for the chain tests, as a router of PHP's built-in web server (no
 * Exponential bootstrap):
 *
 *   EXPAI_MOCK_STATE=<dir> php -S 127.0.0.1:18361 extension/expai/tests/mock/chain_server.php
 *
 *   POST /anthropic/v1/messages   answers the structured request of a chain step with scripted JSON:
 *     "Write" / "Revise"          the fields of the schema (output_config.format.schema properties.fields); a revision
 *                                 marks its body with REVISED; a brief containing [badlink] puts a link without a host
 *                                 into the body
 *     "Review"                    score 55 with a high issue for a text without REVISED, score 90 and every checklist
 *                                 item met for a revised text; a brief containing [neverpass] always fails
 *     "SEO and QA"                short_title, meta title/description/keywords, no issues
 *   Every request is appended to <state>/requests.jsonl (without the key). The step is the label the system prompt
 *   names: 'one step ("<label>") of an editorial pipeline'.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$state = getenv( 'EXPAI_MOCK_STATE' ) ?: dirname( __DIR__, 4 ) . '/var/tmp/expai-chain-mock';
@mkdir( $state, 0770, true );
$raw = (string) file_get_contents( 'php://input' );
$uri = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$headers = array_change_key_case( function_exists( 'getallheaders' ) ? getallheaders() : array(), CASE_LOWER );
$body = json_decode( $raw, true );
$body = is_array( $body ) ? $body : array();

function out( $status, $data )
{
    http_response_code( $status );
    header( 'Content-Type: application/json' );
    header( 'anthropic-ratelimit-requests-remaining: 49' );
    echo json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    exit;
}

if ( empty( $headers['x-api-key'] ) || $headers['x-api-key'] === 'invalid' )
    out( 401, array( 'type' => 'error', 'error' => array( 'type' => 'authentication_error', 'message' => 'invalid x-api-key' ) ) );
if ( $uri !== '/anthropic/v1/messages' || $_SERVER['REQUEST_METHOD'] !== 'POST' )
    out( 404, array( 'type' => 'error', 'error' => array( 'type' => 'not_found_error', 'message' => 'not found' ) ) );

$system = is_string( isset( $body['system'] ) ? $body['system'] : '' ) ? (string) $body['system'] : json_encode( $body['system'] );
$user = '';
foreach ( isset( $body['messages'] ) ? $body['messages'] : array() as $m )
    if ( $m['role'] === 'user' )
        $user = is_string( $m['content'] ) ? $m['content'] : json_encode( $m['content'] );
$step = preg_match( '/one step \("([^"]+)"\)/', $system, $m ) ? $m[1] : '';
file_put_contents( $state . '/requests.jsonl', json_encode( array( 'step' => $step, 'model' => isset( $body['model'] ) ? $body['model'] : '',
    'has_schema' => isset( $body['output_config']['format']['schema'] ), 'user' => mb_substr( $user, 0, 4000 ) ) ) . "\n", FILE_APPEND | LOCK_EX );

$schema = isset( $body['output_config']['format']['schema'] ) ? $body['output_config']['format']['schema'] : array();
$fieldNames = isset( $schema['properties']['fields']['properties'] ) ? array_keys( (array) $schema['properties']['fields']['properties'] ) : array();
$revised = strpos( $user, 'REVISED' ) !== false;
switch ( $step )
{
    case 'Write':
    case 'Revise':
        $fields = array();
        foreach ( $fieldNames as $name )
        {
            if ( $name === 'title' )
                $fields[$name] = 'Spring events for families';
            elseif ( $name === 'intro' )
                $fields[$name] = '<p>Our spring programme has something for every family.</p>';
            elseif ( $name === 'body' )
                $fields[$name] = '<h2>What is on</h2><p>' . ( $step === 'Revise' ? 'REVISED ' : '' ) . 'Workshops, walks and a market. See <a href="'
                               . ( strpos( $user, '[badlink]' ) !== false ? 'https://nohost/' : 'https://example.org/spring' ) . '">the programme</a>.</p>';
            else
                $fields[$name] = 'Value of ' . $name;
        }
        $answer = array( 'fields' => $fields, 'note' => $step === 'Revise' ? 'Fixed the issues.' : 'Assumed a family audience.' );
        break;
    case 'Review':
        $pass = $revised && strpos( $user, '[neverpass]' ) === false;
        $items = array();
        if ( preg_match_all( '/^- (.+)$/m', substr( $user, strpos( $user, 'checklist' ) ?: 0 ), $mm ) )
            foreach ( $mm[1] as $item )
                $items[] = array( 'item' => trim( $item ), 'ok' => $pass );
        $answer = array( 'score' => $pass ? 90 : 55, 'summary' => $pass ? 'Ready after small edits.' : 'The body is too thin.',
                         'issues' => $pass ? array( array( 'severity' => 'low', 'field' => 'title', 'text' => 'Could be more specific.' ) )
                                           : array( array( 'severity' => 'high', 'field' => 'body', 'text' => 'Say when and where the events take place.' ) ),
                         'checklist' => $items );
        break;
    case 'SEO and QA':
        $fields = array();
        foreach ( $fieldNames as $name )
            $fields[$name] = 'Spring for families';
        $answer = array( 'fields' => $fields, 'meta' => array( 'title' => 'Spring events for families', 'description' => 'Workshops, walks and a market this spring.',
                                                               'keywords' => array( 'spring', 'families', 'events' ) ), 'issues' => array() );
        break;
    default:
        $answer = array( 'error' => 'unknown step ' . $step );
}
$text = json_encode( $answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
out( 200, array( 'id' => 'msg_chain' . substr( md5( $raw . microtime() ), 0, 10 ), 'type' => 'message', 'role' => 'assistant',
                 'model' => isset( $body['model'] ) ? $body['model'] : 'mock', 'content' => array( array( 'type' => 'text', 'text' => $text ) ),
                 'stop_reason' => 'end_turn', 'stop_sequence' => null,
                 'usage' => array( 'input_tokens' => (int) ceil( strlen( $raw ) / 4 ), 'output_tokens' => (int) ceil( strlen( $text ) / 4 ),
                                   'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0, 'service_tier' => 'standard' ) ) );
