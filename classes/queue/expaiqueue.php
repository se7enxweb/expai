<?php
/**
 * File containing the expAiQueue class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Queues requests to a model through the extension expqueue (job type expai.request): the lane is chosen
 * (expAiLanePolicy), the cost estimated and reserved (expAiBudget), and the job held when a budget does not allow it.
 * A request is one structured call without tools (what batch and flex lanes can run): system, messages (role and
 * text), max_tokens, output_schema.
 *
 *   $r = expAiQueue::request( 'anthropic.claude-haiku-5-5', array( 'system' => '...', 'messages' => array(
 *            array( 'role' => 'user', 'content' => 'Summarise: ...' ) ), 'max_tokens' => 400 ),
 *            array( 'purpose' => 'summary', 'group' => $group, 'objects' => array( $objectID ) ) );
 *   $r['job'] (expQueueJob), $r['lane'] (expAiLane), $r['estimate'] (micros), $r['held'] (why, or '')
 *
 * The answer is the job's result (text, structured value, tokens, cost, lane, service tier) once it is done; an
 * applier (option apply, [QueueSettings] Appliers[<name>]=<class implementing expAiRequestApplier>) can write it
 * somewhere, e.g. into a draft.
 */
class expAiQueue
{
    const TYPE_REQUEST = 'expai.request';
    const TYPE_SUBMIT = 'expai.batch.submit';
    const TYPE_POLL = 'expai.batch.poll';

    /** @return bool the queue can be used (expqueue active in this siteaccess and installed) */
    public static function available()
    {
        return class_exists( 'expQueue' ) && expAiConfig::extensionActive( 'expqueue' ) && expQueue::installed();
    }

    /**
     * Validates and normalises a request.
     * @return array
     * @throws expAiException
     */
    public static function normaliseRequest( array $request )
    {
        $messages = array();
        foreach ( isset( $request['messages'] ) ? (array) $request['messages'] : array() as $message )
        {
            $role = isset( $message['role'] ) ? (string) $message['role'] : '';
            $content = isset( $message['content'] ) ? $message['content'] : '';
            if ( !in_array( $role, array( 'user', 'assistant' ), true ) || !is_string( $content ) || $content === '' )
                throw new expAiException( 'The request could not be sent to Exponential Intelligence.', 'a queued request has messages of role user or assistant with text only' );
            $messages[] = array( 'role' => $role, 'content' => $content );
        }
        if ( !$messages || $messages[count( $messages ) - 1]['role'] !== 'user' )
            throw new expAiException( 'The request could not be sent to Exponential Intelligence.', 'a queued request ends with a user message' );
        $out = array( 'system' => isset( $request['system'] ) ? (string) $request['system'] : '', 'messages' => $messages,
                      'max_tokens' => max( 1, min( 64000, (int) ( isset( $request['max_tokens'] ) ? $request['max_tokens'] : 1024 ) ) ) );
        if ( !empty( $request['output_schema'] ) && is_array( $request['output_schema'] ) )
            $out['output_schema'] = $request['output_schema'];
        return $out;
    }

    /**
     * Queues one request.
     *
     * @param string $alias model alias ('' = the site default)
     * @param array $request system, messages, max_tokens, output_schema
     * @param array $options purpose, class, priority, group (expQueueGroup, id or uuid), lane (a name, or auto),
     *                       deadline, allow_fast, objects (content object ids the request contains: the data policy),
     *                       node_id, label, est_output (tokens), idempotency_key, user_id, apply, apply_params, tags
     * @return array job, lane, estimate (micros), reserved (micros), held ('' or why), duplicate
     * @throws expAiException|expQueueException
     */
    public static function request( $alias, array $request, array $options = array() )
    {
        if ( !self::available() )
            throw new expAiException( 'The job queue is not available.', 'the extension expqueue is not active or its tables are missing' );
        $config = expAiConfig::instance();
        $registry = $config->registry();
        $resolved = $registry->resolveAlias( (string) $alias );
        $resolved = $resolved !== '' ? $resolved : $registry->defaultAlias();
        if ( !$registry->getModel( $resolved ) )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'no model ' . $alias . ' in the provider registry' );
        $request = self::normaliseRequest( $request );
        $options += array( 'purpose' => '', 'class' => '', 'priority' => null, 'group' => null, 'lane' => 'auto', 'deadline' => 0,
                           'allow_fast' => false, 'objects' => array(), 'node_id' => 0, 'label' => '', 'est_output' => 0,
                           'idempotency_key' => '', 'user_id' => null, 'apply' => '', 'apply_params' => array(), 'tags' => array() );
        $purpose = preg_replace( '/[^a-z0-9_.-]/', '', strtolower( (string) $options['purpose'] ) );
        $class = (string) $options['class'] !== '' ? (string) $options['class'] : self::classFor( $purpose );

