<?php
/**
 * Helpers of the provider, lane, queue, MCP, tool and web tests (tests/phpunit/unit/providers); loaded by each test
 * file of the directory with require_once.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * An HTTP client that never goes on the network: it answers from a queue of canned answers (array( status, body,
 * headers ) or an exception to throw) and records every request. Its clock is virtual: a retry's wait is recorded
 * in $waits and moves $clock on instead of sleeping.
 */
class expAiProviderTestHttp extends expAiHttpClient
{
    /** @var array */
    public $answers = array();

    /** @var array list of array( url, headers, body, options ) */
    public $requests = array();

    /** @var float the virtual time (seconds) */
    public $clock = 1767225600.0;

    /** @var float[] the waits before retries, in seconds */
    public $waits = array();

    /** @var array|null what lastTransfer() reports (null: as the real client before any request) */
    public $transfer = null;

    /**
     * @param array $answers
     */
    public function __construct( array $answers = array() )
    {
        parent::__construct( 5, 2, 1048576, array( 'retry-after', 'x-ratelimit-*', 'anthropic-ratelimit-*' ) );
        $this->answers = $answers;
    }

    public function postJson( $url, array $headers, $body )
    {
        return $this->request( $url, $headers, $body );
    }

    public function request( $url, array $headers, $body, array $options = array() )
    {
        $this->requests[] = array( $url, $headers, $body, $options );
        if ( !$this->answers )
            throw new RuntimeException( 'expAiProviderTestHttp: no answer left for ' . $url );
        $answer = array_shift( $this->answers );
        if ( $answer instanceof Throwable )
            throw $answer;
        if ( is_callable( $answer ) )
            $answer = $answer( $url, $headers, $body, $options );
        return array_pad( $answer, 3, array() );
    }

    public function now()
    {
        return $this->clock;
    }

    public function pause( $seconds )
    {
        $this->waits[] = $seconds;
        $this->clock += $seconds;
    }

    public function lastTransfer()
    {
        return $this->transfer !== null ? $this->transfer : parent::lastTransfer();
    }

    /** @return array the JSON body of request $i, decoded */
    public function json( $i = 0 )
    {
        return json_decode( (string) $this->requests[$i][2], true );
    }
}

/**
 * Settings of eZINI::instance( <file> ) changed in memory for one test and restored afterwards.
 */
class expAiProviderTestIni
{
    /** @var array list of array( file, group, name, had, value ) */
    private $saved = array();

    /**
     * @param string $file
     * @param array $settings group => array( name => value ); a null value removes the setting
     * @return $this
     */
    public function set( $file, array $settings )
    {
        $ini = eZINI::instance( $file );
        foreach ( $settings as $group => $values )
        {
            $hadGroup = $ini->hasGroup( $group );
            foreach ( $values as $name => $value )
            {
                $had = $ini->hasVariable( $group, $name );
                $this->saved[] = array( $file, $group, $name, $had, $had ? $ini->variable( $group, $name ) : null, $hadGroup );
                if ( $value === null )
                    $ini->removeSetting( $group, $name );
                else
                    $ini->setVariable( $group, $name, $value );
            }
        }
        return $this;
    }

    /** Puts every changed value back, last change first. */
    public function restore()
    {
        foreach ( array_reverse( $this->saved ) as $entry )
        {
            list( $file, $group, $name, $had, $value, $hadGroup ) = $entry;
            $ini = eZINI::instance( $file );
            if ( $had )
                $ini->setVariable( $group, $name, $value );
            elseif ( $ini->hasVariable( $group, $name ) )
                $ini->removeSetting( $group, $name );
            if ( !$hadGroup && $ini->hasGroup( $group ) && !$ini->group( $group ) )
                $ini->removeGroup( $group );
        }
        $this->saved = array();
    }
}

/**
 * Base class of the tests of this directory: in-memory settings of the site's expai.ini (restored after the test).
 */
abstract class expAiProviderTestCase extends expAiTestCase
{
    /** @var expAiProviderTestIni|null */
    private $ini = null;


    /**
     * Sets values of eZINI::instance( $file ) for this test; they are restored after it.
     *
     * @param array $settings group => array( name => value )
     * @param string $file
     */
    protected function site( array $settings, $file = 'expai.ini' )
    {
        if ( $this->ini === null )
        {
            $this->ini = new expAiProviderTestIni();
            $ini = $this->ini;
            $this->cleanup( static function () use ( $ini ) { $ini->restore(); } );
        }
        $this->ini->set( $file, $settings );
    }

    /**
     * The site's expai.ini with every provider replaced by in-memory ones (no key of the site is used) and expai
     * switched on.
     *
     * @param array $providers id => [Provider_<id>] values
     * @param array $models alias => [Model_<alias>] values
     * @param string $default
     * @param array $more more groups
     * @return expAiConfig on eZINI::instance( 'expai.ini' )
     */
    protected function siteProviders( array $providers, array $models, $default, array $more = array() )
    {
        $settings = array( 'AISettings' => array( 'Enabled' => 'true', 'ApiKey' => '', 'DisabledSiteAccesses' => array(), 'AllowUserModelChoice' => 'disabled' ),
                           'ProviderSettings' => array( 'Providers' => array_keys( $providers ), 'DefaultModel' => $default ) );
        foreach ( $providers as $id => $values )
            $settings['Provider_' . $id] = $values;
        foreach ( $models as $alias => $values )
            $settings['Model_' . $alias] = $values;
        $this->site( $settings + $more );
        return expAiConfig::instance();
    }

    /**
     * @param array $env ezjscore $_POST and $_SERVER values for one call; restored after the test
     */
    protected function request( array $post, $method = 'POST' )
    {
        $oldPost = $_POST;
        $oldMethod = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
        $this->cleanup( static function () use ( $oldPost, $oldMethod )
        {
            $_POST = $oldPost;
            if ( $oldMethod === null )
                unset( $_SERVER['REQUEST_METHOD'] );
            else
                $_SERVER['REQUEST_METHOD'] = $oldMethod;
        } );
        $_POST = $post;
        $_SERVER['REQUEST_METHOD'] = $method;
    }
}
