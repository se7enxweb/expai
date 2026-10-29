<?php
/**
 * File containing the expAiModelFallback class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The fallback to the site default model ([AISettings] FallbackToDefaultModel, default enabled): when the model a
 * request asked for (the user's choice, a thread's model, the model a conversation is bound to) cannot answer, the
 * request goes to the site default model instead, and the editor is told so in one short line.
 *
 * Reasons to switch (reason()):
 *   stopped      a local server (Managed=ollama) does not run (expAiProviderHealth), or its request was refused
 *   unreachable  connection refused, name not resolved, TLS failed (curl errors), or a provider marked unreachable
 *   timeout      the provider did not send a single byte within its time limit
 *   not_allowed  the user may not use the model any more (expai/model_use, AllowedModels[], model choice off)
 *   removed      the model is not in the registry any more
 *   data_policy  the data policy keeps the content away from the model's provider
 *
 * Not a reason: anything that would fail the same way on any model (the default's own provider refusing access, a
 * request that is too long, a message that is too long, a refusal) - those are shown as before.
 *
 * The replacement (choose()): the site default; when the default may not take the content either (the user's
 * permissions, the data policy) the first model of the user's allowed models that can; when none can, a message that
 * names why the requested model cannot answer. A request that asked for the site default itself never moves to
 * another provider: its failure is shown as before.
 *
 * A conversation carries the content it read (expAiConversation::readObjects()). choose() prefers a model whose
 * provider may take all of it ($prefer, objectsAllow()); a model that may take only the open node is the second
 * choice, and the conversation then goes on with the editor's words only (expAiAssistant::step()).
 */
class expAiModelFallback
{
    const STOPPED = 'stopped';
    const UNREACHABLE = 'unreachable';
    const TIMEOUT = 'timeout';
    const NOT_ALLOWED = 'not_allowed';
    const REMOVED = 'removed';
    const DATA_POLICY = 'data_policy';

    /** curl errors of the connection itself: proxy and host not resolved, refused, TLS handshake and certificates */
    const CONNECTION_ERRORS = array( 5, 6, 7, 35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 );

    /** @return bool [AISettings] FallbackToDefaultModel (default enabled) */
    public static function enabled( expAiConfig $config )
    {
        return $config->flag( 'AISettings', 'FallbackToDefaultModel', true );
    }

    /**
     * Why a model cannot take a request, before it is sent.
     * @param callable|null $allows function( string $providerID ): bool, the data policy of that provider lets the
     *                              content go to it
     * @return string '' when it can, else a reason (see the class)
     */
    public static function preflight( expAiConfig $config, $alias, $allows = null )
    {
        $model = $config->registry()->getModel( (string) $alias );
        if ( !$model )
            return self::REMOVED;
        if ( !$config->mayUseModel( (string) $alias ) )
            return self::NOT_ALLOWED;
        $state = expAiProviderHealth::state( $config, $model['provider'] );
        if ( $state === expAiProviderHealth::STOPPED )
            return self::STOPPED;
        if ( $state === expAiProviderHealth::UNREACHABLE )
            return self::UNREACHABLE;
        if ( $allows && !call_user_func( $allows, $model['provider'] ) )
            return self::DATA_POLICY;
        return '';
    }

    /**
     * Why a failed request is a reason to switch.
     * @return string '' (show the failure as it is), stopped, unreachable or timeout
     */
    public static function reason( expAiException $e, expAiConfig $config, $alias )
    {
        $kind = self::failureKind( $e );
        if ( $kind === '' )
            return '';
        $model = $config->registry()->getModel( (string) $alias );
        $settings = $model ? $config->registry()->getProvider( $model['provider'] ) : null;
        if ( $kind === self::UNREACHABLE && $settings && expAiProviderHealth::isManaged( $settings ) )
            return self::STOPPED;
        // #377 a background run (the queue worker, no web request) that ran out of its own, longer time limit
        // (TimeoutBackground) is slow, not down: the editor's choice of model stays, the failure is shown
        if ( $kind === self::TIMEOUT && class_exists( 'expAiWebBudget' ) && !expAiWebBudget::inWebRequest() )
            return '';
        return $kind;
    }

