<?php
/**
 * File containing the expAiChainFunctionCollection class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Template fetch functions of AI chains:
 *
 *   fetch( 'expai', 'chains' )                              the chains the current user may start (id, label, description,
 *                                                           class, max_items, steps)
 *   fetch( 'expai', 'chain_runs', hash( 'limit', 5, 'node_id', 2 ) )  the current user's latest runs (at a location),
 *                                                           and how many wait for an editor
 */
class expAiChainFunctionCollection
{
    public function fetchChains()
    {
        $out = array();
        if ( !expAiQueue::available() )
            return array( 'result' => $out );
        foreach ( expAiChain::available() as $id => $chain )
        {
            $steps = array();
            foreach ( $chain->order() as $name )
                $steps[] = $chain->step( $name )['label'];
            $out[] = array( 'id' => $id, 'label' => $chain->label(), 'description' => $chain->description(), 'class' => $chain->classIdentifier(),
                            'max_items' => $chain->maxItems(), 'steps' => $steps,
                            'max_cost' => $chain->maxCostMicros() ? expAiBudget::money( $chain->maxCostMicros() ) : '' );
        }
        return array( 'result' => $out );
    }

    public function fetchRuns( $limit = 5, $nodeID = 0 )
    {
        if ( !expAiQueue::available() || !self::installed() )
            return array( 'result' => array( 'rows' => array(), 'waiting' => 0 ) );
        $userID = (int) eZUser::currentUserID();
        $conditions = array( 'user_id' => $userID );
        if ( (int) $nodeID > 0 )
            $conditions['node_id'] = (int) $nodeID;
        $engine = new expAiChainEngine();
        $rows = array();
        foreach ( expAiChainRun::fetchList( $conditions, 0, max( 1, min( 50, (int) $limit ) ) ) as $run )
            $rows[] = self::row( $engine->reconcile( $run ) );
        return array( 'result' => array( 'rows' => $rows, 'waiting' => expAiChainRun::fetchCount( array( 'user_id' => $userID, 'state' => expAiChainRun::STATE_WAITING ) ) ) );
    }

    /** @return array a run for the lists */
    public static function row( expAiChainRun $run )
    {
        return array( 'id' => (int) $run->attribute( 'id' ), 'uuid' => (string) $run->attribute( 'uuid' ), 'label' => (string) $run->attribute( 'label' ),
                      'chain' => (string) $run->attribute( 'chain' ), 'state' => (string) $run->attribute( 'state' ), 'state_label' => $run->stateLabel(),
                      'current_step' => (string) $run->attribute( 'current_step' ), 'cost' => $run->costMoney(), 'node_id' => (int) $run->attribute( 'node_id' ),
                      'object_id' => (int) $run->attribute( 'object_id' ), 'version' => (int) $run->attribute( 'version' ),
                      'modified' => (int) $run->attribute( 'modified' ), 'created' => (int) $run->attribute( 'created' ) );
    }

    /** @return bool the chain tables exist */
    public static function installed()
    {
        static $installed = null;
        if ( $installed === null )
            $installed = !array_intersect( array( 'expai_chain_run', 'expai_chain_step' ), expAiSchema::missingTables() );
        return $installed;
    }
}
