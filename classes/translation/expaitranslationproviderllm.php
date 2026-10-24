<?php
/**
 * File containing the expAiTranslationProviderLlm class.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The translation service llm: the model providers of expai, as the translation sync used them before translation
 * services existed (the default, [TranslationSettings] Provider=llm). The model stays [TranslationSync] Model (empty:
 * the site default), the extra instructions [TranslationSync] Instructions, the lanes PatchLane and FullLane.
 *
 * The translation sync queues its request as before (expAiQueue::request(), job expai.request, the applier
 * translation_sync), so batch and flex lanes still work (capability queue_request); planRequest() builds that request
 * byte for byte as the sync built it. translate() sends the same request at once through expAiUsageRecorder, for
 * callers that need the answer now. The field action Translate keeps its own prompt (expAiPromptFieldAction).
 *
 * The data policy of the model's provider applies where the request is sent (the lane choice of the queue, the
 * request handler, translate()), so dataPolicy() is null.
 */
class expAiTranslationProviderLlm implements expAiTranslationProvider
{
    /** @var string */
    protected $id;

    /** @var array */
    protected $settings;

    /** @var expAiConfig */
    protected $config;

    /** @var expAiUsageLog|null the usage row of the last translate() */
    public $last = null;

    /** @var expAiProvider|null tests: the provider translate() sends to (default: the provider of the alias) */
    public $provider = null;

    public function __construct( $id, array $settings, expAiConfig $config )
    {
        $this->id = (string) $id !== '' ? (string) $id : 'llm';
        $this->settings = $settings;
        $this->config = $config;
    }

    public function id()
    {
        return $this->id;
    }

    public function label()
    {
        return isset( $this->settings['Label'] ) && is_string( $this->settings['Label'] ) && $this->settings['Label'] !== ''
             ? $this->settings['Label'] : 'Exponential Intelligence model';
    }

    public function kind()
    {
        return 'llm';
    }

    public function isConfigured()
    {
        return $this->unavailableReason() === '';
    }

    public function unavailableReason()
    {
        $registry = $this->config->registry();
        return $registry->getModel( $this->alias() ) ? '' : 'no model of the provider registry can translate ([TranslationSync] Model, [ProviderSettings] DefaultModel)';
    }

    public function supports( $source, $target, $purpose )
    {
        return (string) $source !== (string) $target;
    }

    public function capabilities()
    {
        return array( 'patch_semantics' => true, 'markup' => 'placeholders', 'markup_reliable' => true, 'glossary' => true,
                      'context' => true, 'queue_request' => true );
    }

    /** @return array|null the settings of the model's provider */
    protected function modelProvider()
    {
        $model = $this->config->registry()->getModel( $this->alias() );
        return $model ? $this->config->registry()->getProvider( $model['provider'] ) : null;
    }

    public function region()
    {
        $provider = $this->modelProvider();
        return expAiProviderRegistry::cleanRegion( $provider ? $provider['Region'] : '' );
    }

    public function retention()
    {
        $provider = $this->modelProvider();
        return expAiProviderRegistry::cleanRetention( $provider ? $provider['Retention'] : '' );
    }

    public function termsCheckedAt()
    {
        $provider = $this->modelProvider();
        return $provider && isset( $provider['TermsCheckedAt'] ) ? (string) $provider['TermsCheckedAt'] : '';
    }

    public function dataPolicy()
    {
        return null;
    }

    /** @return string the model alias of the sync ([TranslationSync] Model, else the site default) */
    public function alias()
    {
        $registry = $this->config->registry();
        $alias = $registry->resolveAlias( (string) $this->config->get( 'TranslationSync', 'Model', '' ) );
        return $alias !== '' && $registry->getModel( $alias ) ? $alias : $registry->defaultAlias();
    }

    /** @return string the name of a language for the prompt: "German (ger-DE)", or the code when it is not a content language */
    public static function languageName( $code )
    {
        $language = eZContentLanguage::fetchByLocale( (string) $code );
        return $language ? $language->attribute( 'name' ) . ' (' . $code . ')' : (string) $code;
    }

