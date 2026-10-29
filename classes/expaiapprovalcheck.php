<?php
/**
 * File containing the expAiApprovalCheck class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * What "Send for approval" will do with a draft: the triggers before content/publish and their workflows, with the
 * conditions of each approve event (event_ezapprove) evaluated the way eZApproveType::execute() does (section of the
 * object or of a parent, excluded user groups, users and groups who approve, languages, first / later versions).
 *
 *   approval  an approve event applies: the version waits for approval (collaboration item for the approver)
 *   possible  an approve event sits behind a multiplexer whose conditions are not evaluated here
 *   direct    no approve event applies: the version is published at once
 */
class expAiApprovalCheck
{
    const APPROVAL = 'approval';
    const POSSIBLE = 'possible';
    const DIRECT = 'direct';

    /**
     * @return string one of the constants
     */
    public static function check( eZContentObject $object, eZContentObjectVersion $version, ?eZUser $user = null )
    {
        $user = $user ?: eZUser::currentUser();
        $result = self::DIRECT;
        foreach ( eZTrigger::fetchList( array( 'module' => 'content', 'function' => 'publish', 'connectType' => 'b' ) ) as $trigger )
        {
            $verdict = self::workflowVerdict( (int) $trigger->attribute( 'workflow_id' ), $object, $version, $user, 0 );
            if ( $verdict === self::APPROVAL )
                return self::APPROVAL;
            if ( $verdict === self::POSSIBLE )
                $result = self::POSSIBLE;
        }
        return $result;
    }

    protected static function workflowVerdict( $workflowID, eZContentObject $object, eZContentObjectVersion $version, eZUser $user, $depth )
    {
        if ( $depth > 3 )
            return self::POSSIBLE;
        $result = self::DIRECT;
        foreach ( eZWorkflowEvent::fetchFilteredList( array( 'workflow_id' => (int) $workflowID, 'version' => 0 ) ) as $event )
        {
            $type = (string) $event->attribute( 'workflow_type_string' );
            if ( $type === 'event_ezapprove' && self::approveApplies( $event, $object, $version, $user ) )
                return self::APPROVAL;
            if ( $type === 'event_ezmultiplexer' && (int) $event->attribute( 'data_int1' ) > 0 )
            {
                $inner = self::workflowVerdict( (int) $event->attribute( 'data_int1' ), $object, $version, $user, $depth + 1 );
                if ( $inner !== self::DIRECT )
                    $result = self::POSSIBLE;
            }
        }
        return $result;
    }

    /** The conditions of eZApproveType::execute() */
    public static function approveApplies( eZWorkflowEvent $event, eZContentObject $object, eZContentObjectVersion $version, eZUser $user )
    {
        $versionNumber = (int) $version->attribute( 'version' );
        $option = (int) $event->attribute( 'data_int3' );
        if ( ( $option === 1 && $versionNumber > 1 ) || ( $option === 2 && $versionNumber === 1 ) )
            return false;
        $userGroups = array_merge( (array) $user->attribute( 'groups' ), array( $user->attribute( 'contentobject_id' ) ) );
        $sections = explode( ',', (string) $event->attribute( 'data_text1' ) );
        $excluded = (string) $event->attribute( 'data_text2' ) === '' ? array() : explode( ',', (string) $event->attribute( 'data_text2' ) );
        $editors = (string) $event->attribute( 'data_text3' ) === '' ? array() : explode( ',', (string) $event->attribute( 'data_text3' ) );
        $approveGroups = (string) $event->attribute( 'data_text4' ) === '' ? array() : explode( ',', (string) $event->attribute( 'data_text4' ) );
        $languageMask = (int) $event->attribute( 'data_int2' );

        $correctSection = in_array( $object->attribute( 'section_id' ), $sections ) || in_array( -1, $sections );
        if ( !$correctSection )
        {
            foreach ( (array) $object->attribute( 'assigned_nodes' ) as $assigned )
            {
                $parent = $assigned->attribute( 'parent' );
                if ( $parent && in_array( $parent->object()->attribute( 'section_id' ), $sections ) )
                {
                    $correctSection = true;
                    break;
                }
            }
        }
        if ( !$correctSection )
            return false;
        if ( count( array_intersect( $userGroups, $excluded ) ) )
            return false;
        if ( in_array( $user->id(), $editors ) || count( array_intersect( $userGroups, $approveGroups ) ) )
            return false;
        if ( $languageMask !== 0 && !( $languageMask & (int) $version->attribute( 'initial_language_id' ) ) )
            return false;
        return true;
    }
}
