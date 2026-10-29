<?php
/**
 * expai/budgets (policy expai/log): queued requests and their money. The monthly site budget (spent, reserved by
 * queued requests, left), the job groups with their budgets, the jobs held by a budget (and, with expai/settings,
 * "Resume held jobs"), the lanes of every model (price factor, latency, retention, enabled) and the recent provider
 * batches.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** @var eZModule $Module */
$Module = $Params['Module'];
$config = expAiConfig::instance();
$budget = new expAiBudget( $config );
$notice = '';
$canManage = eZUser::currentUser()->hasAccessTo( 'expai', 'settings' )['accessWord'] !== 'no';

if ( $Module->isCurrentAction( 'ResumeHeld' ) && $canManage && expAiQueue::available() )
{
    $r = $budget->resumeHeld();
    if ( class_exists( 'expAiAudit' ) )
        expAiAudit::budgetResume( $r );
    $notice = ezpI18n::tr( 'extension/expai/budget', '%resumed held jobs resumed, %held still held by a budget.', null,
                           array( '%resumed' => $r['resumed'], '%held' => $r['still_held'] ) );
}

$lanes = array();
foreach ( $config->registry()->models() as $alias => $model )
{
    $rows = array();
    foreach ( expAiLane::forModel( $alias, $config ) as $lane )
        $rows[] = array( 'name' => $lane->name(), 'type' => $lane->get( 'type' ), 'usable' => $lane->usable(), 'enabled' => $lane->get( 'enabled' ),
                         'factor' => $lane->get( 'multiplier' ), 'latency' => $lane->get( 'expected_latency' ), 'retention' => $lane->retention(),
                         'verified' => $lane->get( 'verified' ), 'key' => $lane->key() );
    $lanes[] = array( 'alias' => $alias, 'label' => $model['label'], 'provider' => $model['provider'], 'lanes' => $rows );
}
$batches = array();
if ( expAiQueue::available() )
    foreach ( expAiBatch::fetchRecent( 15 ) as $batch )
        $batches[] = $batch;

$tpl = eZTemplate::factory();
$tpl->setVariable( 'status', $budget->status( 15 ) );
$tpl->setVariable( 'lanes', $lanes );
$tpl->setVariable( 'batches', $batches );
$tpl->setVariable( 'held_jobs', expAiQueue::jobs( array( 'state' => 'held' ), 20 ) );
$tpl->setVariable( 'can_manage', $canManage );
$tpl->setVariable( 'notice', $notice );
$tpl->setVariable( 'queue', expAiQueue::available() );
$tpl->setVariable( 'reserve_factor', $budget->reserveFactor() );

$Result = array();
$Result['content'] = $tpl->fetch( 'design:expai/budgets.tpl' );
$Result['path'] = array( array( 'url' => 'expai/dashboard', 'text' => ezpI18n::tr( 'extension/expai/budget', 'Exponential Intelligence' ) ),
                         array( 'url' => false, 'text' => ezpI18n::tr( 'extension/expai/budget', 'EI: Budgets' ) ) );
