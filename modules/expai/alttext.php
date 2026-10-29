<?php
/**
 * expai/alttext (policy expai/write): the "Missing alt text" report (doc/alt-text.md). (node)/<node id> lists the
 * images without alt text under that node (the node included; the editor's read permissions apply), one row per
 * object, language and image field. "Queue suggestions" queues an expai.alttext job for each chosen row in one job
 * group (with an optional budget): a vision model writes the alt text into a new draft of the object, never
 * published; the editor reviews and publishes the drafts.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** @var eZModule $Module */
$Module = $Params['Module'];
$http = eZHTTPTool::instance();
$config = expAiConfig::instance();
$nodeID = isset( $Params['UserParameters']['node'] ) ? (int) $Params['UserParameters']['node'] : 0;
if ( !$nodeID )
    $nodeID = (int) $config->get( 'DashboardSettings', 'DefaultNodeID', 0 ) ?: (int) eZINI::instance( 'content.ini' )->variable( 'NodeSettings', 'RootNode' );
$node = eZContentObjectTreeNode::fetch( $nodeID );
$error = '';
$notice = '';
$queued = null;
$report = array( 'rows' => array(), 'checked' => 0, 'truncated' => false );
$max = $config->int( 'AltTextSettings', 'ReportMaxObjects', 500, 1, 5000 );

if ( !$node instanceof eZContentObjectTreeNode || !$node->canRead() )
    $error = ezpI18n::tr( 'extension/expai/error', 'Node %id does not exist or you may not read it.', null, array( '%id' => $nodeID ) );
else
{
    $report = expAiAltText::missing( $node, $max );
    if ( $http->hasPostVariable( 'ExpAiAltTextQueueButton' ) )
    {
        // only rows that are still missing an alt text and that the editor chose
        $chosen = array_flip( array_map( 'strval', (array) $http->postVariable( 'ExpAiAltTextRows', array() ) ) );
        $rows = array();
        foreach ( $report['rows'] as $row )
            if ( isset( $chosen[$row['object_id'] . ':' . $row['attribute_id'] . ':' . $row['language']] ) )
                $rows[] = $row;
        $budgetText = trim( str_replace( ',', '.', (string) $http->postVariable( 'ExpAiAltTextBudget', '' ) ) );
        try
        {
            if ( !$rows )
                throw new expAiException( 'Choose at least one image.' );
            $queued = expAiAltText::queue( $config, $rows, is_numeric( $budgetText ) && $budgetText > 0 ? expAiBudget::micros( (float) $budgetText ) : 0 );
            $notice = ezpI18n::tr( 'extension/expai/alttext', '%queued suggestion(s) queued (estimated %estimate), %held held by a budget, %duplicates already queued, %skipped skipped. The drafts appear in "My drafts" when the jobs are done.', null, array(
                '%queued' => $queued['queued'], '%estimate' => expAiBudget::money( $queued['estimate'] ), '%held' => $queued['held'],
                '%duplicates' => $queued['duplicates'], '%skipped' => count( $queued['skipped'] ) ) );
        }
        catch ( expAiException $e )
        {
            $error = $e->translatedMessage();
        }
        catch ( expQueueException $e )
        {
            $error = ezpI18n::tr( 'extension/expai/alttext', 'The jobs could not be queued: %error', null, array( '%error' => $e->getMessage() ) );
        }
    }
}

$estimate = null;
$model = null;
if ( $node instanceof eZContentObjectTreeNode && $report['rows'] )
{
    $collection = expAiAltTextFunctionCollection::fetchModel( expAiObjects::fetch( (int) $report['rows'][0]['object_id'] ) );
    $model = $collection['result'];
    if ( $model['available'] )
    {
        $sum = 0;
        $known = true;
        foreach ( $report['rows'] as $row )
        {
            $one = expAiAltText::estimate( $config, $model['alias'], $row['width'], $row['height'] );
            $sum += $one['micros'];
            $known = $known && $one['known'];
        }
        $estimate = array( 'money' => expAiBudget::money( $sum ), 'known' => $known );
    }
}
$budget = new expAiBudget( $config );

$tpl = eZTemplate::factory();
$tpl->setVariable( 'node', $node instanceof eZContentObjectTreeNode && $node->canRead() ? $node : false );
$tpl->setVariable( 'rows', $report['rows'] );
$tpl->setVariable( 'checked', $report['checked'] );
$tpl->setVariable( 'truncated', $report['truncated'] );
$tpl->setVariable( 'max', $max );
$tpl->setVariable( 'error', $error );
$tpl->setVariable( 'notice', $notice );
$tpl->setVariable( 'queued', $queued );
$tpl->setVariable( 'model', $model );
$tpl->setVariable( 'estimate', $estimate );
$tpl->setVariable( 'queue', expAiQueue::available() );
$tpl->setVariable( 'site_cap', $budget->siteCap() > 0 ? expAiBudget::money( $budget->siteCap() ) : '' );
$tpl->setVariable( 'max_queue', $config->int( 'AltTextSettings', 'MaxQueuePerRequest', 100, 1, 1000 ) );

$Result = array();
$Result['content'] = $tpl->fetch( 'design:expai/alttext.tpl' );
$Result['path'] = array( array( 'url' => 'expai/dashboard', 'text' => ezpI18n::tr( 'extension/expai/alttext', 'Exponential Intelligence' ) ),
                         array( 'url' => false, 'text' => ezpI18n::tr( 'extension/expai/alttext', 'Missing alt text' ) ) );
