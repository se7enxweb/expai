<?php
/**
 * Tests of Vector's checkpoints and restore (doc/checkpoints.md) on the database of the installation, without a model
 * call: checkpoints of the conversation, the drafts and the staged changes; the preview; a restore that sets a draft
 * back, one that discards a draft written later, sets later changes aside, cuts the conversation and drops follow-ups;
 * undoing a restore through its "Before restore" checkpoint; owner only, nothing running; the size cap and the limit
 * per thread.
 *
 * It creates a folder with one article under the node EXPAI_TEST_PARENT (default 43, Media), as the user
 * EXPAI_TEST_LOGIN (default admin), and moves that folder to the trash at the end, with the threads and drafts it made.
 *
 *   php bin/php/ezexec.php extension/expai/tests/expaivectorcheckpoint_test.php -s <admin siteaccess>
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
if ( !$user || !expAiThread::available() )
{
    echo "FAIL the user $login or the thread tables are missing\n";
    eZScript::instance()->shutdown( 1 );
}
eZUser::setCurrentlyLoggedInUser( $user, (int) $user->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
$userID = (int) $user->attribute( 'contentobject_id' );
$ini = eZINI::instance( 'expai.ini' );
$ini->setVariable( 'VectorSettings', 'KickWorker', 'disabled' );
$ini->setVariable( 'VectorSettings', 'Checkpoints', 'enabled' );
$config = expAiConfig::instance();
$xml = function ( array $paragraphs )
{
    $out = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<section xmlns:image="http://ez.no/namespaces/ezpublish3/image/" xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/">';
    foreach ( $paragraphs as $p )
        $out .= '<paragraph>' . htmlspecialchars( $p, ENT_XML1 ) . '</paragraph>';
    return $out . "</section>\n";
};
$folder = eZContentFunctions::createAndPublishObject( array( 'class_identifier' => 'folder', 'parent_node_id' => $parentID, 'creator_id' => $userID,
                                                            'attributes' => array( 'name' => 'T360V5 checkpoint test ' . date( 'Y-m-d H:i:s' ) ) ) );
$folderNode = $folder->mainNode();
$article = eZContentFunctions::createAndPublishObject( array( 'class_identifier' => 'article', 'parent_node_id' => (int) $folderNode->attribute( 'node_id' ), 'creator_id' => $userID,
    'attributes' => array( 'title' => 'Opening hours', 'intro' => $xml( array( 'Our office is open for you.' ) ) ) ) );
$check( $article instanceof eZContentObject, 'test article published' );
$objectID = (int) $article->attribute( 'id' );
$language = (string) $article->attribute( 'initial_language_code' );
$threads = array();

// an exchange in the lead conversation, as a turn would leave it
$exchange = function ( expAiThread $t, $user, $reply ) use ( $config )
{
    $c = $t->conversation();
    $history = $c->history();
    $history[] = array( 'id' => count( $history ) + 1, 'time' => time(), 'user' => $user, 'steps' => array(), 'reply' => $reply, 'drafts' => array() );
    $messages = $c->messages();
    $messages[] = array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => $user ) ) );
    $messages[] = array( 'role' => 'assistant', 'content_json' => json_encode( array( array( 'type' => 'text', 'text' => $reply ) ) ) );
    $c->setHistory( $history );
    $c->setMessages( $messages );
    $c->setWindowTurns( $c->windowTurns() + 1 );
    $c->store();
};
$titleOfDraft = function ( $version ) use ( $objectID, $language )
{
    $object = eZContentObject::fetch( $objectID );
    $values = expAiDiff::values( $object, (int) $version, $language );
    return isset( $values['title'] ) ? $values['title']['value'] : null;
};
$stageAndApply = function ( expAiThread $t, $title ) use ( $config, $folderNode, $objectID, $language )
{
    $ctx = new expAiToolContext( $config, (int) $folderNode->attribute( 'node_id' ), $t->conversation() );
    $ctx->staged = true;
    $r = ( new expAiToolUpdateDraft( 'update_draft' ) )->execute( array( 'object_id' => $objectID, 'language' => $language, 'fields' => array( 'title' => $title ) ), $ctx );
    foreach ( $r['change_ids'] as $id )
    {
        $change = expAiChange::fetchByID( $id );
        $change->decide( '', 'accepted', (int) eZUser::currentUserID() );
        $change->store();
    }
    $out = ( new expAiChangeApplier( expAiThread::fetchByID( $t->attribute( 'id' ) ) ) )->apply( $r['change_ids'] );
    return $out['drafts'] ? (int) $out['drafts'][0]['version'] : 0;
};

try
{
    $thread = expAiThread::create( array( 'user_id' => $userID, 'title' => 'T360V5 test checkpoints', 'scope' => array( 'nodes' => array( (int) $article->mainNodeID() ) ),
                                          'run_mode' => 'write', 'review_policy' => 'staged', 'model_alias' => $config->effectiveModel() ) );
    $threads[] = $thread;
    $exchange( $thread, 'Read the article.', 'It is about opening hours.' );

    // ------------------------------------------------------------------ C1: before anything was staged
    $c1 = expAiCheckpoint::write( $thread, expAiCheckpoint::labelFor( 'Rename the article' ) );
    $check( $c1 && (int) $c1->attribute( 'conversation_len' ) === 2 && $c1->attribute( 'turn' ) === 2 && strpos( $c1->attribute( 'label' ), 'Rename the article' ) !== false,
            'checkpoint 1: 2 messages, turn 2, labelled by the message' );
    $check( $c1->drafts() === array() && $c1->data()['meta']['history_len'] === 1, 'no drafts yet, one exchange' );
    $events = array_filter( expAiThreadEvent::fetchAfter( (int) $thread->attribute( 'id' ), 0, 200 ), function ( $e ) { return $e->attribute( 'kind' ) === 'checkpoint'; } );
    $check( count( $events ) === 1, 'a checkpoint event' );

    // the turn: a title staged and written into a draft, one more exchange, a follow-up queued
    $exchange( $thread, 'Rename the article', 'Staged and written the new title.' );
    $v = $stageAndApply( expAiThread::fetchByID( $thread->attribute( 'id' ) ), 'Opening hours (new)' );
    $check( $v > 0 && $titleOfDraft( $v ) === 'Opening hours (new)', 'a draft with the new title: version ' . $v );
    $c2 = expAiCheckpoint::write( expAiThread::fetchByID( $thread->attribute( 'id' ) ), expAiCheckpoint::labelFor( 'Make it shorter' ) );
    $d2 = $c2->drafts();
    $check( count( $d2 ) === 1 && (int) $d2[0]['version'] === $v && $d2[0]['values']['title']['value'] === 'Opening hours (new)', 'checkpoint 2 keeps the draft with its values' );

    // the next turn: the same draft rewritten, a follow-up waits
    $exchange( $thread, 'Make it shorter', 'Done.' );
    $v2 = $stageAndApply( expAiThread::fetchByID( $thread->attribute( 'id' ) ), 'Hours' );
    $check( $v2 === $v && $titleOfDraft( $v ) === 'Hours', 'the same draft rewritten' );
    $f = new expAiFollowup( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'body' => 'And the intro too.', 'position' => 1, 'delivery' => 'queue', 'state' => 'waiting',
                                   'created' => time(), 'modified' => time() ) );
    $f->store();

    // ------------------------------------------------------------------ restore to C2: the draft's title back
    $t = expAiThread::fetchByID( $thread->attribute( 'id' ) );
    $p = $c2->preview( $t, $config );
    $check( $p['blocked'] === '' && count( $p['revert'] ) === 1 && $p['revert'][0]['fields'] === array( 'title' ) && !$p['discard'], 'preview of 2: the draft\'s title goes back' );
    $check( $p['exchanges'] === 1 && count( $p['followups'] ) === 1 && count( $p['stale'] ) === 1, 'preview: one exchange removed, one follow-up dropped, the change staged after it set aside' );
    $t->setState( 'running' );
    $t->store();
    $expect( function () use ( $c2, $t, $config ) { $c2->restore( expAiThread::fetchByID( $t->attribute( 'id' ) ), $config ); }, 'Stop the thread', 'no restore while the thread runs' );
    $t = expAiThread::fetchByID( $thread->attribute( 'id' ) );
    $t->setState( 'idle' );
    $t->store();
    $anon = eZUser::fetch( eZUser::anonymousId() );
    eZUser::setCurrentlyLoggedInUser( $anon, (int) $anon->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
    $expect( function () use ( $c2, $t, $config ) { $c2->restore( $t, $config ); }, 'owner', 'only the owner restores' );
    eZUser::setCurrentlyLoggedInUser( $user, $userID, eZUser::NO_SESSION_REGENERATE );
    $r = $c2->restore( expAiThread::fetchByID( $thread->attribute( 'id' ) ), $config );
    $t = expAiThread::fetchByID( $thread->attribute( 'id' ) );
    $conv = $t->conversation();
    $check( !$r['errors'] && $titleOfDraft( $v ) === 'Opening hours (new)', 'restored to 2: the draft has its title of then' );
    $check( count( $conv->history() ) === 2 && count( $conv->messages() ) === 4, 'the conversation is cut back to checkpoint 2 (a prefix)' );
    $check( expAiFollowup::fetchByID( $f->attribute( 'id' ) )->attribute( 'state' ) === 'dropped', 'the follow-up queued after it is dropped' );
    $check( $r['before'] && strpos( $r['before']['label'], 'Before restore' ) !== false, 'a checkpoint "Before restore" was written first' );

    // ------------------------------------------------------------------ restore to C1: the draft discarded
    $p1 = $c1->preview( $t, $config );
    $check( count( $p1['discard'] ) === 1 && (int) $p1['discard'][0]['version'] === $v && count( $p1['stale'] ) >= 1 && $p1['exchanges'] === 1,
            'preview of 1: the draft written later is discarded, its changes set aside, one exchange removed' );
    $r1 = $c1->restore( $t, $config );
    $object = eZContentObject::fetch( $objectID );
    $gone = eZContentObjectVersion::fetchVersion( $v, $objectID );
    $check( !$gone || (int) $gone->attribute( 'status' ) !== eZContentObjectVersion::STATUS_DRAFT, 'the draft is gone' );
    $check( (int) $object->attribute( 'current_version' ) === 1 && $titleOfDraft( 1 ) === 'Opening hours', 'the published version is untouched' );
    $open = expAiChange::fetchForThread( (int) $thread->attribute( 'id' ), array( 'pending', 'accepted', 'partial', 'applied' ) );
    $check( !$open, 'the changes made after it are stale' );
    $conv = expAiThread::fetchByID( $thread->attribute( 'id' ) )->conversation();
    $check( count( $conv->history() ) === 1 && count( $conv->messages() ) === 2, 'the conversation is back to one exchange' );
    $logs = eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null, array( 'thread_id' => (int) $thread->attribute( 'id' ), 'version' => $v ) );
    $check( $logs && $logs[0]->attribute( 'status' ) === expAiDraftLog::STATUS_DISCARDED, 'the draft log says discarded' );

    // ------------------------------------------------------------------ undo: restore "Before restore" of the last restore
    $undo = expAiCheckpoint::fetchByID( $r1['before']['id'] );
    $pu = $undo->preview( expAiThread::fetchByID( $thread->attribute( 'id' ) ), $config );
    $check( $pu['restore'] && $pu['exchanges'] === 0, 'undo: the changes get their decisions back' );
    $undo->restore( expAiThread::fetchByID( $thread->attribute( 'id' ) ), $config );
    $conv = expAiThread::fetchByID( $thread->attribute( 'id' ) )->conversation();
    $check( count( $conv->history() ) === 2 && count( $conv->messages() ) === 4 && $pu['exchanges_back'] === 1, 'undo: the conversation has its second exchange again, as it was' );
    $back = expAiChange::fetchForThread( (int) $thread->attribute( 'id' ), array( 'applied' ) );
    $check( count( $back ) >= 1, 'undo: the changes are as before the restore' );
    $check( $c1->preview( expAiThread::fetchByID( $thread->attribute( 'id' ) ), $config )['blocked'] === '', 'checkpoints stay restorable' );

    // ------------------------------------------------------------------ the size cap and the limit per thread
    $ini->setVariable( 'VectorSettings', 'MaxCheckpointBytes', '10000' );
    $big = expAiThread::fetchByID( $thread->attribute( 'id' ) );
    $bc = $big->conversation();
    $bc->setHistory( array_merge( $bc->history(), array( array( 'id' => 9, 'time' => time(), 'user' => str_repeat( 'x', 100 ), 'steps' => array(), 'reply' => '', 'drafts' => array() ) ) ) );
    $bc->store();
    foreach ( expAiChange::fetchForThread( (int) $thread->attribute( 'id' ), null ) as $ch )
    {
        $ch->setAttribute( 'proposed', str_repeat( 'Long text. ', 2000 ) );
        $ch->store();
    }
    $cbig = expAiCheckpoint::write( $big, 'big' );
    $check( $cbig && !empty( $cbig->data()['meta']['conversation_only'] ) && strlen( $cbig->attribute( 'changes_json' ) ) < 10000, 'over MaxCheckpointBytes: conversation only' );
    $ini->setVariable( 'VectorSettings', 'MaxCheckpointBytes', '1048576' );
    $ini->setVariable( 'VectorSettings', 'MaxCheckpointsPerThread', '3' );
    for ( $i = 0; $i < 4; $i++ )
        expAiCheckpoint::write( expAiThread::fetchByID( $thread->attribute( 'id' ) ), 'extra ' . $i );
    $check( count( expAiCheckpoint::fetchForThread( (int) $thread->attribute( 'id' ), 50 ) ) === 3, 'at most MaxCheckpointsPerThread per thread' );
    $list = expAiCheckpoint::listFor( expAiThread::fetchByID( $thread->attribute( 'id' ) ) );
    $check( count( $list ) === 3 && $list[0]['label'] === 'extra 3', 'the strip lists the newest first' );
    $ini->setVariable( 'VectorSettings', 'MaxCheckpointsPerThread', '50' );
    $ini->setVariable( 'VectorSettings', 'Checkpoints', 'disabled' );
    $check( expAiCheckpoint::write( expAiThread::fetchByID( $thread->attribute( 'id' ) ), 'off' ) === null, 'Checkpoints=disabled writes none' );
    $ini->setVariable( 'VectorSettings', 'Checkpoints', 'enabled' );
    $check( isset( expAiVector::detail( expAiThread::fetchByID( $thread->attribute( 'id' ) ), $userID, $config )['checkpoints'] ), 'the thread detail carries the checkpoints' );
}
catch ( Exception $e )
{
    $check( false, 'unexpected ' . get_class( $e ) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() );
}

// ------------------------------------------------------------------ cleanup
foreach ( $threads as $t )
{
    $t = expAiThread::fetchByID( $t->attribute( 'id' ) );
    if ( !$t )
        continue;
    foreach ( (array) eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null, array( 'thread_id' => (int) $t->attribute( 'id' ) ) ) as $log )
    {
        $object = eZContentObject::fetch( (int) $log->attribute( 'contentobject_id' ) );
        if ( $object && ( $version = $object->version( (int) $log->attribute( 'version' ) ) ) && (int) $version->attribute( 'status' ) === eZContentObjectVersion::STATUS_DRAFT )
            $version->removeThis();
        $log->remove();
    }
    $t->removeAll( true );
}
eZContentObjectTreeNode::removeSubtrees( array( (int) $folderNode->attribute( 'node_id' ) ), true );
echo ( $failures ? 'FAIL' : 'PASS' ) . " expai vector checkpoints: $failures failure(s)\n";
eZScript::instance()->shutdown( $failures ? 1 : 0 );
