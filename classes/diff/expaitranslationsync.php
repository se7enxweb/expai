<?php
/**
 * File containing the expAiTranslationSync class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Keeps translations in step with their source language (doc/translation-sync.md).
 *
 * After a version in a source language is published (the workflow event expaitranslationsync, the CLI or the
 * Intelligence tab), every other translation of the object gets a decision:
 *
 *   noop   nothing translatable changed since the translation's base: the base moves forward
 *   patch  only the changed blocks are sent (old source, new source, the existing translation of the block); the
 *          answer replaces those blocks, everything else of the translation stays as it is
 *   full   the whole object is translated again
 *
 * Thresholds are in expai.ini [TranslationSync]. Owner rule: a patch below PatchMaxChangedRatio, within
 * PatchMaxSegments and estimated at or below CostThreshold is queued at once (job class translation_patch); a full
 * translation, a patch between the thresholds or above the cost waits for an editor (Approve in the Intelligence
 * tab). Results are drafts of the target language, never published, logged in expai_draft_log with source
 * "translation" (the "Exponential Intelligence" label).
 *
 * Who translates is a translation service (doc/translation-providers.md, expAiTranslationProviderRegistry): llm, the
 * model providers of expai (the default; the request is queued as an expai.request job as before), or a machine
 * translation service such as DeepL (a job expai.translation.run, expAiTranslationRunHandler), chosen per language
 * pair and purpose by [TranslationSettings]. Without a usable service the state is no_provider (or blocked, when the
 * data policy keeps the content away from every service that could take it): nothing is queued.
 *
 * The base of a translation: the newest of (a) the last version whose initial language is the target language (the
 * source text in that version is what the translator saw), (b) the source version of a draft this sync wrote and
 * that was published, (c) a base set by "Skip" or a no-op. State per object and language is kept in ezsite_data
 * (expai_tsync:<object id>:<language>), so no table of its own is needed.
 */
class expAiTranslationSync
{
    const APPLIER = 'translation_sync';
    const CHECK_JOB = 'expai.translation.check';
    const RUN_JOB = 'expai.translation.run';

    /** @var expAiConfig */
    protected $config;

    /** @var expAiTranslationProviderRegistry|null */
    protected $providers = null;

    public function __construct( ?expAiConfig $config = null )
    {
        $this->config = $config ?: expAiConfig::instance();
    }

    /** @return expAiTranslationProviderRegistry the translation services of the sync's settings */
    public function providers()
    {
        if ( !$this->providers )
            $this->providers = new expAiTranslationProviderRegistry( $this->config );
        return $this->providers;
    }

    /** @return expAiTranslationProviderLlm the translation service llm (the request of a model, its estimate and alias) */
    public function llm()
    {
        $llm = $this->providers()->get( expAiTranslationProviderRegistry::DEFAULT_PROVIDER );
        return $llm instanceof expAiTranslationProviderLlm ? $llm : new expAiTranslationProviderLlm( expAiTranslationProviderRegistry::DEFAULT_PROVIDER, array(), $this->config );
    }

    /** @return bool the service's requests are queued as model requests (expai.request and the applier translation_sync) */
    public static function queuesRequest( $provider )
    {
        return $provider instanceof expAiTranslationProviderLlm;
    }

    /** @return int the user who decides, approves or skips (the current user) */
    protected function userID()
    {
        return (int) eZUser::currentUserID();
    }

    /** @return bool [TranslationSync] Enabled */
    public function enabled()
    {
        return $this->config->flag( 'TranslationSync', 'Enabled', true );
    }

    /* ------------------------------------------------------------ the decision */

    /**
     * The decision for one translation, from the diff of the source language.
     * @param array $metrics change_ratio, blocks_changed, hunks (expAiDiffResult::metrics())
     * @param bool $aligned whether the translation has the block structure of the base (patch is possible)
     * @param int $units the blocks a patch would send
     * @param int $estimateMicros the estimated cost of a patch
     * @return array decision (noop, patch, full), needs_editor, auto, reason
     */
    public static function decide( array $metrics, $aligned, $units, $estimateMicros, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $patchMax = (float) $config->get( 'TranslationSync', 'PatchMaxChangedRatio', 0.25 );
        $fullMin = (float) $config->get( 'TranslationSync', 'FullMinChangedRatio', 0.6 );
        $maxUnits = $config->int( 'TranslationSync', 'PatchMaxSegments', 40, 1 );
        $threshold = expAiBudget::micros( (float) $config->get( 'TranslationSync', 'CostThreshold', 0.02 ) );
        $autoPatch = $config->flag( 'TranslationSync', 'AutoQueuePatch', true );
        $ratio = (float) $metrics['change_ratio'];
        if ( empty( $metrics['hunks'] ) )
            return array( 'decision' => 'noop', 'needs_editor' => false, 'auto' => true, 'reason' => 'nothing translatable changed' );
        if ( !$aligned && $config->flag( 'TranslationSync', 'FullIfStructureMismatch', true ) )
            return array( 'decision' => 'full', 'needs_editor' => true, 'auto' => false, 'reason' => 'the translation does not have the structure of its base' );
        if ( $ratio >= $fullMin )
            return array( 'decision' => 'full', 'needs_editor' => true, 'auto' => false, 'reason' => sprintf( 'changed ratio %.2F reaches FullMinChangedRatio %.2F', $ratio, $fullMin ) );
        $reasons = array();
        if ( $ratio > $patchMax )
            $reasons[] = sprintf( 'changed ratio %.2F above PatchMaxChangedRatio %.2F', $ratio, $patchMax );
        if ( (int) $units > $maxUnits )
            $reasons[] = sprintf( '%d blocks above PatchMaxSegments %d', $units, $maxUnits );
        if ( (int) $estimateMicros > $threshold )
            $reasons[] = 'estimate ' . expAiBudget::money( (int) $estimateMicros ) . ' above CostThreshold ' . expAiBudget::money( $threshold );
        if ( !$autoPatch )
            $reasons[] = 'AutoQueuePatch is disabled';
        return array( 'decision' => 'patch', 'needs_editor' => (bool) $reasons, 'auto' => !$reasons,
                      'reason' => $reasons ? implode( '; ', $reasons ) : 'small change: queued automatically' );
    }

