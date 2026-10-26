<?php
/**
 * The translation sync with translation services (doc/translation-providers.md): a machine translation service
 * queues expai.translation.run instead of expai.request, its estimate is what CostThreshold sees, no_provider and
 * blocked are reported and never queued, Approve refuses them, the state rows name the service (old rows: llm), the
 * chain step is skipped with the reason. The first tests are DB-less (a sync whose plans, states and queue are in
 * memory); the last runs the whole way on an article of the installation: check, the run job, the draft and the
 * usage row.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicontenttestcase.php';

/** A translation sync whose plans the test gives, with the states and the queued jobs in memory. */
class expAiTranslationSyncProviderTestSync extends expAiTranslationSync
{
    /** @var array target => plan */
    public $plans = array();

    /** @var array list of array( payload, options, estimate ) */
    public $runs = array();

    /** @var array list of the gate contexts */
    public $gates = array();

    /** @var array "object:language" => state */
    public static $states = array();

    public function plan( eZContentObject $object, $target, array $options = array() )
    {
        return $this->plans[$target];
    }

    public static function state( $objectID, $language )
    {
        return isset( self::$states[$objectID . ':' . $language] ) ? self::$states[$objectID . ':' . $language] : array();
    }

    public static function saveState( $objectID, $language, array $changes )
    {
        self::$states[$objectID . ':' . $language] = array_merge( self::state( $objectID, $language ), $changes );
        return self::$states[$objectID . ':' . $language];
    }

    public static function jobActive( $uuid )
    {
        return false;
    }

    protected static function queueAvailable()
    {
        return true;
    }

    protected function userID()
    {
        return 14;
    }

    protected function gate( array $known )
    {
        $this->gates[] = $known;
    }

    protected function enqueueRun( array $payload, array $options, $estimate )
    {
        $this->runs[] = array( $payload, $options, $estimate );
        return array( 'job' => new expQueueJob( array( 'uuid' => 'bbbbbbbb-0000-4000-8000-00000000000' . count( $this->runs ) ) ), 'held' => '' );
    }

    /** @return array cost() for the test */
    public function costOf( array $choice, array $plan, $mode )
    {
        return $this->cost( $choice, $plan, $mode );
    }
}

class expAiTranslationSyncProviderTest extends expAiContentTestCase
{
    /** @return expAiConfig the shipped settings with a fake machine translation service */
    private function mtConfig( array $more = array() )
    {
        $config = $this->config( array( 'AISettings' => array( 'Enabled' => 'true' ),
            'TranslationProvider_fake' => array( 'Class' => 'expAiFakeTranslationProvider', 'Label' => 'Fake MT', 'Region' => 'eu', 'Retention' => 'none',
                                                 'PricePerMChars' => '1000', 'PriceCurrency' => '' ) ) );
        foreach ( $more as $group => $values )
            foreach ( $values as $name => $value )
                $config->ini()->setVariable( $group, $name, $value );
        return $config;
    }

    /** @return eZContentObject an object in eng-US with a ger-DE translation that never touches the database */
    private static function object()
    {
        return new class( array( 'id' => 88, 'section_id' => 1, 'name' => 'XT', 'current_version' => 3, 'initial_language_code' => 'eng-US' ) ) extends eZContentObject
        {
            public function attribute( $attr, $noFunction = false )
            {
                if ( $attr === 'class_identifier' )
                    return 'article';
                if ( $attr === 'main_node' )
                    return null;
                if ( $attr === 'initial_language_code' )
                    return 'eng-US';
                return parent::attribute( $attr, true );
            }

            public function allLanguages()
            {
                return array( 'eng-US' => true, 'ger-DE' => true );
            }

            public function canEdit( $originalClassID = false, $parentClassID = false, $returnAccessList = false, $language = false )
            {
                return true;
            }
        };
    }

