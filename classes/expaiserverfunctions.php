<?php
/**
 * File containing the expAiServerFunctions class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The ezjscore server functions of expai (ezjscore.ini [ezjscServer_expai]):
 *
 *   POST <root>/ezjscore/call  ezjscServer_function_arguments=expai::<function>, ezxform_token, ...
 *   -> { error_text, content }
 *
 *   panel (full view):  load, chat, reset, drafts, keep, send, discard     (policy expai/write)
 *   edit form:          field, fill                                        (policy expai/write)
 *   everywhere:         labels                                             (reading the content is enough)
 *
 * Every function checks POST, the form token, [AISettings] Enabled and the policy itself; ezjscore checks
 * ezjscore/call (function list "expai"). Failures throw expAiException with a translated message, which
 * ezjscore returns as error_text.
 */
class expAiServerFunctions extends ezjscServerFunctions
{
    /** @return string */
    protected static function post( $name, $default = '' )
    {
        return isset( $_POST[$name] ) && is_scalar( $_POST[$name] ) ? (string) $_POST[$name] : $default;
    }

    /**
     * @param bool $policy the policy expai/write is needed
     * @return expAiConfig
     * @throws expAiException
     */
    protected static function guard( $policy = true, $needsProvider = true )
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
        if ( $policy && !self::hasPolicy() )
            throw ( new expAiException( 'You do not have access to Exponential Intelligence.' ) )->translated();
        return $config;
    }

    /** @return bool the current user has expai/write (any limitation counts as yes) */
    public static function hasPolicy( $function = 'write' )
    {
        $user = eZUser::currentUser();
        if ( !$user instanceof eZUser || !$user->isRegistered() )
            return false;
        $access = $user->hasAccessTo( 'expai', $function );
        return isset( $access['accessWord'] ) && $access['accessWord'] !== 'no';
    }

    /** Runs $fn, turning failures into translated exceptions for ezjscore */
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

    /** @return int the node of the panel, readable */
    protected static function nodeID()
    {
        $nodeID = (int) self::post( 'node_id', '0' );
        $node = $nodeID ? eZContentObjectTreeNode::fetch( $nodeID ) : null;
        if ( !$node || !$node->canRead() )
            throw new expAiException( 'Node %id does not exist or you may not read it.', '', 0, array( '%id' => $nodeID ) );
        return $nodeID;
    }

    /** load: the conversation so far and the drafts (panel start) */
    public static function load( $args )
    {
        $config = self::guard();
        return self::run( function () use ( $config )
        {
            $nodeID = self::nodeID();
            $user = eZUser::currentUser();
            $conversation = expAiConversation::fetchCurrent( $user->attribute( 'contentobject_id' ), $nodeID );
            // a request started on the dashboard ("Start writing") for this node, handed over once
            $http = eZHTTPTool::instance();
            $prefill = '';
            if ( $http->hasSessionVariable( 'ExpAiPrefill' ) )
            {
                $pending = (array) $http->sessionVariable( 'ExpAiPrefill' );
                if ( isset( $pending['node_id'], $pending['text'] ) && (int) $pending['node_id'] === $nodeID )
                {
                    $prefill = (string) $pending['text'];
                    $http->removeSessionVariable( 'ExpAiPrefill' );
                }
            }
            return array( 'prefill' => $prefill, 'history' => ( new expAiAssistant( $config ) )->history( $user, $nodeID ),
                          'model' => array( 'current' => $config->effectiveModel(),
                                            'refused' => $config->preferredModelRefused() ? ezpI18n::tr( 'extension/expai/model', 'The model you chose is no longer available; the default is used.' ) : '',
                                            'slow' => expAiUsage::slowNotice( $config->effectiveModel(), $config ),
                                            // the panel's select is in the view cache: expai.js marks stopped models from this
                                            'states' => $config->modelStates(), 'manage' => self::hasPolicy( 'model_manage' ) ),
                          'drafts' => expAiAssistant::drafts( $user, $nodeID ),
                          'pending' => $conversation ? $conversation->awaitsAnswer() : false );
        } );
    }

    /** history: older exchanges of the panel ("Show older"): node_id, before (the oldest exchange id shown). Read only. */
    public static function history( $args )
    {
        $config = self::guard();
        return self::run( function () use ( $config )
        {
            $nodeID = self::nodeID();
            $before = (int) self::post( 'before', '0' );
            return ( new expAiAssistant( $config ) )->history( eZUser::currentUser(), $nodeID, $before > 0 ? $before : null );
        } );
    }

    /**
     * model: the current user's model choice (preference expai_model). model=<id> from [AISettings] AllowedModels[]
     * (anything else is refused), or model= (empty) to go back to the site default.
     */
    public static function model( $args )
    {
        $config = self::guard( true, false );
        return self::run( function () use ( $config )
        {
            $model = $config->registry()->resolveAlias( self::post( 'model' ) ) ?: self::post( 'model' );
            if ( !$config->modelChoiceEnabled() )
                throw new expAiException( 'Choosing a model is switched off on this site.' );
            // only the models of allowedModels(): the site default, open providers, and those expai/model_use allows
            if ( $model !== '' && !isset( $config->allowedModels()[$model] ) )
                throw new expAiException( 'This model is not available.' );
            eZPreferences::setValue( 'expai_model', $model === $config->model() ? '' : $model );
            $fresh = expAiConfig::instance();
            return array( 'model' => $fresh->effectiveModel(), 'label' => $fresh->allowedModels()[$fresh->effectiveModel()],
                          'default' => $fresh->model(), 'slow' => expAiUsage::slowNotice( $fresh->effectiveModel(), $fresh ) );
        } );
    }

    /**
     * states: the models of the user's pickers that are stopped or unreachable now (alias => { state, text,
     * disabled }), for pickers built in the browser (Vector). Read only; kept briefly (expAiProviderHealth).
     */
    public static function states( $args )
    {
        $config = self::guard( true, false );
        return self::run( function () use ( $config )
        {
            return array( 'states' => $config->modelStates(), 'manage' => self::hasPolicy( 'model_manage' ) );
        } );
    }

    /** chat: node_id, message (a new message) or continue=1 (the next step after tool results) */
    public static function chat( $args )
    {
        $config = self::guard();
        return self::run( function () use ( $config )
        {
            $nodeID = self::nodeID();
            $settings = $config->providerSettings();
            $timeout = max( 1, min( 600, (int) ( isset( $settings['Timeout'] ) && $settings['Timeout'] !== '' ? $settings['Timeout'] : $config->int( 'AISettings', 'Timeout', 115, 1, 600 ) ) ) );
            if ( function_exists( 'set_time_limit' ) )
                @set_time_limit( $timeout + 30 );
            ignore_user_abort( true );
            // a slow model: the turn runs in the queue (threads, doc/vector.md "Background runs")
            $queued = expAiThreadRunner::panelTurn( $nodeID, self::post( 'continue' ) === '1' ? null : self::post( 'message' ), $config );
            if ( $queued )
                return $queued;
            $assistant = new expAiAssistant( $config );
            return $assistant->step( $nodeID, self::post( 'continue' ) === '1' ? null : self::post( 'message' ) );
        } );
    }

    /** reset: forgets the conversation of the user on the node (the drafts stay) */
    public static function reset( $args )
    {
        self::guard( true, false );
        return self::run( function ()
        {
            $nodeID = self::nodeID();
            expAiConversation::removeFor( eZUser::currentUserID(), $nodeID );
            return array( 'history' => array( 'exchanges' => array(), 'has_older' => false ), 'drafts' => expAiAssistant::drafts( eZUser::currentUser(), $nodeID ) );
        } );
    }

    /** drafts: the drafts of the panel */
    public static function drafts( $args )
    {
        self::guard( true, false );
        return self::run( function ()
        {
            return array( 'drafts' => expAiAssistant::drafts( eZUser::currentUser(), self::nodeID() ) );
        } );
    }

    /** keep: "Save as drafts" for object_id, version (a comma list: object:version,...) */
    public static function keep( $args )
    {
        return self::each( 'keep' );
    }

    /** send: "Send for approval" for object_id:version,... */
    public static function send( $args )
    {
        return self::each( 'send' );
    }

    /** discard: removes the drafts object_id:version,... */
    public static function discard( $args )
    {
        return self::each( 'discard' );
    }

    protected static function each( $method )
    {
        self::guard( true, false );
        return self::run( function () use ( $method )
        {
            // node_id 0: called from the dashboard, which reloads itself (no panel list to return)
            $nodeID = self::post( 'node_id', '0' ) === '0' ? 0 : self::nodeID();
            $results = array();
            foreach ( array_slice( array_filter( explode( ',', self::post( 'drafts' ) ) ), 0, 50 ) as $pair )
            {
                list( $objectID, $version ) = array_map( 'intval', explode( ':', $pair . ':0' ) );
                try
                {
                    $results[] = array( 'object_id' => $objectID, 'version' => $version, 'ok' => true )
                               + call_user_func( array( 'expAiPublisher', $method ), $objectID, $version );
                }
                catch ( expAiException $e )
                {
                    $results[] = array( 'object_id' => $objectID, 'version' => $version, 'ok' => false, 'error' => $e->translatedMessage() );
                }
            }
            // #389 a thread whose drafts are all decided no longer says "To review"
            $decided = array();
            foreach ( $results as $r )
                if ( $r['ok'] )
                    $decided[] = array( $r['object_id'], $r['version'] );
            if ( $decided )
                expAiPublisher::settleThreads( $decided );
            return array( 'results' => $results, 'drafts' => $nodeID ? expAiAssistant::drafts( eZUser::currentUser(), $nodeID ) : array() );
        } );
    }

    /** field: a field action in the edit form */
    public static function field( $args )
    {
        $config = self::guard();
        return self::run( function () use ( $config )
        {
            if ( function_exists( 'set_time_limit' ) )
                @set_time_limit( self::timeout( $config ) + 30 );
            return self::withRecord( function () use ( $config )
            {
                return expAiEditForm::fieldAction( $config, self::formPost() );
            } );
        } );
    }

    /**
     * Runs a request of the edit form (a field action, a preset, an Improve entry). When it fails after a request was
     * sent, the answer is { error, record } instead of the failure, so the form can link the request's row in the
     * usage log next to the error; a failure before anything was sent stays a failure.
     */
    protected static function withRecord( $fn )
    {
        expAiEditForm::resetRecord();
        try
        {
            return $fn();
        }
        catch ( expAiException $e )
        {
            $record = expAiEditForm::requestRecord();
            if ( !$record )
                throw $e;
            return array( 'error' => $e->translatedMessage(), 'record' => $record );
        }
    }

    /** fill: values for the fields of the edit form from a brief */
    public static function fill( $args )
    {
        $config = self::guard();
        return self::run( function () use ( $config )
        {
            if ( function_exists( 'set_time_limit' ) )
                @set_time_limit( self::timeout( $config ) + 30 );
            return expAiEditForm::fill( $config, self::formPost() );
        } );
    }

    /** @return int seconds the provider of the current user's model may take */
    protected static function timeout( expAiConfig $config )
    {
        $settings = $config->providerSettings();
        return max( 1, min( 600, (int) ( isset( $settings['Timeout'] ) && $settings['Timeout'] !== '' ? $settings['Timeout'] : 115 ) ) );
    }

    protected static function formPost()
    {
        $post = array();
        foreach ( array( 'object_id', 'version', 'language', 'attribute_id', 'action', 'value', 'instruction', 'target_language', 'context' ) as $name )
            $post[$name] = self::post( $name );
        return $post;
    }

    /**
     * labels: which of the given versions the assistant wrote. pairs=objectID:version,...; collab=itemID,...
     * Only versions of objects the user may read, and approval items the user takes part in.
     */
    public static function labels( $args )
    {
        $config = self::guard( false, false );
        return self::run( function () use ( $config )
        {
            $result = array( 'labels' => array(), 'collab' => array(),
                             'texts' => array( 'short' => ezpI18n::tr( 'extension/expai/label', 'Exponential Intelligence' ),
                                               'full' => ezpI18n::tr( 'extension/expai/label', 'Exponential Intelligence' ),
                                               'written' => ezpI18n::tr( 'extension/expai/label', 'This version was written with Exponential Intelligence.' ),
                                               'model' => ezpI18n::tr( 'extension/expai/label', 'Model' ) ) );
            if ( !$config->flag( 'LabelSettings', 'Enabled', true ) || !eZUser::currentUser()->isRegistered() )
                return $result;
            $pairs = array();
            foreach ( array_slice( array_filter( explode( ',', self::post( 'pairs' ) ) ), 0, 200 ) as $pair )
            {
                $parts = array_map( 'intval', explode( ':', $pair . ':0' ) );
                if ( $parts[0] > 0 && $parts[1] > 0 )
                    $pairs[] = array( $parts[0], $parts[1] );
            }
            $user = eZUser::currentUser();
            foreach ( array_slice( array_filter( array_map( 'intval', explode( ',', self::post( 'collab' ) ) ) ), 0, 100 ) as $itemID )
            {
                $item = eZCollaborationItem::fetch( $itemID );
                if ( $item && $item->attribute( 'type_identifier' ) === 'ezapprove' && $item->userIsParticipant( $user ) )
                {
                    $pair = array( (int) $item->attribute( 'data_int1' ), (int) $item->attribute( 'data_int2' ) );
                    $pairs[] = $pair;
                    $result['collab'][$itemID] = $pair[0] . ':' . $pair[1];
                }
            }
            $readable = array();
            foreach ( expAiDraftLog::fetchForVersions( $pairs ) as $key => $log )
            {
                $objectID = (int) $log->attribute( 'contentobject_id' );
                if ( !isset( $readable[$objectID] ) )
                {
                    $object = expAiObjects::fetch( $objectID );
                    $readable[$objectID] = $object && ( $object->canRead() || (int) $log->attribute( 'user_id' ) === (int) $user->attribute( 'contentobject_id' )
                                                        || in_array( $key, $result['collab'], true ) );
                }
                if ( $readable[$objectID] )
                    $result['labels'][$key] = array( 'model' => (string) $log->attribute( 'model' ), 'action' => (string) $log->attribute( 'action' ),
                                                     'created' => (int) $log->attribute( 'created' ) );
            }
            return $result;
        } );
    }

    /** No caching */
    public static function getCacheTime( $functionName )
    {
        return 0;
    }
}
