<?php
/**
 * Shared fixtures of the tests of Vector's threads, runs, plans and changes (tests/phpunit/unit/vectorcore).
 *
 *  - every test runs as admin with the settings of expai.ini changed in memory only (restored after the test): the
 *    queue's workers are never started (KickWorker=disabled)
 *  - stubModel(): the model xtb.stub of the in-memory provider xtb (Type=custom, expAiVectorCoreStubProvider), which
 *    answers with the replies a test queues and never leaves the process
 *  - threads made by a test are removed after it, with their runs, events, conversations and the usage rows of the
 *    stub provider, also when a code path under test ended a transaction itself
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A provider that answers from a list of replies (expAiVectorCoreStubProvider::$replies), else in words ("Done.").
 */
class expAiVectorCoreStubProvider implements expAiProvider
{
    /** @var array list of content block lists, or callables( array $request ) returning a content list */
    public static $replies = array();

    /** @var array the requests sent */
    public static $requests = array();

    /** @var Exception|null thrown by the next send() */
    public static $throw = null;

    protected $settings;

    public function __construct( $settings = array() )
    {
        $this->settings = (array) $settings;
    }

    public static function reset()
    {
        self::$replies = array();
        self::$requests = array();
        self::$throw = null;
    }

    public function send( array $request )
    {
        self::$requests[] = $request;
        if ( self::$throw )
        {
            $e = self::$throw;
            self::$throw = null;
            throw $e;
        }
        $content = self::$replies ? array_shift( self::$replies ) : array( array( 'type' => 'text', 'text' => 'Done.' ) );
        if ( is_callable( $content ) )
            $content = $content( $request );
        $tool = (bool) array_filter( $content, function ( $b ) { return $b['type'] === 'tool_use'; } );
        return array( 'content' => $content, 'content_json' => json_encode( $content ), 'stop_reason' => $tool ? 'tool_use' : 'end_turn',
                      'model' => 'stub', 'usage' => array( 'input_tokens' => 10, 'output_tokens' => 5 ) );
    }

    public function modelName()
    {
        return 'stub';
    }

    public function providerId()
    {
        return 'xtb';
    }
}

/**
 * A queue context for a job that exists in memory only: the checkpoint and the progress stay in the object, and a
 * test decides whether the job counts as cancelled. Nothing is written to the queue's tables.
 */
class expAiVectorCoreQueueContext extends expQueueContext
{
    public $cancelled = false;
    public $saved = null;
    public $progress = array();

    public function __construct( expQueueJob $job, $checkpoint = null )
    {
        parent::__construct( $job, 'xtb' );
        $this->saved = $checkpoint;
    }

    public function isCancelled()
    {
        return $this->cancelled;
    }

    public function checkpoint()
    {
        return $this->saved;
    }

    public function saveCheckpoint( array $data )
    {
        $this->saved = $data;
    }

    public function progress( $done, $total = null, $message = '' )
    {
        $this->progress[] = array( $done, $total, $message );
    }
}

