<?php
/**
 * File containing the expAiPresetServerFunctions class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The ezjscore function of the editor presets and the Improve menu (ezjscore.ini [ezjscServer_expaipreset]; the
 * caller needs ezjscore/call with the function list "expai"):
 *
 *   POST <root>/ezjscore/call  ezjscServer_function_arguments=expaipreset::preset, ezxform_token, object_id, version,
 *        language, attribute_id, kind (preset|improve), action, part, value, context
 *   -> { value, note, action, kind, part, datatype, fallback }
 *
 * The same checks as expai::field (POST, form token, [AISettings] Enabled, expai/write, the editor's own draft),
 * then expAiEditPresets::run().
 */
class expAiPresetServerFunctions extends expAiServerFunctions
{
    /** preset: one preset or Improve entry on one field of the edit form */
    public static function preset( $args )
    {
        $config = self::guard();
        return self::run( function () use ( $config )
        {
            if ( function_exists( 'set_time_limit' ) )
                @set_time_limit( self::timeout( $config ) + 30 );
            $post = self::formPost();
            $post['kind'] = self::post( 'kind', 'preset' );
            $post['part'] = self::post( 'part' );
            return self::withRecord( function () use ( $config, $post )
            {
                return expAiEditPresets::run( $config, $post );
            } );
        } );
    }
}
