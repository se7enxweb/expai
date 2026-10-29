<?php
/**
 * File containing the expAiRunWatch class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * "Watch this run" of the dashboard's "Start writing": the request opens the Intelligence tab of the chosen location,
 * where it becomes a thread when the editor sends it. The dashboard remembers the location and the time (session
 * variable ExpAiWatch) and shows the state of that thread with links to its live record: the thread in Vector, its
 * requests in the log and, for a run in the background, its job of the job queue.
 *
 * Also the ezjscore function expaiwatch::state (ezjscore.ini [ezjscServer_expaiwatch]; the function list "expai"):
 * POST with the form token, the policy expai/write; answers { html, active, poll (milliseconds) }, which
 * expai_watch.js asks every few seconds while the run waits or works.
 */
class expAiRunWatch extends ezjscServerFunctions
{
    /** The session variable */
    const SESSION = 'ExpAiWatch';

    /** How long a watch is kept after the request was started, in seconds */
    const KEEP = 21600;

    /** The thread states of a run that is not finished */
    const ACTIVE = array( 'queued', 'running' );

    /** Remembers a request of "Start writing" for the dashboard */
    public static function remember( $nodeID, $time = null )
    {
        eZHTTPTool::instance()->setSessionVariable( self::SESSION, array( 'node_id' => (int) $nodeID, 'since' => $time === null ? time() : (int) $time ) );
    }

    /** @return array|null node_id, since of the remembered request (null: none, or older than KEEP) */
    public static function remembered()
    {
        $http = eZHTTPTool::instance();
        if ( !$http->hasSessionVariable( self::SESSION ) )
            return null;
        $watch = (array) $http->sessionVariable( self::SESSION );
        if ( empty( $watch['node_id'] ) || empty( $watch['since'] ) || (int) $watch['since'] < time() - self::KEEP )
            return null;
        return array( 'node_id' => (int) $watch['node_id'], 'since' => (int) $watch['since'] );
    }

    /**
     * The state of the remembered request.
     * @param array|null $watch remembered() (null: read it)
     * @return array|null node_id, node_name, since, state (sent: not sent yet in the Intelligence tab, or the
     *                    thread's state), active (bool), thread (uuid, id, title), job_uuid, links (panel, vector,
     *                    log, job: paths without the site root, '' when not available to the user)
     */
    public static function describe( $userID, $watch = null )
    {
        $watch = $watch === null ? self::remembered() : $watch;
        if ( !$watch )
            return null;
        $node = eZContentObjectTreeNode::fetch( (int) $watch['node_id'] );
        if ( !$node || !$node->canRead() )
            return null;
        $out = array( 'node_id' => (int) $watch['node_id'], 'node_name' => (string) $node->attribute( 'name' ), 'since' => (int) $watch['since'],
                      'state' => 'unsent', 'active' => true, 'thread' => null, 'job_uuid' => '',
                      'links' => array( 'panel' => 'content/view/full/' . (int) $watch['node_id'] . '#exp-ai', 'vector' => '', 'log' => '', 'job' => '' ) );
        $thread = null;
        if ( class_exists( 'expAiThread' ) && expAiThread::available() )
            foreach ( expAiThread::fetchForUser( (int) $userID, array( 'node_id' => (int) $watch['node_id'], 'limit' => 5 ) ) as $candidate )
                if ( (int) $candidate->attribute( 'modified' ) >= (int) $watch['since'] && (int) $candidate->attribute( 'requests' ) + (int) $candidate->attribute( 'lead_run_id' ) > 0 )
                {
                    $thread = $candidate;
                    break;
                }
        if ( !$thread )
            return $out;
        $row = expAiVector::listRow( $thread, (int) $userID );
        $out['state'] = (string) $row['state'];
        $out['active'] = in_array( $out['state'], self::ACTIVE, true );
        $out['thread'] = array( 'uuid' => $row['uuid'], 'id' => (int) $thread->attribute( 'id' ), 'title' => $row['title'] );
        $out['links']['log'] = 'expai/log/(thread)/' . (int) $thread->attribute( 'id' );
        if ( expAiServerFunctions::hasPolicy( 'vector' ) )
            $out['links']['vector'] = 'expai/vector/(thread)/' . $row['uuid'];
        $run = (int) $thread->attribute( 'lead_run_id' ) ? expAiThreadRun::fetchByID( (int) $thread->attribute( 'lead_run_id' ) ) : null;
        if ( $run && (string) $run->attribute( 'job_uuid' ) !== '' )
        {
            $out['job_uuid'] = (string) $run->attribute( 'job_uuid' );
            $access = eZUser::currentUser()->hasAccessTo( 'expqueue', 'view' );
            if ( isset( $access['accessWord'] ) && $access['accessWord'] !== 'no' )
                $out['links']['job'] = 'expqueue/job/' . $out['job_uuid'];
        }
        return $out;
    }

    /** @return string expai/parts/run_watch.tpl for a describe() result */
    public static function render( $watch )
    {
        $tpl = eZTemplate::factory();
        $tpl->setVariable( 'watch', $watch );
        return trim( (string) $tpl->fetch( 'design:expai/parts/run_watch.tpl' ) );
    }

    /** expaiwatch::state: the state of the remembered request, rendered */
    public static function state( $args )
    {
        if ( !isset( $_SERVER['REQUEST_METHOD'] ) || strtoupper( $_SERVER['REQUEST_METHOD'] ) !== 'POST' )
            throw ( new expAiException( 'Exponential Intelligence only accepts POST requests.' ) )->translated();
        if ( class_exists( 'ezxFormToken' ) && ezxFormToken::isEnabled() )
        {
            $given = isset( $_POST['ezxform_token'] ) && is_scalar( $_POST['ezxform_token'] ) ? (string) $_POST['ezxform_token']
                   : ( isset( $_SERVER['HTTP_X_CSRF_TOKEN'] ) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : '' );
            if ( $given === '' || !hash_equals( (string) ezxFormToken::getToken(), $given ) )
                throw ( new expAiException( 'The form token is missing or wrong. Please reload the page.' ) )->translated();
        }
        if ( !expAiServerFunctions::hasPolicy( 'write' ) )
            throw ( new expAiException( 'You do not have access to Exponential Intelligence.' ) )->translated();
        $watch = self::remembered();
        // the session is only read: closed before the queries, so the polling never holds the editor's other requests
        if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE )
            session_write_close();
        $state = self::describe( (int) eZUser::currentUserID(), $watch );
        return array( 'html' => $state ? self::render( $state ) : '', 'active' => $state ? (bool) $state['active'] : false, 'poll' => 4000 );
    }
}
