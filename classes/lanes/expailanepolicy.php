<?php
/**
 * File containing the expAiLanePolicy class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Which lane a queued request takes (expai.ini [LanePolicy]):
 *
 *   1. the order: Order[<purpose>] of the request (e.g. translate, bulk), else ClassOrder[<queue class>], else
 *      Order[default]; lanes separated by ";", e.g. batch;flex;standard
 *   2. candidates are the usable lanes of the model in that order (expAiLane::forModel()); standard is always last
 *   3. fast is never chosen from an order unless the request asks for it and the user has expai/lane_fast
 *   4. priority at or above UrgentPriority drops batch and flex lanes
 *   5. a deadline drops lanes whose expected latency (batch: MaxLatency with StrictDeadline=enabled) does not fit
 *   6. the data policy of the content drops lanes it does not allow (a zero-retention rule drops a batch lane that
 *      keeps requests for 29 days)
 *
 * A request may name a lane (option lane); it is checked against the same rules and refused when it fails them.
 */
class expAiLanePolicy
{
    /** @var expAiConfig */
    protected $config;

    public function __construct( ?expAiConfig $config = null )
    {
        $this->config = $config ?: expAiConfig::instance();
    }

    /** @return string[] the lane order for a purpose and queue class */
    public function order( $purpose = '', $class = '' )
    {
        $orders = $this->config->get( 'LanePolicy', 'Order', array() );
        $orders = is_array( $orders ) ? $orders : array();
        $classOrders = $this->config->get( 'LanePolicy', 'ClassOrder', array() );
        $classOrders = is_array( $classOrders ) ? $classOrders : array();
        if ( $purpose !== '' && !empty( $orders[$purpose] ) )
            $value = $orders[$purpose];
        elseif ( $class !== '' && !empty( $classOrders[$class] ) )
            $value = $classOrders[$class];
        elseif ( !empty( $orders['default'] ) )
            $value = $orders['default'];
        else
            $value = 'standard';
        $out = array();
        foreach ( preg_split( '/[;,\s]+/', (string) $value ) as $name )
            if ( preg_match( '/^[a-z][a-z0-9_]{0,15}$/', $name ) && !in_array( $name, $out, true ) )
                $out[] = $name;
        if ( !in_array( 'standard', $out, true ) )
            $out[] = 'standard';
        return $out;
    }

    /**
     * Chooses the lane.
     *
     * @param string $alias the model alias
     * @param array $request purpose, class (queue class), priority, deadline (timestamp), lane (asked for),
     *                       allow_fast (bool), requirements (list of data policy requirements of the content: see
     *                       expAiDataPolicy::requirement()), user (eZUser, for expai/lane_fast)
     * @return array lane (expAiLane), tried (name => why not), order
     * @throws expAiException when no lane fits (or the lane asked for does not)
     */
    public function choose( $alias, array $request = array() )
    {
        $request += array( 'purpose' => '', 'class' => '', 'priority' => 50, 'deadline' => 0, 'lane' => '', 'allow_fast' => false,
                           'requirements' => array(), 'user' => null );
        $lanes = expAiLane::forModel( $alias, $this->config );
        if ( !$lanes )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'no model ' . $alias . ' in the provider registry' );
        $asked = (string) $request['lane'];
        $order = $asked !== '' && $asked !== 'auto' ? array( $asked ) : $this->order( (string) $request['purpose'], (string) $request['class'] );
        $tried = array();
        foreach ( $order as $name )
        {
            $why = $this->refusal( isset( $lanes[$name] ) ? $lanes[$name] : null, $request, $asked === $name );
            if ( $why === null )
                return array( 'lane' => $lanes[$name], 'tried' => $tried, 'order' => $order );
            $tried[$name] = $why;
        }
        $reasons = array();
        foreach ( $tried as $name => $why )
            $reasons[] = "$name: $why";
        throw new expAiException( 'No lane of the model %model may take this request.', implode( '; ', $reasons ), 0, array( '%model' => (string) $alias ) );
    }

    /**
     * @return string|null why a lane may not take the request, null when it may
     */
    public function refusal( ?expAiLane $lane, array $request, $asked = false )
    {
        if ( !$lane )
            return 'not offered for this model';
        if ( !$lane->get( 'enabled' ) )
            return 'disabled';
        if ( $lane->get( 'multiplier' ) === null )
            return 'no confirmed price';
        if ( $lane->name() === 'fast' )
        {
            if ( !$asked && empty( $request['allow_fast'] ) )
                return 'only on request';
            $user = $request['user'] instanceof eZUser ? $request['user'] : eZUser::currentUser();
            if ( !$user || $user->hasAccessTo( 'expai', 'lane_fast' )['accessWord'] === 'no' )
                return 'needs the policy expai/lane_fast';
        }
        $urgent = (int) $this->config->get( 'LanePolicy', 'UrgentPriority', 80 );
        if ( $urgent > 0 && (int) $request['priority'] >= $urgent && ( $lane->isBatch() || $lane->name() === 'flex' ) && !$asked )
            return 'too slow for an urgent request';
        $deadline = (int) $request['deadline'];
        if ( $deadline > 0 )
        {
            $left = $deadline - time();
            $strict = $this->config->flag( 'LanePolicy', 'StrictDeadline', false );
            $needs = $lane->isBatch() && $strict ? $lane->get( 'max_latency' ) : $lane->get( 'expected_latency' );
            if ( $needs > $left )
                return "expected to take {$needs} s, the deadline is in {$left} s";
        }
        if ( $request['requirements'] )
        {
            $policy = $lane->dataPolicy( $this->config );
            foreach ( $request['requirements'] as $requirement )
                if ( !$policy->meets( $requirement ) )
                    return 'the data policy refuses it: ' . $policy->describe( $requirement );
        }
        return null;
    }
}
