<?php
/**
 * File containing the expAiHttpClient class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * JSON POST to an AI provider (or an MCP server) with curl: http(s) only, no redirects, a total and a connect timeout
 * and a limit on the size of the answer. Of the response headers only the ones of the allowlist are kept (rate limit
 * headers for the usage log, glob patterns such as anthropic-ratelimit-* or x-ratelimit-*). Errors are expAiException
 * whose texts never contain the request, the answer or the headers (the API key travels in a header).
 */
class expAiHttpClient
{
    /** @var int */
    protected $timeout;

    /** @var int */
    protected $connectTimeout;

    /** @var int */
    protected $maxResponseBytes;

    /** @var string[] lower-case glob patterns of the response headers to keep */
    protected $keepHeaders;

    /** @var bool */
    protected $truncated = false;

    /** @var array errno, connect_time (seconds, null when unknown) and status of the last request */
    protected $lastTransfer = array( 'errno' => 0, 'connect_time' => null, 'status' => 0 );

    /**
     * @param string[]|null $keepHeaders glob patterns of the response headers to keep (default: the Anthropic rate
     *                                   limit headers and retry-after)
     */
    public function __construct( $timeout = 115, $connectTimeout = 10, $maxResponseBytes = 4194304, $keepHeaders = null )
    {
        // at most 10 minutes in a web request; 30 minutes in the background (queue worker, command line: TimeoutBackground)
        $this->timeout = max( 1, min( class_exists( 'expAiWebBudget' ) && !expAiWebBudget::inWebRequest() ? 1800 : 600, (int) $timeout ) );
        $this->connectTimeout = max( 1, min( $this->timeout, (int) $connectTimeout ) );
        $this->maxResponseBytes = max( 1024, (int) $maxResponseBytes );
        $this->keepHeaders = array_map( 'strtolower', is_array( $keepHeaders ) && $keepHeaders ? array_values( $keepHeaders )
                                                      : array( 'anthropic-ratelimit-*', 'retry-after' ) );
    }

    /** @return int seconds */
    public function timeout()
    {
        return $this->timeout;
    }

    /**
     * How the last request ended, for the retry decision of a provider: a transport error is only safe to retry when
     * the connection was never established (connect_time 0), as the server cannot have started on the request.
     * @return array errno (0 without a transport error), connect_time (seconds, null when unknown), status
     */
    public function lastTransfer()
    {
        return $this->lastTransfer;
    }

    /** @return bool the last answer was cut at the size limit (option truncate) */
    public function truncated()
    {
        return $this->truncated;
    }

    /** @return float the current time in seconds (the clock of the retry deadline; tests replace it) */
    public function now()
    {
        return microtime( true );
    }

    /** Waits $seconds before a retry (tests replace it so that they do not sleep). */
    public function pause( $seconds )
    {
        if ( $seconds > 0 )
            usleep( (int) round( $seconds * 1000000 ) );
    }

    /** @return bool the header is on the allowlist */
    public function keeps( $name )
    {
        $name = strtolower( $name );
        foreach ( $this->keepHeaders as $pattern )
            if ( fnmatch( $pattern, $name ) )
                return true;
        return false;
    }

    /**
     * @param string $url
     * @param array $headers list of "Name: value"
     * @param string $body JSON
     * @return array( int status, string body, array kept headers: lower-case name => value )
     * @throws expAiException
     */
    public function postJson( $url, array $headers, $body )
    {
        return $this->request( $url, $headers, $body );
    }

    /**
     * @param array $options accept (Accept header, default application/json), resolve (curl CURLOPT_RESOLVE list,
     *                       used to pin a checked address), method (GET, POST, DELETE; default POST),
     *                       content_type (of the body, default application/json), on_chunk (a callable that gets
     *                       every piece of the body as it arrives, e.g. to time the first event of a stream),
     *                       timeout (seconds, at most the client's timeout: the time left of a retry deadline),
     *                       user_agent (default "Exponential expai"), accept_language, encoding (CURLOPT_ENCODING; ''
     *                       accepts every encoding curl can decode, default none), header_max (longest kept header
     *                       value, default 200), truncate (true: an answer over the limit is cut, not an error; the
     *                       cut shows in truncated())
     * @return array( int status, string body, array kept headers )
     * @throws expAiException
     */
    public function request( $url, array $headers, $body, array $options = array() )
    {
        if ( !is_string( $url ) || !preg_match( '~^https?://[^\s/?#@]+(?::\d+)?(?:[/?#]\S*)?$~i', $url ) )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'invalid endpoint (http or https without credentials)' );
        if ( !function_exists( 'curl_init' ) )
            throw new expAiException( 'Exponential Intelligence is not available on this server.', 'the PHP curl extension is missing' );

