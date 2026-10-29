<?php
/**
 * expai/log: what Exponential Intelligence did and what it used.
 *
 *   expai/log                      the requests to the model, as turns (one editor message or one field action and
 *                                  the requests it led to), with filters, totals, per-user and per-day summaries
 *   expai/log/(view)/drafts        the versions it wrote
 *   expai/log/(export)/csv         the filtered requests as CSV (no request texts)
 *
 * Filters are view parameters, e.g. expai/log/(from)/2026-10-01/(to)/2026-10-31/(user)/14/(errors)/1/(sort)/latency_ms/(dir)/asc:
 * from, to (YYYY-MM-DD), user (user object id, with expai/log only), node, action, model (a model alias of the
 * registry, or a model id), provider, region (us, eu, local), errors (1),
 * request (one request, by its id), thread (the requests of one thread; both from links, the filter form drops them),
 * unpriced (1: the requests of models without a price, from the note beside a summed cost),
 * sort (created, latency_ms, input_tokens, output_tokens), dir (asc, desc), offset. The filter form posts and is
 * redirected to that address.
 * Users with expai/write see their own rows; with expai/log everyone's.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** @var eZModule $Module */
$Module = $Params['Module'];
$http = eZHTTPTool::instance();
$user = $Params['UserParameters'];
$offset = isset( $user['offset'] ) ? max( 0, (int) $user['offset'] ) : 0;
$view = isset( $user['view'] ) && $user['view'] === 'drafts' ? 'drafts' : 'usage';
$canSeeAll = expAiServerFunctions::hasPolicy( 'log' );
$limit = 25;

// the filters, cleaned: a value that does not fit is dropped, never echoed
$clean = function ( array $in ) use ( $canSeeAll )
{
    $value = function ( $name ) use ( $in ) { return isset( $in[$name] ) && is_scalar( $in[$name] ) ? trim( (string) $in[$name] ) : ''; };
    $day = function ( $v ) { return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) && strtotime( $v ) ? $v : ''; };
    $id = function ( $v ) { return ctype_digit( $v ) && (int) $v > 0 ? (string) (int) $v : ''; };
    return array( 'from' => $day( $value( 'from' ) ), 'to' => $day( $value( 'to' ) ), 'user' => $canSeeAll ? $id( $value( 'user' ) ) : '',
                  'node' => $id( $value( 'node' ) ), 'action' => preg_replace( '/[^a-z_:]/', '', $value( 'action' ) ),
                  'model' => preg_replace( '/[^A-Za-z0-9._:\/-]/', '', $value( 'model' ) ),
                  'provider' => preg_replace( '/[^a-z0-9_-]/', '', $value( 'provider' ) ),
                  'region' => in_array( $value( 'region' ), array( 'us', 'eu', 'local' ), true ) ? $value( 'region' ) : '',
                  'errors' => $value( 'errors' ) === '1' ? '1' : '',
                  // drill-downs from links (the dashboard's Models card, the test prompt, Watch this run): one request, one thread
                  'request' => $id( $value( 'request' ) ), 'thread' => $id( $value( 'thread' ) ),
                  'unpriced' => $value( 'unpriced' ) === '1' ? '1' : '' );
};
// the view parameters of a set of filters: /(from)/2026-10-01/(errors)/1 ...
$path = function ( array $query )
{
    $out = '';
    foreach ( $query as $name => $value )
        if ( $value !== '' )
            $out .= '/(' . $name . ')/' . str_replace( '%3A', ':', rawurlencode( $value ) );
    return $out;
};

// the filter form posts; its values become view parameters (an address to keep, share and go back to)
if ( $http->hasPostVariable( 'ExpAiFilterButton' ) )
{
    $posted = array();
    foreach ( array( 'from', 'to', 'user', 'node', 'action', 'model', 'provider', 'region', 'errors' ) as $name )
        $posted[$name] = $http->hasPostVariable( 'ExpAiFilter_' . $name ) ? $http->postVariable( 'ExpAiFilter_' . $name ) : '';
    return $Module->redirectTo( '/expai/log' . $path( $clean( $posted ) ) );
}