    /** @return string unreachable, timeout (no byte within the time limit) or '' from the failure's log detail */
    public static function failureKind( expAiException $e )
    {
        $detail = (string) $e->detail();
        if ( preg_match( '/curl error (\d+)/', $detail, $m ) && in_array( (int) $m[1], self::CONNECTION_ERRORS, true ) )
            return self::UNREACHABLE;
        if ( strpos( $detail, 'timeout after' ) !== false && strpos( $detail, 'no answer' ) !== false )
            return self::TIMEOUT;
        return '';
    }

    /** @return bool a usage log error text (message and detail) is a failure of the connection */
    public static function isConnectionFailureText( $text )
    {
        return preg_match( '/curl error (\d+)/', (string) $text, $m ) && in_array( (int) $m[1], self::CONNECTION_ERRORS, true );
    }

    /**
     * The model that answers instead of $requested.
     * @return string the alias, '' when no model can (or when $requested is the site default)
     */
    public static function choose( expAiConfig $config, $requested, $allows = null, $prefer = null )
    {
        $default = $config->model();
        if ( (string) $requested === $default )
            return '';
        $candidates = array_values( array_unique( array_merge( array( $default ), array_keys( $config->allowedModelRows() ) ) ) );
        // first a model that may take everything the conversation read, then one that may take the request itself
        $checks = array();
        if ( $prefer )
            $checks[] = function ( $providerID ) use ( $allows, $prefer ) { return ( !$allows || call_user_func( $allows, $providerID ) ) && call_user_func( $prefer, $providerID ); };
        $checks[] = $allows;
        foreach ( $checks as $check )
            foreach ( $candidates as $alias )
            {
                if ( $alias === '' || $alias === (string) $requested )
                    continue;
                if ( self::preflight( $config, $alias, $check ) === '' )
                    return $alias;
            }
        return '';
    }

    /** @return string the label of a model alias (the alias when the registry does not know it) */
    public static function label( expAiConfig $config, $alias )
    {
        $model = $config->registry()->getModel( (string) $alias );
        return $model ? (string) $model['label'] : (string) $alias;
    }

    /**
     * The line for the editor: "<used> answered instead: <requested> could not be reached."
     * @return string translated
     */
    public static function notice( expAiConfig $config, $requested, $used, $reason )
    {
        $args = array( '%used' => self::label( $config, $used ), '%requested' => self::label( $config, $requested ) );
        // one literal per text, so that the translation files list each of them
        switch ( $reason )
        {
            case self::STOPPED:
                return ezpI18n::tr( 'extension/expai/model', '%used answered instead: %requested is stopped.', null, $args );
            case self::TIMEOUT:
                return ezpI18n::tr( 'extension/expai/model', '%used answered instead: %requested did not answer in time.', null, $args );
            case self::NOT_ALLOWED:
                return ezpI18n::tr( 'extension/expai/model', '%used answered instead: %requested is not allowed.', null, $args );
            case self::REMOVED:
                return ezpI18n::tr( 'extension/expai/model', '%used answered instead: %requested is no longer available.', null, $args );
            case self::DATA_POLICY:
                return ezpI18n::tr( 'extension/expai/model', '%used answered instead: the data policy keeps this content away from %requested.', null, $args );
            default:
                return ezpI18n::tr( 'extension/expai/model', '%used answered instead: %requested could not be reached.', null, $args );
        }
    }

    /**
     * The failure when no model can answer instead.
     * @return expAiException
     */
    public static function noneLeft( expAiConfig $config, $requested, $reason )
    {
        $args = array( '%requested' => self::label( $config, $requested ) );
        switch ( $reason )
        {
            case self::STOPPED:
                return new expAiException( '%requested is stopped, and no other model you may use can answer for this content.', 'fallback: none left', 0, $args );
            case self::TIMEOUT:
                return new expAiException( '%requested did not answer in time, and no other model you may use can answer for this content.', 'fallback: none left', 0, $args );
            case self::NOT_ALLOWED:
                return new expAiException( '%requested is not allowed, and no other model you may use can answer for this content.', 'fallback: none left', 0, $args );
            case self::REMOVED:
                return new expAiException( '%requested is no longer available, and no other model you may use can answer for this content.', 'fallback: none left', 0, $args );
            case self::DATA_POLICY:
                return new expAiException( 'The data policy keeps this content away from %requested, and no other model you may use can answer for it.', 'fallback: none left', 0, $args );
            default:
                return new expAiException( '%requested could not be reached, and no other model you may use can answer for this content.', 'fallback: none left', 0, $args );
        }
    }

