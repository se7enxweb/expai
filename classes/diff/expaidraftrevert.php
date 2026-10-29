<?php
/**
 * File containing the expAiDraftRevert class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * "What changed" of a draft the assistant wrote directly (the Intelligence tab, a Vector thread with Review: Direct;
 * doc/review.md "Direct mode: what changed, and revert"): the diff of the draft against the version it was based on,
 * per field and part (expAiDiff), and the revert of single parts or whole fields into the same draft.
 *
 *   $revert = expAiDraftRevert::forDraft( $objectID, $version );       the editor's own draft, else expAiException
 *   $revert->changes();                                                 fields with hunks, hashes, base version
 *   $revert->revert( array( 'h3f2a...' ), array( 'title' ), $seen );    the parts and fields back to the base
 *
 * Who: the editor the draft was written for (expai_draft_log.user_id); a draft of a thread only the thread's owner.
 * Revert also needs the version to be still a draft of the current user and content/edit in its language, checked
 * at that moment. Nothing is ever published: the values are written into the same draft
 * (expAiDiff::writeDraft with reuse_version), logged with source "revert".
 *
 * Conflicts: changes() returns a hash of every field's value in the draft. revert() takes the hashes the editor saw;
 * when a field to revert has changed in the draft since (edited in the edit form, written again by the assistant), or a
 * part is no longer there, nothing is written and the result is status "conflict" with the fields and the diff as it
 * is now.
 */
class expAiDraftRevert
{
    const SOURCE = 'revert';

    /** @var eZContentObject */
    protected $object;
    /** @var eZContentObjectVersion */
    protected $version;
    /** @var expAiDraftLog the entry that wrote the version (not a revert) */
    protected $log;
    /** @var eZUser */
    protected $user;
    /** @var string */
    protected $language;
    /** @var expAiConfig */
    protected $config;

    protected function __construct( eZContentObject $object, eZContentObjectVersion $version, expAiDraftLog $log, eZUser $user, expAiConfig $config )
    {
        $this->object = $object;
        $this->version = $version;
        $this->log = $log;
        $this->user = $user;
        $this->config = $config;
        $this->language = (string) $log->attribute( 'language_code' ) !== '' ? (string) $log->attribute( 'language_code' ) : (string) $version->initialLanguageCode();
    }

    /**
     * @return expAiDraftRevert the version the assistant wrote for the current user (or in a thread the user owns)
     * @throws expAiException when it does not exist, was not written by the assistant or belongs to someone else
     */
    public static function forDraft( $objectID, $versionNo, ?eZUser $user = null, ?expAiConfig $config = null )
    {
        $user = $user ?: eZUser::currentUser();
        $object = expAiObjects::fetch( (int) $objectID );
        if ( !$object instanceof eZContentObject || !$object->canRead() )
            throw new expAiException( 'Object %id does not exist or you may not read it.', '', 0, array( '%id' => (int) $objectID ) );
        $version = $object->version( (int) $versionNo );
        $log = self::writtenBy( (int) $objectID, (int) $versionNo );
        if ( !$version instanceof eZContentObjectVersion || !$log )
            throw new expAiException( 'This version was not written by Exponential Intelligence.', 'object ' . (int) $objectID . ' version ' . (int) $versionNo );
        $userID = (int) $user->attribute( 'contentobject_id' );
        $threadID = (int) $log->attribute( 'thread_id' );
        if ( $threadID && expAiThread::available() )
        {
            $thread = expAiThread::fetchByID( $threadID );
            if ( !$thread || !$thread->isOwner( $userID ) )
                throw new expAiException( 'Only the owner of the thread can see and revert what this draft changed.' );
        }
        elseif ( (int) $log->attribute( 'user_id' ) !== $userID )
            throw new expAiException( 'Only the editor the draft was written for can see and revert what it changed.' );
        return new self( $object, $version, $log, $user, $config ?: expAiConfig::instance() );
    }

