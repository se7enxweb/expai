<?php
/**
 * File containing the expAiThreadAccess class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Who may see and use a thread, and what the policy expai/vector allows (doc/permissions.md, doc/threads.md).
 *
 * Visibility (owner decision 2026-10-11): a thread is private to its owner. The approvers of a version the thread
 * wrote (participants of its ezapprove collaboration item) may open it read-only. Nobody else, administrators
 * included, sees another editor's thread in the list or by its id.
 *
 * expai/vector limitations, per policy (a user with several policies may do what any one of them allows):
 *   Subtree  every node and subtree of the scope lies inside one of the subtrees
 *   Section  every node and subtree root of the scope is in one of the sections
 *   Scope    the kinds of scope entries the user may pick: node, subtree, class
 *   Review   the review policies the user may choose: staged, direct
 */
class expAiThreadAccess
{
    /** @return bool the user may open the thread (owner, or approver of a version it wrote) */
    public static function canRead( expAiThread $thread, ?eZUser $user = null )
    {
        $user = $user ?: eZUser::currentUser();
        if ( !$user instanceof eZUser || !$user->isRegistered() )
            return false;
        if ( $thread->isOwner( $user->attribute( 'contentobject_id' ) ) )
            return true;
        return self::isApprover( $thread, $user );
    }

    /** @return bool the user may change and run the thread (only its owner) */
    public static function canWrite( expAiThread $thread, ?eZUser $user = null )
    {
        $user = $user ?: eZUser::currentUser();
        return $user instanceof eZUser && $user->isRegistered() && $thread->isOwner( $user->attribute( 'contentobject_id' ) );
    }

