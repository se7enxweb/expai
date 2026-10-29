<?php
/**
 * File containing the expAiSegmenterKeyword class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * ezkeyword: a set. Every keyword is a block, sorted (case-insensitively) and without duplicates, so the diff of two
 * sets is the keywords added and removed. merge() and compose() give a comma list.
 */
class expAiSegmenterKeyword extends expAiSegmenterBase
{
    protected function pieces( $value )
    {
        $set = array();
        foreach ( is_array( $value ) ? $value : explode( ',', (string) $value ) as $keyword )
        {
            $keyword = expAiSentences::normalize( $keyword );
            if ( $keyword !== '' )
                $set[mb_strtolower( $keyword, 'UTF-8' )] = $keyword;
        }
        ksort( $set, SORT_STRING );
        return array_values( $set );
    }

    protected function join( array $pieces )
    {
        return implode( ', ', $this->pieces( implode( ',', $pieces ) ) );
    }

    protected function type()
    {
        return 'keyword';
    }

    protected function sentences()
    {
        return false;
    }

    /** a set: compose() takes the keywords of the first text as the whole set ("a, b, c") */
    public function compose( $value, array $texts, array $options = array() )
    {
        if ( !$texts )
            return $this->join( $this->pieces( $value ) );
        $all = array();
        foreach ( $texts as $text )
            $all[] = (string) ( is_array( $text ) ? $text['text'] : $text );
        return $this->join( explode( ',', implode( ',', $all ) ) );
    }
}
