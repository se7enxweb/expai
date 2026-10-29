<?php
/**
 * File containing the expAiDiff class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The diff of two versions or languages of an object, per attribute, at block and sentence level (doc/diff.md).
 * Used by the translation sync (what changed in the source language) and by Vector's review (accept or reject per
 * hunk).
 *
 *   $diff = expAiDiff::compare( $object, $object, array( 'version_a' => 5, 'version_b' => 7, 'language' => 'eng-GB' ) );
 *   foreach ( $diff->hunks() as $hunk ) ... $hunk['id'], $hunk['op'], $hunk['base_text'], $hunk['prop_text']
 *   $merged = expAiDiff::apply( $diff, array( 'h3f2a...' ) );          values with the accepted hunks
 *   $draft  = expAiDiff::apply( $object, $ids, array( 'diff' => $diff, 'write' => true ) );   ... written as a draft
 *
 * Alignment: the common prefix and suffix of block hashes, then the longest common subsequence; blocks left over
 * between two equal blocks are paired in order when their word similarity reaches [DiffSettings] ModifiedSimilarity
 * (modify), else they are deleted and inserted; a deleted block whose hash is inserted elsewhere is marked moved.
 * Inside a modified block the sentences are aligned the same way. A hunk is a run of changed blocks between two
 * equal blocks; its id is derived from the attribute, the block before it and the hashes it changes, so it stays the
 * same when the diff is computed again.
 */
class expAiDiff
{
    /** the largest LCS table (cells); larger gaps are treated as one replacement */
    const MAX_CELLS = 4000000;

    /**
     * @param eZContentObject|array $a the base: an object (options version_a, language_a or language), array( object,
     *                                 version, language ), or array( 'values' => array( identifier => array( datatype,
     *                                 value ) ), 'language' => ... )
     * @param eZContentObject|array $b the proposal, the same forms (options version_b, language_b)
     * @param array $options attributes (identifiers), translatable_only, datatypes (only these), language (sentences),
     *                       similarity (0..1)
     * @return expAiDiffResult
     */
    public static function compare( $a, $b, array $options = array() )
    {
        $config = isset( $options['config'] ) && $options['config'] instanceof expAiConfig ? $options['config'] : expAiConfig::instance();
        $base = self::side( $a, $options, 'a' );
        $prop = self::side( $b, $options, 'b' );
        $language = isset( $options['language'] ) && $options['language'] !== '' ? (string) $options['language'] : ( $base['language'] !== '' ? $base['language'] : $prop['language'] );
        $attributes = array();
        $identifiers = array_keys( $base['values'] + $prop['values'] );
        foreach ( $identifiers as $identifier )
        {
            if ( !empty( $options['attributes'] ) && !in_array( $identifier, (array) $options['attributes'], true ) )
                continue;
            $row = isset( $base['values'][$identifier] ) ? $base['values'][$identifier] : $prop['values'][$identifier];
            if ( !empty( $options['translatable_only'] ) && isset( $row['can_translate'] ) && !$row['can_translate'] )
                continue;
            if ( !empty( $options['datatypes'] ) && !in_array( $row['datatype'], (array) $options['datatypes'], true ) )
                continue;
            $attributes[$identifier] = self::compareValues( $row['datatype'],
                isset( $base['values'][$identifier] ) ? $base['values'][$identifier]['value'] : '',
                isset( $prop['values'][$identifier] ) ? $prop['values'][$identifier]['value'] : '',
                array( 'language' => $language, 'attribute' => $identifier, 'config' => $config,
                       'similarity' => isset( $options['similarity'] ) ? (float) $options['similarity'] : null ) )
                + array( 'name' => isset( $row['name'] ) ? $row['name'] : $identifier );
        }
        return new expAiDiffResult( $attributes, array(
            'object_id' => $base['object_id'] ?: $prop['object_id'], 'version_a' => $base['version'], 'version_b' => $prop['version'],
            'language_a' => $base['language'], 'language_b' => $prop['language'], 'language' => $language ) );
    }

