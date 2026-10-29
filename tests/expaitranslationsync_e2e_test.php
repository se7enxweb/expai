<?php
/**
 * End-to-end test of the translation sync with a real model: an article in the source language and one translation,
 * then (1) a small edit of the source, published, the workflow event, the check job, the patch queued at once and run
 * (a translation draft with only the changed block different, markup and links kept, the "Exponential Intelligence"
 * label, nothing published), and (2) a rewrite of the source: a full translation that waits for an editor, approved
 * and run. The jobs are run by this script the way a worker runs them. At the end the article, its drafts, their log
 * rows and the stored decisions are removed (EXPAI_KEEP=1 keeps them).
 *
 *   EXPAI_TEST_LOGIN=<login>        the user (expai/write, content edit in both languages)
 *   EXPAI_TEST_PARENT=<node id>     the (hidden) folder to create the article in
 *   EXPAI_TEST_MODEL=<alias>        the model (default anthropic.claude-haiku-5-5; costs a few cents)
 *   EXPAI_TEST_TARGET=<language>    the translation (default ger-DE); the source is the user's eng-* language of the site
 *   php bin/php/ezexec.php extension/expai/tests/expaitranslationsync_e2e_test.php -s <admin siteaccess>
 *
 * Prints one line per check and ends with PASS or FAIL.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$login = (string) getenv( 'EXPAI_TEST_LOGIN' );
$parentID = (int) getenv( 'EXPAI_TEST_PARENT' );
$alias = getenv( 'EXPAI_TEST_MODEL' ) ? (string) getenv( 'EXPAI_TEST_MODEL' ) : 'anthropic.claude-haiku-5-5';
$target = getenv( 'EXPAI_TEST_TARGET' ) ? (string) getenv( 'EXPAI_TEST_TARGET' ) : 'ger-DE';
$keep = (bool) getenv( 'EXPAI_KEEP' );
$failures = 0;
$checks = 0;
$check = function ( $label, $actual, $expected ) use ( &$failures, &$checks )
{
    ++$checks;
    $ok = $actual === $expected;
    if ( !$ok )
        ++$failures;
    echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $label . ( $ok ? '' : ' => ' . var_export( $actual, true ) . ' (expected ' . var_export( $expected, true ) . ')' ) . "\n";
};
$script = eZScript::instance();
$user = $login !== '' ? eZUser::fetchByName( $login ) : null;
$parent = $parentID ? eZContentObjectTreeNode::fetch( $parentID ) : null;
if ( !$user || !$parent || !expAiQueue::available() )
{
    echo "FAIL set EXPAI_TEST_LOGIN and EXPAI_TEST_PARENT; the queue must be available\n";
    $script->shutdown( 1 );
}
eZUser::setCurrentlyLoggedInUser( $user, (int) $user->attribute( 'contentobject_id' ) );

// the settings of this run: the model, the standard lane (no waiting for a provider batch), the queue for the check
$ini = eZINI::instance( 'expai.ini' );
$ini->setVariable( 'TranslationSync', 'Enabled', 'enabled' );
$ini->setVariable( 'TranslationSync', 'Model', $alias );
$ini->setVariable( 'TranslationSync', 'PatchLane', 'standard' );
$ini->setVariable( 'TranslationSync', 'FullLane', 'standard' );
$ini->setVariable( 'TranslationSync', 'QueueCheck', 'enabled' );
$ini->setVariable( 'TranslationSync', 'AutoQueuePatch', 'enabled' );
$ini->setVariable( 'TranslationSync', 'CostThreshold', '0.02' );
$ini->setVariable( 'TranslationSync', 'SourceLanguages', array() );
$ini->setVariable( 'TranslationSync', 'TargetLanguages', array() );

$source = eZContentLanguage::topPriorityLanguage() ? eZContentLanguage::topPriorityLanguage()->attribute( 'locale' ) : 'eng-GB';
$ns = 'xmlns:image="http://ez.no/namespaces/ezpublish3/image/" xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/"';
$xml = function ( $inner ) use ( $ns ) { return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<section ' . $ns . '>' . $inner . '</section>' . "\n"; };
$url = eZURL::registerURL( 'https://example.org/bakery' );
$bodyEn = $xml( '<paragraph>Our bakery opened in 1998 on Main Street. Every morning we bake bread, rolls and cakes.</paragraph>'
              . '<section><header>Opening hours</header><paragraph>We are open from Monday to Saturday. On Sundays we are closed.</paragraph></section>'
              . '<paragraph><ul><li><paragraph>Fresh bread</paragraph></li><li><paragraph>Cakes made to order</paragraph></li></ul></paragraph>'
              . '<paragraph>Visit <link url_id="' . $url . '">our website</link> for <strong>more news</strong>.</paragraph>' );
$bodyDe = $xml( '<paragraph>Unsere Bäckerei wurde 1998 in der Hauptstraße eröffnet. Jeden Morgen backen wir Brot, Brötchen und Kuchen.</paragraph>'
              . '<section><header>Öffnungszeiten</header><paragraph>Wir haben von Montag bis Samstag geöffnet. Sonntags ist geschlossen.</paragraph></section>'
              . '<paragraph><ul><li><paragraph>Frisches Brot</paragraph></li><li><paragraph>Kuchen auf Bestellung</paragraph></li></ul></paragraph>'
              . '<paragraph>Besuchen Sie <link url_id="' . $url . '">unsere Website</link> für <strong>weitere Neuigkeiten</strong>.</paragraph>' );
$introEn = $xml( '<paragraph>A small bakery with a long tradition.</paragraph>' );
$introDe = $xml( '<paragraph>Eine kleine Bäckerei mit langer Tradition.</paragraph>' );

/** a new version of the object in a language with values, published */
$publish = function ( eZContentObject $object, $language, array $values )
{
    $copyFrom = in_array( $language, array_keys( $object->allLanguages() ), true ) ? false : (string) $object->attribute( 'initial_language_code' );
    $version = $object->createNewVersionIn( $language, $copyFrom, false, true, eZContentObjectVersion::STATUS_DRAFT );
    $version->setAttribute( 'creator_id', eZUser::currentUserID() );
    $version->store();
    foreach ( $object->fetchDataMap( (int) $version->attribute( 'version' ), $language ) as $identifier => $attribute )
        if ( isset( $values[$identifier] ) )
        {
            if ( $attribute->attribute( 'data_type_string' ) === 'ezxmltext' )
                $attribute->setAttribute( 'data_text', $values[$identifier] );
            else
                $attribute->fromString( $values[$identifier] );
            $attribute->store();
        }
    $object->setName( $object->contentClass()->contentObjectName( $object, (int) $version->attribute( 'version' ), $language ), (int) $version->attribute( 'version' ), $language );
    $result = eZOperationHandler::execute( 'content', 'publish', array( 'object_id' => (int) $object->attribute( 'id' ), 'version' => (int) $version->attribute( 'version' ) ) );
    eZContentObject::clearCache( array( (int) $object->attribute( 'id' ) ) );
    return (int) $version->attribute( 'version' );
};

