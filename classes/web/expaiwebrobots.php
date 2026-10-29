<?php
/**
 * File containing the expAiWebRobots class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * robots.txt as RFC 9309 reads it: the group of the product token ExponentialIntelligence, else the group "*"; the
 * longest matching Allow or Disallow rule decides (Allow on a tie); * and $ in a path pattern. A missing robots.txt
 * (4xx) allows everything; one that cannot be read (5xx, timeout, refused) allows nothing.
 */
class expAiWebRobots
{
    const PRODUCT = 'ExponentialIntelligence';

    /** @var array list of array( bool allow, string pattern ) */
    protected $rules = array();

    /** @var bool|null everything allowed (true) or nothing (false) regardless of rules */
    protected $all = null;

    /**
     * @param int $status HTTP status of /robots.txt (0: not reachable)
     * @param string $text
     */
    public function __construct( $status, $text )
    {
        $status = (int) $status;
        if ( $status >= 400 && $status < 500 )
            $this->all = true;
        elseif ( $status < 200 || $status >= 300 )
            $this->all = false;
        else
            $this->rules = self::rulesFor( (string) $text, self::PRODUCT );
    }

    /** @return array the rules of the group that applies to the product token */
    public static function rulesFor( $text, $product )
    {
        $groups = array();
        $current = null;
        $lastWasAgent = false;
        foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line )
        {
            $line = trim( preg_replace( '/#.*$/', '', $line ) );
            if ( !preg_match( '/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $m ) )
                continue;
            $key = strtolower( $m[1] );
            $value = trim( $m[2] );
            if ( $key === 'user-agent' )
            {
                if ( !$lastWasAgent )
                {
                    $groups[] = array( 'agents' => array(), 'rules' => array() );
                    $current = count( $groups ) - 1;
                }
                $groups[$current]['agents'][] = strtolower( $value );
                $lastWasAgent = true;
                continue;
            }
            $lastWasAgent = false;
            if ( $current === null || ( $key !== 'allow' && $key !== 'disallow' ) )
                continue;
            // an empty Disallow allows everything
            if ( $value === '' )
                continue;
            $groups[$current]['rules'][] = array( $key === 'allow', $value );
        }
        $product = strtolower( $product );
        $specific = array();
        $star = array();
        $hasSpecific = false;
        $hasStar = false;
        foreach ( $groups as $group )
            foreach ( $group['agents'] as $agent )
            {
                if ( $agent === '*' )
                {
                    $hasStar = true;
                    $star = array_merge( $star, $group['rules'] );
                }
                elseif ( $agent !== '' && strpos( $product, $agent ) !== false )
                {
                    $hasSpecific = true;
                    $specific = array_merge( $specific, $group['rules'] );
                }
            }
        return $hasSpecific ? $specific : ( $hasStar ? $star : array() );
    }

    /**
     * @param string $target path and query
     * @return bool the path may be fetched
     */
    public function allows( $target )
    {
        if ( $this->all !== null )
            return $this->all;
        $target = (string) $target === '' ? '/' : (string) $target;
        $best = -1;
        $allowed = true;
        foreach ( $this->rules as $rule )
        {
            list( $allow, $pattern ) = $rule;
            if ( !self::matches( $pattern, $target ) )
                continue;
            $length = strlen( $pattern );
            if ( $length > $best || ( $length === $best && $allow ) )
            {
                $best = $length;
                $allowed = $allow;
            }
        }
        return $allowed;
    }

    /** @return bool the robots.txt path pattern matches the start of the target */
    public static function matches( $pattern, $target )
    {
        $anchored = substr( $pattern, -1 ) === '$';
        if ( $anchored )
            $pattern = substr( $pattern, 0, -1 );
        $regex = '~^' . str_replace( '\*', '.*', preg_quote( $pattern, '~' ) ) . ( $anchored ? '$' : '' ) . '~';
        return (bool) preg_match( $regex, $target ) || (bool) preg_match( $regex, rawurldecode( $target ) );
    }
}
