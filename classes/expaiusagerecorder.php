<?php
/**
 * File containing the expAiUsageRecorder class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A provider that records every request of the provider it wraps in expai_usage_log (expAiUsage) and enforces
 * [Limits] (expAiLimits) before each request: with a limit set, the request reserves its estimate first, and the row
 * is settled with the real numbers after it. Every request leaves a row, also one that fails in an unexpected way. expai wraps every provider it uses in one; the context
 * (user, node, conversation, turn, action, iteration) is set by the caller.
 */
class expAiUsageRecorder implements expAiProvider
{
    /** @var expAiProvider */
    protected $inner;

    /** @var array */
    protected $context;

    /** @var expAiUsageLog|null the row of the last request */
    public $last;

    public function __construct( expAiProvider $inner, array $context = array() )
    {
        $this->inner = $inner;
        $this->context = $context + array( 'user_id' => (int) eZUser::currentUserID(), 'node_id' => 0, 'conversation_id' => 0,
                                           'turn' => 0, 'action' => '', 'iteration' => 0 );
    }

    public function setContext( array $context )
    {
        $this->context = $context + $this->context;
    }

    public function providerId()
    {
        return $this->inner->providerId();
    }

    public function modelName()
    {
        return $this->inner->modelName();
    }

    /** @return expAiProvider the wrapped provider */
    public function inner()
    {
        return $this->inner;
    }

    /** @return string the model alias of the wrapped provider ('' for a provider without one) */
    public function modelAlias()
    {
        return method_exists( $this->inner, 'modelAlias' ) ? (string) $this->inner->modelAlias() : '';
    }

    /** @return string native | prompted | none */
    public function toolUse()
    {
        return method_exists( $this->inner, 'toolUse' ) ? (string) $this->inner->toolUse() : 'native';
    }