    /**
     * What the result of a request carries when another model answered.
     * @return array requested, requested_label, used, used_label, reason, notice
     */
    public static function describe( expAiConfig $config, $requested, $used, $reason )
    {
        return array( 'requested' => (string) $requested, 'requested_label' => self::label( $config, $requested ),
                      'used' => (string) $used, 'used_label' => self::label( $config, $used ), 'reason' => (string) $reason,
                      'notice' => self::notice( $config, $requested, $used, $reason ) );
    }

    /**
     * The model a request of the current user asked for: the user's stored choice (also one that is no longer
     * allowed or known, so that the editor learns why another model answers), else the site default.
     * @return string alias (or the stored value when the registry does not know it)
     */
    public static function requestedByUser( expAiConfig $config )
    {
        $preferred = $config->modelChoiceEnabled() ? $config->preferredModel() : '';
        return $preferred !== '' ? $preferred : $config->model();
    }

    /**
     * The model for a request of the current user that is sent once (field actions, "Fill the fields"): the
     * requested model, or the replacement when it cannot take the request.
     * @return array( string alias, array|null fallback (describe()) )
     * @throws expAiException when no model can take it
     */
    public static function resolve( expAiConfig $config, $requested, $allows = null, $prefer = null )
    {
        $requested = (string) $requested;
        // switched off: as before (a model the user may not use any more silently becomes the default)
        if ( !self::enabled( $config ) )
            return array( $config->mayUseModel( $requested ) ? $requested : $config->model(), null );
        $reason = self::preflight( $config, $requested, $allows );
        // the site default itself never moves to another provider: its failure is shown as before
        if ( $reason === '' || $requested === $config->model() )
            return array( $requested, null );
        $used = self::choose( $config, $requested, $allows, $prefer );
        if ( $used === '' )
            throw self::noneLeft( $config, $requested, $reason );
        return array( $used, self::describe( $config, $requested, $used, $reason ) );
    }

    /**
     * Sends one request with the fallback: when the model fails at the connection, the replacement gets it.
     * @param callable $send function( string $alias ): mixed, sends the request with that model
     * @param array|null $fallback in: from resolve(); out: set when another model answered
     * @return mixed what $send returned
     * @throws expAiException
     */
    public static function sendWith( expAiConfig $config, $alias, $send, &$fallback = null, $allows = null )
    {
        try
        {
            $result = call_user_func( $send, $alias );
            self::rememberUp( $config, $alias );
            return $result;
        }
        catch ( expAiException $e )
        {
            $reason = self::enabled( $config ) && !$fallback ? self::reason( $e, $config, $alias ) : '';
            if ( $reason === '' )
                throw $e;
            self::rememberDown( $config, $alias );
            $used = self::choose( $config, $alias, $allows );
            if ( $used === '' )
            {
                if ( (string) $alias === $config->model() )
                    throw $e;
                throw self::noneLeft( $config, $alias, $reason );
            }
            $fallback = self::describe( $config, $alias, $used, $reason );
            return call_user_func( $send, $used );
        }
    }

    /** Remembers that the provider of a model answered */
    public static function rememberUp( expAiConfig $config, $alias )
    {
        $model = $config->registry()->getModel( (string) $alias );
        if ( $model )
            expAiProviderHealth::remember( $config, $model['provider'], expAiProviderHealth::OK );
    }

    /** Remembers that the provider of a model failed at the connection (the pickers show it at once) */
    public static function rememberDown( expAiConfig $config, $alias )
    {
        $model = $config->registry()->getModel( (string) $alias );
        $settings = $model ? $config->registry()->getProvider( $model['provider'] ) : null;
        if ( $settings )
            expAiProviderHealth::remember( $config, $model['provider'], expAiProviderHealth::downState( $settings ) );
    }

