<?php
/**
 * expAiServerFunctions (the ezjscore functions of the panel and the edit form), expAiPublisher (keep, send, discard),
 * expAiEditForm (field actions and fill) and expAiContentWriter on a hidden folder of the test, as the
 * administrator, against the mock server of tests/mock/server.php (the provider exists only in memory; nothing leaves
 * 127.0.0.1). The folder is removed with every draft, log row, conversation and thread made below it.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiServerFunctionsTest extends expAiCoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->removeTestRows();
        $this->keepSession();
        expAiProviderHealth::reset();
        $this->cleanup( static function () { expAiProviderHealth::reset(); expAiEditForm::resetRecord(); } );
        if ( class_exists( 'ezxFormToken' ) )
            ezxFormToken::setIsEnabled( false );
    }

    public function testTheGuardRefusesBeforeAnythingHappens(): void
    {
        $this->asUser( 'admin' );
        $this->useMockProvider();
        $this->post( array(), 'GET' );
        $this->assertStringContainsString( 'POST', $this->expectFailure( function () { expAiServerFunctions::load( array() ); } )->getMessage() );

        $this->post( array( 'node_id' => 2 ) );
        if ( class_exists( 'ezxFormToken' ) )
        {
            ezxFormToken::setIsEnabled( true );
            $this->assertStringContainsString( 'form token', $this->expectFailure( function () { expAiServerFunctions::load( array() ); } )->getMessage() );
            $_POST['ezxform_token'] = 'wrong';
            $this->assertStringContainsString( 'form token', $this->expectFailure( function () { expAiServerFunctions::load( array() ); } )->getMessage() );
            $_POST['ezxform_token'] = ezxFormToken::getToken();
            $this->assertIsArray( expAiServerFunctions::load( array() ), 'the right token passes' );
            ezxFormToken::setIsEnabled( false );
        }

        $this->useSettings( array( 'AISettings' => array( 'Enabled' => 'false' ) ) );
        $this->assertStringContainsString( 'switched off', $this->expectFailure( function () { expAiServerFunctions::load( array() ); } )->getMessage() );
        $this->useSettings( array( 'AISettings' => array( 'Enabled' => 'true', 'ApiKey' => '' ) ) );
        $e = $this->expectFailure( function () { expAiServerFunctions::load( array() ); } );
        $this->assertStringContainsString( 'not configured', $e->getMessage() );
        $this->assertSame( 'Model or ApiKey missing', $e->detail() );

        $this->useMockProvider();
        eZUser::setCurrentlyLoggedInUser( eZUser::fetch( eZUser::anonymousId() ), eZUser::anonymousId(), eZUser::NO_SESSION_REGENERATE );
        $this->assertFalse( expAiServerFunctions::hasPolicy() );
        $this->assertStringContainsString( 'do not have access', $this->expectFailure( function () { expAiServerFunctions::load( array() ); } )->getMessage() );
        $this->assertSame( 0, expAiServerFunctions::getCacheTime( 'load' ) );
    }

    public function testLoadHistoryModelStatesResetAndDrafts(): void
    {
        $admin = $this->asUser( 'admin' );
        $before = eZPreferences::value( 'expai_model' );
        $this->cleanup( static function () use ( $before ) { eZPreferences::setValue( 'expai_model', $before === false ? '' : $before ); } );
        $config = $this->useMockProvider();
        $node = $this->workFolder();
        $nodeID = (int) $node->attribute( 'node_id' );

        $this->post( array( 'node_id' => 999999999 ) );
        $this->assertStringContainsString( 'does not exist', $this->expectFailure( function () { expAiServerFunctions::load( array() ); } )->getMessage() );

        // a request handed over from the dashboard is shown once
        eZHTTPTool::instance()->setSessionVariable( 'ExpAiPrefill', array( 'node_id' => $nodeID, 'text' => 'Write about spring' ) );
        $this->post( array( 'node_id' => $nodeID ) );
        $load = expAiServerFunctions::load( array() );
        $this->assertSame( 'Write about spring', $load['prefill'] );
        $this->assertSame( self::TEST_PROVIDER . '.e2e', $load['model']['current'] );
        $this->assertIsString( $load['model']['refused'], 'a translated note when the user\'s stored choice is not allowed here, else empty' );
        $this->assertTrue( $load['model']['manage'] );
        $this->assertFalse( $load['pending'] );
        $this->assertSame( array(), $load['drafts'] );
        $this->assertSame( '', expAiServerFunctions::load( array() )['prefill'], 'and only once' );

        $history = expAiServerFunctions::history( array() );
        $this->assertSame( array(), $history['exchanges'] );

        // the model choice: only an allowed model, '' goes back to the default
        $this->useMockProvider( array( 'AISettings' => array( 'AllowUserModelChoice' => 'enabled' ) ) );
        $this->post( array( 'model' => 'no.such.model' ) );
        $this->assertStringContainsString( 'not available', $this->expectFailure( function () { expAiServerFunctions::model( array() ); } )->getMessage() );
        $this->post( array( 'model' => '' ) );
        $model = expAiServerFunctions::model( array() );
        $this->assertSame( self::TEST_PROVIDER . '.e2e', $model['model'] );
        $this->assertSame( self::TEST_PROVIDER . '.e2e', $model['default'] );
        $this->assertSame( 'XTD e2e', $model['label'] );
        $this->useMockProvider( array( 'AISettings' => array( 'AllowUserModelChoice' => 'disabled' ) ) );
        $this->assertStringContainsString( 'switched off', $this->expectFailure( function () { expAiServerFunctions::model( array() ); } )->getMessage() );
        $this->useMockProvider();

        $states = expAiServerFunctions::states( array() );
        $this->assertArrayNotHasKey( self::TEST_PROVIDER . '.e2e', $states['states'], 'the mock server answers' );
        $this->assertTrue( $states['manage'] );

        // a turn on the mock: the panel's step, then reset forgets the conversation
        $this->post( array( 'node_id' => $nodeID, 'message' => 'Write a folder about our spring events.' ) );
        $step = expAiServerFunctions::chat( array() );
        $this->assertContains( $step['status'], array( 'continue', 'done' ) );
        $this->assertNotNull( expAiConversation::fetchCurrent( (int) $admin->attribute( 'contentobject_id' ), $nodeID ) );
        $this->post( array( 'node_id' => $nodeID, 'continue' => '1' ) );
        for ( $i = 0; $i < 5 && $step['status'] === 'continue'; ++$i )
            $step = expAiServerFunctions::chat( array() );
        $this->assertSame( 'done', $step['status'] );
        $drafts = expAiServerFunctions::drafts( array() );
        $this->assertNotEmpty( $drafts['drafts'], 'the mock wrote a draft below the folder' );
        $this->assertNotEmpty( expAiServerFunctions::history( array() )['exchanges'] );
        $this->post( array( 'node_id' => $nodeID, 'before' => '1' ) );
        $this->assertSame( array(), expAiServerFunctions::history( array() )['exchanges'], 'nothing before the first exchange' );

        $this->post( array( 'node_id' => $nodeID ) );
        $reset = expAiServerFunctions::reset( array() );
        $this->assertSame( array( 'exchanges' => array(), 'has_older' => false ), $reset['history'] );
        $this->assertNull( expAiConversation::fetchCurrent( (int) $admin->attribute( 'contentobject_id' ), $nodeID ) );
        $this->assertNotEmpty( $reset['drafts'], 'the drafts stay' );

        // discard the draft the mock wrote, from the panel's list
        $pair = $drafts['drafts'][0]['object_id'] . ':' . $drafts['drafts'][0]['version'];
        $this->post( array( 'node_id' => $nodeID, 'drafts' => $pair . ',0:0' ) );
        $result = expAiServerFunctions::discard( array() );
        $this->assertTrue( $result['results'][0]['ok'] );
        $this->assertSame( 'discarded', $result['results'][0]['status'] );
        $this->assertFalse( $result['results'][1]['ok'] );
        $this->assertStringContainsString( 'not written for you', $result['results'][1]['error'] );
    }

    public function testKeepSendDiscardThroughThePublisher(): void
    {
        $admin = $this->asUser( 'admin' );
        $this->useMockProvider();
        $node = $this->workFolder();
        $nodeID = (int) $node->attribute( 'node_id' );
        $writer = new expAiContentWriter();
        $adminID = (int) $admin->attribute( 'contentobject_id' );
        $make = function ( $name ) use ( $writer, $node, $adminID, $nodeID )
        {
            list( $object, $version, $written ) = $writer->createDraft( $node, 'folder', array( 'name' => $name ) );
            $this->assertSame( array( 'name' ), $written['written'] );
            expAiDraftLog::record( array( 'contentobject_id' => (int) $object->attribute( 'id' ), 'version' => 1, 'user_id' => $adminID,
                                          'node_id' => $nodeID, 'parent_node_id' => $nodeID, 'action' => 'create', 'source' => 'panel', 'model' => 'mock' ) );
            return $object;
        };
        $kept = $make( 'XTD kept' );
        $sent = $make( 'XTD sent' );

        // node_id 0: the dashboard's call, no list back
        $this->post( array( 'node_id' => '0', 'drafts' => $kept->attribute( 'id' ) . ':1' ) );
        $keep = expAiServerFunctions::keep( array() );
        $this->assertSame( 'kept', $keep['results'][0]['status'] );
        $this->assertSame( array(), $keep['drafts'] );
        $this->assertSame( 'kept', expAiDraftLog::fetchByVersion( $kept->attribute( 'id' ), 1 )->attribute( 'status' ) );
        $this->assertSame( 'kept', expAiPublisher::keep( $kept->attribute( 'id' ), 1 )['status'], 'keeping twice changes nothing' );

        $this->post( array( 'node_id' => (string) $nodeID, 'drafts' => $sent->attribute( 'id' ) . ':1' ) );
        $send = expAiServerFunctions::send( array() );
        $this->assertTrue( $send['results'][0]['ok'], json_encode( $send['results'][0] ) );
        $this->assertContains( $send['results'][0]['status'], array( 'published', 'pending' ) );
        $this->assertContains( $send['results'][0]['approval'], array( 'direct', 'approval', 'possible' ) );
        if ( $send['results'][0]['status'] === 'published' )
        {
            $this->assertStringStartsWith( 'content/view/full/', $send['results'][0]['url'] );
            $this->assertSame( 'published', expAiDraftLog::fetchByVersion( $sent->attribute( 'id' ), 1 )->attribute( 'status' ) );
        }
        $this->assertStringContainsString( 'no longer a draft', $this->expectFailure( function () use ( $sent ) { expAiPublisher::send( $sent->attribute( 'id' ), 1 ); } )->getMessage() );
        $this->assertStringContainsString( 'no longer a draft', $this->expectFailure( function () use ( $sent ) { expAiPublisher::discard( $sent->attribute( 'id' ), 1 ); } )->getMessage() );

        // a required field left empty is named before anything is published
        list( $empty ) = $writer->createDraft( $node, 'folder', array() );
        $emptyName = $empty->fetchDataMap( 1 )['name'];
        $emptyName->fromString( '' );
        $emptyName->store();
        $this->draftLog( array( 'contentobject_id' => (int) $empty->attribute( 'id' ), 'version' => 1, 'user_id' => $adminID, 'node_id' => $nodeID ) );
        $e = $this->expectFailure( function () use ( $empty ) { expAiPublisher::send( $empty->attribute( 'id' ), 1 ); } );
        $this->assertStringContainsString( 'Required fields are empty', $e->getMessage() );
        $this->assertStringContainsString( 'Name', $e->arguments()['%fields'] );
        $this->assertSame( array( 'status' => 'discarded' ), expAiPublisher::discard( $empty->attribute( 'id' ), 1 ) );
        $this->assertNotInstanceOf( 'eZContentObjectVersion', eZContentObjectVersion::fetchVersion( 1, (int) $empty->attribute( 'id' ) ), 'the version is gone' );

        // someone else's entry, a version of another creator
        $other = $this->draftLog( array( 'contentobject_id' => (int) $kept->attribute( 'id' ), 'version' => 1, 'user_id' => self::TEST_USER ) );
        $this->assertStringContainsString( 'not written for you', $this->expectFailure( function () use ( $kept ) { expAiPublisher::load( $kept->attribute( 'id' ), 1 ); } )->getMessage() );
        $other->remove();
        $version = $kept->version( 1 );
        $version->setAttribute( 'creator_id', self::TEST_USER );
        $version->store();
        $this->assertStringContainsString( 'another user', $this->expectFailure( function () use ( $kept ) { expAiPublisher::load( $kept->attribute( 'id' ), 1 ); } )->getMessage() );
        $version->setAttribute( 'creator_id', $adminID );
        $version->store();
        $this->assertTrue( expAiPublisher::canAct( $kept, $kept->version( 1 ) ) );
        $this->assertSame( 0, expAiPublisher::settleThreads( array( array( (int) $kept->attribute( 'id' ), 1 ) ) ), 'no thread wrote it' );
    }

    public function testAThreadLeavesReviewWhenItsDraftsAreDecided(): void
    {
        $admin = $this->asUser( 'admin' );
        $this->useSettings();
        if ( !expAiThread::available() )
            $this->markTestSkipped( 'needs the thread tables' );
        $node = $this->workFolder();
        $nodeID = (int) $node->attribute( 'node_id' );
        $adminID = (int) $admin->attribute( 'contentobject_id' );
        $thread = expAiThread::create( array( 'user_id' => $adminID, 'node_id' => $nodeID, 'title' => 'XTD settle', 'state' => 'review' ) );
        $threadID = (int) $thread->attribute( 'id' );
        $writer = new expAiContentWriter();
        $drafts = array();
        foreach ( array( 'one', 'two' ) as $name )
        {
            list( $object ) = $writer->createDraft( $node, 'folder', array( 'name' => "XTD $name" ) );
            expAiDraftLog::record( array( 'contentobject_id' => (int) $object->attribute( 'id' ), 'version' => 1, 'user_id' => $adminID, 'node_id' => $nodeID,
                                          'thread_id' => $threadID, 'source' => 'vector', 'action' => 'create', 'model' => 'mock' ) );
            $drafts[] = (int) $object->attribute( 'id' );
        }
        expAiPublisher::keep( $drafts[0], 1 );
        $this->assertSame( 0, expAiPublisher::settleThreads( array( array( $drafts[0], 1 ) ) ), 'the second draft still waits' );
        $this->assertSame( 'review', expAiThread::fetchByID( $threadID )->attribute( 'state' ) );
        expAiPublisher::discard( $drafts[1], 1 );
        $this->assertSame( 1, expAiPublisher::settleThreads( array( array( $drafts[0], 1 ), array( $drafts[1], 1 ) ) ) );
        $this->assertSame( 'idle', expAiThread::fetchByID( $threadID )->attribute( 'state' ) );
        $this->assertSame( 0, expAiPublisher::settleThreads( array( array( $drafts[0], 1 ) ) ), 'a thread that is not in review is left alone' );
    }

    public function testFieldActionsFillAndLabels(): void
    {
        $admin = $this->asUser( 'admin' );
        $config = $this->useMockProvider( array( 'ToolSettings' => array( 'WritableDatatypes' => array( 'ezstring', 'eztext', 'ezxmltext', 'ezkeyword', 'ezboolean' ) ) ) );
        $node = $this->workFolder();
        $writer = new expAiContentWriter( $config );
        list( $object, $version ) = $writer->createDraft( $node, 'folder', array( 'name' => 'XTD field test', 'short_description' => '<p>Short</p>' ) );
        $objectID = (int) $object->attribute( 'id' );
        $nameAttribute = $object->fetchDataMap( 1 )['name'];
        $language = $version->initialLanguageCode();
        $form = array( 'object_id' => $objectID, 'version' => 1, 'language' => $language, 'attribute_id' => (int) $nameAttribute->attribute( 'id' ),
                       'action' => 'rewrite', 'value' => 'XTD field test', 'instruction' => 'make it friendlier', 'target_language' => '',
                       'context' => json_encode( array( 'name' => 'XTD field test', 'tags' => array( 'not', 'scalar' ) ) ) );

        $this->post( $form );
        $field = expAiServerFunctions::field( array() );
        $this->assertArrayNotHasKey( 'error', $field, json_encode( $field ) );
        $this->assertSame( 'rewrite', $field['action'] );
        $this->assertSame( 'ezstring', $field['datatype'] );
        $this->assertIsString( $field['value'] );
        $this->assertNull( $field['fallback'] );
        $this->assertGreaterThan( 0, $field['record']['usage_id'] );
        $this->assertSame( 'expai/log/(request)/' . $field['record']['usage_id'], $field['record']['log'] );
        $log = expAiDraftLog::fetchByVersion( $objectID, 1 );
        $this->assertSame( 'edit', $log->attribute( 'source' ) );
        $this->assertSame( 'rewrite: make it friendlier', $log->attribute( 'prompt_summary' ) );
        $this->assertSame( self::TEST_PROVIDER, $log->attribute( 'provider' ) );

        $fill = expAiServerFunctions::fill( array() + array( 'x' => 1 ) );
        $this->assertArrayHasKey( 'fields', $fill );
        $this->assertSame( (int) $log->attribute( 'id' ), (int) expAiDraftLog::fetchByVersion( $objectID, 1 )->attribute( 'id' ), 'one log row per version, updated' );
        foreach ( $fill['fields'] as $row )
            $this->assertArrayHasKey( $row['identifier'], $object->fetchDataMap( 1 ) );

        // what the edit form refuses
        $refused = function ( array $changes, $text ) use ( $form, $config )
        {
            $e = $this->expectFailure( function () use ( $form, $changes, $config ) { expAiEditForm::fieldAction( $config, $changes + $form ); } );
            $this->assertStringContainsString( $text, $e->getMessage() );
        };
        $refused( array( 'action' => 'nonsense' ), 'Unknown action' );
        $refused( array( 'attribute_id' => 0 ), 'field was not found' );
        $refused( array( 'object_id' => 999999999 ), 'draft was not found' );
        $showChildren = $object->fetchDataMap( 1 )['show_children'];
        $refused( array( 'attribute_id' => (int) $showChildren->attribute( 'id' ) ), 'not available for this field' );
        $e = $this->expectFailure( function () use ( $form, $config ) { expAiEditForm::fill( $config, array( 'instruction' => ' ' ) + $form ); } );
        $this->assertStringContainsString( 'describe what to write', $e->getMessage() );
        $e = $this->expectFailure( function () use ( $form ) { expAiEditForm::fill( $this->config( array( 'ToolSettings' => array( 'WritableDatatypes' => array( 'ezfloat' ) ) ) ), $form ); } );
        $this->assertStringContainsString( 'no fields', $e->getMessage() );
        $version->setAttribute( 'creator_id', self::TEST_USER );
        $version->store();
        $refused( array(), 'not a draft of yours' );
        $version->setAttribute( 'creator_id', (int) $admin->attribute( 'contentobject_id' ) );
        $version->store();
        $this->assertSame( array( $objectID, 1, $language ), array( (int) expAiEditForm::draft( $objectID, 1, 'xx-XX' )[0]->attribute( 'id' ),
                           (int) expAiEditForm::draft( $objectID, 1, '' )[1]->attribute( 'version' ), expAiEditForm::draft( $objectID, 1, 'xx-XX' )[2] ),
                           'an unknown language is the draft\'s own' );

        // labels: the versions written with Exponential Intelligence that the user may read
        $this->post( array( 'pairs' => $objectID . ':1,' . $objectID . ':2,0:1,bad', 'collab' => '0,999999999' ) );
        $labels = expAiServerFunctions::labels( array() );
        $this->assertSame( array( $objectID . ':1' ), array_keys( $labels['labels'] ) );
        $this->assertSame( 'field', $labels['labels'][$objectID . ':1']['action'], 'the version was first marked by the field action' );
        $this->assertSame( array(), $labels['collab'] );
        $this->assertNotSame( '', $labels['texts']['full'] );
        $this->useMockProvider( array( 'LabelSettings' => array( 'Enabled' => 'false' ) ) );
        $this->assertSame( array(), expAiServerFunctions::labels( array() )['labels'], 'labels switched off' );
        $this->assertNull( expAiEditForm::requestRecord() === null ? null : null );
    }

    public function testContentWriterWritesEachDatatype(): void
    {
        $admin = $this->asUser( 'admin' );
        $config = $this->useSettings( array( 'ToolSettings' => array( 'WritableDatatypes' => array( 'ezstring', 'ezxmltext', 'ezkeyword', 'ezboolean', 'ezdatetime', 'ezurl' ) ) ) );
        $node = $this->workFolder();
        $writer = new expAiContentWriter( $config );

        $this->assertSame( eZContentObject::defaultLanguage(), $writer->defaultLanguage() );
        $this->assertStringContainsString( 'not used', $this->expectFailure( function () use ( $writer ) { $writer->defaultLanguage( 'xx-XX' ); } )->getMessage() );
        $this->assertStringContainsString( 'no content class', $this->expectFailure( function () use ( $writer, $node ) { $writer->createDraft( $node, 'no_such_class', array() ); } )->getMessage() );

        list( $object, $version, $result ) = $writer->createDraft( $node, 'folder', array(
            'name' => " A <b>new</b>\nfolder ", 'description' => '<p>Hello <a href="https://example.invalid/">world</a></p>', 'tags' => array( 'a', 'b ' ),
            'show_children' => 'yes', 'publish_date' => '2026-01-02 03:04', 'short_name' => array( 'not', 'a', 'string' ) ) );
        $this->assertSame( array( 'name', 'description', 'tags', 'show_children', 'publish_date' ), $result['written'] );
        $this->assertSame( array( 'short_name' => 'the value must be a string, number or boolean' ), $result['errors'] );
        $map = $object->fetchDataMap( 1 );
        $this->assertSame( 'A new folder', $map['name']->toString() );
        $this->assertSame( 'a, b', $map['tags']->toString() );
        $this->assertSame( '1', (string) $map['show_children']->toString() );
        $this->assertSame( strtotime( '2026-01-02 03:04' ), (int) $map['publish_date']->attribute( 'data_int' ) );
        $this->assertStringContainsString( 'world', $map['description']->attribute( 'data_text' ) );
        $this->assertSame( 'A new folder', $object->name( 1 ) );
        $this->assertSame( array(), $writer->missingRequired( $object, $version ) );

        $e = $this->expectFailure( function () use ( $writer, $object, $version ) { $writer->writeFields( $object, $version, $version->initialLanguageCode(), array( 'nope' => 1 ) ); } );
        $this->assertStringContainsString( 'Unknown field(s)', $e->getMessage() );
        $this->assertSame( 'nope', $e->arguments()['%unknown'] );
        $this->assertStringContainsString( 'name', $e->arguments()['%valid'] );
        $narrow = new expAiContentWriter( $this->config( array( 'ToolSettings' => array( 'WritableDatatypes' => array( 'ezstring', 'ezdatetime' ) ) ) ) );
        $skipped = $narrow->writeFields( $object, $version, $version->initialLanguageCode(), array( 'tags' => 'x', 'publish_date' => 'not a date at all' ) );
        $this->assertStringContainsString( 'not written by the assistant', $skipped['skipped']['tags'] );
        $this->assertStringContainsString( 'not a date', $skipped['errors']['publish_date'] );

        // a draft of the published folder; the same draft is reused
        list( $draft, $language, $created ) = $writer->draftFor( $node->object() );
        $this->assertTrue( $created );
        $this->assertSame( eZContentObjectVersion::STATUS_DRAFT, (int) $draft->attribute( 'status' ) );
        list( $again, , $createdAgain ) = $writer->draftFor( $node->object(), '', (int) $draft->attribute( 'version' ) );
        $this->assertFalse( $createdAgain );
        $this->assertSame( (int) $draft->attribute( 'version' ), (int) $again->attribute( 'version' ) );
        $this->assertStringContainsString( 'not published', $this->expectFailure( function () use ( $writer, $object ) { $writer->draftFor( $object ); } )->getMessage() );
        $draft->setAttribute( 'status', eZContentObjectVersion::STATUS_ARCHIVED );
        $this->assertStringContainsString( 'Only drafts', $this->expectFailure( function () use ( $writer, $node, $draft ) { $writer->writeFields( $node->object(), $draft, 'eng-GB', array() ); } )->getMessage() );
        $draft->setAttribute( 'status', eZContentObjectVersion::STATUS_DRAFT );
        $draft->removeThis();

        // a link: ezurl with text, and a scheme that is refused
        list( $link ) = $writer->createDraft( $node, 'link', array( 'name' => 'XTD link', 'location' => 'https://example.invalid/page | The page' ) );
        $this->assertSame( 'https://example.invalid/page|The page', $link->fetchDataMap( 1 )['location']->toString() );
        $refused = $writer->writeFields( $link, $link->version( 1 ), $link->version( 1 )->initialLanguageCode(), array( 'location' => 'javascript:alert(1)' ) );
        $this->assertSame( 'not an http(s) or mailto URL', $refused['errors']['location'] );

        // rich text that the parser takes
        $this->assertStringContainsString( '<paragraph', (string) $writer->richTextToXml( $object, '<p>One <strong>two</strong></p>' ) );
        list( $nameless, $namelessVersion ) = $writer->createDraft( $node, 'folder', array() );
        $namelessName = $nameless->fetchDataMap( 1 )['name'];
        $namelessName->fromString( '' );
        $namelessName->store();
        $this->assertSame( array( 'Name' ), $writer->missingRequired( $nameless, $namelessVersion ) );
    }

    public function testWriteValueOfEveryOtherDatatype(): void
    {
        $this->requireDatabase();
        $this->inTransaction();
        $writer = new expAiContentWriter( $this->config() );
        $object = new eZContentObject( array( 'id' => 0 ) );
        $write = function ( $datatype, $value, $max = 0 ) use ( $writer, $object )
        {
            $attribute = new expAiCoreTestFakeAttribute( $datatype, $max );
            $result = self::invoke( $writer, 'writeValue', array( $object, $attribute, $datatype, $value ) );
            return array( $result, $attribute );
        };
        $this->assertSame( 'longer than the maximum of 3 characters', $write( 'ezstring', 'four', 3 )[0] );
        list( $ok, $attribute ) = $write( 'eztext', "<p>One</p><p>Two</p>" );
        $this->assertTrue( $ok );
        $this->assertSame( "One\n\nTwo", $attribute->attribute( 'data_text' ) );
        $this->assertSame( 1, $attribute->stored );
        $this->assertSame( 'not an integer', $write( 'ezinteger', '1.5' )[0] );
        list( $ok, $attribute ) = $write( 'ezinteger', '42' );
        $this->assertTrue( $ok );
        $this->assertSame( 42, (int) $attribute->attribute( 'data_int' ) );
        $this->assertSame( 'not a number', $write( 'ezfloat', 'pi' )[0] );
        $this->assertTrue( $write( 'ezfloat', '3.25' )[0] );
        $this->assertTrue( $write( 'ezdate', '1700000000' )[0] );
        $this->assertSame( 'not an e-mail address', $write( 'ezemail', 'nobody' )[0] );
        list( $ok, $attribute ) = $write( 'ezemail', ' someone@example.invalid ' );
        $this->assertTrue( $ok );
        $this->assertSame( 'someone@example.invalid', $attribute->attribute( 'data_text' ) );
        list( $ok, $attribute ) = $write( 'ezboolean', false );
        $this->assertSame( 0, (int) $attribute->attribute( 'data_int' ) );
        $this->assertSame( 'the value was refused by the datatype', $write( 'ezxtdnosuchtype', 'x' )[0] );
    }

    public function testContentReaderValuesClassesAndChildren(): void
    {
        $this->asUser( 'admin' );
        $config = $this->useSettings( array( 'ToolSettings' => array( 'FieldValueMaxLength' => '100' ) ) );
        $reader = new expAiContentReader( $config );
        $node = $this->workFolder();
        $this->publish( $node->attribute( 'node_id' ), array( 'name' => 'XTD child' ), 'folder' );
        $this->publish( $node->attribute( 'node_id' ), array( 'name' => 'XTD link child', 'location' => 'https://example.invalid/' ), 'link' );

        $this->assertNull( $reader->policy() );
        $this->assertStringContainsString( 'does not exist', $this->expectFailure( function () use ( $reader ) { $reader->readableNode( 999999999 ); } )->getMessage() );
        $summary = $reader->nodeSummary( $reader->readableNode( $node->attribute( 'node_id' ) ) );
        $this->assertSame( 2, $summary['children_count'] );
        $this->assertTrue( $summary['hidden'] );
        $this->assertSame( 'folder', $summary['class_identifier'] );
        $this->assertSame( array( 'link' ), array_column( $reader->children( $node, 10, 0, 'link' ), 'class_identifier' ) );
        $this->assertCount( 1, $reader->children( $node, 1, 1 ) );
        $short = $reader->shortNode( $node );
        $this->assertSame( (int) $node->attribute( 'node_id' ), $short['node_id'] );
        $this->assertSame( date( 'Y-m-d' ), $short['modified'] );

        $definition = $reader->classDefinition( 'folder' );
        $this->assertSame( 'folder', $definition['identifier'] );
        $this->assertTrue( $definition['is_container'] );
        $byIdentifier = array_column( $definition['attributes'], null, 'identifier' );
        $this->assertSame( 'one line of plain text', $byIdentifier['name']['format'] );
        $this->assertTrue( $byIdentifier['name']['required'] );
        $this->assertSame( 'not writable by the assistant', $reader->formatHint( 'ezimage' ) );
        $this->assertStringContainsString( 'no content class', $this->expectFailure( function () use ( $reader ) { $reader->classDefinition( 'no_such_class' ); } )->getMessage() );
        $this->assertContains( 'folder', array_column( $reader->creatableClasses( $node ), 'identifier' ) );

        // values of each datatype
        $value = function ( $datatype, array $data ) use ( $reader )
        {
            return $reader->value( new expAiCoreTestFakeAttribute( $datatype, 0, $data, true ) );
        };
        $this->assertNull( $reader->value( new expAiCoreTestFakeAttribute( 'ezstring', 0, array(), false ) ), 'no content, no value' );
        $this->assertTrue( $value( 'ezboolean', array( 'data_int' => 1 ) ) );
        $this->assertSame( 7, $value( 'ezinteger', array( 'data_int' => 7 ) ) );
        $this->assertSame( '2023-11-14', $value( 'ezdate', array( 'data_int' => 1700000000 ) ) );
        $this->assertSame( date( 'c', 1700000000 ), $value( 'ezdatetime', array( 'data_int' => 1700000000 ) ) );
        $this->assertSame( '[file]', $value( 'ezbinaryfile', array() ) );
        $this->assertSame( '[account data]', $value( 'ezuser', array() ) );
        $this->assertSame( '<p>Rich</p>', $value( 'ezxmltext', array( 'data_text' => '<section><paragraph>Rich</paragraph></section>' ) ) );
        $this->assertSame( 100, mb_strlen( $value( 'ezstring', array( 'data_text' => str_repeat( 'x', 500 ) ) ), 'UTF-8' ), 'values are cut' );
        $related = $value( 'ezobjectrelation', array( 'data_int' => (int) $node->attribute( 'contentobject_id' ) ) );
        $this->assertStringContainsString( '(object ' . (int) $node->attribute( 'contentobject_id' ) . ')', $related );

        $fields = array_column( $reader->fields( $node->object() ), null, 'identifier' );
        $this->assertSame( 'ezstring', $fields['name']['datatype'] );
        $this->assertStringStartsWith( 'expai unit test core', $fields['name']['value'] );

        // the data policy keeps objects away: children are counted, a node is refused
        $policy = new expAiCoreTestRefusingPolicy( $config );
        $reader->setPolicy( $policy );
        $this->assertSame( $policy, $reader->policy() );
        $this->assertSame( array(), $reader->children( $node, 10 ) );
        $this->assertSame( 2, $reader->withheld );
        $this->assertSame( 'kept away by the data policy', $reader->shortNode( $node )['withheld'] );
        $this->assertFalse( $reader->allowed( $node->object() ) );
        $this->assertStringContainsString( 'policy', $this->expectFailure( function () use ( $reader, $node ) { $reader->readableNode( $node->attribute( 'node_id' ) ); } )->getMessage() );
    }
}

/** An attribute that is not stored: its datatype and class attribute are what the test says */
class expAiCoreTestFakeAttribute extends eZContentObjectAttribute
{
    public $stored = 0;
    private $classAttribute;
    private $content;

