<?php
/**
 * File containing the expAiTool class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A tool the assistant can call. Register one in expai.ini: [ToolSettings] Tools[]=<name> and [Tool_<name>]
 * Class=<class extending expAiTool>. execute() runs as the current user and returns an array (sent to the model as
 * JSON, shortened to [AssistantSettings] MaxToolResultBytes) or throws expAiException (sent as an error result the
 * model can react to). See doc/tools.md and doc/extending.md.
 */
abstract class expAiTool
{
    /** @var string */
    protected $name;

    public function __construct( $name )
    {
        $this->name = (string) $name;
    }

    /** @return string */
    public function name()
    {
        return $this->name;
    }

    /** @return string what the tool does, for the model */
    abstract public function description();

    /** @return array JSON schema of the input (type object) */
    abstract public function inputSchema();

    /**
     * @return array the result
     * @throws expAiException
     */
    abstract public function execute( array $input, expAiToolContext $context );

    /** @return bool the tool writes (creates or changes drafts) */
    public function writes()
    {
        return false;
    }

    /**
     * A short line for the panel, e.g. "Read the class Article" (English; translated in context
     * extension/expai/step with the placeholders of stepArguments()).
     * @return string
     */
    public function stepText( array $input )
    {
        return 'Used the tool %tool';
    }

    /** @return array placeholders of stepText() */
    public function stepArguments( array $input, $result )
    {
        return array( '%tool' => $this->name );
    }

    /** @return array the definition sent to the model */
    public function definition()
    {
        return array( 'name' => $this->name, 'description' => $this->description(), 'input_schema' => $this->inputSchema() );
    }

    /** @return int a node id from the input, the panel's node when not given */
    protected function nodeID( array $input, expAiToolContext $context, $key = 'node_id' )
    {
        return isset( $input[$key] ) && (int) $input[$key] > 0 ? (int) $input[$key] : (int) $context->nodeID;
    }
}