    /**
     * The request of a plan of the translation sync: system, one user message (JSON), output_schema, max_tokens.
     * @param array $plan source, target, units (expAiTranslationSync::plan())
     * @param string $mode patch | full
     * @param string[]|null $terms terms never translated (null: [TranslationSettings] DoNotTranslate[])
     * @param array|null $glossary term => translation (null: [TranslationSettings] Glossary[] of the pair)
     * @return array
     */
    public function planRequest( array $plan, $mode, $terms = null, $glossary = null )
    {
        $units = array();
        foreach ( $plan['units'] as $unit )
        {
            $row = array( 'id' => $unit['id'], 'field' => $unit['attribute'] );
            if ( $unit['kind'] === 'keywords' )
                $row['type'] = 'keywords (a comma-separated list)';
            if ( $mode === 'patch' && $unit['old'] !== '' )
            {
                $row['old_source'] = $unit['old'];
                $row['new_source'] = $unit['new'];
                $row['current_translation'] = $unit['translation'];
            }
            else
                $row['source'] = $unit['new'];
            if ( $unit['before'] !== '' )
                $row['context_before'] = expAiText::cut( $unit['before'], 300 );
            if ( $unit['after'] !== '' )
                $row['context_after'] = expAiText::cut( $unit['after'], 300 );
            $units[] = $row;
        }
        $from = self::languageName( $plan['source'] );
        $to = self::languageName( $plan['target'] );
        $system = $mode === 'patch'
            ? "You update existing translations of website content from $from into $to. The source text of some blocks changed. "
              . 'For a block with old_source, new_source and current_translation, change current_translation only where the source changed and keep '
              . 'its wording, terms and style everywhere else. For a block with only source, translate it in the style of the surrounding translation.'
            : "You translate website content from $from into $to. Translate every block completely, naturally and consistently.";
        $system .= ' Markup is written as numbered placeholders: <1>text</1> wraps text in a link or emphasis, <2/> is an embedded object or a line break. '
                 . 'Keep every placeholder exactly once, with the same numbers, around the matching words; never add new ones. '
                 . 'Escape < and & in text as &lt; and &amp;. Do not translate names of people, products or brands unless the source does. '
                 . 'Answer with JSON only: {"units": [{"id": "...", "text": "..."}]}, one entry per block, the text in ' . $plan['target'] . '.';
        $extra = trim( (string) $this->config->get( 'TranslationSync', 'Instructions', '' ) );
        if ( $extra !== '' )
            $system .= "\n\n" . $extra;
        // the terms and the glossary of [TranslationSettings]: only when set, so that the request stays as it was without them
        $registry = new expAiTranslationProviderRegistry( $this->config );
        $terms = $terms === null ? $registry->terms() : (array) $terms;
        if ( $terms )
            $system .= "\n\nNever translate these terms; keep them exactly as written: " . implode( ', ', $terms );
        $glossary = $glossary === null ? $registry->glossary( $plan['source'], $plan['target'] ) : (array) $glossary;
        if ( $glossary )
        {
            $lines = array();
            foreach ( $glossary as $term => $translation )
                $lines[] = '- ' . $term . ' => ' . $translation;
            $system .= "\n\nUse these translations of terms:\n" . implode( "\n", $lines );
        }
        $content = json_encode( array( 'source_language' => $plan['source'], 'target_language' => $plan['target'], 'units' => $units ),
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        $chars = 0;
        foreach ( $plan['units'] as $unit )
            $chars += mb_strlen( $unit['new'], 'UTF-8' );
        $perToken = max( 1.0, (float) $this->config->get( 'Budgets', 'CharsPerToken', 3.5 ) );
        $maxTokens = (int) min( $this->config->int( 'TranslationSync', 'MaxOutputTokens', 8000, 256, 64000 ), max( 1024, ceil( $chars * 3 / $perToken ) + 48 * count( $units ) + 200 ) );
        return array( 'system' => $system, 'messages' => array( array( 'role' => 'user', 'content' => $content ) ), 'max_tokens' => $maxTokens,
                      'output_schema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'units' ),
                          'properties' => array( 'units' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => false,
                              'required' => array( 'id', 'text' ), 'properties' => array( 'id' => array( 'type' => 'string' ), 'text' => array( 'type' => 'string' ) ) ) ) ) ),
                      'est_output' => (int) ceil( $chars * 1.2 / $perToken ) + 10 * count( $units ) );
    }

    /**
     * The plan form of a translation request (the units as the sync's plan has them), for planRequest().
     * @return array source, target, units
     */
    public static function planOf( expAiTranslationRequest $request )
    {
        $units = array();
        foreach ( $request->units as $unit )
        {
            $unit = expAiTranslationRequest::unit( $unit );
            $units[] = array( 'id' => $unit['id'], 'attribute' => $unit['field'], 'kind' => $unit['kind'], 'old' => $unit['old_source'],
                              'new' => $unit['text'], 'translation' => $unit['current_translation'],
                              'before' => $unit['context_before'], 'after' => $unit['context_after'] );
        }
        return array( 'source' => $request->source, 'target' => $request->target, 'units' => $units );
    }

    /** @return array the model request of a translation request (planRequest() of its plan form) */
    public function buildRequest( expAiTranslationRequest $request )
    {
        return $this->planRequest( self::planOf( $request ), $request->purpose === 'patch' ? 'patch' : 'full',
                                   $request->doNotTranslate ?: null, $request->glossary ?: null );
    }

    /**
     * The estimated cost of a request on the lane the queue would choose.
     * @return array micros, lane, alias
     */
    public function planEstimate( array $request, $mode )
    {
        $alias = $this->alias();
        $budget = new expAiBudget( $this->config );
        $lane = null;
        try
        {
            $choice = ( new expAiLanePolicy( $this->config ) )->choose( $alias, array(
                'purpose' => $mode === 'full' ? 'translate_full' : 'translate', 'class' => $mode === 'full' ? 'translation_full' : 'translation_patch',
                'lane' => (string) $this->config->get( 'TranslationSync', $mode === 'full' ? 'FullLane' : 'PatchLane', 'auto' ) ) );
            $lane = $choice['lane'];
        }
        catch ( expAiException $e )
        {
            $lane = null;
        }
        $estimate = $budget->estimate( $alias, $budget->estimateInputTokens( $request ), isset( $request['est_output'] ) ? (int) $request['est_output'] : 0, $lane );
        return array( 'micros' => (int) $estimate['micros'], 'lane' => $lane ? $lane->name() : 'standard', 'alias' => $alias );
    }

    public function estimate( expAiTranslationRequest $request )
    {
        $mode = $request->purpose === 'patch' ? 'patch' : 'full';
        $estimate = $this->planEstimate( $this->buildRequest( $request ), $mode );
        return array( 'micros' => $estimate['micros'], 'chars' => $request->characters(), 'known' => true, 'label' => $this->label(),
                      'lane' => $estimate['lane'], 'alias' => $estimate['alias'] );
    }

    public function translate( expAiTranslationRequest $request )
    {
        $alias = $this->alias();
        $provider = $this->provider ?: $this->config->registry()->providerFor( $alias );
        $policy = expAiDataPolicy::forProvider( $this->config, $provider->providerId() );
        foreach ( $request->objectIDs as $objectID )
        {
            $object = expAiObjects::fetch( (int) $objectID );
            if ( $object instanceof eZContentObject )
                $policy->assertObject( $object );
        }
        $llmRequest = $this->buildRequest( $request );
        unset( $llmRequest['est_output'] );
        $recorder = new expAiUsageRecorder( $provider, array( 'action' => $request->action !== '' ? $request->action : 'translate', 'node_id' => (int) $request->nodeID )
            + ( $request->userID ? array( 'user_id' => (int) $request->userID ) : array() ) + ( $request->siteAccess !== '' ? array( 'siteaccess' => $request->siteAccess ) : array() ) );
        $start = microtime( true );
        try
        {
            $answer = $recorder->send( $llmRequest );
        }
        finally
        {
            $this->last = $recorder->last;
        }
        $result = new expAiTranslationResult();
        $result->engine = isset( $answer['model'] ) ? (string) $answer['model'] : '';
        $result->stopReason = isset( $answer['stop_reason'] ) ? (string) $answer['stop_reason'] : '';
        $result->tokens = isset( $answer['tokens'] ) ? (array) $answer['tokens'] : array();
        $result->httpStatus = isset( $answer['http_status'] ) ? (int) $answer['http_status'] : 200;
        $result->retries = isset( $answer['retries'] ) ? (int) $answer['retries'] : 0;
        $cost = $this->last ? $this->last->cost() : null;
        $result->costMicros = $cost !== null ? expAiBudget::micros( $cost ) : 0;
        $result->costKnown = $cost !== null;
        $result->billedChars = $request->characters();
        $result->latencyMs = (int) round( ( microtime( true ) - $start ) * 1000 );
        $text = '';
        foreach ( isset( $answer['content'] ) ? (array) $answer['content'] : array() as $block )
            if ( is_array( $block ) && isset( $block['type'], $block['text'] ) && $block['type'] === 'text' )
                $text .= $block['text'];
        $value = $result->stopReason === 'max_tokens' ? null : expAiText::extractJson( expAiText::stripReasoning( $text ) );
        $texts = array();
        foreach ( is_array( $value ) && isset( $value['units'] ) ? (array) $value['units'] : array() as $unit )
            if ( isset( $unit['id'], $unit['text'] ) )
                $texts[(string) $unit['id']] = (string) $unit['text'];
        foreach ( $request->units as $unit )
        {
            $id = (string) $unit['id'];
            if ( isset( $texts[$id] ) )
                $result->units[$id] = $texts[$id];
            else
                $result->errors[$id] = $result->stopReason === 'max_tokens' ? 'the answer was cut off at max_tokens' : 'the answer has no text for this unit';
        }
        return $result;
    }
}
