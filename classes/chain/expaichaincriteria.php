<?php
/**
 * File containing the expAiChainCriteria class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The pass criteria of a chain step ([ChainStep_<chain>.<step>] PassCriteria[]): each entry is one comparison
 * "<metric> <operator> <number>", all of them must hold.
 *
 *   PassCriteria[]=score>=80            the reviewer's score (0..100)
 *   PassCriteria[]=issues.high==0       no issue of severity high (also issues.medium, issues.low)
 *   PassCriteria[]=issues<=5            at most five issues of any severity
 *   PassCriteria[]=checklist.failed==0  every checklist item ([ChainStep_...] Checklist[]) is met
 *   PassCriteria[]=links.broken==0      every link of the text passed the link check (transform steps)
 *
 * Operators: >=, <=, ==, !=, >, <. A metric the step's result does not have counts as 0, except score, which counts
 * as failed when the step returned none.
 */
class expAiChainCriteria
{
    const METRICS = '/^(score|issues|issues\.(high|medium|low)|checklist\.(failed|passed)|links\.(broken|checked)|fields\.missing)$/';

    /**
     * Parses criteria.
     * @param string[] $lines
     * @return array list of array( metric, operator, value, text )
     * @throws expAiException for an entry that is not a known comparison
     */
    public static function parse( array $lines )
    {
        $out = array();
        foreach ( $lines as $line )
        {
            $line = trim( (string) $line );
            if ( $line === '' )
                continue;
            if ( !preg_match( '/^([a-z_.]+)\s*(>=|<=|==|!=|>|<)\s*(-?\d+(?:\.\d+)?)$/', $line, $m ) || !preg_match( self::METRICS, $m[1] ) )
                throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'chain pass criterion not understood: ' . $line );
            $out[] = array( 'metric' => $m[1], 'operator' => $m[2], 'value' => (float) $m[3], 'text' => $m[1] . ' ' . $m[2] . ' ' . $m[3] );
        }
        return $out;
    }

    /**
     * The metrics of a step result (a review: score, issues, checklist; a transform: links, fields).
     * @param array $result score (int|null), issues (list of severity, field, text), checklist (list of item, ok),
     *                      links (list of url, ok), missing (field identifiers)
     * @return array metric => number (score null when the result had none)
     */
    public static function metrics( array $result )
    {
        $metrics = array( 'score' => isset( $result['score'] ) && is_numeric( $result['score'] ) ? (float) $result['score'] : null,
                          'issues' => 0, 'issues.high' => 0, 'issues.medium' => 0, 'issues.low' => 0,
                          'checklist.failed' => 0, 'checklist.passed' => 0, 'links.broken' => 0, 'links.checked' => 0,
                          'fields.missing' => isset( $result['missing'] ) ? count( (array) $result['missing'] ) : 0 );
        foreach ( isset( $result['issues'] ) ? (array) $result['issues'] : array() as $issue )
        {
            ++$metrics['issues'];
            $severity = is_array( $issue ) && isset( $issue['severity'] ) ? strtolower( (string) $issue['severity'] ) : 'medium';
            $severity = in_array( $severity, array( 'high', 'medium', 'low' ), true ) ? $severity : 'medium';
            ++$metrics['issues.' . $severity];
        }
        foreach ( isset( $result['checklist'] ) ? (array) $result['checklist'] : array() as $item )
            ++$metrics[is_array( $item ) && !empty( $item['ok'] ) ? 'checklist.passed' : 'checklist.failed'];
        foreach ( isset( $result['links'] ) ? (array) $result['links'] : array() as $link )
        {
            ++$metrics['links.checked'];
            if ( is_array( $link ) && empty( $link['ok'] ) )
                ++$metrics['links.broken'];
        }
        return $metrics;
    }

    /**
     * Evaluates criteria against a result.
     * @return array pass (bool), failed (the texts of the criteria that did not hold), metrics
     */
    public static function evaluate( array $criteria, array $result )
    {
        $metrics = self::metrics( $result );
        $failed = array();
        foreach ( $criteria as $c )
        {
            $actual = $metrics[$c['metric']];
            if ( $actual === null )
            {
                $failed[] = $c['text'] . ' (no ' . $c['metric'] . ')';
                continue;
            }
            if ( !self::compare( (float) $actual, $c['operator'], (float) $c['value'] ) )
                $failed[] = $c['text'] . ' (was ' . ( floor( $actual ) == $actual ? (int) $actual : $actual ) . ')';
        }
        return array( 'pass' => !$failed, 'failed' => $failed, 'metrics' => $metrics );
    }

    /** @return bool */
    public static function compare( $a, $operator, $b )
    {
        switch ( $operator )
        {
            case '>=': return $a >= $b;
            case '<=': return $a <= $b;
            case '==': return abs( $a - $b ) < 1e-9;
            case '!=': return abs( $a - $b ) >= 1e-9;
            case '>':  return $a > $b;
            case '<':  return $a < $b;
        }
        return false;
    }
}
