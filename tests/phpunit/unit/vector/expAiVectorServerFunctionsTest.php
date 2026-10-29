<?php
/**
 * expAiVectorServerFunctions: the ezjscore functions of threads and Vector (expaivector::*). Each test runs as the
 * administrator (or a user with a role of its own) on threads it creates inside a transaction that is rolled back;
 * the content (a hidden folder with an article) is created before the transaction and removed after it. Model calls
 * go to the mock server of tests/mock/server.php only (a provider that exists in memory for the test).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectortestcase.php';

class expAiVectorServerFunctionsTest extends expAiVectorTestCase
{
    /** @var eZContentObjectTreeNode */
    private $folderNode;

    /** @var eZContentObject */
    private $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        if ( !expAiThread::available() )
            $this->markTestSkipped( 'the thread tables are missing' );
    }

    /** a folder with one article, then a transaction for the thread rows */
    private function content()
    {
        $this->asUser( 'admin' );
        $this->folderNode = $this->folder( 'expai unit test vector' );
        $this->article = $this->publish( (int) $this->folderNode->attribute( 'node_id' ),
            array( 'title' => 'Opening hours', 'intro' => self::xml( array( 'We are open.', 'Monday to Friday, 9 to 5.' ) ) ) );
        $this->setIni( 'VectorSettings', 'KickWorker', 'disabled' );
        $this->inTransaction();
    }

    /** the mock providers of tests/mock/server.php, in memory for this test */
    private function mockModels()
    {
        $mock = expAiTestMockServer::start( 'server.php' );
        $this->setIni( 'AISettings', 'Enabled', 'true' );
        $this->setIni( 'ProviderSettings', 'Providers', array( 'hfmock' ) );
        $this->setIni( 'ProviderSettings', 'DefaultModel', 'hfmock.e2e' );
        $this->setIni( 'ModelSettings', 'AllowUserModelChoice', 'disabled' );
        $this->setIni( 'McpBridge', 'Enabled', 'disabled' );
        $this->setIni( 'McpSettings', 'Servers', array() );
        foreach ( array( 'Type' => 'openai_chat', 'Flavor' => 'huggingface', 'Label' => 'Hugging Face (mock)', 'BaseURL' => $mock['url'] . '/hf/v1',
                         'ApiKey' => 'hf_mock_not_a_key', 'Region' => 'us', 'Retention' => 'unknown', 'Timeout' => 5, 'RetryAfterMax' => 1 ) as $name => $value )
            $this->setIni( 'Provider_hfmock', $name, $value );
        foreach ( array( 'Provider' => 'hfmock', 'Id' => 'mock/e2e:novita', 'Label' => 'hfmock.e2e', 'ToolUse' => 'native', 'WriteTools' => 'enabled',
                         'MaxOutput' => 800, 'WriteGate' => 'trusted' ) as $name => $value )
            $this->setIni( 'Model_hfmock.e2e', $name, $value );
        return $mock;
    }

    /** @return expAiThread scoped to the article, staged review */
    private function articleThread( array $row = array() )
    {
        return $this->thread( $row + array( 'scope' => array( 'nodes' => array( (int) $this->article->attribute( 'main_node_id' ) ) ) ) );
    }

    /* ------------------------------------------------------------------ guard */

    public function testOnlyPostRequestsAreAccepted(): void
    {
        $this->asUser( 'admin' );
        $this->post();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertRefused( static function () { expAiVectorServerFunctions::threads( array() ); }, 'only accepts POST' );
    }

    public function testAWrongFormTokenIsRefused(): void
    {
        if ( !class_exists( 'ezxFormToken' ) )
            $this->markTestSkipped( 'ezformtoken is not installed' );
        $this->asUser( 'admin' );
        $was = ezxFormToken::isEnabled();
        ezxFormToken::setIsEnabled( true );
        $this->cleanup( static function () use ( $was ) { ezxFormToken::setIsEnabled( $was ); } );
        $this->assertRefused( function () { $this->call( 'threads', array( 'ezxform_token' => 'wrong' ) ); }, 'form token is missing or wrong' );
        $this->assertRefused( function () { $this->call( 'threads', array( 'ezxform_token' => '' ) ); }, 'form token is missing or wrong' );
    }

    public function testAUserWithoutExpaiWriteIsRefused(): void
    {
        $this->userWithRole( array( array( 'content', 'read' ) ) );
        $this->assertRefused( function () { $this->call( 'threads' ); }, 'You do not have access to Exponential Intelligence' );
    }

    public function testVectorNeedsItsPolicyAndItsSwitch(): void
    {
        $this->userWithRole( array( array( 'expai', 'write' ), array( 'content', 'read' ) ) );
        // the lists work with expai/write alone
        $answer = $this->call( 'threads' );
        $this->assertSame( array(), $answer['threads'] );
        $this->assertFalse( $answer['has_vector'] );
        $this->assertRefused( function () { $this->call( 'bootstrap' ); }, 'You do not have access to Vector' );

        $this->asUser( 'admin' );
        $this->setIni( 'VectorSettings', 'Enabled', 'false' );
        $this->assertRefused( function () { $this->call( 'bootstrap' ); }, 'You do not have access to Vector' );
    }

    public function testAFunctionThatCallsAModelNeedsTheMasterSwitch(): void
    {
        $this->asUser( 'admin' );
        $this->setIni( 'AISettings', 'Enabled', 'false' );
        $this->assertRefused( function () { $this->call( 'turn', array( 'thread' => 'x' ) ); }, 'switched off' );
    }

    public function testAFunctionThatCallsAModelNeedsAUsableModel(): void
    {
        $this->asUser( 'admin' );
        $this->setIni( 'AISettings', 'Enabled', 'true' );
        // the only provider has no key
        $this->setIni( 'ProviderSettings', 'Providers', array( 'hfnokey' ) );
        $this->setIni( 'ProviderSettings', 'DefaultModel', 'hfnokey.m' );
        $this->setIni( 'Provider_hfnokey', 'Type', 'openai_chat' );
        $this->setIni( 'Provider_hfnokey', 'BaseURL', 'http://127.0.0.1:9/v1' );
        $this->setIni( 'Provider_hfnokey', 'ApiKey', '' );
        $this->setIni( 'Model_hfnokey.m', 'Provider', 'hfnokey' );
        $this->setIni( 'Model_hfnokey.m', 'Id', 'm' );
        $this->assertFalse( expAiConfig::instance()->isConfigured() );
        $this->assertRefused( function () { $this->call( 'turn', array( 'thread' => 'x' ) ); }, 'not configured correctly' );
    }

    public function testNextPollFollowsTheStateAndTheSettings(): void
    {
        $config = $this->config( array( 'VectorSettings' => array( 'PollActiveMs' => 700, 'PollWorkingMs' => 2500, 'PollIdleMs' => 20000 ) ) );
        // doubled while the host is busy: either value is right
        $this->assertContains( expAiVectorServerFunctions::nextPoll( $config, true, true ), array( 700, 1400 ) );
        $this->assertContains( expAiVectorServerFunctions::nextPoll( $config, false, true ), array( 2500, 5000 ) );
        $this->assertContains( expAiVectorServerFunctions::nextPoll( $config, false, false ), array( 20000, 40000 ) );
        // the limits of the settings hold
        $tiny = $this->config( array( 'VectorSettings' => array( 'PollActiveMs' => 1, 'PollIdleMs' => 1 ) ) );
        $this->assertContains( expAiVectorServerFunctions::nextPoll( $tiny, true, false ), array( 500, 1000 ) );
        $this->assertContains( expAiVectorServerFunctions::nextPoll( $tiny, false, false ), array( 1000, 2000 ) );
        $this->assertSame( 0, expAiVectorServerFunctions::getCacheTime( 'threads' ) );
    }

    /* ------------------------------------------------------------------ lists and one thread */

    public function testThreadsListsTheOwnThreadsByShelfSearchAndNode(): void
    {
        $this->content();
        $nodeID = (int) $this->article->attribute( 'main_node_id' );
        $a = $this->articleThread( array( 'title' => 'Alpha opening hours' ) );
        $b = $this->thread( array( 'title' => 'Beta unrelated', 'scope' => array( 'subtrees' => array( (int) $this->folderNode->attribute( 'node_id' ) ) ) ) );
        $b->setAttribute( 'shelf', 'archived' );
        $b->store();

        $uuids = function ( array $rows ) { return array_map( function ( $r ) { return $r['uuid']; }, $rows ); };
        $active = $this->call( 'threads', array( 'search' => 'Alpha opening' ) );
        $this->assertContains( $a->attribute( 'uuid' ), $uuids( $active['threads'] ) );
        $this->assertNotContains( $b->attribute( 'uuid' ), $uuids( $active['threads'] ) );
        $this->assertTrue( $active['has_vector'] );
        $this->assertGreaterThanOrEqual( 1, $active['counts']['archived'] );

        $archived = $this->call( 'threads', array( 'shelf' => 'archived', 'search' => 'Beta unrelated' ) );
        $this->assertSame( array( $b->attribute( 'uuid' ) ), $uuids( $archived['threads'] ) );

        $onNode = $this->call( 'threads', array( 'node' => $nodeID, 'limit' => 5, 'shared' => '1' ) );
        $this->assertContains( $a->attribute( 'uuid' ), $uuids( $onNode['threads'] ) );
        $this->assertSame( array(), $onNode['shared'], 'a node filter lists no shared threads' );
    }

    public function testAnotherEditorsThreadIsNotFoundAndNotListed(): void
    {
        $this->content();
        $thread = $this->articleThread( array( 'title' => 'Admin only thread' ) );
        $this->userWithRole( array( array( 'expai', 'write' ), array( 'expai', 'vector' ), array( 'content', 'read' ) ) );
        $list = $this->call( 'threads', array( 'search' => 'Admin only thread' ) );
        $this->assertSame( array(), $list['threads'] );
        foreach ( array( 'thread', 'history', 'changes', 'plan', 'checkpoints' ) as $function )
            $this->assertRefused( function () use ( $function, $thread ) { $this->call( $function, array( 'thread' => $thread->attribute( 'uuid' ) ) ); },
                                  'does not exist or you may not open it' );
        // the same answer for an id that does not exist
        $this->assertRefused( function () { $this->call( 'thread', array( 'thread' => '00000000-0000-0000-0000-000000000000' ) ); }, 'does not exist or you may not open it' );
    }

    public function testThreadClearsTheUnreadMarkAndAnswersTheDetail(): void
    {
        $this->content();
        $thread = $this->articleThread( array( 'title' => 'Unread' ) );
        $thread->addEvent( 'status', array( 'text' => 'something happened' ) );
        $thread->store();
        $thread = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertLessThan( (int) $thread->attribute( 'last_event_id' ), (int) $thread->attribute( 'seen_event_id' ) );

        ( new expAiFollowup( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'body' => 'next, please', 'position' => 1, 'delivery' => 'queue',
                                    'state' => 'waiting', 'created' => time(), 'modified' => time() ) ) )->store();

        $answer = $this->call( 'thread', array( 'thread' => $thread->attribute( 'uuid' ) ) );
        $this->assertSame( array( 'next, please' ), array_column( $answer['followups'], 'body' ) );
        foreach ( array( 'thread', 'history', 'drafts', 'followups', 'pending', 'lanes' ) as $key )
            $this->assertArrayHasKey( $key, $answer );
        $this->assertSame( $thread->attribute( 'uuid' ), $answer['thread']['uuid'] );
        $this->assertFalse( $answer['pending'] );
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $this->assertSame( (int) $fresh->attribute( 'last_event_id' ), (int) $fresh->attribute( 'seen_event_id' ) );

        $history = $this->call( 'history', array( 'thread' => $thread->attribute( 'uuid' ), 'before' => 5 ) );
        $this->assertSame( array(), $history['exchanges'] );
    }

    public function testHistoryOfAThreadWithoutConversation(): void
    {
        $this->content();
        $thread = $this->articleThread();
        eZPersistentObject::removeObject( expAiConversation::definition(), array( 'thread_id' => (int) $thread->attribute( 'id' ) ) );
        $this->assertSame( array( 'exchanges' => array(), 'has_older' => false ), $this->call( 'history', array( 'thread' => $thread->attribute( 'uuid' ) ) ) );
        $answer = $this->call( 'thread', array( 'thread' => $thread->attribute( 'uuid' ) ) );
        $this->assertSame( array(), $answer['history']['exchanges'] );
    }

    public function testEventsAfterACursorWithTheNextPoll(): void
    {
        $this->content();
        $thread = $this->articleThread();
        $first = $thread->addEvent( 'status', array( 'text' => 'one' ) );
        $thread->addEvent( 'text', array( 'text' => 'two' ) );
        $thread->store();
        $thread = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
        $uuid = (string) $thread->attribute( 'uuid' );

        $all = $this->call( 'events', array( 'threads' => $uuid . ',not-a-thread', 'open' => $uuid ) );
        $this->assertCount( 1, $all['threads'], 'a thread that does not exist is left out' );
        $this->assertSame( 'idle', $all['threads'][0]['state'] );
        $this->assertSame( (int) $thread->attribute( 'last_event_id' ), $all['threads'][0]['last_event_id'] );
        $kinds = array_map( function ( $e ) { return $e['kind']; }, $all['events'] );
        $this->assertSame( array( 'status', 'text' ), array_slice( $kinds, -2 ) );
        $this->assertSame( $uuid, $all['events'][0]['thread'] );
        $this->assertGreaterThanOrEqual( 1000, $all['next_ms'], 'nothing runs: the idle interval' );

        $firstID = $all['events'][count( $all['events'] ) - 2]['id'];
        $after = $this->call( 'events', array( 'threads' => $uuid, 'after' => json_encode( array( $uuid => $firstID ) ) ) );
        $this->assertCount( 1, $after['events'] );
        $this->assertSame( 'two', $after['events'][0]['payload']['text'] );
        $none = $this->call( 'events', array( 'threads' => $uuid, 'after' => json_encode( array( $uuid => (int) $thread->attribute( 'last_event_id' ) ) ) ) );
        $this->assertSame( array(), $none['events'] );

        // a running thread that is not the open one: the working interval
        $thread->setState( 'running' );
        $thread->touch();
        $thread->store();
        $working = $this->call( 'events', array( 'threads' => $uuid, 'open' => 'other' ) );
        $this->assertSame( 'running', $working['threads'][0]['state'] );
        $this->assertNull( $working['threads'][0]['watch'] );
        $this->assertContains( $working['next_ms'], array( expAiVectorServerFunctions::nextPoll( expAiConfig::instance(), false, true ),
                                                           2 * expAiVectorServerFunctions::nextPoll( expAiConfig::instance(), false, true ),
                                                           (int) ( expAiVectorServerFunctions::nextPoll( expAiConfig::instance(), false, true ) / 2 ) ) );
        $open = $this->call( 'events', array( 'threads' => $uuid, 'open' => $uuid ) );
        $this->assertIsArray( $open['threads'][0]['watch'] );
        $this->assertLessThanOrEqual( $working['next_ms'], $open['next_ms'], 'the open running thread polls faster' );
    }

    public function testNodesFindsByIdAndByName(): void
    {
        $this->content();
        $nodeID = (int) $this->article->attribute( 'main_node_id' );
        $byID = $this->call( 'nodes', array( 'q' => (string) $nodeID ) );
        $this->assertSame( $nodeID, $byID['nodes'][0]['id'] );
        $this->assertSame( 'Opening hours', $byID['nodes'][0]['name'] );
        $this->assertSame( 'content/view/full/' . $nodeID, $byID['nodes'][0]['url'] );
        $this->assertSame( (string) $this->folderNode->attribute( 'name' ), $byID['nodes'][0]['parent'] );

        $folderName = (string) $this->folderNode->attribute( 'name' );
        $byName = $this->call( 'nodes', array( 'q' => substr( $folderName, -12 ) ) );
        $this->assertContains( (int) $this->folderNode->attribute( 'node_id' ), array_map( function ( $n ) { return $n['id']; }, $byName['nodes'] ) );
        $this->assertSame( array(), $this->call( 'nodes', array( 'q' => 'x' ) )['nodes'], 'one character finds nothing' );
        $this->assertSame( array(), $this->call( 'nodes', array( 'q' => '999999999' ) )['nodes'] );
    }

    public function testPaletteAndKeyBindings(): void
    {
        $this->content();
        $palette = $this->call( 'palette' );
        $this->assertSame( expAiVectorStrings::palette(), $palette['strings'] );
        $this->assertSame( expAiVectorStrings::commands(), $palette['labels'] );
        $this->assertTrue( $palette['has_vector'] );
        $this->assertIsArray( $palette['scripts'] );
        $this->assertIsBool( $palette['user_overrides'] );

        $command = array_key_first( expAiVector::bindings() );
        $saved = $this->call( 'keybindings', array( 'bindings' => json_encode( array( $command => 'Alt+Shift+KeyJ' ) ) ) );
        $this->assertSame( 'Alt+Shift+KeyJ', is_array( $saved['bindings'][$command] ) ? $saved['bindings'][$command]['keys'] : $saved['bindings'][$command] );
        $reset = $this->call( 'keybindings', array( 'reset' => '1' ) );
        $this->assertNotSame( 'Alt+Shift+KeyJ', is_array( $reset['bindings'][$command] ) ? $reset['bindings'][$command]['keys'] : $reset['bindings'][$command] );
    }

    public function testBootstrapAnswersTheChoicesOfTheHeader(): void
    {
        $this->content();
        $this->setIni( 'VectorSettings', 'DefaultMode', 'plan' );
        $this->setIni( 'VectorSettings', 'DefaultReview', 'direct' );
        $answer = $this->call( 'bootstrap' );
        $this->assertSame( array( 'run_mode' => 'plan', 'review_policy' => 'direct' ), $answer['defaults'] );
        $this->assertSame( array( 'scope' => array( 'node', 'subtree', 'class' ), 'review' => array( 'staged', 'direct' ) ), $answer['choices'] );
        $this->assertContains( $answer['transport'], array( 'sse', 'poll' ) );
        $this->assertSame( array( 'active', 'working', 'idle' ), array_keys( $answer['poll'] ) );
        $this->assertLessThanOrEqual( $answer['poll']['idle'], $answer['poll']['active'] );
        foreach ( array( 'models', 'classes', 'languages', 'bindings', 'labels', 'lanes', 'model_choice' ) as $key )
            $this->assertArrayHasKey( $key, $answer );
    }

    /* ------------------------------------------------------------------ thread_save */

    public function testThreadSaveCreatesAThreadFromTheNode(): void
    {
        $this->content();
        $this->setIni( 'VectorSettings', 'DefaultReview', 'direct' );
        $this->setIni( 'VectorSettings', 'DefaultMode', 'write' );
        $nodeID = (int) $this->article->attribute( 'main_node_id' );
        $answer = $this->call( 'thread_save', array( 'thread' => '', 'node' => $nodeID, 'title' => '  New thread  ' ) );
        $this->assertTrue( $answer['created'] );
        $thread = expAiThread::fetchByUuid( $answer['thread']['uuid'] );
        $this->assertSame( 'New thread', (string) $thread->attribute( 'title' ) );
        $this->assertSame( array( $nodeID ), $thread->scope()['nodes'] );
        $this->assertSame( 'direct', (string) $thread->attribute( 'review_policy' ), 'one object: the default direct review' );
        $this->assertSame( 'write', (string) $thread->attribute( 'run_mode' ) );
        $this->assertTrue( $thread->isOwner( eZUser::currentUser()->attribute( 'contentobject_id' ) ) );
    }

    public function testThreadSaveMakesBulkWorkStagedAndPlanned(): void
    {
        $this->content();
        $this->setIni( 'VectorSettings', 'DefaultReview', 'direct' );
        $answer = $this->call( 'thread_save', array( 'thread' => '', 'scope' => json_encode( array( 'subtrees' => array( (int) $this->folderNode->attribute( 'node_id' ) ) ) ) ) );
        $thread = expAiThread::fetchByUuid( $answer['thread']['uuid'] );
        $this->assertSame( 'staged', (string) $thread->attribute( 'review_policy' ) );
        $this->assertSame( 'plan', (string) $thread->attribute( 'run_mode' ) );

        // changing it: direct review and write mode are refused for bulk work, with notes
        $changed = $this->call( 'thread_save', array( 'thread' => $thread->attribute( 'uuid' ), 'review_policy' => 'direct', 'run_mode' => 'write' ) );
        $this->assertCount( 2, $changed['notes'] );
        $thread = expAiThread::fetchByUuid( $thread->attribute( 'uuid' ) );
        $this->assertSame( 'staged', (string) $thread->attribute( 'review_policy' ) );
        $this->assertSame( 'plan', (string) $thread->attribute( 'run_mode' ) );
    }

    public function testThreadSaveRefusesAScopeThatCannotBeRead(): void
    {
        $this->content();
        $this->assertRefused( function () { $this->call( 'thread_save', array( 'thread' => '', 'scope' => json_encode( array( 'nodes' => array( 999999999 ) ) ) ) ); },
                              'Node 999999999 does not exist' );
        $thread = $this->articleThread();
        $this->assertRefused( function () use ( $thread ) {
            $this->call( 'thread_save', array( 'thread' => $thread->attribute( 'uuid' ), 'scope' => json_encode( array( 'classes' => array( 'no_such_class_x' ) ) ) ) ); },
            'The class "no_such_class_x" does not exist' );
    }

    public function testThreadSaveChangesTitleShelfPositionModelAndLane(): void
    {
        $this->content();
        $this->mockModels();
        $thread = $this->articleThread();
        $answer = $this->call( 'thread_save', array( 'thread' => $thread->attribute( 'uuid' ), 'title' => 'Renamed', 'shelf' => 'pinned', 'position' => '-5',
                                                     'model_alias' => 'hfmock.e2e', 'run_mode' => 'write' ) );
        $this->assertSame( array(), $answer['notes'] );
        $fresh = expAiThread::fetchByUuid( $thread->attribute( 'uuid' ) );
        $this->assertSame( 'Renamed', (string) $fresh->attribute( 'title' ) );
        $this->assertSame( 'pinned', (string) $fresh->attribute( 'shelf' ) );
        $this->assertSame( 0, (int) $fresh->attribute( 'position' ), 'the position is never negative' );
        $this->assertSame( 'hfmock.e2e', (string) $fresh->attribute( 'model_alias' ) );
        $this->assertSame( 'hfmock', (string) $fresh->attribute( 'provider' ) );
        $lane = $answer['lanes'][0]['name'];
        $this->call( 'thread_save', array( 'thread' => $thread->attribute( 'uuid' ), 'lane' => $lane, 'shelf' => 'nonsense' ) );
        $fresh = expAiThread::fetchByUuid( $thread->attribute( 'uuid' ) );
        $this->assertSame( $lane, (string) $fresh->attribute( 'lane' ) );
        $this->assertSame( 'pinned', (string) $fresh->attribute( 'shelf' ), 'an unknown shelf is ignored' );

        $this->assertRefused( function () use ( $thread ) { $this->call( 'thread_save', array( 'thread' => $thread->attribute( 'uuid' ), 'model_alias' => 'no.such.model' ) ); },
                              'This model is not available' );
        $this->assertRefused( function () use ( $thread ) { $this->call( 'thread_save', array( 'thread' => $thread->attribute( 'uuid' ), 'lane' => 'no-such-lane' ) ); },
                              'This lane is not available' );
    }

    /* ------------------------------------------------------------------ turn, stop, followup */

    public function testTurnRefusesWithoutScopeAndWithAModelThatIsGone(): void
    {
        $this->content();
        $this->mockModels();
        $empty = $this->thread( array( 'model_alias' => 'hfmock.e2e' ) );
        $this->assertRefused( function () use ( $empty ) { $this->call( 'turn', array( 'thread' => $empty->attribute( 'uuid' ), 'message' => 'Hi' ) ); }, 'Choose the scope first' );
        $gone = $this->articleThread( array( 'model_alias' => 'gone.model' ) );
        $this->assertRefused( function () use ( $gone ) { $this->call( 'turn', array( 'thread' => $gone->attribute( 'uuid' ), 'message' => 'Hi' ) ); }, 'no longer available to you' );
        $unreadable = $this->thread( array( 'model_alias' => 'hfmock.e2e', 'scope' => array( 'nodes' => array( 999999999 ) ) ) );
        $this->assertRefused( function () use ( $unreadable ) { $this->call( 'turn', array( 'thread' => $unreadable->attribute( 'uuid' ), 'message' => 'Hi' ) ); },
                              'Node 999999999 does not exist' );
        $noConversation = $this->articleThread( array( 'model_alias' => 'hfmock.e2e' ) );
        eZPersistentObject::removeObject( expAiConversation::definition(), array( 'thread_id' => (int) $noConversation->attribute( 'id' ) ) );
        $this->assertRefused( function () use ( $noConversation ) { $this->call( 'turn', array( 'thread' => $noConversation->attribute( 'uuid' ), 'message' => 'Hi' ) ); },
                              'has no conversation any more' );
    }

    public function testATurnInPlanModeReadsAndAnswersThroughTheMock(): void
    {
        $this->content();
        $this->mockModels();
        $this->setIni( 'VectorSettings', 'Background', 'never' );
        $thread = $this->articleThread( array( 'model_alias' => 'hfmock.e2e', 'run_mode' => 'plan' ) );
        $thread->setAttribute( 'shelf', 'settled' );
        $thread->store();
        $uuid = (string) $thread->attribute( 'uuid' );

        $first = $this->call( 'turn', array( 'thread' => $uuid, 'message' => 'What are the opening hours?' ) );
        $this->assertTrue( $first['read_only'] );
        $this->assertSame( 'continue', $first['status'], 'the mock looks things up first' );
        $fresh = expAiThread::fetchByUuid( $uuid );
        $this->assertSame( 'active', (string) $fresh->attribute( 'shelf' ), 'a new message makes a settled thread active again' );
        $this->assertNotEmpty( expAiCheckpoint::listFor( $fresh, 5 ), 'a checkpoint at the start of the turn' );

        $steps = 0;
        $answer = $first;
        while ( isset( $answer['status'] ) && $answer['status'] === 'continue' && $steps++ < 5 )
            $answer = $this->call( 'turn', array( 'thread' => $uuid, 'continue' => '1' ) );
        $this->assertNotSame( 'continue', $answer['status'] );
        $this->assertStringContainsString( 'I can only read here', (string) $answer['text'] );
        $this->assertSame( array(), $answer['followups'] );
        $this->assertSame( $uuid, $answer['thread']['uuid'] );
        // the usage rows of the turn belong to the thread
        $this->assertGreaterThanOrEqual( 2, (int) eZPersistentObject::count( expAiUsageLog::definition(), array( 'thread_id' => (int) $fresh->attribute( 'id' ) ) ) );
    }

    public function testBulkWorkWithoutAnApprovedPlanRunsReadOnly(): void
    {
        $this->content();
        $this->mockModels();
        $this->setIni( 'VectorSettings', 'Background', 'never' );
        $thread = $this->thread( array( 'model_alias' => 'hfmock.e2e', 'run_mode' => 'write', 'review_policy' => 'staged',
                                        'scope' => array( 'subtrees' => array( (int) $this->folderNode->attribute( 'node_id' ) ) ) ) );
        $answer = $this->call( 'turn', array( 'thread' => $thread->attribute( 'uuid' ), 'message' => 'Look at the folder.' ) );
        $this->assertTrue( $answer['read_only'], 'a subtree is bulk work: plan mode until a plan is approved' );
    }

    public function testATurnOnASlowModelIsQueued(): void
    {
        $this->content();
        $this->mockModels();
        $this->setIni( 'VectorSettings', 'Background', 'always' );
        if ( !expAiThreadRunner::queueAvailable() )
            $this->markTestSkipped( 'needs the job queue (expqueue)' );
        $thread = $this->articleThread( array( 'model_alias' => 'hfmock.e2e', 'run_mode' => 'plan' ) );
        $this->assertRefused( function () use ( $thread ) { $this->call( 'turn', array( 'thread' => $thread->attribute( 'uuid' ), 'continue' => '1' ) ); }, 'Please write a message' );
        $answer = $this->call( 'turn', array( 'thread' => $thread->attribute( 'uuid' ), 'message' => 'Summarize it.' ) );
        $this->assertSame( 'queued', $answer['status'] );
        $this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $answer['job'] );
        $this->assertSame( 'queued', expAiThread::fetchByUuid( $thread->attribute( 'uuid' ) )->attribute( 'state' ) );

        // stop: the job is cancelled, the thread and its lead run say stopped
        $stopped = $this->call( 'stop', array( 'thread' => $thread->attribute( 'uuid' ) ) );
        $this->assertSame( 'stopped', $stopped['thread']['state'] );
        $fresh = expAiThread::fetchByUuid( $thread->attribute( 'uuid' ) );
        $this->assertSame( 'stopped', (string) $fresh->leadRun()->attribute( 'state' ) );
        $job = expQueueJob::fetchByUUID( $answer['job'] );
        $this->assertNotContains( $job ? (string) $job->attribute( 'state' ) : 'cancelled', array( 'queued', 'running' ) );
    }

    public function testStopOfAnIdleThreadChangesNothing(): void
    {
        $this->content();
        $thread = $this->articleThread();
        $answer = $this->call( 'stop', array( 'thread' => $thread->attribute( 'uuid' ) ) );
        $this->assertSame( 'idle', $answer['thread']['state'] );
    }

    public function testFollowupsAreQueuedTakenAndDropped(): void
    {
        $this->content();
        $thread = $this->articleThread();
        $uuid = (string) $thread->attribute( 'uuid' );
        $this->assertRefused( function () use ( $uuid ) { $this->call( 'followup', array( 'thread' => $uuid, 'op' => 'add', 'body' => '   ' ) ); }, 'Please write a message' );
        $this->setIni( 'AssistantSettings', 'MaxMessageLength', 10 );
        $this->assertRefused( function () use ( $uuid ) { $this->call( 'followup', array( 'thread' => $uuid, 'op' => 'add', 'body' => str_repeat( 'x', 11 ) ) ); },
                              'at most 10 characters' );

        $one = $this->call( 'followup', array( 'thread' => $uuid, 'op' => 'add', 'body' => 'first' ) );
        $two = $this->call( 'followup', array( 'thread' => $uuid, 'op' => 'add', 'body' => 'second', 'delivery' => 'steer' ) );
        $this->assertSame( array( 'first', 'second' ), array_map( function ( $f ) { return $f['body']; }, $two['followups'] ) );
        $this->assertSame( array( 'queue', 'steer' ), array_map( function ( $f ) { return $f['delivery']; }, $two['followups'] ) );
        $this->assertSame( 2, $two['followups'][1]['position'] );
        $this->assertNull( $one['taken'] );

        $taken = $this->call( 'followup', array( 'thread' => $uuid, 'op' => 'take' ) );
        $this->assertSame( 'first', $taken['taken']['body'] );
        $this->assertCount( 1, $taken['followups'] );
        $dropped = $this->call( 'followup', array( 'thread' => $uuid, 'op' => 'drop', 'id' => $taken['followups'][0]['id'] ) );
        $this->assertSame( array(), $dropped['followups'] );
        $this->assertNull( $dropped['taken'] );
        $this->assertSame( array(), $this->call( 'followup', array( 'thread' => $uuid ) )['followups'] );
        // events of the thread name the additions
        $kinds = array_map( function ( $e ) { return $e->attribute( 'kind' ); }, expAiThreadEvent::fetchAfter( (int) $thread->attribute( 'id' ) ) );
        $this->assertSame( 2, count( array_keys( $kinds, 'followup', true ) ) );
    }

    public function testAtMostTwentyFollowupsWait(): void
    {
        $this->content();
        $thread = $this->articleThread();
        for ( $i = 0; $i < 20; $i++ )
            ( new expAiFollowup( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'body' => "n$i", 'position' => $i + 1, 'delivery' => 'queue',
                                        'state' => 'waiting', 'created' => time(), 'modified' => time() ) ) )->store();
        $this->assertRefused( function () use ( $thread ) { $this->call( 'followup', array( 'thread' => $thread->attribute( 'uuid' ), 'op' => 'add', 'body' => 'one more' ) ); },
                              'At most 20 messages can wait' );
    }

    /* ------------------------------------------------------------------ staged review */

    /** @return array( expAiThread, expAiChange[] by field ) two staged fields of the article */
    private function stagedChanges()
    {
        $thread = $this->articleThread( array( 'review_policy' => 'staged' ) );
        $context = new expAiToolContext( expAiConfig::instance(), (int) $this->folderNode->attribute( 'node_id' ), $thread->conversation() );
        $context->staged = true;
        $result = ( new expAiToolUpdateDraft( 'update_draft' ) )->execute( array( 'object_id' => (int) $this->article->attribute( 'id' ), 'fields' => array(
            'title' => 'Opening hours (new)', 'intro' => '<p>We are open earlier.</p><p>Monday to Friday, 8 to 5.</p>' ) ), $context );
        $this->assertCount( 2, $result['change_ids'] );
        $changes = array();
        foreach ( expAiChange::fetchForThread( (int) $thread->attribute( 'id' ) ) as $change )
            $changes[(string) $change->attribute( 'attribute_identifier' )] = $change;
        return array( $thread, $changes );
    }

    public function testChangesDecideAllAndApply(): void
    {
        $this->content();
        list( $thread, $changes ) = $this->stagedChanges();
        $uuid = (string) $thread->attribute( 'uuid' );
        $list = $this->call( 'changes', array( 'thread' => $uuid ) );
        $this->assertTrue( $list['can_decide'] );
        $this->assertNotEmpty( $list['groups'] );
        $this->assertSame( 2, $list['counts']['total'] );
        $this->assertSame( 2, $list['counts']['open'] );
        $this->assertSame( 0, $list['counts']['applied'] );

        $decided = $this->call( 'change_decide', array( 'thread' => $uuid, 'all' => 'accepted' ) );
        $this->assertGreaterThanOrEqual( 2, $decided['decided'] );
        foreach ( expAiChange::fetchForThread( (int) $thread->attribute( 'id' ) ) as $change )
            $this->assertSame( 'accepted', (string) $change->attribute( 'decision' ) );

        $applied = $this->call( 'apply', array( 'thread' => $uuid ) );
        $this->assertArrayHasKey( 'result', $applied );
        $this->assertSame( 2, $applied['counts']['applied'], 'both accepted changes are written' );
        // an applied change keeps its state
        $again = $this->call( 'change_decide', array( 'thread' => $uuid, 'decisions' => json_encode( array( 'list' => array(
            array( 'change' => (int) $changes['title']->attribute( 'id' ), 'decision' => 'rejected' ) ) ) ) ) );
        $this->assertSame( 0, $again['decided'] );
        $this->assertSame( 'applied', (string) expAiChange::fetchByID( (int) $changes['title']->attribute( 'id' ) )->attribute( 'decision' ) );
        $this->assertNotEmpty( $applied['drafts'], 'the accepted changes are written into a draft' );
        $this->assertSame( (int) $this->article->attribute( 'current_version' ), (int) eZContentObject::fetch( (int) $this->article->attribute( 'id' ) )->attribute( 'current_version' ),
                           'nothing is published' );
        $labels = array_map( function ( $c ) { return $c['label']; }, expAiCheckpoint::listFor( expAiThread::fetchByUuid( $uuid ), 10 ) );
        $this->assertContains( 'Before writing accepted changes to drafts', $labels );
    }

    public function testChangeDecideOneHunkAndEditAndRestage(): void
    {
        $this->content();
        list( $thread, $changes ) = $this->stagedChanges();
        $uuid = (string) $thread->attribute( 'uuid' );
        $intro = $changes['intro'];
        $hunks = $intro->hunks();
        $answer = $this->call( 'change_decide', array( 'thread' => $uuid, 'decisions' => json_encode( array( 'list' => array(
            array( 'change' => (int) $intro->attribute( 'id' ), 'hunk' => $hunks[0]['id'], 'decision' => 'rejected' ),
            'not a decision',
            array( 'change' => (int) $changes['title']->attribute( 'id' ), 'decision' => 'accepted' ) ) ) ) ) );
        $this->assertGreaterThanOrEqual( 2, $answer['decided'] );
        $this->assertSame( 'accepted', (string) expAiChange::fetchByID( (int) $changes['title']->attribute( 'id' ) )->attribute( 'decision' ) );
        $this->assertSame( 'rejected', expAiChange::fetchByID( (int) $intro->attribute( 'id' ) )->hunks()[0]['decision'] );

        // the title is plain text: the editor rewrites the proposed side
        $title = expAiChange::fetchByID( (int) $changes['title']->attribute( 'id' ) );
        $edited = $this->call( 'change_edit', array( 'thread' => $uuid, 'change' => (int) $title->attribute( 'id' ), 'hunk' => $title->hunks()[0]['id'], 'text' => 'Opening hours (edited)' ) );
        $this->assertArrayHasKey( 'groups', $edited );
        $this->assertStringContainsString( 'Opening hours (edited)', (string) expAiChange::fetchByID( (int) $title->attribute( 'id' ) )->attribute( 'proposed' ) );
        $this->setIni( 'VectorSettings', 'MaxEditLength', 100 );
        $this->assertRefused( function () use ( $uuid, $title ) {
            $this->call( 'change_edit', array( 'thread' => $uuid, 'change' => (int) $title->attribute( 'id' ), 'hunk' => '', 'text' => str_repeat( 'y', 101 ) ) ); },
            'The text is too long' );

        $restaged = $this->call( 'change_restage', array( 'thread' => $uuid, 'change' => (int) $intro->attribute( 'id' ) ) );
        $this->assertArrayHasKey( 'counts', $restaged );

        // last: a refused list rolls back its transaction as a whole, and with it the test's own (eZDB has no
        // savepoints), so nothing of the test is read after it
        $this->assertRefused( function () use ( $uuid ) {
            $this->call( 'change_decide', array( 'thread' => $uuid, 'decisions' => json_encode( array( 'list' => array( array( 'change' => 999999999, 'decision' => 'accepted' ) ) ) ) ) ); },
            'This change does not exist any more' );
    }

    public function testRebaseSetsChangedProposalsAsideAndAsksTheModelAgain(): void
    {
        $this->content();
        list( $thread, $changes ) = $this->stagedChanges();
        $uuid = (string) $thread->attribute( 'uuid' );
        $this->assertRefused( function () use ( $uuid ) { $this->call( 'rebase', array( 'thread' => $uuid ) ); }, 'There is nothing to rebase' );
        $answer = $this->call( 'rebase', array( 'thread' => $uuid, 'changes' => json_encode( array( (int) $changes['intro']->attribute( 'id' ) ) ) ) );
        $this->assertStringContainsString( 'object ' . (int) $this->article->attribute( 'id' ), $answer['message'] );
        $this->assertStringContainsString( 'intro', $answer['message'] );
        $this->assertStringNotContainsString( 'title', substr( $answer['message'], 0, strpos( $answer['message'], '. Read' ) ) );
        $this->assertSame( 'stale', (string) expAiChange::fetchByID( (int) $changes['intro']->attribute( 'id' ) )->attribute( 'decision' ) );
        $this->assertSame( 'pending', (string) expAiChange::fetchByID( (int) $changes['title']->attribute( 'id' ) )->attribute( 'decision' ) );
    }

    /* ------------------------------------------------------------------ plan mode */

    public function testPlanOfAThreadWithoutPlan(): void
    {
        $this->content();
        $thread = $this->articleThread();
        $this->assertSame( array( 'plan' => null, 'can_decide' => false ), $this->call( 'plan', array( 'thread' => $thread->attribute( 'uuid' ) ) ) );
        $this->assertRefused( function () use ( $thread ) { $this->call( 'plan_estimate', array( 'thread' => $thread->attribute( 'uuid' ), 'plan' => 999999999 ) ); },
                              'This plan does not exist any more' );
    }

    /** @return expAiPlan proposed for the thread through the tool propose_plan */
    private function proposePlan( expAiThread $thread )
    {
        $context = new expAiToolContext( expAiConfig::instance(), (int) $this->folderNode->attribute( 'node_id' ), $thread->conversation() );
        $result = ( new expAiToolProposePlan( 'propose_plan' ) )->execute( array( 'title' => 'Update the opening hours', 'items' => array(
            array( 'action' => 'update', 'object_id' => (int) $this->article->attribute( 'id' ), 'languages' => array( (string) $this->article->attribute( 'initial_language_code' ) ),
                   'fields' => array( 'title' ), 'summary' => 'Say the new hours in the title.' ) ) ), $context );
        $this->assertArrayHasKey( 'plan_id', $result, 'the plan was proposed: ' . json_encode( $result ) );
        return expAiPlan::fetchByID( (int) $result['plan_id'] );
    }

    public function testPlanEstimateAndReject(): void
    {
        // a configured provider (the mock): plan_decide refuses on a site without one
        $this->mockModels();
        $this->content();
        $thread = $this->thread( array( 'scope' => array( 'subtrees' => array( (int) $this->folderNode->attribute( 'node_id' ) ) ), 'run_mode' => 'plan' ) );
        $plan = $this->proposePlan( $thread );
        $uuid = (string) $thread->attribute( 'uuid' );

        $shown = $this->call( 'plan', array( 'thread' => $uuid ) );
        $this->assertSame( (int) $plan->attribute( 'id' ), (int) $shown['plan']['id'] );
        $this->assertTrue( $shown['can_decide'] );
        $this->assertSame( array( 'low', 'expected', 'high', 'actual' ), array_keys( $shown['plan']['money'] ) );
        $this->assertArrayHasKey( 'ok', $shown['plan']['limits'] );
        $this->assertIsBool( $shown['plan']['cost_enabled'] );

        $estimate = $this->call( 'plan_estimate', array( 'thread' => $uuid, 'plan' => (int) $plan->attribute( 'id' ), 'selected' => json_encode( array( 0 ) ),
                                                         'summaries' => json_encode( array( 0 => 'Say the new hours in the title, briefly.' ) ) ) );
        $this->assertSame( 'proposed', $estimate['plan']['state'] );

        $this->assertRefused( function () use ( $uuid, $plan ) { $this->call( 'plan_decide', array( 'thread' => $uuid, 'plan' => (int) $plan->attribute( 'id' ), 'op' => 'maybe' ) ); },
                              'Unknown decision' );
        $rejected = $this->call( 'plan_decide', array( 'thread' => $uuid, 'plan' => (int) $plan->attribute( 'id' ), 'op' => 'reject' ) );
        $this->assertSame( 'rejected', $rejected['plan']['state'] );
        $this->assertFalse( $rejected['can_decide'] );
        $this->assertSame( array( 'rows' => array(), 'ok' => true, 'message' => '' ), $rejected['plan']['limits'], 'a decided plan touches no limits' );
        $this->assertArrayHasKey( 'thread', $rejected );
    }

    public function testPlanApproveRunsTheItems(): void
    {
        $this->content();
        $this->mockModels();
        $this->setIni( 'VectorSettings', 'ParallelRuns', 'disabled' );
        $thread = $this->thread( array( 'scope' => array( 'subtrees' => array( (int) $this->folderNode->attribute( 'node_id' ) ) ), 'run_mode' => 'plan',
                                        'model_alias' => 'hfmock.e2e' ) );
        $plan = $this->proposePlan( $thread );
        $uuid = (string) $thread->attribute( 'uuid' );
        $approved = $this->call( 'plan_decide', array( 'thread' => $uuid, 'plan' => (int) $plan->attribute( 'id' ), 'op' => 'approve', 'selected' => json_encode( array( 0 ) ) ) );
        $this->assertArrayHasKey( 'run', $approved );
        $this->assertContains( $approved['plan']['state'], array( 'running', 'approved', 'done' ) );
        $this->assertFalse( $approved['can_decide'] );
        $labels = array_map( function ( $c ) { return $c['label']; }, expAiCheckpoint::listFor( expAiThread::fetchByUuid( $uuid ), 10 ) );
        $this->assertContains( 'Before the approved plan runs', $labels );
        // stop it again: the queued item jobs are cancelled
        $this->call( 'stop', array( 'thread' => $uuid ) );
    }

    /* ------------------------------------------------------------------ switch model, checkpoints */

    public function testSwitchModelOfAThreadThatDoesNotRun(): void
    {
        $this->content();
        $this->mockModels();
        $thread = $this->articleThread( array( 'model_alias' => 'hfmock.e2e' ) );
        try
        {
            $answer = $this->call( 'switch_model', array( 'thread' => $thread->attribute( 'uuid' ), 'alias' => '' ) );
            $this->assertArrayHasKey( 'switch', $answer );
            $this->assertSame( (string) $thread->attribute( 'uuid' ), $answer['thread']['uuid'] );
        }
        catch ( expAiException $e )
        {
            // a thread without a running turn on a slow model has nothing to switch
            $this->assertNotSame( '', $e->getMessage() );
        }
    }

    public function testCheckpointsListPreviewAndRestore(): void
    {
        $this->content();
        $thread = $this->articleThread();
        $uuid = (string) $thread->attribute( 'uuid' );
        expAiCheckpoint::write( $thread, 'Before the test', expAiCheckpoint::KIND_TURN );
        $list = $this->call( 'checkpoints', array( 'thread' => $uuid, 'limit' => '500' ) );
        $this->assertTrue( $list['can_restore'] );
        $this->assertSame( 'Before the test', $list['checkpoints'][0]['label'] );
        $id = (int) $list['checkpoints'][0]['id'];

        $preview = $this->call( 'checkpoint_restore', array( 'thread' => $uuid, 'checkpoint' => $id, 'preview' => '1' ) );
        $this->assertArrayHasKey( 'preview', $preview );
        $restored = $this->call( 'checkpoint_restore', array( 'thread' => $uuid, 'checkpoint' => $id ) );
        foreach ( array( 'result', 'thread', 'drafts', 'checkpoints' ) as $key )
            $this->assertArrayHasKey( $key, $restored );
        $this->assertRefused( function () use ( $uuid ) { $this->call( 'checkpoint_restore', array( 'thread' => $uuid, 'checkpoint' => 999999999 ) ); },
                              'This checkpoint does not exist any more' );
    }
}
