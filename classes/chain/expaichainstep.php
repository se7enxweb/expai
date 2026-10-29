<?php
/**
 * File containing the expAiChainStep class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * One step of a chain run (table expai_chain_step): each call to a model is one row with its queue job, model, lane,
 * tokens, cost and the usage row; a checkpoint and a hand-off are rows without a job.
 *
 * state:   queued (its job waits or runs), done, skipped, waiting (the human checkpoint), failed
 * verdict: pass, fail or '' (steps without criteria)
 * score:   the reviewer's score 0..100, -1 when the step has none
 * output:  JSON (capped by [ChainSettings] MaxStoredOutputBytes): the step's structured answer
 */
class expAiChainStep extends eZPersistentObject
{
    public static function definition()
    {
        $int = function ( $name, $default = 0 ) { return array( 'name' => $name, 'datatype' => 'integer', 'default' => $default, 'required' => true ); };
        $str = function ( $name, $default = '' ) { return array( 'name' => $name, 'datatype' => 'string', 'default' => $default, 'required' => true ); };
        return array(
            'fields' => array(
                'id' => $int( 'ID' ),
                'run_id' => $int( 'RunID' ),
                'step' => $str( 'Step' ),
                'step_type' => $str( 'StepType' ),
                'iteration' => $int( 'Iteration' ),
                'language' => $str( 'Language' ),
                'job_uuid' => $str( 'JobUUID' ),
                'model' => $str( 'Model' ),
                'provider' => $str( 'Provider' ),
                'lane' => $str( 'Lane' ),
                'service_tier' => $str( 'ServiceTier' ),
                'state' => $str( 'State', 'queued' ),
                'verdict' => $str( 'Verdict' ),
                'score' => $int( 'Score', -1 ),
                'issue_count' => $int( 'IssueCount' ),
                'input_tokens' => $int( 'InputTokens' ),
                'output_tokens' => $int( 'OutputTokens' ),
                'cost_micros' => $int( 'CostMicros' ),
                'usage_log_id' => $int( 'UsageLogID' ),
                'input_hash' => $str( 'InputHash' ),
                'output' => $str( 'Output' ),
                'note' => $str( 'Note' ),
                'created' => $int( 'Created' ),
                'finished' => $int( 'Finished' ),
            ),
            'keys' => array( 'id' ),
            'increment_key' => 'id',
            'function_attributes' => array( 'output_array' => 'outputArray', 'cost' => 'costMoney', 'job_state' => 'jobState' ),
            'class_name' => 'expAiChainStep',
            'sort' => array( 'id' => 'asc' ),
            'name' => 'expai_chain_step',
        );
    }

    /** @return expAiChainStep|null the step of a queue job */
    public static function fetchByJob( $jobUuid )
    {
        if ( (string) $jobUuid === '' )
            return null;
        $row = eZPersistentObject::fetchObject( self::definition(), null, array( 'job_uuid' => (string) $jobUuid ) );
        return $row instanceof self ? $row : null;
    }

    /** @return array */
    public function outputArray()
    {
        $out = json_decode( (string) $this->attribute( 'output' ), true );
        return is_array( $out ) ? $out : array();
    }

    /** Stores the output as JSON, cut to [ChainSettings] MaxStoredOutputBytes (then only the keys that fit). */
    public function setOutput( array $output )
    {
        $max = expAiConfig::instance()->int( 'ChainSettings', 'MaxStoredOutputBytes', 200000, 2000 );
        $json = json_encode( $output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( strlen( $json ) > $max )
            $json = json_encode( array( 'cut' => true, 'text' => expAiText::cut( $json, (int) ( $max / 2 ) ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $this->setAttribute( 'output', $json );
    }

    /** @return string */
    public function costMoney()
    {
        return expAiBudget::money( (int) $this->attribute( 'cost_micros' ) );
    }

    /** @return string the state of the step's queue job ('' without one) */
    public function jobState()
    {
        $uuid = (string) $this->attribute( 'job_uuid' );
        if ( $uuid === '' || !expAiQueue::available() )
            return '';
        $job = expQueue::fetch( $uuid );
        return $job ? (string) $job->attribute( 'state' ) : '';
    }
}
