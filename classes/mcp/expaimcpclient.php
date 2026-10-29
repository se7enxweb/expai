<?php
/**
 * File containing the expAiMcpClient class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A small client for one MCP server over the streamable HTTP transport (JSON-RPC 2.0 POSTs; the answer is JSON or
 * an event stream whose data lines carry the JSON-RPC response). Per use: initialize (the server may hand out an
 * Mcp-Session-Id, which is sent back), notifications/initialized, then tools/list (cached for [McpServer_<id>]
 * ToolsCacheTTL seconds, default 3600) or tools/call. No stdio, no server-sent requests, no sampling.
 *
 * SSRF guard: the URL comes from the INI only; https, or http to a loopback address with AllowLoopback=enabled;
 * the host is resolved once and every address is checked (private, link-local, reserved and loopback ranges are
 * refused unless AllowLoopback / AllowPrivate allow them); the connection is pinned to the checked address; no
 * redirects. The token ([McpServer_<id>] Token, override only) is sent as a Bearer header and never logged.
 */
class expAiMcpClient
{
    const CLIENT_VERSION = '0.0.1.0';

    /** @var array */
    protected $settings;

    /** @var expAiHttpClient */
    protected $http;

    /** @var string|null */
    protected $session;

    /** @var string */
    protected $protocol;

    /** @var int */
    protected $nextId = 1;

    /** @var array|null CURLOPT_RESOLVE pin of the checked address */
    protected $pin;

    public function __construct( array $settings, $http = null )
    {
        $this->settings = $settings;
        $this->http = $http ?: new expAiHttpClient( $this->setting( 'Timeout', 20 ), 5, max( 65536, (int) $this->setting( 'MaxResponseBytes', 1048576 ) ),
                                                     array( 'content-type', 'mcp-session-id', 'retry-after' ) );
        $this->protocol = (string) $this->setting( 'ProtocolVersion', '2025-06-18' );
    }

    protected function setting( $name, $default = '' )
    {
        return isset( $this->settings[$name] ) && $this->settings[$name] !== '' && $this->settings[$name] !== null ? $this->settings[$name] : $default;
    }

    protected function flag( $name )
    {
        return in_array( strtolower( (string) $this->setting( $name, 'disabled' ) ), array( 'enabled', 'true', '1', 'yes' ), true );
    }

    /** @return string */
    public function serverId()
    {
        return (string) $this->setting( 'ServerId', '' );
    }

    /**
     * Checks the URL and pins its address.
     * @return string the URL
     * @throws expAiException
     */
    public function checkedUrl()
    {
        $url = trim( (string) $this->setting( 'URL', '' ) );
        $parts = parse_url( $url );
        if ( !$parts || empty( $parts['host'] ) || !isset( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) )
            throw new expAiException( 'The MCP server %server is not configured correctly.', 'URL is not an absolute URL without credentials', 0, array( '%server' => $this->serverId() ) );
        $scheme = strtolower( $parts['scheme'] );
        $host = trim( strtolower( $parts['host'] ), '[]' );
        $port = isset( $parts['port'] ) ? (int) $parts['port'] : ( $scheme === 'https' ? 443 : 80 );
        $addresses = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : self::resolve( $host );
        if ( !$addresses )
            throw new expAiException( 'The MCP server %server could not be reached.', 'the host does not resolve', 0, array( '%server' => $this->serverId() ) );
        $loopbackOnly = true;
        foreach ( $addresses as $address )
        {
            $kind = self::addressKind( $address );
            if ( $kind !== 'loopback' )
                $loopbackOnly = false;
            if ( $kind === 'loopback' && !$this->flag( 'AllowLoopback' ) )
                throw new expAiException( 'The MCP server %server is not allowed.', 'loopback address without AllowLoopback', 0, array( '%server' => $this->serverId() ) );
            if ( $kind === 'private' && !$this->flag( 'AllowPrivate' ) )
                throw new expAiException( 'The MCP server %server is not allowed.', 'private or reserved address without AllowPrivate', 0, array( '%server' => $this->serverId() ) );
        }
        if ( $scheme !== 'https' && !( $scheme === 'http' && $loopbackOnly ) )
            throw new expAiException( 'The MCP server %server is not allowed.', 'https is required (http only for a loopback address)', 0, array( '%server' => $this->serverId() ) );
        if ( !filter_var( $host, FILTER_VALIDATE_IP ) )
            $this->pin = array( $host . ':' . $port . ':' . $addresses[0] );
        return $url;
    }

