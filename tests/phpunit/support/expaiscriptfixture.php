<?php
/**
 * File containing the expAiScriptFixture class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * What the script tests need around them when the suite runs them: a hidden folder below node 2 to work in (their
 * EXPAI_TEST_PARENT or EXPAI_TEST_NODE), and afterwards the removal of everything below it, including what a script
 * moved to the trash, the drafts it wrote, their log rows, conversations and threads; plus the rows of the mock
 * providers that only exist in a script's memory (usage, queue, batch and chain rows).
 */
class expAiScriptFixture
{
    /**
     * @param string $name
     * @return eZContentObjectTreeNode a hidden folder below node 2, owned by admin
     */
    public static function folder( $name )
    {
        $admin = self::admin();
        $object = eZContentFunctions::createAndPublishObject( array( 'parent_node_id' => 2, 'class_identifier' => 'folder',
            'creator_id' => (int) $admin->attribute( 'contentobject_id' ),
            'attributes' => array( 'name' => $name . ' ' . date( 'Y-m-d H:i:s' ) . ' ' . getmypid() ) ), false );
        if ( !$object instanceof eZContentObject )
            throw new RuntimeException( 'the test folder could not be created' );
        $node = $object->mainNode();
        eZContentObjectTreeNode::hideSubTree( $node );
        return $node;
    }

