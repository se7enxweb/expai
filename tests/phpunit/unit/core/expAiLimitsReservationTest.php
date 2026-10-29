<?php
/**
 * The reservations of the requests under way and the limits (expAiLimits, expAiUsage, expAiUsageRecorder): the
 * verdict of a limit with what parallel requests reserved, the state of a reservation row, the estimate a request
 * reserves, a model without a price under a cost limit ([Limits] UnpricedCost), and the row fields of answers and
 * failures (the category of a refusal, a failure that is no expAiException). No database.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiLimitsReservationTest extends expAiCoreTestCase
{
    public function testTheVerdictCountsWhatTheRequestsUnderWayReserved(): void
    {
        $this->assertSame( '', expAiLimits::verdict( array( 'used' => 50, 'reserved' => 0, 'max' => 0 ) ), 'no limit' );
        $this->assertSame( '', expAiLimits::verdict( array( 'used' => 50, 'reserved' => 40, 'max' => 100 ) ) );
        $this->assertSame( 'reserved', expAiLimits::verdict( array( 'used' => 50, 'reserved' => 60, 'max' => 100 ) ) );
        $this->assertSame( 'over', expAiLimits::verdict( array( 'used' => 100, 'reserved' => 0, 'max' => 100 ) ) );
        $this->assertSame( '', expAiLimits::verdict( array( 'used' => 10, 'max' => 100 ) ), 'a row without reservations' );
    }

    public function testAReservationRowCountsUntilItIsStale(): void
    {
        $now = 1767225600;
        $this->assertSame( '', expAiUsage::reservationState( array( 'reserved_tokens' => 0, 'reserved_micros' => 0, 'created' => $now ), $now ) );
        $this->assertSame( '', expAiUsage::reservationState( array( 'created' => $now ), $now ), 'a row of an installation before the columns' );
        $this->assertSame( 'reserved', expAiUsage::reservationState( array( 'reserved_tokens' => 900, 'reserved_micros' => 0, 'created' => $now - 60 ), $now ) );
        $this->assertSame( 'stale', expAiUsage::reservationState( array( 'reserved_tokens' => 900, 'reserved_micros' => 5, 'created' => $now - expAiLimits::RESERVATION_SECONDS - 1 ), $now ) );
    }

    public function testAnInstallationWithoutTheReservationColumnsLeavesThemOut(): void
    {
        $asked = 0;
        $columns = array( 'id', 'created', 'user_id' );
        expAiSchema::forgetColumns();
        expAiUsageLog::$columnLister = function ( $table ) use ( &$asked, &$columns )
        {
            $asked++;
            return $table === 'expai_usage_log' ? $columns : null;
        };
        try
        {
            $this->assertFalse( expAiUsageLog::reservationColumns() );
            $definition = expAiUsageLog::definition();
            $this->assertArrayNotHasKey( 'reserved_tokens', $definition['fields'] );
            $this->assertArrayNotHasKey( 'reserved_micros', $definition['fields'] );
            $this->assertArrayHasKey( 'run_id', $definition['fields'] );
            $this->assertSame( array( 'user_id' => 5 ), expAiUsage::requestsOnly( array( 'user_id' => 5 ) ) );
            $this->assertArrayNotHasKey( 'reserved_tokens', expAiUsage::conditions( array( 'user_id' => 5 ) ) );
            $this->assertSame( 0, expAiUsage::removeStaleReservations(), 'nothing to clean without the columns' );
            $this->assertSame( 1, $asked, 'a missing column is not asked again at once' );

            // after the recheck time a missing column is asked again; the upgrade is seen without a restart
            $columns[] = 'reserved_tokens';
            $columns[] = 'reserved_micros';
            $now = time() + expAiSchema::COLUMN_RECHECK_SECONDS + 1;
            $this->assertTrue( expAiSchema::hasColumns( 'expai_usage_log', expAiUsageLog::RESERVATION_COLUMNS, expAiUsageLog::$columnLister, $now ) );
            $this->assertSame( 2, $asked );
            $this->assertTrue( expAiUsageLog::reservationColumns() );
            $this->assertArrayHasKey( 'reserved_tokens', expAiUsageLog::definition()['fields'] );
            $this->assertSame( array( 'user_id' => 5, 'reserved_tokens' => 0 ), expAiUsage::requestsOnly( array( 'user_id' => 5 ) ) );
            $this->assertSame( 0, expAiUsage::conditions( array() )['reserved_tokens'] );
            $columns = array( 'id' );
            $this->assertTrue( expAiUsageLog::reservationColumns(), 'a positive answer is kept for the process' );
            $this->assertSame( 2, $asked );
        }
        finally
        {
            expAiUsageLog::$columnLister = null;
            expAiSchema::forgetColumns();
        }
    }

    public function testTheCleanUpOfStaleReservationsRunsAtMostOnceAMinute(): void
    {
        $last = new ReflectionProperty( 'expAiUsage', 'lastStaleCleanup' );
        $before = $last->getValue();
        expAiSchema::forgetColumns();
        // without the columns the clean-up itself needs no database
        expAiUsageLog::$columnLister = function () { return array( 'id' ); };
        try
        {
            $last->setValue( null, 0 );
            expAiUsage::cleanStaleReservations( 100000 );
            $this->assertSame( 100000, $last->getValue(), 'the first request of a process cleans up' );
            expAiUsage::cleanStaleReservations( 100000 + expAiUsage::STALE_CLEANUP_SECONDS - 1 );
            $this->assertSame( 100000, $last->getValue(), 'skipped within the minute' );
            expAiUsage::cleanStaleReservations( 100000 + expAiUsage::STALE_CLEANUP_SECONDS );
            $this->assertSame( 100000 + expAiUsage::STALE_CLEANUP_SECONDS, $last->getValue() );
        }
        finally
        {
            $last->setValue( null, $before );
            expAiUsageLog::$columnLister = null;
            expAiSchema::forgetColumns();
        }
    }

    public function testAnUnknownColumnListCountsAsPresent(): void
    {
        expAiSchema::forgetColumns();
        $this->assertTrue( expAiSchema::hasColumns( 'expai_usage_log', array( 'reserved_tokens' ), function () { return null; } ), 'MongoDB' );
        expAiSchema::forgetColumns();
        $this->assertTrue( expAiSchema::hasColumns( 'expai_usage_log', array( 'reserved_tokens' ), function () { throw new Exception( 'no database' ); } ) );
        expAiSchema::forgetColumns();
        $this->assertFalse( expAiSchema::hasColumns( 'expai_usage_log', array( 'reserved_tokens' ), function () { return array( 'id' ); } ) );
        expAiSchema::forgetColumns();
    }

    public function testTheEstimateOfARequest(): void
    {
        $this->useSettings();
        $request = array( 'system' => str_repeat( 'a', 350 ), 'messages' => array( array( 'role' => 'user', 'content' => 'hello' ) ) );
        $estimate = expAiUsageRecorder::estimate( $request, 1000, 'claude-sonnet-5-5', 'anthropic.claude-sonnet-5-5' );
        $input = (int) ceil( strlen( json_encode( $request ) ) / 3.5 ) + 10;
        $this->assertSame( $input + 1000, $estimate['tokens'] );
        // Claude Sonnet 5.5: 2 per million input, 10 per million output
        $this->assertSame( (int) round( ( $input * 2 + 1000 * 10 ) ), $estimate['micros'] );
        $this->assertSame( 0, expAiUsageRecorder::estimate( $request, 1000, 'unknown-model', 'xtd.unpriced' )['micros'], 'no price: nothing reserved in money' );
    }

    public function testTheSettingsOfUnpricedModelsAndCostLimits(): void
    {
        $config = $this->useSettings();
        $this->assertFalse( expAiLimits::anySet( $config ) );
        $this->assertSame( 'block', expAiLimits::unpricedCost( $config ) );
        $this->assertSame( array(), expAiLimits::costLimitsFor( 'anthropic', $config ) );
        $config = $this->useSettings( array( 'Limits' => array( 'UnpricedCost' => 'ignore', 'MaxCostPerUserPerDay' => '2', 'MaxTokensPerUserPerDay' => '1000',
                                                                'ProviderMaxCostPerMonth' => array( 'xtd' => '5' ) ) ) );
        $this->assertTrue( expAiLimits::anySet( $config ) );
        $this->assertSame( 'ignore', expAiLimits::unpricedCost( $config ) );
        $this->assertSame( array( 'day_cost' ), expAiLimits::costLimitsFor( 'anthropic', $config ) );
        $this->assertSame( array( 'day_cost', 'provider_month_cost:xtd' ), expAiLimits::costLimitsFor( 'xtd', $config ) );
    }

    public function testAnUnpricedModelUnderACostLimitIsRefusedBeforeAnythingIsCounted(): void
    {
        $this->useSettings( array( 'Limits' => array( 'MaxCostPerUserPerDay' => '2' ),
                                   'Provider_xtd' => array( 'Type' => 'openai_chat', 'BaseURL' => 'http://127.0.0.1:9/v1', 'ApiKey' => '' ),
                                   'Model_xtd.free' => array( 'Provider' => 'xtd', 'Id' => 'xtd-free' ),
                                   'ProviderSettings' => array( 'Providers' => array( 'anthropic', 'xtd' ) ) ) );
        try
        {
            expAiLimits::check( array( 'user_id' => self::TEST_USER, 'provider' => 'xtd', 'model' => 'xtd-free', 'model_alias' => 'xtd.free' ) );
            $this->fail( 'no refusal' );
        }
        catch ( expAiException $e )
        {
            $this->assertStringStartsWith( 'The model %model has no price', $e->getMessage() );
            $this->assertSame( 'xtd.free', $e->arguments()['%model'] );
        }
    }

    public function testAnUnpricedModelOfALocalProviderIsFree(): void
    {
        $config = $this->useSettings( array( 'Limits' => array( 'MaxCostPerUserPerDay' => '2' ),
                                             'Provider_xtd' => array( 'Type' => 'openai_chat', 'BaseURL' => 'http://127.0.0.1:9/v1', 'ApiKey' => '', 'Region' => 'eu' ),
                                             'Provider_xlo' => array( 'Type' => 'openai_chat', 'BaseURL' => 'http://127.0.0.1:9/v1', 'ApiKey' => '', 'Region' => 'local' ),
                                             'Model_xtd.free' => array( 'Provider' => 'xtd', 'Id' => 'xtd-free' ),
                                             'Model_xlo.free' => array( 'Provider' => 'xlo', 'Id' => 'xlo-free' ),
                                             'ProviderSettings' => array( 'Providers' => array( 'anthropic', 'xtd', 'xlo' ) ) ) );
        $this->assertTrue( expAiLimits::isLocalProvider( 'xlo', $config ) );
        $this->assertFalse( expAiLimits::isLocalProvider( 'xtd', $config ) );
        $this->assertFalse( expAiLimits::isLocalProvider( '', $config ) );
        $this->assertFalse( expAiLimits::isLocalProvider( 'unknown', $config ) );
        try
        {
            expAiLimits::check( array( 'user_id' => self::TEST_USER, 'provider' => 'xlo', 'model' => 'xlo-free', 'model_alias' => 'xlo.free' ) );
            $this->addToAssertionCount( 1 );
        }
        catch ( expAiException $e )
        {
            $this->assertStringStartsNotWith( 'The model %model has no price', $e->getMessage(), 'a local model is not refused for its missing price' );
        }
    }

    public function testTheRowOfARefusalNamesItsCategoryAndTheRowOfAnyFailure(): void
    {
        $this->useSettings();
        $row = expAiUsageRecorder::answerFields( array( 'content' => array(), 'stop_reason' => 'refusal', 'model' => 'claude-opus-5-5',
                                                        'tokens' => array( 'input' => 12, 'output' => 0 ), 'stop_details' => array( 'category' => 'bio', 'explanation' => 'x' ) ),
                                                 array( 'model' => 'claude-opus-5-5' ) );
        $this->assertSame( array( 'refusal', 'refusal, category bio', 12 ), array( $row['error_class'], $row['error_message'], $row['input_tokens'] ) );
        $row = expAiUsageRecorder::answerFields( array( 'stop_reason' => 'refusal', 'stop_details' => null ), array() );
        $this->assertSame( 'refusal, no category', $row['error_message'] );
        $row = expAiUsageRecorder::answerFields( array( 'stop_reason' => 'end_turn', 'usage' => array( 'input_tokens' => 3, 'output_tokens' => 4 ) ), array( 'model' => 'm' ) );
        $this->assertArrayNotHasKey( 'error_class', $row );
        $this->assertSame( array( 3, 4, 'm' ), array( $row['input_tokens'], $row['output_tokens'], $row['model'] ) );

        $fields = expAiUsageRecorder::errorFields( new TypeError( 'broken' ) );
        $this->assertSame( array( 'http_status' => 0, 'error_class' => 'TypeError', 'error_message' => 'broken' ), $fields );
        $fields = expAiUsageRecorder::errorFields( new expAiException( 'The limit %x.', 'detail', 429, array( '%x' => 'y' ) ) );
        $this->assertSame( array( 429, 'expAiException', 'The limit y. (detail)' ), array( $fields['http_status'], $fields['error_class'], $fields['error_message'] ) );
    }

    public function testReadersOfRequestsLeaveReservationsOutAndALimitLeavesOutTheOwnOne(): void
    {
        $this->useSettings();
        $this->assertSame( array( 'model_alias' => 'x', 'reserved_tokens' => 0 ), expAiUsage::requestsOnly( array( 'model_alias' => 'x' ) ) );
        $conds = expAiUsage::conditions( array( 'user_id' => 5 ) );
        $this->assertSame( 0, $conds['reserved_tokens'], 'the log, the turns and the statistics see requests only' );
        $conds = expAiUsage::conditions( array( 'user_id' => 5 ) + expAiLimits::reservationFilters( array( 'reservation_id' => 77 ) ) );
        $this->assertArrayNotHasKey( 'reserved_tokens', $conds, 'the limits count the reservations' );
        $this->assertSame( array( '!=', 77 ), $conds['id'], 'but not the request\'s own' );
        $this->assertSame( array( 'with_reservations' => true ), expAiLimits::reservationFilters( array() ) );
    }

    public function testARequestIsNotRefusedForItsOwnReservation(): void
    {
        // with the database: a real limit, the request's own reservation far above it, and one of another request
        $this->useSettings( array( 'Limits' => array( 'MaxTokensPerUserPerDay' => '10000' ) ) );
        $own = $this->usage( array( 'reserved_tokens' => 16500, 'reserved_micros' => 0 ) );
        $context = array( 'user_id' => self::TEST_USER, 'provider' => self::TEST_PROVIDER, 'reservation_id' => (int) $own->attribute( 'id' ) );
        expAiLimits::check( $context );
        $this->addToAssertionCount( 1 );
        $this->usage( array( 'input_tokens' => 4000, 'output_tokens' => 1000 ) );
        $this->usage( array( 'reserved_tokens' => 6000 ) );
        try
        {
            expAiLimits::check( $context );
            $this->fail( 'the other request\'s reservation passes the limit' );
        }
        catch ( expAiException $e )
        {
            $this->assertStringStartsWith( 'The limit %limit would be passed together with the requests under way', $e->getMessage() );
        }
        // a stale reservation is removed and no longer counts
        $stale = $this->usage( array( 'reserved_tokens' => 99999, 'created' => time() - expAiLimits::RESERVATION_SECONDS - 10 ) );
        $this->assertGreaterThanOrEqual( 1, expAiUsage::removeStaleReservations() );
        $this->assertNull( eZPersistentObject::fetchObject( expAiUsageLog::definition(), null, array( 'id' => (int) $stale->attribute( 'id' ) ) ) );
    }
}
