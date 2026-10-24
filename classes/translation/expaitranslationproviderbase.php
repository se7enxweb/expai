<?php
/**
 * File containing the expAiTranslationProviderBase class.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The base of a machine translation service (kind mt): settings, key, endpoint, language codes, markup, batching,
 * the HTTP request with its retries, cost from the price per million characters. A service implements sendBatch()
 * (one request with a list of texts) and, where its answers differ, failure().
 *
 * Settings of [TranslationProvider_<id>]:
 *   Label, Class, Endpoint, ApiKey (settings/override or an untracked [ProviderSettings] KeyLocations[] location)
 *   Region (us | eu | local), Retention (none | zdr | 30d | unknown), TermsCheckedAt (YYYY-MM-DD)
 *   PricePerMChars (the price of a million characters; empty: the cost is unknown), PriceCurrency (default: [Pricing] Currency)
 *   LanguageMap[<eZ locale>]=<code>, Languages[] (the base codes the service translates; empty: the built-in rules)
 *   GlossaryId[<source>:<target>]=<the service's glossary id>
 *   Timeout (seconds, default 30), MaxTextsPerRequest (50), MaxBytesPerRequest (120000), MaxRetries (2),
 *   RetryAfterMax (seconds waited for a retry-after, default 10), SendContext (enabled: the neighbouring blocks go
 *   along as context, one block per request)
 *
 * What is sent: the text of the units and, with SendContext, the text of the blocks next to them. Never the other
 * fields, the name of the object or the current translation.
 */
abstract class expAiTranslationProviderBase implements expAiTranslationProvider
{
    /** @var string */
    protected $id;

    /** @var array */
    protected $settings;

    /** @var expAiConfig */
    protected $config;

    /** @var expAiHttpClient|null tests */
    public $http = null;

    public function __construct( $id, array $settings, expAiConfig $config )
    {
        $this->id = (string) $id;
        $this->settings = $settings;
        $this->config = $config;
    }

    /**
     * One request to the service.
     * @param string[] $texts the texts in the service's markup (or HTML)
     * @param string $format markup (coded text in markupStyle()) | html
     * @param string $context the text around the block ('' for none)
     * @param expAiTranslationRequest $request
     * @return array texts (string[], in the order of $texts), billed (int, or null when the service does not say),
     *               status (HTTP), retries, engine (the engine or model that translated)
     * @throws expAiTranslationException
     */
    abstract protected function sendBatch( array $texts, $format, $context, expAiTranslationRequest $request );

    /** @return string the default endpoint when the group has none */
    abstract protected function defaultEndpoint();

    public function id()
    {
        return $this->id;
    }

    public function label()
    {
        return $this->setting( 'Label', $this->id );
    }

    public function kind()
    {
        return 'mt';
    }

    /** @return string the setting, $default when missing or empty */
    public function setting( $name, $default = '' )
    {
        return isset( $this->settings[$name] ) && $this->settings[$name] !== '' && $this->settings[$name] !== null && !is_array( $this->settings[$name] )
             ? (string) $this->settings[$name] : (string) $default;
    }

    /** @return array an array setting (keys kept) */
    public function settingArray( $name )
    {
        return isset( $this->settings[$name] ) && is_array( $this->settings[$name] )
             ? array_filter( $this->settings[$name], function ( $v ) { return $v !== '' && $v !== null; } ) : array();
    }

    /** @return int a number setting within bounds */
    protected function number( $name, $default, $min, $max )
    {
        $value = $this->setting( $name, '' );
        return max( $min, min( $max, is_numeric( $value ) ? (int) $value : (int) $default ) );
    }

    /** @return bool a flag setting */
    protected function flag( $name, $default )
    {
        $value = strtolower( trim( $this->setting( $name, '' ) ) );
        return $value === '' ? (bool) $default : in_array( $value, array( 'enabled', 'true', '1', 'yes', 'on' ), true );
    }

    /** @return string the endpoint */
    public function endpoint()
    {
        return $this->setting( 'Endpoint', $this->defaultEndpoint() );
    }

