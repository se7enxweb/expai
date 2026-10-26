<?php
/**
 * Alt text (doc/alt-text.md): asking the vision model about a stored image (a stub provider answers), the report of
 * images without alt text, the bulk queue and its job handler writing a draft, the ezjscore function of the edit form,
 * the field action and the template fetch.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicontenttestcase.php';

class expAiAltTextTest extends expAiContentTestCase
{
    private static function answer( $alt, $decorative = false, $note = 'looks red' )
    {
        return expAiContentStubProvider::text( json_encode( array( 'alt_text' => $alt, 'decorative' => $decorative, 'note' => $note ) ) );
    }

    public function testAskTheModelAboutAStoredImage(): void
    {
        $config = $this->siteSettings( array( 'AltTextSettings' => array( 'Enabled' => 'enabled', 'Model' => '', 'ImageRegions' => array(), 'ImageZeroRetention' => 'disabled',
                                                                           'MaxLength' => '150', 'ExtraInstructions' => 'Mention the brand colours.' ) ) );
        $folder = $this->folder();
        $this->asUser( 'admin' );
        $image = $this->image( (int) $folder->attribute( 'node_id' ), 'XT red square' );
        $this->assertTrue( expAiAltText::enabled( $config ) );
        $this->assertSame( array( self::ALIAS ), expAiAltText::visionModels( $config ) );
        $choice = expAiAltText::chooseModel( $config, $image );
        $this->assertSame( array( self::ALIAS, 'Stub model', self::PROVIDER ), array( $choice['alias'], $choice['label'], $choice['provider'] ) );
        $this->assertStringContainsString( 'House rules of this site: Mention the brand colours.', expAiAltText::systemPrompt( $config ) );
        $this->assertSame( self::languageLabel( 'ger-DE' ), expAiAltText::languageName( 'ger-DE' ) );
        $this->assertStringStartsWith( 'German', expAiAltText::languageName( 'ger-DE' ) );
        $this->assertSame( 'xx-YY', expAiAltText::languageName( 'xx-YY' ) );

        $attribute = self::imageAttribute( $image );
        $input = expAiImageInput::fromAttribute( $attribute, expAiImageInput::limitsFor( $config, self::PROVIDER ), 'original' );
        $this->assertSame( array( 64, 48 ), array( $input->facts()['width'], $input->facts()['height'] ) );

        expAiContentStubProvider::$answers = array( self::answer( 'Image of a red square on white' ) );
        $result = expAiAltText::suggest( $config, $image, $attribute, self::SOURCE, array( 'name' => 'XT red square' ), 'old alt' );
        $this->assertSame( array( 'A red square on white', false, 'looks red', self::ALIAS ), array( $result['value'], $result['decorative'], $result['note'], $result['alias'] ) );
        $this->assertGreaterThan( 0, $result['tokens']['image'] );
        $this->assertGreaterThan( 0, $result['usage_log_id'] );
        $request = expAiContentStubProvider::$requests[0];
        $this->assertSame( 'image', $request['messages'][0]['content'][0]['type'] );
        $this->assertStringContainsString( 'The current alt text (improve it if it breaks the rules): old alt', $request['messages'][0]['content'][1]['text'] );
        $row = eZPersistentObject::fetchObject( expAiUsageLog::definition(), null, array( 'id' => $result['usage_log_id'] ) );
        $this->assertSame( array( 'field:alt_text', self::PROVIDER ), array( (string) $row->attribute( 'action' ), (string) $row->attribute( 'provider' ) ) );
        $this->assertStringContainsString( 'image_tokens', (string) $row->attribute( 'tool_calls' ), 'the log shows the image by its facts, not its pixels' );

        expAiContentStubProvider::$answers = array( expAiContentStubProvider::text( 'no', 'refusal' ) );
        $this->assertRefused( function () use ( $config, $image, $attribute ) { expAiAltText::suggest( $config, $image, $attribute, self::SOURCE, array() ); }, 'declined' );
        expAiContentStubProvider::$answers = array( expAiContentStubProvider::text( 'a red square' ) );
        $this->assertRefused( function () use ( $config, $image, $attribute ) { expAiAltText::suggest( $config, $image, $attribute, self::SOURCE, array() ); }, 'could not be read' );

        $config->ini()->setVariable( 'AltTextSettings', 'ImageZeroRetention', 'enabled' );
        $config->ini()->setVariable( 'Provider_' . self::PROVIDER, 'Retention', '30d' );
        $this->assertRefused( function () use ( $config, $image ) { expAiAltText::chooseModel( $config, $image ); }, 'keeps the image of "XT red square"' );
        $config->ini()->setVariable( 'AltTextSettings', 'Enabled', 'disabled' );
        $this->assertRefused( function () use ( $config, $image, $attribute ) { expAiAltText::suggest( $config, $image, $attribute, self::SOURCE, array() ); }, 'switched off' );
    }

    public function testTheReportReadsTheImageAttributesInOneQueryForManyObjects(): void
    {
        $this->siteSettings( array( 'AltTextSettings' => array( 'Enabled' => 'enabled' ) ) );
        $folder = $this->folder();
        $this->asUser( 'admin' );
        $ids = array();
        for ( $i = 0; $i < 4; $i++ )
            $ids[] = (int) $this->image( (int) $folder->attribute( 'node_id' ), 'XT batch ' . $i )->attribute( 'id' );

        // the same attributes as the data maps give: same ids, same identifiers, per language
        $batched = expAiAltText::imageAttributes( $ids );
        foreach ( $ids as $id )
        {
            $object = eZContentObject::fetch( $id );
            $map = $object->fetchDataMap( (int) $object->attribute( 'current_version' ), self::SOURCE );
            $this->assertSame( (int) $map['image']->attribute( 'id' ), (int) $batched[$id][self::SOURCE]['image']->attribute( 'id' ) );
            $this->assertSame( array( 'image' ), array_keys( $batched[$id][self::SOURCE] ) );
        }

        // the report of the whole folder lists the objects in the order of the tree
        $report = expAiAltText::missing( $folder );
        $this->assertCount( 4, $report['rows'] );
        $this->assertSame( $ids, array_column( $report['rows'], 'object_id' ) );
    }

    public function testTheReportTheQueueAndTheJob(): void
    {
        $config = $this->siteSettings( array( 'AltTextSettings' => array( 'Enabled' => 'enabled', 'Model' => '', 'ImageRegions' => array(), 'ImageZeroRetention' => 'disabled',
                                                                           'CompanionAttributes' => array( 'name' ), 'GroupBudget' => '0' ) ) );
        $folder = $this->folder();
        $admin = $this->asUser( 'admin' );
        $image = $this->image( (int) $folder->attribute( 'node_id' ), '' );
        $imageID = (int) $image->attribute( 'id' );
        $this->assertContains( (int) $image->attribute( 'contentclass_id' ), expAiAltText::imageClassIDs() );

        $report = expAiAltText::missing( $folder );
        $this->assertSame( array( 1, false ), array( count( $report['rows'] ), $report['truncated'] ) );
        $row = $report['rows'][0];
        $this->assertSame( array( $imageID, self::SOURCE, 'image', 64, 48 ), array( $row['object_id'], $row['language'], $row['identifier'], $row['width'], $row['height'] ) );
        $this->assertTrue( expAiAltText::missing( $image->mainNode(), 0 )['truncated'], 'the node itself counts' );

        $estimate = expAiAltText::estimate( $config, self::ALIAS, 3000, 2000 );
        $this->assertTrue( $estimate['known'] );
        $this->assertGreaterThan( 0, $estimate['micros'] );

        $this->assertRefused( function () use ( $config ) { expAiAltText::queue( $config, array() ); }, 'nothing to queue' );
        $queued = expAiAltText::queue( $config, array( $row, array( 'object_id' => 0, 'language' => self::SOURCE ) + $row ) );
        $group = expQueueGroup::fetchByUUID( $queued['group'] );
        $groupID = (int) $group->attribute( 'id' );
        $this->cleanup( static function () use ( $groupID )
        {
            $db = eZDB::instance();
            foreach ( (array) $db->arrayQuery( 'SELECT id FROM expqueue_job WHERE group_id = ' . $groupID ) as $job )
                $db->query( 'DELETE FROM expqueue_event WHERE job_id = ' . (int) $job['id'] );
            $db->query( 'DELETE FROM expqueue_job WHERE group_id = ' . $groupID );
            $db->query( 'DELETE FROM expqueue_event WHERE group_id = ' . $groupID );
            $db->query( 'DELETE FROM expqueue_group WHERE id = ' . $groupID );
        } );
        $this->assertSame( array( 1, 0 ), array( $queued['queued'], $queued['duplicates'] ) );
        $this->assertSame( 'you may not edit it', $queued['skipped'][0] );
        $again = expAiAltText::queue( $config, array( $row ) );
        $this->assertSame( array( 0, 1 ), array( $again['queued'], $again['duplicates'] ), 'the same image is not queued twice' );
        $againGroup = (int) expQueueGroup::fetchByUUID( $again['group'] )->attribute( 'id' );
        eZDB::instance()->query( 'DELETE FROM expqueue_group WHERE id = ' . $againGroup );

        $jobRow = eZDB::instance()->arrayQuery( 'SELECT uuid FROM expqueue_job WHERE group_id = ' . $groupID );
        $job = expQueue::fetch( $jobRow[0]['uuid'] );
        $handler = new expAiAltTextJobHandler();
        $this->assertNull( $handler->validate( $job->payloadArray() ) );
        $this->assertStringContainsString( 'required', $handler->validate( array() ) );
        $this->assertStringContainsString( '(' . self::SOURCE . ')', $handler->describe( $job ) );

        // the job as a worker runs it: claimed, the model asked, the draft written
        eZDB::instance()->query( "UPDATE expqueue_job SET state = 'running', lease_owner = 'xtc', lease_until = " . ( time() + 600 ) . ' WHERE id = ' . (int) $job->attribute( 'id' ) );
        $job = expQueue::fetch( $jobRow[0]['uuid'] );
        $context = new expQueueContext( $job, 'xtc' );
        expAiContentStubProvider::$answers = array( self::answer( 'Photo of a red square' ) );
        $result = $handler->run( $job, $context );
        $this->assertSame( 'done', $result->outcome, $result->message );
        $this->assertSame( 'A red square', $result->data['value'] );
        $this->assertEqualsCanonicalizing( array( 'image', 'name' ), $result->data['written'], 'the empty companion field gets the alt text too' );
        $draft = eZContentObject::fetch( $imageID )->version( $result->data['draft_version'] );
        $this->assertSame( eZContentObjectVersion::STATUS_DRAFT, (int) $draft->attribute( 'status' ) );
        $this->assertSame( 'A red square', (string) self::imageAttribute( eZContentObject::fetch( $imageID ), $result->data['draft_version'] )->attribute( 'content' )->attribute( 'alternative_text' ) );
        $this->assertSame( 'alt_text', (string) eZPersistentObject::fetchObject( expAiDraftLog::definition(), null, array( 'contentobject_id' => $imageID ) )->attribute( 'action' ) );
        $this->assertSame( 'A red square', $context->checkpoint()['answer']['value'], 'the answer is kept before the draft is written' );

        // a rerun after a crash: the checkpoint is written again, the model is not asked
        $requests = count( expAiContentStubProvider::$requests );
        $rerun = $handler->run( $job, $context );
        $this->assertSame( 'done', $rerun->outcome );
        $this->assertSame( $requests, count( expAiContentStubProvider::$requests ) );

        // a decorative image gets no draft
        $context->saveCheckpoint( array( 'answer' => array( 'value' => '', 'decorative' => true, 'note' => '', 'model' => 'm', 'cost_micros' => 0, 'image' => array( 'image_tokens' => 1 ) ) ) );
        $decorative = $handler->run( $job, $context );
        $this->assertStringContainsString( 'decorative', $decorative->message );
        $context->saveCheckpoint( array() );

        // the model fails: retry on 429, fail otherwise
        expAiContentStubProvider::$answers = array( new expAiException( 'Too many requests', 'rate limit', 429 ) );
        $this->assertSame( 'retry', $handler->run( $job, $context )->outcome );
        expAiContentStubProvider::$answers = array( new expAiException( 'Bad request', 'broken', 400 ) );
        $failed = $handler->run( $job, $context );
        $this->assertSame( array( 'fail', 'alttext' ), array( $failed->outcome, $failed->errorClass ) );
        // a veto refuses the request: the job fails for good with the reason
        expAiContentStubProvider::$answers = array( new expAiVetoException( 'Not for %what', '', 0, array( '%what' => 'this image' ) ) );
        $vetoed = $handler->run( $job, $context );
        $this->assertSame( array( 'fail', 'veto', 'refused: Not for this image' ), array( $vetoed->outcome, $vetoed->errorClass, $vetoed->message ) );

        // a language the image field is not translated into (any more): a permanent failure, nothing is asked
        $requests = count( expAiContentStubProvider::$requests );
        $otherLanguage = new expQueueJob( array( 'payload' => json_encode( array( 'language' => self::TARGET ) + $job->payloadArray() ) ) );
        try
        {
            $handler->run( $otherLanguage, new expQueueContext( $otherLanguage, 'xtc' ) );
            $this->fail( 'no exception' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertStringContainsString( 'has no ' . self::TARGET . ' translation of the image field', $e->getMessage() );
        }
        $this->assertSame( $requests, count( expAiContentStubProvider::$requests ) );

        // switched off: held
        $config->ini()->setVariable( 'AltTextSettings', 'Enabled', 'disabled' );
        $this->assertSame( 'hold', $handler->run( $job, $context )->outcome );
        $config->ini()->setVariable( 'AltTextSettings', 'Enabled', 'enabled' );
        $config->ini()->setVariable( 'AISettings', 'Enabled', 'false' );
        $this->assertStringContainsString( 'switched off', $handler->run( $job, $context )->message );
        $config->ini()->setVariable( 'AISettings', 'Enabled', 'true' );
        // the site budget does not allow the reservation any more: held
        $config->ini()->setVariable( 'Budgets', 'SiteMonthly', '0.000001' );
        eZDB::instance()->query( 'UPDATE expqueue_job SET cost_reserved_micros = 10 WHERE id = ' . (int) $job->attribute( 'id' ) );
        $budgetHeld = $handler->run( expQueue::fetch( $jobRow[0]['uuid'] ), $context );
        $this->assertSame( 'hold', $budgetHeld->outcome );
        $this->assertStringContainsString( 'budget: the monthly site budget', $budgetHeld->message );
        $config->ini()->setVariable( 'Budgets', 'SiteMonthly', '' );

        // an image that has an alt text now: nothing to do
        $copy = $image->createNewVersion();
        $attribute = self::imageAttribute( $image, (int) $copy->attribute( 'version' ) );
        $handlerContent = $attribute->attribute( 'content' );
        $handlerContent->setAttribute( 'alternative_text', 'Set by an editor' );
        $handlerContent->store( $attribute );
        $attribute->store();
        eZOperationHandler::execute( 'content', 'publish', array( 'object_id' => $imageID, 'version' => (int) $copy->attribute( 'version' ) ) );
        eZContentObject::clearCache( array( $imageID ) );
        $now = $handler->run( $job, $context );
        $this->assertSame( array( 'done', 'the image has an alt text now' ), array( $now->outcome, $now->message ) );
        $this->assertSame( array(), expAiAltText::missing( $folder )['rows'], 'the report lists it no more' );

        $gone = new expQueueJob( array( 'payload' => json_encode( array( 'object_id' => 0, 'attribute_id' => 1, 'language' => 'x' ) ) ) );
        try
        {
            $handler->run( $gone, $context );
            $this->fail( 'no exception' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertStringContainsString( 'is gone', $e->getMessage() );
        }
        $this->assertSame( (int) $admin->attribute( 'contentobject_id' ), (int) eZUser::currentUserID() );
    }

    public function testTheEditFormTheFieldActionAndTheFetch(): void
    {
        $config = $this->siteSettings( array( 'AltTextSettings' => array( 'Enabled' => 'enabled', 'Model' => '', 'ImageRegions' => array(), 'ImageZeroRetention' => 'disabled' ),
                                              'EditViewSettings' => array( 'Enabled' => 'enabled' ) ) );
        $folder = $this->folder();
        $this->asUser( 'admin' );
        $image = $this->image( (int) $folder->attribute( 'node_id' ), 'XT edit form' );
        $imageID = (int) $image->attribute( 'id' );
        $draft = $image->createNewVersion();
        $versionNo = (int) $draft->attribute( 'version' );
        $attribute = self::imageAttribute( eZContentObject::fetch( $imageID ), $versionNo );

        // the template fetch
        $fetched = expAiAltTextFunctionCollection::fetchModel( $image )['result'];
        $this->assertSame( array( true, self::ALIAS, '' ), array( $fetched['available'], $fetched['alias'], $fetched['reason'] ) );
        $this->assertFalse( expAiAltTextFunctionCollection::fetchModel( null )['result']['available'] );

        // the ezjscore function of the button
        expAiContentStubProvider::$answers = array( self::answer( 'A red square', true, 'maybe a divider' ) );
        self::post( array( 'object_id' => $imageID, 'version' => $versionNo, 'language' => self::SOURCE, 'attribute_id' => (int) $attribute->attribute( 'id' ),
                           'value' => '', 'context' => json_encode( array( 'name' => 'XT edit form' ) ) ) );
        $answer = expAiAltTextServerFunctions::suggest( array() );
        $this->assertSame( array( 'A red square', true, 'maybe a divider', 'Stub model' ), array( $answer['value'], $answer['decorative'], $answer['note'], $answer['model_label'] ) );
        $this->assertSame( 64, $answer['image']['width'] );
        self::post( array( 'object_id' => $imageID, 'version' => $versionNo, 'language' => self::SOURCE, 'attribute_id' => 0 ) );
        $this->assertRefused( function () { expAiAltTextServerFunctions::suggest( array() ); }, 'field was not found' );
        $name = self::imageAttribute( eZContentObject::fetch( $imageID ), $versionNo );
        foreach ( eZContentObject::fetch( $imageID )->fetchDataMap( $versionNo, self::SOURCE ) as $identifier => $candidate )
            if ( $identifier === 'name' )
                $name = $candidate;
        self::post( array( 'object_id' => $imageID, 'version' => $versionNo, 'language' => self::SOURCE, 'attribute_id' => (int) $name->attribute( 'id' ) ) );
        $this->assertRefused( function () { expAiAltTextServerFunctions::suggest( array() ); }, 'not available for this field' );

        // the field action on a provider given by the caller
        $action = new expAiAltTextFieldAction( 'alt_text', $config );
        $this->assertSame( array( 'Write alt text', array( 'ezimage' ), 'none' ), array( $action->label(), $action->datatypes(), $action->input() ) );
        $request = new expAiFieldRequest();
        $request->object = eZContentObject::fetch( $imageID );
        $request->attribute = $attribute;
        $request->classAttribute = $attribute->contentClassAttribute();
        $request->language = self::SOURCE;
        $request->context = array();
        $request->value = '';
        expAiContentStubProvider::$answers = array( self::answer( 'Picture of a red square' ) );
        $run = $action->run( $request, $config->registry()->providerFor( self::ALIAS ) );
        $this->assertSame( 'A red square', $run['value'] );
        $config->ini()->setVariable( 'Provider_' . self::PROVIDER, 'Region', 'us' );
        $config->ini()->setVariable( 'AltTextSettings', 'ImageRegions', array( 'eu' ) );
        $this->assertRefused( function () use ( $action, $request, $config ) { $action->run( $request, $config->registry()->providerFor( self::ALIAS ) ); }, 'data policy keeps' );
        $this->assertRefused( function () use ( $image ) { throw new expAiException( expAiAltTextFunctionCollection::fetchModel( $image )['result']['reason'] ); }, 'data policy' );
    }

    public function testAFieldThatRequiresAnAltTextOffersNoDecorativeChoice(): void
    {
        // class attributes that are never stored: the option "Alternative text is required" on and off
        $required = new eZContentClassAttribute( array( 'data_type_string' => 'ezimage', 'data_int2' => 1 ) );
        $optional = new eZContentClassAttribute( array( 'data_type_string' => 'ezimage', 'data_int2' => 0 ) );
        $this->assertFalse( expAiAltText::altTextRequired( $optional ) );
        $this->assertFalse( expAiAltTextFunctionCollection::fetchAltTextRequired( $optional )['result'] );
        $this->assertFalse( expAiAltText::altTextRequired( null ), 'anything but an attribute requires nothing' );
        $this->assertFalse( expAiAltText::altTextRequired( 'image' ) );

        // the edit form leaves the "Decorative" choice out for such a field and says why
        $template = file_get_contents( __DIR__ . '/../../../../design/admin/templates/expai/edit/alttext.tpl' );
        $this->assertStringContainsString( "'alt_text_required', fetch( 'expai', 'alt_text_required', hash( 'attribute', \$exp_ai_alt_attribute ) )", $template );
        $this->assertStringContainsString( "'decorative_required', 'This field requires an alternative text, so it cannot be left empty.'", $template );
        $script = file_get_contents( __DIR__ . '/../../../../design/admin/javascript/expai_alttext.js' );
        $this->assertStringContainsString( "var required = !!image.alt_text_required;", $script );
        $this->assertStringContainsString( "( required ? ' disabled=\"disabled\" aria-disabled=\"true\"' : '' )", $script );
        foreach ( array( 'eng-US', 'ger-DE', 'untranslated' ) as $language )
            $this->assertStringContainsString( '<source>This field requires an alternative text, so it cannot be left empty.</source>',
                file_get_contents( __DIR__ . '/../../../../translations/' . $language . '/translation.ts' ), $language );

        if ( !method_exists( 'eZImageType', 'isAltTextRequired' ) )
        {
            // a kernel without the option: no field requires an alt text, the choice stays
            $this->assertFalse( expAiAltText::altTextRequired( $required ) );
            return;
        }
        $this->assertTrue( expAiAltText::altTextRequired( $required ) );
        $this->assertTrue( expAiAltTextFunctionCollection::fetchAltTextRequired( $required )['result'] );
    }
}
