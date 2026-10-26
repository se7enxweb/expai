<?php
/**
 * File containing the expAiCost class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The one place costs are computed: stored token counts (input, output, cache write, cache read) x the price table of
 * expai.ini [Pricing], at read time, so rows logged before a price existed get a cost too. Used by the dashboard, the
 * log, the CSV export and the limits.
 *
 * Model matching: the exact id, then the id without a date (-YYYYMMDD) or -latest suffix and without a provider
 * prefix (anthropic., us.anthropic. ...), then the family and version (claude-sonnet-5-5-... -> claude-sonnet-5-5).
 * An unknown model is priced with the Default* prices and marked approximate. [Pricing] Enabled=false switches
 * costs off.
 *
 * Since the provider registry: a request is priced, in this order, with the cost its provider reported (OpenRouter
 * usage.cost, stored as reported_cost_micros; shown "reported"), the prices of its model alias ([Model_<alias>]
 * InputPerMTok ...; a local model with prices 0 is free), then the [Pricing] table by model id as before.
 */
class expAiCost
{
    /** @return bool costs are shown */
    public static function enabled( ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        return $config->flag( 'Pricing', 'Enabled', true );
    }

    /**
     * The key of the price table a model id is priced with.
     * @param string $model
     * @param string[] $known the model ids of the price table
     * @return string|null
     */
    public static function match( $model, array $known )
    {
        $model = strtolower( trim( (string) $model ) );
        if ( $model === '' )
            return null;
        if ( in_array( $model, $known, true ) )
            return $model;
        $candidates = array();
        // provider prefixes (Amazon Bedrock: anthropic.claude-..., us.anthropic.claude-...; Vertex: claude-...@date)
        $plain = preg_replace( '/^(?:[a-z]{2}\.)?anthropic\./', '', $model );
        $plain = preg_replace( '/@\d{8}$/', '', $plain );
        $candidates[] = $plain;
        // a date or -latest suffix, and a -v1:0 style version suffix
        $base = preg_replace( '/(?:-\d{8}|-latest)(?:-v\d+(?::\d+)?)?$/', '', $plain );
        $base = preg_replace( '/-v\d+(?::\d+)?$/', '', $base );
        $candidates[] = $base;
        foreach ( $candidates as $candidate )
            if ( in_array( $candidate, $known, true ) )
                return $candidate;
        // family and version: the longest known id that the model starts with (claude-sonnet-5-5-x -> claude-sonnet-5-5)
        $best = null;
        foreach ( $known as $id )
        {
            $stem = preg_replace( '/-\d{8}$/', '', $id );
            if ( ( $base === $stem || strpos( $base, $stem . '-' ) === 0 ) && ( $best === null || strlen( $stem ) > strlen( $best[1] ) ) )
                $best = array( $id, $stem );
        }
        return $best ? $best[0] : null;
    }

    /**
     * The prices (per million tokens) for a model and prompt size.
     * @return array input, output, cache_write, cache_read, approx (bool: fallback prices), key (the table row used or 'default')
     */
    public static function prices( $model, $promptTokens = 0, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $table = function ( $name ) use ( $config )
        {
            $list = $config->get( 'Pricing', $name, array() );
            return is_array( $list ) ? $list : array();
        };
        $input = $table( 'InputPerMTok' );
        $key = self::match( $model, array_map( 'strval', array_keys( $input ) ) );
        $multiplier = (float) $config->get( 'Pricing', 'Multiplier', 1 );
        $multiplier = $multiplier > 0 ? $multiplier : 1.0;
        if ( $key === null )
        {
            $get = function ( $name ) use ( $config, $multiplier ) { return (float) $config->get( 'Pricing', $name, 0 ) * $multiplier; };
            return array( 'input' => $get( 'DefaultInputPerMTok' ), 'output' => $get( 'DefaultOutputPerMTok' ),
                          'cache_write' => $get( 'DefaultCacheWritePerMTok' ), 'cache_read' => $get( 'DefaultCacheReadPerMTok' ),
                          'approx' => true, 'key' => 'default' );
        }
        $thresholds = $table( 'LongContextThreshold' );
        $suffix = isset( $thresholds[$key] ) && (int) $thresholds[$key] > 0 && $promptTokens > (int) $thresholds[$key] ? 'Long' : '';
        $price = function ( $name ) use ( $table, $key, $suffix, $multiplier )
        {
            $list = $table( $name . $suffix );
            if ( !isset( $list[$key] ) && $suffix !== '' )
                $list = $table( $name );
            return isset( $list[$key] ) && is_numeric( $list[$key] ) ? (float) $list[$key] * $multiplier : 0.0;
        };
        return array( 'input' => $price( 'InputPerMTok' ), 'output' => $price( 'OutputPerMTok' ), 'cache_write' => $price( 'CacheWritePerMTok' ),
                      'cache_read' => $price( 'CacheReadPerMTok' ), 'approx' => false, 'key' => $key );
    }

