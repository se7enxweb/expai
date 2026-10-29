<?php
/**
 * The queue side of expai (doc/lanes.md): expAiQueue::request(), the handler of a queued request on a synchronous
 * and on the batch lane, the batch submit and poll handlers, against a small Anthropic API on a loopback port whose
 * answers each test chooses (tests/phpunit/unit/providers/expaiprovidertestbatchrouter.php). Everything the queue writes
 * (jobs, events, resources, batches, usage rows) is written in a transaction that is rolled back: no worker of the
 * site ever sees a job of this test.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiQueueHandlersTest extends expAiProviderTestCase
{
    /** @var array */
    private $mock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        if ( !expAiQueue::available() )
            $this->markTestSkipped( 'the job queue is not installed' );
        $this->mock = expAiTestMockServer::start( '../phpunit/unit/providers/expaiprovidertestbatchrouter.php' );
        $this->mode( 'ok' );
        foreach ( array( 'status.json', 'results.jsonl' ) as $file )
            if ( is_file( $this->mock['state'] . '/' . $file ) )
                unlink( $this->mock['state'] . '/' . $file );
        $this->asUser( 'admin' );
        $this->siteProviders(
            array( 'xteq' => array( 'Type' => 'anthropic', 'Endpoint' => $this->mock['url'] . '/v1/messages', 'ApiKey' => 'test-not-a-key', 'Label' => 'Queue T',
                                      'Region' => 'us', 'Retention' => '30d' ) ),
            array( 'xteq.m' => array( 'Provider' => 'xteq', 'Id' => 'claude-xte', 'InputPerMTok' => '1', 'OutputPerMTok' => '2' ) ),
            'xteq.m',
            array( 'Lane_xteq.standard' => array( 'FallbackLane' => 'fast' ),
                   'Lane_xteq.fast' => array( 'Enabled' => 'true', 'PriceMultiplier' => '2' ),
                   'Lane_xteq.batch' => array( 'Enabled' => 'true', 'Type' => 'batch', 'BatchApi' => 'anthropic', 'PriceMultiplier' => '0.5', 'MaxRequestsPerBatch' => 2,
                                                 'PollSeconds' => 5, 'BatchCollectSeconds' => 0, 'ExpiredLane' => 'standard', 'DeleteAfterFetch' => 'enabled' ),
                   'Lane_xteq.dead' => array( 'Enabled' => 'true', 'Type' => 'batch', 'BatchApi' => 'anthropic', 'PriceMultiplier' => '0.5', 'ExpiredLane' => 'batch' ),
                   'QueueSettings' => array( 'Appliers' => array() ),
                   'Budgets' => array( 'Enabled' => 'disabled' ) ) );
        $this->inTransaction();
    }

    private function mode( $mode )
    {
        file_put_contents( $this->mock['state'] . '/mode', $mode );
    }

    private static function job( array $payload, array $row = array() )
    {
        return new expQueueJob( $row + array( 'id' => 0, 'uuid' => expQueue::uuid(), 'type' => expAiQueue::TYPE_REQUEST, 'state' => 'running', 'user_id' => 14,
                                              'resource_key' => 'xteq:' . ( isset( $payload['lane'] ) ? $payload['lane'] : 'standard' ), 'external_ref' => '', 'checkpoint' => '',
                                              'payload' => json_encode( $payload + array( 'alias' => 'xteq.m', 'lane' => 'standard',
                                                  'request' => array( 'system' => 's', 'messages' => array( array( 'role' => 'user', 'content' => 'Say ok' ) ), 'max_tokens' => 20 ) ) ) ) );
    }

    private static function runJob( expQueueHandler $handler, expQueueJob $job )
    {
        return $handler->run( $job, new expQueueContext( $job, 'xte' ) );
    }

    // ------------------------------------------------------------------ expAiQueue

    public function testRequestsAreCheckedBeforeTheyAreQueued(): void
    {
        foreach ( array( array( array( 'messages' => array() ), 'a queued request ends with a user message' ),
                         array( array( 'messages' => array( array( 'role' => 'system', 'content' => 'x' ) ) ), 'a queued request has messages of role user or assistant with text only' ),
                         array( array( 'messages' => array( array( 'role' => 'user', 'content' => 'q' ), array( 'role' => 'assistant', 'content' => 'a' ) ) ), 'a queued request ends with a user message' ) ) as $case )
        {
            try
            {
                expAiQueue::normaliseRequest( $case[0] );
                $this->fail( 'accepted' );
            }
            catch ( expAiException $e )
            {
                $this->assertSame( $case[1], $e->detail() );
            }
        }
        $this->assertSame( array( 'system' => '', 'messages' => array( array( 'role' => 'user', 'content' => 'q' ) ), 'max_tokens' => 64000, 'output_schema' => array( 'type' => 'object' ) ),
                           expAiQueue::normaliseRequest( array( 'messages' => array( array( 'role' => 'user', 'content' => 'q' ) ), 'max_tokens' => 999999, 'output_schema' => array( 'type' => 'object' ) ) ) );
        try
        {
            expAiQueue::request( 'xteq.m', array( 'messages' => array( array( 'role' => 'user', 'content' => 'q' ) ) ), array( 'apply' => 'no_such_applier' ) );
            $this->fail( 'accepted an unknown applier' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'unknown applier no_such_applier', $e->detail() );
        }
        $this->site( array( 'ProviderSettings' => array( 'DefaultModel' => '' ), 'Model_xteq.m' => array( 'Provider' => 'gone' ) ) );
        try
        {
            expAiQueue::request( 'nothing', array( 'messages' => array( array( 'role' => 'user', 'content' => 'q' ) ) ) );
            $this->fail( 'accepted without a model' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'no model nothing in the provider registry', $e->detail() );
        }
    }

    public function testARequestIsQueuedOnItsLaneWithTheDataPolicyOfItsObjects(): void
    {
        $folder = eZContentObjectTreeNode::fetch( 2 )->object();
        $queued = expAiQueue::request( 'xteq.m', array( 'messages' => array( array( 'role' => 'user', 'content' => 'q' ) ), 'max_tokens' => 100 ),
                                       array( 'purpose' => 'XT Test!', 'lane' => 'batch', 'objects' => array( (int) $folder->attribute( 'id' ), 999999999 ), 'label' => 'label',
                                              'group' => null, 'idempotency_key' => 'xte-' . getmypid(), 'user_id' => 14, 'tags' => array( 'test:xte' ) ) );
        $job = $queued['job'];
        $this->assertSame( array( 'batch', 'xteq:batch', 600 ), array( $queued['lane']->name(), (string) $job->attribute( 'resource_key' ), (int) $job->attribute( 'timeout' ) ) );
        $p = $job->payloadArray();
        $this->assertSame( array( 'xteq.m', 'xttest', array( (int) $folder->attribute( 'id' ), 999999999 ) ), array( $p['alias'], $p['purpose'], $p['objects'] ) );
        $again = expAiQueue::request( 'xteq.m', array( 'messages' => array( array( 'role' => 'user', 'content' => 'q' ) ) ), array( 'idempotency_key' => 'xte-' . getmypid() ) );
        $this->assertTrue( $again['duplicate'] );
        $listed = expAiQueue::jobs( array( 'resource_key' => 'xteq:batch' ), 5 );
        $this->assertSame( array( (string) $job->attribute( 'uuid' ) ), array_column( $listed, 'uuid' ) );
        $this->assertSame( 'xteq.m', $listed[0]['alias'] );
        $this->assertSame( 'editorial', expAiQueue::classFor( 'nothing-mapped' ) );
    }

    // ------------------------------------------------------------------ expAiRequestHandler

    public function testTheRequestHandlerRunsARequestAndKeepsTheAnswer(): void
    {
        $handler = new expAiRequestHandler();
        $this->assertSame( 'alias, lane and request.messages are required', $handler->validate( array( 'alias' => 'x' ) ) );
        $this->assertNull( $handler->validate( array( 'alias' => 'x', 'lane' => 'standard', 'request' => array( 'messages' => array( 1 ) ) ) ) );
        $this->assertSame( 'Spring: xteq.m on standard (seo)', $handler->describe( self::job( array( 'label' => 'Spring', 'purpose' => 'seo' ) ) ) );
        $result = self::runJob( $handler, self::job( array() ) );
        $this->assertSame( array( '{"ok":true}', array( 'ok' => true ), 'standard', 'standard', 'claude-xte' ),
                           array( $result['text'], $result['value'], $result['lane'], $result['service_tier'], $result['model'] ) );
        $this->assertGreaterThan( 0, $result['usage_log_id'] );
        $row = eZPersistentObject::fetchObject( expAiUsageLog::definition(), null, array( 'id' => $result['usage_log_id'] ) );
        $this->assertSame( array( 'standard', 'queue', 'xteq' ), array( (string) $row->attribute( 'lane' ), (string) $row->attribute( 'action' ), (string) $row->attribute( 'provider' ) ) );

        // a rerun after the answer came: the checkpoint is applied, nothing is sent again
        $job = $this->queued( 1 )[0];
        ( new expQueueContext( $job, 'xte' ) )->saveCheckpoint( array( 'answer' => array( 'text' => 'kept', 'value' => null, 'stop_reason' => 'end_turn', 'model' => 'm',
            'lane' => 'batch', 'service_tier' => 'batch', 'tokens' => array(), 'cost_micros' => 5, 'usage_log_id' => 0, 'batch_id' => 7 ) ) );
        $sent = substr_count( (string) file_get_contents( $this->mock['state'] . '/requests.jsonl' ), "\n" );
        $kept = self::runJob( $handler, $job );
        $this->assertSame( array( 'kept', 7, 5 ), array( $kept['text'], $kept['batch_id'], $kept['cost_micros'] ) );
        $this->assertSame( $sent, substr_count( (string) file_get_contents( $this->mock['state'] . '/requests.jsonl' ), "\n" ) );
    }

    public function testTheRequestHandlerRefusesWhatItCannotRun(): void
    {
        $handler = new expAiRequestHandler();
        foreach ( array( array( array( 'alias' => 'nothing' ), 'the model nothing is not in the provider registry' ),
                         array( array( 'lane' => 'nope' ), 'the lane nope of xteq.m is not configured or not enabled' ),
                         array( array( 'apply' => 'no_such_applier' ), 'the applier no_such_applier is not configured' ) ) as $case )
        {
            try
            {
                self::runJob( $handler, self::job( $case[0] ) );
                $this->fail( 'ran ' . json_encode( $case[0] ) );
            }
            catch ( expQueuePermanentException $e )
            {
                $this->assertSame( $case[1], $e->getMessage() );
            }
        }
        // the data policy of the lane: an object that may only go to a local model
        $this->site( array( 'DataPolicy' => array( 'DefaultRegions' => array( 'local' ) ) ) );
        $objectID = (int) eZContentObjectTreeNode::fetch( 2 )->attribute( 'contentobject_id' );
        try
        {
            self::runJob( $handler, self::job( array( 'objects' => array( 999999999, $objectID ) ) ) );
            $this->fail( 'the data policy let it through' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertStringStartsWith( 'the data policy refuses object ' . $objectID . ' on xteq:standard: ', $e->getMessage() );
        }
        // the master switch holds the job
        $this->site( array( 'DataPolicy' => array( 'DefaultRegions' => array() ), 'AISettings' => array( 'Enabled' => 'false' ) ) );
        $this->assertSame( expQueueResult::HOLD, self::runJob( $handler, self::job( array() ) )->outcome );
    }

    public function testFailuresOfTheProviderBecomeRetriesFailuresOrAnotherLane(): void
    {
        $handler = new expAiRequestHandler();
        $this->mode( 'e401' );
        $result = self::runJob( $handler, self::job( array() ) );
        $this->assertSame( array( expQueueResult::RETRY, 300, 'http_401' ), array( $result->outcome, $result->seconds, $result->errorClass ) );
        $this->mode( 'e400' );
        $result = self::runJob( $handler, self::job( array() ) );
        $this->assertSame( array( expQueueResult::FAIL, 'http_400' ), array( $result->outcome, $result->errorClass ) );
        // busy: the standard lane hands the job to its fallback lane
        $this->mode( 'e503' );
        $job = self::job( array() );
        $result = self::runJob( $handler, $job );
        $this->assertSame( array( expQueueResult::DEFER, 'escalated to the lane fast' ), array( $result->outcome, $result->message ) );
        $this->assertSame( 'fast', $job->payloadArray()['lane'] );
        $this->assertSame( 'xteq:fast', (string) $job->attribute( 'resource_key' ) );
        // busy on the fast lane, which has no fallback: a retry after the retry-after of the answer
        $result = self::runJob( $handler, self::job( array( 'lane' => 'fast' ) ) );
        $this->assertSame( array( expQueueResult::RETRY, 2, 'http_503' ), array( $result->outcome, $result->seconds, $result->errorClass ) );
    }

    public function testARequestOnTheBatchLaneWaitsForItsBatch(): void
    {
        $handler = new expAiRequestHandler();
        $result = self::runJob( $handler, self::job( array( 'lane' => 'batch' ), array( 'external_ref' => 'expai-submit:x' ) ) );
        $this->assertSame( array( expQueueResult::DEFER, 'a submit job is taking it into a batch' ), array( $result->outcome, $result->message ) );

        // a batch that ended without a result for it: the expired lane takes it
        $batch = new expAiBatch( array( 'created' => time(), 'modified' => time(), 'provider' => 'xteq', 'lane' => 'batch', 'model_alias' => 'xteq.m', 'state' => 'ended',
                                        'remote_id' => 'msgbatch_xte', 'batch_api' => 'anthropic' ) );
        $batch->store();
        $job = self::job( array( 'lane' => 'batch' ), array( 'external_ref' => $batch->ref() ) );
        $result = self::runJob( $handler, $job );
        $this->assertSame( array( expQueueResult::DEFER, 'moved to the lane standard' ), array( $result->outcome, $result->message ) );
        $this->assertSame( 'the batch ended without a result', $job->payloadArray()['moved'][0]['why'] );
        $batch->setAttribute( 'state', 'in_progress' );
        $batch->store();
        $this->assertStringStartsWith( 'in the batch ', self::runJob( $handler, self::job( array( 'lane' => 'batch' ), array( 'external_ref' => $batch->ref() ) ) )->message );
        try
        {
            $batch->setAttribute( 'state', 'ended' );
            $batch->setAttribute( 'lane', 'dead' );
            $batch->store();
            self::runJob( $handler, self::job( array( 'lane' => 'dead' ), array( 'external_ref' => $batch->ref() ) ) );
            $this->fail( 'no expired lane, no error' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertStringStartsWith( 'the batch ended without a result and the lane batch cannot take it', $e->getMessage() );
        }

        // a new request: a submit job collects it
        $result = self::runJob( $handler, self::job( array( 'lane' => 'batch' ) ) );
        $this->assertSame( 'waits for a batch of xteq:batch', $result->message );
        $submit = expQueue::listJobs( array( 'type' => expAiQueue::TYPE_SUBMIT, 'resource_key' => 'xteq:batch-api' ), 0, 5 );
        $this->assertNotEmpty( $submit );
    }

    public function testRateLimitHintsAndServiceTiers(): void
    {
        $this->assertSame( array( 'status' => 429, 'retry_after' => 2, 'remaining_requests' => 3, 'remaining_tokens' => 9, 'reset_in' => 3725 ),
                           expAiRequestHandler::hints( array( 'Retry-After' => '1.5', 'x-ratelimit-remaining-requests' => '3', 'x-ratelimit-remaining-tokens' => '9',
                                                              'x-ratelimit-reset-requests' => '1h2m5s' ), 429 ) );
        $this->assertSame( strtotime( '2026-10-12T10:00:00Z' ), expAiRequestHandler::hints( array( 'anthropic-ratelimit-requests-reset' => '2026-10-12T10:00:00Z' ), 200 )['reset_at'] );
        $this->assertSame( 'fast', expAiRequestHandler::serviceTier( array( 'usage' => array( 'speed' => 'fast' ) ) ) );
        $this->assertSame( 'priority', expAiRequestHandler::serviceTier( array( 'usage' => array( 'service_tier' => 'priority' ) ) ) );
        $this->assertSame( 'flex', expAiRequestHandler::serviceTier( array( 'service_tier' => 'flex' ) ) );
        $this->assertSame( '', expAiRequestHandler::serviceTier( array() ) );
        expAiRequestHandler::tag( null, expAiLane::fetch( 'xteq', 'standard' ), self::job( array() ), '' );
        $stored = expAiRequestHandler::storedAnswer( array( 'content' => array( array( 'type' => 'text', 'text' => "```json\n{\"a\":1}\n```" ) ) ), 'batch' );
        $this->assertSame( array( array( 'a' => 1 ), 0, 0 ), array( $stored['value'], $stored['usage_log_id'], $stored['cost_micros'] ) );
    }

    // ------------------------------------------------------------------ submit and poll

    private function queued( $count, $alias = 'xteq.m' )
    {
        $jobs = array();
        for ( $i = 0; $i < $count; $i++ )
            $jobs[] = expQueue::enqueue( expAiQueue::TYPE_REQUEST, array( 'alias' => $alias, 'lane' => 'batch',
                'request' => array( 'system' => '', 'messages' => array( array( 'role' => 'user', 'content' => "item $i" ) ), 'max_tokens' => 10 ) ),
                array( 'resource' => 'xteq:batch', 'lane' => 'batch', 'class' => 'bulk', 'user_id' => 14 ) );
        return $jobs;
    }

    private static function submitJob()
    {
        return expQueue::enqueue( expAiQueue::TYPE_SUBMIT, array( 'alias' => 'xteq.m', 'lane' => 'batch' ),
                                  array( 'resource' => 'xteq:batch-api', 'class' => 'system', 'idempotency_key' => 'xte-submit-' . bin2hex( random_bytes( 4 ) ) ) );
    }

    public function testSubmitTakesAsManyRequestsAsABatchHoldsAndQueuesTheNextSubmit(): void
    {
        $handler = new expAiBatchSubmitHandler();
        $this->assertSame( 'alias and lane are required', $handler->validate( array( 'alias' => 'x' ) ) );
        $this->assertSame( 'Submit a batch of a on b', $handler->describe( self::job( array( 'alias' => 'a', 'lane' => 'b' ) ) ) );

        $submit = self::submitJob();
        $this->assertSame( 'no request waits for xteq:batch and xteq.m', self::runJob( $handler, $submit )['message'] );

        $jobs = $this->queued( 3 );
        $this->queued( 1, 'other.alias' );
        $result = self::runJob( $handler, $submit );
        $this->assertSame( array( 'msgbatch_xte', 2, 2 ), array( $result['remote_id'], $result['requests'], $result['waiting_after'] ) );
        // waiting_after counts every request of the lane, also the one of another model
        $batch = expAiBatch::fetch( $result['batch_id'] );
        $this->assertSame( 2, count( $batch->customIds() ) );
        $this->assertNotEmpty( expQueue::listJobs( array( 'type' => expAiQueue::TYPE_POLL ), 0, 50 ) );
        // a rerun after the provider accepted: recorded again, not sent again
        $sent = substr_count( (string) file_get_contents( $this->mock['state'] . '/requests.jsonl' ), '/v1/messages/batches"' );
        $again = self::runJob( $handler, expQueueJob::fetchByID( (int) $submit->attribute( 'id' ) ) );
        $this->assertSame( $result['batch_id'], $again['batch_id'] );
        $this->assertSame( $sent, substr_count( (string) file_get_contents( $this->mock['state'] . '/requests.jsonl' ), '/v1/messages/batches"' ) );
    }

    public function testSubmitFailuresReleaseTheRequests(): void
    {
        $handler = new expAiBatchSubmitHandler();
        $this->queued( 1 );
        $this->mode( 'e400' );
        try
        {
            self::runJob( $handler, self::submitJob() );
            $this->fail( 'no failure' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertStringStartsWith( 'the provider refused the batch: ', $e->getMessage() );
        }
        $this->mode( 'e503' );
        try
        {
            self::runJob( $handler, self::submitJob() );
            $this->fail( 'no failure' );
        }
        catch ( expQueueRetryableException $e )
        {
            $this->assertStringStartsWith( 'the batch could not be submitted: ', $e->getMessage() );
        }
        $db = eZDB::instance();
        $this->assertSame( 0, (int) $db->arrayQuery( "SELECT COUNT(*) AS c FROM expqueue_job WHERE external_ref LIKE 'expai-submit:%' AND resource_key = 'xteq:batch'" )[0]['c'],
                           'the taken requests are free again' );
        foreach ( array( array( array( 'alias' => 'nothing', 'lane' => 'batch' ), 'the model nothing is not in the provider registry' ),
                         array( array( 'alias' => 'xteq.m', 'lane' => 'standard' ), 'the lane standard is not a usable batch lane' ) ) as $case )
        {
            try
            {
                self::runJob( $handler, self::job( $case[0] ) );
                $this->fail( 'ran' );
            }
            catch ( expQueuePermanentException $e )
            {
                $this->assertSame( $case[1], $e->getMessage() );
            }
        }
        $this->site( array( 'AISettings' => array( 'Enabled' => 'false' ) ) );
        $this->assertSame( expQueueResult::HOLD, self::runJob( $handler, self::submitJob() )->outcome );
    }

    public function testPollReadsTheStateThenTheResultsAndDeletesTheBatch(): void
    {
        $jobs = $this->queued( 3 );
        $submitted = self::runJob( new expAiBatchSubmitHandler(), self::submitJob() );
        $batchID = $submitted['batch_id'];
        $poll = new expAiBatchPollHandler();
        $this->assertSame( 'batch_id is required', $poll->validate( array() ) );
        $this->assertSame( 'Poll batch 5', $poll->describe( self::job( array( 'batch_id' => 5 ) ) ) );
        $pollJob = function () use ( $batchID ) { return self::job( array( 'batch_id' => $batchID ), array( 'type' => expAiQueue::TYPE_POLL ) ); };

        // still running: deferred
        file_put_contents( $this->mock['state'] . '/status.json', '{"processing_status":"in_progress","request_counts":{"processing":2}}' );
        $this->assertSame( expQueueResult::DEFER, self::runJob( $poll, $pollJob() )->outcome );
        // the state cannot be read: a retry, with the error kept
        $this->mode( 'e503' );
        try
        {
            self::runJob( $poll, $pollJob() );
            $this->fail( 'no retry' );
        }
        catch ( expQueueRetryableException $e )
        {
            $this->assertStringStartsWith( 'the batch state could not be read: ', $e->getMessage() );
        }
        $this->assertStringContainsString( 'HTTP 503', (string) expAiBatch::fetch( $batchID )->attribute( 'last_error' ) );
        $this->mode( 'ok' );

        // ended: one answer, one invalid item (dead), one failed item (retried), the rest expired (moved to the standard lane)
        $ids = expAiBatch::fetch( $batchID )->customIds();
        $this->assertCount( 2, $ids );
        $lines = json_encode( array( 'custom_id' => $ids[0], 'result' => array( 'type' => 'succeeded', 'message' => array( 'content' => array( array( 'type' => 'text', 'text' => 'Done' ) ),
                    'stop_reason' => 'end_turn', 'model' => 'claude-xte', 'usage' => array( 'input_tokens' => 3, 'output_tokens' => 1 ) ) ) ) ) . "\n"
               . json_encode( array( 'custom_id' => $ids[1], 'result' => array( 'type' => 'errored', 'error' => array( 'type' => 'error', 'error' => array( 'type' => 'invalid_request_error', 'message' => 'bad' ) ) ) ) ) . "\n";
        file_put_contents( $this->mock['state'] . '/results.jsonl', $lines );
        file_put_contents( $this->mock['state'] . '/status.json', '{"processing_status":"ended","request_counts":{"succeeded":1,"errored":1},"ended_at":"2026-10-12T10:00:00Z"}' );
        $result = self::runJob( $poll, $pollJob() );
        $this->assertSame( array( 1, 1, 0, 0, true ), array( $result['succeeded'], $result['errored'], $result['retried'], $result['moved'], $result['deleted'] ) );
        $batch = expAiBatch::fetch( $batchID );
        $this->assertSame( 'done', (string) $batch->attribute( 'state' ) );
        $this->assertGreaterThan( 0, (int) $batch->attribute( 'deleted' ) );
        $first = expQueueJob::fetchByUUID( $ids[0] );
        $this->assertSame( 'Done', json_decode( (string) $first->attribute( 'checkpoint' ), true )['answer']['text'] );
        $this->assertSame( 'dead', (string) expQueueJob::fetchByUUID( $ids[1] )->attribute( 'state' ) );
        $this->assertSame( 'already done', self::runJob( $poll, $pollJob() )['message'] );

        try
        {
            self::runJob( $poll, self::job( array( 'batch_id' => 999999999 ) ) );
            $this->fail( 'polled a batch that is gone' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertSame( 'batch 999999999 is gone', $e->getMessage() );
        }
    }

    public function testPollCancelsABatchNobodyWaitsForAndRetriesWhenTheResultsCannotBeRead(): void
    {
        $jobs = $this->queued( 1 );
        $submitted = self::runJob( new expAiBatchSubmitHandler(), self::submitJob() );
        $batchID = $submitted['batch_id'];
        $poll = new expAiBatchPollHandler();
        $pollJob = function () use ( $batchID ) { return self::job( array( 'batch_id' => $batchID ), array( 'type' => expAiQueue::TYPE_POLL ) ); };
        // the only request was cancelled: the batch is cancelled at the provider
        expQueue::cancel( (string) $jobs[0]->attribute( 'uuid' ), 'xte' );
        file_put_contents( $this->mock['state'] . '/status.json', '{"processing_status":"in_progress"}' );
        $this->assertSame( expQueueResult::DEFER, self::runJob( $poll, $pollJob() )->outcome );
        $this->assertSame( 'canceling', (string) expAiBatch::fetch( $batchID )->attribute( 'state' ) );
        $this->assertStringContainsString( 'msgbatch_xte\\/cancel', (string) file_get_contents( $this->mock['state'] . '/requests.jsonl' ) );

        // ended, but the results cannot be fetched: a retry, the error kept on the batch
        $batch = expAiBatch::fetch( $batchID );
        $batch->touch( array( 'state' => 'ended' ) );
        $this->mode( 'e503' );
        try
        {
            self::runJob( $poll, $pollJob() );
            $this->fail( 'no retry' );
        }
        catch ( expQueueRetryableException $e )
        {
            $this->assertStringStartsWith( 'the batch results could not be read: ', $e->getMessage() );
        }
        $this->mode( 'ok' );
        // a batch whose lane is gone
        $batch->touch( array( 'lane' => 'gone' ) );
        try
        {
            self::runJob( $poll, $pollJob() );
            $this->fail( 'polled without a lane' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertSame( 'the lane xteq:gone is not configured any more', $e->getMessage() );
        }
    }

    public function testPollRetriesFailedItemsAndMovesExpiredOnes(): void
    {
        $jobs = $this->queued( 2 );
        $submitted = self::runJob( new expAiBatchSubmitHandler(), self::submitJob() );
        $batchID = $submitted['batch_id'];
        $ids = expAiBatch::fetch( $batchID )->customIds();
        file_put_contents( $this->mock['state'] . '/results.jsonl', json_encode( array( 'custom_id' => $ids[0], 'result' => array( 'type' => 'errored',
            'error' => array( 'type' => 'error', 'error' => array( 'type' => 'overloaded_error', 'message' => 'busy' ) ) ) ) ) . "\n" );
        file_put_contents( $this->mock['state'] . '/status.json', '{"processing_status":"ended"}' );
        $result = self::runJob( new expAiBatchPollHandler(), self::job( array( 'batch_id' => $batchID ), array( 'type' => expAiQueue::TYPE_POLL ) ) );
        $this->assertSame( array( 0, 1, 1 ), array( $result['succeeded'], $result['retried'], $result['moved'] ) );
        $this->assertSame( '', (string) expQueueJob::fetchByUUID( $ids[0] )->attribute( 'external_ref' ), 'the failed item waits for the next batch' );
        $this->assertSame( 'standard', (string) expQueueJob::fetchByUUID( $ids[1] )->attribute( 'lane' ), 'the expired item goes to the standard lane' );
    }
}
