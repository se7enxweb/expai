<?php
/**
 * What a conversation keeps about its window and how a change of model treats it, without a database: the objects
 * the conversation read (and the data policy of a new provider over them), the editor's words only when the policy
 * keeps the earlier content away, the drafts of an open exchange handed to the next model, the write lock while an
 * external tool's result is in the window, the scope of the write tools, the check of a call's arguments against
 * the tool's schema, and the lock of a conversation while a step runs.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

/** A tool for runTool(): records its calls, notes an object as read, can write or be external */
class expAiConversationStateTestTool extends expAiTool
{
    public $calls = array();
    public $writing = false;
    public $isExternal = false;

    public function description()
    {
        return 'test';
    }

    public function inputSchema()
    {
        return array( 'type' => 'object', 'properties' => array( 'count' => array( 'type' => 'integer' ), 'text' => array( 'type' => 'string' ) ),
                      'required' => array( 'count' ), 'additionalProperties' => false );
    }

    public function execute( array $input, expAiToolContext $context )
    {
        $this->calls[] = $input;
        $context->reader->noteRead( 4711 );
        return array( 'ok' => true );
    }

    public function writes()
    {
        return $this->writing;
    }

    public function external()
    {
        return $this->isExternal;
    }
}

/** A database that knows the two statements of expAiConversationLock, for one table in memory */
class expAiConversationStateTestDb
{
    public $rows = array();
    public $queries = array();

    public function databaseName()
    {
        return 'mysql';
    }

    public function escapeString( $text )
    {
        return addslashes( (string) $text );
    }

    public function query( $sql )
    {
        $this->queries[] = $sql;
        if ( preg_match( "/SET busy_owner = '([^']*)', busy_until = (\\d+) WHERE id = (\\d+) AND \\( busy_until < (\\d+) OR busy_owner = '([^']*)' \\)/", $sql, $m ) )
        {
            $row =& $this->rows[(int) $m[3]];
            if ( $row && ( $row['busy_until'] < (int) $m[4] || $row['busy_owner'] === $m[5] ) )
                $row = array( 'busy_owner' => $m[1], 'busy_until' => (int) $m[2] );
            return true;
        }
        if ( preg_match( "/SET busy_owner = '', busy_until = 0 WHERE id = (\\d+) AND busy_owner = '([^']*)'/", $sql, $m ) )
        {
            if ( isset( $this->rows[(int) $m[1]] ) && $this->rows[(int) $m[1]]['busy_owner'] === $m[2] )
                $this->rows[(int) $m[1]] = array( 'busy_owner' => '', 'busy_until' => 0 );
            return true;
        }
        return false;
    }

    public function arrayQuery( $sql )
    {
        if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) && isset( $this->rows[(int) $m[1]] ) )
            return array( array( 'BUSY_OWNER' => $this->rows[(int) $m[1]]['busy_owner'] ) );
        return array();
    }
}

class expAiConversationStateTest extends expAiCoreTestCase
{
    private static function conversation()
    {
        return new expAiConversation( array( 'id' => 0, 'messages' => '[]', 'user_id' => self::TEST_USER, 'node_id' => 2 ) );
    }

    public function testTheConversationKeepsTheObjectsItReadAndTheExternalFlag(): void
    {
        $conversation = self::conversation();
        $this->assertSame( array(), $conversation->readObjects() );
        $conversation->addReadObjects( array( 5, '7', 0, -1, 5 ) );
        $conversation->addReadObjects( array( 9 ) );
        $this->assertSame( array( 5, 7, 9 ), $conversation->readObjects() );
        $conversation->addReadObjects( range( 100, 100 + expAiConversation::MAX_READ_OBJECTS ) );
        $this->assertCount( expAiConversation::MAX_READ_OBJECTS, $conversation->readObjects(), 'the newest are kept' );
        $this->assertNotContains( 5, $conversation->readObjects() );
        $this->assertTrue( $conversation->contentUntracked(), 'the dropped objects are not known any more: fail closed' );
        $this->assertFalse( $conversation->externalInContext() );
        $conversation->setExternalInContext( true );
        $this->assertTrue( $conversation->externalInContext() );
        $this->assertSame( array(), $conversation->messages(), 'the messages are untouched' );
    }

    public function testTheReaderNotesWhatItLetsThrough(): void
    {
        $reader = new expAiContentReader( $this->useSettings() );
        $reader->noteRead( 12 );
        $reader->noteRead( 12 );
        $reader->noteRead( 0 );
        $this->assertSame( array( 12 ), $reader->takeRead() );
        $this->assertSame( array(), $reader->takeRead(), 'taken once' );
    }

