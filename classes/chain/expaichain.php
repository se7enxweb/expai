<?php
/**
 * File containing the expAiChain class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The definition of an AI chain (doc/chains.md): an ordered list of steps, each one structured call to a model, a
 * human checkpoint or a hand-off, read from expai.ini:
 *
 *   [ChainSettings] Chains[]=article
 *   [Chain_article] Label, Description, Class, Fields[], Steps[], MaxCostPerRun, MaxItems
 *   [ChainStep_article.write] Type, Label, Model, Lane, PromptTemplate | PromptText, Tools[], PassCriteria[],
 *                             Checklist[], MaxIterations, OnFail, ReviseStep, OnExhausted, Checkpoint, Next,
 *                             MaxTokens, OutputFields[], Languages[]
 *
 * A run keeps the snapshot of its definition (toArray(), with the prompt texts), so editing the settings or a prompt
 * template never changes a run that has started.
 */
class expAiChain
{
    const TYPES = array( 'generate', 'review', 'transform', 'human', 'translate' );

    /** @var array the normalised definition */
    protected $def;

    protected function __construct( array $def )
    {
        $this->def = $def;
    }

    /** @return string[] the ids of [ChainSettings] Chains[] */
    public static function ids( ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        return array_values( array_unique( array_filter( array_map( 'strval', $config->arr( 'ChainSettings', 'Chains' ) ),
            function ( $id ) { return preg_match( '/^[a-z0-9_]{1,40}$/', $id ); } ) ) );
    }

    /**
     * The chains a user may start (policy expai/chain, limitation Chain).
     * @return expAiChain[] id => chain (definitions with an error are left out)
     */
    public static function available( ?eZUser $user = null, ?expAiConfig $config = null )
    {
        $out = array();
        foreach ( self::ids( $config ) as $id )
        {
            if ( !expAiChainPolicy::mayUse( $id, $user ) )
                continue;
            try
            {
                $out[$id] = self::load( $id, $config );
            }
            catch ( expAiException $e )
            {
                eZDebug::writeWarning( 'expai chain ' . $id . ': ' . $e->detail(), __METHOD__ );
            }
        }
        return $out;
    }

