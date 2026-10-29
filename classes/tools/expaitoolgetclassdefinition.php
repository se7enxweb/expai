<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** get_class_definition: the fields of a content class */
class expAiToolGetClassDefinition extends expAiTool
{
    public function description()
    {
        return 'Reads a content class: its fields with identifier, name, datatype, whether they are required, whether you can write '
             . 'them and in which format. Read it before creating or changing content of that class.';
    }

    public function inputSchema()
    {
        return array( 'type' => 'object', 'properties' => array(
            'class_identifier' => array( 'type' => 'string', 'description' => 'The class identifier, e.g. article' ),
        ), 'required' => array( 'class_identifier' ), 'additionalProperties' => false );
    }

    public function execute( array $input, expAiToolContext $context )
    {
        return $context->reader->classDefinition( isset( $input['class_identifier'] ) ? (string) $input['class_identifier'] : '' );
    }

    public function stepText( array $input )
    {
        return 'Read the class "%class"';
    }

    public function stepArguments( array $input, $result )
    {
        return array( '%class' => isset( $result['name'] ) ? $result['name'] : ( isset( $input['class_identifier'] ) ? $input['class_identifier'] : '' ) );
    }
}
