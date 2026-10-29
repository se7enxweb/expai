<?php
/**
 * File containing the expAiLane class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A lane: one way of sending a request to a provider, with its own price, speed and retention (expai.ini
 * [Lane_<provider>.<lane>]). The lanes of a provider are its [Lane_<provider>.*] groups (restricted by
 * [Provider_<id>] Lanes[] when that is set); every provider has the lane standard, with or without a group. A model
 * can use a lane when the lane is enabled, its Models[] is empty or names the model's alias, and the model's own
 * [Model_<alias>] Lanes[] (when set) names the lane.
 *
 *   Type             sync (one call, answered in the request) | batch (submitted, polled, results fetched later)
 *   Enabled          true | false (a lane whose terms or price were not confirmed ships disabled)
 *   PriceMultiplier  the factor on the list price (batch 0.5, fast 2.0); empty: unknown, the lane is not used
 *   ExpectedLatency  typical seconds until the answer (for deadlines); MaxLatency the hard bound (batch: 86400)
 *   MaxConcurrent    requests of the lane at once (the expqueue resource <provider>:<lane>)
 *   Retention        inherit (the provider's) | none | zdr | 30d | 29d | 6w | unknown: what the provider keeps of a
 *                    request of this lane (a batch keeps requests and results until they are deleted or expire)
 *   RequestParams[]  values merged into the request body (service_tier=flex, speed=fast)
 *   Headers[]        extra request headers ("anthropic-beta: fast-mode-2026-02-01")
 *   FallbackLane     the lane a request goes to when this one is busy (429/529) or unavailable
 *   OnUnavailable    retry | escalate: what a "resource unavailable" answer of a flex lane does
 *   Timeout          seconds of one call of a sync lane (default: the provider's)
 *   Models[]         only these model aliases (empty: every model of the provider)
 *   BatchApi         anthropic | openai | gemini | mistral | openrouter (Type=batch)
 *   BatchURL         the batch API base when it is not derived from the provider's URL (Gemini)
 *   MaxRequestsPerBatch, BatchCollectSeconds, PollSeconds, DeleteAfterFetch, ExpiredLane (Type=batch)
 *   Verified, Source the day the offer was read and where (documentation only)
 *
 * Read fresh for every call (no static state: Velocity's workers serve many siteaccesses).
 */
class expAiLane
{
    /** The lane names expai knows */
    const NAMES = array( 'standard', 'flex', 'batch', 'fast', 'priority' );

    /** @var array */
    protected $data;

    protected function __construct( array $data )
    {
        $this->data = $data;
    }

    /** @return mixed a property: provider, name, key (provider:lane), type, enabled, multiplier ... */
    public function get( $name, $default = null )
    {
        return array_key_exists( $name, $this->data ) ? $this->data[$name] : $default;
    }

    /** @return array every property */
    public function toArray()
    {
        return $this->data;
    }

    /** @return string provider:lane, the expqueue resource of the lane */
    public function key()
    {
        return $this->data['provider'] . ':' . $this->data['name'];
    }

    /** @return string */
    public function name()
    {
        return $this->data['name'];
    }

    /** @return bool */
    public function isBatch()
    {
        return $this->data['type'] === 'batch';
    }

    /** @return bool the lane may be used: enabled and with a known price */
    public function usable()
    {
        return $this->data['enabled'] && $this->data['multiplier'] !== null;
    }

    /** @return int the price factor in percent (100 = list price) */
    public function factorPercent()
    {
        return $this->data['multiplier'] === null ? 100 : (int) round( $this->data['multiplier'] * 100 );
    }

    /**
     * The price factor a request was billed at, from the tier the provider reported: a fast or flex request the
     * provider served at its standard tier (Anthropic usage.speed standard, OpenAI service_tier default) is billed at
     * the list price.
     * @return int percent
     */
    public function billedFactor( $tier )
    {
        $tier = strtolower( (string) $tier );
        if ( $this->data['name'] !== 'standard' && !$this->isBatch() && in_array( $tier, array( 'default', 'standard', 'auto' ), true ) )
            return 100;
        return $this->factorPercent();
    }

