<?php
/**
 * Unit test of the fallback to the default model (expAiModelFallback, expAiProviderHealth) and of the model states of
 * the pickers: which failures switch (connection, no answer) and which do not (access, size), why a model cannot take
 * a request before it is sent (removed, not allowed, stopped, unreachable, data policy), which model answers instead
 * (the default; the next allowed one when the default may not take the content; none), resolve() and sendWith().
 * The settings exist only in memory; the "stopped" and "unreachable" servers are closed ports on the loopback address
 * (nothing is started or stopped). No database writes; the only network use is the refused loopback connection.
 *
 * Run from the installation root:
 *   php bin/php/ezexec.php extension/expai/tests/expaifallback_test.php
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

// the shipped settings with test providers: a default (remote), a stopped local server, a loopback server that refuses
// connections, a second remote provider with zero retention
$ini = eZINI::fetchFromFile( 'extension/expai/settings/expai.ini.append.php' );
$set = function ( $group, array $values ) use ( $ini ) { foreach ( $values as $name => $value ) $ini->setVariable( $group, $name, $value ); };
$set( 'AISettings', array( 'Enabled' => 'true', 'AllowUserModelChoice' => 'enabled', 'FallbackToDefaultModel' => 'enabled', 'HealthCacheSeconds' => '30' ) );
$set( 'ProviderSettings', array( 'Providers' => array( 'fbdefault', 'fblocal', 'fbloop', 'fbzdr' ), 'DefaultModel' => 'fbdefault.m',
                                 'ProvidersWithoutPolicy' => array( 'fbdefault', 'fblocal', 'fbloop', 'fbzdr' ) ) );
$set( 'Provider_fbdefault', array( 'Type' => 'openai_chat', 'Label' => 'Default', 'BaseURL' => 'https://default.example.test/v1', 'ApiKey' => 'x', 'Region' => 'us', 'Retention' => 'unknown' ) );
$set( 'Provider_fblocal', array( 'Type' => 'openai_chat', 'Flavor' => 'ollama', 'Label' => 'Local', 'BaseURL' => 'http://127.0.0.1:1/v1', 'ApiKeyHeader' => 'none',
                                 'Region' => 'local', 'Retention' => 'none', 'Managed' => 'ollama' ) );
$set( 'Provider_fbloop', array( 'Type' => 'openai_chat', 'Label' => 'Loopback', 'BaseURL' => 'http://127.0.0.1:1/v1', 'ApiKeyHeader' => 'none', 'Region' => 'local', 'Retention' => 'none' ) );
$set( 'Provider_fbzdr', array( 'Type' => 'openai_chat', 'Label' => 'ZDR', 'BaseURL' => 'https://zdr.example.test/v1', 'ApiKey' => 'x', 'Region' => 'us', 'Retention' => 'zdr' ) );
foreach ( array( 'fbdefault.m' => 'fbdefault', 'fblocal.m' => 'fblocal', 'fbloop.m' => 'fbloop', 'fbzdr.m' => 'fbzdr' ) as $alias => $provider )
    $set( 'Model_' . $alias, array( 'Provider' => $provider, 'Id' => 'test/' . $alias, 'Label' => 'Label ' . $alias, 'ToolUse' => 'native' ) );
$config = new expAiConfig( $ini );
expAiProviderHealth::reset();

// ---------------------------------------------------------------- which failures switch
$e = function ( $detail, $code = 0 ) { return new expAiException( 'x', $detail, $code ); };
$check( 'kind: connection refused', expAiModelFallback::failureKind( $e( 'provider fbdefault: curl error 7' ) ), 'unreachable' );
$check( 'kind: name not resolved', expAiModelFallback::failureKind( $e( 'curl error 6' ) ), 'unreachable' );
$check( 'kind: TLS handshake', expAiModelFallback::failureKind( $e( 'curl error 35' ) ), 'unreachable' );
$check( 'kind: timeout before any byte', expAiModelFallback::failureKind( $e( 'provider x: timeout after 115 s, no answer' ) ), 'timeout' );
$check( 'kind: timeout with a partial answer', expAiModelFallback::failureKind( $e( 'timeout after 115 s' ) ), '' );
$check( 'kind: access refused (401)', expAiModelFallback::failureKind( $e( 'provider x: HTTP 401', 401 ) ), '' );
$check( 'kind: request too long (413)', expAiModelFallback::failureKind( $e( 'provider x: HTTP 413', 413 ) ), '' );
$check( 'kind: answer too long', expAiModelFallback::failureKind( $e( 'response larger than 4194304 bytes' ) ), '' );
$check( 'kind: write error is not the connection', expAiModelFallback::failureKind( $e( 'curl error 23' ) ), '' );
$check( 'reason: a refused managed local server is stopped', expAiModelFallback::reason( $e( 'curl error 7' ), $config, 'fblocal.m' ), 'stopped' );
$check( 'reason: a refused remote provider is unreachable', expAiModelFallback::reason( $e( 'curl error 7' ), $config, 'fbzdr.m' ), 'unreachable' );
$check( 'log text: connection failure', expAiModelFallback::isConnectionFailureText( 'Exponential Intelligence could not be reached. (provider local: curl error 7)' ), true );

// ---------------------------------------------------------------- health of the pickers
$check( 'health: managed server on a closed port is stopped', expAiProviderHealth::state( $config, 'fblocal' ), 'stopped' );
$check( 'health: loopback server on a closed port is unreachable', expAiProviderHealth::state( $config, 'fbloop' ), 'unreachable' );
$check( 'health: remote provider is not probed', expAiProviderHealth::state( $config, 'fbzdr' ), '' );
expAiProviderHealth::remember( $config, 'fbzdr', 'unreachable' );
$check( 'health: a failed request marks a remote provider at once', expAiProviderHealth::state( $config, 'fbzdr' ), 'unreachable' );
expAiProviderHealth::remember( $config, 'fbzdr', '' );
$check( 'health: an answer marks it reachable again', expAiProviderHealth::state( $config, 'fbzdr' ), '' );

// ---------------------------------------------------------------- before the request
$check( 'preflight: usable', expAiModelFallback::preflight( $config, 'fbzdr.m' ), '' );
$check( 'preflight: removed from the registry', expAiModelFallback::preflight( $config, 'gone.m' ), 'removed' );
$check( 'preflight: stopped', expAiModelFallback::preflight( $config, 'fblocal.m' ), 'stopped' );
$check( 'preflight: unreachable', expAiModelFallback::preflight( $config, 'fbloop.m' ), 'unreachable' );
$refuseDefault = function ( $providerID ) { return $providerID !== 'fbdefault'; };
$refuseAll = function ( $providerID ) { return false; };
$check( 'preflight: data policy', expAiModelFallback::preflight( $config, 'fbdefault.m', $refuseDefault ), 'data_policy' );
$ini->setVariable( 'AISettings', 'AllowUserModelChoice', 'disabled' );
$check( 'preflight: not allowed (model choice off)', expAiModelFallback::preflight( $config, 'fbzdr.m' ), 'not_allowed' );
$check( 'preflight: the default stays allowed', expAiModelFallback::preflight( $config, 'fbdefault.m' ), '' );
$ini->setVariable( 'AISettings', 'AllowUserModelChoice', 'enabled' );

// ---------------------------------------------------------------- the replacement
$check( 'choose: stopped model -> the default', expAiModelFallback::choose( $config, 'fblocal.m' ), 'fbdefault.m' );
$check( 'choose: the default never moves elsewhere', expAiModelFallback::choose( $config, 'fbdefault.m' ), '' );
$check( 'choose: default refused by the data policy -> next allowed', expAiModelFallback::choose( $config, 'fblocal.m', $refuseDefault ), 'fbzdr.m' );
$check( 'choose: none allowed', expAiModelFallback::choose( $config, 'fblocal.m', $refuseAll ), '' );

list( $used, $fallback ) = expAiModelFallback::resolve( $config, 'fblocal.m' );
$check( 'resolve: stopped -> default', $used, 'fbdefault.m' );
$check( 'resolve: describes the switch', array( $fallback['requested'], $fallback['used'], $fallback['reason'] ), array( 'fblocal.m', 'fbdefault.m', 'stopped' ) );
$check( 'resolve: the notice names both models', $fallback['notice'], 'Label fbdefault.m answered instead: Label fblocal.m is stopped.' );
list( $used, $fallback ) = expAiModelFallback::resolve( $config, 'fbzdr.m' );
$check( 'resolve: usable model stays', array( $used, $fallback ), array( 'fbzdr.m', null ) );
list( $used, $fallback ) = expAiModelFallback::resolve( $config, 'gone.m' );
$check( 'resolve: removed -> default', array( $used, $fallback['reason'] ), array( 'fbdefault.m', 'removed' ) );
list( $used, $fallback ) = expAiModelFallback::resolve( $config, 'fbdefault.m', $refuseAll );
$check( 'resolve: the default itself is kept (its failure shows as before)', array( $used, $fallback ), array( 'fbdefault.m', null ) );
try
{
    expAiModelFallback::resolve( $config, 'fblocal.m', $refuseAll );
    $check( 'resolve: none allowed throws', false, true );
}
catch ( expAiException $x )
{
    $check( 'resolve: none allowed names why', $x->translatedMessage(), 'Label fblocal.m is stopped, and no other model you may use can answer for this content.' );
}
$ini->setVariable( 'AISettings', 'FallbackToDefaultModel', 'disabled' );
list( $used, $fallback ) = expAiModelFallback::resolve( $config, 'fblocal.m' );
$check( 'resolve, switched off: the model as requested, no fallback', array( $used, $fallback ), array( 'fblocal.m', null ) );
$ini->setVariable( 'AISettings', 'FallbackToDefaultModel', 'enabled' );

// ---------------------------------------------------------------- after a failed request
expAiProviderHealth::reset();
$calls = array();
$fallback = null;
$answer = expAiModelFallback::sendWith( $config, 'fbzdr.m', function ( $alias ) use ( &$calls )
{
    $calls[] = $alias;
    if ( $alias === 'fbzdr.m' )
        throw new expAiException( 'Exponential Intelligence could not be reached.', 'provider fbzdr: curl error 7' );
    return 'answer of ' . $alias;
}, $fallback );
$check( 'sendWith: refused -> the default answers', array( $answer, $calls ), array( 'answer of fbdefault.m', array( 'fbzdr.m', 'fbdefault.m' ) ) );
$check( 'sendWith: reason unreachable', $fallback['reason'], 'unreachable' );
$check( 'sendWith: the provider is marked for the pickers', expAiProviderHealth::state( $config, 'fbzdr' ), 'unreachable' );
expAiProviderHealth::reset();
foreach ( array( 'HTTP 401' => 401, 'HTTP 413' => 413 ) as $detail => $code )
{
    $fallback = null;
    try
    {
        expAiModelFallback::sendWith( $config, 'fbzdr.m', function ( $alias ) use ( $detail, $code ) { throw new expAiException( 'refused', 'provider fbzdr: ' . $detail, $code ); }, $fallback );
        $check( "sendWith: $detail is not a reason", 'no exception', 'exception' );
    }
    catch ( expAiException $x )
    {
        $check( "sendWith: $detail is shown as it is", array( $x->getMessage(), $fallback ), array( 'refused', null ) );
    }
}
try
{
    expAiModelFallback::sendWith( $config, 'fbdefault.m', function ( $alias ) { throw new expAiException( 'Exponential Intelligence could not be reached.', 'curl error 7' ); } );
    $check( 'sendWith: an unreachable default throws', false, true );
}
catch ( expAiException $x )
{
    $check( 'sendWith: an unreachable default is shown as it is', $x->getMessage(), 'Exponential Intelligence could not be reached.' );
}
expAiProviderHealth::reset();

// ---------------------------------------------------------------- the pickers
$states = array();
foreach ( $config->modelChoices() as $group )
    foreach ( $group['models'] as $m )
        $states[$m['alias']] = array( $m['state'], $m['disabled'] );
$check( 'picker: stopped local model shown and disabled', $states['fblocal.m'], array( 'stopped', true ) );
$check( 'picker: unreachable loopback model disabled', $states['fbloop.m'], array( 'unreachable', true ) );
$check( 'picker: usable models enabled', array( $states['fbdefault.m'], $states['fbzdr.m'] ), array( array( '', false ), array( '', false ) ) );
$texts = $config->modelStates();
$check( 'picker states: only the unusable models', array_keys( $texts ), array( 'fblocal.m', 'fbloop.m' ) );
$check( 'picker states: stopped text', $texts['fblocal.m']['text'] === 'stopped' || $texts['fblocal.m']['text'] === 'stopped — start it under Models', true );

echo ( $failures ? 'FAIL' : 'PASS' ) . " $checks checks, $failures failure(s)\n";
eZScript::instance()->shutdown( $failures ? 1 : 0 );
