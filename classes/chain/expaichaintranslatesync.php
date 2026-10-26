<?php
/**
 * File containing the expAiChainTranslateSync class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The shipped translation hand-off of chains ([ChainSettings] TranslateHandler=expAiChainTranslateSync): the translate
 * step hands the run's object to the translation sync (expAiTranslationSync, doc/translation-sync.md).
 *
 * The translation sync keeps the existing translations of a published object in step with its source language. So:
 * - a published object with translations: its translations are checked now (no-op, small patches queued at once,
 *   full translations or larger patches waiting for an editor, as the sync decides);
 * - a new draft (the usual case of a chain) or an object without translations: nothing to keep in step yet; the step
 *   is skipped and says so (after publishing, the sync's publish event takes over for later changes);
 * - no translation service can take any of the languages (doc/translation-providers.md): the step is skipped with
 *   the reason of the translation service rules.
 */
class expAiChainTranslateSync
{
    /**
     * @param expAiChainRun $run
     * @param string[] $languages the step's target languages
     * @return array summary; with the key "skipped" (the reason) when nothing was handed over
     */
    public static function chainTranslate( expAiChainRun $run, array $languages )
    {
        if ( !class_exists( 'expAiTranslationSync' ) )
            return array( 'skipped' => 'the translation sync is not installed' );
        $sync = new expAiTranslationSync();
        if ( !$sync->enabled() )
            return array( 'skipped' => 'the translation sync is disabled ([TranslationSync] Enabled)' );
        $object = expAiObjects::fetch( (int) $run->attribute( 'object_id' ) );
        if ( !$object instanceof eZContentObject || (int) $object->attribute( 'status' ) !== eZContentObject::STATUS_PUBLISHED )
            return array( 'skipped' => 'the draft is not published yet; the translation sync keeps translations in step once it is' );
        $targets = array_values( array_intersect( $sync->targetLanguages( $object ), $languages ) );
        if ( !$targets )
            return array( 'skipped' => 'the object has no translation in ' . implode( ', ', $languages ) . ' to keep in step' );
        $out = array();
        $unserved = array();
        foreach ( $sync->check( $object, array( 'languages' => $targets, 'trigger' => 'chain', 'user_id' => (int) $run->attribute( 'user_id' ) ) ) as $language => $row )
        {
            $out[$language] = array( 'decision' => isset( $row['decision'] ) ? $row['decision'] : '', 'state' => isset( $row['state'] ) ? $row['state'] : '',
                                     'job_uuid' => isset( $row['job_uuid'] ) ? $row['job_uuid'] : '' );
            if ( in_array( $out[$language]['state'], array( 'no_provider', 'blocked' ), true ) )
                $unserved[] = isset( $row['provider_reason'] ) && $row['provider_reason'] !== '' ? (string) $row['provider_reason'] : $language . ': no translation service';
        }
        // not one language could be handed to a translation service: the step did nothing
        if ( $out && count( $unserved ) === count( $out ) )
            return array( 'skipped' => implode( '; ', array_unique( $unserved ) ), 'languages' => $out );
        return array( 'languages' => $out );
    }
}
