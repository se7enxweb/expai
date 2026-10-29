<?php
/**
 * File containing the expAiExchangeRates class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The reference rates of the European Central Bank for converting the prices and reported costs of a provider that
 * prices in another currency (Melious: EUR) into [Pricing] Currency (#392).
 *
 * The ECB publishes one rate per currency and working day against the euro
 * (https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml, no personal data). The file is fetched at most once
 * a day: by the job expai.exchange.rates (queued when the stored rates are older than [Pricing] ExchangeRatesMaxAgeHours,
 * once per day), by the cronjob part expai_exchange_rates.php, or with ./console ext:expai:exchange-rates. The rates are
 * kept in ezsite_data (expai_exchange_rates: date, fetched, rates). A failed fetch keeps the last good rates and logs a
 * warning.
 *
 * [Pricing] ExchangeRateSource=ecb (default): the ECB rate; [Pricing] ExchangeRates[<code>] is the fallback when there
 * is no ECB rate (yet) for the currency. ExchangeRateSource=settings: only ExchangeRates[] (an override).
 */
class expAiExchangeRates
{
    const SITE_DATA = 'expai_exchange_rates';
    const URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    /** @var array|null|false per process: the stored rates (false: none) */
    protected static $stored = null;

    /** @var bool a refresh was queued in this process */
    protected static $queued = false;

    /** @return array|null date (YYYY-MM-DD of the ECB), fetched (Unix time), rates (code => units per EUR, EUR = 1) */
    public static function stored()
    {
        if ( self::$stored === null )
        {
            self::$stored = false;
            if ( class_exists( 'eZSiteData' ) )
            {
                $row = eZSiteData::fetchByName( self::SITE_DATA );
                $data = $row ? json_decode( (string) $row->attribute( 'value' ), true ) : null;
                if ( is_array( $data ) && !empty( $data['rates'] ) && is_array( $data['rates'] ) )
                    self::$stored = $data;
            }
        }
        return self::$stored ?: null;
    }

    /** Forgets the rates read in this process (after a refresh; tests) */
    public static function reset( $data = null )
    {
        self::$stored = $data === null ? null : ( $data ?: false );
    }

    /** No refresh is queued from this process (unit tests, the refresh itself) */
    public static function disableRefresh()
    {
        self::$queued = true;
    }

    /**
     * The rate of the ECB: what one unit of $from is in $to, from the rates against the euro.
     * @param array $rates code => units per EUR
     * @return float|null
     */
    public static function cross( array $rates, $from, $to )
    {
        $rates = array_change_key_case( $rates, CASE_UPPER ) + array( 'EUR' => 1.0 );
        $from = strtoupper( (string) $from );
        $to = strtoupper( (string) $to );
        if ( !isset( $rates[$from], $rates[$to] ) || (float) $rates[$from] <= 0 || (float) $rates[$to] <= 0 )
            return null;
        return (float) $rates[$to] / (float) $rates[$from];
    }

    /**
     * The rate to use for one currency: the ECB's (with ExchangeRateSource=ecb) or the setting.
     * @return array|null rate (float), source (ecb | settings), date (YYYY-MM-DD or [Pricing] ExchangeRatesAsOf)
     */
    public static function lookup( $currency, $site, expAiConfig $config )
    {
        $currency = strtoupper( trim( (string) $currency ) );
        $site = strtoupper( trim( (string) $site ) );
        if ( strtolower( (string) $config->get( 'Pricing', 'ExchangeRateSource', 'ecb' ) ) !== 'settings' )
        {
            $stored = self::stored();
            if ( $stored && ( $rate = self::cross( $stored['rates'], $currency, $site ) ) !== null )
            {
                self::refreshIfOld( $config, $stored );
                return array( 'rate' => $rate, 'source' => 'ecb', 'date' => (string) $stored['date'] );
            }
            self::refreshIfOld( $config, $stored );
        }
        $rates = $config->get( 'Pricing', 'ExchangeRates', array() );
        $rates = is_array( $rates ) ? array_change_key_case( $rates, CASE_UPPER ) : array();
        if ( isset( $rates[$currency] ) && is_numeric( $rates[$currency] ) && (float) $rates[$currency] > 0 )
            return array( 'rate' => (float) $rates[$currency], 'source' => 'settings', 'date' => (string) $config->get( 'Pricing', 'ExchangeRatesAsOf', '' ) );
        return null;
    }