    /**
     * The prices of a model alias from its [Model_<alias>] group, when it has input and output prices.
     * @return array|null input, output, cache_write, cache_read, approx (false), key (alias:<alias>)
     */
    public static function aliasPrices( $alias, ?expAiConfig $config = null )
    {
        $alias = (string) $alias;
        if ( $alias === '' )
            return null;
        $config = $config ?: expAiConfig::instance();
        $model = $config->registry()->getModel( $alias );
        if ( !$model || $model['prices']['input'] === null || $model['prices']['output'] === null )
            return null;
        $multiplier = (float) $config->get( 'Pricing', 'Multiplier', 1 );
        $multiplier = $multiplier > 0 ? $multiplier : 1.0;
        $p = $model['prices'];
        return array( 'input' => $p['input'] * $multiplier, 'output' => $p['output'] * $multiplier,
                      'cache_write' => ( $p['cache_write'] !== null ? $p['cache_write'] : $p['input'] ) * $multiplier,
                      'cache_read' => ( $p['cache_read'] !== null ? $p['cache_read'] : $p['input'] ) * $multiplier,
                      'approx' => false, 'key' => 'alias:' . $alias );
    }

    /**
     * The cost of one request.
     * @param string $alias the model alias of the request ('' for rows logged before the registry)
     * @param int $reportedMicros the cost the provider reported, in millionths of the currency (0: none)
     * @return array amount (float), approx (bool), key, source (reported | alias | table | default)
     */
    public static function of( $model, $input, $output, $cacheWrite, $cacheRead, $alias = '', $reportedMicros = 0 )
    {
        if ( (int) $reportedMicros > 0 )
            return array( 'amount' => (int) $reportedMicros / 1000000, 'approx' => false, 'key' => 'reported', 'source' => 'reported' );
        // a translation service (model_alias translation.<id>, expAiTranslationRecorder) bills characters, not tokens:
        // its cost is what the recorder stored, also when that is nothing (a local service, PricePerMChars=0)
        if ( strpos( (string) $alias, 'translation.' ) === 0 )
            return array( 'amount' => 0.0, 'approx' => false, 'key' => 'reported', 'source' => 'reported' );
        $prompt = (int) $input + (int) $cacheWrite + (int) $cacheRead;
        $p = self::aliasPrices( $alias );
        $source = 'alias';
        if ( !$p )
        {
            $p = self::prices( $model, $prompt );
            $source = $p['approx'] ? 'default' : 'table';
            // a model of another provider without prices is not priced like a Claude model: its cost is unknown
            if ( $p['approx'] && (string) $alias !== '' && strpos( (string) $alias, 'anthropic.' ) !== 0 )
            {
                $known = expAiConfig::instance()->registry()->getModel( $alias );
                if ( !$known || $known['provider'] !== 'anthropic' )
                    return array( 'amount' => 0.0, 'approx' => true, 'key' => 'unknown', 'source' => 'unknown' );
            }
        }
        $amount = ( (int) $input * $p['input'] + (int) $output * $p['output'] + (int) $cacheWrite * $p['cache_write']
                  + (int) $cacheRead * $p['cache_read'] ) / 1000000;
        return array( 'amount' => $amount, 'approx' => $p['approx'], 'key' => $p['key'], 'source' => $source );
    }

