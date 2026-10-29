<?php
/**
 * The structure detector of the edit form (design/admin/javascript/expai_structure.js, doc/edit-view-integration.md
 * "Structure is never lost silently"): it is loaded before expai.js, every string the review shows is in the edit
 * form and in the translations, its node test passes, and what the server's cleaning removes is what it counts.
 * The tests that need node are skipped without it. No database.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiStructureScriptTest extends expAiCoreTestCase
{
    /** @return string the extension's directory */
    private static function root()
    {
        return dirname( __DIR__, 4 );
    }

    /** @return string the path of node, or '' */
    private static function node()
    {
        $path = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
        return $path !== '' && is_executable( $path ) ? $path : '';
    }

    /** @return array the kinds the detector counts (its KINDS) */
    private static function kinds()
    {
        $script = file_get_contents( self::root() . '/design/admin/javascript/expai_structure.js' );
        preg_match( "/var KINDS = \[([^\]]+)\]/", $script, $m );
        preg_match_all( "/'([a-z]+)'/", $m[1], $k );
        return $k[1];
    }

    public function testTheDetectorIsLoadedBeforeExpaiJs(): void
    {
        $ini = file_get_contents( self::root() . '/settings/design.ini.append.php' );
        preg_match_all( '/^BackendJavaScriptList\[\]=(\S+)$/m', $ini, $m );
        $structure = array_search( 'expai_structure.js', $m[1], true );
        $this->assertNotFalse( $structure, 'expai_structure.js is in BackendJavaScriptList[]' );
        $this->assertLessThan( array_search( 'expai.js', $m[1], true ), $structure );
    }

    public function testEveryStringOfTheReviewIsInTheEditFormAndTranslated(): void
    {
        $this->assertSame( array( 'embed', 'table', 'cell', 'th', 'merge', 'custom', 'anchor', 'literal', 'link', 'otherlink', 'heading', 'attribute' ), self::kinds() );
        $template = file_get_contents( self::root() . '/design/admin/templates/content/edit/expai.tpl' );
        preg_match_all( "/'([a-z0-9_]+)', '((?:[^'\\\\]|\\\\.)*)'\|i18n\( 'extension\/expai\/edit' \)/", $template, $m );
        $strings = array_combine( $m[1], $m[2] );

        $keys = array( 'review_title', 'review_note', 'review_changed', 'review_lost', 'review_count', 'review_truncated', 'review_unchecked', 'apply_anyway',
                       'keep_original', 'copy_text', 'copied', 'copy_failed', 'kept' );
        foreach ( self::kinds() as $kind )
        {
            $keys[] = 'lost_' . $kind . '_1';
            $keys[] = 'lost_' . $kind . '_n';
        }
        // the keys the scripts read
        $scripts = file_get_contents( self::root() . '/design/admin/javascript/expai.js' )
                 . file_get_contents( self::root() . '/design/admin/javascript/expai_presets.js' );
        foreach ( array( 'review_title', 'review_note', 'review_changed', 'review_lost', 'review_count', 'review_truncated', 'review_unchecked', 'apply_anyway', 'keep_original', 'copy_text', 'copied', 'copy_failed', 'kept' ) as $key )
            $this->assertMatchesRegularExpression( '/\b[SR]\.' . $key . '\b/', $scripts, "a script reads $key" );

        foreach ( glob( self::root() . '/translations/*/translation.ts' ) as $file )
        {
            $doc = new DOMDocument();
            $doc->load( $file );
            $xpath = new DOMXPath( $doc );
            foreach ( $keys as $key )
            {
                $this->assertArrayHasKey( $key, $strings, "the edit form passes $key" );
                $source = $strings[$key];
                $found = $xpath->query( "/TS/context[name='extension/expai/edit']/message[source=" . self::literal( $source ) . "]" );
                $this->assertSame( 1, $found->length, basename( dirname( $file ) ) . ": $source" );
                if ( basename( dirname( $file ) ) !== 'untranslated' )
                    $this->assertNotSame( '', trim( $found->item( 0 )->getElementsByTagName( 'translation' )->item( 0 )->textContent ), $source );
            }
        }
    }

    public function testTheNodeTestPasses(): void
    {
        $node = self::node();
        if ( $node === '' )
            $this->markTestSkipped( 'node is not installed' );
        exec( escapeshellarg( $node ) . ' --test ' . escapeshellarg( self::root() . '/tests/js/expai_structure.test.mjs' ) . ' 2>&1', $output, $status );
        $this->assertSame( 0, $status, implode( "\n", $output ) );
    }

    public function testWhatTheCleaningRemovesIsCounted(): void
    {
        $node = self::node();
        if ( $node === '' )
            $this->markTestSkipped( 'node is not installed' );
        $field = '<p class="lead">Intro <a href="ezobject://12">obj</a> <a href="https://example.invalid/" target="_blank" title="Ext">ext</a></p>'
               . '<p><img id="eZObject_57" src="/a.jpg" alt="medium" /></p>'
               . '<table border="1"><tbody><tr><th>head</th><th>head</th></tr><tr><td colspan="2">cell</td></tr></tbody></table>'
               . '<div class="ezoeItemCustomTag factbox" type="custom"><p>box</p></div>'
               . '<h2><a name="top" class="mceItemAnchor"></a>Top</h2><pre>code</pre><literal><![CDATA[<table>]]></literal>';
        $answer = expAiText::sanitizeHtml( $field );
        $script = 'const S = require( ' . json_encode( self::root() . '/design/admin/javascript/expai_structure.js' ) . ' );'
                . 'process.stdout.write( JSON.stringify( S.loss( process.argv[1], process.argv[2] ) ) );';
        $out = shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $field ) . ' ' . escapeshellarg( $answer ) );
        $loss = array();
        foreach ( json_decode( (string) $out, true ) ?: array() as $l )
            $loss[$l['kind']] = $l['lost'];
        $this->assertSame( array( 'embed' => 1, 'table' => 1, 'cell' => 3, 'th' => 2, 'merge' => 1, 'custom' => 1, 'anchor' => 1, 'literal' => 2, 'link' => 1, 'attribute' => 4 ), $loss,
                           'the cleaned answer: ' . $answer );
    }

    /** What the cleaning removed without the detector saying so: #fragment, tel:, ftp:, www. links, h1/h5/h6, style */
    public function testTheGapsOfTheCleaningAreCounted(): void
    {
        $node = self::node();
        if ( $node === '' )
            $this->markTestSkipped( 'node is not installed' );
        $field = '<h1>One</h1><h5>Five</h5><h6>Six</h6><h2>Two</h2>'
               . '<p style="text-align:center">Text <a href="#top">jump</a> <a href="tel:+491234">call</a> <a href="ftp://x.example/f">ftp</a>'
               . ' <a href="www.example.de">www</a> <a href="https://example.com/">ok</a> <a href="mailto:a@b.c">mail</a></p>';
        $answer = expAiText::sanitizeHtml( $field );
        $script = 'const S = require( ' . json_encode( self::root() . '/design/admin/javascript/expai_structure.js' ) . ' );'
                . 'process.stdout.write( JSON.stringify( S.loss( process.argv[1], process.argv[2] ) ) );';
        $out = shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $field ) . ' ' . escapeshellarg( $answer ) );
        $loss = array();
        foreach ( json_decode( (string) $out, true ) ?: array() as $l )
            $loss[$l['kind']] = $l['lost'];
        $this->assertSame( array( 'otherlink' => 4, 'heading' => 3, 'attribute' => 1 ), $loss, 'the cleaned answer: ' . $answer );
    }

    public function testTheLengthLimitIsOneSettingForServerAndEditForm(): void
    {
        $ini = file_get_contents( self::root() . '/settings/expai.ini.append.php' );
        $this->assertMatchesRegularExpression( '/^MaxFieldLength=20000$/m', $ini );
        $this->assertMatchesRegularExpression( '/^HideActionsWithEditorAI=true$/m', $ini );
        $template = file_get_contents( self::root() . '/design/admin/templates/content/edit/expai.tpl' );
        $this->assertStringContainsString( "'MaxFieldLength', 'expai.ini'", $template );
        $this->assertStringContainsString( "'HideActionsWithEditorAI', 'expai.ini'", $template );
        $action = file_get_contents( self::root() . '/classes/fieldactions/expaipromptfieldaction.php' );
        $this->assertStringContainsString( 'self::maxValueLength( $this->config )', $action );
        $this->assertStringNotContainsString( '(string) $request->value, 20000', $action );
    }

    /** The server and the edit form clamp MaxFieldLength to the same numbers: a value only one side clamps cuts a field silently */
    public function testServerAndEditFormClampTheLengthLimitAlike(): void
    {
        $node = self::node();
        $script = file_get_contents( self::root() . '/design/admin/javascript/expai.js' );
        $this->assertSame( 1, preg_match( '/function fieldLengthLimit\( raw \) \{.*?\n    \}/s', $script, $m ), 'expai.js has fieldLengthLimit()' );
        foreach ( array( '500', '1000', '20000', '200000', '300000', 'abc', '' ) as $raw )
        {
            $server = expAiPromptFieldAction::maxValueLength( $this->useSettings( array( 'EditViewSettings' => array( 'MaxFieldLength' => $raw ) ) ) );
            $this->assertSame( array( 'abc' => 20000, '' => 20000, '500' => 1000, '300000' => 200000 )[$raw] ?? (int) $raw, $server, 'server, ' . var_export( $raw, true ) );
            if ( $node === '' )
                continue;
            $form = shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $m[0] . ' process.stdout.write( String( fieldLengthLimit( process.argv[1] ) ) );' ) . ' ' . escapeshellarg( $raw ) );
            $this->assertSame( (string) $server, $form, 'edit form, ' . var_export( $raw, true ) );
        }
    }

    /** @return string an XPath string literal of $text */
    private static function literal( $text )
    {
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        if ( strpos( $text, "'" ) === false )
            return "'" . $text . "'";
        return 'concat(' . implode( ", \"'\", ", array_map( function ( $p ) { return "'" . $p . "'"; }, explode( "'", $text ) ) ) . ')';
    }
}
