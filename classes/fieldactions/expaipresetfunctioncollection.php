<?php
/**
 * File containing the expAiPresetFunctionCollection class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * fetch( 'expai', 'edit_presets', hash( 'object', $object, 'attributes', $content_attributes ) ): the presets, the
 * Improve menu and the defaults line of an edit form (expAiEditPresets::describe()); false for users without the
 * policy expai/write or when presets are switched off.
 */
class expAiPresetFunctionCollection
{
    public function fetchEditPresets( $object, $attributes )
    {
        if ( !$object instanceof eZContentObject || !expAiServerFunctions::hasPolicy() )
            return array( 'result' => false );
        return array( 'result' => expAiEditPresets::describe( expAiConfig::instance(), $object, is_array( $attributes ) ? $attributes : array() ) );
    }
}
