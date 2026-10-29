#!/usr/bin/env php
<?php
/**
 * ./console ext:expai:exchange-rates — Fetches the reference rates of the European Central Bank (the conversion of
 * prices in another currency, e.g. Melious in EUR) and prints the rates in use.
 *
 *   --show     only print the stored rates and the rates in use (no fetch)
 *
 * The rates are also refreshed by the job expai.exchange.rates (queued once a day when they are older than
 * [Pricing] ExchangeRatesMaxAgeHours) and by the cronjob part expai_exchange_rates.php. A failed fetch keeps the last
 * rates and says so.
 *
 * -s <siteaccess> is mandatory: the script prints the siteaccess and its database (stderr) and does not run
 * where expai is not active (expAiScript).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 * @description Exchange rates: fetch the ECB reference rates and show the rates in use
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array( 'description' => "Exchange rates of Exponential Intelligence: the ECB reference rates\n",
                                     'use-session' => false, 'use-modules' => true, 'use-extensions' => true ) );
$script->startup();
$options = $script->getOptions( '[show]', '', array( 'show' => 'only print the stored rates and the rates in use' ) );
expAiScript::requireSiteAccess( $script, $options );
$script->initialize();
expAiScript::announce( $script );

$config = expAiConfig::instance();
expAiExchangeRates::disableRefresh();
$ok = true;
if ( !$options['show'] )
{
    $result = expAiExchangeRates::refresh();
    $ok = $result['ok'];
    $cli->output( $result['ok'] ? 'fetched the ECB rates of ' . $result['date'] . ' (' . $result['count'] . ' currencies)'
                                : 'the ECB rates were not fetched (' . $result['error'] . '); the last rates stay' . ( $result['date'] ? ' (' . $result['date'] . ')' : '' ) );
}
$stored = expAiExchangeRates::stored();
$cli->output( $stored ? 'stored: ECB ' . $stored['date'] . ', fetched ' . date( 'Y-m-d H:i', (int) $stored['fetched'] ) : 'stored: none' );
foreach ( expAiExchangeRates::inUse( $config ) as $row )
    $cli->output( sprintf( '1 %s = %s %s (%s%s)', $row['currency'], $row['rate'] !== null ? round( $row['rate'], 4 ) : '?', $row['site'],
                           $row['source'] !== '' ? $row['source'] : 'no rate', $row['date'] !== '' ? ', ' . $row['date'] : '' ) );
$cli->output( $ok ? 'PASS' : 'FAIL' );
$script->shutdown( $ok ? 0 : 1 );
