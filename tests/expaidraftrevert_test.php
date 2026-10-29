<?php
/**
 * Tests of "What changed" and revert for drafts written directly (doc/review.md, "Direct mode: what changed, and
 * revert"; expAiDraftRevert) on the database of the installation, without any model call: the diff against the base
 * version, revert of one part (the other parts stay), revert of a whole field, the conflict when the draft changed
 * since, the same draft reused (no new version, the published version unchanged), the log entry (source revert),
 * who may see and revert (the editor, a thread's owner only) and a version that is no longer a draft.
 *
 * It creates a folder with an article under the node EXPAI_TEST_PARENT (default 43, Media), as the user
 * EXPAI_TEST_LOGIN (default admin), and removes that folder at the end, with the drafts, log rows and thread it made.
 *
 *   php bin/php/ezexec.php extension/expai/tests/expaidraftrevert_test.php -s <admin siteaccess>
 *
 * Prints one line per check and ends with PASS or FAIL.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$failures = 0;
$check = function ( $ok, $label ) use ( &$failures )
{
    if ( !$ok )
        ++$failures;
    echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $label . "\n";
};
$expect = function ( $fn, $needle, $label ) use ( $check )
{
    try
    {
        $fn();
        $check( false, $label . ' (no error)' );
    }
    catch ( expAiException $e )
    {
        $check( stripos( strtr( $e->getMessage(), $e->arguments() ), $needle ) !== false, $label . ': ' . strtr( $e->getMessage(), $e->arguments() ) );
    }
};
$login = getenv( 'EXPAI_TEST_LOGIN' ) ?: 'admin';
$parentID = (int) ( getenv( 'EXPAI_TEST_PARENT' ) ?: 43 );
$user = eZUser::fetchByName( $login );
if ( !$user )
{
    echo "FAIL the user $login is missing\n";
    eZScript::instance()->shutdown( 1 );
}
eZUser::setCurrentlyLoggedInUser( $user, (int) $user->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
$userID = (int) $user->attribute( 'contentobject_id' );
$xml = function ( array $paragraphs )
{
    $out = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<section xmlns:image="http://ez.no/namespaces/ezpublish3/image/" xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/">';
    foreach ( $paragraphs as $p )
        $out .= '<paragraph>' . htmlspecialchars( $p, ENT_XML1 ) . '</paragraph>';
    return $out . "</section>\n";
};
$text = function ( $value ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( str_replace( '</paragraph>', "</paragraph>\n", (string) $value ) ) ) ); };
$field = function ( eZContentObject $object, $version, $identifier, $language )
{
    $map = $object->fetchDataMap( (int) $version, $language );
    $a = $map[$identifier];
    return $a->attribute( 'data_type_string' ) === 'ezxmltext' ? (string) $a->attribute( 'data_text' ) : (string) $a->toString();
};
$logIDs = array();
$threadIDs = array();

$folder = eZContentFunctions::createAndPublishObject( array( 'class_identifier' => 'folder', 'parent_node_id' => $parentID, 'creator_id' => $userID,
                                                            'attributes' => array( 'name' => 'T382 What changed test ' . date( 'Y-m-d H:i:s' ) ) ) );
$check( $folder instanceof eZContentObject, 'test folder created' );
$folderNode = $folder->mainNode();
$p1 = 'Our office is open for you.';
$p2 = 'We are glad to see you.';
$p3 = 'Opening hours are Monday to Friday, 9 to 5.';
$article = eZContentFunctions::createAndPublishObject( array( 'class_identifier' => 'article', 'parent_node_id' => (int) $folderNode->attribute( 'node_id' ), 'creator_id' => $userID,
    'attributes' => array( 'title' => 'Opening hours Main Street', 'intro' => $xml( array( $p1, $p2, $p3 ) ) ) ) );
$check( $article instanceof eZContentObject, 'test article published' );

try
{
    $objectID = (int) $article->attribute( 'id' );
    $published = (int) $article->attribute( 'current_version' );
    $lang = (string) $article->attribute( 'initial_language_code' );
    $publishedIntro = $field( $article, $published, 'intro', $lang );

    // ------------------------------------------------------------------ a direct write (as the panel does it)
    $q1 = 'Our office on Main Street is open for you every weekday.';
    $q3 = 'Opening hours are Monday to Saturday, 8 to 6.';
    $write = expAiDiff::writeDraft( $article, $lang, array( 'title' => 'Opening hours at Main Street', 'intro' => $xml( array( $q1, $p2, $q3 ) ) ),
                                    array( 'title' => 'ezstring', 'intro' => 'ezxmltext' ), array( 'source' => 'panel', 'model' => 'test-model' ) );
    $draftNo = (int) $write['version'];
    $check( $draftNo > $published && $write['created'], 'a direct write made draft ' . $draftNo );
    $log = expAiDraftRevert::writtenBy( $objectID, $draftNo );
    $logIDs[] = (int) $log->attribute( 'id' );
    $check( $log && $log->attribute( 'source' ) === 'panel', 'the draft log names the panel' );

    // ------------------------------------------------------------------ what changed
    $revert = expAiDraftRevert::forDraft( $objectID, $draftNo );
    $c = $revert->changes();
    $byId = array();
    foreach ( $c['fields'] as $f )
        $byId[$f['identifier']] = $f;
    $check( $c['base_version'] === $published, 'the base is the published version ' . $published . ' (got ' . $c['base_version'] . ')' );
    $check( isset( $byId['title'], $byId['intro'] ) && count( $c['fields'] ) === 2, 'two fields changed: ' . implode( ', ', array_keys( $byId ) ) );
    $check( isset( $byId['intro'] ) && count( $byId['intro']['hunks'] ) === 2, 'the intro has two parts (first and last paragraph): ' . ( isset( $byId['intro'] ) ? count( $byId['intro']['hunks'] ) : 0 ) );
    $check( $c['can_revert'] && $c['refusal'] === '', 'the editor may revert' );
    $introHunks = $byId['intro']['hunks'];
    $first = null;
    foreach ( $introHunks as $h )
        if ( strpos( $h['base_text'], 'Our office' ) !== false )
            $first = $h;
    $check( $first && strpos( $first['prop_text'], 'every weekday' ) !== false && strpos( $first['base_text'], '<' ) === false, 'the part is readable text, not markup' );

    // ------------------------------------------------------------------ revert one part
    $seen = array();
    foreach ( $c['fields'] as $f )
        $seen[$f['identifier']] = $f['hash'];
    $r = $revert->revert( array( $first['id'] ), array(), $seen );
    $draftIntro = $field( eZContentObject::fetch( $objectID ), $draftNo, 'intro', $lang );
    $check( $r['status'] === 'reverted' && $r['written'] === array( 'intro' ), 'revert of one part wrote the intro: ' . $r['status'] );
    $check( strpos( $text( $draftIntro ), $p1 ) !== false && strpos( $text( $draftIntro ), $q3 ) !== false && strpos( $text( $draftIntro ), $q1 ) === false,
            'the draft has the first paragraph back and keeps the new last one: ' . $text( $draftIntro ) );
    $fresh = eZContentObject::fetch( $objectID );
    $check( (int) $fresh->attribute( 'current_version' ) === $published && $field( $fresh, $published, 'intro', $lang ) === $publishedIntro, 'the published version is unchanged' );
    $versions = eZPersistentObject::count( eZContentObjectVersion::definition(), array( 'contentobject_id' => $objectID ) );
    $check( $versions === 2, 'no new version: the same draft was written (' . $versions . ' versions)' );
    $check( (int) $fresh->version( $draftNo )->attribute( 'status' ) === eZContentObjectVersion::STATUS_DRAFT, 'the draft is still a draft' );
    $revertLogs = eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null, array( 'contentobject_id' => $objectID, 'version' => $draftNo, 'source' => 'revert' ) );
    foreach ( $revertLogs as $l )
        $logIDs[] = (int) $l->attribute( 'id' );
    $check( count( $revertLogs ) === 1 && strpos( $revertLogs[0]->attribute( 'prompt_summary' ), 'Reverted 1 part' ) !== false, 'the revert is logged with source revert' );
    $after = array();
    foreach ( $r['changes']['fields'] as $f )
        $after[$f['identifier']] = $f;
    $check( isset( $after['intro'] ) && count( $after['intro']['hunks'] ) === 1, 'what changed now shows one part of the intro' );
    $check( expAiDraftRevert::writtenBy( $objectID, $draftNo )->attribute( 'source' ) === 'panel', 'the entry that wrote the draft is still found' );

    // ------------------------------------------------------------------ conflict: the draft changed since
    $stale = array();
    foreach ( $r['changes']['fields'] as $f )
        $stale[$f['identifier']] = $f['hash'];
    $staleHunk = $after['intro']['hunks'][0]['id'];
    $writer = new expAiContentWriter();
    $edited = $xml( array( $p1, $p2, 'Opening hours are Monday to Sunday, around the clock.' ) );
    $writer->writeFields( eZContentObject::fetch( $objectID ), eZContentObject::fetch( $objectID )->version( $draftNo ), $lang, array( 'intro' => $edited ) );
    $before = $field( eZContentObject::fetch( $objectID ), $draftNo, 'intro', $lang );
    $r2 = expAiDraftRevert::forDraft( $objectID, $draftNo )->revert( array( $staleHunk ), array(), $stale );
    $check( $r2['status'] === 'conflict' && isset( $r2['conflicts']['#' . $staleHunk] ), 'a part that is gone after an edit is a conflict' );
    $check( $field( eZContentObject::fetch( $objectID ), $draftNo, 'intro', $lang ) === $before, 'nothing is written on a conflict' );
    $r3 = expAiDraftRevert::forDraft( $objectID, $draftNo )->revert( array(), array( 'intro' ), $stale );
    $check( $r3['status'] === 'conflict' && isset( $r3['conflicts']['intro'] ) && $r3['conflicts']['intro'] === 'changed', 'a field whose value changed since is a conflict' );
    $check( count( $r3['changes']['fields'] ) >= 1, 'the conflict answer carries the diff as it is now' );

    // ------------------------------------------------------------------ revert whole fields
    $now = array();
    foreach ( $r3['changes']['fields'] as $f )
        $now[$f['identifier']] = $f['hash'];
    $r4 = expAiDraftRevert::forDraft( $objectID, $draftNo )->revert( array(), array( 'title', 'intro' ), $now );
    $obj = eZContentObject::fetch( $objectID );
    $check( $r4['status'] === 'reverted' && $field( $obj, $draftNo, 'title', $lang ) === 'Opening hours Main Street', 'revert field: the title is the published one again' );
    $check( $text( $field( $obj, $draftNo, 'intro', $lang ) ) === $text( $publishedIntro ), 'revert field: the intro is the published one again' );
    $check( !$r4['changes']['fields'], 'what changed is empty after both fields were reverted' );
    $r5 = expAiDraftRevert::forDraft( $objectID, $draftNo );
    $expect( function () use ( $r5 ) { $r5->revert( array(), array() ); }, 'Choose a part', 'a revert of nothing is refused' );
    foreach ( eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null, array( 'contentobject_id' => $objectID, 'source' => 'revert' ) ) as $l )
        $logIDs[] = (int) $l->attribute( 'id' );

    // ------------------------------------------------------------------ who may see and revert
    $anonymous = eZUser::fetch( (int) eZINI::instance()->variable( 'UserSettings', 'AnonymousUserID' ) );
    $expect( function () use ( $objectID, $draftNo, $anonymous ) { expAiDraftRevert::forDraft( $objectID, $draftNo, $anonymous ); }, '', 'another user is refused' );
    $expect( function () use ( $folder ) { expAiDraftRevert::forDraft( (int) $folder->attribute( 'id' ), 1 ); }, 'not written by Exponential Intelligence', 'a version the assistant did not write is refused' );
    if ( expAiThread::available() )
    {
        $other = expAiThread::create( array( 'user_id' => (int) $anonymous->attribute( 'contentobject_id' ), 'title' => 'T382 someone else', 'scope' => array(),
                                             'run_mode' => 'write', 'review_policy' => 'direct', 'model_alias' => expAiConfig::instance()->effectiveModel() ) );
        $threadIDs[] = (int) $other->attribute( 'id' );
        $own = expAiThread::create( array( 'user_id' => $userID, 'title' => 'T382 own', 'scope' => array(), 'run_mode' => 'write', 'review_policy' => 'direct',
                                           'model_alias' => expAiConfig::instance()->effectiveModel() ) );
        $threadIDs[] = (int) $own->attribute( 'id' );
        $log->setAttribute( 'thread_id', (int) $other->attribute( 'id' ) );
        $log->store();
        $expect( function () use ( $objectID, $draftNo ) { expAiDraftRevert::forDraft( $objectID, $draftNo ); }, 'owner of the thread', 'a thread\'s draft: only the thread\'s owner' );
        $log->setAttribute( 'thread_id', (int) $own->attribute( 'id' ) );
        $log->store();
        $check( expAiDraftRevert::forDraft( $objectID, $draftNo ) instanceof expAiDraftRevert, 'the owner of the thread may' );
    }

    // ------------------------------------------------------------------ a version that is no longer a draft
    $write2 = expAiDiff::writeDraft( eZContentObject::fetch( $objectID ), $lang, array( 'title' => 'Opening hours, Main Street' ), array( 'title' => 'ezstring' ),
                                     array( 'reuse_version' => $draftNo, 'source' => 'panel' ) );
    foreach ( eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null, array( 'contentobject_id' => $objectID ) ) as $l )
        $logIDs[] = (int) $l->attribute( 'id' );
    $op = eZOperationHandler::execute( 'content', 'publish', array( 'object_id' => $objectID, 'version' => $draftNo ) );
    $pub = expAiDraftRevert::forDraft( $objectID, $draftNo );
    $pc = $pub->changes();
    $check( (int) eZContentObject::fetch( $objectID )->attribute( 'current_version' ) === $draftNo && !$pc['can_revert'] && $pc['refusal'] !== '',
            'a published version shows what changed but cannot be reverted: ' . $pc['refusal'] );
    $expect( function () use ( $pub, $pc ) { $pub->revert( array(), array( 'title' ) ); }, 'no longer a draft', 'revert of a published version is refused' );
}
catch ( Throwable $e )
{
    $check( false, 'unexpected ' . get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
}

// ------------------------------------------------------------------ clean up
foreach ( array_unique( $logIDs ) as $id )
{
    $l = eZPersistentObject::fetchObject( expAiDraftLog::definition(), null, array( 'id' => (int) $id ) );
    if ( $l )
        $l->remove();
}
if ( isset( $objectID ) )
    foreach ( (array) eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null, array( 'contentobject_id' => (int) $objectID ) ) as $l )
        $l->remove();
foreach ( $threadIDs as $id )
{
    $t = expAiThread::fetchByID( $id );
    if ( $t )
        $t->removeAll( true );
}
if ( isset( $folderNode ) && $folderNode )
    eZContentObjectTreeNode::removeSubtrees( array( (int) $folderNode->attribute( 'node_id' ) ), true );
echo ( $failures ? 'FAIL' : 'PASS' ) . " expai what changed and revert: $failures failure(s)\n";
eZScript::instance()->shutdown( $failures ? 1 : 0 );
