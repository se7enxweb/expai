<?php
/**
 * File containing the expAiDraftLog class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * One version the assistant wrote (table expai_draft_log): the object and version, the user it was written for,
 * when, a summary of the request, the model, where it was written (panel or edit form) and what happened to it.
 * This is what the "Exponential Intelligence" label is drawn from.
 *
 * status: draft (written, still a draft), kept ("Save as drafts" pressed), pending (sent, waiting for approval),
 *         published, discarded, deleted (the content object was removed since; set by the cronjob expai_prune)
 * action: create (new object), update (new version of an existing object), field (a field action in the edit form)
 * source: panel, edit
 */
class expAiDraftLog extends eZPersistentObject
{
    const STATUS_DRAFT = 'draft';
    const STATUS_KEPT = 'kept';
    const STATUS_PENDING = 'pending';
    const STATUS_PUBLISHED = 'published';
    const STATUS_DISCARDED = 'discarded';
    const STATUS_DELETED = 'deleted';

    /**
     * Whether the logged content object still exists: true or false once checked (markObjectsThatExist() for a
     * whole list in one query), null while not checked yet.
     * @var bool|null
     */
    public $ObjectExists = null;

    public static function definition()
    {
        $int = function ( $name ) { return array( 'name' => $name, 'datatype' => 'integer', 'default' => 0, 'required' => true ); };
        $str = function ( $name, $default = '' ) { return array( 'name' => $name, 'datatype' => 'string', 'default' => $default, 'required' => true ); };
        return array(
            'fields' => array(
                'id' => $int( 'ID' ),
                'contentobject_id' => $int( 'ContentObjectID' ),
                'version' => $int( 'Version' ),
                'user_id' => $int( 'UserID' ),
                'node_id' => $int( 'NodeID' ),
                'parent_node_id' => $int( 'ParentNodeID' ),
                'conversation_id' => $int( 'ConversationID' ),
                'language_code' => $str( 'LanguageCode' ),
                'action' => $str( 'Action' ),
                'source' => $str( 'Source' ),
                'status' => $str( 'Status', self::STATUS_DRAFT ),
                'model' => $str( 'Model' ),
                'provider' => $str( 'Provider' ),
                // the queue job and the content package a version came from (source queue or package)
                'job_uuid' => $str( 'JobUUID' ),
                'package_uuid' => $str( 'PackageUUID' ),
                'prompt_summary' => $str( 'PromptSummary' ),
                'created' => $int( 'Created' ),
                'modified' => $int( 'Modified' ),
                // threads (classes/vector/): the thread that wrote the version, the staged change it came from
                'thread_id' => $int( 'ThreadID' ),
                'change_id' => $int( 'ChangeID' ),
            ),
            'keys' => array( 'id' ),
            'increment_key' => 'id',
            'function_attributes' => array( 'object' => 'contentObject', 'content_version' => 'contentVersion' ),
            'class_name' => 'expAiDraftLog',
            'sort' => array( 'id' => 'desc' ),
            'name' => 'expai_draft_log',
        );
    }

    /**
     * @param array $row
     * @return expAiDraftLog stored
     */
    public static function record( array $row )
    {
        $now = time();
        $row += array( 'created' => $now, 'modified' => $now, 'status' => self::STATUS_DRAFT );
        $summaryMax = expAiConfig::instance()->int( 'LogSettings', 'MaxPromptSummaryLength', 200, 20, 255 );
        $row['prompt_summary'] = expAiText::cut( isset( $row['prompt_summary'] ) ? $row['prompt_summary'] : '', $summaryMax );
        $row['model'] = expAiText::cut( isset( $row['model'] ) ? $row['model'] : '', 100 );
        // the thread of the conversation; a Vector thread's drafts are logged with source "vector"
        if ( empty( $row['thread_id'] ) && !empty( $row['conversation_id'] ) && expAiThread::available() )
        {
            $conversation = eZPersistentObject::fetchObject( expAiConversation::definition(), array( 'thread_id' ), array( 'id' => (int) $row['conversation_id'] ), false );
            $thread = is_array( $conversation ) && (int) $conversation['thread_id'] ? expAiThread::fetchByID( (int) $conversation['thread_id'] ) : null;
            if ( $thread )
            {
                $row['thread_id'] = (int) $thread->attribute( 'id' );
                if ( $thread->attribute( 'origin' ) === expAiThread::ORIGIN_VECTOR && ( !isset( $row['source'] ) || $row['source'] === 'panel' ) )
                    $row['source'] = 'vector';
            }
        }
        $log = new self( $row );
        $log->store();
        // the audit trail (doc/audit-trail.md): the version written, never its text or the prompt
        if ( class_exists( 'expAiAudit' ) )
            expAiAudit::draftWritten( $log );
        return $log;
    }