$worker = new expQueueWorker( array( 'origin' => 'cli' ) );
/** runs a job as a worker does (claim, run, finish); a job a worker of the site already ran is fine */
$run = function ( $uuid ) use ( $worker )
{
    $job = expQueue::fetch( $uuid );
    if ( !$job )
        return 'missing';
    $from = $job->attribute( 'state' );
    if ( $from === 'done' )
        return 'done';
    if ( !in_array( $from, array( 'queued', 'ready', 'retry' ), true ) )
        return 'not runnable (' . $from . ')';
    $owner = 'test:' . getmypid() . ':' . bin2hex( random_bytes( 3 ) );
    $now = time();
    if ( !expQueue::casUpdate( (int) $job->attribute( 'id' ), array( 'state' => $from ), array( 'state' => 'running', 'lease_owner' => $owner, 'lease_until' => $now + 600,
                               'attempts' => (int) $job->attribute( 'attempts' ) + 1, 'started' => $now ) ) )
        return 'claim failed';
    ob_start();
    $outcome = $worker->runJob( expQueueJob::fetchByID( (int) $job->attribute( 'id' ) ), $owner );
    ob_end_clean();
    $user = eZUser::fetch( (int) $job->attribute( 'user_id' ) );
    if ( $user )
        eZUser::setCurrentlyLoggedInUser( $user, (int) $user->attribute( 'contentobject_id' ) );
    return $outcome;
};

