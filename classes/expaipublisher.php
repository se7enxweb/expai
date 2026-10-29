<?php
/**
 * File containing the expAiPublisher class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * What the editor does with the drafts the assistant wrote: keep them ("Save as drafts"), send them ("Send for
 * approval": the regular content/publish operation as the editor, so a pre-publish approval workflow holds them
 * as pending, else they are published), or discard them. Only the editor's own drafts that are in the log.
 */
class expAiPublisher
{
    /**
     * The log entry, its object and version, when the current user may act on them.
     * @return array( expAiDraftLog, eZContentObject, eZContentObjectVersion )
     * @throws expAiException
     */
    public static function load( $objectID, $versionNumber )
    {
        $user = eZUser::currentUser();
        $log = expAiDraftLog::fetchByVersion( (int) $objectID, (int) $versionNumber );
        $object = expAiObjects::fetch( (int) $objectID );
        $version = $object ? $object->version( (int) $versionNumber ) : null;
        if ( !$log || !$object || !$version || (int) $log->attribute( 'user_id' ) !== (int) $user->attribute( 'contentobject_id' ) )
            throw new expAiException( 'This draft was not written for you by Exponential Intelligence.' );
        if ( (int) $version->attribute( 'creator_id' ) !== (int) $user->attribute( 'contentobject_id' ) )
            throw new expAiException( 'This draft belongs to another user.' );
        return array( $log, $object, $version );
    }