    /**
     * The newest entry of a version. The kernel reuses the number of a version that was discarded (an edit draft
     * removed by the editor or by the draft cleanup): an entry older than the version's "created" belongs to the
     * removed version, not to this one, and is ignored.
     * @return expAiDraftLog|null
     */
    public static function fetchByVersion( $objectID, $version )
    {
        $list = eZPersistentObject::fetchObjectList( self::definition(), null,
            array( 'contentobject_id' => (int) $objectID, 'version' => (int) $version ), array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => 1 ) );
        if ( !$list )
            return null;
        $created = self::versionCreated( array( (int) $objectID ) );
        $key = (int) $objectID . ':' . (int) $version;
        return self::belongsToVersion( (int) $list[0]->attribute( 'created' ), isset( $created[$key] ) ? $created[$key] : 0 ) ? $list[0] : null;
    }

    /**
     * @param int $logCreated the "created" of a log entry
     * @param int $versionCreated the "created" of the version with that number now (0: no such version, or not known)
     * @return bool the entry was written for this version, not for an earlier one with the same number
     */
    public static function belongsToVersion( $logCreated, $versionCreated )
    {
        return (int) $versionCreated <= 0 || (int) $logCreated >= (int) $versionCreated;
    }

    /**
     * @param int[] $objectIDs
     * @return array "objectID:version" => created of every version of the objects
     */
    protected static function versionCreated( array $objectIDs )
    {
        $objectIDs = array_values( array_unique( array_filter( array_map( 'intval', $objectIDs ) ) ) );
        if ( !$objectIDs )
            return array();
        $rows = eZPersistentObject::fetchObjectList( eZContentObjectVersion::definition(), array( 'contentobject_id', 'version', 'created' ),
            array( 'contentobject_id' => array( $objectIDs ) ), null, null, false );
        $out = array();
        foreach ( (array) $rows as $row )
            $out[(int) $row['contentobject_id'] . ':' . (int) $row['version']] = (int) $row['created'];
        return $out;
    }

    /**
     * @param array $pairs list of array( objectID, version )
     * @return array "objectID:version" => expAiDraftLog (newest entry of each that belongs to the version as it is now,
     *               see fetchByVersion())
     */
    public static function fetchForVersions( array $pairs )
    {
        $result = array();
        $objectIDs = array();
        foreach ( $pairs as $pair )
            $objectIDs[(int) $pair[0]] = true;
        if ( !$objectIDs )
            return $result;
        $wanted = array();
        foreach ( $pairs as $pair )
            $wanted[(int) $pair[0] . ':' . (int) $pair[1]] = true;
        $list = eZPersistentObject::fetchObjectList( self::definition(), null,
            array( 'contentobject_id' => array( array_keys( $objectIDs ) ) ), array( 'id' => 'asc' ) );
        $created = $list ? self::versionCreated( array_keys( $objectIDs ) ) : array();
        foreach ( $list as $log )
        {
            $key = $log->attribute( 'contentobject_id' ) . ':' . $log->attribute( 'version' );
            if ( isset( $wanted[$key] ) && self::belongsToVersion( (int) $log->attribute( 'created' ), isset( $created[$key] ) ? $created[$key] : 0 ) )
                $result[$key] = $log;
        }
        return $result;
    }

    /** @return expAiDraftLog[] the entries of a user on a node (newest first) */
    public static function fetchForUserNode( $userID, $nodeID, $limit = 50 )
    {
        return eZPersistentObject::fetchObjectList( self::definition(), null,
            array( 'user_id' => (int) $userID, 'node_id' => (int) $nodeID, 'source' => 'panel' ), array( 'id' => 'desc' ),
            array( 'offset' => 0, 'length' => (int) $limit ) );
    }

    /** @return expAiDraftLog[] */
    public static function fetchList( $userID = false, $offset = 0, $limit = 50 )
    {
        $conds = $userID !== false ? array( 'user_id' => (int) $userID ) : null;
        return eZPersistentObject::fetchObjectList( self::definition(), null, $conds, array( 'id' => 'desc' ),
            array( 'offset' => (int) $offset, 'length' => (int) $limit ) );
    }

    /** @return int */
    public static function fetchListCount( $userID = false )
    {
        $conds = $userID !== false ? array( 'user_id' => (int) $userID ) : null;
        return (int) eZPersistentObject::count( self::definition(), $conds );
    }

    /**
     * Checks for all entries at once (one query) whether their content object still exists, so that a list does
     * not look up a removed object row by row.
     * @param expAiDraftLog[] $logs
     * @return expAiDraftLog[] the same entries
     */
    public static function markObjectsThatExist( array $logs )
    {
        $ids = array();
        foreach ( $logs as $log )
            $ids[] = (int) $log->attribute( 'contentobject_id' );
        $existing = expAiObjects::existing( $ids );
        foreach ( $logs as $log )
            if ( (int) $log->attribute( 'contentobject_id' ) > 0 )
                $log->ObjectExists = isset( $existing[(int) $log->attribute( 'contentobject_id' )] );
        return $logs;
    }

    /** @return bool|null whether the content object still exists (null: not checked, see markObjectsThatExist()) */
    public function objectExists()
    {
        return $this->ObjectExists;
    }

    /** @return eZContentObject|null null, without a debug error, when the object was removed */
    public function contentObject()
    {
        if ( $this->ObjectExists === false )
            return null;
        $id = (int) $this->attribute( 'contentobject_id' );
        $object = $this->ObjectExists === true ? eZContentObject::fetch( $id ) : expAiObjects::fetch( $id );
        if ( $this->ObjectExists === null )
            $this->ObjectExists = (bool) $object;
        return $object;
    }

    /** @return eZContentObjectVersion|null */
    public function contentVersion()
    {
        if ( $this->ObjectExists === false )
            return null;
        return eZContentObjectVersion::fetchVersion( (int) $this->attribute( 'version' ), (int) $this->attribute( 'contentobject_id' ) );
    }

    /**
     * The entries whose content object no longer exists (removed, or purged from the trash), checked in batches.
     * @param bool $unmarkedOnly only those not marked "deleted" yet
     * @param int[]|null $rowIDs only among these entries (null: all)
     * @return array list of array( id, contentobject_id, status )
     */
    public static function orphanRows( $unmarkedOnly = false, $rowIDs = null )
    {
        $out = array();
        $offset = 0;
        $batch = 500;
        $conditions = array( 'contentobject_id' => array( '>', 0 ) );
        if ( $rowIDs !== null )
        {
            $rowIDs = array_values( array_filter( array_map( 'intval', (array) $rowIDs ) ) );
            if ( !$rowIDs )
                return $out;
            $conditions['id'] = array( $rowIDs );
        }
        do
        {
            $rows = eZPersistentObject::fetchObjectList( self::definition(), array( 'id', 'contentobject_id', 'status' ),
                $conditions, array( 'id' => 'asc' ), array( 'offset' => $offset, 'length' => $batch ), false );
            $rows = (array) $rows;
            $existing = expAiObjects::existing( array_column( $rows, 'contentobject_id' ) );
            foreach ( $rows as $row )
            {
                if ( isset( $existing[(int) $row['contentobject_id']] ) )
                    continue;
                if ( $unmarkedOnly && (string) $row['status'] === self::STATUS_DELETED )
                    continue;
                $out[] = array( 'id' => (int) $row['id'], 'contentobject_id' => (int) $row['contentobject_id'], 'status' => (string) $row['status'] );
            }
            $offset += $batch;
        }
        while ( count( $rows ) === $batch );
        return $out;
    }

    /**
     * Marks the entries whose content object was removed as "deleted" (the cronjob expai_prune). The rows stay:
     * they are the record of what was written with Exponential Intelligence.
     * @param int[]|null $rowIDs only among these entries (null: all)
     * @return int the entries marked now
     */
    public static function markDeletedObjects( $rowIDs = null )
    {
        $ids = array_column( self::orphanRows( true, $rowIDs ), 'id' );
        foreach ( array_chunk( $ids, 500 ) as $chunk )
            eZPersistentObject::updateObjectList( array( 'definition' => self::definition(),
                'update_fields' => array( 'status' => self::STATUS_DELETED, 'modified' => time() ),
                'conditions' => array( 'id' => $chunk ) ) );
        return count( $ids );
    }

    public function setStatus( $status )
    {
        $from = (string) $this->attribute( 'status' );
        $this->setAttribute( 'status', $status );
        $this->setAttribute( 'modified', time() );
        $this->store();
        if ( class_exists( 'expAiAudit' ) )
            expAiAudit::draftState( $this, $from, (string) $status );
    }
}
