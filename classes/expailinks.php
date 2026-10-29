<?php
/**
 * File containing the expAiLinks class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The icon links of a row in expai/log and on the dashboard: View, Edit, Prompt again, Intelligence tab. Each only
 * when its target exists and the current user may use it; a removed object is "deleted", one in the trash "trash".
 */
class expAiLinks
{
    /**
     * @param int $objectID 0 when only a node is known
     * @param int $version
     * @param string $language
     * @param int $nodeID the node the row belongs to (panel node), used when the object has no node
     * @param int $againID the expai_draft_log row whose request "Prompt again" prefills (only with [UsageSettings]
     *                     LogRequests=enabled; the dashboard checks that the row is the user's own)
     * @param bool|null $exists whether the object still exists when the caller checked it already for a whole list
     *                          (expAiDraftLog::markObjectsThatExist()); null: checked here
     * @return array state (ok | trash | deleted), view, edit, prompt, tab (URLs below the siteaccess root, '' when not offered)
     */
    public static function build( $objectID, $version = 0, $language = '', $nodeID = 0, $againID = 0, $exists = null )
    {
        $links = array( 'state' => 'ok', 'view' => '', 'edit' => '', 'prompt' => '', 'tab' => '' );
        $user = eZUser::currentUser();
        $node = null;
        $object = null;
        if ( $objectID )
        {
            // a removed object: no link, and no "Object not found" error in the debug output
            if ( $exists === false )
                return array( 'state' => 'deleted' ) + $links;
            $object = $exists === true ? eZContentObject::fetch( (int) $objectID ) : expAiObjects::fetch( (int) $objectID );
            if ( !$object )
                return array( 'state' => 'deleted' ) + $links;
            if ( (int) $object->attribute( 'status' ) === eZContentObject::STATUS_ARCHIVED )
                return array( 'state' => 'trash' ) + $links;
            $node = $object->mainNode();
        }
        if ( !$node && $nodeID )
        {
            $node = eZContentObjectTreeNode::fetch( (int) $nodeID );
            if ( !$node && !$objectID )
                return array( 'state' => 'deleted' ) + $links;
            if ( $node && !$objectID )
                $object = $node->object();
        }
        $readable = $node && $node->canRead();

        // View: the node's full view, or the version view of a draft-only object
        if ( $readable && $object && $node->attribute( 'contentobject_id' ) == $object->attribute( 'id' ) )
            $links['view'] = 'content/view/full/' . (int) $node->attribute( 'node_id' );
        elseif ( $object && $version )
        {
            $v = $object->version( (int) $version );
            if ( $v && ( (int) $v->attribute( 'creator_id' ) === (int) $user->attribute( 'contentobject_id' ) || $object->canRead() ) )
                $links['view'] = 'content/versionview/' . (int) $object->attribute( 'id' ) . '/' . (int) $version . '/' . ( $language ?: $v->initialLanguageCode() );
        }

        // Edit: the logged draft while it is the user's draft, else a new version of the current one
        if ( $object )
        {
            $v = $version ? $object->version( (int) $version ) : null;
            $lang = $language ?: (string) $object->attribute( 'initial_language_code' );
            if ( $v && in_array( (int) $v->attribute( 'status' ), array( eZContentObjectVersion::STATUS_DRAFT, eZContentObjectVersion::STATUS_INTERNAL_DRAFT ), true )
                 && (int) $v->attribute( 'creator_id' ) === (int) $user->attribute( 'contentobject_id' ) )
                $links['edit'] = 'content/edit/' . (int) $object->attribute( 'id' ) . '/' . (int) $version . '/' . $lang;
            elseif ( (int) $object->attribute( 'status' ) === eZContentObject::STATUS_PUBLISHED && $object->canEdit( false, false, false, $lang ) )
                $links['edit'] = 'content/edit/' . (int) $object->attribute( 'id' ) . '/f/' . $lang;
        }

        // Prompt again and the Intelligence tab: where the conversation can go on
        $place = $readable ? $node : ( $nodeID ? eZContentObjectTreeNode::fetch( (int) $nodeID ) : null );
        if ( $place && $place->canRead() && expAiServerFunctions::hasPolicy() )
        {
            $links['prompt'] = 'expai/dashboard/(node)/' . (int) $place->attribute( 'node_id' );
            if ( (int) $againID > 0 && expAiConfig::instance()->flag( 'UsageSettings', 'LogRequests', false ) )
                $links['prompt'] .= '/(again)/' . (int) $againID;
            $links['tab'] = 'content/view/full/' . (int) $place->attribute( 'node_id' ) . '#exp-ai';
        }
        return $links;
    }
}
