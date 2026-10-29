<?php
/**
 * expAiHttpClient against a local web server of files: methods, kept headers, the size limit, timeouts, refusals.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiHttpClientTest extends expAiProviderTestCase
{
    /** @var string */
    private static $url;

    /** @var string */
    private static $docroot;

    public static function tearDownAfterClass(): void
    {
        expAiTestMockServer::removeTree( self::$docroot );
    }

    public static function setUpBeforeClass(): void
    {
        $docroot = self::$docroot = expAiTestMockServer::tmp( 'xte-http' );
        file_put_contents( $docroot . '/echo.php', '<?php header( "X-Ratelimit-Remaining: 7" ); header( "X-Other: no" ); header( "Content-Type: application/json" );'
            . ' echo json_encode( array( "method" => $_SERVER["REQUEST_METHOD"], "type" => isset( $_SERVER["CONTENT_TYPE"] ) ? $_SERVER["CONTENT_TYPE"] : "",'
            . ' "accept" => $_SERVER["HTTP_ACCEPT"], "body" => file_get_contents( "php://input" ), "agent" => $_SERVER["HTTP_USER_AGENT"] ) );' );
        file_put_contents( $docroot . '/big.php', '<?php echo str_repeat( "x", 5000 );' );
        file_put_contents( $docroot . '/slow.php', '<?php sleep( 3 ); echo "late";' );
        $server = expAiTestMockServer::start( '', $docroot );
        self::$url = $server['url'];
    }

    public function testPostSendsTheJsonBodyAndKeepsOnlyTheAllowedHeaders(): void
    {
        $http = new expAiHttpClient( 5, 2, 4096, array( 'x-ratelimit-*' ) );
        list( $status, $body, $headers ) = $http->postJson( self::$url . '/echo.php', array( 'X-Test: 1' ), '{"a":1}' );
        $data = json_decode( $body, true );
        $this->assertSame( 200, $status );
        $this->assertSame( 'POST', $data['method'] );
        $this->assertSame( 'application/json', $data['type'] );
        $this->assertSame( '{"a":1}', $data['body'] );
        $this->assertSame( 'Exponential expai', $data['agent'] );
        $this->assertSame( array( 'x-ratelimit-remaining' => '7' ), $headers );
        $this->assertTrue( $http->keeps( 'X-RateLimit-Limit' ) );
        $this->assertFalse( $http->keeps( 'x-other' ) );
    }

    public function testDefaultHeadersAndLimitsAreClamped(): void
    {
        $http = new expAiHttpClient( 0, 99, 10 );
        $this->assertSame( 1, $http->timeout() );
        $this->assertTrue( $http->keeps( 'anthropic-ratelimit-requests-remaining' ) );
        $this->assertTrue( $http->keeps( 'Retry-After' ) );
        $this->assertFalse( $http->keeps( 'set-cookie' ) );
    }

    public function testGetAndDeleteSendNoBodyAndNoContentType(): void
    {
        $http = new expAiHttpClient( 5, 2, 4096 );
        list( , $body ) = $http->request( self::$url . '/echo.php', array(), 'ignored', array( 'method' => 'GET', 'accept' => 'text/plain' ) );
        $data = json_decode( $body, true );
        $this->assertSame( array( 'GET', '', 'text/plain', '' ), array( $data['method'], $data['type'], $data['accept'], $data['body'] ) );

        list( , $body ) = $http->request( self::$url . '/echo.php', array(), '', array( 'method' => 'delete' ) );
        $data = json_decode( $body, true );
        $this->assertSame( array( 'DELETE', '' ), array( $data['method'], $data['type'] ) );

        // a DELETE with a body, and a content type of its own (multipart uploads)
        list( , $body ) = $http->request( self::$url . '/echo.php', array(), 'x=1', array( 'method' => 'DELETE', 'content_type' => 'text/plain' ) );
        $data = json_decode( $body, true );
        $this->assertSame( array( 'DELETE', 'text/plain', 'x=1' ), array( $data['method'], $data['type'], $data['body'] ) );
    }

    public function testAContentTypeWithALineBreakIsNotUsed(): void
    {
        $http = new expAiHttpClient( 5, 2, 4096 );
        list( , $body ) = $http->request( self::$url . '/echo.php', array(), '{}', array( 'content_type' => "text/plain\r\nX-Evil: 1" ) );
        $this->assertSame( 'application/json', json_decode( $body, true )['type'] );
    }

    public function testAnAnswerLargerThanTheLimitIsRefused(): void
    {
        $http = new expAiHttpClient( 5, 2, 1024 );
        try
        {
            $http->request( self::$url . '/big.php', array(), '', array( 'method' => 'GET' ) );
            $this->fail( 'no exception' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'The answer of Exponential Intelligence was too long.', $e->getMessage() );
            $this->assertSame( 'response larger than 1024 bytes', $e->detail() );
        }
    }

    public function testATimeoutSaysSoAndWhetherAnythingCameBack(): void
    {
        $http = new expAiHttpClient( 1, 1, 4096 );
        try
        {
            $http->request( self::$url . '/slow.php', array(), '', array( 'method' => 'GET' ) );
            $this->fail( 'no exception' );
        }
        catch ( expAiException $e )
        {
            $this->assertStringContainsString( 'did not answer in time', $e->getMessage() );
            $this->assertSame( 'timeout after 1 s, no answer', $e->detail() );
        }
    }

    public function testAClosedPortIsACurlError(): void
    {
        $probe = stream_socket_server( 'tcp://127.0.0.1:0' );
        $port = (int) substr( strrchr( stream_socket_get_name( $probe, false ), ':' ), 1 );
        fclose( $probe );
        $http = new expAiHttpClient( 2, 1, 4096 );
        try
        {
            $http->postJson( 'http://127.0.0.1:' . $port . '/x', array(), '{}' );
            $this->fail( 'no exception' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'Exponential Intelligence could not be reached.', $e->getMessage() );
            $this->assertStringStartsWith( 'curl error ', $e->detail() );
        }
    }

    public function testEndpointsWithCredentialsOrOtherSchemesAndOtherMethodsAreRefused(): void
    {
        $http = new expAiHttpClient( 2, 1, 4096 );
        foreach ( array( 'ftp://example.invalid/x', 'https://user:pass@example.invalid/x', 'not a url', array( 'x' ) ) as $url )
        {
            try
            {
                $http->request( $url, array(), '' );
                $this->fail( 'accepted ' . json_encode( $url ) );
            }
            catch ( expAiException $e )
            {
                $this->assertSame( 'invalid endpoint (http or https without credentials)', $e->detail() );
            }
        }
        try
        {
            $http->request( self::$url . '/echo.php', array(), '', array( 'method' => 'PUT' ) );
            $this->fail( 'PUT accepted' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'unsupported HTTP method', $e->detail() );
        }
    }
}
