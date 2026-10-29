<?php
/**
 * File containing the expAiAltTextServerFunctions class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The ezjscore function of the "Write alt text" button (ezjscore.ini [ezjscServer_expaialttext]; the caller needs
 * ezjscore/call with the function list "expai"):
 *
 *   POST <root>/ezjscore/call  ezjscServer_function_arguments=expaialttext::suggest, ezxform_token, object_id,
 *        version, language, attribute_id, value (the alt text in the form), context (JSON identifier => text)
 *   -> { value, decorative, note, model, model_label, image { width, height, bytes, resized, image_tokens }, cost }
 *
 * The same checks as expai::field (POST, form token, [AISettings] Enabled, expai/write, the editor's own draft),
 * then expAiAltText::suggest() on the stored image of the draft. The answer only goes into the form.
 */
class expAiAltTextServerFunctions extends expAiServerFunctions
{
    /** suggest: the alt text of one ezimage field of the edit form */
    public static function suggest( $args )
    {
        $config = self::guard();
        return self::run( function () use ( $config )
        {
            if ( function_exists( 'set_time_limit' ) )
                @set_time_limit( self::timeout( $config ) + 30 );
            list( $object, $version, $language ) = expAiEditForm::draft( self::post( 'object_id' ), self::post( 'version' ), self::post( 'language' ) );
            $attribute = null;
            foreach ( $object->fetchDataMap( (int) $version->attribute( 'version' ), $language ) as $candidate )
                if ( (int) $candidate->attribute( 'id' ) === (int) self::post( 'attribute_id' ) )
                    $attribute = $candidate;
            if ( !$attribute )
                throw new expAiException( 'The field was not found in this draft.' );
            $action = ( new expAiFieldActionRegistry( $config ) )->get( 'alt_text' );
            $datatypes = $action ? $action->datatypes() : array( 'ezimage' );
            if ( !in_array( (string) $attribute->attribute( 'data_type_string' ), $datatypes, true ) )
                throw new expAiException( 'This action is not available for this field.' );
            $context = json_decode( self::post( 'context' ), true );
            $context = is_array( $context ) ? array_slice( $context, 0, 50, true ) : array();
            if ( !$context )
                $context = expAiAltText::contextFromVersion( $object, (int) $version->attribute( 'version' ), $language );
            $result = expAiAltText::suggest( $config, $object, $attribute, $language, $context, expAiText::cut( self::post( 'value' ), 1000 ) );
            expAiEditForm::mark( $object, $version, $language, 'field', 'alt_text', $result['model'], $result['provider'] );
            return array( 'value' => $result['value'], 'decorative' => $result['decorative'], 'note' => $result['note'],
                          'model' => $result['model'], 'model_label' => $result['model_label'],
                          'image' => array( 'width' => $result['image']['width'], 'height' => $result['image']['height'], 'bytes' => $result['image']['bytes'],
                                            'resized' => $result['image']['resized'], 'image_tokens' => $result['image']['image_tokens'] ),
                          'cost' => $result['cost'], 'tokens' => $result['tokens'] );
        } );
    }
}