    /**
     * A plan as expAiTranslationSync::plan() returns it.
     * @param array $choice expAiTranslationProviderRegistry::forPair()
     * @return array
     */
    private static function plan( array $choice, array $more = array() )
    {
        $service = $choice['provider'];
        return $more + array( 'object_id' => 88, 'source' => 'eng-US', 'target' => 'ger-DE', 'base_version' => 2, 'base_from' => 'target_edit', 'to_version' => 3,
            'target_version' => 3, 'target_hash' => 'h', 'metrics' => array( 'change_ratio' => 0.1, 'blocks_changed' => 1, 'sentences_changed' => 1, 'hunks' => 1 ),
            'aligned' => true, 'decision' => 'patch', 'needs_editor' => false, 'auto' => true, 'reason' => 'small change: queued automatically', 'mode' => 'patch',
            'units' => array( array( 'id' => 'u1', 'attribute' => 'body', 'index' => 1, 'kind' => 'block', 'old' => 'Old.', 'new' => 'New <1>text</1>.',
                                     'translation' => 'Alt.', 'before' => '', 'after' => '' ) ),
            'request' => null, 'estimate' => 9000, 'patch_estimate' => 9000, 'lane' => '', 'alias' => '', 'service' => $service,
            'provider' => $service ? $service->id() : '', 'provider_label' => $service ? $service->label() : '', 'provider_kind' => $service ? $service->kind() : '',
            'provider_state' => $choice['state'], 'provider_reason' => $choice['reason'], 'provider_chosen_by' => $choice['chosen_by'] );
    }

    /** @var array|null the language list of the process before the test */
    private $languages = null;

    protected function setUp(): void
    {
        parent::setUp();
        expAiTranslationSyncProviderTestSync::$states = array();
        expAiFakeTranslationProvider::reset();
        $this->languages = isset( $GLOBALS['eZContentLanguageList'] ) ? $GLOBALS['eZContentLanguageList'] : null;
    }

    /** The names of the languages without the database, for the DB-less tests (eZContentLanguage::fetchList() reads them from here). */
    private static function seedLanguages()
    {
        $GLOBALS['eZContentLanguageList'] = array(
            2 => new eZContentLanguage( array( 'id' => 2, 'name' => 'English (American)', 'locale' => 'eng-US', 'disabled' => 0 ) ),
            4 => new eZContentLanguage( array( 'id' => 4, 'name' => 'German', 'locale' => 'ger-DE', 'disabled' => 0 ) ) );
    }

    protected function tearDown(): void
    {
        if ( $this->languages === null )
            unset( $GLOBALS['eZContentLanguageList'] );
        else
            $GLOBALS['eZContentLanguageList'] = $this->languages;
        parent::tearDown();
    }

