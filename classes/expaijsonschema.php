<?php
/**
 * File containing the expAiJsonSchema class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A small check of tool input against the tool's JSON schema (the subset expai's tools use: type, required,
 * properties, additionalProperties false, items, enum). Used for models without native tool calling, whose calls
 * are plain JSON written into the text.
 */
class expAiJsonSchema
{
    /**
     * @param array|object $schema
     * @param mixed $value
     * @return string '' when valid, else what is wrong (English, for the model)
     */
    public static function validate( $schema, $value, $path = 'input' )
    {
        $schema = json_decode( json_encode( $schema ), true );
        if ( !is_array( $schema ) )
            return '';
        if ( isset( $schema['type'] ) )
        {
            $types = (array) $schema['type'];
            $ok = false;
            foreach ( $types as $type )
                if ( self::isType( $value, $type ) )
                    $ok = true;
            if ( !$ok )
                return "$path must be " . implode( ' or ', $types );
        }
        if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && !in_array( $value, $schema['enum'], true ) )
            return "$path must be one of " . implode( ', ', array_map( 'json_encode', $schema['enum'] ) );
        if ( is_array( $value ) && self::isType( $value, 'object' ) )
        {
            foreach ( isset( $schema['required'] ) ? (array) $schema['required'] : array() as $name )
                if ( !array_key_exists( $name, $value ) )
                    return "$path.$name is required";
            $properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
            foreach ( $value as $name => $item )
            {
                if ( isset( $properties[$name] ) )
                {
                    $error = self::validate( $properties[$name], $item, "$path.$name" );
                    if ( $error !== '' )
                        return $error;
                }
                elseif ( isset( $schema['additionalProperties'] ) && $schema['additionalProperties'] === false )
                    return "$path.$name is not a known field (allowed: " . implode( ', ', array_keys( $properties ) ) . ')';
                elseif ( isset( $schema['additionalProperties'] ) && is_array( $schema['additionalProperties'] ) )
                {
                    $error = self::validate( $schema['additionalProperties'], $item, "$path.$name" );
                    if ( $error !== '' )
                        return $error;
                }
            }
        }
        if ( is_array( $value ) && self::isType( $value, 'array' ) && isset( $schema['items'] ) && is_array( $schema['items'] ) )
            foreach ( $value as $i => $item )
            {
                $error = self::validate( $schema['items'], $item, $path . '[' . $i . ']' );
                if ( $error !== '' )
                    return $error;
            }
        return '';
    }

    /** @return bool */
    protected static function isType( $value, $type )
    {
        switch ( $type )
        {
            case 'object':
                return is_array( $value ) && ( !$value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) );
            case 'array':
                return is_array( $value ) && ( !$value || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
            case 'string':
                return is_string( $value );
            case 'integer':
                return is_int( $value ) || ( is_float( $value ) && floor( $value ) == $value ) || ( is_string( $value ) && ctype_digit( $value ) );
            case 'number':
                return is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) );
            case 'boolean':
                return is_bool( $value );
            case 'null':
                return $value === null;
        }
        return true;
    }
}