    /** @return string none | zdr | 30d | 29d | 6w | unknown: the retention of the lane (inherit resolved) */
    public function retention()
    {
        return $this->data['retention'];
    }

    /** @return bool the lane keeps nothing (meets a zero-retention rule) */
    public function zeroRetention()
    {
        return in_array( $this->data['retention'], array( 'none', 'zdr' ), true ) || $this->data['region'] === 'local';
    }

    // ---- lookup ----------------------------------------------------------------------------------------------

    /**
     * The lanes of a provider.
     * @return expAiLane[] name => lane (standard first)
     */
    public static function forProvider( $providerID, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $provider = $config->registry()->getProvider( (string) $providerID );
        if ( !$provider )
            return array();
        $names = array( 'standard' );
        foreach ( array_keys( $config->ini()->groups() ) as $group )
            if ( strpos( $group, 'Lane_' . $providerID . '.' ) === 0 )
            {
                $name = substr( $group, strlen( 'Lane_' . $providerID . '.' ) );
                if ( preg_match( '/^[a-z][a-z0-9_]{0,15}$/', $name ) && !in_array( $name, $names, true ) )
                    $names[] = $name;
            }
        $only = isset( $provider['Lanes'] ) && is_array( $provider['Lanes'] ) ? array_values( array_filter( array_map( 'trim', $provider['Lanes'] ), 'strlen' ) ) : array();
        $out = array();
        foreach ( $names as $name )
        {
            if ( $only && $name !== 'standard' && !in_array( $name, $only, true ) )
                continue;
            $out[$name] = self::build( $config, $provider, $name );
        }
        return $out;
    }

    /** @return expAiLane|null one lane of a provider */
    public static function fetch( $providerID, $name, ?expAiConfig $config = null )
    {
        $lanes = self::forProvider( $providerID, $config );
        return isset( $lanes[$name] ) ? $lanes[$name] : null;
    }

    /**
     * The lanes a model can use (usable or not: see usable()).
     * @return expAiLane[] name => lane
     */
    public static function forModel( $alias, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $model = $config->registry()->getModel( (string) $alias );
        if ( !$model )
            return array();
        $modelLanes = isset( $model['settings']['Lanes'] ) && is_array( $model['settings']['Lanes'] )
                    ? array_values( array_filter( array_map( 'trim', $model['settings']['Lanes'] ), 'strlen' ) ) : array();
        $out = array();
        foreach ( self::forProvider( $model['provider'], $config ) as $name => $lane )
        {
            if ( $modelLanes && $name !== 'standard' && !in_array( $name, $modelLanes, true ) )
                continue;
            $models = $lane->get( 'models' );
            if ( $models && !in_array( $model['alias'], $models, true ) && !in_array( $model['id'], $models, true ) )
                continue;
            $out[$name] = $lane;
        }
        return $out;
    }

    /** @return expAiLane|null a usable lane of a model */
    public static function forModelLane( $alias, $name, ?expAiConfig $config = null )
    {
        $lanes = self::forModel( $alias, $config );
        return isset( $lanes[$name] ) && $lanes[$name]->usable() ? $lanes[$name] : null;
    }

