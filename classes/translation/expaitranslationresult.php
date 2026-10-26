<?php
/**
 * File containing the expAiTranslationResult class.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * What a translation service answered (expAiTranslationProvider::translate()). A unit whose text could not be
 * used (its placeholders came back wrong) is in $errors, never in $units: a partial result is never written.
 */
class expAiTranslationResult
{
    /** @var array unit id => translated text (in the unit's format: coded, text or html) */
    public $units = array();

    /** @var array unit id => why its text could not be used (English, for the log) */
    public $errors = array();

    /** @var string the model or engine that translated (expai_usage_log.model) */
    public $engine = '';

    /** @var int the characters the service billed (0 when it does not say: then the estimate) */
    public $billedChars = 0;

    /** @var int the cost in millionths of [Pricing] Currency (reported by the service or computed from its price) */
    public $costMicros = 0;

    /** @var bool the cost is known (a price is configured, or the service reported it) */
    public $costKnown = true;

    /** @var int */
    public $httpStatus = 200;

    /** @var int */
    public $latencyMs = 0;

    /** @var int retries of the service's requests (429, 5xx) */
    public $retries = 0;

    /** @var string[] */
    public $warnings = array();

    /** @var string end_turn, or the stop reason of the model (llm) */
    public $stopReason = 'end_turn';

    /** @var array the model's token counts (llm), else empty */
    public $tokens = array();

    /** @return bool every unit has a text */
    public function complete()
    {
        return !$this->errors;
    }

    /**
     * The answer in the shape the translation sync's applier reads (expAiTranslationSync::applyAnswer()).
     * @return array value (units: list of id, text), model, stop_reason
     */
    public function answer()
    {
        $units = array();
        foreach ( $this->units as $id => $text )
            $units[] = array( 'id' => (string) $id, 'text' => (string) $text );
        return array( 'value' => array( 'units' => $units ), 'model' => $this->engine, 'stop_reason' => $this->stopReason );
    }
}
