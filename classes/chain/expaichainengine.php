<?php
/**
 * File containing the expAiChainEngine class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Runs AI chains (doc/chains.md) through the job queue.
 *
 * Every step that calls a model is one queued request (expAiQueue::request(), job type expai.request) in the run's
 * job group, on the step's lane, with the run's budget as the group budget, and with the applier "chain"
 * (expAiChainApplier). The applier runs in the worker once the answer is stored: it records the step (tokens, cost,
 * model, lane, verdict, score), keeps the text so far in the run, decides what comes next (the next step, a revision,
 * a checkpoint, the end) and queues it. So a step is queued only after the one before it is done, and a rerun of a
 * job after a crash finds the step already recorded and changes nothing.
 *
 * Drafts only: the run writes one draft (expAiContentWriter, as the user who started the run, expai_draft_log source
 * "chain") when it reaches a human checkpoint or its end, and writes into the same draft after a revision.
 * Publishing stays with the editor.
 */
class expAiChainEngine
{
    const APPLIER = 'chain';

    /** @var expAiConfig */
    protected $config;

    public function __construct( ?expAiConfig $config = null )
    {
        $this->config = $config ?: expAiConfig::instance();
    }

    // ------------------------------------------------------------------ start

    /**
     * Starts runs of a chain: one run (and job group) per item.
     *
     * @param string $chainID
     * @param int $nodeID the location the drafts are created under
     * @param string $brief what to write
     * @param int $count how many items (runs), at most the chain's MaxItems
     * @param array $options language, lane (overrides every model step's lane), user_id (CLI)
     * @return expAiChainRun[]
     * @throws expAiException
     */
    public function start( $chainID, $nodeID, $brief, $count = 1, array $options = array() )
    {
        if ( !expAiQueue::available() )
            throw new expAiException( 'The job queue is not available.', 'the extension expqueue is not active or its tables are missing' );
        if ( !$this->config->isEnabled() || !$this->config->isConfigured() )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'expai is disabled or has no key' );
        $user = eZUser::currentUser();
        if ( !expAiChainPolicy::mayUse( $chainID, $user ) || !expAiServerFunctions::hasPolicy( 'write' ) )
            throw new expAiException( 'You may not start the chain "%chain".', 'policy expai/chain or expai/write missing', 403, array( '%chain' => (string) $chainID ) );
        $chain = expAiChain::load( $chainID, $this->config );
        $brief = trim( (string) $brief );
        $max = $this->config->int( 'ChainSettings', 'MaxBriefLength', 4000, 100 );
        if ( $brief === '' )
            throw new expAiException( 'Write a brief: what should the chain write?', 'empty brief' );
        $brief = mb_substr( $brief, 0, $max, 'UTF-8' );
        $node = eZContentObjectTreeNode::fetch( (int) $nodeID );
        if ( !$node instanceof eZContentObjectTreeNode || !$node->canRead() )
            throw new expAiException( 'Node %id does not exist or you may not read it.', '', 0, array( '%id' => (int) $nodeID ) );
        $class = eZContentClass::fetchByIdentifier( $chain->classIdentifier() );
        if ( !$class instanceof eZContentClass )
            throw new expAiException( 'There is no content class "%identifier".', '', 0, array( '%identifier' => $chain->classIdentifier() ) );
        $writer = new expAiContentWriter( $this->config );
        $language = $writer->defaultLanguage( isset( $options['language'] ) ? (string) $options['language'] : '' );
        if ( $node->checkAccess( 'create', $class->attribute( 'id' ), $node->object()->attribute( 'contentclass_id' ), false, $language ) != 1 )
            throw new expAiException( 'You may not create %class objects in %language under this node.', '', 0,
                                      array( '%class' => $chain->classIdentifier(), '%language' => $language ) );
        $count = max( 1, min( $chain->maxItems(), (int) $count ) );
        $runs = array();
        for ( $item = 1; $item <= $count; ++$item )
        {
            $label = expAiText::cut( $chain->label() . ': ' . expAiText::oneLine( $brief ), 140 ) . ( $count > 1 ? ' (' . $item . '/' . $count . ')' : '' );
            $group = expQueue::createGroup( $label, 'expai.chain', array( 'budget_micros' => $chain->maxCostMicros(),
                                                                          'user_id' => (int) $user->attribute( 'contentobject_id' ) ) );
            // #385 an insert that failed (SQLite: "database is locked" while another process writes) leaves the object
            // without its id; going on would queue steps into a group that does not exist and store the run as row 0
            if ( (int) $group->attribute( 'id' ) <= 0 )
                throw new expAiException( 'The chain could not be started. Please try again.', 'the job group of the run could not be stored' );
            $now = time();
            $run = new expAiChainRun( array(
                'uuid' => self::uuid(), 'chain' => $chain->id(), 'chain_hash' => $chain->hash(), 'label' => $label,
                'user_id' => (int) $user->attribute( 'contentobject_id' ), 'group_uuid' => (string) $group->attribute( 'uuid' ),
                'node_id' => (int) $node->attribute( 'node_id' ), 'language' => $language, 'class_identifier' => $chain->classIdentifier(),
                'brief' => $brief, 'item' => $item, 'item_count' => $count, 'state' => expAiChainRun::STATE_RUNNING,
                'snapshot' => json_encode( $chain->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'data' => json_encode( array( 'document' => new stdClass(), 'lane' => isset( $options['lane'] ) ? (string) $options['lane'] : '' ) ),
                'created' => $now, 'modified' => $now ) );
            $run->store();
            if ( (int) $run->attribute( 'id' ) <= 0 )
            {
                if ( class_exists( 'expQueueGroup' ) && expAiConfig::extensionActive( 'expqueue' ) && method_exists( $group, 'remove' ) )
                    $group->remove();
                throw new expAiException( 'The chain could not be started. Please try again.', 'the run could not be stored' );
            }
            $this->enqueueStep( $run, $chain->order()[0], 0 );
            $runs[] = expAiChainRun::fetch( (int) $run->attribute( 'id' ) );
        }
        return $runs;
    }

    // ------------------------------------------------------------------ steps

