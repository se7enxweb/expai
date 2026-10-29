<?php
/**
 * File containing the expAiFieldAction interface.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * An action next to a field of the content edit form (write, rewrite, shorten, translate, suggest, ...). Register
 * one in expai.ini: [EditViewSettings] FieldActions[]=<name> and [FieldAction_<name>] Label, Handler (a class
 * implementing this interface), Datatypes[], Input, Instruction. run() returns the new value; the browser puts it
 * into the form for the editor to review. See doc/extending.md.
 */
interface expAiFieldAction
{
    /**
     * @param string $name the action name (the part after FieldAction_)
     * @param expAiConfig $config
     */
    public function __construct( $name, expAiConfig $config );

    /** @return string */
    public function name();

    /** @return string English label (translated in the context extension/expai/fieldaction) */
    public function label();

    /** @return string[] datatype strings the action is offered for */
    public function datatypes();

    /** @return string none, instruction, optional_instruction or language */
    public function input();

    /**
     * @param expAiFieldRequest $request the field, its current value, the other fields, the editor's input
     * @param expAiProvider $provider
     * @return array value (string, in the field's format), note (string)
     * @throws expAiException
     */
    public function run( expAiFieldRequest $request, expAiProvider $provider );
}