    public function testTheDataPolicyOfANewProviderOverTheObjectsRead(): void
    {
        $config = $this->useSettings();
        $policy = new class
        {
            public function allowsObject( $object )
            {
                return $object->id !== 2;
            }
        };
        $fetch = function ( $id ) { return $id === 3 ? null : (object) array( 'id' => $id ); };
        $none = function ( $object, array $request ) { return ''; };
        $this->assertTrue( expAiModelFallback::objectsAllowed( $config, 'xtd', array(), $fetch, $policy ) );
        $this->assertTrue( expAiModelFallback::objectsAllowed( $config, 'xtd', array( 1, 4 ), $fetch, $policy, array(), $none ) );
        $this->assertFalse( expAiModelFallback::objectsAllowed( $config, 'xtd', array( 1, 3 ), $fetch, $policy, array(), $none ), 'fail closed: an object that cannot be loaded is not allowed' );
        $this->assertFalse( expAiModelFallback::objectsAllowed( $config, 'xtd', array( 1, 2 ), $fetch, $policy, array(), $none ) );
        $this->assertFalse( expAiModelFallback::objectsAllowed( $config, 'xtd', array( 1 ), $fetch, $policy ), 'an object the veto cannot check is not allowed' );
        $this->assertNull( expAiModelFallback::objectsAllow( $config, array() ) );
        $this->assertIsCallable( expAiModelFallback::objectsAllow( $config, array( 1 ) ) );
    }

    public function testTheRequestVetoOfANewProviderOverTheObjectsRead(): void
    {
        $config = $this->useSettings();
        $policy = new class
        {
            public function allowsObject( $object )
            {
                return true;
            }

            public function providerRegion()
            {
                return 'us';
            }
        };
        $fetch = function ( $id ) { return (object) array( 'id' => $id ); };
        $seen = array();
        $veto = function ( $object, array $request ) use ( &$seen )
        {
            $seen[] = $request;
            return $object->id === 7 && $request['provider'] === 'xtd' ? 'Not to this provider.' : '';
        };
        $this->assertFalse( expAiModelFallback::objectsAllowed( $config, 'xtd', array( 5, 7 ), $fetch, $policy, array( 'purpose' => 'panel' ), $veto ),
                            'the data policy lets the object through, the request veto does not' );
        $this->assertSame( array( 'purpose' => 'panel', 'provider' => 'xtd', 'region' => 'us' ), $seen[0], 'the veto hears the new provider, its region and the purpose' );
        $this->assertTrue( expAiModelFallback::objectsAllowed( $config, 'other', array( 5, 7 ), $fetch, $policy, array( 'purpose' => 'panel' ), $veto ) );
    }

    public function testTheEditorsWordsOnlyAndTheDraftsOfAnOpenExchange(): void
    {
        $history = array(
            array( 'id' => 1, 'user' => 'Read the price list', 'reply' => 'The prices are 12 and 14.', 'drafts' => array() ),
            array( 'id' => 2, 'user' => 'Write a teaser', 'reply' => 'Done: Teaser about prices', 'drafts' => array( array( 'key' => '42:3', 'name' => 'Prices 12 and 14' ) ) ),
            array( 'id' => 3, 'user' => 'Shorter', 'reply' => 'Shortened.', 'drafts' => array() ),
        );
        $messages = expAiAssistant::compactWordsOnly( $history, 2 );
        $text = json_encode( $messages );
        $this->assertStringNotContainsString( '12 and 14', $text, 'nothing of the earlier answers or draft names' );
        $this->assertStringContainsString( 'Read the price list', $messages[0]['content'][0]['text'] );
        $this->assertSame( 'Write a teaser', $messages[2]['content'][0]['text'] );
        $this->assertSame( expAiAssistant::WITHHELD_REPLY . ' [Drafts written: object:version 42:3]', $messages[3]['content'][0]['text'] );
        $this->assertSame( expAiAssistant::WITHHELD_REPLY, $messages[5]['content'][0]['text'] );
        $this->assertCount( 6, $messages );
        $roles = array_column( $messages, 'role' );
        $this->assertSame( array( 'user', 'assistant', 'user', 'assistant', 'user', 'assistant' ), $roles );

        $pending = array( 'user' => 'Write three articles', 'drafts' => array( array( 'key' => '50:1', 'name' => 'First' ), array( 'key' => '51:1', 'name' => 'Second' ) ) );
        $this->assertSame( "Write three articles\n\n[Already done for this request by the model before you: drafts written: First (object:version 50:1), Second (object:version 51:1). "
                           . 'Do not create them again; change them with update_draft where the request needs more.]', expAiAssistant::pendingText( $pending ) );
        $this->assertStringNotContainsString( 'First', expAiAssistant::pendingText( $pending, false ), 'no names when the policy keeps the content away' );
        $this->assertSame( 'Hello', expAiAssistant::pendingText( array( 'user' => 'Hello', 'drafts' => array() ) ) );
    }

