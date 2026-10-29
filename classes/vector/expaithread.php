<?php
/**
 * File containing the expAiThread class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A thread (table expai_thread): one unit of work of one editor. It has a scope (nodes, subtrees, classes, languages),
 * a mode (plan or write), a review policy (staged or direct), a model and lane, a state and a shelf. The conversation
 * of the full view panel is a thread too (origin "panel", scope: its node); Vector (expai/vector) starts threads of
 * its own (origin "vector"). Each thread has a lead run (expAiThreadRun) with its own conversation.
 *
 * States:  idle, queued, running, waiting_input, review, done, failed, stopped
 * Shelves: active, pinned, settled, archived
 *
 * Threads are private to their owner; the approvers of a version a thread wrote may open it read-only
 * (expAiThreadAccess). See doc/threads.md.
 */
class expAiThread extends eZPersistentObject
{
    const ORIGIN_PANEL = 'panel';
    const ORIGIN_VECTOR = 'vector';

    /** @var string[] */
    public static $states = array( 'idle', 'queued', 'running', 'waiting_input', 'review', 'done', 'failed', 'stopped' );

    /** @var string[] */
    public static $shelves = array( 'active', 'pinned', 'settled', 'archived' );

    /** @var bool|null the thread tables exist (checked once per process, only true is kept) */
    protected static $available = null;

    public static function definition()
    {
        $int = function ( $name ) { return array( 'name' => $name, 'datatype' => 'integer', 'default' => 0, 'required' => true ); };
        $str = function ( $name, $default = '' ) { return array( 'name' => $name, 'datatype' => 'string', 'default' => $default, 'required' => true ); };
        return array(
            'fields' => array(
                'id' => $int( 'ID' ),
                'uuid' => $str( 'UUID' ),
                'user_id' => $int( 'UserID' ),
                'title' => $str( 'Title' ),
                'origin' => $str( 'Origin', self::ORIGIN_VECTOR ),
                'node_id' => $int( 'NodeID' ),
                'scope_json' => $str( 'ScopeJSON', '{}' ),
                'run_mode' => $str( 'RunMode', 'write' ),
                'review_policy' => $str( 'ReviewPolicy', 'staged' ),
                'model_alias' => $str( 'ModelAlias' ),
                'provider' => $str( 'Provider' ),
                'lane' => $str( 'Lane', 'auto' ),
                'state' => $str( 'State', 'idle' ),
                'shelf' => $str( 'Shelf', 'active' ),
                'position' => $int( 'Position' ),
                'lead_run_id' => $int( 'LeadRunID' ),
                'plan_id' => $int( 'PlanID' ),
                'cost_micros' => $int( 'CostMicros' ),
                'tokens' => $int( 'Tokens' ),
                'requests' => $int( 'Requests' ),
                'reserved_micros' => $int( 'ReservedMicros' ),
                'last_event_id' => $int( 'LastEventID' ),
                'seen_event_id' => $int( 'SeenEventID' ),
                'created' => $int( 'Created' ),
                'modified' => $int( 'Modified' ),
            ),
            'keys' => array( 'id' ),
            'increment_key' => 'id',
            'class_name' => 'expAiThread',
            'sort' => array( 'modified' => 'desc' ),
            'name' => 'expai_thread',
        );
    }

    /**
     * The thread tables and columns exist (an installation that has not run bin/php/expaischema.php --install since
     * the update has none; the panel then works as before, without threads).
     * @return bool
     */
    public static function available()
    {
        if ( self::$available )
            return true;
        try
        {
            $db = eZDB::instance();
            $ok = !array_diff( expAiThreadSchema::tables(), expAiSchema::existingTables( $db ) );
            if ( $ok )
            {
                $columns = expAiSchema::existingColumns( 'expai_conversation', $db );
                $ok = $columns === null || in_array( 'thread_id', $columns, true );
            }
        }
        catch ( Exception $e )
        {
            $ok = false;
        }
        if ( $ok )
            self::$available = true;
        return $ok;
    }

    /** @return string a random UUID (version 4) */
    public static function newUuid()
    {
        $bytes = random_bytes( 16 );
        $bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
        $bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
        $hex = bin2hex( $bytes );
        return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
    }

