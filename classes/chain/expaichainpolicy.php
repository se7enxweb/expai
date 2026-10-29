<?php
/**
 * File containing the expAiChainPolicy class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Access to AI chains: the policy expai/chain (limitation Chain) starts runs and acts on them at the checkpoint.
 *
 * - Start a run: expai/chain (for that chain) and expai/write; the run writes its draft with the starting user's
 *   permissions (content/create of the class under the location).
 * - See a run: its starter, or anyone with expai/log, or anyone with expai/chain who may read the run's location.
 * - Act on a run (approve, ask for changes, stop): its starter, or anyone with expai/chain for that chain who may
 *   edit the run's draft (collaboration: a second editor reviews). The steps after a checkpoint keep running as the
 *   starter, who owns the draft.
 */
class expAiChainPolicy
{
    public function __construct( $parameters = array() )
    {
    }

    /** @return array the chains of [ChainSettings] Chains[] for the role editor's Chain limitation */
    public function chains()
    {
        $config = expAiConfig::instance();
        $out = array();
        foreach ( expAiChain::ids( $config ) as $id )
            $out[] = array( 'id' => $id, 'name' => (string) $config->get( 'Chain_' . $id, 'Label', $id ) . ' (' . $id . ')' );
        return $out;
    }

    /** @return bool the user may start (and act on) runs of the chain */
    public static function mayUse( $chainID, ?eZUser $user = null )
    {
        return expAiPolicy::allows( 'chain', 'Chain', (string) $chainID, $user );
    }

    /**
     * @return bool the user may see the run (its document holds the values of the content): the user who started it,
     *              a holder of expai/log, or a user who may use the chain and edit the run's object. A run that has
     *              no object yet (the chain is still to create it below its node) is seen by the first two only:
     *              who may edit the parent node does not see another editor's unpublished document.
     */
    public static function maySee( expAiChainRun $run, ?eZUser $user = null )
    {
        $user = $user ?: eZUser::currentUser();
        if ( !$user instanceof eZUser || !$user->isRegistered() )
            return false;
        if ( (int) $run->attribute( 'user_id' ) === (int) $user->attribute( 'contentobject_id' ) )
            return true;
        $log = $user->hasAccessTo( 'expai', 'log' );
        if ( isset( $log['accessWord'] ) && $log['accessWord'] !== 'no' )
            return true;
        if ( !self::mayUse( $run->attribute( 'chain' ), $user ) )
            return false;
        return static::mayEditObject( $run, $user );
    }

    /**
     * @return bool the user (not necessarily the current one) may edit the run's object; false when the run has no
     *              object (yet)
     */
    public static function mayEditObject( expAiChainRun $run, eZUser $user )
    {
        $object = (int) $run->attribute( 'object_id' ) ? static::fetchObject( (int) $run->attribute( 'object_id' ) ) : null;
        return $object instanceof eZContentObject
            && (int) $object->checkAccess( 'edit', false, false, false, false, (int) $user->attribute( 'contentobject_id' ) ) === 1;
    }

    /** @return eZContentObject|null */
    protected static function fetchObject( $objectID )
    {
        return expAiObjects::fetch( (int) $objectID );
    }

    /** @return bool the user may approve, ask for changes, stop or continue the run */
    public static function mayAct( expAiChainRun $run, ?eZUser $user = null )
    {
        $user = $user ?: eZUser::currentUser();
        if ( !$user instanceof eZUser || !$user->isRegistered() || !self::mayUse( $run->attribute( 'chain' ), $user ) )
            return false;
        if ( (int) $run->attribute( 'user_id' ) === (int) $user->attribute( 'contentobject_id' ) )
            return true;
        return static::mayEditObject( $run, $user );
    }
}
