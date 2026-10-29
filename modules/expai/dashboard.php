<?php
/**
 * expai/dashboard: the Exponential Intelligence page of the Dashboard tab (navigation part ezmynavigationpart, menu
 * entry in menu.ini [Leftmenu_my]): the user's drafts, the AI-written versions waiting for their approval, "Start
 * writing" (pick a location with the content browser, then the panel opens there with the request) and the status.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** @var eZModule $Module */
$Module = $Params['Module'];
$http = eZHTTPTool::instance();
$error = '';
$max = expAiConfig::instance()->int( 'AssistantSettings', 'MaxMessageLength', 4000, 10 );

// the master switch (#370 M2): while expai is off, "Start writing" opens nothing (the status says "Switched off")
if ( !expAiConfig::instance()->isEnabled() && ( $Module->isCurrentAction( 'StartWriting' ) || $Module->isCurrentAction( 'ChangeLocation' )
                                                 || $Module->isCurrentAction( 'ExpAiStartWriting' ) ) )
{
    $error = ezpI18n::tr( 'extension/expai/error', 'Exponential Intelligence is switched off.' );
    $Module->setCurrentAction( 'ExpAiSwitchedOff' );
}

// "Start writing" at the default location (or the one chosen before): open the panel there with the request
if ( $Module->isCurrentAction( 'StartWriting' ) )
{
    $text = mb_substr( trim( (string) $Module->actionParameter( 'Prompt' ) ), 0, $max, 'UTF-8' );
    $nodeID = (int) $Module->actionParameter( 'NodeID' );
    $node = $nodeID ? eZContentObjectTreeNode::fetch( $nodeID ) : null;
    if ( $node && $node->canRead() )
    {
        $http->setSessionVariable( 'ExpAiPrefill', array( 'node_id' => $nodeID, 'text' => $text ) );
        // "Watch this run": the dashboard follows the request sent from there (expAiRunWatch)
        if ( class_exists( 'expAiRunWatch' ) )
            expAiRunWatch::remember( $nodeID );
        return $Module->redirectTo( '/content/view/full/' . $nodeID . '#exp-ai' );
    }
    $error = ezpI18n::tr( 'extension/expai/dashboard', 'The chosen location cannot be read.' );
}
// "Change location": keep the request, choose the location with the content browser
if ( $Module->isCurrentAction( 'ChangeLocation' ) )
{
    $text = mb_substr( trim( (string) $Module->actionParameter( 'Prompt' ) ), 0, $max, 'UTF-8' );
    $http->setSessionVariable( 'ExpAiPrefill', array( 'node_id' => 0, 'text' => $text ) );
    eZContentBrowse::browse( array( 'action_name' => 'ExpAiStartWriting',
                                    'description_template' => 'design:expai/browse_start_writing.tpl',
                                    'from_page' => 'expai/dashboard' ), $Module );
    return;
}
// the content browser came back with the chosen node: open the panel there
if ( $http->hasPostVariable( 'BrowseCancelButton' ) && $http->hasPostVariable( 'BrowseCancelURI' ) )
    return $Module->redirectTo( '/expai/dashboard' );
if ( $Module->isCurrentAction( 'ExpAiStartWriting' ) )
{
    $selected = (array) eZContentBrowse::result( 'ExpAiStartWriting' );
    $nodeID = (int) reset( $selected );
    $node = $nodeID ? eZContentObjectTreeNode::fetch( $nodeID ) : null;
    if ( $node && $node->canRead() )
    {
        $pending = $http->hasSessionVariable( 'ExpAiPrefill' ) ? (array) $http->sessionVariable( 'ExpAiPrefill' ) : array();
        $http->setSessionVariable( 'ExpAiPrefill', array( 'node_id' => $nodeID, 'text' => isset( $pending['text'] ) ? (string) $pending['text'] : '' ) );
        if ( class_exists( 'expAiRunWatch' ) )
            expAiRunWatch::remember( $nodeID );
        return $Module->redirectTo( '/content/view/full/' . $nodeID . '#exp-ai' );
    }
    $error = ezpI18n::tr( 'extension/expai/dashboard', 'The chosen location cannot be read.' );
}

// the period, the scope (me | site, with expai/log) and the chart (tokens | cost) are view parameters:
// expai/dashboard/(period)/30d/(scope)/site/(chart)/cost; a choice in the address is kept as the user's preference
$viewParameters = isset( $Params['UserParameters'] ) && is_array( $Params['UserParameters'] ) ? $Params['UserParameters'] : array();
$pick = function ( $name, $allowed, $preference, $default ) use ( $viewParameters )
{
    $value = isset( $viewParameters[$name] ) && is_scalar( $viewParameters[$name] ) ? (string) $viewParameters[$name] : '';
    if ( in_array( $value, $allowed, true ) )
    {
        eZPreferences::setValue( $preference, $value );
        return $value;
    }
    $stored = eZPreferences::value( $preference );
    return in_array( $stored, $allowed, true ) ? $stored : $default;
};
$canSeeSite = expAiServerFunctions::hasPolicy( 'log' );
$period = $pick( 'period', array( 'today', '7d', '30d', 'month' ), 'expai_period', '7d' );
$scope = $canSeeSite ? $pick( 'scope', array( 'me', 'site' ), 'expai_scope', 'me' ) : 'me';
$chart = $pick( 'chart', array( 'tokens', 'cost' ), 'expai_chart', 'tokens' );
$userID = (int) eZUser::currentUserID();
$summaryUser = $scope === 'site' ? false : $userID;
$summary = expAiUsage::periodSummary( $summaryUser, $period );
$chartDays = array( 'today' => 7, '7d' => 7, '30d' => 30, 'month' => max( 7, (int) date( 'j' ) ) );
$config = expAiConfig::instance();
$models = $config->allowedModels();
$providerFilters = array( 'from' => $summary['range']['from'], 'to' => $summary['range']['to'] );
if ( $summaryUser !== false )
    $providerFilters['user_id'] = (int) $summaryUser;
