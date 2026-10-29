<?php
/**
 * File containing the expAiCheckpoint class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Checkpoints of a thread (table expai_checkpoint, doc/checkpoints.md): the state at the start of every turn of the
 * lead run and before staged changes are written, so the editor can go back to it.
 *
 *   conversation_len  messages of the lead conversation at that point
 *   drafts_json       the drafts this thread wrote that were still drafts: object, version, language, and the values
 *                     of their writable fields (capped by [VectorSettings] MaxCheckpointBytes; over it the checkpoint
 *                     keeps no values: "conversation only")
 *   changes_json      meta: history_len, messages_hash, window_turns, the high-water marks of change, draft log and
 *                     follow-up ids, the waiting follow-ups, kind; changes: id, decision, run, the proposal and its parts
 *
 * Restore (owner only, nothing may run): drafts this thread created after the checkpoint are discarded (still drafts
 * of the editor; a published or submitted one is listed and left alone), drafts that existed get the values back that
 * changed since, staged changes made after it become stale and the others get their decisions and proposals back,
 * the lead conversation is cut back (a valid prefix, or rebuilt as a summary of the exchanges up to it), and
 * follow-ups queued after it are dropped. A restore writes a checkpoint "Before restore" first, so it can be undone.
 * Kept [ThreadSettings] CheckpointKeepDays (30), at most MaxCheckpointsPerThread per thread.
 */
class expAiCheckpoint extends eZPersistentObject
{
    const KIND_TURN = 'turn';
    const KIND_APPLY = 'apply';
    const KIND_PLAN = 'plan';
    const KIND_RESTORE = 'restore';

    public static function definition()
    {
        $int = function ( $name ) { return array( 'name' => $name, 'datatype' => 'integer', 'default' => 0, 'required' => true ); };
        $str = function ( $name, $default = '' ) { return array( 'name' => $name, 'datatype' => 'string', 'default' => $default, 'required' => true ); };
        return array(
            'fields' => array(
                'id' => $int( 'ID' ),
                'thread_id' => $int( 'ThreadID' ),
                'turn' => $int( 'Turn' ),
                'label' => $str( 'Label' ),
                'conversation_len' => $int( 'ConversationLen' ),
                'drafts_json' => $str( 'DraftsJSON', '[]' ),
                'changes_json' => $str( 'ChangesJSON', '{}' ),
                'created' => $int( 'Created' ),
            ),
            'keys' => array( 'id' ),
            'increment_key' => 'id',
            'class_name' => 'expAiCheckpoint',
            'sort' => array( 'id' => 'desc' ),
            'name' => 'expai_checkpoint',
        );
    }

    /** @return bool checkpoints are written ([VectorSettings] Checkpoints) */
    public static function enabled( ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        return $config->flag( 'VectorSettings', 'Checkpoints', true ) && expAiThread::available();
    }

    /** @return expAiCheckpoint|null */
    public static function fetchByID( $id )
    {
        if ( !(int) $id )
            return null;
        $object = eZPersistentObject::fetchObject( self::definition(), null, array( 'id' => (int) $id ) );
        return $object instanceof self ? $object : null;
    }

    /** @return expAiCheckpoint[] the newest checkpoints of a thread first */
    public static function fetchForThread( $threadID, $limit = 20 )
    {
        return (array) eZPersistentObject::fetchObjectList( self::definition(), null, array( 'thread_id' => (int) $threadID ), array( 'id' => 'desc' ),
                                                            array( 'offset' => 0, 'length' => max( 1, (int) $limit ) ) );
    }

    /** @return array meta and changes */
    public function data()
    {
        $data = json_decode( (string) $this->attribute( 'changes_json' ), true );
        return is_array( $data ) ? $data + array( 'meta' => array(), 'changes' => array() ) : array( 'meta' => array(), 'changes' => array() );
    }

