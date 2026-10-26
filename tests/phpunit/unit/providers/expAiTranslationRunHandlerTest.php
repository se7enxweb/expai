<?php
/**
 * The job type expai.translation.run (expAiTranslationRunHandler): a translation by a machine translation service.
 * Transient and permanent errors, the budget hold and the settlement, the master switch, the checkpoint of a rerun,
 * the data policy, a translation edited meanwhile and placeholders that came back wrong. DB-less: the job, its
 * context, the budget, the translation sync and the content object are doubles that keep everything in memory; the
 * service is expAiFakeTranslationProvider.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

/** A queue context that keeps the checkpoint and the cost in memory. */
class expAiTranslationRunTestContext extends expQueueContext
{
    /** @var array|null */
    public $stored = null;

    /** @var int */
    public $costMicros = 0;

    public function heartbeat( $seconds = null )
    {
        return true;
    }

    public function isCancelled()
    {
        return false;
    }

    public function checkpoint()
    {
        return $this->stored;
    }

    public function saveCheckpoint( array $data )
    {
        $this->stored = $data;
        return true;
    }

    public function cost( $micros )
    {
        $this->costMicros = (int) $micros;
    }

    public function log( $message, array $data = array() )
    {
    }

    public function rateLimit( array $hints, $resource = null )
    {
    }
}

/** A budget whose refusal the test sets and whose settlements it reads. */
class expAiTranslationRunTestBudget extends expAiBudget
{
    /** @var array|null */
    public static $refusal = null;

    /** @var array list of array( job id, group id, micros ) */
    public static $settled = array();

    public function refusal( $micros, $groupID = 0, $excludeJobID = 0 )
    {
        return self::$refusal;
    }

    public function settle( $jobID, $groupID, $micros )
    {
        self::$settled[] = array( (int) $jobID, (int) $groupID, (int) $micros );
    }
}

/** A translation sync with a canned plan; states and drafts in memory. */
class expAiTranslationRunTestSync extends expAiTranslationSync
{
    /** @var array */
    public static $states = array();

    /** @var array list of array( answer, params ) */
    public static $applied = array();

    /** @var bool */
    public static $changed = false;

    /** @var array the plan of the job */
    public static $plan = array();

    public static function saveState( $objectID, $language, array $changes )
    {
        self::$states[$objectID . ':' . $language] = $changes + ( isset( self::$states[$objectID . ':' . $language] ) ? self::$states[$objectID . ':' . $language] : array() );
        return self::$states[$objectID . ':' . $language];
    }

    public static function changedSincePlan( eZContentObject $object, array $plan, array $params )
    {
        return self::$changed;
    }

    public function planForJob( eZContentObject $object, array $params )
    {
        return self::$plan;
    }

    public function applyAnswer( expQueueJob $job, array $answer, array $params )
    {
        self::$applied[] = array( $answer, $params );
        return array( 'draft_version' => 9, 'language' => $params['target'] );
    }
}

/** A recorder that only hands the request to the service (the usage row is the business of expAiTranslationRecorder's own tests). */
class expAiTranslationRunTestRecorder extends expAiTranslationRecorder
{
    public function translate( expAiTranslationRequest $request )
    {
        return $this->provider->translate( $request );
    }
}

class expAiTranslationRunTestHandler extends expAiTranslationRunHandler
{
    /** @var expAiConfig */
    public $testConfig;

    /** @var eZContentObject|null */
    public $testObject;

    protected function config()
    {
        return $this->testConfig;
    }

    protected function sync( expAiConfig $config )
    {
        return new expAiTranslationRunTestSync( $config );
    }

    protected function budget( expAiConfig $config )
    {
        return new expAiTranslationRunTestBudget( $config );
    }

    protected function recorder( expAiTranslationProvider $service, array $context )
    {
        return new expAiTranslationRunTestRecorder( $service, $context );
    }

    protected function object( $objectID )
    {
        return $this->testObject;
    }
}

class expAiTranslationRunHandlerTest extends expAiProviderTestCase
{
    /** @var expAiTranslationRunTestHandler */
    private $handler;

