<?php
/**
 * File containing the expAiPlanner class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Plan mode (doc/plan-mode.md): the plan propose_plan stores, its validation against the thread's scope and the
 * editor's permissions, the editor's approval (with items deselected or their instructions edited), the reservation
 * of the estimate against limits and budgets, and the run of the approved items.
 *
 * An approved plan runs as turns of the thread's lead run, one turn per item, in write mode with staged review (every
 * write becomes a staged change, new objects too): in the job queue (job expai.thread.turn with the item messages,
 * the estimate reserved on the job) when the queue is there, else in the browser (the item messages wait as queued
 * follow-ups and are sent one after the other). With [VectorSettings] ParallelRuns=enabled each item is a child run of
 * its own, side by side, translations after their source item (expAiThreadFanout, doc/parallel-runs.md); on a batch
 * lane each item is one provider batch request (expAiPlanBatch).
 */
class expAiPlanner
{
    /** @var string[] */
    public static $actions = array( 'create', 'update', 'translate' );

    /**
     * Checks the items of a proposed plan: target readable and inside the scope, the user may edit it in the languages
     * (or create the class under the parent), the class and the languages exist. Items that fail stay in the plan with
     * the reason in "refused" (the editor sees them; they never run).
     * @param array $raw the tool input's items
     * @return array the items
     */
    public static function validateItems( expAiThread $thread, array $raw, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $max = $config->int( 'VectorSettings', 'MaxPlanItems', 50, 1, 500 );
        $siteLanguages = eZContentLanguage::fetchLocaleList();
        $scope = $thread->scope();
        $items = array();
        foreach ( array_slice( array_values( $raw ), 0, $max ) as $n => $in )
        {
            $in = is_array( $in ) ? $in : array();
            $item = self::newItem( $in, $n );
            $item['refused'] = self::check( $thread, $item, $siteLanguages, $scope );
            if ( $item['refused'] === '' || $item['target_name'] === '' )
                $item['target_name'] = self::targetName( $item );
            // dependencies (doc/parallel-runs.md): the numbers of earlier items this one waits for (1-based in the tool
            // input, kept as indexes); an index the item already has (a plan read back for approval) stays as it is
            $item['depends_on'] = self::dependencies( $in, $n );
            $items[] = $item;
        }
        return self::autoDependencies( $items );
    }

    /**
     * An item of the tool input in the stored form, not yet checked (no database is read).
     * @param array $in an item of the tool input
     * @param int $n its index
     * @return array index, action, target (node_id, object_id, parent_node_id), target_name, class, languages, fields,
     *               summary, refused, selected
     */
    public static function newItem( array $in, $n )
    {
        $action = isset( $in['action'] ) && in_array( $in['action'], self::$actions, true ) ? (string) $in['action'] : 'update';
        $languages = array_values( array_unique( array_filter( array_map( 'strval', isset( $in['languages'] ) ? (array) $in['languages'] : array() ),
            function ( $l ) { return preg_match( '/^[a-z]{3}-[A-Z]{2}$/', $l ) === 1; } ) ) );
        $fields = array_values( array_unique( array_filter( array_map( 'strval', isset( $in['fields'] ) ? (array) $in['fields'] : array() ),
            function ( $f ) { return preg_match( '/^[A-Za-z0-9_]{1,100}$/', $f ) === 1; } ) ) );
        return array( 'index' => (int) $n, 'action' => $action,
                      'target' => array( 'node_id' => isset( $in['node_id'] ) ? (int) $in['node_id'] : 0, 'object_id' => isset( $in['object_id'] ) ? (int) $in['object_id'] : 0,
                                         'parent_node_id' => isset( $in['parent_node_id'] ) ? (int) $in['parent_node_id'] : 0 ),
                      'target_name' => '', 'class' => isset( $in['class'] ) ? mb_substr( (string) $in['class'], 0, 100, 'UTF-8' ) : '',
                      'languages' => $languages, 'fields' => array_slice( $fields, 0, 30 ),
                      'summary' => expAiText::cut( expAiText::oneLine( isset( $in['summary'] ) ? (string) $in['summary'] : '' ), 500 ),
                      'refused' => '', 'selected' => true );
    }

    /** @return int[] the earlier item indexes an input item names in depends_on (1-based numbers, or indexes as stored) */
    protected static function dependencies( array $in, $n )
    {
        $out = array();
        $list = isset( $in['depends_on'] ) ? (array) $in['depends_on'] : array();
        $stored = !empty( $in['depends_stored'] );
        foreach ( $list as $value )
        {
            if ( !is_numeric( $value ) )
                continue;
            $index = $stored ? (int) $value : (int) $value - 1;
            if ( $index >= 0 && $index < $n && !in_array( $index, $out, true ) )
                $out[] = $index;
        }
        sort( $out );
        return $out;
    }