    /* ------------------------------------------------------------ languages, base, state */

    /** @return string the source language of an object ([TranslationSync] SourceLanguages[], else its main language) */
    public function sourceLanguage( eZContentObject $object )
    {
        $languages = array_keys( $object->allLanguages() );
        foreach ( $this->config->arr( 'TranslationSync', 'SourceLanguages' ) as $language )
            if ( in_array( $language, $languages, true ) )
                return (string) $language;
        return (string) $object->attribute( 'initial_language_code' );
    }

    /** @return string[] the translations kept in step ([TranslationSync] TargetLanguages[], else all others) */
    public function targetLanguages( eZContentObject $object, $source = '' )
    {
        $source = $source !== '' ? $source : $this->sourceLanguage( $object );
        $only = $this->config->arr( 'TranslationSync', 'TargetLanguages' );
        $out = array();
        foreach ( array_keys( $object->allLanguages() ) as $language )
            if ( $language !== $source && ( !$only || in_array( $language, $only, true ) ) )
                $out[] = (string) $language;
        return $out;
    }

    /** @return string[] the datatypes translated ([TranslationSync] Datatypes[]) */
    public function datatypes()
    {
        $list = $this->config->arr( 'TranslationSync', 'Datatypes' );
        return $list ? array_values( $list ) : array( 'ezstring', 'eztext', 'ezxmltext', 'ezkeyword' );
    }

