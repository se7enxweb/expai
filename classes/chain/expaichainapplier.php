<?php
/**
 * File containing the expAiChainApplier class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The applier "chain" of queued requests ([QueueSettings] Appliers[chain]): hands the stored answer of a chain step
 * to expAiChainEngine::onAnswer(), which records it and queues the next step. Runs in the worker as the run's user.
 */
class expAiChainApplier implements expAiRequestApplier
{
    public function apply( expQueueJob $job, array $answer, array $params, expQueueContext $context )
    {
        $context->throwIfCancelled();
        return ( new expAiChainEngine() )->onAnswer( $job, $answer, $params );
    }
}