    /**
     * Reads and checks a chain of the settings.
     * @return expAiChain
     * @throws expAiException when it is not listed or not valid
     */
    public static function load( $id, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $id = (string) $id;
        if ( !in_array( $id, self::ids( $config ), true ) )
            throw new expAiException( 'There is no chain "%chain".', 'chain ' . $id . ' is not in [ChainSettings] Chains[]', 0, array( '%chain' => $id ) );
        $ini = $config->ini();
        if ( !$ini->hasGroup( 'Chain_' . $id ) )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'no group [Chain_' . $id . ']' );
        $group = $ini->group( 'Chain_' . $id );
        $steps = array();
        foreach ( self::stepNames( $id, $group, $ini ) as $name )
            $steps[$name] = $ini->hasGroup( 'ChainStep_' . $id . '.' . $name ) ? $ini->group( 'ChainStep_' . $id . '.' . $name ) : null;
        $chain = self::fromArrays( $id, $group, $steps );
        $chain->resolvePrompts( $config );
        return $chain;
    }

    /** @return string[] the steps of Steps[] and every step they reach (ReviseStep, Next) */
    protected static function stepNames( $id, array $group, eZINI $ini )
    {
        $names = isset( $group['Steps'] ) ? array_values( array_filter( array_map( 'strval', (array) $group['Steps'] ) ) ) : array();
        for ( $i = 0; $i < count( $names ) && $i < 50; ++$i )
        {
            $g = 'ChainStep_' . $id . '.' . $names[$i];
            if ( !$ini->hasGroup( $g ) )
                continue;
            foreach ( array( 'ReviseStep', 'Next' ) as $key )
            {
                $value = $ini->hasVariable( $g, $key ) ? trim( (string) $ini->variable( $g, $key ) ) : '';
                if ( $value !== '' && !in_array( $value, $names, true ) )
                    $names[] = $value;
            }
        }
        return $names;
    }

    /**
     * Builds and validates a definition from settings arrays (also the unit tests).
     * @param array $group the [Chain_<id>] values
     * @param array $steps step name => the [ChainStep_<id>.<step>] values (null: the group is missing)
     * @return expAiChain
     * @throws expAiException
     */
    public static function fromArrays( $id, array $group, array $steps )
    {
        $fail = function ( $why ) use ( $id )
        {
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'chain ' . $id . ': ' . $why );
        };
        $get = function ( array $a, $key, $default = '' ) { return isset( $a[$key] ) && !is_array( $a[$key] ) ? trim( (string) $a[$key] ) : $default; };
        $list = function ( array $a, $key ) { return isset( $a[$key] ) ? array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $a[$key] ) ), 'strlen' ) ) : array(); };

        $order = $list( $group, 'Steps' );
        if ( !$order )
            $fail( 'Steps[] is empty' );
        $def = array( 'id' => (string) $id, 'label' => $get( $group, 'Label', $id ), 'description' => $get( $group, 'Description' ),
                      'class' => $get( $group, 'Class', 'article' ), 'fields' => $list( $group, 'Fields' ), 'order' => $order,
                      'max_cost' => (float) $get( $group, 'MaxCostPerRun', '0' ), 'max_items' => max( 1, (int) $get( $group, 'MaxItems', '5' ) ),
                      'steps' => array() );
        if ( !$def['fields'] )
            $fail( 'Fields[] is empty (the fields the generate steps write)' );
        foreach ( $steps as $name => $values )
        {
            if ( !preg_match( '/^[a-z0-9_]{1,40}$/', (string) $name ) )
                $fail( 'step name ' . $name . ' (a-z, 0-9, _)' );
            if ( $values === null )
                $fail( 'no group [ChainStep_' . $id . '.' . $name . ']' );
            $type = strtolower( $get( $values, 'Type' ) );
            if ( !in_array( $type, self::TYPES, true ) )
                $fail( 'step ' . $name . ': Type must be one of ' . implode( ', ', self::TYPES ) );
            $step = array( 'name' => (string) $name, 'type' => $type, 'label' => $get( $values, 'Label', ucfirst( str_replace( '_', ' ', $name ) ) ),
                           'model' => $get( $values, 'Model' ), 'lane' => $get( $values, 'Lane', 'auto' ),
                           'prompt_template' => $get( $values, 'PromptTemplate' ), 'prompt_text' => $get( $values, 'PromptText' ),
                           'tools' => $list( $values, 'Tools' ), 'criteria' => expAiChainCriteria::parse( $list( $values, 'PassCriteria' ) ),
                           'checklist' => $list( $values, 'Checklist' ), 'max_iterations' => max( 1, min( 10, (int) $get( $values, 'MaxIterations', '1' ) ) ),
                           'on_fail' => strtolower( $get( $values, 'OnFail', 'escalate' ) ), 'revise_step' => $get( $values, 'ReviseStep', 'revise' ),
                           'on_exhausted' => strtolower( $get( $values, 'OnExhausted', 'escalate' ) ),
                           'checkpoint' => strtolower( $get( $values, 'Checkpoint', 'none' ) ), 'next' => $get( $values, 'Next' ),
                           'max_tokens' => max( 256, min( 64000, (int) $get( $values, 'MaxTokens', '4000' ) ) ),
                           'output_fields' => $list( $values, 'OutputFields' ), 'languages' => $list( $values, 'Languages' ) );
            if ( !in_array( $step['on_fail'], array( 'revise', 'stop', 'escalate' ), true ) )
                $fail( 'step ' . $name . ': OnFail must be revise, stop or escalate' );
            if ( !in_array( $step['on_exhausted'], array( 'stop', 'escalate' ), true ) )
                $fail( 'step ' . $name . ': OnExhausted must be stop or escalate' );
            if ( !in_array( $step['checkpoint'], array( 'human', 'none' ), true ) )
                $fail( 'step ' . $name . ': Checkpoint must be human or none' );
            if ( in_array( $type, array( 'generate', 'review', 'transform' ), true ) )
            {
                if ( $step['model'] === '' )
                    $fail( 'step ' . $name . ': Model is required' );
                if ( $step['prompt_template'] === '' && $step['prompt_text'] === '' )
                    $fail( 'step ' . $name . ': PromptTemplate or PromptText is required' );
                if ( $step['prompt_template'] !== '' && ( !preg_match( '~^[A-Za-z0-9_][A-Za-z0-9_./-]*\.tpl$~', $step['prompt_template'] )
                                                          || strpos( $step['prompt_template'], '..' ) !== false ) )
                    $fail( 'step ' . $name . ': PromptTemplate must be a relative .tpl path' );
            }
            if ( $type === 'review' && !$step['criteria'] )
                $fail( 'step ' . $name . ': a review needs PassCriteria[]' );
            $def['steps'][$name] = $step;
        }
        foreach ( $def['steps'] as $name => $step )
        {
            if ( $step['next'] !== '' && !isset( $def['steps'][$step['next']] ) )
                $fail( 'step ' . $name . ': Next=' . $step['next'] . ' is not a step' );
            if ( $step['on_fail'] === 'revise' && ( $step['criteria'] || $step['type'] === 'review' ) )
            {
                if ( !isset( $def['steps'][$step['revise_step']] ) || $def['steps'][$step['revise_step']]['type'] !== 'generate' )
                    $fail( 'step ' . $name . ': ReviseStep=' . $step['revise_step'] . ' must be a generate step' );
            }
        }
        foreach ( $order as $name )
            if ( !isset( $def['steps'][$name] ) )
                $fail( 'step ' . $name . ' of Steps[] has no group' );
        if ( $def['steps'][$order[0]]['type'] !== 'generate' )
            $fail( 'the first step must be a generate step' );
        return new self( $def );
    }

    /** Reads the prompt templates into the definition (the snapshot carries the texts). */
    protected function resolvePrompts( expAiConfig $config )
    {
        foreach ( $this->def['steps'] as $name => $step )
        {
            if ( $step['prompt_template'] === '' )
                continue;
            $file = expAiChainPrompt::find( $step['prompt_template'], $config );
            if ( $file === null )
                throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'chain ' . $this->def['id'] . ': prompt template ' . $step['prompt_template'] . ' not found' );
            $this->def['steps'][$name]['prompt_text'] = (string) file_get_contents( $file );
        }
    }

    /** @return expAiChain a definition from a run's snapshot */
    public static function fromSnapshot( array $def )
    {
        return new self( $def );
    }

    /** @return array the definition with the prompt texts (the run's snapshot) */
    public function toArray()
    {
        return $this->def;
    }

    /** @return string sha1 of the definition (the run shows when its chain has changed since) */
    public function hash()
    {
        return sha1( json_encode( $this->def ) );
    }

    public function id() { return $this->def['id']; }
    public function label() { return $this->def['label']; }
    public function description() { return $this->def['description']; }
    public function classIdentifier() { return $this->def['class']; }
    public function fields() { return $this->def['fields']; }
    public function order() { return $this->def['order']; }
    public function maxItems() { return $this->def['max_items']; }

    /** @return int the budget of one run in micros (0: none) */
    public function maxCostMicros()
    {
        return $this->def['max_cost'] > 0 ? (int) round( $this->def['max_cost'] * 1000000 ) : 0;
    }

    /** @return array|null a step */
    public function step( $name )
    {
        return isset( $this->def['steps'][$name] ) ? $this->def['steps'][$name] : null;
    }

    /** @return string[] the step names, the ordered steps first */
    public function stepNamesAll()
    {
        return array_keys( $this->def['steps'] );
    }

    /**
     * The step after a step: Next=, else the following step of Steps[] (a step outside Steps[], e.g. revise, without
     * Next= continues after the review that sent it, which the engine passes as $after).
     * @return string '' at the end
     */
    public function nextAfter( $name, $after = '' )
    {
        $step = $this->step( $name );
        if ( $step && $step['next'] !== '' )
            return $step['next'];
        $position = array_search( $after !== '' ? $after : $name, $this->def['order'], true );
        if ( $position === false || !isset( $this->def['order'][$position + 1] ) )
            return '';
        return $this->def['order'][$position + 1];
    }

    /**
     * What the review/revise loop does after a failed check.
     * @param array $step the checking step
     * @param int $reviews how many times this step has run in the run (including this one)
     * @return string revise | stop | escalate
     */
    public static function onFailure( array $step, $reviews )
    {
        if ( $step['on_fail'] !== 'revise' )
            return $step['on_fail'];
        return (int) $reviews < (int) $step['max_iterations'] ? 'revise' : $step['on_exhausted'];
    }

    /**
     * Whether the run may spend more: what it cost so far plus the estimate of the next step within the run budget.
     * @return bool
     */
    public static function budgetAllows( $spentMicros, $estimateMicros, $capMicros )
    {
        return (int) $capMicros <= 0 || (int) $spentMicros + (int) $estimateMicros <= (int) $capMicros;
    }
}