    /** @return bool the service needs a key (ApiKeyRequired, default enabled) */
    protected function keyRequired()
    {
        return $this->flag( 'ApiKeyRequired', true );
    }

    public function isConfigured()
    {
        return $this->unavailableReason() === '';
    }

    public function unavailableReason()
    {
        $endpoint = $this->endpoint();
        if ( !preg_match( '~^https?://[^\s/?#@]+(?::\d+)?(?:[/?#]\S*)?$~i', $endpoint ) )
            return 'the endpoint is not an http or https URL without credentials ([TranslationProvider_' . $this->id . '] Endpoint)';
        if ( stripos( $endpoint, 'http://' ) === 0 && $this->region() !== 'local' )
            return 'a remote service needs an https endpoint; plain http is only for Region=local ([TranslationProvider_' . $this->id . '] Endpoint)';
        if ( $this->keyRequired() && trim( $this->setting( 'ApiKey' ) ) === '' )
            return 'no API key is set ([TranslationProvider_' . $this->id . '] ApiKey in settings/override)';
        return '';
    }

    public function region()
    {
        return expAiProviderRegistry::cleanRegion( $this->setting( 'Region', '' ) );
    }

    public function retention()
    {
        return expAiProviderRegistry::cleanRetention( $this->setting( 'Retention', '' ) );
    }

    public function termsCheckedAt()
    {
        return $this->setting( 'TermsCheckedAt', '' );
    }

    public function dataPolicy()
    {
        return new expAiDataPolicy( $this->config, array( 'ProviderId' => $this->id, 'Label' => $this->label(), 'Region' => $this->region(),
                                                          'Retention' => $this->retention(), 'TermsCheckedAt' => $this->termsCheckedAt() ) );
    }

    public function capabilities()
    {
        return array( 'patch_semantics' => false, 'markup' => 'placeholders', 'markup_reliable' => true, 'glossary' => true,
                      'context' => true, 'queue_request' => false );
    }

    /** @return string xml | html: how placeholders travel (expAiTranslationMarkup) */
    protected function markupStyle()
    {
        return expAiTranslationMarkup::STYLE_XML;
    }

    /** @return string the service's code of a source locale ('' when not translated) */
    public function sourceCode( $locale )
    {
        return expAiTranslationLocale::forProvider( $locale, $this->id, 'source', $this->settings );
    }

    /** @return string the service's code of a target locale ('' when not translated) */
    public function targetCode( $locale )
    {
        return expAiTranslationLocale::forProvider( $locale, $this->id, 'target', $this->settings );
    }

    public function supports( $source, $target, $purpose )
    {
        return (string) $source !== '' && (string) $source !== (string) $target && $this->sourceCode( $source ) !== '' && $this->targetCode( $target ) !== '';
    }

    /** @return string the service's glossary of a pair ('' when none: GlossaryId[<source>:<target>]) */
    public function glossaryId( $source, $target )
    {
        $ids = $this->settingArray( 'GlossaryId' );
        $key = $source . ':' . $target;
        return isset( $ids[$key] ) ? trim( (string) $ids[$key] ) : '';
    }

    /**
     * The cost of characters: PricePerMChars per million, in PriceCurrency, converted to [Pricing] Currency.
     * @return array( int micros, bool known )
     */
    public function cost( $chars )
    {
        $price = $this->setting( 'PricePerMChars', '' );
        if ( !is_numeric( $price ) )
            return array( 0, false );
        if ( (float) $price <= 0 )
            return array( 0, true );
        $rate = expAiCost::rate( $this->setting( 'PriceCurrency', '' ), $this->config );
        if ( $rate === null )
        {
            eZDebug::writeWarning( 'expai: no exchange rate for ' . $this->setting( 'PriceCurrency' ) . ' to price the translation service ' . $this->id, __METHOD__ );
            return array( 0, false );
        }
        return array( (int) round( max( 0, (int) $chars ) * (float) $price * $rate ), true );
    }

    public function estimate( expAiTranslationRequest $request )
    {
        $chars = $request->characters();
        list( $micros, $known ) = $this->cost( $chars );
        return array( 'micros' => $micros, 'chars' => $chars, 'known' => $known, 'label' => $this->label() );
    }

