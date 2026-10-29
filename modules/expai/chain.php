<?php
/**
 * expai/chain (policy expai/chain): AI chains (doc/chains.md).
 *
 *   expai/chain                      the runs: the user's own (with expai/log: everyone's), newest first
 *   expai/chain/(run)/<id>           one run: steps, scores, costs, the current state, the draft; approve, ask for
 *                                    changes, stop, continue (POST with the form token)
 *   expai/chain/(offset)/<n>         paging of the list
 *
 * Starting runs: POST ExpAiChainStartButton with ExpAiChain, ExpAiNodeID, ExpAiPrompt (the brief), ExpAiCount (items)
 * and ExpAiChainLanguage, from the Intelligence tab (panel section "chains") and the dashboard's "Start writing".
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** @var eZModule $Module */
$Module = $Params['Module'];
$http = eZHTTPTool::instance();
$viewParameters = isset( $Params['UserParameters'] ) && is_array( $Params['UserParameters'] ) ? $Params['UserParameters'] : array();
$engine = new expAiChainEngine();
$error = '';
$notice = '';
$ready = expAiQueue::available() && expAiChainFunctionCollection::installed();
$post = function ( $name, $default = '' ) use ( $http )
{
    return $http->hasPostVariable( $name ) && is_scalar( $http->postVariable( $name ) ) ? (string) $http->postVariable( $name ) : $default;
};
$message = function ( expAiException $e )
{
    if ( $e->detail() !== '' )
        eZDebug::writeWarning( $e->getMessage() . ' (' . $e->detail() . ')', 'expai/chain' );
    return $e->translatedMessage();
};

// ------------------------------------------------------------------ start
if ( $Module->isCurrentAction( 'StartChain' ) )
{
    $nodeID = (int) $post( 'ExpAiNodeID', '0' );
    try
    {
        if ( !$ready )
            throw new expAiException( 'The job queue is not available.', 'expqueue or the chain tables are missing' );
        $runs = $engine->start( $post( 'ExpAiChain' ), $nodeID, $post( 'ExpAiPrompt' ), (int) $post( 'ExpAiCount', '1' ),
                                array( 'language' => $post( 'ExpAiChainLanguage' ) ) );
        // the audit trail (doc/audit-trail.md): which chain, which node, never the prompt
        if ( class_exists( 'expAiAudit' ) )
            expAiAudit::chain( 'start', array_map( function ( $run ) { return (int) $run->attribute( 'id' ); }, $runs ), $nodeID, (string) $post( 'ExpAiChain' ) );
        if ( count( $runs ) === 1 )
            return $Module->redirectTo( '/expai/chain/(run)/' . (int) $runs[0]->attribute( 'id' ) );
        // several runs: the list names them, with their job groups, so the editor can follow each one
        $http->setSessionVariable( 'ExpAiChainStarted', array_map( function ( $run ) { return (int) $run->attribute( 'id' ); }, $runs ) );
        return $Module->redirectTo( '/expai/chain' );
    }
    catch ( expAiException $e )
    {
        // shown on the list (the Intelligence tab is part of the cached full view and shows nothing personal)
        $error = $message( $e );
    }
}

// ------------------------------------------------------------------ actions on a run
$actions = array( 'Approve', 'RequestChanges', 'StopRun', 'ResumeRun' );
foreach ( $actions as $action )
{
    if ( !$Module->isCurrentAction( $action ) )
        continue;
    $run = expAiChainRun::fetch( (int) $post( 'ExpAiRunID', '0' ) );
    if ( !$run || !expAiChainPolicy::mayAct( $run ) )
        return $Module->handleError( eZError::KERNEL_ACCESS_DENIED, 'kernel' );
    try
    {
        if ( $action === 'Approve' )
            $engine->approve( $run, $post( 'ExpAiNote' ) );
        elseif ( $action === 'RequestChanges' )
            $engine->requestChanges( $run, $post( 'ExpAiNote' ) );
        elseif ( $action === 'StopRun' )
            $engine->stop( $run, 'stopped by an editor (user ' . (int) eZUser::currentUserID() . ')' );
        else
            $engine->resume( $run );
        // the audit trail (doc/audit-trail.md): the action on the run, never the note
        if ( class_exists( 'expAiAudit' ) )
            expAiAudit::chain( array( 'Approve' => 'approve', 'RequestChanges' => 'changes', 'StopRun' => 'stop', 'ResumeRun' => 'resume' )[$action],
                               array( (int) $run->attribute( 'id' ) ), (int) $run->attribute( 'node_id' ), (string) $run->attribute( 'chain' ) );
        return $Module->redirectTo( '/expai/chain/(run)/' . (int) $run->attribute( 'id' ) );
    }
    catch ( expAiException $e )
    {
        $error = $message( $e );
        $viewParameters['run'] = (int) $run->attribute( 'id' );
    }
}

