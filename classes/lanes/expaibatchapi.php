<?php
/**
 * File containing the expAiBatchApi class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A provider's batch API: submit many requests at once, poll the batch, fetch the results, cancel, delete. One
 * subclass per shape ([Lane_<provider>.batch] BatchApi=):
 *
 *   anthropic   Message Batches (POST /v1/messages/batches; results as JSONL)          expAiBatchAnthropic
 *   openai      Batch API (JSONL file upload to /v1/files, POST /v1/batches)            expAiBatchOpenAi
 *   gemini      batchGenerateContent with inline requests (results inline)            expAiBatchGemini
 *   mistral     /v1/batch/jobs with inline requests (results inline or as a file)     expAiBatchMistral
 *   openrouter  /api/v1/batches with inline requests (results in the batch object)    expAiBatchOpenRouter
 *
 * Every request of a batch is an expai request (system, messages, max_tokens, output_schema; no tools), built into
 * the provider's body by the provider's own adapter, and every result becomes an expai answer (content, tokens,
 * stop_reason, model) as a synchronous call returns it. custom_id is the expqueue job uuid.
 *
 * Normalised states: in_progress, canceling, ended, failed, expired, canceled. Normalised item types: succeeded,
 * errored, expired, canceled.
 */
abstract class expAiBatchApi
{
    const SHAPES = array( 'anthropic' => 'expAiBatchAnthropic', 'openai' => 'expAiBatchOpenAi', 'gemini' => 'expAiBatchGemini',
                          'mistral' => 'expAiBatchMistral', 'openrouter' => 'expAiBatchOpenRouter' );

    /** @var expAiLane */
    protected $lane;

    /** @var string */
    protected $alias;

    /** @var array provider + model settings (with the key: never log or output) */
    protected $settings;

    /** @var expAiHttpClient */
    protected $http;

    public function __construct( expAiLane $lane, $alias, array $settings, $http = null )
    {
        $this->lane = $lane;
        $this->alias = (string) $alias;
        $this->settings = $settings;
        $timeout = (int) ( isset( $settings['BatchTimeout'] ) ? $settings['BatchTimeout'] : 120 );
        $max = (int) ( isset( $settings['BatchMaxResponseBytes'] ) ? $settings['BatchMaxResponseBytes'] : 268435456 );
        $this->http = $http ?: new expAiHttpClient( $timeout, 10, $max, array( 'retry-after', 'x-ratelimit-*', 'anthropic-ratelimit-*', 'content-type' ) );
    }