    /**
     * One side of a comparison as values.
     * @return array values (identifier => datatype, value, can_translate, name), language, object_id, version
     */
    public static function side( $x, array $options, $which )
    {
        if ( is_array( $x ) && isset( $x['values'] ) )
        {
            $values = array();
            foreach ( (array) $x['values'] as $identifier => $row )
                $values[$identifier] = is_array( $row ) ? $row + array( 'datatype' => 'ezstring', 'value' => '', 'can_translate' => true )
                                                        : array( 'datatype' => 'ezstring', 'value' => (string) $row, 'can_translate' => true );
            return array( 'values' => $values, 'language' => isset( $x['language'] ) ? (string) $x['language'] : '',
                          'object_id' => isset( $x['object_id'] ) ? (int) $x['object_id'] : 0, 'version' => isset( $x['version'] ) ? (int) $x['version'] : 0 );
        }
        $object = $x;
        $version = isset( $options['version_' . $which] ) ? (int) $options['version_' . $which] : 0;
        $language = isset( $options['language_' . $which] ) ? (string) $options['language_' . $which] : ( isset( $options['language'] ) ? (string) $options['language'] : '' );
        if ( is_array( $x ) )
        {
            $object = isset( $x['object'] ) ? $x['object'] : null;
            $version = isset( $x['version'] ) ? (int) $x['version'] : $version;
            $language = isset( $x['language'] ) ? (string) $x['language'] : $language;
        }
        if ( is_numeric( $object ) )
            $object = expAiObjects::fetch( (int) $object );
        if ( !$object instanceof eZContentObject )
            throw new expAiException( 'The content to compare was not found.', 'side ' . $which . ' is neither an object nor values' );
        $version = $version ?: (int) $object->attribute( 'current_version' );
        $language = $language !== '' ? $language : (string) $object->attribute( 'initial_language_code' );
        return array( 'values' => self::values( $object, $version, $language ), 'language' => $language,
                      'object_id' => (int) $object->attribute( 'id' ), 'version' => $version );
    }

    /**
     * The values of an object version in a language, as the segmenters read them.
     * @return array identifier => array( datatype, value, can_translate, name )
     */
    public static function values( eZContentObject $object, $version, $language )
    {
        $out = array();
        foreach ( (array) $object->fetchDataMap( (int) $version, (string) $language ) as $identifier => $attribute )
        {
            if ( !$attribute instanceof eZContentObjectAttribute || (string) $attribute->attribute( 'language_code' ) !== (string) $language )
                continue;
            $datatype = (string) $attribute->attribute( 'data_type_string' );
            $value = $datatype === 'ezxmltext' ? (string) $attribute->attribute( 'data_text' ) : (string) $attribute->toString();
            $classAttribute = $attribute->contentClassAttribute();
            $out[$identifier] = array( 'datatype' => $datatype, 'value' => $value, 'can_translate' => (bool) $attribute->attribute( 'can_translate' ),
                                       'name' => $classAttribute ? (string) $classAttribute->attribute( 'name' ) : $identifier );
        }
        return $out;
    }

    /**
     * The diff of two values of one datatype.
     * @param array $options language, attribute (identifier for hunk ids), similarity, config
     * @return array datatype, segmenter, base_value, prop_value, base_blocks, prop_blocks, ops, hunks, metrics
     */
    public static function compareValues( $datatype, $baseValue, $propValue, array $options = array() )
    {
        $config = isset( $options['config'] ) && $options['config'] instanceof expAiConfig ? $options['config'] : expAiConfig::instance();
        $language = isset( $options['language'] ) ? (string) $options['language'] : 'eng';
        $attribute = isset( $options['attribute'] ) ? (string) $options['attribute'] : '';
        $similarity = isset( $options['similarity'] ) && $options['similarity'] !== null ? (float) $options['similarity']
                    : (float) $config->get( 'DiffSettings', 'ModifiedSimilarity', 0.5 );
        $segmenter = expAiSegmenterRegistry::forDatatype( $datatype, $config );
        $baseBlocks = $segmenter->segment( $baseValue, array( 'language' => $language ) );
        $propBlocks = $segmenter->segment( $propValue, array( 'language' => $language ) );
        $ops = self::alignBlocks( $baseBlocks, $propBlocks, $similarity );
        $hunks = self::hunks( $attribute, $ops, $baseBlocks, $propBlocks, $language );
        foreach ( $hunks as $hunk )
            foreach ( $hunk['op_indexes'] as $n )
                $ops[$n]['hunk'] = $hunk['id'];
        return array( 'datatype' => (string) $datatype, 'segmenter' => get_class( $segmenter ), 'base_value' => (string) $baseValue,
                      'prop_value' => (string) $propValue, 'base_blocks' => $baseBlocks, 'prop_blocks' => $propBlocks, 'ops' => $ops,
                      'hunks' => $hunks, 'metrics' => self::metrics( $ops, $hunks, $baseBlocks, $propBlocks ) );
    }

