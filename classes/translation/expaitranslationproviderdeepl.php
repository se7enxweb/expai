<?php
/**
 * File containing the expAiTranslationProviderDeepl class.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * DeepL (doc/translation-providers.md): POST <Endpoint> (https://api.deepl.com/v2/translate; the Free plan
 * https://api-free.deepl.com/v2/translate) with JSON and the header "Authorization: DeepL-Auth-Key <key>".
 *
 * The placeholders travel as XML (tag_handling=xml, <x i="1">...</x> and <x i="2"/>, never splitting a sentence);
 * terms of [TranslationSettings] DoNotTranslate[] are wrapped in <k> (ignore_tags). The editor's HTML of the field
 * action goes with tag_handling=html. Also sent: source_lang (the base language), target_lang (with the variant DeepL
 * needs), formality ([TranslationProvider_deepl] Formality, unless default), glossary_id (GlossaryId[<source>:<target>]),
 * context (the neighbouring blocks, with SendContext) and show_billed_characters, whose answer prices the request.
 *
 * Errors: 403 (the key), 456 (the quota is used up) and 400 are permanent; 429 and 5xx are retried (retry-after)
 * and then left to the queue.
 */
class expAiTranslationProviderDeepl extends expAiTranslationProviderBase
{
    protected function defaultEndpoint()
    {
        return 'https://api.deepl.com/v2/translate';
    }

    public function capabilities()
    {
        return array( 'patch_semantics' => false, 'markup' => 'placeholders', 'markup_reliable' => true, 'glossary' => true,
                      'context' => true, 'queue_request' => false );
    }

    protected function sendBatch( array $texts, $format, $context, expAiTranslationRequest $request )
    {
        $body = array( 'text' => array_values( $texts ), 'source_lang' => $this->sourceCode( $request->source ),
                       'target_lang' => $this->targetCode( $request->target ), 'show_billed_characters' => true );
        if ( $format === 'html' )
            $body['tag_handling'] = 'html';
        else
        {
            $body['tag_handling'] = 'xml';
            $body['non_splitting_tags'] = array( 'x' );
            if ( $request->doNotTranslate )
                $body['ignore_tags'] = array( expAiTranslationMarkup::IGNORE_TAG );
        }
        $formality = $this->setting( 'Formality', 'default' );
        if ( $formality !== 'default' && in_array( $formality, array( 'more', 'less', 'prefer_more', 'prefer_less' ), true ) )
            $body['formality'] = $formality;
        $glossary = $this->glossaryId( $request->source, $request->target );
        if ( $glossary !== '' )
            $body['glossary_id'] = $glossary;
        if ( $context !== '' )
            $body['context'] = $context;
        list( $status, $answer, $headers, $retries ) = $this->post( $this->endpoint(), array( 'Authorization: DeepL-Auth-Key ' . trim( $this->setting( 'ApiKey' ) ) ),
                                                                    json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
        if ( $status === 456 )
            throw new expAiTranslationException( 'The quota of the translation service %service is used up.', 'deepl: HTTP 456' . self::errorText( $answer ),
                                                 456, array( '%service' => $this->label() ), false );
        if ( $status < 200 || $status >= 300 )
            throw $this->failure( $status, $answer, $headers );
        $data = json_decode( (string) $answer, true );
        if ( !is_array( $data ) || !isset( $data['translations'] ) || !is_array( $data['translations'] ) )
            throw new expAiTranslationException( 'The answer of the translation service %service could not be read.', 'deepl: no translations', $status,
                                                 array( '%service' => $this->label() ), false );
        $out = array();
        $billed = 0;
        $reported = true;
        foreach ( $data['translations'] as $row )
        {
            $out[] = is_array( $row ) && isset( $row['text'] ) ? (string) $row['text'] : '';
            if ( is_array( $row ) && isset( $row['billed_characters'] ) && is_numeric( $row['billed_characters'] ) )
                $billed += (int) $row['billed_characters'];
            else
                $reported = false;
        }
        return array( 'texts' => $out, 'billed' => $reported ? $billed : null, 'status' => $status, 'retries' => $retries,
                      'engine' => 'deepl' . ( !empty( $data['translations'][0]['model_type_used'] ) ? ':' . preg_replace( '/[^a-z0-9_.-]/i', '', (string) $data['translations'][0]['model_type_used'] ) : '' ) );
    }
}
