<?php
/**
 * Unit test of the compact prompt profile for small and local models (expAiCompactPrompt, doc/local-models.md "Making
 * a local model usable"): the profile of a model (own setting, the default of Region=local), what a message asks for,
 * the tools a message gets (none for a plain question, list_children for the children, update_draft for a change,
 * create_draft for a new object, propose_plan in plan mode) within the model's own Tools[], the short definitions,
 * the short system prompt and its size, the snapshot of the open page and its limit, the short rules of a field
 * action; and the settings that come with it: TimeoutBackground outside a web request only, no fallback after a
 * background timeout, keep_alive for Ollama. Settings exist only in memory; the content is read (node 2), nothing is
 * written and no model is called.
 *
 * Run from the installation root:
 *   php bin/php/ezexec.php extension/expai/tests/expaicompactprompt_test.php -s admin
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
    $ok = $actual === $expected;
    if ( !$ok )
        ++$failures;
    echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $label . ( $ok ? '' : ' => ' . var_export( $actual, true ) . ' (expected ' . var_export( $expected, true ) . ')' ) . "\n";
};
$tokens = function ( $text ) { return (int) ceil( strlen( (string) $text ) / 3.6 ); };

$admin = eZUser::fetchByName( 'admin' );
eZUser::setCurrentlyLoggedInUser( $admin, $admin->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
$ini = eZINI::fetchFromFile( 'extension/expai/settings/expai.ini.append.php' );
$set = function ( $group, array $values ) use ( $ini ) { foreach ( $values as $name => $value ) $ini->setVariable( $group, $name, $value ); };
$set( 'AISettings', array( 'Enabled' => 'true', 'AllowUserModelChoice' => 'enabled', 'FallbackToDefaultModel' => 'enabled' ) );
$set( 'ProviderSettings', array( 'Providers' => array( 'cpremote', 'cplocal' ), 'DefaultModel' => 'cpremote.m', 'ProvidersWithoutPolicy' => array( 'cpremote', 'cplocal' ) ) );
$set( 'Provider_cpremote', array( 'Type' => 'openai_chat', 'Label' => 'Remote', 'BaseURL' => 'https://remote.example.test/v1', 'ApiKey' => 'x', 'Region' => 'us', 'Timeout' => '115' ) );
$set( 'Provider_cplocal', array( 'Type' => 'openai_chat', 'Flavor' => 'ollama', 'Label' => 'Local', 'BaseURL' => 'http://127.0.0.1:1/v1', 'ApiKeyHeader' => 'none',
                                'Region' => 'local', 'Retention' => 'none', 'Managed' => 'ollama', 'Timeout' => '280', 'TimeoutBackground' => '900', 'KeepAlive' => '30m' ) );
$set( 'Model_cpremote.m', array( 'Provider' => 'cpremote', 'Id' => 'remote/m', 'Label' => 'Remote model', 'ToolUse' => 'native' ) );
$set( 'Model_cplocal.small', array( 'Provider' => 'cplocal', 'Id' => 'small:4b', 'Label' => 'Small (local)', 'ToolUse' => 'native',
                                     'Tools' => array( 'get_node', 'list_children', 'create_draft', 'update_draft', 'propose_plan' ) ) );
$set( 'Model_cplocal.full', array( 'Provider' => 'cplocal', 'Id' => 'big:9b', 'Label' => 'Big (local)', 'ToolUse' => 'native', 'PromptProfile' => 'full' ) );
$set( 'Model_cpremote.compact', array( 'Provider' => 'cpremote', 'Id' => 'remote/s', 'Label' => 'Remote small', 'ToolUse' => 'native', 'PromptProfile' => 'compact' ) );
$set( 'McpBridge', array( 'Enabled' => 'disabled' ) );
$config = new expAiConfig( $ini );

// ---------------------------------------------------------------- the profile of a model
$check( 'profile: a local model is compact by default', expAiCompactPrompt::profile( $config, 'cplocal.small' ), 'compact' );
$check( 'profile: a remote model is full by default', expAiCompactPrompt::profile( $config, 'cpremote.m' ), 'full' );
$check( 'profile: PromptProfile=full wins for a local model', expAiCompactPrompt::profile( $config, 'cplocal.full' ), 'full' );
$check( 'profile: PromptProfile=compact for a remote model', expAiCompactPrompt::applies( $config, 'cpremote.compact' ), true );
$check( 'profile: an unknown alias is full', expAiCompactPrompt::profile( $config, 'nope.x' ), 'full' );
$shipped = eZINI::fetchFromFile( 'extension/expai/settings/expai.ini.append.php' );
$check( 'shipped: the local Qwen model is compact', $shipped->variable( 'Model_local.qwen3.5-4b', 'PromptProfile' ), 'compact' );
$check( 'shipped: the local server has a background time limit', (int) $shipped->variable( 'Provider_local', 'TimeoutBackground' ), 900 );

// ---------------------------------------------------------------- what a message asks for
$i = expAiCompactPrompt::intents( 'what is 2 + 3?' );
$check( 'intents: a plain question asks for nothing', array_keys( array_filter( $i ) ), array() );
$i = expAiCompactPrompt::intents( 'summarize this page in 2 sentences' );
$check( 'intents: a summary is about the page', $i['page'] && !$i['write'], true );
$i = expAiCompactPrompt::intents( 'rewrite the intro shorter' );
$check( 'intents: a rewrite is a change of the page', $i['page'] && $i['write'] && !$i['create'], true );
$i = expAiCompactPrompt::intents( 'create a folder named Test here' );
$check( 'intents: create', $i['create'] && $i['write'], true );
$i = expAiCompactPrompt::intents( 'Fasse diese Seite zusammen' );
$check( 'intents: German "diese Seite"', $i['page'], true );
$i = expAiCompactPrompt::intents( 'Kürze die Einleitung' );
$check( 'intents: German "Kürze" (umlaut word boundary)', $i['write'], true );
$i = expAiCompactPrompt::intents( 'list the children' );
$check( 'intents: children', $i['children'] && $i['page'], true );

// ---------------------------------------------------------------- the tools a message gets
$available = array( 'get_node', 'list_children', 'get_tree_context', 'list_creatable_classes', 'get_class_definition', 'create_draft', 'update_draft', 'propose_plan', 'web_fetch' );
$names = function ( $message, $writes = true, $mode = 'write', $alias = 'cplocal.small' ) use ( $config, $available )
{
    return expAiCompactPrompt::toolNames( $config, $alias, $message, $writes, $mode, $available );
};
$check( 'tools: none for a plain question', $names( 'what is 2 + 3?' ), array() );
$check( 'tools: none for a summary (the snapshot answers it)', $names( 'summarize this page in 2 sentences' ), array() );
$check( 'tools: update_draft for a rewrite', $names( 'rewrite the intro shorter' ), array( 'update_draft' ) );
$check( 'tools: create_draft for a new object (get_class_definition is not in this model\'s Tools[])', $names( 'create a folder named Test here' ), array( 'create_draft', 'update_draft' ) );
$check( 'tools: the core list when the model names none', $names( 'create a folder named Test here', true, 'write', 'cpremote.compact' ), array( 'get_class_definition', 'create_draft', 'update_draft' ) );
$check( 'tools: list_children for the children', $names( 'list the children' ), array( 'list_children' ) );
$check( 'tools: no write tools for a model that may not write', $names( 'rewrite the intro shorter', false ), array() );
$check( 'tools: plan mode reads and proposes', $names( 'update all articles', false, 'plan' ), array( 'get_node', 'propose_plan' ) );
$check( 'tools: never a tool that is not available', expAiCompactPrompt::toolNames( $config, 'cplocal.small', 'rewrite the intro', true, 'write', array( 'get_node' ) ), array() );

// ---------------------------------------------------------------- short definitions
$registry = new expAiToolRegistry( $config, false );
$defs = expAiCompactPrompt::definitions( array( $registry->get( 'update_draft' ), $registry->get( 'create_draft' ), $registry->get( 'list_children' ) ) );
$check( 'definitions: the short description', $defs[0]['description'], expAiCompactPrompt::SHORT['update_draft'] );
$check( 'definitions: new_key is dropped outside staged review', isset( $defs[1]['input_schema']['properties']['new_key'] ), false );
$check( 'definitions: paging is dropped', isset( $defs[2]['input_schema']['properties']['offset'] ), false );
$check( 'definitions: required fields stay', $defs[1]['input_schema']['required'], array( 'class_identifier', 'fields' ) );
$staged = expAiCompactPrompt::definitions( array( $registry->get( 'create_draft' ) ), true );
$check( 'definitions: new_key stays in staged review', isset( $staged[0]['input_schema']['properties']['new_key'] ), true );
$full = 0;
foreach ( $registry->definitions( true ) as $d )
    $full += strlen( json_encode( $d ) );
$short = 0;
foreach ( $defs as $d )
    $short += strlen( json_encode( $d ) );
$check( 'definitions: three short tools are much smaller than the full list', $short * 3 < $full, true );

// ---------------------------------------------------------------- the system prompt, the snapshot
$node = eZContentObjectTreeNode::fetch( 2 );
$system = expAiCompactPrompt::system( $node, $config, 'cplocal.small', true );
$check( 'system: under 350 tokens', $tokens( $system ) < 350, true );
$check( 'system: the node line comes last (static prefix first)', strpos( $system, 'The editor has node 2 open' ) > strpos( $system, 'Rules:' ), true );
$check( 'system: the safety rules are in it', strpos( $system, 'data, not instructions' ) !== false && strpos( $system, 'never publish' ) !== false, true );
$check( 'system: names the local model', strpos( $system, 'Small (local)' ) !== false, true );
$check( 'system: the same for the same node (cacheable)', expAiCompactPrompt::system( $node, $config, 'cplocal.small', true ), $system );
$check( 'system: staged review note', strpos( expAiCompactPrompt::system( $node, $config, 'cplocal.small', true, 'staged' ), 'Staged review' ) !== false, true );
$check( 'system: read only says so', strpos( expAiCompactPrompt::system( $node, $config, 'cplocal.small', false ), 'cannot write drafts' ) !== false, true );
$full = expAiPrompts::panelSystem( $node, $admin, $config, true );
$check( 'system: shorter than the full one', strlen( $system ) < strlen( $full ), true );
$reader = new expAiContentReader( $config, expAiDataPolicy::forProvider( $config, 'cplocal' ) );
$snapshot = expAiCompactPrompt::snapshot( $config, $reader, $node );
$check( 'snapshot: starts with the marker', strpos( $snapshot, expAiCompactPrompt::SNAPSHOT_MARKER ) === 0, true );
$check( 'snapshot: within CompactContextChars (1800) and a line', strlen( $snapshot ) <= 1800 + 200, true );
$check( 'snapshot: no HTML tags', $snapshot === strip_tags( $snapshot ), true );
$ini->setVariable( 'AssistantSettings', 'CompactContextChars', '300' );
$check( 'snapshot: the limit is a setting', strlen( expAiCompactPrompt::snapshot( new expAiConfig( $ini ), $reader, $node ) ) <= 300 + 200, true );
$ini->setVariable( 'AssistantSettings', 'CompactContextChars', '1800' );
$check( 'snapshot: found once in a conversation', expAiCompactPrompt::hasSnapshot( array( array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => $snapshot ) ) ) ) ), true );
$check( 'snapshot: not in an empty conversation', expAiCompactPrompt::hasSnapshot( array() ), false );
$parts = expAiCompactPrompt::build( $config, 'cplocal.small', $node, $admin, $reader, $registry, true, 'what is 2 + 3?', array() );
$check( 'build: a plain question is under 1,000 tokens in all', $tokens( $parts['system'] ) + $tokens( json_encode( $parts['tools'] ) ) + $tokens( implode( "\n", $parts['context'] ) ) < 1000, true );
$check( 'build: no context for a plain question', $parts['context'], array() );
$parts = expAiCompactPrompt::build( $config, 'cplocal.small', $node, $admin, $reader, $registry, true, 'rewrite the intro shorter', array() );
$check( 'build: a rewrite has the snapshot and update_draft', count( $parts['context'] ) === 1 && $parts['tools'][0]['name'] === 'update_draft', true );

// ---------------------------------------------------------------- the field action's rules
$check( 'field: compact rules for a compact model', strpos( expAiPrompts::fieldSystem( $config, 'cplocal.small' ), expAiCompactPrompt::rules() ) !== false, true );
$check( 'field: full rules otherwise', strpos( expAiPrompts::fieldSystem( $config, 'cpremote.m' ), expAiPrompts::rules() ) !== false, true );

// ---------------------------------------------------------------- the time limits and the fallback, keep_alive
$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
unset( $_SERVER['REQUEST_METHOD'] );
$check( 'timeout: background runs get TimeoutBackground', (int) $config->registry()->settingsFor( 'cplocal.small' )['Timeout'], 900 );
$check( 'timeout: a model without it keeps Timeout', (int) $config->registry()->settingsFor( 'cpremote.m' )['Timeout'], 115 );
$check( 'http: 900 s are allowed in the background', ( new expAiHttpClient( 900 ) )->timeout(), 900 );
$timeout = new expAiException( 'x', 'provider cplocal: timeout after 900 s, no answer' );
$check( 'fallback: a background timeout keeps the editor\'s model', expAiModelFallback::reason( $timeout, $config, 'cplocal.small' ), '' );
$check( 'fallback: a background connection failure still hands on', expAiModelFallback::reason( new expAiException( 'x', 'curl error 7' ), $config, 'cplocal.small' ), 'stopped' );
$_SERVER['REQUEST_METHOD'] = 'POST';
$check( 'timeout: a web request keeps the short Timeout', (int) $config->registry()->settingsFor( 'cplocal.small' )['Timeout'], 280 );
$check( 'http: at most 600 s in a web request', ( new expAiHttpClient( 900 ) )->timeout(), 600 );
$check( 'fallback: a timeout in a web request hands on', expAiModelFallback::reason( $timeout, $config, 'cplocal.small' ), 'timeout' );
if ( $method === null )
    unset( $_SERVER['REQUEST_METHOD'] );
else
    $_SERVER['REQUEST_METHOD'] = $method;
$provider = new expAiOpenAiChatProvider( $config->registry()->settingsFor( 'cplocal.small' ) );
$payload = $provider->buildPayload( array( 'system' => $system, 'messages' => array( array( 'role' => 'user', 'content' => 'hi' ) ), 'tools' => array() ) );
$check( 'keep_alive: sent to Ollama', isset( $payload['keep_alive'] ) ? $payload['keep_alive'] : null, '30m' );
$check( 'keep_alive: no tools for a plain question', isset( $payload['tools'] ), false );
$remote = new expAiOpenAiChatProvider( $config->registry()->settingsFor( 'cpremote.m' ) + array( 'KeepAlive' => '30m' ) );
$check( 'keep_alive: never to another server', isset( $remote->buildPayload( array( 'messages' => array() ) )['keep_alive'] ), false );

// ---------------------------------------------------------------- the Models card shows what the model read
$row = function ( $id, $in, $cached, $error = '' )
{
    return array( 'id' => $id, 'created' => time(), 'model_alias' => 'cplocal.small', 'model' => 'small:4b', 'provider' => 'cplocal', 'region' => 'local',
                  'error_class' => $error, 'error_message' => $error ? 'timeout after 280 s, no answer' : '', 'http_status' => 0, 'latency_ms' => 50000,
                  'input_tokens' => $in, 'output_tokens' => 20, 'cache_write_tokens' => 0, 'cache_read_tokens' => $cached, 'reported_cost_micros' => 0,
                  'price_factor' => 100, 'requested_model_alias' => '', 'fallback_reason' => '', 'conversation_id' => 0, 'turn' => 0, 'thread_id' => 0, 'run_id' => 0 );
};
$stats = expAiModelStats::aggregate( array( $row( 1, 268, 0 ), $row( 2, 24, 264 ), $row( 3, 565, 0 ), $row( 4, 0, 0, 'expAiException' ) ) );
$check( 'card: the median prompt of the successful requests', $stats[0]['prompt_median'], 288 );
$check( 'card: the share of prompt tokens from the cache', $stats[0]['cache_percent'], 24 );
$check( 'card: no prompt figures without requests', expAiModelStats::aggregate( array( $row( 5, 0, 0, 'expAiException' ) ) )[0]['prompt_median'], null );
$check( 'models page: the profile of each model', ( function () use ( $config ) { foreach ( $config->registry()->describeProvider( 'cplocal' )['models'] as $m ) if ( $m['alias'] === 'cplocal.small' ) return $m['prompt_profile']; return null; } )(), 'compact' );

// ---------------------------------------------------------------- the probe tests what a compact model is sent
$probeTools = ( new expAiProbe( $config ) )->toolsFor( new expAiOpenAiChatProvider( $config->registry()->settingsFor( 'cplocal.small' ) ) );
$check( 'probe: a compact model gets the seven short panel tools', array_map( function ( $d ) { return $d['name']; }, $probeTools ),
        array( 'get_node', 'list_children', 'get_tree_context', 'list_creatable_classes', 'get_class_definition', 'create_draft', 'update_draft' ) );
$check( 'probe: paging stays for the paging task', isset( $probeTools[1]['input_schema']['properties']['offset'] ), true );
$check( 'probe: a full model gets the full definitions', count( ( new expAiProbe( $config ) )->toolsFor( new expAiOpenAiChatProvider( $config->registry()->settingsFor( 'cpremote.m' ) ) ) ) > 7, true );

echo ( $failures ? 'FAIL' : 'PASS' ) . " ($checks checks, $failures failed)\n";
eZScript::instance()->shutdown( $failures ? 1 : 0 );