    protected function setUp(): void
    {
        parent::setUp();
        expAiFakeTranslationProvider::reset();
        expAiTranslationRunTestBudget::$refusal = null;
        expAiTranslationRunTestBudget::$settled = array();
        expAiTranslationRunTestSync::$states = array();
        expAiTranslationRunTestSync::$applied = array();
        expAiTranslationRunTestSync::$changed = false;
        expAiTranslationRunTestSync::$plan = array( 'object_id' => 77, 'source' => 'ger-DE', 'target' => 'eng-GB', 'units' => array(
            array( 'id' => 'u1', 'attribute' => 'body', 'index' => 1, 'kind' => 'block', 'old' => 'Alt', 'new' => 'Der <1>Hund</1> schläft.', 'translation' => 'Old',
                   'before' => '', 'after' => '' ),
            array( 'id' => 'u2', 'attribute' => 'title', 'index' => 0, 'kind' => 'text', 'old' => '', 'new' => 'Titel', 'translation' => '', 'before' => '', 'after' => '' ) ) );
        $this->handler = new expAiTranslationRunTestHandler();
        $this->handler->testConfig = $this->config( array(
            'AISettings' => array( 'Enabled' => 'true', 'DisabledSiteAccesses' => array() ),
            'TranslationProvider_fake' => array( 'Class' => 'expAiFakeTranslationProvider', 'Label' => 'Fake MT', 'Region' => 'eu', 'Retention' => 'none', 'PricePerMChars' => '1000000' ),
            'DataPolicy' => array( 'DefaultRegions' => array(), 'ClassRegions' => array(), 'SectionRegions' => array(), 'ZeroRetention' => 'disabled' ) ) );
        $this->handler->testObject = new class( array( 'id' => 77, 'section_id' => 1, 'name' => 'XT article' ) ) extends eZContentObject
        {
            public function attribute( $attr, $noFunction = false )
            {
                return $attr === 'class_identifier' ? 'article' : parent::attribute( $attr, true );
            }
        };
        $this->cleanup( static function () { expAiFakeTranslationProvider::reset(); } );
    }

    /** @return expQueueJob */
    private static function job( array $payload = array() )
    {
        return new expQueueJob( array( 'id' => 5, 'uuid' => 'aaaaaaaa-0000-4000-8000-000000000005', 'type' => expAiTranslationSync::RUN_JOB, 'state' => 'running',
            'user_id' => 14, 'group_id' => 3, 'cost_reserved_micros' => 100, 'cost_actual_micros' => 0, 'attempts' => 1, 'checkpoint' => '',
            'payload' => json_encode( $payload + array( 'provider' => 'fake', 'purpose' => 'translate', 'node_id' => 2, 'label' => 'Translation patch ger-DE -> eng-GB of object 77 by Fake MT',
                'apply_params' => array( 'object_id' => 77, 'source' => 'ger-DE', 'target' => 'eng-GB', 'base_version' => 1, 'base_from' => 'target_edit', 'to_version' => 2,
                                         'target_version' => 2, 'target_hash' => 'abc', 'mode' => 'patch', 'provider' => 'fake' ) ) ) ) );
    }

    /** @return array( expQueueResult, expAiTranslationRunTestContext ) */
    private function runJob( ?expQueueJob $job = null, ?expAiTranslationRunTestContext $context = null )
    {
        $job = $job ?: self::job();
        $context = $context ?: new expAiTranslationRunTestContext( $job, 'test' );
        return array( expQueueResult::from( $this->handler->run( $job, $context ) ), $context );
    }

    /** @return string the stored state of the test translation */
    private static function state()
    {
        return isset( expAiTranslationRunTestSync::$states['77:eng-GB'] ) ? expAiTranslationRunTestSync::$states['77:eng-GB'] : array();
    }

    public function testValidationAndDescription(): void
    {
        $this->assertSame( 'provider and apply_params (object_id, target, mode) are required', $this->handler->validate( array( 'provider' => 'fake' ) ) );
        $this->assertNull( $this->handler->validate( self::job()->payloadArray() ) );
        $this->assertSame( 'Translation patch ger-DE -> eng-GB of object 77 by Fake MT', $this->handler->describe( self::job() ) );
        $this->assertSame( 'Translation by fake of object 77', $this->handler->describe( self::job( array( 'label' => '' ) ) ) );
    }