$perProvider = expAiUsage::aggregate( $providerFilters );

$tpl = eZTemplate::factory();
$tpl->setVariable( 'error', $error );
$tpl->setVariable( 'period', $period );
$tpl->setVariable( 'scope', $scope );
$tpl->setVariable( 'can_see_site', $canSeeSite );
$tpl->setVariable( 'summary', $summary );
$tpl->setVariable( 'series', expAiUsage::dailySeries( $summaryUser, $chartDays[$period], $chart ) );
$tpl->setVariable( 'cost_enabled', expAiCost::enabled() );
$tpl->setVariable( 'pricing', expAiUsage::pricingInfo() );
// the Anthropic-specific notes (invoice, Console spend limit) only where an Anthropic provider is set up
$anthropicInUse = false;
foreach ( $config->registry()->providers() as $providerSettings )
    if ( isset( $providerSettings['Type'] ) && $providerSettings['Type'] === 'anthropic' )
        $anthropicInUse = true;
$tpl->setVariable( 'anthropic_in_use', $anthropicInUse );
$tpl->setVariable( 'status', expAiDashboard::status() );
$tpl->setVariable( 'model_label', isset( $models[$config->effectiveModel()] ) ? $models[$config->effectiveModel()] : $config->effectiveModel() );
// expai/dashboard/(node)/<id> ("Prompt again") preselects that location; /(again)/<draft log id> fills the request
// with the logged summary of the user's own earlier request (only with [UsageSettings] LogRequests=enabled)
$tpl->setVariable( 'start', expAiDashboard::defaultLocation( isset( $viewParameters['node'] ) ? (int) $viewParameters['node'] : 0 ) );
$prompt = '';
if ( isset( $viewParameters['again'] ) && (int) $viewParameters['again'] > 0 && $config->flag( 'UsageSettings', 'LogRequests', false ) )
{
    $again = eZPersistentObject::fetchObject( expAiDraftLog::definition(), null, array( 'id' => (int) $viewParameters['again'] ) );
    if ( $again instanceof expAiDraftLog && (int) $again->attribute( 'user_id' ) === $userID )
        $prompt = mb_substr( (string) $again->attribute( 'prompt_summary' ), 0, $max, 'UTF-8' );
}
$tpl->setVariable( 'prompt', $prompt );
$tpl->setVariable( 'limits', array_values( array_filter( expAiLimits::status(), function ( $l ) use ( $canSeeSite )
{
    return $l['max'] > 0 && ( $l['scope'] === 'user' || $canSeeSite );
} ) ) );
$tpl->setVariable( 'rate_limits', expAiLimits::latestRateLimits() );
$tpl->setVariable( 'per_provider', $perProvider['per_provider'] );
$monthStats = $canSeeSite && $scope === 'site' ? expAiUsage::aggregate( array( 'from' => expAiLimits::periodStart( 'month' ) ) ) : false;
$tpl->setVariable( 'top_users', $monthStats ? array_slice( $monthStats['per_user'], 0, 5 ) : array() );
$tpl->setVariable( 'drafts', expAiDashboard::myDrafts( 0, 5 ) );
$tpl->setVariable( 'draft_count', expAiDashboard::myDraftCount() );
$tpl->setVariable( 'waiting', expAiDashboard::waitingForMyApproval() );
// the Models card (the same period and scope) and "Watch this run" of "Start writing"
// (class_exists: a Velocity worker started before an update knows only the classes of its warm-up)
$tpl->setVariable( 'model_stats', class_exists( 'expAiModelStats' ) ? expAiModelStats::forPeriod( $summaryUser, $period, $config ) : array( 'models' => array() ) );
$tpl->setVariable( 'watch', class_exists( 'expAiRunWatch' ) ? expAiRunWatch::describe( $userID ) : null );
// "Details in the log": the same period (and user) as view parameters of expai/log
$tpl->setVariable( 'details_path', '/(from)/' . date( 'Y-m-d', $summary['range']['from'] ) . '/(to)/' . date( 'Y-m-d', $summary['range']['to'] )
                                   . ( $summaryUser !== false && $canSeeSite ? '/(user)/' . (int) $summaryUser : '' ) );

$Result = array();
$Result['content'] = $tpl->fetch( 'design:expai/dashboard.tpl' );
$Result['path'] = array( array( 'url' => 'content/dashboard', 'text' => ezpI18n::tr( 'extension/expai/dashboard', 'Dashboard' ) ),
                         array( 'url' => false, 'text' => ezpI18n::tr( 'extension/expai/dashboard', 'Exponential Intelligence' ) ) );
