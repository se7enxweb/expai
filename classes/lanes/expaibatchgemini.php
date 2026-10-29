<?php
/**
 * File containing the expAiBatchGemini class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The Gemini Batch API with inline requests (https://ai.google.dev/gemini-api/docs/batch-api, read 2026-10-11):
 * 50% of the standard prices, a 24 hour target, inline requests up to 20 MB (larger ones need a file, not
 * implemented), results kept for 6 weeks. The provider's key is sent as x-goog-api-key; the API is Gemini's native
 * one ([Lane_<provider>.batch] BatchURL, default https://generativelanguage.googleapis.com/v1beta), not the
 * OpenAI-compatible endpoint the synchronous lanes may use.
 *
 *   POST   {base}/models/{model}:batchGenerateContent
 *          { batch: { display_name, input_config: { requests: { requests: [ { request, metadata: { key } } ] } } } }
 *          -> { name: batches/<id>, metadata: { state } }
 *   GET    {base}/batches/<id>   metadata.state (or state) JOB_STATE_PENDING | JOB_STATE_RUNNING | JOB_STATE_SUCCEEDED |
 *          JOB_STATE_FAILED | JOB_STATE_CANCELLED | JOB_STATE_EXPIRED (older pages: BATCH_STATE_*);
 *          results in response.inlinedResponses (a list, or an object holding the list under inlinedResponses):
 *          { response: GenerateContentResponse, metadata: { key } } | { error: { code, message }, metadata }
 *   POST   {base}/batches/<id>:cancel
 *   DELETE {base}/batches/<id>
 */
class expAiBatchGemini extends expAiBatchApi
{
    public function shape()
    {
        return 'gemini';
    }

    /** @return string */
    protected function base()
    {
        $url = (string) $this->lane->get( 'batch_url' );
        return rtrim( $url !== '' ? $url : 'https://generativelanguage.googleapis.com/v1beta', '/' );
    }