    /** @return array the drafts with their values */
    public function drafts()
    {
        $data = json_decode( (string) $this->attribute( 'drafts_json' ), true );
        return is_array( $data ) ? $data : array();
    }

    /** @return int the highest id of a table's rows of a thread (0 none) */
    protected static function maxID( $definition, $threadID )
    {
        $rows = eZPersistentObject::fetchObjectList( $definition, array( 'id' ), array( 'thread_id' => (int) $threadID ), array( 'id' => 'desc' ),
                                                     array( 'offset' => 0, 'length' => 1 ), false );
        return $rows ? (int) $rows[0]['id'] : 0;
    }

    /** @return string the hash of a list of messages (a prefix of the conversation is recognized by it) */
    public static function hashMessages( array $messages )
    {
        return sha1( json_encode( array_values( $messages ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) );
    }

    /** @return expAiDraftLog[] the draft log rows of a thread, newest first */
    protected static function draftLogs( $threadID )
    {
        return (array) eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null, array( 'thread_id' => (int) $threadID ), array( 'id' => 'desc' ),
                                                            array( 'offset' => 0, 'length' => 500 ) );
    }

    /** @return eZContentObjectVersion|null the version when it is still a draft */
    protected static function draftVersion( $objectID, $version )
    {
        $object = expAiObjects::fetch( (int) $objectID );
        $v = $object ? $object->version( (int) $version ) : null;
        return $v && (int) $v->attribute( 'status' ) === eZContentObjectVersion::STATUS_DRAFT ? $v : null;
    }

    /**
     * The values of the writable fields of a draft in a language.
     * @return array identifier => { datatype, value }
     */
    protected static function draftValues( $objectID, $version, $language, expAiConfig $config )
    {
        $object = expAiObjects::fetch( (int) $objectID );
        if ( !$object )
            return array();
        $writable = $config->writableDatatypes();
        $out = array();
        foreach ( expAiDiff::values( $object, (int) $version, (string) $language ) as $identifier => $row )
            if ( in_array( $row['datatype'], $writable, true ) )
                $out[$identifier] = array( 'datatype' => $row['datatype'], 'value' => (string) $row['value'] );
        return $out;
    }

