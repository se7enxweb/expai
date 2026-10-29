<?php
/**
 * expAiUsage (recording, filters, totals, turns, "My usage", the dashboard's period figures and series, the slow
 * notice, pruning) and expAiLimits (the limits of [Limits] and the refusal when one is reached), on rows of a user id
 * and a provider id no real user or provider has. Every row is removed after the test.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiUsageTest extends expAiCoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->removeTestRows();
    }

    public function testRecordCutsLongTextsAndCountsTokensOfToday(): void
    {
        $this->useSettings();
        $log = expAiUsage::record( array( 'user_id' => self::TEST_USER, 'provider' => self::TEST_PROVIDER, 'model' => str_repeat( 'm', 150 ),
                                          'action' => str_repeat( 'a', 50 ), 'error_message' => str_repeat( 'e', 300 ),
                                          'input_tokens' => 100, 'output_tokens' => 20, 'cache_write_tokens' => 3, 'cache_read_tokens' => 7 ) );
        $this->assertGreaterThan( 0, (int) $log->attribute( 'id' ) );
        $this->assertSame( 100, mb_strlen( $log->attribute( 'model' ), 'UTF-8' ) );
        $this->assertSame( 40, mb_strlen( $log->attribute( 'action' ), 'UTF-8' ) );
        $this->assertSame( 255, mb_strlen( $log->attribute( 'error_message' ), 'UTF-8' ) );
        $this->usage( array( 'input_tokens' => 1000, 'created' => mktime( 0, 0, 0 ) - 10 ) );
        $this->assertSame( 130, expAiUsage::tokensToday( self::TEST_USER ), 'only the rows since midnight count' );
    }

    public function testCostWithFactorsReportedCostsAndUnknownModels(): void
    {
        $this->useSettings();
        $p = expAiCost::prices( 'claude-sonnet-5-5' );
        $full = ( 1000000 * $p['input'] + 1000000 * $p['output'] ) / 1000000;
        expAiUsage::approxSeen();
        $this->assertEqualsWithDelta( $full, expAiUsage::cost( 'claude-sonnet-5-5', 1000000, 1000000, 0, 0 ), 1e-9 );
        $this->assertEqualsWithDelta( $full / 2, expAiUsage::cost( 'claude-sonnet-5-5', 1000000, 1000000, 0, 0, '', 0, 50 ), 1e-9, 'the batch lane halves the price' );
        $this->assertEqualsWithDelta( 1.5, expAiUsage::cost( 'claude-sonnet-5-5', 1, 1, 0, 0, '', 1500000, 50 ), 1e-9, 'a reported cost is taken as it is' );
        $this->assertFalse( expAiUsage::approxSeen() );
        $this->assertNull( expAiUsage::cost( 'mystery', 10, 10, 0, 0, self::TEST_PROVIDER . '.mystery' ), 'a model of another provider without prices has no cost' );
        expAiUsage::cost( 'claude-unknown-9', 10, 10, 0, 0 );
        $this->assertTrue( expAiUsage::approxSeen(), 'an unknown Claude model is priced with the fallback, approximately' );
        $this->assertFalse( expAiUsage::approxSeen(), 'and the flag is forgotten once read' );

        $this->useSettings( array( 'Pricing' => array( 'Enabled' => 'false' ) ) );
        $this->assertNull( expAiUsage::cost( 'claude-sonnet-5-5', 10, 10, 0, 0 ), 'costs switched off' );
        $this->assertSame( array(), expAiUsage::unpricedModels() );
    }

    public function testPricingInfoAndNotes(): void
    {
        $this->useSettings( array( 'Pricing' => array( 'Currency' => 'EUR', 'PricesAsOf' => '2026-01-01', 'Source' => 'the test', 'Multiplier' => '1.2' ) ) );
        $this->assertSame( array( 'as_of' => '2026-01-01', 'source' => 'the test', 'currency' => 'EUR', 'multiplier' => '1.2' ), expAiUsage::pricingInfo() );
        $this->assertSame( 'EUR', expAiUsage::currency() );
        $this->assertSame( '', expAiUsage::unpricedNote( 0 ) );
        $this->assertStringContainsString( '1 request', expAiUsage::unpricedNote( 1 ) );
        $this->assertStringContainsString( '15', expAiUsage::unpricedNote( 15 ) );
    }

    public function testConditionsOfTheLogFilters(): void
    {
        $this->useSettings();
        // the reservations of requests under way are left out unless asked for (with_reservations, the limits)
        $this->assertSame( array( 'reserved_tokens' => 0 ), expAiUsage::conditions( array() ) );
        $this->assertSame( array(), expAiUsage::conditions( array( 'with_reservations' => 1 ) ) );
        $conds = expAiUsage::conditions( array( 'from' => 10, 'user_id' => '5', 'node_id' => 0, 'action' => 'panel', 'model' => '', 'errors' => 1 ) );
        $this->assertSame( array( 'created' => array( false, array( 10, 2147483647 ) ), 'user_id' => 5, 'reserved_tokens' => 0, 'action' => 'panel', 'error_class' => array( '!=', '' ) ), $conds );
        $this->assertSame( array( 'created' => array( false, array( 0, 99 ) ), 'reserved_tokens' => 0 ), expAiUsage::conditions( array( 'to' => 99 ) ) );

        // unpriced: the models without a price; none at all is a filter that matches nothing
        $this->usage( array( 'model' => 'xtd-mystery', 'model_alias' => self::TEST_PROVIDER . '.mystery' ) );
        $unpriced = expAiUsage::conditions( array( 'unpriced' => 1 ) );
        $this->assertContains( 'xtd-mystery', $unpriced['model'][0] );
        $this->assertSame( 0, $unpriced['reported_cost_micros'] );
    }

    public function testPagesTurnsAndTotals(): void
    {
        $this->useSettings();
        $now = time();
        $a = $this->usage( array( 'conversation_id' => 987650001, 'turn' => 1, 'input_tokens' => 100, 'output_tokens' => 10, 'latency_ms' => 1000,
                                  'reported_cost_micros' => 2000000, 'tool_calls' => json_encode( array( array( 'name' => 'get_node' ), array( 'name' => 'get_node' ) ) ),
                                  'tool_count' => 2, 'region' => 'us', 'created' => $now - 30, 'reasoning_tokens' => 5 ) );
        $b = $this->usage( array( 'conversation_id' => 987650001, 'turn' => 1, 'input_tokens' => 200, 'output_tokens' => 20, 'latency_ms' => 3000,
                                  'reported_cost_micros' => 1000000, 'drafts_written' => 1, 'error_class' => 'timeout', 'created' => $now - 20 ) );
        $c = $this->usage( array( 'input_tokens' => 50, 'output_tokens' => 5, 'latency_ms' => 500, 'model' => 'xtd-mystery',
                                  'model_alias' => self::TEST_PROVIDER . '.mystery', 'created' => $now - 10, 'action' => 'field:write' ) );

        $filters = array( 'user_id' => self::TEST_USER );
        $page = expAiUsage::fetchPage( $filters, 'latency_ms', 'asc', 0, 10 );
        $this->assertSame( array( (int) $c->attribute( 'id' ), (int) $a->attribute( 'id' ), (int) $b->attribute( 'id' ) ),
                           array_map( function ( $r ) { return (int) $r->attribute( 'id' ); }, $page ) );
        $this->assertCount( 1, expAiUsage::fetchPage( $filters, 'nonsense', 'sideways', 1, 1 ), 'an unknown sort is by date' );
        $this->assertSame( array( (int) $a->attribute( 'id' ), (int) $b->attribute( 'id' ) ),
                           array_map( function ( $r ) { return (int) $r->attribute( 'id' ); }, expAiUsage::fetchTurn( 987650001, 1 ) ) );

        $agg = expAiUsage::aggregate( $filters );
        $t = $agg['totals'];
        $this->assertSame( 3, $t['requests'] );
        $this->assertSame( 350, $t['input'] );
        $this->assertSame( 35, $t['output'] );
        $this->assertSame( 1, $t['errors'] );
        $this->assertSame( 33.3, $t['error_rate'] );
        $this->assertSame( 1500, $t['avg_latency'] );
        $this->assertSame( 2, $t['tools'] );
        $this->assertSame( 1, $t['drafts'] );
        $this->assertSame( 5, $t['reasoning'] );
        $this->assertEqualsWithDelta( 3.0, $t['cost'], 1e-9, 'the reported costs' );
        $this->assertFalse( $t['cost_known'], 'one model has no price' );
        $this->assertSame( 1, $t['unpriced'] );
        $this->assertSame( array( 'xtd-mystery' => 1 ), $t['unpriced_models'] );
        $this->assertTrue( $t['cost_partial'] );
        $this->assertFalse( $agg['truncated'] );
        $this->assertCount( 1, $agg['per_user'] );
        $this->assertSame( self::TEST_USER, $agg['per_user'][0]['user_id'] );
        $this->assertSame( array( self::TEST_PROVIDER ), array_column( $agg['per_provider'], 'provider' ) );
        $this->assertSame( 'us', $agg['per_provider'][0]['regions'] );
        $this->assertSame( array( date( 'Y-m-d', $now - 30 ) ), array_unique( array_column( $agg['per_day'], 'day' ) ) );

        $turns = expAiUsage::turns( $filters, 0, 10, 'input_tokens', 'desc' );
        $this->assertSame( 2, $turns['count'] );
        $byKey = array_column( $turns['turns'], null, 'key' );
        $turn = $byKey['c987650001-1'];
        $this->assertSame( 2, $turn['requests'] );
        $this->assertSame( 300, $turn['input'] );
        $this->assertSame( array( 'get_node' => 2 ), $turn['tools'] );
        $this->assertTrue( $turn['cost_reported'] );
        $this->assertSame( 1, $turn['errors'] );
        $this->assertSame( $now - 30, $turn['created'], 'a turn starts with its first request' );
        $this->assertSame( array( (int) $a->attribute( 'id' ), (int) $b->attribute( 'id' ) ),
                           array_map( function ( $r ) { return (int) $r->attribute( 'id' ); }, $turn['rows'] ), 'the rows of a turn oldest first' );
        $this->assertFalse( $byKey['r' . $c->attribute( 'id' )]['cost_known'], 'a request without a conversation is a turn of its own' );
        $this->assertSame( 'c987650001-1', $turns['turns'][0]['key'], 'sorted by input, highest first' );
        $asc = expAiUsage::turns( $filters, 0, 1, 'created', 'asc' );
        $this->assertSame( 'c987650001-1', $asc['turns'][0]['key'] );
        $this->assertCount( 1, $asc['turns'] );
    }

    public function testMyUsagePeriodsAndTheDailySeries(): void
    {
        $this->useSettings();
        $now = time();
        $this->usage( array( 'input_tokens' => 100, 'output_tokens' => 10, 'reported_cost_micros' => 500000, 'created' => $now - 60 ) );
        $this->usage( array( 'input_tokens' => 40, 'output_tokens' => 4, 'reported_cost_micros' => 250000, 'created' => $now - 10 * 86400 ) );
        $this->draftLog( array( 'contentobject_id' => 0, 'created' => $now - 30, 'modified' => $now - 30, 'status' => 'published' ) );

        $summary = expAiUsage::summary( self::TEST_USER );
        $this->assertSame( 1, $summary['today']['requests'] );
        $this->assertSame( 110, $summary['today']['tokens'] );
        $this->assertSame( 1, $summary['week']['requests'] );
        $this->assertSame( 2, $summary['month']['requests'] );
        $this->assertEqualsWithDelta( 0.75, $summary['month']['cost'], 1e-9 );

        foreach ( array( 'today', '7d', '30d', 'month' ) as $period )
        {
            $range = expAiUsage::periodRange( $period );
            $this->assertLessThan( $range['from'], $range['prev_to'] );
            $this->assertLessThanOrEqual( $range['to'], $range['from'] );
            $this->assertLessThan( $range['prev_to'], $range['prev_from'] );
        }
        $this->assertSame( 7 * 86400, expAiUsage::periodRange( '7d' )['to'] - expAiUsage::periodRange( '7d' )['from'] );

        $period = expAiUsage::periodSummary( self::TEST_USER, '7d' );
        $this->assertSame( '7d', $period['period'] );
        $this->assertSame( 1, $period['current']['requests'] );
        $this->assertSame( 1, $period['current']['written'] );
        $this->assertSame( 1, $period['current']['sent'], 'a published draft counts as sent' );
        $this->assertSame( 1, $period['previous']['requests'] );
        $this->assertSame( 150.0, $period['change']['tokens'], '110 tokens after 44: +150 %' );
        $this->assertNull( $period['change']['written'], 'no change without a value before' );
        $this->assertArrayNotHasKey( 'cost_known', $period['change'] );

        $series = expAiUsage::dailySeries( self::TEST_USER, 3, 'tokens' );
        $this->assertSame( 3, $series['days'] );
        $this->assertCount( 3, $series['series'] );
        $last = end( $series['series'] );
        $this->assertTrue( $last['today'] );
        $this->assertSame( 110, $last['value'] );
        $this->assertSame( 100.0, (float) $last['percent'] );
        $this->assertSame( 110, $series['max'] );
        $cost = expAiUsage::dailySeries( self::TEST_USER, 100, 'cost' );
        $this->assertSame( 62, $cost['days'], 'at most 62 days' );
        $this->assertEqualsWithDelta( 0.5, end( $cost['series'] )['value'], 1e-9 );
    }

    public function testSlowNoticeAndPrune(): void
    {
        $config = $this->useSettings( array( 'Model_anthropic.xtd-slow' => array( 'Provider' => 'anthropic', 'Id' => 'slow-model', 'Slow' => 'true' ) ) );
        $this->assertSame( '', expAiUsage::slowNotice( 'anthropic.claude-sonnet-5-5', $config ) );
        $this->assertSame( '', expAiUsage::slowNotice( 'no.such', $config ) );
        $this->assertStringContainsString( 'several minutes', expAiUsage::slowNotice( 'anthropic.xtd-slow', $config ) );
        $this->usage( array( 'model_alias' => 'anthropic.xtd-slow', 'latency_ms' => 40000 ) );
        $this->usage( array( 'model_alias' => 'anthropic.xtd-slow', 'latency_ms' => 80000 ) );
        $this->assertStringContainsString( '60', expAiUsage::slowNotice( 'anthropic.xtd-slow', $config ), 'the measured average in seconds' );

        $this->assertSame( 0, expAiUsage::prune( 0 ), 'retention 0 keeps everything' );
        // a row from the first minutes of 1970: older than any real row
        $old = $this->usage( array( 'created' => 5 ) );
        $days = (int) floor( ( time() - 60 ) / 86400 );
        $this->assertGreaterThanOrEqual( 1, expAiUsage::prune( $days ), "the row of 1970 is older than the retention" );
        $this->assertNull( eZPersistentObject::fetchObject( expAiUsageLog::definition(), null, array( 'id' => (int) $old->attribute( 'id' ) ) ) );
        $this->useSettings( array( 'UsageSettings' => array( 'RetentionDays' => '0' ) ) );
        $this->assertSame( 0, expAiUsage::prune() );
    }

    public function testLimitsDefinitionsPeriodsAndLabels(): void
    {
        $config = $this->useSettings( array( 'Limits' => array( 'ProviderMaxTokensPerMonth' => array( self::TEST_PROVIDER => '100', 'BAD ID' => '5', 'x' => '0' ),
                                                                'ProviderMaxCostPerMonth' => array( self::TEST_PROVIDER => '2.5' ) ) ) );
        $this->assertCount( 8, expAiLimits::definitions() );
        $defs = expAiLimits::providerDefinitions( $config );
        $this->assertSame( array( 'provider_month_tokens:' . self::TEST_PROVIDER, 'provider_month_cost:' . self::TEST_PROVIDER ), array_keys( $defs ) );
        $this->assertSame( 2.5, $defs['provider_month_cost:' . self::TEST_PROVIDER][5] );
        $this->assertSame( mktime( 0, 0, 0 ), expAiLimits::periodStart( 'day' ) );
        $this->assertSame( mktime( 0, 0, 0, (int) date( 'n' ), 1 ), expAiLimits::periodStart( 'month' ) );
        $this->assertSame( 0, expAiLimits::periodStart( 'conversation' ) );
        $this->assertSame( mktime( 0, 0, 0, (int) date( 'n' ), (int) date( 'j' ) + 1 ), expAiLimits::periodEnd( 'day' ) );
        $this->assertSame( mktime( 0, 0, 0, (int) date( 'n' ) + 1, 1 ), expAiLimits::periodEnd( 'month' ) );
        $this->assertNull( expAiLimits::periodEnd( 'session' ) );
        $this->assertSame( 'tokens per day', expAiLimits::label( 'day_tokens' ) );
        $this->assertSame( 'unknown_key', expAiLimits::label( 'unknown_key' ) );
        $this->assertStringContainsString( 'x1', expAiLimits::label( 'provider_month_cost:x1' ) );
        $this->assertStringContainsString( 'cost', expAiLimits::label( 'provider_month_cost:x1' ) );
        $this->assertStringContainsString( 'tokens', expAiLimits::label( 'provider_month_tokens:x1' ) );
        $key = expAiLimits::sessionKey();
        $this->assertTrue( $key === '' || strlen( $key ) === 40, 'a hash of the session, never the id itself' );
    }

    public function testLimitsStatusAndRefusal(): void
    {
        $this->useSettings( array( 'Limits' => array( 'MaxTokensPerConversation' => '1000', 'MaxTokensPerUserPerDay' => '200', 'MaxCostPerUserPerDay' => '10',
                                                      'MaxTokensPerUserPerMonth' => '', 'ProviderMaxTokensPerMonth' => array( self::TEST_PROVIDER => '50' ) ) ) );
        $this->usage( array( 'conversation_id' => 987650002, 'input_tokens' => 150, 'output_tokens' => 30, 'reported_cost_micros' => 9000000 ) );
        $status = expAiLimits::status( array( 'user_id' => self::TEST_USER, 'conversation_id' => 987650002 ) );
        $this->assertSame( 180, $status['conversation']['used'] );
        $this->assertSame( 'ok', $status['conversation']['level'] );
        $this->assertSame( 90.0, (float) $status['day_tokens']['percent'] );
        $this->assertSame( 'high', $status['day_tokens']['level'] );
        $this->assertSame( 'high', $status['day_cost']['level'] );
        $this->assertSame( 9.0, (float) $status['day_cost']['used'] );
        $this->assertSame( 'ok', $status['month_tokens']['level'], 'an empty limit is no limit' );
        $this->assertSame( 0, $status['month_tokens']['percent'] );
        $this->assertSame( 'over', $status['provider_month_tokens:' . self::TEST_PROVIDER]['level'] );
        $this->assertSame( self::TEST_PROVIDER, $status['provider_month_tokens:' . self::TEST_PROVIDER]['provider'] );
        $this->assertSame( 0, expAiLimits::status( array( 'user_id' => self::TEST_USER ) )['conversation']['used'], 'no conversation, nothing used' );
        $this->assertArrayNotHasKey( 'provider_month_tokens:' . self::TEST_PROVIDER,
                                     expAiLimits::status( array( 'user_id' => self::TEST_USER, 'provider' => 'other', 'only_provider' => 1 ) ) );

        // the provider's limit stops requests to that provider only
        expAiLimits::check( array( 'user_id' => self::TEST_USER, 'provider' => 'anthropic' ) );
        $e = $this->expectFailure( function () { expAiLimits::check( array( 'user_id' => self::TEST_USER, 'provider' => self::TEST_PROVIDER ) ); } );
        $this->assertSame( 'The limit %limit is reached (%used of %max). It resets %reset.', $e->getMessage() );
        $this->assertSame( '180', $e->arguments()['%used'] );
        $this->assertStringContainsString( date( 'Y-m-01', expAiLimits::periodEnd( 'month' ) ), $e->arguments()['%reset'] );

        // a user limit: the day's tokens
        $this->usage( array( 'input_tokens' => 30 ) );
        $e = $this->expectFailure( function () { expAiLimits::check( array( 'user_id' => self::TEST_USER, 'provider' => 'anthropic' ) ); } );
        $this->assertSame( 'limit MaxTokensPerUserPerDay', $e->detail() );

        // a cost limit names the currency
        $this->useSettings( array( 'Limits' => array( 'MaxCostPerUserPerMonth' => '5' ) ) );
        $e = $this->expectFailure( function () { expAiLimits::check( array( 'user_id' => self::TEST_USER ) ); } );
        $this->assertStringContainsString( 'USD', $e->arguments()['%max'] );

        // no limit set: nothing is even counted
        $this->useSettings();
        expAiLimits::check( array( 'user_id' => self::TEST_USER ) );
        $this->addToAssertionCount( 1 );
    }

    public function testLatestRateLimits(): void
    {
        $this->useSettings();
        $before = expAiLimits::latestRateLimits();
        $this->assertTrue( $before === array() || isset( $before['time'], $before['values'] ) );
        $this->usage( array( 'provider' => 'anthropic', 'rate_limits' => json_encode( array( 'anthropic-ratelimit-requests-remaining' => '42' ) ), 'created' => time() ) );
        $latest = expAiLimits::latestRateLimits();
        $this->assertSame( array( 'anthropic-ratelimit-requests-remaining' => '42' ), $latest['values'] );
    }
}
