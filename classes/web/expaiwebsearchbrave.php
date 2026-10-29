<?php
/**
 * File containing the expAiWebSearchBrave class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Brave Search API (web search): GET <Endpoint>?q=...&count=... with the key in the X-Subscription-Token header.
 * The endpoint host must resolve to public addresses (expAiWebGuard) and the connection is pinned. Only the
 * query, the count and the optional Country / SearchLang settings are sent.
 */
class expAiWebSearchBrave extends expAiWebSearchProvider
{
    /** @var expAiHttpClient|null tests */
    public $http = null;

    public function search( $query, $count )
    {
        $endpoint = $this->setting( 'Endpoint', 'https://api.search.brave.com/res/v1/web/search' );
        $parts = expAiWebUrl::parse( $endpoint );
        if ( !$parts || $parts['scheme'] !== 'https' && !$this->http )
            throw new expAiException( 'Web search is not configured correctly.', 'search: the endpoint must be https' );
        $params = array( 'q' => (string) $query, 'count' => max( 1, min( 20, (int) $count ) ), 'safesearch' => $this->setting( 'SafeSearch', 'moderate' ) );
        if ( $this->setting( 'Country' ) !== '' )
            $params['country'] = $this->setting( 'Country' );
        if ( $this->setting( 'SearchLang' ) !== '' )
            $params['search_lang'] = $this->setting( 'SearchLang' );
        $url = $parts['url'] . ( strpos( $parts['url'], '?' ) === false ? '?' : '&' ) . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
        $pin = array();
        if ( !$this->http )
            list( $pin ) = ( new expAiWebGuard() )->check( $parts );
        $http = $this->http ?: new expAiHttpClient( (int) $this->setting( 'Timeout', 10 ), 5, 1048576, array( 'retry-after', 'x-ratelimit-*' ) );
        list( $status, $body ) = $http->request( $url, array( 'X-Subscription-Token: ' . $this->setting( 'ApiKey' ) ), '',
                                                 array( 'method' => 'GET', 'accept' => 'application/json', 'resolve' => $pin ) );
        if ( $status === 401 || $status === 403 )
            throw new expAiException( 'Web search refused the request: the key is not valid.', 'search: HTTP ' . $status, $status );
        if ( $status === 429 )
            throw new expAiException( 'Web search is busy or its monthly allowance is used up. Please try again later.', 'search: HTTP 429', 429 );
        if ( $status < 200 || $status >= 300 )
            throw new expAiException( 'Web search did not answer properly (HTTP %status).', 'search: HTTP ' . $status, $status, array( '%status' => $status ) );
        $data = json_decode( (string) $body, true );
        $out = array();
        foreach ( isset( $data['web']['results'] ) && is_array( $data['web']['results'] ) ? $data['web']['results'] : array() as $row )
        {
            if ( !is_array( $row ) || empty( $row['url'] ) )
                continue;
            $out[] = array( 'title' => expAiText::cut( expAiText::oneLine( html_entity_decode( strip_tags( isset( $row['title'] ) ? (string) $row['title'] : '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 200 ),
                            'url' => expAiText::cut( (string) $row['url'], 2048 ),
                            'snippet' => expAiText::cut( expAiText::oneLine( html_entity_decode( strip_tags( isset( $row['description'] ) ? (string) $row['description'] : '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 400 ) );
            if ( count( $out ) >= $count )
                break;
        }
        return $out;
    }
}