    /**
     * Writes a checkpoint of the thread now.
     * @param string $label "Before: <the message>"
     * @return expAiCheckpoint|null null when checkpoints are off or the thread has no conversation
     */
    public static function write( expAiThread $thread, $label, $kind = self::KIND_TURN, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        if ( !self::enabled( $config ) )
            return null;
        $conversation = $thread->conversation();
        if ( !$conversation )
            return null;
        $threadID = (int) $thread->attribute( 'id' );
        $messages = $conversation->messages();
        $history = $conversation->history();
        // the drafts this thread wrote that are still drafts (one row per object, version and language)
        $drafts = array();
        foreach ( self::draftLogs( $threadID ) as $log )
        {
            $key = $log->attribute( 'contentobject_id' ) . ':' . $log->attribute( 'version' ) . ':' . $log->attribute( 'language_code' );
            if ( isset( $drafts[$key] ) || !self::draftVersion( (int) $log->attribute( 'contentobject_id' ), (int) $log->attribute( 'version' ) ) )
                continue;
            $drafts[$key] = array( 'object_id' => (int) $log->attribute( 'contentobject_id' ), 'version' => (int) $log->attribute( 'version' ),
                                   'language' => (string) $log->attribute( 'language_code' ), 'created' => $log->attribute( 'action' ) === 'create',
                                   'values' => self::draftValues( (int) $log->attribute( 'contentobject_id' ), (int) $log->attribute( 'version' ),
                                                                  (string) $log->attribute( 'language_code' ), $config ) );
        }
        $changes = array();
        foreach ( expAiChange::fetchForThread( $threadID, array( 'pending', 'accepted', 'rejected', 'partial', 'applied', 'conflict', 'stale' ), 1000 ) as $change )
            $changes[] = array( 'id' => (int) $change->attribute( 'id' ), 'decision' => (string) $change->attribute( 'decision' ),
                                'modified' => (int) $change->attribute( 'modified' ), 'applied_version' => (int) $change->attribute( 'applied_version' ),
                                'proposed' => (string) $change->attribute( 'proposed' ), 'hunks_json' => (string) $change->attribute( 'hunks_json' ),
                                'base_version' => (int) $change->attribute( 'base_version' ), 'base_hash' => (string) $change->attribute( 'base_hash' ) );
        $waiting = array_map( function ( $f ) { return (int) $f->attribute( 'id' ); }, expAiFollowup::waiting( $threadID ) );
        $meta = array( 'kind' => (string) $kind, 'history_len' => count( $history ), 'messages_hash' => self::hashMessages( $messages ),
                       'window_turns' => $conversation->windowTurns(), 'run_id' => (int) $thread->attribute( 'lead_run_id' ),
                       'max_change_id' => self::maxID( expAiChange::definition(), $threadID ), 'max_log_id' => self::maxID( expAiDraftLog::definition(), $threadID ),
                       'max_followup_id' => self::maxID( expAiFollowup::definition(), $threadID ), 'followups' => $waiting,
                       'state' => (string) $thread->attribute( 'state' ), 'conversation_only' => false );
        // "Before restore" keeps the conversation itself: undoing the restore brings back the exchanges it cut
        if ( $kind === self::KIND_RESTORE )
        {
            $meta['history'] = $history;
            $meta['messages'] = $messages;
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $draftsJson = json_encode( array_values( $drafts ), $flags );
        $changesJson = json_encode( array( 'meta' => $meta, 'changes' => $changes ), $flags );
        // over the cap: the values and proposals are left out, the checkpoint restores the conversation and the decisions
        $cap = $config->int( 'VectorSettings', 'MaxCheckpointBytes', 1048576, 10000 );
        if ( strlen( $draftsJson ) + strlen( $changesJson ) > $cap )
        {
            $meta['conversation_only'] = true;
            unset( $meta['history'], $meta['messages'] );
            foreach ( $drafts as $key => $draft )
                $drafts[$key]['values'] = null;
            foreach ( $changes as $i => $change )
                unset( $changes[$i]['proposed'], $changes[$i]['hunks_json'] );
            $draftsJson = json_encode( array_values( $drafts ), $flags );
            $changesJson = json_encode( array( 'meta' => $meta, 'changes' => $changes ), $flags );
        }
        $checkpoint = new self( array( 'thread_id' => $threadID, 'turn' => count( $history ) + ( $kind === self::KIND_TURN ? 1 : 0 ),
                                       'label' => expAiText::cut( expAiText::oneLine( (string) $label ), 255 ), 'conversation_len' => count( $messages ),
                                       'drafts_json' => $draftsJson, 'changes_json' => $changesJson, 'created' => time() ) );
        $checkpoint->store();
        $thread->addEvent( 'checkpoint', array( 'op' => 'written', 'id' => (int) $checkpoint->attribute( 'id' ), 'kind' => (string) $kind ) );
        $thread->storeActivity();
        self::trim( $threadID, $config );
        return $checkpoint;
    }

    /** Keeps the newest [VectorSettings] MaxCheckpointsPerThread checkpoints of a thread */
    protected static function trim( $threadID, expAiConfig $config )
    {
        $max = $config->int( 'VectorSettings', 'MaxCheckpointsPerThread', 50, 2, 1000 );
        $old = eZPersistentObject::fetchObjectList( self::definition(), array( 'id' ), array( 'thread_id' => (int) $threadID ), array( 'id' => 'desc' ),
                                                    array( 'offset' => $max, 'length' => 500 ), false );
        foreach ( (array) $old as $row )
            eZPersistentObject::removeObject( self::definition(), array( 'id' => (int) $row['id'] ) );
    }

    /** @return string the label of a checkpoint before a message */
    public static function labelFor( $message )
    {
        return ezpI18n::tr( 'extension/expai/vector', 'Before: %text', null, array( '%text' => expAiText::cut( expAiText::oneLine( (string) $message ), 80 ) ) );
    }

    /** @return array the checkpoint for the browser */
    public function present()
    {
        $meta = $this->data()['meta'];
        return array( 'id' => (int) $this->attribute( 'id' ), 'turn' => (int) $this->attribute( 'turn' ), 'label' => (string) $this->attribute( 'label' ),
                      'kind' => isset( $meta['kind'] ) ? (string) $meta['kind'] : self::KIND_TURN, 'created' => (int) $this->attribute( 'created' ),
                      'conversation_only' => !empty( $meta['conversation_only'] ) );
    }

    /**
     * The newest checkpoints of a thread for the browser (the strip): id, turn, label, created. Only these columns are
     * read (the snapshots can be large).
     * @return array
     */
    public static function listFor( expAiThread $thread, $limit = 12 )
    {
        if ( !expAiThread::available() )
            return array();
        $rows = eZPersistentObject::fetchObjectList( self::definition(), array( 'id', 'turn', 'label', 'created' ), array( 'thread_id' => (int) $thread->attribute( 'id' ) ),
                                                     array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => max( 1, (int) $limit ) ), false );
        return array_map( function ( $r ) { return array( 'id' => (int) $r['id'], 'turn' => (int) $r['turn'], 'label' => (string) $r['label'], 'created' => (int) $r['created'] ); },
                          (array) $rows );
    }