    /** Queues the job expai.exchange.rates once a day when the stored rates are old (never in a test without the queue) */
    protected static function refreshIfOld( expAiConfig $config, $stored )
    {
        $hours = $config->int( 'Pricing', 'ExchangeRatesMaxAgeHours', 26, 1 );
        if ( self::$queued || ( $stored && (int) $stored['fetched'] > time() - $hours * 3600 ) )
            return;
        self::$queued = true;
        if ( !class_exists( 'expQueue' ) || !class_exists( 'expAiQueue' ) || !expAiQueue::available() )
            return;
        try
        {
            expQueue::enqueue( 'expai.exchange.rates', array(), array( 'idempotency_key' => 'expai.exchange.rates:' . gmdate( 'Y-m-d' ) ) );
        }
        catch ( Exception $e )
        {
            eZDebug::writeWarning( 'expai: the exchange rates could not be queued: ' . $e->getMessage(), __METHOD__ );
        }
    }

    /**
     * The rates of an ECB daily file.
     * @param string $xml
     * @return array|null date, rates (code => units per EUR, EUR = 1)
     */
    public static function parse( $xml )
    {
        $xml = (string) $xml;
        if ( !preg_match( '/<Cube\s+time=[\'"](\d{4}-\d{2}-\d{2})[\'"]/', $xml, $m ) )
            return null;
        $rates = array( 'EUR' => 1.0 );
        if ( preg_match_all( '/<Cube\s+currency=[\'"]([A-Z]{3})[\'"]\s+rate=[\'"]([0-9]+(?:\.[0-9]+)?)[\'"]/', $xml, $all, PREG_SET_ORDER ) )
            foreach ( $all as $r )
                if ( (float) $r[2] > 0 )
                    $rates[$r[1]] = (float) $r[2];
        return count( $rates ) > 1 ? array( 'date' => $m[1], 'rates' => $rates ) : null;
    }

    /**
     * Fetches the ECB file and stores its rates; on any failure the stored rates stay and a warning is logged.
     * @param expAiHttpClient|null $http (tests hand in a stub)
     * @return array ok (bool), date, count, error
     */
    public static function refresh( $http = null )
    {
        $http = $http ?: new expAiHttpClient( 20, 10, 262144, array( 'content-type' ) );
        try
        {
            list( $status, $body ) = $http->request( self::URL, array(), '', array( 'method' => 'GET', 'accept' => 'application/xml, text/xml' ) );
            if ( $status !== 200 )
                throw new expAiException( 'The exchange rates could not be fetched.', 'HTTP ' . (int) $status );
            $parsed = self::parse( $body );
            if ( !$parsed )
                throw new expAiException( 'The exchange rates could not be fetched.', 'no rates in the answer' );
        }
        catch ( expAiException $e )
        {
            eZDebug::writeWarning( 'expai: the ECB exchange rates were not refreshed (' . $e->detail() . '); the last rates stay', __METHOD__ );
            return array( 'ok' => false, 'error' => $e->detail(), 'date' => ( $s = self::stored() ) ? $s['date'] : '', 'count' => 0 );
        }
        $data = array( 'date' => $parsed['date'], 'fetched' => time(), 'source' => self::URL, 'rates' => $parsed['rates'] );
        $row = eZSiteData::fetchByName( self::SITE_DATA );
        if ( !$row )
            $row = eZSiteData::create( self::SITE_DATA, '' );
        $row->setAttribute( 'value', json_encode( $data ) );
        $row->store();
        self::reset( $data );
        return array( 'ok' => true, 'date' => $parsed['date'], 'count' => count( $parsed['rates'] ) - 1, 'error' => '' );
    }

    /**
     * For the pages that show converted costs: the currencies the active providers and models price in, with their
     * rate, source and date.
     * @return array list of { currency, site, rate, source, date }
     */
    public static function inUse( expAiConfig $config )
    {
        $site = strtoupper( (string) $config->get( 'Pricing', 'Currency', 'USD' ) );
        $out = array();
        foreach ( $config->registry()->models() as $model )
        {
            $currency = isset( $model['price_currency'] ) ? (string) $model['price_currency'] : '';
            if ( $currency === '' || $currency === $site || isset( $out[$currency] ) )
                continue;
            $found = self::lookup( $currency, $site, $config );
            $out[$currency] = array( 'currency' => $currency, 'site' => $site, 'rate' => $found ? $found['rate'] : null,
                                     'source' => $found ? $found['source'] : '', 'date' => $found ? $found['date'] : '' );
        }
        return array_values( $out );
    }
}