    public function testTheTargetsOfAWriteAndTheSubtree(): void
    {
        $this->assertSame( array( 'nodes' => array( 5 ), 'objects' => array( 9 ) ), expAiAssistant::writeTargets( array( 'parent_node_id' => '5', 'object_id' => 9, 'fields' => array() ) ) );
        $this->assertSame( array( 'nodes' => array(), 'objects' => array() ), expAiAssistant::writeTargets( array( 'node_id' => 'x', 'object_id' => 0 ) ) );
        $this->assertTrue( expAiAssistant::pathInSubtree( '/1/2/60/61/', 60 ) );
        $this->assertTrue( expAiAssistant::pathInSubtree( '/1/2/60/', 60 ) );
        $this->assertFalse( expAiAssistant::pathInSubtree( '/1/2/600/', 60 ) );
        $this->assertFalse( expAiAssistant::pathInSubtree( '/1/2/', 0 ) );
        // an object that was never published has no location: the parent its draft is assigned to decides
        $this->assertTrue( expAiAssistant::objectInSubtree( array(), array( '/1/2/60/' ), 60 ) );
        $this->assertTrue( expAiAssistant::objectInSubtree( array(), array( '/1/2/60/70/' ), 60 ) );
        $this->assertFalse( expAiAssistant::objectInSubtree( array(), array( '/1/2/61/' ), 60 ) );
        $this->assertTrue( expAiAssistant::objectInSubtree( array( '/1/2/5/', '/1/2/60/9/' ), array(), 60 ), 'one location inside is enough' );
        $this->assertFalse( expAiAssistant::objectInSubtree( array(), array(), 60 ) );
    }

    /** @return array( expAiAssistant, expAiToolContext, expAiConversationStateTestTool ) */
    private function runner( array $settings = array() )
    {
        $config = $this->useSettings( $settings );
        $tool = new expAiConversationStateTestTool( 'test_tool' );
        $registry = ( new ReflectionClass( 'expAiToolRegistry' ) )->newInstanceWithoutConstructor();
        $registry->add( $tool );
        $assistant = new expAiAssistant( $config, null, $registry );
        ( new ReflectionProperty( 'expAiAssistant', 'writeAllowed' ) )->setValue( $assistant, true );
        ( new ReflectionProperty( 'expAiAssistant', 'turn' ) )->setValue( $assistant, 3 );
        $context = ( new ReflectionClass( 'expAiToolContext' ) )->newInstanceWithoutConstructor();
        $context->config = $config;
        $context->reader = new expAiContentReader( $config );
        $context->nodeID = 2;
        $context->conversation = self::conversation();
        return array( $assistant, $context, $tool );
    }

    private function callTool( expAiAssistant $assistant, expAiToolContext $context, array $input )
    {
        return self::invoke( $assistant, 'runTool', array( array( 'id' => 't1', 'name' => 'test_tool', 'input' => $input ), $context, false ) );
    }

    public function testArgumentsThatDoNotMatchTheSchemaAreRefusedBeforeTheToolRuns(): void
    {
        list( $assistant, $context, $tool ) = $this->runner();
        list( $content, $isError, $step ) = $this->callTool( $assistant, $context, array( 'text' => 'no count' ) );
        $this->assertTrue( $isError );
        $this->assertSame( 'The arguments do not match the schema of test_tool: input.count is required. Call the tool again with valid arguments.', $content );
        $this->assertFalse( $step['ok'] );
        list( $content, $isError ) = $this->callTool( $assistant, $context, array( 'count' => 2, 'colour' => 'red' ) );
        $this->assertTrue( $isError );
        $this->assertStringContainsString( 'input.colour is not a known field', $content );
        $this->assertSame( array(), $tool->calls );

        list( $content, $isError ) = $this->callTool( $assistant, $context, array( 'count' => 2 ) );
        $this->assertFalse( $isError );
        $this->assertSame( array( array( 'count' => 2 ) ), $tool->calls );
        $this->assertSame( array( 4711 ), $context->conversation->readObjects(), 'the objects of the result are noted with the conversation' );
    }

