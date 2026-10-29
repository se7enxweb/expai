<?php
/**
 * File containing the expAiProviderBase class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * What every built-in provider shares: its settings (the [Provider_<id>] values merged with the [Model_<alias>]
 * values, see expAiProviderRegistry::settingsFor()), the HTTP client with the provider's timeouts and rate limit
 * header allowlist, the API key header, retries of transient failures (post()), and the mapping of HTTP errors to
 * texts for the editor that name the provider. The key is never part of a message, a detail or a log line.
 */
abstract class expAiProviderBase implements expAiProvider
{
    /** @var array */
    protected $settings;

    /** @var expAiHttpClient */
    protected $http;

    /** @var string[] glob patterns of the response headers kept for the usage log */
    protected $defaultRateLimitHeaders = array( 'retry-after' );

    /**
     * @param array $settings provider and model settings (contains the key: never log or output it)
     * @param expAiHttpClient|null $http
     */
    public function __construct( array $settings, $http = null )
    {
        $this->settings = $settings;
        $this->http = $http ?: new expAiHttpClient(
            $this->setting( 'Timeout', 115 ), $this->setting( 'ConnectTimeout', 10 ), $this->setting( 'MaxResponseBytes', 4194304 ),
            $this->rateLimitHeaders() );
    }

    /** @return mixed */
    public function setting( $name, $default = '' )
    {
        return isset( $this->settings[$name] ) && $this->settings[$name] !== '' && $this->settings[$name] !== null && $this->settings[$name] !== array()
             ? $this->settings[$name] : $default;
    }

    /** @return bool */
    protected function enabled( $name, $default )
    {
        $value = strtolower( trim( (string) $this->setting( $name, $default ) ) );
        return in_array( $value, array( 'enabled', 'true', '1', 'yes', 'on' ), true );
    }

    /** @return string[] */
    protected function listSetting( $name )
    {
        $value = $this->setting( $name, array() );
        $value = is_array( $value ) ? $value : array( $value );
        return array_values( array_filter( array_map( 'trim', array_map( 'strval', $value ) ), 'strlen' ) );
    }

    /** @return string[] the response headers kept for the usage log ([Provider_<id>] RateLimitHeaders[]) */
    public function rateLimitHeaders()
    {
        $list = $this->listSetting( 'RateLimitHeaders' );
        return $list ?: $this->defaultRateLimitHeaders;
    }

    public function providerId()
    {
        return (string) $this->setting( 'ProviderId', $this->defaultProviderId() );
    }

    /** @return string */
    abstract protected function defaultProviderId();

    /** @return string the name the editor reads in error texts ([Provider_<id>] Label) */
    public function providerLabel()
    {
        return (string) $this->setting( 'Label', $this->providerId() );
    }

    /** @return string the alias of the model in the registry ('' for a provider built without it) */
    public function modelAlias()
    {
        return (string) $this->setting( 'ModelAlias', '' );
    }

    public function modelName()
    {
        return trim( (string) $this->setting( 'Model', '' ) );
    }

    /** @return string native | prompted | none */
    public function toolUse()
    {
        $mode = strtolower( (string) $this->setting( 'ToolUse', 'native' ) );
        return in_array( $mode, array( 'native', 'prompted', 'none' ), true ) ? $mode : 'native';
    }

    /** @return string[] the capabilities of the model ([Model_<alias>] Capabilities[]) */
    public function capabilities()
    {
        return array_map( 'strtolower', $this->listSetting( 'Capabilities' ) );
    }

    /** @return bool */
    public function hasCapability( $name )
    {
        return in_array( strtolower( $name ), $this->capabilities(), true );
    }

