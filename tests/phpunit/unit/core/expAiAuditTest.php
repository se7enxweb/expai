<?php
/**
 * expAiAudit (what expai records in the audit trail) and expAiAuditSource (the audit console's source of the channel
 * "ai": links, the dashboard card, an event's fields and links, object links, the chart). The audit of the suite
 * writes into its throwaway directory (expAiTestAudit), never into the site's audit trail.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiAuditTest extends expAiCoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if ( !class_exists( 'expAiAudit' ) || !expAiAudit::available() || !defined( 'EXPAI_TEST_AUDIT_DIR' ) )
            $this->markTestSkipped( 'needs the kernel audit (expAudit) and the isolated audit directory of the suite' );
        $fixtures = EXPAI_TEST_ROOT . '/tests/tests/kernel/classes/audit/fixtures/expaudittestfixtures.php';
        if ( !is_file( $fixtures ) )
            $this->markTestSkipped( 'needs the kernel audit test fixtures' );
        require_once $fixtures;
        expAiTestAudit::isolate( EXPAI_TEST_AUDIT_DIR );
    }

    /** @return array the newest record of the channel "ai" named $name, or null */
    private function last( $name )
    {
        expAudit::flushFinal();
        $list = array_values( array_filter( expAuditTestFixtures::events( EXPAI_TEST_AUDIT_DIR . '/', expAiAudit::CHANNEL ), function ( $r ) use ( $name ) { return $r['name'] === $name; } ) );
        return $list ? end( $list ) : null;
    }

    public function testEventsOfChainsModelsBudgetsAndMcp(): void
    {
        $this->asUser( 'admin' );
        $this->useSettings();
        expAiAudit::chain( 'start', array( 41, 42 ), 2, 'article', 'timeout' );
        $chain = $this->last( 'content.expai_chain.start' );
        $this->assertSame( 'expai_chain_run', $chain['object']['type'] );
        $this->assertSame( 'failed', $chain['result'] );
        $this->assertSame( array( 'node', 2 ), array( $chain['target']['type'], (int) $chain['target']['id'] ) );
        $before = $this->last( 'content.expai_chain.nonsense' );
        expAiAudit::chain( 'nonsense', array( 1 ) );
        $this->assertSame( $before, $this->last( 'content.expai_chain.nonsense' ), 'an unknown action is not recorded' );

        expAiAudit::modelAction( 'load', 'local.qwen', 'unreachable' );
        $model = $this->last( 'system.expai.model.action' );
        $this->assertSame( 'local.qwen', (string) $model['object']['id'] );
        $this->assertSame( 'failed', $model['result'] );

        expAiAudit::mcpCall( 'docs', 'search', 0, 'failed' );
        $mcp = $this->last( 'system.expai.mcp.call.failed' );
        $this->assertSame( 'docs:search', $mcp['object']['id'] );
        $this->assertArrayNotHasKey( 'target', $mcp );

        expAiAudit::budgetHold( null );
        expAiAudit::policyMigration( array() );
        expAiAudit::draftWritten( null );
        expAiAudit::request( null );
        $this->assertNull( $this->last( 'system.expai.policy.migrate.nothing' ), 'nothing to record, nothing recorded' );

        // the switch, with a node
        expAiAudit::switchedOff( array( 'model_alias' => 'anthropic.x', 'node_id' => 2, 'purpose' => 'panel', 'provider' => 'anthropic' ), 'siteaccess' );
        $off = $this->last( 'system.expai.request.refused' );
        $this->assertSame( 'switched_off', $off['reason'] );
        $this->assertSame( 'siteaccess', $off['after']['switched_off'] );

        // a failure of the data: never into the caller
        $this->assertNull( expAiAudit::event( 'system.expai.model.action', function () { throw new RuntimeException( 'broken data' ); } ) );
    }

    public function testRequestsWithErrorsAndOtherUsers(): void
    {
        $this->asUser( 'admin' );
        $this->useSettings();
        $log = new expAiUsageLog( array( 'id' => 77, 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'model_alias' => '', 'error_class' => 'timeout',
                                         'http_status' => 504, 'user_id' => self::TEST_USER, 'node_id' => 0, 'input_tokens' => 1, 'output_tokens' => 1,
                                         'error_message' => 'SECRET XTD provider text', 'price_factor' => 0 ) );
        expAiAudit::request( $log );
        $failed = $this->last( 'system.expai.request.failed' );
        $this->assertSame( 'error', $failed['reason'] );
        $this->assertSame( 'HTTP 504', $failed['error']['message'], 'the status, never the provider\'s text' );
        $this->assertSame( self::TEST_USER, (int) $failed['after']['user_id'], 'made for another user' );
        $this->assertStringNotContainsString( 'SECRET XTD', json_encode( $failed ) );
        $this->assertSame( 'claude-sonnet-5-5', $failed['object']['name'] );

        $draft = new expAiDraftLog( array( 'id' => 5, 'contentobject_id' => 9, 'version' => 2, 'node_id' => 0, 'source' => 'panel', 'status' => 'draft' ) );
        expAiAudit::draftState( $draft, 'draft', 'nonsense' );
        expAiAudit::draftState( $draft, 'draft', 'kept' );
        $kept = $this->last( 'content.expai_draft.keep' );
        $this->assertSame( 'object', $kept['object']['type'] );
    }

    public function testTheSourceOfTheConsole(): void
    {
        $this->asUser( 'admin' );
        $this->useSettings();
        $source = new expAiAuditSource();
        $this->assertSame( 'ai', $source->channel() );
        $this->assertSame( '', $source->problem() );
        $this->assertNotSame( '', $source->label() );

        foreach ( array( 'audit/recent', 'audit/charts', 'audit/export', 'audit/alerts', 'audit/console' ) as $view )
        {
            $other = array_column( $source->links( $view, array() ), 'url' );
            $own = array_column( $source->links( $view, array( 'channel' => 'ai' ) ), 'url' );
            $this->assertNotEmpty( $other, $view );
            $this->assertLessThanOrEqual( count( $other ), count( $own ), "$view: fewer links in the channel itself" );
            $this->assertContains( 'expai/log/(view)/drafts', $other, 'the administrator sees the log' );
        }
        $this->assertContains( 'audit/recent/(channel)/ai', array_column( $source->links( 'audit/recent', array() ), 'url' ) );
        $narrow = array_column( $source->links( 'audit/console', array( 'user' => '14', 'object' => 'node:2', 'from' => '2026-01-01', 'to' => 'bad' ) ), 'url' );
        $this->assertContains( 'expai/log/(user)/14/(node)/2/(from)/2026-01-01', $narrow );
        $this->assertContains( 'expai/log/(user)/14/(node)/2/(from)/2026-01-01/(errors)/1', $narrow );

        $this->assertSame( 'expai/log/(request)/5', $source->linkFor( 'expai_request', '5' ) );
        $this->assertSame( 'expai/chain/(run)/6', $source->linkFor( 'expai_chain_run', 6 ) );
        $this->assertSame( 'expqueue/job/abc-1', $source->linkFor( 'expqueue_job', 'abc-1' ) );
        $this->assertNull( $source->linkFor( 'expqueue_job', 'bad id!' ) );
        $this->assertSame( 'expai/models', $source->linkFor( 'expai_model', 'x' ) );
        $this->assertNull( $source->linkFor( 'expai_request', '0' ) );
        $this->assertNull( $source->linkFor( 'something', '1' ) );
        $chart = $source->charts();
        $this->assertCount( 5, $chart[0]['series'] );

        $event = $source->event( array( 'name' => 'content.expai_draft.write', 'object' => array( 'type' => 'expai_request', 'id' => '12' ), 'actor' => array( 'user_id' => 14 ),
            'after' => array( 'provider' => 'anthropic', 'model' => 'claude', 'model_alias' => 'anthropic.claude', 'region' => 'us', 'retention' => '30d',
                              'action' => 'panel', 'usage' => array( 'in' => 10, 'out' => 5, 'cache_read' => 3, 'cache_write' => 'x' ), 'cost_micros' => 25000,
                              'latency_ms' => 900, 'http_status' => 200, 'lane' => 'batch', 'fallback_from' => 'local.qwen', 'fallback_reason' => 'stopped',
                              'source' => 'panel', 'version' => 2, 'language' => 'eng-GB', 'chain' => 'article', 'server' => 'docs', 'tool' => 'search',
                              'user_id' => 99, 'thread_id' => 3 ) ) );
        $fields = array_column( $event['fields'], 'value', 'label' );
        $this->assertSame( 'anthropic.claude (claude)', $fields[ezpI18n::tr( 'extension/expai/audit', 'Model' )] );
        $this->assertStringContainsString( '10', $fields[ezpI18n::tr( 'extension/expai/audit', 'Tokens' )] );
        $this->assertStringContainsString( '3', $fields[ezpI18n::tr( 'extension/expai/audit', 'Tokens' )] );
        $this->assertSame( '900 ms', $fields[ezpI18n::tr( 'extension/expai/audit', 'Latency' )] );
        $this->assertSame( 'local.qwen (stopped)', $fields[ezpI18n::tr( 'extension/expai/audit', 'Fallback from' )] );
        $this->assertSame( 'panel', $fields[ezpI18n::tr( 'extension/expai/audit', 'Draft source' )] );
        $links = array_column( $event['links'], 'url' );
        $this->assertContains( 'expai/log/(request)/12', $links );
        $this->assertContains( 'expai/log/(thread)/3', $links );
        $this->assertContains( 'expai/log/(view)/drafts', $links );
        foreach ( array( 'expai_chain_run' => 'expai/chain/(run)/4', 'expqueue_job' => 'expqueue/job/u-1' ) as $type => $url )
        {
            $other = $source->event( array( 'name' => 'system.expai.budget.hold', 'object' => array( 'type' => $type, 'id' => $type === 'expqueue_job' ? 'u-1' : '4' ) ) );
            $this->assertContains( $url, array_column( $other['links'], 'url' ) );
            $this->assertContains( 'expai/budgets', array_column( $other['links'], 'url' ) );
        }
        $plain = $source->event( array( 'name' => 'system.expai.model.action', 'after' => 'not an array' ) );
        $this->assertSame( array( 'fields' => array(), 'links' => array() ), $plain );
    }

    public function testTheDashboardCard(): void
    {
        $this->requireDatabase();
        $this->asUser( 'admin' );
        $this->useSettings();
        $this->removeTestRows();
        $this->usage( array( 'created' => time(), 'reported_cost_micros' => 1000000 ) );
        $query = new expAiCoreTestAuditQuery( array( 'system.expai.request.*' => 9, 'system.expai.request.failed' => 2, 'system.expai.budget.hold' => 1 ),
                                           array( array( 'login' => 'admin', 'n' => 4 ), array( 'login' => '', 'n' => 1 ), array( 'object_name' => 'anthropic.claude', 'n' => 3 ),
                                                  array( 'object_name' => 'a model with spaces', 'n' => 1 ) ) );
        $now = (int) ( microtime( true ) * 1000 );
        $card = ( new expAiAuditSource() )->dashboard( $query, array( 'ai' ), array( 'today_ms' => $now - 3600000, 'week_ms' => $now - 7 * 86400000 ) );
        $figures = array_column( $card['figures'], null, 'label' );
        $failed = $figures[ezpI18n::tr( 'extension/expai/audit', 'failed, 7 days' )];
        $this->assertSame( 2, $failed['value'] );
        $this->assertSame( 'failed', $failed['state'] );
        $this->assertSame( '', $figures[ezpI18n::tr( 'extension/expai/audit', 'refused, 7 days' )]['state'] );
        $this->assertArrayHasKey( ezpI18n::tr( 'extension/expai/audit', 'held by a budget, 7 days' ), $figures );
        $this->assertNotEmpty( array_filter( array_keys( $figures ), function ( $l ) { return strpos( $l, 'cost' ) !== false; } ), 'the estimated cost from the usage log' );
        $users = $card['lists'][0]['items'];
        $this->assertSame( array( 'admin' ), array_column( $users, 'label' ), 'an empty login is left out' );
        $models = array_column( $card['lists'][1]['items'], 'url', 'label' );
        $this->assertSame( 'expai/log/(model)/anthropic.claude', $models['anthropic.claude'] );
        $this->assertStringNotContainsString( 'expai/log', $models['a model with spaces'] );
        $this->assertContains( 'expai/budgets', array_column( $card['links'], 'url' ) );
        $this->assertNotSame( '', $card['note'] );
    }
}

/** An audit index that answers what the test says */
class expAiCoreTestAuditQuery extends expAuditQuery
{
    private $counts;
    private $groups;

    public function __construct( array $counts, array $groups )
    {
        $this->counts = $counts;
        $this->groups = $groups;
    }

    public function count( array $f, ?array $channels )
    {
        return isset( $this->counts[$f['name']] ) ? $this->counts[$f['name']] : 0;
    }

    public function groupCount( array $columns, array $f, ?array $channels, $limit = 10 )
    {
        $column = $columns[0];
        return array_values( array_filter( $this->groups, function ( $r ) use ( $column ) { return array_key_exists( $column, $r ); } ) );
    }
}
