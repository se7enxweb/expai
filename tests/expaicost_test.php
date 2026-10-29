<?php
/**
 * Unit test of expAiCost: the model matcher and the prices, against the price table shipped in
 * extension/expai/settings/expai.ini.append.php (not the site's overrides). No database, no network.
 *
 * Run from the installation root:
 *   php bin/php/ezexec.php extension/expai/tests/expaicost_test.php
 * It prints one line per check and ends with PASS or FAIL (exit code 0 or 1).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$shipped = eZINI::fetchFromFile( 'extension/expai/settings/expai.ini.append.php' );
$config = new expAiConfig( $shipped );
$known = array_map( 'strval', array_keys( (array) $config->get( 'Pricing', 'InputPerMTok', array() ) ) );
$failures = 0;
$checks = 0;

$check = function ( $label, $actual, $expected ) use ( &$failures, &$checks )
{
    ++$checks;
    $ok = is_float( $expected ) ? abs( (float) $actual - $expected ) < 1e-9 : $actual === $expected;
    if ( !$ok )
        ++$failures;
    echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $label . ' => ' . var_export( $actual, true ) . ( $ok ? '' : ' (expected ' . var_export( $expected, true ) . ')' ) . "\n";
};

$check( 'the shipped price table has models', count( $known ) > 0, true );

// every shipped model id, as it is and in the forms the providers and the API report it
foreach ( $known as $id )
{
    $stem = preg_replace( '/-\d{8}$/', '', $id );
    $check( "match( $id )", expAiCost::match( $id, $known ), $id );
    $check( "match( " . strtoupper( $id ) . " )", expAiCost::match( strtoupper( $id ), $known ), $id );
    $check( "match( $stem-20990101 )", expAiCost::match( $stem . '-20990101', $known ), $id );
    $check( "match( $stem-latest )", expAiCost::match( $stem . '-latest', $known ), $id );
    $check( "match( anthropic.$id-v1:0 )", expAiCost::match( 'anthropic.' . $id . '-v1:0', $known ), $id );
    $check( "match( us.anthropic.$stem-20990101-v1:0 )", expAiCost::match( 'us.anthropic.' . $stem . '-20990101-v1:0', $known ), $id );
    $check( "match( $stem@20990101 )", expAiCost::match( $stem . '@20990101', $known ), $id );
    // the alias without the date of a dated row
    if ( $stem !== $id )
        $check( "match( $stem )", expAiCost::match( $stem, $known ), $id );

    // the prices of the row itself: never the fallback, never zero
    $p = expAiCost::prices( $id, 0, $config );
    $check( "prices( $id ) not approximate", $p['approx'], false );
    $check( "prices( $id ) input and output set", $p['input'] > 0 && $p['output'] > 0, true );
}

// a longer stem wins over a shorter one: claude-opus-5-5 is not priced as claude-opus-5
$check( 'match( claude-opus-5-5-20990101 )', expAiCost::match( 'claude-opus-5-5-20990101', $known ), 'claude-opus-5-5' );
$check( 'match( claude-sonnet-5-20990101 )', expAiCost::match( 'claude-sonnet-5-20990101', $known ), 'claude-sonnet-5' );

// unknown models are not guessed: the default prices are used and marked approximate
foreach ( array( '', 'gpt-5', 'claude-unknown-9', 'claude-opus-4-8', 'claude-sonnet-55' ) as $model )
    $check( "match( '$model' )", expAiCost::match( $model, $known ), null );
$p = expAiCost::prices( 'claude-unknown-9', 0, $config );
$check( 'prices( unknown ) approximate', $p['approx'], true );
$check( 'prices( unknown ) key', $p['key'], 'default' );
$check( 'prices( unknown ) = DefaultInputPerMTok', $p['input'], (float) $config->get( 'Pricing', 'DefaultInputPerMTok' ) );

// Claude Sonnet 5.5: 2 / 2.50 / 0.10 / 10 per million tokens
$p = expAiCost::prices( 'claude-sonnet-5-5', 0, $config );
$check( 'sonnet 5.5 input', $p['input'], 2.0 );
$check( 'sonnet 5.5 cache write', $p['cache_write'], 2.5 );
$check( 'sonnet 5.5 cache read', $p['cache_read'], 0.1 );
$check( 'sonnet 5.5 output', $p['output'], 10.0 );

// Claude Haiku 5.5: the long-context tier above the threshold (input + cache write + cache read)
if ( in_array( 'claude-haiku-5-5', $known, true ) )
{
    $short = expAiCost::prices( 'claude-haiku-5-5', 100000, $config );
    $long = expAiCost::prices( 'claude-haiku-5-5', 100001, $config );
    $check( 'haiku 5.5 at the threshold: short input price', $short['input'], 0.10 );
    $check( 'haiku 5.5 over the threshold: long input price', $long['input'], 0.50 );
    $check( 'haiku 5.5 over the threshold: long output price', $long['output'], 2.50 );
}

// formatting does not depend on the price table
$check( 'tokens( 950 )', expAiCost::tokens( 950 ), '950' );

echo ( $failures ? 'FAIL' : 'PASS' ) . " $checks checks, $failures failure(s), " . count( $known ) . " shipped model id(s)\n";
$script = eZScript::instance();
$script->shutdown( $failures ? 1 : 0 );