        $this->lastTransfer = array( 'errno' => 0, 'connect_time' => null, 'status' => 0 );
        $this->truncated = false;
        $received = '';
        $tooLarge = false;
        $responseHeaders = array();
        $limit = $this->maxResponseBytes;
        $truncate = !empty( $options['truncate'] );
        $headerMax = isset( $options['header_max'] ) ? max( 1, min( 8192, (int) $options['header_max'] ) ) : 200;
        $client = $this;
        $accept = isset( $options['accept'] ) ? (string) $options['accept'] : 'application/json';
        $ch = curl_init( $url );
        $method = isset( $options['method'] ) ? strtoupper( (string) $options['method'] ) : 'POST';
        if ( !in_array( $method, array( 'GET', 'POST', 'DELETE' ), true ) )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'unsupported HTTP method' );
        $get = $method === 'GET';
        // DELETE without a body sends no content type (batch APIs); content_type: e.g. multipart/form-data; boundary=...
        $noBody = $get || ( $method === 'DELETE' && (string) $body === '' );
        $timeout = isset( $options['timeout'] ) && is_numeric( $options['timeout'] ) ? max( 1, min( $this->timeout, (int) $options['timeout'] ) ) : $this->timeout;
        $agent = isset( $options['user_agent'] ) && !preg_match( '/[\r\n]/', (string) $options['user_agent'] ) ? (string) $options['user_agent'] : 'Exponential expai';
        $contentType = isset( $options['content_type'] ) && !preg_match( '/[\r\n]/', (string) $options['content_type'] ) ? (string) $options['content_type'] : 'application/json';
        curl_setopt_array( $ch, array(
            CURLOPT_POST => $method === 'POST',
            CURLOPT_HTTPGET => $get,
            CURLOPT_HTTPHEADER => array_merge( $noBody ? array( 'Accept: ' . $accept ) : array( 'Content-Type: ' . $contentType, 'Accept: ' . $accept ),
                                               isset( $options['accept_language'] ) && !preg_match( '/[\r\n]/', (string) $options['accept_language'] ) ? array( 'Accept-Language: ' . $options['accept_language'] ) : array(),
                                               $headers ),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min( $timeout, $this->connectTimeout ),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $agent,
            // only the headers of the allowlist (rate limits, retry-after, content-type, mcp-session-id ...) are kept
            CURLOPT_HEADERFUNCTION => static function ( $handle, $line ) use ( &$responseHeaders, $client, $headerMax )
            {
                // the headers of a redirect answer do not carry over to the answer after it
                if ( preg_match( '/^HTTP\//', $line ) )
                    $responseHeaders = array();
                elseif ( preg_match( '/^([A-Za-z0-9-]+):\s*(.*?)\s*$/', $line, $m ) && $client->keeps( $m[1] ) )
                    $responseHeaders[strtolower( $m[1] )] = substr( $m[2], 0, $headerMax );
                return strlen( $line );
            },
            CURLOPT_WRITEFUNCTION => static function ( $handle, $chunk ) use ( &$received, &$tooLarge, $limit, $options, $truncate )
            {
                if ( strlen( $received ) + strlen( $chunk ) > $limit )
                {
                    if ( $truncate )
                        $received .= substr( $chunk, 0, max( 0, $limit - strlen( $received ) ) );
                    $tooLarge = true;
                    return 0;
                }
                $received .= $chunk;
                // #392 on_chunk: a callable that sees every piece of the body as it arrives (an event stream)
                if ( isset( $options['on_chunk'] ) && is_callable( $options['on_chunk'] ) )
                    call_user_func( $options['on_chunk'], $chunk );
                return strlen( $chunk );
            },
        ) );
        if ( $method === 'DELETE' )
            curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'DELETE' );
        if ( !$noBody )
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
        if ( isset( $options['encoding'] ) )
            curl_setopt( $ch, CURLOPT_ENCODING, (string) $options['encoding'] );
        if ( !empty( $options['resolve'] ) )
            curl_setopt( $ch, CURLOPT_RESOLVE, (array) $options['resolve'] );
        if ( defined( 'CURLOPT_PROTOCOLS_STR' ) )
            curl_setopt( $ch, CURLOPT_PROTOCOLS_STR, 'http,https' );
        elseif ( defined( 'CURLOPT_PROTOCOLS' ) )
            curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );

        $ok = curl_exec( $ch );
        $errno = curl_errno( $ch );
        $status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        $connectTime = curl_getinfo( $ch, CURLINFO_CONNECT_TIME );
        $this->lastTransfer = array( 'errno' => (int) $errno, 'connect_time' => is_numeric( $connectTime ) ? (float) $connectTime : null, 'status' => $status );
        $this->truncated = $truncate && $tooLarge;
        if ( PHP_VERSION_ID < 80000 )
            curl_close( $ch );
        unset( $ch );

        // a cut page is the answer (curl error 23: the write callback stopped the transfer), not an error
        if ( $truncate && $tooLarge )
            return array( $status, $received, $responseHeaders );
        if ( $tooLarge )
            throw new expAiException( 'The answer of Exponential Intelligence was too long.', 'response larger than ' . $limit . ' bytes', $status );
        if ( $ok === false || $errno )
        {
            // "no answer": not a single byte came back (expAiModelFallback may hand the request to the default model)
            if ( $errno === 28 )
                throw new expAiException( 'Exponential Intelligence did not answer in time. Please try again with a smaller request.',
                                          'timeout after ' . $timeout . ' s' . ( $received === '' && $status === 0 ? ', no answer' : '' ) );
            throw new expAiException( 'Exponential Intelligence could not be reached.', 'curl error ' . $errno );
        }
        return array( $status, $received, $responseHeaders );
    }
}