    /**
     * The block operations: equal, modify, insert, delete (with moved), in document order.
     * @return array[] op, base (index or null), prop (index or null), moved (bool)
     */
    public static function alignBlocks( array $base, array $prop, $similarity = 0.5 )
    {
        $pairs = self::lcs( array_column( $base, 'hash' ), array_column( $prop, 'hash' ) );
        $ops = array();
        $i = 0;
        $j = 0;
        $pairs[] = array( count( $base ), count( $prop ) );
        foreach ( $pairs as $pair )
        {
            list( $bi, $pj ) = $pair;
            self::gap( $ops, $base, $prop, range( $i, $bi - 1 ), range( $j, $pj - 1 ), $similarity, $i < $bi, $j < $pj );
            if ( $bi < count( $base ) && $pj < count( $prop ) )
                $ops[] = array( 'op' => 'equal', 'base' => $bi, 'prop' => $pj, 'moved' => false );
            $i = $bi + 1;
            $j = $pj + 1;
        }
        // moved blocks: a deleted block whose hash is inserted elsewhere
        $inserted = array();
        foreach ( $ops as $n => $op )
            if ( $op['op'] === 'insert' )
                $inserted[$prop[$op['prop']]['hash']][] = $n;
        foreach ( $ops as $n => $op )
        {
            if ( $op['op'] !== 'delete' )
                continue;
            $hash = $base[$op['base']]['hash'];
            if ( !empty( $inserted[$hash] ) )
            {
                $m = array_shift( $inserted[$hash] );
                $ops[$n]['moved'] = true;
                $ops[$m]['moved'] = true;
            }
        }
        return $ops;
    }

    /** the operations of a gap between two equal blocks: similar blocks paired in order, the rest deleted and inserted */
    protected static function gap( array &$ops, array $base, array $prop, array $b, array $p, $threshold, $hasB, $hasP )
    {
        $b = $hasB ? $b : array();
        $p = $hasP ? $p : array();
        if ( !$b && !$p )
            return;
        $pairs = array();
        if ( $b && $p && count( $b ) * count( $p ) <= 40000 )
        {
            // best order-preserving pairing by similarity (DP over the gap)
            $nb = count( $b );
            $np = count( $p );
            $score = array_fill( 0, $nb + 1, array_fill( 0, $np + 1, 0.0 ) );
            $sim = array();
            for ( $x = $nb - 1; $x >= 0; --$x )
                for ( $y = $np - 1; $y >= 0; --$y )
                {
                    $s = self::similarity( $base[$b[$x]], $prop[$p[$y]] );
                    $sim[$x][$y] = $s;
                    $best = max( $score[$x + 1][$y], $score[$x][$y + 1] );
                    if ( $s >= $threshold )
                        $best = max( $best, $s + $score[$x + 1][$y + 1] );
                    $score[$x][$y] = $best;
                }
            $x = 0;
            $y = 0;
            while ( $x < $nb && $y < $np )
            {
                if ( $sim[$x][$y] >= $threshold && abs( $score[$x][$y] - ( $sim[$x][$y] + $score[$x + 1][$y + 1] ) ) < 1e-9 )
                {
                    $pairs[] = array( $x, $y );
                    ++$x;
                    ++$y;
                }
                elseif ( $score[$x + 1][$y] >= $score[$x][$y + 1] )
                    ++$x;
                else
                    ++$y;
            }
        }
        $x = 0;
        $y = 0;
        $pairs[] = array( count( $b ), count( $p ) );
        foreach ( $pairs as $pair )
        {
            for ( ; $x < $pair[0]; ++$x )
                $ops[] = array( 'op' => 'delete', 'base' => $b[$x], 'prop' => null, 'moved' => false );
            for ( ; $y < $pair[1]; ++$y )
                $ops[] = array( 'op' => 'insert', 'base' => null, 'prop' => $p[$y], 'moved' => false );
            if ( $pair[0] < count( $b ) && $pair[1] < count( $p ) )
            {
                $ops[] = array( 'op' => 'modify', 'base' => $b[$pair[0]], 'prop' => $p[$pair[1]], 'moved' => false );
                ++$x;
                ++$y;
            }
        }
    }