    public function testAMachineTranslationIsQueuedAsARunJob(): void
    {
        $config = $this->mtConfig( array( 'TranslationSettings' => array( 'Provider' => 'fake' ) ) );
        $sync = new expAiTranslationSyncProviderTestSync( $config );
        $choice = $sync->providers()->forPair( 'eng-US', 'ger-DE', 'patch' );
        $this->assertSame( 'fake', $choice['provider']->id() );
        $this->assertFalse( expAiTranslationSync::queuesRequest( $choice['provider'] ) );
        $sync->plans['ger-DE'] = self::plan( $choice );
        $row = $sync->check( self::object(), array( 'trigger' => 'test', 'user_id' => 14 ) )['ger-DE'];
        $this->assertSame( array( 'queued', 'bbbbbbbb-0000-4000-8000-000000000001', '', 'fake', 'Fake MT', 'mt' ),
                           array( $row['state'], $row['job_uuid'], $row['lane'], $row['provider'], $row['provider_label'], $row['provider_kind'] ) );
        $this->assertCount( 1, $sync->runs );
        list( $payload, $options, $estimate ) = $sync->runs[0];
        $this->assertSame( array( 'fake', 'translate', array( 88 ), 0 ), array( $payload['provider'], $payload['purpose'], $payload['objects'], $payload['node_id'] ) );
        $this->assertSame( array( 'object_id' => 88, 'source' => 'eng-US', 'target' => 'ger-DE', 'base_version' => 2, 'base_from' => 'target_edit', 'to_version' => 3,
                                  'target_version' => 3, 'target_hash' => 'h', 'mode' => 'patch', 'provider' => 'fake' ), $payload['apply_params'] );
        $this->assertSame( 'Translation patch eng-US -> ger-DE of object 88 by Fake MT', $payload['label'] );
        $this->assertSame( array( 'translation_patch', 'translation:fake', 9000, array( 'provider:fake' ), 'tsync:88:ger-DE:2-3:patch:fake', 14 ),
                           array( $options['class'], $options['resource'], $options['cost_estimate_micros'], $options['tags'], $options['idempotency_key'], $options['user_id'] ) );
        $this->assertSame( 9000, $estimate );
        $this->assertSame( array( 'fake', 'translation.fake', 'queue.translate', 'eu', 'none' ),
                           array( $sync->gates[0]['provider'], $sync->gates[0]['model_alias'], $sync->gates[0]['purpose'], $sync->gates[0]['region'], $sync->gates[0]['retention'] ) );

        // a full translation: the class and the key say so
        $sync->plans['ger-DE'] = self::plan( $choice, array( 'decision' => 'full', 'mode' => 'full', 'auto' => false, 'needs_editor' => true ) );
        $this->assertSame( 'waiting', $sync->check( self::object() )['ger-DE']['state'], 'a full translation waits for an editor' );
        $approved = $sync->approve( self::object(), 'ger-DE', 'full' );
        $this->assertSame( array( 'queued', 14 ), array( $approved['state'], $approved['approved_by'] ) );
        $this->assertSame( array( 'translation_full', 'tsync:88:ger-DE:2-3:full:fake' ), array( $sync->runs[1][1]['class'], $sync->runs[1][1]['idempotency_key'] ) );
        $this->assertSame( 'translate_full', $sync->runs[1][0]['purpose'] );
    }

    public function testWithoutAServiceNothingIsQueued(): void
    {
        $config = $this->mtConfig( array( 'TranslationSettings' => array( 'Provider' => 'none' ) ) );
        $sync = new expAiTranslationSyncProviderTestSync( $config );
        $choice = $sync->providers()->forPair( 'eng-US', 'ger-DE', 'patch' );
        $sync->plans['ger-DE'] = self::plan( $choice );
        $dry = $sync->check( self::object(), array( 'dry_run' => true ) )['ger-DE'];
        $this->assertSame( array( 'no_provider', '' ), array( $dry['state'], $dry['provider'] ) );
        $row = $sync->check( self::object() )['ger-DE'];
        $this->assertSame( 'no_provider', $row['state'] );
        $this->assertSame( 'No translation service is installed for eng-US -> ger-DE ([TranslationSettings] Provider=none)', $row['provider_reason'] );
        $this->assertSame( 'small change: queued automatically', $row['reason'], 'the decision itself stays' );
        $this->assertSame( array(), $sync->runs );
        try
        {
            $sync->approve( self::object(), 'ger-DE' );
            $this->fail( 'approved without a translation service' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'No translation service is installed for %source → %target.', $e->getMessage() );
            $this->assertSame( array( '%source' => 'eng-US', '%target' => 'ger-DE' ), $e->arguments() );
            $this->assertSame( $row['provider_reason'], $e->detail() );
        }
        $this->assertSame( array(), $sync->runs );

        // blocked: the data policy keeps the content away from every service that could take it
        $blocked = self::plan( array( 'provider' => null, 'state' => 'blocked', 'reason' => 'The data policy keeps this content away from the translation service for eng-US -> ger-DE (fake: ...)', 'chosen_by' => 'Provider' ) );
        $sync->plans['ger-DE'] = $blocked;
        $this->assertSame( 'blocked', $sync->check( self::object() )['ger-DE']['state'] );
        try
        {
            $sync->approve( self::object(), 'ger-DE' );
            $this->fail( 'approved a blocked translation' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'The data policy keeps this content away from every translation service for %source → %target.', $e->getMessage() );
        }
        // nothing changed in the source: up to date, whatever the services
        $sync->plans['ger-DE'] = self::plan( $choice, array( 'decision' => 'noop', 'metrics' => array( 'change_ratio' => 0, 'blocks_changed' => 0, 'sentences_changed' => 0, 'hunks' => 0 ) ) );
        $this->assertSame( 'up_to_date', $sync->check( self::object() )['ger-DE']['state'] );
    }

