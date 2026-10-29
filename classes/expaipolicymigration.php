<?php
/**
 * File containing the expAiPolicyMigration class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Renames the policy functions of expai that pre-release builds called differently (RENAMED), in every role, with
 * their limitations (they belong to the policy, which keeps its id). Idempotent: a second run finds nothing.
 * Called by bin/php/expaischema.php --install (the upgrade step, INSTALL.md).
 */
class expAiPolicyMigration
{
    /** function names of pre-release builds => the name of the function in module.php now */
    const RENAMED = array( 'facts' => 'summary' );

    /**
     * @return eZPolicy[] the policies of module expai with a function name of RENAMED (every role, drafts too)
     */
    public static function pending()
    {
        $policies = array();
        foreach ( array_keys( self::RENAMED ) as $old )
        {
            $found = eZPersistentObject::fetchObjectList( eZPolicy::definition(), null,
                array( 'module_name' => 'expai', 'function_name' => $old ), array( 'id' => 'asc' ), null, true );
            foreach ( (array) $found as $policy )
                $policies[] = $policy;
        }
        return $policies;
    }

    /**
     * Renames the pending policies and, when one changed, expires the role and user caches.
     * @param bool $dryRun report only, change nothing
     * @return array[] one row per policy: id, role_id, role, from, to, limitations (identifier => values)
     */
    public static function run( $dryRun = false )
    {
        $rows = array();
        foreach ( self::pending() as $policy )
        {
            $old = $policy->attribute( 'function_name' );
            $role = eZRole::fetch( $policy->attribute( 'role_id' ) );
            $limitations = array();
            foreach ( (array) $policy->limitationList( false ) as $limitation )
                $limitations[$limitation->attribute( 'identifier' )] = $limitation->allValues();
            $rows[] = array( 'id' => (int) $policy->attribute( 'id' ), 'role_id' => (int) $policy->attribute( 'role_id' ),
                             'role' => $role ? (string) $role->attribute( 'name' ) : '', 'from' => "expai/$old",
                             'to' => 'expai/' . self::RENAMED[$old], 'limitations' => $limitations );
            if ( !$dryRun )
            {
                $policy->setAttribute( 'function_name', self::RENAMED[$old] );
                $policy->store();
            }
        }
        if ( $rows && !$dryRun )
        {
            eZRole::expireCache();
            eZUser::cleanupCache();
            if ( class_exists( 'expAiAudit' ) )
                expAiAudit::policyMigration( $rows );
        }
        return $rows;
    }
}
