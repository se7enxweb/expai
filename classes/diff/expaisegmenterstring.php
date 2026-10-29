<?php
/**
 * File containing the expAiSegmenterString class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * ezstring (and ezemail, ezisbn): the value is one block, split into sentences.
 */
class expAiSegmenterString extends expAiSegmenterBase
{
    protected function pieces( $value )
    {
        $value = trim( (string) $value );
        return $value === '' ? array() : array( $value );
    }

    protected function join( array $pieces )
    {
        return implode( ' ', $pieces );
    }

    protected function type()
    {
        return 'value';
    }
}
