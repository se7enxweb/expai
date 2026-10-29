<?php
/**
 * File containing the expAiAltTextFunctionCollection class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * fetch( 'expai', 'alt_text_model', hash( 'object', $object ) ): the vision model the "Write alt text" button uses
 * for the images of this object, or why there is none (doc/alt-text.md).
 */
class expAiAltTextFunctionCollection
{
    /** @return array result: { available (bool), alias, label, provider, reason (translated, '' when available) } */
    public static function fetchModel( $object )
    {
        $config = expAiConfig::instance();
        $out = array( 'available' => false, 'alias' => '', 'label' => '', 'provider' => '', 'reason' => '' );
        if ( !$object instanceof eZContentObject || !expAiAltText::enabled( $config ) )
        {
            $out['reason'] = ezpI18n::tr( 'extension/expai/alttext', 'Alt text suggestions are switched off.' );
            return array( 'result' => $out );
        }
        try
        {
            $choice = expAiAltText::chooseModel( $config, $object );
            $out = array( 'available' => true, 'alias' => $choice['alias'], 'label' => $choice['label'], 'provider' => $choice['provider'], 'reason' => '' );
        }
        catch ( expAiException $e )
        {
            $out['reason'] = $e->translatedMessage();
        }
        return array( 'result' => $out );
    }

    /**
     * fetch( 'expai', 'alt_text_required', hash( 'attribute', $attribute ) ): whether the image field refuses an empty
     * alternative text (expAiAltText::altTextRequired()), so the edit form leaves out the "Decorative" choice.
     * @return array result: bool
     */
    public static function fetchAltTextRequired( $attribute )
    {
        return array( 'result' => expAiAltText::altTextRequired( $attribute ) );
    }
}