    protected function headers()
    {
        $key = trim( (string) $this->setting( 'ApiKey', '' ) );
        if ( $key === '' || preg_match( '/[\r\n]/', $key ) )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'ApiKey of provider ' . $this->providerId() . ' is not set' );
        return array_merge( array( 'x-goog-api-key: ' . $key ), $this->extraHeaders() );
    }

    /** @return string the model id without the models/ prefix */
    protected function model()
    {
        return preg_replace( '~^models/~', '', $this->modelId() );
    }

    /** @return string text of an expai message content (a string or blocks) */
    protected static function text( $content )
    {
        if ( is_string( $content ) )
            return $content;
        $out = '';
        foreach ( (array) $content as $block )
            if ( is_array( $block ) && isset( $block['type'], $block['text'] ) && $block['type'] === 'text' )
                $out .= $block['text'];
        return $out;
    }

    /** @return array a GenerateContentRequest of an expai request */
    public function request( array $request )
    {
        $contents = array();
        foreach ( $request['messages'] as $message )
        {
            $content = isset( $message['content_json'] ) ? json_decode( $message['content_json'], true ) : $message['content'];
            $contents[] = array( 'role' => $message['role'] === 'assistant' ? 'model' : 'user', 'parts' => array( array( 'text' => self::text( $content ) ) ) );
        }
        $out = array( 'contents' => $contents );
        if ( isset( $request['system'] ) && (string) $request['system'] !== '' )
            $out['systemInstruction'] = array( 'parts' => array( array( 'text' => (string) $request['system'] ) ) );
        $config = array( 'maxOutputTokens' => (int) ( isset( $request['max_tokens'] ) ? $request['max_tokens'] : $this->setting( 'MaxTokens', 8000 ) ) );
        if ( !empty( $request['output_schema'] ) )
        {
            $config['responseMimeType'] = 'application/json';
            $config['responseJsonSchema'] = $request['output_schema'];
        }
        $out['generationConfig'] = $config;
        return $out;
    }

    public function submit( array $items )
    {
        $requests = array();
        foreach ( $items as $customID => $request )
            $requests[] = array( 'request' => $this->request( $request ), 'metadata' => array( 'key' => (string) $customID ) );
        $body = array( 'batch' => array( 'display_name' => 'expai-' . date( 'Ymd-His' ), 'input_config' => array( 'requests' => array( 'requests' => $requests ) ) ) );
        list( , $data ) = $this->call( 'POST', $this->base() . '/models/' . rawurlencode( $this->model() ) . ':batchGenerateContent', self::json( $body ) );
        $name = $data && isset( $data['name'] ) ? (string) $data['name'] : '';
        if ( !preg_match( '~^batches/[A-Za-z0-9_-]+$~', $name ) )
            throw new expAiException( 'The batch API of %provider reported an error.', 'no batch name in the answer', 0, array( '%provider' => $this->providerId() ) );
        return array( 'remote_id' => $name, 'input_ref' => '', 'state' => $this->state( $data ), 'remote_status' => $this->rawState( $data ), 'expires_at' => 0 );
    }

    /** @return string the provider's state */
    protected function rawState( array $data )
    {
        if ( isset( $data['metadata']['state'] ) )
            return (string) $data['metadata']['state'];
        return isset( $data['state'] ) ? (string) $data['state'] : '';
    }

    /** @return string the normalised state */
    protected function state( array $data )
    {
        $state = preg_replace( '/^(JOB|BATCH)_STATE_/', '', $this->rawState( $data ) );
        $map = array( 'PENDING' => 'in_progress', 'RUNNING' => 'in_progress', 'SUCCEEDED' => 'ended', 'FAILED' => 'failed',
                      'CANCELLED' => 'canceled', 'EXPIRED' => 'expired' );
        if ( isset( $map[$state] ) )
            return $map[$state];
        return !empty( $data['done'] ) ? 'ended' : 'in_progress';
    }

    /** @return array the inlined responses of a batch */
    protected static function inlined( array $data )
    {
        foreach ( array( array( 'response', 'inlinedResponses' ), array( 'metadata', 'output', 'inlinedResponses' ), array( 'output', 'inlinedResponses' ) ) as $path )
        {
            $v = $data;
            foreach ( $path as $key )
                $v = is_array( $v ) && isset( $v[$key] ) ? $v[$key] : null;
            if ( is_array( $v ) && isset( $v['inlinedResponses'] ) && is_array( $v['inlinedResponses'] ) )
                $v = $v['inlinedResponses'];
            if ( is_array( $v ) && ( !$v || isset( $v[0] ) ) )
                return $v;
        }
        return array();
    }

    public function status( expAiBatch $batch )
    {
        list( , $data ) = $this->call( 'GET', $this->base() . '/' . $batch->attribute( 'remote_id' ) );
        $data = $data ?: array();
        $counts = array( 'succeeded' => 0, 'errored' => 0, 'expired' => 0, 'canceled' => 0, 'processing' => 0 );
        foreach ( self::inlined( $data ) as $item )
            $counts[isset( $item['error'] ) ? 'errored' : 'succeeded']++;
        $stats = isset( $data['metadata']['batchStats'] ) && is_array( $data['metadata']['batchStats'] ) ? $data['metadata']['batchStats'] : array();
        if ( isset( $stats['pendingRequestCount'] ) )
            $counts['processing'] = (int) $stats['pendingRequestCount'];
        return array( 'state' => $this->state( $data ), 'remote_status' => $this->rawState( $data ), 'counts' => $counts, 'output_ref' => '',
                      'expires_at' => 0, 'ended' => self::time( isset( $data['metadata']['endTime'] ) ? $data['metadata']['endTime'] : 0 ) );
    }

    public function results( expAiBatch $batch )
    {
        list( , $data ) = $this->call( 'GET', $this->base() . '/' . $batch->attribute( 'remote_id' ) );
        $ids = $batch->customIds();
        $out = array();
        foreach ( self::inlined( $data ?: array() ) as $i => $item )
        {
            $id = isset( $item['metadata']['key'] ) ? (string) $item['metadata']['key'] : ( isset( $ids[$i] ) ? $ids[$i] : '' );
            if ( $id === '' )
                continue;
            if ( isset( $item['error'] ) )
            {
                $code = isset( $item['error']['code'] ) ? (int) $item['error']['code'] : 500;
                $out[$id] = array( 'type' => 'errored', 'answer' => null, 'error' => self::itemError( $item['error'] ), 'status' => $code );
            }
            elseif ( isset( $item['response'] ) && is_array( $item['response'] ) )
                $out[$id] = array( 'type' => 'succeeded', 'answer' => $this->answer( $item['response'] ), 'error' => '', 'status' => 200 );
        }
        $state = (string) $batch->attribute( 'state' );
        foreach ( $ids as $id )
            if ( !isset( $out[$id] ) )
                $out[$id] = array( 'type' => $state === 'canceled' ? 'canceled' : 'expired', 'answer' => null, 'error' => '', 'status' => 0 );
        return $out;
    }

    /** @return array the expai answer of a GenerateContentResponse */
    public function answer( array $response )
    {
        $text = '';
        $candidate = isset( $response['candidates'][0] ) && is_array( $response['candidates'][0] ) ? $response['candidates'][0] : array();
        foreach ( isset( $candidate['content']['parts'] ) ? (array) $candidate['content']['parts'] : array() as $part )
            if ( is_array( $part ) && isset( $part['text'] ) && empty( $part['thought'] ) )
                $text .= $part['text'];
        $finish = isset( $candidate['finishReason'] ) ? (string) $candidate['finishReason'] : 'STOP';
        $map = array( 'STOP' => 'end_turn', 'MAX_TOKENS' => 'max_tokens', 'SAFETY' => 'refusal', 'PROHIBITED_CONTENT' => 'refusal', 'RECITATION' => 'refusal' );
        $u = isset( $response['usageMetadata'] ) && is_array( $response['usageMetadata'] ) ? $response['usageMetadata'] : array();
        $n = function ( $k ) use ( $u ) { return isset( $u[$k] ) ? (int) $u[$k] : 0; };
        $cached = $n( 'cachedContentTokenCount' );
        $content = $text !== '' ? array( array( 'type' => 'text', 'text' => $text ) ) : array();
        return array(
            'content' => $content,
            'content_json' => json_encode( $content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'stop_reason' => isset( $map[$finish] ) ? $map[$finish] : strtolower( $finish ),
            'model' => isset( $response['modelVersion'] ) ? (string) $response['modelVersion'] : $this->model(),
            'usage' => $u,
            // thinking tokens are billed as output
            'tokens' => array( 'input' => max( 0, $n( 'promptTokenCount' ) - $cached ), 'output' => $n( 'candidatesTokenCount' ) + $n( 'thoughtsTokenCount' ),
                               'cache_write' => 0, 'cache_read' => $cached, 'reasoning' => $n( 'thoughtsTokenCount' ), 'reported_cost' => null ),
            'service_tier' => 'batch',
            'provider' => $this->providerId(),
            'http_status' => 200,
        );
    }

    public function cancel( expAiBatch $batch )
    {
        $this->call( 'POST', $this->base() . '/' . $batch->attribute( 'remote_id' ) . ':cancel', '{}' );
        return true;
    }

    public function delete( expAiBatch $batch )
    {
        $this->call( 'DELETE', $this->base() . '/' . $batch->attribute( 'remote_id' ) );
        return true;
    }
}
