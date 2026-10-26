<?php
/**
 * File containing the expAiTranslationSyncServerFunctions class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The ezjscore functions of the "Translations" section of the Intelligence tab (ezjscore.ini
 * [ezjscServer_expaitranslation]; the caller needs ezjscore/call with the function list "expai"):
 *
 *   POST <root>/ezjscore/call  ezjscServer_function_arguments=expaitranslation::<function>, ezxform_token, node, ...
 *
 *   status                          the translations of the node's object and their decisions (expai/write)
 *   check, approve, skip            decide again, queue (language, mode), mark as up to date (language)
 *                                   (expai/write and expai/translation_sync)
 *
 * Every function checks POST, the form token, the policies and that the user may read the node; approve and skip
 * also that the user may edit the object in that language. Nothing here is cached.
 */
class expAiTranslationSyncServerFunctions extends ezjscServerFunctions
{
    /** @return string */
    protected static function post( $name, $default = '' )
    {
        return isset( $_POST[$name] ) && is_scalar( $_POST[$name] ) ? (string) $_POST[$name] : $default;
    }

    /**
     * POST, form token, policies, the node.
     * @return eZContentObject
     */
    protected static function guard( $act )
    {
        if ( !isset( $_SERVER['REQUEST_METHOD'] ) || strtoupper( $_SERVER['REQUEST_METHOD'] ) !== 'POST' )
            throw ( new expAiException( 'Exponential Intelligence only accepts POST requests.' ) )->translated();
        if ( class_exists( 'ezxFormToken' ) && ezxFormToken::isEnabled() )
        {
            $given = self::post( 'ezxform_token', isset( $_SERVER['HTTP_X_CSRF_TOKEN'] ) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : '' );
            if ( $given === '' || !hash_equals( (string) ezxFormToken::getToken(), $given ) )
                throw ( new expAiException( 'The form token is missing or wrong. Please reload the page.' ) )->translated();
        }
        if ( !expAiServerFunctions::hasPolicy( 'write' ) )
            throw ( new expAiException( 'You do not have access to Exponential Intelligence.' ) )->translated();
        if ( $act && !expAiServerFunctions::hasPolicy( 'translation_sync' ) )
            throw ( new expAiException( 'You may not start or skip translations.' ) )->translated();
        if ( !( new expAiTranslationSync() )->enabled() )
            throw ( new expAiException( 'The translation sync is switched off.' ) )->translated();
        $node = eZContentObjectTreeNode::fetch( (int) self::post( 'node' ) );
        if ( !$node instanceof eZContentObjectTreeNode || !$node->canRead() )
            throw ( new expAiException( 'Node %id does not exist or you may not read it.', '', 0, array( '%id' => (int) self::post( 'node' ) ) ) )->translated();
        if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE && !$act )
            session_write_close();
        return $node->attribute( 'object' );
    }

    /** @return string a language code of the object, from POST */
    protected static function language( eZContentObject $object )
    {
        $language = self::post( 'language' );
        if ( !in_array( $language, array_keys( $object->allLanguages() ), true ) )
            throw ( new expAiException( 'The language %language is not a translation of this object.', '', 0, array( '%language' => $language ) ) )->translated();
        return $language;
    }

    /** @return array the status for the browser */
    protected static function result( eZContentObject $object, $message = '' )
    {
        $sync = new expAiTranslationSync();
        $status = $sync->status( $object );
        $rows = array();
        foreach ( $status['languages'] as $code => $row )
        {
            $rows[] = array( 'language' => $code, 'name' => $row['name'], 'state' => (string) $row['state'], 'decision' => (string) $row['decision'],
                             'mode' => isset( $row['mode'] ) ? (string) $row['mode'] : '', 'reason' => isset( $row['reason'] ) ? (string) $row['reason'] : '',
                             'ratio' => isset( $row['change_ratio'] ) ? round( 100 * (float) $row['change_ratio'] ) : null,
                             'blocks' => isset( $row['blocks_changed'] ) ? (int) $row['blocks_changed'] : 0, 'units' => isset( $row['units'] ) ? (int) $row['units'] : 0,
                             'estimate' => (string) $row['estimate'], 'lane' => isset( $row['lane'] ) ? (string) $row['lane'] : '',
                             // the translation service (doc/translation-providers.md): an id, its name, llm or mt, why none can take it
                             'provider' => isset( $row['provider'] ) ? (string) $row['provider'] : '',
                             'provider_label' => isset( $row['provider_label'] ) ? (string) $row['provider_label'] : '',
                             'provider_kind' => isset( $row['provider_kind'] ) ? (string) $row['provider_kind'] : '',
                             'provider_reason' => isset( $row['provider_reason'] ) ? expAiText::cut( (string) $row['provider_reason'], 300 ) : '',
                             'from_version' => isset( $row['from_version'] ) ? (int) $row['from_version'] : 0, 'to_version' => isset( $row['to_version'] ) ? (int) $row['to_version'] : 0,
                             'stale' => (bool) $row['stale'], 'job_uuid' => (string) $row['job_uuid'], 'job_state' => (string) $row['job_state'],
                             'draft_version' => isset( $row['draft_version'] ) && $row['state'] === 'draft' ? (int) $row['draft_version'] : 0,
                             'error' => isset( $row['error'] ) ? expAiText::cut( (string) $row['error'], 300 ) : '',
                             'can_edit' => (bool) $object->canEdit( false, false, false, $code ) );
        }
        $queueView = eZUser::currentUser()->hasAccessTo( 'expqueue', 'view' );
        return array( 'object_id' => (int) $object->attribute( 'id' ), 'source' => $status['source'], 'source_name' => $status['source_name'],
                      'current_version' => $status['current_version'], 'languages' => $rows, 'message' => $message,
                      'can_act' => expAiServerFunctions::hasPolicy( 'translation_sync' ), 'queue' => expAiQueue::available(),
                      'has_queue_view' => isset( $queueView['accessWord'] ) && $queueView['accessWord'] !== 'no' );
    }

    /** status: the translations of the node's object */
    public static function status( $args )
    {
        $object = self::guard( false );
        return self::result( $object );
    }

    /** check: decide again for every translation (queues the small patches) */
    public static function check( $args )
    {
        $object = self::guard( true );
        ( new expAiTranslationSync() )->check( $object, array( 'trigger' => 'manual' ) );
        return self::result( $object );
    }

    /** approve: queue the patch or the full translation of one language (mode: '' = the decision, patch, full) */
    public static function approve( $args )
    {
        $object = self::guard( true );
        $language = self::language( $object );
        $mode = self::post( 'mode' );
        if ( !in_array( $mode, array( '', 'patch', 'full' ), true ) )
            $mode = '';
        try
        {
            ( new expAiTranslationSync() )->approve( $object, $language, $mode );
        }
        catch ( expAiException $e )
        {
            throw $e->translated();
        }
        return self::result( $object );
    }

    /** skip: the translation counts as up to date */
    public static function skip( $args )
    {
        $object = self::guard( true );
        $language = self::language( $object );
        try
        {
            ( new expAiTranslationSync() )->skip( $object, $language );
        }
        catch ( expAiException $e )
        {
            throw $e->translated();
        }
        return self::result( $object );
    }
}
