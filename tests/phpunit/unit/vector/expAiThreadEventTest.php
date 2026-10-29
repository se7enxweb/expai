<?php
/**
 * expAiThreadEvent: kinds, the payload cap (a structural summary, else only "truncated"), the cursor.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectortestcase.php';

class expAiThreadEventTest extends expAiVectorTestCase
{
    public function testASmallPayloadIsKeptAsItIs(): void
    {
        $this->setIni( 'ThreadSettings', 'MaxEventPayloadBytes', 1024 );
        $payload = array( 'text' => 'Grüße', 'path' => 'a/b' );
        $this->assertSame( '{"text":"Grüße","path":"a/b"}', expAiThreadEvent::encode( $payload ) );
    }

    public function testABigPayloadKeepsItsKeysWithLongStringsCut(): void
    {
        $this->setIni( 'ThreadSettings', 'MaxEventPayloadBytes', 1024 );
        $payload = array( 'text' => str_repeat( 'word ', 600 ), 'list' => range( 1, 50 ), 'ok' => true, 'nested' => array( 'inner' => str_repeat( 'x', 900 ) ) );
        $json = expAiThreadEvent::encode( $payload );
        $this->assertLessThanOrEqual( 1024, strlen( $json ) );
        $data = json_decode( $json, true );
        $this->assertTrue( $data['truncated'] );
        $this->assertSame( array( 'text', 'list', 'ok', 'nested', 'truncated' ), array_keys( $data ) );
        $this->assertCount( 20, $data['list'], 'lists keep their first 20 entries' );
        $this->assertSame( range( 1, 20 ), $data['list'] );
        $this->assertTrue( $data['ok'] );
        $this->assertLessThan( 300, mb_strlen( $data['text'] ) );
        $this->assertLessThan( 900, mb_strlen( $data['nested']['inner'] ) );
    }

    public function testAPayloadThatStaysTooBigIsOnlyMarkedTruncated(): void
    {
        $this->setIni( 'ThreadSettings', 'MaxEventPayloadBytes', 1024 );
        $payload = array();
        for ( $i = 0; $i < 20; $i++ )
            $payload['key' . $i] = str_repeat( 'z', 2000 );
        $this->assertSame( '{"truncated":true}', expAiThreadEvent::encode( $payload ) );
    }

    public function testKindsPayloadAndCursor(): void
    {
        $this->inTransaction();
        $this->asUser( 'admin' );
        $thread = $this->thread();
        $id = (int) $thread->attribute( 'id' );
        $a = expAiThreadEvent::append( $id, 0, 'no-such-kind', array( 'n' => 1 ) );
        $b = expAiThreadEvent::append( $id, 7, 'text', array( 'text' => 'two' ) );
        $this->assertSame( 'status', (string) $a->attribute( 'kind' ), 'an unknown kind is a status event' );
        $this->assertSame( array( 'id' => (int) $b->attribute( 'id' ), 'run_id' => 7, 'kind' => 'text', 'created' => (int) $b->attribute( 'created' ),
                                  'payload' => array( 'text' => 'two' ) ), $b->present() );
        $after = expAiThreadEvent::fetchAfter( $id, (int) $a->attribute( 'id' ) );
        $this->assertSame( array( (int) $b->attribute( 'id' ) ), array_map( function ( $e ) { return (int) $e->attribute( 'id' ); }, $after ) );
        $this->assertCount( 1, expAiThreadEvent::fetchAfter( $id, 0, 1 ), 'the limit holds' );
        $broken = new expAiThreadEvent( array( 'payload' => 'not json' ) );
        $this->assertSame( array(), $broken->payload() );
    }
}
