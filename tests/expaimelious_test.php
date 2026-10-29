<?php
/**
 * Unit test of Melious (Type=openai_chat, Flavor=melious) against answers recorded from the real API on 2026-10-12
 * (tests/fixtures/melious, ids scrubbed, no key): the shipped [Provider_melious] and [Model_melious.*] groups, the
 * prices in EUR converted with [Pricing] ExchangeRates[], the request (max_tokens, reasoning_effort, tools,
 * tool_choice, response_format, no streaming), the answer (text, a tool call with reasoning_content, usage with
 * reasoning and cached tokens, the cost Melious reports in billing_cost.credits), the assistant message sent back
 * on the next turn without the proxy's extra fields, the error shapes (unknown model, bad key, rate limit, no credit)
 * and the data policy (eu, zero retention). No database writes, no network.
 *
 * Run from the installation root:
 *   php bin/php/ezexec.php extension/expai/tests/expaimelious_test.php
 * It prints one line per check and ends with PASS or FAIL (exit code 0 or 1).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$failures = 0;
$checks = 0;
$check = function ( $label, $actual, $expected ) use ( &$failures, &$checks )
{
    ++$checks;
    $ok = is_float( $expected ) ? abs( (float) $actual - $expected ) < 1e-9 : $actual === $expected;
    if ( !$ok )
        ++$failures;
    echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $label . ( $ok ? '' : ' => ' . var_export( $actual, true ) . ' (expected ' . var_export( $expected, true ) . ')' ) . "\n";
};
$fixture = function ( $name )
{
    $data = json_decode( (string) file_get_contents( 'extension/expai/tests/fixtures/melious/' . $name ), true );
    return isset( $data['response'] ) ? $data['response'] : $data;
};
$thrown = function ( $callback )
{
    try
    {
        $callback();
    }
    catch ( expAiException $e )
    {
        return $e;
    }
    return null;
};

// ---------------------------------------------------------------- the shipped settings with melious switched on
// the ECB rates of the site are not read and no refresh is queued: the conversion uses [Pricing] ExchangeRates[] here
expAiExchangeRates::disableRefresh();
expAiExchangeRates::reset( false );
$ini = eZINI::fetchFromFile( 'extension/expai/settings/expai.ini.append.php' );
$check( 'shipped: melious is not active by default', in_array( 'melious', (array) $ini->variable( 'ProviderSettings', 'Providers' ), true ), false );
$check( 'shipped: [Provider_melious] has no key', (string) $ini->variable( 'Provider_melious', 'ApiKey' ), '' );
$ini->setVariable( 'ProviderSettings', 'Providers', array( 'anthropic', 'melious' ) );
$config = new expAiConfig( $ini );
$registry = $config->registry();
$aliases = array( 'melious.glm-5.3-flash', 'melious.glm-5.3', 'melious.deepseek-v4-flash-0731', 'melious.deepseek-v4.1-flash' );
$check( 'registry: the four partner models', array_values( array_filter( array_keys( $registry->models() ), function ( $a ) { return strpos( $a, 'melious.' ) === 0; } ) ), $aliases );
$melious = $registry->getProvider( 'melious' );
$check( 'provider: region eu', $melious['Region'], 'eu' );
$check( 'provider: retention zdr', $melious['Retention'], 'zdr' );
$check( 'provider: terms checked', $melious['TermsCheckedAt'], '2026-10-12' );
$check( 'provider: not ready without a key', $registry->providerReady( 'melious' ), false );
$described = $registry->describeProvider( 'melious' );
$check( 'provider: host', $described['host'], 'api.melious.ai' );
$check( 'provider: description has no key', array_key_exists( 'ApiKey', $described ), false );
foreach ( $aliases as $alias )
{
    $model = $registry->getModel( $alias );
    $check( "$alias: id is the alias without the provider", $model['id'], substr( $alias, 8 ) );
    $check( "$alias: native tools, writes only after the probe", $model['tool_use'] . '/' . ( $model['write_tools'] ? 'write' : 'read' ) . '/' . $model['write_gate'], 'native/write/probe' );
    $check( "$alias: context of one million tokens", $model['context_tokens'], 1000000 );
    $check( "$alias: prices in EUR", $model['price_currency'], 'EUR' );
}
// EUR prices x [Pricing] ExchangeRates[EUR]
$rate = (float) $ini->variable( 'Pricing', 'ExchangeRates' )['EUR'];
$check( 'rate: EUR from [Pricing] ExchangeRates', expAiCost::rate( 'EUR', $config ), $rate );
$check( 'rate: the site currency is 1', expAiCost::rate( 'usd', $config ), 1.0 );
$check( 'rate: no currency is 1', expAiCost::rate( '', $config ), 1.0 );
$check( 'rate: unknown currency', expAiCost::rate( 'GBP', $config ), null );
$flash = $registry->getModel( 'melious.glm-5.3-flash' );
$check( 'price: glm-5.3-flash input', $flash['prices']['input'], 0.10 * $rate );
$check( 'price: glm-5.3-flash output', $flash['prices']['output'], 0.40 * $rate );
$check( 'price: glm-5.3-flash cache read', $flash['prices']['cache_read'], 0.02 * $rate );
$check( 'price: glm-5.3 output', $registry->getModel( 'melious.glm-5.3' )['prices']['output'], 3.0 * $rate );
$check( 'price: deepseek-v4-flash-0731 output', $registry->getModel( 'melious.deepseek-v4-flash-0731' )['prices']['output'], 0.25 * $rate );
$check( 'price: deepseek-v4.1-flash input', $registry->getModel( 'melious.deepseek-v4.1-flash' )['prices']['input'], 0.20 * $rate );
// without a rate the prices are unknown, never taken as dollars
$noRate = eZINI::fetchFromFile( 'extension/expai/settings/expai.ini.append.php' );
$noRate->setVariable( 'ProviderSettings', 'Providers', array( 'anthropic', 'melious' ) );
$noRate->setVariable( 'Pricing', 'ExchangeRates', array() );
$noRateModel = ( new expAiConfig( $noRate ) )->registry()->getModel( 'melious.glm-5.3' );
$check( 'price: no rate, no price', $noRateModel['prices']['input'] === null && $noRateModel['prices']['output'] === null, true );
// a site that counts in EUR takes the prices as they are
$euro = eZINI::fetchFromFile( 'extension/expai/settings/expai.ini.append.php' );
$euro->setVariable( 'ProviderSettings', 'Providers', array( 'anthropic', 'melious' ) );
$euro->setVariable( 'Pricing', 'Currency', 'EUR' );
$check( 'price: a EUR site uses the EUR price', ( new expAiConfig( $euro ) )->registry()->getModel( 'melious.glm-5.3' )['prices']['output'], 3.0 );

// ---------------------------------------------------------------- the ECB reference rates (#392)
$ecbXml = '<?xml version="1.0" encoding="UTF-8"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">'
        . '<gesmes:subject>Reference rates</gesmes:subject><Cube><Cube time=\'2026-10-09\'><Cube currency=\'USD\' rate=\'1.1206\'/><Cube currency=\'GBP\' rate=\'0.8650\'/>'
        . '<Cube currency=\'JPY\' rate=\'170.12\'/></Cube></Cube></gesmes:Envelope>';
$parsedEcb = expAiExchangeRates::parse( $ecbXml );
$check( 'ecb: the date of the file', $parsedEcb['date'], '2026-10-09' );
$check( 'ecb: the rates against the euro', $parsedEcb['rates'], array( 'EUR' => 1.0, 'USD' => 1.1206, 'GBP' => 0.865, 'JPY' => 170.12 ) );
$check( 'ecb: no Cube, no rates', expAiExchangeRates::parse( '<html>maintenance</html>' ), null );
$check( 'ecb: EUR in USD', expAiExchangeRates::cross( $parsedEcb['rates'], 'EUR', 'USD' ), 1.1206 );
$check( 'ecb: GBP in USD (cross rate)', expAiExchangeRates::cross( $parsedEcb['rates'], 'GBP', 'USD' ), 1.1206 / 0.865 );
$check( 'ecb: an unknown currency', expAiExchangeRates::cross( $parsedEcb['rates'], 'XYZ', 'USD' ), null );
expAiExchangeRates::reset( array( 'date' => '2026-10-09', 'fetched' => time(), 'rates' => $parsedEcb['rates'] ) );
$check( 'ecb: used for the conversion (ExchangeRateSource=ecb)', expAiCost::rate( 'EUR', $config ), 1.1206 );
$check( 'ecb: the lookup names its source and date', expAiExchangeRates::lookup( 'EUR', 'USD', $config ), array( 'rate' => 1.1206, 'source' => 'ecb', 'date' => '2026-10-09' ) );
$check( 'ecb: the price of a model converted with it', ( new expAiConfig( $ini ) )->registry()->getModel( 'melious.glm-5.3' )['prices']['output'], 3.0 * 1.1206 );
$check( 'ecb: the rates in use for expai/models', expAiExchangeRates::inUse( $config ), array( array( 'currency' => 'EUR', 'site' => 'USD', 'rate' => 1.1206, 'source' => 'ecb', 'date' => '2026-10-09' ) ) );
$override = eZINI::fetchFromFile( 'extension/expai/settings/expai.ini.append.php' );
$override->setVariable( 'Pricing', 'ExchangeRateSource', 'settings' );
$check( 'ecb: ExchangeRateSource=settings overrides it', expAiCost::rate( 'EUR', new expAiConfig( $override ) ), $rate );
// a failed fetch keeps the last rates
class expAiMeliousTestFailingHttp extends expAiHttpClient
{
    public $url = '';
    public function request( $url, array $headers, $body, array $options = array() ) { $this->url = $url; return array( 503, 'busy', array() ); }
}
$failing = new expAiMeliousTestFailingHttp();
$refreshed = expAiExchangeRates::refresh( $failing );
$check( 'ecb: only the ECB daily file is fetched', $failing->url, 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml' );
$check( 'ecb: a failed fetch is reported', array( $refreshed['ok'], $refreshed['error'] ), array( false, 'HTTP 503' ) );
$check( 'ecb: a failed fetch keeps the last rates', expAiCost::rate( 'EUR', $config ), 1.1206 );
expAiExchangeRates::reset( false );
$check( 'ecb: without ECB rates the settings are the fallback', expAiCost::rate( 'EUR', $config ), $rate );

// ---------------------------------------------------------------- the request
$settings = $registry->settingsFor( 'melious.deepseek-v4-flash-0731' );
$settings['ApiKey'] = 'test-key';
$provider = new expAiOpenAiChatProvider( $settings );
$check( 'adapter: flavor melious', $provider->flavor(), 'melious' );
$check( 'adapter: endpoint', $provider->endpoint(), 'https://api.melious.ai/v1/chat/completions' );
$check( 'adapter: tool_choice supported', $provider->supportsToolChoice(), true );
$tools = array( array( 'name' => 'get_node', 'description' => 'Read a content node by its id.',
                       'input_schema' => array( 'type' => 'object', 'properties' => array( 'node_id' => array( 'type' => 'integer' ) ), 'required' => array( 'node_id' ) ) ) );
$user = array( array( 'role' => 'user', 'content' => 'Read node 2 with the tool.' ) );
$payload = $provider->buildPayload( array( 'system' => 'Be brief.', 'messages' => $user, 'tools' => $tools, 'tool_choice' => array( 'type' => 'any' ) ) );
$check( 'request: model id', $payload['model'], 'deepseek-v4-flash-0731' );
$check( 'request: max_tokens from MaxOutput', isset( $payload['max_tokens'] ) && !isset( $payload['max_completion_tokens'] ) ? $payload['max_tokens'] : null, 8000 );
$check( 'request: reasoning_effort low', isset( $payload['reasoning_effort'] ) ? $payload['reasoning_effort'] : null, 'low' );
$check( 'request: tools as functions', $payload['tools'][0]['type'] . '/' . $payload['tools'][0]['function']['name'], 'function/get_node' );
$check( 'request: any is required', $payload['tool_choice'], 'required' );
$check( 'request: no streaming', $payload['stream'], false );
$check( 'request: system first', $payload['messages'][0]['role'], 'system' );
$schema = array( 'type' => 'object', 'properties' => array( 'title' => array( 'type' => 'string' ) ) );
$payload = $provider->buildPayload( array( 'messages' => $user, 'output_schema' => $schema ) );
$check( 'request: deepseek-v4-flash-0731 json_schema', $payload['response_format']['type'], 'json_schema' );
$glmSettings = $registry->settingsFor( 'melious.glm-5.3' );
$glmSettings['ApiKey'] = 'test-key';
$glm = new expAiOpenAiChatProvider( $glmSettings );
$payload = $glm->buildPayload( array( 'messages' => $user, 'output_schema' => $schema ) );
$check( 'request: glm-5.3 json_object', $payload['response_format'], array( 'type' => 'json_object' ) );
$headers = new ReflectionMethod( 'expAiOpenAiChatProvider', 'keyHeaders' );
$headers->setAccessible( true );
$check( 'request: Bearer key header', $headers->invoke( $provider, 'authorization' ), array( 'Authorization: Bearer test-key' ) );

// ---------------------------------------------------------------- the answers (recorded)
$text = $glm->parseAnswer( $fixture( 'chat-text.json' ) );
$check( 'text: the answer', $text['content'], array( array( 'type' => 'text', 'text' => 'Paris' ) ) );
$check( 'text: stop end_turn', $text['stop_reason'], 'end_turn' );
$check( 'text: input tokens', $text['tokens']['input'], 22 );
$check( 'text: output tokens', $text['tokens']['output'], 3 );
$check( 'text: reasoning tokens', $text['tokens']['reasoning'], 1 );
$check( 'text: reported cost from billing_cost.credits', $text['tokens']['reported_cost'], 0.0000034 );
$check( 'text: reported in EUR', $text['tokens']['reported_currency'], 'EUR' );
$check( 'text: model from the answer', $text['model'], 'glm-5.3-flash' );
$check( 'text: provider', $text['provider'], 'melious' );

$tool = $provider->parseAnswer( $fixture( 'chat-tool-call.json' ) );
$uses = array_values( array_filter( $tool['content'], function ( $b ) { return $b['type'] === 'tool_use'; } ) );
$check( 'tool: one call', count( $uses ), 1 );
$check( 'tool: the call', array( $uses[0]['id'], $uses[0]['name'], $uses[0]['input'] ), array( 'call_t385fixture0', 'get_node', array( 'node_id' => 2 ) ) );
$check( 'tool: empty content is no text block', count( $tool['content'] ), 1 );
$check( 'tool: stop tool_use', $tool['stop_reason'], 'tool_use' );
$check( 'tool: tokens', array( $tool['tokens']['input'], $tool['tokens']['output'], $tool['tokens']['reasoning'] ), array( 367, 64, 19 ) );
$check( 'tool: reported cost', $tool['tokens']['reported_cost'], 0.0000527 );
// the reported cost equals the list price: 367 x 0.10 + 64 x 0.25 EUR per million
$check( 'tool: the reported cost is the list price', ( 367 * 0.10 + 64 * 0.25 ) / 1000000, 0.0000527 );
$check( 'tool: no invalid input', $tool['invalid_tool_input'], array() );

// cached tokens are cache reads, not input
$cached = $fixture( 'chat-text.json' );
$cached['usage']['prompt_tokens'] = 393;
$cached['usage']['prompt_tokens_details']['cached_tokens'] = 256;
$cached['usage']['cached_tokens'] = 256;
$parsed = $glm->parseAnswer( $cached );
$check( 'usage: cached tokens are cache reads', array( $parsed['tokens']['input'], $parsed['tokens']['cache_read'] ), array( 137, 256 ) );
$noCost = $fixture( 'chat-text.json' );
unset( $noCost['billing_cost'] );
$check( 'usage: no billing_cost, no reported cost', $glm->parseAnswer( $noCost )['tokens']['reported_cost'], null );

// ---------------------------------------------------------------- the next turn: the answer sent back
$history = array_merge( $user, array(
    array( 'role' => 'assistant', 'content_json' => $tool['content_json'], 'native_json' => $tool['native_json'], 'native_for' => $tool['native_for'], 'native_ids' => $tool['native_ids'] ),
    array( 'role' => 'user', 'content' => array( array( 'type' => 'tool_result', 'tool_use_id' => 'call_t385fixture0', 'content' => '{"name":"Home"}' ) ) ),
) );
$payload = $provider->buildPayload( array( 'messages' => $history, 'tools' => $tools ) );
$resent = $payload['messages'][1];
$check( 'resend: the native message goes back', $resent['tool_calls'][0]['id'], 'call_t385fixture0' );
$check( 'resend: reasoning_content kept (DeepSeek wants it on a tool turn)', isset( $resent['reasoning_content'] ), true );
$check( 'resend: no provider_specific_fields', array_key_exists( 'provider_specific_fields', $resent ), false );
$check( 'resend: no null function_call', array_key_exists( 'function_call', $resent ), false );
$check( 'resend: the tool result', $payload['messages'][2], array( 'role' => 'tool', 'tool_call_id' => 'call_t385fixture0', 'content' => '{"name":"Home"}' ) );
$textBack = $glm->buildPayload( array( 'messages' => array_merge( $user, array(
    array( 'role' => 'assistant', 'content_json' => $text['content_json'], 'native_json' => $text['native_json'], 'native_for' => $text['native_for'] ),
    array( 'role' => 'user', 'content' => 'Thanks' ) ) ) ) );
$check( 'resend: a text answer has no null tool_calls', array_key_exists( 'tool_calls', $textBack['messages'][1] ), false );
$check( 'resend: a text answer keeps its words', $textBack['messages'][1]['content'], 'Paris' );
// another model of the same provider gets the canonical blocks, not the native message
$other = $glm->buildPayload( array( 'messages' => $history, 'tools' => $tools ) );
$check( 'resend: another model gets canonical blocks', isset( $other['messages'][1]['reasoning_content'] ), false );

// ---------------------------------------------------------------- errors (recorded, and the shapes of the reference)
$error = new ReflectionMethod( 'expAiOpenAiChatProvider', 'httpError' );
$error->setAccessible( true );
$e = $error->invoke( $provider, 404, $fixture( 'error-unknown-model.json' ), array() );
$check( 'error: unknown model text', strtr( $e->getMessage(), $e->arguments() ), 'Melious does not know the configured model. Please tell the administrator.' );
$check( 'error: unknown model detail', strpos( $e->detail(), 'HTTP 404 invalid_request_error: Model not found' ) !== false, true );
$e = $error->invoke( $provider, 401, $fixture( 'error-bad-key.json' ), array() );
$check( 'error: bad key text', strtr( $e->getMessage(), $e->arguments() ), 'Melious refused the request (access). Please tell the administrator.' );
$check( 'error: bad key detail', strpos( $e->detail(), 'authentication_error: Invalid API key format' ) !== false, true );
$e = $error->invoke( $provider, 429, array( 'error' => array( 'code' => 'AUTH_1028', 'message' => 'Too many requests' ) ), array( 'retry-after' => '2', 'x-ratelimit-remaining' => '0' ) );
$check( 'error: rate limit text', strtr( $e->getMessage(), $e->arguments() ), 'Melious is busy or over its limit. Please try again later.' );
$check( 'error: rate limit code', strpos( $e->detail(), 'AUTH_1028' ) !== false, true );
$check( 'error: rate limit headers kept', $e->rateLimits, array( 'retry-after' => '2', 'x-ratelimit-remaining' => '0' ) );
$e = $error->invoke( $provider, 402, array( 'error' => array( 'code' => 'BILLING_2001', 'message' => 'Out of energy' ) ), array() );
$check( 'error: no credit text', strtr( $e->getMessage(), $e->arguments() ), 'Melious refused the request (no credit left). Please tell the administrator.' );
$e = $error->invoke( $provider, 400, array( 'error' => array( 'code' => 'INFERENCE_3207', 'message' => 'Context window exceeded for sk-mel-abcdefgh1234' ) ), array() );
$check( 'error: Melious code in the detail', strpos( $e->detail(), 'INFERENCE_3207' ) !== false, true );
$check( 'error: key-like text redacted', strpos( $e->detail(), 'sk-mel-abcdefgh1234' ), false );
$e = $thrown( function () use ( $provider ) { $provider->parseAnswer( array( 'error' => array( 'code' => 'INFERENCE_3208', 'message' => 'Content filter' ) ) ); } );
$check( 'error: an error object without choices is an error', $e ? $e->getCode() : null, 502 );
$rate = $provider->rateLimitHeaders();
$check( 'rate limit headers: x-ratelimit-* kept', in_array( 'x-ratelimit-*', $rate, true ), true );

// ---------------------------------------------------------------- streaming (recorded): expai asks for whole answers
$sse = (string) file_get_contents( 'extension/expai/tests/fixtures/melious/stream-with-usage.sse' );
$chunks = array();
foreach ( preg_split( '/\n\n/', trim( $sse ) ) as $event )
    if ( strpos( $event, 'data: ' ) === 0 && trim( substr( $event, 6 ) ) !== '[DONE]' )
        $chunks[] = json_decode( substr( $event, 6 ), true );
$last = end( $chunks );
$check( 'stream: Melious streams chunks', count( $chunks ) > 2 && $chunks[0]['object'] === 'chat.completion.chunk', true );
$check( 'stream: usage arrives in the last chunk (include_usage)', isset( $last['usage']['prompt_tokens'] ), true );
$check( 'stream: ends with [DONE]', substr( trim( $sse ), -12 ), 'data: [DONE]' );

// ---------------------------------------------------------------- streaming for the test prompt (#392)
$assembled = expAiOpenAiChatProvider::assembleStream( $sse );
$check( 'stream: assembled as one answer', isset( $assembled['choices'][0]['message'] ), true );
$check( 'stream: the reasoning deltas joined', strpos( $assembled['choices'][0]['message']['reasoning_content'], 'need answer user' ) !== false, true );
$check( 'stream: the finish reason', $assembled['choices'][0]['finish_reason'], 'length' );
$check( 'stream: the usage of the last chunk', $assembled['usage']['prompt_tokens'], 41 );
$toolStream = "data: " . json_encode( array( 'choices' => array( array( 'delta' => array( 'role' => 'assistant', 'content' => 'Let me ' ) ) ) ) ) . "\n\n"
            . "data: " . json_encode( array( 'choices' => array( array( 'delta' => array( 'content' => 'look.', 'tool_calls' => array( array( 'index' => 0, 'id' => 'call_s1', 'function' => array( 'name' => 'get_node', 'arguments' => '{"node_' ) ) ) ) ) ) ) ) . "\n\n"
            . "data: " . json_encode( array( 'choices' => array( array( 'delta' => array( 'tool_calls' => array( array( 'index' => 0, 'function' => array( 'arguments' => 'id": 2}' ) ) ) ), 'finish_reason' => 'tool_calls' ) ) ) ) . "\n\n"
            . "data: " . json_encode( array( 'choices' => array(), 'usage' => array( 'prompt_tokens' => 10, 'completion_tokens' => 5 ), 'billing_cost' => array( 'credits' => '0.000002' ) ) ) . "\n\ndata: [DONE]\n\n";
$parsed = $provider->parseAnswer( expAiOpenAiChatProvider::assembleStream( $toolStream ) );
$check( 'stream: text joined', $parsed['content'][0], array( 'type' => 'text', 'text' => 'Let me look.' ) );
$check( 'stream: the tool call with its arguments joined', array( $parsed['content'][1]['id'], $parsed['content'][1]['name'], $parsed['content'][1]['input'] ), array( 'call_s1', 'get_node', array( 'node_id' => 2 ) ) );
$check( 'stream: stop tool_use', $parsed['stop_reason'], 'tool_use' );
$check( 'stream: usage and reported cost of the last chunk', array( $parsed['tokens']['input'], $parsed['tokens']['output'], $parsed['tokens']['reported_cost'] ), array( 10, 5, 0.000002 ) );
$check( 'stream: an error event is an error', expAiOpenAiChatProvider::assembleStream( 'data: {"error":{"message":"x","code":"INFERENCE_3001"}}' ), array( 'error' => array( 'message' => 'x', 'code' => 'INFERENCE_3001' ) ) );
// the timed request through a recorded stream: stream_timing streams only on a server that streams
class expAiMeliousTestStreamHttp extends expAiHttpClient
{
    public $body;
    public $options = null;
    public $payload = null;
    public function __construct( $body ) { parent::__construct(); $this->body = $body; }
    public function request( $url, array $headers, $body, array $options = array() )
    {
        $this->options = $options;
        $this->payload = json_decode( $body, true );
        foreach ( str_split( $this->body, 200 ) as $piece )
        {
            if ( isset( $options['on_chunk'] ) )
                call_user_func( $options['on_chunk'], $piece );
            usleep( 2000 );
        }
        return array( 200, $this->body, array( 'x-ratelimit-remaining' => '599' ) );
    }
    public function postJson( $url, array $headers, $body )
    {
        $this->payload = json_decode( $body, true );
        return array( 200, json_encode( array( 'choices' => array( array( 'message' => array( 'content' => 'whole' ), 'finish_reason' => 'stop' ) ) ) ), array() );
    }
}
$http = new expAiMeliousTestStreamHttp( $toolStream );
$streaming = new expAiOpenAiChatProvider( $settings, $http );
$check( 'stream: Melious streams (Streaming=enabled)', $streaming->streams(), true );
$timed = $streaming->send( array( 'messages' => $user, 'tools' => $tools, 'stream_timing' => true ) );
$check( 'stream: the request asks for a stream with the usage', array( $http->payload['stream'], $http->payload['stream_options']['include_usage'], $http->options['accept'] ),
        array( true, true, 'text/event-stream' ) );
$check( 'stream: the first token is timed', is_int( $timed['timings']['first_token'] ) && $timed['timings']['first_token'] >= 0 && $timed['timings']['first_token'] <= $timed['timings']['total'], true );
$check( 'stream: the answer is the same', $timed['content'][0]['text'], 'Let me look.' );
$check( 'stream: the rate limit headers kept', $timed['rate_limits'], array( 'x-ratelimit-remaining' => '599' ) );
$noStream = new expAiOpenAiChatProvider( array( 'Streaming' => 'disabled' ) + $settings, $http2 = new expAiMeliousTestStreamHttp( $toolStream ) );
$whole = $noStream->send( array( 'messages' => $user, 'stream_timing' => true ) );
$check( 'stream: a server that does not stream answers in one piece', array( $whole['content'][0]['text'], $http2->payload['stream'], isset( $whole['timings'] ) ), array( 'whole', false, false ) );
$check( 'stream: stream_timing is not sent to the provider', array_key_exists( 'stream_timing', $http->payload ) || array_key_exists( 'stream_timing', $http2->payload ), false );

// ---------------------------------------------------------------- the data policy
$policy = new expAiDataPolicy( $config, $melious );
$check( 'policy: an eu requirement is met', $policy->meets( array( 'regions' => array( 'eu' ), 'zero_retention' => false ) ), true );
$check( 'policy: zero retention is met', $policy->meets( array( 'regions' => null, 'zero_retention' => true ) ), true );
$check( 'policy: a local-only requirement is not met', $policy->meets( array( 'regions' => array( 'local' ), 'zero_retention' => false ) ), false );

// ---------------------------------------------------------------- a chain run whose insert failed (found with the Melious chain)
// SQLite answered "database is locked" to the insert of a run; the run kept the id 0 and the next store inserted the row 0
$check( 'chain run: fetch(0) is no run', expAiChainRun::fetch( 0 ), null );
$broken = new expAiChainRun( array( 'id' => 0, 'uuid' => 't385-not-stored', 'chain' => 'melious', 'created' => time() ) );
$broken->store();
$check( 'chain run: a run with the id 0 is not stored', expAiChainRun::fetch( 't385-not-stored' ), null );

// ---------------------------------------------------------------- the probe: a lookup first is followed (follow_ok, #392)
// The Melious models read the class definition or the object before they write; the probe answers the lookup with a
// scripted result and grades the next call, at most [ProbeSettings] FollowRounds times.
class expAiMeliousTestScriptedProvider implements expAiProvider
{
    public $requests = array();
    protected $plan;
    public function __construct( $plan ) { $this->plan = $plan; }
    public function modelName() { return 'scripted'; }
    public function providerId() { return 'scripted'; }
    public function send( array $request )
    {
        $this->requests[] = $request;
        $last = end( $request['messages'] );
        $text = '';
        foreach ( isset( $last['content'] ) && is_array( $last['content'] ) ? $last['content'] : array() as $block )
            if ( isset( $block['type'] ) && $block['type'] === 'text' )
                $text = $block['text'];
        $round = 0;
        foreach ( $request['messages'] as $m )
            $round += $m['role'] === 'assistant' ? 1 : 0;
        $calls = call_user_func( $this->plan, $text !== '' ? $text : $this->firstText( $request ), $round );
        $content = array();
        foreach ( $calls as $i => $c )
            $content[] = is_string( $c ) ? array( 'type' => 'text', 'text' => $c ) : array( 'type' => 'tool_use', 'id' => 'call_' . $round . '_' . $i, 'name' => $c[0], 'input' => $c[1] );
        return array( 'content' => $content, 'content_json' => json_encode( $content ), 'stop_reason' => 'end_turn', 'usage' => array() );
    }
    protected function firstText( array $request )
    {
        foreach ( $request['messages'][0]['content'] as $block )
            if ( $block['type'] === 'text' )
                return $block['text'];
        return '';
    }
}
$probeConfig = new expAiConfig( eZINI::fetchFromFile( 'extension/expai/settings/expai.ini.append.php' ) );
$probe = new expAiProbe( $probeConfig );
// a careful model: reads first on the two write tasks, then writes; everything else as the task asks
$careful = function ( $text, $round )
{
    if ( strpos( $text, 'Create a new folder' ) === 0 )
        return $round === 0 ? array( array( 'get_class_definition', array( 'class_identifier' => 'folder' ) ) )
                            : array( array( 'create_draft', array( 'class_identifier' => 'folder', 'parent_node_id' => 2, 'fields' => array( 'name' => 'Probe page' ) ) ) );
    if ( strpos( $text, 'Change the field' ) === 0 )
        return $round === 0 ? array( array( 'get_node', array( 'object_id' => 1 ) ) )
                            : array( array( 'update_draft', array( 'object_id' => 1, 'fields' => array( 'name' => 'Probe title' ) ) ) );
    return array( 'ready' );
};
$result = $probe->run( new expAiMeliousTestScriptedProvider( $careful ), 2, 1, 'folder' );
$byId = array();
foreach ( $result['results'] as $row )
    $byId[$row['id']] = $row;
$check( 'probe follow: create_draft after a lookup passes', $byId['create_draft']['ok'], true );
$check( 'probe follow: one lookup answered', $byId['create_draft']['followed'], 1 );
$check( 'probe follow: update_draft after a lookup passes', $byId['update_draft']['ok'], true );
$check( 'probe follow: tasks without follow_ok are not followed', $byId['read_node']['followed'], 0 );
// the scripted results the model got
$provider = new expAiMeliousTestScriptedProvider( $careful );
$probe->run( $provider, 2, 1, 'folder' );
$answered = null;
foreach ( $provider->requests as $r )
    if ( count( $r['messages'] ) === 3 && strpos( json_encode( $r['messages'][0] ), 'Create a new folder' ) !== false )
        $answered = json_decode( $r['messages'][2]['content'][0]['content'], true );
$check( 'probe follow: the lookup is answered with the class definition', $answered ? $answered['class_identifier'] . '/' . $answered['fields'][0]['identifier'] : '', 'folder/name' );
// a model that only ever looks up: two rounds, then graded as before
$reader = function ( $text, $round ) { return strpos( $text, 'Create a new folder' ) === 0 ? array( array( 'list_creatable_classes', array( 'parent_node_id' => 2 ) ) ) : array( 'ready' ); };
$provider = new expAiMeliousTestScriptedProvider( $reader );
$result = $probe->run( $provider, 2, 1, 'folder' );
foreach ( $result['results'] as $row )
    if ( $row['id'] === 'create_draft' )
        $byId['reader'] = $row;
$check( 'probe follow: at most FollowRounds (2) answered lookups', $byId['reader']['followed'], 2 );
$check( 'probe follow: no write after the lookups fails', $byId['reader']['ok'] . '/' . $byId['reader']['reason'], '/looked up first (no create_draft call)' );
// FollowRounds=0 grades the first call (as before #392)
$noFollowIni = eZINI::fetchFromFile( 'extension/expai/settings/expai.ini.append.php' );
$noFollowIni->setVariable( 'ProbeSettings', 'FollowRounds', '0' );
$result = ( new expAiProbe( new expAiConfig( $noFollowIni ) ) )->run( new expAiMeliousTestScriptedProvider( $careful ), 2, 1, 'folder' );
foreach ( $result['results'] as $row )
    if ( $row['id'] === 'create_draft' )
        $byId['nofollow'] = $row;
$check( 'probe follow: FollowRounds=0 grades the first call', $byId['nofollow']['ok'] . '/' . $byId['nofollow']['followed'], '/0' );
// a write of another tool ends the follow; the injection task is not follow_ok
$check( 'probe follow: a write call is no lookup', expAiProbe::onlyLookups( array( array( 'name' => 'update_draft' ) ) ), false );
$check( 'probe follow: an MCP tool is no lookup', expAiProbe::onlyLookups( array( array( 'name' => 'exp__exp_content_get' ) ) ), false );
$check( 'probe follow: reads are lookups', expAiProbe::onlyLookups( array( array( 'name' => 'get_node' ), array( 'name' => 'get_class_definition' ) ) ), true );
$tasks = array();
foreach ( $probe->tasks( 2, 1, 'folder' ) as $t )
    $tasks[$t['id']] = isset( $t['follow_ok'] ) ? $t['follow_ok'] : '';
$check( 'probe follow: follow_ok on the two write tasks only', array_filter( $tasks ), array( 'create_draft' => 'create_draft', 'update_draft' => 'update_draft' ) );

// the fixtures carry no key or account data
$all = '';
foreach ( glob( 'extension/expai/tests/fixtures/melious/*' ) as $file )
    $all .= file_get_contents( $file );
$check( 'fixtures: no key, no Bearer value, no request id', (bool) preg_match( '/sk-mel-|Bearer\s|x-request-id/i', $all ), false );

echo ( $failures ? 'FAIL' : 'PASS' ) . " $checks checks, $failures failure(s)\n";
$script = eZScript::instance();
$script->shutdown( $failures ? 1 : 0 );
