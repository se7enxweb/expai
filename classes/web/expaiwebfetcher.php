<?php
/**
 * File containing the expAiWebFetcher class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Fetches one web page for web_fetch: GET only, http(s) only, the host on [WebSettings] AllowedDomains[] (or given as
 * allowed by the caller), the SSRF guard (expAiWebGuard: resolved once, private ranges refused, connection pinned to
 * the checked address), robots.txt respected (cached [WebSettings] RobotsCacheTTL seconds), at most MaxRedirects
 * redirects and only to the same host, at most MaxBytes read (the rest is cut, not an error), Timeout seconds in
 * all, text types only. Nothing but the URL is sent: no cookies, no credentials, no referrer, no request body.
 */
class expAiWebFetcher
{
    /** @var callable|null tests: function( $url, array $pin, $timeout, $maxBytes ) => array( status, contentType, location, body, truncated ) */
    public static $transport = null;

    /** @var string[] content types that are read */
    protected static $types = array( 'text/html', 'application/xhtml+xml', 'text/plain', 'text/markdown', 'text/x-markdown', 'application/json',
                                     'text/xml', 'application/xml', 'application/atom+xml', 'application/rss+xml', 'text/csv' );

    /** @var expAiConfig */
    protected $config;

    /** @var expAiWebGuard */
    protected $guard;

    public function __construct( expAiConfig $config, ?expAiWebGuard $guard = null )
    {
        $this->config = $config;
        $this->guard = $guard ?: expAiWebGuard::fromConfig( $config );
    }

    /** @return string[] ports allowed besides 80 and 443 ([WebSettings] AllowedPorts[]) */
    public function ports()
    {
        return $this->config->arr( 'WebSettings', 'AllowedPorts' );
    }

    /** @return string the User-Agent header (the robots.txt product token first) */
    public static function userAgent()
    {
        return expAiWebRobots::PRODUCT . '/1.0 (Exponential expai; read-only fetch for an editor)';
    }

    /**
     * @param string $url
     * @param bool $allowed the caller allows the host although it is not on the allowlist (a search result with
     *                      [SearchSettings] SearchResultsFetchable=enabled); the SSRF guard still applies
     * @return array url (asked), final_url, host, status, content_type, body, truncated
     * @throws expAiException
     */
    public function fetch( $url, $allowed = false )
    {
        $parts = expAiWebUrl::parse( $url, $this->ports() );
        if ( !$parts )
            throw new expAiException( 'This is not a web address that can be fetched: only http and https, without user name or password, on the standard ports.', 'web: invalid url' );
        if ( !$allowed && !expAiWebUrl::hostAllowed( $parts['host'], $this->config->arr( 'WebSettings', 'AllowedDomains' ) ) )
            throw new expAiException( 'The host %host is not on the list of sites that may be fetched.', 'web: host not allowlisted', 0, array( '%host' => $parts['host'] ) );

        $timeout = $this->config->int( 'WebSettings', 'Timeout', 10, 1, 60 );
        $maxBytes = $this->config->int( 'WebSettings', 'MaxBytes', 524288, 4096, 8388608 );
        $maxRedirects = $this->config->int( 'WebSettings', 'MaxRedirects', 3, 0, 10 );
        $deadline = microtime( true ) + $timeout;
        $current = $parts;
        for ( $hop = 0; ; ++$hop )
        {
            list( $pin ) = $this->guard->check( $current );
            if ( $this->config->flag( 'WebSettings', 'RespectRobots', true ) && !$this->robots( $current )->allows( $current['target'] ) )
                throw new expAiException( 'The site %host does not allow automated reading of this page (robots.txt).', 'web: robots.txt disallows', 0, array( '%host' => $current['host'] ) );
            $left = (int) ceil( $deadline - microtime( true ) );
            if ( $left < 1 )
                throw new expAiException( 'The page %host did not answer in time.', 'web: timeout', 0, array( '%host' => $current['host'] ) );
            list( $status, $type, $location, $body, $truncated ) = $this->transport( $current['url'], $pin, $left, $maxBytes );
            if ( $status >= 300 && $status < 400 && $location !== '' )
            {
                if ( $hop >= $maxRedirects )
                    throw new expAiException( 'The page %host redirects too often.', 'web: too many redirects', 0, array( '%host' => $current['host'] ) );
                $target = expAiWebUrl::resolve( $current['url'], $location );
                $next = $target !== null ? expAiWebUrl::parse( $target, $this->ports() ) : null;
                if ( !$next )
                    throw new expAiException( 'The page %host redirects to an address that cannot be fetched.', 'web: redirect to an invalid url', 0, array( '%host' => $current['host'] ) );
                // never to another host: the model may fetch the target itself, which checks the allowlist again
                if ( $next['host'] !== $current['host'] || $next['port'] !== $current['port'] && !( $current['scheme'] === 'http' && $next['scheme'] === 'https' ) )
                    throw new expAiException( 'The page redirects to another site: %target. Fetch that address instead if it is allowed.', 'web: cross-host redirect', 0,
                                              array( '%target' => expAiText::cut( preg_replace( '/[?#].*$/', '', $next['url'] ), 300 ) ) );
                $current = $next;
                continue;
            }
            break;
        }
        if ( $status < 200 || $status >= 300 )
            throw new expAiException( 'The page %url answered with HTTP status %status.', 'web: HTTP ' . $status, $status,
                                      array( '%url' => expAiText::cut( $current['url'], 300 ), '%status' => $status ) );
        $mime = strtolower( trim( preg_replace( '/;.*$/', '', $type ) ) );
        if ( $mime !== '' && !in_array( $mime, self::$types, true ) )
            throw new expAiException( 'The address %url is not a text page (%type).', 'web: content type ' . $mime, 0,
                                      array( '%url' => expAiText::cut( $current['url'], 300 ), '%type' => expAiText::cut( $mime, 60 ) ) );
        return array( 'url' => $parts['url'], 'final_url' => $current['url'], 'host' => $current['host'], 'status' => $status,
                      'content_type' => $mime === '' ? 'text/html' : $mime, 'charset' => self::charset( $type ), 'body' => $body, 'truncated' => $truncated );
    }

