<?php
/**
 * File containing the expAiServiceTools class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Service tools: the read services of the extension expservices that the assistant may call (doc/service-tools.md).
 *
 * One declaration in a service class's static $services serves three channels: HTTP (ezjscore/call/exp<domain>::
 * <method>), MCP (the tools expmcp generates from the catalogue) and the assistant. A service is offered to the
 * model only when it opts in:
 *
 *   - its declaration carries 'ai' => true, or 'ai' => array( 'summary' => 'text for the model' ), or
 *   - expai.ini [ServiceTools] Services[]=<domain>::<method> or Domains[]=<domain> lists it (a site that opts in
 *     without changing code).
 *
 * 'ai' => false in a declaration keeps a service away even when the settings list it. A service that writes
 * ('write' => true) is never offered, whatever the declaration or the settings say. [ServiceTools] Exclude[] removes
 * single services, Enabled=disabled all of them.
 *
 * For each user only the services they may call are offered: the policy expai/services (limitation Domain), the
 * policy ezjscore/call (the same router check as HTTP) and the service's own access declaration.
 */
class expAiServiceTools
{
    /** The name prefix of the tools, the same names expmcp gives the services (exp_svc_<domain>_<method>). */
    const PREFIX = 'exp_svc_';

    /** @return bool the extension expservices is active in this siteaccess */
    public static function available()
    {
        return class_exists( 'expServicesCatalog' ) && class_exists( 'expServiceBase' ) && expAiConfig::extensionActive( 'expservices' );
    }

    /** @return bool [ServiceTools] Enabled (default enabled; the services still have to opt in) */
    public static function enabled( expAiConfig $config )
    {
        return $config->flag( 'ServiceTools', 'Enabled', true );
    }

    /** @return string the tool name of a service: exp_svc_<domain>_<method in snake case>, at most 64 characters */
    public static function toolName( $domain, $method )
    {
        $snake = strtolower( preg_replace( '/(?<=[a-z0-9])([A-Z])/', '_$1', (string) $method ) );
        $name = trim( preg_replace( '/_+/', '_', preg_replace( '/[^a-z0-9_]+/', '_', self::PREFIX . strtolower( (string) $domain ) . '_' . $snake ) ), '_' );
        if ( strlen( $name ) > 64 )
            $name = substr( $name, 0, 55 ) . '_' . sprintf( '%08x', crc32( $name ) );
        return $name;
    }

    /**
     * Why a declared service is or is not offered on this site (independent of the user).
     * @return string '' when it is offered, else the reason (English, for the models page and the tests)
     */
    public static function siteRefusal( expAiConfig $config, $domain, $method, array $decl )
    {
        if ( !empty( $decl['write'] ) )
            return 'writes';
        if ( array_key_exists( 'ai', $decl ) && $decl['ai'] === false )
            return 'opted out in its declaration';
        $key = $domain . '::' . $method;
        foreach ( $config->arr( 'ServiceTools', 'Exclude' ) as $pattern )
            if ( self::matches( $key, $pattern ) )
                return 'excluded by [ServiceTools] Exclude[]';
        if ( !empty( $decl['ai'] ) )
            return '';
        foreach ( $config->arr( 'ServiceTools', 'Services' ) as $pattern )
            if ( self::matches( $key, $pattern ) )
                return '';
        if ( in_array( (string) $domain, $config->arr( 'ServiceTools', 'Domains' ), true ) )
            return '';
        return 'not opted in';
    }

    /** @return bool "<domain>::<method>" matches a pattern of the settings (exact, or <domain>::* for a whole domain) */
    protected static function matches( $key, $pattern )
    {
        $pattern = trim( (string) $pattern );
        if ( $pattern === $key )
            return true;
        return substr( $pattern, -3 ) === '::*' && strpos( $key, substr( $pattern, 0, -1 ) ) === 0;
    }

    /**
     * The services this site offers, before any user check.
     * @return array tool name => array( domain, class, method, decl )
     */
    public static function siteServices( expAiConfig $config )
    {
        $out = array();
        if ( !self::available() || !self::enabled( $config ) )
            return $out;
        foreach ( expServicesCatalog::domainClasses() as $domain => $class )
        {
            foreach ( (array) $class::$services as $method => $decl )
            {
                if ( !is_array( $decl ) || self::siteRefusal( $config, (string) $domain, (string) $method, $decl ) !== '' )
                    continue;
                $out[self::toolName( $domain, $method )] = array( 'domain' => (string) $domain, 'class' => $class, 'method' => (string) $method, 'decl' => $decl );
            }
        }
        ksort( $out );
        return $out;
    }

    /**
     * Why the current user may not call a service ('' when they may): expai/services, ezjscore/call and the
     * service's own access declaration.
     * @return string
     */
    public static function userRefusal( $domain, $method, array $decl, ?eZUser $user = null )
    {
        $user = $user ?: eZUser::currentUser();
        if ( !$user instanceof eZUser || !$user->isRegistered() )
            return 'not logged in';
        if ( !expAiPolicy::allows( 'services', 'Domain', $domain, $user ) )
            return 'no access to expai/services for the domain ' . $domain;
        $call = $user->hasAccessTo( 'ezjscore', 'call' );
        if ( !isset( $call['accessWord'] ) || $call['accessWord'] === 'no' )
            return 'no access to ezjscore/call';
        // the router's own check (Functions / PermissionPrFunction of [ezjscServer_exp<domain>]), as for HTTP
        if ( class_exists( 'ezjscServerRouter' ) && ezjscServerRouter::getInstance( array( 'exp' . $domain, $method ), true, false ) === null )
            return 'no access to the service through ezjscore/call';
        $access = isset( $decl['access'] ) ? $decl['access'] : 'user';
        if ( is_array( $access ) && count( $access ) >= 2 )
        {
            $result = $user->hasAccessTo( $access[0], $access[1] );
            if ( !isset( $result['accessWord'] ) || $result['accessWord'] === 'no' )
                return 'no access to ' . $access[0] . '/' . $access[1];
        }
        elseif ( $access !== 'public' && $access !== 'user' )
            return 'no valid access declaration';
        return '';
    }

    /**
     * The service tools the current user may call.
     * @return expAiServiceTool[] name => tool
     */
    public static function tools( expAiConfig $config )
    {
        $out = array();
        foreach ( self::siteServices( $config ) as $name => $service )
            if ( self::userRefusal( $service['domain'], $service['method'], $service['decl'] ) === '' )
                $out[$name] = new expAiServiceTool( $name, $service['domain'], $service['class'], $service['method'], $service['decl'] );
        return $out;
    }

    /**
     * Every declared read service with its state on this site, for the models page and the docs.
     * @return array list of domain, method, tool, offered (bool), reason
     */
    public static function report( expAiConfig $config )
    {
        $out = array();
        if ( !self::available() )
            return $out;
        foreach ( expServicesCatalog::domainClasses() as $domain => $class )
            foreach ( (array) $class::$services as $method => $decl )
            {
                if ( !is_array( $decl ) || !empty( $decl['write'] ) )
                    continue;
                $reason = self::enabled( $config ) ? self::siteRefusal( $config, (string) $domain, (string) $method, $decl ) : 'service tools are switched off';
                if ( $reason === 'not opted in' )
                    continue;
                $out[] = array( 'domain' => (string) $domain, 'method' => (string) $method, 'tool' => self::toolName( $domain, $method ),
                                'offered' => $reason === '', 'reason' => $reason );
            }
        return $out;
    }
}
