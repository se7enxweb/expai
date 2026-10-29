<?php
/**
 * Cronjob expai_queue (part "expai"): resumes the queued requests a budget held, as soon as they fit (a new month, a
 * raised budget, requests that cost less than reserved), and gives requests whose provider batch was lost back to the
 * queue. Nothing happens without the extension expqueue.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** @var eZCLI $cli */
if ( !expAiQueue::available() )
{
    if ( !$isQuiet )
        $cli->output( 'expai: the job queue is not available, nothing to resume' );
    return;
}
$result = ( new expAiBudget() )->resumeHeld();
if ( !$isQuiet )
    $cli->output( "expai: resumed {$result['resumed']} job(s) held by a budget, {$result['still_held']} still held" );

// the master switch (#370 M2): requests held while expai was switched off run again once it is on
if ( class_exists( 'expAiGate' ) )
{
    $switch = expAiGate::resumeHeld();
    if ( !$isQuiet && ( $switch['resumed'] || $switch['still_held'] ) )
        $cli->output( "expai: resumed {$switch['resumed']} job(s) held while switched off, {$switch['still_held']} still held" );
}

// a request marked by a submit job that is no longer active waits for the next batch again
$db = eZDB::instance();
$rows = $db->arrayQuery( "SELECT id, external_ref FROM expqueue_job WHERE type = 'expai.request' AND external_ref LIKE 'expai-submit:%' AND state = 'retry'" );
$released = 0;
foreach ( is_array( $rows ) ? $rows : array() as $row )
{
    $row = array_change_key_case( $row, CASE_LOWER );
    $submit = expQueue::fetch( substr( (string) $row['external_ref'], strlen( 'expai-submit:' ) ) );
    if ( !$submit || $submit->isFinal() )
    {
        expQueue::casUpdate( (int) $row['id'], array( 'external_ref' => (string) $row['external_ref'] ), array( 'external_ref' => '', 'run_after' => time() ) );
        ++$released;
    }
}
if ( $released && !$isQuiet )
    $cli->output( "expai: $released request(s) of an ended submit job wait for the next batch" );
