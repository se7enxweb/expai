<?php
/**
 * expai/stream: Server-Sent Events of the current user's threads (doc/vector.md "How events reach the browser").
 * The hook for live steps; foreground runs return their steps in the turn's answer as well.
 *
 *   expai/stream/(thread)/<uuid>/(after)/<event id>     (Last-Event-ID wins over (after) on a reconnect)
 *
 * Used only where [VectorSettings] Transport resolves to sse: "auto" means SSE under PHP-FPM/Apache (the proxy flushes
 * each packet) and under Velocity (its pool workers stream; past its cap they answer 503). The stream closes the session lock
 * first, sends a first line at once, holds at most [VectorSettings] StreamSeconds (25), checks for new events every
 * second, stops when the client leaves, and answers 204 when [VectorSettings] MaxStreams streams are open (the
 * browser then polls). Read only: a replayed GET is harmless. Never wrapped in catch (Exception): cleanExit() throws
 * on Velocity.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** @var eZModule $Module */
$Module = $Params['Module'];
$config = expAiConfig::instance();
$viewParameters = isset( $Params['UserParameters'] ) && is_array( $Params['UserParameters'] ) ? $Params['UserParameters'] : array();
$uuid = isset( $viewParameters['thread'] ) ? (string) $viewParameters['thread'] : '';
$after = isset( $_SERVER['HTTP_LAST_EVENT_ID'] ) && ctype_digit( (string) $_SERVER['HTTP_LAST_EVENT_ID'] ) ? (int) $_SERVER['HTTP_LAST_EVENT_ID']
       : ( isset( $viewParameters['after'] ) ? (int) $viewParameters['after'] : 0 );

$thread = expAiThread::available() ? expAiThread::fetchByUuid( $uuid ) : null;
if ( expAiVectorStream::transport( $config ) !== 'sse' || !expAiVector::allowed() || !$thread || !expAiThreadAccess::canRead( $thread ) )
{
    header( 'HTTP/1.1 204 No Content' );
    header( 'Cache-Control: no-store' );
    eZExecution::cleanExit();
}
$slot = expAiVectorStream::acquireSlot( $config );
if ( !$slot )
{
    header( 'HTTP/1.1 204 No Content' );
    header( 'Cache-Control: no-store' );
    eZExecution::cleanExit();
}
if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE )
    session_write_close();
expAiVectorStream::serve( $thread, $after, $config );
expAiVectorStream::releaseSlot( $slot );
eZExecution::cleanExit();
