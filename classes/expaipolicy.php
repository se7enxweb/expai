<?php
/**
 * File containing the expAiPolicy class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The policies of expai with limitations (module.php): expai/model_use (limitation Provider) and expai/mcp
 * (limitation Server). Also the value lists the role editor offers for them (the class is instantiated by the
 * kernel with an empty array).
 */
class expAiPolicy
{
    public function __construct( $parameters = array() )
    {
    }

    /**
     * What the current user may do with a policy function that has one limitation.
     * @return bool|string[] false: no access; true: every value; else the allowed values
     */
    public static function limitationValues( $function, $limitation, ?eZUser $user = null )
    {
        $user = $user ?: eZUser::currentUser();
        if ( !$user instanceof eZUser || !$user->isRegistered() )
            return false;
        $access = $user->hasAccessTo( 'expai', $function );
        $word = isset( $access['accessWord'] ) ? $access['accessWord'] : 'no';
        if ( $word === 'yes' )
            return true;
        if ( $word !== 'limited' || empty( $access['policies'] ) )
            return false;
        $values = array();
        foreach ( (array) $access['policies'] as $policy )
        {
            // a policy without this limitation allows everything
            if ( !isset( $policy[$limitation] ) )
                return true;
            foreach ( (array) $policy[$limitation] as $value )
                $values[] = (string) $value;
        }
        return array_values( array_unique( $values ) );
    }

    /** @return bool the user may use the value under the policy */
    public static function allows( $function, $limitation, $value, ?eZUser $user = null )
    {
        $values = self::limitationValues( $function, $limitation, $user );
        return $values === true || ( is_array( $values ) && in_array( (string) $value, $values, true ) );
    }

    /** @return array the providers of every configured [Provider_<id>] (the role editor's Provider limitation) */
    public function providers()
    {
        $out = array();
        $config = expAiConfig::instance();
        $ids = $config->arr( 'ProviderSettings', 'Providers' ) ?: array( 'anthropic' );
        foreach ( $config->ini()->groups() as $group => $values )
            if ( strpos( $group, 'Provider_' ) === 0 && !in_array( substr( $group, 9 ), $ids, true ) )
                $ids[] = substr( $group, 9 );
        foreach ( $ids as $id )
        {
            $label = (string) $config->get( 'Provider_' . $id, 'Label', $id === 'anthropic' ? 'Anthropic' : $id );
            $out[] = array( 'id' => $id, 'name' => $label . ' (' . $id . ')' );
        }
        return $out;
    }

    /** @return array the MCP servers ([McpSettings] Servers[] and the in-process bridge "exp") for the Server limitation */
    public function mcpServers()
    {
        $config = expAiConfig::instance();
        $out = array( array( 'id' => 'exp', 'name' => 'Exponential (in-process bridge)' ) );
        foreach ( $config->arr( 'McpSettings', 'Servers' ) as $id )
            $out[] = array( 'id' => $id, 'name' => (string) $config->get( 'McpServer_' . $id, 'Label', $id ) . ' (' . $id . ')' );
        return $out;
    }

    /** @return array the domains of the expservices catalogue, for the limitation Domain of expai/services (#370 M1) */
    public function serviceDomains()
    {
        $out = array();
        if ( expAiServiceTools::available() )
            foreach ( expServicesCatalog::domainClasses() as $domain => $class )
                $out[] = array( 'id' => (string) $domain, 'name' => (string) $domain );
        return $out;
    }
}
