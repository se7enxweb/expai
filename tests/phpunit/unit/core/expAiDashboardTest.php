<?php
/**
 * expAiDashboard, expAiLinks, expAiFunctionCollection, expAiRunWatch, expAiPackageDraftListener and
 * expAiApprovalCheck: what the dashboard shows for the current user, on a hidden folder of the test (removed with
 * everything below it, also from the trash) and rows of the test that are removed again.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiDashboardTest extends expAiCoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->removeTestRows();
        $this->keepSession();
        if ( class_exists( 'ezxFormToken' ) )
            ezxFormToken::setIsEnabled( false );
    }

    public function testMyDraftsWithTheirLinks(): void
    {
        $admin = $this->asUser( 'admin' );
        $adminID = (int) $admin->attribute( 'contentobject_id' );
        $this->useSettings( array( 'UsageSettings' => array( 'LogRequests' => 'enabled' ) ) );
        $node = $this->workFolder();
        $nodeID = (int) $node->attribute( 'node_id' );
        list( $draft ) = ( new expAiContentWriter() )->createDraft( $node, 'folder', array( 'name' => 'XTD dashboard draft' ) );
        $gone = $this->draftLog( array( 'contentobject_id' => 999999999, 'version' => 1, 'user_id' => $adminID, 'node_id' => $nodeID, 'created' => time() - 5 ) );
        $mine = $this->draftLog( array( 'contentobject_id' => (int) $draft->attribute( 'id' ), 'version' => 1, 'user_id' => $adminID, 'node_id' => $nodeID,
                                        'parent_node_id' => $nodeID, 'prompt_summary' => 'Write about spring', 'source' => 'panel', 'model' => 'mock' ) );

        $rows = array_column( expAiDashboard::myDrafts( 0, 5 ), null, 'key' );
        $row = $rows[$draft->attribute( 'id' ) . ':1'];
        $this->assertSame( 'panel', $row['source'] );
        $this->assertSame( 'Write about spring', $row['prompt_summary'] );
        $this->assertSame( 'ok', $row['links']['state'] );
        $this->assertSame( 'content/versionview/' . $draft->attribute( 'id' ) . '/1/' . $draft->version( 1 )->initialLanguageCode(), $row['links']['view'] );
        $this->assertStringStartsWith( 'content/edit/' . $draft->attribute( 'id' ) . '/1/', $row['links']['edit'] );
        $this->assertSame( 'expai/dashboard/(node)/' . $nodeID . '/(again)/' . $mine->attribute( 'id' ), $row['links']['prompt'] );
        $this->assertSame( 'content/view/full/' . $nodeID . '#exp-ai', $row['links']['tab'] );
        $deleted = $rows['999999999:1'];
        $this->assertSame( 'deleted', $deleted['status'] );
        $this->assertTrue( $deleted['deleted'] );
        $this->assertSame( 'deleted', $deleted['links']['state'] );
        $this->assertGreaterThanOrEqual( 2, expAiDashboard::myDraftCount() );

        $collection = new expAiFunctionCollection();
        $this->assertSame( expAiDashboard::myDraftCount(), $collection->fetchMyDraftCount()['result'] );
        $this->assertCount( 1, $collection->fetchMyDrafts( -3, 1 )['result'] );
        $this->assertIsArray( $collection->fetchWaitingForMyApproval()['result'] );
        $models = $collection->fetchModels()['result'];
        $this->assertSame( $models['default'], expAiConfig::instance()->model() );
        foreach ( array( 'allowed', 'groups', 'current', 'choice', 'refused', 'slow', 'placement', 'pricing', 'states', 'manage' ) as $key )
            $this->assertArrayHasKey( $key, $models );

        // without the policy: nothing
        eZUser::setCurrentlyLoggedInUser( eZUser::fetch( eZUser::anonymousId() ), eZUser::anonymousId(), eZUser::NO_SESSION_REGENERATE );
        $this->assertSame( array(), $collection->fetchMyDrafts( 0, 10 )['result'] );
        $this->assertSame( 0, $collection->fetchMyDraftCount()['result'] );
        $this->assertSame( array(), $collection->fetchWaitingForMyApproval()['result'] );
    }

    public function testLinksOfEveryState(): void
    {
        $admin = $this->asUser( 'admin' );
        $this->useSettings( array( 'UsageSettings' => array( 'LogRequests' => 'disabled' ) ) );
        $node = $this->workFolder();
        $nodeID = (int) $node->attribute( 'node_id' );
        $this->assertSame( 'deleted', expAiLinks::build( 999999999 )['state'] );
        $this->assertSame( 'deleted', expAiLinks::build( 0, 0, '', 999999999 )['state'] );

        $links = expAiLinks::build( 0, 0, '', $nodeID, 55 );
        $this->assertSame( 'ok', $links['state'] );
        $this->assertSame( 'content/view/full/' . $nodeID, $links['view'] );
        $this->assertStringStartsWith( 'content/edit/' . $node->attribute( 'contentobject_id' ) . '/f/', $links['edit'] );
        $this->assertSame( 'expai/dashboard/(node)/' . $nodeID, $links['prompt'], 'no "again" while requests are not logged' );

        $child = $this->publish( $nodeID, array( 'name' => 'XTD trashed' ), 'folder' );
        eZContentObjectTreeNode::removeSubtrees( array( (int) $child->attribute( 'main_node_id' ) ), true );
        $this->assertSame( 'trash', expAiLinks::build( (int) $child->attribute( 'id' ) )['state'] );

        // anonymous: may at most read it, never edit it or use the assistant there
        eZUser::setCurrentlyLoggedInUser( eZUser::fetch( eZUser::anonymousId() ), eZUser::anonymousId(), eZUser::NO_SESSION_REGENERATE );
        $none = expAiLinks::build( (int) $node->attribute( 'contentobject_id' ), 1, '', $nodeID );
        $this->assertSame( array( '', '', '' ), array( $none['edit'], $none['prompt'], $none['tab'] ) );
    }

    public function testLocationApprovalAndStatus(): void
    {
        $this->asUser( 'admin' );
        $this->useSettings( array( 'DashboardSettings' => array( 'DefaultNodeID' => '' ) ) );
        $node = $this->workFolder();
        $chosen = expAiDashboard::defaultLocation( (int) $node->attribute( 'node_id' ) );
        $this->assertTrue( $chosen['usable'] );
        $this->assertSame( '', $chosen['reason'] );
        $this->assertSame( (string) $node->attribute( 'name' ), $chosen['name'] );
        $this->assertSame( array( 'node_id' => 999999999, 'name' => '', 'usable' => false, 'reason' => 'read' ), expAiDashboard::defaultLocation( 999999999 ) );
        $this->assertSame( (int) eZINI::instance( 'content.ini' )->variable( 'NodeSettings', 'RootNode' ), expAiDashboard::defaultLocation()['node_id'] );
        $this->useSettings( array( 'DashboardSettings' => array( 'DefaultNodeID' => (string) $node->attribute( 'node_id' ) ) ) );
        $this->assertSame( (int) $node->attribute( 'node_id' ), expAiDashboard::defaultLocation()['node_id'] );

        $this->assertIsBool( expAiDashboard::approvalWorkflowExists() );
        $this->assertIsArray( expAiDashboard::waitingForMyApproval( 5 ) );
        $this->draftLog( array( 'user_id' => (int) eZUser::currentUserID(), 'contentobject_id' => 0, 'created' => time() ) );
        $status = expAiDashboard::status();
        $this->assertFalse( $status['enabled'], 'the shipped settings: switched off' );
        $this->assertFalse( $status['configured'] );
        $this->assertSame( 'anthropic', $status['provider'] );
        $this->assertSame( 'yes', $status['create'] );
        $this->assertTrue( $status['tables'] );
        $this->assertGreaterThanOrEqual( 1, $status['recent'] );
        $this->assertIsArray( $status['placement'] );
        $this->assertArrayNotHasKey( 'key', $status );
        $this->assertStringNotContainsString( 'ApiKey', json_encode( $status ) );
    }

    public function testRunWatch(): void
    {
        $admin = $this->asUser( 'admin' );
        $adminID = (int) $admin->attribute( 'contentobject_id' );
        $this->useSettings();
        $node = $this->workFolder();
        $nodeID = (int) $node->attribute( 'node_id' );

        $this->assertNull( expAiRunWatch::describe( $adminID, null ), 'nothing remembered' );
        expAiRunWatch::remember( $nodeID, time() - expAiRunWatch::KEEP - 1 );
        $this->assertNull( expAiRunWatch::remembered(), 'too old' );
        $this->assertNull( expAiRunWatch::describe( $adminID, array( 'node_id' => 999999999, 'since' => time() ) ) );
        expAiRunWatch::remember( $nodeID );
        $watch = expAiRunWatch::remembered();
        $this->assertSame( $nodeID, $watch['node_id'] );
        $state = expAiRunWatch::describe( $adminID );
        $this->assertSame( 'unsent', $state['state'] );
        $this->assertTrue( $state['active'] );
        $this->assertSame( 'content/view/full/' . $nodeID . '#exp-ai', $state['links']['panel'] );
        ob_start();
        $html = expAiRunWatch::render( $state );
        $printed = ob_get_clean();
        // the first template of a process loads every extension's template autoloads; one of another extension
        // (recaptcha) prints the newline after its closing tag, so only whitespace is allowed here
        $this->assertSame( '', trim( $printed ), 'render() returns the HTML, prints nothing' );
        $this->assertNotSame( '', $html );

        // the thread the request became
        if ( expAiThread::available() )
        {
            $conversation = expAiConversation::start( $adminID, $nodeID );
            $thread = expAiThread::forPanelConversation( $conversation, $nodeID, 'anthropic.claude-sonnet-5-5' );
            $this->assertInstanceOf( 'expAiThread', $thread );
            $thread->setAttribute( 'requests', 1 );
            $thread->setAttribute( 'modified', time() + 1 );
            $thread->store();
            $state = expAiRunWatch::describe( $adminID, $watch );
            $this->assertSame( (int) $thread->attribute( 'id' ), $state['thread']['id'] );
            $this->assertSame( 'expai/log/(thread)/' . $thread->attribute( 'id' ), $state['links']['log'] );
            $this->assertStringStartsWith( 'expai/vector/(thread)/', $state['links']['vector'] );
            $this->assertSame( in_array( $state['state'], expAiRunWatch::ACTIVE, true ), $state['active'] );
        }

        $this->post( array(), 'GET' );
        $this->assertStringContainsString( 'POST', $this->expectFailure( function () { expAiRunWatch::state( array() ); } )->getMessage() );
        $this->post( array() );
        if ( class_exists( 'ezxFormToken' ) )
        {
            ezxFormToken::setIsEnabled( true );
            $this->assertStringContainsString( 'form token', $this->expectFailure( function () { expAiRunWatch::state( array() ); } )->getMessage() );
            ezxFormToken::setIsEnabled( false );
        }
        ob_start();
        $answer = expAiRunWatch::state( array() );
        $this->assertSame( '', ob_get_clean(), 'state() prints nothing' );
        $this->assertSame( 4000, $answer['poll'] );
        $this->assertNotSame( '', $answer['html'] );
        eZUser::setCurrentlyLoggedInUser( eZUser::fetch( eZUser::anonymousId() ), eZUser::anonymousId(), eZUser::NO_SESSION_REGENERATE );
        $this->assertStringContainsString( 'do not have access', $this->expectFailure( function () { expAiRunWatch::state( array() ); } )->getMessage() );
    }

    public function testPackageDraftsAreLogged(): void
    {
        $admin = $this->asUser( 'admin' );
        $this->useSettings();
        $node = $this->workFolder();
        list( $draft ) = ( new expAiContentWriter() )->createDraft( $node, 'folder', array( 'name' => 'XTD package draft' ) );
        $objectID = (int) $draft->attribute( 'id' );
        $result = array( 'drafts' => array( array( 'object_id' => $objectID, 'version' => 1 ), array( 'object_id' => 0, 'version' => 1 ),
                                            array( 'object_id' => 999999999, 'version' => 1 ) ) );

        $plain = new eZPackage( array( 'name' => 'xtd-plain' ) );
        $this->assertSame( 0, expAiPackageDraftListener::installed( $plain, $result ), 'a package not written with Exponential Intelligence' );
        $this->assertSame( 0, expAiPackageDraftListener::installed( 'not a package', $result ) );

        $package = new eZPackage( array( 'name' => 'xtd-ai' ) );
        $package->setMetadata( expAiPackageDraftListener::META_AI_GENERATED, 'true' );
        $package->setMetadata( expAiPackageDraftListener::META_MODELS, ' claude-sonnet-5-5 , other ' );
        $this->assertSame( 1, expAiPackageDraftListener::installed( $package, $result, array( 'user_id' => self::TEST_USER, 'job' => 'job-uuid' ) ) );
        $log = expAiDraftLog::fetchByVersion( $objectID, 1 );
        $this->assertSame( 'package', $log->attribute( 'source' ) );
        $this->assertSame( 'claude-sonnet-5-5', $log->attribute( 'model' ) );
        $this->assertSame( 'package xtd-ai', $log->attribute( 'prompt_summary' ) );
        $this->assertSame( self::TEST_USER, (int) $log->attribute( 'user_id' ) );
        $this->assertSame( 'job-uuid', $log->attribute( 'job_uuid' ) );
        $this->assertSame( 0, expAiPackageDraftListener::installed( $package, $result ), 'a version already in the log is not logged twice' );
    }

    public function testApprovalCheck(): void
    {
        $admin = $this->asUser( 'admin' );
        $node = $this->workFolder();
        $object = $node->object();
        $version = $object->currentVersion();
        $this->assertContains( expAiApprovalCheck::check( $object, $version ), array( 'direct', 'approval', 'possible' ) );

        $event = function ( array $data ) { return new eZWorkflowEvent( $data + array( 'id' => 0, 'version' => 0, 'workflow_id' => 0, 'workflow_type_string' => 'event_ezapprove',
            'data_int1' => 0, 'data_int2' => 0, 'data_int3' => 0, 'data_text1' => '-1', 'data_text2' => '', 'data_text3' => '', 'data_text4' => '' ) ); };
        $applies = function ( array $data ) use ( $event, $object, $version, $admin ) { return expAiApprovalCheck::approveApplies( $event( $data ), $object, $version, $admin ); };
        $this->assertTrue( $applies( array() ), 'every section, no exception' );
        $this->assertFalse( $applies( array( 'data_int3' => 2 ) ), 'later versions only, this is version 1' );
        $this->assertTrue( $applies( array( 'data_int3' => 1 ) ), 'first versions only' );
        $this->assertFalse( $applies( array( 'data_text1' => '987654' ) ), 'another section' );
        $this->assertFalse( $applies( array( 'data_text2' => (string) $admin->attribute( 'contentobject_id' ) ) ), 'the user is excluded' );
        $this->assertFalse( $applies( array( 'data_text3' => (string) $admin->id() ) ), 'the user approves himself' );
        $groups = (array) $admin->attribute( 'groups' );
        if ( $groups )
            $this->assertFalse( $applies( array( 'data_text4' => (string) reset( $groups ) ) ), 'a group that approves' );
        $this->assertFalse( $applies( array( 'data_int2' => 1 << 30 ) ), 'another language' );
        $this->assertTrue( $applies( array( 'data_int2' => (int) $version->attribute( 'initial_language_id' ) ) ) );
        // the section of a parent counts
        $sectionOfParent = (int) $node->fetchParent()->object()->attribute( 'section_id' );
        $object->setAttribute( 'section_id', 987654 );
        $this->assertTrue( $applies( array( 'data_text1' => (string) $sectionOfParent ) ) );

        // workflows: an approve event, one behind a multiplexer, too deep
        $this->inTransaction();
        $store = function ( $workflowID, array $data ) use ( $event ) { $e = $event( $data + array( 'id' => null, 'workflow_id' => $workflowID ) ); $e->store(); return $e; };
        $store( 987650011, array( 'workflow_type_string' => 'event_ezapprove' ) );
        $store( 987650012, array( 'workflow_type_string' => 'event_ezmultiplexer', 'data_int1' => 987650011 ) );
        $store( 987650013, array( 'workflow_type_string' => 'event_ezapprove', 'data_text1' => '987654' ) );
        $object->setAttribute( 'section_id', $sectionOfParent );
        $verdict = function ( $id, $depth = 0 ) use ( $object, $version, $admin ) { return self::invoke( 'expAiApprovalCheck', 'workflowVerdict', array( $id, $object, $version, $admin, $depth ) ); };
        $this->assertSame( 'approval', $verdict( 987650011 ) );
        $this->assertSame( 'possible', $verdict( 987650012 ), 'behind a multiplexer the conditions are not evaluated' );
        $this->assertSame( 'direct', $verdict( 987650013 ) );
        $this->assertSame( 'possible', $verdict( 987650011, 4 ) );
    }
}