    /**
     * Queues a step (or performs it: a checkpoint, a hand-off).
     * @param string $after for a step outside Steps[] (a revision): the step whose place in Steps[] it continues from
     */
    public function enqueueStep( expAiChainRun $run, $name, $iteration, $after = '' )
    {
        $chain = $run->chain();
        $step = $chain->step( $name );
        if ( !$step )
            return $this->fail( $run, 'the step ' . $name . ' is not part of the chain' );
        $run->setAttribute( 'current_step', $name );
        $run->setAttribute( 'state', expAiChainRun::STATE_RUNNING );
        $run->touch();
        if ( $step['type'] === 'human' )
            return $this->pause( $run, $step, (int) $iteration, $chain->nextAfter( $name, $after ), '' );
        if ( $step['type'] === 'translate' )
            return $this->handOff( $run, $step, (int) $iteration, $chain->nextAfter( $name, $after ) );

        $row = $this->stepRow( $run, $name, $iteration );
        if ( $row->attribute( 'job_uuid' ) !== '' || $row->attribute( 'state' ) !== 'queued' )
            return $row;
        $request = $this->buildRequest( $run, $chain, $step );
        $alias = $this->config->registry()->resolveAlias( $step['model'] );
        if ( $alias === '' )
            return $this->fail( $run, 'the model ' . $step['model'] . ' of the step ' . $name . ' is not in the provider registry' );
        // the run's own budget: what it cost so far and the estimate of this step
        $budget = new expAiBudget( $this->config );
        $estimate = $budget->estimate( $alias, $budget->estimateInputTokens( $request ),
                                       (int) ceil( $request['max_tokens'] * (float) $this->config->get( 'Budgets', 'OutputShareOfMax', 0.5 ) ) );
        $cap = $this->capMicros( $run, $chain );
        if ( !expAiChain::budgetAllows( (int) $run->attribute( 'cost_micros' ), $estimate['micros'], $cap ) )
        {
            $row->setAttribute( 'state', 'held' );
            $row->setAttribute( 'note', expAiText::cut( 'held: the run budget of ' . expAiBudget::money( $cap ) . ' does not allow ' . expAiBudget::money( $estimate['micros'] ) . ' more', 255 ) );
            $row->store();
            $run->mergeData( array( 'pending' => array( 'step' => $name, 'iteration' => (int) $iteration, 'after' => (string) $after ) ) );
            $run->setAttribute( 'state', expAiChainRun::STATE_HELD );
            $run->setAttribute( 'last_error', expAiText::cut( 'the run budget of ' . expAiBudget::money( $cap ) . ' is reached (' . expAiBudget::money( (int) $run->attribute( 'cost_micros' ) ) . ' spent)', 255 ) );
            $run->touch();
            return $row;
        }
        $data = $run->dataArray();
        $lane = !empty( $data['lane'] ) ? (string) $data['lane'] : $step['lane'];
        $objects = array_values( array_filter( array( (int) $run->attribute( 'object_id' ), $this->nodeObjectID( $run ) ) ) );
        try
        {
            $r = expAiQueue::request( $alias, $request, array(
                'purpose' => 'chain', 'class' => 'chain', 'group' => (string) $run->attribute( 'group_uuid' ), 'lane' => $lane !== '' ? $lane : 'auto',
                'objects' => $objects, 'node_id' => (int) $run->attribute( 'node_id' ), 'user_id' => (int) $run->attribute( 'user_id' ),
                'label' => 'Chain ' . $chain->id() . ' #' . $run->attribute( 'id' ) . ': ' . $step['label'],
                'idempotency_key' => 'chain:' . $run->attribute( 'uuid' ) . ':' . $name . ':' . (int) $iteration,
                'apply' => self::APPLIER, 'apply_params' => array( 'run' => (int) $run->attribute( 'id' ), 'step' => $name, 'iteration' => (int) $iteration, 'after' => (string) $after ),
                'tags' => array( 'chain:' . $chain->id() ) ) );
        }
        catch ( Exception $e )
        {
            $row->setAttribute( 'state', 'failed' );
            $row->setAttribute( 'note', expAiText::cut( 'not queued: ' . $e->getMessage(), 255 ) );
            $row->store();
            $this->fail( $run, 'the step ' . $name . ' could not be queued: ' . ( $e instanceof expAiException ? strtr( $e->getMessage(), $e->arguments() ) . ' (' . $e->detail() . ')' : $e->getMessage() ) );
            return $row;
        }
        $job = $r['job'];
        $model = $this->config->registry()->getModel( $alias );
        $row = expAiChainStep::fetchObject( expAiChainStep::definition(), null, array( 'id' => (int) $row->attribute( 'id' ) ) );
        $row->setAttribute( 'job_uuid', (string) $job->attribute( 'uuid' ) );
        $row->setAttribute( 'model', $alias );
        $row->setAttribute( 'provider', $model ? (string) $model['provider'] : '' );
        $row->setAttribute( 'lane', $r['lane']->name() );
        $row->setAttribute( 'input_hash', sha1( json_encode( $request ) ) );
        if ( $r['held'] !== '' )
            $row->setAttribute( 'note', expAiText::cut( 'held: ' . $r['held'], 255 ) );
        $row->store();
        if ( $r['held'] !== '' )
        {
            $run->setAttribute( 'state', expAiChainRun::STATE_HELD );
            $run->setAttribute( 'last_error', expAiText::cut( $r['held'], 255 ) );
            $run->touch();
        }
        return $row;
    }

    /**
     * The row of a step and iteration (created before the job is queued, so the applier always finds it).
     * @return expAiChainStep
     */
    protected function stepRow( expAiChainRun $run, $name, $iteration, $state = 'queued' )
    {
        $row = eZPersistentObject::fetchObject( expAiChainStep::definition(), null,
            array( 'run_id' => (int) $run->attribute( 'id' ), 'step' => (string) $name, 'iteration' => (int) $iteration ) );
        if ( $row instanceof expAiChainStep )
            return $row;
        $step = $run->chain()->step( $name );
        $row = new expAiChainStep( array( 'run_id' => (int) $run->attribute( 'id' ), 'step' => (string) $name, 'step_type' => $step ? $step['type'] : '',
                                          'iteration' => (int) $iteration, 'language' => (string) $run->attribute( 'language' ), 'state' => $state,
                                          'created' => time() ) );
        $row->store();
        return $row;
    }

    /**
     * The request of a model step: the shared system prompt, the step's prompt with the run's values, the output
     * schema of the step type.
     * @return array system, messages, max_tokens, output_schema
     */
    public function buildRequest( expAiChainRun $run, expAiChain $chain, array $step )
    {
        $values = $this->promptValues( $run, $chain, $step );
        return array( 'system' => expAiChainPrompt::system( $step, $this->config ),
                      'messages' => array( array( 'role' => 'user', 'content' => expAiChainPrompt::render( $step['prompt_text'], $values ) ) ),
                      // the step's MaxTokens, at least expAiModelTest::REASONING_MIN_TOKENS for a reasoning model
                      'max_tokens' => expAiModelTest::outputBudget( $this->config->registry()->resolveAlias( (string) $step['model'] ), (int) $step['max_tokens'], $this->config ),
                      'output_schema' => self::outputSchema( $step, $chain ) );
    }

