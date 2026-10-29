<?php
/**
 * File containing the expAiTranslationSyncApplier class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The applier "translation_sync" (expai.ini [QueueSettings] Appliers[translation_sync]): writes the answer of a
 * translation patch or full translation into a draft of the target language (expAiTranslationSync::applyAnswer()).
 * Idempotent: a rerun finds the draft of the job's uuid in expai_draft_log.
 */
class expAiTranslationSyncApplier implements expAiRequestApplier
{
    public function apply( expQueueJob $job, array $answer, array $params, expQueueContext $context )
    {
        if ( empty( $params['object_id'] ) || empty( $params['target'] ) || empty( $params['mode'] ) )
            throw new expQueuePermanentException( 'object_id, target and mode are required', 'params' );
        if ( !is_array( $answer['value'] ) )
        {
            $message = isset( $answer['stop_reason'] ) && $answer['stop_reason'] === 'max_tokens'
                     ? 'the answer was cut off at max_tokens; raise [TranslationSync] MaxOutputTokens or translate in parts'
                     : 'the answer is not the JSON that was asked for';
            expAiTranslationSync::saveState( (int) $params['object_id'], (string) $params['target'], array( 'state' => 'failed', 'error' => $message ) );
            throw new expQueuePermanentException( $message, 'answer' );
        }
        $context->heartbeat();
        return ( new expAiTranslationSync() )->applyAnswer( $job, $answer, $params );
    }
}
