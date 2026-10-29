<?php
/**
 * Test of the editor presets (expAiEditPresets) and the "EI: Summary" page (expAiSummary), no network:
 *   - the shipped mapping ([Presets] in extension/expai/settings/expai.ini.append.php): every preset and Improve entry
 *     has its field action section; every target of an installed class exists and fits (writable datatype, or a part
 *     of an xrowmetadata field);
 *   - target parsing and the cleaning of xrowmetadata parts;
 *   - expAiSummary::collect() with the site's settings: every section is there, and no key or token value appears in
 *     the collected summary.
 *
 * Run from the installation root (siteaccess with the site's expai settings, e.g. admin):
 *   php bin/php/ezexec.php extension/expai/tests/expaipresets_test.php -s admin
 * It prints one line per check and ends with PASS or FAIL (exit code 0 or 1).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$shipped = new expAiConfig( eZINI::fetchFromFile( 'extension/expai/settings/expai.ini.append.php' ) );
$failures = 0;
$checks = 0;
$check = function ( $label, $ok, $detail = '' ) use ( &$failures, &$checks )
{
    ++$checks;
    if ( !$ok )
        ++$failures;
    echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $label . ( $detail !== '' ? ' => ' . $detail : '' ) . "\n";
};

// targets
$check( 'parseTargets( "title" )', expAiEditPresets::parseTargets( 'title' ) === array( array( 'title', '' ) ) );
$check( 'parseTargets( "title, metadata.description" )', expAiEditPresets::parseTargets( 'title, metadata.description' ) === array( array( 'title', '' ), array( 'metadata', 'description' ) ) );
$check( 'parseTargets ignores an unknown part', expAiEditPresets::parseTargets( 'metadata.robots,body' ) === array( array( 'body', '' ) ) );
$check( 'parseTargets ignores junk', expAiEditPresets::parseTargets( 'Title; drop' ) === array() );

// the shipped presets and Improve entries have their sections
$presets = expAiEditPresets::presetNames( $shipped );
$improve = expAiEditPresets::improveNames( $shipped );
$check( 'four presets shipped', $presets === array( 'title', 'summary', 'meta_description', 'keywords' ), implode( ',', $presets ) );
$check( 'seven Improve entries shipped', count( $improve ) === 7, implode( ',', $improve ) );
foreach ( $presets as $name )
    $check( "[FieldAction_preset_$name] has Label and Instruction", (string) $shipped->get( 'FieldAction_preset_' . $name, 'Label', '' ) !== ''
            && (string) $shipped->get( 'FieldAction_preset_' . $name, 'Instruction', '' ) !== '' );
foreach ( $improve as $name )
    $check( "[FieldAction_improve_$name] has Label and Instruction", (string) $shipped->get( 'FieldAction_improve_' . $name, 'Label', '' ) !== ''
            && (string) $shipped->get( 'FieldAction_improve_' . $name, 'Instruction', '' ) !== '' );

// the shipped mapping against the installed classes
$writable = $shipped->writableDatatypes();
foreach ( array( 'article', 'blog_post', 'folder', 'ng_article', 'ng_blog_post', 'ng_news', 'ng_landing_page', 'ng_category' ) as $classIdentifier )
{
    $map = expAiEditPresets::classPresets( $shipped, $classIdentifier );
    $check( "class $classIdentifier has presets", count( $map ) > 0, implode( ',', array_keys( $map ) ) );
    $class = eZContentClass::fetchByIdentifier( $classIdentifier );
    if ( !$class )
    {
        echo "skip  class $classIdentifier is not installed\n";
        continue;
    }
    $datatypes = array();
    foreach ( $class->fetchAttributes() as $attribute )
        $datatypes[$attribute->attribute( 'identifier' )] = $attribute->attribute( 'data_type_string' );
    foreach ( $map as $preset => $targets )
        foreach ( $targets as $target )
        {
            list( $identifier, $part ) = $target;
            $datatype = isset( $datatypes[$identifier] ) ? $datatypes[$identifier] : '';
            $fits = $part !== '' ? $datatype === 'xrowmetadata' : in_array( $datatype, $writable, true );
            $check( "$classIdentifier: $preset -> $identifier" . ( $part !== '' ? ".$part" : '' ) . " exists and fits", $fits, $datatype ?: 'missing' );
        }
}

// cleaning a part of an xrowmetadata field
$request = new expAiPresetFieldRequest();
$request->part = 'description';
$long = str_repeat( 'Lorem ipsum dolor sit amet. ', 20 );
$max = $request->partMaxLength();
$check( 'description part is cut to MaxDescription', mb_strlen( $request->clean( "<p>$long</p>" ), 'UTF-8' ) <= $max, (string) $max );
$check( 'description part loses quotes and tags', $request->clean( '"<b>A garden</b> for readers"' ) === 'A garden for readers' );
$request->part = 'keywords';
$check( 'keywords part is a comma list', $request->clean( "garden,  library ,,reading\nhour" ) === 'garden, library, reading hour' );

// the summary of the site
$config = expAiConfig::instance();
try
{
    $summary = ( new expAiSummary( $config ) )->collect();
    $check( 'expAiSummary::collect()', true );
}
catch ( Throwable $e )
{
    $summary = array();
    $check( 'expAiSummary::collect()', false, get_class( $e ) . ': ' . $e->getMessage() );
}
foreach ( array( 'switches', 'providers', 'policy', 'limits', 'retention', 'tools', 'roles' ) as $part )
    $check( "summary has $part", isset( $summary[$part] ) );
$check( 'summary: master switch matches [AISettings] Enabled', isset( $summary['switches'] ) && $summary['switches']['master'] === $config->isEnabled() );
$dump = json_encode( $summary );
$secrets = 0;
foreach ( $config->ini()->groups() as $group => $values )
    foreach ( array( 'ApiKey', 'Token' ) as $name )
        if ( isset( $values[$name] ) && is_string( $values[$name] ) && strlen( trim( $values[$name] ) ) >= 8 )
        {
            ++$secrets;
            $check( "summary does not contain [$group] $name", strpos( $dump, trim( $values[$name] ) ) === false );
        }
echo "      ($secrets key(s) or token(s) set in the site's settings)\n";
foreach ( isset( $summary['providers'] ) ? $summary['providers'] : array() as $p )
    $check( "summary: provider {$p['id']} names the key source without the key", $p['key']['set'] ? $p['key']['file'] !== '' : true, $p['key']['set'] ? $p['key']['file'] : 'no key' );

echo ( $failures ? 'FAIL' : 'PASS' ) . " $checks checks, $failures failure(s)\n";
eZScript::instance()->shutdown( $failures ? 1 : 0 );