$object = eZContentFunctions::createAndPublishObject( array( 'parent_node_id' => $parentID, 'class_identifier' => 'article',
    'creator_id' => (int) $user->attribute( 'contentobject_id' ), 'language' => $source,
    'attributes' => array( 'title' => 'T359c bakery', 'intro' => $introEn, 'body' => $bodyEn ) ), false );
if ( !$object )
{
    echo "FAIL could not create the article\n";
    $script->shutdown( 1 );
}
$objectID = (int) $object->attribute( 'id' );
echo "article object $objectID in $source\n";
$sync = new expAiTranslationSync();
$made = array( 'jobs' => array() );
try
{
    $object = eZContentObject::fetch( $objectID );
    $vDe = $publish( $object, $target, array( 'title' => 'T359c Bäckerei', 'intro' => $introDe, 'body' => $bodyDe ) );
    $object = eZContentObject::fetch( $objectID );
    $check( 'the translation exists', in_array( $target, array_keys( $object->allLanguages() ), true ), true );
    $first = $sync->check( $object, array( 'dry_run' => true ) );
    $check( 'right after the translation: no-op', $first[$target]['decision'], 'noop' );

    /* --------------------------------------------------- 1. a small edit: a patch, queued at once */
    $edited = str_replace( 'On Sundays we are closed.', 'On Sundays we are open from 8 to 12.', $bodyEn );
    $v3 = $publish( $object, $source, array( 'body' => $edited ) );
    $object = eZContentObject::fetch( $objectID );
    $dry = $sync->check( $object, array( 'dry_run' => true ) );
    $check( 'small edit: decision patch', $dry[$target]['decision'], 'patch' );
    $check( 'small edit: base is the translation version', $dry[$target]['from_version'], $vDe );
    $check( 'small edit: one block changed', $dry[$target]['blocks_changed'], 1 );
    $check( 'small edit: below the threshold, queued automatically', array( $dry[$target]['auto'], $dry[$target]['state'] ), array( true, 'would_queue' ) );
    echo '      ratio ' . $dry[$target]['change_ratio'] . ', estimate ' . expAiBudget::money( $dry[$target]['estimate_micros'] ) . ' on ' . $dry[$target]['lane'] . "\n";

    // the workflow event, as the trigger content/publish/after calls it
    include_once 'extension/expai/eventtypes/event/expaitranslationsync/expaitranslationsynctype.php';
    $process = new class( $objectID, $v3 ) {
        private $p;
        public function __construct( $o, $v ) { $this->p = array( 'object_id' => $o, 'version' => $v ); }
        public function attribute( $name ) { return $name === 'parameter_list' ? $this->p : null; }
    };
    $type = new expAiTranslationSyncType();
    $check( 'workflow event: accepted', $type->execute( $process, null ), eZWorkflowType::STATUS_ACCEPTED );
    $checkJobs = expQueue::listJobs( array( 'type' => expAiTranslationSync::CHECK_JOB ), 0, 5 );
    $checkJob = null;
    foreach ( $checkJobs as $job )
    {
        $p = $job->payloadArray();
        if ( (int) $p['object_id'] === $objectID && (int) $p['version'] === $v3 )
            $checkJob = $job;
    }
    $check( 'workflow event: a check job was queued', $checkJob !== null, true );
    if ( $checkJob )
    {
        $made['jobs'][] = $checkJob->attribute( 'uuid' );
        $check( 'check job: done', $run( $checkJob->attribute( 'uuid' ) ), 'done' );
    }
    $state = expAiTranslationSync::state( $objectID, $target );
    $check( 'after the check: queued', $state['state'], 'queued' );
    $check( 'after the check: a patch', $state['mode'], 'patch' );
    $patchJob = expQueue::fetch( $state['job_uuid'] );
    $check( 'the patch job has the class translation_patch', $patchJob ? $patchJob->attribute( 'class' ) : '', 'translation_patch' );
    $made['jobs'][] = $state['job_uuid'];
    $check( 'patch job: done', $run( $state['job_uuid'] ), 'done' );
    $patchJob = expQueue::fetch( $state['job_uuid'] );
    if ( $patchJob && $patchJob->attribute( 'state' ) !== 'done' )
        echo '      job error: ' . $patchJob->attribute( 'last_error' ) . "\n";
    $state = expAiTranslationSync::state( $objectID, $target );
    $check( 'after the patch: a draft', $state['state'], 'draft' );
    $draftNo = isset( $state['draft_version'] ) ? (int) $state['draft_version'] : 0;
    $object = eZContentObject::fetch( $objectID );
    $draft = $draftNo ? $object->version( $draftNo ) : null;
    $check( 'the draft is a draft (not published)', $draft ? (int) $draft->attribute( 'status' ) : -1, eZContentObjectVersion::STATUS_DRAFT );
    $check( 'the draft is in the translation language', $draft ? $draft->initialLanguageCode() : '', $target );
    $log = $draftNo ? expAiDraftLog::fetchByVersion( $objectID, $draftNo ) : null;
    $check( 'the draft carries the label (draft log, source translation)', $log ? $log->attribute( 'source' ) : '', 'translation' );
    $values = $draftNo ? expAiDiff::values( $object, $draftNo, $target ) : array();
    $newBody = isset( $values['body'] ) ? $values['body']['value'] : '';
    $rich = new expAiSegmenterRichText();
    $before = $rich->segment( $bodyDe, array( 'language' => $target ) );
    $after = $rich->segment( $newBody, array( 'language' => $target ) );
    $check( 'the draft body has the same blocks', count( $after ), count( $before ) );
    $changedBlocks = array();
    foreach ( $before as $i => $block )
        if ( !isset( $after[$i] ) || $after[$i]['hash'] !== $block['hash'] )
            $changedBlocks[] = $i;
    $check( 'only the changed block differs', $changedBlocks, array( 2 ) );
    echo '      new: ' . ( isset( $after[2] ) ? $after[2]['text'] : '' ) . "\n";
    $check( 'the patched block is German about Sunday', isset( $after[2] ) && preg_match( '/Sonntag/u', $after[2]['text'] ) === 1 && preg_match( '/8/', $after[2]['text'] ) === 1, true );
    $check( 'the link with its url_id is kept', strpos( $newBody, '<link url_id="' . $url . '">unsere Website</link>' ) !== false, true );
    $check( 'the intro was not touched', isset( $values['intro'] ) ? $values['intro']['value'] === $introDe || $rich->segment( $values['intro']['value'] )[0]['hash'] === $rich->segment( $introDe )[0]['hash'] : false, true );
    $check( 'the published translation is unchanged', expAiDiff::values( $object, (int) $object->attribute( 'current_version' ), $target )['body']['value'] === $bodyDe
            || $rich->segment( expAiDiff::values( $object, (int) $object->attribute( 'current_version' ), $target )['body']['value'] ) == $before, true );

    /* --------------------------------------------------- 2. a rewrite: full translation, waits for an editor */
    $rewritten = $xml( '<paragraph>Since this spring the bakery has a new owner and a café with twenty seats.</paragraph>'
                     . '<section><header>New café</header><paragraph>Breakfast is served every day until eleven o\'clock, with coffee from a local roaster.</paragraph></section>'
                     . '<paragraph><ul><li><paragraph>Sourdough bread</paragraph></li><li><paragraph>Vegan cakes</paragraph></li><li><paragraph>Breakfast</paragraph></li></ul></paragraph>'
                     . '<paragraph>Read <link url_id="' . $url . '">the menu</link> online.</paragraph>' );
    $object = eZContentObject::fetch( $objectID );
    $v5 = $publish( $object, $source, array( 'intro' => $xml( '<paragraph>A bakery with a new café.</paragraph>' ), 'body' => $rewritten ) );
    $object = eZContentObject::fetch( $objectID );
    $rows = $sync->check( $object, array( 'trigger' => 'test' ) );
    $check( 'rewrite: decision full', $rows[$target]['decision'], 'full' );
    $check( 'rewrite: waits for an editor', array( $rows[$target]['state'], $rows[$target]['auto'], $rows[$target]['job_uuid'] ), array( 'waiting', false, '' ) );
    echo '      ratio ' . $rows[$target]['change_ratio'] . ', estimate ' . expAiBudget::money( $rows[$target]['estimate_micros'] ) . ', reason: ' . $rows[$target]['reason'] . "\n";
    $status = $sync->status( $object );
    $check( 'status lists the translation', isset( $status['languages'][$target] ) ? $status['languages'][$target]['state'] : '', 'waiting' );
    $approved = $sync->approve( $object, $target );
    $check( 'approve: queued as a full translation', array( $approved['state'], $approved['mode'] ), array( 'queued', 'full' ) );
    $fullJob = expQueue::fetch( $approved['job_uuid'] );
    $check( 'the full job has the class translation_full', $fullJob ? $fullJob->attribute( 'class' ) : '', 'translation_full' );
    $made['jobs'][] = $approved['job_uuid'];
    $check( 'full job: done', $run( $approved['job_uuid'] ), 'done' );
    $fullJob = expQueue::fetch( $approved['job_uuid'] );
    if ( $fullJob && $fullJob->attribute( 'state' ) !== 'done' )
        echo '      job error: ' . $fullJob->attribute( 'last_error' ) . "\n";
    $state = expAiTranslationSync::state( $objectID, $target );
    $check( 'after the full translation: a draft', $state['state'], 'draft' );
    $object = eZContentObject::fetch( $objectID );
    $fullValues = !empty( $state['draft_version'] ) ? expAiDiff::values( $object, (int) $state['draft_version'], $target ) : array();
    $fullBody = isset( $fullValues['body'] ) ? $fullValues['body']['value'] : '';
    $fb = $rich->segment( $fullBody, array( 'language' => $target ) );
    $check( 'full: the structure of the new source', array_column( $fb, 'type' ), array_column( $rich->segment( $rewritten ), 'type' ) );
    $check( 'full: German text', preg_match( '/(Bäckerei|Frühstück|Kuchen)/u', implode( ' ', array_column( $fb, 'text' ) ) ) === 1, true );
    $check( 'full: the link kept', preg_match( '/<link url_id="' . $url . '">[^<]+<\/link>/', $fullBody ) === 1, true );
    echo '      ' . expAiText::cut( implode( ' | ', array_column( $fb, 'text' ) ), 300 ) . "\n";
    $title = isset( $fullValues['title'] ) ? $fullValues['title']['value'] : '';
    $check( 'full: the title is unchanged in the source, so it was translated too', $title !== '', true );

    /* --------------------------------------------------- skip */
    $skipped = $sync->skip( $object, $target );
    $check( 'skip: up to date with the current version', array( $skipped['state'], $skipped['base_version'] ), array( 'skipped', (int) $object->attribute( 'current_version' ) ) );
    $after = $sync->check( $object, array( 'dry_run' => true ) );
    $check( 'after skip: no-op', $after[$target]['decision'], 'noop' );
}
catch ( Exception $e )
{
    $check( 'no exception', get_class( $e ) . ': ' . $e->getMessage() . ( $e instanceof expAiException ? ' (' . $e->detail() . ')' : '' ), '' );
}

// costs of this run (the usage rows stay: they are real spend)
$cost = 0;
foreach ( $made['jobs'] as $uuid )
{
    $job = expQueue::fetch( $uuid );
    if ( $job )
        $cost += (int) $job->attribute( 'cost_actual_micros' );
}
echo '      cost of this run: ' . expAiBudget::money( $cost ) . ' (' . count( $made['jobs'] ) . " jobs)\n";

if ( !$keep )
{
    eZPersistentObject::removeObject( expAiDraftLog::definition(), array( 'contentobject_id' => $objectID ) );
    expAiTranslationSync::forget( $objectID, array( $target, $source ) );
    eZContentObjectOperations::remove( $objectID );
    eZContentObject::clearCache();
    $check( 'cleanup: the article is gone', eZContentObject::fetch( $objectID ) === null, true );
}
else
    echo "      kept: object $objectID\n";

echo ( $failures ? 'FAIL' : 'PASS' ) . " $checks checks, $failures failed\n";
$script->shutdown( $failures ? 1 : 0 );
