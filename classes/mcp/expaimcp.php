<?php
/**
 * File containing the expAiMcp class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * expai as an MCP client, two layers:
 *
 *   exp__<tool>            the in-process bridge to the bridge-safe read tools of the extension expmcp
 *                          (expMcpBridge), run as the current user ([McpBridge] Enabled, Tools[] as the opt-in
 *                          allowlist; policy expai/mcp with Server "exp")
 *   mcp__<server>__<tool>  external MCP servers over streamable HTTP ([McpSettings] Servers[], [McpServer_<id>]
 *                          URL, AllowedTools[] ...; policy expai/mcp with Server <id>)
 *
 * Both are read-only: no MCP tool writes, and after an external tool's result no write tool runs for the rest of
 * the turn (expAiAssistant). Results are wrapped as data and capped.
 */
class expAiMcp
{
    /**
     * The bridge with an older expmcp, before 0.3.1 brought its own bridge seam (expMcpBridge): name => array( class,
     * method, parameters, description method, schema method ). Only exp_content_query applied the current user's
     * permissions there. With expMcpBridge, expMcpBridge::isBridgeSafe() decides instead (see bridgeSafe()).
     */
    const LEGACY_BRIDGE_TOOLS = array(
        'exp_content_query' => array( 'expMcpContentTool', 'runQuery',
            array( 'parent_node_id', 'class_identifier', 'depth', 'limit', 'offset', 'sort_by', 'attribute_filter', 'name_contains', 'main_node_only', 'with_child_count' ),
            'queryDescription', 'queryInputSchema' ),
    );

    /** @var bool|null tests only: true or false pretends expmcp has or lacks the bridge seam; null detects it */
    public static $bridgeSeam = null;

    /** @return bool expmcp offers the bridge seam expMcpBridge (0.3.1 or later) */
    public static function hasBridgeSeam()
    {
        if ( self::$bridgeSeam !== null )
            return self::$bridgeSeam && class_exists( 'expMcpBridge' );
        return class_exists( 'expMcpBridge' ) && method_exists( 'expMcpBridge', 'call' ) && method_exists( 'expMcpBridge', 'isBridgeSafe' )
            && expAiConfig::extensionActive( 'expmcp' );
    }

    /** @return bool expmcp may run the tool in process as the current user */
    public static function bridgeSafe( $name )
    {
        if ( self::hasBridgeSeam() )
            return expMcpBridge::isBridgeSafe( (string) $name );
        return isset( self::LEGACY_BRIDGE_TOOLS[(string) $name] );
    }

    /** @return string[] the expmcp tools the bridge could offer with the installed expmcp */
    public static function bridgeSafeNames()
    {
        if ( self::hasBridgeSeam() )
            return expMcpBridge::toolNames();
        return class_exists( 'expMcpContentTool' ) && expAiConfig::extensionActive( 'expmcp' ) ? array_keys( self::LEGACY_BRIDGE_TOOLS ) : array();
    }

    /** @return bool the name is an MCP tool (bridge or external) */
    public static function isMcpTool( $name )
    {
        return strpos( (string) $name, 'exp__' ) === 0 || strpos( (string) $name, 'mcp__' ) === 0;
    }

    /** @return bool the name is a tool of an external MCP server */
    public static function isExternal( $name )
    {
        return strpos( (string) $name, 'mcp__' ) === 0;
    }

    /**
     * The MCP tools the current user may use.
     * @return expAiTool[] name => tool
     */
    public static function tools( expAiConfig $config )
    {
        $out = array();
        if ( $config->flag( 'McpBridge', 'Enabled', false ) && expAiPolicy::allows( 'mcp', 'Server', 'exp' ) )
        {
            foreach ( $config->arr( 'McpBridge', 'Tools' ) as $name )
            {
                $tool = expAiMcpBridgeTool::create( $name );
                if ( $tool )
                    $out[$tool->name()] = $tool;
            }
        }
        foreach ( $config->arr( 'McpSettings', 'Servers' ) as $id )
        {
            if ( !preg_match( '/^[a-z0-9]{1,20}$/', $id ) || !$config->ini()->hasGroup( 'McpServer_' . $id ) || !expAiPolicy::allows( 'mcp', 'Server', $id ) )
                continue;
            $settings = $config->ini()->group( 'McpServer_' . $id ) + array( 'ServerId' => $id );
            $settings['ServerId'] = $id;
            try
            {
                $client = new expAiMcpClient( $settings );
                $allowed = $config->arr( 'McpServer_' . $id, 'AllowedTools' );
                foreach ( $client->cachedTools() as $definition )
                {
                    if ( !isset( $definition['name'] ) || !in_array( $definition['name'], $allowed, true ) )
                        continue;
                    $tool = new expAiMcpRemoteTool( $client, $settings, $definition );
                    if ( strlen( $tool->name() ) <= 64 )
                        $out[$tool->name()] = $tool;
                }
            }
            catch ( expAiException $e )
            {
                eZDebug::writeWarning( 'expai: MCP server ' . $id . ' is not available: ' . $e->getMessage() . ' (' . $e->detail() . ')', __METHOD__ );
            }
        }
        return $out;
    }
}
