<?php
/**
 * File containing the expAiObjects class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Content objects by an id expai stored earlier (the draft log, staged changes, checkpoints, chain runs, queued
 * jobs): such an object may have been removed since. eZContentObject::fetch() writes an "Object not found" error to
 * the debug output for every id it does not find, so these lookups go through here and a removed object is simply
 * null, without an error.
 */
class expAiObjects
{
    /**
     * Which of the ids still have a content object, in one query (a list or dashboard checks all its rows at once).
     * @param int[] $ids
     * @return array id => true for every id whose object exists (objects in the trash exist)
     */
    public static function existing( array $ids )
    {
        $wanted = array();
        foreach ( $ids as $id )
            if ( (int) $id > 0 )
                $wanted[(int) $id] = true;
        if ( !$wanted )
            return array();
        $rows = eZPersistentObject::fetchObjectList( eZContentObject::definition(), array( 'id' ),
            array( 'id' => array( array_keys( $wanted ) ) ), null, null, false );
        $out = array();
        foreach ( (array) $rows as $row )
            $out[(int) $row['id']] = true;
        return $out;
    }

    /**
     * eZContentObject::fetch() for a stored id that may be gone: null without a debug error when it is.
     * @param int $id
     * @return eZContentObject|null
     */
    public static function fetch( $id )
    {
        $id = (int) $id;
        if ( $id <= 0 )
            return null;
        // an object loaded before in this request is in the kernel's cache: no query at all
        if ( isset( $GLOBALS['eZContentObjectContentObjectCache'][$id] ) || eZContentObject::exists( $id ) )
            return eZContentObject::fetch( $id );
        return null;
    }
}