        // the master switch and the veto hook (#370 M2): nothing is queued while expai is off, nor a refused request
        if ( class_exists( 'expAiGate' ) )
        {
            $gateModel = $registry->getModel( $resolved );
            expAiGate::check( array( 'user_id' => $options['user_id'] ? (int) $options['user_id'] : (int) eZUser::currentUserID(),
                                     'node_id' => (int) $options['node_id'], 'provider' => (string) $gateModel['provider'], 'model' => (string) $gateModel['id'],
                                     'model_alias' => $resolved, 'purpose' => expAiText::cut( 'queue' . ( $purpose !== '' ? '.' . $purpose : '' ), 40 ) ), $config );
        }

        // the data policy of the content in the request, for the lane choice and again when it runs
        $objects = array_values( array_unique( array_filter( array_map( 'intval', (array) $options['objects'] ) ) ) );
        $requirements = array();
        $model = $registry->getModel( $resolved );
        $policy = expAiDataPolicy::forProvider( $config, $model['provider'] );
        foreach ( $objects as $objectID )
        {
            $object = expAiObjects::fetch( $objectID );
            if ( $object instanceof eZContentObject )
                $requirements[] = $policy->requirement( $object );
        }
        $user = $options['user_id'] ? eZUser::fetch( (int) $options['user_id'] ) : eZUser::currentUser();
        $choice = ( new expAiLanePolicy( $config ) )->choose( $resolved, array(
            'purpose' => $purpose, 'class' => $class, 'priority' => $options['priority'] === null ? 50 : (int) $options['priority'],
            'deadline' => (int) $options['deadline'], 'lane' => (string) $options['lane'], 'allow_fast' => (bool) $options['allow_fast'],
            'requirements' => $requirements, 'user' => $user ) );
        $lane = $choice['lane'];

        $budget = new expAiBudget( $config );
        $estInput = $budget->estimateInputTokens( $request );
        $estOutput = (int) $options['est_output'] > 0 ? (int) $options['est_output']
                   : (int) ceil( $request['max_tokens'] * (float) $config->get( 'Budgets', 'OutputShareOfMax', 0.5 ) );
        $estimate = $budget->estimate( $resolved, $estInput, $estOutput, $lane );
        $reserve = (int) ceil( $estimate['micros'] * $budget->reserveFactor() );