    /** @return expAiLane the lane from its group (or the implicit standard lane) */
    protected static function build( expAiConfig $config, array $provider, $name )
    {
        $id = (string) $provider['ProviderId'];
        $group = 'Lane_' . $id . '.' . $name;
        $v = $config->ini()->hasGroup( $group ) ? (array) $config->ini()->group( $group ) : array();
        $get = function ( $key, $default = '' ) use ( $v )
        {
            return isset( $v[$key] ) && $v[$key] !== '' && $v[$key] !== array() ? $v[$key] : $default;
        };
        $flag = function ( $key, $default ) use ( $get )
        {
            return in_array( strtolower( trim( (string) $get( $key, $default ) ) ), array( 'enabled', 'true', '1', 'yes', 'on' ), true );
        };
        $list = function ( $key ) use ( $v )
        {
            return isset( $v[$key] ) && is_array( $v[$key] ) ? array_values( array_filter( array_map( 'trim', array_map( 'strval', $v[$key] ) ), 'strlen' ) ) : array();
        };
        $type = strtolower( (string) $get( 'Type', $name === 'batch' ? 'batch' : 'sync' ) ) === 'batch' ? 'batch' : 'sync';
        $multiplier = $get( 'PriceMultiplier', $name === 'standard' ? '1' : '' );
        $multiplier = is_numeric( $multiplier ) && (float) $multiplier > 0 ? (float) $multiplier : null;
        $retention = strtolower( trim( (string) $get( 'Retention', 'inherit' ) ) );
        if ( $retention === 'inherit' || $retention === '' )
            $retention = (string) $provider['Retention'];
        if ( !in_array( $retention, array( 'none', 'zdr', '30d', '29d', '6w', 'unknown' ), true ) )
            $retention = 'unknown';
        $params = isset( $v['RequestParams'] ) && is_array( $v['RequestParams'] ) ? $v['RequestParams'] : array();
        $cleanParams = array();
        foreach ( $params as $key => $value )
            if ( is_string( $key ) && preg_match( '/^[a-z_]{1,40}$/', $key ) && !in_array( $key, array( 'model', 'messages', 'tools', 'system', 'stream' ), true ) && $value !== '' )
                $cleanParams[$key] = is_numeric( $value ) ? $value + 0 : (string) $value;
        $headers = array();
        foreach ( $list( 'Headers' ) as $line )
            if ( !preg_match( '/[\r\n]/', $line ) && preg_match( '/^([A-Za-z0-9-]{1,60}):\s*(.+)$/', $line, $m )
                 && !in_array( strtolower( $m[1] ), array( 'authorization', 'x-api-key', 'api-key', 'x-goog-api-key', 'host', 'content-length', 'content-type' ), true ) )
                $headers[] = $m[1] . ': ' . $m[2];
        $data = array(
            'provider' => $id,
            'provider_label' => (string) $provider['Label'],
            'provider_type' => (string) $provider['Type'],
            'region' => (string) $provider['Region'],
            'name' => $name,
            'key' => $id . ':' . $name,
            'configured' => (bool) $v,
            'type' => $type,
            'enabled' => $flag( 'Enabled', $name === 'standard' ? 'true' : ( $v ? 'true' : 'false' ) ),
            'multiplier' => $multiplier,
            'expected_latency' => max( 1, (int) $get( 'ExpectedLatency', $type === 'batch' ? 3600 : 60 ) ),
            'max_latency' => max( 1, (int) $get( 'MaxLatency', $type === 'batch' ? 86400 : (int) $get( 'ExpectedLatency', 60 ) * 4 ) ),
            'max_concurrent' => max( 1, (int) $get( 'MaxConcurrent', 4 ) ),
            'retention' => $retention,
            'params' => $cleanParams,
            'headers' => $headers,
            'fallback' => preg_replace( '/[^a-z0-9_]/', '', (string) $get( 'FallbackLane', '' ) ),
            'on_unavailable' => strtolower( (string) $get( 'OnUnavailable', 'retry' ) ) === 'escalate' ? 'escalate' : 'retry',
            'timeout' => (int) $get( 'Timeout', 0 ),
            'models' => $list( 'Models' ),
            'batch_api' => preg_replace( '/[^a-z]/', '', strtolower( (string) $get( 'BatchApi', $type === 'batch' ? (string) $provider['Type'] : '' ) ) ),
            'batch_url' => (string) $get( 'BatchURL', '' ),
            'max_per_batch' => max( 1, min( 100000, (int) $get( 'MaxRequestsPerBatch', 2000 ) ) ),
            'collect_seconds' => max( 0, (int) $get( 'BatchCollectSeconds', 300 ) ),
            'poll_seconds' => max( 5, (int) $get( 'PollSeconds', 300 ) ),
            'delete_after_fetch' => $flag( 'DeleteAfterFetch', 'enabled' ),
            'expired_lane' => preg_replace( '/[^a-z0-9_]/', '', (string) $get( 'ExpiredLane', 'standard' ) ),
            'verified' => (string) $get( 'Verified', '' ),
            'source' => (string) $get( 'Source', '' ),
            'label' => (string) $get( 'Label', $name ),
        );
        if ( $data['batch_api'] === 'openaichat' )
            $data['batch_api'] = 'openai';
        return new self( $data );
    }

