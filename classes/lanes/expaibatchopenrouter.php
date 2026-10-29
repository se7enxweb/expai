<?php
/**
 * File containing the expAiBatchOpenRouter class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The OpenRouter Batch API (https://openrouter.ai/docs/batch-quickstart and
 * https://openrouter.ai/docs/api/api-reference/batch/get-a-batch, read 2026-10-11): a 24 hour window, "typically
 * billed at 50% of the model's standard per-token pricing", results deleted 30 days after creation (410 after
 * that), or at once with DELETE.
 *
 *   POST   {base}/batches          { endpoint: /v1/chat/completions, model, requests: [ { custom_id, body } ] }
 *                                  -> 202 { id, status: validating }
 *   GET    {base}/batches/{id}     status validating | in_progress | finalizing | completed | failed | expired |
 *                                  cancelling | cancelled; request_counts { total, completed, failed }; usage;
 *                                  results (terminal states): [ { custom_id, response | error } ]
 *   DELETE {base}/batches/{id}     (terminal batches)
 *   POST   {base}/batches/{id}/cancel   (assumption: the pages read list submit, list, poll and delete)
 * Whether results[].response is the Chat Completions body or { status_code, body } is not shown; both are read.
 */
class expAiBatchOpenRouter extends expAiBatchApi
{
    public function shape()
    {
        return 'openrouter';
    }

    protected function base()
    {
        return preg_replace( '~/chat/completions$~', '', rtrim( trim( (string) $this->setting( 'BaseURL', 'https://openrouter.ai/api/v1' ) ), '/' ) );
    }

    protected function headers()
    {
        return array_merge( $this->keyHeaders( 'authorization' ), $this->extraHeaders() );
    }

    public function submit( array $items )
    {
        $requests = array();
        foreach ( $items as $customID => $request )
        {
            $body = $this->chatBody( $request );
            unset( $body['model'] );
            $requests[] = array( 'custom_id' => (string) $customID, 'body' => $body );
        }
        list( , $data ) = $this->call( 'POST', $this->base() . '/batches', self::json( array(
            'endpoint' => '/v1/chat/completions', 'model' => $this->modelId(), 'requests' => $requests ) ) );
        if ( !$data || empty( $data['id'] ) )
            throw new expAiException( 'The batch API of %provider reported an error.', 'no batch id in the answer', 0, array( '%provider' => $this->providerId() ) );
        return array( 'remote_id' => (string) $data['id'], 'input_ref' => '', 'state' => $this->state( $data ),
                      'remote_status' => (string) ( isset( $data['status'] ) ? $data['status'] : '' ), 'expires_at' => 0 );
    }

    protected function state( array $data )
    {
        $map = array( 'validating' => 'in_progress', 'in_progress' => 'in_progress', 'finalizing' => 'in_progress', 'completed' => 'ended',
                      'failed' => 'failed', 'expired' => 'expired', 'cancelling' => 'canceling', 'cancelled' => 'canceled' );
        $status = isset( $data['status'] ) ? (string) $data['status'] : '';
        return isset( $map[$status] ) ? $map[$status] : 'in_progress';
    }

    protected function fetchBatch( expAiBatch $batch )
    {
        list( , $data ) = $this->call( 'GET', $this->base() . '/batches/' . rawurlencode( $batch->attribute( 'remote_id' ) ) );
        return $data ?: array();
    }

    public function status( expAiBatch $batch )
    {
        $data = $this->fetchBatch( $batch );
        $c = isset( $data['request_counts'] ) && is_array( $data['request_counts'] ) ? $data['request_counts'] : array();
        $n = function ( $k ) use ( $c ) { return isset( $c[$k] ) ? (int) $c[$k] : 0; };
        return array( 'state' => $this->state( $data ), 'remote_status' => (string) ( isset( $data['status'] ) ? $data['status'] : '' ),
                      'counts' => array( 'succeeded' => $n( 'completed' ), 'errored' => $n( 'failed' ), 'expired' => 0, 'canceled' => 0,
                                         'processing' => max( 0, $n( 'total' ) - $n( 'completed' ) - $n( 'failed' ) ) ),
                      'output_ref' => '', 'expires_at' => 0, 'ended' => self::time( isset( $data['finalized_at'] ) ? $data['finalized_at'] : 0 ) );
    }

    public function results( expAiBatch $batch )
    {
        $data = $this->fetchBatch( $batch );
        $out = array();
        foreach ( isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array() as $item )
        {
            if ( !is_array( $item ) || !isset( $item['custom_id'] ) )
                continue;
            $id = (string) $item['custom_id'];
            $response = isset( $item['response'] ) && is_array( $item['response'] ) ? $item['response'] : null;
            $status = $response && isset( $response['status_code'] ) ? (int) $response['status_code'] : 200;
            $body = $response && isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : $response;
            if ( $body && isset( $body['choices'] ) && empty( $item['error'] ) && $status < 300 )
            {
                try
                {
                    $out[$id] = array( 'type' => 'succeeded', 'answer' => $this->chatAnswer( $body, $status ), 'error' => '', 'status' => $status );
                }
                catch ( expAiException $e )
                {
                    $out[$id] = array( 'type' => 'errored', 'answer' => null, 'error' => $e->detail(), 'status' => $status );
                }
            }
            else
            {
                $code = isset( $item['error']['code'] ) && is_numeric( $item['error']['code'] ) ? (int) $item['error']['code'] : 500;
                $out[$id] = array( 'type' => 'errored', 'answer' => null, 'error' => self::itemError( isset( $item['error'] ) ? $item['error'] : $body ), 'status' => $code );
            }
        }
        $state = (string) $batch->attribute( 'state' );
        foreach ( $batch->customIds() as $id )
            if ( !isset( $out[$id] ) )
                $out[$id] = array( 'type' => $state === 'canceled' ? 'canceled' : 'expired', 'answer' => null, 'error' => '', 'status' => 0 );
        return $out;
    }

    public function cancel( expAiBatch $batch )
    {
        $this->call( 'POST', $this->base() . '/batches/' . rawurlencode( $batch->attribute( 'remote_id' ) ) . '/cancel', '{}' );
        return true;
    }

    public function delete( expAiBatch $batch )
    {
        $this->call( 'DELETE', $this->base() . '/batches/' . rawurlencode( $batch->attribute( 'remote_id' ) ) );
        return true;
    }
}