    public function testWritesStayLockedWhileAnExternalResultIsInTheWindow(): void
    {
        list( $assistant, $context, $tool ) = $this->runner();
        $tool->isExternal = true;
        $this->assertFalse( $this->callTool( $assistant, $context, array( 'count' => 1 ) )[1] );
        $this->assertTrue( $context->conversation->externalInContext() );
        $this->assertSame( 3, $context->conversation->taintedTurn() );

        // a later turn: still locked, as long as the result is part of the window
        ( new ReflectionProperty( 'expAiAssistant', 'turn' ) )->setValue( $assistant, 7 );
        $tool->isExternal = false;
        $tool->writing = true;
        list( $content, $isError, $step ) = $this->callTool( $assistant, $context, array( 'count' => 1 ) );
        $this->assertTrue( $isError );
        $this->assertStringStartsWith( 'Writing is not available while the result of an external tool is part of this conversation.', $content );
        $this->assertCount( 1, $tool->calls );

        // after a compaction (or in a new conversation) it may write again; a write without a target needs no scope check
        $context->conversation->setExternalInContext( false );
        $this->assertFalse( $this->callTool( $assistant, $context, array( 'count' => 1 ) )[1] );
        $this->assertCount( 2, $tool->calls );
    }

    public function testContentTheListDoesNotCoverIsNeverCarriedAndOnlyAWordsOnlyWindowEndsTheMarks(): void
    {
        $conversation = self::conversation();
        $this->assertFalse( $conversation->contentUntracked() );
        $conversation->addReadObjects( array( 1, 2 ) );
        $conversation->markUntracked();
        $conversation->setExternalInContext( true );
        $this->assertTrue( $conversation->contentUntracked() );

        // an ordinary compaction (the summary keeps the earlier answers): every mark stays
        expAiAssistant::afterCompaction( $conversation, false );
        $this->assertTrue( $conversation->contentUntracked() );
        $this->assertTrue( $conversation->externalInContext() );
        $this->assertSame( array( 1, 2 ), $conversation->readObjects() );
        // the editor's words only: nothing of it is left in the window
        expAiAssistant::afterCompaction( $conversation, true );
        $this->assertFalse( $conversation->contentUntracked() );
        $this->assertFalse( $conversation->externalInContext() );
        $this->assertSame( array(), $conversation->readObjects() );

        $config = $this->useSettings();
        $this->assertFalse( call_user_func( expAiModelFallback::objectsAllow( $config, array(), true ), 'anthropic' ), 'no provider passes for untracked content' );
    }

    public function testTheBridgeAndExternalToolsMarkTheirContentAsUntracked(): void
    {
        list( $assistant, $context, $tool ) = $this->runner();
        $this->assertFalse( $this->callTool( $assistant, $context, array( 'count' => 1 ) )[1] );
        $this->assertFalse( $context->conversation->contentUntracked(), 'a tool that reads through the reader is tracked' );

        $bridge = new expAiConversationStateTestTool( 'exp__content_get' );
        $registry = ( new ReflectionProperty( 'expAiAssistant', 'tools' ) )->getValue( $assistant );
        $registry->add( $bridge );
        self::invoke( $assistant, 'runTool', array( array( 'id' => 't2', 'name' => 'exp__content_get', 'input' => array( 'count' => 1 ) ), $context, false ) );
        $this->assertTrue( $context->conversation->contentUntracked() );
        $this->assertFalse( $context->conversation->externalInContext(), 'the in-process bridge is no external content' );

        list( $assistant, $context, $tool ) = $this->runner();
        $tool->isExternal = true;
        $this->callTool( $assistant, $context, array( 'count' => 1 ) );
        $this->assertTrue( $context->conversation->contentUntracked() );
        $this->assertTrue( $context->conversation->externalInContext() );
    }

