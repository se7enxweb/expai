<?php
/**
 * File containing the expAiBatchAnthropic class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Anthropic Message Batches (https://platform.claude.com/docs/en/build-with-claude/batch-processing, read
 * 2026-10-11): 50% of the standard prices; up to 100,000 requests or 256 MB per batch; most batches end within an
 * hour, unfinished requests expire after 24 hours (not billed); results are available for 29 days, and the batch
 * can be deleted after it ended.
 *
 *   POST   {messages URL}/batches                  { requests: [ { custom_id, params } ] }
 *   GET    {messages URL}/batches/{id}             processing_status in_progress | canceling | ended,
 *                                                  request_counts { processing, succeeded, errored, canceled, expired },
 *                                                  results_url, expires_at, ended_at
 *   GET    results_url                             JSONL: { custom_id, result: { type: succeeded, message } |
 *                                                  { type: errored, error } | { type: canceled } | { type: expired } }
 *   POST   {messages URL}/batches/{id}/cancel
 *   DELETE {messages URL}/batches/{id}
 */
class expAiBatchAnthropic extends expAiBatchApi
{
    public function shape()
    {
        return 'anthropic';
    }

    /** @return string the batches URL */
    protected function base()
    {
        $endpoint = rtrim( trim( (string) $this->setting( 'Endpoint', $this->setting( 'BaseURL', expAiAnthropicProvider::DEFAULT_ENDPOINT ) ) ), '/' );
        return $endpoint . '/batches';
    }

