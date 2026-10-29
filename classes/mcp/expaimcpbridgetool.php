<?php
/**
 * File containing the expAiMcpBridgeTool class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A bridge-safe read tool of the extension expmcp, called in process as the current user (exp__<tool>).
 *
 * With expmcp 0.3.1 or later the call goes through expmcp's own bridge seam, expMcpBridge::call(): the tool runs in
 * bridge mode, as eZUser::currentUser(), answers only what that user may read and never writes; expmcp's siteaccess
 * allowlist ([Security] AllowedSiteAccesses) still applies. With an older expmcp (no expMcpBridge) only
 * exp_content_query is offered, built the old way with expmcp's security object and a logger that drops everything.
 *
 * On top of what the user may read, the data policy of the panel's provider applies: rows of a list (nodes, items,
 * locations) whose object it keeps away are left out, and an answer about one object it keeps away (a top-level
 * object_id or node_id) is a tool error.
 */
class expAiMcpBridgeTool extends expAiTool
{
    /** The lists of rows in bridge answers; each row carries an object_id or a node_id. */
    const ROW_LISTS = array( 'nodes', 'items', 'locations' );

    /** @var string the expmcp tool name */
    protected $mcpName;

    /** @var array|null the entry of expAiMcp::LEGACY_BRIDGE_TOOLS (older expmcp), null with the bridge seam */
    protected $spec;

    /** @return expAiMcpBridgeTool|null the bridge tool for an expmcp tool name (null: not bridge-safe or not installed) */
    public static function create( $mcpName )
    {
        $mcpName = (string) $mcpName;
        if ( expAiMcp::hasBridgeSeam() )
        {
            if ( !expMcpBridge::isBridgeSafe( $mcpName ) )
            {
                eZDebug::writeWarning( "expai: the expmcp tool $mcpName is not bridge-safe and is not offered", __METHOD__ );
                return null;
            }
            $tool = new self( 'exp__' . $mcpName );
            $tool->mcpName = $mcpName;
            return $tool;
        }
        // an older expmcp without the bridge seam
        $tools = expAiMcp::LEGACY_BRIDGE_TOOLS;
        if ( !isset( $tools[$mcpName] ) )
        {
            eZDebug::writeNotice( "expai: the expmcp tool $mcpName needs expmcp 0.3.1 or later for the bridge and is not offered", __METHOD__ );
            return null;
        }
        if ( !expAiConfig::extensionActive( 'expmcp' ) || !class_exists( $tools[$mcpName][0] ) || !class_exists( 'expMcpSecurity' ) || !class_exists( 'Psr\\Log\\NullLogger' ) )
            return null;
        $tool = new self( 'exp__' . $mcpName );
        $tool->mcpName = $mcpName;
        $tool->spec = $tools[$mcpName];
        return $tool;
    }

    /** @return string the expmcp tool name */
    public function mcpName()
    {
        return $this->mcpName;
    }

    public function description()
    {
        $text = $this->spec ? call_user_func( array( $this->spec[0], $this->spec[3] ) ) : expMcpBridge::description( $this->mcpName );
        return '[Exponential, read only] ' . $text;
    }

    public function inputSchema()
    {
        return $this->spec ? call_user_func( array( $this->spec[0], $this->spec[4] ) ) : expMcpBridge::inputSchema( $this->mcpName );
    }

    public function stepText( array $input )
    {
        return 'Queried the content (%tool)';
    }

    /** @return object the expmcp tool, built as an older expmcp builds it, read-only */
    protected function legacyInstance()
    {
        $ini = eZINI::instance( 'expmcp.ini' );
        $security = new expMcpSecurity( $ini, false, 'stdio' );
        $logger = new Psr\Log\NullLogger();
        $siteAccess = isset( $GLOBALS['eZCurrentAccess']['name'] ) ? (string) $GLOBALS['eZCurrentAccess']['name'] : '';
        $class = $this->spec[0];
        return new $class( $security, $logger, $siteAccess, $ini, null );
    }

    /** @return mixed the tool's answer */
    protected function run( array $input )
    {
        if ( !$this->spec )
            return expMcpBridge::call( $this->mcpName, $input );
        $arguments = array();
        foreach ( $this->spec[2] as $name )
            $arguments[] = array_key_exists( $name, $input ) ? $input[$name] : null;
        return call_user_func_array( array( $this->legacyInstance(), $this->spec[1] ), $arguments );
    }

    public function execute( array $input, expAiToolContext $context )
    {
        try
        {
            $result = $this->run( $input );
        }
        catch ( Throwable $e )
        {
            throw new expAiException( 'The tool %tool failed: %error', get_class( $e ), 0, array( '%tool' => $this->name, '%error' => expAiText::cut( $e->getMessage(), 200 ) ) );
        }
        if ( !is_array( $result ) )
            return array( 'result' => $result );
        return self::applyDataPolicy( $result, $context->reader );
    }

    /**
     * The answer without what the data policy keeps away from the provider.
     * @return array
     * @throws expAiException when the answer is about one object the policy keeps away
     */
    public static function applyDataPolicy( array $result, expAiContentReader $reader )
    {
        if ( !$reader->filters() || isset( $result['error'] ) )
            return $result;
        // an answer about one object (exp_content_get, exp_content_meta, the object of relations and versions)
        $object = self::rowObject( $result );
        if ( $object )
            $reader->assertAllowed( $object );
        foreach ( self::ROW_LISTS as $list )
        {
            if ( !isset( $result[$list] ) || !is_array( $result[$list] ) )
                continue;
            $kept = array();
            foreach ( $result[$list] as $row )
            {
                $object = is_array( $row ) ? self::rowObject( $row ) : null;
                if ( $object && !$reader->allowed( $object ) )
                {
                    $reader->withheld++;
                    continue;
                }
                $kept[] = $row;
            }
            $result[$list] = $kept;
            if ( array_key_exists( 'returned', $result ) && $list !== 'locations' )
                $result['returned'] = count( $kept );
        }
        return $result;
    }

    /** @return eZContentObject|null the object a row or an answer names by object_id, else by node_id */
    protected static function rowObject( array $row )
    {
        if ( !empty( $row['object_id'] ) && is_numeric( $row['object_id'] ) )
        {
            $object = expAiObjects::fetch( (int) $row['object_id'] );
            if ( $object instanceof eZContentObject )
                return $object;
        }
        if ( !empty( $row['node_id'] ) && is_numeric( $row['node_id'] ) )
        {
            $node = eZContentObjectTreeNode::fetch( (int) $row['node_id'] );
            if ( $node instanceof eZContentObjectTreeNode && $node->object() instanceof eZContentObject )
                return $node->object();
        }
        return null;
    }
}
