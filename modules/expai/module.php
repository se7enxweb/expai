<?php
/**
 * The module "expai" (Exponential Intelligence).
 *
 * Policies:
 *   expai/write  the assistant: the full view panel, the edit form controls (ezjscore functions expai::*)
 *   expai/log    the log page with the drafts of every user (with expai/write a user sees their own)
 *   expai/settings  shows administrators where the site default model is set (the dashboard); "Resume held jobs"
 *                   on expai/budgets
 *   expai/lane_fast       queued requests may ask for a fast lane (a price premium)
 *   expai/model_use     choosing models of providers outside [ProviderSettings] ProvidersWithoutPolicy[]
 *                       (limitation Provider)
 *   expai/model_manage  the view expai/models: providers, key presence, probe results, the local model (start,
 *                       stop, pull, remove, test prompt, load model)
 *   expai/mcp           MCP tools in the panel (limitation Server: "exp" for the in-process bridge, or a server id)
 *   expai/vector        the Vector workspace and its functions (expaivector::*), with expai/write; limitations
 *                       Subtree, Section (the scope must lie inside), Scope (node, subtree, class), Review (staged, direct)
 *   expai/translation_sync  Check, Approve and Skip of translation updates (the Translations section of the tab)
 *   expai/summary       the view expai/summary: the printable "EI: Summary" page (providers, regions, key sources, roles,
 *                       limits, tools); grant it to a data protection officer
 *
 * Views:
 *   expai/log        the requests to the model and their usage; (view)/drafts the versions written; (export)/csv
 *   expai/budgets    the monthly budget of queued requests, job groups, held jobs, lanes, provider batches (expai/log)
 *   expai/dashboard  the Dashboard tab page
 *   expai/models     providers, models, probe results and the local model (expai/model_manage)
 *   expai/chain      AI chains (expai/chain, limitation Chain): the runs, (run)/<id> one run and its checkpoint,
 *                    (offset)/<n>; POST starts runs and approves, asks for changes, stops or continues them
 *   expai/vector     the Vector workspace: threads, conversation and steps, plan/diff/cost (doc/vector.md);
 *                    (thread)/<uuid>, (node)/<node id>, (tab)/plan|diff|cost, (shelf)/settled|archived
 *   expai/summary    the "EI: Summary" page for a data protection officer, printable (expai/summary)
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$Module = array( 'name' => 'Exponential Intelligence', 'variable_params' => false );

$ViewList = array();
$ViewList['log'] = array(
    'default_navigation_part' => 'ezmynavigationpart',
    'script' => 'log.php',
    'functions' => array( 'write' ),
    'params' => array(),
    'unordered_params' => array( 'offset' => 'Offset', 'view' => 'View', 'export' => 'Export', 'from' => 'From', 'to' => 'To',
                                 'user' => 'User', 'node' => 'Node', 'action' => 'Action', 'model' => 'Model', 'errors' => 'Errors',
                                 'sort' => 'Sort', 'dir' => 'Dir', 'provider' => 'Provider', 'region' => 'Region', 'request' => 'Request', 'thread' => 'Thread',
                                 'unpriced' => 'Unpriced' ) );

// lanes and budgets (classes/lanes/, classes/budget/)
$ViewList['budgets'] = array(
    'default_navigation_part' => 'ezsetupnavigationpart',
    'script' => 'budgets.php',
    'functions' => array( 'log' ),
    'params' => array(),
    'single_post_actions' => array( 'ExpAiResumeHeldButton' => 'ResumeHeld' ),
    'unordered_params' => array() );

$ViewList['dashboard'] = array(
    'default_navigation_part' => 'ezmynavigationpart',
    'script' => 'dashboard.php',
    'functions' => array( 'write' ),
    'params' => array(),
    'single_post_actions' => array( 'StartWritingButton' => 'StartWriting', 'ChangeLocationButton' => 'ChangeLocation' ),
    'post_action_parameters' => array( 'StartWriting' => array( 'Prompt' => 'ExpAiPrompt', 'NodeID' => 'ExpAiNodeID' ),
                                      'ChangeLocation' => array( 'Prompt' => 'ExpAiPrompt' ) ),
    'post_actions' => array( 'BrowseActionName' ),
    'unordered_params' => array( 'offset' => 'Offset', 'node' => 'Node', 'period' => 'Period', 'scope' => 'Scope', 'chart' => 'Chart',
                                 'again' => 'Again' ) );

$ViewList['models'] = array(
    'default_navigation_part' => 'ezsetupnavigationpart',
    'script' => 'models.php',
    'functions' => array( 'model_manage' ),
    'params' => array(),
    'single_post_actions' => array( 'ExpAiLocalActionButton' => 'LocalAction', 'ExpAiTestPromptButton' => 'TestPrompt', 'ExpAiLoadModelButton' => 'LoadModel' ),
    'post_action_parameters' => array( 'LocalAction' => array( 'Action' => 'ExpAiLocalAction', 'Model' => 'ExpAiLocalModel' ),
                                      'TestPrompt' => array( 'Model' => 'ExpAiTestModel' ), 'LoadModel' => array( 'Model' => 'ExpAiTestModel' ) ),
    'unordered_params' => array( 'provider' => 'Provider' ) );

// AI chains (classes/chain/, doc/chains.md): the runs, one run with its checkpoint, starting runs
$ViewList['chain'] = array(
    'default_navigation_part' => 'ezmynavigationpart',
    'script' => 'chain.php',
    'functions' => array( 'chain' ),
    'params' => array(),
    'single_post_actions' => array( 'ExpAiChainStartButton' => 'StartChain', 'ExpAiChainApproveButton' => 'Approve',
                                    'ExpAiChainChangesButton' => 'RequestChanges', 'ExpAiChainStopButton' => 'StopRun',
                                    'ExpAiChainResumeButton' => 'ResumeRun' ),
    'unordered_params' => array( 'run' => 'Run', 'offset' => 'Offset' ) );

// Vector (classes/vector/, doc/vector.md): the shell only; threads and steps come through expaivector::*
$ViewList['vector'] = array(
    'default_navigation_part' => 'ezmynavigationpart',
    'script' => 'vector.php',
    'functions' => array( 'vector' ),
    'params' => array(),
    'unordered_params' => array( 'thread' => 'Thread', 'node' => 'Node', 'tab' => 'Tab', 'shelf' => 'Shelf' ) );

// the event stream of a thread (Server-Sent Events where [VectorSettings] Transport resolves to sse; else 204)
$ViewList['stream'] = array(
    'default_navigation_part' => 'ezmynavigationpart',
    'script' => 'stream.php',
    'functions' => array( 'vector' ),
    'params' => array(),
    'unordered_params' => array( 'thread' => 'Thread', 'after' => 'After' ) );

// the "EI: Summary" page (classes/expaisummary.php, doc/summary.md): one printable page for a data protection officer
$ViewList['summary'] = array(
    'default_navigation_part' => 'ezsetupnavigationpart',
    'script' => 'summary.php',
    'functions' => array( 'summary' ),
    'params' => array(),
    'unordered_params' => array() );

$Provider = array( 'name' => 'Provider', 'values' => array(), 'class' => 'expAiPolicy', 'function' => 'providers', 'parameter' => array() );
$Server = array( 'name' => 'Server', 'values' => array(), 'class' => 'expAiPolicy', 'function' => 'mcpServers', 'parameter' => array() );

$FunctionList = array();
$FunctionList['write'] = array();
$FunctionList['log'] = array();
$FunctionList['settings'] = array();
$FunctionList['model_use'] = array( 'Provider' => $Provider );
$FunctionList['model_manage'] = array();
$FunctionList['lane_fast'] = array();
$FunctionList['mcp'] = array( 'Server' => $Server );
// AI chains: start runs and act at their checkpoints (limitation Chain: the chains of [ChainSettings] Chains[])
$FunctionList['chain'] = array(
    'Chain' => array( 'name' => 'Chain', 'values' => array(), 'class' => 'expAiChainPolicy', 'function' => 'chains', 'parameter' => array() ) );

// Vector: Subtree and Section are the kernel's limitation kinds (the role editor browses for subtrees)
$FunctionList['vector'] = array(
    'Subtree' => array( 'name' => 'Subtree', 'values' => array() ),
    'Section' => array( 'name' => 'Section', 'values' => array(), 'class' => 'eZSection', 'function' => 'fetchList', 'parameter' => array( false ) ),
    'Scope' => array( 'name' => 'Scope', 'values' => array(), 'class' => 'expAiVectorPolicy', 'function' => 'scopes', 'parameter' => array() ),
    'Review' => array( 'name' => 'Review', 'values' => array(), 'class' => 'expAiVectorPolicy', 'function' => 'reviews', 'parameter' => array() ) );

// Web and history tools (#369): web_fetch, web_search, project_history; without the limitation Tool every one of them
$FunctionList['web'] = array(
    'Tool' => array( 'name' => 'Tool', 'values' => array(), 'class' => 'expAiWebPolicy', 'function' => 'tools', 'parameter' => array() ) );

// Translation sync (classes/diff/, doc/translation-sync.md): Check, Approve and Skip in the Translations section of the
// Intelligence tab (expaitranslation::check|approve|skip), with expai/write; the drafts need content/edit in the language
$FunctionList['translation_sync'] = array();

// Service tools (#370 M1, doc/service-tools.md): the read services of expservices the assistant may call, by domain
// (limitation Domain: the domains of the expservices catalogue); without the limitation every opted-in service
$FunctionList['services'] = array(
    'Domain' => array( 'name' => 'Domain', 'values' => array(), 'class' => 'expAiPolicy', 'function' => 'serviceDomains', 'parameter' => array() ) );

// the "EI: Summary" page expai/summary (read-only: providers, regions, key sources, roles, limits, tools)
$FunctionList['summary'] = array();

// Alt text for images (#370 M5 alt text, doc/alt-text.md): the "Missing alt text" report of a subtree, and queueing
// suggestions as drafts; (node)/<node id>
$ViewList['alttext'] = array(
    'default_navigation_part' => 'ezvisualnavigationpart',
    'script' => 'alttext.php',
    'functions' => array( 'write' ),
    'params' => array(),
    'unordered_params' => array( 'node' => 'Node' ) );

?>
