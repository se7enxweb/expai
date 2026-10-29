<?php
/**
 * expai/vector: the Vector workspace (doc/vector.md). The view draws the shell only; the threads, the conversation
 * and every action come through the ezjscore functions expaivector::* (classes/vector/expaivectorserverfunctions.php),
 * so nothing personal is part of the page and switching threads does not reload it.
 *
 * View parameters (no GET parameters):
 *   expai/vector                      the newest active thread, or none
 *   expai/vector/(thread)/<uuid>      that thread
 *   expai/vector/(node)/<node id>     a new thread with that node as its scope ("New thread here")
 *   expai/vector/(tab)/plan|diff|cost the right pane's tab
 *   expai/vector/(shelf)/settled|archived  the list of settled or archived threads
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** @var eZModule $Module */
$Module = $Params['Module'];
$config = expAiConfig::instance();
$viewParameters = isset( $Params['UserParameters'] ) && is_array( $Params['UserParameters'] ) ? $Params['UserParameters'] : array();
$param = function ( $name ) use ( $viewParameters )
{
    return isset( $viewParameters[$name] ) && is_scalar( $viewParameters[$name] ) ? (string) $viewParameters[$name] : '';
};

$thread = $param( 'thread' );
// #389 the address of a thread that was deleted (or that the user may not open: the same answer, so another
// editor's thread ids reveal nothing) is a 404 with a way back to Vector, not the workspace with an error in it
if ( $thread !== '' && expAiThread::available() )
{
    $found = expAiThread::fetchByUuid( $thread );
    if ( !$found || !expAiThreadAccess::canRead( $found ) )
    {
        $status = '404 Not Found';
        header( eZSys::serverVariable( 'SERVER_PROTOCOL' ) . ' ' . $status );
        header( 'Status: ' . $status );
        $tpl = eZTemplate::factory();
        $Result = array();
        $Result['content'] = $tpl->fetch( 'design:expai/vector_gone.tpl' );
        $Result['path'] = array( array( 'url' => 'content/dashboard', 'text' => ezpI18n::tr( 'extension/expai/dashboard', 'Dashboard' ) ),
                                 array( 'url' => 'expai/dashboard', 'text' => ezpI18n::tr( 'extension/expai/dashboard', 'Exponential Intelligence' ) ),
                                 array( 'url' => 'expai/vector', 'text' => ezpI18n::tr( 'extension/expai/vector', 'Vector' ) ),
                                 array( 'url' => false, 'text' => ezpI18n::tr( 'extension/expai/vector', 'Thread not found' ) ) );
        return $Result;
    }
}
$thread = expAiThread::isUuid( $thread ) ? $thread : '';
$nodeID = (int) $param( 'node' );
$node = $nodeID ? eZContentObjectTreeNode::fetch( $nodeID ) : null;
if ( !$node instanceof eZContentObjectTreeNode || !$node->canRead() )
{
    $node = null;
    $nodeID = 0;
}
$tab = in_array( $param( 'tab' ), array( 'plan', 'diff', 'cost' ), true ) ? $param( 'tab' ) : '';
$shelf = in_array( $param( 'shelf' ), array( 'settled', 'archived' ), true ) ? $param( 'shelf' ) : '';

$panes = array();
foreach ( $config->arr( 'VectorSettings', 'Panes' ) as $pane )
{
    $template = (string) $config->get( 'VectorPane_' . $pane, 'Template', '' );
    if ( preg_match( '/^[a-z0-9_]+$/', $pane ) && $template !== '' )
        $panes[$pane] = $template;
}

$tpl = eZTemplate::factory();
$tpl->setVariable( 'vector_enabled', $config->flag( 'VectorSettings', 'Enabled', true ) );
$tpl->setVariable( 'ai_ready', $config->isEnabled() && $config->isConfigured() );
$tpl->setVariable( 'tables_ready', expAiThread::available() );
$tpl->setVariable( 'thread_uuid', $thread );
$tpl->setVariable( 'start_node', $node );
$tpl->setVariable( 'tab', $tab );
$tpl->setVariable( 'shelf', $shelf );
$tpl->setVariable( 'panes', $panes );
$tpl->setVariable( 'cost_enabled', expAiCost::enabled() );

$Result = array();
$Result['content'] = $tpl->fetch( 'design:expai/vector.tpl' );
$Result['path'] = array( array( 'url' => 'content/dashboard', 'text' => ezpI18n::tr( 'extension/expai/dashboard', 'Dashboard' ) ),
                         array( 'url' => 'expai/dashboard', 'text' => ezpI18n::tr( 'extension/expai/dashboard', 'Exponential Intelligence' ) ),
                         array( 'url' => false, 'text' => ezpI18n::tr( 'extension/expai/vector', 'Vector' ) ) );