    public function testATranslationIsStoredSettledAndApplied(): void
    {
        list( $result, $context ) = $this->runJob();
        $this->assertSame( expQueueResult::DONE, $result->outcome );
        $this->assertCount( 1, expAiFakeTranslationProvider::$batches );
        $this->assertSame( array( 'Der <x i="1">Hund</x> schläft.', 'Titel' ), expAiFakeTranslationProvider::$batches[0][0] );
        $this->assertStringNotContainsString( 'Old', json_encode( expAiFakeTranslationProvider::$batches ), 'the current translation is not sent' );
        $answer = $context->stored['answer'];
        $this->assertSame( array( array( 'id' => 'u1', 'text' => '[en-GB] Der <1>Hund</1> schläft.' ), array( 'id' => 'u2', 'text' => '[en-GB] Titel' ) ),
                           $answer['value']['units'] );
        $this->assertSame( array( 'fake-mt', 'end_turn', 'fake' ), array( $answer['model'], $answer['stop_reason'], $answer['provider'] ) );
        // 20 characters at a million per million characters: 20 currency units
        $chars = mb_strlen( 'Der Hund schläft.Titel', 'UTF-8' );
        $this->assertSame( array( $chars, $chars * 1000000 ), array( $answer['billed_chars'], $answer['cost_micros'] ) );
        $this->assertSame( array( array( 5, 3, $chars * 1000000 ) ), expAiTranslationRunTestBudget::$settled );
        $this->assertSame( $chars * 1000000, $context->costMicros );
        $this->assertCount( 1, expAiTranslationRunTestSync::$applied );
        $this->assertSame( $answer, expAiTranslationRunTestSync::$applied[0][0] );
        $this->assertSame( array( 9, 'fake' ), array( $result->data['applied']['draft_version'], $result->data['provider'] ) );

        // a rerun after a crash: the checkpoint is applied, nothing is sent or paid again
        list( $again, ) = $this->runJob( null, $context );
        $this->assertSame( expQueueResult::DONE, $again->outcome );
        $this->assertCount( 1, expAiFakeTranslationProvider::$batches );
        $this->assertCount( 1, expAiTranslationRunTestBudget::$settled );
        $this->assertCount( 2, expAiTranslationRunTestSync::$applied );
    }

    public function testTransientErrorsAreRetriedAndPermanentOnesFail(): void
    {
        $busy = new expAiTranslationException( 'The translation service %service is busy. Please try again later.', 'fake: HTTP 429', 429, array( '%service' => 'Fake MT' ), true );
        $busy->retryAfter = 12;
        expAiFakeTranslationProvider::$failure = $busy;
        list( $result, ) = $this->runJob();
        $this->assertSame( array( expQueueResult::RETRY, 12, 'http_429', 'fake: fake: HTTP 429' ), array( $result->outcome, $result->seconds, $result->errorClass, $result->message ) );
        $this->assertSame( array(), self::state(), 'a retry leaves the state as it is' );

        expAiFakeTranslationProvider::$failure = new expAiTranslationException( 'The translation service %service could not be reached.', 'curl error 28', 0, array( '%service' => 'Fake MT' ), true );
        list( $result, ) = $this->runJob();
        $this->assertSame( array( expQueueResult::RETRY, 'transport' ), array( $result->outcome, $result->errorClass ) );

        expAiFakeTranslationProvider::$failure = new expAiTranslationException( 'The quota of the translation service %service is used up.', 'fake: HTTP 456', 456, array( '%service' => 'Fake MT' ), false );
        list( $result, ) = $this->runJob();
        $this->assertSame( array( expQueueResult::FAIL, 'http_456' ), array( $result->outcome, $result->errorClass ) );
        $this->assertSame( 'fake: The quota of the translation service Fake MT is used up. (fake: HTTP 456)', $result->message );
        $this->assertSame( array( 'failed', $result->message ), array( self::state()['state'], self::state()['error'] ) );
        $this->assertSame( array(), expAiTranslationRunTestBudget::$settled );
        $this->assertSame( array(), expAiTranslationRunTestSync::$applied );
    }

    public function testBudgetLimitsAndTheMasterSwitchHoldTheJob(): void
    {
        expAiTranslationRunTestBudget::$refusal = array( 'message' => 'the monthly site budget of 1.00 is reached' );
        list( $result, ) = $this->runJob();
        $this->assertSame( array( expQueueResult::HOLD, 'budget: the monthly site budget of 1.00 is reached' ), array( $result->outcome, $result->message ) );
        $this->assertSame( array(), expAiFakeTranslationProvider::$batches, 'nothing is sent' );

        expAiTranslationRunTestBudget::$refusal = null;
        expAiFakeTranslationProvider::$failure = new expAiLimitException( 'The limit %limit is reached (%used of %max). It resets %reset.', 'limit MaxCostPerSitePerMonth' );
        $this->assertSame( expQueueResult::HOLD, $this->runJob()[0]->outcome );
        // any other error that is not the service's: not retried, failed with the reason
        expAiFakeTranslationProvider::$failure = new expAiException( 'Something else.', 'limit-like detail' );
        $this->assertSame( expQueueResult::FAIL, $this->runJob()[0]->outcome );
        expAiTranslationRunTestSync::$states = array();

        $this->handler->testConfig->ini()->setVariable( 'AISettings', 'Enabled', 'false' );
        list( $result, ) = $this->runJob();
        $this->assertSame( array( expQueueResult::HOLD, expAiGate::HELD_PREFIX . 'Exponential Intelligence is switched off.' ), array( $result->outcome, $result->message ) );
        $this->assertSame( array(), self::state() );
    }