$query = $clean( $user );
$filters = array(
    'from' => $query['from'] !== '' ? strtotime( $query['from'] . ' 00:00:00' ) : 0,
    'to' => $query['to'] !== '' ? strtotime( $query['to'] . ' 23:59:59' ) : 0,
    'user_id' => $canSeeAll ? (int) $query['user'] : (int) eZUser::currentUserID(),
    'node_id' => (int) $query['node'],
    'action' => $query['action'],
    'model' => $query['model'],
    'provider' => $query['provider'],
    'region' => $query['region'],
    'errors' => $query['errors'] === '1',
    'id' => (int) $query['request'],
    'thread_id' => (int) $query['thread'],
    'unpriced' => $query['unpriced'] === '1',
);
// (model)/<x>: an alias of the registry is the model alias of the requests, anything else the model id
if ( $filters['model'] !== '' && expAiConfig::instance()->registry()->getModel( $filters['model'] ) )
{
    $filters['model_alias'] = $filters['model'];
    $filters['model'] = '';
}
$sort = isset( $user['sort'] ) && in_array( $user['sort'], array( 'created', 'latency_ms', 'input_tokens', 'output_tokens' ), true ) ? $user['sort'] : 'created';
$dir = isset( $user['dir'] ) && $user['dir'] === 'asc' ? 'asc' : 'desc';
$filterPath = $path( $query );
$sortPath = $sort !== 'created' || $dir !== 'desc' ? '/(sort)/' . $sort . '/(dir)/' . $dir : '';

// CSV of the filtered requests: no request texts, user ids only
if ( isset( $user['export'] ) && $user['export'] === 'csv' )
{
    $rows = expAiUsage::fetchPage( $filters, $sort, $dir, 0, expAiUsage::AGGREGATE_LIMIT );
    $out = fopen( 'php://temp', 'w+' );
    fputcsv( $out, array( 'id', 'time', 'user_id', 'node_id', 'conversation_id', 'turn', 'action', 'model', 'input_tokens', 'output_tokens',
                          'cache_write_tokens', 'cache_read_tokens', 'stop_reason', 'http_status', 'latency_ms', 'iteration', 'tool_count',
                          'tool_ms', 'tools', 'retries', 'error_class', 'error_message', 'drafts_written', 'cost_' . expAiUsage::currency(),
                          'provider', 'model_alias', 'region', 'retention', 'reasoning_tokens', 'cost_source', 'tool_mode', 'mcp_calls' ), ',', '"', '' );
    foreach ( $rows as $row )
    {
        $cost = $row->cost();
        fputcsv( $out, expAiUsage::csvRow( array( $row->attribute( 'id' ), date( 'c', (int) $row->attribute( 'created' ) ), $row->attribute( 'user_id' ),
                              $row->attribute( 'node_id' ), $row->attribute( 'conversation_id' ), $row->attribute( 'turn' ), $row->attribute( 'action' ),
                              $row->attribute( 'model' ), $row->attribute( 'input_tokens' ), $row->attribute( 'output_tokens' ),
                              $row->attribute( 'cache_write_tokens' ), $row->attribute( 'cache_read_tokens' ), $row->attribute( 'stop_reason' ),
                              $row->attribute( 'http_status' ), $row->attribute( 'latency_ms' ), $row->attribute( 'iteration' ),
                              $row->attribute( 'tool_count' ), $row->attribute( 'tool_ms' ),
                              implode( ' ', array_map( function ( $t ) { return $t['name']; }, $row->tools() ) ), $row->attribute( 'retries' ),
                              $row->attribute( 'error_class' ), $row->attribute( 'error_message' ), $row->attribute( 'drafts_written' ),
                              $cost === null ? '' : number_format( $cost, 6, '.', '' ),
                              $row->attribute( 'provider' ), $row->attribute( 'model_alias' ), $row->attribute( 'region' ), $row->attribute( 'retention' ),
                              $row->attribute( 'reasoning_tokens' ), $row->costSource(), $row->attribute( 'tool_mode' ), $row->attribute( 'mcp_calls' ) ) ), ',', '"', '' );
    }
    rewind( $out );
    $csv = stream_get_contents( $out );
    fclose( $out );
    // sent after everything that may fail: on Velocity cleanExit() ends the request by throwing, nothing catches it here
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="expai-usage-' . date( 'Ymd-His' ) . '.csv"' );
    header( 'X-Content-Type-Options: nosniff' );
    echo $csv;
    eZDB::checkTransactionCounter();
    eZExecution::cleanExit();
}

