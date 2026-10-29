<?php
/**
 * File containing the expAiExchangeRatesHandler class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The job type expai.exchange.rates (class system, no model call, #392): fetches the reference rates of the European
 * Central Bank once a day (expAiExchangeRates). Queued by the cost conversion when the stored rates are old; a failed
 * fetch keeps the last rates and is retried by the queue.
 */
class expAiExchangeRatesHandler extends expQueueHandlerBase
{
    public function validate( array $payload )
    {
        return null;
    }

    public function describe( expQueueJob $job )
    {
        return 'Exchange rates of the European Central Bank';
    }

    public function run( expQueueJob $job, expQueueContext $context )
    {
        expAiExchangeRates::disableRefresh();
        $result = expAiExchangeRates::refresh();
        if ( !$result['ok'] )
            throw new expQueueException( 'the ECB rates were not fetched: ' . $result['error'] );
        return array( 'date' => $result['date'], 'currencies' => $result['count'] );
    }
}