    public function testWhatIsRefusedBeforeAnythingIsSent(): void
    {
        list( $result, ) = $this->runJob( self::job( array( 'provider' => 'nothing' ) ) );
        $this->assertSame( array( expQueueResult::FAIL, 'provider' ), array( $result->outcome, $result->errorClass ) );
        $this->assertSame( 'the translation service nothing is not available: there is no [TranslationProvider_nothing] group', $result->message );

        $this->handler->testConfig->ini()->setVariable( 'DataPolicy', 'ClassRegions', array( 'article' => 'local' ) );
        list( $result, ) = $this->runJob();
        $this->assertSame( array( expQueueResult::FAIL, 'data_policy' ), array( $result->outcome, $result->errorClass ) );
        $this->assertStringStartsWith( 'the data policy refuses object 77 for fake: regions local required', $result->message );
        $this->handler->testConfig->ini()->setVariable( 'DataPolicy', 'ClassRegions', array() );

        expAiTranslationRunTestSync::$changed = true;
        list( $result, ) = $this->runJob();
        $this->assertSame( array( expQueueResult::FAIL, 'conflict' ), array( $result->outcome, $result->errorClass ) );
        $this->assertSame( 'failed', self::state()['state'] );
        expAiTranslationRunTestSync::$changed = false;

        $this->handler->testObject = null;
        try
        {
            $this->runJob();
            $this->fail( 'ran for an object that is gone' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertSame( 'the object is gone', $e->getMessage() );
        }
        $this->assertSame( array(), expAiFakeTranslationProvider::$batches );
    }

    public function testPlaceholdersThatComeBackWrongFailWithoutADraft(): void
    {
        expAiFakeTranslationProvider::$answer = function ( array $texts ) { return array( 'The dog sleeps.', 'Title' ); };
        list( $result, $context ) = $this->runJob();
        $this->assertSame( array( expQueueResult::FAIL, 'markup' ), array( $result->outcome, $result->errorClass ) );
        $this->assertSame( 'fake: the placeholders of u1 came back wrong (placeholder 1 missing)', $result->message );
        $this->assertNull( $context->stored, 'no checkpoint of a broken answer' );
        $this->assertSame( array(), expAiTranslationRunTestSync::$applied );
        $chars = mb_strlen( 'Der Hund schläft.Titel', 'UTF-8' );
        $this->assertSame( array( array( 5, 3, $chars * 1000000 ) ), expAiTranslationRunTestBudget::$settled, 'what was sent was billed: the cost is settled' );
        $this->assertSame( $chars * 1000000, $context->costMicros );
    }

    public function testARetryResumesAfterTheRequestsThatWereAnswered(): void
    {
        $this->handler->testConfig->ini()->setVariable( 'TranslationProvider_fake', 'MaxTextsPerRequest', '1' );
        $calls = 0;
        expAiFakeTranslationProvider::$answer = function ( array $texts ) use ( &$calls )
        {
            if ( ++$calls === 2 )
                throw new expAiTranslationException( 'The translation service %service is busy. Please try again later.', 'fake: HTTP 429', 429, array( '%service' => 'Fake MT' ), true );
            return array_map( function ( $t ) { return 'T:' . $t; }, $texts );
        };
        list( $result, $context ) = $this->runJob();
        $this->assertSame( expQueueResult::RETRY, $result->outcome );
        $this->assertSame( array( 0 => 'T:Der <x i="1">Hund</x> schläft.' ), $context->stored['partial']['texts'] );
        list( $again, $context ) = $this->runJob( null, $context );
        $this->assertSame( expQueueResult::DONE, $again->outcome );
        $this->assertSame( array( 'Titel' ), end( expAiFakeTranslationProvider::$batches )[0], 'only the request that failed is sent again' );
        $this->assertSame( array( array( 'id' => 'u1', 'text' => 'T:Der <1>Hund</1> schläft.' ), array( 'id' => 'u2', 'text' => 'T:Titel' ) ),
                           $context->stored['answer']['value']['units'] );
        $this->assertSame( 5 * 1000000, $context->stored['answer']['cost_micros'], 'the retry bills what it sent' );
    }
}
