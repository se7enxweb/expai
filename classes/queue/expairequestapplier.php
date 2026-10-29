<?php
/**
 * File containing the expAiRequestApplier interface.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Applies the answer of a queued request (expai.request), e.g. writes it into a draft. Registered in expai.ini
 * [QueueSettings] Appliers[<name>]=<class>, named by the producer with the option apply (and apply_params).
 *
 * apply() runs in the worker as the user who queued the request, after the answer was stored in the job's checkpoint.
 * It must be idempotent: after a crash it runs again with the same answer (find what an earlier run wrote, e.g. the
 * draft of expai_draft_log with the job's uuid).
 */
interface expAiRequestApplier
{
    /**
     * @param expQueueJob $job
     * @param array $answer text, value (decoded JSON when the request had an output_schema), tokens, model, lane
     * @param array $params the producer's apply_params
     * @param expQueueContext $context
     * @return array what the job's result shows of the application (never a secret)
     * @throws expQueuePermanentException|expQueueRetryableException
     */
    public function apply( expQueueJob $job, array $answer, array $params, expQueueContext $context );
}
