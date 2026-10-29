<?php
/**
 * File containing the expAiChangeApplier class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Writes the accepted parts of staged changes into drafts (doc/review.md "Apply"). Never publishes.
 *
 * Per object and language (or proposed new object): every change with accepted hunks is checked against its base (the
 * sha256 of the published value at staging time); a changed base is a conflict, which is not written. The others are
 * merged at block level (expAiDiff::apply() with the accepted hunk ids: rejected hunks keep the published text) and
 * written as the current user through expAiDiff::apply( ..., write ) into the draft this thread wrote for the object
 * before, else a new draft; a proposed new object is created as a draft under its parent first. Each draft is logged
 * in expai_draft_log with source "vector", the thread and the change.
 */
class expAiChangeApplier
{
    /** @var expAiThread */
    protected $thread;

    public function __construct( expAiThread $thread )
    {
        $this->thread = $thread;
    }

    /**
     * @param int[] $changeIDs only these changes (empty: every change with accepted hunks)
     * @return array drafts (list of { object_id, version, language, name, created, changes, missing, urls }), conflicts
     *               (change ids), errors (list of { target, message }), applied (change ids)
     */
    public function apply( array $changeIDs = array() )
    {
        $threadID = (int) $this->thread->attribute( 'id' );
        $only = array_flip( array_map( 'intval', $changeIDs ) );
        $groups = array();
        foreach ( expAiChange::fetchForThread( $threadID, array( 'accepted', 'partial' ) ) as $change )
        {
            if ( $only && !isset( $only[(int) $change->attribute( 'id' )] ) )
                continue;
            if ( !$change->acceptedHunkIDs() )
                continue;
            $groups[$change->targetKey()][] = $change;
        }
        $out = array( 'drafts' => array(), 'conflicts' => array(), 'errors' => array(), 'applied' => array() );
        foreach ( $groups as $key => $changes )
        {
            try
            {
                $draft = $changes[0]->isNew() ? $this->applyNew( $changes, $out ) : $this->applyExisting( $changes, $out );
                if ( $draft )
                    $out['drafts'][] = $draft;
            }
            catch ( expAiException $e )
            {
                $out['errors'][] = array( 'target' => $key, 'message' => $e->translatedMessage() );
            }
        }
        if ( $out['applied'] || $out['conflicts'] )
        {
            $this->thread->addEvent( 'status', array( 'applied' => count( $out['applied'] ), 'conflicts' => count( $out['conflicts'] ) ) );
            if ( !expAiChange::counts( $threadID )['open'] && $this->thread->attribute( 'state' ) === 'review' && !$out['drafts'] )
                $this->thread->setState( 'idle' );
            elseif ( $out['drafts'] && !in_array( $this->thread->attribute( 'state' ), array( 'queued', 'running' ), true ) )
                $this->thread->setState( 'review' );
            $this->thread->touch();
            $this->thread->store();
        }
        return $out;
    }

    /** @return array the options of expAiDiff::apply() for a draft of this thread */
    protected function options( expAiChange $first, array $extra = array() )
    {
        return $extra + array( 'write' => true, 'structure_guard' => true, 'source' => 'vector', 'thread_id' => (int) $this->thread->attribute( 'id' ),
                               'change_id' => (int) $first->attribute( 'id' ), 'model' => (string) $this->thread->attribute( 'model_alias' ),
                               'provider' => (string) $this->thread->attribute( 'provider' ) ?: expAiConfig::instance()->registry()->providerOf( (string) $this->thread->attribute( 'model_alias' ) ),
                               'prompt_summary' => expAiText::cut( (string) $this->thread->attribute( 'title' ), 200 ) );
    }

