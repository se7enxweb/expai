<?php
/**
 * File containing the expAiAnthropicProvider class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The Anthropic Messages API (POST {Endpoint}, default https://api.anthropic.com/v1/messages) with tool use,
 * adaptive thinking at the configured effort, optional structured output, prompt caching and the server-side
 * refusal fallback. Earlier assistant turns are sent back exactly as they were received (content_json), as the
 * API requires for thinking blocks; the conversation is only ever appended to.
 */
class expAiAnthropicProvider extends expAiProviderBase
{
    const DEFAULT_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    const DEFAULT_VERSION = '2023-06-01';
    const FALLBACK_BETA = 'server-side-fallback-2026-07-01';
    const BINDING_BETA = 'thinking-binding-controls-2026-08-01';

    /** the default longest answer: a request is sent without streaming, so it stays well inside the HTTP time limits */
    const DEFAULT_MAX_TOKENS = 16000;

    /**
     * The models that accept the server-side refusal fallback (fallbacks: "default"). Any other model (Claude Haiku
     * 5.5 has no server-side fallback) only gets it with the capability refusal_fallback; the capability
     * no_refusal_fallback turns it off for a listed one.
     */
    const FALLBACK_MODELS = array( 'claude-fable-5-1', 'claude-mythos-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5' );

    /** @var string[] */
    protected $defaultRateLimitHeaders = array( 'anthropic-ratelimit-*', 'retry-after' );

    protected function defaultProviderId()
    {
        return 'anthropic';
    }

    public function providerLabel()
    {
        // the error texts name the provider like those of every other provider: its Label, otherwise "Anthropic"
        return (string) $this->setting( 'Label', 'Anthropic' );
    }

    /** @return bool the Anthropic-only extras (prompt caching, refusal fallback, workspace, betas) are sent */
    protected function extras()
    {
        return $this->enabled( 'AnthropicExtras', 'enabled' );
    }

    /** @return bool the model accepts the server-side refusal fallback (FALLBACK_MODELS, capabilities) */
    public function supportsRefusalFallback()
    {
        $capabilities = array_map( 'strtolower', array_map( 'strval', (array) $this->setting( 'Capabilities', array() ) ) );
        if ( in_array( 'no_refusal_fallback', $capabilities, true ) )
            return false;
        if ( in_array( 'refusal_fallback', $capabilities, true ) )
            return true;
        // a dated snapshot or a -latest name of a listed model
        $id = preg_replace( '/(-\d{8}|-latest)$/', '', strtolower( trim( $this->modelName() ) ) );
        return in_array( $id, self::FALLBACK_MODELS, true );
    }

    /**
     * A tool definition with strict: true when its schema allows it: every object of the schema lists its
     * properties and has additionalProperties false (the top level gets it when it is missing), and the schema uses
     * nothing but type, properties, required, items, enum, description and additionalProperties. Any other tool is
     * sent as it is ([Provider_<id>] StrictTools=disabled sends none strict).
     * @param array|object $tool
     * @return array|object
     */
    public static function strictTool( $tool )
    {
        $array = json_decode( json_encode( $tool ), true );
        if ( !is_array( $array ) || !isset( $array['input_schema'] ) || !is_array( $array['input_schema'] ) || isset( $array['type'] ) )
            return $tool;
        $schema = $array['input_schema'];
        if ( !isset( $schema['type'] ) || $schema['type'] !== 'object' )
            return $tool;
        if ( !array_key_exists( 'additionalProperties', $schema ) )
            $schema['additionalProperties'] = false;
        if ( !self::strictSchema( $schema ) )
            return $tool;
        if ( isset( $schema['properties'] ) && $schema['properties'] === array() )
            $schema['properties'] = new stdClass();
        $array['input_schema'] = $schema;
        $array['strict'] = true;
        return $array;
    }

    /**
     * The tools of a request, strict where strictTool() allows it, within the limits a request has for strict tools
     * (how many tools, how many optional parameters and parameters with more than one type in all of them): a tool
     * that would pass a limit goes as it is, so a full tool set never makes the request fail.
     * @return array
     */
    public static function strictTools( array $tools, $maxTools = 20, $maxOptional = 24, $maxUnion = 16 )
    {
        $count = 0;
        $optional = 0;
        $union = 0;
        $out = array();
        foreach ( $tools as $tool )
        {
            $strict = self::strictTool( $tool );
            if ( is_array( $strict ) && !empty( $strict['strict'] ) )
            {
                list( $o, $u ) = self::schemaCounts( $strict['input_schema'] );
                if ( $count + 1 > $maxTools || $optional + $o > $maxOptional || $union + $u > $maxUnion )
                    $strict = $tool;
                else
                {
                    $count++;
                    $optional += $o;
                    $union += $u;
                }
            }
            $out[] = $strict;
        }
        return $out;
    }

