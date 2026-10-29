<?php
/**
 * Cronjob expai_exchange_rates: refreshes the reference rates of the European Central Bank once a day (the conversion
 * of prices in another currency, expAiExchangeRates). Does nothing while the stored rates are younger than
 * [Pricing] ExchangeRatesMaxAgeHours; a failed fetch keeps the last rates (a warning is logged).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** @var eZCLI $cli */
$config = expAiConfig::instance();
expAiExchangeRates::disableRefresh();
$stored = expAiExchangeRates::stored();
$hours = $config->int( 'Pricing', 'ExchangeRatesMaxAgeHours', 26, 1 );
if ( strtolower( (string) $config->get( 'Pricing', 'ExchangeRateSource', 'ecb' ) ) === 'settings' || ( $stored && (int) $stored['fetched'] > time() - $hours * 3600 ) )
    return;
$result = expAiExchangeRates::refresh();
if ( !$isQuiet )
    $cli->output( $result['ok'] ? 'expai: ECB exchange rates of ' . $result['date'] : 'expai: ECB exchange rates not fetched (' . $result['error'] . ')' );
