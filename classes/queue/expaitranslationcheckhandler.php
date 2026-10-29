<?php
/**
 * File containing the expAiTranslationCheckHandler class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The job type expai.translation.check (class system, no model call): after a source-language version was
 * published, decides for each translation of the object and queues the small patches (doc/translation-sync.md).
 * Runs as the user who published.
 */
class expAiTranslationCheckHandler extends expQueueHandlerBase
{
    public function validate( array $payload )
    {
        return empty( $payload['object_id'] ) ? 'object_id is required' : null;
    }

    public function describe( expQueueJob $job )
    {
        $p = $job->payloadArray();
        return 'Translation check of object ' . (int) $p['object_id'] . ( !empty( $p['version'] ) ? ' version ' . (int) $p['version'] : '' );
    }

    public function run( expQueueJob $job, expQueueContext $context )
    {
        $p = $job->payloadArray();
        $object = expAiObjects::fetch( (int) $p['object_id'] );
        if ( !$object instanceof eZContentObject )
            throw new expQueuePermanentException( 'the object is gone', 'not_found' );
        $sync = new expAiTranslationSync();
        if ( !$sync->enabled() )
            return array( 'skipped' => 'disabled' );
        $result = array();
        foreach ( $sync->check( $object, array( 'trigger' => 'publish', 'user_id' => (int) $job->attribute( 'user_id' ) ) ) as $language => $row )
            $result[$language] = array( 'decision' => $row['decision'], 'state' => $row['state'], 'change_ratio' => $row['change_ratio'],
                                        'estimate_micros' => isset( $row['estimate_micros'] ) ? (int) $row['estimate_micros'] : 0,
                                        'job_uuid' => isset( $row['job_uuid'] ) ? $row['job_uuid'] : '' );
        return array( 'languages' => $result );
    }
}
