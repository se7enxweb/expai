<?php
/**
 * The web tools (doc/tools.md): the URL rules, the address guard, the HTML to Markdown conversion, the fetcher (with
 * its transport hook, and once for real against a local web server), robots.txt, the web search with the Brave API
 * shape (an HTTP stub, never the real API) and the tools web_fetch and web_search.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiProviderTestSearch extends expAiWebSearchProvider
{
    public $rows = array();

    public function search( $query, $count )
    {
        return array_slice( $this->rows, 0, $count );
    }
}

class expAiWebFetchTest extends expAiProviderTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup( static function ()
        {
            expAiWebFetcher::$transport = null;
            expAiWebGuard::$resolver = null;
        } );
    }

    private function webConfig( array $web = array(), array $more = array() )
    {
        return $this->config( array( 'WebSettings' => $web + array( 'AllowedDomains' => array( 'docs.example.com' ), 'PrivateHosts' => array(), 'AllowedPorts' => array(),
                                                                    'RespectRobots' => 'false', 'RobotsCacheTTL' => 0, 'Timeout' => 5, 'MaxRedirects' => 2 ) ) + $more );
    }

    // ------------------------------------------------------------------ expAiWebUrl

    public function testUrlsAreParsedStrictly(): void
    {
        $this->assertSame( 'https://docs.example.com/', expAiWebUrl::parse( 'HTTPS://Docs.Example.COM.' )['url'] );
        $this->assertSame( 'https://docs.example.com/?q=1', expAiWebUrl::parse( 'https://docs.example.com?q=1' )['url'] );
        $this->assertSame( 'http://[::1]:8080/x', expAiWebUrl::parse( 'http://[0:0::1]:8080/x', array( 8080 ) )['url'] );
        $this->assertSame( 'xn--bcher-kva.example', expAiWebUrl::parse( 'https://bücher.example/' )['host'] );
        foreach ( array( '', 'ftp://x.example/', 'https://user@x.example/', 'https:///path', 'https://[zz]/', 'https://x.example:8080/', 'https://x.example:abc/',
                         'https://127.1/', 'https://0x7f.1/', 'https://-bad-.example/', "https://x.example/a b", 'https://' . str_repeat( 'a', 2048 ) . '.example/' ) as $url )
            $this->assertNull( expAiWebUrl::parse( $url ), "accepted '$url'" );
        $this->assertNull( expAiWebUrl::normalizeHost( '' ) );
        $this->assertSame( '::1', expAiWebUrl::normalizeHost( '[::1]' ) );
    }

    public function testHostPatternsMatchWholeLabels(): void
    {
        $patterns = array( '', '*.github.com', 'docs.example.com', '*.10.0.0.1' );
        $this->assertTrue( expAiWebUrl::hostAllowed( 'api.github.com', $patterns ) );
        $this->assertFalse( expAiWebUrl::hostAllowed( 'github.com', $patterns ) );
        $this->assertFalse( expAiWebUrl::hostAllowed( 'evilgithub.com', $patterns ) );
        $this->assertTrue( expAiWebUrl::hostAllowed( 'docs.example.com', $patterns ) );
        $this->assertFalse( expAiWebUrl::hostAllowed( 'x.10.0.0.1', $patterns ), 'a wildcard of an address matches nothing' );
        $this->assertFalse( expAiWebUrl::hostAllowed( '', $patterns ) );
    }

    public function testRelativeAddressesAreResolvedAgainstThePage(): void
    {
        $base = 'https://docs.example.com/guide/start.html?x=1';
        $this->assertSame( 'https://docs.example.com/guide/next.html', expAiWebUrl::resolve( $base, 'next.html' ) );
        $this->assertSame( 'https://docs.example.com/top.html', expAiWebUrl::resolve( $base, '../../top.html' ) );
        $this->assertSame( 'https://docs.example.com/a/', expAiWebUrl::resolve( $base, '/a/b/..' ) );
        $this->assertSame( 'https://docs.example.com/a/b?q#f', expAiWebUrl::resolve( $base, '/a/./b?q#f' ) );
        $this->assertSame( 'https://docs.example.com/guide/start.html?y=2', expAiWebUrl::resolve( $base, '?y=2' ) );
        $this->assertSame( 'https://docs.example.com/?y=2', expAiWebUrl::resolve( 'https://docs.example.com', '?y=2' ) );
        $this->assertSame( 'https://cdn.example.com/x', expAiWebUrl::resolve( $base, '//cdn.example.com/x' ) );
        $this->assertSame( 'https://docs.example.com/x', expAiWebUrl::resolve( 'https://docs.example.com', 'x' ) );
        $this->assertSame( 'http://other.example/', expAiWebUrl::resolve( $base, 'http://other.example/' ) );
        $this->assertNull( expAiWebUrl::resolve( $base, 'javascript:alert(1)' ) );
        $this->assertNull( expAiWebUrl::resolve( $base, '#top' ) );
        $this->assertNull( expAiWebUrl::resolve( 'not a base', 'x' ) );
    }

    // ------------------------------------------------------------------ expAiWebGuard

    public function testTheGuardRefusesPrivateAddressesUnlessTheHostIsListed(): void
    {
        $guard = new expAiWebGuard();
        $this->assertSame( array( array(), '93.184.215.14' ), $guard->check( array( 'host' => '93.184.215.14', 'port' => 443 ) ) );
        expAiWebGuard::$resolver = function () { return array( '2606:4700::1', '93.184.215.14' ); };
        $this->assertSame( array( array( 'docs.example.com:443:93.184.215.14' ), '93.184.215.14' ), $guard->check( array( 'host' => 'docs.example.com', 'port' => 443 ) ), 'IPv4 first' );
        expAiWebGuard::$resolver = function () { return array( '2606:4700::1' ); };
        $this->assertSame( array( array( 'v6.example.com:443:[2606:4700::1]' ), '2606:4700::1' ), $guard->check( array( 'host' => 'v6.example.com', 'port' => 443 ) ) );
        foreach ( array( '10.0.0.5', '::ffff:127.0.0.1', '::ffff:93.184.215.14', '239.1.1.1', 'ff02::1' ) as $address )
        {
            expAiWebGuard::$resolver = function () use ( $address ) { return array( $address ); };
            try
            {
                $guard->check( array( 'host' => 'docs.example.com', 'port' => 443 ) );
                $this->fail( "accepted $address" );
            }
            catch ( expAiException $e )
            {
                $this->assertStringContainsString( 'points into a private network', $e->getMessage() );
            }
        }
        expAiWebGuard::$resolver = function () { return array( '10.0.0.5' ); };
        $listed = expAiWebGuard::fromConfig( $this->config( array( 'WebSettings' => array( 'PrivateHosts' => array( 'intranet.example' ) ) ) ) );
        $this->assertSame( '10.0.0.5', $listed->check( array( 'host' => 'intranet.example', 'port' => 80 ) )[1] );
        expAiWebGuard::$resolver = function () { return array(); };
        try
        {
            $guard->check( array( 'host' => 'nowhere.example', 'port' => 443 ) );
            $this->fail( 'accepted a host without address' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'web: the host does not resolve', $e->detail() );
        }
    }

    public function testTheSystemResolverFindsLocalhost(): void
    {
        $this->assertContains( '127.0.0.1', expAiWebGuard::resolve( 'localhost' ) );
    }

    // ------------------------------------------------------------------ expAiWebHtml

    public function testHtmlBecomesMarkdownWithoutTheChrome(): void
    {
        $html = '<html><head><title> The  Title </title><base href="/docs/"><style>x{}</style></head><body><nav>menu</nav>'
              . '<article><h2>Intro</h2><h3> </h3><p>Text <strong>bold</strong> <b></b> <em>it</em><i></i> <code>a`b</code> <kbd> </kbd><br>line</p><hr>'
              . '<ul><li>one</li><li> </li><span>x</span><li>two<br>lines</li></ul><ol><li>first</li></ol><li>loose</li>'
              . '<dl><dt>Term</dt><dd>Meaning</dd></dl><blockquote>quoted<br>twice</blockquote><blockquote> </blockquote>'
              . '<pre>code ``` here</pre><pre> </pre><a href="page.html#frag">a [link]</a> <a href="javascript:x()">bad</a> <a href="x"></a>'
              . '<table><tr><th>h|1</th><td>c</td></tr><tr></tr></table><table></table><div hidden>hidden</div><p aria-hidden="true">aria</p>'
              . '<p style="display: none">none</p><img src="x.png" alt="pic"><!-- comment --></article></body></html>';
        $md = expAiWebHtml::toMarkdown( $html, 'https://docs.example.com/start' );
        $this->assertSame( 'The Title', $md['title'] );
        $this->assertSame( 1, $md['links'] );
        $text = $md['text'];
        foreach ( array( '## Intro', '**bold**', '*it*', "`a'b`", "\nline", "\n---\n", "- one\n- two\n  lines", '1. first', '- loose', "**Term**\n: Meaning",
                         "> quoted\n> twice", "```\ncode ` ` ` here\n```", '[a (link)](https://docs.example.com/docs/page.html)', ' bad', '| h/1 | c |' ) as $part )
            $this->assertStringContainsString( $part, $text );
        foreach ( array( 'menu', 'hidden', 'aria', 'none', 'pic', 'x{}', '###', '](javascript' ) as $absent )
            $this->assertStringNotContainsString( $absent, $text );
        $this->assertSame( array( 'title' => '', 'text' => '', 'links' => 0 ), expAiWebHtml::toMarkdown( ' ', 'https://x.example/' ) );
        $this->assertSame( 'only main', expAiWebHtml::toMarkdown( '<body><p>outside</p><main>only main</main></body>', 'https://x.example/' )['text'] );
        $this->assertSame( 'body text', expAiWebHtml::toMarkdown( 'body text', 'https://x.example/' )['text'] );
    }

    // ------------------------------------------------------------------ expAiWebFetcher

    public function testTheFetcherFollowsRedirectsOnTheSameHostOnly(): void
    {
        expAiWebGuard::$resolver = function () { return array( '93.184.215.14' ); };
        $seen = array();
        $routes = array(
            'https://docs.example.com/a' => array( 301, '', '/b' ),
            'https://docs.example.com/b' => array( 200, 'text/html; charset="ISO-8859-1"', '', '<p>B</p>', true ),
            'http://docs.example.com/c' => array( 302, '', 'https://docs.example.com/b' ),
            'https://docs.example.com/loop' => array( 302, '', '/loop' ),
            'https://docs.example.com/away' => array( 302, '', 'https://evil.example/x?secret=1' ),
            'https://docs.example.com/bad' => array( 302, '', 'ftp://docs.example.com/' ),
            'https://docs.example.com/gone' => array( 404, 'text/html', '', 'missing' ),
            'https://docs.example.com/pic' => array( 200, 'image/png', '', 'PNG' ),
            'https://docs.example.com/plain' => array( 200, '', '', 'text' ),
            'https://other.example/' => array( 200, 'text/plain', '', 'allowed' ),
        );
        expAiWebFetcher::$transport = function ( $url, array $pin, $timeout, $maxBytes ) use ( &$seen, $routes )
        {
            $seen[] = array( $url, $pin );
            return isset( $routes[$url] ) ? $routes[$url] : array( 500 );
        };
        $fetcher = new expAiWebFetcher( $this->webConfig() );
        $page = $fetcher->fetch( 'https://docs.example.com/a' );
        $this->assertSame( array( 'https://docs.example.com/a', 'https://docs.example.com/b', 'docs.example.com', 200, 'text/html', 'iso-8859-1', '<p>B</p>', true ),
                           array( $page['url'], $page['final_url'], $page['host'], $page['status'], $page['content_type'], $page['charset'], $page['body'], $page['truncated'] ) );
        $this->assertSame( array( 'docs.example.com:443:93.184.215.14' ), $seen[0][1] );
        $this->assertSame( 'https://docs.example.com/b', $fetcher->fetch( 'http://docs.example.com/c' )['final_url'], 'http to https on the same host' );
        $this->assertSame( 'text/html', $fetcher->fetch( 'https://docs.example.com/plain' )['content_type'] );

        $errors = array( 'https://docs.example.com/loop' => 'web: too many redirects', 'https://docs.example.com/away' => 'web: cross-host redirect',
                         'https://docs.example.com/bad' => 'web: redirect to an invalid url', 'https://docs.example.com/gone' => 'web: HTTP 404',
                         'https://docs.example.com/pic' => 'web: content type image/png', 'https://other.example/' => 'web: host not allowlisted',
                         'mailto:x@example.com' => 'web: invalid url' );
        foreach ( $errors as $url => $detail )
        {
            try
            {
                $fetcher->fetch( $url );
                $this->fail( "fetched $url" );
            }
            catch ( expAiException $e )
            {
                $this->assertSame( $detail, $e->detail(), $url );
                if ( $detail === 'web: cross-host redirect' )
                    $this->assertSame( array( '%target' => 'https://evil.example/x' ), $e->arguments(), 'the query of the target is not shown' );
            }
        }
        $this->assertSame( 'allowed', $fetcher->fetch( 'https://other.example/', true )['body'], 'an allowed host (a search result) skips the list' );
    }

    public function testRobotsTxtIsReadCachedAndFollowedOnTheSameHost(): void
    {
        $this->requireDatabase();
        expAiWebGuard::$resolver = function () { return array( '93.184.215.14' ); };
        $robots = array( 'https://docs.example.com/robots.txt' => array( 301, '', '/robots2.txt' ),
                         'https://docs.example.com/robots2.txt' => array( 200, 'text/plain', '', "User-agent: *\nDisallow: /private\n" ),
                         'https://elsewhere.example.com/robots.txt' => array( 302, '', 'https://cdn.example/robots.txt' ),
                         'https://docs.example.com/private/x' => array( 200, 'text/html', '', 'secret' ),
                         'https://docs.example.com/public' => array( 200, 'text/html', '', 'ok' ),
                         'https://elsewhere.example.com/private/x' => array( 200, 'text/html', '', 'ok there' ) );
        $calls = 0;
        expAiWebFetcher::$transport = function ( $url ) use ( $robots, &$calls )
        {
            if ( substr( $url, -4 ) === '.txt' )
                $calls++;
            return isset( $robots[$url] ) ? $robots[$url] : array( 404 );
        };
        $dir = eZSys::cacheDirectory() . '/expai/web';
        foreach ( array( 'https://docs.example.com', 'https://elsewhere.example.com' ) as $origin )
        {
            $file = $dir . '/robots-' . sha1( $origin ) . '.json';
            $this->cleanup( static function () use ( $file ) { if ( is_file( $file ) ) unlink( $file ); } );
        }
        $fetcher = new expAiWebFetcher( $this->webConfig( array( 'RespectRobots' => 'true', 'RobotsCacheTTL' => 600,
                                                                 'AllowedDomains' => array( 'docs.example.com', 'elsewhere.example.com' ) ) ) );
        $this->assertSame( 'ok', $fetcher->fetch( 'https://docs.example.com/public' )['body'] );
        try
        {
            $fetcher->fetch( 'https://docs.example.com/private/x' );
            $this->fail( 'robots.txt was not followed' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'web: robots.txt disallows', $e->detail() );
        }
        $this->assertSame( 2, $calls, 'robots.txt is read once (with its redirect) and then taken from the cache' );
        $this->assertSame( 'ok there', $fetcher->fetch( 'https://elsewhere.example.com/private/x' )['body'], 'a robots.txt on another host counts as missing' );

        // a robots.txt that cannot be fetched (the guard refuses its address) allows nothing (RFC 9309: unreachable)
        expAiWebGuard::$resolver = function () { return array( '10.1.1.1' ); };
        $robotsOf = $fetcher->robots( array( 'scheme' => 'http', 'host' => 'blocked.example', 'port' => 80 ) );
        $this->assertFalse( $robotsOf->allows( '/anything' ) );
        $file = $dir . '/robots-' . sha1( 'http://blocked.example' ) . '.json';
        $this->cleanup( static function () use ( $file ) { if ( is_file( $file ) ) unlink( $file ); } );
    }

    public function testTheRealTransportReadsALocalPageAndStopsAtTheLimit(): void
    {
        $www = expAiTestMockServer::tmp( 'xte-www' );
        $this->cleanup( static function () use ( $www ) { expAiTestMockServer::removeTree( $www ); } );
        file_put_contents( $www . '/page.php', '<?php header( "Content-Type: text/html; charset=utf-8" ); echo "<title>Local</title><p>" . str_repeat( "word ", 2000 ) . "</p>";' );
        file_put_contents( $www . '/move.php', '<?php header( "Location: /page.php", true, 302 );' );
        file_put_contents( $www . '/slow.php', '<?php sleep( 3 ); echo "late";' );
        $server = expAiTestMockServer::start( '', $www );
        $port = (int) parse_url( $server['url'], PHP_URL_PORT );
        $config = $this->webConfig( array( 'AllowedDomains' => array( '127.0.0.1' ), 'PrivateHosts' => array( '127.0.0.1' ), 'AllowedPorts' => array( $port ),
                                           'MaxBytes' => 4096, 'Timeout' => 1 ) );
        $fetcher = new expAiWebFetcher( $config );
        $page = $fetcher->fetch( $server['url'] . '/move.php' );
        $this->assertSame( $server['url'] . '/page.php', $page['final_url'] );
        $this->assertSame( array( 'text/html', 'utf-8', 4096, true ), array( $page['content_type'], $page['charset'], strlen( $page['body'] ), $page['truncated'] ) );
        try
        {
            $fetcher->fetch( $server['url'] . '/slow.php' );
            $this->fail( 'no timeout' );
        }
        catch ( expAiException $e )
        {
            $this->assertStringStartsWith( 'web: timeout', $e->detail() );
        }
        // a host name: the checked address is pinned for curl
        file_put_contents( $www . '/small.txt', 'small page' );
        expAiWebGuard::$resolver = function () { return array( '127.0.0.1' ); };
        $named = new expAiWebFetcher( $this->webConfig( array( 'AllowedDomains' => array( 'xte.invalid' ), 'PrivateHosts' => array( 'xte.invalid' ), 'AllowedPorts' => array( $port ) ) ) );
        $page = $named->fetch( 'http://xte.invalid:' . $port . '/small.txt' );
        $this->assertSame( array( 'small page', false, 'text/plain' ), array( $page['body'], $page['truncated'], $page['content_type'] ) );

        $probe = stream_socket_server( 'tcp://127.0.0.1:0' );
        $closed = (int) substr( strrchr( stream_socket_get_name( $probe, false ), ':' ), 1 );
        fclose( $probe );
        try
        {
            ( new expAiWebFetcher( $this->webConfig( array( 'AllowedDomains' => array( '127.0.0.1' ), 'PrivateHosts' => array( '127.0.0.1' ), 'AllowedPorts' => array( $closed ) ) ) ) )
                ->fetch( 'http://127.0.0.1:' . $closed . '/' );
            $this->fail( 'reached a closed port' );
        }
        catch ( expAiException $e )
        {
            $this->assertStringStartsWith( 'web: curl error ', $e->detail() );
        }
        $this->assertStringContainsString( 'read-only fetch for an editor', expAiWebFetcher::userAgent() );
    }

    // ------------------------------------------------------------------ search providers

    public function testTheBraveSearchRequestAndItsResults(): void
    {
        $http = new expAiProviderTestHttp( array( array( 200, json_encode( array( 'web' => array( 'results' => array(
            array( 'title' => '<b>Spring</b> &amp; events', 'url' => 'https://a.example/', 'description' => 'Snip <em>pet</em>' ),
            'junk', array( 'title' => 'no url' ),
            array( 'title' => 'Two', 'url' => 'https://b.example/', 'description' => '' ),
            array( 'title' => 'Three', 'url' => 'https://c.example/' ) ) ) ) ) ) ) );
        $brave = new expAiWebSearchBrave( array( 'ApiKey' => 'test-not-a-key', 'Country' => 'de', 'SearchLang' => 'de', 'ProviderId' => 'brave', 'Region' => 'US', 'Retention' => 'ZDR' ) );
        $brave->http = $http;
        $results = $brave->search( 'spring events', 2 );
        $this->assertSame( array( array( 'title' => 'Spring & events', 'url' => 'https://a.example/', 'snippet' => 'Snip pet' ),
                                  array( 'title' => 'Two', 'url' => 'https://b.example/', 'snippet' => '' ) ), $results );
        list( $url, $headers, , $options ) = $http->requests[0];
        $this->assertSame( 'https://api.search.brave.com/res/v1/web/search?q=spring%20events&count=2&safesearch=moderate&country=de&search_lang=de', $url );
        $this->assertSame( array( 'X-Subscription-Token: test-not-a-key' ), $headers );
        $this->assertSame( 'GET', $options['method'] );
        $this->assertSame( array( 'brave', 'brave', 'us', 'zdr' ), array( $brave->id(), $brave->label(), $brave->region(), $brave->retention() ) );

        foreach ( array( 401 => 'search: HTTP 401', 403 => 'search: HTTP 403', 429 => 'search: HTTP 429', 500 => 'search: HTTP 500' ) as $status => $detail )
        {
            $brave->http = new expAiProviderTestHttp( array( array( $status, '' ) ) );
            try
            {
                $brave->search( 'x', 3 );
                $this->fail( "no exception for $status" );
            }
            catch ( expAiException $e )
            {
                $this->assertSame( $detail, $e->detail() );
            }
        }
    }

    public function testBraveRefusesAnEndpointThatIsNotHttpsOrPrivate(): void
    {
        $brave = new expAiWebSearchBrave( array( 'ApiKey' => 'k', 'Endpoint' => 'http://api.search.brave.com/x' ) );
        try
        {
            $brave->search( 'x', 1 );
            $this->fail( 'accepted http' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'search: the endpoint must be https', $e->detail() );
        }
        // the address of the endpoint is checked before anything is sent
        expAiWebGuard::$resolver = function () { return array( '127.0.0.1' ); };
        $brave = new expAiWebSearchBrave( array( 'ApiKey' => 'k' ) );
        try
        {
            $brave->search( 'x', 1 );
            $this->fail( 'sent to a private address' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'web: loopback address 127.0.0.1', $e->detail() );
        }
    }

    public function testTheSearchProviderComesFromTheSettings(): void
    {
        $this->assertNull( expAiWebSearchProvider::fromConfig( $this->config( array( 'SearchSettings' => array( 'Provider' => 'Bad Id' ) ) ) ) );
        $this->assertNull( expAiWebSearchProvider::fromConfig( $this->config( array( 'SearchSettings' => array( 'Provider' => 'xtenone' ) ) ) ) );
        $this->assertNull( expAiWebSearchProvider::fromConfig( $this->config( array( 'SearchSettings' => array( 'Provider' => 'xte' ),
                                                                                        'SearchProvider_xte' => array( 'Class' => 'expAiConfig', 'ApiKey' => 'k' ) ) ) ) );
        $this->assertNull( expAiWebSearchProvider::fromConfig( $this->config( array( 'SearchSettings' => array( 'Provider' => 'xte' ),
                                                                                        'SearchProvider_xte' => array( 'Class' => 'expAiProviderTestSearch', 'ApiKey' => '' ) ) ) ), 'no key' );
        $provider = expAiWebSearchProvider::fromConfig( $this->config( array( 'SearchSettings' => array( 'Provider' => 'xte' ),
                                                                              'SearchProvider_xte' => array( 'Class' => 'expAiProviderTestSearch', 'ApiKey' => 'k', 'Label' => 'Test search' ) ) ) );
        $this->assertInstanceOf( 'expAiProviderTestSearch', $provider );
        $this->assertSame( array( 'xte', 'Test search' ), array( $provider->id(), $provider->label() ) );
        $this->assertSame( array( array( 'id' => 'web_fetch', 'name' => 'Web page (web_fetch)' ), array( 'id' => 'web_search', 'name' => 'Web search (web_search)' ),
                                  array( 'id' => 'project_history', 'name' => 'Project history (project_history)' ) ), ( new expAiWebPolicy( array() ) )->tools() );
    }

    // ------------------------------------------------------------------ the tools

    public function testWebSearchMarksTheFetchableResultsAndChecksTheDataPolicy(): void
    {
        $this->requireDatabase();
        $this->asUser( 'admin' );
        $config = $this->webConfig( array( 'AllowedDomains' => array( 'a.example' ) ), array( 'SearchSettings' => array( 'MaxResults' => 2, 'MaxSearchesPerTurn' => 4 ) ) );
        $tool = new expAiToolWebSearch( 'web_search' );
        $context = new expAiToolContext( $config, 2 );
        $this->assertFalse( $tool->configured( $config ) );
        try
        {
            $tool->execute( array( 'query' => 'x' ), $context );
            $this->fail( 'searched without a provider' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'search: no provider or no key', $e->detail() );
        }
        $tool->provider = new expAiProviderTestSearch( array( 'ApiKey' => 'k', 'ProviderId' => 'xte', 'Label' => 'T', 'Region' => 'eu', 'Retention' => 'none' ) );
        $tool->provider->rows = array( array( 'title' => 'A', 'url' => 'https://a.example/x', 'snippet' => '' ), array( 'title' => 'B', 'url' => 'https://b.example/', 'snippet' => '' ),
                                       array( 'title' => 'C', 'url' => 'https://c.example/', 'snippet' => '' ) );
        $this->assertTrue( $tool->configured( $config ) );
        $this->assertStringContainsString( 'never put content of the site', $tool->description() );
        $this->assertSame( array( 'query' ), $tool->inputSchema()['required'] );
        $result = $tool->execute( array( 'query' => "  spring\nevents ", 'count' => 9 ), $context );
        $this->assertSame( 'spring events', $result['query'] );
        $this->assertSame( array( true, false ), array_column( $result['results'], 'fetchable' ) );
        $this->assertSame( 'web search (T)', $result['source'] );
        $this->assertSame( array( 'host' => 'search:xte' ), $tool->logFields() );
        $this->assertSame( array( '%query' => 'spring events' ), $tool->stepArguments( array( 'query' => "spring\nevents" ), null ) );
        $this->assertSame( 'Searched the web for "%query"', $tool->stepText( array() ) );
        try
        {
            $tool->execute( array( 'query' => str_repeat( 'x', 201 ) ), $context );
            $this->fail( 'accepted a long query' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'search: query length', $e->detail() );
        }
        // the cap per request: every call counts, also a refused one (three so far): the fourth is the last
        $tool->execute( array( 'query' => 'third' ), $context );
        try
        {
            $tool->execute( array( 'query' => 'fourth' ), $context );
            $this->fail( 'no cap' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'web: per-turn cap', $e->detail() );
        }
    }

    public function testSearchResultUrlsOfAConversationAndTheCountSinceTheRequest(): void
    {
        $messages = array(
            array( 'role' => 'user', 'content' => 'find it' ),
            array( 'role' => 'assistant', 'content_json' => json_encode( array( array( 'type' => 'tool_use', 'id' => 's1', 'name' => 'web_search', 'input' => array() ),
                                                                              array( 'type' => 'tool_use', 'id' => 'f1', 'name' => 'web_fetch', 'input' => array() ) ) ) ),
            array( 'role' => 'user', 'content' => array( array( 'type' => 'tool_result', 'tool_use_id' => 's1', 'content' => json_encode( array( 'results' => array(
                array( 'url' => 'https://A.example/x' ), array( 'url' => 'ftp://no' ), array( 'title' => 'no url' ) ) ) ) ),
                                                         array( 'type' => 'tool_result', 'tool_use_id' => 'f1', 'content' => '{"results":[{"url":"https://not-a-search.example/"}]}' ),
                                                       ) ),
            array( 'role' => 'assistant', 'content_json' => json_encode( array( 'junk', array( 'type' => 'tool_use', 'id' => 's2', 'name' => 'web_search', 'input' => array() ) ) ) ),
            array( 'role' => 'user', 'content' => array( array( 'type' => 'tool_result', 'tool_use_id' => 's2', 'is_error' => true, 'content' => '{"results":[{"url":"https://err.example/"}]}' ) ) ),
        );
        $this->assertSame( array( 'https://a.example/x' ), expAiToolWebSearch::resultUrls( $messages ) );
        $this->assertSame( 1, expAiWebTool::countSinceRequest( $messages, 'web_fetch' ) );
        $this->assertSame( 1, expAiWebTool::countSinceRequest( array_slice( $messages, 0, 3 ), 'web_search' ), 'tool results do not end the request' );
        $this->assertSame( 2, expAiWebTool::countSinceRequest( $messages, 'web_search' ) );
    }

    public function testWebFetchConvertsPagesJsonAndOtherCharsets(): void
    {
        $this->requireDatabase();
        $this->asUser( 'admin' );
        expAiWebGuard::$resolver = function () { return array( '93.184.215.14' ); };
        $pages = array(
            'https://docs.example.com/page' => array( 200, 'text/html; charset=ISO-8859-1', '', '<title>Caf' . "\xe9" . '</title><p>Hello <a href="/next">next</a> ' . str_repeat( 'x', 900 ) . '</p>' ),
            'https://docs.example.com/latin' => array( 200, 'text/html', '', '<meta charset="iso-8859-1"><title>Caf' . "\xe9" . '</title><p>x</p>' ),
            'https://docs.example.com/data' => array( 200, 'application/json', '', '{"a":[1,2]}' ),
            'https://docs.example.com/broken' => array( 200, 'application/json', '', '{not json' ),
            'https://docs.example.com/notes' => array( 200, 'text/plain; charset=utf-8', '', 'plain notes' ),
            'https://found.example/page' => array( 200, 'text/plain', '', 'from the search' ),
        );
        expAiWebFetcher::$transport = function ( $url ) use ( $pages ) { return isset( $pages[$url] ) ? $pages[$url] : array( 404 ); };
        $config = $this->webConfig( array( 'MaxResultChars' => 500, 'MaxFetchesPerTurn' => 10 ), array( 'SearchSettings' => array( 'SearchResultsFetchable' => 'true' ) ) );
        $tool = new expAiToolWebFetch( 'web_fetch' );
        $this->assertTrue( $tool->configured( $config ) );
        $this->assertSame( array( 'url' ), $tool->inputSchema()['required'] );
        $context = new expAiToolContext( $config, 2 );

        $result = $tool->execute( array( 'url' => 'https://docs.example.com/page', 'max_chars' => 300 ), $context );
        $this->assertSame( 'Café', $result['title'] );
        $this->assertSame( 1, $result['links'] );
        $this->assertTrue( $result['truncated'] );
        $this->assertStringContainsString( '[cut: the page has ', $result['content'] );
        $this->assertStringStartsWith( '[Begin of outside data from docs.example.com.', $result['content'] );
        $this->assertSame( array( 'host' => 'docs.example.com' ), $tool->logFields() );

        $this->assertStringContainsString( "\"a\": [\n", $tool->execute( array( 'url' => 'https://docs.example.com/data' ), $context )['content'] );
        $this->assertStringContainsString( '{not json', $tool->execute( array( 'url' => 'https://docs.example.com/broken' ), $context )['content'] );
        $notes = $tool->execute( array( 'url' => 'https://docs.example.com/notes' ), $context );
        $this->assertSame( array( 'text/plain', false ), array( $notes['content_type'], $notes['truncated'] ) );

        // a result of a search in this conversation may be fetched although its host is not on the list
        $conversation = new expAiConversation( array( 'messages' => json_encode( array(
            array( 'role' => 'assistant', 'content_json' => json_encode( array( array( 'type' => 'tool_use', 'id' => 's1', 'name' => 'web_search', 'input' => array() ) ) ) ),
            array( 'role' => 'user', 'content' => array( array( 'type' => 'tool_result', 'tool_use_id' => 's1', 'content' => '{"results":[{"url":"https://found.example/page"}]}' ) ) ) ) ) ) );
        $context = new expAiToolContext( $config, 2, $conversation );
        $this->assertStringContainsString( 'from the search', $tool->execute( array( 'url' => 'https://found.example/page' ), $context )['content'] );
        $this->assertSame( array( '%host' => 'found.example' ), $tool->stepArguments( array( 'url' => 'https://found.example/page' ), null ) );
        $this->assertSame( array( '%host' => '' ), $tool->stepArguments( array(), null ) );
        $this->assertSame( 'Read the web page %host', $tool->stepText( array() ) );
    }

    public function testWebFetchOfAPageThatNamesItsCharsetInAMetaElement(): void
    {
        $this->requireDatabase();
        $this->asUser( 'admin' );
        expAiWebGuard::$resolver = function () { return array( '93.184.215.14' ); };
        expAiWebFetcher::$transport = function () { return array( 200, 'text/html', '', '<meta charset="iso-8859-1"><title>Caf' . "\xe9" . '</title><p>x</p>' ); };
        $config = $this->webConfig();
        // converted to UTF-8 by the tool; the meta element must not make libxml decode it a second time
        $latin = ( new expAiToolWebFetch( 'web_fetch' ) )->execute( array( 'url' => 'https://docs.example.com/latin' ), new expAiToolContext( $config, 2 ) );
        $this->assertSame( 'Café', $latin['title'] );
    }
}