    /** @return string the ezsite_data key of an object's translation */
    public static function stateKey( $objectID, $language )
    {
        return 'expai_tsync:' . (int) $objectID . ':' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $language );
    }

    /** @return array the stored state of a translation (empty when none) */
    public static function state( $objectID, $language )
    {
        $row = eZSiteData::fetchByName( self::stateKey( $objectID, $language ) );
        $value = $row ? json_decode( (string) $row->attribute( 'value' ), true ) : null;
        return is_array( $value ) ? $value : array();
    }

    /** Stores (merges) the state of a translation. */
    public static function saveState( $objectID, $language, array $changes )
    {
        $name = self::stateKey( $objectID, $language );
        $row = eZSiteData::fetchByName( $name );
        $state = $row ? json_decode( (string) $row->attribute( 'value' ), true ) : array();
        $state = array_merge( is_array( $state ) ? $state : array(), $changes, array( 'updated' => time() ) );
        $json = json_encode( $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( $row )
        {
            $row->setAttribute( 'value', $json );
            $row->store();
        }
        else
            eZSiteData::create( $name, $json )->store();
        return $state;
    }

    /** Removes the state of every translation of an object (the cleanup of tests and of removed objects). */
    public static function forget( $objectID, array $languages )
    {
        foreach ( $languages as $language )
        {
            $row = eZSiteData::fetchByName( self::stateKey( $objectID, $language ) );
            if ( $row )
                $row->remove();
        }
    }

    /**
     * The base version of a translation: the source text it was made from.
     * @return array version (0 when none), from (target_edit, ai_draft, marked, none)
     */
    public function baseFor( eZContentObject $object, $target, $source, array $state = array() )
    {
        $best = array( 'version' => 0, 'from' => 'none' );
        foreach ( (array) $object->versions() as $version )
        {
            $status = (int) $version->attribute( 'status' );
            if ( !in_array( $status, array( eZContentObjectVersion::STATUS_PUBLISHED, eZContentObjectVersion::STATUS_ARCHIVED ), true ) )
                continue;
            if ( $version->initialLanguageCode() !== $target )
                continue;
            $number = (int) $version->attribute( 'version' );
            $from = 'target_edit';
            if ( !empty( $state['draft_version'] ) && (int) $state['draft_version'] === $number && !empty( $state['draft_source_version'] ) )
            {
                $number = (int) $state['draft_source_version'];
                $from = 'ai_draft';
            }
            if ( $number > $best['version'] )
                $best = array( 'version' => $number, 'from' => $from );
        }
        if ( !empty( $state['base_version'] ) && ( empty( $state['source'] ) || $state['source'] === $source ) && (int) $state['base_version'] > $best['version'] )
            $best = array( 'version' => (int) $state['base_version'], 'from' => 'marked' );
        return $best;
    }

    /* ------------------------------------------------------------ the plan */

    /**
     * What a patch or a full translation of one language would send, and the decision.
     * @param array $options mode (patch|full; default the decision), base_version, to_version, target_version (to
     *                       recompute a queued plan exactly), provider (a translation service that must be used)
     * @return array object_id, source, target, base_version, base_from, to_version, target_version, diff
     *               (expAiDiffResult), metrics, aligned, attributes (per attribute: datatype, kind, units, texts),
     *               units (flat list), mode, request (of the llm service, else null), estimate, decision, needs_editor,
     *               auto, reason, alias, lane, service (expAiTranslationProvider|null), provider, provider_label,
     *               provider_kind, provider_state (ok, no_provider, blocked), provider_reason, provider_chosen_by
     */
    public function plan( eZContentObject $object, $target, array $options = array() )
    {
        $source = isset( $options['source'] ) && $options['source'] !== '' ? (string) $options['source'] : $this->sourceLanguage( $object );
        $state = self::state( (int) $object->attribute( 'id' ), $target );
        $to = !empty( $options['to_version'] ) ? (int) $options['to_version'] : (int) $object->attribute( 'current_version' );
        $targetVersion = !empty( $options['target_version'] ) ? (int) $options['target_version'] : (int) $object->attribute( 'current_version' );
        if ( !empty( $options['base_version'] ) )
            $base = array( 'version' => (int) $options['base_version'], 'from' => isset( $options['base_from'] ) ? (string) $options['base_from'] : 'given' );
        else
            $base = $this->baseFor( $object, $target, $source, $state );
        $baseVersion = $base['version'] ? min( $base['version'], $to ) : 0;
        $datatypes = $this->datatypes();
        $diff = expAiDiff::compare( $object, $object, array( 'version_a' => $baseVersion ?: $to, 'version_b' => $to, 'language' => $source,
                                                              'translatable_only' => true, 'datatypes' => $datatypes, 'config' => $this->config ) );
        if ( !$baseVersion )
        {
            // no base at all: everything counts as new
            $diff = expAiDiff::compare( array( 'values' => array(), 'language' => $source ), $object,
                                        array( 'version_b' => $to, 'language' => $source, 'translatable_only' => true, 'datatypes' => $datatypes, 'config' => $this->config ) );
        }
        $targetValues = expAiDiff::values( $object, $targetVersion, $target );
        $plan = array( 'object_id' => (int) $object->attribute( 'id' ), 'source' => $source, 'target' => (string) $target,
                       'base_version' => $baseVersion, 'base_from' => $base['from'], 'to_version' => $to, 'target_version' => $targetVersion,
                       'diff' => $diff, 'metrics' => $diff->metrics(), 'aligned' => true, 'attributes' => array(), 'units' => array(),
                       'target_hash' => self::valuesHash( $targetValues, array_keys( $diff->attributes() ) ) );
        if ( !$baseVersion )
            $plan['aligned'] = false;
        $mode = isset( $options['mode'] ) ? (string) $options['mode'] : '';
        $forced = isset( $options['provider'] ) ? (string) $options['provider'] : '';
        $patch = $this->units( $diff, $targetValues, 'patch', $plan['aligned'] );
        $plan['aligned'] = $patch['aligned'];
        // the translation service of a patch, and what the patch would cost there (CostThreshold gets that estimate)
        $patchChoice = $this->providers()->forPair( $source, (string) $target, 'patch', $object, $forced );
        $patchEstimate = $patch['units'] ? $this->cost( $patchChoice, array_merge( $plan, array( 'units' => $patch['units'] ) ), 'patch' )
                                         : $this->noCost( $patchChoice );
        $decision = self::decide( $plan['metrics'], $plan['aligned'], count( $patch['units'] ), $patchEstimate['micros'], $this->config );
        $plan += $decision;
        $mode = $mode !== '' ? $mode : ( $decision['decision'] === 'noop' ? 'patch' : $decision['decision'] );
        $chosen = $mode === 'full' ? $this->units( $diff, $targetValues, 'full', $plan['aligned'] ) : $patch;
        $choice = $mode === 'full' ? $this->providers()->forPair( $source, (string) $target, 'full', $object, $forced ) : $patchChoice;
        $plan['mode'] = $mode;
        $plan['attributes'] = $chosen['attributes'];
        $plan['units'] = $chosen['units'];
        $estimate = $mode === 'patch' ? $patchEstimate : ( $chosen['units'] ? $this->cost( $choice, $plan, 'full' ) : $this->noCost( $choice ) );
        $plan['request'] = $chosen['units'] ? $estimate['request'] : null;
        $plan['estimate'] = (int) $estimate['micros'];
        $plan['patch_estimate'] = (int) $patchEstimate['micros'];
        $plan['lane'] = $estimate['lane'];
        $plan['alias'] = $estimate['alias'];
        $service = $choice['provider'];
        $plan['service'] = $service;
        $plan['provider'] = $service ? $service->id() : '';
        $plan['provider_label'] = $service ? $service->label() : '';
        $plan['provider_kind'] = $service ? $service->kind() : '';
        $plan['provider_state'] = $choice['state'];
        $plan['provider_reason'] = $choice['reason'];
        $plan['provider_chosen_by'] = $choice['chosen_by'];
        return $plan;
    }

    /**
     * What a plan costs on the chosen translation service: the llm service estimates the model request on the lane
     * the queue would choose (and keeps the request); a machine translation service prices the characters.
     * @param array $choice expAiTranslationProviderRegistry::forPair()
     * @return array micros, lane, alias, request (the model request of the llm service, else null)
     */
    protected function cost( array $choice, array $plan, $mode )
    {
        $service = $choice['provider'];
        if ( !$service )
            return $this->noCost( $choice );
        if ( self::queuesRequest( $service ) )
        {
            $request = $service->planRequest( $plan, $mode );
            return array( 'request' => $request ) + $service->planEstimate( $request, $mode );
        }
        $estimate = $service->estimate( $this->translationRequest( $plan, $mode ) );
        return array( 'micros' => (int) $estimate['micros'], 'lane' => '', 'alias' => '', 'request' => null );
    }

    /** @return array the estimate of a plan without units (or without a service) */
    protected function noCost( array $choice )
    {
        $llm = !$choice['provider'] || self::queuesRequest( $choice['provider'] );
        return array( 'micros' => 0, 'lane' => '', 'alias' => $llm ? $this->alias() : '', 'request' => null );
    }

    /**
     * The translation request of a plan (a machine translation service): the units, the terms and glossary of the
     * pair, the object (the data policy and the usage log).
     * @return expAiTranslationRequest
     */
    public function translationRequest( array $plan, $mode )
    {
        $request = expAiTranslationRequest::fromPlan( $plan, $mode );
        $request->doNotTranslate = $this->providers()->terms();
        $request->glossary = $this->providers()->glossary( $plan['source'], $plan['target'] );
        $request->action = 'queue.' . ( $mode === 'full' ? 'translate_full' : 'translate' );
        return $request;
    }

    /** @return string a hash of the target values of some attributes (to notice an edit between the plan and the answer) */
    protected static function valuesHash( array $values, array $identifiers )
    {
        $parts = array();
        foreach ( $identifiers as $identifier )
            $parts[] = $identifier . '=' . ( isset( $values[$identifier] ) ? sha1( $values[$identifier]['value'] ) : '' );
        return substr( sha1( implode( '|', $parts ) ), 0, 16 );
    }

    /**
     * The blocks to translate.
     * @param string $mode patch (changed blocks only, with the existing translation) or full (every block)
     * @return array aligned, attributes (identifier => datatype, kind whole|blocks, prop_value, target_value, texts
     *               (block index => text from the translation), units (unit ids by block index)), units (flat list)
     */
    protected function units( expAiDiffResult $diff, array $targetValues, $mode, $aligned )
    {
        $attributes = array();
        $units = array();
        foreach ( $diff->attributes() as $identifier => $a )
        {
            if ( $mode === 'patch' && !$a['hunks'] )
                continue;
            if ( $mode === 'full' && !$a['prop_blocks'] )
                continue;
            $targetValue = isset( $targetValues[$identifier] ) ? (string) $targetValues[$identifier]['value'] : '';
            $whole = !in_array( $a['segmenter'], array( 'expAiSegmenterText', 'expAiSegmenterRichText' ), true )
                     && !is_subclass_of( $a['segmenter'], 'expAiSegmenterText' ) && !is_subclass_of( $a['segmenter'], 'expAiSegmenterRichText' );
            $row = array( 'datatype' => $a['datatype'], 'kind' => $whole ? 'whole' : 'blocks', 'prop_value' => $a['prop_value'],
                          'target_value' => $targetValue, 'texts' => array(), 'units' => array() );
            if ( $whole )
            {
                $id = 'u' . ( count( $units ) + 1 );
                $join = function ( array $blocks ) { return implode( ', ', array_column( $blocks, 'text' ) ); };
                $isKeyword = $a['segmenter'] === 'expAiSegmenterKeyword';
                $units[] = array( 'id' => $id, 'attribute' => $identifier, 'index' => 0, 'kind' => $isKeyword ? 'keywords' : 'text',
                                  'old' => $mode === 'patch' ? ( $isKeyword ? $join( $a['base_blocks'] ) : $a['base_value'] ) : '',
                                  'new' => $isKeyword ? $join( $a['prop_blocks'] ) : $a['prop_value'],
                                  'translation' => $mode === 'patch' ? $targetValue : '', 'before' => '', 'after' => '' );
                $row['units'][0] = $id;
                $attributes[$identifier] = $row;
                continue;
            }
            $segmenter = expAiSegmenterRegistry::forDatatype( $a['datatype'], $this->config );
            $targetBlocks = $segmenter->segment( $targetValue, array( 'language' => $diff->get( 'language' ) ) );
            if ( $mode === 'patch' )
            {
                $same = count( $targetBlocks ) === count( $a['base_blocks'] )
                        && array_column( $targetBlocks, 'type' ) === array_column( $a['base_blocks'], 'type' );
                if ( !$same )
                    $aligned = false;
            }
            $prop = $a['prop_blocks'];
            $byProp = array();
            foreach ( $a['ops'] as $op )
                if ( $op['prop'] !== null )
                    $byProp[$op['prop']] = $op;
            foreach ( $prop as $j => $block )
            {
                $op = isset( $byProp[$j] ) ? $byProp[$j] : array( 'op' => 'insert', 'base' => null );
                if ( $mode === 'patch' && $op['op'] === 'equal' )
                {
                    if ( isset( $targetBlocks[$op['base']] ) )
                        $row['texts'][$j] = array( 'text' => $targetBlocks[$op['base']]['coded'], 'from' => array( $targetValue, (int) $op['base'] ) );
                    continue;
                }
                // a moved block: its translation moves with it
                if ( $mode === 'patch' && $op['op'] === 'insert' && !empty( $op['moved'] ) )
                {
                    foreach ( $a['ops'] as $other )
                        if ( $other['op'] === 'delete' && !empty( $other['moved'] ) && $a['base_blocks'][$other['base']]['hash'] === $block['hash'] && isset( $targetBlocks[$other['base']] ) )
                        {
                            $row['texts'][$j] = array( 'text' => $targetBlocks[$other['base']]['coded'], 'from' => array( $targetValue, (int) $other['base'] ) );
                            continue 2;
                        }
                }
                $id = 'u' . ( count( $units ) + 1 );
                $hasOld = $mode === 'patch' && $op['op'] === 'modify';
                $units[] = array( 'id' => $id, 'attribute' => $identifier, 'index' => $j, 'kind' => 'block',
                                  'old' => $hasOld ? $a['base_blocks'][$op['base']]['coded'] : '',
                                  'new' => $block['coded'],
                                  'translation' => $hasOld && isset( $targetBlocks[$op['base']] ) ? $targetBlocks[$op['base']]['coded'] : '',
                                  'before' => $j > 0 ? $prop[$j - 1]['text'] : '', 'after' => isset( $prop[$j + 1] ) ? $prop[$j + 1]['text'] : '' );
                $row['units'][$j] = $id;
            }
            $attributes[$identifier] = $row;
        }
        return array( 'aligned' => $aligned, 'attributes' => $attributes, 'units' => $units );
    }

    /** @return string the model alias of the sync ([TranslationSync] Model, else the site default; the llm service) */
    public function alias()
    {
        return $this->llm()->alias();
    }

    /** @return string the name of a language, for the prompt and the status ("German (ger-DE)") */
    protected static function languageName( $code )
    {
        return expAiTranslationProviderLlm::languageName( $code );
    }

    /**
     * The request of a plan to a model: system, one user message (JSON), output_schema, max_tokens (the llm service,
     * expAiTranslationProviderLlm::planRequest()).
     * @return array
     */
    public function request( array $plan, $mode )
    {
        return $this->llm()->planRequest( $plan, $mode );
    }

    /**
     * The estimated cost of a model request on the lane the queue would choose (the llm service).
     * @return array micros, lane, alias
     */
    public function estimate( array $request, $mode )
    {
        return $this->llm()->planEstimate( $request, $mode );
    }

    /* ------------------------------------------------------------ check, queue, skip */

    /**
     * Decides for every translation of an object; queues small patches (AutoQueuePatch), stores the decisions.
     * @param array $options languages (only these), dry_run (decide only: nothing stored or queued), trigger, user_id,
     *                       provider (a translation service that must be used: no rule, no fallback)
     * @return array[] per language: the decision row (see stateRow())
     */
    public function check( eZContentObject $object, array $options = array() )
    {
        $source = $this->sourceLanguage( $object );
        $out = array();
        foreach ( $this->targetLanguages( $object, $source ) as $target )
        {
            if ( !empty( $options['languages'] ) && !in_array( $target, (array) $options['languages'], true ) )
                continue;
            $plan = $this->plan( $object, $target, array( 'source' => $source ) + ( !empty( $options['provider'] ) ? array( 'provider' => (string) $options['provider'] ) : array() ) );
            $row = $this->stateRow( $plan );
            $row['trigger'] = isset( $options['trigger'] ) ? (string) $options['trigger'] : 'manual';
            // no translation service can take it (none installed, or the data policy keeps the content away): reported, never queued
            $unserved = $plan['decision'] !== 'noop' && $plan['provider_state'] !== 'ok';
            if ( !empty( $options['dry_run'] ) )
            {
                $row['state'] = $plan['decision'] === 'noop' ? 'up_to_date' : ( $unserved ? $plan['provider_state'] : ( $plan['auto'] ? 'would_queue' : 'waiting' ) );
                $out[$target] = $row;
                continue;
            }
            $previous = static::state( (int) $object->attribute( 'id' ), $target );
            if ( $plan['decision'] === 'noop' )
            {
                $row['state'] = 'up_to_date';
                $row['base_version'] = $plan['to_version'];
                $row['source'] = $source;
            }
            elseif ( !empty( $previous['job_uuid'] ) && (int) $previous['to_version'] === (int) $plan['to_version'] && static::jobActive( $previous['job_uuid'] ) )
            {
                $row['state'] = 'queued';
                $row['job_uuid'] = $previous['job_uuid'];
            }
            elseif ( $unserved )
                $row['state'] = $plan['provider_state'];
            elseif ( $plan['auto'] )
            {
                $row = array_merge( $row, $this->queuePlan( $object, $plan, isset( $options['user_id'] ) ? (int) $options['user_id'] : 0 ) );
            }
            else
                $row['state'] = 'waiting';
            $out[$target] = static::saveState( (int) $object->attribute( 'id' ), $target, $row );
        }
        return $out;
    }

    /** @return array what is stored of a plan */
    protected function stateRow( array $plan )
    {
        $m = $plan['metrics'];
        return array( 'source' => $plan['source'], 'target' => $plan['target'], 'from_version' => $plan['base_version'], 'base_from' => $plan['base_from'],
                      'to_version' => $plan['to_version'], 'target_version' => $plan['target_version'], 'decision' => $plan['decision'],
                      'needs_editor' => $plan['needs_editor'], 'auto' => $plan['auto'], 'reason' => $plan['reason'], 'mode' => $plan['mode'],
                      'change_ratio' => $m['change_ratio'], 'blocks_changed' => $m['blocks_changed'], 'sentences_changed' => $m['sentences_changed'],
                      'hunks' => $m['hunks'], 'units' => count( $plan['units'] ), 'aligned' => $plan['aligned'],
                      'estimate_micros' => $plan['estimate'], 'patch_estimate_micros' => $plan['patch_estimate'], 'lane' => $plan['lane'], 'alias' => $plan['alias'],
                      'provider' => $plan['provider'], 'provider_label' => $plan['provider_label'], 'provider_kind' => $plan['provider_kind'],
                      'provider_reason' => $plan['provider_reason'],
                      'checked' => time(), 'checked_by' => $this->userID(), 'job_uuid' => '', 'error' => '' );
    }

    /** @return bool whether a queue job has not ended */
    public static function jobActive( $uuid )
    {
        if ( (string) $uuid === '' || !expAiQueue::available() )
            return false;
        $job = expQueue::fetch( (string) $uuid );
        return $job && !in_array( (string) $job->attribute( 'state' ), array( 'done', 'dead', 'cancelled' ), true );
    }

    /**
     * An editor's approval (or a forced mode): plans again and queues.
     * @param string $mode '' (the decision), patch or full
     * @param array $options user_id, provider (a translation service that must be used)
     * @return array the stored state
     * @throws expAiException also when no translation service can take it (the state is stored first)
     */
    public function approve( eZContentObject $object, $target, $mode = '', array $options = array() )
    {
        if ( !$object->canEdit( false, false, false, $target ) )
            throw new expAiException( 'You may not edit object %id in %language.', '', 0, array( '%id' => (int) $object->attribute( 'id' ), '%language' => $target ) );
        $plan = $this->plan( $object, $target, ( $mode !== '' ? array( 'mode' => $mode ) : array() ) + ( !empty( $options['provider'] ) ? array( 'provider' => (string) $options['provider'] ) : array() ) );
        if ( !$plan['units'] )
            return static::saveState( (int) $object->attribute( 'id' ), $target, array_merge( $this->stateRow( $plan ), array( 'state' => 'up_to_date', 'base_version' => $plan['to_version'] ) ) );
        if ( $plan['provider_state'] !== 'ok' )
        {
            static::saveState( (int) $object->attribute( 'id' ), $target, array_merge( $this->stateRow( $plan ), array( 'state' => $plan['provider_state'] ) ) );
            $arguments = array( '%source' => $plan['source'], '%target' => $plan['target'] );
            if ( $plan['provider_state'] === 'blocked' )
                throw new expAiException( 'The data policy keeps this content away from every translation service for %source → %target.', $plan['provider_reason'], 0, $arguments );
            throw new expAiException( 'No translation service is installed for %source → %target.', $plan['provider_reason'], 0, $arguments );
        }
        if ( !$plan['aligned'] && $plan['mode'] === 'patch' )
            throw new expAiException( 'This translation cannot be patched; translate it again in full.', 'the translation does not have the structure of its base' );
        $row = array_merge( $this->stateRow( $plan ), $this->queuePlan( $object, $plan, isset( $options['user_id'] ) ? (int) $options['user_id'] : 0 ),
                            array( 'approved_by' => $this->userID(), 'approved' => time() ) );
        return static::saveState( (int) $object->attribute( 'id' ), $target, $row );
    }

    /**
     * Queues a plan: the llm service's request as a model request (expAiQueue::request, applier translation_sync),
     * another service's translation as a job expai.translation.run (queueRun()).
     * @return array state, job_uuid, held, estimate_micros, lane
     */
    protected function queuePlan( eZContentObject $object, array $plan, $userID = 0 )
    {
        if ( !empty( $plan['service'] ) && !self::queuesRequest( $plan['service'] ) )
            return $this->queueRun( $object, $plan, $userID );
        $full = $plan['mode'] === 'full';
        $node = $object->attribute( 'main_node' );
        $request = $plan['request'];
        if ( !$plan['units'] || !$request )
            throw new expAiException( 'There is nothing to translate.', 'a plan without blocks is never queued' );
        $estOutput = isset( $request['est_output'] ) ? (int) $request['est_output'] : 0;
        unset( $request['est_output'] );
        $r = expAiQueue::request( $plan['alias'], $request, array(
            'purpose' => $full ? 'translate_full' : 'translate', 'class' => $full ? 'translation_full' : 'translation_patch',
            'lane' => (string) $this->config->get( 'TranslationSync', $full ? 'FullLane' : 'PatchLane', 'auto' ),
            'objects' => array( (int) $object->attribute( 'id' ) ), 'node_id' => $node ? (int) $node->attribute( 'node_id' ) : 0,
            'label' => ( $full ? 'Translation ' : 'Translation patch ' ) . $plan['source'] . ' -> ' . $plan['target'] . ' of object ' . (int) $object->attribute( 'id' ),
            'est_output' => $estOutput, 'user_id' => $userID ?: null,
            'idempotency_key' => 'tsync:' . (int) $object->attribute( 'id' ) . ':' . $plan['target'] . ':' . $plan['base_version'] . '-' . $plan['to_version'] . ':' . $plan['mode'],
            'apply' => self::APPLIER,
            'apply_params' => self::applyParams( $object, $plan ) ) );
        return array( 'state' => $r['held'] !== '' ? 'held' : 'queued', 'job_uuid' => (string) $r['job']->attribute( 'uuid' ), 'held' => (string) $r['held'],
                      'estimate_micros' => (int) $r['estimate'], 'lane' => $r['lane']->name(), 'mode' => $plan['mode'], 'error' => '' );
    }

    /** @return array what the applier needs to plan again exactly (expAiTranslationSync::planForJob()) */
    protected static function applyParams( eZContentObject $object, array $plan )
    {
        return array( 'object_id' => (int) $object->attribute( 'id' ), 'source' => $plan['source'], 'target' => $plan['target'],
                      'base_version' => $plan['base_version'], 'base_from' => $plan['base_from'], 'to_version' => $plan['to_version'],
                      'target_version' => $plan['target_version'], 'target_hash' => $plan['target_hash'], 'mode' => $plan['mode'] );
    }

    /**
     * Queues the translation of a plan by a machine translation service: a job expai.translation.run (class
     * translation_patch or translation_full, resource translation:<id>) whose estimate is reserved in the budgets
     * of queued requests, held at once when it does not fit (expAiBudget), and nothing queued while expai is off or
     * the veto refuses the service (expAiGate).
     * @return array state, job_uuid, held, estimate_micros, lane ('')
     * @throws expAiException|expQueueException
     */
    protected function queueRun( eZContentObject $object, array $plan, $userID = 0 )
    {
        if ( !$plan['units'] )
            throw new expAiException( 'There is nothing to translate.', 'a plan without blocks is never queued' );
        if ( !static::queueAvailable() )
            throw new expAiException( 'The job queue is not available.', 'the extension expqueue is not active or its tables are missing' );
        $service = $plan['service'];
        $full = $plan['mode'] === 'full';
        $purpose = $full ? 'translate_full' : 'translate';
        $node = $object->attribute( 'main_node' );
        $nodeID = $node ? (int) $node->attribute( 'node_id' ) : 0;
        $this->gate( array( 'user_id' => $userID ? (int) $userID : $this->userID(), 'node_id' => $nodeID, 'provider' => $service->id(),
                            'model' => $service->id(), 'model_alias' => 'translation.' . $service->id(), 'purpose' => 'queue.' . $purpose,
                            'region' => $service->region(), 'retention' => $service->retention() ) );
        $params = self::applyParams( $object, $plan ) + array( 'provider' => $service->id() );
        $payload = array( 'provider' => $service->id(), 'purpose' => $purpose, 'objects' => array( (int) $object->attribute( 'id' ) ), 'node_id' => $nodeID,
                          'label' => expAiText::cut( ( $full ? 'Translation ' : 'Translation patch ' ) . $plan['source'] . ' -> ' . $plan['target'] . ' of object '
                                                     . (int) $object->attribute( 'id' ) . ' by ' . $service->label(), 120 ),
                          'apply_params' => $params );
        if ( class_exists( 'expAiGate' ) && expAiGate::siteAccess() !== '' )
            $payload['siteaccess'] = expAiGate::siteAccess();
        $options = array( 'class' => $full ? 'translation_full' : 'translation_patch', 'resource' => 'translation:' . $service->id(),
                          'cost_estimate_micros' => (int) $plan['estimate'], 'tags' => array( 'provider:' . $service->id() ),
                          'idempotency_key' => 'tsync:' . (int) $object->attribute( 'id' ) . ':' . $plan['target'] . ':' . $plan['base_version'] . '-' . $plan['to_version']
                                               . ':' . $plan['mode'] . ':' . $service->id() );
        if ( $userID )
            $options['user_id'] = (int) $userID;
        $r = $this->enqueueRun( $payload, $options, (int) $plan['estimate'] );
        return array( 'state' => $r['held'] !== '' ? 'held' : 'queued', 'job_uuid' => (string) $r['job']->attribute( 'uuid' ), 'held' => (string) $r['held'],
                      'estimate_micros' => (int) $plan['estimate'], 'lane' => '', 'mode' => $plan['mode'], 'error' => '' );
    }

    /**
     * Queues a job expai.translation.run with its reservation (held at once when a budget does not allow it).
     * @param int $estimate micros
     * @return array job (expQueueJob), held ('' or why)
     */
    protected function enqueueRun( array $payload, array $options, $estimate )
    {
        $budget = new expAiBudget( $this->config );
        $reserve = (int) ceil( (int) $estimate * $budget->reserveFactor() );
        return expQueue::withLock( function () use ( $payload, $options, $budget, $reserve )
        {
            $refusal = $budget->refusal( $reserve );
            $job = expQueue::enqueue( self::RUN_JOB, $payload, $options );
            if ( !empty( $job->duplicate ) )
                return array( 'job' => $job, 'held' => '' );
            $budget->reserve( $job, $reserve );
            $held = '';
            if ( $refusal !== null && $budget->hold( $job, $refusal['message'] ) )
                $held = $refusal['message'];
            return array( 'job' => $job, 'held' => $held );
        } );
    }

    /**
     * The master switch and the veto before a translation is queued (expAiGate::check()).
     * @throws expAiException|expAiVetoException
     */
    protected function gate( array $known )
    {
        if ( class_exists( 'expAiGate' ) )
            expAiGate::check( $known, $this->config );
    }

    /** @return bool the job queue can be used (expAiQueue::available()) */
    protected static function queueAvailable()
    {
        return expAiQueue::available();
    }

    /**
     * "Skip": the translation counts as up to date with the current source version.
     * @return array the stored state
     */
    public function skip( eZContentObject $object, $target )
    {
        if ( !$object->canEdit( false, false, false, $target ) )
            throw new expAiException( 'You may not edit object %id in %language.', '', 0, array( '%id' => (int) $object->attribute( 'id' ), '%language' => $target ) );
        $previous = self::state( (int) $object->attribute( 'id' ), $target );
        if ( !empty( $previous['job_uuid'] ) && self::jobActive( $previous['job_uuid'] ) )
            expQueue::cancel( expQueue::fetch( $previous['job_uuid'] ), 'skipped by an editor' );
        return self::saveState( (int) $object->attribute( 'id' ), $target, array(
            'source' => $this->sourceLanguage( $object ), 'base_version' => (int) $object->attribute( 'current_version' ),
            'to_version' => (int) $object->attribute( 'current_version' ), 'state' => 'skipped', 'decision' => 'noop',
            'reason' => 'marked as up to date by an editor', 'change_ratio' => 0, 'blocks_changed' => 0, 'sentences_changed' => 0, 'hunks' => 0,
            'units' => 0, 'estimate_micros' => 0, 'error' => '',
            'skipped_by' => $this->userID(), 'skipped' => time(), 'job_uuid' => '' ) );
    }

    /**
     * The translations of an object with their stored decision and the live state of their job.
     * @return array source, languages (code => state row with name, job_state, job_error, draft link data)
     */
    public function status( eZContentObject $object )
    {
        $source = $this->sourceLanguage( $object );
        $out = array( 'source' => $source, 'source_name' => self::languageName( $source ), 'current_version' => (int) $object->attribute( 'current_version' ),
                      'languages' => array() );
        foreach ( $this->targetLanguages( $object, $source ) as $target )
        {
            $row = static::state( (int) $object->attribute( 'id' ), $target ) + array( 'state' => 'unchecked', 'decision' => '', 'job_uuid' => '' );
            // a decision stored before translation services existed was made for the llm service
            if ( !isset( $row['provider'] ) && $row['decision'] !== '' )
                $row['provider'] = expAiTranslationProviderRegistry::DEFAULT_PROVIDER;
            if ( !isset( $row['provider_label'] ) || ( $row['provider_label'] === '' && !empty( $row['provider'] ) ) )
                $row['provider_label'] = !empty( $row['provider'] ) ? ( ( $service = $this->providers()->get( $row['provider'] ) ) ? $service->label() : (string) $row['provider'] ) : '';
            $row['name'] = self::languageName( $target );
            $row['stale'] = isset( $row['to_version'] ) && (int) $row['to_version'] !== (int) $object->attribute( 'current_version' )
                            && !in_array( $row['state'], array( 'queued', 'held' ), true );
            $row['job_state'] = '';
            if ( $row['job_uuid'] !== '' && static::queueAvailable() )
            {
                $job = expQueue::fetch( $row['job_uuid'] );
                if ( $job )
                {
                    $row['job_state'] = (string) $job->attribute( 'state' );
                    if ( in_array( $row['job_state'], array( 'dead', 'cancelled' ), true ) && in_array( $row['state'], array( 'queued', 'held' ), true ) )
                        $row['state'] = 'failed';
                    if ( $row['job_state'] === 'dead' )
                        $row['error'] = (string) $job->attribute( 'last_error' );
                }
            }
            $row['estimate'] = isset( $row['estimate_micros'] ) ? expAiBudget::money( (int) $row['estimate_micros'] ) : '';
            $out['languages'][$target] = $row;
        }
        return $out;
    }

    /* ------------------------------------------------------------ the answer */

    /**
     * The plan of a queued translation again, exactly as it was queued (the applier, the run job).
     * @param array $params the apply_params of the job
     * @return array the plan
     */
    public function planForJob( eZContentObject $object, array $params )
    {
        return $this->plan( $object, (string) $params['target'], array( 'mode' => (string) $params['mode'], 'source' => (string) $params['source'],
                                                     'base_version' => (int) $params['base_version'], 'base_from' => isset( $params['base_from'] ) ? (string) $params['base_from'] : '',
                                                     'to_version' => (int) $params['to_version'], 'target_version' => (int) $params['target_version'] )
                                                     + ( !empty( $params['provider'] ) ? array( 'provider' => (string) $params['provider'] ) : array() ) );
    }

    /**
     * Whether the translation was edited after the patch was planned (a patch is then not written over that edit).
     * @return bool
     */
    public static function changedSincePlan( eZContentObject $object, array $plan, array $params )
    {
        $now = expAiDiff::values( $object, (int) $object->attribute( 'current_version' ), (string) $params['target'] );
        return $params['mode'] === 'patch' && self::valuesHash( $now, array_keys( $plan['diff']->attributes() ) ) !== (string) $params['target_hash'];
    }

    /**
     * Writes the answer of a queued request into a draft of the target language (the applier).
     * @return array draft_version, language, written, skipped, errors
     * @throws expQueuePermanentException
     */
    public function applyAnswer( expQueueJob $job, array $answer, array $params )
    {
        $uuid = (string) $job->attribute( 'uuid' );
        $object = expAiObjects::fetch( (int) $params['object_id'] );
        if ( !$object instanceof eZContentObject )
            throw new expQueuePermanentException( 'the object is gone', 'not_found' );
        $target = (string) $params['target'];
        $done = eZPersistentObject::fetchObject( expAiDraftLog::definition(), null, array( 'job_uuid' => $uuid ) );
        if ( $done instanceof expAiDraftLog )
            return array( 'draft_version' => (int) $done->attribute( 'version' ), 'language' => $target, 'again' => true );
        $fail = function ( $message, $class ) use ( $object, $target )
        {
            self::saveState( (int) $object->attribute( 'id' ), $target, array( 'state' => 'failed', 'error' => $message ) );
            throw new expQueuePermanentException( $message, $class );
        };
        $plan = $this->planForJob( $object, $params );
        // the translation was edited after the plan: do not overwrite that edit
        if ( self::changedSincePlan( $object, $plan, $params ) )
            $fail( 'the translation was changed after the patch was planned; check again', 'conflict' );
        $texts = array();
        foreach ( isset( $answer['value']['units'] ) ? (array) $answer['value']['units'] : array() as $unit )
            if ( isset( $unit['id'], $unit['text'] ) )
                $texts[(string) $unit['id']] = (string) $unit['text'];
        $values = array();
        $types = array();
        foreach ( $plan['attributes'] as $identifier => $a )
        {
            $types[$identifier] = $a['datatype'];
            $segmenter = expAiSegmenterRegistry::forDatatype( $a['datatype'], $this->config );
            $compose = $a['texts'];
            foreach ( $a['units'] as $index => $id )
            {
                if ( !isset( $texts[$id] ) )
                    $fail( 'the answer has no text for block ' . $id . ' of ' . $identifier, 'answer' );
                $compose[$index] = array( 'text' => $texts[$id], 'from' => array( $a['prop_value'], (int) $index ) );
            }
            try
            {
                if ( $a['kind'] === 'whole' )
                    $values[$identifier] = $a['datatype'] === 'ezkeyword'
                        ? $segmenter->compose( '', array( $texts[$a['units'][0]] ) )
                        : trim( html_entity_decode( $texts[$a['units'][0]], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
                else
                {
                    ksort( $compose );
                    $values[$identifier] = $segmenter->compose( $a['prop_value'], $compose );
                }
            }
            catch ( expAiException $e )
            {
                $fail( $identifier . ': ' . $e->getMessage() . ' (' . $e->detail() . ')', 'markup' );
            }
        }
        if ( !$values )
            $fail( 'nothing to write', 'answer' );
        try
        {
            $written = expAiDiff::writeDraft( $object, $target, $values, $types, array(
                'source' => 'translation', 'job_uuid' => $uuid, 'model' => isset( $answer['model'] ) ? (string) $answer['model'] : '',
                'prompt_summary' => ( $params['mode'] === 'full' ? 'Translation ' : 'Translation patch ' ) . $params['source'] . ' v' . (int) $params['base_version']
                                    . ' -> v' . (int) $params['to_version'] . ' into ' . $target )
                // a machine translation service names itself (its engine is not a model of the provider registry)
                + ( !empty( $answer['provider'] ) ? array( 'provider' => (string) $answer['provider'] ) : array() ) );
        }
        catch ( expAiException $e )
        {
            $fail( $e->getMessage() . ( $e->detail() !== '' ? ' (' . $e->detail() . ')' : '' ), 'write' );
        }
        self::saveState( (int) $object->attribute( 'id' ), $target, array( 'state' => 'draft', 'draft_version' => (int) $written['version'],
                                                                            'draft_source_version' => (int) $params['to_version'], 'error' => '',
                                                                            'written' => $written['written'] ) );
        return array( 'draft_version' => (int) $written['version'], 'language' => $target, 'written' => $written['written'],
                      'skipped' => $written['skipped'], 'errors' => $written['errors'] );
    }

    /* ------------------------------------------------------------ the trigger */

    /**
     * After a publish (the workflow event, or a caller of its own): a check of the object's translations when the
     * published version is in its source language. Queued as a job (expai.translation.check, class system) when the
     * queue is there and [TranslationSync] QueueCheck is enabled, else run at once.
     * @return string what happened (queued, checked, not_source, single_language, disabled)
     */
    public function onPublish( $objectID, $versionNumber, $userID = 0 )
    {
        if ( !$this->enabled() )
            return 'disabled';
        $object = expAiObjects::fetch( (int) $objectID );
        if ( !$object instanceof eZContentObject )
            return 'not_found';
        $version = $object->version( (int) $versionNumber );
        if ( !$version || $version->initialLanguageCode() !== $this->sourceLanguage( $object ) )
            return 'not_source';
        if ( !$this->targetLanguages( $object ) )
            return 'single_language';
        if ( $this->config->flag( 'TranslationSync', 'QueueCheck', true ) && expAiQueue::available() )
        {
            expQueue::enqueue( self::CHECK_JOB, array( 'object_id' => (int) $objectID, 'version' => (int) $versionNumber ),
                               array( 'class' => 'system', 'idempotency_key' => 'tsync-check:' . (int) $objectID . ':' . (int) $versionNumber,
                                      'user_id' => $userID ?: null ) );
            return 'queued';
        }
        $this->check( $object, array( 'trigger' => 'publish' ) );
        return 'checked';
    }
}