    /**
     * The user takes part in the approval (ezapprove collaboration item) of a version the thread wrote.
     * @return bool
     */
    public static function isApprover( expAiThread $thread, eZUser $user )
    {
        $logs = eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null, array( 'thread_id' => (int) $thread->attribute( 'id' ) ),
                                                     array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => 200 ) );
        // the kernel keeps participant lists for the request (and a persistent Velocity worker beyond it): read fresh
        unset( $GLOBALS['eZCollaborationItemParticipantLinkListCache'] );
        foreach ( (array) $logs as $log )
        {
            $items = eZPersistentObject::fetchObjectList( eZCollaborationItem::definition(), null,
                array( 'type_identifier' => 'ezapprove', 'data_int1' => (int) $log->attribute( 'contentobject_id' ), 'data_int2' => (int) $log->attribute( 'version' ) ),
                null, array( 'offset' => 0, 'length' => 5 ) );
            foreach ( (array) $items as $item )
                if ( $item->userIsParticipant( $user ) )
                    return true;
        }
        return false;
    }

    /**
     * The threads the current user approves for: those that wrote a version waiting for the user's approval.
     * @return expAiThread[]
     */
    public static function sharedWithMe( $limit = 20 )
    {
        $user = eZUser::currentUser();
        $pairs = array();
        foreach ( expAiDashboard::waitingForMyApproval( 100 ) as $row )
            $pairs[] = array( (int) $row['object_id'], (int) $row['version'] );
        $ids = array();
        foreach ( expAiDraftLog::fetchForVersions( $pairs ) as $log )
            if ( (int) $log->attribute( 'thread_id' ) && (int) $log->attribute( 'user_id' ) !== (int) $user->attribute( 'contentobject_id' ) )
                $ids[(int) $log->attribute( 'thread_id' )] = true;
        $out = array();
        foreach ( array_slice( array_keys( $ids ), 0, $limit ) as $id )
        {
            $thread = expAiThread::fetchByID( $id );
            if ( $thread )
                $out[] = $thread;
        }
        return $out;
    }

    /**
     * What expai/vector allows the user.
     * @return bool|array false: no access; true: everything; else the list of policies, each an array of
     *                    limitation => values (Subtree: path strings, Section: ids, Scope, Review)
     */
    public static function vectorPolicies( ?eZUser $user = null )
    {
        $user = $user ?: eZUser::currentUser();
        if ( !$user instanceof eZUser || !$user->isRegistered() )
            return false;
        $access = $user->hasAccessTo( 'expai', 'vector' );
        $word = isset( $access['accessWord'] ) ? $access['accessWord'] : 'no';
        if ( $word === 'yes' )
            return true;
        if ( $word !== 'limited' || empty( $access['policies'] ) )
            return false;
        $out = array();
        foreach ( (array) $access['policies'] as $policy )
        {
            $limits = array();
            foreach ( array( 'Subtree' => 'User_Subtree', 'Section' => 'User_Section', 'Scope' => '', 'Review' => '' ) as $name => $alt )
            {
                if ( isset( $policy[$name] ) )
                    $limits[$name] = array_map( 'strval', (array) $policy[$name] );
                elseif ( $alt !== '' && isset( $policy[$alt] ) )
                    $limits[$name] = array_map( 'strval', (array) $policy[$alt] );
            }
            $out[] = $limits;
        }
        return $out;
    }

    /** @return bool the user has expai/vector at all */
    public static function hasVector( ?eZUser $user = null )
    {
        return self::vectorPolicies( $user ) !== false;
    }

    /**
     * The scope kinds and review policies some policy allows (for the controls; check() decides).
     * @return array { scope: string[], review: string[] }
     */
    public static function choices( ?eZUser $user = null )
    {
        $policies = self::vectorPolicies( $user );
        $all = array( 'scope' => array( 'node', 'subtree', 'class' ), 'review' => array( 'staged', 'direct' ) );
        if ( $policies === true )
            return $all;
        if ( !$policies )
            return array( 'scope' => array(), 'review' => array() );
        $scope = array();
        $review = array();
        foreach ( $policies as $limits )
        {
            $scope = array_merge( $scope, isset( $limits['Scope'] ) ? $limits['Scope'] : $all['scope'] );
            $review = array_merge( $review, isset( $limits['Review'] ) ? $limits['Review'] : $all['review'] );
        }
        return array( 'scope' => array_values( array_intersect( $all['scope'], $scope ) ), 'review' => array_values( array_intersect( $all['review'], $review ) ) );
    }

    /**
     * Checks a scope and review policy against expai/vector and the user's read access.
     * @param array $scope expAiThread::cleanScope() form
     * @return string '' when allowed, else the reason (translated)
     */
    public static function check( array $scope, $review, ?eZUser $user = null )
    {
        $user = $user ?: eZUser::currentUser();
        $policies = self::vectorPolicies( $user );
        if ( $policies === false )
            return ezpI18n::tr( 'extension/expai/vector', 'You do not have access to Vector.' );
        // the nodes: readable, with path and section
        $nodes = array();
        foreach ( array( 'nodes' => 'node', 'subtrees' => 'subtree' ) as $key => $kind )
            foreach ( $scope[$key] as $nodeID )
            {
                $node = eZContentObjectTreeNode::fetch( (int) $nodeID );
                if ( !$node instanceof eZContentObjectTreeNode || !$node->canRead() )
                    return ezpI18n::tr( 'extension/expai/vector', 'Node %id does not exist or you may not read it.', null, array( '%id' => (int) $nodeID ) );
                $object = $node->object();
                $nodes[] = array( 'kind' => $kind, 'path' => (string) $node->attribute( 'path_string' ),
                                  'section' => $object ? (string) $object->attribute( 'section_id' ) : '' );
            }
        foreach ( $scope['classes'] as $identifier )
            if ( !eZContentClass::fetchByIdentifier( $identifier ) )
                return ezpI18n::tr( 'extension/expai/vector', 'The class "%class" does not exist.', null, array( '%class' => $identifier ) );
        foreach ( $scope['languages'] as $code )
            if ( !eZContentLanguage::fetchByLocale( $code ) )
                return ezpI18n::tr( 'extension/expai/vector', 'The language "%language" is not used on this site.', null, array( '%language' => $code ) );
        if ( !in_array( $review, array( 'staged', 'direct' ), true ) )
            $review = 'staged';
        if ( $policies === true )
            return '';
        $kinds = array();
        if ( $scope['nodes'] )
            $kinds[] = 'node';
        if ( $scope['subtrees'] )
            $kinds[] = 'subtree';
        if ( $scope['classes'] )
            $kinds[] = 'class';
        $reason = '';
        foreach ( $policies as $limits )
        {
            $fail = self::failsPolicy( $limits, $nodes, $kinds, $scope, $review );
            if ( $fail === '' )
                return '';
            $reason = $reason ?: $fail;
        }
        return $reason;
    }

    /** @return string '' when the one policy allows the scope, else why not */
    protected static function failsPolicy( array $limits, array $nodes, array $kinds, array $scope, $review )
    {
        if ( isset( $limits['Scope'] ) && array_diff( $kinds, $limits['Scope'] ) )
            return ezpI18n::tr( 'extension/expai/vector', 'Your role does not allow this kind of scope (%kinds).', null,
                                array( '%kinds' => implode( ', ', array_diff( $kinds, $limits['Scope'] ) ) ) );
        if ( isset( $limits['Review'] ) && !in_array( $review, $limits['Review'], true ) )
            return ezpI18n::tr( 'extension/expai/vector', 'Your role does not allow this review policy.' );
        if ( isset( $limits['Subtree'] ) )
        {
            if ( !$nodes && ( $scope['classes'] || $scope['languages'] ) )
                return ezpI18n::tr( 'extension/expai/vector', 'Your role limits Vector to some subtrees: choose a location inside them.' );
            foreach ( $nodes as $node )
            {
                $inside = false;
                foreach ( $limits['Subtree'] as $path )
                    if ( $path !== '' && strpos( $node['path'], $path ) === 0 )
                        $inside = true;
                if ( !$inside )
                    return ezpI18n::tr( 'extension/expai/vector', 'Part of the scope lies outside the subtrees your role allows for Vector.' );
            }
        }
        if ( isset( $limits['Section'] ) )
        {
            if ( !$nodes && ( $scope['classes'] || $scope['languages'] ) )
                return ezpI18n::tr( 'extension/expai/vector', 'Your role limits Vector to some sections: choose a location inside them.' );
            foreach ( $nodes as $node )
                if ( !in_array( $node['section'], $limits['Section'], true ) )
                    return ezpI18n::tr( 'extension/expai/vector', 'Part of the scope lies in a section your role does not allow for Vector.' );
        }
        return '';
    }
}