    /** @return expAiDraftLog|null the newest log entry that wrote the version (entries of reverts left out) */
    public static function writtenBy( $objectID, $versionNo )
    {
        $list = eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null,
            array( 'contentobject_id' => (int) $objectID, 'version' => (int) $versionNo ), array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => 20 ) );
        foreach ( (array) $list as $log )
            if ( $log->attribute( 'source' ) !== self::SOURCE )
                return $log;
        return null;
    }

    /**
     * The version the draft was based on: none for a new object, else the newest published or archived version
     * below the draft (a new version is copied from the version published at that moment).
     * @return int 0 for none
     */
    public function baseVersion()
    {
        if ( $this->log->attribute( 'action' ) === 'create' )
            return 0;
        $draftNo = (int) $this->version->attribute( 'version' );
        $best = 0;
        foreach ( (array) $this->object->versions() as $v )
        {
            $no = (int) $v->attribute( 'version' );
            $status = (int) $v->attribute( 'status' );
            if ( $no < $draftNo && $no > $best
                 && ( $status === eZContentObjectVersion::STATUS_PUBLISHED || $status === eZContentObjectVersion::STATUS_ARCHIVED ) )
                $best = $no;
        }
        return $best;
    }

    /** @return string[] the datatypes the assistant writes, so the only ones a part can be reverted in */
    protected function datatypes()
    {
        return array_values( array_unique( array_merge( $this->config->writableDatatypes(), array( 'ezxmltext' ) ) ) );
    }

    /** @return expAiDiffResult the base version (or nothing) against the draft as it is now */
    public function diff()
    {
        $base = $this->baseVersion();
        $baseSide = $base ? array( 'object' => $this->object, 'version' => $base, 'language' => $this->language )
                          : array( 'values' => array(), 'language' => $this->language, 'object_id' => (int) $this->object->attribute( 'id' ) );
        // a language the base version does not have counts as empty (a new translation)
        if ( $base && !in_array( $this->language, (array) $this->object->version( $base )->translationList( false, false ), true ) )
            $baseSide = array( 'values' => array(), 'language' => $this->language, 'object_id' => (int) $this->object->attribute( 'id' ), 'version' => $base );
        $draftSide = array( 'object' => $this->object, 'version' => (int) $this->version->attribute( 'version' ), 'language' => $this->language );
        return expAiDiff::compare( $baseSide, $draftSide, array( 'language' => $this->language, 'datatypes' => $this->datatypes(), 'config' => $this->config ) );
    }

    /** @return string the fingerprint of a field's value in the draft */
    public static function hash( $value )
    {
        return substr( sha1( (string) $value ), 0, 16 );
    }

    /** @return bool the version is a draft of the current user */
    public function isOwnDraft()
    {
        return (int) $this->version->attribute( 'status' ) === eZContentObjectVersion::STATUS_DRAFT
            && (int) $this->version->attribute( 'creator_id' ) === (int) $this->user->attribute( 'contentobject_id' );
    }

    /** @return string why the parts cannot be reverted now ('' when they can) */
    public function revertRefusal()
    {
        if ( (int) $this->version->attribute( 'status' ) !== eZContentObjectVersion::STATUS_DRAFT )
            return ezpI18n::tr( 'extension/expai/revert', 'This version is no longer a draft; only drafts can be reverted.' );
        if ( !$this->isOwnDraft() )
            return ezpI18n::tr( 'extension/expai/revert', 'This draft belongs to someone else.' );
        if ( !$this->object->canEdit( false, false, false, $this->language ) )
            return ezpI18n::tr( 'extension/expai/revert', 'You may not edit this content in %language.', null, array( '%language' => $this->language ) );
        return '';
    }

    /**
     * What the draft changed, for the browser.
     * @return array object_id, version, language, name, base_version, published_version, published_newer, can_revert,
     *               refusal, fields (identifier, name, datatype, hash, hunks), metrics
     */
    public function changes( ?expAiDiffResult $diff = null )
    {
        $diff = $diff ?: $this->diff();
        $fields = array();
        foreach ( $diff->attributes() as $identifier => $attribute )
        {
            if ( !$attribute['hunks'] )
                continue;
            $hunks = array();
            foreach ( $attribute['hunks'] as $hunk )
                $hunks[] = array( 'id' => $hunk['id'], 'op' => $hunk['op'], 'base_text' => (string) $hunk['base_text'], 'prop_text' => (string) $hunk['prop_text'],
                                  'context_before' => (string) $hunk['context_before'], 'context_after' => (string) $hunk['context_after'],
                                  'mergeable' => !empty( $hunk['mergeable'] ), 'chars' => (int) $hunk['chars'] );
            $fields[] = array( 'identifier' => (string) $identifier, 'name' => (string) $attribute['name'], 'datatype' => (string) $attribute['datatype'],
                               'hash' => self::hash( $attribute['prop_value'] ), 'hunks' => $hunks );
        }
        $base = $this->baseVersion();
        $published = (int) $this->object->attribute( 'current_version' );
        $refusal = $this->revertRefusal();
        $versionNo = (int) $this->version->attribute( 'version' );
        return array(
            'object_id' => (int) $this->object->attribute( 'id' ), 'version' => $versionNo, 'language' => $this->language,
            'name' => (string) $this->object->versionLanguageName( $versionNo, $this->language ),
            'base_version' => $base, 'published_version' => $published,
            'published_newer' => $base > 0 && $published !== $base && (int) $this->object->attribute( 'status' ) === eZContentObject::STATUS_PUBLISHED,
            'can_revert' => $refusal === '', 'refusal' => $refusal, 'fields' => $fields, 'metrics' => $diff->metrics(),
            'urls' => array( 'view' => 'content/versionview/' . (int) $this->object->attribute( 'id' ) . '/' . $versionNo . '/' . $this->language,
                             'edit' => $refusal === '' ? 'content/edit/' . (int) $this->object->attribute( 'id' ) . '/' . $versionNo . '/' . $this->language : '' ),
        );
    }

    /**
     * Reverts parts and whole fields into the same draft.
     * @param string[] $hunkIds the parts to take back
     * @param string[] $fields identifiers of fields to take back whole
     * @param array $seen identifier => hash the editor saw (changes()); a field missing here is not checked
     * @return array status (reverted, conflict, unchanged), written, conflicts (identifier => reason), changes
     * @throws expAiException when the draft may not be reverted or a part cannot be taken back alone
     */
    public function revert( array $hunkIds, array $fields = array(), array $seen = array() )
    {
        $refusal = $this->revertRefusal();
        if ( $refusal !== '' )
            throw new expAiException( $refusal );
        $hunkIds = array_values( array_unique( array_filter( array_map( 'strval', $hunkIds ) ) ) );
        $fields = array_values( array_unique( array_filter( array_map( 'strval', $fields ) ) ) );
        if ( !$hunkIds && !$fields )
            throw new expAiException( 'Choose a part or a field to revert.' );
        $diff = $this->diff();
        $attributes = $diff->attributes();
        // which field each requested part belongs to
        $byField = array();
        $conflicts = array();
        foreach ( $hunkIds as $id )
        {
            $found = null;
            foreach ( $attributes as $identifier => $attribute )
                foreach ( $attribute['hunks'] as $hunk )
                    if ( $hunk['id'] === $id )
                        $found = $identifier;
            if ( $found === null )
                $conflicts['#' . $id] = 'part';
            else
                $byField[$found][] = $id;
        }
        foreach ( $fields as $identifier )
            if ( !isset( $attributes[$identifier] ) || !$attributes[$identifier]['hunks'] )
                $conflicts[$identifier] = 'field';
        $touched = array_values( array_unique( array_merge( array_keys( $byField ), array_intersect( $fields, array_keys( $attributes ) ) ) ) );
        foreach ( $touched as $identifier )
            if ( isset( $seen[$identifier] ) && (string) $seen[$identifier] !== self::hash( $attributes[$identifier]['prop_value'] ) )
                $conflicts[$identifier] = 'changed';
        if ( $conflicts )
            return array( 'status' => 'conflict', 'written' => array(), 'conflicts' => $conflicts, 'changes' => $this->changes( $diff ) );

        $values = array();
        $types = array();
        $reverted = 0;
        foreach ( $touched as $identifier )
        {
            $attribute = $attributes[$identifier];
            $types[$identifier] = $attribute['datatype'];
            $all = array_column( $attribute['hunks'], 'id' );
            $back = in_array( $identifier, $fields, true ) ? $all : array_intersect( $all, $byField[$identifier] );
            $reverted += count( $back );
            $keep = array_values( array_diff( $all, $back ) );
            if ( !$keep )
            {
                $values[$identifier] = self::emptyValue( $attribute['datatype'], $attribute['base_value'] );
                continue;
            }
            try
            {
                // the base with every part the editor keeps: the draft without the parts taken back
                $merged = expAiDiff::apply( $diff, $keep );
            }
            catch ( expAiException $e )
            {
                throw new expAiException( 'This part cannot be taken back alone (a table cell). Revert the whole field instead.', $e->getMessage() );
            }
            $values[$identifier] = isset( $merged['values'][$identifier] ) ? $merged['values'][$identifier] : self::emptyValue( $attribute['datatype'], $attribute['base_value'] );
        }
        foreach ( $values as $identifier => $value )
            if ( (string) $value === (string) $attributes[$identifier]['prop_value'] )
                unset( $values[$identifier] );
        if ( !$values )
            return array( 'status' => 'unchanged', 'written' => array(), 'conflicts' => array(), 'changes' => $this->changes( $diff ) );

        $versionNo = (int) $this->version->attribute( 'version' );
        $names = array();
        foreach ( array_keys( $values ) as $identifier )
            $names[] = $attributes[$identifier]['name'];
        $result = expAiDiff::writeDraft( $this->object, $this->language, $values, $types, array(
            'reuse_version' => $versionNo, 'source' => self::SOURCE, 'thread_id' => (int) $this->log->attribute( 'thread_id' ),
            'model' => (string) $this->log->attribute( 'model' ), 'provider' => (string) $this->log->attribute( 'provider' ),
            'prompt_summary' => 'Reverted ' . $reverted . ' part(s) of ' . implode( ', ', $names ) ) );
        if ( (int) $result['version'] !== $versionNo )
            throw new expAiException( 'The draft could not be written.', 'revert wrote version ' . (int) $result['version'] . ' instead of ' . $versionNo );
        // the version object of this instance is stale after the write
        $this->version = $this->object->version( $versionNo );
        return array( 'status' => 'reverted', 'written' => $result['written'], 'errors' => $result['errors'], 'skipped' => $result['skipped'],
                      'reverted' => $reverted, 'conflicts' => array(), 'changes' => $this->changes() );
    }

    /** @return string the base value, or an empty value of the datatype when the base had none (a new object or translation) */
    protected static function emptyValue( $datatype, $baseValue )
    {
        if ( (string) $baseValue !== '' || $datatype !== 'ezxmltext' )
            return (string) $baseValue;
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
             . '<section xmlns:image="http://ez.no/namespaces/ezpublish3/image/" xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/"/>' . "\n";
    }
}
