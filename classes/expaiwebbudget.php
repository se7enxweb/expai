<?php
/**
 * File containing the expAiWebBudget class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The time a web request may spend on one model call. A web server ends a request after its own limit (Velocity
 * after its request timeout, Apache after its proxy timeout), and the browser then sees a 504 instead of an answer.
 * A model that cannot answer within that time is not called from a web request: the caller runs the call as a job
 * (the test prompt of expai/models, Vector turns), or refuses with a clear message (the field actions of the edit
 * form).
 *
 *   [WebBudget]
 *   Seconds=25          what one call from a web request may take
 *   SlowModels=queue    queue | allow: allow lets slow models (Slow=true, local servers with Managed=ollama) be
 *                       called from a web request anyway, e.g. behind a web server with a long timeout
 *   MinSamples=5        a model counts as slow by measurement when its last successful requests (at least this
 *                       many, of the last 20) took longer than Seconds on average
 *
 * A model's own setting wins: [Model_<alias>] RunInBackground=always marks it too slow, never lets it through.
 */
class expAiWebBudget
{
    /** @return int seconds */
    public static function seconds( ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        return $config->int( 'WebBudget', 'Seconds', 25, 1, 600 );
    }

    /** @return bool this PHP serves a web request (also on Velocity, whose workers are command line PHP) */
    public static function inWebRequest()
    {
        return isset( $_SERVER['REQUEST_METHOD'] ) && (string) $_SERVER['REQUEST_METHOD'] !== '';
    }

    /**
     * @return float|null the average seconds of the last successful requests of a model, null with fewer than
     *                    MinSamples of them
     */
    public static function measuredSeconds( $alias, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $min = $config->int( 'WebBudget', 'MinSamples', 5, 1, 20 );
        $rows = eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), array( 'latency_ms' ),
            expAiUsage::requestsOnly( array( 'model_alias' => (string) $alias, 'error_class' => '' ) ), array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => 20 ), false );
        $rows = is_array( $rows ) ? $rows : array();
        if ( count( $rows ) < $min )
            return null;
        return array_sum( array_map( function ( $r ) { return (int) $r['latency_ms']; }, $rows ) ) / count( $rows ) / 1000;
    }

    /**
     * Why a model does not fit into the web budget.
     * @param float|null|false $measured the measured average (false: measure it)
     * @return string '' when it fits; slow (marked slow or a managed local server), measured (slower than the budget
     *                on average), setting (RunInBackground=always)
     */
    public static function reason( $alias, ?expAiConfig $config = null, $measured = false )
    {
        $config = $config ?: expAiConfig::instance();
        $registry = $config->registry();
        $model = $registry->getModel( (string) $alias );
        if ( !$model )
            return '';
        $own = isset( $model['settings']['RunInBackground'] ) ? strtolower( (string) $model['settings']['RunInBackground'] ) : 'auto';
        if ( in_array( $own, array( 'never', 'disabled', 'false' ), true ) )
            return '';
        if ( in_array( $own, array( 'always', 'enabled', 'true' ), true ) )
            return 'setting';
        $provider = $registry->getProvider( $model['provider'] );
        $managed = $provider && isset( $provider['Managed'] ) && strtolower( (string) $provider['Managed'] ) === 'ollama';
        if ( ( $managed || !empty( $model['slow'] ) ) && strtolower( (string) $config->get( 'WebBudget', 'SlowModels', 'queue' ) ) !== 'allow' )
            return 'slow';
        if ( $measured === false )
            $measured = self::measuredSeconds( $alias, $config );
        if ( $measured !== null && $measured > self::seconds( $config ) )
            return 'measured';
        return '';
    }

    /** @return bool the model can be called from a web request */
    public static function fits( $alias, ?expAiConfig $config = null )
    {
        return self::reason( $alias, $config ) === '';
    }

    /**
     * Refuses a call of a model that does not fit into the web budget, when this PHP serves a web request.
     * @param string $what field (the field actions and the fill of the edit form) | test
     * @throws expAiException
     */
    public static function assertFits( $alias, $what = 'field', ?expAiConfig $config = null )
    {
        if ( !self::inWebRequest() )
            return;
        $config = $config ?: expAiConfig::instance();
        $reason = self::reason( $alias, $config );
        if ( $reason === '' )
            return;
        $label = (string) $alias;
        $model = $config->registry()->getModel( (string) $alias );
        if ( $model && $model['label'] !== '' )
            $label = $model['label'];
        if ( $what === 'test' )
            throw new expAiException( 'The model %model is too slow to be tested within one page request, and the job queue is not available.',
                                      'web budget: ' . $reason, 0, array( '%model' => $label ) );
        throw new expAiException( 'The model %model is too slow for field actions here (more than %seconds seconds per request). Choose another model in the model menu, or use the Intelligence tab, which runs slow models in the background.',
                                  'web budget: ' . $reason, 0, array( '%model' => $label, '%seconds' => self::seconds( $config ) ) );
    }
}
