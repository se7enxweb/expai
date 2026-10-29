<?php
/**
 * File containing the expAiThreadFanout class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Parallel runs of an approved plan (doc/parallel-runs.md). Each runnable item becomes a child run of the thread
 * (expai_thread_run with parent_run_id = the lead run, plan_item = the item) with a conversation of its own, and one
 * queue job expai.thread.item (expAiThreadItemHandler) in one job group per plan:
 *
 *   - side by side: at most [VectorSettings] MaxParallelRunsPerThread items of a thread run at once (the expqueue
 *     resource expai.thread:<thread id>), within the queue's own limits (class interactive, per user and site);
 *   - dependencies: an item waits for the items it depends on (propose_plan depends_on; a translation waits for the
 *     update of its source object): the queue holds it behind its last dependency (depends_on of the job), and the
 *     handler defers it while another one still works; it starts with the changes they staged as its source;
 *   - one object x language x field is staged by one run at a time ("held by run N", expAiChangeStager);
 *   - progress: per run (steps done of the expected rounds) and per plan (items done of the items);
 *   - the thread stays running until the last item ended; then the plan is done, its actual cost per item recorded
 *     (the calibration per action and class learns from it) and the thread waits in Needs you with the changes.
 *
 * The plan's estimate_json keeps group (the job group uuid) and runs (item index => run id).
 */
class expAiThreadFanout
{
    const JOB_TYPE = 'expai.thread.item';

    /** @return bool approved plans fan out ([VectorSettings] ParallelRuns, the job queue and the job type are there) */
    public static function enabled( ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        return $config->flag( 'VectorSettings', 'ParallelRuns', true ) && expAiThreadRunner::queueAvailable()
            && (bool) expQueueConfig::instance()->jobType( self::JOB_TYPE );
    }

    /** @return int items of one thread that run at once */
    public static function maxParallel( ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        return $config->int( 'VectorSettings', 'MaxParallelRunsPerThread', 4, 1, 16 );
    }

    /** @return string the queue resource of a thread's items */
    public static function resourceKey( expAiThread $thread )
    {
        return 'expai.thread:' . (int) $thread->attribute( 'id' );
    }

    /**
     * The concurrency of the thread's resource: its row in expqueue_resource gets MaxParallelRunsPerThread (within
     * the queue's [ResourceSettings] MaxConcurrent, the ceiling of every resource without a group of its own).
     */
    protected static function limit( expAiThread $thread, expAiConfig $config )
    {
        $key = self::resourceKey( $thread );
        expQueueResource::ensure( $key );
        $max = self::maxParallel( $config );
        $db = expQueue::db();
        $db->query( 'UPDATE expqueue_resource SET max_concurrent = ' . (int) $max . " WHERE resource_key = '" . $db->escapeString( $key ) . "'" );
        expQueueResource::clearCache();
    }

    /** @return string the label of an item's run: "Update: Main Street (eng-GB)" */
    public static function label( array $item )
    {
        $actions = array( 'create' => ezpI18n::tr( 'extension/expai/vector', 'Create' ), 'update' => ezpI18n::tr( 'extension/expai/vector', 'Update' ),
                          'translate' => ezpI18n::tr( 'extension/expai/vector', 'Translate' ) );
        $action = isset( $actions[$item['action']] ) ? $actions[$item['action']] : (string) $item['action'];
        return expAiText::cut( $action . ': ' . $item['target_name'] . ( $item['languages'] ? ' (' . implode( ', ', $item['languages'] ) . ')' : '' ), 255 );
    }

    /** @return int the node an item's run works on: the target's node, the parent of a new object, else the fallback */
    public static function nodeOf( array $item, $fallback )
    {
        if ( $item['action'] === 'create' && !empty( $item['target']['parent_node_id'] ) )
            return (int) $item['target']['parent_node_id'];
        if ( !empty( $item['target']['node_id'] ) )
            return (int) $item['target']['node_id'];
        if ( !empty( $item['target']['object_id'] ) )
        {
            $object = expAiObjects::fetch( (int) $item['target']['object_id'] );
            if ( $object && $object->mainNodeID() )
                return (int) $object->mainNodeID();
        }
        return (int) $fallback;
    }

