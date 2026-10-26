<?php
/**
 * File containing the expAiTranslationRecorder class.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * What expAiUsageRecorder does for a model, for a machine translation service: before the request the master switch
 * and the veto (expAiGate, provider = the service's id, so [RequestVeto] Providers[] applies) and the cost limits of
 * [Limits] (expAiLimits; the token limits do not count characters); after it one row in expai_usage_log with
 * provider = the service's id, model = its engine, model_alias = translation.<id>, no tokens, the cost in
 * reported_cost_micros (billed characters x PricePerMChars), the billed characters (billed_chars), region, retention,
 * latency, HTTP status and the job. The row also writes the audit event system.expai.request (or .failed), as for a
 * model. A request that fails leaves a row with the error.
 */
class expAiTranslationRecorder
{
    /** @var expAiTranslationProvider */
    protected $provider;

    /** @var array */
    protected $context;

    /** @var expAiUsageLog|null the row of the last request */
    public $last;

    /** @var expAiUsageLog|null the reservation of the request under way */
    protected $pending = null;

    /**
     * @param array $context user_id, node_id, action, job_uuid, siteaccess
     */
    public function __construct( expAiTranslationProvider $provider, array $context = array() )
    {
        $this->provider = $provider;
        $this->context = $context + array( 'node_id' => 0, 'action' => '', 'job_uuid' => '' );
        if ( !isset( $this->context['user_id'] ) )
            $this->context['user_id'] = (int) eZUser::currentUserID();
    }

    /** @return string the service's id (expAiEditForm::mark() records it as the provider of the draft) */
    public function providerId()
    {
        return $this->provider->id();
    }

    /** @return string the model alias of the usage log */
    public function modelAlias()
    {
        return 'translation.' . $this->provider->id();
    }

    /**
     * @return expAiTranslationResult
     * @throws expAiException|expAiVetoException
     */
    public function translate( expAiTranslationRequest $request )
    {
        $config = expAiConfig::instance();
        $id = $this->provider->id();
        $action = expAiText::cut( (string) $this->context['action'], 40 );
        if ( class_exists( 'expAiGate' ) )
            expAiGate::check( array( 'user_id' => (int) $this->context['user_id'], 'node_id' => (int) $this->context['node_id'], 'provider' => $id,
                                     'model' => $id, 'model_alias' => $this->modelAlias(), 'purpose' => $action,
                                     'region' => $this->provider->region(), 'retention' => $this->provider->retention() )
                              + ( !empty( $this->context['siteaccess'] ) ? array( 'siteaccess' => (string) $this->context['siteaccess'] ) : array() ), $config );
        $row = array( 'provider' => $id, 'model' => $id, 'model_alias' => $this->modelAlias(), 'tool_mode' => 'none',
                      'region' => $this->provider->region(), 'retention' => $this->provider->retention(),
                      'user_id' => (int) $this->context['user_id'], 'node_id' => (int) $this->context['node_id'], 'action' => $action,
                      'session_key' => expAiLimits::sessionKey() );
        if ( (string) $this->context['job_uuid'] !== '' && self::hasField( 'job_uuid' ) )
            $row['job_uuid'] = (string) $this->context['job_uuid'];
        $estimate = $this->provider->estimate( $request );
        // with limits set, the request reserves its estimate first (as expAiUsageRecorder does)
        $this->pending = null;
        if ( expAiLimits::anySet( $config ) && expAiUsageLog::reservationColumns() && (int) $estimate['micros'] > 0 )
        {
            expAiUsage::cleanStaleReservations();
            $this->pending = expAiUsage::record( $row + array( 'reserved_tokens' => 0, 'reserved_micros' => (int) $estimate['micros'] ), false );
        }
        try
        {
            // the cost limits only: a translation service has no tokens
            expAiLimits::check( array( 'user_id' => (int) $this->context['user_id'], 'provider' => $id, 'units' => array( 'cost' ),
                                       'reservation_id' => $this->pending ? (int) $this->pending->attribute( 'id' ) : 0 ) );
        }
        catch ( Throwable $e )
        {
            if ( $this->pending )
                $this->pending->remove();
            $this->pending = null;
            throw $e;
        }
        $start = microtime( true );
        try
        {
            $result = $this->provider->translate( $request );
        }
        catch ( Throwable $e )
        {
            $row['latency_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );
            $row += expAiUsageRecorder::errorFields( $e );
            // the requests before the failed one were billed: their cost is in this row
            if ( $e instanceof expAiTranslationException && !empty( $e->partial ) )
            {
                $row['reported_cost_micros'] = max( 0, (int) $e->partial['cost_micros'] );
                if ( expAiUsageLog::billedCharsColumn() )
                    $row['billed_chars'] = max( 0, (int) $e->partial['billed'] );
            }
            $this->last = $this->store( $row );
            throw $e;
        }
        $row = self::resultFields( $result, $row );
        $row['latency_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );
        $this->last = $this->store( $row );
        return $result;
    }

    /** @return array the fields of a usage row from a result */
    public static function resultFields( expAiTranslationResult $result, array $row )
    {
        if ( $result->engine !== '' )
            $row['model'] = $result->engine;
        $row['input_tokens'] = 0;
        $row['output_tokens'] = 0;
        $row['reported_cost_micros'] = max( 0, (int) $result->costMicros );
        if ( expAiUsageLog::billedCharsColumn() )
            $row['billed_chars'] = max( 0, (int) $result->billedChars );
        $row['stop_reason'] = $result->stopReason;
        $row['http_status'] = (int) $result->httpStatus;
        $row['retries'] = (int) $result->retries;
        if ( $result->errors )
        {
            $row['error_class'] = 'markup';
            $row['error_message'] = 'units ' . implode( ', ', array_keys( $result->errors ) ) . ': ' . implode( '; ', array_unique( $result->errors ) );
        }
        return $row;
    }

    /** @return bool the usage log has the field (the lane columns come with the queue schema) */
    protected static function hasField( $name )
    {
        $definition = expAiUsageLog::definition();
        return isset( $definition['fields'][$name] );
    }

    /** @return expAiUsageLog the row: the reservation settled, else a new row */
    protected function store( array $row )
    {
        $pending = $this->pending;
        $this->pending = null;
        return $pending ? expAiUsage::settle( $pending, $row ) : expAiUsage::record( $row );
    }
}