    public function testOneStepAtATimePerConversation(): void
    {
        $db = new expAiConversationStateTestDb();
        $db->rows[7] = array( 'busy_owner' => '', 'busy_until' => 0 );
        $first = new expAiConversationLock( $db, 'host:1:a' );
        $second = new expAiConversationLock( $db, 'host:2:b' );
        $first->now = $second->now = 1000;
        $this->assertTrue( $first->acquire( 7, 300 ) );
        $this->assertTrue( $first->acquire( 7, 300 ), 'the owner takes its own lock again' );
        $this->assertFalse( $second->acquire( 7, 300 ), 'a second step is refused' );
        $second->release( 7 );
        $this->assertSame( 'host:1:a', $db->rows[7]['busy_owner'], 'only the owner releases' );
        $first->release( 7 );
        $this->assertTrue( $second->acquire( 7, 300 ) );
        // a lock that ran out (a process that ended without releasing it) is taken over
        $first->now = 1000 + 301;
        $this->assertTrue( $first->acquire( 7, 300 ) );
        $this->assertTrue( $first->acquire( 0, 300 ), 'no conversation yet: nothing to lock' );
        $this->assertTrue( $first->acquire( 99, 300 ), 'a conversation that is gone: nothing to protect' );
        $this->assertSame( 'A step of this conversation is already running. Wait until it has finished, then try again.', expAiConversationLock::busy()->getMessage() );
        // a refusal before the step began may run again later (a queued turn is deferred); one that lost its lock midway not
        $this->assertInstanceOf( 'expAiException', expAiConversationLock::busy(), 'existing catches still see it' );
        $this->assertTrue( expAiConversationBusyException::retryable( expAiConversationLock::busy() ) );
        $this->assertFalse( expAiConversationBusyException::retryable( expAiConversationLock::busy( true ) ) );
        $this->assertFalse( expAiConversationBusyException::retryable( new expAiException( 'other' ) ) );
    }

    public function testAStepRenewsItsLockAndStopsWhenAnotherTookItOver(): void
    {
        $db = new expAiConversationStateTestDb();
        $db->rows[8] = array( 'busy_owner' => '', 'busy_until' => 0 );
        $lock = new expAiConversationLock( $db, 'host:1:a' );
        $lock->now = 1000;
        $assistant = ( new expAiAssistant( $this->useSettings( array( 'AISettings' => array( 'Timeout' => 100 ) ) ) ) )->setLock( $lock );
        $this->assertTrue( $lock->acquire( 8, 10 ) );
        ( new ReflectionProperty( 'expAiAssistant', 'lockedID' ) )->setValue( $assistant, 8 );
        $lock->now = 1005;
        self::invoke( $assistant, 'renewLock' );
        $this->assertSame( 1005 + 260, $db->rows[8]['busy_until'], 'extended by the step\'s time limit' );

        // the lock ran out and another step took it: this step stops
        $db->rows[8] = array( 'busy_owner' => 'host:2:b', 'busy_until' => 99999 );
        try
        {
            self::invoke( $assistant, 'renewLock' );
            $this->fail( 'no refusal' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'conversation lock held', $e->detail() );
            $this->assertInstanceOf( 'expAiConversationBusyException', $e );
            $this->assertTrue( $e->started(), 'the step had begun: not simply run again' );
        }
        $this->assertSame( 0, ( new ReflectionProperty( 'expAiAssistant', 'lockedID' ) )->getValue( $assistant ), 'it releases nothing it does not hold' );
    }

    public function testTheLockColumnsAreAskedAgainWhileMissing(): void
    {
        $asked = 0;
        $columns = array( 'id', 'user_id' );
        expAiSchema::forgetColumns();
        expAiConversationLock::$columnLister = function ( $table ) use ( &$asked, &$columns )
        {
            $asked++;
            return $columns;
        };
        try
        {
            $this->assertFalse( expAiConversationLock::columnsPresent() );
            $this->assertFalse( expAiConversationLock::columnsPresent() );
            $this->assertSame( 1, $asked, 'a missing column is not asked on every step' );
            $columns = array( 'id', 'busy_owner', 'busy_until' );
            $this->assertTrue( expAiSchema::hasColumns( 'expai_conversation', array( 'busy_owner', 'busy_until' ), expAiConversationLock::$columnLister,
                                                        time() + expAiSchema::COLUMN_RECHECK_SECONDS + 1 ), 'asked again after the recheck time' );
            $this->assertTrue( expAiConversationLock::columnsPresent() );
            $columns = array();
            $this->assertTrue( expAiConversationLock::columnsPresent(), 'a positive answer is kept' );
            $this->assertSame( 2, $asked );
        }
        finally
        {
            expAiConversationLock::$columnLister = null;
            expAiSchema::forgetColumns();
        }
    }
}