    /** @return bool a well-formed UUID */
    public static function isUuid( $value )
    {
        return is_string( $value ) && preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value ) === 1;
    }

    /** @return expAiThread|null */
    public static function fetchByUuid( $uuid )
    {
        if ( !self::isUuid( $uuid ) )
            return null;
        $list = eZPersistentObject::fetchObjectList( self::definition(), null, array( 'uuid' => (string) $uuid ), null, array( 'offset' => 0, 'length' => 1 ) );
        return $list ? $list[0] : null;
    }

    /** @return expAiThread|null */
    public static function fetchByID( $id )
    {
        $object = eZPersistentObject::fetchObject( self::definition(), null, array( 'id' => (int) $id ) );
        return $object instanceof self ? $object : null;
    }

    /**
     * A new thread with its lead run and the run's conversation, stored.
     * @param array $row fields of expai_thread (user_id, title, origin, node_id, scope, run_mode, ...)
     * @param expAiConversation|null $conversation the conversation of the lead run (a new one when null)
     * @return expAiThread
     */
    public static function create( array $row, ?expAiConversation $conversation = null )
    {
        $now = time();
        $scope = isset( $row['scope'] ) ? $row['scope'] : array();
        unset( $row['scope'] );
        $row += array( 'uuid' => self::newUuid(), 'created' => $now, 'modified' => $now, 'origin' => self::ORIGIN_VECTOR,
                       'state' => 'idle', 'shelf' => 'active', 'lane' => 'auto' );
        $row['title'] = expAiText::cut( isset( $row['title'] ) ? (string) $row['title'] : '', 255 );
        $thread = new self( $row );
        $thread->setScope( $scope );
        $thread->store();
        if ( !$conversation )
        {
            // a Vector thread's conversation is found through its run, never by user and node (node_id 0), so the
            // panel of the scope's node keeps its own conversation
            $conversation = new expAiConversation( array( 'user_id' => (int) $row['user_id'], 'node_id' => 0, 'messages' => '[]',
                                                          'created' => $now, 'modified' => $now ) );
            $conversation->store();
        }
        $run = new expAiThreadRun( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'conversation_id' => (int) $conversation->attribute( 'id' ),
                                          'kind' => 'turn', 'state' => 'idle', 'label' => $thread->attribute( 'title' ),
                                          'model_alias' => (string) $thread->attribute( 'model_alias' ), 'lane' => (string) $thread->attribute( 'lane' ),
                                          'created' => $now, 'modified' => $now ) );
        $run->store();
        $conversation->setAttribute( 'thread_id', (int) $thread->attribute( 'id' ) );
        $conversation->setAttribute( 'run_id', (int) $run->attribute( 'id' ) );
        $conversation->store();
        $thread->setAttribute( 'lead_run_id', (int) $run->attribute( 'id' ) );
        $thread->store();
        return $thread;
    }

    /** @return array { nodes: int[], subtrees: int[], classes: string[], languages: string[] } */
    public function scope()
    {
        $data = json_decode( (string) $this->attribute( 'scope_json' ), true );
        $data = is_array( $data ) ? $data : array();
        return array(
            'nodes' => array_values( array_unique( array_map( 'intval', isset( $data['nodes'] ) ? (array) $data['nodes'] : array() ) ) ),
            'subtrees' => array_values( array_unique( array_map( 'intval', isset( $data['subtrees'] ) ? (array) $data['subtrees'] : array() ) ) ),
            'classes' => array_values( array_unique( array_map( 'strval', isset( $data['classes'] ) ? (array) $data['classes'] : array() ) ) ),
            'languages' => array_values( array_unique( array_map( 'strval', isset( $data['languages'] ) ? (array) $data['languages'] : array() ) ) ),
        );
    }

    public function setScope( array $scope )
    {
        $clean = self::cleanScope( $scope );
        $this->setAttribute( 'scope_json', json_encode( $clean ) );
        $first = $clean['nodes'] ? $clean['nodes'][0] : ( $clean['subtrees'] ? $clean['subtrees'][0] : 0 );
        if ( $this->attribute( 'origin' ) !== self::ORIGIN_PANEL )
            $this->setAttribute( 'node_id', (int) $first );
    }

    /** @return array a scope with valid entries only (ids > 0, identifiers of letters, digits, _ and -), at most 50 each */
    public static function cleanScope( array $scope )
    {
        $ints = function ( $list ) { return array_slice( array_values( array_unique( array_filter( array_map( 'intval', (array) $list ), function ( $v ) { return $v > 0; } ) ) ), 0, 50 ); };
        $ids = function ( $list ) { return array_slice( array_values( array_unique( array_filter( array_map( 'strval', (array) $list ), function ( $v ) { return preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $v ) === 1; } ) ) ), 0, 50 ); };
        return array( 'nodes' => $ints( isset( $scope['nodes'] ) ? $scope['nodes'] : array() ),
                      'subtrees' => $ints( isset( $scope['subtrees'] ) ? $scope['subtrees'] : array() ),
                      'classes' => $ids( isset( $scope['classes'] ) ? $scope['classes'] : array() ),
                      'languages' => $ids( isset( $scope['languages'] ) ? $scope['languages'] : array() ) );
    }

    /**
     * Bulk work: more than one object or more than one language ([VectorSettings] BulkObjectThreshold,
     * BulkLanguageThreshold), or any subtree or class entry. Bulk threads start in plan mode (owner decision 5).
     * @return bool
     */
    public function isBulk( ?expAiConfig $config = null )
    {
        return self::scopeIsBulk( $this->scope(), $config );
    }

    public static function scopeIsBulk( array $scope, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $objects = $config->int( 'VectorSettings', 'BulkObjectThreshold', 1, 1 );
        $languages = $config->int( 'VectorSettings', 'BulkLanguageThreshold', 1, 1 );
        return !empty( $scope['subtrees'] ) || !empty( $scope['classes'] )
            || count( isset( $scope['nodes'] ) ? $scope['nodes'] : array() ) > $objects
            || count( isset( $scope['languages'] ) ? $scope['languages'] : array() ) > $languages;
    }

    /** @return expAiThreadRun|null */
    public function leadRun()
    {
        return expAiThreadRun::fetchByID( (int) $this->attribute( 'lead_run_id' ) );
    }

    /** @return expAiConversation|null the conversation of the lead run */
    public function conversation()
    {
        $run = $this->leadRun();
        if ( !$run || !(int) $run->attribute( 'conversation_id' ) )
            return null;
        $conversation = eZPersistentObject::fetchObject( expAiConversation::definition(), null, array( 'id' => (int) $run->attribute( 'conversation_id' ) ) );
        return $conversation instanceof expAiConversation ? $conversation : null;
    }

    /** @return bool the owner is the user */
    public function isOwner( $userID )
    {
        return (int) $this->attribute( 'user_id' ) === (int) $userID && (int) $userID > 0;
    }

    /**
     * Appends an event to the thread's stream (doc/threads.md "Events"). Payloads over
     * [ThreadSettings] MaxEventPayloadBytes become a structural summary.
     * @return expAiThreadEvent
     */
    public function addEvent( $kind, array $payload = array(), $runID = 0 )
    {
        $event = expAiThreadEvent::append( (int) $this->attribute( 'id' ), $runID ?: (int) $this->attribute( 'lead_run_id' ), $kind, $payload );
        $this->setAttribute( 'last_event_id', (int) $event->attribute( 'id' ) );
        return $event;
    }

    /**
     * Changes the state; a change is an event of kind "status".
     * @return bool changed
     */
    public function setState( $state, array $extra = array() )
    {
        if ( !in_array( $state, self::$states, true ) || $state === $this->attribute( 'state' ) )
            return false;
        $previous = (string) $this->attribute( 'state' );
        $this->setAttribute( 'state', $state );
        $this->addEvent( 'status', array( 'state' => $state, 'from' => $previous ) + $extra );
        return true;
    }

    /** Takes the cost, tokens and requests of the thread from the usage log (rows with its thread_id) */
    public function refreshCost()
    {
        $micros = 0;
        $tokens = 0;
        $requests = 0;
        $rows = eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), null, expAiUsage::requestsOnly( array( 'thread_id' => (int) $this->attribute( 'id' ) ) ),
                                                      array( 'id' => 'asc' ), array( 'offset' => 0, 'length' => 5000 ), false );
        foreach ( (array) $rows as $row )
        {
            $requests++;
            $tokens += (int) $row['input_tokens'] + (int) $row['output_tokens'] + (int) $row['cache_write_tokens'] + (int) $row['cache_read_tokens'];
            $cost = expAiUsage::cost( $row['model'], $row['input_tokens'], $row['output_tokens'], $row['cache_write_tokens'], $row['cache_read_tokens'],
                                      isset( $row['model_alias'] ) ? (string) $row['model_alias'] : '', isset( $row['reported_cost_micros'] ) ? (int) $row['reported_cost_micros'] : 0 );
            if ( $cost !== null )
                $micros += (int) round( $cost * 1000000 );
        }
        // cost_micros is 64 bits wide (expAiSchema::BIGINT)
        $this->setAttribute( 'cost_micros', $micros );
        $this->setAttribute( 'tokens', min( 2147483647, $tokens ) );
        $this->setAttribute( 'requests', $requests );
    }

    /** @return string the thread's cost as money ("$0.06"), '' when costs are switched off */
    public function costText()
    {
        return expAiCost::enabled() ? expAiCost::money( (int) $this->attribute( 'cost_micros' ) / 1000000 ) : '';
    }

    /** @var bool set by the job of an approved plan while it has more turns to run: the thread stays "running" between them */
    public static $holdRunning = false;

    /**
     * The state of a thread that does not work: "review" while staged changes wait (pending, accepted, conflict) or a
     * plan waits for approval, else "idle".
     * @return string
     */
    public function restingState()
    {
        $id = (int) $this->attribute( 'id' );
        $open = eZPersistentObject::count( expAiChange::definition(), array( 'thread_id' => $id, 'decision' => array( array( 'pending', 'accepted', 'partial', 'conflict' ) ) ) );
        if ( $open )
            return 'review';
        $plan = class_exists( 'expAiPlan' ) ? expAiPlan::fetchCurrent( $id ) : null;
        return $plan && $plan->attribute( 'state' ) === 'proposed' ? 'review' : 'idle';
    }

    /**
     * Stores only what a child run changes (its events and the cost), never the state or settings: several workers
     * run the items of a plan at once, and a whole-row store of an object read a moment earlier would put back a
     * state that Stop or the end of the plan just set. The event cursor only moves forward.
     */
    public function storeActivity()
    {
        $id = (int) $this->attribute( 'id' );
        $definition = self::definition();
        eZPersistentObject::updateObjectList( array( 'definition' => $definition,
            'update_fields' => array( 'cost_micros' => (int) $this->attribute( 'cost_micros' ), 'tokens' => (int) $this->attribute( 'tokens' ),
                                      'requests' => (int) $this->attribute( 'requests' ), 'modified' => time() ),
            'conditions' => array( 'id' => $id ) ) );
        // integers only, portable to every engine (eZPersistentObject conditions know no "<")
        $last = (int) $this->attribute( 'last_event_id' );
        eZDB::instance()->query( "UPDATE expai_thread SET last_event_id = $last WHERE id = $id AND last_event_id < $last" );
    }

    /** Marks the thread as touched now */
    public function touch()
    {
        $this->setAttribute( 'modified', time() );
    }

    /**
     * Removes the thread with its runs, events, follow-ups, checkpoints, plans and staged changes. The conversations
     * of its runs stay unless $withConversations (the panel's "New conversation" removes them itself).
     */
    public function removeAll( $withConversations = false )
    {
        $id = (int) $this->attribute( 'id' );
        $db = eZDB::instance();
        $db->begin();
        try
        {
            foreach ( expAiThreadRun::fetchForThread( $id ) as $run )
            {
                if ( $withConversations && (int) $run->attribute( 'conversation_id' ) )
                    eZPersistentObject::removeObject( expAiConversation::definition(), array( 'id' => (int) $run->attribute( 'conversation_id' ) ) );
                $run->remove();
            }
            foreach ( array( 'expai_thread_event', 'expai_followup', 'expai_checkpoint', 'expai_plan', 'expai_change' ) as $table )
                eZPersistentObject::removeObject( expAiThreadRetention::tableDefinition( $table ), array( 'thread_id' => $id ) );
            $this->remove();
            $db->commit();
        }
        catch ( Throwable $e )
        {
            if ( $db->transactionCounter() > 0 )
                $db->rollback();
            throw $e;
        }
    }

    /**
     * The threads of a user.
     * @param array $filters shelf (string or list), state (list), node_id, origin, search (title, at least 2 characters),
     *                       limit (default [ThreadSettings] ListLimit), offset
     * @return expAiThread[] newest first (pinned by position)
     */
    public static function fetchForUser( $userID, array $filters = array() )
    {
        $conds = array( 'user_id' => (int) $userID );
        if ( isset( $filters['shelf'] ) && $filters['shelf'] !== '' )
            $conds['shelf'] = is_array( $filters['shelf'] ) ? array( array_values( $filters['shelf'] ) ) : (string) $filters['shelf'];
        if ( !empty( $filters['state'] ) )
            $conds['state'] = array( array_values( (array) $filters['state'] ) );
        if ( !empty( $filters['node_id'] ) )
            $conds['node_id'] = (int) $filters['node_id'];
        if ( !empty( $filters['origin'] ) )
            $conds['origin'] = (string) $filters['origin'];
        $limit = isset( $filters['limit'] ) ? max( 1, min( 500, (int) $filters['limit'] ) ) : expAiConfig::instance()->int( 'ThreadSettings', 'ListLimit', 100, 1, 500 );
        $offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;
        $search = isset( $filters['search'] ) ? trim( (string) $filters['search'] ) : '';
        if ( mb_strlen( $search, 'UTF-8' ) >= 2 )
        {
            // titles are filtered here (a LIKE across five engines is not portable through eZPersistentObject)
            $all = eZPersistentObject::fetchObjectList( self::definition(), null, $conds, array( 'modified' => 'desc' ), array( 'offset' => 0, 'length' => 1000 ) );
            $needle = mb_strtolower( $search, 'UTF-8' );
            $all = array_values( array_filter( (array) $all, function ( $t ) use ( $needle )
            {
                return mb_strpos( mb_strtolower( (string) $t->attribute( 'title' ), 'UTF-8' ), $needle ) !== false;
            } ) );
            return array_slice( $all, $offset, $limit );
        }
        return (array) eZPersistentObject::fetchObjectList( self::definition(), null, $conds, array( 'modified' => 'desc', 'id' => 'desc' ),
                                                            array( 'offset' => $offset, 'length' => $limit ) );
    }

    /** @return int threads of the user in the shelves (and states) */
    public static function countForUser( $userID, array $filters = array() )
    {
        $conds = array( 'user_id' => (int) $userID );
        if ( !empty( $filters['shelf'] ) )
            $conds['shelf'] = is_array( $filters['shelf'] ) ? array( array_values( $filters['shelf'] ) ) : (string) $filters['shelf'];
        if ( !empty( $filters['state'] ) )
            $conds['state'] = array( array_values( (array) $filters['state'] ) );
        return (int) eZPersistentObject::count( self::definition(), $conds );
    }

    /** @return expAiThread|null the thread of a conversation (through its thread_id) */
    public static function fetchForConversation( $conversation )
    {
        if ( !$conversation instanceof expAiConversation || !self::available() )
            return null;
        $id = (int) $conversation->attribute( 'thread_id' );
        return $id ? self::fetchByID( $id ) : null;
    }

    /* --------------------------------------------------------------- the panel */

    /**
     * The panel started a new conversation on a node: it becomes a thread (origin panel, scope: the node, direct
     * review: small panel edits write drafts at once). Earlier active panel threads of the user on that node are
     * settled.
     * @return expAiThread|null
     */
    public static function forPanelConversation( expAiConversation $conversation, $nodeID, $alias = '' )
    {
        if ( !self::available() )
            return null;
        $userID = (int) $conversation->attribute( 'user_id' );
        foreach ( self::fetchForUser( $userID, array( 'node_id' => (int) $nodeID, 'origin' => self::ORIGIN_PANEL, 'shelf' => 'active', 'limit' => 20 ) ) as $old )
        {
            $old->setAttribute( 'shelf', 'settled' );
            $old->store();
        }
        $thread = self::create( array( 'user_id' => $userID, 'origin' => self::ORIGIN_PANEL, 'node_id' => (int) $nodeID,
                                       'scope' => array( 'nodes' => array( (int) $nodeID ) ), 'run_mode' => 'write', 'review_policy' => 'direct',
                                       'model_alias' => (string) $alias ), $conversation );
        return $thread;
    }

    /**
     * After a step of a conversation that belongs to a thread (panel or Vector): state, title, model, cost and the
     * events of the step.
     * @param expAiConversation $conversation
     * @param array $result the step's result (status, text, steps, exchange) or { status: failed, error }
     * @param expAiDraftLog[] $written drafts written in the step
     */
    public static function afterStep( $conversation, array $result, array $written = array() )
    {
        $thread = self::fetchForConversation( $conversation );
        if ( !$thread )
            return;
        $runID = (int) $conversation->attribute( 'run_id' );
        // a child run of an approved plan (doc/parallel-runs.md): its steps are events of the thread with the run's
        // id; the thread's state is the plan's (running until the last item ended, expAiThreadFanout::settle())
        $child = $runID ? expAiThreadRun::fetchByID( $runID ) : null;
        if ( $child && $child->isChild() )
            return self::afterChildStep( $thread, $child, $conversation, $result, $written );
        // a run that is no longer the lead (the editor switched the model of the turn, expAiSlowModel): a step it
        // still finished changes nothing of the thread
        if ( $child && (int) $thread->attribute( 'lead_run_id' ) !== $runID )
            return;
        $db = eZDB::instance();
        $db->begin();
        try
        {
            if ( (string) $thread->attribute( 'title' ) === '' && !empty( $result['exchange']['user'] ) )
                $thread->setAttribute( 'title', expAiText::cut( expAiText::oneLine( (string) $result['exchange']['user'] ), expAiConfig::instance()->int( 'ThreadSettings', 'TitleLength', 80, 20, 255 ) ) );
            if ( (string) $conversation->attribute( 'model_alias' ) !== '' )
            {
                $thread->setAttribute( 'model_alias', (string) $conversation->attribute( 'model_alias' ) );
                $thread->setAttribute( 'provider', (string) $conversation->attribute( 'provider' ) );
            }
            foreach ( isset( $result['steps'] ) ? (array) $result['steps'] : array() as $step )
                $thread->addEvent( 'step_end', array( 'text' => isset( $step['text'] ) ? (string) $step['text'] : '', 'ok' => !empty( $step['ok'] ),
                                                      'writes' => !empty( $step['writes'] ) ), $runID );
            if ( isset( $result['text'] ) && trim( (string) $result['text'] ) !== '' )
                $thread->addEvent( 'text', array( 'text' => (string) $result['text'] ), $runID );
            foreach ( $written as $log )
                $thread->addEvent( 'draft', array( 'object_id' => (int) $log->attribute( 'contentobject_id' ), 'version' => (int) $log->attribute( 'version' ),
                                                   'language' => (string) $log->attribute( 'language_code' ), 'action' => (string) $log->attribute( 'action' ) ), $runID );
            $before = (int) $thread->attribute( 'cost_micros' );
            $thread->refreshCost();
            if ( (int) $thread->attribute( 'cost_micros' ) !== $before || !empty( $result['steps'] ) || isset( $result['text'] ) )
                $thread->addEvent( 'cost', array( 'micros' => (int) $thread->attribute( 'cost_micros' ), 'tokens' => (int) $thread->attribute( 'tokens' ),
                                                  'requests' => (int) $thread->attribute( 'requests' ) ), $runID );
            $status = isset( $result['status'] ) ? (string) $result['status'] : 'done';
            if ( $status === 'continue' )
                $state = 'running';
            elseif ( $status === 'failed' )
            {
                $state = 'failed';
                $thread->addEvent( 'error', array( 'message' => isset( $result['error'] ) ? expAiText::cut( (string) $result['error'], 500 ) : '' ), $runID );
            }
            elseif ( $status === 'stopped' )
                $state = 'stopped';
            elseif ( self::$holdRunning )
                // a job with more turns to go (the items of an approved plan)
                $state = 'running';
            else
                // the exchange of the turn wrote drafts, staged changes or a plan: they wait for the editor
                $state = $written || !empty( $result['exchange']['drafts'] ) ? 'review' : $thread->restingState();
            $thread->setState( $state );
            // a turn that ended in words: notes sent to steer it that it could not take any more wait as queued
            // follow-ups (doc/parallel-runs.md "Steering")
            if ( $state !== 'running' && class_exists( 'expAiFollowup' ) )
                expAiFollowup::steersToQueue( (int) $thread->attribute( 'id' ) );
            $run = expAiThreadRun::fetchByID( $runID );
            if ( $run )
            {
                $run->setAttribute( 'state', $state );
                $run->setAttribute( 'model_alias', (string) $conversation->attribute( 'model_alias' ) );
                if ( !(int) $run->attribute( 'started' ) )
                    $run->setAttribute( 'started', time() );
                if ( $state !== 'running' )
                    $run->setAttribute( 'finished', time() );
                $run->setAttribute( 'modified', time() );
                $run->store();
            }
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
    }

    /** After a step of a child run: its events, the thread's cost, the run's model and times (not the thread's state) */
    protected static function afterChildStep( expAiThread $thread, expAiThreadRun $run, $conversation, array $result, array $written )
    {
        $runID = (int) $run->attribute( 'id' );
        $db = eZDB::instance();
        $db->begin();
        try
        {
            foreach ( isset( $result['steps'] ) ? (array) $result['steps'] : array() as $step )
                $thread->addEvent( 'step_end', array( 'text' => isset( $step['text'] ) ? (string) $step['text'] : '', 'ok' => !empty( $step['ok'] ),
                                                      'writes' => !empty( $step['writes'] ), 'run_id' => $runID ), $runID );
            if ( isset( $result['text'] ) && trim( (string) $result['text'] ) !== '' )
                $thread->addEvent( 'text', array( 'text' => (string) $result['text'], 'run_id' => $runID ), $runID );
            if ( isset( $result['status'] ) && $result['status'] === 'failed' )
                $thread->addEvent( 'error', array( 'message' => isset( $result['error'] ) ? expAiText::cut( (string) $result['error'], 500 ) : '', 'run_id' => $runID ), $runID );
            foreach ( $written as $log )
                $thread->addEvent( 'draft', array( 'object_id' => (int) $log->attribute( 'contentobject_id' ), 'version' => (int) $log->attribute( 'version' ),
                                                   'language' => (string) $log->attribute( 'language_code' ), 'action' => (string) $log->attribute( 'action' ) ), $runID );
            $thread->refreshCost();
            $thread->addEvent( 'cost', array( 'micros' => (int) $thread->attribute( 'cost_micros' ), 'tokens' => (int) $thread->attribute( 'tokens' ),
                                              'requests' => (int) $thread->attribute( 'requests' ) ), $runID );
            $run->setAttribute( 'model_alias', (string) $conversation->attribute( 'model_alias' ) );
            if ( !(int) $run->attribute( 'started' ) )
                $run->setAttribute( 'started', time() );
            $run->refreshCost();
            $run->setAttribute( 'modified', time() );
            $run->store();
            $thread->storeActivity();
            $db->commit();
        }
        catch ( Throwable $e )
        {
            if ( $db->transactionCounter() > 0 )
                $db->rollback();
            throw $e;
        }
    }

    /** The panel's "New conversation": the panel threads of those conversations go with them */
    public static function removeForPanel( $userID, $nodeID )
    {
        if ( !self::available() )
            return;
        foreach ( self::fetchForUser( $userID, array( 'node_id' => (int) $nodeID, 'origin' => self::ORIGIN_PANEL, 'limit' => 500 ) ) as $thread )
            $thread->removeAll( false );
    }
}
