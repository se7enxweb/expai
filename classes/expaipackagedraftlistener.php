<?php
/**
 * File containing the expAiPackageDraftListener class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Listens to the kernel's event package/install/drafts (eZPackage::notifyDraftsInstalled(): after any install of a
 * package as drafts, in Setup > Packages, with ezpm install --as-drafts, or in a background install). The packages
 * themselves are the kernel's (Setup > Packages, ezpm, doc/en/us/bc/6.0/package-json-format.md); this is only the
 * integration: a package whose metadata says expai.ai-generated=true was written with Exponential Intelligence, so
 * each draft it gave is recorded in expai_draft_log (source "package"). That is what puts the "Written with
 * Exponential Intelligence" label on the draft and lists it among the drafts of expai. Any other package is left
 * alone.
 *
 * Configured in settings/site.ini.append.php: [Event] Listeners[]=package/install/drafts@expAiPackageDraftListener::installed
 */
class expAiPackageDraftListener
{
    /** the package metadata keys of expai's provenance (generic package metadata, shown on the package page) */
    const META_AI_GENERATED = 'expai.ai-generated';
    const META_MODELS = 'expai.models';

    /**
     * @param eZPackage $package
     * @param array $result drafts (action, remote_id, object_id, version, name), waiting, skipped, ok
     * @param array $context user_id, via (web, cli, background), job
     * @return int the drafts recorded
     */
    public static function installed( $package, $result = array(), $context = array() )
    {
        if ( !$package instanceof eZPackage || $package->metadata( self::META_AI_GENERATED ) !== 'true' )
            return 0;
        $models = array_values( array_filter( array_map( 'trim', explode( ',', (string)$package->metadata( self::META_MODELS ) ) ) ) );
        $userID = isset( $context['user_id'] ) && (int)$context['user_id'] ? (int)$context['user_id'] : (int)eZUser::currentUserID();
        $recorded = 0;
        foreach ( isset( $result['drafts'] ) && is_array( $result['drafts'] ) ? $result['drafts'] : array() as $draft )
        {
            if ( empty( $draft['version'] ) || empty( $draft['object_id'] ) )
                continue;
            $object = eZContentObject::fetch( (int)$draft['object_id'] );
            if ( !$object )
                continue;
            if ( expAiDraftLog::fetchByVersion( (int)$draft['object_id'], (int)$draft['version'] ) )
                continue;
            $version = $object->version( (int)$draft['version'] );
            try
            {
                expAiDraftLog::record( array( 'contentobject_id' => (int)$draft['object_id'], 'version' => (int)$draft['version'], 'user_id' => $userID,
                                              'node_id' => (int)$object->attribute( 'main_node_id' ), 'parent_node_id' => 0,
                                              'language_code' => $version ? (string)$version->initialLanguageCode() : '',
                                              'action' => 'package', 'source' => 'package', 'provider' => '',
                                              'model' => $models ? $models[0] : '',
                                              'prompt_summary' => 'package ' . $package->attribute( 'name' ),
                                              'job_uuid' => isset( $context['job'] ) ? substr( (string)$context['job'], 0, 36 ) : '' ) );
                ++$recorded;
            }
            catch ( Exception $e )
            {
                eZDebug::writeError( 'expai: the draft ' . (int)$draft['object_id'] . '/' . (int)$draft['version'] . ' of the package '
                                     . $package->attribute( 'name' ) . ' was not recorded: ' . $e->getMessage(), __METHOD__ );
            }
        }
        return $recorded;
    }
}
