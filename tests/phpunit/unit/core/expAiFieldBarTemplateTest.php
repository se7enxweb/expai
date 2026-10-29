<?php
/**
 * The bar of field actions drawn with the page (doc/edit-view-integration.md): content.ini [EditSettings]
 * AttributeTemplates[] names content/edit/expai_attribute.tpl, the template draws the same bar as expai.js (classes,
 * the chips with data-action, the form box and the note), disabled until expai.js takes it on, and expai.js takes a
 * drawn bar on instead of adding a second one. No database.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiFieldBarTemplateTest extends expAiCoreTestCase
{
    /** @return string the extension's directory */
    private static function root()
    {
        return dirname( __DIR__, 4 );
    }

    public function testTheHookNamesTheTemplate(): void
    {
        $ini = file_get_contents( self::root() . '/settings/content.ini.append.php' );
        $this->assertMatchesRegularExpression( '/^AttributeTemplates\[\]=content\/edit\/expai_attribute\.tpl$/m', $ini );
        $this->assertFileExists( self::root() . '/design/admin/templates/content/edit/expai_attribute.tpl' );
    }

    public function testTheTemplateDrawsTheBarOfTheScript(): void
    {
        $tpl = file_get_contents( self::root() . '/design/admin/templates/content/edit/expai_attribute.tpl' );
        $js = file_get_contents( self::root() . '/design/admin/javascript/expai.js' );
        foreach ( array( 'exp-ai exp-ai-field-bar', 'exp-ai-field-title', 'exp-ai-mark', 'exp-ai-chip', 'exp-ai-field-form', 'exp-ai-field-note' ) as $class )
        {
            $this->assertStringContainsString( $class, $tpl, "the template draws $class" );
            $this->assertStringContainsString( $class, $js, "expai.js draws $class" );
        }
        $this->assertStringContainsString( 'data-exp-ai-attribute="{$attribute.id}"', $tpl );
        $this->assertStringContainsString( 'disabled="disabled"', $tpl, 'the buttons wait for the script' );
        // the same conditions as the edit form area and the script
        foreach ( array( "fetch( 'expai', 'switched_on' )", "'EditViewSettings', 'Enabled'", "'ToolSettings', 'WritableDatatypes'",
                         "'module', 'expai', 'function', 'write'", "'EditViewSettings', 'FieldActions'", "'Datatypes'" ) as $condition )
            $this->assertStringContainsString( $condition, $tpl, "the template checks $condition" );
        $this->assertStringNotContainsString( 'style=', $tpl, 'no inline style' );
    }

    public function testTheScriptTakesADrawnBarOn(): void
    {
        $js = file_get_contents( self::root() . '/design/admin/javascript/expai.js' );
        $this->assertStringContainsString( ".exp-ai-field-bar[data-exp-ai-attribute=\"", $js );
        $this->assertMatchesRegularExpression( '/chip\.disabled = false/', $js );
    }
}
