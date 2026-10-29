<?php
/**
 * File containing the expAiModelServerFunctions class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The ezjscore functions of the "Test prompt" and "Load model" actions of expai/models (ezjscore.ini
 * [ezjscServer_expaimodel]; the caller needs ezjscore/call with the function list "expai"):
 *
 *   POST <root>/ezjscore/call  ezjscServer_function_arguments=expaimodel::<function>, ezxform_token, ...
 *
 *   status   models (comma list of aliases): the newest test and load of each, whether a local model is loaded
 *   test     model: queues a test prompt (one per model at a time) and starts a worker
 *   load     model: queues loading a model of a local server
 *
 * Each answers { models: [ { alias, active, state_html, result_html } ], message, poll (milliseconds) }. Every
 * function checks POST, the form token and the policy expai/model_manage, and closes the session before it waits for
 * anything, so the polling never holds the editor's other requests.
 */
class expAiModelServerFunctions extends ezjscServerFunctions
{
    /** @return string */
    protected static function post( $name, $default = '' )
    {
        return isset( $_POST[$name] ) && is_scalar( $_POST[$name] ) ? (string) $_POST[$name] : $default;
    }

    /** POST, form token, expai/model_manage; then the session is closed */
    protected static function guard()
    {
        if ( !isset( $_SERVER['REQUEST_METHOD'] ) || strtoupper( $_SERVER['REQUEST_METHOD'] ) !== 'POST' )
            throw ( new expAiException( 'Exponential Intelligence only accepts POST requests.' ) )->translated();
        if ( class_exists( 'ezxFormToken' ) && ezxFormToken::isEnabled() )
        {
            $given = self::post( 'ezxform_token', isset( $_SERVER['HTTP_X_CSRF_TOKEN'] ) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : '' );
            if ( $given === '' || !hash_equals( (string) ezxFormToken::getToken(), $given ) )
                throw ( new expAiException( 'The form token is missing or wrong. Please reload the page.' ) )->translated();
        }
        if ( !expAiServerFunctions::hasPolicy( 'model_manage' ) )
            throw ( new expAiException( 'You do not have access to Exponential Intelligence.' ) )->translated();
        if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE )
            session_write_close();
        return expAiConfig::instance();
    }

    /** @return string[] the known aliases of a comma list (at most 50) */
    protected static function aliases( expAiConfig $config, $list )
    {
        $out = array();
        foreach ( array_slice( array_filter( array_map( 'trim', explode( ',', (string) $list ) ) ), 0, 50 ) as $alias )
            if ( $config->registry()->getModel( $alias ) )
                $out[] = $alias;
        return array_values( array_unique( $out ) );
    }

    /** @return array the answer for the browser */
    protected static function answer( expAiConfig $config, array $aliases, $message = '' )
    {
        $models = array();
        $jobs = expAiModelTest::recentJobs();
        // a job that waits too long gets a worker of its own
        if ( method_exists( 'expAiModelTest', 'nudge' ) )
            expAiModelTest::nudge( $jobs, $config );
        foreach ( $aliases as $alias )
        {
            $t = expAiModelTest::forModel( $alias, $config, null, $jobs );
            $models[] = array( 'alias' => $alias, 'active' => (bool) $t['active'], 'state_html' => self::render( $t, 'state' ), 'result_html' => self::render( $t, 'result' ),
                               'has_result' => $t['test'] !== null || $t['load'] !== null );
        }
        return array( 'models' => $models, 'message' => (string) $message,
                      'poll' => 1000 * $config->int( 'ModelTest', 'PollSeconds', 3, 2, 30 ) );
    }

    /** @return string a part of expai/parts/model_test.tpl */
    public static function render( array $t, $part )
    {
        $tpl = eZTemplate::factory();
        $tpl->setVariable( 't', $t );
        $tpl->setVariable( 'part', $part );
        return trim( (string) $tpl->fetch( 'design:expai/parts/model_test.tpl' ) );
    }

    /** status: models=<alias>,<alias> */
    public static function status( $args )
    {
        $config = self::guard();
        return self::answer( $config, self::aliases( $config, self::post( 'models' ) ) );
    }

    /** test: model=<alias> */
    public static function test( $args )
    {
        return self::start( 'test' );
    }

    /** load: model=<alias> (a model of a local server) */
    public static function load( $args )
    {
        return self::start( 'load' );
    }

    protected static function start( $action )
    {
        $config = self::guard();
        $aliases = self::aliases( $config, self::post( 'model' ) );
        if ( !$aliases )
            throw ( new expAiException( 'This model is not available.' ) )->translated();
        try
        {
            $started = expAiModelTest::start( $aliases[0], $action, $config );
            if ( class_exists( 'expAiAudit' ) && empty( $started['duplicate'] ) )
                expAiAudit::modelAction( $action, $aliases[0] );
        }
        catch ( expAiException $e )
        {
            if ( class_exists( 'expAiAudit' ) )
                expAiAudit::modelAction( $action, $aliases[0], get_class( $e ) );
            throw $e->translated();
        }
        return self::answer( $config, $aliases, self::notice( $config, $aliases[0], $action, $started['duplicate'] ) );
    }

    /** @return string the message after queueing */
    public static function notice( expAiConfig $config, $alias, $action, $duplicate )
    {
        $model = $config->registry()->getModel( $alias );
        $label = $model ? $model['label'] : $alias;
        if ( $duplicate )
            return $action === 'load' ? ezpI18n::tr( 'extension/expai/modeltest', 'The model %model is already being loaded.', null, array( '%model' => $label ) )
                                      : ezpI18n::tr( 'extension/expai/modeltest', 'A test of %model is already waiting or running.', null, array( '%model' => $label ) );
        return $action === 'load' ? ezpI18n::tr( 'extension/expai/modeltest', 'The model %model is being loaded in the background.', null, array( '%model' => $label ) )
                                  : ezpI18n::tr( 'extension/expai/modeltest', 'The test of %model runs in the background.', null, array( '%model' => $label ) );
    }
}
