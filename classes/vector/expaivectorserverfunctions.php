<?php
/**
 * File containing the expAiVectorServerFunctions class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The ezjscore functions of threads, Vector and the keyboard (ezjscore.ini [ezjscServer_expaivector]; the caller needs
 * ezjscore/call with the function list "expai", as for expai::*):
 *
 *   POST <root>/ezjscore/call  ezjscServer_function_arguments=expaivector::<function>, ezxform_token, ...
 *
 *   threads, thread, history, events, nodes, palette, keybindings     (policy expai/write)
 *   bootstrap, thread_save, turn, stop, followup                      (policies expai/write and expai/vector)
 *
 * Every function checks POST, the form token and the policies itself. A thread is read only by its owner and by the
 * approvers of a version it wrote; only the owner changes or runs it (expAiThreadAccess). Failures are translated
 * expAiException messages (error_text). Nothing here is cached.
 */
class expAiVectorServerFunctions extends ezjscServerFunctions
{
    /** @return string */
    protected static function post( $name, $default = '' )
    {
        return isset( $_POST[$name] ) && is_scalar( $_POST[$name] ) ? (string) $_POST[$name] : $default;
    }

    /**
     * POST, form token, expai/write (and expai/vector), the thread tables.
     * @return expAiConfig
     */
    protected static function guard( $vector = false, $needsProvider = false )
    {
        if ( !isset( $_SERVER['REQUEST_METHOD'] ) || strtoupper( $_SERVER['REQUEST_METHOD'] ) !== 'POST' )
            throw ( new expAiException( 'Exponential Intelligence only accepts POST requests.' ) )->translated();
        if ( class_exists( 'ezxFormToken' ) && ezxFormToken::isEnabled() )
        {
            $given = self::post( 'ezxform_token', isset( $_SERVER['HTTP_X_CSRF_TOKEN'] ) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : '' );
            if ( $given === '' || !hash_equals( (string) ezxFormToken::getToken(), $given ) )
                throw ( new expAiException( 'The form token is missing or wrong. Please reload the page.' ) )->translated();
        }
        $config = expAiConfig::instance();
        if ( $needsProvider && !$config->isEnabled() )
            throw ( new expAiException( 'Exponential Intelligence is switched off.' ) )->translated();
        if ( $needsProvider && !$config->isConfigured() )
            throw ( new expAiException( 'Exponential Intelligence is not configured correctly.', 'Model or ApiKey missing' ) )->translated();
        if ( !expAiServerFunctions::hasPolicy( 'write' ) )
            throw ( new expAiException( 'You do not have access to Exponential Intelligence.' ) )->translated();
        if ( $vector && ( !$config->flag( 'VectorSettings', 'Enabled', true ) || !expAiThreadAccess::hasVector() ) )
            throw ( new expAiException( 'You do not have access to Vector.' ) )->translated();
        if ( !expAiThread::available() )
            throw ( new expAiException( 'The thread tables are missing. Please ask an administrator to update the database of Exponential Intelligence.' ) )->translated();
        return $config;
    }