    /**
     * Removes the folder and everything that was made below it (never moved to the trash).
     *
     * @param eZContentObjectTreeNode $folder
     * @return int the content objects removed
     */
    public static function removeFolder( eZContentObjectTreeNode $folder )
    {
        self::admin();
        $db = eZDB::instance();
        $folderNodeID = (int) $folder->attribute( 'node_id' );
        $path = (string) $folder->attribute( 'path_string' );

        $nodeIDs = array( $folderNodeID => true );
        $objectIDs = array();
        foreach ( (array) $folder->subTree( array( 'Limitation' => array(), 'IgnoreVisibility' => true, 'MainNodeOnly' => false ) ) as $node )
        {
            $nodeIDs[(int) $node->attribute( 'node_id' )] = true;
            $objectIDs[(int) $node->attribute( 'contentobject_id' )] = true;
        }
        foreach ( (array) $db->arrayQuery( "SELECT node_id, contentobject_id FROM ezcontentobject_trash WHERE path_string LIKE '" . $db->escapeString( $path ) . "%'" ) as $row )
        {
            $row = array_change_key_case( $row, CASE_LOWER );
            $nodeIDs[(int) $row['node_id']] = true;
            $objectIDs[(int) $row['contentobject_id']] = true;
        }
        $nodeList = array_keys( $nodeIDs );

        // drafts of new objects below these nodes: never published, no node of their own
        foreach ( array_chunk( $nodeList, 200 ) as $chunk )
        {
            foreach ( (array) $db->arrayQuery( 'SELECT DISTINCT a.contentobject_id FROM eznode_assignment a, ezcontentobject o WHERE a.contentobject_id = o.id AND o.status = '
                                                . eZContentObject::STATUS_DRAFT . ' AND a.parent_node IN ( ' . implode( ', ', $chunk ) . ' )' ) as $row )
                $objectIDs[(int) array_change_key_case( $row, CASE_LOWER )['contentobject_id']] = true;
            foreach ( eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null, array( 'node_id' => array( $chunk ) ) ) as $log )
                $objectIDs[(int) $log->attribute( 'contentobject_id' )] = true;
            foreach ( eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null, array( 'parent_node_id' => array( $chunk ) ) ) as $log )
                $objectIDs[(int) $log->attribute( 'contentobject_id' )] = true;
            foreach ( eZPersistentObject::fetchObjectList( expAiThread::definition(), null, array( 'node_id' => array( $chunk ) ) ) as $thread )
                $thread->removeAll( true );
            eZPersistentObject::removeObject( expAiConversation::definition(), array( 'node_id' => array( $chunk ) ) );
        }
        unset( $objectIDs[0], $objectIDs[(int) $folder->attribute( 'contentobject_id' )] );

        $removed = 0;
        foreach ( array_keys( $objectIDs ) as $objectID )
        {
            eZPersistentObject::removeObject( expAiDraftLog::definition(), array( 'contentobject_id' => $objectID ) );
            $object = eZContentObject::fetch( $objectID );
            if ( !$object instanceof eZContentObject )
                continue;
            // only what lives below the folder: a published object elsewhere is never touched
            $elsewhere = false;
            foreach ( $object->assignedNodes( false ) as $node )
                if ( strpos( (string) $node['path_string'], $path ) !== 0 )
                    $elsewhere = true;
            if ( $elsewhere )
                continue;
            $object->purge();
            ++$removed;
        }
        if ( eZContentObjectTreeNode::fetch( $folderNodeID ) )
            eZContentObjectTreeNode::removeSubtrees( array( $folderNodeID ), false );
        return $removed;
    }

    /**
     * Removes the rows that the mock providers of the script tests leave: providers that exist only in a script's
     * memory, so no real row has these names.
     *
     * @param string[] $providers provider ids (hfmock, tch ...)
     * @param string[] $groupLabels labels of queue groups the script makes
     * @param string|null $state the mock's state directory, where a script lists its rows in t-rows.json
     */
    public static function removeMockRows( array $providers, array $groupLabels = array(), $state = null )
    {
        $db = eZDB::instance();
        $made = $state && is_file( $state . '/t-rows.json' ) ? (array) json_decode( (string) file_get_contents( $state . '/t-rows.json' ), true ) : array();
        $uuid = static function ( $value ) { return is_string( $value ) && preg_match( '/^[0-9a-f-]{36}$/', $value ); };

        $jobIDs = array();
        $groupIDs = array();
        $jobUuids = array_values( array_filter( isset( $made['jobs'] ) ? (array) $made['jobs'] : array(), $uuid ) );

        // chain runs: their steps, their job group, the draft each wrote
        $runIDs = array_map( 'intval', isset( $made['runs'] ) ? (array) $made['runs'] : array() );
        if ( isset( $made['node'] ) && (int) $made['node'] && class_exists( 'expAiChainRun' ) )
            foreach ( expAiChainRun::fetchList( array( 'node_id' => (int) $made['node'] ), 0, 1000 ) as $run )
                $runIDs[] = (int) $run->attribute( 'id' );
        foreach ( array_unique( array_filter( $runIDs ) ) as $runID )
        {
            $run = expAiChainRun::fetch( $runID );
            if ( !$run )
                continue;
            if ( $run->attribute( 'group_uuid' ) !== '' && ( $group = expQueueGroup::fetchByUUID( (string) $run->attribute( 'group_uuid' ) ) ) )
                $groupIDs[] = (int) $group->attribute( 'id' );
            foreach ( $run->steps() as $step )
                if ( $uuid( (string) $step->attribute( 'job_uuid' ) ) )
                    $jobUuids[] = (string) $step->attribute( 'job_uuid' );
            $objectID = (int) $run->attribute( 'object_id' );
            $object = $objectID ? eZContentObject::fetch( $objectID ) : null;
            if ( $object && (int) $object->attribute( 'status' ) !== eZContentObject::STATUS_PUBLISHED )
            {
                eZPersistentObject::removeObject( expAiDraftLog::definition(), array( 'contentobject_id' => $objectID ) );
                $object->purge();
            }
            eZPersistentObject::removeObject( expAiChainStep::definition(), array( 'run_id' => $runID ) );
            $run->remove();
        }

        foreach ( isset( $made['groups'] ) ? (array) $made['groups'] : array() as $groupUuid )
            if ( $uuid( $groupUuid ) && ( $group = expQueueGroup::fetchByUUID( $groupUuid ) ) )
                $groupIDs[] = (int) $group->attribute( 'id' );
        foreach ( $groupLabels as $label )
            foreach ( (array) $db->arrayQuery( "SELECT id FROM expqueue_group WHERE label = '" . $db->escapeString( $label ) . "'" ) as $row )
                $groupIDs[] = (int) array_change_key_case( $row, CASE_LOWER )['id'];
        $groupIDs = array_values( array_unique( $groupIDs ) );

        $where = array();
        foreach ( $providers as $provider )
            $where[] = "resource_key LIKE '" . $db->escapeString( $provider ) . ":%'";
        if ( $jobUuids )
            $where[] = "uuid IN ( '" . implode( "', '", array_unique( $jobUuids ) ) . "' )";
        foreach ( $groupIDs as $groupID )
            $where[] = 'group_id = ' . (int) $groupID;
        if ( $where )
            foreach ( (array) $db->arrayQuery( 'SELECT id, uuid FROM expqueue_job WHERE ' . implode( ' OR ', $where ) ) as $row )
            {
                $row = array_change_key_case( $row, CASE_LOWER );
                $jobIDs[] = (int) $row['id'];
                $jobUuids[] = (string) $row['uuid'];
            }
        foreach ( array_chunk( array_unique( $jobIDs ), 200 ) as $chunk )
        {
            $db->query( 'DELETE FROM expqueue_event WHERE job_id IN ( ' . implode( ', ', $chunk ) . ' )' );
            $db->query( 'DELETE FROM expqueue_job WHERE id IN ( ' . implode( ', ', $chunk ) . ' )' );
        }
        foreach ( $groupIDs as $groupID )
        {
            $db->query( 'DELETE FROM expqueue_event WHERE group_id = ' . (int) $groupID );
            $db->query( 'DELETE FROM expqueue_group WHERE id = ' . (int) $groupID );
        }
        foreach ( array_unique( array_filter( $jobUuids, $uuid ) ) as $jobUuid )
            eZPersistentObject::removeObject( expAiUsageLog::definition(), array( 'job_uuid' => $jobUuid ) );
        foreach ( $providers as $provider )
        {
            $db->query( "DELETE FROM expqueue_resource WHERE resource_key LIKE '" . $db->escapeString( $provider ) . ":%'" );
            eZPersistentObject::removeObject( expAiUsageLog::definition(), array( 'provider' => $provider ) );
            eZPersistentObject::removeObject( expAiBatch::definition(), array( 'provider' => $provider ) );
            eZPersistentObject::removeObject( expAiProbeResult::definition(), array( 'provider' => $provider ) );
        }
    }

    /** @return eZUser the administrator, logged in for the process */
    private static function admin()
    {
        $admin = eZUser::fetchByName( 'admin' );
        eZUser::setCurrentlyLoggedInUser( $admin, (int) $admin->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
        return $admin;
    }
}