    /** @return array the placeholders of a step's prompt */
    public function promptValues( expAiChainRun $run, expAiChain $chain, array $step )
    {
        $data = $run->dataArray();
        $class = eZContentClass::fetchByIdentifier( $chain->classIdentifier() );
        $fields = array();
        if ( $class instanceof eZContentClass )
            foreach ( $class->fetchAttributes() as $attribute )
                if ( in_array( $attribute->attribute( 'identifier' ), self::writtenFields( $step, $chain ), true ) )
                    $fields[] = '- ' . $attribute->attribute( 'identifier' ) . ' (' . $attribute->attribute( 'data_type_string' ) . ', "' . $attribute->attribute( 'name' ) . '"'
                              . ( $attribute->attribute( 'is_required' ) ? ', required' : '' )
                              . ( $attribute->attribute( 'data_type_string' ) === 'ezxmltext' ? ', HTML' : '' ) . ')';
        $issues = array();
        foreach ( isset( $data['review']['issues'] ) ? (array) $data['review']['issues'] : array() as $issue )
            if ( is_array( $issue ) )
                $issues[] = '- [' . ( isset( $issue['severity'] ) ? $issue['severity'] : 'medium' ) . '] ' . ( !empty( $issue['field'] ) ? $issue['field'] . ': ' : '' )
                          . ( isset( $issue['text'] ) ? expAiText::oneLine( $issue['text'] ) : '' );
        if ( !empty( $data['review']['failed'] ) )
            $issues[] = '- Not met: ' . implode( '; ', (array) $data['review']['failed'] );
        $node = $run->node();
        $language = eZContentLanguage::fetchByLocale( (string) $run->attribute( 'language' ) );
        $document = isset( $data['document'] ) ? (array) $data['document'] : array();
        return array(
            'brief' => (string) $run->attribute( 'brief' ),
            'chain' => $chain->label(),
            'class_name' => $class instanceof eZContentClass ? (string) $class->attribute( 'name' ) : $chain->classIdentifier(),
            'class_identifier' => $chain->classIdentifier(),
            'fields' => implode( "\n", $fields ),
            'language' => ( $language ? $language->attribute( 'name' ) . ' ' : '' ) . '(' . $run->attribute( 'language' ) . ')',
            'location' => $node ? '"' . $node->attribute( 'name' ) . '" (node ' . (int) $node->attribute( 'node_id' ) . ', ' . $node->attribute( 'path_identification_string' ) . ')' : '',
            'context' => $this->toolContext( $run, $chain, $step ),
            'item' => (string) (int) $run->attribute( 'item' ),
            'item_count' => (string) (int) $run->attribute( 'item_count' ),
            'document' => $document ? json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '',
            'issues' => implode( "\n", $issues ),
            'checklist' => implode( "\n", array_map( function ( $c ) { return '- ' . $c; }, $step['checklist'] ) ),
            'note' => isset( $data['note'] ) ? (string) $data['note'] : '',
            'seo' => !empty( $data['seo'] ) ? json_encode( $data['seo'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '',
        );
    }

    /** @return string[] the fields a generate or transform step writes */
    public static function writtenFields( array $step, expAiChain $chain )
    {
        if ( $step['type'] === 'transform' )
            return $step['output_fields'];
        return $step['output_fields'] ? $step['output_fields'] : $chain->fields();
    }

    /**
     * The read tools of a step (Tools[]), run before the call as the run's user: a chain step is one structured
     * call (which batch and flex lanes can take), so its context is gathered beforehand. Writing tools are refused.
     * @return string JSON lines "tool: result"
     */
    protected function toolContext( expAiChainRun $run, expAiChain $chain, array $step )
    {
        if ( !$step['tools'] )
            return '';
        $registry = new expAiToolRegistry( $this->config, false );
        $context = new expAiToolContext( $this->config, (int) $run->attribute( 'node_id' ) );
        // the steps of a chain run in the queue (purpose queue.chain, as the gate sees them)
        $context->reader->setRequest( array( 'purpose' => 'queue.chain' ) );
        $max = $this->config->int( 'ChainSettings', 'MaxContextChars', 12000, 500 );
        $out = array();
        foreach ( $step['tools'] as $name )
        {
            $tool = $registry->get( $name );
            if ( !$tool || $tool->writes() )
            {
                $out[] = $name . ': (not available to chain steps)';
                continue;
            }
            $schema = $tool->inputSchema();
            $properties = isset( $schema['properties'] ) ? (array) $schema['properties'] : array();
            $input = array();
            foreach ( array( 'node_id' => (int) $run->attribute( 'node_id' ), 'parent_node_id' => (int) $run->attribute( 'node_id' ),
                             'class_identifier' => $chain->classIdentifier() ) as $key => $value )
                if ( isset( $properties[$key] ) )
                    $input[$key] = $value;
            foreach ( isset( $schema['required'] ) ? (array) $schema['required'] : array() as $required )
                if ( !isset( $input[$required] ) )
                    continue 2;
            try
            {
                $result = $tool->execute( $input, $context );
                $out[] = $name . ': ' . json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            }
            catch ( expAiException $e )
            {
                $out[] = $name . ': (failed: ' . strtr( $e->getMessage(), $e->arguments() ) . ')';
            }
        }
        return expAiText::cut( implode( "\n", $out ), $max );
    }

    /** @return array the JSON schema of a step type's answer */
    public static function outputSchema( array $step, expAiChain $chain )
    {
        $issues = array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
            'severity' => array( 'type' => 'string', 'enum' => array( 'high', 'medium', 'low' ) ),
            'field' => array( 'type' => 'string' ), 'text' => array( 'type' => 'string' ) ),
            'required' => array( 'severity', 'field', 'text' ), 'additionalProperties' => false ) );
        $fieldsOf = function ( array $names )
        {
            $props = array();
            foreach ( $names as $name )
                $props[$name] = array( 'type' => 'string' );
            return array( 'type' => 'object', 'properties' => $props ?: new stdClass(), 'required' => array_values( $names ), 'additionalProperties' => false );
        };
        switch ( $step['type'] )
        {
            case 'review':
                return array( 'type' => 'object', 'properties' => array(
                    'score' => array( 'type' => 'integer', 'description' => '0 (unusable) to 100 (ready to publish)' ),
                    'summary' => array( 'type' => 'string' ),
                    'issues' => $issues,
                    'checklist' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
                        'item' => array( 'type' => 'string' ), 'ok' => array( 'type' => 'boolean' ) ), 'required' => array( 'item', 'ok' ), 'additionalProperties' => false ) ) ),
                    'required' => array( 'score', 'summary', 'issues', 'checklist' ), 'additionalProperties' => false );
            case 'transform':
                return array( 'type' => 'object', 'properties' => array(
                    'fields' => $fieldsOf( $step['output_fields'] ),
                    'meta' => array( 'type' => 'object', 'properties' => array(
                        'title' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ),
                        'keywords' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ),
                        'required' => array( 'title', 'description', 'keywords' ), 'additionalProperties' => false ),
                    'issues' => $issues ),
                    'required' => array( 'fields', 'meta', 'issues' ), 'additionalProperties' => false );
            default:
                return array( 'type' => 'object', 'properties' => array(
                    'fields' => $fieldsOf( self::writtenFields( $step, $chain ) ),
                    'note' => array( 'type' => 'string' ) ),
                    'required' => array( 'fields', 'note' ), 'additionalProperties' => false );
        }
    }

    // ------------------------------------------------------------------ answers (the applier, in the worker)

    /**
     * Records the answer of a step and queues what comes next. Idempotent: a step already recorded is left alone.
     * @return array what the job's result shows
     */
    public function onAnswer( expQueueJob $job, array $answer, array $params )
    {
        $run = expAiChainRun::fetch( isset( $params['run'] ) ? (int) $params['run'] : 0 );
        if ( !$run )
            return array( 'chain' => 'the run is gone' );
        $name = isset( $params['step'] ) ? (string) $params['step'] : '';
        $iteration = isset( $params['iteration'] ) ? (int) $params['iteration'] : 0;
        $row = $this->stepRow( $run, $name, $iteration );
        if ( $row->attribute( 'state' ) !== 'queued' && $row->attribute( 'state' ) !== 'held' )
            return array( 'chain' => 'already recorded', 'run' => (int) $run->attribute( 'id' ), 'step' => $name );
        $row->setAttribute( 'job_uuid', (string) $job->attribute( 'uuid' ) );
        // input tokens with the cached prefix (written or read), which prompt caching reports apart
        $tokens = isset( $answer['tokens'] ) ? (array) $answer['tokens'] : array();
        $row->setAttribute( 'input_tokens', ( isset( $tokens['input'] ) ? (int) $tokens['input'] : 0 ) + ( isset( $tokens['cache_write'] ) ? (int) $tokens['cache_write'] : 0 )
                                            + ( isset( $tokens['cache_read'] ) ? (int) $tokens['cache_read'] : 0 ) );
        $row->setAttribute( 'output_tokens', isset( $answer['tokens']['output'] ) ? (int) $answer['tokens']['output'] : 0 );
        $row->setAttribute( 'cost_micros', isset( $answer['cost_micros'] ) ? (int) $answer['cost_micros'] : 0 );
        $row->setAttribute( 'usage_log_id', isset( $answer['usage_log_id'] ) ? (int) $answer['usage_log_id'] : 0 );
        $row->setAttribute( 'lane', isset( $answer['lane'] ) ? (string) $answer['lane'] : $row->attribute( 'lane' ) );
        $row->setAttribute( 'service_tier', isset( $answer['service_tier'] ) ? substr( (string) $answer['service_tier'], 0, 20 ) : '' );
        $row->setAttribute( 'finished', time() );
        $run->setAttribute( 'cost_micros', (int) $run->attribute( 'cost_micros' ) + (int) $row->attribute( 'cost_micros' ) );
        $run->setAttribute( 'input_tokens', (int) $run->attribute( 'input_tokens' ) + (int) $row->attribute( 'input_tokens' ) );
        $run->setAttribute( 'output_tokens', (int) $run->attribute( 'output_tokens' ) + (int) $row->attribute( 'output_tokens' ) );

        $cutOff = '';
        try
        {
            $value = self::answerValue( $answer );
        }
        catch ( expAiException $e )
        {
            $value = null;
            $cutOff = $e->getMessage();
        }
        if ( $run->isFinal() )
        {
            $row->setAttribute( 'state', 'done' );
            $row->setAttribute( 'note', 'the run had ended' );
            $row->setOutput( is_array( $value ) ? $value : array( 'text' => isset( $answer['text'] ) ? expAiText::cut( (string) $answer['text'], 2000 ) : '' ) );
            $row->store();
            $run->touch();
            return array( 'chain' => 'the run had ended', 'run' => (int) $run->attribute( 'id' ) );
        }
        if ( !is_array( $value ) )
        {
            $row->setAttribute( 'state', 'failed' );
            $row->setOutput( array( 'text' => isset( $answer['text'] ) ? expAiText::cut( (string) $answer['text'], 4000 ) : '' ) );
            $row->setAttribute( 'note', $cutOff !== '' ? 'the answer was cut off at the token limit (stop reason max_tokens)'
                                        : 'the answer was not the JSON the step asks for' . ( !empty( $answer['stop_reason'] ) ? ' (stop reason ' . $answer['stop_reason'] . ')' : '' ) );
            $row->store();
            $this->fail( $run, $cutOff !== '' ? 'the step ' . $name . ': ' . $cutOff : 'the step ' . $name . ' did not answer in the expected format' );
            return array( 'chain' => 'failed', 'run' => (int) $run->attribute( 'id' ), 'step' => $name );
        }
        try
        {
            $outcome = $this->record( $run, $row, $value, $params );
        }
        catch ( expAiException $e )
        {
            $this->fail( $run, strtr( $e->getMessage(), $e->arguments() ) . ( $e->detail() !== '' ? ' (' . $e->detail() . ')' : '' ) );
            return array( 'chain' => 'failed', 'run' => (int) $run->attribute( 'id' ), 'step' => $name, 'error' => expAiText::cut( $e->getMessage(), 200 ) );
        }
        return array( 'chain' => $outcome, 'run' => (int) $run->attribute( 'id' ), 'step' => $name, 'iteration' => $iteration,
                      'verdict' => (string) $row->attribute( 'verdict' ), 'score' => (int) $row->attribute( 'score' ) );
    }

    /**
     * The structured value of a stored answer: its value, or the JSON object in its text. An answer cut off at the
     * token limit is not read at all (a cut JSON object is never complete, and a shorter one inside it is not the
     * answer).
     * @param array $answer the stored answer (expAiRequestHandler::storedAnswer()): value, text, stop_reason
     * @return array|null null when the answer has no JSON object
     * @throws expAiException when the answer was cut off at the token limit
     */
    public static function answerValue( array $answer )
    {
        if ( isset( $answer['stop_reason'] ) && $answer['stop_reason'] === 'max_tokens' )
            throw new expAiException( 'The answer was cut off at the token limit. Raise MaxTokens or lower Effort for this model.', 'stop_reason max_tokens' );
        if ( isset( $answer['value'] ) && is_array( $answer['value'] ) )
            return $answer['value'];
        return expAiText::extractJson( expAiText::stripReasoning( isset( $answer['text'] ) ? (string) $answer['text'] : '' ) );
    }

    /**
     * Records the structured answer of a step and continues the run.
     * @return string what happened (next, revise, checkpoint, done, stopped)
     */
    protected function record( expAiChainRun $run, expAiChainStep $row, array $value, array $params )
    {
        $chain = $run->chain();
        $name = (string) $row->attribute( 'step' );
        $step = $chain->step( $name );
        $after = isset( $params['after'] ) ? (string) $params['after'] : '';
        $data = $run->dataArray();
        $document = isset( $data['document'] ) ? (array) $data['document'] : array();
        $result = array();
        if ( $step['type'] === 'generate' )
        {
            $fields = self::cleanFields( isset( $value['fields'] ) ? $value['fields'] : array(), self::writtenFields( $step, $chain ) );
            $document = array_merge( $document, $fields );
            $row->setOutput( array( 'fields' => $fields, 'note' => isset( $value['note'] ) ? expAiText::cut( (string) $value['note'], 1000 ) : '' ) );
            $run->mergeData( array( 'document' => $document, 'note' => null ) );
            $result = array( 'missing' => array_values( array_diff( self::writtenFields( $step, $chain ), array_keys( array_filter( $fields, 'strlen' ) ) ) ) );
        }
        elseif ( $step['type'] === 'review' )
        {
            $result = self::cleanReview( $value, $step );
            $row->setOutput( $result );
            $row->setAttribute( 'score', $result['score'] === null ? -1 : (int) $result['score'] );
            $row->setAttribute( 'issue_count', count( $result['issues'] ) );
        }
        else // transform
        {
            $fields = self::cleanFields( isset( $value['fields'] ) ? $value['fields'] : array(), $step['output_fields'] );
            $document = array_merge( $document, $fields );
            $meta = isset( $value['meta'] ) && is_array( $value['meta'] ) ? $value['meta'] : array();
            $seo = array( 'title' => expAiText::cut( expAiText::oneLine( isset( $meta['title'] ) ? (string) $meta['title'] : '' ), 200 ),
                          'description' => expAiText::cut( expAiText::oneLine( isset( $meta['description'] ) ? (string) $meta['description'] : '' ), 400 ),
                          'keywords' => array_slice( array_values( array_filter( array_map( function ( $k ) { return expAiText::cut( expAiText::oneLine( (string) $k ), 60 ); },
                                                     isset( $meta['keywords'] ) && is_array( $meta['keywords'] ) ? $meta['keywords'] : array() ), 'strlen' ) ), 0, 20 ) );
            $links = self::checkLinks( $document );
            $result = array( 'issues' => self::cleanIssues( isset( $value['issues'] ) ? $value['issues'] : array() ), 'links' => $links );
            $row->setOutput( array( 'fields' => $fields, 'meta' => $seo, 'issues' => $result['issues'], 'links' => $links ) );
            $row->setAttribute( 'issue_count', count( $result['issues'] ) + count( array_filter( $links, function ( $l ) { return empty( $l['ok'] ); } ) ) );
            $run->mergeData( array( 'document' => $document, 'seo' => $seo, 'links' => $links ) );
        }
        $row->setAttribute( 'state', 'done' );
        $verdict = '';
        $failed = array();
        if ( $step['criteria'] )
        {
            $evaluation = expAiChainCriteria::evaluate( $step['criteria'], $result );
            $verdict = $evaluation['pass'] ? 'pass' : 'fail';
            $failed = $evaluation['failed'];
            if ( $failed )
                $row->setAttribute( 'note', expAiText::cut( 'not met: ' . implode( '; ', $failed ), 255 ) );
        }
        $row->setAttribute( 'verdict', $verdict );
        $row->store();
        if ( $step['type'] === 'review' )
            $run->mergeData( array( 'review' => $result + array( 'failed' => $failed, 'step' => $name ) ) );
        $run->touch();

        $next = $chain->nextAfter( $name, $after );
        if ( $verdict === 'fail' )
        {
            $counts = isset( $data['review_counts'] ) ? (array) $data['review_counts'] : array();
            $counts[$name] = ( isset( $counts[$name] ) ? (int) $counts[$name] : 0 ) + 1;
            $run->mergeData( array( 'review_counts' => $counts ) );
            $run->touch();
            $action = expAiChain::onFailure( $step, $counts[$name] );
            if ( $action === 'revise' )
            {
                $this->enqueueStep( $run, $step['revise_step'], $this->nextIteration( $run, $step['revise_step'] ), $name );
                return 'revise';
            }
            if ( $action === 'stop' )
            {
                $this->fail( $run, 'the step ' . $name . ' did not pass: ' . implode( '; ', $failed ) );
                return 'stopped';
            }
            // escalate: the run goes on and an editor decides at the next checkpoint, with the reasons shown there;
            // without a checkpoint ahead, the run waits for an editor here
            $this->flag( $run, $step['label'] . ': ' . implode( '; ', $failed ) );
            if ( $this->nextHuman( $chain, $name, $after ) === '' && $step['checkpoint'] !== 'human' )
            {
                $this->pause( $run, $step, (int) $row->attribute( 'iteration' ), $next, 'escalated' );
                return 'checkpoint';
            }
        }
        if ( $step['checkpoint'] === 'human' )
        {
            $this->pause( $run, $step, (int) $row->attribute( 'iteration' ), $next, 'checkpoint' );
            return 'checkpoint';
        }
        return $this->advance( $run, $next );
    }

    /** @return string the next step, or 'done' */
    protected function advance( expAiChainRun $run, $next )
    {
        if ( $next === '' )
        {
            $this->finish( $run );
            return 'done';
        }
        $this->enqueueStep( $run, $next, $this->nextIteration( $run, $next ) );
        return 'next';
    }

    /** @return int the iteration of the next run of a step (0 for the first) */
    protected function nextIteration( expAiChainRun $run, $name )
    {
        $rows = eZPersistentObject::fetchObjectList( expAiChainStep::definition(), array( 'iteration' ), array( 'run_id' => (int) $run->attribute( 'id' ), 'step' => (string) $name ),
                                                     array( 'iteration' => 'desc' ), array( 'offset' => 0, 'length' => 1 ), false );
        return $rows ? (int) $rows[0]['iteration'] + 1 : 0;
    }

    /** @return string the first human step after a step ('' when none) */
    protected function nextHuman( expAiChain $chain, $name, $after )
    {
        $seen = array();
        $current = $chain->nextAfter( $name, $after );
        while ( $current !== '' && !isset( $seen[$current] ) )
        {
            $seen[$current] = true;
            $step = $chain->step( $current );
            if ( $step && $step['type'] === 'human' )
                return $current;
            $current = $chain->nextAfter( $current );
        }
        return '';
    }

    /** Adds a line an editor should read at the checkpoint. */
    protected function flag( expAiChainRun $run, $text )
    {
        $data = $run->dataArray();
        $flags = isset( $data['flags'] ) ? (array) $data['flags'] : array();
        $flags[] = expAiText::cut( (string) $text, 300 );
        $run->mergeData( array( 'flags' => array_slice( $flags, -10 ) ) );
        $run->touch();
    }

    // ------------------------------------------------------------------ checkpoint, hand-off, end

    /**
     * The human checkpoint: the draft is written, the run waits for an editor.
     * @param string $resume the step after the checkpoint ('' = the end)
     */
    protected function pause( expAiChainRun $run, array $step, $iteration, $resume, $why )
    {
        $this->writeDraft( $run );
        $run = expAiChainRun::fetch( (int) $run->attribute( 'id' ) );
        if ( $run->isFinal() )
            return $run;
        if ( $step['type'] === 'human' )
        {
            $row = $this->stepRow( $run, $step['name'], $this->nextIteration( $run, $step['name'] ), 'waiting' );
            $row->setAttribute( 'state', 'waiting' );
            $row->store();
        }
        $run->mergeData( array( 'resume' => (string) $resume, 'paused_at' => $step['name'], 'pause_reason' => (string) $why ) );
        $run->setAttribute( 'state', expAiChainRun::STATE_WAITING );
        $run->setAttribute( 'current_step', $step['name'] );
        $run->touch();
        return $run;
    }

    /**
     * The translation step: hands the draft to [ChainSettings] TranslateHandler (a class with a static method
     * chainTranslate( expAiChainRun $run, array $languages ) that queues the translations and returns a summary
     * array); without one the step is skipped.
     */
    protected function handOff( expAiChainRun $run, array $step, $iteration, $next )
    {
        $row = $this->stepRow( $run, $step['name'], $this->nextIteration( $run, $step['name'] ), 'done' );
        $class = trim( (string) $this->config->get( 'ChainSettings', 'TranslateHandler', '' ) );
        $languages = $step['languages'];
        if ( !$languages || in_array( 'all', $languages, true ) )
            $languages = array_values( array_diff( eZContentLanguage::fetchLocaleList(), array( (string) $run->attribute( 'language' ) ) ) );
        if ( $class !== '' && class_exists( $class ) && method_exists( $class, 'chainTranslate' ) && (int) $run->attribute( 'object_id' ) )
        {
            try
            {
                $summary = (array) call_user_func( array( $class, 'chainTranslate' ), $run, $languages );
                $row->setOutput( array( 'handler' => $class, 'languages' => $languages, 'summary' => $summary ) );
                if ( isset( $summary['skipped'] ) )
                {
                    // the handler had nothing to take over (e.g. the draft is not published yet)
                    $row->setAttribute( 'state', 'skipped' );
                    $row->setAttribute( 'note', expAiText::cut( (string) $summary['skipped'], 255 ) );
                }
                else
                {
                    $row->setAttribute( 'note', expAiText::cut( 'handed to ' . $class . ' for ' . implode( ', ', $languages ), 255 ) );
                    $run->mergeData( array( 'translate' => array( 'handler' => $class, 'languages' => $languages, 'summary' => $summary ) ) );
                }
            }
            catch ( Exception $e )
            {
                $row->setAttribute( 'state', 'failed' );
                $row->setAttribute( 'note', expAiText::cut( 'the translation hand-off failed: ' . $e->getMessage(), 255 ) );
                $this->flag( $run, 'Translation: ' . $e->getMessage() );
            }
        }
        else
        {
            $row->setAttribute( 'state', 'skipped' );
            $row->setAttribute( 'note', $class === '' ? 'no translation sync is configured ([ChainSettings] TranslateHandler)' : 'the translation sync ' . $class . ' is not available' );
        }
        $row->setAttribute( 'finished', time() );
        $row->store();
        return $this->advance( $run, $next );
    }

    /** The end of a run: the draft is written (when no checkpoint wrote it), the run is done. */
    protected function finish( expAiChainRun $run )
    {
        $this->writeDraft( $run );
        $run = expAiChainRun::fetch( (int) $run->attribute( 'id' ) );
        if ( $run->isFinal() )
            return $run;
        $run->setAttribute( 'state', expAiChainRun::STATE_DONE );
        $run->setAttribute( 'current_step', '' );
        $run->setAttribute( 'finished', time() );
        $run->touch();
        return $run;
    }

    /** Marks a run failed. */
    public function fail( expAiChainRun $run, $why )
    {
        $run->setAttribute( 'state', expAiChainRun::STATE_FAILED );
        $run->setAttribute( 'last_error', expAiText::cut( (string) $why, 255 ) );
        $run->setAttribute( 'finished', time() );
        $run->touch();
        return $run;
    }

    // ------------------------------------------------------------------ the draft

    /**
     * Writes the run's text into its draft: a new object under the run's location the first time, the same draft
     * afterwards (a new version when the draft was published or is gone). Runs as the run's user only.
     */
    public function writeDraft( expAiChainRun $run )
    {
        $data = $run->dataArray();
        $document = isset( $data['document'] ) ? (array) $data['document'] : array();
        if ( !$document )
            return;
        if ( (int) eZUser::currentUserID() !== (int) $run->attribute( 'user_id' ) )
        {
            // an editor acting on another user's run: the draft belongs to the owner and was written at the checkpoint
            if ( !(int) $run->attribute( 'object_id' ) )
                $this->flag( $run, 'The draft was not written: it is written as the user who started the run.' );
            return;
        }
        $writer = new expAiContentWriter( $this->config );
        $language = (string) $run->attribute( 'language' );
        $chain = $run->chain();
        $fields = array_intersect_key( $document, array_flip( $chain->fields() ) + array_flip( self::allOutputFields( $chain ) ) );
        $object = (int) $run->attribute( 'object_id' ) ? expAiObjects::fetch( (int) $run->attribute( 'object_id' ) ) : null;
        $first = !$object instanceof eZContentObject;
        if ( $first )
        {
            $node = $run->node();
            if ( !$node )
                throw new expAiException( 'Node %id does not exist or you may not read it.', '', 0, array( '%id' => (int) $run->attribute( 'node_id' ) ) );
            list( $object, $version, $result ) = $writer->createDraft( $node, $chain->classIdentifier(), $fields, $language );
            $action = 'create';
        }
        else
        {
            list( $version, $language, $created ) = $writer->draftFor( $object, $language, (int) $run->attribute( 'version' ) );
            $result = $writer->writeFields( $object, $version, $language, $fields );
            $action = 'update';
            $first = $created;
        }
        $this->writeMeta( $object, $version, $language, isset( $data['seo'] ) ? (array) $data['seo'] : array() );
        if ( $first )
            expAiDraftLog::record( array( 'contentobject_id' => (int) $object->attribute( 'id' ), 'version' => (int) $version->attribute( 'version' ),
                                          'user_id' => (int) $run->attribute( 'user_id' ), 'parent_node_id' => (int) $run->attribute( 'node_id' ),
                                          'language_code' => $language, 'action' => $action, 'source' => 'chain',
                                          'model' => $this->writerModel( $run ), 'provider' => $this->config->registry()->providerOf( $this->writerModel( $run ) ),
                                          'job_uuid' => $this->lastJob( $run ), 'prompt_summary' => 'Chain ' . $chain->label() . ': ' . (string) $run->attribute( 'brief' ) ) );
        if ( !empty( $result['errors'] ) )
            $this->flag( $run, 'Fields not written: ' . implode( '; ', array_map( function ( $k, $v ) { return $k . ' (' . $v . ')'; }, array_keys( $result['errors'] ), $result['errors'] ) ) );
        $run = expAiChainRun::fetch( (int) $run->attribute( 'id' ) );
        $run->setAttribute( 'object_id', (int) $object->attribute( 'id' ) );
        $run->setAttribute( 'version', (int) $version->attribute( 'version' ) );
        $run->touch();
    }

    /** @return string[] the OutputFields of every transform step */
    protected static function allOutputFields( expAiChain $chain )
    {
        $out = array();
        foreach ( $chain->stepNamesAll() as $name )
        {
            $step = $chain->step( $name );
            if ( $step['type'] === 'transform' || $step['type'] === 'generate' )
                $out = array_merge( $out, $step['output_fields'] );
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * The SEO step's meta title, description and keywords into the class's xrowmetadata field, when it has one
     * (keeping the field's other values: priority, change, sitemap, image, canonical address, license).
     */
    protected function writeMeta( eZContentObject $object, eZContentObjectVersion $version, $language, array $seo )
    {
        if ( !$seo || ( $seo['title'] === '' && $seo['description'] === '' && !$seo['keywords'] ) || !class_exists( 'xrowMetaDataType' ) )
            return;
        foreach ( $object->fetchDataMap( (int) $version->attribute( 'version' ), $language ) as $attribute )
        {
            if ( $attribute->attribute( 'data_type_string' ) !== 'xrowmetadata' )
                continue;
            $meta = $attribute->attribute( 'data_text' ) !== '' ? xrowMetaDataType::fetchMetaData( $attribute ) : null;
            if ( !$meta instanceof xrowMetaData )
                $meta = new xrowMetaData( '', array(), '', '', '', '', '' );
            $meta->title = $seo['title'];
            $meta->description = $seo['description'];
            $meta->keywords = $seo['keywords'];
            $attribute->setAttribute( 'data_text', xrowMetaDataType::saveXML( $meta ) );
            $attribute->store();
        }
    }

    /** @return string the model of the run's first generate step (for the draft log) */
    protected function writerModel( expAiChainRun $run )
    {
        $rows = eZPersistentObject::fetchObjectList( expAiChainStep::definition(), array( 'model' ), array( 'run_id' => (int) $run->attribute( 'id' ), 'step_type' => 'generate' ),
                                                     array( 'id' => 'asc' ), array( 'offset' => 0, 'length' => 1 ), false );
        return $rows ? (string) $rows[0]['model'] : '';
    }

    /** @return string the job of the run's last step with one */
    protected function lastJob( expAiChainRun $run )
    {
        $rows = eZPersistentObject::fetchObjectList( expAiChainStep::definition(), array( 'job_uuid' ), array( 'run_id' => (int) $run->attribute( 'id' ), 'job_uuid' => array( '!=', '' ) ),
                                                     array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => 1 ), false );
        return $rows ? (string) $rows[0]['job_uuid'] : '';
    }

    /**
     * Reads the draft back into the run's text (an editor may have edited it in the edit form at the checkpoint).
     */
    protected function readDraft( expAiChainRun $run )
    {
        $object = (int) $run->attribute( 'object_id' ) ? expAiObjects::fetch( (int) $run->attribute( 'object_id' ) ) : null;
        if ( !$object instanceof eZContentObject || !(int) $run->attribute( 'version' ) )
            return;
        $chain = $run->chain();
        $wanted = array_merge( $chain->fields(), self::allOutputFields( $chain ) );
        $document = array();
        foreach ( $object->fetchDataMap( (int) $run->attribute( 'version' ), (string) $run->attribute( 'language' ) ) as $identifier => $attribute )
        {
            if ( !in_array( $identifier, $wanted, true ) )
                continue;
            $datatype = $attribute->attribute( 'data_type_string' );
            $document[$identifier] = $datatype === 'ezxmltext' ? expAiText::xmlTextToHtml( (string) $attribute->attribute( 'data_text' ) ) : (string) $attribute->toString();
        }
        if ( $document )
            $run->mergeData( array( 'document' => $document ) );
    }

    // ------------------------------------------------------------------ editor actions (web, CLI)

    /**
     * The checkpoint: continue with the step after it.
     * @throws expAiException
     */
    public function approve( expAiChainRun $run, $note = '' )
    {
        $this->assertWaiting( $run );
        $this->readDraft( $run );
        $this->closeCheckpoint( $run, 'pass', 'approved' . ( trim( $note ) !== '' ? ': ' . expAiText::oneLine( $note ) : '' ) );
        $data = $run->dataArray();
        $resume = isset( $data['resume'] ) ? (string) $data['resume'] : '';
        $run->mergeData( array( 'resume' => null, 'flags' => null, 'pause_reason' => null ) );
        $run->touch();
        return $this->continueAsOwner( $run, function ( $engine, $run ) use ( $resume ) { return $engine->advance( $run, $resume ); } );
    }

    /**
     * The checkpoint: a revision with the editor's note, then the review again and back to the checkpoint.
     * @throws expAiException
     */
    public function requestChanges( expAiChainRun $run, $note )
    {
        $this->assertWaiting( $run );
        $note = trim( (string) $note );
        if ( $note === '' )
            throw new expAiException( 'Say what should change.', 'empty note' );
        $this->readDraft( $run );
        $this->closeCheckpoint( $run, 'fail', 'changes requested: ' . expAiText::oneLine( $note ) );
        $chain = $run->chain();
        $revise = $this->reviseStepOf( $chain );
        $run->mergeData( array( 'note' => mb_substr( $note, 0, 4000, 'UTF-8' ), 'review_counts' => array(), 'resume' => null, 'flags' => null,
                                'review' => array( 'issues' => array( array( 'severity' => 'high', 'field' => '', 'text' => 'Editor: ' . expAiText::oneLine( $note ) ) ) ) ) );
        $run->touch();
        $review = $this->reviewStepOf( $chain );
        return $this->continueAsOwner( $run, function ( $engine, $run ) use ( $revise, $review )
        {
            return $engine->enqueueStep( $run, $revise, $engine->nextIteration( $run, $revise ), $review );
        } );
    }

    /** Stops a run: its queued jobs are cancelled; the draft stays (the editor discards it like any draft). */
    public function stop( expAiChainRun $run, $why = 'stopped by an editor' )
    {
        if ( $run->isFinal() )
            return $run;
        if ( $run->attribute( 'group_uuid' ) !== '' && expAiQueue::available() && ( $group = expQueue::resolveGroup( (string) $run->attribute( 'group_uuid' ) ) ) )
            expQueue::cancelGroup( $group, (string) $why );
        if ( $run->attribute( 'state' ) === expAiChainRun::STATE_WAITING )
            $this->closeCheckpoint( $run, 'fail', (string) $why );
        $run->setAttribute( 'state', expAiChainRun::STATE_STOPPED );
        $run->setAttribute( 'last_error', expAiText::cut( (string) $why, 255 ) );
        $run->setAttribute( 'finished', time() );
        $run->touch();
        return $run;
    }

    /**
     * A run held by its budget continues: the run budget (and its job group's) grows by one MaxCostPerRun, the held
     * step is queued again (or its held job retried).
     * @throws expAiException
     */
    public function resume( expAiChainRun $run )
    {
        if ( $run->attribute( 'state' ) !== expAiChainRun::STATE_HELD )
            throw new expAiException( 'This run is not held.', 'state ' . $run->attribute( 'state' ) );
        $chain = $run->chain();
        $data = $run->dataArray();
        $extra = isset( $data['cap_extra'] ) ? (int) $data['cap_extra'] : 0;
        $extra += max( $chain->maxCostMicros(), 0 );
        $run->mergeData( array( 'cap_extra' => $extra ) );
        $group = expQueue::resolveGroup( (string) $run->attribute( 'group_uuid' ) );
        if ( $group && (int) $group->attribute( 'budget_micros' ) > 0 )
            eZDB::instance()->query( 'UPDATE expqueue_group SET budget_micros = ' . (int) ( $chain->maxCostMicros() + $extra ) . ' WHERE id = ' . (int) $group->attribute( 'id' ) );
        $run->setAttribute( 'state', expAiChainRun::STATE_RUNNING );
        $run->setAttribute( 'last_error', '' );
        $run->touch();
        if ( !empty( $data['pending'] ) )
        {
            $pending = (array) $data['pending'];
            $run->mergeData( array( 'pending' => null ) );
            $row = eZPersistentObject::fetchObject( expAiChainStep::definition(), null, array( 'run_id' => (int) $run->attribute( 'id' ), 'step' => (string) $pending['step'], 'iteration' => (int) $pending['iteration'] ) );
            if ( $row instanceof expAiChainStep && $row->attribute( 'state' ) === 'held' )
            {
                $row->setAttribute( 'state', 'queued' );
                $row->setAttribute( 'note', '' );
                $row->store();
            }
            return $this->continueAsOwner( $run, function ( $engine, $run ) use ( $pending )
            {
                return $engine->enqueueStep( $run, (string) $pending['step'], (int) $pending['iteration'], (string) $pending['after'] );
            } );
        }
        foreach ( array_reverse( $run->steps() ) as $row )
        {
            $job = $row->attribute( 'job_uuid' ) !== '' ? expQueue::fetch( (string) $row->attribute( 'job_uuid' ) ) : null;
            if ( $job && $job->attribute( 'state' ) === 'held' )
            {
                expQueue::retry( $job );
                break;
            }
        }
        return $run;
    }

    /**
     * Brings a run in line with its queue job: a dead or cancelled job fails the run, a held one holds it, a job
     * running again (after "Resume held jobs") runs it.
     */
    public function reconcile( expAiChainRun $run )
    {
        if ( !in_array( $run->attribute( 'state' ), array( expAiChainRun::STATE_RUNNING, expAiChainRun::STATE_HELD ), true ) || !expAiQueue::available() )
            return $run;
        $steps = $run->steps();
        $last = $steps ? end( $steps ) : null;
        if ( !$last || $last->attribute( 'job_uuid' ) === '' || !in_array( $last->attribute( 'state' ), array( 'queued', 'held' ), true ) )
            return $run;
        $job = expQueue::fetch( (string) $last->attribute( 'job_uuid' ) );
        if ( !$job )
            return $this->fail( $run, 'the queue job of the step ' . $last->attribute( 'step' ) . ' is gone' );
        $state = (string) $job->attribute( 'state' );
        if ( in_array( $state, array( 'dead', 'cancelled' ), true ) )
        {
            $last->setAttribute( 'state', 'failed' );
            $last->setAttribute( 'note', expAiText::cut( $state . ': ' . (string) $job->attribute( 'last_error' ), 255 ) );
            $last->store();
            return $this->fail( $run, 'the step ' . $last->attribute( 'step' ) . ' ended ' . $state . ': ' . (string) $job->attribute( 'last_error' ) );
        }
        if ( $state === 'held' && $run->attribute( 'state' ) !== expAiChainRun::STATE_HELD )
        {
            $run->setAttribute( 'state', expAiChainRun::STATE_HELD );
            $run->setAttribute( 'last_error', expAiText::cut( (string) $job->attribute( 'last_error' ), 255 ) );
            $run->touch();
        }
        elseif ( $state !== 'held' && $run->attribute( 'state' ) === expAiChainRun::STATE_HELD && empty( $run->dataArray()['pending'] ) )
        {
            $run->setAttribute( 'state', expAiChainRun::STATE_RUNNING );
            $run->setAttribute( 'last_error', '' );
            $run->touch();
        }
        return $run;
    }

    /** @throws expAiException when the run does not wait for an editor */
    protected function assertWaiting( expAiChainRun $run )
    {
        if ( $run->attribute( 'state' ) !== expAiChainRun::STATE_WAITING )
            throw new expAiException( 'This run is not waiting for an editor.', 'state ' . $run->attribute( 'state' ) );
    }

    /** Closes the waiting checkpoint row with the editor's decision. */
    protected function closeCheckpoint( expAiChainRun $run, $verdict, $note )
    {
        foreach ( $run->steps() as $row )
            if ( $row->attribute( 'state' ) === 'waiting' )
            {
                $row->setAttribute( 'state', 'done' );
                $row->setAttribute( 'verdict', (string) $verdict );
                $row->setAttribute( 'note', expAiText::cut( $note . ' (user ' . (int) eZUser::currentUserID() . ')', 255 ) );
                $row->setAttribute( 'finished', time() );
                $row->store();
            }
    }

    /**
     * Continues a run after an editor's action. The queued steps run as the run's owner (the job's user), who owns
     * the draft; the editor's own permission was checked by the caller (expAiChainPolicy::mayAct).
     */
    protected function continueAsOwner( expAiChainRun $run, $fn )
    {
        return $fn( $this, $run );
    }

    /** @return string the step that revises after an editor's request (the first review's ReviseStep) */
    protected function reviseStepOf( expAiChain $chain )
    {
        foreach ( $chain->stepNamesAll() as $name )
        {
            $step = $chain->step( $name );
            if ( $step['type'] === 'review' && $chain->step( $step['revise_step'] ) )
                return $step['revise_step'];
        }
        return $chain->order()[0];
    }

    /** @return string the first review step ('' when none) */
    protected function reviewStepOf( expAiChain $chain )
    {
        foreach ( $chain->order() as $name )
            if ( $chain->step( $name )['type'] === 'review' )
                return $name;
        return $chain->order()[0];
    }

    /** @return int the run budget in micros (MaxCostPerRun plus what "Continue" added) */
    protected function capMicros( expAiChainRun $run, expAiChain $chain )
    {
        $cap = $chain->maxCostMicros();
        if ( $cap <= 0 )
            return 0;
        $data = $run->dataArray();
        return $cap + ( isset( $data['cap_extra'] ) ? (int) $data['cap_extra'] : 0 );
    }

    /** @return int the content object of the run's location (the data policy of the request) */
    protected function nodeObjectID( expAiChainRun $run )
    {
        $node = $run->node();
        return $node ? (int) $node->attribute( 'contentobject_id' ) : 0;
    }

    // ------------------------------------------------------------------ cleaning answers

    /**
     * The fields of an answer, as identifier => string, only the allowed ones.
     * @param mixed $fields an object (identifier => value) or a list of {identifier, value}
     * @return array
     */
    public static function cleanFields( $fields, array $allowed )
    {
        $out = array();
        if ( !is_array( $fields ) )
            return $out;
        foreach ( $fields as $key => $value )
        {
            if ( is_array( $value ) && isset( $value['identifier'] ) )
            {
                $key = (string) $value['identifier'];
                $value = isset( $value['value'] ) ? $value['value'] : '';
            }
            if ( !is_string( $key ) || !in_array( $key, $allowed, true ) || !is_scalar( $value ) )
                continue;
            $out[$key] = trim( (string) $value );
        }
        return $out;
    }

    /** @return array a review: score (0..100 or null), summary, issues, checklist (the step's items, unknown ones as not met) */
    public static function cleanReview( array $value, array $step )
    {
        $score = isset( $value['score'] ) && is_numeric( $value['score'] ) ? (float) $value['score'] : null;
        // a reviewer that answers on a 0..1 or 0..10 scale
        if ( $score !== null && $score > 0 && $score <= 1 && (string) $value['score'] !== '1' )
            $score *= 100;
        $score = $score === null ? null : (int) round( max( 0, min( 100, $score ) ) );
        $checklist = array();
        $given = array();
        foreach ( isset( $value['checklist'] ) && is_array( $value['checklist'] ) ? $value['checklist'] : array() as $item )
            if ( is_array( $item ) && isset( $item['item'] ) )
                $given[] = array( 'item' => expAiText::cut( expAiText::oneLine( (string) $item['item'] ), 200 ), 'ok' => !empty( $item['ok'] ) && $item['ok'] !== 'false' );
        foreach ( $step['checklist'] as $i => $text )
        {
            $found = null;
            foreach ( $given as $g )
                if ( strcasecmp( $g['item'], $text ) === 0 )
                    $found = $g;
            if ( $found === null && isset( $given[$i] ) )
                $found = $given[$i];
            $checklist[] = array( 'item' => $text, 'ok' => $found ? $found['ok'] : false );
        }
        return array( 'score' => $score, 'summary' => expAiText::cut( isset( $value['summary'] ) ? (string) $value['summary'] : '', 2000 ),
                      'issues' => self::cleanIssues( isset( $value['issues'] ) ? $value['issues'] : array() ), 'checklist' => $checklist );
    }

    /** @return array issues: severity, field, text (at most 30) */
    public static function cleanIssues( $issues )
    {
        $out = array();
        foreach ( is_array( $issues ) ? $issues : array() as $issue )
        {
            if ( !is_array( $issue ) || empty( $issue['text'] ) )
                continue;
            $severity = isset( $issue['severity'] ) ? strtolower( (string) $issue['severity'] ) : 'medium';
            $out[] = array( 'severity' => in_array( $severity, array( 'high', 'medium', 'low' ), true ) ? $severity : 'medium',
                            'field' => expAiText::cut( isset( $issue['field'] ) ? (string) $issue['field'] : '', 50 ),
                            'text' => expAiText::cut( expAiText::oneLine( (string) $issue['text'] ), 500 ) );
        }
        return array_slice( $out, 0, 30 );
    }

    /**
     * The links of the text: the address of each <a href> is checked offline (an http(s) or mailto address that
     * parses; an internal link to a node or object that exists). No page is fetched.
     * @return array list of url, ok, why
     */
    public static function checkLinks( array $document )
    {
        $out = array();
        foreach ( $document as $field => $value )
        {
            if ( !preg_match_all( '/<a\s[^>]*href\s*=\s*(["\'])(.*?)\1/i', (string) $value, $m ) )
                continue;
            foreach ( $m[2] as $url )
            {
                $url = html_entity_decode( trim( $url ), ENT_QUOTES, 'UTF-8' );
                $ok = false;
                $why = '';
                if ( preg_match( '~^ezurl://(\d+)~', $url, $u ) )
                    $ok = (bool) eZURL::fetch( (int) $u[1] );
                elseif ( preg_match( '~^eznode://(\d+)~', $url, $u ) || preg_match( '~^/?content/view/full/(\d+)~', $url, $u ) )
                {
                    $ok = (bool) eZContentObjectTreeNode::fetch( (int) $u[1] );
                    $why = $ok ? '' : 'no node ' . (int) $u[1];
                }
                elseif ( preg_match( '~^ezobject://(\d+)~', $url, $u ) )
                {
                    $ok = (bool) expAiObjects::fetch( (int) $u[1] );
                    $why = $ok ? '' : 'no object ' . (int) $u[1];
                }
                elseif ( preg_match( '~^mailto:~i', $url ) )
                    $ok = (bool) filter_var( substr( $url, 7 ), FILTER_VALIDATE_EMAIL );
                elseif ( preg_match( '~^https?://~i', $url ) )
                {
                    $parts = parse_url( $url );
                    $ok = is_array( $parts ) && !empty( $parts['host'] ) && strpos( $parts['host'], '.' ) !== false && !preg_match( '/\s/', $url );
                    $why = $ok ? '' : 'not a valid address';
                }
                else
                    $why = 'not an absolute http(s), mailto or internal link';
                $out[] = array( 'field' => (string) $field, 'url' => expAiText::cut( $url, 300 ), 'ok' => $ok, 'why' => $why );
            }
        }
        return array_slice( $out, 0, 100 );
    }

    /** @return string a version 4 UUID */
    public static function uuid()
    {
        $b = random_bytes( 16 );
        $b[6] = chr( ord( $b[6] ) & 0x0f | 0x40 );
        $b[8] = chr( ord( $b[8] ) & 0x3f | 0x80 );
        return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
    }
}
