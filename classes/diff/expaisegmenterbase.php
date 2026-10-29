<?php
/**
 * File containing the expAiSegmenterBase class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The plain-text segmenters' common part: blocks are pieces of text joined with a separator; merge() and compose()
 * build the list of pieces and join it. Subclasses define pieces() and join().
 */
abstract class expAiSegmenterBase implements expAiSegmenter
{
    /** @return string[] the raw pieces of a value, in order */
    abstract protected function pieces( $value );

    /** @return string the value of pieces */
    abstract protected function join( array $pieces );

    /** @return string the block type of the pieces */
    protected function type()
    {
        return 'paragraph';
    }

    /** @return bool whether a block is split into sentences */
    protected function sentences()
    {
        return true;
    }

    public function segment( $value, array $options = array() )
    {
        $language = isset( $options['language'] ) ? (string) $options['language'] : 'eng';
        $blocks = array();
        foreach ( $this->pieces( $value ) as $piece )
            $blocks[] = self::block( count( $blocks ), $this->type(), $piece, $piece, '', $this->sentences() ? $language : null );
        return $blocks;
    }

    /**
     * One block array.
     * @param string|null $language null: the block is one sentence
     */
    public static function block( $index, $type, $text, $coded, $markup = '', $language = 'eng', array $atoms = array(), $mergeable = true )
    {
        $normal = expAiSentences::normalize( $text );
        $sentences = array();
        foreach ( $language === null ? ( $normal === '' ? array() : array( $normal ) ) : expAiSentences::split( $normal, $language ) as $sentence )
            $sentences[] = array( 'text' => $sentence, 'hash' => expAiSentences::hash( $sentence ), 'chars' => expAiSentences::chars( $sentence ) );
        $textHash = substr( sha1( $normal ), 0, 16 );
        return array( 'index' => (int) $index, 'type' => (string) $type, 'text' => (string) $text, 'coded' => (string) $coded,
                      'hash' => substr( sha1( $type . "\n" . $normal . "\n" . $markup ), 0, 16 ), 'text_hash' => $textHash,
                      'chars' => expAiSentences::chars( $normal ), 'sentences' => $sentences, 'atoms' => $atoms, 'mergeable' => (bool) $mergeable );
    }

    public function merge( $baseValue, $propValue, array $ops, array $options = array() )
    {
        $base = $this->pieces( $baseValue );
        $prop = $this->pieces( $propValue );
        $out = array();
        foreach ( $ops as $op )
        {
            $accept = !empty( $op['accept'] );
            switch ( $op['op'] )
            {
                case 'equal':
                    $out[] = $base[$op['base']];
                    break;
                case 'modify':
                    $out[] = $accept ? $prop[$op['prop']] : $base[$op['base']];
                    break;
                case 'insert':
                    if ( $accept )
                        $out[] = $prop[$op['prop']];
                    break;
                case 'delete':
                    if ( !$accept )
                        $out[] = $base[$op['base']];
                    break;
            }
        }
        return $this->join( $out );
    }

    public function compose( $value, array $texts, array $options = array() )
    {
        $pieces = $this->pieces( $value );
        foreach ( $texts as $index => $text )
            if ( isset( $pieces[$index] ) )
                $pieces[$index] = trim( (string) ( is_array( $text ) ? $text['text'] : $text ) );
        return $this->join( $pieces );
    }
}
