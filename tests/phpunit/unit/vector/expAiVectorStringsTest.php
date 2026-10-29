<?php
/**
 * expAiVectorStrings: the texts of the command palette and of the shortcut sheet.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

class expAiVectorStringsTest extends expAiTestCase
{
    public function testThePaletteHasEveryTextWithItsPlaceholders(): void
    {
        $palette = expAiVectorStrings::palette();
        foreach ( array( 'title', 'placeholder', 'empty', 'group_commands', 'group_threads', 'group_nodes', 'group_models',
                         'group_fields', 'new_thread_here', 'model_switched', 'field_action', 'press_keys', 'error' ) as $key )
            $this->assertArrayHasKey( $key, $palette );
        foreach ( $palette as $key => $text )
            $this->assertNotSame( '', trim( $text ), "palette text $key is empty" );
        // the script replaces these placeholders: a translation must keep them
        $this->assertStringContainsString( '%name', $palette['new_thread_here'] );
        $this->assertStringContainsString( '%model', $palette['model_switched'] );
        $this->assertStringContainsString( '%action', $palette['field_action'] );
        $this->assertStringContainsString( '%field', $palette['field_action'] );
        $this->assertStringContainsString( '%command', $palette['press_keys'] );
    }

    public function testEveryCommandOfTheKeyBindingsHasALabel(): void
    {
        $commands = expAiVectorStrings::commands();
        $bindings = (array) self::shippedIni()->variable( 'KeyBindings', 'Binding' );
        $this->assertNotEmpty( $bindings );
        foreach ( array_keys( $bindings ) as $command )
            $this->assertArrayHasKey( $command, $commands, "the shipped binding '$command' has no label on the shortcut sheet" );
        foreach ( $commands as $command => $label )
            $this->assertNotSame( '', trim( $label ), "command $command has no label" );
    }
}
