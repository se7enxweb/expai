<?php
/**
 * File containing the expAiBatchMistral class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Mistral batch jobs with inline requests (https://docs.mistral.ai/studio/batch-processing and
 * https://docs.mistral.ai/api/endpoint/batch, read 2026-10-11): 50% of the standard prices; inline batching below
 * 10,000 requests (MaxRequestsPerBatch is capped there).
 *
 *   POST   {base}/batch/jobs              { model, endpoint: /v1/chat/completions, requests: [ { custom_id, body } ],
 *                                         metadata, timeout_hours }
 *   GET    {base}/batch/jobs/{id}?inline=true   status QUEUED | RUNNING | SUCCESS | FAILED | TIMEOUT_EXCEEDED |
 *                                         CANCELLATION_REQUESTED | CANCELLED; total_requests, succeeded_requests,
 *                                         failed_requests, output_file, error_file, outputs (inline results)
 *   GET    {base}/files/{id}/content      JSONL: { custom_id, response: { status_code, body }, error }
 *   POST   {base}/batch/jobs/{id}/cancel
 *   DELETE {base}/batch/jobs/{id}         and DELETE {base}/files/{id} for the output and error files
 * The shape of one inline output is not shown on the pages read; it is taken to be the file line's shape
 * (assumption, the mock follows it).
 */
class expAiBatchMistral extends expAiBatchApi
{
    const INLINE_MAX = 9999;

    public function shape()
    {
        return 'mistral';
    }

    protected function base()
    {
        return preg_replace( '~/chat/completions$~', '', rtrim( trim( (string) $this->setting( 'BaseURL', 'https://api.mistral.ai/v1' ) ), '/' ) );
    }

    protected function headers()
    {
        return array_merge( $this->keyHeaders( 'authorization' ), $this->extraHeaders() );
    }

