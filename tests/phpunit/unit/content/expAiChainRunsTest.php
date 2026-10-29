<?php
/**
 * AI chains around the queue (doc/chains.md): starting a run and what start refuses, a run brought in line with its
 * queue job (held, running again, dead, gone), resuming a run held by its budget, the template fetches, the policy,
 * the translation hand-off, the run and step rows, and the offline link check and answer cleaning. The chain's model
 * is the stub provider; its resource is paused, so no worker of the site runs a step, and no step is answered.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicontenttestcase.php';

class expAiChainRunsTest extends expAiContentTestCase
{
    /** @return array the settings of the chain xt: write, review, an editor's checkpoint, translate */
    private static function chainSettings( $maxCost = '1.00', $class = 'article' )
    {
        return array(
            'ChainSettings' => array( 'Chains' => array( 'xt' ), 'MaxBriefLength' => '200', 'TranslateHandler' => 'expAiChainTranslateSync' ),
            'Chain_xt' => array( 'Label' => 'XT chain', 'Description' => 'Writes a test article', 'Class' => $class, 'Fields' => array( 'title', 'intro', 'body' ),
                                   'Steps' => array( 'write', 'review', 'checkpoint', 'translate' ), 'MaxCostPerRun' => $maxCost, 'MaxItems' => '2' ),
            'ChainStep_xt.write' => array( 'Type' => 'generate', 'Label' => 'Write', 'Model' => self::ALIAS, 'PromptText' => 'Write about {brief}.', 'MaxTokens' => '1000' ),
            'ChainStep_xt.review' => array( 'Type' => 'review', 'Label' => 'Review', 'Model' => self::ALIAS, 'PromptText' => 'Review it.', 'PassCriteria' => array( 'score>=80' ),
                                              'OnFail' => 'revise', 'ReviseStep' => 'write', 'MaxIterations' => '2', 'Checklist' => array( 'Clear' ) ),
            'ChainStep_xt.checkpoint' => array( 'Type' => 'human', 'Label' => 'Approve' ),
            'ChainStep_xt.translate' => array( 'Type' => 'translate', 'Label' => 'Translate', 'Languages' => array( 'all' ) ),
        );
    }

    /** Removes the runs below the folder with their steps, job groups, jobs and drafts after the test. */
    private function forgetRuns( eZContentObjectTreeNode $folder )
    {
        $state = expAiTestMockServer::tmp( 'chain-rows' );
        file_put_contents( $state . '/t-rows.json', json_encode( array( 'node' => (int) $folder->attribute( 'node_id' ) ) ) );
        $this->cleanup( static function () use ( $state )
        {
            expAiScriptFixture::removeMockRows( array( self::PROVIDER ), array(), $state );
            expAiTestMockServer::removeTree( $state );
        } );
    }

    private static function setJob( $uuid, array $values )
    {
        $db = eZDB::instance();
        $set = array();
        foreach ( $values as $name => $value )
            $set[] = $name . " = '" . $db->escapeString( (string) $value ) . "'";
        $db->query( 'UPDATE expqueue_job SET ' . implode( ', ', $set ) . " WHERE uuid = '" . $db->escapeString( $uuid ) . "'" );
    }

    public function testStartReconcileAndResume(): void
    {
        $config = $this->siteSettings( self::chainSettings() );
        $folder = $this->folder();
        $this->forgetRuns( $folder );
        $this->asUser( 'admin' );
        $nodeID = (int) $folder->attribute( 'node_id' );
        $engine = new expAiChainEngine( $config );

        $this->assertRefused( function () use ( $engine, $nodeID ) { $engine->start( 'xt', $nodeID, '   ' ); }, 'Write a brief' );
        $this->assertRefused( function () use ( $engine ) { $engine->start( 'xt', 0, 'A brief' ); }, 'does not exist' );
        $this->assertRefused( function () use ( $engine, $nodeID ) { $engine->start( 'nochain', $nodeID, 'A brief' ); }, 'There is no chain' );

        $runs = $engine->start( 'xt', $nodeID, str_repeat( 'Bread and rolls. ', 30 ), 5 );
        $this->assertCount( 2, $runs, 'MaxItems caps the count' );
        $run = $runs[0];
        $this->assertSame( array( 'running', 'write', 200 ), array( $run->attribute( 'state' ), $run->attribute( 'current_step' ), mb_strlen( $run->attribute( 'brief' ) ) ) );
        $this->assertStringContainsString( '(1/2)', $run->attribute( 'label' ) );
        $steps = $run->steps();
        $this->assertCount( 1, $steps );
        $step = $steps[0];
        $uuid = (string) $step->attribute( 'job_uuid' );
        $this->assertSame( array( self::ALIAS, self::PROVIDER ), array( $step->attribute( 'model' ), $step->attribute( 'provider' ) ) );
        $this->assertSame( $step->attribute( 'id' ), expAiChainStep::fetchByJob( $uuid )->attribute( 'id' ) );
        $this->assertNull( expAiChainStep::fetchByJob( '' ) );
        $this->assertContains( $step->jobState(), array( 'queued', 'ready', 'held' ) );
        $this->assertSame( $run->attribute( 'id' ), expAiChainRun::fetch( $run->attribute( 'uuid' ) )->attribute( 'id' ) );

        // the queue job decides the run's state
        self::setJob( $uuid, array( 'state' => 'queued' ) );
        $this->assertSame( 'running', $engine->reconcile( $run )->attribute( 'state' ) );
        self::setJob( $uuid, array( 'state' => 'held', 'last_error' => 'budget: the site budget is spent' ) );
        $held = $engine->reconcile( expAiChainRun::fetch( (int) $run->attribute( 'id' ) ) );
        $this->assertSame( array( 'held', 'budget: the site budget is spent' ), array( $held->attribute( 'state' ), $held->attribute( 'last_error' ) ) );
        // resume: the held job is retried, the run budget grows
        $resumed = $engine->resume( $held );
        $this->assertSame( 'running', $resumed->attribute( 'state' ) );
        $this->assertSame( 1000000, $resumed->dataArray()['cap_extra'] );
        $this->assertNotSame( 'held', (string) expQueue::fetch( $uuid )->attribute( 'state' ) );
        self::setJob( $uuid, array( 'state' => 'held' ) );
        $engine->reconcile( expAiChainRun::fetch( (int) $run->attribute( 'id' ) ) );
        self::setJob( $uuid, array( 'state' => 'running' ) );
        $this->assertSame( 'running', $engine->reconcile( expAiChainRun::fetch( (int) $run->attribute( 'id' ) ) )->attribute( 'state' ), 'running again after "Resume held jobs"' );
        $this->assertRefused( function () use ( $engine, $run ) { $engine->resume( expAiChainRun::fetch( (int) $run->attribute( 'id' ) ) ); }, 'not held' );
        self::setJob( $uuid, array( 'state' => 'dead', 'last_error' => 'the provider said no' ) );
        $failed = $engine->reconcile( expAiChainRun::fetch( (int) $run->attribute( 'id' ) ) );
        $this->assertSame( 'failed', $failed->attribute( 'state' ) );
        $this->assertStringContainsString( 'ended dead: the provider said no', $failed->attribute( 'last_error' ) );
        $this->assertSame( 'failed', $failed->steps()[0]->attribute( 'state' ) );
        $this->assertTrue( $failed->isFinal() );
        $this->assertSame( 'failed', $engine->reconcile( $failed )->attribute( 'state' ), 'a final run stays as it is' );

        // the second run: its job is gone
        $second = $runs[1];
        $secondJob = (string) $second->steps()[0]->attribute( 'job_uuid' );
        eZDB::instance()->query( "DELETE FROM expqueue_job WHERE uuid = '" . eZDB::instance()->escapeString( $secondJob ) . "'" );
        $gone = $engine->reconcile( $second );
        $this->assertSame( 'failed', $gone->attribute( 'state' ) );
        $this->assertStringContainsString( 'is gone', $gone->attribute( 'last_error' ) );

        // the template fetches
        $fetch = new expAiChainFunctionCollection();
        $chains = $fetch->fetchChains()['result'];
        $this->assertSame( array( 'xt', 'XT chain', 'article', 2, array( 'Write', 'Review', 'Approve', 'Translate' ), '$1.00' ),
                           array( $chains[0]['id'], $chains[0]['label'], $chains[0]['class'], $chains[0]['max_items'], $chains[0]['steps'], $chains[0]['max_cost'] ) );
        $this->assertTrue( expAiChainFunctionCollection::installed() );
        $list = $fetch->fetchRuns( 5, $nodeID )['result'];
        $this->assertCount( 2, $list['rows'] );
        $this->assertSame( array( 'failed', 'Failed' ), array( $list['rows'][0]['state'], $list['rows'][0]['state_label'] ) );
        $this->assertSame( 0, $fetch->fetchRuns( 5, 1 )['result']['rows'] ? -1 : 0, 'no run at another location' );

        // the policy
        $anonymous = eZUser::fetch( eZUser::anonymousId() );
        $this->assertTrue( expAiChainPolicy::maySee( $failed ) );
        $this->assertTrue( expAiChainPolicy::mayAct( $failed ) );
        $this->assertFalse( expAiChainPolicy::maySee( $failed, $anonymous ) );
        $this->assertFalse( expAiChainPolicy::mayAct( $failed, $anonymous ) );
        $policy = new expAiChainPolicy();
        $this->assertSame( array( array( 'id' => 'xt', 'name' => 'XT chain (xt)' ) ), $policy->chains() );
        $this->assertRefused( function () use ( $engine, $nodeID, $anonymous ) {
            eZUser::setCurrentlyLoggedInUser( $anonymous, (int) $anonymous->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
            $engine->start( 'xt', $nodeID, 'A brief' ); }, 'You may not start the chain' );
        $this->asUser( 'admin' );
    }

    public function testARunHeldByItsBudgetContinues(): void
    {
        $config = $this->siteSettings( self::chainSettings( '0.000001' ) );
        $folder = $this->folder();
        $this->forgetRuns( $folder );
        $this->asUser( 'admin' );
        $engine = new expAiChainEngine( $config );
        $run = $engine->start( 'xt', (int) $folder->attribute( 'node_id' ), 'A brief' )[0];
        $this->assertSame( 'held', $run->attribute( 'state' ) );
        $this->assertStringContainsString( 'the run budget of', $run->attribute( 'last_error' ) );
        $this->assertSame( 'write', $run->dataArray()['pending']['step'] );
        $this->assertSame( 'held', $run->steps()[0]->attribute( 'state' ) );
        // the editor's "Continue": one more MaxCostPerRun, still not enough for the step
        $again = $engine->resume( $run );
        $this->assertNotNull( $again );
        $this->assertSame( 'held', expAiChainRun::fetch( (int) $run->attribute( 'id' ) )->attribute( 'state' ) );
        $config->ini()->setVariable( 'Chain_xt', 'MaxCostPerRun', '100' );
        $data = $run->dataArray();
        $stopped = $engine->stop( expAiChainRun::fetch( (int) $run->attribute( 'id' ) ), 'XT stop' );
        $this->assertSame( array( 'stopped', 'XT stop' ), array( $stopped->attribute( 'state' ), $stopped->attribute( 'last_error' ) ) );
        $this->assertSame( $stopped, $engine->stop( $stopped ), 'a final run is not stopped again' );
        $this->assertArrayHasKey( 'pending', $data );

        // an answer that arrives for an ended run, twice, and one for a run that is gone
        $job = new expQueueJob( array( 'uuid' => expAiChainEngine::uuid() ) );
        $params = array( 'run' => (int) $run->attribute( 'id' ), 'step' => 'write', 'iteration' => 0 );
        $late = $engine->onAnswer( $job, array( 'text' => '{"fields": {"title": "Late"}}', 'tokens' => array( 'input' => 10, 'cache_read' => 5, 'output' => 3 ), 'cost_micros' => 7 ), $params );
        $this->assertSame( 'the run had ended', $late['chain'] );
        $row = expAiChainRun::fetch( (int) $run->attribute( 'id' ) )->steps()[0];
        $this->assertSame( array( 'done', 15, 3, array( 'fields' => array( 'title' => 'Late' ) ) ),
                           array( $row->attribute( 'state' ), (int) $row->attribute( 'input_tokens' ), (int) $row->attribute( 'output_tokens' ), $row->outputArray() ) );
        $this->assertSame( 'already recorded', $engine->onAnswer( $job, array(), $params )['chain'] );
        $this->assertSame( 'the run is gone', $engine->onAnswer( $job, array(), array( 'run' => 2147483646 ) )['chain'] );

        // the policy for someone else's run
        $other = new expAiChainRun( array( 'chain' => 'xt', 'user_id' => 999999999, 'node_id' => 2, 'object_id' => 0 ) );
        $this->assertTrue( expAiChainPolicy::maySee( $other ), 'expai/log sees every run' );
        $this->assertFalse( expAiChainPolicy::mayAct( $other ), 'without a draft to edit nobody else acts on it' );

        // the checkpoint needs a run that waits, and a note for changes
        $this->assertRefused( function () use ( $engine, $other ) { $engine->approve( $other ); }, 'not waiting for an editor' );
        $other->setAttribute( 'state', expAiChainRun::STATE_WAITING );
        $this->assertRefused( function () use ( $engine, $other ) { $engine->requestChanges( $other, '  ' ); }, 'Say what should change' );
        // a step the chain does not have ends the run
        $ended = $engine->enqueueStep( expAiChainRun::fetch( (int) $run->attribute( 'id' ) ), 'no_such_step', 0 );
        $this->assertSame( array( 'failed', 'the step no_such_step is not part of the chain' ), array( $ended->attribute( 'state' ), $ended->attribute( 'last_error' ) ) );
    }

    public function testAStepWithAnUnknownModelEndsTheRun(): void
    {
        $settings = self::chainSettings();
        $settings['ChainStep_xt.write']['Model'] = 'xtnosuch.model';
        $config = $this->siteSettings( $settings );
        $folder = $this->folder();
        $this->forgetRuns( $folder );
        $this->asUser( 'admin' );
        $run = ( new expAiChainEngine( $config ) )->start( 'xt', (int) $folder->attribute( 'node_id' ), 'A brief' )[0];
        $this->assertSame( 'failed', $run->attribute( 'state' ) );
        $this->assertStringContainsString( 'the model xtnosuch.model of the step write is not in the provider registry', $run->attribute( 'last_error' ) );
    }

    public function testAnAnswerThatIsNotTheJsonOfTheStepEndsTheRun(): void
    {
        $config = $this->siteSettings( self::chainSettings() );
        $folder = $this->folder();
        $this->forgetRuns( $folder );
        $this->asUser( 'admin' );
        $engine = new expAiChainEngine( $config );
        $run = $engine->start( 'xt', (int) $folder->attribute( 'node_id' ), 'A brief' )[0];
        $this->assertSame( 'running', $run->attribute( 'state' ) );
        $job = new expQueueJob( array( 'uuid' => expAiChainEngine::uuid() ) );
        $answer = $engine->onAnswer( $job, array( 'text' => 'Here is your article, without any JSON.', 'stop_reason' => 'end_turn' ),
                                     array( 'run' => (int) $run->attribute( 'id' ), 'step' => 'write', 'iteration' => 0 ) );
        $this->assertSame( array( 'failed', 'write' ), array( $answer['chain'], $answer['step'] ) );
        $run = expAiChainRun::fetch( (int) $run->attribute( 'id' ) );
        $this->assertSame( 'failed', $run->attribute( 'state' ) );
        $this->assertStringContainsString( 'the step write did not answer in the expected format', $run->attribute( 'last_error' ) );
        $step = $run->steps()[0];
        $this->assertSame( 'failed', $step->attribute( 'state' ) );
        $this->assertSame( 'the answer was not the JSON the step asks for (stop reason end_turn)', $step->attribute( 'note' ) );
        $this->assertSame( array( 'text' => 'Here is your article, without any JSON.' ), $step->outputArray(), 'the text is kept for the editor' );
    }

    public function testAnAnswerCutOffAtTheTokenLimitEndsTheRunWithAHint(): void
    {
        $config = $this->siteSettings( self::chainSettings() );
        $folder = $this->folder();
        $this->forgetRuns( $folder );
        $this->asUser( 'admin' );
        $engine = new expAiChainEngine( $config );
        $run = $engine->start( 'xt', (int) $folder->attribute( 'node_id' ), 'A brief' )[0];
        $job = new expQueueJob( array( 'uuid' => expAiChainEngine::uuid() ) );
        // a complete object inside the cut text is not taken for the answer
        $answer = $engine->onAnswer( $job, array( 'text' => '{"fields": {"title": "Spring"}, "note": "', 'value' => array( 'fields' => array( 'title' => 'Spring' ) ), 'stop_reason' => 'max_tokens' ),
                                     array( 'run' => (int) $run->attribute( 'id' ), 'step' => 'write', 'iteration' => 0 ) );
        $this->assertSame( array( 'failed', 'write' ), array( $answer['chain'], $answer['step'] ) );
        $run = expAiChainRun::fetch( (int) $run->attribute( 'id' ) );
        $this->assertSame( 'failed', $run->attribute( 'state' ) );
        $this->assertStringContainsString( 'Raise MaxTokens or lower Effort for this model.', $run->attribute( 'last_error' ) );
        $this->assertSame( 'the answer was cut off at the token limit (stop reason max_tokens)', $run->steps()[0]->attribute( 'note' ) );
    }

    public function testAChainWithAMissingClassIsRefused(): void
    {
        $config = $this->siteSettings( self::chainSettings( '1.00', 'xt_no_such_class' ) );
        $folder = $this->folder();
        $this->asUser( 'admin' );
        $engine = new expAiChainEngine( $config );
        $this->assertRefused( function () use ( $engine, $folder ) { $engine->start( 'xt', (int) $folder->attribute( 'node_id' ), 'A brief' ); }, 'There is no content class' );
        $config->ini()->setVariable( 'AISettings', 'Enabled', 'false' );
        $this->assertRefused( function () use ( $engine, $folder ) { $engine->start( 'xt', (int) $folder->attribute( 'node_id' ), 'A brief' ); }, 'expai is disabled' );
    }

    public function testRunAndStepRows(): void
    {
        $this->siteSettings( array( 'ChainSettings' => array( 'MaxStoredOutputBytes' => '2000' ) ) );
        $this->inTransaction();
        $run = new expAiChainRun( array( 'uuid' => expAiChainEngine::uuid(), 'chain' => 'xt', 'state' => expAiChainRun::STATE_WAITING, 'node_id' => 2,
                                         'user_id' => 14, 'cost_micros' => 1234567, 'data' => '' ) );
        $run->store();
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $run->attribute( 'uuid' ) );
        $this->assertSame( array(), $run->dataArray() );
        $run->mergeData( array( 'a' => 1, 'b' => 2 ) );
        $run->mergeData( array( 'a' => null ) );
        $this->assertSame( array( 'b' => 2 ), $run->dataArray() );
        $this->assertRefused( function () use ( $run ) { $run->chain(); }, 'has no snapshot' );
        $this->assertSame( array( false, 'Waiting for an editor', 'odd' ), array( $run->isFinal(), $run->stateLabel(), expAiChainRun::label( 'odd' ) ) );
        $this->assertSame( 2, (int) $run->node()->attribute( 'node_id' ) );
        $this->assertSame( 14, (int) $run->user()->attribute( 'contentobject_id' ) );
        $this->assertSame( '$1.23', $run->costMoney() );
        $this->assertSame( 1, expAiChainRun::fetchCount( array( 'uuid' => $run->attribute( 'uuid' ) ) ) );

        $step = new expAiChainStep( array( 'run_id' => (int) $run->attribute( 'id' ), 'step' => 'write', 'cost_micros' => 20000, 'output' => 'not json' ) );
        $this->assertSame( array(), $step->outputArray() );
        $step->setOutput( array( 'title' => 'Short' ) );
        $this->assertSame( array( 'title' => 'Short' ), $step->outputArray() );
        $step->setOutput( array( 'body' => str_repeat( 'long text ', 500 ) ) );
        $this->assertTrue( $step->outputArray()['cut'], 'an output above MaxStoredOutputBytes is cut' );
        $this->assertSame( '', $step->jobState() );
        $this->assertSame( '$0.02', $step->costMoney() );
        $this->assertSame( expAiChainFunctionCollection::row( $run )['state'], expAiChainRun::STATE_WAITING );
    }

    public function testTheTranslationHandOff(): void
    {
        $config = $this->siteSettings( array( 'TranslationSync' => array( 'Enabled' => 'enabled', 'SourceLanguages' => array(), 'TargetLanguages' => array(),
                                                                           'AutoQueuePatch' => 'disabled' ) ) );
        $folder = $this->folder();
        $this->asUser( 'admin' );
        $run = new expAiChainRun( array( 'uuid' => expAiChainEngine::uuid(), 'chain' => 'xt', 'user_id' => 14, 'object_id' => 0 ) );
        $this->assertStringContainsString( 'not published yet', expAiChainTranslateSync::chainTranslate( $run, array( self::TARGET ) )['skipped'] );
        $single = $this->article( (int) $folder->attribute( 'node_id' ), 'XT hand-off', '<paragraph>Intro</paragraph>', '<paragraph>Body</paragraph>' );
        $run->setAttribute( 'object_id', (int) $single->attribute( 'id' ) );
        $this->assertStringContainsString( 'has no translation in ger-DE', expAiChainTranslateSync::chainTranslate( $run, array( self::TARGET ) )['skipped'] );
        self::publishVersion( eZContentObject::fetch( (int) $single->attribute( 'id' ) ), self::TARGET, array( 'title' => 'XT Übergabe' ) );
        $handed = expAiChainTranslateSync::chainTranslate( $run, array( self::TARGET ) );
        $this->assertSame( array( 'noop', 'up_to_date' ), array( $handed['languages'][self::TARGET]['decision'], $handed['languages'][self::TARGET]['state'] ) );
        $config->ini()->setVariable( 'TranslationSync', 'Enabled', 'disabled' );
        $this->assertStringContainsString( 'disabled', expAiChainTranslateSync::chainTranslate( $run, array( self::TARGET ) )['skipped'] );
    }

    public function testTheOfflineLinkCheckAndTheCleaning(): void
    {
        $url = eZURL::registerURL( 'https://example.org/xt-chain' );
        $links = expAiChainEngine::checkLinks( array(
            'body' => '<a href="https://example.org/ok">a</a> <a href="http://nohost">b</a> <a href="mailto:info@example.org">c</a> <a href="mailto:nobody">d</a>'
                    . ' <a href="eznode://2">e</a> <a href="eznode://999999999">f</a> <a href="ezobject://1">g</a> <a href="ezobject://999999999">h</a>'
                    . " <a href='/content/view/full/2'>i</a> <a href=\"ezurl://" . (int) $url . '">j</a> <a href="relative/page">k</a>',
            'title' => 'no links' ) );
        $byUrl = array();
        foreach ( $links as $link )
            $byUrl[$link['url']] = array( $link['ok'], $link['why'] );
        $this->assertSame( array( true, '' ), $byUrl['https://example.org/ok'] );
        $this->assertSame( array( false, 'not a valid address' ), $byUrl['http://nohost'] );
        $this->assertSame( true, $byUrl['mailto:info@example.org'][0] );
        $this->assertSame( false, $byUrl['mailto:nobody'][0] );
        $this->assertSame( array( true, '' ), $byUrl['eznode://2'] );
        $this->assertSame( array( false, 'no node 999999999' ), $byUrl['eznode://999999999'] );
        $this->assertSame( array( false, 'no object 999999999' ), $byUrl['ezobject://999999999'] );
        $this->assertSame( true, $byUrl['/content/view/full/2'][0] );
        $this->assertSame( true, $byUrl['ezurl://' . (int) $url][0] );
        $this->assertSame( array( false, 'not an absolute http(s), mailto or internal link' ), $byUrl['relative/page'] );

        $this->assertSame( array( 'title' => 'T', 'intro' => 'I' ),
                           expAiChainEngine::cleanFields( array( array( 'identifier' => 'title', 'value' => ' T ' ), 'intro' => 'I', 'body' => array( 1 ), 'other' => 'x' ), array( 'title', 'intro', 'body' ) ) );
        $this->assertSame( array(), expAiChainEngine::cleanFields( 'text', array( 'title' ) ) );
        $review = expAiChainEngine::cleanReview( array( 'score' => '0.85', 'summary' => 'Fine', 'checklist' => array( array( 'item' => 'other wording', 'ok' => 'false' ) ),
                                                        'issues' => array( array( 'text' => 'Typo', 'severity' => 'LOW' ), array( 'text' => 'Odd', 'severity' => 'urgent' ), array( 'no' => 'text' ) ) ),
                                                 array( 'checklist' => array( 'Clear', 'Short' ) ) );
        $this->assertSame( 85, $review['score'], 'a score on a 0..1 scale is taken as a percentage' );
        $this->assertSame( array( array( 'item' => 'Clear', 'ok' => false ), array( 'item' => 'Short', 'ok' => false ) ), $review['checklist'] );
        $this->assertSame( array( 'low', 'medium' ), array_column( $review['issues'], 'severity' ) );
        $this->assertNull( expAiChainEngine::cleanReview( array(), array( 'checklist' => array() ) )['score'] );
        $this->assertSame( 100, expAiChainEngine::cleanReview( array( 'score' => 140 ), array( 'checklist' => array() ) )['score'] );
    }
}
