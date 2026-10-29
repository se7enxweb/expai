<?php
/**
 * File containing the expAiAuditSource class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The source of Exponential Intelligence in the audit console (audit.ini [AuditSourceSettings] Sources[expai],
 * doc/audit-trail.md): the dashboard panel (requests, failures, refusals, drafts written and published, budget holds
 * and the estimated cost, today and in 7 days; the top users and models), the links of the console's views, the
 * fields and links of an event of the channel "ai", the chart of requests and drafts per day, the columns of its CSV
 * export (provider, model, region, purpose, lane, tokens, latency, status, fallback, cost). Every link into
 * expai's own pages is offered only to a user with the policy that page needs.
 */
class expAiAuditSource extends expAuditSourceBase
{
    public function label()
    {
        return ezpI18n::tr( 'extension/expai/audit', 'Exponential Intelligence' );
    }

    public function channel()
    {
        return expAiAudit::CHANNEL;
    }

    public function problem()
    {
        return class_exists( 'expAiUsageLog' ) && class_exists( 'expAiAudit' ) ? '' : 'the classes of expai are not loaded';
    }

    public function links( $view, array $filters )
    {
        $t = function ( $text ) { return ezpI18n::tr( 'extension/expai/audit', $text ); };
        $links = array();
        $ownChannel = isset( $filters['channel'] ) && $filters['channel'] === expAiAudit::CHANNEL;
        switch ( $view )
        {
            case 'audit/recent':
                if ( !$ownChannel )
                    $links[] = array( 'label' => $t( 'Recent AI events' ), 'url' => 'audit/recent/(channel)/' . expAiAudit::CHANNEL );
                break;
            case 'audit/charts':
                if ( !$ownChannel )
                    $links[] = array( 'label' => $t( 'Charts of the AI events' ), 'url' => self::consoleUrl( 'audit/charts', array( 'channel' => expAiAudit::CHANNEL ) ) );
                break;
            case 'audit/export':
                if ( !$ownChannel )
                    $links[] = array( 'label' => $t( 'Export the AI events' ), 'url' => self::consoleUrl( 'audit/export', array( 'channel' => expAiAudit::CHANNEL ) ) );
                break;
            case 'audit/alerts':
                $links[] = array( 'label' => $t( 'Alerts on AI events' ), 'url' => self::consoleUrl( 'audit/console', array( 'name' => 'system.audit.alert', 'q' => 'expai_request_failures' ) ) );
                break;
        }
        if ( !$ownChannel || $view !== 'audit/console' )
            $links[] = array( 'label' => $t( 'AI events in the console' ), 'url' => self::consoleUrl( 'audit/console', array( 'channel' => expAiAudit::CHANNEL ) ) );
        $links[] = array( 'label' => $t( 'Failed requests' ), 'url' => self::consoleUrl( 'audit/console', array( 'name' => 'system.expai.request.failed' ) ) );
        $links[] = array( 'label' => $t( 'Drafts' ), 'url' => self::consoleUrl( 'audit/console', array( 'name' => 'content.expai_draft.*' ) ) );
        if ( self::canSeeLog() )
        {
            // the same narrowing in expai/log: a user, a node, a day range (whole days only)
            $params = array();
            if ( isset( $filters['user'] ) && ctype_digit( (string) $filters['user'] ) )
                $params['user'] = (string) (int) $filters['user'];
            if ( isset( $filters['object'] ) && preg_match( '/^node:(\d+)$/', (string) $filters['object'], $m ) )
                $params['node'] = $m[1];
            foreach ( array( 'from', 'to' ) as $k )
                if ( isset( $filters[$k] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $filters[$k] ) )
                    $params[$k] = (string) $filters[$k];
            $links[] = array( 'label' => $t( 'EI: Log' ), 'url' => self::logUrl( $params ) );
            $links[] = array( 'label' => $t( 'EI: Log, errors' ), 'url' => self::logUrl( $params + array( 'errors' => '1' ) ) );
            $links[] = array( 'label' => $t( 'EI: Drafts' ), 'url' => 'expai/log/(view)/drafts' );
        }
        return $links;
    }

