<?php
/**
 * File containing the expAiFieldActionRegistry class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The field actions of expai.ini [EditViewSettings] FieldActions[], and the edit form's description of them.
 */
class expAiFieldActionRegistry
{
    /** @var expAiFieldAction[] */
    protected $actions = array();

    public function __construct( expAiConfig $config )
    {
        foreach ( $config->arr( 'EditViewSettings', 'FieldActions' ) as $name )
        {
            $class = (string) $config->get( 'FieldAction_' . $name, 'Handler', 'expAiPromptFieldAction' );
            if ( !class_exists( $class ) || !in_array( 'expAiFieldAction', class_implements( $class ), true ) )
            {
                eZDebug::writeWarning( "expai: field action $name has no usable handler ($class)", __METHOD__ );
                continue;
            }
            $this->actions[$name] = new $class( $name, $config );
        }
    }

    /** @return expAiFieldAction[] */
    public function actions()
    {
        return $this->actions;
    }

    /** @return expAiFieldAction|null */
    public function get( $name )
    {
        return isset( $this->actions[$name] ) ? $this->actions[$name] : null;
    }

    /**
     * @return array for the edit form: name => { label, input, datatypes } (labels translated); an action that cannot
     *               be used at all (unavailableReason(), e.g. Translate without any translation service) is left out
     */
    public function describe()
    {
        $out = array();
        foreach ( $this->actions as $name => $action )
        {
            if ( method_exists( $action, 'unavailableReason' ) && $action->unavailableReason() !== '' )
                continue;
            $out[] = array( 'name' => $name, 'label' => ezpI18n::tr( 'extension/expai/fieldaction', $action->label() ),
                            'input' => $action->input(), 'datatypes' => $action->datatypes() );
        }
        return $out;
    }

    /**
     * The { value, note } of a structured answer, cleaned for the field.
     * @return array
     * @throws expAiException
     */
    public static function parseStructured( array $answer, expAiFieldRequest $request )
    {
        $stop = isset( $answer['stop_reason'] ) ? (string) $answer['stop_reason'] : '';
        if ( $stop === 'refusal' )
            throw new expAiException( 'Exponential Intelligence declined this request.', 'stop_reason refusal' );
        // a cut-off answer is not read: a shorter object inside the cut JSON is not the answer
        if ( $stop === 'max_tokens' )
            throw new expAiException( 'The answer was cut off at the token limit. Raise MaxTokens or lower Effort for this model.', 'stop_reason max_tokens' );
        $text = '';
        foreach ( $answer['content'] as $block )
            if ( isset( $block['type'] ) && $block['type'] === 'text' )
                $text .= $block['text'];
        // a model without structured output may wrap the JSON in words or a code fence (a reasoning model: after its thinking)
        $data = expAiText::extractJson( expAiText::stripReasoning( $text ) );
        if ( !is_array( $data ) || !isset( $data['value'] ) )
            throw new expAiException( 'The answer of Exponential Intelligence could not be read.', 'no structured value' );
        return array( 'value' => $request->clean( $data['value'] ), 'note' => isset( $data['note'] ) ? (string) $data['note'] : '',
                      'model' => (string) $answer['model'] );
    }
}