    /* ------------------------------------------------------------- restore */

    /**
     * What a restore to this checkpoint would do, without doing it.
     * @return array discard (drafts discarded), revert (drafts whose fields go back: fields), left (drafts left alone,
     *               with why), stale (changes set aside), restore (changes whose decision or proposal goes back),
     *               exchanges (exchanges removed from the conversation), followups (queued messages dropped), blocked ('' or why
     *               it cannot run now), conversation_only
     */
    public function preview( expAiThread $thread, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $data = $this->data();
        $meta = $data['meta'];
        $threadID = (int) $thread->attribute( 'id' );
        $out = array( 'discard' => array(), 'revert' => array(), 'left' => array(), 'stale' => array(), 'restore' => array(), 'exchanges' => 0,
                      'followups' => array(), 'blocked' => '', 'conversation_only' => !empty( $meta['conversation_only'] ),
                      'checkpoint' => $this->present() );
        if ( (int) $this->attribute( 'thread_id' ) !== $threadID )
            $out['blocked'] = ezpI18n::tr( 'extension/expai/vector', 'This checkpoint belongs to another thread.' );
        elseif ( in_array( $thread->attribute( 'state' ), array( 'queued', 'running' ), true ) )
            $out['blocked'] = ezpI18n::tr( 'extension/expai/vector', 'Stop the thread first: nothing may run while it is restored.' );
        $userID = (int) eZUser::currentUserID();
        $known = array();
        foreach ( $this->drafts() as $draft )
            $known[$draft['object_id'] . ':' . $draft['version'] . ':' . $draft['language']] = $draft;
        // drafts written after the checkpoint (draft log rows after its high-water mark)
        $seen = array();
        foreach ( self::draftLogs( $threadID ) as $log )
        {
            $key = $log->attribute( 'contentobject_id' ) . ':' . $log->attribute( 'version' ) . ':' . $log->attribute( 'language_code' );
            if ( isset( $seen[$key] ) )
                continue;
            $seen[$key] = true;
            $objectID = (int) $log->attribute( 'contentobject_id' );
            $object = expAiObjects::fetch( $objectID );
            // the name only for a viewer who may read the object
            $name = $object && $object->canRead() ? (string) $object->attribute( 'name' ) : '#' . $objectID;
            $row = array( 'object_id' => $objectID, 'version' => (int) $log->attribute( 'version' ), 'language' => (string) $log->attribute( 'language_code' ),
                          'name' => $name, 'log_id' => (int) $log->attribute( 'id' ) );
            $version = self::draftVersion( $objectID, (int) $log->attribute( 'version' ) );
            if ( isset( $known[$key] ) )
            {
                if ( !$version )
                {
                    $out['left'][] = $row + array( 'why' => ezpI18n::tr( 'extension/expai/vector', 'no longer a draft (published or sent for approval)' ) );
                    continue;
                }
                if ( !is_array( $known[$key]['values'] ) )
                    continue;
                $now = self::draftValues( $objectID, (int) $log->attribute( 'version' ), (string) $log->attribute( 'language_code' ), $config );
                $fields = array();
                foreach ( $known[$key]['values'] as $identifier => $was )
                    if ( isset( $now[$identifier] ) && (string) $now[$identifier]['value'] !== (string) $was['value'] )
                        $fields[] = $identifier;
                if ( $fields )
                    $out['revert'][] = $row + array( 'fields' => $fields );
                continue;
            }
            if ( (int) $log->attribute( 'id' ) <= (int) $meta['max_log_id'] )
                continue;
            if ( !$version )
                $out['left'][] = $row + array( 'why' => ezpI18n::tr( 'extension/expai/vector', 'no longer a draft (published or sent for approval)' ) );
            elseif ( (int) $version->attribute( 'creator_id' ) !== $userID )
                $out['left'][] = $row + array( 'why' => ezpI18n::tr( 'extension/expai/vector', 'a draft of another user' ) );
            else
                $out['discard'][] = $row + array( 'new_object' => $object && (int) $object->attribute( 'status' ) === eZContentObject::STATUS_DRAFT );
        }
        $before = array();
        foreach ( $data['changes'] as $c )
            $before[(int) $c['id']] = $c;
        foreach ( expAiChange::fetchForThread( $threadID, array( 'pending', 'accepted', 'rejected', 'partial', 'applied', 'conflict', 'stale' ), 1000 ) as $change )
        {
            $id = (int) $change->attribute( 'id' );
            $label = array( 'id' => $id, 'field' => (string) $change->attribute( 'attribute_identifier' ), 'language' => (string) $change->attribute( 'language_code' ),
                            'object_id' => (int) $change->attribute( 'contentobject_id' ), 'decision' => (string) $change->attribute( 'decision' ) );
            if ( !isset( $before[$id] ) )
            {
                if ( $id > (int) $meta['max_change_id'] && $change->attribute( 'decision' ) !== 'stale' )
                    $out['stale'][] = $label;
                continue;
            }
            $was = $before[$id];
            if ( (string) $was['decision'] !== (string) $change->attribute( 'decision' ) || (int) $was['modified'] !== (int) $change->attribute( 'modified' ) )
                $out['restore'][] = $label + array( 'to' => (string) $was['decision'] );
        }
        $conversation = $thread->conversation();
        $out['exchanges'] = $conversation ? max( 0, count( $conversation->history() ) - (int) $meta['history_len'] ) : 0;
        // an undo of a restore brings exchanges back
        $out['exchanges_back'] = $conversation && isset( $meta['history'] ) ? max( 0, (int) $meta['history_len'] - count( $conversation->history() ) ) : 0;
        foreach ( expAiFollowup::waiting( $threadID ) as $followup )
            if ( (int) $followup->attribute( 'id' ) > (int) $meta['max_followup_id'] )
                $out['followups'][] = array( 'id' => (int) $followup->attribute( 'id' ), 'body' => expAiText::cut( (string) $followup->attribute( 'body' ), 120 ) );
        $out['nothing'] = !$out['discard'] && !$out['revert'] && !$out['stale'] && !$out['restore'] && !$out['exchanges'] && !$out['exchanges_back'] && !$out['followups'];
        return $out;
    }

