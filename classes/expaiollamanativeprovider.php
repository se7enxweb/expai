<?php
/**
 * File containing the expAiOllamaNativeProvider class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A model of a local Ollama server (a provider with Managed=ollama) spoken to through Ollama's own API on the
 * loopback address instead of its OpenAI compatible one, for the test prompt and the "Load model" action of
 * expai/models (expAiModelTest). Ollama's own API reports how the time was spent, which the compatible one does not:
 * loading the model, reading the prompt and writing the answer (load_duration, prompt_eval_*, eval_*), so the test
 * can show the time to the first token and the tokens per second in both directions.
 *
 * It implements expAiProvider, so the test goes through expAiUsageRecorder like every other request (master switch,
 * veto, limits, the usage log). Only text messages; no tools.
 */
class expAiOllamaNativeProvider implements expAiProvider
{
    /** @var array settings of the model (expAiProviderRegistry::settingsFor()) */
    protected $settings;

    /** @var string */
    protected $root;

    /** @var expAiHttpClient|object */
    protected $http;

    /**
     * @param array $settings expAiProviderRegistry::settingsFor() of a model of a provider with Managed=ollama
     * @param object|null $http an expAiHttpClient (tests hand in a stub with request())
     * @throws expAiException when the server is not on the loopback address
     */
    public function __construct( array $settings, $http = null )
    {
        $this->settings = $settings;
        $url = preg_replace( '~/v1/?(chat/completions)?$~', '', rtrim( isset( $settings['BaseURL'] ) ? (string) $settings['BaseURL'] : '', '/' ) );
        $host = (string) parse_url( $url, PHP_URL_HOST );
        if ( !in_array( $host, array( '127.0.0.1', 'localhost', '::1', '[::1]' ), true ) )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'a managed local model server must be on the loopback address' );
        $this->root = $url;
        $timeout = isset( $settings['Timeout'] ) && (int) $settings['Timeout'] > 0 ? (int) $settings['Timeout'] : 280;
        $this->http = $http ?: new expAiHttpClient( $timeout, 5, 4194304, array( 'content-type' ) );
    }

    /** @return expAiOllamaNativeProvider the model of an alias, or null when its provider is not Managed=ollama */
    public static function forAlias( expAiConfig $config, $alias )
    {
        $registry = $config->registry();
        $model = $registry->getModel( (string) $alias );
        $provider = $model ? $registry->getProvider( $model['provider'] ) : null;
        if ( !$provider || !isset( $provider['Managed'] ) || strtolower( (string) $provider['Managed'] ) !== 'ollama' )
            return null;
        return new self( $registry->settingsFor( (string) $alias ) );
    }

    public function providerId()
    {
        return isset( $this->settings['ProviderId'] ) ? (string) $this->settings['ProviderId'] : 'local';
    }

    public function modelName()
    {
        return isset( $this->settings['Model'] ) ? (string) $this->settings['Model'] : '';
    }

    /** @return string */
    public function modelAlias()
    {
        return isset( $this->settings['ModelAlias'] ) ? (string) $this->settings['ModelAlias'] : '';
    }

    /** @return string */
    public function toolUse()
    {
        return 'none';
    }

    /** @return array the JSON body of /api/chat for a request (system, messages of text, max_tokens) */
    public function buildPayload( array $request )
    {
        $messages = array();
        if ( isset( $request['system'] ) && (string) $request['system'] !== '' )
            $messages[] = array( 'role' => 'system', 'content' => (string) $request['system'] );
        foreach ( isset( $request['messages'] ) ? (array) $request['messages'] : array() as $message )
            if ( isset( $message['role'], $message['content'] ) && is_string( $message['content'] ) )
                $messages[] = array( 'role' => (string) $message['role'], 'content' => $message['content'] );
        $payload = array( 'model' => $this->modelName(), 'messages' => $messages, 'stream' => false,
                          'options' => array( 'num_predict' => max( 1, (int) ( isset( $request['max_tokens'] ) ? $request['max_tokens'] : 120 ) ) ) );
        // Effort=none on a model that can reason: thinking off, as the OpenAI compatible request does
        $capabilities = isset( $this->settings['Capabilities'] ) ? (array) $this->settings['Capabilities'] : array();
        if ( isset( $this->settings['Effort'] ) && strtolower( (string) $this->settings['Effort'] ) === 'none' && in_array( 'reasoning_effort', $capabilities, true ) )
            $payload['think'] = false;
        return $payload;
    }

    /**
     * @return array the expai answer: content, stop_reason, model, tokens (input, output), timings (milliseconds:
     *               total, load, prompt, eval, first_token; null when Ollama did not report it)
     * @throws expAiException
     */
    public function send( array $request )
    {
        if ( $this->modelName() === '' )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'Model is not set' );
        $data = $this->post( '/api/chat', $this->buildPayload( $request ) );
        return self::parseAnswer( $data );
    }

    /**
     * The expai answer of an /api/chat response.
     * @return array
     * @throws expAiException
     */
    public static function parseAnswer( $data )
    {
        if ( !is_array( $data ) || !isset( $data['message'] ) || !is_array( $data['message'] ) )
            throw new expAiException( 'The answer of Exponential Intelligence could not be read.', 'ollama /api/chat: no message' );
        $text = expAiText::stripReasoning( isset( $data['message']['content'] ) ? (string) $data['message']['content'] : '' );
        $ms = function ( $name ) use ( $data )
        {
            return isset( $data[$name] ) && is_numeric( $data[$name] ) ? (int) round( $data[$name] / 1000000 ) : null;
        };
        $timings = array( 'total' => $ms( 'total_duration' ), 'load' => $ms( 'load_duration' ), 'prompt' => $ms( 'prompt_eval_duration' ),
                          'eval' => $ms( 'eval_duration' ) );
        // the first token comes after the model is loaded and the prompt is read
        $timings['first_token'] = $timings['load'] !== null || $timings['prompt'] !== null ? (int) $timings['load'] + (int) $timings['prompt'] : null;
        $reason = isset( $data['done_reason'] ) ? (string) $data['done_reason'] : '';
        return array( 'content' => array( array( 'type' => 'text', 'text' => $text ) ),
                      'stop_reason' => $reason === 'length' ? 'max_tokens' : 'end_turn',
                      'model' => isset( $data['model'] ) ? (string) $data['model'] : '',
                      'tokens' => array( 'input' => isset( $data['prompt_eval_count'] ) ? (int) $data['prompt_eval_count'] : 0,
                                         'output' => isset( $data['eval_count'] ) ? (int) $data['eval_count'] : 0 ),
                      'timings' => $timings, 'http_status' => 200 );
    }

    /**
     * Loads the model into memory without generating anything (/api/generate with an empty prompt) and keeps it
     * there for $keepAlive.
     * @param string $keepAlive an Ollama duration (30m, 2h) or seconds
     * @return array load_ms, total_ms
     * @throws expAiException
     */
    public function load( $keepAlive )
    {
        $keepAlive = preg_match( '/^\d{1,5}[smh]?$/', (string) $keepAlive ) ? (string) $keepAlive : '30m';
        $start = microtime( true );
        $data = $this->post( '/api/generate', array( 'model' => $this->modelName(), 'prompt' => '', 'stream' => false,
                                                     'keep_alive' => ctype_digit( $keepAlive ) ? (int) $keepAlive : $keepAlive ) );
        // no load_duration: the model was already in memory
        return array( 'load_ms' => isset( $data['load_duration'] ) && is_numeric( $data['load_duration'] ) ? (int) round( $data['load_duration'] / 1000000 ) : 0,
                      'total_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ) );
    }

    /**
     * Whether the model is in memory, from /api/ps.
     * @return array loaded (bool), until (timestamp or 0), size_mb
     */
    public function loadedState()
    {
        try
        {
            $http = new expAiHttpClient( 3, 2, 1048576, array( 'content-type' ) );
            list( $status, $body ) = $http->request( $this->root . '/api/ps', array(), '', array( 'method' => 'GET' ) );
        }
        catch ( expAiException $e )
        {
            return array( 'loaded' => false, 'until' => 0, 'size_mb' => 0, 'reachable' => false );
        }
        $data = json_decode( (string) $body, true );
        return self::loadedIn( $status === 200 && is_array( $data ) ? $data : array(), $this->modelName() ) + array( 'reachable' => $status === 200 );
    }

    /** @return array loaded, until, size_mb of $model in a decoded /api/ps answer */
    public static function loadedIn( array $ps, $model )
    {
        foreach ( isset( $ps['models'] ) ? (array) $ps['models'] : array() as $m )
        {
            $name = isset( $m['name'] ) ? (string) $m['name'] : ( isset( $m['model'] ) ? (string) $m['model'] : '' );
            if ( $name === (string) $model || $name === $model . ':latest' )
                return array( 'loaded' => true, 'until' => isset( $m['expires_at'] ) ? (int) strtotime( (string) $m['expires_at'] ) : 0,
                              'size_mb' => isset( $m['size'] ) ? (int) round( $m['size'] / 1048576 ) : 0 );
        }
        return array( 'loaded' => false, 'until' => 0, 'size_mb' => 0 );
    }

    /**
     * @return array the decoded answer of a POST to the server
     * @throws expAiException
     */
    protected function post( $path, array $payload )
    {
        list( $status, $body ) = array_pad( $this->http->request( $this->root . $path, array(), json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), 2, '' );
        $data = json_decode( (string) $body, true );
        if ( $status < 200 || $status >= 300 )
        {
            $why = is_array( $data ) && isset( $data['error'] ) && is_string( $data['error'] ) ? expAiText::cut( $data['error'], 200 ) : 'HTTP ' . $status;
            throw new expAiException( 'The local model server refused the request: %reason', 'ollama ' . $path . ' HTTP ' . $status, (int) $status, array( '%reason' => $why ) );
        }
        if ( !is_array( $data ) )
            throw new expAiException( 'The answer of Exponential Intelligence could not be read.', 'ollama ' . $path . ': not JSON' );
        return $data;
    }
}