$tpl = eZTemplate::factory();
$tpl->setVariable( 'error', $error );
$tpl->setVariable( 'notice', $notice );
$tpl->setVariable( 'ready', $ready );
$canSeeAll = expAiServerFunctions::hasPolicy( 'log' );

// ------------------------------------------------------------------ one run
if ( !empty( $viewParameters['run'] ) && $ready )
{
    $run = expAiChainRun::fetch( (int) $viewParameters['run'] );
    if ( !$run || !expAiChainPolicy::maySee( $run ) )
        return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
    $run = $engine->reconcile( $run );
    $chain = $run->chain();
    $data = $run->dataArray();
    $steps = array();
    foreach ( $run->steps() as $row )
    {
        $def = $chain->step( $row->attribute( 'step' ) );
        $out = $row->outputArray();
        $steps[] = array( 'id' => (int) $row->attribute( 'id' ), 'step' => (string) $row->attribute( 'step' ), 'label' => $def ? $def['label'] : $row->attribute( 'step' ),
                          'type' => (string) $row->attribute( 'step_type' ), 'iteration' => (int) $row->attribute( 'iteration' ), 'state' => (string) $row->attribute( 'state' ),
                          'job_state' => $row->jobState(), 'job_uuid' => (string) $row->attribute( 'job_uuid' ), 'model' => (string) $row->attribute( 'model' ),
                          'lane' => (string) $row->attribute( 'lane' ), 'verdict' => (string) $row->attribute( 'verdict' ), 'score' => (int) $row->attribute( 'score' ),
                          'issue_count' => (int) $row->attribute( 'issue_count' ), 'tokens_in' => (int) $row->attribute( 'input_tokens' ),
                          'tokens_out' => (int) $row->attribute( 'output_tokens' ), 'cost' => $row->costMoney(), 'cost_micros' => (int) $row->attribute( 'cost_micros' ),
                          'note' => (string) $row->attribute( 'note' ), 'summary' => isset( $out['summary'] ) ? (string) $out['summary'] : ( isset( $out['note'] ) ? (string) $out['note'] : '' ),
                          'issues' => isset( $out['issues'] ) ? (array) $out['issues'] : array(), 'checklist' => isset( $out['checklist'] ) ? (array) $out['checklist'] : array(),
                          'created' => (int) $row->attribute( 'created' ), 'finished' => (int) $row->attribute( 'finished' ) );
    }
    $document = array();
    foreach ( isset( $data['document'] ) ? (array) $data['document'] : array() as $identifier => $value )
        $document[] = array( 'identifier' => (string) $identifier, 'html' => expAiText::sanitizeHtml( (string) $value ) );
    $planned = array();
    foreach ( $chain->order() as $name )
    {
        $def = $chain->step( $name );
        $planned[] = array( 'name' => $name, 'label' => $def['label'], 'type' => $def['type'], 'model' => $def['model'],
                            'done' => (bool) array_filter( $steps, function ( $s ) use ( $name ) { return $s['step'] === $name && in_array( $s['state'], array( 'done', 'skipped' ), true ); } ),
                            'current' => $run->attribute( 'current_step' ) === $name && !$run->isFinal() );
    }
    $group = $run->attribute( 'group_uuid' ) !== '' ? expQueue::resolveGroup( (string) $run->attribute( 'group_uuid' ) ) : null;
    // whether the chain's settings changed since the run started (the run keeps its snapshot)
    try
    {
        $changed = expAiChain::load( $chain->id() )->hash() !== $run->attribute( 'chain_hash' );
    }
    catch ( expAiException $e )
    {
        $changed = true;
    }
    $tpl->setVariable( 'run', $run );
    $tpl->setVariable( 'run_row', expAiChainFunctionCollection::row( $run ) );
    $tpl->setVariable( 'chain', array( 'id' => $chain->id(), 'label' => $chain->label(), 'changed' => $changed,
                                       'max_cost' => $chain->maxCostMicros() ? expAiBudget::money( $chain->maxCostMicros() ) : '' ) );
    $tpl->setVariable( 'steps', $steps );
    $tpl->setVariable( 'planned', $planned );
    $tpl->setVariable( 'document', $document );
    $tpl->setVariable( 'review', isset( $data['review'] ) ? (array) $data['review'] : array() );
    $tpl->setVariable( 'seo', isset( $data['seo'] ) ? (array) $data['seo'] : array() );
    $tpl->setVariable( 'links', isset( $data['links'] ) ? (array) $data['links'] : array() );
    $tpl->setVariable( 'flags', isset( $data['flags'] ) ? (array) $data['flags'] : array() );
    $tpl->setVariable( 'note', isset( $data['note'] ) ? (string) $data['note'] : '' );
    $tpl->setVariable( 'translate', isset( $data['translate'] ) ? (array) $data['translate'] : array() );
    $tpl->setVariable( 'can_act', expAiChainPolicy::mayAct( $run ) );
    $tpl->setVariable( 'tokens', (int) $run->attribute( 'input_tokens' ) + (int) $run->attribute( 'output_tokens' ) );
    $tpl->setVariable( 'group', $group ? array( 'uuid' => (string) $group->attribute( 'uuid' ), 'budget' => (int) $group->attribute( 'budget_micros' ) ? expAiBudget::money( (int) $group->attribute( 'budget_micros' ) ) : '',
                                                'spent' => expAiBudget::money( (int) $group->attribute( 'spent_micros' ) ) ) : array() );
    $tpl->setVariable( 'duration', ( $run->attribute( 'finished' ) ? (int) $run->attribute( 'finished' ) : time() ) - (int) $run->attribute( 'created' ) );
    $Result = array();
    $Result['content'] = $tpl->fetch( 'design:expai/chain_run.tpl' );
    $Result['path'] = array( array( 'url' => 'expai/dashboard', 'text' => ezpI18n::tr( 'extension/expai/chain', 'Exponential Intelligence' ) ),
                             array( 'url' => 'expai/chain', 'text' => ezpI18n::tr( 'extension/expai/chain', 'Chains' ) ),
                             array( 'url' => false, 'text' => (string) $run->attribute( 'label' ) ) );
    return $Result;
}

