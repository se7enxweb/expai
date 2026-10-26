<?php
/**
 * File containing the expAiTranslationProviderLibreTranslate class.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * LibreTranslate (doc/translation-providers.md), usually on the site's own server (Region=local: it meets every
 * region and retention rule of the data policy): POST <Endpoint> (http://127.0.0.1:5000/translate) with JSON q (the
 * texts), source, target (lower-case base codes), format html and api_key when one is set.
 *
 * The placeholders travel as HTML (<span data-x="1">...</span>, <br data-x="2">); terms of [TranslationSettings]
 * DoNotTranslate[] are wrapped in <span translate="no">. LibreTranslate's HTML handling moves or drops tags more
 * often than DeepL: a text whose placeholders come back wrong is refused (capability markup_reliable false), so route
 * rich text to another service with [TranslationSettings] ProviderFor[] where that happens often.
 */
class expAiTranslationProviderLibreTranslate extends expAiTranslationProviderBase
{
    protected function defaultEndpoint()
    {
        return 'http://127.0.0.1:5000/translate';
    }

    /** a local server usually runs without a key (ApiKeyRequired=enabled when it asks for one) */
    protected function keyRequired()
    {
        return $this->flag( 'ApiKeyRequired', false );
    }

    protected function markupStyle()
    {
        return expAiTranslationMarkup::STYLE_HTML;
    }

    public function capabilities()
    {
        return array( 'patch_semantics' => false, 'markup' => 'placeholders', 'markup_reliable' => false, 'glossary' => false,
                      'context' => false, 'queue_request' => false );
    }

    protected function sendBatch( array $texts, $format, $context, expAiTranslationRequest $request )
    {
        $body = array( 'q' => array_values( $texts ), 'source' => $this->sourceCode( $request->source ), 'target' => $this->targetCode( $request->target ),
                       'format' => 'html' );
        if ( trim( $this->setting( 'ApiKey' ) ) !== '' )
            $body['api_key'] = trim( $this->setting( 'ApiKey' ) );
        list( $status, $answer, $headers, $retries ) = $this->post( $this->endpoint(), array(), json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
        if ( $status < 200 || $status >= 300 )
            throw $this->failure( $status, $answer, $headers );
        $data = json_decode( (string) $answer, true );
        $out = is_array( $data ) && isset( $data['translatedText'] ) ? $data['translatedText'] : null;
        if ( is_string( $out ) && count( $texts ) === 1 )
            $out = array( $out );
        if ( !is_array( $out ) )
            throw new expAiTranslationException( 'The answer of the translation service %service could not be read.', 'libretranslate: no translatedText', $status,
                                                 array( '%service' => $this->label() ), false );
        // LibreTranslate does not bill: the characters sent are what PricePerMChars prices (usually 0)
        return array( 'texts' => array_map( 'strval', array_values( $out ) ), 'billed' => null, 'status' => $status, 'retries' => $retries, 'engine' => 'libretranslate' );
    }
}