    /** @return float word similarity (Jaccard) of two blocks; 1 for the same text */
    public static function similarity( array $a, array $b )
    {
        if ( $a['text_hash'] === $b['text_hash'] )
            return 1.0;
        $words = function ( $text )
        {
            $list = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( expAiSentences::normalize( $text ), 'UTF-8' ), -1, PREG_SPLIT_NO_EMPTY );
            return array_flip( $list );
        };
        $wa = $words( $a['text'] );
        $wb = $words( $b['text'] );
        if ( !$wa && !$wb )
            return 1.0;
        $union = count( $wa + $wb );
        return $union ? count( array_intersect_key( $wa, $wb ) ) / $union : 0.0;
    }

    /**
     * Longest common subsequence of two lists of hashes (common prefix and suffix first).
     * @return array[] pairs (index in $a, index in $b), ascending
     */
    public static function lcs( array $a, array $b )
    {
        $n = count( $a );
        $m = count( $b );
        $pairs = array();
        $start = 0;
        while ( $start < $n && $start < $m && $a[$start] === $b[$start] )
        {
            $pairs[] = array( $start, $start );
            ++$start;
        }
        $endA = $n;
        $endB = $m;
        $tail = array();
        while ( $endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1] )
        {
            --$endA;
            --$endB;
            array_unshift( $tail, array( $endA, $endB ) );
        }
        $la = $endA - $start;
        $lb = $endB - $start;
        if ( $la > 0 && $lb > 0 && ( $la + 1 ) * ( $lb + 1 ) <= self::MAX_CELLS )
        {
            $table = array_fill( 0, $la + 1, array_fill( 0, $lb + 1, 0 ) );
            for ( $x = $la - 1; $x >= 0; --$x )
                for ( $y = $lb - 1; $y >= 0; --$y )
                    $table[$x][$y] = $a[$start + $x] === $b[$start + $y] ? $table[$x + 1][$y + 1] + 1 : max( $table[$x + 1][$y], $table[$x][$y + 1] );
            $x = 0;
            $y = 0;
            while ( $x < $la && $y < $lb )
            {
                if ( $a[$start + $x] === $b[$start + $y] )
                {
                    $pairs[] = array( $start + $x, $start + $y );
                    ++$x;
                    ++$y;
                }
                elseif ( $table[$x + 1][$y] >= $table[$x][$y + 1] )
                    ++$x;
                else
                    ++$y;
            }
        }
        return array_merge( $pairs, $tail );
    }

    /**
     * The sentence operations inside a modified block.
     * @return array[] op (equal, modify, insert, delete), base (texts), prop (texts), chars (changed characters)
     */
    public static function sentenceOps( array $baseBlock, array $propBlock )
    {
        $bs = $baseBlock['sentences'];
        $ps = $propBlock['sentences'];
        $pairs = self::lcs( array_column( $bs, 'hash' ), array_column( $ps, 'hash' ) );
        $pairs[] = array( count( $bs ), count( $ps ) );
        $out = array();
        $i = 0;
        $j = 0;
        foreach ( $pairs as $pair )
        {
            $gb = array_slice( $bs, $i, $pair[0] - $i );
            $gp = array_slice( $ps, $j, $pair[1] - $j );
            $k = max( count( $gb ), count( $gp ) );
            for ( $n = 0; $n < $k; ++$n )
            {
                $sb = isset( $gb[$n] ) ? $gb[$n] : null;
                $sp = isset( $gp[$n] ) ? $gp[$n] : null;
                $out[] = array( 'op' => $sb && $sp ? 'modify' : ( $sb ? 'delete' : 'insert' ), 'base' => $sb ? $sb['text'] : '', 'prop' => $sp ? $sp['text'] : '',
                                'chars' => max( $sb ? $sb['chars'] : 0, $sp ? $sp['chars'] : 0 ) );
            }
            if ( $pair[0] < count( $bs ) && $pair[1] < count( $ps ) )
                $out[] = array( 'op' => 'equal', 'base' => $bs[$pair[0]]['text'], 'prop' => $ps[$pair[1]]['text'], 'chars' => 0 );
            $i = $pair[0] + 1;
            $j = $pair[1] + 1;
        }
        return $out;
    }

    /**
     * Hunks: runs of changed blocks between equal blocks.
     * @return array[] id, attribute, op (insert, delete, replace), op_indexes, base (block indexes), prop, changes,
     *                 base_text, prop_text, context_before, context_after, mergeable, chars
     */
    protected static function hunks( $attribute, array $ops, array $base, array $prop, $language )
    {
        $hunks = array();
        $run = array();
        $seen = array();
        $flush = function ( $nextIndex ) use ( &$run, &$hunks, &$seen, $ops, $base, $prop, $attribute )
        {
            if ( !$run )
                return;
            $before = null;
            for ( $k = $run[0] - 1; $k >= 0; --$k )
                if ( $ops[$k]['op'] === 'equal' )
                {
                    $before = $base[$ops[$k]['base']];
                    break;
                }
            $after = $nextIndex !== null && isset( $ops[$nextIndex] ) && $ops[$nextIndex]['op'] === 'equal' ? $base[$ops[$nextIndex]['base']] : null;
            $baseIdx = array();
            $propIdx = array();
            $changes = array();
            $chars = 0;
            $mergeable = true;
            $kinds = array();
            foreach ( $run as $n )
            {
                $op = $ops[$n];
                $kinds[$op['op']] = true;
                $change = array( 'op' => $op['op'], 'base' => $op['base'], 'prop' => $op['prop'], 'moved' => $op['moved'], 'structural' => false, 'sentences' => array() );
                if ( $op['base'] !== null )
                {
                    $baseIdx[] = $op['base'];
                    $mergeable = $mergeable && ( $op['op'] === 'modify' || $base[$op['base']]['mergeable'] );
                }
                if ( $op['prop'] !== null )
                {
                    $propIdx[] = $op['prop'];
                    $mergeable = $mergeable && ( $op['op'] === 'modify' || $prop[$op['prop']]['mergeable'] );
                }
                if ( $op['op'] === 'modify' )
                {
                    $change['structural'] = $base[$op['base']]['text_hash'] === $prop[$op['prop']]['text_hash'];
                    $change['sentences'] = $change['structural'] ? array() : self::sentenceOps( $base[$op['base']], $prop[$op['prop']] );
                    foreach ( $change['sentences'] as $s )
                        $chars += $s['chars'];
                }
                elseif ( !$op['moved'] )
                    $chars += $op['op'] === 'insert' ? $prop[$op['prop']]['chars'] : $base[$op['base']]['chars'];
                $changes[] = $change;
            }
            $key = $attribute . '|' . ( $before ? $before['hash'] : '^' ) . '|' . implode( ',', array_map( function ( $i ) use ( $base ) { return $base[$i]['hash']; }, $baseIdx ) )
                 . '>' . implode( ',', array_map( function ( $i ) use ( $prop ) { return $prop[$i]['hash']; }, $propIdx ) );
            $id = 'h' . substr( sha1( $key ), 0, 12 );
            $seen[$id] = isset( $seen[$id] ) ? $seen[$id] + 1 : 1;
            if ( $seen[$id] > 1 )
                $id .= '-' . $seen[$id];
            $text = function ( array $idx, array $blocks ) { return implode( "\n", array_map( function ( $i ) use ( $blocks ) { return $blocks[$i]['text']; }, $idx ) ); };
            $loses = self::loses( $baseIdx, $base, $propIdx, $prop );
            $hunks[] = array( 'id' => $id, 'attribute' => (string) $attribute, 'loses' => $loses,
                              'op' => count( $kinds ) === 1 && isset( $kinds['insert'] ) ? 'insert' : ( count( $kinds ) === 1 && isset( $kinds['delete'] ) ? 'delete' : 'replace' ),
                              'op_indexes' => $run, 'base' => $baseIdx, 'prop' => $propIdx, 'changes' => $changes,
                              'base_text' => $text( $baseIdx, $base ), 'prop_text' => $text( $propIdx, $prop ),
                              'context_before' => $before ? $before['text'] : '', 'context_after' => $after ? $after['text'] : '',
                              'mergeable' => $mergeable, 'chars' => $chars );
            $run = array();
        };
        foreach ( $ops as $n => $op )
        {
            if ( $op['op'] === 'equal' )
                $flush( $n );
            else
                $run[] = $n;
        }
        $flush( null );
        return $hunks;
    }

    /**
     * The protected elements (custom tags, embeds, anchors, ... of stored rich text: the blocks' "protected") the
     * proposed blocks of a hunk have fewer of than its base blocks. Such a hunk cannot be accepted: taking it would
     * drop them (a formula whose paragraph came back as plain text looks like a change of markup only).
     * @return int[] kind => count
     */
    public static function loses( array $baseIdx, array $base, array $propIdx, array $prop )
    {
        $sum = function ( array $idx, array $blocks )
        {
            $out = array();
            foreach ( $idx as $i )
                foreach ( isset( $blocks[$i]['protected'] ) ? (array) $blocks[$i]['protected'] : array() as $kind => $n )
                    $out[$kind] = ( isset( $out[$kind] ) ? $out[$kind] : 0 ) + (int) $n;
            return $out;
        };
        $before = $sum( $baseIdx, $base );
        $after = $sum( $propIdx, $prop );
        $out = array();
        foreach ( $before as $kind => $n )
            if ( ( isset( $after[$kind] ) ? $after[$kind] : 0 ) < $n )
                $out[$kind] = $n - ( isset( $after[$kind] ) ? $after[$kind] : 0 );
        return $out;
    }

    /** @return array the metrics of one attribute */
    protected static function metrics( array $ops, array $hunks, array $base, array $prop )
    {
        $sum = function ( array $blocks, $key ) { $n = 0; foreach ( $blocks as $b ) $n += is_array( $b[$key] ) ? count( $b[$key] ) : (int) $b[$key]; return $n; };
        $m = array( 'base_chars' => $sum( $base, 'chars' ), 'prop_chars' => $sum( $prop, 'chars' ), 'changed_chars' => 0,
                    'blocks' => count( $base ), 'prop_blocks' => count( $prop ), 'blocks_changed' => 0, 'modified' => 0, 'inserted' => 0,
                    'deleted' => 0, 'moved' => 0, 'structural' => 0, 'sentences' => $sum( $base, 'sentences' ), 'sentences_changed' => 0,
                    'hunks' => count( $hunks ) );
        foreach ( $hunks as $hunk )
        {
            $m['changed_chars'] += $hunk['chars'];
            foreach ( $hunk['changes'] as $change )
            {
                if ( $change['moved'] )
                {
                    $m['moved'] += $change['op'] === 'delete' ? 1 : 0;
                    continue;
                }
                ++$m['blocks_changed'];
                if ( $change['op'] === 'modify' )
                {
                    ++$m['modified'];
                    if ( $change['structural'] )
                        ++$m['structural'];
                    foreach ( $change['sentences'] as $s )
                        if ( $s['op'] !== 'equal' )
                            ++$m['sentences_changed'];
                }
                elseif ( $change['op'] === 'insert' )
                {
                    ++$m['inserted'];
                    $m['sentences_changed'] += count( $prop[$change['prop']]['sentences'] );
                }
                else
                {
                    ++$m['deleted'];
                    $m['sentences_changed'] += count( $base[$change['base']]['sentences'] );
                }
            }
        }
        $m['change_ratio'] = self::ratio( $m['changed_chars'], $m['base_chars'], $m['prop_chars'] );
        $m['structural_only'] = $m['hunks'] > 0 && $m['changed_chars'] === 0;
        return $m;
    }

    /** @return float changed characters / characters of the base (1 when the base is empty and the proposal not) */
    public static function ratio( $changed, $baseChars, $propChars = 0 )
    {
        if ( $baseChars <= 0 )
            return $changed > 0 || $propChars > 0 ? 1.0 : 0.0;
        return round( min( 1.0, $changed / $baseChars ), 4 );
    }

    /**
     * The base with the accepted hunks of a diff.
     * @param expAiDiffResult|eZContentObject $target a diff, or the object to write a draft of (option diff)
     * @param string[] $acceptedHunkIds
     * @param array $options diff (expAiDiffResult, with an object), write (bool: write a draft of the object),
     *                       language (of the draft; default the base language), reuse_version, source (draft log, default "diff"),
     *                       prompt_summary, model, job_uuid, thread_id, change_id, action (draft log: update, or create
     *                       for a new object written by writeDraft())
     * @return array values (identifier => merged value of each attribute with an accepted hunk), applied (ids),
     *               unknown (ids not in the diff); with write also version, language, written, skipped, errors
     * @throws expAiException
     */
    public static function apply( $target, array $acceptedHunkIds, array $options = array() )
    {
        $diff = $target instanceof expAiDiffResult ? $target : ( isset( $options['diff'] ) ? $options['diff'] : null );
        if ( !$diff instanceof expAiDiffResult )
            throw new expAiException( 'The changes could not be applied.', 'apply() needs a diff (expAiDiffResult)' );
        $accepted = array_flip( array_map( 'strval', $acceptedHunkIds ) );
        $result = array( 'values' => array(), 'applied' => array(), 'unknown' => array() );
        $known = array();
        foreach ( $diff->attributes() as $identifier => $attribute )
        {
            $ops = $attribute['ops'];
            $any = false;
            foreach ( $ops as $n => $op )
            {
                $hunk = isset( $op['hunk'] ) ? $op['hunk'] : '';
                if ( $hunk !== '' )
                    $known[$hunk] = true;
                $ops[$n]['accept'] = $hunk !== '' && isset( $accepted[$hunk] );
                $any = $any || $ops[$n]['accept'];
            }
            if ( !$any )
                continue;
            $segmenter = expAiSegmenterRegistry::forDatatype( $attribute['datatype'] );
            $result['values'][$identifier] = $segmenter->merge( $attribute['base_value'], $attribute['prop_value'], $ops );
            foreach ( $attribute['hunks'] as $hunk )
                if ( isset( $accepted[$hunk['id']] ) )
                    $result['applied'][] = $hunk['id'];
        }
        foreach ( array_keys( $accepted ) as $id )
            if ( !isset( $known[$id] ) )
                $result['unknown'][] = (string) $id;
        if ( !empty( $options['structure_guard'] ) )
            self::guardStructure( $diff, $result['values'] );
        if ( empty( $options['write'] ) )
            return $result;

        $object = $target instanceof eZContentObject ? $target : ( isset( $options['object'] ) ? $options['object'] : expAiObjects::fetch( (int) $diff->get( 'object_id' ) ) );
        if ( !$object instanceof eZContentObject )
            throw new expAiException( 'The content to compare was not found.', 'no object to write the draft of' );
        $language = isset( $options['language'] ) && $options['language'] !== '' ? (string) $options['language'] : (string) $diff->get( 'language_a' );
        $types = array();
        foreach ( $diff->attributes() as $identifier => $attribute )
            $types[$identifier] = $attribute['datatype'];
        return $result + self::writeDraft( $object, $language, $result['values'], $types, $options );
    }

    /**
     * The last check before merged rich text is written (option structure_guard of apply()): no value may have fewer
     * custom tags, embeds, tables, ... than its base (expAiRichTextTokens::loss()), whichever parts were accepted.
     * @throws expAiException naming the field and what it would lose
     */
    public static function guardStructure( expAiDiffResult $diff, array $values )
    {
        $attributes = $diff->attributes();
        $tokens = null;
        foreach ( $values as $identifier => $value )
        {
            if ( !isset( $attributes[$identifier] ) || !expAiRichTextTokens::handles( $attributes[$identifier]['datatype'] ) )
                continue;
            $tokens = $tokens ?: new expAiRichTextTokens();
            $loss = $tokens->loss( (string) $attributes[$identifier]['base_value'], (string) $value );
            if ( $loss )
                throw new expAiException( 'The accepted parts would remove %items from the field %field. Nothing was written.', 'structure guard', 0,
                                          array( '%items' => expAiRichTextTokens::describe( $loss ), '%field' => (string) $identifier ) );
        }
    }

    /**
     * Writes values (storage form: ezxmltext XML, other datatypes as fromString() text) into a draft of the current
     * user, and logs it in expai_draft_log (the "Exponential Intelligence" label).
     * @return array version, language, written, skipped, errors, created
     */
    public static function writeDraft( eZContentObject $object, $language, array $values, array $datatypes, array $options = array() )
    {
        $writer = new expAiContentWriter();
        list( $version, $language, $created ) = $writer->draftFor( $object, $language, isset( $options['reuse_version'] ) ? (int) $options['reuse_version'] : 0 );
        $versionNo = (int) $version->attribute( 'version' );
        $dataMap = $object->fetchDataMap( $versionNo, $language );
        $plain = array();
        $result = array( 'written' => array(), 'skipped' => array(), 'errors' => array() );
        $db = eZDB::instance();
        $db->begin();
        try
        {
            foreach ( $values as $identifier => $value )
            {
                if ( !isset( $dataMap[$identifier] ) )
                {
                    $result['errors'][$identifier] = 'no such attribute';
                    continue;
                }
                $attribute = $dataMap[$identifier];
                if ( !$attribute->attribute( 'can_translate' ) && $language !== $object->attribute( 'initial_language_code' ) )
                {
                    $result['skipped'][$identifier] = 'not translatable';
                    continue;
                }
                $datatype = isset( $datatypes[$identifier] ) ? $datatypes[$identifier] : (string) $attribute->attribute( 'data_type_string' );
                if ( $datatype !== 'ezxmltext' )
                {
                    $plain[$identifier] = $value;
                    continue;
                }
                $check = new DOMDocument( '1.0', 'UTF-8' );
                $previous = libxml_use_internal_errors( true );
                $ok = $check->loadXML( (string) $value, LIBXML_NONET );
                libxml_clear_errors();
                libxml_use_internal_errors( $previous );
                if ( !$ok )
                {
                    $result['errors'][$identifier] = 'the rich text is not well-formed';
                    continue;
                }
                $attribute->setAttribute( 'data_text', (string) $value );
                $attribute->store();
                $urlIDs = array();
                foreach ( $check->getElementsByTagName( 'link' ) as $link )
                    if ( $link->hasAttribute( 'url_id' ) )
                        $urlIDs[] = (int) $link->getAttribute( 'url_id' );
                if ( $urlIDs && class_exists( 'eZSimplifiedXMLInput' ) )
                    eZSimplifiedXMLInput::updateUrlObjectLinks( $attribute, array_values( array_unique( $urlIDs ) ) );
                $result['written'][] = $identifier;
            }
            $db->commit();
        }
        catch ( Throwable $e )
        {
            if ( $db->transactionCounter() > 0 )
                $db->rollback();
            throw $e;
        }
        if ( $plain )
        {
            $written = $writer->writeFields( $object, $version, $language, $plain );
            $result['written'] = array_merge( $result['written'], $written['written'] );
            $result['skipped'] += $written['skipped'];
            $result['errors'] += $written['errors'];
        }
        else
            $writer->updateName( $object, $version, $language );
        $node = $object->attribute( 'main_node' );
        $config = isset( $options['config'] ) && $options['config'] instanceof expAiConfig ? $options['config'] : expAiConfig::instance();
        $model = isset( $options['model'] ) ? (string) $options['model'] : '';
        expAiDraftLog::record( array(
            'contentobject_id' => (int) $object->attribute( 'id' ), 'version' => $versionNo,
            'user_id' => (int) eZUser::currentUserID(), 'node_id' => $node ? (int) $node->attribute( 'node_id' ) : 0,
            'parent_node_id' => $node ? (int) $node->attribute( 'parent_node_id' ) : 0, 'language_code' => $language,
            'action' => isset( $options['action'] ) && $options['action'] === 'create' ? 'create' : 'update', 'source' => isset( $options['source'] ) ? (string) $options['source'] : 'diff',
            'model' => $model, 'provider' => isset( $options['provider'] ) ? (string) $options['provider'] : $config->registry()->providerOf( $model ),
            'job_uuid' => isset( $options['job_uuid'] ) ? (string) $options['job_uuid'] : '',
            'thread_id' => isset( $options['thread_id'] ) ? (int) $options['thread_id'] : 0, 'change_id' => isset( $options['change_id'] ) ? (int) $options['change_id'] : 0,
            'prompt_summary' => isset( $options['prompt_summary'] ) ? (string) $options['prompt_summary'] : '' ) );
        return $result + array( 'version' => $versionNo, 'language' => $language, 'created' => $created );
    }
}
