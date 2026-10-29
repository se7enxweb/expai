<?php
/**
 * File containing the expAiProbeResult class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * One run of bin/php/expaiprobe.php (table expai_probe_result): the model alias, provider and model id, the tool
 * mode, live or mock, the tasks, how many passed, the pass rate in percent and the result per task (JSON: id, ok,
 * reason, ms, the tools called; no content).
 */
class expAiProbeResult extends eZPersistentObject
{
    public static function definition()
    {
        $int = function ( $name ) { return array( 'name' => $name, 'datatype' => 'integer', 'default' => 0, 'required' => true ); };
        $str = function ( $name ) { return array( 'name' => $name, 'datatype' => 'string', 'default' => '', 'required' => true ); };
        return array(
            'fields' => array(
                'id' => $int( 'ID' ),
                'model_alias' => $str( 'ModelAlias' ),
                'provider' => $str( 'Provider' ),
                'model' => $str( 'Model' ),
                'tool_mode' => $str( 'ToolMode' ),
                'probe_mode' => $str( 'ProbeMode' ),
                'tasks' => $int( 'Tasks' ),
                'passed' => $int( 'Passed' ),
                'pass_rate' => $int( 'PassRate' ),
                'details' => $str( 'Details' ),
                'user_id' => $int( 'UserID' ),
                'created' => $int( 'Created' ),
            ),
            'keys' => array( 'id' ),
            'increment_key' => 'id',
            'function_attributes' => array( 'results' => 'results' ),
            'class_name' => 'expAiProbeResult',
            'sort' => array( 'id' => 'desc' ),
            'name' => 'expai_probe_result',
        );
    }

    /** @return array the result per task */
    public function results()
    {
        $list = json_decode( (string) $this->attribute( 'details' ), true );
        return is_array( $list ) ? $list : array();
    }
}