    /**
     * Restores the thread to this checkpoint (see the class comment). The owner only; nothing may run.
     * @return array the preview that was carried out, with before (the checkpoint "Before restore") and errors
     * @throws expAiException
     */
    public function restore( expAiThread $thread, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        if ( !$thread->isOwner( (int) eZUser::currentUser()->attribute( 'contentobject_id' ) ) )
            throw new expAiException( 'Only the owner of this thread can restore it.' );
        $plan = $this->preview( $thread, $config );
        if ( $plan['blocked'] !== '' )
            throw ( new expAiException( $plan['blocked'] ) )->translated();
        // the state now, so the restore can be undone
        $before = self::write( $thread, ezpI18n::tr( 'extension/expai/vector', 'Before restore to: %label', null, array( '%label' => (string) $this->attribute( 'label' ) ) ),
                               self::KIND_RESTORE, $config );
        $errors = array();
        $data = $this->data();
        $meta = $data['meta'];
        $known = array();
        foreach ( $this->drafts() as $draft )
            $known[$draft['object_id'] . ':' . $draft['version'] . ':' . $draft['language']] = $draft;
        // drafts written after the checkpoint: discarded
        foreach ( $plan['discard'] as $row )
        {
            $object = expAiObjects::fetch( (int) $row['object_id'] );
            $version = self::draftVersion( (int) $row['object_id'], (int) $row['version'] );
            if ( !$object || !$version )
                continue;
            if ( (int) $object->attribute( 'status' ) === eZContentObject::STATUS_DRAFT )
                $object->purge();
            else
                $version->removeThis();
            eZPersistentObject::updateObjectList( array( 'definition' => expAiDraftLog::definition(), 'update_fields' => array( 'status' => expAiDraftLog::STATUS_DISCARDED, 'modified' => time() ),
                                                         'conditions' => array( 'thread_id' => (int) $thread->attribute( 'id' ), 'contentobject_id' => (int) $row['object_id'],
                                                                                'version' => (int) $row['version'] ) ) );
        }
        // drafts that existed: the fields that changed since get their values back
        foreach ( $plan['revert'] as $row )
        {
            $key = $row['object_id'] . ':' . $row['version'] . ':' . $row['language'];
            $object = expAiObjects::fetch( (int) $row['object_id'] );
            if ( !$object || !isset( $known[$key] ) || !is_array( $known[$key]['values'] ) )
                continue;
            $values = array();
            $types = array();
            foreach ( $row['fields'] as $identifier )
            {
                $values[$identifier] = (string) $known[$key]['values'][$identifier]['value'];
                $types[$identifier] = (string) $known[$key]['values'][$identifier]['datatype'];
            }
            try
            {
                $result = expAiDiff::writeDraft( $object, (string) $row['language'], $values, $types,
                    array( 'reuse_version' => (int) $row['version'], 'source' => 'vector', 'thread_id' => (int) $thread->attribute( 'id' ),
                           'model' => (string) $thread->attribute( 'model_alias' ), 'prompt_summary' => expAiText::cut( (string) $this->attribute( 'label' ), 200 ) ) );
                foreach ( (array) $result['errors'] as $field => $error )
                    $errors[] = $row['name'] . ' ' . $field . ': ' . $error;
            }
            catch ( Exception $e )
            {
                $errors[] = $row['name'] . ': ' . $e->getMessage();
            }
        }
        // staged changes: the later ones set aside, the others as they were
        $before = $before ?: null;
        $was = array();
        foreach ( $data['changes'] as $c )
            $was[(int) $c['id']] = $c;
        foreach ( $plan['stale'] as $row )
        {
            $change = expAiChange::fetchByID( (int) $row['id'] );
            if ( $change )
            {
                $change->setAttribute( 'decision', 'stale' );
                $change->setAttribute( 'modified', time() );
                $change->store();
            }
        }
        foreach ( $plan['restore'] as $row )
        {
            $change = expAiChange::fetchByID( (int) $row['id'] );
            $c = $was[(int) $row['id']];
            if ( !$change )
                continue;
            $change->setAttribute( 'decision', (string) $c['decision'] );
            $change->setAttribute( 'applied_version', (int) $c['applied_version'] );
            if ( isset( $c['proposed'], $c['hunks_json'] ) )
            {
                $change->setAttribute( 'proposed', (string) $c['proposed'] );
                $change->setAttribute( 'hunks_json', (string) $c['hunks_json'] );
                $change->setAttribute( 'base_version', (int) $c['base_version'] );
                $change->setAttribute( 'base_hash', (string) $c['base_hash'] );
            }
            $change->setAttribute( 'modified', time() );
            $change->store();
        }
        // the lead conversation back to the checkpoint
        $conversation = $thread->conversation();
        if ( $conversation && $plan['exchanges'] >= 0 )
            self::cutConversation( $conversation, (int) $this->attribute( 'conversation_len' ), $meta, $config );
        foreach ( $plan['followups'] as $row )
        {
            $followup = expAiFollowup::fetchByID( (int) $row['id'] );
            if ( $followup && $followup->attribute( 'state' ) === 'waiting' )
            {
                $followup->setAttribute( 'state', 'dropped' );
                $followup->setAttribute( 'modified', time() );
                $followup->store();
            }
        }
        $thread = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ?: $thread;
        $thread->addEvent( 'checkpoint', array( 'op' => 'restored', 'id' => (int) $this->attribute( 'id' ), 'before' => $before ? (int) $before->attribute( 'id' ) : 0,
                                                'discarded' => count( $plan['discard'] ), 'reverted' => count( $plan['revert'] ), 'stale' => count( $plan['stale'] ),
                                                'exchanges' => (int) $plan['exchanges'] ) );
        $thread->setState( $thread->restingState() );
        $thread->touch();
        $thread->store();
        return $plan + array( 'before' => $before ? $before->present() : null, 'errors' => $errors );
    }

