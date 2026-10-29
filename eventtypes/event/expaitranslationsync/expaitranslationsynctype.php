<?php
/**
 * File containing the expAiTranslationSyncType class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The workflow event "Exponential Intelligence: translation sync" (event_expaitranslationsync). Put it in a workflow
 * on the trigger content / publish / after: when the published version is in the object's source language, the
 * object's translations are checked (a queue job) and small patches are queued (doc/translation-sync.md).
 * It never stops a publish: every outcome, failures included, is "accepted".
 */
class expAiTranslationSyncType extends eZWorkflowEventType
{
    const WORKFLOW_TYPE_STRING = 'expaitranslationsync';

    public function __construct()
    {
        parent::__construct( self::WORKFLOW_TYPE_STRING, ezpI18n::tr( 'extension/expai/translation', 'Exponential Intelligence: translation sync' ) );
        $this->setTriggerTypes( array( 'content' => array( 'publish' => array( 'after' ) ) ) );
    }

    public function execute( $process, $event )
    {
        $parameters = $process->attribute( 'parameter_list' );
        $objectID = isset( $parameters['object_id'] ) ? (int) $parameters['object_id'] : 0;
        $version = isset( $parameters['version'] ) ? (int) $parameters['version'] : 0;
        if ( !$objectID || !$version )
            return eZWorkflowType::STATUS_ACCEPTED;
        try
        {
            $outcome = ( new expAiTranslationSync() )->onPublish( $objectID, $version, (int) eZUser::currentUserID() );
            eZDebug::writeNotice( "translation sync of object $objectID version $version: $outcome", __METHOD__ );
        }
        catch ( Exception $e )
        {
            eZDebug::writeError( 'translation sync of object ' . $objectID . ' failed: ' . $e->getMessage(), __METHOD__ );
        }
        return eZWorkflowType::STATUS_ACCEPTED;
    }
}

eZWorkflowEventType::registerEventType( expAiTranslationSyncType::WORKFLOW_TYPE_STRING, 'expAiTranslationSyncType' );
