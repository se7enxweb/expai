<?php
/**
 * expAiVectorStream::serve(): the event stream of a thread (text/event-stream: a first line at once, the events after
 * the cursor as "thread" events with their id, a ping per second, the end after StreamSeconds). The stream drops
 * every output buffer, so it runs in a process of its own (expaivectorstream_serve_script.php, through ezexec.php);
 * its coverage is added when the suite measures coverage.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

class expAiVectorStreamServeTest extends expAiTestCase
{
    public function testTheStreamSendsTheEventsAfterTheCursorAndEnds(): void
    {
        $this->requireDatabase();
        if ( !expAiThread::available() )
            $this->markTestSkipped( 'the thread tables are missing' );
        $started = microtime( true );
        $result = expAiScriptRunner::run( 'phpunit/unit/vector/expaivectorstream_serve_script.php', array(), array( '-s', expAiTestInstallation::siteAccess() ), 120 );
        $output = $result['output'];
        $this->assertSame( 0, $result['exit'], $output );
        $this->assertSame( 'PASS', $result['status'], $output );
        $this->assertSame( array(), $result['fail'] );
        $this->assertMatchesRegularExpression( '/^THREAD ([0-9a-f-]{36})$/m', $output );
        preg_match( '/^THREAD ([0-9a-f-]{36})$/m', $output, $m );
        $this->assertStringContainsString( ": ok\nretry: 3000\n\n", $output, 'the first line comes at once' );
        $this->assertSame( 1, preg_match_all( '/^event: thread$/m', $output ), 'one event after the cursor' );
        $this->assertMatchesRegularExpression( '/^id: \d+\nevent: thread\ndata: \{"thread":"' . $m[1] . '","id":\d+,"run_id":\d+,"kind":"text",.*"payload":\{"text":"second, streamed"\}\}$/m', $output );
        $this->assertStringNotContainsString( 'first, before the cursor', $output );
        $this->assertGreaterThanOrEqual( 3, substr_count( $output, ": ping\n\n" ), 'a ping per second' );
        $this->assertStringContainsString( 'END OF STREAM', $output );
        $this->assertGreaterThanOrEqual( 5, microtime( true ) - $started, 'the stream is held for StreamSeconds' );
    }
}