    /**
     * The relative cost of a model for the picker, by its output price: free, $ (< 1 per million tokens),
     * $$ (< 8), $$$.
     * @return string
     */
    public static function costClass( $alias, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $p = self::aliasPrices( $alias, $config );
        if ( !$p )
        {
            $model = $config->registry()->getModel( $alias );
            $p = self::prices( $model ? $model['id'] : $alias, 0, $config );
        }
        $out = (float) $p['output'];
        if ( $out <= 0 )
            return 'free';
        return $out < 1 ? '$' : ( $out < 8 ? '$$' : '$$$' );
    }

    /**
     * The factor that converts an amount in another currency into [Pricing] Currency ([Pricing] ExchangeRates[<code>]:
     * what one unit of it is in Currency). A provider that prices in EUR (Melious) is priced and its reported cost
     * stored in the site's currency with it.
     * @param string $currency an ISO code ('' or the site's own: 1.0)
     * @return float|null null when no rate is configured (the cost is then unknown)
     */
    public static function rate( $currency, ?expAiConfig $config = null )
    {
        $currency = strtoupper( trim( (string) $currency ) );
        $config = $config ?: expAiConfig::instance();
        $site = strtoupper( trim( (string) $config->get( 'Pricing', 'Currency', 'USD' ) ) );
        if ( $currency === '' || $currency === $site )
            return 1.0;
        // #392 the reference rate of the European Central Bank (expAiExchangeRates), else [Pricing] ExchangeRates[]
        // (a process that started before the class existed, e.g. a long-running worker, uses the settings)
        if ( class_exists( 'expAiExchangeRates' ) )
        {
            $found = expAiExchangeRates::lookup( $currency, $site, $config );
            return $found ? (float) $found['rate'] : null;
        }
        $rates = $config->get( 'Pricing', 'ExchangeRates', array() );
        $rates = is_array( $rates ) ? array_change_key_case( $rates, CASE_UPPER ) : array();
        return isset( $rates[$currency] ) && is_numeric( $rates[$currency] ) && (float) $rates[$currency] > 0 ? (float) $rates[$currency] : null;
    }

    /** @return string the currency symbol of [Pricing] Currency */
    public static function symbol()
    {
        $currency = strtoupper( expAiUsage::currency() );
        $symbols = array( 'USD' => '$', 'EUR' => "\u{20AC}", 'GBP' => "\u{00A3}", 'JPY' => "\u{00A5}" );
        return isset( $symbols[$currency] ) ? $symbols[$currency] : $currency . ' ';
    }

    /** @return string a number with the locale's separators */
    public static function number( $value, $decimals = 0 )
    {
        $locale = eZLocale::instance();
        $dec = (string) $locale->attribute( 'decimal_symbol' );
        $sep = (string) $locale->attribute( 'thousands_separator' );
        return number_format( (float) $value, (int) $decimals, $dec !== '' ? $dec : '.', $sep );
    }

    /** @return string an amount as money: "$0.06", "< $0.01", "~$0.06" when approximate */
    public static function money( $amount, $approx = false )
    {
        $amount = (float) $amount;
        $prefix = $approx ? "\u{2248}" : '';
        if ( $amount > 0 && $amount < 0.01 )
            return '< ' . $prefix . self::symbol() . self::number( 0.01, 2 );
        return $prefix . self::symbol() . self::number( $amount, 2 );
    }

    /** @return string tokens abbreviated: 950, 21.6k, 1.2M */
    public static function tokens( $value )
    {
        $value = (float) $value;
        if ( $value >= 1000000 )
            return self::number( $value / 1000000, $value >= 10000000 ? 0 : 1 ) . 'M';
        if ( $value >= 1000 )
            return self::number( $value / 1000, $value >= 10000 ? 0 : 1 ) . 'k';
        return self::number( $value );
    }
}