        $apply = preg_replace( '/[^a-z0-9_]/', '', (string) $options['apply'] );
        if ( $apply !== '' && !self::applier( $apply ) )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'unknown applier ' . $apply );
        $payload = array( 'alias' => $resolved, 'lane' => $lane->name(), 'purpose' => $purpose, 'request' => $request,
                          'est_input' => $estInput, 'est_output' => $estOutput, 'objects' => $objects, 'node_id' => (int) $options['node_id'],
                          'label' => expAiText::cut( (string) $options['label'], 120 ), 'apply' => $apply, 'apply_params' => (array) $options['apply_params'],
                          'deadline' => (int) $options['deadline'] );
        // the siteaccess it was queued from: the master switch of that siteaccess holds it ([AISettings] DisabledSiteAccesses[])
        if ( class_exists( 'expAiGate' ) && expAiGate::siteAccess() !== '' )
            $payload['siteaccess'] = expAiGate::siteAccess();
        $queueOptions = array( 'class' => $class, 'resource' => $lane->key(), 'lane' => $lane->name(), 'cost_estimate_micros' => $estimate['micros'],
                               'deadline' => (int) $options['deadline'], 'tags' => array_merge( array( 'lane:' . $lane->name(), 'provider:' . $lane->get( 'provider' ) ), (array) $options['tags'] ) );
        foreach ( array( 'priority', 'group', 'idempotency_key', 'user_id' ) as $name )
            if ( $options[$name] !== null && $options[$name] !== '' )
                $queueOptions[$name] = $options[$name];
        if ( $lane->isBatch() )
            $queueOptions['timeout'] = 600;
        return expQueue::withLock( function () use ( $payload, $queueOptions, $budget, $reserve, $estimate, $lane )
        {
            $groupID = 0;
            if ( !empty( $queueOptions['group'] ) )
            {
                $group = expQueue::resolveGroup( $queueOptions['group'] );
                $groupID = $group ? (int) $group->attribute( 'id' ) : 0;
            }
            $refusal = $budget->refusal( $reserve, $groupID );
            $job = expQueue::enqueue( self::TYPE_REQUEST, $payload, $queueOptions );
            if ( !empty( $job->duplicate ) )
                return array( 'job' => $job, 'lane' => $lane, 'estimate' => $estimate['micros'], 'reserved' => (int) $job->attribute( 'cost_reserved_micros' ),
                              'held' => '', 'duplicate' => true );
            $budget->reserve( $job, $reserve );
            $held = '';
            if ( $refusal !== null && $budget->hold( $job, $refusal['message'] ) )
                $held = $refusal['message'];
            return array( 'job' => $job, 'lane' => $lane, 'estimate' => $estimate['micros'], 'reserved' => $reserve, 'held' => $held, 'duplicate' => false );
        } );
    }

    /** @return string the queue class of a purpose ([LanePolicy] PurposeClass[<purpose>], else editorial) */
    public static function classFor( $purpose )
    {
        $map = expAiConfig::instance()->get( 'LanePolicy', 'PurposeClass', array() );
        $map = is_array( $map ) ? $map : array();
        if ( $purpose !== '' && !empty( $map[$purpose] ) )
            return (string) $map[$purpose];
        return !empty( $map['default'] ) ? (string) $map['default'] : 'editorial';
    }

    /** @return expAiRequestApplier|null an applier of [QueueSettings] Appliers[] */
    public static function applier( $name )
    {
        $map = expAiConfig::instance()->get( 'QueueSettings', 'Appliers', array() );
        $class = is_array( $map ) && isset( $map[$name] ) ? (string) $map[$name] : '';
        if ( $class === '' || !class_exists( $class ) || !in_array( 'expAiRequestApplier', class_implements( $class ), true ) )
            return null;
        return new $class();
    }

    /**
     * The jobs of expai with their lane, model, estimate and cost (for ext:expai:queue and the dashboard).
     * @return array
     */
    public static function jobs( array $filter = array(), $limit = 50 )
    {
        if ( !self::available() )
            return array();
        $filter += array( 'type' => array( self::TYPE_REQUEST, self::TYPE_SUBMIT, self::TYPE_POLL ) );
        $out = array();
        foreach ( expQueue::listJobs( $filter, 0, $limit ) as $job )
        {
            $p = $job->payloadArray();
            $out[] = array( 'uuid' => $job->attribute( 'uuid' ), 'type' => $job->attribute( 'type' ), 'state' => $job->attribute( 'state' ),
                            'class' => $job->attribute( 'class' ), 'lane' => $job->attribute( 'lane' ), 'alias' => isset( $p['alias'] ) ? $p['alias'] : '',
                            'estimate' => (int) $job->attribute( 'cost_estimate_micros' ), 'reserved' => (int) $job->attribute( 'cost_reserved_micros' ),
                            'actual' => (int) $job->attribute( 'cost_actual_micros' ), 'external_ref' => $job->attribute( 'external_ref' ),
                            'error' => $job->attribute( 'last_error' ), 'created' => (int) $job->attribute( 'created' ) );
        }
        return $out;
    }
}