    /**
     * A translation waits for the item that updates or creates its source: an earlier update item of the same object
     * (the translation then works from the staged source text, not the published one).
     * @return array the items with depends_on completed
     */
    public static function autoDependencies( array $items )
    {
        foreach ( $items as $i => $item )
        {
            if ( $item['action'] !== 'translate' || empty( $item['target']['object_id'] ) )
                continue;
            for ( $j = 0; $j < $i; $j++ )
                if ( $items[$j]['action'] === 'update' && (int) $items[$j]['target']['object_id'] === (int) $item['target']['object_id']
                     && !array_intersect( $items[$j]['languages'], $item['languages'] ) && !in_array( $j, $item['depends_on'], true ) )
                    $items[$i]['depends_on'][] = $j;
            sort( $items[$i]['depends_on'] );
        }
        return $items;
    }

    /**
     * The dependencies of the runnable items among each other: a dependency on an item that does not run (deselected
     * or refused) is dropped, the item runs without waiting.
     * @param array $run the runnable items
     * @return array index => int[] runnable indexes it waits for
     */
    public static function runnableDependencies( array $run )
    {
        $indexes = array_map( function ( $i ) { return (int) $i['index']; }, $run );
        $out = array();
        foreach ( $run as $item )
            $out[(int) $item['index']] = array_values( array_intersect( isset( $item['depends_on'] ) ? array_map( 'intval', (array) $item['depends_on'] ) : array(), $indexes ) );
        return $out;
    }

    /** @return string '' or why the item cannot run (translated) */
    protected static function check( expAiThread $thread, array &$item, array $siteLanguages, array $scope )
    {
        $tr = function ( $text, $args = array() ) { return ezpI18n::tr( 'extension/expai/vector', $text, null, $args ); };
        if ( !$item['languages'] )
            return $tr( 'No language given.' );
        foreach ( $item['languages'] as $language )
        {
            if ( !in_array( $language, $siteLanguages, true ) )
                return $tr( 'The language %language is not used on this site.', array( '%language' => $language ) );
            if ( !expAiChangeStager::languageInScope( $thread, $language ) )
                return $tr( 'The language %language is not in the scope of this thread.', array( '%language' => $language ) );
        }
        if ( $item['action'] === 'create' )
        {
            $parent = eZContentObjectTreeNode::fetch( $item['target']['parent_node_id'] ?: $item['target']['node_id'] );
            if ( !$parent instanceof eZContentObjectTreeNode || !$parent->canRead() )
                return $tr( 'The location does not exist or you may not read it.' );
            $item['target']['parent_node_id'] = (int) $parent->attribute( 'node_id' );
            $item['target_name'] = (string) $parent->attribute( 'name' );
            if ( !expAiChangeStager::nodeInScope( $thread, $parent ) )
                return $tr( 'The location lies outside the scope of this thread.' );
            $class = eZContentClass::fetchByIdentifier( $item['class'] );
            if ( !$class instanceof eZContentClass )
                return $tr( 'There is no content class "%class".', array( '%class' => $item['class'] ) );
            if ( $scope['classes'] && !in_array( $item['class'], $scope['classes'], true ) )
                return $tr( 'The class "%class" is not in the scope of this thread.', array( '%class' => $item['class'] ) );
            $parentObject = $parent->object();
            foreach ( $item['languages'] as $language )
                if ( $parent->checkAccess( 'create', $class->attribute( 'id' ), $parentObject->attribute( 'contentclass_id' ), false, $language ) != 1 )
                    return $tr( 'You may not create %class objects in %language here.', array( '%class' => $item['class'], '%language' => $language ) );
            return '';
        }
        $object = null;
        if ( $item['target']['object_id'] )
            $object = expAiObjects::fetch( $item['target']['object_id'] );
        elseif ( $item['target']['node_id'] )
        {
            $node = eZContentObjectTreeNode::fetch( $item['target']['node_id'] );
            $object = $node ? $node->object() : null;
        }
        if ( !$object instanceof eZContentObject || !$object->canRead() )
            return $tr( 'The object does not exist or you may not read it.' );
        $item['target']['object_id'] = (int) $object->attribute( 'id' );
        if ( !$item['target']['node_id'] && $object->mainNodeID() )
            $item['target']['node_id'] = (int) $object->mainNodeID();
        $item['target_name'] = (string) $object->attribute( 'name' );
        $item['class'] = (string) $object->attribute( 'class_identifier' );
        if ( !expAiChangeStager::objectInScope( $thread, $object ) )
            return $tr( 'The object lies outside the scope of this thread.' );
        foreach ( $item['languages'] as $language )
            if ( !$object->canEdit( false, false, false, $language ) )
                return $tr( 'You may not edit this object in %language.', array( '%language' => $language ) );
        return '';
    }