    /**
     * Fans an approved plan out into child runs and their jobs.
     * @param array $run the runnable items
     * @param int $reserve the reservation of the whole plan (micros), shared by the jobs in proportion to their estimate
     * @return array mode parallel, group (uuid), reserved, items, parallel (at once)
     */
    public static function start( expAiThread $thread, expAiPlan $plan, array $run, $reserve, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $lead = $thread->leadRun();
        $ownerID = (int) $thread->attribute( 'user_id' );
        $deps = expAiPlanner::runnableDependencies( $run );
        $estimate = $plan->estimate();
        $perItem = array();
        foreach ( isset( $estimate['items'] ) ? (array) $estimate['items'] : array() as $row )
            $perItem[(int) $row['index']] = isset( $row['micros'] ) ? (int) $row['micros'] : 0;
        $sum = max( 1, array_sum( $perItem ) );
        $budget = new expAiBudget( $config );
        $scope = $thread->scope();
        $fallback = $scope['nodes'] ? $scope['nodes'][0] : ( $scope['subtrees'] ? $scope['subtrees'][0] : 0 );
        $group = expQueue::createGroup( expAiText::cut( 'Vector plan ' . (int) $plan->attribute( 'id' ) . ': ' . $thread->attribute( 'title' ), 255 ),
                                        'expai.thread.plan', array( 'user_id' => $ownerID ) );
        self::limit( $thread, $config );
        $options = expAiPlanner::runOptions( $thread );
        $siteaccess = class_exists( 'expAiGate' ) ? expAiGate::siteAccess() : '';
        $rounds = new expAiPlanEstimate( $config );
        $runs = array();
        $jobs = array();
        $now = time();
        $total = count( $run );
        foreach ( array_values( $run ) as $n => $item )
        {
            $index = (int) $item['index'];
            $conversation = new expAiConversation( array( 'user_id' => $ownerID, 'node_id' => 0, 'messages' => '[]', 'created' => $now, 'modified' => $now ) );
            $conversation->store();
            $child = new expAiThreadRun( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'parent_run_id' => $lead ? (int) $lead->attribute( 'id' ) : 0,
                                                'plan_item' => $index, 'conversation_id' => (int) $conversation->attribute( 'id' ), 'kind' => 'item',
                                                'state' => $deps[$index] ? 'waiting' : 'queued', 'label' => self::label( $item ),
                                                'progress_total' => $rounds->rounds( $item['action'] ) * max( 1, count( $item['languages'] ) ),
                                                'model_alias' => (string) $thread->attribute( 'model_alias' ), 'lane' => (string) $thread->attribute( 'lane' ),
                                                'created' => $now, 'modified' => $now ) );
            $child->store();
            $conversation->setAttribute( 'thread_id', (int) $thread->attribute( 'id' ) );
            $conversation->setAttribute( 'run_id', (int) $child->attribute( 'id' ) );
            $conversation->store();
            // the queue knows one parent per job: the last dependency (the handler waits for the others)
            $parent = $deps[$index] && isset( $jobs[end( $deps[$index] )] ) ? $jobs[end( $deps[$index] )] : null;
            $payload = array( 'thread_id' => (int) $thread->attribute( 'id' ), 'run_id' => (int) $child->attribute( 'id' ), 'plan_id' => (int) $plan->attribute( 'id' ),
                              'item' => $index, 'node_id' => self::nodeOf( $item, $fallback ),
                              'message' => expAiPlanner::itemMessage( $item, $n + 1, $total ), 'options' => $options, 'siteaccess' => $siteaccess );
            $queueOptions = array( 'class' => 'interactive', 'user_id' => $ownerID, 'group' => $group, 'resource' => self::resourceKey( $thread ),
                                   'tags' => array( 'thread:' . $thread->attribute( 'uuid' ), 'plan:' . (int) $plan->attribute( 'id' ) ) );
            if ( $parent )
                $queueOptions['depends_on'] = $parent;
            $job = expQueue::enqueue( self::JOB_TYPE, $payload, $queueOptions );
            $budget->reserve( $job, (int) round( $reserve * ( isset( $perItem[$index] ) ? $perItem[$index] : $sum / max( 1, $total ) ) / $sum ) );
            $child->setAttribute( 'job_uuid', (string) $job->attribute( 'uuid' ) );
            $child->store();
            $runs[$index] = (int) $child->attribute( 'id' );
            $jobs[$index] = $job;
        }
        $estimate['group'] = (string) $group->attribute( 'uuid' );
        $estimate['runs'] = $runs;
        $plan->setEstimate( $estimate );
        $plan->store();
        $parallel = min( self::maxParallel( $config ), count( array_filter( $deps, function ( $d ) { return !$d; } ) ) ?: 1 );
        $thread->setState( 'running', array( 'runs' => count( $runs ) ) );
        $thread->addEvent( 'run', array( 'op' => 'fanout', 'plan_id' => (int) $plan->attribute( 'id' ), 'runs' => count( $runs ), 'parallel' => $parallel ) );
        $thread->touch();
        $thread->store();
        // workers that take items for up to 15 minutes (each leaves as soon as nothing is ready)
        expAiThreadRunner::kick( $parallel, 900 );
        return array( 'mode' => 'parallel', 'job' => '', 'group' => (string) $group->attribute( 'uuid' ), 'reserved' => (int) $reserve,
                      'items' => count( $runs ), 'parallel' => $parallel );
    }

    /** @return expAiThreadRun[] index => run, the child runs of a plan */
    public static function planRuns( ?expAiPlan $plan )
    {
        if ( !$plan )
            return array();
        $estimate = $plan->estimate();
        $out = array();
        foreach ( isset( $estimate['runs'] ) ? (array) $estimate['runs'] : array() as $index => $runID )
        {
            $run = expAiThreadRun::fetchByID( (int) $runID );
            if ( $run )
                $out[(int) $index] = $run;
        }
        ksort( $out );
        return $out;
    }

    /** @return expAiPlan|null the thread's plan when it fanned out into child runs */
    public static function planOf( expAiThread $thread )
    {
        $plan = expAiPlan::fetchByID( (int) $thread->attribute( 'plan_id' ) );
        if ( !$plan )
            return null;
        $estimate = $plan->estimate();
        return !empty( $estimate['runs'] ) ? $plan : null;
    }

    /** @return int[] the item indexes an item of a plan waits for (runnable ones only) */
    public static function dependenciesOf( expAiPlan $plan, $index )
    {
        $runs = self::planRuns( $plan );
        foreach ( $plan->items() as $item )
            if ( (int) $item['index'] === (int) $index )
                return array_values( array_intersect( isset( $item['depends_on'] ) ? array_map( 'intval', (array) $item['depends_on'] ) : array(), array_keys( $runs ) ) );
        return array();
    }

    /**
     * @return array( expAiThreadRun[] still working, expAiThreadRun[] ended without finishing ) the runs an item waits for
     */
    public static function blockers( expAiPlan $plan, expAiThreadRun $run )
    {
        $runs = self::planRuns( $plan );
        $active = array();
        $broken = array();
        foreach ( self::dependenciesOf( $plan, (int) $run->attribute( 'plan_item' ) ) as $index )
        {
            if ( !isset( $runs[$index] ) )
                continue;
            if ( $runs[$index]->isActive() )
                $active[] = $runs[$index];
            elseif ( $runs[$index]->attribute( 'state' ) !== 'done' )
                $broken[] = $runs[$index];
        }
        return array( $active, $broken );
    }

    /**
     * What the items an item waits for staged, as the source of a translation: per field the proposed value (rich text
     * as HTML, the form the write tools take). Cut at about 12000 characters.
     * @return string '' when they staged nothing
     */
    public static function dependencyContext( expAiPlan $plan, expAiThreadRun $run )
    {
        $runs = self::planRuns( $plan );
        $lines = array();
        $budget = 12000;
        foreach ( self::dependenciesOf( $plan, (int) $run->attribute( 'plan_item' ) ) as $index )
        {
            if ( !isset( $runs[$index] ) )
                continue;
            $changes = eZPersistentObject::fetchObjectList( expAiChange::definition(), null,
                array( 'thread_id' => (int) $run->attribute( 'thread_id' ), 'run_id' => (int) $runs[$index]->attribute( 'id' ),
                       'decision' => array( array( 'pending', 'accepted', 'partial', 'applied' ) ) ), array( 'id' => 'asc' ), array( 'offset' => 0, 'length' => 100 ) );
            foreach ( (array) $changes as $change )
            {
                $value = (string) $change->attribute( 'proposed' );
                // with placeholders numbered from the proposal: the stager takes the open proposals of the thread as sources
                if ( expAiRichTextTokens::handles( $change->attribute( 'datatype' ) ) )
                    $value = ( new expAiRichTextTokens() )->forModel( $value );
                elseif ( $change->attribute( 'datatype' ) === 'ezxmltext' )
                    $value = expAiText::xmlTextToHtml( $value );
                $value = expAiText::cut( $value, max( 200, $budget ) );
                $budget -= mb_strlen( $value, 'UTF-8' );
                $lines[] = '- object ' . (int) $change->attribute( 'contentobject_id' ) . ', language ' . $change->attribute( 'language_code' )
                         . ', field ' . $change->attribute( 'attribute_identifier' ) . ":\n" . $value;
                if ( $budget <= 0 )
                    break 2;
            }
        }
        return $lines ? "The item(s) this one waits for staged these changes (not yet written; they are the current source text):\n" . implode( "\n", $lines ) : '';
    }

    /**
     * A run ended (done, failed, stopped): its state and cost, an event, the runs that wait for it are stopped when it
     * did not finish, and the plan is settled when no run is left.
     */
    public static function finishRun( expAiThread $thread, expAiThreadRun $run, $state, $error = '' )
    {
        $run = expAiThreadRun::fetchByID( (int) $run->attribute( 'id' ) ) ?: $run;
        $run->setAttribute( 'state', $state );
        $run->setAttribute( 'finished', time() );
        $run->setAttribute( 'modified', time() );
        $run->refreshCost();
        if ( $state === 'done' )
            $run->setAttribute( 'progress_done', max( (int) $run->attribute( 'progress_done' ), (int) $run->attribute( 'progress_total' ) ) );
        $run->store();
        $thread = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ?: $thread;
        if ( $error !== '' )
            $thread->addEvent( 'error', array( 'message' => expAiText::cut( $error, 500 ), 'run_id' => (int) $run->attribute( 'id' ) ), (int) $run->attribute( 'id' ) );
        $thread->addEvent( 'run', array( 'run_id' => (int) $run->attribute( 'id' ), 'item' => (int) $run->attribute( 'plan_item' ), 'state' => $state,
                                         'micros' => (int) $run->attribute( 'cost_micros' ) ), (int) $run->attribute( 'id' ) );
        $thread->storeActivity();
        $plan = self::planOf( $thread );
        if ( $plan && $state !== 'done' )
            self::stopDependants( $thread, $plan, $run );
        elseif ( $plan )
            self::wakeDependants( $plan, $run );
        self::settle( $thread );
    }

    /**
     * An item that waited for several items was deferred while one still worked; when the last of them is done its
     * job is made ready at once, so the worker that finished takes it next (a worker leaves when nothing is ready).
     */
    protected static function wakeDependants( expAiPlan $plan, expAiThreadRun $finished )
    {
        $index = (int) $finished->attribute( 'plan_item' );
        foreach ( self::planRuns( $plan ) as $i => $run )
        {
            if ( $run->attribute( 'state' ) !== 'waiting' || (string) $run->attribute( 'job_uuid' ) === '' || !in_array( $index, self::dependenciesOf( $plan, $i ), true ) )
                continue;
            list( $active, $broken ) = self::blockers( $plan, $run );
            if ( $active || $broken )
                continue;
            $job = expQueue::fetch( (string) $run->attribute( 'job_uuid' ) );
            // only a deferred job (its parent job is done): a job that still waits for its parent is woken by the queue
            if ( $job && $job->attribute( 'state' ) === 'queued' && ( !(int) $job->attribute( 'parent_id' ) || ( ( $p = expQueueJob::fetchByID( (int) $job->attribute( 'parent_id' ) ) ) && $p->attribute( 'state' ) === 'done' ) ) )
                expQueue::casUpdate( (int) $job->attribute( 'id' ), array( 'state' => 'queued' ), array( 'state' => 'ready', 'ready_since' => expQueue::now(), 'run_after' => 0 ) );
        }
    }

    /** The runs that wait for a run that did not finish are stopped (their jobs are cancelled by the queue) */
    protected static function stopDependants( expAiThread $thread, expAiPlan $plan, expAiThreadRun $failed )
    {
        $index = (int) $failed->attribute( 'plan_item' );
        foreach ( self::planRuns( $plan ) as $i => $run )
        {
            if ( !$run->isActive() || !in_array( $index, self::dependenciesOf( $plan, $i ), true ) )
                continue;
            if ( (string) $run->attribute( 'job_uuid' ) !== '' )
                expQueue::cancel( (string) $run->attribute( 'job_uuid' ), 'the item it waits for did not finish' );
            self::finishRun( $thread, $run, 'stopped', ezpI18n::tr( 'extension/expai/vector', 'Not run: the item it waits for (%run) did not finish.', null,
                                                                       array( '%run' => $failed->shortName() ) ) );
        }
    }

    /**
     * The plan ended when none of its runs is active: the plan is done (actual cost per item recorded), the
     * reservation released, the thread waits in Needs you with the staged changes (or is idle).
     * @return bool the plan was settled now
     */
    public static function settle( expAiThread $thread )
    {
        $thread = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ?: $thread;
        $plan = self::planOf( $thread );
        if ( !$plan || $plan->attribute( 'state' ) !== 'running' )
            return false;
        foreach ( self::planRuns( $plan ) as $run )
            if ( $run->isActive() )
                return false;
        expAiPlanner::complete( $thread );
        $thread = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ?: $thread;
        if ( in_array( $thread->attribute( 'state' ), array( 'queued', 'running' ), true ) )
            $thread->setState( $thread->restingState() );
        $thread->touch();
        $thread->store();
        return true;
    }

    /** @return array item index => actual micros of the plan's runs (refreshed from the usage log) */
    public static function itemActuals( expAiThread $thread, expAiPlan $plan )
    {
        $out = array();
        foreach ( self::planRuns( $plan ) as $index => $run )
        {
            $run->refreshCost();
            $run->store();
            $out[$index] = (int) $run->attribute( 'cost_micros' );
        }
        return $out;
    }

    /** Stop: the plan's job group is cancelled (running items stop after their current step), its waiting runs stop */
    public static function cancel( expAiThread $thread )
    {
        $plan = self::planOf( $thread );
        if ( !$plan )
            return 0;
        $estimate = $plan->estimate();
        $n = 0;
        if ( !empty( $estimate['group'] ) && expAiThreadRunner::queueAvailable() )
            $n = expQueue::cancelGroup( (string) $estimate['group'], 'stopped by the editor' );
        foreach ( self::planRuns( $plan ) as $run )
        {
            // a running item finishes its current step and stops itself (the handler sees the cancelled job)
            if ( in_array( $run->attribute( 'state' ), array( 'queued', 'waiting' ), true ) )
            {
                $run->setAttribute( 'state', 'stopped' );
                $run->setAttribute( 'finished', time() );
                $run->store();
            }
        }
        self::settle( $thread );
        return $n;
    }

    /**
     * Runs whose job ended without finishing the item (dead, cancelled, lost) are marked failed or stopped, so the
     * thread never waits for them. Called by expaivector::events.
     * @return bool something changed
     */
    public static function reconcile( expAiThread $thread )
    {
        $plan = self::planOf( $thread );
        if ( !$plan || $plan->attribute( 'state' ) !== 'running' || !expAiThreadRunner::queueAvailable() )
            return false;
        $changed = false;
        $estimate = $plan->estimate();
        foreach ( self::planRuns( $plan ) as $run )
        {
            if ( !$run->isActive() || (string) $run->attribute( 'job_uuid' ) === '' )
                continue;
            $job = expQueue::fetch( (string) $run->attribute( 'job_uuid' ) );
            if ( !$job || !$job->isFinal() )
                continue;
            $state = (string) $job->attribute( 'state' );
            // on a batch lane a run has one request per language; its applier ends the run with the last one
            if ( $state === 'done' && !empty( $estimate['batch'] ) )
                continue;
            if ( $state === 'done' )
            {
                // the handler ended without saying so (it is finishing now, or lost its last write): done
                self::finishRun( $thread, $run, 'done' );
                $changed = true;
                continue;
            }
            self::finishRun( $thread, $run, $state === 'cancelled' ? 'stopped' : 'failed',
                             $state === 'cancelled' ? '' : ezpI18n::tr( 'extension/expai/vector', 'The job of this item ended without an answer: %error', null,
                                                                         array( '%error' => expAiText::cut( (string) $job->attribute( 'last_error' ), 200 ) ) ) );
            $changed = true;
        }
        return $changed || self::settle( $thread );
    }

    /**
     * The runs of the thread's plan for the browser: per item the run's state, progress, last step, estimate and
     * actual cost, and what it waits for; with the plan's progress (items done of the items).
     * @return array runs (list), done, total, active
     */
    public static function present( expAiThread $thread )
    {
        $plan = self::planOf( $thread );
        if ( !$plan )
            return array( 'runs' => array(), 'done' => 0, 'total' => 0, 'active' => 0 );
        $estimate = $plan->estimate();
        $estimates = array();
        foreach ( isset( $estimate['items'] ) ? (array) $estimate['items'] : array() as $row )
            $estimates[(int) $row['index']] = isset( $row['micros'] ) ? (int) $row['micros'] : 0;
        $runs = self::planRuns( $plan );
        // the last step of each run: from the newest events of the thread
        $last = array();
        $from = max( 0, (int) $thread->attribute( 'last_event_id' ) - 400 );
        foreach ( expAiThreadEvent::fetchAfter( (int) $thread->attribute( 'id' ), $from, 400 ) as $event )
            if ( in_array( $event->attribute( 'kind' ), array( 'step_end', 'text', 'error' ), true ) )
            {
                $payload = $event->payload();
                $text = isset( $payload['text'] ) ? (string) $payload['text'] : ( isset( $payload['message'] ) ? (string) $payload['message'] : '' );
                if ( $text !== '' )
                    $last[(int) $event->attribute( 'run_id' )] = expAiText::cut( expAiText::oneLine( $text ), 160 );
            }
        $money = function ( $micros ) { return expAiCost::enabled() ? expAiCost::money( (int) $micros / 1000000 ) : ''; };
        $out = array();
        $done = 0;
        $active = 0;
        foreach ( $runs as $index => $run )
        {
            $state = (string) $run->attribute( 'state' );
            if ( !$run->isActive() )
                $done++;
            else
                $active++;
            $out[] = array( 'id' => (int) $run->attribute( 'id' ), 'item' => (int) $index, 'label' => (string) $run->attribute( 'label' ), 'state' => $state,
                            'progress_done' => (int) $run->attribute( 'progress_done' ), 'progress_total' => (int) $run->attribute( 'progress_total' ),
                            'last' => isset( $last[(int) $run->attribute( 'id' )] ) ? $last[(int) $run->attribute( 'id' )] : '',
                            'depends_on' => array_map( function ( $i ) { return $i + 1; }, self::dependenciesOf( $plan, $index ) ),
                            'estimate' => isset( $estimates[$index] ) ? $money( $estimates[$index] ) : '', 'actual' => $money( (int) $run->attribute( 'cost_micros' ) ),
                            'estimate_micros' => isset( $estimates[$index] ) ? $estimates[$index] : 0, 'actual_micros' => (int) $run->attribute( 'cost_micros' ),
                            'lane' => (string) $run->attribute( 'lane' ) );
        }
        return array( 'runs' => $out, 'done' => $done, 'total' => count( $out ), 'active' => $active, 'plan_id' => (int) $plan->attribute( 'id' ),
                      'plan_state' => (string) $plan->attribute( 'state' ) );
    }
}