    public function dashboard( expAuditQuery $q, ?array $allowed, array $period )
    {
        $t = function ( $text ) { return ezpI18n::tr( 'extension/expai/audit', $text ); };
        $today = array( 'from_ms' => (int) $period['today_ms'] );
        $week = array( 'from_ms' => (int) $period['week_ms'] );
        $count = function ( array $f, $name ) use ( $q, $allowed ) { return (int) $q->count( $f + array( 'name' => $name ), $allowed ); };
        $requests = $count( $week, 'system.expai.request.*' );
        $failed = $count( $week, 'system.expai.request.failed' );
        $refused = $count( $week, 'system.expai.request.refused' );
        $written = $count( $week, 'content.expai_draft.write' );
        $published = $count( $week, 'content.expai_draft.publish' );
        $holds = $count( $week, 'system.expai.budget.hold' );
        $console = function ( array $filters ) { return self::consoleUrl( 'audit/console', $filters ); };
        $figures = array(
            array( 'label' => $t( 'requests today' ), 'value' => $count( $today, 'system.expai.request.*' ), 'url' => $console( array( 'name' => 'system.expai.request.*', 'from' => date( 'Y-m-d' ) ) ) ),
            array( 'label' => $t( 'requests, 7 days' ), 'value' => $requests, 'url' => $console( array( 'name' => 'system.expai.request.*' ) ) ),
            array( 'label' => $t( 'failed, 7 days' ), 'value' => $failed, 'url' => $console( array( 'name' => 'system.expai.request.failed' ) ), 'state' => $failed ? 'failed' : '' ),
            array( 'label' => $t( 'refused, 7 days' ), 'value' => $refused, 'url' => $console( array( 'name' => 'system.expai.request.refused' ) ), 'state' => $refused ? 'refused' : '' ),
            array( 'label' => $t( 'drafts written, 7 days' ), 'value' => $written, 'url' => $console( array( 'name' => 'content.expai_draft.write' ) ) ),
            array( 'label' => $t( 'drafts published, 7 days' ), 'value' => $published, 'url' => $console( array( 'name' => 'content.expai_draft.publish' ) ) ),
        );
        if ( $holds )
            $figures[] = array( 'label' => $t( 'held by a budget, 7 days' ), 'value' => $holds, 'url' => $console( array( 'name' => 'system.expai.budget.hold' ) ), 'state' => 'refused' );
        // the estimated cost of the period, from expai's own usage log (the audit records carry it per request)
        if ( class_exists( 'expAiCost' ) && expAiCost::enabled() )
        {
            $totals = expAiUsage::aggregate( array( 'from' => (int) floor( $period['week_ms'] / 1000 ) ) )['totals'];
            if ( $totals['requests'] )
                $figures[] = array( 'label' => $totals['cost_known'] ? $t( 'estimated cost, 7 days' ) : $t( 'estimated cost, 7 days (some prices unknown)' ),
                                    'value' => expAiCost::money( $totals['cost'], !empty( $totals['cost_approx'] ) ) );
        }
        $top = function ( $column, $link ) use ( $q, $allowed, $week )
        {
            $items = array();
            foreach ( $q->groupCount( array( $column ), $week + array( 'name' => 'system.expai.request.*' ), $allowed, 5 ) as $r )
                if ( (string) $r[$column] !== '' )
                    $items[] = array( 'label' => (string) $r[$column], 'n' => (int) $r['n'], 'url' => $link( (string) $r[$column] ) );
            return $items;
        };
        $lists = array(
            array( 'title' => $t( 'Top users, 7 days' ), 'items' => $top( 'login', function ( $v ) { return self::consoleUrl( 'audit/console', array( 'channel' => expAiAudit::CHANNEL, 'login' => $v ) ); } ) ),
            array( 'title' => $t( 'Top models, 7 days' ), 'items' => $top( 'object_name', function ( $v )
            {
                return self::canSeeLog() && preg_match( '/^[A-Za-z0-9._:-]+$/', $v ) ? self::logUrl( array( 'model' => $v ) )
                                                                                       : self::consoleUrl( 'audit/console', array( 'channel' => expAiAudit::CHANNEL, 'q' => $v ) );
            } ) ),
        );
        $links = array( array( 'label' => $t( 'Charts' ), 'url' => self::consoleUrl( 'audit/charts', array( 'channel' => expAiAudit::CHANNEL ) ) ) );
        if ( self::canSeeLog() )
        {
            $links[] = array( 'label' => $t( 'EI: Log' ), 'url' => 'expai/log' );
            $links[] = array( 'label' => $t( 'EI: Log, errors' ), 'url' => 'expai/log/(errors)/1' );
            $links[] = array( 'label' => $t( 'EI: Drafts' ), 'url' => 'expai/log/(view)/drafts' );
            $links[] = array( 'label' => $t( 'EI: Budgets' ), 'url' => 'expai/budgets' );
        }
        return array( 'figures' => $figures, 'lists' => $lists, 'links' => $links,
                      'note' => $t( 'The audit trail records who asked which model for what, never a prompt or an answer.' ) );
    }