    /**
     * #389 after "Send for approval", "Save as drafts" or Discard: a thread that says "To review" because of the drafts
     * it wrote goes back to its resting state once none of its drafts waits for a decision any more (each one was
     * published, sent for approval, kept or removed). A thread with staged Vector changes or a proposed plan stays
     * in review (expAiThread::restingState()).
     * @param array $pairs list of array( object id, version ) that were decided
     * @return int threads changed
     */
    public static function settleThreads( array $pairs )
    {
        if ( !class_exists( 'expAiThread' ) || !expAiThread::available() )
            return 0;
        $threadIDs = array();
        foreach ( $pairs as $pair )
        {
            $log = expAiDraftLog::fetchByVersion( (int) $pair[0], (int) $pair[1] );
            if ( $log && (int) $log->attribute( 'thread_id' ) > 0 )
                $threadIDs[(int) $log->attribute( 'thread_id' )] = true;
        }
        $changed = 0;
        foreach ( array_keys( $threadIDs ) as $threadID )
        {
            $thread = expAiThread::fetchByID( $threadID );
            if ( !$thread || $thread->attribute( 'state' ) !== 'review' )
                continue;
            $waiting = false;
            foreach ( (array) eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null,
                          array( 'thread_id' => $threadID, 'status' => expAiDraftLog::STATUS_DRAFT ) ) as $open )
            {
                $object = expAiObjects::fetch( (int) $open->attribute( 'contentobject_id' ) );
                $version = $object ? $object->version( (int) $open->attribute( 'version' ) ) : null;
                if ( $version && (int) $version->attribute( 'status' ) === eZContentObjectVersion::STATUS_DRAFT )
                {
                    $waiting = true;
                    break;
                }
            }
            if ( $waiting )
                continue;
            if ( $thread->setState( $thread->restingState() ) )
            {
                $thread->touch();
                $thread->store();
                $changed++;
            }
        }
        return $changed;
    }

    /** @return bool the current user may edit (and so publish or discard) this version */
    public static function canAct( eZContentObject $object, eZContentObjectVersion $version )
    {
        return (bool) $object->editAccess( $version ) || ( (int) $object->attribute( 'status' ) === eZContentObject::STATUS_DRAFT && $object->draftCreateAccess() );
    }

    /** @return array result for the panel */
    public static function keep( $objectID, $versionNumber )
    {
        list( $log, $object, $version ) = self::load( $objectID, $versionNumber );
        if ( (int) $version->attribute( 'status' ) === eZContentObjectVersion::STATUS_DRAFT && $log->attribute( 'status' ) === expAiDraftLog::STATUS_DRAFT )
            $log->setStatus( expAiDraftLog::STATUS_KEPT );
        return array( 'status' => $log->attribute( 'status' ) );
    }

    /**
     * "Send for approval": the publish operation as the current user.
     * @return array status (published, pending, draft), approval (what the check expected), url
     * @throws expAiException
     */
    public static function send( $objectID, $versionNumber )
    {
        list( $log, $object, $version ) = self::load( $objectID, $versionNumber );
        if ( (int) $version->attribute( 'status' ) !== eZContentObjectVersion::STATUS_DRAFT )
            throw new expAiException( 'This version is no longer a draft.' );
        if ( !self::canAct( $object, $version ) )
            throw new expAiException( 'You may not publish this draft.' );
        $writer = new expAiContentWriter();
        $missing = $writer->missingRequired( $object, $version );
        if ( $missing )
            throw new expAiException( 'Required fields are empty: %fields. Open the draft in the edit form to complete it.', '', 0,
                                      array( '%fields' => implode( ', ', $missing ) ) );
        $expected = expAiApprovalCheck::check( $object, $version );

        $behaviour = new ezpContentPublishingBehaviour();
        $behaviour->isTemporary = true;
        $behaviour->disableAsynchronousPublishing = true;
        ezpContentPublishingBehaviour::setBehaviour( $behaviour );
        eZOperationHandler::execute( 'content', 'publish', array( 'object_id' => (int) $objectID, 'version' => (int) $versionNumber ) );

        $fresh = eZContentObjectVersion::fetchVersion( (int) $versionNumber, (int) $objectID );
        $status = $fresh ? (int) $fresh->attribute( 'status' ) : -1;
        $url = '';
        if ( $status === eZContentObjectVersion::STATUS_PUBLISHED )
        {
            $log->setStatus( expAiDraftLog::STATUS_PUBLISHED );
            $object = expAiObjects::fetch( (int) $objectID );
            $main = $object ? $object->mainNode() : null;
            $url = $main ? 'content/view/full/' . (int) $main->attribute( 'node_id' ) : '';
            $result = 'published';
        }
        elseif ( $status === eZContentObjectVersion::STATUS_PENDING )
        {
            $log->setStatus( expAiDraftLog::STATUS_PENDING );
            $result = 'pending';
        }
        else
            $result = 'draft';
        return array( 'status' => $result, 'approval' => $expected, 'url' => $url );
    }

    /**
     * Removes the draft (as the edit form's Discard does: the version, and a never published object with it).
     * @return array
     */
    public static function discard( $objectID, $versionNumber )
    {
        list( $log, $object, $version ) = self::load( $objectID, $versionNumber );
        if ( !in_array( (int) $version->attribute( 'status' ), array( eZContentObjectVersion::STATUS_DRAFT, eZContentObjectVersion::STATUS_INTERNAL_DRAFT ), true ) )
            throw new expAiException( 'This version is no longer a draft.' );
        if ( !self::canAct( $object, $version ) )
            throw new expAiException( 'You may not remove this draft.' );
        $db = eZDB::instance();
        $db->begin();
        try
        {
            $auditVersion = array( 'version' => (int) $version->attribute( 'version' ), 'status' => (int) $version->attribute( 'status' ),
                                   'language' => (string) $version->initialLanguageCode() );
            $name = (string) $object->attribute( 'name' );
            if ( class_exists( 'expAuditHook' ) )
                expAuditHook::muted( 'content.object.purge', function () use ( $version ) { $version->removeThis(); } );
            else
                $version->removeThis();
            if ( class_exists( 'expAuditHook' ) )
                expAuditHook::emit( 'content.version.remove', array(
                    'object' => array( 'type' => 'version', 'id' => (string) $auditVersion['version'], 'object_id' => (int) $objectID,
                                       'name' => $name !== '' ? $name : null ),
                    'before' => array( 'versions' => array( $auditVersion ), 'discard' => true ) ) );
            $db->commit();
        }
        catch ( Throwable $e )
        {
            if ( $db->transactionCounter() > 0 )
                $db->rollback();
            throw $e;
        }
        $log->setStatus( expAiDraftLog::STATUS_DISCARDED );
        return array( 'status' => expAiDraftLog::STATUS_DISCARDED );
    }
}