    // ---- the provider of a lane ----------------------------------------------------------------------------------

    /**
     * The settings a provider object of this lane is built with: the model's (expAiProviderRegistry::settingsFor())
     * with the lane's request parameters, headers and timeout.
     * @return array (contains the key: never log or output it)
     */
    public function providerSettings( $alias, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $settings = $config->registry()->settingsFor( $alias );
        if ( !$settings )
            return array();
        if ( $this->data['timeout'] > 0 )
            $settings['Timeout'] = $this->data['timeout'];
        if ( $this->data['headers'] )
            $settings['Headers'] = array_merge( isset( $settings['Headers'] ) ? (array) $settings['Headers'] : array(), $this->data['headers'] );
        if ( $this->data['params'] )
        {
            if ( $settings['Type'] === 'anthropic' )
                $settings['LaneParams'] = $this->data['params'];
            else
            {
                $extra = isset( $settings['ExtraBody'] ) ? json_decode( (string) $settings['ExtraBody'], true ) : null;
                $extra = is_array( $extra ) ? $extra : array();
                $settings['ExtraBody'] = json_encode( array_replace( $extra, $this->data['params'] ) );
            }
        }
        $settings['Lane'] = $this->data['name'];
        return $settings;
    }

    /**
     * The provider object for a model on this lane.
     * @return expAiProvider
     * @throws expAiException
     */
    public function provider( $alias, ?expAiConfig $config = null )
    {
        $settings = $this->providerSettings( $alias, $config );
        if ( !$settings )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'no model ' . $alias . ' in the provider registry' );
        $types = expAiProviderRegistry::TYPES;
        $type = (string) $settings['Type'];
        if ( $type === 'custom' )
        {
            if ( $this->data['name'] !== 'standard' )
                throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'a custom provider has only the lane standard' );
            return ( $config ?: expAiConfig::instance() )->registry()->providerFor( $alias );
        }
        if ( !isset( $types[$type] ) )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'unknown provider Type ' . preg_replace( '/[^\w-]/', '', $type ) );
        $class = $types[$type];
        return new $class( $settings );
    }

    /**
     * The data policy for requests of this lane: the provider's region with the lane's retention.
     * @return expAiDataPolicy
     */
    public function dataPolicy( ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $provider = $config->registry()->getProvider( $this->data['provider'] ) ?: array( 'ProviderId' => $this->data['provider'], 'Label' => $this->data['provider'] );
        // the policy knows none | zdr | 30d | unknown; a lane that keeps data for days counts as keeping it
        $provider['Retention'] = in_array( $this->data['retention'], array( 'none', 'zdr' ), true ) ? $this->data['retention']
                               : ( $this->data['retention'] === 'unknown' ? 'unknown' : '30d' );
        $provider['Label'] = (string) ( isset( $provider['Label'] ) ? $provider['Label'] : $this->data['provider'] ) . ' (' . $this->data['name'] . ')';
        return new expAiDataPolicy( $config, $provider );
    }
}
