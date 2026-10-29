<?php
/**
 * File containing the expAiAltTextFieldAction class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The field action "Write alt text" ([FieldAction_alt_text], doc/alt-text.md) of ezimage fields. The edit form calls
 * it through expaialttext::suggest (expAiAltTextServerFunctions), which chooses a vision model the data policy
 * allows; run() is the same work on a provider given by the caller, which must be able to look at images.
 */
class expAiAltTextFieldAction extends expAiPromptFieldAction
{
    public function label()
    {
        return (string) $this->setting( 'Label', 'Write alt text' );
    }

    public function datatypes()
    {
        $list = $this->config->arr( 'FieldAction_' . $this->name, 'Datatypes' );
        return $list ? $list : array( 'ezimage' );
    }

    public function input()
    {
        return 'none';
    }

    /**
     * @return array value, note, model, decorative, image, cost
     * @throws expAiException
     */
    public function run( expAiFieldRequest $request, expAiProvider $provider )
    {
        $alias = method_exists( $provider, 'modelAlias' ) ? (string) $provider->modelAlias() : '';
        $model = $alias !== '' ? $this->config->registry()->getModel( $alias ) : null;
        if ( !$model || !in_array( 'vision', $model['capabilities'], true ) )
            throw new expAiException( 'This model cannot look at images. Choose a model that can (Capabilities[]=vision).' );
        $policy = expAiDataPolicy::forProvider( $this->config, $provider->providerId() );
        $requirement = expAiAltText::requirement( $this->config, $policy, $request->object );
        if ( !$policy->meets( $requirement ) )
            throw new expAiException( 'The data policy keeps the image of "%name" away from every model that can look at images: %rule.', 'data policy', 0, array(
                '%name' => expAiText::cut( (string) $request->object->attribute( 'name' ), 80 ), '%rule' => $policy->describe( $requirement ) ) );
        $image = expAiImageInput::fromAttribute( $request->attribute, expAiImageInput::limitsFor( $this->config, $provider->providerId() ),
                                                 (string) $this->config->get( 'AltTextSettings', 'ImageAlias', 'original' ) );
        $recorder = $provider instanceof expAiUsageRecorder ? $provider : new expAiUsageRecorder( $provider, array( 'action' => 'field:alt_text' ) );
        $text = expAiAltText::userText( $this->config, $request->object, $request->language, (string) $request->classAttribute->attribute( 'name' ),
                                        $request->context, (string) $request->value );
        return expAiAltText::ask( $this->config, $recorder, $image, $text );
    }
}