    /**
     * The pieces sent for the units: a keyword list is split (each keyword one piece), plain text is escaped, coded
     * text gets the service's markup, the terms of [TranslationSettings] DoNotTranslate[] are wrapped.
     * @return array list of { unit, sub, format (markup | html), kind, text, source (coded, for the check), context }
     */
    protected function pieces( expAiTranslationRequest $request )
    {
        $pieces = array();
        $style = $this->markupStyle();
        $context = $this->flag( 'SendContext', true );
        foreach ( $request->units as $unit )
        {
            $unit = expAiTranslationRequest::unit( $unit );
            $around = '';
            if ( $context && ( $unit['context_before'] !== '' || $unit['context_after'] !== '' ) )
                $around = trim( expAiText::cut( $unit['context_before'], 300 ) . "\n" . expAiText::cut( $unit['context_after'], 300 ) );
            if ( $unit['format'] === 'html' )
            {
                // the terms that are never translated, in the text of the HTML (translate="no", honoured in HTML mode)
                $pieces[] = array( 'unit' => $unit['id'], 'sub' => 0, 'format' => 'html', 'kind' => $unit['kind'],
                                   'text' => expAiTranslationMarkup::wrapTermsInHtml( $unit['text'], $request->doNotTranslate ), 'source' => '', 'context' => $around );
                continue;
            }
            $texts = $unit['kind'] === 'keywords'
                   ? array_values( array_filter( array_map( 'trim', explode( ',', $unit['text'] ) ), 'strlen' ) )
                   : array( $unit['text'] );
            foreach ( $texts as $sub => $text )
            {
                $coded = $unit['format'] === 'coded' ? $text : htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
                $pieces[] = array( 'unit' => $unit['id'], 'sub' => $sub, 'format' => 'markup', 'kind' => $unit['kind'], 'plain' => $unit['format'] !== 'coded',
                                   'text' => expAiTranslationMarkup::toMarkup( $coded, $style, $request->doNotTranslate ), 'source' => $coded, 'context' => $around );
            }
        }
        return $pieces;
    }

    /**
     * The pieces in requests: at most MaxTextsPerRequest texts and MaxBytesPerRequest bytes each, one format per
     * request, a piece with context alone (the context belongs to one block).
     * @return array list of list of piece indexes
     */
    protected function batches( array $pieces )
    {
        $maxTexts = $this->number( 'MaxTextsPerRequest', 50, 1, 1000 );
        $maxBytes = $this->number( 'MaxBytesPerRequest', 120000, 1000, 10000000 );
        $batches = array();
        $open = array();
        foreach ( $pieces as $i => $piece )
        {
            if ( $piece['context'] !== '' )
            {
                $batches[] = array( $i );
                continue;
            }
            $key = $piece['format'];
            if ( isset( $open[$key] ) )
            {
                $b = $open[$key];
                if ( count( $batches[$b]['items'] ) < $maxTexts && $batches[$b]['bytes'] + strlen( $piece['text'] ) <= $maxBytes )
                {
                    $batches[$b]['items'][] = $i;
                    $batches[$b]['bytes'] += strlen( $piece['text'] );
                    continue;
                }
            }
            $batches[] = array( 'items' => array( $i ), 'bytes' => strlen( $piece['text'] ) );
            $open[$key] = count( $batches ) - 1;
        }
        return array_map( function ( $b ) { return isset( $b['items'] ) ? $b['items'] : $b; }, $batches );
    }

