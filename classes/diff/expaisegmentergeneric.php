<?php
/**
 * File containing the expAiSegmenterGeneric class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The fallback for datatypes without a segmenter of their own: the value as a string (toString()) is one block,
 * not split into sentences. Changes of such attributes are found and shown; whether they are translated is decided
 * by [TranslationSync] Datatypes[].
 */
class expAiSegmenterGeneric extends expAiSegmenterBase
{
    protected function pieces( $value )
    {
        $value = trim( (string) $value );
        return $value === '' ? array() : array( $value );
    }

    protected function join( array $pieces )
    {
        return implode( '', $pieces );
    }

    protected function type()
    {
        return 'value';
    }

    protected function sentences()
    {
        return false;
    }
}