    public function event( array $record )
    {
        $t = function ( $text ) { return ezpI18n::tr( 'extension/expai/audit', $text ); };
        $after = isset( $record['after'] ) && is_array( $record['after'] ) ? $record['after'] : array();
        $get = function ( $key ) use ( $after ) { return isset( $after[$key] ) && is_scalar( $after[$key] ) ? (string) $after[$key] : ''; };
        $name = isset( $record['name'] ) ? (string) $record['name'] : '';
        $fields = array();
        $add = function ( $label, $value ) use ( &$fields ) { if ( $value !== '' ) $fields[] = array( 'label' => $label, 'value' => $value ); };
        $add( $t( 'Provider' ), $get( 'provider' ) );
        $add( $t( 'Model' ), $get( 'model_alias' ) !== '' ? $get( 'model_alias' ) . ( $get( 'model' ) !== '' ? ' (' . $get( 'model' ) . ')' : '' ) : $get( 'model' ) );
        $add( $t( 'Region' ), trim( $get( 'region' ) . ( $get( 'retention' ) !== '' ? ', ' . $t( 'retention' ) . ' ' . $get( 'retention' ) : '' ), ', ' ) );
        $add( $t( 'Purpose' ), $get( 'action' ) );
        $usage = isset( $after['usage'] ) && is_array( $after['usage'] ) ? array_map( 'intval', array_filter( $after['usage'], 'is_numeric' ) ) : array();
        $usage += array( 'in' => 0, 'out' => 0, 'cache_write' => 0, 'cache_read' => 0 );
        if ( $usage['in'] || $usage['out'] )
            $add( $t( 'Tokens' ), ezpI18n::tr( 'extension/expai/audit', '%in in, %out out', null, array( '%in' => $usage['in'], '%out' => $usage['out'] ) )
                                  . ( $usage['cache_read'] + $usage['cache_write'] ? ', ' . ezpI18n::tr( 'extension/expai/audit', '%n cache', null, array( '%n' => $usage['cache_read'] + $usage['cache_write'] ) ) : '' ) );
        if ( $get( 'cost_micros' ) !== '' && class_exists( 'expAiCost' ) )
            $add( $t( 'Estimated cost' ), expAiCost::money( (int) $get( 'cost_micros' ) / 1000000 ) );
        if ( $get( 'latency_ms' ) !== '' )
            $add( $t( 'Latency' ), (int) $get( 'latency_ms' ) . ' ms' );
        $add( $t( 'HTTP status' ), $get( 'http_status' ) );
        $add( $t( 'Lane' ), $get( 'lane' ) );
        if ( $get( 'fallback_from' ) !== '' )
            $add( $t( 'Fallback from' ), $get( 'fallback_from' ) . ( $get( 'fallback_reason' ) !== '' ? ' (' . $get( 'fallback_reason' ) . ')' : '' ) );
        $add( $t( 'Draft source' ), strpos( $name, 'content.expai_draft.' ) === 0 ? $get( 'source' ) : '' );
        $add( $t( 'Version' ), $get( 'version' ) );
        $add( $t( 'Language' ), $get( 'language' ) );
        $add( $t( 'Chain' ), $get( 'chain' ) );
        $add( $t( 'MCP server' ), $get( 'server' ) );
        $add( $t( 'MCP tool' ), $get( 'tool' ) );
        $add( $t( 'For user' ), $get( 'user_id' ) );

        $links = array();
        $object = isset( $record['object'] ) && is_array( $record['object'] ) ? $record['object'] : array();
        $type = isset( $object['type'] ) ? (string) $object['type'] : '';
        $id = isset( $object['id'] ) ? (string) $object['id'] : '';
        $userID = isset( $record['actor']['user_id'] ) ? (int) $record['actor']['user_id'] : 0;
        $ownRow = $get( 'user_id' ) !== '' ? (int) $get( 'user_id' ) === (int) eZUser::currentUserID() : $userID === (int) eZUser::currentUserID();
        if ( $type === 'expai_request' && ctype_digit( $id ) && (int) $id > 0 && ( self::canSeeLog() || ( $ownRow && expAiServerFunctions::hasPolicy( 'write' ) ) ) )
            $links[] = array( 'label' => $t( 'This request in EI: Log' ), 'url' => 'expai/log/(request)/' . (int) $id );
        if ( (int) $get( 'thread_id' ) && self::canSeeLog() )
            $links[] = array( 'label' => $t( 'The requests of this thread' ), 'url' => 'expai/log/(thread)/' . (int) $get( 'thread_id' ) );
        if ( strpos( $name, 'content.expai_draft.' ) === 0 && expAiServerFunctions::hasPolicy( 'write' ) )
            $links[] = array( 'label' => $t( 'EI: Drafts' ), 'url' => 'expai/log/(view)/drafts' );
        if ( $type === 'expai_chain_run' && (int) $id > 0 && expAiServerFunctions::hasPolicy( 'chain' ) )
            $links[] = array( 'label' => $t( 'This chain run' ), 'url' => 'expai/chain/(run)/' . (int) $id );
        if ( $type === 'expqueue_job' && $id !== '' && self::hasPolicy( 'expqueue', 'view' ) )
            $links[] = array( 'label' => $t( 'This job' ), 'url' => 'expqueue/job/' . rawurlencode( $id ) );
        if ( strpos( $name, 'system.expai.budget.' ) === 0 && self::canSeeLog() )
            $links[] = array( 'label' => $t( 'EI: Budgets' ), 'url' => 'expai/budgets' );
        return array( 'fields' => $fields, 'links' => $links );
    }

