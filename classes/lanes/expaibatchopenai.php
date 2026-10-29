<?php
/**
 * File containing the expAiBatchOpenAi class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The OpenAI Batch API (https://developers.openai.com/api/docs/guides/batch, read 2026-10-11): 50% of the standard
 * prices, a 24 hour completion window, up to 50,000 requests or 200 MB per input file, a separate rate-limit pool.
 *
 *   POST   {base}/files            multipart: purpose=batch, file=<JSONL of { custom_id, method: POST,
 *                                  url: /v1/chat/completions, body }>          -> { id }
 *   POST   {base}/batches          { input_file_id, endpoint: /v1/chat/completions, completion_window: 24h }
 *   GET    {base}/batches/{id}     status validating | in_progress | finalizing | completed | failed | expired |
 *                                  cancelling | cancelled; request_counts { total, completed, failed };
 *                                  output_file_id, error_file_id
 *   GET    {base}/files/{id}/content   JSONL: { custom_id, response: { status_code, body }, error }
 *   POST   {base}/batches/{id}/cancel
 *   DELETE {base}/files/{id}       (the input, output and error files; a batch itself is not deleted)
 */
class expAiBatchOpenAi extends expAiBatchApi
{
    public function shape()
    {
        return 'openai';
    }

    /** @return string the API base (BaseURL without /chat/completions) */
    protected function base()
    {
        $base = rtrim( trim( (string) $this->setting( 'BaseURL', 'https://api.openai.com/v1' ) ), '/' );
        return preg_replace( '~/chat/completions$~', '', $base );
    }

    protected function headers()
    {
        return array_merge( $this->keyHeaders( 'authorization' ), $this->extraHeaders() );
    }

    /** @return string the JSONL input file */
    public function inputFile( array $items )
    {
        $lines = array();
        foreach ( $items as $customID => $request )
            $lines[] = self::json( array( 'custom_id' => (string) $customID, 'method' => 'POST', 'url' => '/v1/chat/completions',
                                          'body' => $this->chatBody( $request ) ) );
        return implode( "\n", $lines ) . "\n";
    }

    public function submit( array $items )
    {
        $boundary = '----expai' . bin2hex( random_bytes( 12 ) );
        $body = "--$boundary\r\nContent-Disposition: form-data; name=\"purpose\"\r\n\r\nbatch\r\n"
              . "--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"expai-batch.jsonl\"\r\nContent-Type: application/jsonl\r\n\r\n"
              . $this->inputFile( $items ) . "\r\n--$boundary--\r\n";
        list( , $file ) = $this->call( 'POST', $this->base() . '/files', $body, 'multipart/form-data; boundary=' . $boundary );
        if ( !$file || empty( $file['id'] ) )
            throw new expAiException( 'The batch API of %provider reported an error.', 'no file id in the answer', 0, array( '%provider' => $this->providerId() ) );
        try
        {
            list( , $data ) = $this->call( 'POST', $this->base() . '/batches', self::json( array(
                'input_file_id' => (string) $file['id'], 'endpoint' => '/v1/chat/completions', 'completion_window' => '24h',
                'metadata' => array( 'source' => 'expai' ) ) ) );
        }
        catch ( expAiException $e )
        {
            $this->deleteFile( (string) $file['id'] );
            throw $e;
        }
        if ( !$data || empty( $data['id'] ) )
            throw new expAiException( 'The batch API of %provider reported an error.', 'no batch id in the answer', 0, array( '%provider' => $this->providerId() ) );
        return array( 'remote_id' => (string) $data['id'], 'input_ref' => (string) $file['id'], 'state' => $this->state( $data ),
                      'remote_status' => (string) ( isset( $data['status'] ) ? $data['status'] : '' ),
                      'expires_at' => self::time( isset( $data['expires_at'] ) ? $data['expires_at'] : 0 ) );
    }

    /** @return string the normalised state */
    protected function state( array $data )
    {
        $map = array( 'validating' => 'in_progress', 'in_progress' => 'in_progress', 'finalizing' => 'in_progress', 'completed' => 'ended',
                      'failed' => 'failed', 'expired' => 'expired', 'cancelling' => 'canceling', 'cancelled' => 'canceled' );
        $status = isset( $data['status'] ) ? (string) $data['status'] : '';
        return isset( $map[$status] ) ? $map[$status] : 'in_progress';
    }

