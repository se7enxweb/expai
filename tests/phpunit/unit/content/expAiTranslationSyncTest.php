<?php
/**
 * expAiTranslationSync, its applier and its ezjscore functions: the decision, the state per translation, the plan of
 * a patch and of a full translation, check / approve / skip / status, the answer written into a draft of the target
 * language, and the publish trigger. The model is never called: the queued request is answered by the test.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicontenttestcase.php';

class expAiTranslationSyncTest extends expAiContentTestCase
{
    private static function metrics( $ratio, $hunks = 1 )
    {
        return array( 'change_ratio' => $ratio, 'blocks_changed' => $hunks, 'hunks' => $hunks );
    }

    public function testDecideNoopFullAndPatch(): void
    {
        $config = $this->config( array( 'TranslationSync' => array( 'PatchMaxChangedRatio' => '0.25', 'FullMinChangedRatio' => '0.6', 'PatchMaxSegments' => '3',
                                                                     'CostThreshold' => '0.02', 'AutoQueuePatch' => 'enabled', 'FullIfStructureMismatch' => 'enabled' ) ) );
        $noop = expAiTranslationSync::decide( self::metrics( 0.0, 0 ), true, 0, 0, $config );
        $this->assertSame( array( 'noop', false, true ), array( $noop['decision'], $noop['needs_editor'], $noop['auto'] ) );

        $mismatch = expAiTranslationSync::decide( self::metrics( 0.1 ), false, 1, 0, $config );
        $this->assertSame( 'full', $mismatch['decision'] );
        $this->assertStringContainsString( 'structure', $mismatch['reason'] );

        $big = expAiTranslationSync::decide( self::metrics( 0.7 ), true, 1, 0, $config );
        $this->assertSame( array( 'full', true ), array( $big['decision'], $big['needs_editor'] ) );
        $this->assertStringContainsString( 'FullMinChangedRatio 0.60', $big['reason'] );

        $small = expAiTranslationSync::decide( self::metrics( 0.1 ), true, 2, 1000, $config );
        $this->assertSame( array( 'patch', false, true, 'small change: queued automatically' ),
                           array( $small['decision'], $small['needs_editor'], $small['auto'], $small['reason'] ) );

        $waiting = expAiTranslationSync::decide( self::metrics( 0.4 ), true, 5, 50000, $config );
        $this->assertSame( array( 'patch', true, false ), array( $waiting['decision'], $waiting['needs_editor'], $waiting['auto'] ) );
        $this->assertStringContainsString( 'above PatchMaxChangedRatio', $waiting['reason'] );
        $this->assertStringContainsString( '5 blocks above PatchMaxSegments 3', $waiting['reason'] );
        $this->assertStringContainsString( 'above CostThreshold', $waiting['reason'] );

        $config->ini()->setVariable( 'TranslationSync', 'AutoQueuePatch', 'disabled' );
        $config->ini()->setVariable( 'TranslationSync', 'FullIfStructureMismatch', 'disabled' );
        $off = expAiTranslationSync::decide( self::metrics( 0.1 ), false, 1, 0, $config );
        $this->assertSame( array( 'patch', 'AutoQueuePatch is disabled' ), array( $off['decision'], $off['reason'] ) );
    }

    public function testStateIsStoredMergedAndForgotten(): void
    {
        $this->inTransaction();
        $this->assertSame( 'expai_tsync:12:ger-DE', expAiTranslationSync::stateKey( '12', 'ger-DE' ) );
        $this->assertSame( 'expai_tsync:12:gerDE', expAiTranslationSync::stateKey( 12, "ger'DE" ), 'the language is cleaned' );
        $id = 900000000 + mt_rand( 1, 99999 );
        $this->assertSame( array(), expAiTranslationSync::state( $id, 'ger-DE' ) );
        expAiTranslationSync::saveState( $id, 'ger-DE', array( 'state' => 'waiting', 'units' => 3 ) );
        $merged = expAiTranslationSync::saveState( $id, 'ger-DE', array( 'state' => 'queued' ) );
        $this->assertSame( array( 'queued', 3 ), array( $merged['state'], $merged['units'] ) );
        $this->assertSame( 'queued', expAiTranslationSync::state( $id, 'ger-DE' )['state'] );
        expAiTranslationSync::forget( $id, array( 'ger-DE', 'eng-US' ) );
        $this->assertSame( array(), expAiTranslationSync::state( $id, 'ger-DE' ) );
        $this->assertFalse( expAiTranslationSync::jobActive( '' ) );
        $this->assertFalse( expAiTranslationSync::jobActive( '00000000-0000-4000-8000-000000000000' ) );
    }

    /**
     * An article in eng-US with a ger-DE translation.
     * @return eZContentObject
     */
    private function translatedArticle( $folder )
    {
        $this->asUser( 'admin' );
        $object = $this->article( (int) $folder->attribute( 'node_id' ), 'XT bakery',
            '<paragraph>A small bakery with a long tradition.</paragraph>',
            '<paragraph>Our bakery opened in 1998 on Main Street.</paragraph><paragraph>Every morning we bake bread and rolls.</paragraph>'
          . '<paragraph>We are open from Monday to Saturday.</paragraph><paragraph>On Sundays we are closed.</paragraph>' );
        self::publishVersion( eZContentObject::fetch( (int) $object->attribute( 'id' ) ), self::TARGET, array(
            'title' => 'XT Bäckerei', 'intro' => self::xml( '<paragraph>Eine kleine Bäckerei mit langer Tradition.</paragraph>' ),
            'body' => self::xml( '<paragraph>Unsere Bäckerei wurde 1998 in der Hauptstraße eröffnet.</paragraph><paragraph>Jeden Morgen backen wir Brot und Brötchen.</paragraph>'
                               . '<paragraph>Wir haben von Montag bis Samstag geöffnet.</paragraph><paragraph>Sonntags ist geschlossen.</paragraph>' ) ) );
        return eZContentObject::fetch( (int) $object->attribute( 'id' ) );
    }

    /** a small edit of the source: one sentence of the body */
    private function editSource( eZContentObject $object )
    {
        self::publishVersion( $object, self::SOURCE, array(
            'body' => self::xml( '<paragraph>Our bakery opened in 1998 on Main Street.</paragraph><paragraph>Every morning we bake bread, rolls and cakes.</paragraph>'
                               . '<paragraph>We are open from Monday to Saturday.</paragraph><paragraph>On Sundays we are closed.</paragraph>' ) ) );
        return eZContentObject::fetch( (int) $object->attribute( 'id' ) );
    }

    public function testLanguagesBaseAndAnUnchangedTranslation(): void
    {
        $config = $this->siteSettings( array( 'TranslationSync' => array( 'Enabled' => 'enabled', 'SourceLanguages' => array(), 'TargetLanguages' => array(),
                                                                           'Datatypes' => array(), 'AutoQueuePatch' => 'disabled' ) ) );
        $object = $this->translatedArticle( $this->folder() );
        $sync = new expAiTranslationSync( $config );
        $this->assertTrue( $sync->enabled() );
        $this->assertSame( self::SOURCE, $sync->sourceLanguage( $object ) );
        $this->assertSame( array( self::TARGET ), $sync->targetLanguages( $object ) );
        $this->assertSame( array( 'ezstring', 'eztext', 'ezxmltext', 'ezkeyword' ), $sync->datatypes() );

        $base = $sync->baseFor( $object, self::TARGET, self::SOURCE );
        $this->assertSame( array( 2, 'target_edit' ), array( $base['version'], $base['from'] ), 'the translation was made from version 2' );
        $marked = $sync->baseFor( $object, self::TARGET, self::SOURCE, array( 'base_version' => 9, 'source' => self::SOURCE ) );
        $this->assertSame( array( 9, 'marked' ), array( $marked['version'], $marked['from'] ) );
        $other = $sync->baseFor( $object, self::TARGET, self::SOURCE, array( 'base_version' => 9, 'source' => 'fre-FR' ) );
        $this->assertSame( 'target_edit', $other['from'], 'a base marked for another source does not count' );
        $ai = $sync->baseFor( $object, self::TARGET, self::SOURCE, array( 'draft_version' => 2, 'draft_source_version' => 1 ) );
        $this->assertSame( array( 1, 'ai_draft' ), array( $ai['version'], $ai['from'] ) );

        $dry = $sync->check( $object, array( 'dry_run' => true ) );
        $this->assertSame( array( 'noop', 'up_to_date' ), array( $dry[self::TARGET]['decision'], $dry[self::TARGET]['state'] ) );
        $this->assertSame( array(), expAiTranslationSync::state( (int) $object->attribute( 'id' ), self::TARGET ), 'a dry run stores nothing' );
        $stored = $sync->check( $object );
        $this->assertSame( array( 'up_to_date', 2 ), array( $stored[self::TARGET]['state'], $stored[self::TARGET]['base_version'] ) );

        // only the configured languages
        $config->ini()->setVariable( 'TranslationSync', 'TargetLanguages', array( 'fre-FR' ) );
        $this->assertSame( array(), $sync->targetLanguages( $object ) );
        $config->ini()->setVariable( 'TranslationSync', 'SourceLanguages', array( self::TARGET ) );
        $this->assertSame( self::TARGET, $sync->sourceLanguage( $object ) );
        $config->ini()->setVariable( 'TranslationSync', 'Datatypes', array( 'ezstring' ) );
        $this->assertSame( array( 'ezstring' ), $sync->datatypes() );
    }

    public function testASmallEditIsPlannedAsAPatchAndAnsweredIntoADraft(): void
    {
        $config = $this->siteSettings( array( 'TranslationSync' => array( 'Enabled' => 'enabled', 'SourceLanguages' => array(), 'TargetLanguages' => array(),
                                                                           'Datatypes' => array(), 'AutoQueuePatch' => 'disabled', 'PatchMaxChangedRatio' => '0.5',
                                                                           'Model' => '', 'PatchLane' => 'standard', 'FullLane' => 'standard',
                                                                           'Instructions' => 'Use the formal Sie.' ) ) );
        $object = $this->editSource( $this->translatedArticle( $this->folder() ) );
        $objectID = (int) $object->attribute( 'id' );
        $sync = new expAiTranslationSync( $config );
        $this->assertSame( self::ALIAS, $sync->alias() );

        $plan = $sync->plan( $object, self::TARGET );
        $this->assertSame( array( 'patch', 'patch', true, 2, 3 ), array( $plan['decision'], $plan['mode'], $plan['aligned'], $plan['base_version'], $plan['to_version'] ) );
        $this->assertCount( 1, $plan['units'], 'only the changed block is sent' );
        $unit = $plan['units'][0];
        $this->assertSame( 'body', $unit['attribute'] );
        $this->assertStringContainsString( 'rolls', $unit['old'] );
        $this->assertStringContainsString( 'cakes', $unit['new'] );
        $this->assertStringContainsString( 'Brötchen', $unit['translation'] );
        $this->assertStringContainsString( 'Main Street', $unit['before'] );
        $this->assertStringContainsString( 'Monday', $unit['after'] );
        $content = json_decode( $plan['request']['messages'][0]['content'], true );
        $this->assertSame( array( self::SOURCE, self::TARGET ), array( $content['source_language'], $content['target_language'] ) );
        $this->assertArrayHasKey( 'old_source', $content['units'][0] );
        $this->assertArrayHasKey( 'current_translation', $content['units'][0] );
        $this->assertStringContainsString( 'update existing translations', $plan['request']['system'] );
        $this->assertStringContainsString( 'Use the formal Sie.', $plan['request']['system'] );
        $this->assertSame( array( 'units' ), $plan['request']['output_schema']['required'] );
        $this->assertGreaterThan( 0, $plan['estimate'] );
        $this->assertSame( 'standard', $plan['lane'] );

        $full = $sync->plan( $object, self::TARGET, array( 'mode' => 'full' ) );
        $this->assertSame( 'full', $full['mode'] );
        $this->assertGreaterThan( count( $plan['units'] ), count( $full['units'] ), 'a full translation sends every block' );
        $this->assertStringContainsString( 'Translate every block', $full['request']['system'] );
        $this->assertArrayHasKey( 'source', json_decode( $full['request']['messages'][0]['content'], true )['units'][0] );

        // check: AutoQueuePatch is off, so the patch waits for an editor
        $row = $sync->check( $object, array( 'trigger' => 'test' ) )[self::TARGET];
        $this->assertSame( array( 'waiting', 'patch', 'test' ), array( $row['state'], $row['decision'], $row['trigger'] ) );
        $status = $sync->status( $object );
        $this->assertSame( self::SOURCE, $status['source'] );
        $this->assertSame( 'waiting', $status['languages'][self::TARGET]['state'] );
        $this->assertFalse( $status['languages'][self::TARGET]['stale'] );
        $this->assertNotSame( '', $status['languages'][self::TARGET]['estimate'] );

        // approve: queued for the stub model (its resource is paused: no worker of the site runs it)
        $approved = $sync->approve( $object, self::TARGET );
        $this->assertContains( $approved['state'], array( 'queued', 'held' ) );
        $this->assertSame( 'patch', $approved['mode'] );
        $job = expQueue::fetch( $approved['job_uuid'] );
        $this->assertInstanceOf( 'expQueueJob', $job );
        $uuid = $approved['job_uuid'];
        $this->cleanup( static function () use ( $uuid ) { self::removeJob( $uuid ); } );
        $payload = $job->payloadArray();
        $this->assertSame( array( 'translation_sync', $objectID, self::TARGET, 'patch' ),
                           array( $payload['apply'], $payload['apply_params']['object_id'], $payload['apply_params']['target'], $payload['apply_params']['mode'] ) );
        $this->assertTrue( expAiTranslationSync::jobActive( $uuid ) );
        $again = $sync->check( $object )[self::TARGET];
        $this->assertSame( array( 'queued', $uuid ), array( $again['state'], $again['job_uuid'] ), 'a check keeps the job that is queued for this version' );

        // the answer of the job: the changed block in German, through the applier
        $answer = array( 'value' => array( 'units' => array( array( 'id' => $unit['id'], 'text' => 'Jeden Morgen backen wir Brot, Brötchen und Kuchen.' ) ) ), 'model' => 'stub-model' );
        $applier = new expAiTranslationSyncApplier();
        $context = new expQueueContext( $job, 'test-owner' );
        $result = $applier->apply( $job, $answer, $payload['apply_params'], $context );
        $this->assertSame( self::TARGET, $result['language'] );
        $this->assertContains( 'body', $result['written'] );
        $draft = eZContentObject::fetch( $objectID )->version( $result['draft_version'] );
        $this->assertSame( eZContentObjectVersion::STATUS_DRAFT, (int) $draft->attribute( 'status' ) );
        $body = '';
        foreach ( eZContentObject::fetch( $objectID )->fetchDataMap( $result['draft_version'], self::TARGET ) as $identifier => $attribute )
            if ( $identifier === 'body' )
                $body = (string) $attribute->attribute( 'data_text' );
        $this->assertStringContainsString( 'Brot, Brötchen und Kuchen', $body );
        $this->assertStringContainsString( 'Sonntags ist geschlossen', $body, 'the other blocks keep their translation' );
        $log = eZPersistentObject::fetchObject( expAiDraftLog::definition(), null, array( 'job_uuid' => $uuid ) );
        $this->assertSame( 'translation', (string) $log->attribute( 'source' ) );
        $this->assertSame( 'draft', expAiTranslationSync::state( $objectID, self::TARGET )['state'] );
        $rerun = $sync->applyAnswer( $job, $answer, $payload['apply_params'] );
        $this->assertTrue( $rerun['again'], 'a rerun finds the draft of the job' );
        $this->assertSame( $result['draft_version'], $rerun['draft_version'] );

        // skip: the job is cancelled, the translation is up to date with the current version
        $skipped = $sync->skip( eZContentObject::fetch( $objectID ), self::TARGET );
        $this->assertSame( array( 'skipped', 'noop', '' ), array( $skipped['state'], $skipped['decision'], $skipped['job_uuid'] ) );
        $this->assertSame( 'cancelled', (string) expQueue::fetch( $uuid )->attribute( 'state' ) );
    }

    public function testAnswersThatCannotBeWritten(): void
    {
        $config = $this->siteSettings( array( 'TranslationSync' => array( 'Enabled' => 'enabled', 'SourceLanguages' => array(), 'TargetLanguages' => array(),
                                                                           'Datatypes' => array(), 'AutoQueuePatch' => 'enabled', 'PatchMaxChangedRatio' => '0.5',
                                                                           'CostThreshold' => '100', 'Model' => '', 'PatchLane' => 'standard' ) ) );
        $object = $this->editSource( $this->translatedArticle( $this->folder() ) );
        $objectID = (int) $object->attribute( 'id' );
        $sync = new expAiTranslationSync( $config );
        $row = $sync->check( $object )[self::TARGET];
        $this->assertContains( $row['state'], array( 'queued', 'held' ), 'a small patch is queued at once' );
        $uuid = $row['job_uuid'];
        $this->cleanup( static function () use ( $uuid ) { self::removeJob( $uuid ); } );
        $job = expQueue::fetch( $uuid );
        $params = $job->payloadArray()['apply_params'];
        $applier = new expAiTranslationSyncApplier();
        $context = new expQueueContext( $job, 'test-owner' );

        foreach ( array( array( array(), 'object_id, target and mode are required' ) ) as $case )
            try
            {
                $applier->apply( $job, array( 'value' => array() ), $case[0], $context );
                $this->fail( 'no exception' );
            }
            catch ( expQueuePermanentException $e )
            {
                $this->assertStringContainsString( $case[1], $e->getMessage() );
            }
        try
        {
            $applier->apply( $job, array( 'value' => null, 'stop_reason' => 'max_tokens' ), $params, $context );
            $this->fail( 'no exception' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertStringContainsString( 'MaxOutputTokens', $e->getMessage() );
        }
        $this->assertSame( 'failed', expAiTranslationSync::state( $objectID, self::TARGET )['state'] );
        try
        {
            $applier->apply( $job, array( 'value' => 'text' ), $params, $context );
            $this->fail( 'no exception' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertStringContainsString( 'not the JSON', $e->getMessage() );
        }
        try
        {
            $sync->applyAnswer( $job, array( 'value' => array( 'units' => array() ) ), $params );
            $this->fail( 'no exception' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertStringContainsString( 'the answer has no text for block', $e->getMessage() );
        }
        try
        {
            $sync->applyAnswer( $job, array( 'value' => array( 'units' => array() ) ), array( 'object_id' => 0 ) + $params );
            $this->fail( 'no exception' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertStringContainsString( 'the object is gone', $e->getMessage() );
        }

        // the translation is edited after the plan: the patch does not overwrite that edit
        self::publishVersion( eZContentObject::fetch( $objectID ), self::TARGET, array( 'title' => 'XT Bäckerei Müller' ) );
        try
        {
            $sync->applyAnswer( $job, array( 'value' => array( 'units' => array() ) ), $params );
            $this->fail( 'no exception' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertStringContainsString( 'changed after the patch was planned', $e->getMessage() );
        }
    }

    public function testTheFullTranslationOfANewTranslation(): void
    {
        $config = $this->siteSettings( array( 'TranslationSync' => array( 'Enabled' => 'enabled', 'SourceLanguages' => array(), 'TargetLanguages' => array(),
                                                                           'Datatypes' => array( 'ezstring' ), 'Model' => '', 'FullLane' => 'standard' ) ) );
        $object = $this->translatedArticle( $this->folder() );
        $objectID = (int) $object->attribute( 'id' );
        $sync = new expAiTranslationSync( $config );
        // a base that does not exist: everything counts as new and the structure is unknown
        $plan = $sync->plan( $object, self::TARGET, array( 'base_version' => 0, 'mode' => 'full' ) );
        $approved = $sync->approve( $object, self::TARGET, 'full' );
        $uuid = $approved['job_uuid'];
        $this->cleanup( static function () use ( $uuid ) { self::removeJob( $uuid ); } );
        $this->assertSame( 'full', $approved['mode'] );
        $job = expQueue::fetch( $uuid );
        $params = $job->payloadArray()['apply_params'];
        $units = array();
        foreach ( $sync->plan( $object, self::TARGET, array( 'mode' => 'full', 'base_version' => $params['base_version'], 'to_version' => $params['to_version'] ) )['units'] as $unit )
            $units[] = array( 'id' => $unit['id'], 'text' => 'XT Bäckerei &amp; Café' );
        $this->assertNotEmpty( $units );
        $result = $sync->applyAnswer( $job, array( 'value' => array( 'units' => $units ) ), $params );
        $this->assertContains( 'title', $result['written'] );
        $title = '';
        foreach ( eZContentObject::fetch( $objectID )->fetchDataMap( $result['draft_version'], self::TARGET ) as $identifier => $attribute )
            if ( $identifier === 'title' )
                $title = $attribute->toString();
        $this->assertSame( 'XT Bäckerei & Café', $title, 'a whole field is written as plain text' );
        $this->assertIsArray( $plan['units'] );
    }

    public function testOnPublish(): void
    {
        $config = $this->siteSettings( array( 'TranslationSync' => array( 'Enabled' => 'disabled', 'SourceLanguages' => array(), 'TargetLanguages' => array(),
                                                                           'QueueCheck' => 'disabled', 'AutoQueuePatch' => 'disabled' ) ) );
        $sync = new expAiTranslationSync( $config );
        $this->assertSame( 'disabled', $sync->onPublish( 1, 1 ) );
        $config->ini()->setVariable( 'TranslationSync', 'Enabled', 'enabled' );
        $this->assertSame( 'not_found', $sync->onPublish( 0, 1 ) );
        $folder = $this->folder();
        $this->asUser( 'admin' );
        $single = $this->article( (int) $folder->attribute( 'node_id' ), 'XT single', '<paragraph>Intro</paragraph>', '<paragraph>Body</paragraph>' );
        $this->assertSame( 'single_language', $sync->onPublish( (int) $single->attribute( 'id' ), 1 ) );
        $object = $this->translatedArticle( $folder );
        $this->assertSame( 'not_source', $sync->onPublish( (int) $object->attribute( 'id' ), 2 ), 'version 2 is the translation' );
        $this->assertSame( 'checked', $sync->onPublish( (int) $object->attribute( 'id' ), 1 ) );
        $this->assertSame( 'up_to_date', expAiTranslationSync::state( (int) $object->attribute( 'id' ), self::TARGET )['state'] );

        $config->ini()->setVariable( 'TranslationSync', 'QueueCheck', 'enabled' );
        $this->assertSame( 'queued', $sync->onPublish( (int) $object->attribute( 'id' ), 1 ) );
        $jobs = eZDB::instance()->arrayQuery( "SELECT uuid FROM expqueue_job WHERE idempotency_key = 'tsync-check:" . (int) $object->attribute( 'id' ) . ":1'" );
        $this->assertCount( 1, $jobs );
        self::removeJob( $jobs[0]['uuid'] );
    }

    public function testTheEzjscoreFunctions(): void
    {
        $this->siteSettings( array( 'TranslationSync' => array( 'Enabled' => 'enabled', 'SourceLanguages' => array(), 'TargetLanguages' => array(),
                                                                 'Datatypes' => array(), 'AutoQueuePatch' => 'disabled', 'PatchMaxChangedRatio' => '0.5', 'Model' => '' ) ) );
        $object = $this->editSource( $this->translatedArticle( $this->folder() ) );
        $nodeID = (int) $object->attribute( 'main_node_id' );

        self::post( array( 'node' => $nodeID ), 'GET' );
        $this->assertRefused( function () { expAiTranslationSyncServerFunctions::status( array() ); }, 'only accepts POST' );
        if ( class_exists( 'ezxFormToken' ) && ezxFormToken::isEnabled() )
        {
            self::post( array( 'node' => $nodeID, 'ezxform_token' => 'wrong' ) );
            $this->assertRefused( function () { expAiTranslationSyncServerFunctions::status( array() ); }, 'form token' );
        }
        self::post( array( 'node' => 0 ) );
        $this->assertRefused( function () { expAiTranslationSyncServerFunctions::status( array() ); }, 'does not exist' );

        self::post( array( 'node' => $nodeID ) );
        $status = expAiTranslationSyncServerFunctions::status( array() );
        $this->assertSame( array( (int) $object->attribute( 'id' ), self::SOURCE ), array( $status['object_id'], $status['source'] ) );
        $this->assertSame( 'unchecked', $status['languages'][0]['state'] );
        $this->assertTrue( $status['can_act'] );
        $this->assertTrue( $status['languages'][0]['can_edit'] );

        $checked = expAiTranslationSyncServerFunctions::check( array() );
        $this->assertSame( array( 'waiting', 'patch' ), array( $checked['languages'][0]['state'], $checked['languages'][0]['decision'] ) );
        $this->assertGreaterThan( 0, $checked['languages'][0]['ratio'] );

        self::post( array( 'node' => $nodeID, 'language' => 'fre-FR' ) );
        $this->assertRefused( function () { expAiTranslationSyncServerFunctions::skip( array() ); }, 'not a translation of this object' );
        self::post( array( 'node' => $nodeID, 'language' => self::TARGET ) );
        $skipped = expAiTranslationSyncServerFunctions::skip( array() );
        $this->assertSame( 'skipped', $skipped['languages'][0]['state'] );

        self::post( array( 'node' => $nodeID, 'language' => self::TARGET, 'mode' => 'odd' ) );
        $approved = expAiTranslationSyncServerFunctions::approve( array() );
        $this->assertSame( 'up_to_date', $approved['languages'][0]['state'], 'after a skip there is nothing to translate' );

        eZINI::instance( 'expai.ini' )->setVariable( 'TranslationSync', 'Enabled', 'disabled' );
        $this->assertRefused( function () { expAiTranslationSyncServerFunctions::status( array() ); }, 'switched off' );
    }

    public function testTheQueuedCheckReportsEveryTranslation(): void
    {
        $this->siteSettings( array( 'TranslationSync' => array( 'Enabled' => 'enabled', 'SourceLanguages' => array(), 'TargetLanguages' => array(),
                                                                 'Datatypes' => array(), 'AutoQueuePatch' => 'disabled', 'PatchMaxChangedRatio' => '0.5', 'Model' => '' ) ) );
        $object = $this->editSource( $this->translatedArticle( $this->folder() ) );
        $job = new expQueueJob( array( 'user_id' => (int) eZUser::currentUserID(), 'payload' => json_encode( array( 'object_id' => (int) $object->attribute( 'id' ) ) ) ) );
        $result = ( new expAiTranslationCheckHandler() )->run( $job, new expQueueContext( $job, 'xtc' ) );
        $this->assertSame( array( self::TARGET ), array_keys( $result['languages'] ) );
        $row = $result['languages'][self::TARGET];
        $this->assertSame( array( 'patch', 'waiting', '' ), array( $row['decision'], $row['state'], $row['job_uuid'] ), 'without AutoQueuePatch the patch waits' );
        $this->assertGreaterThan( 0, $row['change_ratio'] );
    }

    public function testTheEzjscoreFunctionsFollowThePoliciesOfTheEditor(): void
    {
        $this->siteSettings( array( 'TranslationSync' => array( 'Enabled' => 'enabled', 'SourceLanguages' => array(), 'TargetLanguages' => array(),
                                                                 'Datatypes' => array(), 'AutoQueuePatch' => 'disabled', 'PatchMaxChangedRatio' => '0.5', 'Model' => '' ) ) );
        $object = $this->editSource( $this->translatedArticle( $this->folder() ) );
        $nodeID = (int) $object->attribute( 'main_node_id' );
        $sync = new expAiTranslationSync();
        $sync->check( $object, array( 'trigger' => 'publish' ) );

        // no expai policy at all: nothing is answered
        $this->roleUser( array( array( 'content', 'read' ) ) );
        self::post( array( 'node' => $nodeID ) );
        $this->assertRefused( function () { expAiTranslationSyncServerFunctions::status( array() ); }, 'You do not have access to Exponential Intelligence.' );

        // expai/write only: the state is shown, starting or skipping a translation is refused
        $this->roleUser( array( array( 'content', 'read' ), array( 'expai', 'write' ) ) );
        self::post( array( 'node' => $nodeID ) );
        $status = expAiTranslationSyncServerFunctions::status( array() );
        $this->assertFalse( $status['can_act'] );
        self::post( array( 'node' => $nodeID, 'language' => self::TARGET ) );
        $this->assertRefused( function () { expAiTranslationSyncServerFunctions::skip( array() ); }, 'You may not start or skip translations.' );
        $this->assertRefused( function () { expAiTranslationSyncServerFunctions::approve( array() ); }, 'You may not start or skip translations.' );

        // the translation sync policy without the right to edit the translation: refused by the sync, as the editor
        $this->roleUser( array( array( 'content', 'read' ), array( 'expai', 'write' ), array( 'expai', 'translation_sync' ) ) );
        self::post( array( 'node' => $nodeID, 'language' => self::TARGET ) );
        $this->assertRefused( function () { expAiTranslationSyncServerFunctions::skip( array() ); }, 'You may not edit object' );
        $this->assertRefused( function () { expAiTranslationSyncServerFunctions::approve( array() ); }, 'You may not edit object' );
    }

    /**
     * A user of its own with one role, logged in; both are removed after the test (as the administrator).
     *
     * @param array $policies array( array( module, function ), ... )
     */
    private function roleUser( array $policies )
    {
        $name = 'expai-unit-' . bin2hex( random_bytes( 4 ) );
        $admin = eZUser::fetchByName( 'admin' );
        $object = eZContentClass::fetchByIdentifier( 'user' )->instantiate( (int) $admin->attribute( 'contentobject_id' ) );
        $userID = (int) $object->attribute( 'id' );
        $role = eZRole::create( 'expai unit test ' . $name );
        $role->store();
        $this->cleanup( static function () use ( $userID, $role, $admin )
        {
            eZUser::setCurrentlyLoggedInUser( $admin, (int) $admin->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
            $role->removeThis();
            if ( $object = eZContentObject::fetch( $userID ) )
                $object->purge();
            if ( eZUser::fetch( $userID ) )
                eZUser::removeUser( $userID );
            eZUser::purgeUserCacheByUserId( $userID );
            eZRole::expireCache();
        } );
        $user = eZUser::create( $userID );
        $user->setAttribute( 'login', $name );
        $user->setAttribute( 'email', $name . '@x1.example.invalid' );
        $user->setAttribute( 'password_hash', eZUser::createHash( $name, bin2hex( random_bytes( 12 ) ), eZUser::site(), eZUser::hashType() ) );
        $user->setAttribute( 'password_hash_type', eZUser::hashType() );
        $user->store();
        foreach ( $policies as $policy )
            $role->appendPolicy( $policy[0], $policy[1], array() );
        $role->assignToUser( $userID );
        eZRole::expireCache();
        eZUser::purgeUserCacheByUserId( $userID );
        $user = eZUser::fetch( $userID );
        eZUser::setCurrentlyLoggedInUser( $user, $userID, eZUser::NO_SESSION_REGENERATE );
        return $user;
    }

    /** Removes a queue job of the test with its events. */
    private static function removeJob( $uuid )
    {
        if ( !preg_match( '/^[0-9a-f-]{36}$/', (string) $uuid ) )
            return;
        $db = eZDB::instance();
        foreach ( (array) $db->arrayQuery( "SELECT id FROM expqueue_job WHERE uuid = '" . $db->escapeString( $uuid ) . "'" ) as $row )
        {
            $db->query( 'DELETE FROM expqueue_event WHERE job_id = ' . (int) $row['id'] );
            $db->query( 'DELETE FROM expqueue_job WHERE id = ' . (int) $row['id'] );
        }
        eZPersistentObject::removeObject( expAiUsageLog::definition(), array( 'job_uuid' => (string) $uuid ) );
    }
}
