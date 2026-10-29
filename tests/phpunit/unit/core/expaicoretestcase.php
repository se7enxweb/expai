<?php
/**
 * File containing the expAiCoreTestCase class: the base of the unit tests of the classes in extension/expai/classes
 * (not its subdirectories). Loaded by the tests of this directory with require_once; not a test itself.
 *
 *  - useSettings(): the code under test reads expai.ini through expAiConfig::instance(). For the test, the settings
 *    of that instance are the shipped ones (extension/expai/settings/expai.ini.append.php) with the test's values on
 *    top; the site's override is not used and its values (keys) are put back after the test.
 *  - post(): a POST request with these fields (ezjscore server functions), restored after the test.
 *  - usage(): a row of expai_usage_log, removed after the test.
 *  - draftLog(): a row of expai_draft_log, removed after the test.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

abstract class expAiCoreTestCase extends expAiTestCase
{
    /** an id no real user has: rows of the tests are found by it */
    const TEST_USER = 987654321;

    /** the provider id of the rows the tests write (no real provider has it) */
    const TEST_PROVIDER = 'xtd';

    protected function tearDown(): void
    {
        parent::tearDown();
        // the objects of the test are destroyed here, while the test runs: a notice of a destructor is the test's,
        // not a failure of PHPUnit between two tests
        gc_collect_cycles();
    }

    /**
     * @param array $settings group => array( name => value ) on top of the shipped expai.ini
     * @return expAiConfig the instance the code under test reads
     */
    protected function useSettings( array $settings = array() )
    {
        $ini = eZINI::instance( 'expai.ini' );
        $property = new ReflectionProperty( 'eZINI', 'BlockValues' );
        $before = $property->getValue( $ini );
        $property->setValue( $ini, $property->getValue( self::shippedIni() ) );
        // no key and no remote endpoint of the shipped settings either: only what the test sets reaches a server
        if ( method_exists( 'expAiTestInstallation', 'blockProviders' ) )
            expAiTestInstallation::blockProviders();
        foreach ( $settings as $group => $values )
            foreach ( $values as $name => $value )
                $ini->setVariable( $group, $name, $value );
        $this->cleanup( static function () use ( $property, $ini, $before ) { $property->setValue( $ini, $before ); } );
        return expAiConfig::instance();
    }

    /**
     * Settings with a provider that needs no key and answers from the mock server (tests/mock/server.php), as the
     * default model mock.e2e. Nothing leaves 127.0.0.1.
     *
     * @param array $settings more settings on top
     * @return expAiConfig
     */
    protected function useMockProvider( array $settings = array(), $model = 'mock/e2e' )
    {
        $mock = expAiTestMockServer::start( 'server.php' );
        return $this->useSettings( array_replace_recursive( array(
            // the user's own model choice (a preference row) is not used: the site default answers
            'AISettings' => array( 'Enabled' => 'true', 'AllowUserModelChoice' => 'disabled' ),
            'ProviderSettings' => array( 'Providers' => array( 'anthropic', self::TEST_PROVIDER ), 'DefaultModel' => self::TEST_PROVIDER . '.e2e' ),
            'Provider_' . self::TEST_PROVIDER => array( 'Type' => 'openai_chat', 'Flavor' => 'huggingface', 'Label' => 'XTD mock',
                'BaseURL' => $mock['url'] . '/hf/v1', 'ApiKey' => 'hf_mock_not_a_key', 'Region' => 'us', 'Retention' => 'unknown',
                'Timeout' => 5, 'RetryAfterMax' => 1 ),
            'Model_' . self::TEST_PROVIDER . '.e2e' => array( 'Provider' => self::TEST_PROVIDER, 'Id' => $model, 'Label' => 'XTD e2e',
                'ToolUse' => 'native', 'WriteTools' => 'enabled', 'WriteGate' => 'trusted', 'MaxOutput' => 800,
                'InputPerMTok' => '1', 'OutputPerMTok' => '2' ),
            'McpBridge' => array( 'Enabled' => 'disabled' ),
            'McpSettings' => array( 'Servers' => array() ),
        ), $settings ) );
    }

    /**
     * @param array $post fields of the request
     * @param string $method
     */
    protected function post( array $post, $method = 'POST' )
    {
        $before = array( $_POST, isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null );
        $tokenEnabled = class_exists( 'ezxFormToken' ) ? ezxFormToken::isEnabled() : false;
        $_POST = $post;
        $_SERVER['REQUEST_METHOD'] = $method;
        $this->cleanup( static function () use ( $before, $tokenEnabled )
        {
            $_POST = $before[0];
            if ( $before[1] === null )
                unset( $_SERVER['REQUEST_METHOD'] );
            else
                $_SERVER['REQUEST_METHOD'] = $before[1];
            if ( class_exists( 'ezxFormToken' ) )
                ezxFormToken::setIsEnabled( $tokenEnabled );
        } );
    }

    /**
     * @param array $row fields of expai_usage_log (user_id, provider default to the test's)
     * @return expAiUsageLog stored, removed after the test
     */
    protected function usage( array $row )
    {
        $this->requireDatabase();
        $row += array( 'user_id' => self::TEST_USER, 'provider' => self::TEST_PROVIDER, 'created' => time(), 'model' => 'claude-sonnet-5-5' );
        $log = new expAiUsageLog( $row );
        $log->store();
        $id = (int) $log->attribute( 'id' );
        $this->cleanup( static function () use ( $id ) { eZPersistentObject::removeObject( expAiUsageLog::definition(), array( 'id' => $id ) ); } );
        return $log;
    }

    /**
     * @param array $row fields of expai_draft_log
     * @return expAiDraftLog stored, removed after the test
     */
    protected function draftLog( array $row )
    {
        $this->requireDatabase();
        $row += array( 'user_id' => self::TEST_USER, 'created' => time(), 'modified' => time(), 'status' => 'draft', 'source' => 'panel', 'action' => 'create' );
        $log = new expAiDraftLog( $row );
        $log->store();
        $id = (int) $log->attribute( 'id' );
        $this->cleanup( static function () use ( $id ) { eZPersistentObject::removeObject( expAiDraftLog::definition(), array( 'id' => $id ) ); } );
        return $log;
    }

    /**
     * A hidden folder below node 2 as the administrator, removed after the test with everything the code under test
     * made below it: drafts never published, their log rows, conversations and threads (expAiScriptFixture).
     *
     * @return eZContentObjectTreeNode
     */
    protected function workFolder( $name = 'expai unit test core' )
    {
        $node = $this->folder( $name );
        $this->cleanup( static function () use ( $node ) { expAiScriptFixture::removeFolder( $node ); } );
        return $node;
    }

    /** Restores $_SESSION after the test (the session variables of eZHTTPTool live there on the command line). */
    protected function keepSession()
    {
        $before = isset( $_SESSION ) ? $_SESSION : null;
        $this->cleanup( static function () use ( $before )
        {
            if ( $before === null )
                unset( $_SESSION );
            else
                $_SESSION = $before;
        } );
    }

    /** Removes every usage and draft log row of the test user and provider (after code that wrote them itself). */
    protected function removeTestRows()
    {
        $this->cleanup( static function ()
        {
            eZPersistentObject::removeObject( expAiUsageLog::definition(), array( 'user_id' => self::TEST_USER ) );
            eZPersistentObject::removeObject( expAiUsageLog::definition(), array( 'provider' => self::TEST_PROVIDER ) );
            eZPersistentObject::removeObject( expAiDraftLog::definition(), array( 'user_id' => self::TEST_USER ) );
        } );
    }

    /**
     * @param callable $fn
     * @return expAiException the exception $fn threw
     */
    protected function expectFailure( callable $fn )
    {
        try
        {
            $fn();
        }
        catch ( expAiException $e )
        {
            return $e;
        }
        $this->fail( 'expected an expAiException' );
    }
}
