<?php
/**
 * File containing the expAiLocalModel class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A local Ollama server of a provider with Managed=ollama, for the view expai/models. The state is read from
 * Ollama's own API on the loopback address (/api/version, /api/ps, /api/tags) and from the status file the helper
 * writes. Changes (start, stop, pull, remove) are never run by the web process: they are written as a request into
 * the spool directory ([LocalModelSettings] SpoolDirectory), which the root-owned helper of share/systemd/ carries
 * out (fixed actions, its own model allowlist, one at a time, audited in the journal).
 */
class expAiLocalModel
{
    const ACTIONS = array( 'start', 'stop', 'pull', 'remove', 'refresh_status' );

    /** @var expAiConfig */
    protected $config;

    /** @var string */
    protected $providerID;

    public function __construct( expAiConfig $config, $providerID )
    {
        $this->config = $config;
        $this->providerID = (string) $providerID;
    }

    /** @return string[] the ids of the providers with Managed=ollama */
    public static function managedProviders( expAiConfig $config )
    {
        $out = array();
        foreach ( $config->registry()->providers() as $id => $settings )
            if ( isset( $settings['Managed'] ) && strtolower( (string) $settings['Managed'] ) === 'ollama' )
                $out[] = $id;
        return $out;
    }

    /** @return string the server's root URL (BaseURL without /v1), loopback only */
    public function rootUrl()
    {
        $settings = $this->config->registry()->getProvider( $this->providerID );
        $url = $settings && isset( $settings['BaseURL'] ) ? (string) $settings['BaseURL'] : '';
        $url = preg_replace( '~/v1/?(chat/completions)?$~', '', rtrim( $url, '/' ) );
        $host = (string) parse_url( $url, PHP_URL_HOST );
        return in_array( $host, array( '127.0.0.1', 'localhost', '::1', '[::1]' ), true ) ? $url : '';
    }

    /** @return array|null a GET of the Ollama API, null when it does not answer */
    protected function api( $path )
    {
        $root = $this->rootUrl();
        if ( $root === '' )
            return null;
        try
        {
            $http = new expAiHttpClient( 3, 2, 1048576, array( 'content-type' ) );
            list( $status, $body ) = $http->request( $root . $path, array(), '', array( 'method' => 'GET' ) );
        }
        catch ( expAiException $e )
        {
            return null;
        }
        $data = json_decode( $body, true );
        return $status === 200 && is_array( $data ) ? $data : null;
    }

    /**
     * @return array running (bool), version, loaded (list of { name, size_mb, until }), installed (list of { name,
     *               size_mb }), helper (array from the status file or null), spool_writable (bool), pending (int)
     */
    public function status()
    {
        $version = $this->api( '/api/version' );
        $out = array( 'provider' => $this->providerID, 'running' => $version !== null, 'version' => $version && isset( $version['version'] ) ? (string) $version['version'] : '',
                      'loaded' => array(), 'installed' => array(), 'root' => $this->rootUrl() );
        if ( $version !== null )
        {
            $ps = $this->api( '/api/ps' );
            foreach ( $ps && isset( $ps['models'] ) ? (array) $ps['models'] : array() as $m )
                $out['loaded'][] = array( 'name' => (string) $m['name'], 'size_mb' => isset( $m['size'] ) ? (int) round( $m['size'] / 1048576 ) : 0,
                                          'until' => isset( $m['expires_at'] ) ? (string) $m['expires_at'] : '' );
            $tags = $this->api( '/api/tags' );
            foreach ( $tags && isset( $tags['models'] ) ? (array) $tags['models'] : array() as $m )
                $out['installed'][] = array( 'name' => (string) $m['name'], 'size_mb' => isset( $m['size'] ) ? (int) round( $m['size'] / 1048576 ) : 0 );
        }
        $file = (string) $this->config->get( 'LocalModelSettings', 'StatusFile', '' );
        $helper = $file !== '' && is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
        $out['helper'] = is_array( $helper ) ? $helper : null;
        $spool = $this->spoolDirectory();
        $out['spool_writable'] = $spool !== '' && is_dir( $spool ) && is_writable( $spool );
        $out['pending'] = $out['spool_writable'] ? count( (array) glob( $spool . '/*.json' ) ) : 0;
        return $out;
    }

    /** @return string */
    protected function spoolDirectory()
    {
        return rtrim( (string) $this->config->get( 'LocalModelSettings', 'SpoolDirectory', '' ), '/' );
    }

    /** @return string[] the models the view offers ([LocalModelSettings] Models[]) */
    public function allowedModels()
    {
        return array_values( array_filter( $this->config->arr( 'LocalModelSettings', 'Models' ), function ( $m ) { return preg_match( '/^[a-z0-9._-]{1,60}(:[a-z0-9._-]{1,40})?$/', $m ); } ) );
    }

    /**
     * Writes a request for the helper.
     * @return string the request id
     * @throws expAiException
     */
    public function request( $action, $model = '' )
    {
        if ( !in_array( $action, self::ACTIONS, true ) )
            throw new expAiException( 'Unknown action.' );
        if ( in_array( $action, array( 'pull', 'remove', 'start' ), true ) && $model !== '' && !in_array( $model, $this->allowedModels(), true ) )
            throw new expAiException( 'This model is not on the list of allowed local models.' );
        if ( in_array( $action, array( 'pull', 'remove' ), true ) && $model === '' )
            throw new expAiException( 'Please choose a model.' );
        $spool = $this->spoolDirectory();
        if ( $spool === '' || !is_dir( $spool ) || !is_writable( $spool ) )
            throw new expAiException( 'The local model helper is not installed (the spool directory is missing or not writable).' );
        $id = bin2hex( random_bytes( 16 ) );
        $request = array( 'id' => $id, 'action' => $action, 'model' => (string) $model, 'requested_by_user_id' => (int) eZUser::currentUserID(), 'ts' => time() );
        $tmp = $spool . '/.' . $id . '.tmp';
        if ( file_put_contents( $tmp, json_encode( $request ) ) === false || !@chmod( $tmp, 0660 ) || !rename( $tmp, $spool . '/' . $id . '.json' ) )
            throw new expAiException( 'The request could not be written for the local model helper.' );
        eZLog::write( sprintf( 'local model request %s: %s %s by user %d', $id, $action, $model, (int) eZUser::currentUserID() ), 'expai-models.log' );
        return $id;
    }
}