    public function __construct( $datatype, $max = 0, array $data = array(), $content = true )
    {
        parent::__construct( $data + array( 'id' => 0, 'contentobject_id' => 0, 'version' => 1, 'data_type_string' => $datatype,
                                            'data_text' => '', 'data_int' => 0, 'data_float' => 0, 'sort_key_int' => 0, 'sort_key_string' => '',
                                            'language_code' => 'eng-GB', 'contentclassattribute_id' => 0 ) );
        $this->classAttribute = new eZContentClassAttribute( array( 'data_int1' => $max, 'data_type_string' => $datatype, 'name' => 'XTD', 'is_required' => 0 ) );
        $this->content = $content;
    }

    public function contentClassAttribute()
    {
        return $this->classAttribute;
    }

    public function store( $fieldFilters = null )
    {
        ++$this->stored;
    }

    public function hasContent()
    {
        return $this->content;
    }

    public function toString()
    {
        $type = $this->attribute( 'data_type_string' );
        if ( $type === 'ezobjectrelation' )
            return (string) $this->attribute( 'data_int' );
        if ( $type === 'ezbinaryfile' || $type === 'ezuser' )
            return '';
        return (string) $this->attribute( 'data_text' );
    }
}

/** A data policy that keeps every object away */
class expAiCoreTestRefusingPolicy extends expAiDataPolicy
{
    public function __construct( expAiConfig $config )
    {
        parent::__construct( $config, array() );
    }

    public function allowsObject( eZContentObject $object )
    {
        return false;
    }

    public function assertObject( eZContentObject $object )
    {
        throw new expAiException( 'The data policy keeps this object away from the provider.' );
    }
}