    /** @return string[] the IPv4 and IPv6 addresses of a host */
    protected static function resolve( $host )
    {
        $out = array();
        $records = @dns_get_record( $host, DNS_A | DNS_AAAA );
        foreach ( is_array( $records ) ? $records : array() as $record )
        {
            if ( isset( $record['ip'] ) )
                $out[] = $record['ip'];
            if ( isset( $record['ipv6'] ) )
                $out[] = $record['ipv6'];
        }
        if ( !$out && $host === 'localhost' )
            $out[] = '127.0.0.1';
        return array_values( array_unique( $out ) );
    }

    /** @return string public | loopback | private (private, link-local, reserved, unspecified ...) */
    public static function addressKind( $address )
    {
        if ( $address === '::1' || preg_match( '/^127\./', $address ) || preg_match( '/^::ffff:127\./i', $address ) )
            return 'loopback';
        if ( !filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) )
            return 'private';
        // ranges PHP does not count as private or reserved
        foreach ( array( '/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', '/^169\.254\./', '/^0\./', '/^fe80:/i', '/^f[cd][0-9a-f]{2}:/i', '/^::ffff:(10|192\.168|169\.254)\./i' ) as $pattern )
            if ( preg_match( $pattern, $address ) )
                return 'private';
        return 'public';
    }

    /**
     * One JSON-RPC message.
     * @param bool $notification no id, no answer expected
     * @return array|null the result
     * @throws expAiException
     */
    protected function rpc( $method, $params, $notification = false )
    {
        $url = $this->checkedUrl();
        $message = array( 'jsonrpc' => '2.0', 'method' => $method );
        if ( $params !== null )
            $message['params'] = $params;
        $id = null;
        if ( !$notification )
        {
            $id = $this->nextId++;
            $message['id'] = $id;
        }
        $headers = array();
        $token = trim( (string) $this->setting( 'Token', '' ) );
        if ( $token !== '' && !preg_match( '/[\r\n]/', $token ) )
            $headers[] = 'Authorization: Bearer ' . $token;
        if ( $method !== 'initialize' )
            $headers[] = 'MCP-Protocol-Version: ' . $this->protocol;
        if ( $this->session !== null )
            $headers[] = 'Mcp-Session-Id: ' . $this->session;
        $body = json_encode( $message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
        try
        {
            list( $status, $received, $responseHeaders ) = $this->http->request( $url, $headers, $body,
                array( 'accept' => 'application/json, text/event-stream', 'resolve' => $this->pin ) );
        }
        catch ( expAiException $e )
        {
            throw new expAiException( 'The MCP server %server could not be reached.', 'mcp ' . $this->serverId() . ': ' . $e->detail(), $e->getCode(), array( '%server' => $this->serverId() ) );
        }
        if ( isset( $responseHeaders['mcp-session-id'] ) && preg_match( '/^[\x21-\x7e]{1,200}$/', $responseHeaders['mcp-session-id'] ) )
            $this->session = $responseHeaders['mcp-session-id'];
        if ( $notification )
        {
            if ( $status >= 400 )
                throw new expAiException( 'The MCP server %server reported an error.', 'mcp ' . $this->serverId() . ": $method HTTP $status", $status, array( '%server' => $this->serverId() ) );
            return null;
        }
        if ( $status === 401 || $status === 403 )
            throw new expAiException( 'The MCP server %server refused the request (access).', 'mcp ' . $this->serverId() . ": $method HTTP $status", $status, array( '%server' => $this->serverId() ) );
        if ( $status < 200 || $status >= 300 )
            throw new expAiException( 'The MCP server %server reported an error.', 'mcp ' . $this->serverId() . ": $method HTTP $status", $status, array( '%server' => $this->serverId() ) );
        $type = isset( $responseHeaders['content-type'] ) ? strtolower( $responseHeaders['content-type'] ) : 'application/json';
        $response = strpos( $type, 'text/event-stream' ) === 0 ? self::fromEventStream( $received, $id ) : json_decode( $received, true );
        if ( !is_array( $response ) || ( isset( $response['id'] ) && $response['id'] !== $id ) )
            throw new expAiException( 'The MCP server %server sent an answer that could not be read.', 'mcp ' . $this->serverId() . ": $method no JSON-RPC response", 0, array( '%server' => $this->serverId() ) );
        if ( isset( $response['error'] ) )
            throw new expAiException( 'The MCP server %server reported an error.', 'mcp ' . $this->serverId() . ": $method error "
                                      . ( isset( $response['error']['code'] ) ? (int) $response['error']['code'] : 0 ) . ' '
                                      . expAiText::cut( isset( $response['error']['message'] ) ? (string) $response['error']['message'] : '', 200 ), 0, array( '%server' => $this->serverId() ) );
        return isset( $response['result'] ) && is_array( $response['result'] ) ? $response['result'] : array();
    }

    /** @return array|null the JSON-RPC response with the id from an event stream */
    public static function fromEventStream( $text, $id )
    {
        $data = '';
        foreach ( preg_split( '/\r?\n/', (string) $text ) as $line )
        {
            if ( strpos( $line, 'data:' ) === 0 )
                $data .= ltrim( substr( $line, 5 ) );
            elseif ( trim( $line ) === '' && $data !== '' )
            {
                $message = json_decode( $data, true );
                $data = '';
                if ( is_array( $message ) && array_key_exists( 'id', $message ) && $message['id'] === $id && ( isset( $message['result'] ) || isset( $message['error'] ) ) )
                    return $message;
            }
        }
        if ( $data !== '' )
        {
            $message = json_decode( $data, true );
            if ( is_array( $message ) && array_key_exists( 'id', $message ) && $message['id'] === $id )
                return $message;
        }
        return null;
    }

    /** initialize + notifications/initialized */
    public function initialize()
    {
        $result = $this->rpc( 'initialize', array( 'protocolVersion' => $this->protocol, 'capabilities' => new stdClass(),
                                                   'clientInfo' => array( 'name' => 'Exponential expai', 'version' => self::CLIENT_VERSION ) ) );
        if ( isset( $result['protocolVersion'] ) && is_string( $result['protocolVersion'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $result['protocolVersion'] ) )
            $this->protocol = $result['protocolVersion'];
        $this->rpc( 'notifications/initialized', null, true );
        return $result;
    }

    /** @return array the tools of the server (definitions: name, description, inputSchema) */
    public function listTools()
    {
        $this->initialize();
        $tools = array();
        $cursor = null;
        for ( $page = 0; $page < 5; $page++ )
        {
            $result = $this->rpc( 'tools/list', $cursor !== null ? array( 'cursor' => $cursor ) : new stdClass() );
            foreach ( isset( $result['tools'] ) && is_array( $result['tools'] ) ? $result['tools'] : array() as $tool )
                if ( is_array( $tool ) && isset( $tool['name'] ) && is_string( $tool['name'] ) )
                    $tools[] = $tool;
            $cursor = isset( $result['nextCursor'] ) && is_string( $result['nextCursor'] ) ? $result['nextCursor'] : null;
            if ( $cursor === null )
                break;
        }
        return $tools;
    }

    /** @return array listTools(), cached in the var directory for ToolsCacheTTL seconds */
    public function cachedTools()
    {
        $ttl = max( 0, (int) $this->setting( 'ToolsCacheTTL', 3600 ) );
        $dir = eZSys::cacheDirectory() . '/expai/mcp';
        $file = $dir . '/' . $this->serverId() . '-' . substr( sha1( (string) $this->setting( 'URL', '' ) ), 0, 12 ) . '.json';
        if ( $ttl > 0 && is_file( $file ) && filemtime( $file ) > time() - $ttl )
        {
            $cached = json_decode( (string) file_get_contents( $file ), true );
            if ( is_array( $cached ) )
                return $cached;
        }
        $tools = $this->listTools();
        if ( $ttl > 0 )
        {
            if ( !is_dir( $dir ) )
                eZDir::mkdir( $dir, false, true );
            eZFile::create( basename( $file ), $dir, json_encode( $tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), true );
        }
        return $tools;
    }

    /** @return array the result of tools/call: content, isError, structuredContent */
    public function callTool( $name, array $arguments )
    {
        $this->initialize();
        return $this->rpc( 'tools/call', array( 'name' => (string) $name, 'arguments' => $arguments ?: new stdClass() ) );
    }
}