    public function testTheEstimateOfAMachineTranslationIsWhatTheThresholdSees(): void
    {
        $config = $this->mtConfig( array( 'TranslationSettings' => array( 'Provider' => 'fake' ), 'TranslationSync' => array( 'CostThreshold' => '0.01' ) ) );
        $sync = new expAiTranslationSyncProviderTestSync( $config );
        $choice = $sync->providers()->forPair( 'eng-US', 'ger-DE', 'patch' );
        $plan = self::plan( $choice );
        $cost = $sync->costOf( $choice, $plan, 'patch' );
        // "New text." is 9 characters at 1000 per million: 9000 micros, no lane, no model request
        $this->assertSame( array( 9000, '', '', null ), array( $cost['micros'], $cost['lane'], $cost['alias'], $cost['request'] ) );
        $metrics = array( 'change_ratio' => 0.1, 'blocks_changed' => 1, 'hunks' => 1 );
        $this->assertTrue( expAiTranslationSync::decide( $metrics, true, 1, $cost['micros'], $config )['auto'] );
        $config->ini()->setVariable( 'TranslationProvider_fake', 'PricePerMChars', '2000000' );
        $other = new expAiTranslationSyncProviderTestSync( $config );
        $expensive = $other->costOf( $other->providers()->forPair( 'eng-US', 'ger-DE', 'patch' ), $plan, 'patch' );
        $this->assertSame( 18000000, $expensive['micros'] );
        $decision = expAiTranslationSync::decide( $metrics, true, 1, $expensive['micros'], $config );
        $this->assertFalse( $decision['auto'] );
        $this->assertStringContainsString( 'above CostThreshold', $decision['reason'] );
        $this->assertSame( array( 0, '', null ), array_values( array_intersect_key( $sync->costOf( array( 'provider' => null, 'state' => 'no_provider', 'reason' => '', 'chosen_by' => '' ), $plan, 'patch' ),
                                                                                     array( 'micros' => 1, 'lane' => 1, 'request' => 1 ) ) ) );
    }

    public function testTheStatusNamesTheServiceAndOldRowsAreTheModels(): void
    {
        self::seedLanguages();
        $sync = new expAiTranslationSyncProviderTestSync( $this->mtConfig() );
        // a row stored before translation services existed
        expAiTranslationSyncProviderTestSync::$states['88:ger-DE'] = array( 'state' => 'waiting', 'decision' => 'patch', 'to_version' => 3, 'job_uuid' => '',
                                                                            'estimate_micros' => 5000 );
        $status = $sync->status( self::object() );
        $row = $status['languages']['ger-DE'];
        $this->assertSame( array( 'llm', 'Exponential Intelligence model' ), array( $row['provider'], $row['provider_label'] ) );
        expAiTranslationSyncProviderTestSync::$states['88:ger-DE'] = array( 'state' => 'queued', 'decision' => 'patch', 'to_version' => 3, 'job_uuid' => '',
                                                                            'provider' => 'fake', 'provider_label' => '', 'provider_kind' => 'mt' );
        $this->assertSame( 'Fake MT', $sync->status( self::object() )['languages']['ger-DE']['provider_label'] );
        // never checked: no service yet
        expAiTranslationSyncProviderTestSync::$states = array();
        $this->assertSame( array( 'unchecked', '' ), array( $sync->status( self::object() )['languages']['ger-DE']['state'], $sync->status( self::object() )['languages']['ger-DE']['provider_label'] ) );
    }

