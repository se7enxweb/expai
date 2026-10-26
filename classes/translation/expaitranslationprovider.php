<?php
/**
 * File containing the expAiTranslationProvider interface.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A translation service (doc/translation-providers.md): what the translation sync, the field action Translate and
 * the chain step translate use to translate content. expai.ini [TranslationSettings] Provider=<id> (and
 * ProviderFor[] per language pair and purpose) names a [TranslationProvider_<id>] group with Class=<a class
 * implementing this interface>; the group's settings arrive in the constructor.
 *
 * Shipped: llm (expAiTranslationProviderLlm, the model providers of expai, the default), deepl
 * (expAiTranslationProviderDeepl) and libretranslate (expAiTranslationProviderLibreTranslate). A machine
 * translation service extends expAiTranslationProviderBase and implements sendBatch().
 *
 * Units travel in the coded form of the translation sync: XML-escaped text with numbered placeholders <1>...</1>
 * and <2/> (expAiSegmenterRichText). A service converts them to its own markup and back (expAiTranslationMarkup)
 * and checks every placeholder before it returns a text.
 */
interface expAiTranslationProvider
{
    /**
     * @param string $id the part after TranslationProvider_
     * @param array $settings the values of that group
     * @param expAiConfig $config
     */
    public function __construct( $id, array $settings, expAiConfig $config );

    /** @return string the id ('llm', 'deepl'; stored in expai_usage_log.provider) */
    public function id();

    /** @return string the name shown to editors and administrators */
    public function label();

    /** @return string llm | mt */
    public function kind();

    /** @return bool usable: a key is set, the endpoint is valid */
    public function isConfigured();

    /** @return string '' or why the service cannot be used (English, shown when it is not configured) */
    public function unavailableReason();

    /**
     * @param string $source eZ locale of the source (ger-DE)
     * @param string $target eZ locale of the target
     * @param string $purpose patch | full | field
     * @return bool the service translates this pair
     */
    public function supports( $source, $target, $purpose );

    /** @return array patch_semantics, markup (placeholders | html), markup_reliable, glossary, context, queue_request (bool each) */
    public function capabilities();

    /** @return string us | eu | local (expAiProviderRegistry::cleanRegion) */
    public function region();

    /** @return string none | zdr | 30d | unknown */
    public function retention();

    /** @return string the day the terms were checked (YYYY-MM-DD), '' when not */
    public function termsCheckedAt();

    /**
     * The data policy for this service; null when the requests go through the model providers of expai, whose own
     * policy applies (the llm service: lanes and the queue check it as before).
     * @return expAiDataPolicy|null
     */
    public function dataPolicy();

    /**
     * @param expAiTranslationRequest $request
     * @return array micros (the estimated cost in [Pricing] Currency), chars (characters billed), label
     */
    public function estimate( expAiTranslationRequest $request );

    /**
     * @param expAiTranslationRequest $request
     * @return expAiTranslationResult
     * @throws expAiTranslationException|expAiException (the code is the HTTP status; transient() for 429, 5xx and timeouts)
     */
    public function translate( expAiTranslationRequest $request );
}
