<?php
/**
 * expAiVector: models, lanes, scope pickers, key bindings and how threads are presented to the browser.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectorcoretestcase.php';

class expAiVectorTest extends expAiVectorCoreTestCase
{
    public function testAllowedFollowsTheSwitchAndThePolicies(): void
    {
        $this->ini( 'VectorSettings', 'Enabled', 'true' );
        $this->assertTrue( expAiVector::allowed(), 'admin has expai/write and expai/vector' );
        $this->ini( 'VectorSettings', 'Enabled', 'false' );
        $this->assertFalse( expAiVector::allowed(), 'Vector switched off' );
    }

    public function testModelsAndModelUse(): void
    {
        $alias = $this->stubModel();
        $config = expAiConfig::instance();
        $models = expAiVector::models( $config );
        $this->assertSame( array( 'groups', 'current', 'default' ), array_keys( $models ) );
        $this->assertSame( $alias, $models['default'] );
        $this->assertTrue( expAiVector::mayUseModel( '', $config ), 'no alias: the default' );
        $this->assertTrue( expAiVector::mayUseModel( $alias, $config ) );
        $this->assertFalse( expAiVector::mayUseModel( 'nobody.nothing', $config ) );
        $this->assertSame( $alias, expAiVector::models()['default'], 'the site settings are read when no config is given' );
    }

    public function testLanesStartWithAutomaticAndAlwaysOfferStandard(): void
    {
        $alias = $this->stubModel();
        $lanes = expAiVector::lanes( $alias, expAiConfig::instance() );
        $this->assertSame( 'auto', $lanes[0]['name'] );
        $this->assertTrue( $lanes[0]['usable'] );
        $names = array_column( $lanes, 'name' );
        $this->assertContains( 'standard', $names );
        foreach ( $lanes as $lane )
        {
            $this->assertSame( array( 'name', 'label', 'usable', 'batch', 'factor' ), array_keys( $lane ) );
            $this->assertIsInt( $lane['factor'] );
        }
    }

    public function testLaneLabels(): void
    {
        foreach ( array( 'standard' => 'Standard', 'flex' => 'Flex', 'batch' => 'Batch', 'fast' => 'Fast', 'priority' => 'Priority' ) as $name => $label )
            $this->assertNotSame( '', expAiVector::laneLabel( $name ) );
        $this->assertSame( 'Standard', expAiVector::laneLabel( 'standard' ) );
        $this->assertSame( 'custom-lane', expAiVector::laneLabel( 'custom-lane' ), 'an unknown lane keeps its name' );
    }

    public function testClassesAreSortedByNameAndLanguagesListed(): void
    {
        $classes = expAiVector::classes();
        $identifiers = array_column( $classes, 'identifier' );
        $this->assertContains( 'article', $identifiers );
        $this->assertContains( 'folder', $identifiers );
        $names = array_column( $classes, 'name' );
        $sorted = $names;
        usort( $sorted, 'strcasecmp' );
        $this->assertSame( $sorted, $names );

        $languages = expAiVector::languages();
        $this->assertNotEmpty( $languages );
        $codes = array_column( $languages, 'code' );
        $this->assertContains( (string) eZContentLanguage::topPriorityLanguage()->attribute( 'locale' ), $codes );
    }

    public function testDefaultBindingsKeepWellFormedCommandsOnly(): void
    {
        $config = $this->config( array( 'KeyBindings' => array( 'Binding' => array( 'palette' => 'Mod+KeyK', 'Bad-Name' => 'KeyX', 'run' => 'Mod+Enter' ),
                                                                 'When' => array( 'palette' => 'global' ) ) ) );
        $bindings = expAiVector::defaultBindings( $config );
        $this->assertSame( array( 'palette' => array( 'keys' => 'Mod+KeyK', 'when' => 'global' ), 'run' => array( 'keys' => 'Mod+Enter', 'when' => 'vector' ) ), $bindings );
        $shipped = expAiVector::defaultBindings( $this->config() );
        $this->assertArrayHasKey( 'accept_field', $shipped );
        $this->assertSame( 'list', $shipped['accept_field']['when'] );
    }

    public function testValidKeys(): void
    {
        foreach ( array( 'Mod+KeyK', 'Shift+KeyA', 'Escape Escape', 'Ctrl+Alt+Digit1', '?', 'Mod+Enter' ) as $keys )
            $this->assertTrue( expAiVector::validKeys( $keys ), $keys );
        foreach ( array( '', 'k', 'Mod+', 'Escape Escape Escape', 'Super+KeyK', str_repeat( 'A', 41 ), 'Mod+Key K' ) as $keys )
            $this->assertFalse( expAiVector::validKeys( $keys ), "'$keys'" );
    }

    public function testUserBindingsFilterUnknownCommandsAndBadKeysAndCanBeSwitchedOff(): void
    {
        $this->inTransaction();
        eZPreferences::setValue( 'expai_keybindings', json_encode( array( 'palette' => 'Mod+KeyP', 'nocommand' => 'KeyQ', 'run' => 'not keys' ) ) );
        $this->ini( 'KeyBindings', 'UserOverrides', 'true' );
        $this->assertSame( array( 'palette' => 'Mod+KeyP' ), expAiVector::userBindings() );
        $effective = expAiVector::bindings();
        $this->assertSame( 'Mod+KeyP', $effective['palette']['keys'] );
        $this->assertNotSame( 'Mod+KeyP', $effective['palette']['default'] );
        $this->assertSame( $effective['run']['default'], $effective['run']['keys'], 'a malformed override is ignored' );

        $this->ini( 'KeyBindings', 'UserOverrides', 'false' );
        $this->assertSame( array(), expAiVector::userBindings() );
        $this->assertSame( expAiVector::bindings()['palette']['default'], expAiVector::bindings()['palette']['keys'] );
    }

    public function testSaveBindingsStoresChangesDropsDefaultsAndRefusesBadInput(): void
    {
        $this->inTransaction();
        $this->ini( 'KeyBindings', 'UserOverrides', 'true' );
        eZPreferences::setValue( 'expai_keybindings', '' );
        $defaults = expAiVector::defaultBindings();
        $result = expAiVector::saveBindings( array( 'palette' => 'Mod+KeyJ', 'run' => $defaults['run']['keys'] ) );
        $this->assertSame( 'Mod+KeyJ', $result['palette']['keys'] );
        $this->assertSame( array( 'palette' => 'Mod+KeyJ' ), json_decode( eZPreferences::value( 'expai_keybindings' ), true ), 'a binding equal to the default is not stored' );

        $result = expAiVector::saveBindings( array( 'palette' => '' ) );
        $this->assertSame( $defaults['palette']['keys'], $result['palette']['keys'], "'' resets" );
        $this->assertSame( '', (string) eZPreferences::value( 'expai_keybindings' ) );
        try
        {
            expAiVector::saveBindings( array( 'nocommand' => 'KeyQ' ) );
            $this->fail( 'an unknown command was stored' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'Unknown command: nocommand', self::text( $e ) );
        }
        try
        {
            expAiVector::saveBindings( array( 'palette' => 'Mod+' ) );
            $this->fail( 'malformed keys were stored' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'This shortcut cannot be used: Mod+', self::text( $e ) );
        }
        $this->ini( 'KeyBindings', 'UserOverrides', 'false' );
        $this->expectException( expAiException::class );
        expAiVector::saveBindings( array( 'palette' => 'Mod+KeyJ' ) );
    }

    public function testListRowOfAnUntitledThreadAndOfAStaleRun(): void
    {
        $thread = $this->thread( array( 'title' => '' ) );
        // the fixture's prefix makes a title: an untitled thread is one with an empty title
        $thread->setAttribute( 'title', '' );
        $thread->setAttribute( 'state', 'running' );
        $thread->setAttribute( 'modified', time() - 7200 );
        $thread->setAttribute( 'last_event_id', 5 );
        $thread->setAttribute( 'seen_event_id', 2 );
        $this->ini( 'ThreadSettings', 'StaleRunSeconds', '900' );
        $row = expAiVector::listRow( $thread, $this->userID );
        $this->assertTrue( $row['untitled'] );
        $this->assertSame( 'New thread', $row['title'] );
        $this->assertSame( 'stopped', $row['state'], 'a running thread without a change for longer than StaleRunSeconds shows as stopped' );
        $this->assertTrue( $row['unread'] );
        $this->assertTrue( $row['is_owner'] );
        $this->assertFalse( expAiVector::listRow( $thread, $this->userID + 100000 )['unread'], 'only the owner has unread events' );
        $this->assertSame( '', $row['progress'] );

        $thread->setAttribute( 'modified', time() );
        $thread->setAttribute( 'title', 'XTB fresh' );
        $row = expAiVector::listRow( $thread, $this->userID );
        $this->assertSame( 'running', $row['state'] );
        $this->assertSame( 'XTB fresh', $row['title'] );
        $this->assertFalse( $row['untitled'] );
    }

    public function testProgressTextCountsTheEndedRunsOfARunningPlan(): void
    {
        $thread = $this->thread( array( 'title' => 'progress' ) );
        $this->assertSame( '', expAiVector::progressText( $thread ), 'no plan' );
        $runs = array();
        foreach ( array( 'done', 'running', 'failed' ) as $i => $state )
        {
            $run = new expAiThreadRun( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'parent_run_id' => (int) $thread->attribute( 'lead_run_id' ),
                                              'plan_item' => $i, 'state' => $state, 'kind' => 'item', 'created' => time(), 'modified' => time() ) );
            $run->store();
            $runs[$i] = (int) $run->attribute( 'id' );
        }
        $plan = new expAiPlan( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'state' => 'running', 'created' => time(), 'modified' => time() ) );
        $plan->setEstimate( array( 'runs' => $runs ) );
        $plan->store();
        $thread->setAttribute( 'plan_id', (int) $plan->attribute( 'id' ) );
        $thread->setAttribute( 'state', 'idle' );
        $this->assertSame( '', expAiVector::progressText( $thread ), 'only while the thread works' );
        $thread->setAttribute( 'state', 'running' );
        $this->assertSame( '2/3', expAiVector::progressText( $thread ) );

        $plan->setEstimate( array( 'runs' => array() ) );
        $plan->store();
        $this->assertSame( '', expAiVector::progressText( $thread ), 'a plan that did not fan out' );
    }

    public function testDetailNamesTheScopeAndTheModel(): void
    {
        $article = $this->article( 'XTB detail article' );
        $nodeID = (int) $article->mainNodeID();
        $alias = $this->stubModel();
        $language = (string) $article->attribute( 'initial_language_code' );
        $thread = $this->thread( array( 'title' => 'detail', 'model_alias' => $alias, 'origin' => expAiThread::ORIGIN_PANEL, 'node_id' => $nodeID,
                                        'scope' => array( 'nodes' => array( $nodeID, 999999999 ), 'subtrees' => array( 2 ), 'classes' => array( 'article', 'noclass' ),
                                                          'languages' => array( $language, 'xxx-XX' ) ) ) );
        $detail = expAiVector::detail( $thread, $this->userID, expAiConfig::instance() );
        $this->assertSame( 'XTB detail article', $detail['scope']['nodes'][0]['name'] );
        $this->assertTrue( $detail['scope']['nodes'][0]['readable'] );
        $this->assertSame( 'article', $detail['scope']['nodes'][0]['class'] );
        $this->assertSame( array( 'id' => 999999999, 'name' => '#999999999', 'class' => '', 'readable' => false ), $detail['scope']['nodes'][1] );
        $this->assertSame( 2, $detail['scope']['subtrees'][0]['id'] );
        $this->assertSame( array( 'identifier' => 'noclass', 'name' => 'noclass' ), $detail['scope']['classes'][1], 'an unknown class keeps its identifier' );
        $this->assertSame( (string) eZContentClass::fetchByIdentifier( 'article' )->attribute( 'name' ), $detail['scope']['classes'][0]['name'] );
        $this->assertSame( array( 'code' => 'xxx-XX', 'name' => 'xxx-XX' ), $detail['scope']['languages'][1] );
        $this->assertSame( 'Stub', $detail['model_label'] );
        $this->assertTrue( $detail['model_allowed'] );
        $this->assertSame( 'content/view/full/' . $nodeID, $detail['panel_url'] );
        $this->assertSame( 'expai/vector/(thread)/' . $thread->attribute( 'uuid' ), $detail['url'] );
        $this->assertTrue( $detail['can_write'] );
        $this->assertNull( $detail['plan'] );
        $this->assertSame( '', $detail['reserved'] );
        $this->assertIsArray( $detail['runs'] );
        $this->assertIsArray( $detail['checkpoints'] );

        $thread->setAttribute( 'model_alias', 'gone.model' );
        $detail = expAiVector::detail( $thread, $this->userID, expAiConfig::instance() );
        $this->assertFalse( $detail['model_allowed'] );
        $this->assertSame( 'gone.model', $detail['model_label'] );
    }

    public function testPlanSummaryOfTheCurrentPlan(): void
    {
        $thread = $this->thread( array( 'title' => 'plan summary' ) );
        $this->assertNull( expAiVector::planSummary( $thread ) );
        $plan = new expAiPlan( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'state' => 'proposed', 'created' => time(), 'modified' => time() ) );
        $plan->setItems( array( array( 'index' => 0, 'selected' => true ), array( 'index' => 1, 'selected' => false ), array( 'index' => 2, 'selected' => true, 'refused' => 'no' ) ) );
        $plan->setEstimate( array( 'expected_micros' => 1500000 ) );
        $plan->store();
        $summary = expAiVector::planSummary( $thread );
        $this->assertSame( (int) $plan->attribute( 'id' ), $summary['id'] );
        $this->assertSame( 'proposed', $summary['state'] );
        $this->assertSame( 1, $summary['items'], 'selected and not refused' );
        $this->assertSame( expAiCost::enabled() ? expAiBudget::money( 1500000 ) : '', $summary['expected'] );
    }

    public function testChangeGroupsAndDraftsOfAThread(): void
    {
        $article = $this->article( 'XTB change groups', array( 'First sentence.', 'Second sentence.' ) );
        $folderNodeID = (int) eZContentObjectTreeNode::fetch( $article->mainNodeID() )->attribute( 'parent_node_id' );
        $alias = $this->stubModel();
        $thread = $this->thread( array( 'title' => 'changes', 'model_alias' => $alias, 'scope' => array( 'nodes' => array( (int) $article->mainNodeID() ) ) ) );
        $context = new expAiToolContext( expAiConfig::instance(), $folderNodeID, $thread->conversation() );
        $context->staged = true;
        $update = new expAiToolUpdateDraft( 'update_draft' );
        $result = $update->execute( array( 'object_id' => (int) $article->attribute( 'id' ), 'fields' => array(
            'title' => 'XTB change groups (new)', 'intro' => '<p>First sentence, changed.</p><p>Second sentence.</p>' ) ), $context );
        $this->assertCount( 2, $result['change_ids'] );
        $groups = expAiVector::changeGroups( $thread );
        $this->assertCount( 1, $groups );
        $existing = $groups[0];
        $this->assertFalse( $existing['is_new'] );
        $this->assertSame( (int) $article->attribute( 'id' ), $existing['object_id'] );
        $this->assertSame( 'XTB change groups', $existing['name'] );
        $this->assertSame( 'content/view/full/' . (int) $article->mainNodeID(), $existing['url'] );
        $this->assertFalse( $existing['conflict'] );
        $this->assertCount( 2, $existing['fields'] );
        $this->assertLessThanOrEqual( $existing['fields'][1]['position'], $existing['fields'][0]['position'], 'fields in class order' );
        $this->assertFalse( $existing['fields'][0]['base_changed'] );
        $this->assertNotSame( '', $existing['fields'][0]['name'], 'the field is named after its class attribute' );

        // a proposed new object below the folder (a thread of its own: one object per thread without a plan)
        $other = $this->thread( array( 'title' => 'new object', 'model_alias' => $alias, 'scope' => array( 'nodes' => array( $folderNodeID ) ) ) );
        $context2 = new expAiToolContext( expAiConfig::instance(), $folderNodeID, $other->conversation() );
        $context2->staged = true;
        $create = new expAiToolCreateDraft( 'create_draft' );
        $create->execute( array( 'parent_node_id' => $folderNodeID, 'class_identifier' => 'article', 'fields' => array( 'title' => 'XTB proposed article' ) ), $context2 );
        $groups = expAiVector::changeGroups( $other );
        $this->assertCount( 1, $groups );
        $this->assertTrue( $groups[0]['is_new'] );
        $this->assertSame( 'XTB proposed article', $groups[0]['name'], 'a new object is named after its proposed title' );
        $this->assertSame( '', $groups[0]['url'] );
        $this->assertNotSame( '', $groups[0]['parent_name'] );

        // the published version changes after staging: the group shows a conflict
        $published = eZContentObject::fetch( (int) $article->attribute( 'id' ) );
        eZContentFunctions::updateAndPublishObject( $published, array( 'attributes' => array( 'title' => 'XTB changed meanwhile' ) ) );
        $groups = expAiVector::changeGroups( expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) );
        $this->assertTrue( $groups[0]['conflict'] );
        $this->assertTrue( (bool) array_filter( $groups[0]['fields'], function ( $f ) { return $f['base_changed']; } ) );
    }

    public function testDraftsListTheLoggedVersionsOnceAndNeverDiscardedOnes(): void
    {
        $article = $this->article( 'XTB drafts' );
        $objectID = (int) $article->attribute( 'id' );
        $this->cleanup( static function () use ( $objectID ) { eZPersistentObject::removeObject( expAiDraftLog::definition(), array( 'contentobject_id' => $objectID ) ); } );
        $thread = $this->thread( array( 'title' => 'drafts' ) );
        $this->assertSame( array(), expAiVector::drafts( $thread ) );
        $version = (int) $article->attribute( 'current_version' );
        $row = array( 'contentobject_id' => $objectID, 'version' => $version, 'user_id' => $this->userID, 'thread_id' => (int) $thread->attribute( 'id' ),
                      'language_code' => (string) $article->attribute( 'initial_language_code' ), 'action' => 'update', 'source' => 'vector' );
        expAiDraftLog::record( $row );
        expAiDraftLog::record( $row );
        expAiDraftLog::record( array( 'version' => 999 ) + $row );
        expAiDraftLog::record( array( 'version' => $version + 50, 'status' => expAiDraftLog::STATUS_DISCARDED ) + $row );
        $drafts = expAiVector::drafts( $thread );
        $this->assertCount( 1, $drafts, 'one row per version; a version that does not exist and a discarded one are left out' );
        $this->assertSame( $objectID, $drafts[0]['object_id'] );
        $this->assertSame( $version, $drafts[0]['version'] );
        $this->assertSame( expAiDraftLog::STATUS_PUBLISHED, $drafts[0]['status'], 'the log follows the published version' );
    }
}