    /**
     * The data policy check of a node for choose() and preflight().
     * @return callable function( string $providerID ): bool
     */
    public static function nodeAllows( expAiConfig $config, $nodeID )
    {
        return function ( $providerID ) use ( $config, $nodeID )
        {
            try
            {
                ( new expAiContentReader( $config, expAiDataPolicy::forProvider( $config, $providerID ) ) )->readableNode( $nodeID );
                return true;
            }
            catch ( expAiException $e )
            {
                return false;
            }
        };
    }

    /**
     * The data policy and request veto check of the objects a conversation read, for choose() (objectsAllowed()).
     * @param int[] $objectIDs
     * @param bool $untracked the conversation holds content its list does not cover (no provider passes then)
     * @param array $request what is known of the request for the veto (purpose)
     * @return callable|null function( string $providerID ): bool; null when there are none
     */
    public static function objectsAllow( expAiConfig $config, array $objectIDs, $untracked = false, array $request = array() )
    {
        // content the conversation cannot account for: no provider is known to be allowed it
        if ( $untracked )
            return function ( $providerID ) { return false; };
        if ( !$objectIDs )
            return null;
        return function ( $providerID ) use ( $config, $objectIDs, $request )
        {
            return expAiModelFallback::objectsAllowed( $config, $providerID, $objectIDs, null, null, $request );
        };
    }

    /**
     * May every one of these objects go to a provider? Both the data policy of the provider and the request veto
     * ([RequestVeto] rules, expAiGate::objectVeto() with the provider, its region and the purpose of the request) must
     * let each of them through.
     * Fail closed: an object that cannot be loaded any more (removed, or the lookup failed) counts as not allowed,
     * since what the conversation holds of it cannot be checked.
     * @param int[] $objectIDs
     * @param callable|null $fetch function( int $id ): eZContentObject|null (default eZContentObject::fetch)
     * @param expAiDataPolicy|null $policy the provider's policy (default expAiDataPolicy::forProvider())
     * @param array $request what is known of the request for the veto (purpose, siteaccess); provider and region are
     *                       those of the new provider
     * @param callable|null $veto function( object $object, array $request ): string reason ('' when allowed)
     *                            (default expAiGate::objectVeto())
     * @return bool
     */
    public static function objectsAllowed( expAiConfig $config, $providerID, array $objectIDs, $fetch = null, $policy = null, array $request = array(), $veto = null )
    {
        if ( !$objectIDs )
            return true;
        $policy = $policy ?: expAiDataPolicy::forProvider( $config, (string) $providerID );
        $request['provider'] = (string) $providerID;
        if ( !isset( $request['region'] ) )
            $request['region'] = self::providerRegion( $config, (string) $providerID, $policy );
        foreach ( array_unique( array_map( 'intval', $objectIDs ) ) as $id )
        {
            $object = $fetch ? call_user_func( $fetch, $id ) : eZContentObject::fetch( $id );
            if ( !is_object( $object ) )
                return false;
            if ( !$policy->allowsObject( $object ) )
                return false;
            if ( $veto )
                $reason = (string) call_user_func( $veto, $object, $request );
            elseif ( $object instanceof eZContentObject )
            {
                $result = expAiGate::objectVeto( $object, $request, $config );
                $reason = (string) $result[0];
            }
            else
                // nothing the veto can check: fail closed
                $reason = 'unknown object';
            if ( $reason !== '' )
                return false;
        }
        return true;
    }

    /** @return string the region of a provider: from its data policy, else from the registry ('' when not known) */
    protected static function providerRegion( expAiConfig $config, $providerID, $policy )
    {
        if ( is_object( $policy ) && method_exists( $policy, 'providerRegion' ) )
            return (string) $policy->providerRegion();
        $settings = $config->registry()->getProvider( $providerID );
        return $settings && isset( $settings['Region'] ) ? (string) $settings['Region'] : '';
    }

    /** @return callable the data policy check of an object */
    public static function objectAllows( expAiConfig $config, eZContentObject $object )
    {
        return function ( $providerID ) use ( $config, $object )
        {
            return expAiDataPolicy::forProvider( $config, $providerID )->allowsObject( $object );
        };
    }
}
