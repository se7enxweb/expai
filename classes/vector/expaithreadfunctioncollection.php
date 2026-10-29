<?php
/**
 * File containing the expAiThreadFunctionCollection class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Template fetch functions of threads (function_definition.php):
 *
 *   fetch( 'expai', 'my_threads', hash( 'limit', 6 ) )   the current user's own active and pinned threads, newest first:
 *                                                        { rows: list of thread rows (+ state_label), needs: count of
 *                                                        threads that need the user (to review, waiting, failed) }
 */
class expAiThreadFunctionCollection
{
    public function fetchMyThreads( $limit = 6 )
    {
        $out = array( 'rows' => array(), 'needs' => 0 );
        $user = eZUser::currentUser();
        if ( !$user->isRegistered() || !expAiServerFunctions::hasPolicy( 'write' ) || !expAiThread::available() )
            return array( 'result' => $out );
        $userID = (int) $user->attribute( 'contentobject_id' );
        $labels = array( 'idle' => 'Idle', 'queued' => 'Queued', 'running' => 'Working', 'waiting_input' => 'Waits for you', 'review' => 'To review',
                         'done' => 'Done', 'failed' => 'Failed', 'stopped' => 'Stopped' );
        foreach ( expAiThread::fetchForUser( $userID, array( 'shelf' => array( 'active', 'pinned' ), 'limit' => max( 1, min( 50, (int) $limit ) ) ) ) as $thread )
        {
            $row = expAiVector::listRow( $thread, $userID );
            $row['state_label'] = ezpI18n::tr( 'extension/expai/threads', isset( $labels[$row['state']] ) ? $labels[$row['state']] : 'Idle' );
            $out['rows'][] = $row;
        }
        $out['needs'] = expAiThread::countForUser( $userID, array( 'shelf' => array( 'active', 'pinned' ), 'state' => array( 'review', 'waiting_input', 'failed' ) ) );
        return array( 'result' => $out );
    }
}