    /** @return string */
    protected static function targetName( array $item )
    {
        if ( $item['action'] === 'create' )
        {
            $node = eZContentObjectTreeNode::fetch( (int) $item['target']['parent_node_id'] );
            return $node && $node->canRead() ? (string) $node->attribute( 'name' ) : '#' . (int) $item['target']['parent_node_id'];
        }
        $object = $item['target']['object_id'] ? expAiObjects::fetch( (int) $item['target']['object_id'] ) : null;
        return $object && $object->canRead() ? (string) $object->attribute( 'name' ) : '#' . (int) ( $item['target']['object_id'] ?: $item['target']['node_id'] );
    }

    /**
     * Stores a proposed plan (earlier proposed plans of the thread are superseded) with its estimate.
     * @return expAiPlan
     */
    public static function propose( expAiThread $thread, array $input, $model = '', ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $items = self::validateItems( $thread, isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : array(), $config );
        if ( !$items )
            throw new expAiException( 'A plan needs at least one item.' );
        $list = function ( $key ) use ( $input )
        {
            return array_slice( array_values( array_filter( array_map( function ( $v ) { return expAiText::cut( expAiText::oneLine( (string) $v ), 400 ); },
                isset( $input[$key] ) && is_array( $input[$key] ) ? array_filter( $input[$key], 'is_scalar' ) : array() ), 'strlen' ) ), 0, 10 );
        };
        $db = eZDB::instance();
        $db->begin();
        try
        {
            foreach ( (array) eZPersistentObject::fetchObjectList( expAiPlan::definition(), null, array( 'thread_id' => (int) $thread->attribute( 'id' ), 'state' => 'proposed' ) ) as $old )
            {
                $old->setAttribute( 'state', 'superseded' );
                $old->setAttribute( 'modified', time() );
                $old->store();
            }
            $now = time();
            $plan = new expAiPlan( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'state' => 'proposed', 'created' => $now, 'modified' => $now ) );
            $plan->setItems( $items );
            $estimate = ( new expAiPlanEstimate( $config ) )->estimate( $plan->runnableItems(), self::alias( $thread, $config ), (string) $thread->attribute( 'lane' ), $thread );
            $estimate['meta'] = array( 'assumptions' => $list( 'assumptions' ), 'questions' => $list( 'questions' ), 'risks' => $list( 'risks' ), 'model' => (string) $model );
            $plan->setEstimate( $estimate );
            $plan->store();
            $thread->addEvent( 'plan', array( 'plan_id' => (int) $plan->attribute( 'id' ), 'items' => count( $items ),
                                              'refused' => count( array_filter( $items, function ( $i ) { return $i['refused'] !== ''; } ) ),
                                              'expected_micros' => (int) $estimate['expected_micros'] ) );
            $thread->touch();
            $thread->store();
            $db->commit();
        }
        catch ( Throwable $e )
        {
            if ( $db->transactionCounter() > 0 )
                $db->rollback();
            throw $e;
        }
        return $plan;
    }

    /** @return string the model of the thread (or the user's) */
    public static function alias( expAiThread $thread, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        return (string) $thread->attribute( 'model_alias' ) !== '' ? (string) $thread->attribute( 'model_alias' ) : $config->effectiveModel();
    }

    /**
     * The editor's changes to a proposed plan: items deselected, the instruction of an item rewritten. Returns the
     * plan with a new estimate (not stored).
     * @param array $selected item indexes that run (null: unchanged)
     * @param array $summaries index => new instruction
     */
    public static function edit( expAiPlan $plan, expAiThread $thread, $selected = null, array $summaries = array(), ?expAiConfig $config = null )
    {
        $items = $plan->items();
        foreach ( $items as $i => $item )
        {
            if ( is_array( $selected ) )
                $items[$i]['selected'] = in_array( (int) $item['index'], array_map( 'intval', $selected ), true );
            if ( isset( $summaries[$item['index']] ) && is_scalar( $summaries[$item['index']] ) )
                $items[$i]['summary'] = expAiText::cut( expAiText::oneLine( (string) $summaries[$item['index']] ), 500 );
        }
        $plan->setItems( $items );
        $estimate = $plan->estimate();
        $meta = isset( $estimate['meta'] ) ? $estimate['meta'] : array();
        $estimate = ( new expAiPlanEstimate( $config ) )->estimate( $plan->runnableItems(), self::alias( $thread, $config ), (string) $thread->attribute( 'lane' ), $thread );
        $estimate['meta'] = $meta;
        $plan->setEstimate( $estimate );
        return $plan;
    }

    /** @return string the message of one approved item for the model (English, as all instructions) */
    public static function itemMessage( array $item, $n, $total )
    {
        $target = $item['action'] === 'create'
            ? 'a new ' . ( $item['class'] !== '' ? $item['class'] : 'object' ) . ' under node ' . (int) $item['target']['parent_node_id'] . ' ("' . $item['target_name'] . '")'
            : 'object ' . (int) $item['target']['object_id'] . ' ("' . $item['target_name'] . '")';
        return 'Approved plan, item ' . $n . ' of ' . $total . ': ' . $item['action'] . ' ' . $target
             . ( $item['languages'] ? ', language(s) ' . implode( ', ', $item['languages'] ) : '' )
             . ( $item['fields'] ? ', fields ' . implode( ', ', $item['fields'] ) : '' ) . '. '
             . ( $item['summary'] !== '' ? 'Instruction: ' . $item['summary'] . ' ' : '' )
             . 'Do this item now and only this item: read what you need, then stage the change with '
             . ( $item['action'] === 'create' ? 'create_draft' : 'update_draft' ) . ' (once per language). Then answer in one sentence.';
    }

    /**
     * Approves a proposed plan: limits and budgets are checked against the expected cost, the plan becomes the
     * thread's plan, the thread goes to write mode with staged review, and the items run.
     * @return array mode (background | foreground), job (uuid), reserved (micros), items (count)
     * @throws expAiException
     */
    public static function approve( expAiThread $thread, expAiPlan $plan, $selected = null, array $summaries = array(), ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        if ( $plan->attribute( 'state' ) !== 'proposed' || (int) $plan->attribute( 'thread_id' ) !== (int) $thread->attribute( 'id' ) )
            throw new expAiException( 'This plan can no longer be approved.' );
        $alias = self::alias( $thread, $config );
        if ( !expAiVector::mayUseModel( (string) $thread->attribute( 'model_alias' ), $config ) )
            throw new expAiException( 'The model of this thread is no longer available to you. Choose another model.' );
        // the targets again: permissions or content may have changed since the plan was proposed
        $items = self::validateItems( $thread, array_map( function ( $i )
        {
            return array( 'action' => $i['action'], 'node_id' => $i['target']['node_id'], 'object_id' => $i['target']['object_id'],
                          'parent_node_id' => $i['target']['parent_node_id'], 'class' => $i['class'], 'languages' => $i['languages'],
                          'fields' => $i['fields'], 'summary' => $i['summary'],
                          'depends_on' => isset( $i['depends_on'] ) ? $i['depends_on'] : array(), 'depends_stored' => true );
        }, $plan->items() ), $config );
        $old = $plan->items();
        foreach ( $items as $i => $item )
            $items[$i]['selected'] = isset( $old[$i]['selected'] ) ? (bool) $old[$i]['selected'] : true;
        $plan->setItems( $items );
        self::edit( $plan, $thread, $selected, $summaries, $config );
        $run = $plan->runnableItems();
        if ( !$run )
            throw new expAiException( 'Choose at least one item that can run.' );
        $estimate = $plan->estimate();
        $limits = ( new expAiPlanEstimate( $config ) )->limits( (int) $estimate['expected_micros'], (int) $thread->attribute( 'user_id' ) );
        if ( !$limits['ok'] )
            throw ( new expAiException( $limits['message'] ) )->translated();
        $now = time();
        $estimate['start_micros'] = (int) $thread->attribute( 'cost_micros' );
        $plan->setEstimate( $estimate );
        $plan->setAttribute( 'state', 'running' );
        $plan->setAttribute( 'approved_by', (int) eZUser::currentUserID() );
        $plan->setAttribute( 'approved', $now );
        $plan->setAttribute( 'modified', $now );
        $plan->store();
        $budget = new expAiBudget( $config );
        $reserve = (int) round( (int) $estimate['expected_micros'] * $budget->reserveFactor() );
        $thread->setAttribute( 'plan_id', (int) $plan->attribute( 'id' ) );
        $thread->setAttribute( 'run_mode', 'write' );
        $thread->setAttribute( 'review_policy', 'staged' );
        $thread->setAttribute( 'reserved_micros', min( 2147483647, $reserve ) );
        $thread->addEvent( 'plan', array( 'plan_id' => (int) $plan->attribute( 'id' ), 'state' => 'approved', 'items' => count( $run ), 'reserved_micros' => $reserve ) );
        $thread->touch();
        $thread->store();
        $messages = array();
        foreach ( $run as $n => $item )
            $messages[] = self::itemMessage( $item, $n + 1, count( $run ) );
        $scope = $thread->scope();
        $nodeID = $scope['nodes'] ? $scope['nodes'][0] : ( $scope['subtrees'] ? $scope['subtrees'][0] : 0 );
        // the thread's lane is a batch lane: one provider batch request per item, staged when the batch returns
        if ( $nodeID && expAiPlanBatch::applies( $thread, $config ) )
            return expAiPlanBatch::start( $thread, $plan, $run, $config );
        // one child run per item, side by side in the queue (doc/parallel-runs.md)
        if ( $nodeID && expAiThreadFanout::enabled( $config ) )
            return expAiThreadFanout::start( $thread, $plan, $run, $reserve, $config );
        if ( expAiThreadRunner::queueAvailable() && $nodeID )
        {
            $job = expAiThreadRunner::enqueue( $thread, $nodeID, '', self::runOptions( $thread ) + array( 'messages' => $messages, 'plan_id' => (int) $plan->attribute( 'id' ) ) );
            $budget->reserve( $job, $reserve );
            return array( 'mode' => 'background', 'job' => (string) $job->attribute( 'uuid' ), 'reserved' => $reserve, 'items' => count( $run ) );
        }
        // no queue: the items wait as follow-ups the browser sends one after the other
        $position = 1;
        foreach ( $messages as $message )
        {
            $followup = new expAiFollowup( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'body' => $message, 'position' => $position++,
                                                  'delivery' => 'queue', 'state' => 'waiting', 'created' => $now, 'modified' => $now ) );
            $followup->store();
        }
        return array( 'mode' => 'foreground', 'job' => '', 'reserved' => $reserve, 'items' => count( $run ) );
    }

    /** @return array the turn options of a thread's run in write mode with staged review */
    public static function runOptions( expAiThread $thread )
    {
        return array( 'alias' => (string) $thread->attribute( 'model_alias' ), 'read_only' => false, 'mode' => 'write', 'staged' => true,
                      'scope_note' => expAiThreadPrompts::scopeNote( $thread->scope() ), 'action' => 'vector' );
    }

    /** Rejects a proposed plan: the thread is idle again, in plan mode */
    public static function reject( expAiThread $thread, expAiPlan $plan )
    {
        if ( $plan->attribute( 'state' ) !== 'proposed' )
            throw new expAiException( 'This plan can no longer be rejected.' );
        $plan->setAttribute( 'state', 'rejected' );
        $plan->setAttribute( 'modified', time() );
        $plan->store();
        $thread->addEvent( 'plan', array( 'plan_id' => (int) $plan->attribute( 'id' ), 'state' => 'rejected' ) );
        if ( $thread->attribute( 'state' ) === 'review' && !expAiChange::counts( (int) $thread->attribute( 'id' ) )['open'] )
            $thread->setState( 'idle' );
        $thread->touch();
        $thread->store();
    }

    /**
     * The run of the thread's plan ended (all items, a stop or a failure): the plan is done, its actual cost recorded
     * for the calibration, the reservation released.
     */
    public static function complete( expAiThread $thread )
    {
        $plan = expAiPlan::fetchByID( (int) $thread->attribute( 'plan_id' ) );
        if ( !$plan || $plan->attribute( 'state' ) !== 'running' )
            return;
        $thread->refreshCost();
        $estimate = $plan->estimate();
        $estimate['actual_micros'] = max( 0, (int) $thread->attribute( 'cost_micros' ) - ( isset( $estimate['start_micros'] ) ? (int) $estimate['start_micros'] : 0 ) );
        // per item (child runs, doc/parallel-runs.md): the actual cost of each item, for the calibration per action and class
        $items = expAiThreadFanout::itemActuals( $thread, $plan );
        if ( $items )
            $estimate['actual_items'] = $items;
        $plan->setEstimate( $estimate );
        $plan->setAttribute( 'state', 'done' );
        $plan->setAttribute( 'modified', time() );
        $plan->store();
        $thread->setAttribute( 'reserved_micros', 0 );
        $thread->addEvent( 'plan', array( 'plan_id' => (int) $plan->attribute( 'id' ), 'state' => 'done', 'actual_micros' => (int) $estimate['actual_micros'] ) );
        $thread->store();
    }
}
