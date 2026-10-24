<?php
/**
 * The translation services where they meet the rest of expai: the field action Translate on the direct path (the
 * units of each datatype, the data policy before anything is sent, the value back in the field's format), the edit
 * form's list without Translate when no service is installed, expAiTranslationRecorder (the usage row with provider,
 * reported_cost_micros and billed_chars, the row of a failure, nothing sent while expai is off, the cost limits
 * only), the cost of a translation row and the missing billed_chars column. DB-less except the recorder's rows.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

/** The field action with a recorder that only hands the request to the service (the recorder has tests of its own). */
class expAiTranslationServicesTestAction extends expAiTranslateFieldAction
{
    protected function recorder( expAiTranslationProvider $service, array $context )
    {
        return new class( $service, $context + array( 'user_id' => 14 ) ) extends expAiTranslationRecorder
        {
            public function translate( expAiTranslationRequest $request )
            {
                return $this->provider->translate( $request );
            }
        };
    }
}

class expAiTranslationServicesTest extends expAiCoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        expAiFakeTranslationProvider::reset();
    }

    /** @return expAiConfig the shipped settings with a fake machine translation service for fields */
    private function mtConfig( array $more = array() )
    {
        $config = $this->config( array( 'AISettings' => array( 'Enabled' => 'true' ),
            'TranslationSettings' => array( 'Provider' => 'llm', 'Fallback' => 'none', 'ProviderFor' => array( '*:*:field' => 'fake' ), 'DoNotTranslate' => array( 'Velocity' ) ),
            'TranslationProvider_fake' => array( 'Class' => 'expAiFakeTranslationProvider', 'Label' => 'Fake MT', 'Region' => 'eu', 'Retention' => 'none', 'PricePerMChars' => '0' ),
            'DataPolicy' => array( 'DefaultRegions' => array(), 'ClassRegions' => array(), 'SectionRegions' => array(), 'ZeroRetention' => 'disabled' ) ) );
        foreach ( $more as $group => $values )
            foreach ( $values as $name => $value )
                $config->ini()->setVariable( $group, $name, $value );
        return $config;
    }

    /** @return expAiFieldRequest a field of an article that never touches the database */
    private static function field( $datatype, $value )
    {
        $request = new expAiFieldRequest();
        $request->object = new class( array( 'id' => 99, 'section_id' => 1, 'name' => 'XT' ) ) extends eZContentObject
        {
            public function attribute( $attr, $noFunction = false )
            {
                return $attr === 'class_identifier' ? 'article' : parent::attribute( $attr, true );
            }

            public function mainNode()
            {
                return null;
            }
        };
        // the field's class attribute: no length limit, nothing read from the database
        $request->classAttribute = new class extends eZContentClassAttribute
        {
            public function __construct()
            {
            }

            public function attribute( $attr, $noFunction = false )
            {
                return $attr === 'data_int1' ? 0 : 'f';
            }
        };
        $request->datatype = $datatype;
        $request->value = $value;
        $request->language = 'ger-DE';
        $request->targetLanguage = 'eng-GB';
        return $request;
    }

    public function testTheFieldActionTranslatesEachDatatypeInItsOwnShape(): void
    {
        $action = new expAiTranslationServicesTestAction( 'translate', $this->mtConfig() );
        $service = $action->directService( self::field( 'ezstring', 'x' ) );
        $this->assertSame( 'fake', $service->id() );

        $string = $action->runDirect( self::field( 'ezstring', 'Velocity & Co' ), $service );
        $this->assertSame( array( '[en-GB] Velocity & Co', '', 'fake-mt' ), array( $string['value'], $string['note'], $string['model'] ) );
        $this->assertSame( array( '<k>Velocity</k> &amp; Co' ), expAiFakeTranslationProvider::$batches[0][0], 'the term is kept, the text escaped' );

        $text = $action->runDirect( self::field( 'eztext', "Erste Zeile\n\nZweite Zeile\nDritte" ), $service );
        $this->assertSame( "[en-GB] Erste Zeile\n\n[en-GB] Zweite Zeile\n[en-GB] Dritte", $text['value'], 'line by line, the line breaks where they were' );

        $keywords = $action->runDirect( self::field( 'ezkeyword', 'Hund, Katze' ), $service );
        $this->assertSame( '[en-GB] Hund, [en-GB] Katze', $keywords['value'] );

        $rich = $action->runDirect( self::field( 'ezxmltext', '<p title="Velocity">Der <strong>Hund</strong> von Velocity</p>' ), $service );
        $this->assertSame( 'html', end( expAiFakeTranslationProvider::$batches )[1] );
        $this->assertSame( array( '<p title="Velocity">Der <strong>Hund</strong> von <span translate="no" class="notranslate">Velocity</span></p>' ),
                           end( expAiFakeTranslationProvider::$batches )[0], 'terms in the text of the HTML, not in its attributes' );
        $this->assertStringContainsString( '<strong>Hund</strong> von Velocity', $rich['value'], 'the wrapper is gone again' );
        $this->assertStringNotContainsString( 'notranslate', $rich['value'] );

        list( $request, $layout ) = $action->translationRequest( self::field( 'eztext', "A\r\nB" ) );
        $this->assertSame( array( 'field', 'field:translate', array( 99 ) ), array( $request->purpose, $request->action, $request->objectIDs ) );
        $this->assertSame( array( array( 'f1' ), "\r\n", array( 'f2' ) ), $layout );
        try
        {
            $action->runDirect( self::field( 'eztext', "\n\n" ), $service );
            $this->fail( 'translated an empty field' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'The field is empty: there is nothing to translate.', $e->getMessage() );
        }
    }

    public function testTheDataPolicyAndTheRulesDecideBeforeAnythingIsSent(): void
    {
        $action = new expAiTranslationServicesTestAction( 'translate', $this->mtConfig( array( 'DataPolicy' => array( 'ClassRegions' => array( 'article' => 'local' ) ) ) ) );
        try
        {
            $action->directService( self::field( 'ezstring', 'x' ) );
            $this->fail( 'the data policy let it through' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'The data policy keeps this content away from every translation service for %source → %target.', $e->getMessage() );
        }
        $config = $this->mtConfig( array( 'TranslationSettings' => array( 'ProviderFor' => array() ) ) );
        $this->assertNull( ( new expAiTranslationServicesTestAction( 'translate', $config ) )->directService( self::field( 'ezstring', 'x' ) ),
                           'the llm service: the prompt of expAiPromptFieldAction, as before' );
        $config->ini()->setVariable( 'TranslationSettings', 'Provider', 'none' );
        try
        {
            ( new expAiTranslationServicesTestAction( 'translate', $config ) )->directService( self::field( 'ezstring', 'x' ) );
            $this->fail( 'translated without a service' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'No translation service is installed for %source → %target.', $e->getMessage() );
        }
        // no target language, or the field's own language: the llm prompt as before (it asks for the language itself)
        $noTarget = self::field( 'ezstring', 'x' );
        $noTarget->targetLanguage = '';
        $this->assertNull( $action->directService( $noTarget ) );
        $same = self::field( 'ezstring', 'x' );
        $same->targetLanguage = 'ger-DE';
        $this->assertNull( $action->directService( $same ) );
        $this->assertSame( array(), expAiFakeTranslationProvider::$batches );
    }

    public function testTheEditFormLeavesTranslateOutWithoutAService(): void
    {
        $config = $this->mtConfig( array( 'TranslationSettings' => array( 'Provider' => 'none', 'ProviderFor' => array() ),
                                          'EditViewSettings' => array( 'FieldActions' => array( 'rewrite', 'translate' ) ) ) );
        $registry = new expAiFieldActionRegistry( $config );
        $this->assertInstanceOf( 'expAiTranslateFieldAction', $registry->get( 'translate' ), 'the shipped handler' );
        $this->assertSame( array( 'rewrite' ), array_column( $registry->describe(), 'name' ) );
        $this->assertStringContainsString( 'no translation service', $registry->get( 'translate' )->unavailableReason() );
        $config->ini()->setVariable( 'TranslationSettings', 'Provider', 'llm' );
        $this->assertSame( array( 'rewrite', 'translate' ), array_column( ( new expAiFieldActionRegistry( $config ) )->describe(), 'name' ) );
    }

    public function testTheCostOfATranslationRowIsWhatTheRecorderStored(): void
    {
        $this->assertSame( array( 0.0, 'reported' ), array_values( array_intersect_key( expAiCost::of( 'fake-mt', 0, 0, 0, 0, 'translation.fake', 0 ), array( 'amount' => 1, 'source' => 1 ) ) ) );
        $this->assertSame( array( 0.25, 'reported' ), array_values( array_intersect_key( expAiCost::of( 'deepl', 0, 0, 0, 0, 'translation.deepl', 250000 ), array( 'amount' => 1, 'source' => 1 ) ) ) );

        $result = new expAiTranslationResult();
        $result->engine = 'deepl';
        $result->billedChars = 1234;
        $result->costMicros = 24680;
        $result->errors = array( 'u2' => 'placeholder 1 missing' );
        expAiSchema::forgetColumns();
        $columns = array( 'id', 'created', 'reserved_tokens', 'reserved_micros', 'billed_chars' );
        expAiUsageLog::$columnLister = function ( $table ) use ( &$columns ) { return $columns; };
        $this->cleanup( static function () { expAiUsageLog::$columnLister = null; expAiSchema::forgetColumns(); } );
        $row = expAiTranslationRecorder::resultFields( $result, array( 'model' => 'deepl' ) );
        $this->assertSame( array( 'deepl', 0, 0, 24680, 1234, 'markup', 'units u2: placeholder 1 missing' ),
                           array( $row['model'], $row['input_tokens'], $row['output_tokens'], $row['reported_cost_micros'], $row['billed_chars'], $row['error_class'], $row['error_message'] ) );
        $this->assertArrayHasKey( 'billed_chars', expAiUsageLog::definition()['fields'] );
        // an installation not upgraded: the column is left out, the cost is still stored
        $columns = array( 'id', 'created', 'reserved_tokens', 'reserved_micros' );
        expAiSchema::forgetColumns();
        $this->assertFalse( expAiUsageLog::billedCharsColumn() );
        $this->assertArrayNotHasKey( 'billed_chars', expAiUsageLog::definition()['fields'] );
        $this->assertArrayNotHasKey( 'billed_chars', expAiTranslationRecorder::resultFields( $result, array() ) );
        $this->assertArrayHasKey( 'billed_chars', expAiSchema::definition()['expai_usage_log']['fields'], 'the upgrade adds it' );
    }

    public function testTheRecorderWritesTheUsageRow(): void
    {
        $this->inTransaction();
        $this->useSettings( array( 'AISettings' => array( 'Enabled' => 'true', 'DisabledSiteAccesses' => array() ), 'RequestVeto' => array( 'Rules' => array() ),
                                   'Limits' => array( 'MaxCostPerSitePerMonth' => '', 'MaxTokensPerUserPerDay' => '1' ) ) );
        $config = $this->mtConfig( array( 'TranslationProvider_fake' => array( 'PricePerMChars' => '1000000' ) ) );
        $service = ( new expAiTranslationProviderRegistry( $config ) )->get( 'fake' );
        $request = new expAiTranslationRequest();
        $request->source = 'ger-DE';
        $request->target = 'eng-GB';
        $request->units[] = expAiTranslationRequest::unit( array( 'id' => 'u1', 'kind' => 'text', 'format' => 'text', 'text' => 'Hallo' ) );
        expAiFakeTranslationProvider::$billed = 7;
        $recorder = new expAiTranslationRecorder( $service, array( 'user_id' => self::TEST_USER, 'node_id' => 2, 'action' => 'field:translate', 'job_uuid' => 'cccccccc-0000-4000-8000-000000000001' ) );
        $result = $recorder->translate( $request );
        $this->assertSame( '[en-GB] Hallo', $result->units['u1'] );
        $row = $recorder->last;
        $this->assertSame( array( 'fake', 'fake-mt', 'translation.fake', 'field:translate', self::TEST_USER, 2, 7000000, 'eu', 'none', 'cccccccc-0000-4000-8000-000000000001' ),
                           array( (string) $row->attribute( 'provider' ), (string) $row->attribute( 'model' ), (string) $row->attribute( 'model_alias' ),
                                  (string) $row->attribute( 'action' ), (int) $row->attribute( 'user_id' ), (int) $row->attribute( 'node_id' ),
                                  (int) $row->attribute( 'reported_cost_micros' ), (string) $row->attribute( 'region' ), (string) $row->attribute( 'retention' ),
                                  (string) $row->attribute( 'job_uuid' ) ) );
        if ( expAiUsageLog::billedCharsColumn() )
            $this->assertSame( 7, (int) $row->attribute( 'billed_chars' ) );
        $this->assertEquals( 7.0, $row->cost(), 'reported: 7 characters at a million per million (an exact amount comes back as an integer)' );
        $this->assertSame( 'reported', $row->costSource() );

        // a failure leaves a row with the error
        expAiFakeTranslationProvider::$failure = new expAiTranslationException( 'The translation service %service refused the key.', 'fake: HTTP 403', 403, array( '%service' => 'Fake MT' ), false );
        try
        {
            $recorder->translate( $request );
            $this->fail( 'no failure' );
        }
        catch ( expAiTranslationException $e )
        {
            $this->assertSame( array( 403, 'expAiTranslationException' ), array( (int) $recorder->last->attribute( 'http_status' ), (string) $recorder->last->attribute( 'error_class' ) ) );
            $this->assertStringContainsString( 'refused the key. (fake: HTTP 403)', (string) $recorder->last->attribute( 'error_message' ) );
        }

        // switched off: nothing is sent, no row
        $this->useSettings( array( 'AISettings' => array( 'Enabled' => 'false' ) ) );
        $batches = count( expAiFakeTranslationProvider::$batches );
        $last = $recorder->last;
        try
        {
            $recorder->translate( $request );
            $this->fail( 'sent while switched off' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'Exponential Intelligence is switched off.', $e->getMessage() );
        }
        $this->assertSame( $batches, count( expAiFakeTranslationProvider::$batches ) );
        $this->assertSame( $last, $recorder->last );
    }
}