    /**
     * The API key header: ApiKeyHeader x-api-key (default of anthropic), authorization (Bearer, default of the
     * others), api-key (Azure), x-goog-api-key, or none (a local server without a key).
     * @return string[] header lines, empty without a key
     * @throws expAiException when a key is required and missing
     */
    protected function keyHeaders( $defaultHeader )
    {
        $header = strtolower( trim( (string) $this->setting( 'ApiKeyHeader', $defaultHeader ) ) );
        if ( $header === 'none' )
            return array();
        $key = trim( (string) $this->setting( 'ApiKey', '' ) );
        if ( $key === '' )
        {
            if ( $this->enabled( 'KeyOptional', 'false' ) )
                return array();
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'ApiKey of provider ' . $this->providerId() . ' is not set' );
        }
        if ( preg_match( '/[\r\n]/', $key ) )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'ApiKey of provider ' . $this->providerId() . ' contains a line break' );
        if ( $header === 'authorization' )
            return array( 'Authorization: Bearer ' . $key );
        if ( !preg_match( '/^[a-z0-9-]{1,60}$/', $header ) )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'ApiKeyHeader of provider ' . $this->providerId() . ' is not a header name' );
        return array( $header . ': ' . $key );
    }

    /**
     * [Provider_<id>] Headers[] ("Name: value" lines), checked: no line breaks, no key headers.
     * @return string[]
     */
    protected function extraHeaders()
    {
        $out = array();
        foreach ( $this->listSetting( 'Headers' ) as $line )
        {
            if ( preg_match( '/[\r\n]/', $line ) || !preg_match( '/^([A-Za-z0-9-]{1,60}):\s*(.*)$/', $line, $m ) )
                continue;
            if ( in_array( strtolower( $m[1] ), array( 'authorization', 'x-api-key', 'api-key', 'x-goog-api-key', 'host', 'content-length', 'content-type' ), true ) )
                continue;
            $out[] = $m[1] . ': ' . $m[2];
        }
        return $out;
    }

    /** at most this many retries of one request (three attempts) */
    const MAX_RETRIES = 2;

    /** no single wait before a retry is longer (seconds) */
    const MAX_WAIT = 5;

    /** HTTP statuses that are worth one more attempt */
    protected static $transientStatuses = array( 408, 425, 429, 500, 502, 503, 504, 529 );

    /** gateway timeouts: the provider behind the gateway may still be generating (and billing) the answer */
    protected static $gatewayTimeoutStatuses = array( 408, 502, 504 );

    /** a server error after an attempt longer than this (seconds) is not retried */
    const SLOW_ATTEMPT = 30;

    /**
     * POST with up to two retries of a transient failure: HTTP 408, 425, 429, 500, 502, 503, 504, 529, and a transport
     * error when the connection was never established (name not resolved, refused: curl error 6 or 7, or no connect
     * time). A timeout or an answer that broke off is never retried: the model may have done (and billed) the work.
     * The wait is the Retry-After of the answer (seconds or an HTTP date; no retry when it is longer than
     * [Provider_<id>] RetryAfterMax, default and at most 5 seconds), otherwise 1 and then 2 seconds with a little
     * jitter. All attempts share one deadline, the client's timeout; a follow-up request of the same call (postFollowUp())
     * shares it too. A server error (408, 425, 500, 502, 504) is not retried when the attempt itself took longer than
     * SLOW_ATTEMPT seconds: the provider behind a gateway may still bill that long generation; 429, 503, 529 (the request
     * was not taken) and quick server errors are retried. [Provider_<id>] RetryOnServerError=disabled
     * leaves only a 429/503/529 with a short Retry-After to be retried.
     * @return array( int status, string body, array rate limit headers, int retries )
     * @throws expAiException
     */
    protected function post( $url, array $headers, $body )
    {
        $retries = 0;
        $timeout = method_exists( $this->http, 'timeout' ) ? (float) $this->http->timeout() : (float) $this->setting( 'Timeout', 115 );
        $deadline = $this->sharedDeadline !== null ? (float) $this->sharedDeadline : $this->now() + $timeout;
        $this->lastDeadline = $deadline;
        while ( true )
        {
            $failure = null;
            $status = 0;
            $received = '';
            $rate = array();
            $started = $this->now();
            try
            {
                $left = $deadline - $started;
                if ( ( $retries === 0 && $this->sharedDeadline === null ) || $left >= $timeout || !method_exists( $this->http, 'request' ) )
                    list( $status, $received, $rate ) = array_pad( $this->http->postJson( $url, $headers, $body ), 3, array() );
                else
                    list( $status, $received, $rate ) = array_pad( $this->http->request( $url, $headers, $body, array( 'timeout' => (int) floor( $left ) ) ), 3, array() );
            }
            catch ( expAiException $e )
            {
                if ( $retries >= self::MAX_RETRIES || !$this->retryTransport( $e ) )
                    throw $this->named( $e );
                $failure = $e;
            }
            $wait = $failure !== null ? $this->backoff( $retries ) : ( $retries < self::MAX_RETRIES ? $this->retryWait( $status, $rate, $retries, $this->now() - $started ) : null );
            if ( $failure === null && $wait === null )
                return array( $status, $received, $rate, $retries );
            // the wait and at least one second for the next attempt must fit before the deadline
            if ( $this->now() + $wait + 1 > $deadline )
            {
                if ( $failure !== null )
                    throw $this->named( $failure );
                return array( $status, $received, $rate, $retries );
            }
            $this->pause( $wait );
            $retries++;
        }
    }

    /** @var float|null the deadline post() uses instead of its own (a follow-up request of the same call) */
    private $sharedDeadline = null;

    /** @var float|null the deadline of the last post() */
    protected $lastDeadline = null;

    /**
     * A follow-up request of the same call (the same request without a parameter the server refused, or with
     * different thinking options): post() on the deadline of the previous post(), so the call as a whole never runs
     * longer than one timeout.
     * @return array|null what post() returns, null when less than a second of the deadline is left
     * @throws expAiException
     */
    protected function postFollowUp( $url, array $headers, $body )
    {
        $deadline = $this->lastDeadline;
        if ( $deadline === null )
            return $this->post( $url, $headers, $body );
        if ( $this->now() + 1 > $deadline )
            return null;
        $this->sharedDeadline = $deadline;
        try
        {
            return $this->post( $url, $headers, $body );
        }
        finally
        {
            $this->sharedDeadline = null;
        }
    }

    /** @return bool a failed transport is safe to retry: the connection was never established */
    protected function retryTransport( expAiException $e )
    {
        if ( !preg_match( '/^curl error (\d+)/', $e->detail(), $m ) || !$this->enabled( 'RetryOnServerError', 'enabled' ) )
            return false;
        $errno = (int) $m[1];
        // 6 name not resolved, 7 connection refused
        if ( $errno === 6 || $errno === 7 )
            return true;
        // 28 timeout, 18 partial file, 52 empty reply, 56 receive error: the request may have reached the model
        if ( in_array( $errno, array( 18, 28, 52, 56 ), true ) )
            return false;
        $transfer = method_exists( $this->http, 'lastTransfer' ) ? $this->http->lastTransfer() : array();
        return isset( $transfer['errno'], $transfer['connect_time'] ) && (int) $transfer['errno'] === $errno && (float) $transfer['connect_time'] == 0.0;
    }

    /**
     * @param float $took the seconds the failed attempt took
     * @return float|null seconds to wait before the retry of an HTTP answer, null for no retry
     */
    protected function retryWait( $status, array $rate, $retries, $took = 0.0 )
    {
        if ( !in_array( (int) $status, self::$transientStatuses, true ) )
            return null;
        // a slow failure may have been generated (and billed) behind a gateway; 429, 503 and 529 say the request was not taken
        if ( $took > self::SLOW_ATTEMPT && !in_array( (int) $status, array( 429, 503, 529 ), true ) )
            return null;
        $after = $this->retryAfter( $rate );
        if ( !$this->enabled( 'RetryOnServerError', 'enabled' ) && !( $after !== null && in_array( (int) $status, array( 429, 503, 529 ), true ) ) )
            return null;
        if ( $after === null )
            return $this->backoff( $retries );
        return $after <= min( (float) self::MAX_WAIT, (float) $this->setting( 'RetryAfterMax', self::MAX_WAIT ) ) ? $after : null;
    }

    /** @return float|null the seconds of a Retry-After header (a number or an HTTP date), null without a usable one */
    protected function retryAfter( array $rate )
    {
        if ( !isset( $rate['retry-after'] ) )
            return null;
        $value = trim( (string) $rate['retry-after'] );
        if ( is_numeric( $value ) )
            return (float) $value >= 0 ? (float) $value : null;
        if ( !preg_match( '/^[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $value ) )
            return null;
        $time = strtotime( $value );
        return $time === false ? null : max( 0.0, $time - $this->now() );
    }

    /** @return float the wait before retry $retries + 1 without a Retry-After: 1 s, then 2 s, plus up to 0.25 s jitter */
    protected function backoff( $retries )
    {
        return min( (float) self::MAX_WAIT, ( $retries === 0 ? 1.0 : 2.0 ) + mt_rand( 0, 250 ) / 1000 );
    }

    /** @return float the current time of the HTTP client's clock */
    protected function now()
    {
        return method_exists( $this->http, 'now' ) ? (float) $this->http->now() : microtime( true );
    }

    /** Waits before a retry, on the HTTP client's clock. */
    protected function pause( $seconds )
    {
        if ( method_exists( $this->http, 'pause' ) )
            $this->http->pause( $seconds );
        elseif ( $seconds > 0 )
            usleep( (int) round( $seconds * 1000000 ) );
    }

    /** @return expAiException the same failure, its detail naming the provider */
    protected function named( expAiException $e )
    {
        $copy = new expAiException( $e->getMessage(), 'provider ' . $this->providerId() . ': ' . $e->detail(), $e->getCode(), $e->arguments() );
        $copy->rateLimits = $e->rateLimits;
        return $copy;
    }

    /**
     * The exception of a failed HTTP request: a text for the editor by status, and for the log the status, the
     * provider's error type and message (shortened, control characters removed). Both the
     * { error: { type, message, code } } shape and a plain { error: "text" } (Hugging Face) are read.
     * @return expAiException
     */
    protected function httpError( $status, $data, array $rateHeaders, $retries = 0 )
    {
        $type = '';
        $apiMessage = '';
        if ( is_array( $data ) && isset( $data['error'] ) )
        {
            $error = $data['error'];
            if ( is_string( $error ) )
                $apiMessage = $error;
            elseif ( is_array( $error ) )
            {
                foreach ( array( 'type', 'code' ) as $field )
                    if ( $type === '' && isset( $error[$field] ) && is_scalar( $error[$field] ) && (string) $error[$field] !== (string) $status )
                        $type = (string) $error[$field];
                if ( isset( $error['message'] ) && is_string( $error['message'] ) )
                    $apiMessage = $error['message'];
            }
        }
        elseif ( is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) )
            $apiMessage = $data['message'];
        $type = preg_replace( '/[^\w.-]/', '', substr( $type, 0, 60 ) );
        // never echo something that looks like a key (masked before and after shortening: a cut key is still a key)
        // at most 2000 bytes are masked (the answer may be megabytes long), 300 are shown
        $apiMessage = self::maskSecrets( substr( self::maskSecrets( preg_replace( '/[\x00-\x1f]/', ' ', substr( (string) $apiMessage, 0, 2000 ) ) ), 0, 300 ) );
        $detail = 'provider ' . $this->providerId() . ': HTTP ' . $status . ( $type !== '' ? ' ' . $type : '' )
                . ( $apiMessage !== '' ? ': ' . $apiMessage : '' ) . ( $retries ? ' (after ' . $retries . ( $retries === 1 ? ' retry)' : ' retries)' ) : '' );
        $arguments = array( '%provider' => $this->providerLabel() );
        // one literal per text, so that the translation files list each of them
        if ( $status === 401 || $status === 403 )
            $error = new expAiException( '%provider refused the request (access). Please tell the administrator.', $detail, $status, $arguments );
        elseif ( $status === 402 )
            $error = new expAiException( '%provider refused the request (no credit left). Please tell the administrator.', $detail, $status, $arguments );
        elseif ( $status === 429 || $status === 529 || $status === 503 )
            $error = new expAiException( '%provider is busy or over its limit. Please try again later.', $detail, $status, $arguments );
        elseif ( $status === 413 )
            $error = new expAiException( 'The request is too long for %provider.', $detail, $status, $arguments );
        elseif ( $status === 404 )
            $error = new expAiException( '%provider does not know the configured model. Please tell the administrator.', $detail, $status, $arguments );
        elseif ( $status === 408 || $status === 504 )
            $error = new expAiException( '%provider did not answer in time. Please try again with a smaller request.', $detail, $status, $arguments );
        else
            $error = new expAiException( '%provider reported an error.', $detail, $status, $arguments );
        $error->rateLimits = $rateHeaders;
        return $error;
    }

    /**
     * A provider's error text with everything that may be a secret replaced by [redacted]: keys (sk-..., hf_...),
     * Bearer tokens (also URL encoded), Authorization headers, key=, api_key=, token=, secret=, password= values of a
     * query or form, and JSON string values of keys named like key, token, secret, password or authorization.
     * @return string
     */
    public static function maskSecrets( $text )
    {
        $text = (string) $text;
        // a text PCRE gives up on (backtrack limit) is not shown at all rather than unmasked or empty
        $redacted = static function ( $result ) { return is_string( $result ) ? $result : '[redacted]'; };
        $text = $redacted( preg_replace( '/"([\w.-]*(?:key|token|secret|password|passwd|authorization))"\s*:\s*"(?:[^"\\\\]|\\\\.)*"?/i', '"$1":"[redacted]"', $text ) );
        $text = $redacted( preg_replace( '/\b([\w.-]*(?:key|token|secret|password|passwd))=(?!\[redacted\])[^&\s"\',;]+/i', '$1=[redacted]', $text ) );
        $text = $redacted( preg_replace( '/\bAuthorization(?::|%3A)(?:\s|%20|\+)*(?!\[redacted\])(?:(?:Bearer|Basic|Token|Digest)(?:\s|%20|\+)+)?[^\s&"\',;]+/i', 'Authorization: [redacted]', $text ) );
        $text = $redacted( preg_replace( '/\bBearer(?:\s+|%20|\+)(?!\[redacted\])[^\s&"\',;]+/i', '[redacted]', $text ) );
        return $redacted( preg_replace( '/\b(sk-[A-Za-z0-9_-]{6,}|hf_[A-Za-z0-9]{6,})/', '[redacted]', $text ) );
    }

    /** @return string JSON of a payload */
    protected function encode( array $payload )
    {
        $body = json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
        if ( $body === false )
            throw new expAiException( 'The request could not be sent to Exponential Intelligence.', 'json_encode failed' );
        return $body;
    }
}