$tpl = eZTemplate::factory();
$tpl->setVariable( 'view', $view );
$tpl->setVariable( 'offset', $offset );
$tpl->setVariable( 'limit', $limit );
$tpl->setVariable( 'can_see_all', $canSeeAll );
$tpl->setVariable( 'query', $query );
$tpl->setVariable( 'filter_path', $filterPath );
$tpl->setVariable( 'sort_path', $sortPath );
$tpl->setVariable( 'sort', $sort );
$tpl->setVariable( 'dir', $dir );
$tpl->setVariable( 'currency', expAiUsage::currency() );
$tpl->setVariable( 'cost_enabled', expAiCost::enabled() );

if ( $view === 'drafts' )
{
    $userID = $canSeeAll && $query['user'] !== '' ? (int) $query['user'] : ( $canSeeAll ? false : eZUser::currentUserID() );
    $rows = array();
    // one query for whether the objects of all rows still exist; a removed one is listed as removed, without links
    foreach ( expAiDraftLog::markObjectsThatExist( expAiDraftLog::fetchList( $userID, $offset, $limit ) ) as $log )
    {
        $object = $log->contentObject();
        $rows[] = array(
            'log' => $log,
            'status' => $object || (int) $log->attribute( 'contentobject_id' ) <= 0 ? (string) $log->attribute( 'status' ) : expAiDraftLog::STATUS_DELETED,
            'name' => $object ? ( (string) $object->versionLanguageName( (int) $log->attribute( 'version' ), $log->attribute( 'language_code' ) ) ?: (string) $object->attribute( 'name' ) ) : '',
            'exists' => (bool) $object,
            'can_read' => $object ? (bool) $object->canRead() : false,
            // the version view only while the logged version is still there (a discarded draft leaves the row)
            'version_exists' => $object ? (bool) $object->version( (int) $log->attribute( 'version' ) ) : false,
            'links' => expAiLinks::build( (int) $log->attribute( 'contentobject_id' ), (int) $log->attribute( 'version' ), (string) $log->attribute( 'language_code' ),
                                          (int) $log->attribute( 'node_id' ), (string) $log->attribute( 'prompt_summary' ) !== '' ? (int) $log->attribute( 'id' ) : 0,
                                          $log->objectExists() ),
        );
    }
    $tpl->setVariable( 'rows', $rows );
    $tpl->setVariable( 'total', expAiDraftLog::fetchListCount( $userID ) );
}
else
{
    $turns = expAiUsage::turns( $filters, $offset, $limit, $sort, $dir );
    foreach ( $turns['turns'] as $i => $turn )
    {
        // a turn has a node, not one object: no Edit icon
        $turns['turns'][$i]['links'] = expAiLinks::build( 0, 0, '', $turn['node_id'] );
        $turns['turns'][$i]['links']['edit'] = '';
    }
    $tpl->setVariable( 'turns', $turns['turns'] );
    $tpl->setVariable( 'total', $turns['count'] );
    $tpl->setVariable( 'stats', expAiUsage::aggregate( $filters ) );
    $tpl->setVariable( 'actions', array( 'panel', 'fill', 'field:write', 'field:rewrite', 'field:shorten', 'field:translate', 'field:suggest', 'probe', 'test' ) );
    $tpl->setVariable( 'providers', array_keys( expAiConfig::instance()->registry()->providers() ) );
}

$Result = array();
$Result['content'] = $tpl->fetch( 'design:expai/log.tpl' );
$Result['path'] = array( array( 'url' => 'expai/dashboard', 'text' => ezpI18n::tr( 'extension/expai/log', 'Exponential Intelligence' ) ),
                         array( 'url' => false, 'text' => ezpI18n::tr( 'extension/expai/log', 'Log' ) ) );