    /** @return expAiDiffResult the diff of the changes' bases and proposals */
    protected static function diffOf( array $changes, array $bases, $objectID )
    {
        $a = array();
        $b = array();
        foreach ( $changes as $change )
        {
            $identifier = (string) $change->attribute( 'attribute_identifier' );
            $a[$identifier] = array( 'datatype' => (string) $change->attribute( 'datatype' ), 'value' => $bases[$identifier] );
            $b[$identifier] = array( 'datatype' => (string) $change->attribute( 'datatype' ), 'value' => (string) $change->attribute( 'proposed' ) );
        }
        $language = (string) $changes[0]->attribute( 'language_code' );
        return expAiDiff::compare( array( 'values' => $a, 'language' => $language, 'object_id' => (int) $objectID ),
                                   array( 'values' => $b, 'language' => $language, 'object_id' => (int) $objectID ) );
    }

    /** @return string[] the accepted hunk ids of changes */
    protected static function accepted( array $changes )
    {
        $ids = array();
        foreach ( $changes as $change )
            $ids = array_merge( $ids, $change->acceptedHunkIDs() );
        return $ids;
    }

    /** Marks changes applied into a version, with draft events */
    protected function markApplied( array $changes, $version, array &$out )
    {
        $now = time();
        foreach ( $changes as $change )
        {
            $change->setAttribute( 'decision', 'applied' );
            $change->setAttribute( 'applied_version', (int) $version );
            $change->setAttribute( 'decided_by', (int) eZUser::currentUserID() );
            $change->setAttribute( 'decided', $now );
            $change->setAttribute( 'modified', $now );
            $change->store();
            $out['applied'][] = (int) $change->attribute( 'id' );
        }
    }

    /** @return array|null the draft of an existing object */
    protected function applyExisting( array $changes, array &$out )
    {
        $object = expAiObjects::fetch( (int) $changes[0]->attribute( 'contentobject_id' ) );
        $language = (string) $changes[0]->attribute( 'language_code' );
        if ( !$object instanceof eZContentObject || !$object->canRead() )
            throw new expAiException( 'Object %id does not exist or you may not read it.', '', 0, array( '%id' => (int) $changes[0]->attribute( 'contentobject_id' ) ) );
        if ( !$object->canEdit( false, false, false, $language ) )
            throw new expAiException( 'You may not edit object %id in %language.', '', 0, array( '%id' => (int) $object->attribute( 'id' ), '%language' => $language ) );
        $bases = array();
        $ready = array();
        foreach ( $changes as $change )
        {
            $base = expAiChange::baseOf( $object, $language, (string) $change->attribute( 'attribute_identifier' ) );
            if ( expAiChange::hash( $base['value'] ) !== (string) $change->attribute( 'base_hash' ) )
            {
                // someone changed the field since the proposal: never written over silently
                $change->setAttribute( 'decision', 'conflict' );
                $change->setAttribute( 'modified', time() );
                $change->store();
                $out['conflicts'][] = (int) $change->attribute( 'id' );
                continue;
            }
            $bases[(string) $change->attribute( 'attribute_identifier' )] = $base['value'];
            $ready[] = $change;
        }
        if ( !$ready )
            return null;
        $diff = self::diffOf( $ready, $bases, (int) $object->attribute( 'id' ) );
        $result = expAiDiff::apply( $object, self::accepted( $ready ), $this->options( $ready[0], array(
            'diff' => $diff, 'language' => $language, 'reuse_version' => $this->draftVersion( (int) $object->attribute( 'id' ), $language ) ) ) );
        if ( $result['errors'] && !$result['written'] )
            throw new expAiException( 'The draft could not be written: %errors', '', 0, array( '%errors' => implode( '; ', array_map( function ( $k, $v ) { return "$k: $v"; },
                                                                                                                         array_keys( $result['errors'] ), $result['errors'] ) ) ) );
        $this->markApplied( $ready, (int) $result['version'], $out );
        $this->thread->addEvent( 'draft', array( 'object_id' => (int) $object->attribute( 'id' ), 'version' => (int) $result['version'], 'language' => (string) $result['language'],
                                                 'action' => 'update', 'changes' => array_map( function ( $c ) { return (int) $c->attribute( 'id' ); }, $ready ) ) );
        return $this->draftRow( (int) $object->attribute( 'id' ), (int) $result['version'], (string) $result['language'], false, $ready, $result );
    }