// ------------------------------------------------------------------ the list
$offset = isset( $viewParameters['offset'] ) ? max( 0, (int) $viewParameters['offset'] ) : 0;
$limit = 25;
$conditions = $canSeeAll ? array() : array( 'user_id' => (int) eZUser::currentUserID() );
$rows = array();
$total = 0;
if ( $ready )
{
    foreach ( expAiChainRun::fetchList( $conditions, $offset, $limit ) as $run )
        $rows[] = expAiChainFunctionCollection::row( $engine->reconcile( $run ) );
    $total = expAiChainRun::fetchCount( $conditions );
}
$tpl->setVariable( 'rows', $rows );
$tpl->setVariable( 'total', $total );
$tpl->setVariable( 'offset', $offset );
$tpl->setVariable( 'limit', $limit );
$tpl->setVariable( 'all_users', $canSeeAll );
// the runs started just before (several at once): links to each run and its job group
$started = array();
if ( $http->hasSessionVariable( 'ExpAiChainStarted' ) )
{
    foreach ( array_slice( (array) $http->sessionVariable( 'ExpAiChainStarted' ), 0, 50 ) as $runID )
        if ( $ready && ( $run = expAiChainRun::fetch( (int) $runID ) ) && expAiChainPolicy::maySee( $run ) )
            $started[] = array( 'id' => (int) $run->attribute( 'id' ), 'label' => (string) $run->attribute( 'label' ), 'group' => (string) $run->attribute( 'group_uuid' ) );
    $http->removeSessionVariable( 'ExpAiChainStarted' );
}
$tpl->setVariable( 'started', $started );
$Result = array();
$Result['content'] = $tpl->fetch( 'design:expai/chain.tpl' );
$Result['path'] = array( array( 'url' => 'expai/dashboard', 'text' => ezpI18n::tr( 'extension/expai/chain', 'Exponential Intelligence' ) ),
                         array( 'url' => false, 'text' => ezpI18n::tr( 'extension/expai/chain', 'Chains' ) ) );
