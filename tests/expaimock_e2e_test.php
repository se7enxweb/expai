<?php
/**
 * End-to-end test of the providers against the mock servers of tests/mock/server.php: the panel's full tool loop
 * (expAiAssistant::step()) on Hugging Face (router) and OpenRouter shapes, with read tools, parallel calls, a draft
 * written in the test folder, the usage rows (provider, alias, region, tokens, reasoning, reported or alias cost),
 * a change of model in one conversation, malformed arguments, the prompted tool protocol, the write gate, the data
 * policy, 401/402/429/5xx/timeouts, an MCP server (JSON and event stream, allowlist, injection after which writing
 * is refused) and a field action. The provider settings exist only in memory for this run; no key is used.
 *
 *   EXPAI_MOCK_URL=http://127.0.0.1:18358   the mock server (php -S 127.0.0.1:18358 extension/expai/tests/mock/server.php)
 *   EXPAI_TEST_NODE=<node id>               a folder for the test; drafts are written below it and removed again
 *   EXPAI_TEST_LOGIN=<login>                the user the test runs as (needs expai/write and content/create there)
 *   php bin/php/ezexec.php extension/expai/tests/expaimock_e2e_test.php
 *
 * Prints one line per check and ends with PASS or FAIL (exit code 0 or 1).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$mock = rtrim( (string) getenv( 'EXPAI_MOCK_URL' ), '/' );
$nodeID = (int) getenv( 'EXPAI_TEST_NODE' );
$login = (string) getenv( 'EXPAI_TEST_LOGIN' );
$failures = 0;
$checks = 0;
$check = function ( $label, $actual, $expected ) use ( &$failures, &$checks )
{
    ++$checks;
    $ok = $actual === $expected;
    if ( !$ok )
        ++$failures;
    echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $label . ( $ok ? '' : ' => ' . var_export( $actual, true ) . ' (expected ' . var_export( $expected, true ) . ')' ) . "\n";
};
$script = eZScript::instance();
$node = eZContentObjectTreeNode::fetch( $nodeID );
$user = $login !== '' ? eZUser::fetchByName( $login ) : null;
if ( $mock === '' || !$node || !$user )
{
    echo "FAIL set EXPAI_MOCK_URL, EXPAI_TEST_NODE and EXPAI_TEST_LOGIN\n";
    $script->shutdown( 1 );
}
eZUser::setCurrentlyLoggedInUser( $user, (int) $user->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
$userID = (int) $user->attribute( 'contentobject_id' );
$started = time();
$run = 'r' . bin2hex( random_bytes( 4 ) );

// ------------------------------------------------------------------ the providers of this run, in memory only
$ini = eZINI::instance( 'expai.ini' );
$set = function ( $group, array $values ) use ( $ini ) { foreach ( $values as $name => $value ) $ini->setVariable( $group, $name, $value ); };
$set( 'AISettings', array( 'Enabled' => 'true', 'AllowUserModelChoice' => 'disabled' ) );
$set( 'ProviderSettings', array( 'Providers' => array( 'anthropic', 'hfmock', 'ormock', 'hfbad' ), 'DefaultModel' => 'hfmock.e2e' ) );
$set( 'Limits', array( 'MaxRequestsPerTurn' => '' ) );
$set( 'Provider_hfmock', array( 'Type' => 'openai_chat', 'Flavor' => 'huggingface', 'Label' => 'Hugging Face (mock)', 'BaseURL' => $mock . '/hf/v1',
    'ApiKey' => 'hf_mock_not_a_key', 'Region' => 'us', 'Retention' => 'unknown', 'Timeout' => 3, 'RetryAfterMax' => 2, 'Headers' => array( 'X-Expai-Test-Run: ' . $run ) ) );
$set( 'Provider_ormock', array( 'Type' => 'openai_chat', 'Flavor' => 'openrouter', 'Label' => 'OpenRouter (mock)', 'BaseURL' => $mock . '/openrouter/api/v1',
    'ApiKey' => 'sk-or-mock-not-a-key', 'Region' => 'us', 'Retention' => 'zdr', 'Timeout' => 10, 'RetryAfterMax' => 2,
    'Headers' => array( 'X-Expai-Test-Run: ' . $run, 'X-OpenRouter-Title: Exponential Intelligence' ), 'ExtraBody' => '{"provider":{"data_collection":"deny","zdr":true}}' ) );
$set( 'Provider_hfbad', array( 'Type' => 'openai_chat', 'Flavor' => 'huggingface', 'Label' => 'Hugging Face (bad key)', 'BaseURL' => $mock . '/hf/v1', 'ApiKey' => 'invalid', 'Region' => 'us' ) );
$model = function ( $alias, $provider, $id, array $extra = array() ) use ( $set )
{
    $set( 'Model_' . $alias, $extra + array( 'Provider' => $provider, 'Id' => $id, 'Label' => $alias, 'ToolUse' => 'native', 'WriteTools' => 'enabled', 'MaxOutput' => 800 ) );
};
$model( 'hfmock.e2e', 'hfmock', 'mock/e2e:novita', array( 'WriteGate' => 'trusted', 'InputPerMTok' => '0.15', 'OutputPerMTok' => '0.60' ) );
$model( 'hfmock.gated', 'hfmock', 'mock/e2e:fastest' );
$model( 'hfmock.malformed', 'hfmock', 'mock/malformed:cheapest', array( 'WriteGate' => 'trusted' ) );
$model( 'hfmock.prompted', 'hfmock', 'mock/promptedjson', array( 'ToolUse' => 'prompted' ) );
$model( 'hfmock.ratelimitlong', 'hfmock', 'mock/ratelimitlong' );
$model( 'hfmock.slow', 'hfmock', 'mock/slow' );
$model( 'hfmock.down', 'hfmock', 'mock/down' );
$model( 'hfbad.e2e', 'hfbad', 'mock/e2e' );
$model( 'ormock.e2e', 'ormock', 'mock/e2e', array( 'WriteGate' => 'trusted', 'Capabilities' => array( 'reasoning_effort', 'json_schema' ), 'Effort' => 'low' ) );
$model( 'ormock.ratelimit', 'ormock', 'mock/ratelimit' );
$model( 'ormock.flaky', 'ormock', 'mock/flaky' );
$model( 'ormock.credits', 'ormock', 'mock/credits' );
$model( 'ormock.error200', 'ormock', 'mock/error200' );
$set( 'McpBridge', array( 'Enabled' => 'disabled' ) );
$set( 'McpSettings', array( 'Servers' => array() ) );
$config = expAiConfig::instance();
$registry = $config->registry();
$check( 'registry: mock providers active', array_keys( $registry->providers() ), array( 'anthropic', 'hfmock', 'ormock', 'hfbad' ) );
$check( 'registry: default is the mock', $config->model(), 'hfmock.e2e' );

$usageRows = function ( $conversationID )
{
    return eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), null, array( 'conversation_id' => (int) $conversationID ), array( 'id' => 'asc' ) );
};
$loop = function ( expAiAssistant $assistant, $message, $max = 8 ) use ( $nodeID )
{
    $results = array( $assistant->step( $nodeID, $message ) );
    while ( end( $results )['status'] === 'continue' && count( $results ) < $max )
        $results[] = $assistant->step( $nodeID, null );
    return $results;
};
$stepTexts = function ( array $results ) { $out = array(); foreach ( $results as $r ) foreach ( $r['steps'] as $s ) $out[] = $s['text']; return $out; };

expAiConversation::removeFor( $userID, $nodeID );

// ------------------------------------------------------------------ 1. Hugging Face: the full loop with a draft
foreach ( array( 'hfmock.e2e' => 'hf', 'ormock.e2e' => 'openrouter' ) as $alias => $label )
{
    expAiConversation::removeFor( $userID, $nodeID );
    $assistant = new expAiAssistant( $config, $registry->providerFor( $alias ) );
    $results = $loop( $assistant, 'Write a folder about our spring events.' );
    $last = end( $results );
    $check( "$alias: loop of 3 steps ends in words", array( count( $results ), $last['status'] ), array( 3, 'done' ) );
    $check( "$alias: first step ran two lookups in parallel", count( $results[0]['steps'] ), 2 );
    $check( "$alias: the draft was written", isset( $results[1]['steps'][0]['writes'] ) && $results[1]['steps'][0]['writes'] && $results[1]['steps'][0]['ok'], true );
    $check( "$alias: the answer names the draft", strpos( $last['text'], 'T358 mock draft (' . $label . ')' ) !== false, true );
    $drafts = array_values( array_filter( $last['drafts'], function ( $d ) use ( $label ) { return $d['name'] === 'T358 mock draft (' . $label . ')'; } ) );
    $check( "$alias: the panel lists the draft", count( $drafts ), 1 );
    $conversation = expAiConversation::fetchCurrent( $userID, $nodeID );
    $check( "$alias: conversation bound to the model", array( $conversation->boundAlias(), $conversation->attribute( 'provider' ) ), array( $alias, $registry->getModel( $alias )['provider'] ) );
    $rows = $usageRows( $conversation->attribute( 'id' ) );
    $check( "$alias: three usage rows", count( $rows ), 3 );
    $first = $rows[0];
    $check( "$alias: row provider, alias, region, tool mode", array( $first->attribute( 'provider' ), $first->attribute( 'model_alias' ), $first->attribute( 'region' ), $first->attribute( 'tool_mode' ) ),
            array( $registry->getModel( $alias )['provider'], $alias, 'us', 'native' ) );
    $check( "$alias: tokens recorded", $first->attribute( 'input_tokens' ) > 0 && $first->attribute( 'output_tokens' ) > 0, true );
    $check( "$alias: draft counted on the second row", (int) $rows[1]->attribute( 'drafts_written' ), 1 );
    $check( "$alias: tool calls logged", count( $first->tools() ), 2 );
    if ( $label === 'hf' )
    {
        $check( 'hf: model id without the routing suffix (mock assumption)', $first->attribute( 'model' ), 'mock/e2e' );
        $check( 'hf: no reported cost', (int) $first->attribute( 'reported_cost_micros' ), 0 );
        $check( 'hf: cost from the alias prices', $first->costSource(), 'alias' );
        $expected = ( (int) $first->attribute( 'input_tokens' ) * 0.15 + (int) $first->attribute( 'output_tokens' ) * 0.60 ) / 1000000;
        $check( 'hf: alias cost amount', abs( $first->cost() - $expected ) < 1e-12, true );
    }
    else
    {
        $check( 'openrouter: reported cost stored', (int) $first->attribute( 'reported_cost_micros' ) > 0, true );
        $check( 'openrouter: cost source reported', $first->costSource(), 'reported' );
        $check( 'openrouter: cost = reported', abs( $first->cost() - (int) $first->attribute( 'reported_cost_micros' ) / 1000000 ) < 1e-12, true );
        $check( 'openrouter: reasoning tokens', (int) $first->attribute( 'reasoning_tokens' ), 9 );
        $check( 'openrouter: retention of the provider', $first->attribute( 'retention' ), 'zdr' );
        $check( 'openrouter: cached tokens on a later request', (int) $rows[2]->attribute( 'cache_read_tokens' ) > 0, true );
    }
    $log = expAiDraftLog::fetchForUserNode( $userID, $nodeID, 5 );
    $check( "$alias: draft log row names the provider", $log ? $log[0]->attribute( 'provider' ) : '', $registry->getModel( $alias )['provider'] );
}
// what the mock received: headers and the routing object
$requests = array();
$state = getenv( 'EXPAI_MOCK_STATE' );
foreach ( $state && is_file( $state . '/requests.jsonl' ) ? file( $state . '/requests.jsonl' ) : array() as $line )
{
    $entry = json_decode( $line, true );
    if ( isset( $entry['headers']['x-expai-test-run'] ) && $entry['headers']['x-expai-test-run'] === $run )
        $requests[] = $entry;
}
$or = array_values( array_filter( $requests, function ( $r ) { return $r['endpoint'] === 'openrouter'; } ) );
$hf = array_values( array_filter( $requests, function ( $r ) { return $r['endpoint'] === 'hf'; } ) );
$check( 'requests: OpenRouter got the routing object', $or ? $or[0]['body']['provider'] : null, array( 'data_collection' => 'deny', 'zdr' => true ) );
$check( 'requests: OpenRouter got the title header', $or ? $or[0]['headers']['x-openrouter-title'] : null, 'Exponential Intelligence' );
$check( 'requests: OpenRouter got the reasoning effort', $or ? $or[0]['body']['reasoning'] : null, array( 'effort' => 'low' ) );
$check( 'requests: Hugging Face got the provider suffix', $hf ? $hf[0]['body']['model'] : null, 'mock/e2e:novita' );
$check( 'requests: bearer authentication', $hf ? $hf[0]['auth_scheme'] : null, 'Bearer' );
$check( 'requests: the key is not in the logged headers', $hf ? strpos( json_encode( $hf[0] ), 'hf_mock_not_a_key' ) : null, false );

// ------------------------------------------------------------------ 2. a change of model in one conversation
expAiConversation::removeFor( $userID, $nodeID );
$loop( new expAiAssistant( $config, $registry->providerFor( 'hfmock.gated' ) ), 'Please look up this folder.' );
$results = $loop( new expAiAssistant( $config, $registry->providerFor( 'ormock.e2e' ) ), 'Now write the folder.' );
$conversation = expAiConversation::fetchCurrent( $userID, $nodeID );
$messages = $conversation->messages();
$check( 'switch: bound to the new model', $conversation->boundAlias(), 'ormock.e2e' );
$check( 'switch: the window starts from the compacted words', strpos( json_encode( $messages[0] ), 'Please look up this folder.' ) !== false, true );
$check( 'switch: no native state of the old provider is kept', strpos( json_encode( $messages ), '"native_for":"hfmock/' ), false );
$check( 'switch: the new model finished', end( $results )['status'], 'done' );
$check( 'switch: history keeps both exchanges', count( $conversation->history() ), 2 );

// ------------------------------------------------------------------ 3. the write gate
expAiConversation::removeFor( $userID, $nodeID );
$results = $loop( new expAiAssistant( $config, $registry->providerFor( 'hfmock.gated' ) ), 'Write a folder about our spring events.' );
$conversation = expAiConversation::fetchCurrent( $userID, $nodeID );
$frame = $conversation->frame();
$names = array_map( function ( $t ) { return $t->name; }, (array) $frame['tools'] );
$check( 'gate: a model without a passed probe gets no write tools', array( in_array( 'create_draft', $names, true ), in_array( 'update_draft', $names, true ) ), array( false, false ) );
$check( 'gate: its instructions say it may only read', strpos( $frame['system'], 'you can only read' ) !== false, true );
$check( 'gate: the loop ends in words', end( $results )['text'], 'I looked it up: the content is read. I can only read here.' );
$check( 'gate: writeAllowed false for the gated model', expAiProbe::writeAllowed( 'hfmock.gated', $config ), false );
$check( 'gate: the reason names the probe', strpos( expAiProbe::writeRefusal( 'hfmock.gated', $config ), 'has not passed the probe' ) !== false, true );
// a model naming a write tool anyway is refused at run time
class expAiE2eForgedProvider implements expAiProvider
{
    public function send( array $request )
    {
        $blocks = array( array( 'type' => 'tool_use', 'id' => 'forged1', 'name' => 'create_draft', 'input' => array( 'class_identifier' => 'folder', 'fields' => array( 'name' => 'forged' ) ) ) );
        $last = end( $request['messages'] );
        if ( isset( $last['content'][0]['type'] ) && $last['content'][0]['type'] === 'tool_result' )
            $blocks = array( array( 'type' => 'text', 'text' => 'ok' ) );
        return array( 'content' => $blocks, 'content_json' => json_encode( $blocks ), 'stop_reason' => $blocks[0]['type'] === 'tool_use' ? 'tool_use' : 'end_turn',
                      'model' => 'forged', 'tokens' => array( 'input' => 1, 'output' => 1, 'cache_write' => 0, 'cache_read' => 0 ) );
    }
    public function modelName() { return 'forged'; }
    public function providerId() { return 'hfmock'; }
    public function modelAlias() { return 'hfmock.gated'; }
}
expAiConversation::removeFor( $userID, $nodeID );
$results = $loop( new expAiAssistant( $config, new expAiE2eForgedProvider() ), 'Write something.' );
$check( 'gate: a forged create_draft is refused', $results[0]['steps'][0]['ok'], false );
$check( 'gate: refusal text', strpos( $results[0]['steps'][0]['text'], 'may not write drafts' ) !== false, true );
$check( 'gate: nothing named forged was written', count( array_filter( expAiAssistant::drafts( $user, $nodeID ), function ( $d ) { return $d['name'] === 'forged'; } ) ), 0 );

// ------------------------------------------------------------------ 4. malformed arguments and the prompted protocol
expAiConversation::removeFor( $userID, $nodeID );
$results = $loop( new expAiAssistant( $config, $registry->providerFor( 'hfmock.malformed' ) ), 'Read this node.' );
$texts = $stepTexts( $results );
$check( 'malformed: the bad call is answered with an error', strpos( $texts[0], 'unreadable arguments' ) !== false, true );
$check( 'malformed: the model retried with valid arguments', count( $texts ) >= 2 && $results[1]['steps'][0]['ok'], true );
$check( 'malformed: ends in words', end( $results )['text'], 'Node ' . $nodeID . ' is read.' );
expAiConversation::removeFor( $userID, $nodeID );
$results = $loop( new expAiAssistant( $config, $registry->providerFor( 'hfmock.prompted' ) ), 'Read this node.' );
$check( 'prompted: a tool call from JSON text', $results[0]['status'] === 'continue' && $results[0]['steps'][0]['ok'], true );
$check( 'prompted: the fenced JSON answer', end( $results )['text'], 'Node ' . $nodeID . ' is read (prompted).' );
$conversation = expAiConversation::fetchCurrent( $userID, $nodeID );
$rows = $usageRows( $conversation->attribute( 'id' ) );
$check( 'prompted: tool mode logged', $rows[0]->attribute( 'tool_mode' ), 'prompted' );
$check( 'prompted: canonical history keeps tool_use', strpos( json_encode( $conversation->messages() ), 'tool_use' ) !== false, true );
$check( 'prompted: no write tools in its frame', strpos( json_encode( $conversation->frame()['tools'] ), 'create_draft' ), false );

// ------------------------------------------------------------------ 5. errors, retries and timeouts
$send = function ( $alias ) use ( $registry )
{
    $recorder = new expAiUsageRecorder( $registry->providerFor( $alias ), array( 'action' => 'test' ) );
    $start = microtime( true );
    try
    {
        $answer = $recorder->send( array( 'system' => 'Test.', 'messages' => array( array( 'role' => 'user', 'content' => 'Hello' ) ) ) );
        return array( 'ok', isset( $answer['retries'] ) ? (int) $answer['retries'] : 0, microtime( true ) - $start, $recorder->last );
    }
    catch ( expAiException $e )
    {
        return array( (int) $e->getCode(), strtr( $e->getMessage(), $e->arguments() ), microtime( true ) - $start, $recorder->last );
    }
};
$r = $send( 'ormock.ratelimit' );
$check( '429 with retry-after 1: retried once and answered', array( $r[0], $r[1] ), array( 'ok', 1 ) );
$check( '429 retry: the row records the retry', (int) $r[3]->attribute( 'retries' ), 1 );
$r = $send( 'hfmock.ratelimitlong' );
$check( '429 with retry-after 60: no waiting, an error for the editor', array( $r[0], $r[1] ), array( 429, 'Hugging Face (mock) is busy or over its limit. Please try again later.' ) );
$check( '429 long: answered at once', $r[2] < 3, true );
$check( '429 long: rate limit headers logged', strpos( (string) $r[3]->attribute( 'rate_limits' ), 'retry-after' ) !== false, true );
$r = $send( 'ormock.flaky' );
$check( '500 once: retried and answered', array( $r[0], $r[1] ), array( 'ok', 1 ) );
$r = $send( 'hfmock.down' );
$check( '503: error after the one retry it allows', $r[0], 503 );
$r = $send( 'hfmock.slow' );
$check( 'timeout: the editor is told', $r[1], 'Exponential Intelligence did not answer in time. Please try again with a smaller request.' );
$check( 'timeout: within the provider timeout', $r[2] < 5, true );
$r = $send( 'hfbad.e2e' );
$check( '401: access text naming the provider', array( $r[0], $r[1] ), array( 401, 'Hugging Face (bad key) refused the request (access). Please tell the administrator.' ) );
$check( '401: the HF error text in the log row', strpos( (string) $r[3]->attribute( 'error_message' ), 'Invalid username or password.' ) !== false, true );
$r = $send( 'ormock.credits' );
$check( '402: no credit', array( $r[0], $r[1] ), array( 402, 'OpenRouter (mock) refused the request (no credit left). Please tell the administrator.' ) );
$r = $send( 'ormock.error200' );
$check( 'error inside HTTP 200: reported as the error', $r[0], 502 );

// ------------------------------------------------------------------ 6. the data policy
$set( 'DataPolicy', array( 'ClassRegions' => array( 'folder' => 'eu;local' ) ) );
expAiConversation::removeFor( $userID, $nodeID );
try
{
    ( new expAiAssistant( expAiConfig::instance(), $registry->providerFor( 'hfmock.e2e' ) ) )->step( $nodeID, 'Write a folder.' );
    $policyError = '';
}
catch ( expAiException $e )
{
    $policyError = strtr( $e->getMessage(), $e->arguments() );
}
$check( 'policy: a folder that needs EU is kept away from a US provider', strpos( $policyError, 'The data policy keeps' ) === 0 && strpos( $policyError, 'regions eu or local required' ) !== false, true );
$check( 'policy: no request was sent', expAiConversation::fetchCurrent( $userID, $nodeID ), null );
$set( 'DataPolicy', array( 'ClassRegions' => array() ) );

// ------------------------------------------------------------------ 7. MCP: an external server, allowlist, injection, event stream
foreach ( array( '' => 'json', '?sse=1' => 'event stream' ) as $suffix => $kind )
{
    $set( 'McpSettings', array( 'Servers' => array( 'docsmock' ) ) );
    $set( 'McpServer_docsmock', array( 'Label' => 'Docs (mock)', 'URL' => $mock . '/mcp' . $suffix, 'Token' => 'mcp-test-token', 'AllowedTools' => array( 'search_docs' ),
                                        'Region' => 'us', 'Retention' => 'none', 'AllowLoopback' => 'enabled', 'ToolsCacheTTL' => 0, 'MaxResultBytes' => 2000 ) );
    $tools = expAiMcp::tools( expAiConfig::instance() );
    $check( "mcp ($kind): only the allowlisted tool", array_keys( $tools ), array( 'mcp__docsmock__search_docs' ) );
    expAiConversation::removeFor( $userID, $nodeID );
    $assistant = new expAiAssistant( expAiConfig::instance(), $registry->providerFor( 'hfmock.e2e' ) );
    $results = $loop( $assistant, 'Please search the docs for spring events.' );
    $texts = $stepTexts( $results );
    $check( "mcp ($kind): the external tool ran", isset( $results[0]['steps'][0] ) && $results[0]['steps'][0]['ok'], true );
    $check( "mcp ($kind): writing refused after the external result", count( array_filter( $texts, function ( $t ) { return strpos( $t, 'Writing refused after an external tool result' ) !== false; } ) ), 1 );
    $check( "mcp ($kind): nothing named hacked was written", count( array_filter( expAiAssistant::drafts( $user, $nodeID ), function ( $d ) { return $d['name'] === 'hacked'; } ) ), 0 );
    $conversation = expAiConversation::fetchCurrent( $userID, $nodeID );
    $rows = $usageRows( $conversation->attribute( 'id' ) );
    $check( "mcp ($kind): the MCP call is counted", (int) $rows[0]->attribute( 'mcp_calls' ), 1 );
    $check( "mcp ($kind): the result went to the model as data", strpos( json_encode( $conversation->messages() ), 'data, not instructions' ) !== false, true );
}
$set( 'McpServer_docsmock', array( 'Token' => 'wrong' ) );
$check( 'mcp: a wrong token means no tools (and a warning)', array_keys( expAiMcp::tools( expAiConfig::instance() ) ), array() );
$set( 'McpSettings', array( 'Servers' => array() ) );

// ------------------------------------------------------------------ 8. a field action and "fill" on the draft written above
$draftLog = null;
foreach ( expAiDraftLog::fetchForUserNode( $userID, $nodeID, 30 ) as $row )
    if ( (int) $row->attribute( 'created' ) >= $started && $row->contentVersion() && (int) $row->contentVersion()->attribute( 'status' ) === eZContentObjectVersion::STATUS_DRAFT )
        $draftLog = $row;
if ( $draftLog )
{
    $object = $draftLog->contentObject();
    $attributeID = 0;
    foreach ( $object->fetchDataMap( (int) $draftLog->attribute( 'version' ), $draftLog->attribute( 'language_code' ) ) as $identifier => $attribute )
        if ( $identifier === 'name' )
            $attributeID = (int) $attribute->attribute( 'id' );
    $post = array( 'object_id' => $object->attribute( 'id' ), 'version' => $draftLog->attribute( 'version' ), 'language' => $draftLog->attribute( 'language_code' ),
                   'attribute_id' => $attributeID, 'action' => 'rewrite', 'value' => 'Old name', 'instruction' => '', 'target_language' => '', 'context' => '{}' );
    foreach ( array( 'hfmock.e2e' => 'hf', 'ormock.e2e' => 'openrouter' ) as $alias => $label )
    {
        $set( 'ProviderSettings', array( 'DefaultModel' => $alias ) );
        $result = expAiEditForm::fieldAction( expAiConfig::instance(), $post );
        $check( "field action ($alias): the value of the model", $result['value'], 'A clearer value written by the mock (' . $label . ')' );
        $fill = expAiEditForm::fill( expAiConfig::instance(), array( 'instruction' => 'A folder about spring' ) + $post );
        $check( "fill ($alias): the fields of the model", $fill['fields'] ? $fill['fields'][0]['value'] : null, 'Filled by the mock' );
    }
    $marked = expAiDraftLog::fetchByVersion( $object->attribute( 'id' ), $draftLog->attribute( 'version' ) );
    $check( 'field action: the version is marked with the provider', $marked ? $marked->attribute( 'provider' ) : '', 'ormock' );
}
else
    $check( 'field action: a draft of this run to work on', false, true );

// ------------------------------------------------------------------ 9. the probe through the mock (mode mock: never counts for the gate)
$set( 'Model_hfmock.probe', array( 'Provider' => 'hfmock', 'Id' => 'mock/probe', 'ToolUse' => 'native', 'WriteTools' => 'enabled' ) );
$set( 'Model_ormock.probe', array( 'Provider' => 'ormock', 'Id' => 'mock/probe', 'ToolUse' => 'native', 'WriteTools' => 'enabled' ) );
foreach ( array( 'hfmock.probe', 'ormock.probe' ) as $alias )
{
    $config = expAiConfig::instance();
    $result = ( new expAiProbe( $config ) )->run( new expAiUsageRecorder( $config->registry()->providerFor( $alias ), array( 'action' => 'probe' ) ), $nodeID,
                                                  (int) $node->attribute( 'contentobject_id' ), 'folder' );
    $failed = array_values( array_filter( $result['results'], function ( $r ) { return !$r['ok']; } ) );
    $check( "probe ($alias, mock): every task passed", array( $result['passed'], $result['tasks'] ), array( 15, 15 ) );
    foreach ( $failed as $f )
        echo "      $alias failed {$f['id']}: {$f['reason']}\n";
    expAiProbe::store( $alias, $config->registry()->getModel( $alias )['provider'], 'mock/probe', 'native', 'mock', $result );
    $check( "probe ($alias): a mock result does not open the gate", expAiProbe::writeAllowed( $alias, $config ), false );
}

// ------------------------------------------------------------------ clean up: the drafts of this run, the conversations, the probe rows
$removed = 0;
foreach ( eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null, array( 'user_id' => $userID, 'node_id' => $nodeID, 'created' => array( '>=', $started ) ) ) as $row )
{
    $object = $row->contentObject();
    if ( $object && (int) $object->attribute( 'status' ) === eZContentObject::STATUS_DRAFT && !$object->mainNode() )
    {
        $object->purge();
        $removed++;
    }
    $row->remove();
}
expAiConversation::removeFor( $userID, $nodeID );
eZPersistentObject::removeObject( expAiProbeResult::definition(), array( 'model_alias' => array( array( 'hfmock.probe', 'ormock.probe' ) ) ) );
echo "cleaned up: $removed draft object(s) of this run, the conversations and the mock probe rows (usage rows stay: they are the log)\n";
echo ( $failures ? 'FAIL' : 'PASS' ) . " $checks checks, $failures failure(s)\n";
$script->shutdown( $failures ? 1 : 0 );