    public function send( array $request )
    {
        $config = expAiConfig::instance();
        $providerID = $this->inner->providerId();
        // the master switch and the veto hook (#370 M2, doc/switch-and-veto.md): before the limits, before anything is sent
        if ( class_exists( 'expAiGate' ) )
            expAiGate::check( array( 'user_id' => (int) $this->context['user_id'], 'node_id' => (int) $this->context['node_id'], 'provider' => $providerID,
                                     'model' => $this->inner->modelName(), 'model_alias' => $this->modelAlias(), 'purpose' => (string) $this->context['action'] )
                              + ( !empty( $this->context['siteaccess'] ) ? array( 'siteaccess' => (string) $this->context['siteaccess'] ) : array() ), $config );
        $provider = $config->registry()->getProvider( $providerID );
        $row = array( 'model_alias' => $this->modelAlias(), 'tool_mode' => $this->toolUse(),
                      'region' => $provider ? (string) $provider['Region'] : '', 'retention' => $provider ? (string) $provider['Retention'] : '', 'user_id' => (int) $this->context['user_id'], 'node_id' => (int) $this->context['node_id'],
                      'conversation_id' => (int) $this->context['conversation_id'], 'turn' => (int) $this->context['turn'],
                      'action' => (string) $this->context['action'], 'iteration' => (int) $this->context['iteration'],
                      'model' => $this->inner->modelName(), 'provider' => $this->inner->providerId(), 'session_key' => expAiLimits::sessionKey() );
        // threads (classes/vector/): the thread and run of the request
        if ( !empty( $this->context['thread_id'] ) )
            $row += array( 'thread_id' => (int) $this->context['thread_id'], 'run_id' => isset( $this->context['run_id'] ) ? (int) $this->context['run_id'] : 0 );
        if ( $config->flag( 'UsageSettings', 'LogRequests', false ) )
            $row['request_debug'] = expAiText::cut( json_encode( class_exists( 'expAiImageInput' ) ? expAiImageInput::redact( $request ) : $request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 200000 );
        // the fallback to the default model (expAiModelFallback): the model the request asked for and why another answers
        if ( !empty( $this->context['requested_alias'] ) )
            $row += array( 'requested_model_alias' => expAiText::cut( (string) $this->context['requested_alias'], 100 ),
                           'fallback_reason' => expAiText::cut( isset( $this->context['fallback_reason'] ) ? (string) $this->context['fallback_reason'] : '', 20 ) );

        // with limits set, the request reserves its estimate before the check (expAiLimits): parallel requests see it;
        // an installation not upgraded (no reservation columns) checks without it, as before
        $this->pending = null;
        if ( expAiLimits::anySet( $config ) && expAiUsageLog::reservationColumns() )
        {
            expAiUsage::cleanStaleReservations();
            $estimate = self::estimate( $request, $this->maxTokens( $request, $config ), $row['model'], $row['model_alias'] );
            $this->pending = expAiUsage::record( $row + array( 'reserved_tokens' => $estimate['tokens'], 'reserved_micros' => $estimate['micros'] ), false );
        }
        try
        {
            expAiLimits::check( $this->context + array( 'provider' => $providerID, 'model' => $row['model'], 'model_alias' => $row['model_alias'],
                                                        'reservation_id' => $this->pending ? (int) $this->pending->attribute( 'id' ) : 0 ) );
        }
        catch ( Throwable $e )
        {
            // a refused request was never sent: its reservation goes, it leaves no row
            if ( $this->pending )
                $this->pending->remove();
            $this->pending = null;
            throw $e;
        }

        $start = microtime( true );
        try
        {
            $answer = $this->inner->send( $request );
        }
        catch ( Throwable $e )
        {
            // every failure leaves a row: a provider's error, and any other (a bug, a PHP error) as well
            $row['latency_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );
            $row += self::errorFields( $e );
            $this->last = $this->store( $row );
            throw $e;
        }
        try
        {
            $row = self::answerFields( $answer, $row );
        }
        catch ( Throwable $e )
        {
            $row += self::errorFields( $e );
            $row['latency_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );
            $this->last = $this->store( $row );
            throw $e;
        }
        $row['latency_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );
        $this->last = $this->store( $row );
        return $answer;
    }

    /** @var expAiUsageLog|null the reservation of the request under way */
    protected $pending = null;

    /** @return expAiUsageLog the row of the request: the reservation settled, else a new row */
    protected function store( array $row )
    {
        $pending = $this->pending;
        $this->pending = null;
        return $pending ? expAiUsage::settle( $pending, $row ) : expAiUsage::record( $row );
    }

    /** @return int the longest answer of the request (its max_tokens, else the model's MaxTokens) */
    protected function maxTokens( array $request, expAiConfig $config )
    {
        if ( isset( $request['max_tokens'] ) && (int) $request['max_tokens'] > 0 )
            return (int) $request['max_tokens'];
        if ( method_exists( $this->inner, 'setting' ) && (int) $this->inner->setting( 'MaxTokens', 0 ) > 0 )
            return (int) $this->inner->setting( 'MaxTokens', 0 );
        return max( 1, (int) $config->get( 'AISettings', 'MaxTokens', 16000 ) );
    }

    /**
     * The estimate a request reserves: its input (characters of the request / 3.5) and its longest answer.
     * @return array tokens, micros (0 when the model has no price)
     */
    public static function estimate( array $request, $maxTokens, $model, $alias = '' )
    {
        unset( $request['_drop_block'] );
        $input = (int) ceil( strlen( (string) json_encode( $request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) ) / 3.5 ) + 10;
        $output = max( 1, (int) $maxTokens );
        $micros = 0;
        if ( expAiCost::enabled() )
        {
            $cost = expAiCost::of( (string) $model, $input, $output, 0, 0, (string) $alias );
            if ( $cost['source'] !== 'unknown' )
                $micros = (int) round( $cost['amount'] * 1000000 );
        }
        return array( 'tokens' => $input + $output, 'micros' => $micros );
    }

    /** @return array the error fields of a usage row for a failure */
    public static function errorFields( Throwable $e )
    {
        if ( $e instanceof expAiException )
            return array( 'http_status' => (int) $e->getCode(), 'error_class' => get_class( $e ),
                          'error_message' => strtr( $e->getMessage(), $e->arguments() ) . ( $e->detail() !== '' ? ' (' . $e->detail() . ')' : '' ),
                          'rate_limits' => $e->rateLimits ? json_encode( $e->rateLimits ) : '' );
        return array( 'http_status' => 0, 'error_class' => get_class( $e ), 'error_message' => $e->getMessage() );
    }

    /**
     * The fields of a usage row from a provider's answer: tokens, cost, model, stop reason (with the category of a
     * refusal), status, retries, rate limits.
     * @return array the row
     */
    public static function answerFields( array $answer, array $row )
    {
        // normalised token counts from the provider (the Anthropic usage fields when a provider gives none)
        $usage = isset( $answer['usage'] ) && is_array( $answer['usage'] ) ? $answer['usage'] : array();
        $tokens = isset( $answer['tokens'] ) && is_array( $answer['tokens'] ) ? $answer['tokens'] : array(
            'input' => isset( $usage['input_tokens'] ) ? $usage['input_tokens'] : 0, 'output' => isset( $usage['output_tokens'] ) ? $usage['output_tokens'] : 0,
            'cache_write' => isset( $usage['cache_creation_input_tokens'] ) ? $usage['cache_creation_input_tokens'] : 0,
            'cache_read' => isset( $usage['cache_read_input_tokens'] ) ? $usage['cache_read_input_tokens'] : 0 );
        $row['input_tokens'] = (int) ( isset( $tokens['input'] ) ? $tokens['input'] : 0 );
        $row['output_tokens'] = (int) ( isset( $tokens['output'] ) ? $tokens['output'] : 0 );
        $row['cache_write_tokens'] = (int) ( isset( $tokens['cache_write'] ) ? $tokens['cache_write'] : 0 );
        $row['cache_read_tokens'] = (int) ( isset( $tokens['cache_read'] ) ? $tokens['cache_read'] : 0 );
        $row['reasoning_tokens'] = (int) ( isset( $tokens['reasoning'] ) ? $tokens['reasoning'] : 0 );
        $row['reported_cost_micros'] = isset( $tokens['reported_cost'] ) && is_numeric( $tokens['reported_cost'] ) && $tokens['reported_cost'] > 0
                                     ? (int) round( (float) $tokens['reported_cost'] * 1000000 ) : 0;
        // #385 a cost reported in another currency (Melious: EUR) is stored in [Pricing] Currency; without a rate it is
        // not stored, and the row is priced from the model's prices (or "cost unknown")
        if ( $row['reported_cost_micros'] > 0 && !empty( $tokens['reported_currency'] ) )
        {
            $rate = expAiCost::rate( $tokens['reported_currency'] );
            $row['reported_cost_micros'] = $rate === null ? 0 : (int) round( (float) $tokens['reported_cost'] * $rate * 1000000 );
        }
        $row['model'] = isset( $answer['model'] ) && $answer['model'] !== '' ? (string) $answer['model'] : ( isset( $row['model'] ) ? $row['model'] : '' );
        $row['stop_reason'] = isset( $answer['stop_reason'] ) ? (string) $answer['stop_reason'] : '';
        $row['http_status'] = isset( $answer['http_status'] ) ? (int) $answer['http_status'] : 200;
        $row['retries'] = isset( $answer['retries'] ) ? (int) $answer['retries'] : 0;
        $row['rate_limits'] = !empty( $answer['rate_limits'] ) ? json_encode( $answer['rate_limits'] ) : '';
        if ( $row['stop_reason'] === 'refusal' )
        {
            $row['error_class'] = 'refusal';
            // the category the provider names for the refusal (stop_details: cyber, bio, ...), never its content
            $details = isset( $answer['stop_details'] ) && is_array( $answer['stop_details'] ) ? $answer['stop_details'] : array();
            $category = isset( $details['category'] ) && is_string( $details['category'] ) ? preg_replace( '/[^a-z0-9_.-]/i', '', $details['category'] ) : '';
            $row['error_message'] = 'refusal' . ( $category !== '' ? ', category ' . $category : ', no category' );
        }
        return $row;
    }

    /** Adds what happened after the request (the tool calls of the step, the drafts written) to its row */
    public function complete( array $tools, $draftsWritten, $mcpCalls = 0 )
    {
        if ( !$this->last )
            return;
        $ms = 0;
        foreach ( $tools as $tool )
            $ms += isset( $tool['ms'] ) ? (int) $tool['ms'] : 0;
        $this->last->setAttribute( 'tool_calls', json_encode( $tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
        $this->last->setAttribute( 'tool_count', count( $tools ) );
        $this->last->setAttribute( 'tool_ms', $ms );
        $this->last->setAttribute( 'drafts_written', (int) $draftsWritten );
        $this->last->setAttribute( 'mcp_calls', (int) $mcpCalls );
        $this->last->store();
    }
}
