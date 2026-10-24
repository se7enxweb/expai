<?php
/**
 * File containing the expAiTranslationRequest class.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * What a translation service translates (expAiTranslationProvider::translate()): the language pair, the purpose,
 * the units, the terms and glossary of the pair, and the context the gate, the usage log and the data policy need.
 *
 * A unit is an array:
 *   id                   the unit's id (u1, u2, ... of the translation sync)
 *   field                the attribute identifier (only the llm service sends it)
 *   kind                 text | block | keywords | html
 *   format               coded (XML-escaped text with numbered placeholders) | text (plain) | html (the editor's HTML)
 *   text                 the source text to translate
 *   old_source           the source the current translation was made from (patches; only the llm service sends it)
 *   current_translation  the translation of old_source (patches; only the llm service sends it)
 *   context_before       the text of the block before (context, not translated)
 *   context_after        the text of the block after
 */
class expAiTranslationRequest
{
    /** @var string eZ locale of the source */
    public $source = '';

    /** @var string eZ locale of the target */
    public $target = '';

    /** @var string patch | full | field */
    public $purpose = 'full';

    /** @var array[] the units */
    public $units = array();

    /** @var array term => translation of this language pair */
    public $glossary = array();

    /** @var string[] terms that are never translated */
    public $doNotTranslate = array();

    /** @var string extra instructions (the llm service only) */
    public $instructions = '';

    /** @var int[] the content objects the units come from (the data policy) */
    public $objectIDs = array();

    /** @var int */
    public $nodeID = 0;

    /** @var int the user the request is made for (0: the current user) */
    public $userID = 0;

    /** @var string the queue job of the request ('' outside the queue) */
    public $jobUUID = '';

    /** @var string the action of the usage log (queue.translate, field:translate) */
    public $action = '';

    /** @var string the siteaccess a queued request came from */
    public $siteAccess = '';

    /**
     * @var array what an earlier attempt translated before a later request failed (expAiTranslationException::$partial):
     *            key, texts; used when the key matches the pieces of this request, so those are not sent and paid again
     */
    public $resume = array();

    /**
     * @param array $unit id, text and the other keys of a unit
     * @return array the unit with every key
     */
    public static function unit( array $unit )
    {
        return $unit + array( 'id' => '', 'field' => '', 'kind' => 'block', 'format' => 'coded', 'text' => '', 'old_source' => '',
                              'current_translation' => '', 'context_before' => '', 'context_after' => '' );
    }

    /**
     * The request of a plan of the translation sync (expAiTranslationSync::plan()): every unit with its text, the
     * old source and current translation of a patch, the neighbouring blocks.
     * @param array $plan source, target, units
     * @param string $mode patch | full
     * @return expAiTranslationRequest
     */
    public static function fromPlan( array $plan, $mode )
    {
        $request = new self();
        $request->source = (string) $plan['source'];
        $request->target = (string) $plan['target'];
        $request->purpose = $mode === 'patch' ? 'patch' : 'full';
        foreach ( $plan['units'] as $unit )
        {
            $patch = $mode === 'patch' && (string) $unit['old'] !== '';
            $request->units[] = self::unit( array(
                'id' => (string) $unit['id'], 'field' => (string) $unit['attribute'], 'kind' => (string) $unit['kind'],
                'format' => $unit['kind'] === 'block' ? 'coded' : 'text', 'text' => (string) $unit['new'],
                'old_source' => $patch ? (string) $unit['old'] : '', 'current_translation' => $patch ? (string) $unit['translation'] : '',
                'context_before' => (string) $unit['before'], 'context_after' => (string) $unit['after'] ) );
        }
        if ( !empty( $plan['object_id'] ) )
            $request->objectIDs = array( (int) $plan['object_id'] );
        return $request;
    }

    /** @return int the characters of the source texts (markup and tags not counted) */
    public function characters()
    {
        $chars = 0;
        foreach ( $this->units as $unit )
            $chars += self::textLength( $unit );
        return $chars;
    }

    /** @return int the characters of one unit's text without placeholders and tags, entities counted once */
    public static function textLength( array $unit )
    {
        $text = (string) $unit['text'];
        if ( $unit['format'] !== 'text' )
            $text = html_entity_decode( (string) preg_replace( '~<[^>]*>~', '', $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return mb_strlen( $text, 'UTF-8' );
    }
}