    public function testTheLlmServiceBuildsTheRequestOfThePlanAsBefore(): void
    {
        self::seedLanguages();
        $config = $this->mtConfig();
        $sync = new expAiTranslationSyncProviderTestSync( $config );
        $choice = $sync->providers()->forPair( 'eng-US', 'ger-DE', 'patch' );
        $this->assertTrue( expAiTranslationSync::queuesRequest( $choice['provider'] ) );
        $plan = self::plan( $choice );
        $cost = $sync->costOf( $choice, $plan, 'patch' );
        $this->assertSame( $sync->request( $plan, 'patch' ), $cost['request'], 'the request the queue gets' );
        $this->assertSame( $sync->alias(), $cost['alias'] );
        $this->assertSame( $sync->llm()->alias(), $sync->alias() );
    }

    /** The whole way on an article of the installation: check, the run job, the draft, the usage row. */
    public function testAnArticleIsTranslatedByTheServiceIntoADraft(): void
    {
        $config = $this->siteSettings( array(
            'TranslationSync' => array( 'Enabled' => 'enabled', 'SourceLanguages' => array(), 'TargetLanguages' => array(), 'Datatypes' => array(),
                                        'AutoQueuePatch' => 'enabled', 'PatchMaxChangedRatio' => '0.5', 'CostThreshold' => '100', 'Model' => '' ),
            'TranslationSettings' => array( 'Provider' => 'fake', 'Fallback' => 'none', 'ProviderFor' => array(), 'DoNotTranslate' => array(), 'Glossary' => array() ),
            'TranslationProvider_fake' => array( 'Class' => 'expAiFakeTranslationProvider', 'Label' => 'Fake MT', 'Region' => 'eu', 'Retention' => 'none',
                                                 'PricePerMChars' => '1000', 'PriceCurrency' => '' ),
            // a site may hide costs ([Pricing] Enabled=false): the cost source is only named while they are shown
            'Pricing' => array( 'Enabled' => 'true' ) ) );
        expQueue::pause( 'resource', 'translation:fake' );
        $this->cleanup( static function () { expQueue::resume( 'resource', 'translation:fake' ); } );
        $this->asUser( 'admin' );
        $folder = $this->folder();
        $object = $this->article( (int) $folder->attribute( 'node_id' ), 'XT bakery', '<paragraph>A small bakery.</paragraph>',
            '<paragraph>Our bakery opened in 1998.</paragraph><paragraph>Every morning we bake bread and rolls.</paragraph><paragraph>We are closed on Sundays.</paragraph>' );
        self::publishVersion( eZContentObject::fetch( (int) $object->attribute( 'id' ) ), self::TARGET, array(
            'title' => 'XT Bäckerei', 'intro' => self::xml( '<paragraph>Eine kleine Bäckerei.</paragraph>' ),
            'body' => self::xml( '<paragraph>Unsere Bäckerei wurde 1998 eröffnet.</paragraph><paragraph>Jeden Morgen backen wir Brot und Brötchen.</paragraph>'
                               . '<paragraph>Sonntags ist geschlossen.</paragraph>' ) ) );
        self::publishVersion( eZContentObject::fetch( (int) $object->attribute( 'id' ) ), self::SOURCE, array(
            'body' => self::xml( '<paragraph>Our bakery opened in 1998.</paragraph><paragraph>Every morning we bake bread, rolls and cakes.</paragraph>'
                               . '<paragraph>We are closed on Sundays.</paragraph>' ) ) );
        $object = eZContentObject::fetch( (int) $object->attribute( 'id' ) );
        $objectID = (int) $object->attribute( 'id' );
        $sync = new expAiTranslationSync( $config );

        $plan = $sync->plan( $object, self::TARGET );
        $this->assertSame( array( 'patch', 'fake', 'mt', 'ok', null, '' ), array( $plan['mode'], $plan['provider'], $plan['provider_kind'], $plan['provider_state'], $plan['request'], $plan['lane'] ) );
        $this->assertSame( 1000 * mb_strlen( 'Every morning we bake bread, rolls and cakes.', 'UTF-8' ), $plan['estimate'] );

        $row = $sync->check( $object, array( 'trigger' => 'test' ) )[self::TARGET];
        $this->assertContains( $row['state'], array( 'queued', 'held' ) );
        $uuid = $row['job_uuid'];
        $this->cleanup( static function () use ( $uuid )
        {
            $db = eZDB::instance();
            foreach ( (array) $db->arrayQuery( "SELECT id FROM expqueue_job WHERE uuid = '" . $db->escapeString( $uuid ) . "'" ) as $r )
            {
                $db->query( 'DELETE FROM expqueue_event WHERE job_id = ' . (int) $r['id'] );
                $db->query( 'DELETE FROM expqueue_job WHERE id = ' . (int) $r['id'] );
            }
            eZPersistentObject::removeObject( expAiUsageLog::definition(), array( 'job_uuid' => (string) $uuid ) );
        } );
        $job = expQueue::fetch( $uuid );
        $this->assertSame( array( expAiTranslationSync::RUN_JOB, 'translation_patch', 'translation:fake' ),
                           array( (string) $job->attribute( 'type' ), (string) $job->attribute( 'class' ), (string) $job->attribute( 'resource_key' ) ) );
        $this->assertSame( array( 'fake', 'patch' ), array( $job->payloadArray()['provider'], $job->payloadArray()['apply_params']['mode'] ) );

        $result = ( new expAiTranslationRunHandler() )->run( $job, new expQueueContext( $job, 'test-owner' ) );
        $this->assertIsArray( $result );
        $this->assertSame( 'fake', $result['provider'] );
        $body = '';
        foreach ( eZContentObject::fetch( $objectID )->fetchDataMap( $result['applied']['draft_version'], self::TARGET ) as $identifier => $attribute )
            if ( $identifier === 'body' )
                $body = (string) $attribute->attribute( 'data_text' );
        $this->assertStringContainsString( '[de-DE] Every morning we bake bread, rolls and cakes.', $body );
        $this->assertStringContainsString( 'Sonntags ist geschlossen', $body, 'the other blocks keep their translation' );
        $usage = eZPersistentObject::fetchObject( expAiUsageLog::definition(), null, array( 'id' => (int) $result['usage_log_id'] ) );
        $this->assertSame( array( 'fake', 'fake-mt', 'translation.fake', 'queue.translate', $uuid, 0, 0 ),
                           array( (string) $usage->attribute( 'provider' ), (string) $usage->attribute( 'model' ), (string) $usage->attribute( 'model_alias' ),
                                  (string) $usage->attribute( 'action' ), (string) $usage->attribute( 'job_uuid' ), (int) $usage->attribute( 'input_tokens' ),
                                  (int) $usage->attribute( 'output_tokens' ) ) );
        $this->assertSame( $plan['estimate'], (int) $usage->attribute( 'reported_cost_micros' ) );
        $this->assertSame( 'reported', $usage->costSource() );
        if ( expAiUsageLog::billedCharsColumn() )
            $this->assertSame( mb_strlen( 'Every morning we bake bread, rolls and cakes.', 'UTF-8' ), (int) $usage->attribute( 'billed_chars' ) );
        $log = eZPersistentObject::fetchObject( expAiDraftLog::definition(), null, array( 'job_uuid' => $uuid ) );
        $this->assertSame( array( 'translation', 'fake' ), array( (string) $log->attribute( 'source' ), (string) $log->attribute( 'provider' ) ) );
        $this->assertSame( 'draft', expAiTranslationSync::state( $objectID, self::TARGET )['state'] );

        // without a service: reported, not queued (the job of this test ran outside a worker and is still queued: it ends first)
        expQueue::cancel( expQueue::fetch( $uuid ), 'test' );
        $config->ini()->setVariable( 'TranslationSettings', 'Provider', 'none' );
        $none = ( new expAiTranslationSync( new expAiConfig() ) )->check( eZContentObject::fetch( $objectID ), array( 'trigger' => 'test' ) )[self::TARGET];
        $this->assertContains( $none['state'], array( 'no_provider', 'up_to_date' ) );
    }
}
