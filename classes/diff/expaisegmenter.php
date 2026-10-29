<?php
/**
 * File containing the expAiSegmenter interface.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Cuts the value of one datatype into blocks and sentences, and puts values together again (doc/diff.md).
 * Registered per datatype in expai.ini [DiffSettings] Segmenters[<datatype>]=<class>; expAiSegmenterRegistry picks
 * the class, expAiDiff aligns the blocks, the translation sync and Vector's review (per hunk) build values with
 * merge() and compose().
 *
 * A block is an array:
 *   index      position in the value (0..n-1)
 *   type       paragraph, heading, list_item, table_cell, custom, value, keyword, line
 *   text       readable text (atoms as [[embed 12]]), not normalised
 *   coded      the text with inline markup as placeholders <1>..</1> and atoms as <2/> (rich text); else = text
 *   hash       identity of the block (type, normalised text, markup); equal hashes = equal blocks
 *   text_hash  identity of the normalised text only (a different hash with the same text_hash = a markup change)
 *   chars      characters of the normalised text
 *   sentences  list of {text, hash, chars}
 *   atoms      list of strings (embeds, images, anchors the block contains)
 *   mergeable  false when the block cannot be inserted, removed or rewritten on its own (a table cell)
 */
interface expAiSegmenter
{
    /**
     * @param mixed $value the stored value (string; ezxmltext XML or HTML; keywords as a comma list)
     * @param array $options language (for sentences)
     * @return array[] blocks
     */
    public function segment( $value, array $options = array() );

    /**
     * Base value with chosen changes of the proposed value.
     * @param array $ops the operations of expAiDiff (op equal|modify|insert|delete, base, prop, accept)
     * @return string the merged value
     * @throws expAiException when an accepted change cannot be merged
     */
    public function merge( $baseValue, $propValue, array $ops, array $options = array() );

    /**
     * A new value with the structure of $value and the text of each block replaced.
     * @param array $texts block index => array( 'text' => coded text, 'from' => array( value, block index ) ) where
     *                     'from' names the block whose placeholders the coded text uses (default: the same block of $value)
     * @return string
     * @throws expAiException when a coded text does not fit its placeholders
     */
    public function compose( $value, array $texts, array $options = array() );
}