    /**
     * Cuts the conversation back to the checkpoint: its first $length messages when they are still the same (a prefix
     * keeps signed thinking blocks valid), else the exchanges up to it as a short summary (the instructions are built
     * again for the next turn).
     */
    public static function cutConversation( expAiConversation $conversation, $length, array $meta, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        // a "Before restore" checkpoint has the conversation itself (the undo of a restore that cut it)
        if ( isset( $meta['history'], $meta['messages'] ) && is_array( $meta['history'] ) && is_array( $meta['messages'] ) )
        {
            $conversation->setHistory( $meta['history'] );
            $conversation->setMessages( $meta['messages'] );
            $conversation->setWindowTurns( isset( $meta['window_turns'] ) ? (int) $meta['window_turns'] : count( $meta['history'] ) );
            $conversation->setAttribute( 'iterations', 0 );
            $conversation->setAttribute( 'modified', time() );
            $conversation->store();
            return;
        }
        $messages = $conversation->messages();
        $history = array_slice( $conversation->history(), 0, max( 0, (int) $meta['history_len'] ) );
        $prefix = array_slice( $messages, 0, max( 0, (int) $length ) );
        if ( count( $messages ) >= (int) $length && self::hashMessages( $prefix ) === (string) $meta['messages_hash'] )
        {
            $conversation->setMessages( $prefix );
            $conversation->setWindowTurns( isset( $meta['window_turns'] ) ? (int) $meta['window_turns'] : count( $history ) );
        }
        else
        {
            $keep = max( 1, (int) floor( $config->int( 'AssistantSettings', 'ContextWindowTurns', 8, 2, 100 ) / 2 ) );
            $conversation->setMessages( $history ? expAiAssistant::compact( $history, $keep ) : array() );
            $conversation->setWindowTurns( min( count( $history ), $keep ) );
            $conversation->setFrame( '', array() );
        }
        $conversation->setHistory( $history );
        $conversation->setAttribute( 'iterations', 0 );
        $conversation->setAttribute( 'modified', time() );
        $conversation->store();
    }
}