abstract class expAiVectorCoreTestCase extends expAiTestCase
{
    /** @var int the admin's content object id */
    protected $userID = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        if ( !expAiThread::available() )
            $this->markTestSkipped( 'the thread tables are not installed' );
        $user = $this->asUser( 'admin' );
        $this->userID = (int) $user->attribute( 'contentobject_id' );
        expAiVectorCoreStubProvider::reset();
        $max = eZDB::instance()->arrayQuery( 'SELECT MAX(id) AS m FROM expai_usage_log' );
        $this->usageBefore = (int) ( isset( $max[0]['m'] ) ? $max[0]['m'] : 0 );
        $ini = eZINI::instance( 'expai.ini' );
        $saved = $ini->groups();
        $this->cleanup( static function () use ( $ini, $saved )
        {
            $ini->setGroups( $saved );
            expAiThread::$holdRunning = false;
        } );
        $this->cleanup( array( $this, 'removeOwnRows' ) );
        $this->ini( 'VectorSettings', 'KickWorker', 'disabled' );
        $this->ini( 'VectorSettings', 'ParallelRuns', 'disabled' );
        // no real provider exists for these tests: the stub is the only one, whatever the user prefers
        $this->stubModel();
        $config = expAiConfig::instance();
        $effective = $config->effectiveModel();
        if ( $effective !== 'xtb.stub' || !$config->registry()->providerFor( $effective ) instanceof expAiVectorCoreStubProvider
             || $config->registry()->providerIds() !== array( 'xtb' ) )
            $this->fail( "the stub provider is not the only one (effective model '$effective'): not running a test that could reach a real provider" );
    }

    /** @var int the newest usage row before the test */
    private $usageBefore = 0;

    protected function tearDown(): void
    {
        // setUp() skipped the test without a usable database (a DB-less run): nothing ran, nothing to guard
        if ( expAiTestInstallation::unavailableReason() !== null )
        {
            parent::tearDown();
            return;
        }
        // a guard: the threads of the test logged requests of the stub provider only
        $foreign = array();
        $db = eZDB::instance();
        // a second guard: the code under test closed every transaction it opened (the test's own inTransaction() is
        // rolled back by its cleanup and does not count)
        $total = $db->transactionCounter();
        $open = max( 0, $total - $this->testTransactions );
        if ( $open > 0 )
            $db->rollback();
        $rows = $db->arrayQuery( 'SELECT u.id, u.provider FROM expai_usage_log u, expai_thread t WHERE u.thread_id = t.id AND u.id > ' . (int) $this->usageBefore
                                 . " AND t.title LIKE 'XTB %' AND u.provider <> 'xtb'" );
        foreach ( (array) $rows as $row )
            $foreign[] = implode( ':', $row );
        parent::tearDown();
        $this->assertSame( 0, $open, 'the test left a transaction open' );
        $this->assertSame( array(), $foreign, 'a request reached a provider other than the stub' );
    }

    /** Sets a value of the global expai.ini for this test (restored after it) */
    protected function ini( $group, $name, $value )
    {
        eZINI::instance( 'expai.ini' )->setVariable( $group, $name, $value );
    }

    /**
     * Makes xtb.stub the default model, answered by expAiVectorCoreStubProvider.
     * @param array $model more settings of [Model_xtb.stub]
     * @return string the alias
     */
    protected function stubModel( array $model = array() )
    {
        $ini = eZINI::instance( 'expai.ini' );
        $ini->setVariable( 'AISettings', 'Enabled', 'true' );
        // the stub is the only provider and the user's own model choice is off: no request can reach a real provider
        $ini->setVariable( 'AISettings', 'AllowUserModelChoice', 'disabled' );
        $ini->setVariable( 'AISettings', 'Model', 'xtb.stub' );
        $ini->setVariable( 'AISettings', 'ApiKey', '' );
        $ini->setVariable( 'ProviderSettings', 'Providers', array( 'xtb' ) );
        $ini->setVariable( 'ProviderSettings', 'DefaultModel', 'xtb.stub' );
        foreach ( array( 'Type' => 'custom', 'Class' => 'expAiVectorCoreStubProvider', 'Label' => 'XTB stub', 'Region' => 'eu', 'Retention' => 'none' ) as $name => $value )
            $ini->setVariable( 'Provider_xtb', $name, $value );
        foreach ( $model + array( 'Provider' => 'xtb', 'Id' => 'stub', 'Label' => 'Stub', 'ToolUse' => 'native', 'WriteTools' => 'enabled',
                                  'WriteGate' => 'trusted', 'MaxOutput' => 400 ) as $name => $value )
            $ini->setVariable( 'Model_xtb.stub', $name, $value );
        return 'xtb.stub';
    }

    /**
     * @param array $row fields (title gets the prefix XTB)
     * @return expAiThread a thread of the admin, removed after the test
     */
    protected function thread( array $row = array() )
    {
        $row += array( 'user_id' => $this->userID, 'title' => 'thread', 'run_mode' => 'write', 'review_policy' => 'staged' );
        $row['title'] = 'XTB ' . $row['title'];
        return expAiThread::create( $row );
    }

    /**
     * A running plan that fanned out: one child run per item, each with its own conversation.
     * @param expAiThread $thread
     * @param array $items list of { state, depends_on (indexes), action, target, languages, target_name }
     * @param array $estimate more of the plan's estimate (items, group, batch)
     * @return array( expAiPlan, expAiThreadRun[] index => run )
     */
    protected function planWithRuns( expAiThread $thread, array $items, array $estimate = array() )
    {
        $now = time();
        $runs = array();
        $ids = array();
        $planItems = array();
        foreach ( array_values( $items ) as $index => $item )
        {
            $item += array( 'state' => 'queued', 'depends_on' => array(), 'action' => 'update', 'target' => array(), 'languages' => array(), 'target_name' => 'item ' . $index );
            $conversation = new expAiConversation( array( 'user_id' => (int) $thread->attribute( 'user_id' ), 'node_id' => 0, 'messages' => '[]',
                                                          'thread_id' => (int) $thread->attribute( 'id' ), 'created' => $now, 'modified' => $now ) );
            $conversation->store();
            $run = new expAiThreadRun( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'parent_run_id' => (int) $thread->attribute( 'lead_run_id' ),
                                              'plan_item' => $index, 'conversation_id' => (int) $conversation->attribute( 'id' ), 'kind' => 'item',
                                              'state' => $item['state'], 'label' => 'XTB item ' . $index, 'lane' => 'standard', 'created' => $now, 'modified' => $now ) );
            $run->store();
            $conversation->setAttribute( 'run_id', (int) $run->attribute( 'id' ) );
            $conversation->store();
            $runs[$index] = $run;
            $ids[$index] = (int) $run->attribute( 'id' );
            $planItems[] = array( 'index' => $index, 'selected' => true, 'action' => $item['action'], 'target' => $item['target'],
                                  'languages' => $item['languages'], 'target_name' => $item['target_name'], 'depends_on' => $item['depends_on'], 'instructions' => 'Do item ' . $index );
        }
        $plan = new expAiPlan( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'state' => 'running', 'created' => $now, 'modified' => $now ) );
        $plan->setItems( $planItems );
        $plan->setEstimate( $estimate + array( 'runs' => $ids ) );
        $plan->store();
        $thread->setAttribute( 'plan_id', (int) $plan->attribute( 'id' ) );
        $thread->setState( 'running' );
        $thread->store();
        return array( $plan, $runs );
    }

    /** Removes what the tests of this directory wrote: threads titled XTB..., stub usage rows, their queue rows */
    public function removeOwnRows()
    {
        $db = eZDB::instance();
        while ( $db->transactionCounter() > 0 )
            $db->rollback();
        foreach ( (array) $db->arrayQuery( "SELECT id FROM expai_thread WHERE title LIKE 'XTB %'" ) as $row )
        {
            $id = (int) array_change_key_case( $row, CASE_LOWER )['id'];
            $thread = expAiThread::fetchByID( $id );
            if ( !$thread )
                continue;
            if ( class_exists( 'expQueue' ) )
            {
                foreach ( (array) $db->arrayQuery( "SELECT id FROM expqueue_job WHERE payload LIKE '%\"thread_id\":$id,%' OR payload LIKE '%\"thread_id\":$id}%'" ) as $job )
                {
                    $jobID = (int) array_change_key_case( $job, CASE_LOWER )['id'];
                    $db->query( 'DELETE FROM expqueue_event WHERE job_id = ' . $jobID );
                    $db->query( 'DELETE FROM expqueue_job WHERE id = ' . $jobID );
                }
                $db->query( "DELETE FROM expqueue_resource WHERE resource_key = 'expai.thread:$id'" );
            }
            $thread->removeAll( true );
        }
        eZPersistentObject::removeObject( expAiUsageLog::definition(), array( 'provider' => 'xtb' ) );
    }

    /**
     * @param string $paragraphs
     * @return string rich text XML of paragraphs
     */
    protected static function xml( array $paragraphs )
    {
        $out = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<section xmlns:image="http://ez.no/namespaces/ezpublish3/image/" xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/">';
        foreach ( $paragraphs as $p )
            $out .= '<paragraph>' . htmlspecialchars( $p, ENT_XML1 ) . '</paragraph>';
        return $out . "</section>\n";
    }

    /** @return eZContentObject an article below a new hidden folder (both removed after the test) */
    protected function article( $title = 'XTB article', array $paragraphs = array( 'One.', 'Two.' ) )
    {
        $folder = $this->folder( 'expai unit test vector' );
        return $this->publish( (int) $folder->attribute( 'node_id' ), array( 'title' => $title, 'intro' => self::xml( $paragraphs ) ) );
    }

    /** @return string the message of an expAiException with its arguments */
    protected static function text( expAiException $e )
    {
        return strtr( $e->getMessage(), $e->arguments() );
    }
}
