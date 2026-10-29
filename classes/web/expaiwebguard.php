<?php
/**
 * File containing the expAiWebGuard class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The SSRF guard of the web tools. A host is resolved once; every address it resolves to is checked with the
 * classification of the MCP client (expAiMcpClient::addressKind(): loopback, private, link-local, reserved, carrier
 * grade NAT ...). Anything but a public address is refused unless the host is named in [WebSettings] PrivateHosts[]
 * (an explicit exception for an intranet documentation server). The connection is then pinned to the checked address
 * (CURLOPT_RESOLVE), so a second DNS answer (DNS rebinding) cannot send the request elsewhere.
 */
class expAiWebGuard
{
    /** @var callable|null host => list of addresses (tests replace the resolver) */
    public static $resolver = null;

    /** @var string[] */
    protected $privateHosts;

    public function __construct( array $privateHosts = array() )
    {
        $this->privateHosts = $privateHosts;
    }

    /** @return expAiWebGuard with the settings of [WebSettings] */
    public static function fromConfig( expAiConfig $config )
    {
        return new self( $config->arr( 'WebSettings', 'PrivateHosts' ) );
    }

    /**
     * @param array $parts expAiWebUrl::parse()
     * @return array the CURLOPT_RESOLVE pin (empty for an IP literal) and the checked address: array( pin, address )
     * @throws expAiException
     */
    public function check( array $parts )
    {
        $host = $parts['host'];
        $literal = (bool) filter_var( $host, FILTER_VALIDATE_IP );
        $addresses = $literal ? array( $host ) : self::resolve( $host );
        if ( !$addresses )
            throw new expAiException( 'The address %host could not be found.', 'web: the host does not resolve', 0, array( '%host' => $host ) );
        $exception = expAiWebUrl::hostAllowed( $host, $this->privateHosts );
        foreach ( $addresses as $address )
        {
            $kind = expAiMcpClient::addressKind( $address );
            // IPv4-mapped IPv6 of any non-public range, unspecified and multicast addresses
            if ( $kind === 'public' && ( preg_match( '/^::ffff:/i', $address ) || preg_match( '/^(22[4-9]|2[3-5]\d)\./', $address ) || preg_match( '/^ff/i', $address ) ) )
            {
                $mapped = preg_match( '/^::ffff:([0-9.]+)$/i', $address, $m ) ? expAiMcpClient::addressKind( $m[1] ) : 'private';
                $kind = $mapped === 'public' ? 'public' : 'private';
            }
            if ( $kind !== 'public' && !$exception )
                throw new expAiException( 'The address %host is not allowed: it points into a private network.', 'web: ' . $kind . ' address ' . $address, 0, array( '%host' => $host ) );
        }
        if ( $literal )
            return array( array(), $host );
        // IPv4 first: not every server has a working IPv6 route
        $pinned = $addresses[0];
        foreach ( $addresses as $address )
            if ( strpos( $address, ':' ) === false )
            {
                $pinned = $address;
                break;
            }
        $pin = $host . ':' . $parts['port'] . ':' . ( strpos( $pinned, ':' ) !== false ? '[' . $pinned . ']' : $pinned );
        return array( array( $pin ), $pinned );
    }

    /** @return string[] the IPv4 and IPv6 addresses of the host */
    public static function resolve( $host )
    {
        if ( self::$resolver )
            return array_values( (array) call_user_func( self::$resolver, $host ) );
        $out = array();
        $records = @dns_get_record( $host, DNS_A | DNS_AAAA );
        foreach ( is_array( $records ) ? $records : array() as $record )
        {
            if ( isset( $record['ip'] ) )
                $out[] = $record['ip'];
            elseif ( isset( $record['ipv6'] ) )
                $out[] = $record['ipv6'];
        }
        if ( !$out )
        {
            $v4 = @gethostbynamel( $host );
            if ( is_array( $v4 ) )
                $out = $v4;
        }
        return array_values( array_unique( $out ) );
    }
}