    /** @return string the charset of a Content-Type header, '' when none */
    public static function charset( $type )
    {
        return preg_match( '/charset\s*=\s*"?([A-Za-z0-9._-]+)/i', (string) $type, $m ) ? strtolower( $m[1] ) : '';
    }

    /** @return expAiWebRobots the rules of the host, cached */
    public function robots( array $parts )
    {
        $origin = $parts['scheme'] . '://' . ( strpos( $parts['host'], ':' ) !== false ? '[' . $parts['host'] . ']' : $parts['host'] )
                . ( $parts['port'] !== ( $parts['scheme'] === 'https' ? 443 : 80 ) ? ':' . $parts['port'] : '' );
        $ttl = $this->config->int( 'WebSettings', 'RobotsCacheTTL', 3600, 0, 604800 );
        $dir = eZSys::cacheDirectory() . '/expai/web';
        $file = $dir . '/robots-' . sha1( $origin ) . '.json';
        if ( $ttl > 0 && is_file( $file ) && filemtime( $file ) > time() - $ttl )
        {
            $cached = json_decode( (string) @file_get_contents( $file ), true );
            if ( is_array( $cached ) && isset( $cached['status'], $cached['text'] ) )
                return new expAiWebRobots( (int) $cached['status'], (string) $cached['text'] );
        }
        $status = 0;
        $text = '';
        try
        {
            $robots = expAiWebUrl::parse( $origin . '/robots.txt', $this->ports() );
            list( $pin ) = $this->guard->check( $robots );
            // redirects of robots.txt are followed within the host only (RFC 9309 allows up to five)
            for ( $hop = 0; $hop < 5; ++$hop )
            {
                list( $status, , $location, $text ) = $this->transport( $robots['url'], $pin, min( 10, $this->config->int( 'WebSettings', 'Timeout', 10, 1, 60 ) ), 524288 );
                if ( $status < 300 || $status >= 400 || $location === '' )
                    break;
                $next = expAiWebUrl::parse( (string) expAiWebUrl::resolve( $robots['url'], $location ), $this->ports() );
                if ( !$next || $next['host'] !== $robots['host'] )
                {
                    // a robots.txt elsewhere is not read: treated as missing
                    $status = 404;
                    break;
                }
                $robots = $next;
            }
        }
        catch ( expAiException $e )
        {
            $status = 0;
        }
        if ( $ttl > 0 )
        {
            if ( !is_dir( $dir ) )
                eZDir::mkdir( $dir, false, true );
            eZFile::create( basename( $file ), $dir, json_encode( array( 'status' => $status, 'text' => substr( (string) $text, 0, 524288 ) ) ), true );
        }
        return new expAiWebRobots( $status, (string) $text );
    }

    /** @return array( int status, string content type, string location, string body, bool truncated ) */
    protected function transport( $url, array $pin, $timeout, $maxBytes )
    {
        if ( self::$transport )
            return array_values( call_user_func( self::$transport, $url, $pin, $timeout, $maxBytes ) ) + array( 0, '', '', '', false );
        if ( !function_exists( 'curl_init' ) )
            throw new expAiException( 'Exponential Intelligence is not available on this server.', 'the PHP curl extension is missing' );
        // the shared client: http(s) only, no redirects (followed here, host by host), the checked address pinned
        // through CURLOPT_RESOLVE, the answer cut at MaxBytes, nothing sent but the URL
        $client = new expAiHttpClient( (int) $timeout, min( 5, (int) $timeout ), (int) $maxBytes, array( 'content-type', 'location' ) );
        try
        {
            list( $status, $body, $headers ) = $client->request( $url, array(), '', array(
                'method' => 'GET', 'accept' => 'text/html,application/xhtml+xml,text/plain,text/markdown;q=0.9,*/*;q=0.1', 'accept_language' => 'en',
                'user_agent' => self::userAgent(), 'encoding' => '', 'resolve' => $pin, 'truncate' => true, 'header_max' => 2048 ) );
        }
        catch ( expAiException $e )
        {
            $host = (string) parse_url( $url, PHP_URL_HOST );
            $transfer = $client->lastTransfer();
            if ( $transfer['errno'] === 28 )
                throw new expAiException( 'The page %host did not answer in time.', 'web: timeout after ' . $timeout . ' s', 0, array( '%host' => $host ) );
            throw new expAiException( 'The page %host could not be reached.', 'web: ' . ( $transfer['errno'] ? 'curl error ' . $transfer['errno'] : $e->detail() ), 0, array( '%host' => $host ) );
        }
        $truncated = $client->truncated();
        return array( $status, isset( $headers['content-type'] ) ? substr( $headers['content-type'], 0, 200 ) : '',
                      isset( $headers['location'] ) ? $headers['location'] : '', $body, $truncated );
    }
}
