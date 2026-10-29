<?php
/**
 * File containing the expAiDashboard class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * What expai/dashboard and the dashboard block show for the current user: their drafts from the log, the
 * versions written with Exponential Intelligence that wait for their approval, the configuration state (never the
 * key) and what they may do.
 */
class expAiDashboard
{
    /**
     * The current user's log rows with object, version and state.
     * @return array list of rows (expAiAssistant::draftRow() plus log, source, prompt_summary)
     */
    public static function myDrafts( $offset = 0, $limit = 20 )
    {
        $out = array();
        // one query for whether the objects of all rows still exist; a removed one is listed as removed, without links
        $logs = expAiDraftLog::markObjectsThatExist( expAiDraftLog::fetchList( eZUser::currentUserID(), $offset, $limit ) );
        foreach ( $logs as $log )
        {
            $deleted = $log->objectExists() === false;
            $row = $deleted ? null : expAiAssistant::draftRow( $log );
            if ( !$row )
                $row = array( 'object_id' => (int) $log->attribute( 'contentobject_id' ), 'version' => (int) $log->attribute( 'version' ),
                              'name' => '', 'class_name' => '', 'language' => (string) $log->attribute( 'language_code' ),
                              'status' => $deleted ? expAiDraftLog::STATUS_DELETED : expAiDraftLog::STATUS_DISCARDED, 'is_draft' => false,
                              'approval' => '', 'action' => (string) $log->attribute( 'action' ),
                              'model' => (string) $log->attribute( 'model' ), 'created' => (int) $log->attribute( 'created' ),
                              'urls' => array( 'edit' => '', 'view' => '', 'node' => '' ) );
            $row['deleted'] = $deleted;
            $row['source'] = (string) $log->attribute( 'source' );
            $row['prompt_summary'] = (string) $log->attribute( 'prompt_summary' );
            $row['key'] = $row['object_id'] . ':' . $row['version'];
            $row['links'] = expAiLinks::build( $row['object_id'], $row['version'], $row['language'], (int) $log->attribute( 'node_id' ),
                                              $row['prompt_summary'] !== '' ? (int) $log->attribute( 'id' ) : 0, $log->objectExists() );
            $out[] = $row;
        }
        return $out;
    }

    /** @return int */
    public static function myDraftCount()
    {
        return expAiDraftLog::fetchListCount( eZUser::currentUserID() );
    }

    /**
     * Approval items waiting for the current user's decision whose version was written with Exponential Intelligence.
     * @return array inbox rows (expCollaborationInbox) with model
     */
    public static function waitingForMyApproval( $limit = 50 )
    {
        if ( !class_exists( 'expCollaborationInbox' ) )
            return array();
        $user = eZUser::currentUser();
        $access = $user->hasAccessTo( 'collaboration', 'view' );
        if ( !isset( $access['accessWord'] ) || $access['accessWord'] === 'no' )
            return array();
        $inbox = expCollaborationInbox::fetch( array( 'status' => 'waiting', 'role' => 'approver', 'type' => 'ezapprove', 'offset' => 0, 'limit' => $limit ) );
        $rows = isset( $inbox['items'] ) ? $inbox['items'] : array();
        $pairs = array();
        foreach ( $rows as $row )
            $pairs[] = array( (int) $row['object_id'], (int) $row['version'] );
        $logs = expAiDraftLog::fetchForVersions( $pairs );
        $out = array();
        foreach ( $rows as $row )
        {
            $key = (int) $row['object_id'] . ':' . (int) $row['version'];
            if ( !isset( $logs[$key] ) )
                continue;
            unset( $row['item'] );
            $row['model'] = (string) $logs[$key]->attribute( 'model' );
            $out[] = $row;
        }
        return $out;
    }

    /** @return bool an approval workflow (ezapprove before content/publish) exists at all */
    public static function approvalWorkflowExists()
    {
        foreach ( eZTrigger::fetchList( array( 'module' => 'content', 'function' => 'publish', 'connectType' => 'b' ) ) as $trigger )
            foreach ( eZWorkflowEvent::fetchFilteredList( array( 'workflow_id' => (int) $trigger->attribute( 'workflow_id' ), 'version' => 0 ) ) as $event )
                if ( in_array( $event->attribute( 'workflow_type_string' ), array( 'event_ezapprove', 'event_ezmultiplexer' ), true ) )
                    return true;
        return false;
    }

    /**
     * The location "Start writing" uses unless the editor picks another: expai.ini [DashboardSettings] DefaultNodeID,
     * else content.ini [NodeSettings] RootNode. Usable when the user may read it and create something below it.
     * @return array node_id, name, usable (bool), reason ('' | 'read' | 'create')
     */
    public static function defaultLocation( $chosen = 0 )
    {
        $nodeID = (int) $chosen > 0 ? (int) $chosen : (int) expAiConfig::instance()->get( 'DashboardSettings', 'DefaultNodeID', '' );
        if ( $nodeID <= 0 )
            $nodeID = (int) eZINI::instance( 'content.ini' )->variable( 'NodeSettings', 'RootNode' );
        $node = $nodeID ? eZContentObjectTreeNode::fetch( $nodeID ) : null;
        if ( !$node || !$node->canRead() )
            return array( 'node_id' => $nodeID, 'name' => '', 'usable' => false, 'reason' => 'read' );
        $canCreate = (bool) $node->canCreate();
        return array( 'node_id' => $nodeID, 'name' => (string) $node->attribute( 'name' ), 'usable' => $canCreate,
                      'reason' => $canCreate ? '' : 'create' );
    }

    /**
     * The state for the status card. Never contains the key.
     * @return array
     */
    public static function status()
    {
        $config = expAiConfig::instance();
        $user = eZUser::currentUser();
        $word = function ( $module, $function ) use ( $user )
        {
            $access = $user->hasAccessTo( $module, $function );
            return isset( $access['accessWord'] ) ? $access['accessWord'] : 'no';
        };
        $db = eZDB::instance();
        $since = time() - 7 * 86400;
        $recent = (int) eZPersistentObject::count( expAiDraftLog::definition(), array( 'user_id' => (int) $user->attribute( 'contentobject_id' ), 'created' => array( '>', $since ) ) );
        return array(
            'enabled' => $config->isEnabled(),
            'configured' => $config->isConfigured(),
            'provider' => (string) ( ( $m = $config->registry()->getModel( $config->model() ) ) ? $m['provider'] : '' ),
            'model' => $config->model(),
            'model_label' => $m ? (string) $m['label'] : $config->model(),
            'can_manage' => expAiServerFunctions::hasPolicy( 'model_manage' ),
            'placement' => expAiServerFunctions::hasPolicy( 'settings' ) ? $config->modelPlacement() : false,
            'create' => $word( 'content', 'create' ),
            'edit' => $word( 'content', 'edit' ),
            'publish_note' => self::approvalWorkflowExists(),
            'recent' => $recent,
            'tables' => !expAiSchema::missingTables( $db ) && !expAiSchema::missingColumns( $db ),
        );
    }
}
