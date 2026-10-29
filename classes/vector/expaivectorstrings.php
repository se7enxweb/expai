<?php
/**
 * File containing the expAiVectorStrings class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The texts of the command palette and the shortcut sheet (context extension/expai/keyboard). The palette is loaded
 * on any admin page on the first Ctrl/Cmd+K, so its texts come with expaivector::palette instead of a template.
 */
class expAiVectorStrings
{
    /** @return array key => translated text */
    public static function palette()
    {
        $t = function ( $text ) { return ezpI18n::tr( 'extension/expai/keyboard', $text ); };
        return array(
            'title' => $t( 'Command palette' ),
            'placeholder' => $t( 'Type a command, a thread or a node...' ),
            'empty' => $t( 'Nothing found.' ),
            'searching' => $t( 'Searching...' ),
            'results_1' => $t( '%count result' ),
            'results_n' => $t( '%count results' ),
            'group_commands' => $t( 'Commands' ),
            'group_threads' => $t( 'Threads' ),
            'group_nodes' => $t( 'Nodes' ),
            'group_models' => $t( 'Models' ),
            'group_fields' => $t( 'Field actions' ),
            'open_vector' => $t( 'Open Vector' ),
            'new_thread' => $t( 'New thread' ),
            'new_thread_here' => $t( 'New thread here (%name)' ),
            'open_thread' => $t( 'Open thread' ),
            'jump_node' => $t( 'Jump to node' ),
            'switch_model' => $t( 'Switch model' ),
            'model_current' => $t( 'current' ),
            'model_switched' => $t( 'Model switched to %model.' ),
            'shortcuts' => $t( 'Keyboard shortcuts' ),
            'hint' => $t( 'Up and down to choose, Enter to run, Esc to close' ),
            'field_action' => $t( '%action: %field' ),
            'close' => $t( 'Close' ),
            'changed' => $t( 'changed' ),
            'change' => $t( 'Change' ),
            'reset' => $t( 'Reset' ),
            'reset_all' => $t( 'Reset all shortcuts' ),
            'press_keys' => $t( 'Press the new keys for "%command" (Esc cancels)' ),
            'error' => $t( 'Something went wrong:' ),
        );
    }

    /** @return array command => translated label (the shortcut sheet and the palette) */
    public static function commands()
    {
        $t = function ( $text ) { return ezpI18n::tr( 'extension/expai/keyboard', $text ); };
        return array(
            'palette' => $t( 'Open the command palette' ),
            'run' => $t( 'Run / send the message' ),
            'queue' => $t( 'Queue the message while a turn runs' ),
            'steer' => $t( 'Steer the running turn with a note' ),
            'stop' => $t( 'Stop the thread (press twice)' ),
            'next' => $t( 'Next item' ),
            'previous' => $t( 'Previous item' ),
            'accept' => $t( 'Accept the change' ),
            'reject' => $t( 'Reject the change' ),
            'accept_field' => $t( 'Accept the whole field' ),
            'reject_field' => $t( 'Reject the whole field' ),
            'edit_hunk' => $t( 'Edit the proposed text' ),
            'thread_next' => $t( 'Next thread' ),
            'thread_previous' => $t( 'Previous thread' ),
            'new_thread' => $t( 'New thread' ),
            'mode' => $t( 'Switch between plan and write' ),
            'model' => $t( 'Choose the model' ),
            'lane' => $t( 'Choose the lane' ),
            'scope' => $t( 'Change the scope' ),
            'tab_plan' => $t( 'Show the plan' ),
            'tab_diff' => $t( 'Show the changes' ),
            'tab_cost' => $t( 'Show the cost' ),
            'help' => $t( 'Show the shortcuts' ),
        );
    }
}
