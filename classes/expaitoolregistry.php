<?php
/**
 * File containing the expAiToolRegistry class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The tools of expai.ini [ToolSettings] Tools[], each [Tool_<name>] Class=<expAiTool subclass>, plus the MCP tools
 * the current user may use (expAiMcp::tools(): the in-process bridge exp__<tool> and external servers
 * mcp__<server>__<tool>). Write tools are part of the definitions only for models that may write
 * (expAiProbe::writeAllowed()); expAiAssistant refuses them a second time when they are called anyway.
 */
class expAiToolRegistry
{
    /** @var expAiTool[] name => tool */
    protected $tools = array();

    public function __construct( expAiConfig $config, $withMcp = true )
    {
        foreach ( $config->arr( 'ToolSettings', 'Tools' ) as $name )
        {
            $class = (string) $config->get( 'Tool_' . $name, 'Class', '' );
            if ( $class === '' || !class_exists( $class ) || !is_subclass_of( $class, 'expAiTool' ) )
            {
                eZDebug::writeWarning( "expai: tool $name has no usable class ($class)", __METHOD__ );
                continue;
            }
            $tool = new $class( $name );
            // a tool that decides itself whether it is offered (web_fetch, web_search, project_history: the policy
            // expai/web and its settings)
            if ( method_exists( $tool, 'available' ) && !$tool->available( $config ) )
                continue;
            $this->tools[$name] = $tool;
        }
        if ( $withMcp )
            foreach ( expAiMcp::tools( $config ) as $name => $tool )
                if ( !isset( $this->tools[$name] ) )
                    $this->tools[$name] = $tool;
        // service tools (#370 M1): the read services of expservices that opted in and the user may call
        foreach ( expAiServiceTools::tools( $config ) as $name => $tool )
            if ( !isset( $this->tools[$name] ) )
                $this->tools[$name] = $tool;
    }

    /** Adds a tool (tests, extensions) */
    public function add( expAiTool $tool )
    {
        $this->tools[$tool->name()] = $tool;
    }

    /** @return expAiTool[] */
    public function tools()
    {
        return $this->tools;
    }

    /** @return expAiTool|null */
    public function get( $name )
    {
        return isset( $this->tools[$name] ) ? $this->tools[$name] : null;
    }

    /**
     * @param bool $writes include the write tools (only for a model that may write)
     * @return array the definitions for the provider
     */
    public function definitions( $writes = true )
    {
        $out = array();
        foreach ( $this->tools as $tool )
            if ( $writes || !$tool->writes() )
                $out[] = $tool->definition();
        return $out;
    }
}