    public function status( expAiBatch $batch )
    {
        list( , $data ) = $this->call( 'GET', $this->base() . '/batches/' . rawurlencode( $batch->attribute( 'remote_id' ) ) );
        $data = $data ?: array();
        $c = isset( $data['request_counts'] ) && is_array( $data['request_counts'] ) ? $data['request_counts'] : array();
        $total = isset( $c['total'] ) ? (int) $c['total'] : 0;
        $done = isset( $c['completed'] ) ? (int) $c['completed'] : 0;
        $failed = isset( $c['failed'] ) ? (int) $c['failed'] : 0;
        $refs = array();
        foreach ( array( 'output_file_id', 'error_file_id' ) as $key )
            $refs[] = isset( $data[$key] ) && is_string( $data[$key] ) ? $data[$key] : '';
        return array( 'state' => $this->state( $data ), 'remote_status' => (string) ( isset( $data['status'] ) ? $data['status'] : '' ),
                      'counts' => array( 'succeeded' => $done, 'errored' => $failed, 'expired' => 0, 'canceled' => 0, 'processing' => max( 0, $total - $done - $failed ) ),
                      'output_ref' => implode( ',', $refs ), 'expires_at' => self::time( isset( $data['expires_at'] ) ? $data['expires_at'] : 0 ),
                      'ended' => self::time( isset( $data['completed_at'] ) ? $data['completed_at'] : ( isset( $data['failed_at'] ) ? $data['failed_at'] : 0 ) ) );
    }

    /** @return array the output and error file ids */
    protected function outputFiles( expAiBatch $batch )
    {
        return array_pad( explode( ',', (string) $batch->attribute( 'output_ref' ) ), 2, '' );
    }

    public function results( expAiBatch $batch )
    {
        $out = array();
        list( $output, $errors ) = $this->outputFiles( $batch );
        foreach ( array( $output, $errors ) as $fileID )
        {
            if ( $fileID === '' )
                continue;
            list( , , $raw ) = $this->call( 'GET', $this->base() . '/files/' . rawurlencode( $fileID ) . '/content', '', 'application/json', 'application/binary' );
            foreach ( self::jsonLines( $raw ) as $line )
                if ( isset( $line['custom_id'] ) )
                    $out[(string) $line['custom_id']] = $this->item( $line );
        }
        // what an expired or cancelled batch did not get to has no line: it expired (or was cancelled)
        $state = (string) $batch->attribute( 'state' );
        if ( in_array( $state, array( 'expired', 'canceled' ), true ) )
            foreach ( $batch->customIds() as $id )
                if ( !isset( $out[$id] ) )
                    $out[$id] = array( 'type' => $state, 'answer' => null, 'error' => '', 'status' => 0 );
        return $out;
    }

    /** @return array one result line as an item */
    protected function item( array $line )
    {
        $status = isset( $line['response']['status_code'] ) ? (int) $line['response']['status_code'] : 0;
        $body = isset( $line['response']['body'] ) && is_array( $line['response']['body'] ) ? $line['response']['body'] : null;
        if ( $status >= 200 && $status < 300 && $body && empty( $line['error'] ) )
        {
            try
            {
                return array( 'type' => 'succeeded', 'answer' => $this->chatAnswer( $body, $status ), 'error' => '', 'status' => $status );
            }
            catch ( expAiException $e )
            {
                return array( 'type' => 'errored', 'answer' => null, 'error' => $e->detail(), 'status' => $status );
            }
        }
        $error = !empty( $line['error'] ) ? $line['error'] : ( $body && isset( $body['error'] ) ? $body : 'HTTP ' . $status );
        $text = self::itemError( $error );
        if ( stripos( $text, 'expired' ) !== false && isset( $line['error']['code'] ) && $line['error']['code'] === 'batch_expired' )
            return array( 'type' => 'expired', 'answer' => null, 'error' => $text, 'status' => $status );
        return array( 'type' => 'errored', 'answer' => null, 'error' => $text, 'status' => $status ?: 500 );
    }

    public function cancel( expAiBatch $batch )
    {
        $this->call( 'POST', $this->base() . '/batches/' . rawurlencode( $batch->attribute( 'remote_id' ) ) . '/cancel', '{}' );
        return true;
    }

    public function delete( expAiBatch $batch )
    {
        list( $output, $errors ) = $this->outputFiles( $batch );
        $ok = true;
        foreach ( array( (string) $batch->attribute( 'input_ref' ), $output, $errors ) as $fileID )
            if ( $fileID !== '' )
                $ok = $this->deleteFile( $fileID ) && $ok;
        return $ok;
    }

    /** @return bool */
    protected function deleteFile( $fileID )
    {
        try
        {
            $this->call( 'DELETE', $this->base() . '/files/' . rawurlencode( $fileID ) );
            return true;
        }
        catch ( expAiException $e )
        {
            eZDebug::writeWarning( 'expai: a batch file could not be deleted: ' . $e->detail(), __METHOD__ );
            return false;
        }
    }
}