    /**
     * The batch API of a lane.
     * @return expAiBatchApi
     * @throws expAiException
     */
    public static function forLane( expAiLane $lane, $alias, ?expAiConfig $config = null, $http = null )
    {
        $shapes = self::SHAPES;
        $api = (string) $lane->get( 'batch_api' );
        if ( !$lane->isBatch() || !isset( $shapes[$api] ) )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'lane ' . $lane->key() . ' has no known BatchApi (' . $api . ')' );
        $settings = $lane->providerSettings( $alias, $config );
        if ( !$settings )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'no model ' . $alias . ' in the provider registry' );
        $class = $shapes[$api];
        return new $class( $lane, $alias, $settings, $http );
    }

    /** @return string the shape name */
    abstract public function shape();

    /**
     * Submits the requests.
     * @param array $items custom_id => expai request
     * @return array remote_id, input_ref, state, remote_status, expires_at
     * @throws expAiException
     */
    abstract public function submit( array $items );

    /**
     * @return array state, remote_status, counts (succeeded, errored, expired, canceled, processing), output_ref, expires_at, ended
     * @throws expAiException
     */
    abstract public function status( expAiBatch $batch );

    /**
     * The results of an ended batch.
     * @return array custom_id => array( type, answer (expai answer, for succeeded), error (text), status (HTTP status of the item) )
     * @throws expAiException
     */
    abstract public function results( expAiBatch $batch );

    /** @return bool */
    abstract public function cancel( expAiBatch $batch );

    /** @return bool the batch (and its files) are deleted at the provider */
    abstract public function delete( expAiBatch $batch );

    // ---- shared helpers --------------------------------------------------------------------------------------------

    /** @return mixed */
    protected function setting( $name, $default = '' )
    {
        return isset( $this->settings[$name] ) && $this->settings[$name] !== '' && $this->settings[$name] !== array() ? $this->settings[$name] : $default;
    }

    /** @return string the provider id */
    public function providerId()
    {
        return (string) $this->setting( 'ProviderId', $this->lane->get( 'provider' ) );
    }

    /** @return string the model id the provider expects */
    public function modelId()
    {
        return trim( (string) $this->setting( 'Model', '' ) );
    }

    /** @return string[] the key header ("x-api-key: ...", "Authorization: Bearer ...") */
    protected function keyHeaders( $defaultHeader )
    {
        $header = strtolower( trim( (string) $this->setting( 'ApiKeyHeader', $defaultHeader ) ) );
        if ( $header === 'none' )
            return array();
        $key = trim( (string) $this->setting( 'ApiKey', '' ) );
        if ( $key === '' || preg_match( '/[\r\n]/', $key ) )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'ApiKey of provider ' . $this->providerId() . ' is not set' );
        if ( $header === 'authorization' )
            return array( 'Authorization: Bearer ' . $key );
        if ( !preg_match( '/^[a-z0-9-]{1,60}$/', $header ) )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'ApiKeyHeader is not a header name' );
        return array( $header . ': ' . $key );
    }

    /** @return string[] the provider's and the lane's extra headers */
    protected function extraHeaders()
    {
        $out = array();
        foreach ( (array) $this->setting( 'Headers', array() ) as $line )
            if ( is_string( $line ) && !preg_match( '/[\r\n]/', $line ) && preg_match( '/^([A-Za-z0-9-]{1,60}):\s*(.+)$/', $line, $m )
                 && !in_array( strtolower( $m[1] ), array( 'authorization', 'x-api-key', 'api-key', 'x-goog-api-key', 'host', 'content-length', 'content-type' ), true ) )
                $out[] = $m[1] . ': ' . $m[2];
        return $out;
    }

    /** @return string[] the headers of every call */
    abstract protected function headers();

    /**
     * One HTTP call.
     * @return array( int status, array|null decoded JSON, string raw body, array kept headers )
     * @throws expAiException on a transport error or a non-2xx status
     */
    protected function call( $method, $url, $body = '', $contentType = 'application/json', $accept = 'application/json' )
    {
        $options = array( 'method' => $method, 'content_type' => $contentType, 'accept' => $accept );
        list( $status, $raw, $headers ) = array_pad( $this->http->request( $url, $this->headers(), (string) $body, $options ), 3, array() );
        $data = json_decode( (string) $raw, true );
        if ( $status < 200 || $status >= 300 )
            throw $this->error( $status, $data, $headers, $method . ' ' . preg_replace( '~^https?://[^/]+~', '', preg_replace( '/[?#].*$/', '', $url ) ) );
        return array( $status, is_array( $data ) ? $data : null, (string) $raw, $headers );
    }

    /** @return expAiException a failed batch call (detail: provider, call, status and the provider's message, no key) */
    protected function error( $status, $data, array $headers, $what )
    {
        $message = '';
        if ( is_array( $data ) && isset( $data['error'] ) )
            $message = is_string( $data['error'] ) ? $data['error'] : ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ? $data['error']['message'] : '' );
        elseif ( is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) )
            $message = $data['message'];
        $message = preg_replace( '/\b(sk-[A-Za-z0-9_-]{6,}|hf_[A-Za-z0-9]{6,}|Bearer\s+\S+)/', '[redacted]', substr( preg_replace( '/[\x00-\x1f]/', ' ', $message ), 0, 300 ) );
        $e = new expAiException( 'The batch API of %provider reported an error.', 'provider ' . $this->providerId() . ' batch ' . $what . ': HTTP ' . $status
                                 . ( $message !== '' ? ': ' . $message : '' ), (int) $status, array( '%provider' => (string) $this->setting( 'Label', $this->providerId() ) ) );
        $e->rateLimits = $headers;
        return $e;
    }

    /** @return string JSON */
    protected static function json( $value )
    {
        $json = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
        if ( $json === false )
            throw new expAiException( 'The request could not be sent to Exponential Intelligence.', 'json_encode failed' );
        return $json;
    }

    /** @return array decoded lines of a JSONL text */
    protected static function jsonLines( $text )
    {
        $out = array();
        foreach ( preg_split( '/\r?\n/', (string) $text ) as $line )
        {
            $line = trim( $line );
            if ( $line === '' )
                continue;
            $row = json_decode( $line, true );
            if ( is_array( $row ) )
                $out[] = $row;
        }
        return $out;
    }

    /** @return int a timestamp of an ISO 8601 or RFC 3339 time (0 when none) */
    protected static function time( $value )
    {
        if ( is_numeric( $value ) )
            return (int) $value;
        $t = is_string( $value ) && $value !== '' ? strtotime( $value ) : false;
        return $t ? (int) $t : 0;
    }

    /**
     * The expai answer of a Chat Completions body, by the openai_chat adapter.
     * @return array
     */
    protected function chatAnswer( array $body, $status = 200 )
    {
        $provider = new expAiOpenAiChatProvider( $this->settings );
        $answer = $provider->parseAnswer( $body, $status );
        $answer['service_tier'] = isset( $body['service_tier'] ) && is_string( $body['service_tier'] ) ? $body['service_tier'] : 'batch';
        return $answer;
    }

    /** @return array the Chat Completions body of an expai request, by the openai_chat adapter */
    protected function chatBody( array $request )
    {
        $provider = new expAiOpenAiChatProvider( $this->settings );
        $body = $provider->buildPayload( $request );
        unset( $body['stream'], $body['tools'], $body['tool_choice'], $body['parallel_tool_calls'] );
        return $body;
    }

    /** @return string text of an item's error */
    protected static function itemError( $error )
    {
        if ( is_string( $error ) )
            return substr( $error, 0, 255 );
        if ( is_array( $error ) )
        {
            foreach ( array( array( 'error', 'message' ), array( 'message' ), array( 'error' ) ) as $path )
            {
                $v = $error;
                foreach ( $path as $key )
                    $v = is_array( $v ) && isset( $v[$key] ) ? $v[$key] : null;
                if ( is_string( $v ) && $v !== '' )
                    return substr( $v, 0, 255 );
            }
            return substr( json_encode( $error ), 0, 255 );
        }
        return 'unknown error';
    }

    /**
     * Whether an item error must not be retried (the request itself is invalid).
     * @return bool
     */
    public static function permanentItemError( $status, $text )
    {
        return in_array( (int) $status, array( 400, 404, 413, 422 ), true ) || stripos( (string) $text, 'invalid_request' ) !== false;
    }
}
