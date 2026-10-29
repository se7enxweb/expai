<?php
/**
 * File containing the expAiDiffResult class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The result of expAiDiff::compare(): per attribute the blocks, operations, hunks and metrics, and the metrics of the
 * whole object (characters weighted over the attributes). toArray() is what is stored or sent to the browser.
 */
class expAiDiffResult
{
    protected $attributes;
    protected $info;

    public function __construct( array $attributes, array $info = array() )
    {
        $this->attributes = $attributes;
        $this->info = $info;
    }

    /** @return mixed object_id, version_a, version_b, language_a, language_b, language */
    public function get( $name )
    {
        return isset( $this->info[$name] ) ? $this->info[$name] : null;
    }

    /** @return array identifier => attribute diff (datatype, base_value, prop_value, base_blocks, prop_blocks, ops, hunks, metrics) */
    public function attributes()
    {
        return $this->attributes;
    }

    /** @return array|null */
    public function attribute( $identifier )
    {
        return isset( $this->attributes[$identifier] ) ? $this->attributes[$identifier] : null;
    }

    /** @return array[] every hunk, attribute by attribute */
    public function hunks()
    {
        $out = array();
        foreach ( $this->attributes as $attribute )
            foreach ( $attribute['hunks'] as $hunk )
                $out[] = $hunk;
        return $out;
    }

    /** @return string[] */
    public function hunkIds()
    {
        return array_column( $this->hunks(), 'id' );
    }

    /** @return array|null */
    public function hunk( $id )
    {
        foreach ( $this->hunks() as $hunk )
            if ( $hunk['id'] === (string) $id )
                return $hunk;
        return null;
    }

    /** @return bool */
    public function isEmpty()
    {
        return !$this->hunks();
    }

    /**
     * The metrics of the object: chars, changed_chars, change_ratio, blocks, blocks_changed, sentences,
     * sentences_changed, hunks, attributes_changed, structural_only.
     * @return array
     */
    public function metrics()
    {
        $m = array( 'chars' => 0, 'prop_chars' => 0, 'changed_chars' => 0, 'blocks' => 0, 'blocks_changed' => 0, 'sentences' => 0,
                    'sentences_changed' => 0, 'hunks' => 0, 'attributes_changed' => 0, 'structural' => 0 );
        foreach ( $this->attributes as $attribute )
        {
            $a = $attribute['metrics'];
            $m['chars'] += $a['base_chars'];
            $m['prop_chars'] += $a['prop_chars'];
            $m['changed_chars'] += $a['changed_chars'];
            $m['blocks'] += $a['blocks'];
            $m['blocks_changed'] += $a['blocks_changed'];
            $m['sentences'] += $a['sentences'];
            $m['sentences_changed'] += $a['sentences_changed'];
            $m['hunks'] += $a['hunks'];
            $m['structural'] += $a['structural'];
            $m['attributes_changed'] += $a['hunks'] > 0 ? 1 : 0;
        }
        $m['change_ratio'] = expAiDiff::ratio( $m['changed_chars'], $m['chars'], $m['prop_chars'] );
        $m['block_ratio'] = $m['blocks'] ? round( min( 1, $m['blocks_changed'] / $m['blocks'] ), 4 ) : ( $m['blocks_changed'] ? 1.0 : 0.0 );
        $m['sentence_ratio'] = $m['sentences'] ? round( min( 1, $m['sentences_changed'] / $m['sentences'] ), 4 ) : ( $m['sentences_changed'] ? 1.0 : 0.0 );
        $m['structural_only'] = $m['hunks'] > 0 && $m['changed_chars'] === 0;
        return $m;
    }

    /**
     * @param bool $withValues include the values and blocks (large)
     * @return array info, metrics, attributes (identifier => datatype, name, metrics, hunks[, values, blocks])
     */
    public function toArray( $withValues = false )
    {
        $attributes = array();
        foreach ( $this->attributes as $identifier => $attribute )
        {
            $row = array( 'datatype' => $attribute['datatype'], 'name' => isset( $attribute['name'] ) ? $attribute['name'] : $identifier,
                          'metrics' => $attribute['metrics'], 'hunks' => array() );
            foreach ( $attribute['hunks'] as $hunk )
            {
                unset( $hunk['op_indexes'] );
                $row['hunks'][] = $hunk;
            }
            if ( $withValues )
                $row += array( 'base_value' => $attribute['base_value'], 'prop_value' => $attribute['prop_value'],
                               'base_blocks' => $attribute['base_blocks'], 'prop_blocks' => $attribute['prop_blocks'], 'ops' => $attribute['ops'] );
            $attributes[$identifier] = $row;
        }
        return array( 'info' => $this->info, 'metrics' => $this->metrics(), 'attributes' => $attributes );
    }
}
