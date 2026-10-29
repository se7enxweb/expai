<?php
/**
 * File containing the expAiSegmenterText class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * eztext: blocks are paragraphs (separated by blank lines), split into sentences. Line breaks inside a paragraph
 * stay part of its raw text; merge() and compose() join paragraphs with one blank line.
 */
class expAiSegmenterText extends expAiSegmenterBase
{
    protected function pieces( $value )
    {
        $value = str_replace( array( "\r\n", "\r" ), "\n", (string) $value );
        $pieces = array();
        foreach ( preg_split( "/\n[ \t]*\n/", $value ) as $piece )
            if ( trim( $piece ) !== '' )
                $pieces[] = trim( $piece );
        return $pieces;
    }

    protected function join( array $pieces )
    {
        return implode( "\n\n", $pieces );
    }
}