    /** @return array the draft of a proposed new object */
    protected function applyNew( array $changes, array &$out )
    {
        $first = $changes[0];
        $parent = eZContentObjectTreeNode::fetch( (int) $first->attribute( 'parent_node_id' ) );
        if ( !$parent instanceof eZContentObjectTreeNode || !$parent->canRead() )
            throw new expAiException( 'Node %id does not exist or you may not read it.', '', 0, array( '%id' => (int) $first->attribute( 'parent_node_id' ) ) );
        $bases = array();
        foreach ( $changes as $change )
            $bases[(string) $change->attribute( 'attribute_identifier' )] = '';
        $diff = self::diffOf( $changes, $bases, 0 );
        $merged = expAiDiff::apply( $diff, self::accepted( $changes ) );
        $writer = new expAiContentWriter();
        // the object first (content/create of the user under the parent), then the values in storage form
        list( $object, $version ) = $writer->createDraft( $parent, (string) $first->attribute( 'class_identifier' ), array(), (string) $first->attribute( 'language_code' ) );
        $types = array();
        foreach ( $changes as $change )
            $types[(string) $change->attribute( 'attribute_identifier' )] = (string) $change->attribute( 'datatype' );
        $object = eZContentObject::fetch( (int) $object->attribute( 'id' ) );
        $result = expAiDiff::writeDraft( $object, (string) $first->attribute( 'language_code' ), $merged['values'], $types,
                                         $this->options( $first, array( 'reuse_version' => (int) $version->attribute( 'version' ), 'action' => 'create' ) ) );
        $this->markApplied( $changes, (int) $result['version'], $out );
        $this->thread->addEvent( 'draft', array( 'object_id' => (int) $object->attribute( 'id' ), 'version' => (int) $result['version'], 'language' => (string) $result['language'],
                                                 'action' => 'create', 'changes' => array_map( function ( $c ) { return (int) $c->attribute( 'id' ); }, $changes ) ) );
        return $this->draftRow( (int) $object->attribute( 'id' ), (int) $result['version'], (string) $result['language'], true, $changes, $result );
    }

    /** @return int the version of a draft this thread wrote for the object in the language, still a draft of the user (0: none) */
    protected function draftVersion( $objectID, $language )
    {
        $logs = eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null,
            array( 'thread_id' => (int) $this->thread->attribute( 'id' ), 'contentobject_id' => (int) $objectID, 'language_code' => (string) $language,
                   'user_id' => (int) eZUser::currentUserID() ),
            array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => 5 ) );
        foreach ( (array) $logs as $log )
        {
            if ( !in_array( $log->attribute( 'status' ), array( expAiDraftLog::STATUS_DRAFT, expAiDraftLog::STATUS_KEPT ), true ) )
                continue;
            $object = expAiObjects::fetch( (int) $objectID );
            $version = $object ? $object->version( (int) $log->attribute( 'version' ) ) : null;
            if ( $version && (int) $version->attribute( 'status' ) === eZContentObjectVersion::STATUS_DRAFT )
                return (int) $log->attribute( 'version' );
        }
        return 0;
    }

    /** @return array a written draft for the browser */
    protected function draftRow( $objectID, $version, $language, $created, array $changes, array $result )
    {
        $object = expAiObjects::fetch( (int) $objectID );
        $writer = new expAiContentWriter();
        $versionObject = $object ? $object->version( (int) $version ) : null;
        return array( 'object_id' => (int) $objectID, 'version' => (int) $version, 'language' => (string) $language, 'created' => (bool) $created,
                      'name' => $object ? (string) $object->versionLanguageName( (int) $version, $language ) : '#' . (int) $objectID,
                      'changes' => array_map( function ( $c ) { return (int) $c->attribute( 'id' ); }, $changes ),
                      'written' => array_values( (array) $result['written'] ), 'errors' => (array) $result['errors'],
                      'missing' => $object && $versionObject ? $writer->missingRequired( $object, $versionObject ) : array(),
                      'urls' => array( 'edit' => 'content/edit/' . (int) $objectID . '/' . (int) $version . '/' . $language,
                                       'view' => 'content/versionview/' . (int) $objectID . '/' . (int) $version . '/' . $language ) );
    }
}
