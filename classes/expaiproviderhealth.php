<?php
/**
 * File containing the expAiProviderHealth class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Whether a provider can be reached at the moment, for the model pickers (a stopped or unreachable model is shown and
 * cannot be chosen) and for the fallback to the default model (expAiModelFallback):
 *
 *   Managed=ollama           the local server's own API on the loopback address answers (GET /api/version), read the
 *                            way the view expai/models reads it; never by running a command. Not answering: "stopped".
 *   BaseURL on loopback      a TCP connection to its port. Refused: "unreachable".
 *   any other provider       its last request (expai_usage_log) failed at the connection within the cache time, or a
 *                            request of this process did. Never probed from here: no extra request goes out.
 *
 * The answer is kept for [AISettings] HealthCacheSeconds (default 30) per process. The key is the provider and its
 * URL, and every entry carries its time, so a Velocity worker that serves many requests and siteaccesses never
 * reuses a stale or foreign answer; a failed or successful request updates it at once (remember()).
 */
class expAiProviderHealth
{
    const OK = '';
    const STOPPED = 'stopped';
    const UNREACHABLE = 'unreachable';

    /** @var array key => array( time, state ) */
    protected static $cache = array();

    /** @return int seconds an answer is kept (0: checked every time) */
    public static function ttl( expAiConfig $config )
    {
        return $config->int( 'AISettings', 'HealthCacheSeconds', 30, 0, 600 );
    }

    /**
     * @return string '' (reachable, or not known to be down), stopped (a managed local server that does not run) or
     *                unreachable
     */
    public static function state( expAiConfig $config, $providerID )
    {
        $settings = $config->registry()->getProvider( (string) $providerID );
        if ( !$settings )
            return self::OK;
        $key = self::key( $providerID, $settings );
        $ttl = self::ttl( $config );
        if ( isset( self::$cache[$key] ) && $ttl > 0 && time() - self::$cache[$key][0] < $ttl )
            return self::$cache[$key][1];
        $state = self::check( $config, (string) $providerID, $settings, $ttl );
        self::$cache[$key] = array( time(), $state );
        return $state;
    }

    /** Remembers the outcome of a real request of this process (reachable, stopped or unreachable) */
    public static function remember( expAiConfig $config, $providerID, $state )
    {
        $settings = $config->registry()->getProvider( (string) $providerID );
        if ( $settings )
            self::$cache[self::key( $providerID, $settings )] = array( time(), (string) $state );
    }

    /** Forgets every remembered answer (tests) */
    public static function reset()
    {
        self::$cache = array();
    }

    /** @return bool the provider is a local server expai/models manages (Managed=ollama) */
    public static function isManaged( array $settings )
    {
        return isset( $settings['Managed'] ) && strtolower( trim( (string) $settings['Managed'] ) ) === 'ollama';
    }

    /** @return string the state of a provider whose request failed at the connection */
    public static function downState( array $settings )
    {
        return self::isManaged( $settings ) ? self::STOPPED : self::UNREACHABLE;
    }

    protected static function key( $providerID, array $settings )
    {
        return (string) $providerID . '|' . ( isset( $settings['BaseURL'] ) ? (string) $settings['BaseURL'] : '' )
             . '|' . ( isset( $settings['Endpoint'] ) ? (string) $settings['Endpoint'] : '' );
    }

    protected static function check( expAiConfig $config, $providerID, array $settings, $ttl )
    {
        if ( self::isManaged( $settings ) )
        {
            $local = new expAiLocalModel( $config, $providerID );
            $root = $local->rootUrl();
            if ( $root === '' )
                return self::OK;
            try
            {
                $http = new expAiHttpClient( 2, 1, 65536, array( 'content-type' ) );
                list( $status ) = $http->request( $root . '/api/version', array(), '', array( 'method' => 'GET' ) );
            }
            catch ( expAiException $e )
            {
                return self::STOPPED;
            }
            return (int) $status === 200 ? self::OK : self::STOPPED;
        }
        $url = isset( $settings['BaseURL'] ) && $settings['BaseURL'] !== '' ? (string) $settings['BaseURL'] : ( isset( $settings['Endpoint'] ) ? (string) $settings['Endpoint'] : '' );
        $host = strtolower( trim( (string) parse_url( $url, PHP_URL_HOST ), '[]' ) );
        if ( in_array( $host, array( '127.0.0.1', 'localhost', '::1' ), true ) )
        {
            $port = (int) parse_url( $url, PHP_URL_PORT ) ?: ( strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ) === 'https' ? 443 : 80 );
            $socket = @fsockopen( $host === '::1' ? '[::1]' : $host, $port, $errno, $error, 1 );
            if ( !$socket )
                return self::UNREACHABLE;
            fclose( $socket );
            return self::OK;
        }
        return self::recentFailure( $providerID, max( $ttl, 30 ) ) ? self::UNREACHABLE : self::OK;
    }

    /** @return bool the last request to the provider failed at the connection, less than $seconds ago */
    protected static function recentFailure( $providerID, $seconds )
    {
        $rows = eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), array( 'created', 'error_message' ),
            expAiUsage::requestsOnly( array( 'provider' => (string) $providerID ) ), array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => 1 ), false );
        if ( !is_array( $rows ) || !$rows )
            return false;
        $row = $rows[0];
        return time() - (int) $row['created'] < $seconds && expAiModelFallback::isConnectionFailureText( (string) $row['error_message'] );
    }
}
