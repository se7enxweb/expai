<?php
/**
 * File containing the expAiWebUrl class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * URLs of the web tools: parsing (http and https only, no user name or password, ports 80 and 443 only, at most
 * 2048 characters), host normalization (lower case, no trailing dot, international names as punycode, IP literals in
 * their canonical form), the allowlist ([WebSettings] AllowedDomains[]: an exact host, or *.domain for its
 * subdomains, matched on label boundaries) and resolving relative links against a base URL.
 */
class expAiWebUrl
{
    const MAX_LENGTH = 2048;

    /**
     * @param string $url
     * @param array $ports ports allowed besides 80 and 443
     * @return array|null scheme, host (normalized), port, target (path and query), url (normalized, no fragment);
     *                    null when the URL is not an allowed web address
     */
    public static function parse( $url, array $ports = array() )
    {
        $url = trim( (string) $url );
        if ( $url === '' || strlen( $url ) > self::MAX_LENGTH || preg_match( '/[\x00-\x20\x7f]/', $url ) )
            return null;
        if ( !preg_match( '~^(https?)://([^/?#]*)([^#]*)~i', $url, $m ) )
            return null;
        $scheme = strtolower( $m[1] );
        $authority = $m[2];
        $target = $m[3] === '' ? '/' : $m[3];
        if ( $target[0] !== '/' )
            $target = '/' . $target;
        // credentials in the URL are never sent
        if ( $authority === '' || strpos( $authority, '@' ) !== false )
            return null;
        $port = null;
        if ( $authority[0] === '[' )
        {
            if ( !preg_match( '/^\[([0-9a-fA-F:.]+)\](?::(\d{1,5}))?$/', $authority, $a ) )
                return null;
            $host = $a[1];
            $port = isset( $a[2] ) && $a[2] !== '' ? (int) $a[2] : null;
        }
        else
        {
            if ( !preg_match( '/^([^:]+)(?::(\d{1,5}))?$/', $authority, $a ) )
                return null;
            $host = $a[1];
            $port = isset( $a[2] ) && $a[2] !== '' ? (int) $a[2] : null;
        }
        $host = self::normalizeHost( $host );
        if ( $host === null )
            return null;
        $default = $scheme === 'https' ? 443 : 80;
        if ( $port === null )
            $port = $default;
        // 80 and 443, and the ports of [WebSettings] AllowedPorts[] (an intranet server, a test server)
        if ( $port !== 80 && $port !== 443 && !in_array( (string) $port, array_map( 'strval', $ports ), true ) )
            return null;
        $hostPart = strpos( $host, ':' ) !== false ? '[' . $host . ']' : $host;
        $normalized = $scheme . '://' . $hostPart . ( $port !== $default ? ':' . $port : '' ) . $target;
        return array( 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'target' => $target, 'url' => $normalized );
    }

    /**
     * @param string $host
     * @return string|null lower case ASCII host (punycode), or the canonical IP address; null when invalid
     */
    public static function normalizeHost( $host )
    {
        $host = rtrim( trim( (string) $host ), '.' );
        if ( $host === '' )
            return null;
        if ( $host[0] === '[' && substr( $host, -1 ) === ']' )
            $host = substr( $host, 1, -1 );
        if ( filter_var( $host, FILTER_VALIDATE_IP ) )
        {
            $packed = @inet_pton( $host );
            return $packed === false ? null : strtolower( inet_ntop( $packed ) );
        }
        // forms an IPv4 parser would accept but filter_var does not (0x7f.1, 2130706433, 127.1): never a host name
        if ( preg_match( '/^[0-9.]+$/', $host ) || preg_match( '/^0x[0-9a-f]+(\.|$)/i', $host ) )
            return null;
        if ( preg_match( '/[^\x21-\x7e]/', $host ) )
        {
            if ( !function_exists( 'idn_to_ascii' ) )
                return null;
            $ascii = idn_to_ascii( $host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46 );
            if ( !is_string( $ascii ) || $ascii === '' )
                return null;
            $host = $ascii;
        }
        $host = strtolower( $host );
        if ( strlen( $host ) > 253 || !preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host ) )
            return null;
        return $host;
    }

    /**
     * @param string $host a normalized host (normalizeHost())
     * @param string[] $patterns exact hosts or *.domain
     * @return bool the host is on the list
     */
    public static function hostAllowed( $host, array $patterns )
    {
        $host = (string) $host;
        if ( $host === '' )
            return false;
        foreach ( $patterns as $pattern )
        {
            $pattern = trim( (string) $pattern );
            if ( $pattern === '' )
                continue;
            if ( strpos( $pattern, '*.' ) === 0 )
            {
                $domain = self::normalizeHost( substr( $pattern, 2 ) );
                // subdomains only, on a label boundary (*.github.com: api.github.com, not evilgithub.com, not github.com)
                if ( $domain !== null && !filter_var( $domain, FILTER_VALIDATE_IP ) && strlen( $host ) > strlen( $domain ) + 1
                     && substr( $host, -( strlen( $domain ) + 1 ) ) === '.' . $domain )
                    return true;
                continue;
            }
            if ( self::normalizeHost( $pattern ) === $host )
                return true;
        }
        return false;
    }

    /**
     * @param string $base an absolute http(s) URL
     * @param string $relative a link as written in a page
     * @return string|null the absolute URL (http or https only), null for other schemes (javascript:, mailto:, data: ...)
     */
    public static function resolve( $base, $relative )
    {
        $relative = trim( html_entity_decode( (string) $relative, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( $relative === '' || $relative[0] === '#' )
            return null;
        if ( preg_match( '~^([a-z][a-z0-9+.-]*):~i', $relative, $m ) )
            return in_array( strtolower( $m[1] ), array( 'http', 'https' ), true ) ? $relative : null;
        if ( !preg_match( '~^(https?:)//([^/?#]*)([^?#]*)~i', (string) $base, $b ) )
            return null;
        if ( strpos( $relative, '//' ) === 0 )
            return $b[1] . $relative;
        $origin = $b[1] . '//' . $b[2];
        if ( $relative[0] === '/' )
            return $origin . self::removeDots( $relative );
        if ( $relative[0] === '?' )
            return $origin . ( $b[3] === '' ? '/' : $b[3] ) . $relative;
        $dir = $b[3] === '' ? '/' : substr( $b[3], 0, strrpos( $b[3], '/' ) + 1 );
        return $origin . self::removeDots( $dir . $relative );
    }

    /** @return string the path with . and .. segments removed (the query is kept) */
    protected static function removeDots( $path )
    {
        $query = '';
        if ( ( $q = strcspn( $path, '?#' ) ) < strlen( $path ) )
        {
            $query = substr( $path, $q );
            $path = substr( $path, 0, $q );
        }
        $out = array();
        foreach ( explode( '/', $path ) as $i => $segment )
        {
            if ( $segment === '.' )
                continue;
            if ( $segment === '..' )
            {
                if ( count( $out ) > 1 )
                    array_pop( $out );
                continue;
            }
            $out[] = $segment;
        }
        $result = implode( '/', $out );
        if ( preg_match( '~/\.\.?$~', $path ) )
            $result .= '/';
        return ( $result === '' || $result[0] !== '/' ? '/' . ltrim( $result, '/' ) : $result ) . $query;
    }
}