    public function submit( array $items )
    {
        if ( count( $items ) > self::INLINE_MAX )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'more than ' . self::INLINE_MAX . ' inline requests in one Mistral batch' );
        $requests = array();
        foreach ( $items as $customID => $request )
        {
            $body = $this->chatBody( $request );
            unset( $body['model'] );
            $requests[] = array( 'custom_id' => (string) $customID, 'body' => $body );
        }
        list( , $data ) = $this->call( 'POST', $this->base() . '/batch/jobs', self::json( array(
            'model' => $this->modelId(), 'endpoint' => '/v1/chat/completions', 'requests' => $requests, 'metadata' => array( 'source' => 'expai' ), 'timeout_hours' => 24 ) ) );
        if ( !$data || empty( $data['id'] ) )
            throw new expAiException( 'The batch API of %provider reported an error.', 'no job id in the answer', 0, array( '%provider' => $this->providerId() ) );
        return array( 'remote_id' => (string) $data['id'], 'input_ref' => '', 'state' => $this->state( $data ),
                      'remote_status' => (string) ( isset( $data['status'] ) ? $data['status'] : '' ), 'expires_at' => 0 );
    }

    protected function state( array $data )
    {
        $map = array( 'QUEUED' => 'in_progress', 'RUNNING' => 'in_progress', 'SUCCESS' => 'ended', 'FAILED' => 'failed', 'TIMEOUT_EXCEEDED' => 'expired',
                      'CANCELLATION_REQUESTED' => 'canceling', 'CANCELLED' => 'canceled' );
        $status = isset( $data['status'] ) ? strtoupper( (string) $data['status'] ) : '';
        return isset( $map[$status] ) ? $map[$status] : 'in_progress';
    }

    /** @return array the job */
    protected function job( expAiBatch $batch )
    {
        list( , $data ) = $this->call( 'GET', $this->base() . '/batch/jobs/' . rawurlencode( $batch->attribute( 'remote_id' ) ) . '?inline=true' );
        return $data ?: array();
    }

    public function status( expAiBatch $batch )
    {
        $data = $this->job( $batch );
        $n = function ( $k ) use ( $data ) { return isset( $data[$k] ) ? (int) $data[$k] : 0; };
        $refs = array( isset( $data['output_file'] ) && is_string( $data['output_file'] ) ? $data['output_file'] : '',
                       isset( $data['error_file'] ) && is_string( $data['error_file'] ) ? $data['error_file'] : '' );
        return array( 'state' => $this->state( $data ), 'remote_status' => (string) ( isset( $data['status'] ) ? $data['status'] : '' ),
                      'counts' => array( 'succeeded' => $n( 'succeeded_requests' ), 'errored' => $n( 'failed_requests' ), 'expired' => 0, 'canceled' => 0,
                                         'processing' => max( 0, $n( 'total_requests' ) - $n( 'succeeded_requests' ) - $n( 'failed_requests' ) ) ),
                      'output_ref' => implode( ',', $refs ), 'expires_at' => 0, 'ended' => self::time( isset( $data['completed_at'] ) ? $data['completed_at'] : 0 ) );
    }

    public function results( expAiBatch $batch )
    {
        $data = $this->job( $batch );
        $lines = array();
        if ( isset( $data['outputs'] ) && is_array( $data['outputs'] ) && $data['outputs'] )
            $lines = $data['outputs'];
        else
            foreach ( array_pad( explode( ',', (string) $batch->attribute( 'output_ref' ) ), 2, '' ) as $fileID )
                if ( $fileID !== '' )
                {
                    list( , , $raw ) = $this->call( 'GET', $this->base() . '/files/' . rawurlencode( $fileID ) . '/content', '', 'application/json', 'application/binary' );
                    $lines = array_merge( $lines, self::jsonLines( $raw ) );
                }
        $out = array();
        foreach ( $lines as $line )
        {
            if ( !is_array( $line ) || !isset( $line['custom_id'] ) )
                continue;
            $status = isset( $line['response']['status_code'] ) ? (int) $line['response']['status_code'] : 0;
            $body = isset( $line['response']['body'] ) && is_array( $line['response']['body'] ) ? $line['response']['body'] : null;
            if ( $body && $status >= 200 && $status < 300 && empty( $line['error'] ) )
            {
                try
                {
                    $out[(string) $line['custom_id']] = array( 'type' => 'succeeded', 'answer' => $this->chatAnswer( $body, $status ), 'error' => '', 'status' => $status );
                }
                catch ( expAiException $e )
                {
                    $out[(string) $line['custom_id']] = array( 'type' => 'errored', 'answer' => null, 'error' => $e->detail(), 'status' => $status );
                }
            }
            else
                $out[(string) $line['custom_id']] = array( 'type' => 'errored', 'answer' => null,
                                                           'error' => self::itemError( !empty( $line['error'] ) ? $line['error'] : $body ), 'status' => $status ?: 500 );
        }
        $state = (string) $batch->attribute( 'state' );
        foreach ( $batch->customIds() as $id )
            if ( !isset( $out[$id] ) )
                $out[$id] = array( 'type' => $state === 'canceled' ? 'canceled' : 'expired', 'answer' => null, 'error' => '', 'status' => 0 );
        return $out;
    }

    public function cancel( expAiBatch $batch )
    {
        $this->call( 'POST', $this->base() . '/batch/jobs/' . rawurlencode( $batch->attribute( 'remote_id' ) ) . '/cancel', '{}' );
        return true;
    }

    public function delete( expAiBatch $batch )
    {
        foreach ( array_pad( explode( ',', (string) $batch->attribute( 'output_ref' ) ), 2, '' ) as $fileID )
            if ( $fileID !== '' )
            {
                try
                {
                    $this->call( 'DELETE', $this->base() . '/files/' . rawurlencode( $fileID ) );
                }
                catch ( expAiException $e )
                {
                    eZDebug::writeWarning( 'expai: a batch file could not be deleted: ' . $e->detail(), __METHOD__ );
                }
            }
        $this->call( 'DELETE', $this->base() . '/batch/jobs/' . rawurlencode( $batch->attribute( 'remote_id' ) ) );
        return true;
    }
}