    public function linkFor( $type, $id )
    {
        if ( $type === 'expai_request' && ctype_digit( (string) $id ) && (int) $id > 0 && self::canSeeLog() )
            return 'expai/log/(request)/' . (int) $id;
        if ( $type === 'expai_chain_run' && ctype_digit( (string) $id ) && (int) $id > 0 && expAiServerFunctions::hasPolicy( 'chain' ) )
            return 'expai/chain/(run)/' . (int) $id;
        if ( $type === 'expqueue_job' && preg_match( '/^[A-Za-z0-9-]{1,64}$/', (string) $id ) && self::hasPolicy( 'expqueue', 'view' ) )
            return 'expqueue/job/' . $id;
        if ( $type === 'expai_model' && self::hasPolicy( 'expai', 'model_manage' ) )
            return 'expai/models';
        return null;
    }

    public function charts()
    {
        $t = function ( $text ) { return ezpI18n::tr( 'extension/expai/audit', $text ); };
        return array( array( 'title' => $t( 'Exponential Intelligence per day' ), 'series' => array(
            array( 'label' => $t( 'requests' ), 'name' => 'system.expai.request' ),
            array( 'label' => $t( 'failed' ), 'name' => 'system.expai.request.failed' ),
            array( 'label' => $t( 'refused' ), 'name' => 'system.expai.request.refused' ),
            array( 'label' => $t( 'drafts written' ), 'name' => 'content.expai_draft.write' ),
            array( 'label' => $t( 'drafts published' ), 'name' => 'content.expai_draft.publish' ),
        ) ) );
    }

    /**
     * The CSV export of the channel "ai" (audit/export/(channel)/ai/(format)/csv): the request's routing and usage
     * after the generic columns. Only these fields: never a prompt, an answer, a draft's text or a key.
     */
    public function exportColumns()
    {
        return array(
            'provider'        => 'after.provider',
            'model'           => 'after.model',
            'model_alias'     => 'after.model_alias',
            'region'          => 'after.region',
            'purpose'         => 'after.action',
            'lane'            => 'after.lane',
            'tokens_in'       => 'after.usage.in',
            'tokens_out'      => 'after.usage.out',
            'latency_ms'      => 'after.latency_ms',
            'http_status'     => 'after.http_status',
            'status'          => 'after.status',
            'fallback_from'   => 'after.fallback_from',
            'fallback_reason' => 'after.fallback_reason',
            'cost_micros'     => 'after.cost_micros',
            'currency'        => 'after.currency',
        );
    }

    /** @return bool the user may see every user's requests in expai/log (expai/write and expai/log) */
    protected static function canSeeLog()
    {
        return class_exists( 'expAiServerFunctions' ) && expAiServerFunctions::hasPolicy( 'write' ) && expAiServerFunctions::hasPolicy( 'log' );
    }

    /** @return string expai/log with view parameters */
    protected static function logUrl( array $params )
    {
        $url = 'expai/log';
        foreach ( $params as $k => $v )
            if ( $v !== '' )
                $url .= '/(' . $k . ')/' . str_replace( array( '%3A', '%2F' ), array( ':', '%252F' ), rawurlencode( (string) $v ) );
        return $url;
    }
}
