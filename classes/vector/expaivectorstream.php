<?php
/**
 * File containing the expAiVectorStream class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The transport of thread events (modules/expai/stream.php): which one applies, the stream slots, the stream loop.
 *
 *   [VectorSettings] Transport=auto | poll | sse
 *     auto  SSE under PHP-FPM, Apache's PHP module and Velocity (its pool workers stream a text/event-stream
 *           answer; past its stream cap it answers 503 and the browser polls); polling elsewhere
 *   MaxStreams    streams open at once on this server (one PHP process each; 25 % of FPM pm.max_children)
 *   StreamSeconds how long one stream is held (the browser reconnects with Last-Event-ID)
 */
class expAiVectorStream
{
    /** @return string sse | poll */
    public static function transport( ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $setting = (string) $config->get( 'VectorSettings', 'Transport', 'auto' );
        if ( $setting === 'poll' )
            return 'poll';
        // cli: Velocity's workers (the stream view is never called from a command line script)
        $streams = in_array( PHP_SAPI, array( 'fpm-fcgi', 'apache2handler', 'cgi-fcgi', 'cli' ), true );
        if ( $setting === 'sse' )
            return $streams ? 'sse' : 'poll';
        return $streams ? 'sse' : 'poll';
    }

    /** @return string the directory of the slot lock files */
    protected static function slotDirectory()
    {
        return eZSys::cacheDirectory() . '/expai/streams';
    }

    /**
     * One of MaxStreams slots (a lock file held for the stream's life), or false when all are taken.
     * @return resource|false
     */
    public static function acquireSlot( ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $max = $config->int( 'VectorSettings', 'MaxStreams', 4, 0, 256 );
        if ( $max <= 0 )
            return false;
        $dir = self::slotDirectory();
        if ( !is_dir( $dir ) )
            eZDir::mkdir( $dir, false, true );
        for ( $i = 0; $i < $max; $i++ )
        {
            $handle = @fopen( "$dir/slot-$i.lock", 'c' );
            if ( $handle && flock( $handle, LOCK_EX | LOCK_NB ) )
                return $handle;
            if ( $handle )
                fclose( $handle );
        }
        return false;
    }

    public static function releaseSlot( $handle )
    {
        if ( is_resource( $handle ) )
        {
            flock( $handle, LOCK_UN );
            fclose( $handle );
        }
    }

    /** Sends the stream: a first line at once, then the thread's events after $after, for at most StreamSeconds */
    public static function serve( expAiThread $thread, $after, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $seconds = $config->int( 'VectorSettings', 'StreamSeconds', 25, 5, 25 );
        // No output buffer may hold the stream back: the kernel's own (ezpKernelWeb::run()) and PHP's
        // output_buffering go. A Velocity pool worker keeps its capture buffer, which cannot be removed: it
        // sends what reaches it on ob_flush()/flush() once the answer is a text/event-stream (self::send())
        eZExecution::discardOutputBuffers();
        header( 'Content-Type: text/event-stream; charset=utf-8' );
        header( 'Cache-Control: no-store' );
        header( 'X-Accel-Buffering: no' );
        self::send( ": ok\nretry: 3000\n\n" );
        $threadID = (int) $thread->attribute( 'id' );
        $uuid = (string) $thread->attribute( 'uuid' );
        // an approver gets the events as expAiVector::visibleEvent() leaves them
        $owner = $thread->isOwner( (int) eZUser::currentUserID() );
        $cursor = (int) $after;
        $end = time() + $seconds;
        while ( time() < $end )
        {
            foreach ( expAiThreadEvent::fetchAfter( $threadID, $cursor, 200 ) as $event )
            {
                $cursor = (int) $event->attribute( 'id' );
                echo 'id: ' . $cursor . "\nevent: thread\ndata: " . json_encode( array( 'thread' => $uuid ) + expAiVector::visibleEvent( $event->present(), $owner ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n\n";
            }
            self::send( ": ping\n\n" );
            if ( connection_aborted() )
                return;
            sleep( 1 );
        }
    }

    /** Prints $text and pushes it out through every buffer that is left (Velocity's capture) to the client */
    protected static function send( $text )
    {
        echo $text;
        if ( ob_get_level() > 0 )
            @ob_flush();
        flush();
    }
}
