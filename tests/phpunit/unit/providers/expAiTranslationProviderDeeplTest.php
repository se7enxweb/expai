<?php
/**
 * The machine translation services DeepL and LibreTranslate against a fake HTTP client: the body and the headers they
 * send, batching, the context, retries, permanent errors, the billed characters and the cost, and the units that come
 * back in their own format (coded, plain text, keywords, HTML). DB-less, no network.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiTranslationProviderDeeplTest extends expAiProviderTestCase
{
    /** @return expAiTranslationProviderDeepl */
    private function deepl( array $settings = array(), array $answers = array() )
    {
        $config = $this->config( array( 'Pricing' => array( 'Currency' => 'EUR' ) ) );
        $provider = new expAiTranslationProviderDeepl( 'deepl', $settings + array( 'Class' => 'expAiTranslationProviderDeepl', 'Label' => 'DeepL',
            'Endpoint' => 'https://api.deepl.com/v2/translate', 'ApiKey' => 'test-not-a-key', 'Region' => 'eu', 'Retention' => 'none',
            'PricePerMChars' => '20', 'PriceCurrency' => 'EUR', 'SendContext' => 'disabled' ), $config );
        $provider->http = new expAiProviderTestHttp( $answers );
        return $provider;
    }

    /** @return string a DeepL answer of the texts */
    private static function answer( array $texts, $billed = true )
    {
        $rows = array();
        foreach ( $texts as $text )
            $rows[] = array( 'detected_source_language' => 'DE', 'text' => $text ) + ( $billed ? array( 'billed_characters' => 10 ) : array() );
        return json_encode( array( 'translations' => $rows ) );
    }

    /** @return expAiTranslationRequest */
    private static function translationRequest( array $units, $purpose = 'patch' )
    {
        $request = new expAiTranslationRequest();
        $request->source = 'ger-DE';
        $request->target = 'eng-GB';
        $request->purpose = $purpose;
        foreach ( $units as $unit )
            $request->units[] = expAiTranslationRequest::unit( $unit );
        return $request;
    }

    public function testTheBodyAndTheHeaderOfARequest(): void
    {
        $deepl = $this->deepl( array( 'Formality' => 'prefer_more', 'GlossaryId' => array( 'ger-DE:eng-GB' => 'gl-123' ) ), array(
            array( 200, self::answer( array( 'The <x i="1">dog</x> sleeps &amp; <k>Exponential</k> dreams.<x i="2"/>', 'Title' ) ) ) ) );
        $request = self::translationRequest( array(
            array( 'id' => 'u1', 'kind' => 'block', 'format' => 'coded', 'text' => 'Der <1>Hund</1> schläft &amp; Exponential träumt.<2/>',
                   'old_source' => 'Der Hund', 'current_translation' => 'The dog', 'context_before' => 'Davor.', 'context_after' => 'Danach.' ),
            array( 'id' => 'u2', 'kind' => 'text', 'format' => 'text', 'text' => 'Titel' ) ) );
        $request->doNotTranslate = array( 'Exponential' );
        $result = $deepl->translate( $request );
        $this->assertCount( 1, $deepl->http->requests, 'one request for both units' );
        list( $url, $headers, , ) = $deepl->http->requests[0];
        $this->assertSame( 'https://api.deepl.com/v2/translate', $url );
        $this->assertSame( array( 'Authorization: DeepL-Auth-Key test-not-a-key' ), $headers );
        $body = $deepl->http->json( 0 );
        $this->assertSame( array( 'Der <x i="1">Hund</x> schläft &amp; <k>Exponential</k> träumt.<x i="2"/>', 'Titel' ), $body['text'] );
        $this->assertSame( array( 'DE', 'EN-GB', 'xml', array( 'x' ), array( 'k' ), 'prefer_more', 'gl-123', true ),
                           array( $body['source_lang'], $body['target_lang'], $body['tag_handling'], $body['non_splitting_tags'], $body['ignore_tags'],
                                  $body['formality'], $body['glossary_id'], $body['show_billed_characters'] ) );
        $this->assertArrayNotHasKey( 'context', $body, 'SendContext=disabled' );
        $this->assertStringNotContainsString( 'The dog', $deepl->http->requests[0][2], 'the current translation is never sent' );
        $this->assertStringNotContainsString( 'Davor', $deepl->http->requests[0][2] );
        $this->assertSame( array( 'u1' => 'The <1>dog</1> sleeps &amp; Exponential dreams.<2/>', 'u2' => 'Title' ), $result->units );
        $this->assertSame( array(), $result->errors );
        // cost from billed_characters: 20 characters x 20 EUR per million
        $this->assertSame( array( 20, 400, true, 'deepl' ), array( $result->billedChars, $result->costMicros, $result->costKnown, $result->engine ) );
    }

    public function testBatchesContextAndKeywords(): void
    {
        $echo = function ( $url, $headers, $body ) { return array( 200, self::answer( json_decode( $body, true )['text'] ) ); };
        $deepl = $this->deepl( array( 'MaxTextsPerRequest' => '2', 'SendContext' => 'enabled' ), array( $echo, $echo, $echo ) );
        $result = $deepl->translate( self::translationRequest( array(
            array( 'id' => 'u1', 'kind' => 'keywords', 'format' => 'text', 'text' => 'Hund, Katze , Maus,' ),
            array( 'id' => 'u2', 'kind' => 'block', 'format' => 'coded', 'text' => 'Mit <1>Kontext</1>.', 'context_before' => 'Davor.', 'context_after' => 'Danach.' ),
            array( 'id' => 'u3', 'kind' => 'text', 'format' => 'text', 'text' => 'A < B & C' ) ) ) );
        $this->assertCount( 3, $deepl->http->requests, 'keywords one by one, two per request; the block with context alone' );
        $this->assertSame( array( 'Hund', 'Katze' ), $deepl->http->json( 0 )['text'] );
        $this->assertSame( array( 'Maus', 'A &lt; B &amp; C' ), $deepl->http->json( 1 )['text'] );
        $this->assertArrayNotHasKey( 'context', $deepl->http->json( 1 ) );
        $this->assertSame( array( 'Mit <x i="1">Kontext</x>.' ), $deepl->http->json( 2 )['text'] );
        $this->assertSame( "Davor.\nDanach.", $deepl->http->json( 2 )['context'] );
        $this->assertSame( array( 'u1' => 'Hund, Katze, Maus', 'u2' => 'Mit <1>Kontext</1>.', 'u3' => 'A < B & C' ), $result->units,
                           'keywords joined again, plain text unescaped' );
    }

    public function testTheEditorsHtmlGoesWithHtmlTagHandling(): void
    {
        $deepl = $this->deepl( array(), array( array( 200, self::answer( array( '<p>The <strong>dog</strong></p>' ), false ) ) ) );
        $result = $deepl->translate( self::translationRequest( array( array( 'id' => 'f1', 'kind' => 'html', 'format' => 'html', 'text' => '<p>Der <strong>Hund</strong></p>' ) ), 'field' ) );
        $body = $deepl->http->json( 0 );
        $this->assertSame( 'html', $body['tag_handling'] );
        $this->assertArrayNotHasKey( 'non_splitting_tags', $body );
        $this->assertSame( '<p>The <strong>dog</strong></p>', $result->units['f1'] );
        $this->assertSame( 8, $result->billedChars, 'not reported: the characters of the source text (without the tags)' );
    }

    public function testBusyAnswersAreRetriedAndPermanentOnesAreNot(): void
    {
        $deepl = $this->deepl( array(), array( array( 429, '{"message":"Too many requests"}', array( 'retry-after' => '3' ) ),
                                               array( 503, '' ), array( 200, self::answer( array( 'ok' ) ) ) ) );
        $result = $deepl->translate( self::translationRequest( array( array( 'id' => 'u1', 'format' => 'text', 'kind' => 'text', 'text' => 'gut' ) ) ) );
        $this->assertSame( array( 3.0, 2 ), $deepl->http->waits, 'the retry-after, then 2^1 seconds' );
        $this->assertSame( array( 'ok', 2 ), array( $result->units['u1'], $result->retries ) );

        // still busy after the retries: transient, left to the queue
        $deepl = $this->deepl( array( 'MaxRetries' => '1' ), array( array( 429, '' ), array( 429, '', array( 'retry-after' => '7' ) ) ) );
        $e = $this->translateFails( $deepl );
        $this->assertSame( array( 429, true, 7 ), array( $e->getCode(), $e->transient(), $e->retryAfter ) );
        // a retry-after above RetryAfterMax is not waited for
        $deepl = $this->deepl( array( 'RetryAfterMax' => '5' ), array( array( 503, '', array( 'retry-after' => '60' ) ) ) );
        $this->assertSame( array( 503, true ), array( $this->translateFails( $deepl )->getCode(), true ) );
        $this->assertSame( array(), $deepl->http->waits );

        foreach ( array( 456 => 'The quota of the translation service %service is used up.', 403 => 'The translation service %service refused the key.',
                         400 => 'The translation service %service refused the request (HTTP %status).' ) as $status => $message )
        {
            $deepl = $this->deepl( array(), array( array( $status, '{"message":"no"}' ) ) );
            $e = $this->translateFails( $deepl );
            $this->assertSame( array( $status, false, $message ), array( $e->getCode(), $e->transient(), $e->getMessage() ), 'HTTP ' . $status );
            $this->assertSame( array( '%service' => 'DeepL' ), array_intersect_key( $e->arguments(), array( '%service' => 1 ) ) );
            $this->assertCount( 1, $deepl->http->requests, 'not retried' );
            $this->assertStringContainsString( ': no', $e->detail() );
        }
        // the connection failed: transient
        $deepl = $this->deepl( array(), array( new expAiException( 'Exponential Intelligence could not be reached.', 'curl error 7' ) ) );
        $e = $this->translateFails( $deepl );
        $this->assertSame( array( 0, true, 'curl error 7' ), array( $e->getCode(), $e->transient(), $e->detail() ) );
        // an answer that cannot be read
        $deepl = $this->deepl( array(), array( array( 200, '{"translations":"no"}' ) ) );
        $this->assertSame( 'The answer of the translation service %service could not be read.', $this->translateFails( $deepl )->getMessage() );
    }

    public function testTermsInTheEditorsHtml(): void
    {
        $deepl = $this->deepl( array(), array( array( 200, self::answer( array( '<p>The dog of <span translate="no" class="notranslate">Velocity</span></p>' ) ) ) ) );
        $request = self::translationRequest( array( array( 'id' => 'f1', 'kind' => 'html', 'format' => 'html', 'text' => '<p>Der Hund von Velocity</p>' ) ), 'field' );
        $request->doNotTranslate = array( 'Velocity' );
        $result = $deepl->translate( $request );
        $body = $deepl->http->json( 0 );
        $this->assertSame( array( '<p>Der Hund von <span translate="no" class="notranslate">Velocity</span></p>' ), $body['text'] );
        $this->assertSame( 'html', $body['tag_handling'] );
        $this->assertArrayNotHasKey( 'ignore_tags', $body, 'translate="no" is honoured in HTML mode' );
        $this->assertSame( '<p>The dog of Velocity</p>', $result->units['f1'] );
    }

    public function testAFailedLaterRequestKeepsWhatWasTranslatedForTheRetry(): void
    {
        $echo = function ( $url, $headers, $body ) { return array( 200, self::answer( json_decode( $body, true )['text'] ) ); };
        $units = array( array( 'id' => 'u1', 'format' => 'text', 'kind' => 'text', 'text' => 'Eins' ), array( 'id' => 'u2', 'format' => 'text', 'kind' => 'text', 'text' => 'Zwei' ),
                        array( 'id' => 'u3', 'format' => 'text', 'kind' => 'text', 'text' => 'Drei' ) );
        $deepl = $this->deepl( array( 'MaxTextsPerRequest' => '2', 'MaxRetries' => '0' ), array( $echo, array( 503, '' ) ) );
        $e = $this->translateFails( $deepl, self::translationRequest( $units ) );
        $this->assertTrue( $e->transient() );
        $this->assertSame( array( 0 => 'Eins', 1 => 'Zwei' ), $e->partial['texts'] );
        $this->assertSame( array( 20, 400 ), array( $e->partial['billed'], $e->partial['cost_micros'] ), 'the first request was billed' );

        // the retry sends only what is missing, and bills only that
        $retry = $this->deepl( array( 'MaxTextsPerRequest' => '2' ), array( $echo ) );
        $request = self::translationRequest( $units );
        $request->resume = array( 'key' => $e->partial['key'], 'texts' => $e->partial['texts'] );
        $result = $retry->translate( $request );
        $this->assertCount( 1, $retry->http->requests );
        $this->assertSame( array( 'Drei' ), $retry->http->json( 0 )['text'] );
        $this->assertSame( array( 'u1' => 'Eins', 'u2' => 'Zwei', 'u3' => 'Drei' ), $result->units );
        $this->assertSame( 10, $result->billedChars );
        // a resume of other pieces is ignored
        $other = $this->deepl( array( 'MaxTextsPerRequest' => '2' ), array( $echo, $echo ) );
        $request->resume = array( 'key' => 'not-these', 'texts' => array( 0 => 'X' ) );
        $this->assertSame( 'Eins', $other->translate( $request )->units['u1'] );
        $this->assertCount( 2, $other->http->requests );
        // a plain expAiException (the gate, a limit, a bug) is never retried as a failure of the service
        $this->assertFalse( expAiTranslationException::isTransient( new expAiException( 'x', 'y', 0 ) ) );
        $this->assertFalse( expAiTranslationException::isTransient( new expAiException( 'x', 'y', 503 ) ) );
    }

    public function testAPlaceholderThatComesBackWrongIsAUnitError(): void
    {
        $deepl = $this->deepl( array(), array( array( 200, self::answer( array( 'The dog sleeps.', 'Fine <x i="1">here</x>' ) ) ) ) );
        $result = $deepl->translate( self::translationRequest( array(
            array( 'id' => 'u1', 'format' => 'coded', 'kind' => 'block', 'text' => 'Der <1>Hund</1> schläft.' ),
            array( 'id' => 'u2', 'format' => 'coded', 'kind' => 'block', 'text' => 'Gut <1>hier</1>' ) ) ) );
        $this->assertFalse( $result->complete() );
        $this->assertSame( array( 'u1' => 'placeholder 1 missing' ), $result->errors );
        $this->assertSame( array( 'u2' => 'Fine <1>here</1>' ), $result->units, 'only the units that fit' );
    }

    public function testConfigurationSupportAndCost(): void
    {
        $deepl = $this->deepl();
        $this->assertTrue( $deepl->isConfigured() );
        $this->assertSame( array( 'deepl', 'DeepL', 'mt', 'eu', 'none' ), array( $deepl->id(), $deepl->label(), $deepl->kind(), $deepl->region(), $deepl->retention() ) );
        $this->assertTrue( $deepl->supports( 'ger-DE', 'eng-GB', 'patch' ) );
        $this->assertFalse( $deepl->supports( 'ger-DE', 'ger-DE', 'patch' ) );
        $this->assertFalse( $deepl->supports( 'ger-DE', 'tlh-QO', 'full' ) );
        $this->assertSame( array( 0, false ), $this->deepl( array( 'PricePerMChars' => '' ) )->cost( 1000 ) );
        $this->assertSame( array( 20000000, true ), $deepl->cost( 1000000 ), 'a million characters: 20 EUR' );
        $estimate = $deepl->estimate( self::translationRequest( array( array( 'id' => 'u1', 'format' => 'coded', 'kind' => 'block', 'text' => 'Der <1>Hund</1> &amp; die Katze' ) ) ) );
        $this->assertSame( array( 20, 400, 'DeepL' ), array( $estimate['chars'], $estimate['micros'], $estimate['label'] ), 'placeholders are not billed, entities count once' );
        $this->assertStringContainsString( 'no API key', $this->deepl( array( 'ApiKey' => '' ) )->unavailableReason() );
        $this->assertStringContainsString( 'https', $this->deepl( array( 'Endpoint' => 'http://api.deepl.com/v2/translate' ) )->unavailableReason() );
        $policy = $deepl->dataPolicy();
        $this->assertSame( array( 'deepl', 'eu' ), array( $policy->providerID(), $policy->providerRegion() ) );
        $unsupported = $this->deepl();
        $request = self::translationRequest( array( array( 'id' => 'u1', 'format' => 'text', 'kind' => 'text', 'text' => 'x' ) ) );
        $request->target = 'tlh-QO';
        $e = $this->translateFails( $unsupported, $request );
        $this->assertSame( array( 'The translation service %service does not translate %source into %target.', false ), array( $e->getMessage(), $e->transient() ) );
        $this->assertSame( array(), $unsupported->http->requests, 'nothing is sent for a pair the service does not translate' );
    }

    public function testLibreTranslate(): void
    {
        $config = $this->config();
        $libre = new expAiTranslationProviderLibreTranslate( 'libretranslate', array( 'Class' => 'expAiTranslationProviderLibreTranslate', 'Region' => 'local',
            'Retention' => 'none', 'PricePerMChars' => '0' ), $config );
        $this->assertSame( '', $libre->unavailableReason(), 'a local server needs no key and may use http' );
        $libre->http = new expAiProviderTestHttp( array(
            array( 200, json_encode( array( 'translatedText' => array( 'The <span data-x="1">dog</span> <span translate="no" class="notranslate">Velocity</span><br data-x="2">', 'Title' ) ) ) ) ) );
        $request = self::translationRequest( array( array( 'id' => 'u1', 'format' => 'coded', 'kind' => 'block', 'text' => 'Der <1>Hund</1> Velocity<2/>' ),
                                         array( 'id' => 'u2', 'format' => 'text', 'kind' => 'text', 'text' => 'Titel' ) ) );
        $request->doNotTranslate = array( 'Velocity' );
        $result = $libre->translate( $request );
        list( $url, $headers, , ) = $libre->http->requests[0];
        $this->assertSame( array( 'http://127.0.0.1:5000/translate', array() ), array( $url, $headers ) );
        $body = $libre->http->json( 0 );
        $this->assertSame( array( 'de', 'en', 'html' ), array( $body['source'], $body['target'], $body['format'] ) );
        $this->assertSame( 'Der <span data-x="1">Hund</span> <span translate="no" class="notranslate">Velocity</span><br data-x="2">', $body['q'][0] );
        $this->assertArrayNotHasKey( 'api_key', $body );
        $this->assertSame( array( 'u1' => 'The <1>dog</1> Velocity<2/>', 'u2' => 'Title' ), $result->units );
        $this->assertSame( array( 0, true ), array( $result->costMicros, $result->costKnown ) );
        $this->assertFalse( $libre->capabilities()['markup_reliable'] );

        // with a key; one text answers with a string
        $keyed = new expAiTranslationProviderLibreTranslate( 'libretranslate', array( 'ApiKey' => 'k', 'Region' => 'local' ), $config );
        $keyed->http = new expAiProviderTestHttp( array( array( 200, '{"translatedText":"Hello"}' ), array( 403, '{"error":"Invalid API key"}' ) ) );
        $this->assertSame( 'Hello', $keyed->translate( self::translationRequest( array( array( 'id' => 'u1', 'format' => 'text', 'kind' => 'text', 'text' => 'Hallo' ) ) ) )->units['u1'] );
        $this->assertSame( 'k', $keyed->http->json( 0 )['api_key'] );
        $e = $this->translateFails( $keyed );
        $this->assertSame( array( 403, false ), array( $e->getCode(), $e->transient() ) );
        $this->assertStringContainsString( 'Invalid API key', $e->detail() );
        // a remote server needs https
        $remote = new expAiTranslationProviderLibreTranslate( 'libretranslate', array( 'Endpoint' => 'http://mt.example.org/translate', 'Region' => 'eu' ), $config );
        $this->assertStringContainsString( 'https', $remote->unavailableReason() );
    }

    /** @return expAiTranslationException */
    private function translateFails( expAiTranslationProviderBase $provider, ?expAiTranslationRequest $request = null )
    {
        try
        {
            $provider->translate( $request ?: self::translationRequest( array( array( 'id' => 'u1', 'format' => 'text', 'kind' => 'text', 'text' => 'gut' ) ) ) );
        }
        catch ( expAiTranslationException $e )
        {
            return $e;
        }
        $this->fail( 'the translation did not fail' );
    }
}