    /** @return array( int optional parameters, int parameters with more than one type ) of a schema and the schemas in it */
    protected static function schemaCounts( $schema )
    {
        $schema = json_decode( json_encode( $schema ), true );
        $optional = 0;
        $union = 0;
        if ( !is_array( $schema ) )
            return array( 0, 0 );
        if ( isset( $schema['type'] ) && is_array( $schema['type'] ) && count( $schema['type'] ) > 1 )
            $union++;
        if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) )
        {
            $required = isset( $schema['required'] ) ? (array) $schema['required'] : array();
            foreach ( $schema['properties'] as $name => $property )
            {
                if ( !in_array( $name, $required, true ) )
                    $optional++;
                list( $o, $u ) = self::schemaCounts( $property );
                $optional += $o;
                $union += $u;
            }
        }
        if ( isset( $schema['items'] ) )
        {
            list( $o, $u ) = self::schemaCounts( $schema['items'] );
            $optional += $o;
            $union += $u;
        }
        return array( $optional, $union );
    }

    /** @return bool the schema (and every schema in it) fits strict tool use */
    protected static function strictSchema( $schema )
    {
        if ( !is_array( $schema ) )
            return false;
        foreach ( array_keys( $schema ) as $key )
            if ( !in_array( $key, array( 'type', 'properties', 'required', 'items', 'enum', 'description', 'additionalProperties' ), true ) )
                return false;
        $types = isset( $schema['type'] ) ? (array) $schema['type'] : array();
        if ( in_array( 'object', $types, true ) )
        {
            if ( !array_key_exists( 'additionalProperties', $schema ) || $schema['additionalProperties'] !== false )
                return false;
            foreach ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array() as $property )
                if ( !self::strictSchema( $property ) )
                    return false;
        }
        elseif ( array_key_exists( 'additionalProperties', $schema ) )
            return false;
        if ( isset( $schema['items'] ) && !self::strictSchema( $schema['items'] ) )
            return false;
        return true;
    }

    /** @return array the request body (exposed for tests and doc examples; contains no key) */
    public function buildPayload( array $request )
    {
        $payload = array(
            'model' => $this->modelName(),
            'max_tokens' => (int) ( isset( $request['max_tokens'] ) ? $request['max_tokens'] : $this->setting( 'MaxTokens', self::DEFAULT_MAX_TOKENS ) ),
            'system' => (string) $request['system'],
            'messages' => array(),
        );
        foreach ( $request['messages'] as $message )
        {
            if ( isset( $message['content_json'] ) )
                $payload['messages'][] = array( 'role' => $message['role'], 'content' => json_decode( $message['content_json'] ) );
            else
                $payload['messages'][] = array( 'role' => $message['role'], 'content' => $message['content'] );
        }
        if ( !empty( $request['tools'] ) )
        {
            $payload['tools'] = $request['tools'];
            // strict tool use: the API guarantees that a call matches the tool's schema
            if ( $this->enabled( 'StrictTools', 'enabled' ) )
                $payload['tools'] = self::strictTools( array_values( (array) $request['tools'] ), (int) $this->setting( 'StrictToolsMax', 20 ),
                                                       (int) $this->setting( 'StrictOptionalMax', 24 ), (int) $this->setting( 'StrictUnionMax', 16 ) );
            if ( isset( $request['tool_choice'] ) )
                $payload['tool_choice'] = $request['tool_choice'];
        }
        $outputConfig = array();
        $effort = strtolower( trim( (string) $this->setting( 'Effort', '' ) ) );
        if ( in_array( $effort, array( 'low', 'medium', 'high', 'xhigh', 'max' ), true ) )
            $outputConfig['effort'] = $effort;
        if ( !empty( $request['output_schema'] ) )
            $outputConfig['format'] = array( 'type' => 'json_schema', 'schema' => $request['output_schema'] );
        if ( $outputConfig )
            $payload['output_config'] = $outputConfig;
        if ( $this->extras() && $this->enabled( 'PromptCaching', 'enabled' ) )
            $payload['cache_control'] = array( 'type' => 'ephemeral' );
        // the server-side refusal fallback only for the models that accept it (a 400 on any other)
        if ( $this->extras() && $this->enabled( 'RefusalFallback', 'enabled' ) && $this->supportsRefusalFallback() )
            $payload['fallbacks'] = 'default';
        // a lane's request parameters ([Lane_<provider>.<lane>] RequestParams[], e.g. speed=fast)
        foreach ( (array) $this->setting( 'LaneParams', array() ) as $name => $value )
            if ( is_string( $name ) && !isset( $payload[$name] ) )
                $payload[$name] = $value;
        return $payload;
    }

    public function send( array $request )
    {
        $endpoint = trim( (string) $this->setting( 'Endpoint', $this->setting( 'BaseURL', self::DEFAULT_ENDPOINT ) ) );
        if ( $this->modelName() === '' )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'Model is not set' );

        $headers = array_merge( $this->keyHeaders( 'x-api-key' ), array( 'anthropic-version: ' . $this->setting( 'AnthropicVersion', self::DEFAULT_VERSION ) ),
                                $this->extraHeaders() );
        $workspace = $this->extras() ? trim( (string) $this->setting( 'AnthropicWorkspaceId', '' ) ) : '';
        if ( $workspace !== '' )
        {
            if ( !preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $workspace ) )
                throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'AnthropicWorkspaceId is not a workspace id' );
            $headers[] = 'anthropic-workspace-id: ' . $workspace;
        }
        $payload = $this->buildPayload( $request );
        if ( isset( $payload['fallbacks'] ) )
            $headers[] = 'anthropic-beta: ' . self::FALLBACK_BETA;

        $body = $this->encode( $payload );

        // transient failures (busy, server errors, no connection) are retried by post()
        list( $status, $received, $rateHeaders, $retries ) = $this->post( $endpoint, $headers, $body );
        $data = json_decode( $received, true );
        // Thinking blocks are bound to the exact start of the conversation. When that start changed (a conversation
        // stored before its instructions were kept with it), the API refuses the old blocks: retry once and let it
        // drop them (the conversation goes on without the earlier reasoning).
        if ( $status === 400 && is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] )
             && strpos( $data['error']['message'], 'block_binding' ) !== false && empty( $request['_drop_block'] ) && $this->extras() )
        {
            eZDebug::writeNotice( 'expai: thinking blocks bound to another conversation start, retrying with drop_block', __METHOD__ );
            $payload['thinking'] = array( 'type' => 'adaptive', 'block_binding' => array( 'prefix_mismatch_behavior' => 'drop_block' ) );
            $betas = array( self::BINDING_BETA );
            if ( isset( $payload['fallbacks'] ) )
                $betas[] = self::FALLBACK_BETA;
            $retryHeaders = array_values( array_filter( $headers, function ( $h ) { return stripos( $h, 'anthropic-beta:' ) !== 0; } ) );
            $retryHeaders[] = 'anthropic-beta: ' . implode( ',', $betas );
            $body = json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
            $next = $this->postFollowUp( $endpoint, $retryHeaders, $body );
            if ( $next !== null )
            {
                list( $status, $received, $rateHeaders, $more ) = $next;
                $data = json_decode( $received, true );
                $retries += 1 + $more;
            }
        }
        if ( $status < 200 || $status >= 300 )
            throw $this->httpError( $status, $data, $rateHeaders, $retries );
        $object = json_decode( $received );
        if ( !is_array( $data ) || !isset( $data['content'] ) || !is_array( $data['content'] ) || !is_object( $object ) )
            throw new expAiException( 'The answer of Exponential Intelligence could not be read.', 'HTTP ' . $status . ', no content[]', $status );
        return array(
            'content' => $data['content'],
            'content_json' => json_encode( $object->content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'stop_reason' => isset( $data['stop_reason'] ) ? (string) $data['stop_reason'] : '',
            // set only for a refusal: type, category (cyber, bio, ... or null), explanation
            'stop_details' => isset( $data['stop_details'] ) && is_array( $data['stop_details'] ) ? $data['stop_details'] : null,
            'model' => isset( $data['model'] ) ? (string) $data['model'] : $this->modelName(),
            'usage' => isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array(),
            // the normalised token counts the usage log, prices and limits use (other providers map theirs to these)
            'tokens' => array( 'input' => isset( $data['usage']['input_tokens'] ) ? (int) $data['usage']['input_tokens'] : 0,
                               'output' => isset( $data['usage']['output_tokens'] ) ? (int) $data['usage']['output_tokens'] : 0,
                               'cache_write' => isset( $data['usage']['cache_creation_input_tokens'] ) ? (int) $data['usage']['cache_creation_input_tokens'] : 0,
                               'cache_read' => isset( $data['usage']['cache_read_input_tokens'] ) ? (int) $data['usage']['cache_read_input_tokens'] : 0,
                               'reasoning' => 0, 'reported_cost' => null ),
            'provider' => $this->providerId(),
            'http_status' => $status,
            'retries' => $retries,
            'rate_limits' => $rateHeaders,
        );
    }

    /**
     * The provider of the current user's model (kept for code written for 0.0.1.0: the registry decides now).
     * @return expAiProvider
     * @throws expAiException
     */
    public static function fromConfig( expAiConfig $config )
    {
        return expAiProviderRegistry::instance( $config )->providerFor( $config->effectiveModel() );
    }
}