    protected function headers()
    {
        $headers = array_merge( $this->keyHeaders( 'x-api-key' ),
                                array( 'anthropic-version: ' . $this->setting( 'AnthropicVersion', expAiAnthropicProvider::DEFAULT_VERSION ) ),
                                $this->extraHeaders() );
        $workspace = trim( (string) $this->setting( 'AnthropicWorkspaceId', '' ) );
        if ( $workspace !== '' && preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $workspace ) )
            $headers[] = 'anthropic-workspace-id: ' . $workspace;
        return $headers;
    }

    /** @return array the params of one request (the Messages body of the synchronous adapter, without betas) */
    public function params( array $request )
    {
        $provider = new expAiAnthropicProvider( $this->settings );
        $params = $provider->buildPayload( $request );
        // the server-side refusal fallback is a beta of the synchronous API
        unset( $params['fallbacks'], $params['stream'] );
        return $params;
    }

    public function submit( array $items )
    {
        $requests = array();
        foreach ( $items as $customID => $request )
            $requests[] = array( 'custom_id' => (string) $customID, 'params' => $this->params( $request ) );
        list( , $data ) = $this->call( 'POST', $this->base(), self::json( array( 'requests' => $requests ) ) );
        if ( !$data || empty( $data['id'] ) )
            throw new expAiException( 'The batch API of %provider reported an error.', 'no batch id in the answer', 0, array( '%provider' => $this->providerId() ) );
        return array( 'remote_id' => (string) $data['id'], 'input_ref' => '', 'state' => $this->state( $data ),
                      'remote_status' => (string) ( isset( $data['processing_status'] ) ? $data['processing_status'] : '' ),
                      'expires_at' => self::time( isset( $data['expires_at'] ) ? $data['expires_at'] : 0 ) );
    }

    /** @return string the normalised state */
    protected function state( array $data )
    {
        $status = isset( $data['processing_status'] ) ? (string) $data['processing_status'] : '';
        return $status === 'ended' ? 'ended' : ( $status === 'canceling' ? 'canceling' : 'in_progress' );
    }

    public function status( expAiBatch $batch )
    {
        list( , $data ) = $this->call( 'GET', $this->base() . '/' . rawurlencode( $batch->attribute( 'remote_id' ) ) );
        $data = $data ?: array();
        $c = isset( $data['request_counts'] ) && is_array( $data['request_counts'] ) ? $data['request_counts'] : array();
        $n = function ( $k ) use ( $c ) { return isset( $c[$k] ) ? (int) $c[$k] : 0; };
        return array( 'state' => $this->state( $data ), 'remote_status' => (string) ( isset( $data['processing_status'] ) ? $data['processing_status'] : '' ),
                      'counts' => array( 'succeeded' => $n( 'succeeded' ), 'errored' => $n( 'errored' ), 'expired' => $n( 'expired' ),
                                         'canceled' => $n( 'canceled' ), 'processing' => $n( 'processing' ) ),
                      'output_ref' => isset( $data['results_url'] ) && is_string( $data['results_url'] ) ? $data['results_url'] : '',
                      'expires_at' => self::time( isset( $data['expires_at'] ) ? $data['expires_at'] : 0 ),
                      'ended' => self::time( isset( $data['ended_at'] ) ? $data['ended_at'] : 0 ) );
    }

    public function results( expAiBatch $batch )
    {
        $url = (string) $batch->attribute( 'output_ref' );
        // only the provider's own host is fetched (the URL comes from its answer)
        if ( $url === '' || parse_url( $url, PHP_URL_HOST ) !== parse_url( $this->base(), PHP_URL_HOST ) )
            $url = $this->base() . '/' . rawurlencode( $batch->attribute( 'remote_id' ) ) . '/results';
        list( , , $raw ) = $this->call( 'GET', $url, '', 'application/json', 'application/binary' );
        $out = array();
        foreach ( self::jsonLines( $raw ) as $line )
        {
            if ( !isset( $line['custom_id'] ) || !isset( $line['result']['type'] ) )
                continue;
            $result = $line['result'];
            $id = (string) $line['custom_id'];
            if ( $result['type'] === 'succeeded' && isset( $result['message'] ) && is_array( $result['message'] ) )
                $out[$id] = array( 'type' => 'succeeded', 'answer' => $this->answer( $result['message'] ), 'error' => '', 'status' => 200 );
            elseif ( $result['type'] === 'errored' )
            {
                $error = isset( $result['error'] ) ? $result['error'] : array();
                $type = isset( $error['error']['type'] ) ? (string) $error['error']['type'] : ( isset( $error['type'] ) ? (string) $error['type'] : '' );
                $out[$id] = array( 'type' => 'errored', 'answer' => null, 'error' => trim( $type . ': ' . self::itemError( $error ), ': ' ),
                                   'status' => $type === 'invalid_request_error' ? 400 : 500 );
            }
            else
                $out[$id] = array( 'type' => $result['type'] === 'expired' ? 'expired' : 'canceled', 'answer' => null, 'error' => '', 'status' => 0 );
        }
        return $out;
    }

    /** @return array the expai answer of a Messages response */
    public function answer( array $message )
    {
        $usage = isset( $message['usage'] ) && is_array( $message['usage'] ) ? $message['usage'] : array();
        $content = isset( $message['content'] ) && is_array( $message['content'] ) ? $message['content'] : array();
        return array(
            'content' => $content,
            'content_json' => json_encode( $content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'stop_reason' => isset( $message['stop_reason'] ) ? (string) $message['stop_reason'] : '',
            'model' => isset( $message['model'] ) ? (string) $message['model'] : $this->modelId(),
            'usage' => $usage,
            'tokens' => array( 'input' => isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0,
                               'output' => isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0,
                               'cache_write' => isset( $usage['cache_creation_input_tokens'] ) ? (int) $usage['cache_creation_input_tokens'] : 0,
                               'cache_read' => isset( $usage['cache_read_input_tokens'] ) ? (int) $usage['cache_read_input_tokens'] : 0,
                               'reasoning' => 0, 'reported_cost' => null ),
            'service_tier' => isset( $usage['service_tier'] ) && is_string( $usage['service_tier'] ) ? $usage['service_tier'] : 'batch',
            'provider' => $this->providerId(),
            'http_status' => 200,
        );
    }

    public function cancel( expAiBatch $batch )
    {
        $this->call( 'POST', $this->base() . '/' . rawurlencode( $batch->attribute( 'remote_id' ) ) . '/cancel', '{}' );
        return true;
    }

    public function delete( expAiBatch $batch )
    {
        $this->call( 'DELETE', $this->base() . '/' . rawurlencode( $batch->attribute( 'remote_id' ) ) );
        return true;
    }
}