    public function translate( expAiTranslationRequest $request )
    {
        if ( !$this->isConfigured() )
            throw new expAiTranslationException( 'The translation service %service is not configured.', $this->unavailableReason(), 0,
                                                 array( '%service' => $this->label() ), false );
        if ( !$this->supports( $request->source, $request->target, $request->purpose ) )
            throw new expAiTranslationException( 'The translation service %service does not translate %source into %target.', 'unsupported language pair', 0,
                                                 array( '%service' => $this->label(), '%source' => $request->source, '%target' => $request->target ), false );
        $start = microtime( true );
        $result = new expAiTranslationResult();
        $pieces = $this->pieces( $request );
        // what an earlier attempt translated before a later request failed: kept, not sent and paid again
        $key = self::piecesKey( $pieces );
        $translated = !empty( $request->resume['key'] ) && $request->resume['key'] === $key && isset( $request->resume['texts'] )
                    ? array_intersect_key( (array) $request->resume['texts'], $pieces ) : array();
        $billed = 0;
        $sent = 0;
        $reported = true;
        foreach ( $this->batches( $pieces ) as $batch )
        {
            if ( !array_diff( $batch, array_keys( $translated ) ) )
                continue;
            $texts = array();
            $chars = 0;
            foreach ( $batch as $i )
            {
                $texts[] = $pieces[$i]['text'];
                $chars += expAiTranslationRequest::textLength( array( 'text' => $pieces[$i]['source'] !== '' ? $pieces[$i]['source'] : $pieces[$i]['text'], 'format' => 'coded' ) );
            }
            try
            {
                $answer = $this->sendBatch( $texts, $pieces[$batch[0]]['format'], $pieces[$batch[0]]['context'], $request );
                if ( count( $answer['texts'] ) !== count( $texts ) )
                    throw new expAiTranslationException( 'The answer of the translation service %service could not be read.',
                                                         count( $answer['texts'] ) . ' texts for ' . count( $texts ), (int) $answer['status'], array( '%service' => $this->label() ), false );
            }
            catch ( expAiTranslationException $e )
            {
                // the requests before this one were translated and billed: the caller can keep them for a retry
                if ( $billed + $sent > 0 || $translated )
                {
                    $partialBilled = $reported ? $billed : $sent;
                    list( $partialCost, ) = $this->cost( $partialBilled );
                    $e->partial = array( 'key' => $key, 'texts' => $translated, 'billed' => $partialBilled, 'cost_micros' => $partialCost );
                }
                throw $e;
            }
            foreach ( array_values( $batch ) as $n => $i )
                $translated[$i] = (string) $answer['texts'][$n];
            if ( $answer['billed'] === null )
                $reported = false;
            else
                $billed += (int) $answer['billed'];
            $sent += $chars;
            $result->retries += (int) $answer['retries'];
            $result->httpStatus = (int) $answer['status'];
            if ( !empty( $answer['engine'] ) )
                $result->engine = (string) $answer['engine'];
        }
        // back into the format of each unit, checked; keywords joined again
        $parts = array();
        foreach ( $pieces as $i => $piece )
        {
            if ( isset( $result->errors[$piece['unit']] ) )
                continue;
            if ( $piece['format'] === 'html' )
            {
                $parts[$piece['unit']][$piece['sub']] = expAiTranslationMarkup::unwrapTermsInHtml( expAiTranslationMarkup::stripControls( $translated[$i] ) );
                continue;
            }
            try
            {
                $coded = expAiTranslationMarkup::fromMarkup( $translated[$i], $piece['source'], $this->markupStyle() );
            }
            catch ( expAiTranslationException $e )
            {
                $result->errors[$piece['unit']] = $e->detail();
                unset( $parts[$piece['unit']] );
                continue;
            }
            $parts[$piece['unit']][$piece['sub']] = !empty( $piece['plain'] ) ? html_entity_decode( $coded, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $coded;
        }
        foreach ( $request->units as $unit )
        {
            $id = (string) $unit['id'];
            if ( isset( $result->errors[$id] ) )
                continue;
            $list = isset( $parts[$id] ) ? $parts[$id] : array( '' );
            ksort( $list );
            $result->units[$id] = isset( $unit['kind'] ) && $unit['kind'] === 'keywords' ? implode( ', ', $list ) : implode( '', $list );
        }
        $result->engine = $result->engine !== '' ? $result->engine : $this->id;
        // only what this attempt sent (a resumed attempt does not bill the requests of the earlier one again)
        $result->billedChars = $reported ? $billed : $sent;
        list( $result->costMicros, $result->costKnown ) = $this->cost( $result->billedChars );
        $result->latencyMs = (int) round( ( microtime( true ) - $start ) * 1000 );
        return $result;
    }

    /** @return string a key of the pieces of a request (a resumed attempt must send the same ones) */
    protected static function piecesKey( array $pieces )
    {
        return sha1( json_encode( array_map( function ( $p ) { return array( $p['unit'], $p['sub'], $p['format'], $p['text'], $p['context'] ); }, $pieces ),
                                  JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) );
    }

    /** @return expAiHttpClient */
    protected function http()
    {
        if ( !$this->http )
            $this->http = new expAiHttpClient( $this->number( 'Timeout', 30, 1, 600 ), 10, $this->number( 'MaxResponseBytes', 4194304, 1024, 67108864 ),
                                               array( 'retry-after' ) );
        return $this->http;
    }

    /**
     * POST with retries: a busy service (429) and server errors (5xx) are tried again up to MaxRetries times, after
     * the retry-after it names (at most RetryAfterMax seconds) or 1, 2, 4 ... seconds.
     * @param array $headers list of "Name: value"
     * @param string $body
     * @param array $options of expAiHttpClient::request()
     * @return array( int status, string body, array kept headers, int retries )
     * @throws expAiTranslationException when the service cannot be reached
     */
    protected function post( $url, array $headers, $body, array $options = array() )
    {
        $http = $this->http();
        $retries = 0;
        $max = $this->number( 'MaxRetries', 2, 0, 5 );
        $cap = $this->number( 'RetryAfterMax', 10, 0, 120 );
        while ( true )
        {
            try
            {
                list( $status, $answer, $kept ) = $http->request( $url, $headers, $body, $options );
            }
            catch ( expAiException $e )
            {
                throw new expAiTranslationException( 'The translation service %service could not be reached.', $e->detail(), 0, array( '%service' => $this->label() ), true );
            }
            if ( ( $status === 429 || $status >= 500 ) && $retries < $max )
            {
                $after = isset( $kept['retry-after'] ) && is_numeric( $kept['retry-after'] ) ? (float) $kept['retry-after'] : pow( 2, $retries );
                // a wait longer than the cap is left to the queue (it retries the job later)
                if ( $after > $cap )
                    return array( $status, $answer, $kept, $retries );
                $http->pause( $after );
                ++$retries;
                continue;
            }
            return array( $status, $answer, $kept, $retries );
        }
    }

    /**
     * The exception of an answer that is not a success.
     * @param array $headers the kept headers (retry-after)
     * @return expAiTranslationException
     */
    protected function failure( $status, $body, array $headers = array() )
    {
        $status = (int) $status;
        $service = array( '%service' => $this->label() );
        $detail = $this->id . ': HTTP ' . $status . self::errorText( $body );
        if ( $status === 401 || $status === 403 )
            $e = new expAiTranslationException( 'The translation service %service refused the key.', $detail, $status, $service, false );
        elseif ( $status === 429 )
            $e = new expAiTranslationException( 'The translation service %service is busy. Please try again later.', $detail, $status, $service, true );
        elseif ( $status >= 500 || $status === 408 )
            $e = new expAiTranslationException( 'The translation service %service is not available (HTTP %status).', $detail, $status, $service + array( '%status' => $status ), true );
        else
            $e = new expAiTranslationException( 'The translation service %service refused the request (HTTP %status).', $detail, $status, $service + array( '%status' => $status ), false );
        if ( isset( $headers['retry-after'] ) && is_numeric( $headers['retry-after'] ) )
            $e->retryAfter = (int) ceil( (float) $headers['retry-after'] );
        return $e;
    }

    /** @return string the service's error message of an answer (": text", cut), never the request */
    protected static function errorText( $body )
    {
        $data = json_decode( (string) $body, true );
        $message = '';
        if ( is_array( $data ) )
            foreach ( array( 'message', 'error', 'detail' ) as $name )
                if ( isset( $data[$name] ) && is_string( $data[$name] ) )
                {
                    $message = $data[$name];
                    break;
                }
        return $message !== '' ? ': ' . expAiText::cut( expAiText::oneLine( $message ), 160 ) : '';
    }
}