    /**
     * Ends the session lock at once: these functions only read the session (user, form token), and PHP keeps the
     * session locked for the whole request, so a poll or a long foreground turn would otherwise hold up every other
     * request of the same browser (measured: 4-6 s on Apache and on Velocity).
     */
    protected static function releaseSession()
    {
        if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE )
            session_write_close();
    }

    /**
     * The next poll interval in milliseconds: [VectorSettings] PollActiveMs while the open thread runs,
     * PollWorkingMs while another thread works, PollIdleMs otherwise; doubled while the host is busy (load above
     * twice the number of processors). The browser does not poll while its tab is hidden.
     */
    public static function nextPoll( expAiConfig $config, $openRunning, $otherRunning )
    {
        if ( $openRunning )
            $ms = $config->int( 'VectorSettings', 'PollActiveMs', 1000, 500, 60000 );
        elseif ( $otherRunning )
            $ms = $config->int( 'VectorSettings', 'PollWorkingMs', 3000, 500, 60000 );
        else
            $ms = $config->int( 'VectorSettings', 'PollIdleMs', 15000, 1000, 300000 );
        if ( function_exists( 'sys_getloadavg' ) )
        {
            $load = sys_getloadavg();
            $cpus = 0;
            if ( is_readable( '/proc/cpuinfo' ) )
                $cpus = substr_count( (string) @file_get_contents( '/proc/cpuinfo' ), "\nprocessor" ) + 1;
            if ( $load && $cpus > 0 && $load[0] > $cpus * 2 )
                $ms *= 2;
        }
        return $ms;
    }

    protected static function run( $fn )
    {
        try
        {
            return $fn();
        }
        catch ( expAiException $e )
        {
            throw $e->translated();
        }
    }

    /** @return int */
    protected static function userID()
    {
        return (int) eZUser::currentUser()->attribute( 'contentobject_id' );
    }

    /**
     * The thread named by POST "thread" (a UUID) that the user may read; $write: that the user owns.
     * @return expAiThread
     */
    protected static function loadThread( $write = false )
    {
        $thread = expAiThread::fetchByUuid( self::post( 'thread' ) );
        // the same answer for "missing" and "not yours": another editor's thread ids reveal nothing
        if ( !$thread || !expAiThreadAccess::canRead( $thread ) || ( $write && !expAiThreadAccess::canWrite( $thread ) ) )
            throw new expAiException( 'This thread does not exist or you may not open it.' );
        return $thread;
    }

    /** @return array a JSON list from POST */
    protected static function postJson( $name )
    {
        $data = json_decode( self::post( $name, '' ), true );
        return is_array( $data ) ? $data : array();
    }

    /* ------------------------------------------------------------------ lists */

    /**
     * threads: the user's threads. shelf=active (default: active and pinned) | settled | archived, search (title, at
     * least 2 characters), node (threads on that node). Also the threads shared for approval and the counts.
     */
    public static function threads( $args )
    {
        self::guard();
        self::releaseSession();
        return self::run( function ()
        {
            $userID = self::userID();
            $shelf = self::post( 'shelf', 'active' );
            $filters = array( 'shelf' => $shelf === 'settled' || $shelf === 'archived' ? $shelf : array( 'active', 'pinned' ),
                              'search' => mb_substr( self::post( 'search' ), 0, 100, 'UTF-8' ) );
            if ( (int) self::post( 'node', '0' ) > 0 )
                $filters['node_id'] = (int) self::post( 'node' );
            if ( (int) self::post( 'limit', '0' ) > 0 )
                $filters['limit'] = (int) self::post( 'limit' );
            $rows = array();
            foreach ( expAiThread::fetchForUser( $userID, $filters ) as $thread )
                $rows[] = expAiVector::listRow( $thread, $userID );
            $shared = array();
            if ( self::post( 'shared', '1' ) === '1' && empty( $filters['node_id'] ) )
                foreach ( expAiThreadAccess::sharedWithMe() as $thread )
                    $shared[] = expAiVector::listRow( $thread, $userID );
            return array( 'threads' => $rows, 'shared' => $shared,
                          'counts' => array( 'settled' => expAiThread::countForUser( $userID, array( 'shelf' => 'settled' ) ),
                                             'archived' => expAiThread::countForUser( $userID, array( 'shelf' => 'archived' ) ) ),
                          'has_vector' => expAiVector::allowed() );
        } );
    }

    /** thread: one thread (header, scope, last exchanges, drafts, follow-ups, cost); the owner's unread mark is cleared */
    public static function thread( $args )
    {
        $config = self::guard();
        self::releaseSession();
        return self::run( function () use ( $config )
        {
            $thread = self::loadThread();
            $userID = self::userID();
            $conversation = $thread->conversation();
            $history = array( 'exchanges' => array(), 'has_older' => false );
            if ( $conversation )
                $history = expAiVector::visibleHistory( ( new expAiAssistant( $config ) )->useConversation( $conversation )
                    ->history( eZUser::currentUser(), (int) $thread->attribute( 'node_id' ) ), $thread->isOwner( $userID ) );
            if ( $thread->isOwner( $userID ) && (int) $thread->attribute( 'seen_event_id' ) < (int) $thread->attribute( 'last_event_id' ) )
            {
                eZPersistentObject::updateObjectList( array( 'definition' => expAiThread::definition(),
                                                             'update_fields' => array( 'seen_event_id' => (int) $thread->attribute( 'last_event_id' ) ),
                                                             'conditions' => array( 'id' => (int) $thread->attribute( 'id' ) ) ) );
                $thread->setAttribute( 'seen_event_id', (int) $thread->attribute( 'last_event_id' ) );
            }
            $followups = expAiVector::visibleFollowups( expAiFollowup::waiting( (int) $thread->attribute( 'id' ) ), $thread->isOwner( $userID ) );
            return array( 'thread' => expAiVector::detail( $thread, $userID, $config ), 'history' => $history,
                          'drafts' => expAiVector::drafts( $thread ), 'followups' => $followups['list'], 'followups_waiting' => $followups['count'],
                          'pending' => $conversation ? $conversation->awaitsAnswer() : false,
                          'lanes' => expAiVector::lanes( (string) $thread->attribute( 'model_alias' ) ?: $config->effectiveModel(), $config ) );
        } );
    }

    /** history: older exchanges of a thread (thread, before) */
    public static function history( $args )
    {
        $config = self::guard();
        self::releaseSession();
        return self::run( function () use ( $config )
        {
            $thread = self::loadThread();
            $conversation = $thread->conversation();
            if ( !$conversation )
                return array( 'exchanges' => array(), 'has_older' => false );
            $before = (int) self::post( 'before', '0' );
            return expAiVector::visibleHistory( ( new expAiAssistant( $config ) )->useConversation( $conversation )
                ->history( eZUser::currentUser(), (int) $thread->attribute( 'node_id' ), $before > 0 ? $before : null ), $thread->isOwner( self::userID() ) );
        } );
    }

    /**
     * events: the events of threads after a cursor. threads=uuid,uuid (at most 20), after={"<uuid>": <event id>}.
     * Read only; the answer suggests the next poll interval (next_ms). Streaming of steps (background runs) builds on
     * this; foreground runs return their steps in the turn's answer as well.
     */
    public static function events( $args )
    {
        $config = self::guard();
        self::releaseSession();
        return self::run( function () use ( $config )
        {
            $after = self::postJson( 'after' );
            $out = array( 'threads' => array(), 'events' => array() );
            $openRunning = false;
            $running = false;
            // open=<uuid>: the thread the editor looks at (the fast interval applies to it alone)
            $open = self::post( 'open' );
            foreach ( array_slice( array_filter( explode( ',', self::post( 'threads' ) ) ), 0, 20 ) as $uuid )
            {
                $thread = expAiThread::fetchByUuid( $uuid );
                if ( !$thread || !expAiThreadAccess::canRead( $thread ) )
                    continue;
                // a background turn whose job ended without an answer: the thread says so
                if ( expAiThreadRunner::reconcile( $thread ) )
                    $thread = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
                // the items of an approved plan whose jobs ended without finishing (doc/parallel-runs.md); looked at only
                // when no item reported for 20 seconds (a working item touches the thread with every step)
                if ( $thread->attribute( 'state' ) === 'running' && (int) $thread->attribute( 'plan_id' ) && (int) $thread->attribute( 'modified' ) < time() - 20
                     && expAiThreadFanout::reconcile( $thread ) )
                    $thread = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
                $cursor = isset( $after[$uuid] ) ? (int) $after[$uuid] : 0;
                $out['threads'][] = array( 'uuid' => $uuid, 'state' => (string) $thread->attribute( 'state' ), 'last_event_id' => (int) $thread->attribute( 'last_event_id' ),
                                           'cost' => $thread->costText(), 'modified' => (int) $thread->attribute( 'modified' ),
                                           'progress' => expAiVector::progressText( $thread ),
                                           // a turn on a slow model: its model, time per step, step, since; the switch target
                                           'slow' => $thread->isOwner( self::userID() ) ? expAiSlowModel::status( $thread, $config ) : null,
                                           // "Watch this run" of the open thread: its state and step, with the links to its live record
                                           'watch' => $uuid === $open ? expAiTurnRecord::watch( $thread ) : null );
                if ( in_array( $thread->attribute( 'state' ), array( 'queued', 'running' ), true ) )
                {
                    if ( $uuid === $open )
                        $openRunning = true;
                    else
                        $running = true;
                }
                if ( $cursor < (int) $thread->attribute( 'last_event_id' ) )
                    foreach ( expAiThreadEvent::fetchAfter( (int) $thread->attribute( 'id' ), $cursor, 200 ) as $event )
                        $out['events'][] = array( 'thread' => $uuid ) + expAiVector::visibleEvent( $event->present(), $thread->isOwner( self::userID() ) );
            }
            $out['next_ms'] = self::nextPoll( $config, $openRunning, $running );
            return $out;
        } );
    }

    /**
     * nodes: content the user may read whose name contains q (at least 2 characters), or the node with the id q. For
     * the scope picker and the palette's "Jump to node". At most 12.
     */
    public static function nodes( $args )
    {
        self::guard();
        self::releaseSession();
        return self::run( function ()
        {
            $q = trim( mb_substr( self::post( 'q' ), 0, 100, 'UTF-8' ) );
            $found = array();
            if ( ctype_digit( $q ) )
            {
                $node = eZContentObjectTreeNode::fetch( (int) $q );
                if ( $node instanceof eZContentObjectTreeNode && $node->canRead() )
                    $found[] = $node;
            }
            if ( mb_strlen( $q, 'UTF-8' ) >= 2 )
            {
                $like = str_replace( array( '*', '%', '_' ), ' ', $q );
                $list = eZContentObjectTreeNode::subTreeByNodeID( array( 'AttributeFilter' => array( array( 'name', 'like', '*' . $like . '*' ) ),
                                                                         'Limit' => 12, 'SortBy' => array( 'modified', false ), 'IgnoreVisibility' => true ), 1 );
                foreach ( (array) $list as $node )
                    $found[] = $node;
            }
            $out = array();
            $seen = array();
            foreach ( $found as $node )
            {
                $id = (int) $node->attribute( 'node_id' );
                if ( isset( $seen[$id] ) || count( $out ) >= 12 )
                    continue;
                $seen[$id] = true;
                $parent = $node->fetchParent();
                $out[] = array( 'id' => $id, 'name' => (string) $node->attribute( 'name' ), 'class' => (string) $node->attribute( 'class_name' ),
                                'parent' => $parent && $parent->canRead() ? (string) $parent->attribute( 'name' ) : '',
                                'url' => 'content/view/full/' . $id );
            }
            return array( 'nodes' => $out );
        } );
    }

    /**
     * palette: what the command palette needs on any admin page (it is loaded on the first Ctrl/Cmd+K): its script,
     * texts, the user's key bindings, the models, and whether Vector is open to the user.
     */
    public static function palette( $args )
    {
        $config = self::guard();
        self::releaseSession();
        return self::run( function () use ( $config )
        {
            $scripts = array();
            try
            {
                // ezjscore.ini [eZJSCore] Packer is optional (ezjscPacker applies it itself when it is set)
                $ezjscINI = eZINI::instance( 'ezjscore.ini' );
                $packLevel = $ezjscINI->hasVariable( 'eZJSCore', 'Packer' ) ? (int) $ezjscINI->variable( 'eZJSCore', 'Packer' ) : 0;
                $scripts = ezjscPacker::buildJavascriptFiles( array( 'expai_palette.js' ), $packLevel );
            }
            catch ( Exception $e )
            {
                eZDebug::writeWarning( 'expai: the palette script could not be packed: ' . $e->getMessage(), __METHOD__ );
            }
            return array( 'scripts' => array_values( (array) $scripts ), 'strings' => expAiVectorStrings::palette(), 'labels' => expAiVectorStrings::commands(),
                          'bindings' => expAiVector::bindings(), 'has_vector' => expAiVector::allowed(),
                          'models' => $config->modelChoiceEnabled() ? expAiVector::models( $config ) : null,
                          'user_overrides' => $config->flag( 'KeyBindings', 'UserOverrides', true ),
                          'global' => $config->flag( 'KeyBindings', 'GlobalPalette', true ) );
        } );
    }

    /** keybindings: bindings={"command": "keys", ...} stores the user's shortcuts ('' resets one), reset=1 resets all */
    public static function keybindings( $args )
    {
        self::guard();
        return self::run( function ()
        {
            if ( self::post( 'reset' ) === '1' )
            {
                eZPreferences::setValue( 'expai_keybindings', '' );
                return array( 'bindings' => expAiVector::bindings() );
            }
            return array( 'bindings' => expAiVector::saveBindings( self::postJson( 'bindings' ) ) );
        } );
    }

    /* ----------------------------------------------------------------- Vector */

    /** bootstrap: the choices of the Vector header: models, classes, languages, scope kinds and review policies allowed */
    public static function bootstrap( $args )
    {
        $config = self::guard( true );
        self::releaseSession();
        return self::run( function () use ( $config )
        {
            return array( 'models' => expAiVector::models( $config ), 'classes' => expAiVector::classes(), 'languages' => expAiVector::languages(),
                          'choices' => expAiThreadAccess::choices(), 'bindings' => expAiVector::bindings(), 'labels' => expAiVectorStrings::commands(),
                          'lanes' => expAiVector::lanes( $config->effectiveModel(), $config ),
                          'model_choice' => $config->modelChoiceEnabled(),
                          'defaults' => array( 'run_mode' => $config->get( 'VectorSettings', 'DefaultMode', 'write' ) === 'plan' ? 'plan' : 'write',
                                               'review_policy' => $config->get( 'VectorSettings', 'DefaultReview', 'staged' ) === 'direct' ? 'direct' : 'staged' ),
                          'transport' => expAiVectorStream::transport( $config ), 'poll' => array( 'active' => self::nextPoll( $config, true, false ), 'working' => self::nextPoll( $config, false, true ),
                                           'idle' => self::nextPoll( $config, false, false ) ) );
        } );
    }

    /**
     * thread_save: creates a thread (no "thread") or changes one of the user's: title, scope (JSON), run_mode, review_policy,
     * model_alias, lane, shelf, position. A new thread takes its scope from "node" when no scope is given. Rules: the
     * scope must pass expai/vector (Subtree, Section, Scope) and be readable; bulk scopes run in plan mode until a plan
     * is approved; direct review only for one object in one language.
     */
    public static function thread_save( $args )
    {
        $config = self::guard( true );
        self::releaseSession();
        return self::run( function () use ( $config )
        {
            $userID = self::userID();
            $isNew = self::post( 'thread' ) === '';
            if ( $isNew )
            {
                $scope = self::postJson( 'scope' );
                if ( !$scope && (int) self::post( 'node', '0' ) > 0 )
                    $scope = array( 'nodes' => array( (int) self::post( 'node' ) ) );
                $scope = expAiThread::cleanScope( $scope );
                $bulk = expAiThread::scopeIsBulk( $scope, $config );
                $review = $config->get( 'VectorSettings', 'DefaultReview', 'staged' ) === 'direct' && !$bulk ? 'direct' : 'staged';
                if ( !in_array( $review, expAiThreadAccess::choices()['review'], true ) )
                    $review = expAiThreadAccess::choices()['review'] ? expAiThreadAccess::choices()['review'][0] : 'staged';
                $reason = expAiThreadAccess::check( $scope, $review );
                if ( $reason !== '' )
                    throw new expAiException( $reason );
                $mode = $bulk || $config->get( 'VectorSettings', 'DefaultMode', 'write' ) === 'plan' ? 'plan' : 'write';
                $thread = expAiThread::create( array( 'user_id' => $userID, 'origin' => expAiThread::ORIGIN_VECTOR, 'scope' => $scope,
                                                      'run_mode' => $mode, 'review_policy' => $review, 'model_alias' => $config->effectiveModel(),
                                                      'lane' => 'auto', 'title' => mb_substr( trim( self::post( 'title' ) ), 0, 255, 'UTF-8' ) ) );
                return array( 'thread' => expAiVector::detail( $thread, $userID, $config ), 'created' => true );
            }
            $thread = self::loadThread( true );
            $notes = array();
            $scope = $thread->scope();
            if ( isset( $_POST['scope'] ) )
                $scope = expAiThread::cleanScope( self::postJson( 'scope' ) );
            $review = isset( $_POST['review_policy'] ) && in_array( self::post( 'review_policy' ), array( 'staged', 'direct' ), true )
                    ? self::post( 'review_policy' ) : (string) $thread->attribute( 'review_policy' );
            $bulk = expAiThread::scopeIsBulk( $scope, $config );
            if ( $review === 'direct' && $bulk && $thread->attribute( 'origin' ) !== expAiThread::ORIGIN_PANEL )
            {
                $review = 'staged';
                $notes[] = ezpI18n::tr( 'extension/expai/vector', 'Bulk work is always reviewed before it is written.' );
            }
            if ( isset( $_POST['scope'] ) || isset( $_POST['review_policy'] ) )
            {
                $reason = expAiThreadAccess::check( $scope, $review );
                if ( $reason !== '' )
                    throw new expAiException( $reason );
                $thread->setScope( $scope );
                $thread->setAttribute( 'review_policy', $review );
            }
            $mode = isset( $_POST['run_mode'] ) && in_array( self::post( 'run_mode' ), array( 'plan', 'write' ), true ) ? self::post( 'run_mode' ) : (string) $thread->attribute( 'run_mode' );
            if ( $mode === 'write' && $bulk && !(int) $thread->attribute( 'plan_id' ) )
            {
                $mode = 'plan';
                $notes[] = ezpI18n::tr( 'extension/expai/vector', 'Bulk work starts with a plan: approve it before anything is written.' );
            }
            $thread->setAttribute( 'run_mode', $mode );
            if ( isset( $_POST['model_alias'] ) )
            {
                $alias = $config->registry()->resolveAlias( self::post( 'model_alias' ) ) ?: self::post( 'model_alias' );
                if ( !expAiVector::mayUseModel( $alias, $config ) )
                    throw new expAiException( 'This model is not available.' );
                $thread->setAttribute( 'model_alias', $alias );
                $model = $config->registry()->getModel( $alias );
                $thread->setAttribute( 'provider', $model ? (string) $model['provider'] : '' );
            }
            if ( isset( $_POST['lane'] ) )
            {
                $lane = self::post( 'lane' );
                $names = array_map( function ( $l ) { return $l['name']; }, expAiVector::lanes( (string) $thread->attribute( 'model_alias' ) ?: $config->effectiveModel(), $config ) );
                if ( !in_array( $lane, $names, true ) )
                    throw new expAiException( 'This lane is not available for the model.' );
                $thread->setAttribute( 'lane', $lane );
            }
            if ( isset( $_POST['title'] ) )
                $thread->setAttribute( 'title', mb_substr( trim( self::post( 'title' ) ), 0, 255, 'UTF-8' ) );
            if ( isset( $_POST['shelf'] ) && in_array( self::post( 'shelf' ), expAiThread::$shelves, true ) )
                $thread->setAttribute( 'shelf', self::post( 'shelf' ) );
            if ( isset( $_POST['position'] ) )
                $thread->setAttribute( 'position', max( 0, min( 1000000, (int) self::post( 'position' ) ) ) );
            $thread->touch();
            $thread->store();
            return array( 'thread' => expAiVector::detail( $thread, $userID, $config ), 'notes' => $notes,
                          'lanes' => expAiVector::lanes( (string) $thread->attribute( 'model_alias' ) ?: $config->effectiveModel(), $config ) );
        } );
    }

    /**
     * turn: one step of the thread's lead run in foreground mode (the browser calls the next step while status is
     * "continue", as the panel does): thread, message (a new message) or continue=1. Runs as the thread's owner with
     * the thread's model. Write tools only in write mode with direct review on one object in one language; plan mode
     * and staged review read only. Background runs in the queue replace this for bulk work.
     */
    public static function turn( $args )
    {
        $config = self::guard( true, true );
        self::releaseSession();
        return self::run( function () use ( $config )
        {
            $thread = self::loadThread( true );
            $scope = $thread->scope();
            $review = (string) $thread->attribute( 'review_policy' );
            $reason = expAiThreadAccess::check( $scope, $review );
            if ( $reason !== '' )
                throw new expAiException( $reason );
            $nodeID = $scope['nodes'] ? $scope['nodes'][0] : ( $scope['subtrees'] ? $scope['subtrees'][0] : 0 );
            if ( !$nodeID )
                throw new expAiException( 'Choose the scope first: a node or a subtree.' );
            $alias = (string) $thread->attribute( 'model_alias' );
            if ( $alias !== '' && !expAiVector::mayUseModel( $alias, $config ) )
                throw new expAiException( 'The model of this thread is no longer available to you. Choose another model.' );
            $bulk = $thread->isBulk( $config );
            $mode = (string) $thread->attribute( 'run_mode' );
            // bulk work without an approved plan runs in plan mode (read tools and propose_plan); staged review: the
            // write tools stage proposals for the Changes tab (doc/review.md)
            if ( $bulk && !expAiChangeStager::approvedPlan( $thread ) && $thread->attribute( 'origin' ) !== expAiThread::ORIGIN_PANEL )
                $mode = 'plan';
            $readOnly = $mode === 'plan';
            $staged = !$readOnly && $review === 'staged' && $thread->attribute( 'origin' ) !== expAiThread::ORIGIN_PANEL;
            $conversation = $thread->conversation();
            if ( !$conversation )
                throw new expAiException( 'This thread has no conversation any more.' );
            $settings = $config->providerSettings();
            $timeout = max( 1, min( 600, (int) ( isset( $settings['Timeout'] ) && $settings['Timeout'] !== '' ? $settings['Timeout'] : $config->int( 'AISettings', 'Timeout', 115, 1, 600 ) ) ) );
            if ( function_exists( 'set_time_limit' ) )
                @set_time_limit( $timeout + 30 );
            ignore_user_abort( true );
            // a settled or archived thread that gets a new message is active again
            if ( self::post( 'continue' ) !== '1' && in_array( $thread->attribute( 'shelf' ), array( 'settled', 'archived' ), true ) )
            {
                $thread->setAttribute( 'shelf', 'active' );
                $thread->store();
            }
            $options = array( 'alias' => $alias, 'read_only' => $readOnly, 'staged' => $staged, 'mode' => $mode,
                'scope_note' => $thread->attribute( 'origin' ) === expAiThread::ORIGIN_PANEL ? '' : expAiThreadPrompts::scopeNote( $scope ),
                'action' => $thread->attribute( 'origin' ) === expAiThread::ORIGIN_PANEL ? 'panel' : 'vector' );
            // a checkpoint at the start of every turn with a new message (doc/checkpoints.md)
            if ( self::post( 'continue' ) !== '1' && trim( self::post( 'message' ) ) !== '' )
                expAiCheckpoint::write( $thread, expAiCheckpoint::labelFor( trim( self::post( 'message' ) ) ), expAiCheckpoint::KIND_TURN, $config );
            // a slow model: the turn runs in the queue; the browser follows its events
            if ( expAiThreadRunner::inBackground( $alias, $config ) )
            {
                $message = self::post( 'continue' ) === '1' ? '' : trim( self::post( 'message' ) );
                if ( $message === '' && !$conversation->awaitsAnswer() )
                    throw new expAiException( 'Please write a message.' );
                $job = expAiThreadRunner::enqueue( $thread, $nodeID, $message, $options );
                $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ?: $thread;
                return array( 'status' => 'queued', 'job' => (string) $job->attribute( 'uuid' ), 'steps' => array(), 'text' => '',
                              'thread' => expAiVector::detail( $fresh, self::userID(), $config ), 'read_only' => $readOnly, 'watch' => expAiTurnRecord::watch( $fresh ) );
            }
            if ( self::post( 'continue' ) !== '1' )
            {
                $thread->setState( 'running' );
                $thread->touch();
                $thread->store();
            }
            $assistant = ( new expAiAssistant( $config ) )->useConversation( $conversation, $options );
            $result = $assistant->step( $nodeID, self::post( 'continue' ) === '1' ? null : self::post( 'message' ) );
            $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) );
            // an approved plan run in the browser: done when its last item (a queued follow-up) has had its turn
            if ( $fresh && ( !isset( $result['status'] ) || $result['status'] !== 'continue' ) && !expAiFollowup::waiting( (int) $fresh->attribute( 'id' ) ) )
            {
                $plan = expAiPlan::fetchByID( (int) $fresh->attribute( 'plan_id' ) );
                if ( $plan && $plan->attribute( 'state' ) === 'running' )
                    expAiPlanner::complete( $fresh );
            }
            $result['thread'] = expAiVector::detail( $fresh ?: $thread, self::userID(), $config );
            $result['drafts'] = expAiVector::drafts( $fresh ?: $thread );
            $result['read_only'] = $readOnly;
            // the queue after the step: notes that steered it are gone, a note the turn could not take is queued
            $result['followups'] = array_map( function ( $f ) { return $f->present(); }, expAiFollowup::waiting( (int) $thread->attribute( 'id' ) ) );
            return $result;
        } );
    }

    /**
     * stop: stops the thread's runs. In foreground mode the browser stops calling the next step; the state says
     * "stopped". (Background runs: honored at the next call boundary.)
     */
    public static function stop( $args )
    {
        $config = self::guard( true );
        self::releaseSession();
        return self::run( function () use ( $config )
        {
            $thread = self::loadThread( true );
            if ( in_array( $thread->attribute( 'state' ), array( 'queued', 'running', 'waiting_input' ), true ) )
            {
                // a background turn: its job is cancelled (a running one stops after the current step); the items of
                // an approved plan: their job group (doc/parallel-runs.md)
                expAiThreadRunner::cancel( $thread );
                expAiThreadFanout::cancel( $thread );
                $thread = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ?: $thread;
                $thread->setState( 'stopped' );
                $run = $thread->leadRun();
                if ( $run )
                {
                    $run->setAttribute( 'state', 'stopped' );
                    $run->setAttribute( 'finished', time() );
                    $run->store();
                }
                $thread->touch();
                $thread->store();
            }
            return array( 'thread' => expAiVector::detail( $thread, self::userID(), $config ) );
        } );
    }

    /**
     * followup: messages queued while a turn runs. op=add (body, delivery=queue|steer), drop (id), take (the first
     * waiting one, marked sent: the browser sends it as the next message), list.
     */
    public static function followup( $args )
    {
        $config = self::guard( true );
        self::releaseSession();
        return self::run( function () use ( $config )
        {
            $thread = self::loadThread( true );
            $threadID = (int) $thread->attribute( 'id' );
            $op = self::post( 'op', 'list' );
            $taken = null;
            if ( $op === 'add' )
            {
                $body = trim( self::post( 'body' ) );
                $max = $config->int( 'AssistantSettings', 'MaxMessageLength', 4000, 10 );
                if ( $body === '' )
                    throw new expAiException( 'Please write a message.' );
                if ( mb_strlen( $body, 'UTF-8' ) > $max )
                    throw new expAiException( 'The message is too long (at most %max characters).', '', 0, array( '%max' => $max ) );
                $waiting = expAiFollowup::waiting( $threadID );
                if ( count( $waiting ) >= 20 )
                    throw new expAiException( 'At most 20 messages can wait.' );
                $now = time();
                $followup = new expAiFollowup( array( 'thread_id' => $threadID, 'body' => $body, 'position' => count( $waiting ) ? (int) end( $waiting )->attribute( 'position' ) + 1 : 1,
                                                      'delivery' => self::post( 'delivery' ) === 'steer' ? 'steer' : 'queue', 'state' => 'waiting',
                                                      'created' => $now, 'modified' => $now ) );
                $followup->store();
                $thread->addEvent( 'followup', array( 'op' => 'add', 'id' => (int) $followup->attribute( 'id' ) ) );
                $thread->store();
            }
            elseif ( $op === 'drop' || $op === 'take' )
            {
                $followup = $op === 'take' ? current( expAiFollowup::waiting( $threadID ) ) : expAiFollowup::fetchByID( (int) self::post( 'id' ) );
                if ( $followup && (int) $followup->attribute( 'thread_id' ) === $threadID && $followup->attribute( 'state' ) === 'waiting' )
                {
                    $followup->setAttribute( 'state', $op === 'take' ? 'sent' : 'dropped' );
                    $followup->setAttribute( 'modified', time() );
                    $followup->store();
                    if ( $op === 'take' )
                        $taken = $followup->present();
                }
            }
            $list = array();
            foreach ( expAiFollowup::waiting( $threadID ) as $followup )
                $list[] = $followup->present();
            return array( 'followups' => $list, 'taken' => $taken );
        } );
    }

    /* -------------------------------------------- staged review (doc/review.md) */

    /** @return array the Changes tab: groups, counts, whether the user may decide and apply */
    protected static function changesAnswer( expAiThread $thread, array $extra = array() )
    {
        $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ?: $thread;
        $groups = expAiVector::changeGroups( $fresh, $withheld, self::userID() );
        return $extra + array( 'groups' => $groups, 'withheld' => $withheld, 'counts' => expAiChange::counts( (int) $fresh->attribute( 'id' ) ),
                               'can_decide' => expAiThreadAccess::canWrite( $fresh ) && expAiVector::allowed(),
                               'thread' => expAiVector::detail( $fresh, self::userID() ), 'drafts' => expAiVector::drafts( $fresh ) );
    }

    /** @return expAiChange a change of the thread named by POST "change" */
    protected static function loadChange( expAiThread $thread, $id = null )
    {
        $change = expAiChange::fetchByID( $id === null ? (int) self::post( 'change' ) : (int) $id );
        if ( !$change || (int) $change->attribute( 'thread_id' ) !== (int) $thread->attribute( 'id' ) )
            throw new expAiException( 'This change does not exist any more.' );
        return $change;
    }

    /** changes: the staged changes of a thread (thread), grouped by object and language; readable by the approvers too */
    public static function changes( $args )
    {
        self::guard();
        self::releaseSession();
        return self::run( function ()
        {
            return self::changesAnswer( self::loadThread() );
        } );
    }

    /**
     * change_decide: decisions={"list": [{change, hunk ('' = the whole field), decision (accepted|rejected|pending)}]}
     * or all=accepted|rejected (every open hunk of the thread). Applied, stale and conflicting changes keep their state
     * (a conflict is resolved with change_restage, rebase or "keep current" = rejected for the field).
     */
    public static function change_decide( $args )
    {
        self::guard( true );
        self::releaseSession();
        return self::run( function ()
        {
            $thread = self::loadThread( true );
            $userID = self::userID();
            $n = 0;
            $db = eZDB::instance();
            $db->begin();
            try
            {
                $all = self::post( 'all' );
                if ( in_array( $all, array( 'accepted', 'rejected' ), true ) )
                {
                    foreach ( expAiChange::fetchForThread( (int) $thread->attribute( 'id' ), array( 'pending', 'accepted', 'rejected', 'partial' ) ) as $change )
                    {
                        $n += $change->decide( '', $all, $userID );
                        $change->store();
                    }
                }
                else
                {
                    $list = self::postJson( 'decisions' );
                    foreach ( array_slice( isset( $list['list'] ) && is_array( $list['list'] ) ? $list['list'] : array(), 0, 500 ) as $d )
                    {
                        if ( !is_array( $d ) || !isset( $d['change'], $d['decision'] ) )
                            continue;
                        $change = self::loadChange( $thread, (int) $d['change'] );
                        if ( in_array( $change->attribute( 'decision' ), array( 'applied', 'stale' ), true ) )
                            continue;
                        $n += $change->decide( isset( $d['hunk'] ) ? (string) $d['hunk'] : '', (string) $d['decision'], $userID );
                        $change->store();
                    }
                }
                // the editor's own decisions are no event: the answer carries them, and an event would make every open
                // browser of the thread reload it
                if ( $n )
                {
                    $thread->touch();
                    $thread->store();
                }
                $db->commit();
            }
            catch ( Throwable $e )
            {
                if ( $db->transactionCounter() > 0 )
                    $db->rollback();
                throw $e;
            }
            return self::changesAnswer( $thread, array( 'decided' => $n ) );
        } );
    }

    /** change_edit: the editor rewrites the proposed side of a hunk (change, hunk, text; plain text fields only) */
    public static function change_edit( $args )
    {
        $config = self::guard( true );
        self::releaseSession();
        return self::run( function () use ( $config )
        {
            $thread = self::loadThread( true );
            $text = self::post( 'text' );
            if ( mb_strlen( $text, 'UTF-8' ) > $config->int( 'VectorSettings', 'MaxEditLength', 20000, 100 ) )
                throw new expAiException( 'The text is too long.' );
            expAiChangeStager::editHunk( self::loadChange( $thread ), self::post( 'hunk' ), $text );
            return self::changesAnswer( $thread );
        } );
    }

    /** change_restage: a change whose base changed is compared again with the current version (change) */
    public static function change_restage( $args )
    {
        self::guard( true );
        self::releaseSession();
        return self::run( function ()
        {
            $thread = self::loadThread( true );
            expAiChangeStager::restage( self::loadChange( $thread ) );
            return self::changesAnswer( $thread );
        } );
    }

    /**
     * rebase: changes whose base changed (changes=[ids], default all conflicts) are set aside (stale) and the answer
     * holds the message that asks the model to stage them again against the current version; the browser sends it as
     * the next turn.
     */
    public static function rebase( $args )
    {
        self::guard( true );
        self::releaseSession();
        return self::run( function ()
        {
            $thread = self::loadThread( true );
            $ids = array_map( 'intval', self::postJson( 'changes' ) );
            $lines = array();
            $db = eZDB::instance();
            $db->begin();
            try
            {
                foreach ( expAiChange::fetchForThread( (int) $thread->attribute( 'id' ), array( 'pending', 'accepted', 'partial', 'conflict' ) ) as $change )
                {
                    if ( $ids ? !in_array( (int) $change->attribute( 'id' ), $ids, true ) : $change->attribute( 'decision' ) !== 'conflict' && !$change->baseChanged() )
                        continue;
                    if ( $change->isNew() )
                        continue;
                    $key = (int) $change->attribute( 'contentobject_id' ) . ' (' . $change->attribute( 'language_code' ) . ')';
                    $lines[$key][] = (string) $change->attribute( 'attribute_identifier' );
                    $change->setAttribute( 'decision', 'stale' );
                    $change->setAttribute( 'modified', time() );
                    $change->store();
                }
                $db->commit();
            }
            catch ( Throwable $e )
            {
                if ( $db->transactionCounter() > 0 )
                    $db->rollback();
                throw $e;
            }
            if ( !$lines )
                throw new expAiException( 'There is nothing to rebase.' );
            $parts = array();
            foreach ( $lines as $key => $fields )
                $parts[] = 'object ' . $key . ': ' . implode( ', ', array_unique( $fields ) );
            $message = 'The content changed since you staged these changes: ' . implode( '; ', $parts ) . '. Read the current version of each object again '
                     . 'and stage your changes once more with update_draft, keeping what the editor changed meanwhile. Then answer in one sentence.';
            return self::changesAnswer( $thread, array( 'message' => $message ) );
        } );
    }

    /**
     * apply: writes the accepted parts of the thread's changes into drafts (changes=[ids], default every change with
     * accepted hunks). Never publishes. Conflicts are reported, not written.
     */
    public static function apply( $args )
    {
        self::guard( true );
        self::releaseSession();
        return self::run( function ()
        {
            $thread = self::loadThread( true );
            // a checkpoint before the accepted changes are written (doc/checkpoints.md)
            expAiCheckpoint::write( $thread, ezpI18n::tr( 'extension/expai/vector', 'Before writing accepted changes to drafts' ), expAiCheckpoint::KIND_APPLY );
            $result = ( new expAiChangeApplier( $thread ) )->apply( array_map( 'intval', self::postJson( 'changes' ) ) );
            return self::changesAnswer( $thread, array( 'result' => $result ) );
        } );
    }

    /* ----------------------------------------------- plan mode (doc/plan-mode.md) */

    /** @return expAiPlan the thread's plan named by POST "plan" */
    protected static function loadPlan( expAiThread $thread )
    {
        $plan = expAiPlan::fetchByID( (int) self::post( 'plan' ) );
        if ( !$plan || (int) $plan->attribute( 'thread_id' ) !== (int) $thread->attribute( 'id' ) )
            throw new expAiException( 'This plan does not exist any more.' );
        return $plan;
    }

    /** @return array the Plan tab: the plan with its estimate, the limits it touches, money texts */
    protected static function planAnswer( expAiThread $thread, ?expAiPlan $plan, ?expAiConfig $config = null, array $extra = array() )
    {
        $config = $config ?: expAiConfig::instance();
        if ( !$plan )
            return $extra + array( 'plan' => null, 'can_decide' => false );
        $out = $plan->present();
        // the approver sees no name, instruction or field of a target it may not read
        $out = expAiVector::visiblePlan( $out, $thread->isOwner( self::userID() ) );
        $e = $out['estimate'];
        $money = function ( $micros ) { return expAiCost::enabled() ? expAiBudget::money( (int) $micros ) : ''; };
        $out['money'] = array( 'low' => $money( isset( $e['low_micros'] ) ? $e['low_micros'] : 0 ), 'expected' => $money( isset( $e['expected_micros'] ) ? $e['expected_micros'] : 0 ),
                               'high' => $money( isset( $e['high_micros'] ) ? $e['high_micros'] : 0 ),
                               'actual' => isset( $e['actual_micros'] ) ? $money( $e['actual_micros'] ) : '' );
        $out['lane_money'] = array();
        foreach ( isset( $e['lanes'] ) ? (array) $e['lanes'] : array() as $lane )
            $out['lane_money'][] = array( 'name' => $lane['name'], 'label' => $lane['label'], 'batch' => !empty( $lane['batch'] ),
                                          'low' => $money( $lane['low'] ), 'expected' => $money( $lane['expected'] ), 'high' => $money( $lane['high'] ) );
        $out['item_money'] = array();
        foreach ( isset( $e['items'] ) ? (array) $e['items'] : array() as $row )
            $out['item_money'][(int) $row['index']] = $money( $row['micros'] );
        $out['tokens'] = isset( $e['tokens'] ) ? expAiCost::tokens( (int) $e['tokens'] ) : '';
        $out['cost_enabled'] = expAiCost::enabled();
        $out['limits'] = $plan->attribute( 'state' ) === 'proposed'
            ? ( new expAiPlanEstimate( $config ) )->limits( isset( $e['expected_micros'] ) ? (int) $e['expected_micros'] : 0, (int) $thread->attribute( 'user_id' ) )
            : array( 'rows' => array(), 'ok' => true, 'message' => '' );
        return $extra + array( 'plan' => $out, 'can_decide' => $plan->attribute( 'state' ) === 'proposed' && expAiThreadAccess::canWrite( $thread ) && expAiVector::allowed() );
    }

    /** plan: the current plan of a thread (thread) */
    public static function plan( $args )
    {
        $config = self::guard();
        self::releaseSession();
        return self::run( function () use ( $config )
        {
            $thread = self::loadThread();
            return self::planAnswer( $thread, expAiPlan::fetchCurrent( (int) $thread->attribute( 'id' ) ), $config );
        } );
    }

    /** plan_estimate: the estimate of a proposed plan with the editor's selection (thread, plan, selected=[indexes], summaries={index: text}); not stored */
    public static function plan_estimate( $args )
    {
        $config = self::guard( true );
        self::releaseSession();
        return self::run( function () use ( $config )
        {
            $thread = self::loadThread( true );
            $plan = self::loadPlan( $thread );
            if ( $plan->attribute( 'state' ) === 'proposed' )
                expAiPlanner::edit( $plan, $thread, isset( $_POST['selected'] ) ? self::postJson( 'selected' ) : null, self::postJson( 'summaries' ), $config );
            return self::planAnswer( $thread, $plan, $config );
        } );
    }

    /**
     * plan_decide: op=approve (selected, summaries: the editor's edits; limits and budgets are checked; the items run
     * in the queue, or in the browser as queued follow-ups when there is no queue) | reject.
     */
    public static function plan_decide( $args )
    {
        $config = self::guard( true, true );
        self::releaseSession();
        return self::run( function () use ( $config )
        {
            $thread = self::loadThread( true );
            $plan = self::loadPlan( $thread );
            if ( self::post( 'op' ) === 'reject' )
            {
                expAiPlanner::reject( $thread, $plan );
                return self::planAnswer( $thread, $plan, $config, array( 'thread' => expAiVector::detail( expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ?: $thread, self::userID(), $config ) ) );
            }
            if ( self::post( 'op' ) !== 'approve' )
                throw new expAiException( 'Unknown decision.' );
            $reason = expAiThreadAccess::check( $thread->scope(), 'staged' );
            if ( $reason !== '' )
                throw new expAiException( $reason );
            expAiCheckpoint::write( $thread, ezpI18n::tr( 'extension/expai/vector', 'Before the approved plan runs' ), expAiCheckpoint::KIND_PLAN, $config );
            $run = expAiPlanner::approve( $thread, $plan, isset( $_POST['selected'] ) ? self::postJson( 'selected' ) : null, self::postJson( 'summaries' ), $config );
            $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ?: $thread;
            $followups = array();
            foreach ( expAiFollowup::waiting( (int) $fresh->attribute( 'id' ) ) as $followup )
                $followups[] = $followup->present();
            return self::planAnswer( $fresh, expAiPlan::fetchByID( (int) $plan->attribute( 'id' ) ), $config,
                                     array( 'run' => $run, 'thread' => expAiVector::detail( $fresh, self::userID(), $config ), 'followups' => $followups ) );
        } );
    }

    /**
     * switch_model: the running turn of a thread on a slow model goes on with another model (alias, '' the site
     * default): the job is stopped, a new lead run carries the conversation (compacted). The owner only; also for
     * threads of the Intelligence tab (expai/write). Answer: switch (alias, label, background, pending), thread.
     * The browser continues the turn (continue=1) unless it was queued again (background).
     */
    public static function switch_model( $args )
    {
        $config = self::guard( false, true );
        self::releaseSession();
        return self::run( function () use ( $config )
        {
            $thread = self::loadThread( true );
            $switch = expAiSlowModel::switchTo( $thread, self::post( 'alias' ), $config );
            $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ?: $thread;
            return array( 'switch' => $switch, 'thread' => expAiVector::detail( $fresh, self::userID(), $config ) );
        } );
    }

    /* --------------------------------------------------- checkpoints (doc/checkpoints.md) */

    /** checkpoints: the checkpoints of a thread (thread, limit), newest first; readable by whoever may read the thread */
    public static function checkpoints( $args )
    {
        self::guard();
        self::releaseSession();
        return self::run( function ()
        {
            $thread = self::loadThread();
            return array( 'checkpoints' => expAiVector::visibleCheckpoints( expAiCheckpoint::listFor( $thread, max( 1, min( 50, (int) self::post( 'limit', '50' ) ) ) ),
                                                                            $thread->isOwner( self::userID() ) ),
                          'can_restore' => expAiThreadAccess::canWrite( $thread ) && expAiVector::allowed() );
        } );
    }

    /**
     * checkpoint_restore: thread, checkpoint (id), preview=1 (what would change, nothing done) or the restore itself
     * (the owner only, nothing may run). Answer: preview or result, thread, drafts, the checkpoints.
     */
    public static function checkpoint_restore( $args )
    {
        $config = self::guard( true );
        self::releaseSession();
        return self::run( function () use ( $config )
        {
            $thread = self::loadThread( true );
            $checkpoint = expAiCheckpoint::fetchByID( (int) self::post( 'checkpoint' ) );
            if ( !$checkpoint || (int) $checkpoint->attribute( 'thread_id' ) !== (int) $thread->attribute( 'id' ) )
                throw new expAiException( 'This checkpoint does not exist any more.' );
            if ( self::post( 'preview' ) === '1' )
                return array( 'preview' => $checkpoint->preview( $thread, $config ) );
            $result = $checkpoint->restore( $thread, $config );
            $fresh = expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ?: $thread;
            return array( 'result' => $result, 'thread' => expAiVector::detail( $fresh, self::userID(), $config ), 'drafts' => expAiVector::drafts( $fresh ),
                          'checkpoints' => expAiCheckpoint::listFor( $fresh, 50 ) );
        } );
    }

    /** No caching */
    public static function getCacheTime( $functionName )
    {
        return 0;
    }
}
