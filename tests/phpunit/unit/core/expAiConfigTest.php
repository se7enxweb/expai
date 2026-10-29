<?php
/**
 * expAiConfig (reading settings, the model choice), expAiException, expAiPolicy and expAiPolicyMigration (the
 * policies with limitations and their renaming), expAiWebBudget (models too slow for a web request).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiConfigTest extends expAiCoreTestCase
{
    public function testFlagsListsAndNumbers(): void
    {
        $config = $this->config( array( 'XTD' => array( 'On' => ' Yes ', 'Off' => 'no', 'One' => 'x', 'Empty' => '', 'List' => array( 'a', '', null, 'b' ),
                                                          'Num' => '700', 'Bad' => 'many' ) ) );
        $this->assertTrue( $config->flag( 'XTD', 'On' ) );
        $this->assertFalse( $config->flag( 'XTD', 'Off', true ) );
        $this->assertTrue( $config->flag( 'XTD', 'Missing', true ), 'a missing flag is its default' );
        $this->assertSame( array( 'x' ), $config->arr( 'XTD', 'One' ), 'a single value is a list of one' );
        $this->assertSame( array(), $config->arr( 'XTD', 'Empty' ) );
        $this->assertSame( array( 'a', 'b' ), $config->arr( 'XTD', 'List' ) );
        $this->assertSame( 600, $config->int( 'XTD', 'Num', 5, 1, 600 ) );
        $this->assertSame( 5, $config->int( 'XTD', 'Bad', 5 ) );
        $this->assertSame( 10, $config->int( 'XTD', 'Missing', 3, 10 ) );
        $this->assertSame( 'fallback', $config->get( 'XTD', 'Missing', 'fallback' ) );
    }

    public function testWritableDatatypesAndPlacement(): void
    {
        $config = $this->useSettings( array( 'ToolSettings' => array( 'WritableDatatypes' => array( 'ezstring', 'eztext' ) ) ) );
        $this->assertSame( array( 'ezstring', 'eztext' ), $config->writableDatatypes() );
        $placement = $config->modelPlacement();
        $this->assertArrayHasKey( 'placement', $placement );
        $this->assertArrayHasKey( 'file', $placement );
        $this->assertStringNotContainsString( EXPAI_TEST_ROOT, $placement['file'], 'the file is relative to the installation' );
    }

    public function testThePreferredModelAndTheProviderSettings(): void
    {
        $this->asUser( 'admin' );
        // the preference is a row of the administrator: written in a transaction that is rolled back
        $this->inTransaction();
        $config = $this->useSettings( array( 'AISettings' => array( 'Model' => 'claude-sonnet-5-5', 'AllowUserModelChoice' => 'enabled' ) ) );
        $before = eZPreferences::value( 'expai_model' );
        $this->cleanup( static function () use ( $before ) { eZPreferences::setValue( 'expai_model', $before === false ? '' : $before ); } );

        eZPreferences::setValue( 'expai_model', 'claude-haiku-5-5' );
        $this->assertSame( 'anthropic.claude-haiku-5-5', $config->preferredModel() );
        $this->assertFalse( $config->preferredModelRefused() );
        $this->assertSame( 'anthropic.claude-haiku-5-5', $config->effectiveModel() );
        $settings = $config->providerSettings();
        $this->assertSame( 'claude-haiku-5-5', $settings['Model'] );
        $this->assertSame( 'anthropic', $settings['ProviderId'] );
        $this->assertSame( 'anthropic.claude-haiku-5-5', $settings['ModelAlias'] );

        eZPreferences::setValue( 'expai_model', 'no-such-model' );
        $this->assertTrue( $config->preferredModelRefused(), 'a model that is not allowed is refused' );
        $this->assertSame( $config->model(), $config->effectiveModel(), 'the default is used instead' );
    }

    public function testExceptionTranslationKeepsTheDetailAndCode(): void
    {
        $e = new expAiException( 'Node %id does not exist or you may not read it.', 'debug detail', 404, array( '%id' => 7 ) );
        $this->assertSame( 'debug detail', $e->detail() );
        $this->assertSame( array( '%id' => 7 ), $e->arguments() );
        $this->assertStringContainsString( '7', $e->translatedMessage() );
        $translated = $e->translated();
        $this->assertSame( $e->translatedMessage(), $translated->getMessage() );
        $this->assertSame( 404, $translated->getCode() );
        $this->assertSame( 'debug detail', $translated->detail() );
        $this->assertSame( '', ( new expAiException( 'x' ) )->translated()->detail() );
    }

    public function testPolicyLimitationValues(): void
    {
        $this->assertFalse( expAiPolicy::limitationValues( 'write', 'Provider', eZUser::fetch( eZUser::anonymousId() ) ), 'anonymous has nothing' );
        $admin = $this->asUser( 'admin' );
        $this->assertTrue( expAiPolicy::limitationValues( 'model_use', 'Provider', $admin ), 'the administrator may use every provider' );
        $this->assertTrue( expAiPolicy::allows( 'model_use', 'Provider', 'anything', $admin ) );

        // a user with a limited policy: a stub with the access the kernel would report
        $limited = new expAiCoreTestAccessUser( array( 'accessWord' => 'limited', 'policies' => array(
            array( 'Provider' => array( 'huggingface', 'local' ) ), array( 'Provider' => array( 'local' ) ) ) ) );
        $this->assertSame( array( 'huggingface', 'local' ), expAiPolicy::limitationValues( 'model_use', 'Provider', $limited ) );
        $this->assertTrue( expAiPolicy::allows( 'model_use', 'Provider', 'local', $limited ) );
        $this->assertFalse( expAiPolicy::allows( 'model_use', 'Provider', 'openrouter', $limited ) );
        $open = new expAiCoreTestAccessUser( array( 'accessWord' => 'limited', 'policies' => array( array( 'Provider' => array( 'local' ) ), array( 'Node' => array( 2 ) ) ) ) );
        $this->assertTrue( expAiPolicy::limitationValues( 'model_use', 'Provider', $open ), 'a policy without the limitation allows every value' );
        $none = new expAiCoreTestAccessUser( array( 'accessWord' => 'no' ) );
        $this->assertFalse( expAiPolicy::limitationValues( 'model_use', 'Provider', $none ) );
        $this->assertFalse( expAiPolicy::allows( 'model_use', 'Provider', 'local', $none ) );
        $emptyLimited = new expAiCoreTestAccessUser( array( 'accessWord' => 'limited', 'policies' => array() ) );
        $this->assertFalse( expAiPolicy::limitationValues( 'model_use', 'Provider', $emptyLimited ) );
    }

    public function testPolicyValueListsOfTheRoleEditor(): void
    {
        $this->useSettings( array( 'ProviderSettings' => array( 'Providers' => array( 'anthropic', 'huggingface' ) ),
                                   'McpSettings' => array( 'Servers' => array( 'docs' ) ), 'McpServer_docs' => array( 'Label' => 'Docs' ) ) );
        $policy = new expAiPolicy( array() );
        $providers = array_column( $policy->providers(), 'name', 'id' );
        $this->assertSame( 'Anthropic (anthropic)', $providers['anthropic'] );
        $this->assertArrayHasKey( 'huggingface', $providers );
        $this->assertArrayHasKey( 'local', $providers, 'a configured provider that is not active is offered too' );
        $servers = array_column( $policy->mcpServers(), 'name', 'id' );
        $this->assertSame( array( 'exp' => 'Exponential (in-process bridge)', 'docs' => 'Docs (docs)' ), $servers );
        foreach ( $policy->serviceDomains() as $domain )
            $this->assertSame( $domain['id'], $domain['name'] );
    }

    public function testPolicyMigrationRenamesOldFunctionNames(): void
    {
        $this->inTransaction();
        $role = eZRole::create( 'expai xtd migration test ' . mt_rand() );
        $role->store();
        $role->appendPolicy( 'expai', 'facts', array( 'Node' => array( 2 ) ) );
        $roleID = (int) $role->attribute( 'id' );

        $mine = function ( array $rows ) use ( $roleID ) { return array_values( array_filter( $rows, function ( $r ) use ( $roleID ) { return $r['role_id'] === $roleID; } ) ); };
        $pending = array_filter( expAiPolicyMigration::pending(), function ( $p ) use ( $roleID ) { return (int) $p->attribute( 'role_id' ) === $roleID; } );
        $this->assertCount( 1, $pending );

        $dry = $mine( expAiPolicyMigration::run( true ) );
        $this->assertCount( 1, $dry );
        $this->assertSame( array( 'expai/facts', 'expai/summary' ), array( $dry[0]['from'], $dry[0]['to'] ) );
        $this->assertSame( $role->attribute( 'name' ), $dry[0]['role'] );
        $this->assertArrayHasKey( 'Node', $dry[0]['limitations'] );
        $this->assertCount( 1, array_filter( expAiPolicyMigration::pending(), function ( $p ) use ( $roleID ) { return (int) $p->attribute( 'role_id' ) === $roleID; } ),
                            'a dry run changes nothing' );

        $this->assertCount( 1, $mine( expAiPolicyMigration::run( false ) ) );
        $this->assertSame( array(), $mine( expAiPolicyMigration::run( true ) ), 'a second run finds nothing' );
        $functions = array();
        foreach ( eZPersistentObject::fetchObjectList( eZPolicy::definition(), null, array( 'role_id' => $roleID ) ) as $policy )
            $functions[] = $policy->attribute( 'module_name' ) . '/' . $policy->attribute( 'function_name' );
        $this->assertSame( array( 'expai/summary' ), $functions, 'the policy keeps its id and limitation, with the new name' );
        $this->cleanup( static function () { eZRole::expireCache(); } );
    }

    public function testWebBudget(): void
    {
        $config = $this->useSettings( array(
            'WebBudget' => array( 'Seconds' => '2', 'MinSamples' => '3' ),
            'ProviderSettings' => array( 'Providers' => array( 'anthropic', 'xtdlocal' ) ),
            'Provider_xtdlocal' => array( 'Type' => 'openai_chat', 'BaseURL' => 'http://127.0.0.1:9/v1', 'Managed' => 'ollama', 'ApiKey' => '' ),
            'Model_xtdlocal.small' => array( 'Provider' => 'xtdlocal', 'Id' => 'small', 'Label' => 'Small local' ),
            'Model_anthropic.xtd-always' => array( 'Provider' => 'anthropic', 'Id' => 'x', 'RunInBackground' => 'always' ),
            'Model_anthropic.xtd-never' => array( 'Provider' => 'anthropic', 'Id' => 'y', 'RunInBackground' => 'never', 'Slow' => 'true' ),
            'Model_anthropic.xtd-measured' => array( 'Provider' => 'anthropic', 'Id' => 'z', 'Label' => 'Measured' ),
        ) );
        $this->assertSame( 2, expAiWebBudget::seconds( $config ) );
        $this->assertSame( '', expAiWebBudget::reason( 'no.such.model', $config ) );
        $this->assertSame( 'setting', expAiWebBudget::reason( 'anthropic.xtd-always', $config ) );
        $this->assertSame( '', expAiWebBudget::reason( 'anthropic.xtd-never', $config ), 'the model\'s own setting wins over Slow' );
        $this->assertSame( 'slow', expAiWebBudget::reason( 'xtdlocal.small', $config ), 'a managed local server is slow' );
        $this->assertSame( 'measured', expAiWebBudget::reason( 'anthropic.xtd-measured', $config, 5.0 ) );
        $this->assertSame( '', expAiWebBudget::reason( 'anthropic.xtd-measured', $config, 1.0 ) );

        // measured from the usage log: at least MinSamples successful requests
        $this->assertNull( expAiWebBudget::measuredSeconds( 'anthropic.xtd-measured', $config ) );
        foreach ( array( 4000, 5000, 6000 ) as $ms )
            $this->usage( array( 'model_alias' => 'anthropic.xtd-measured', 'latency_ms' => $ms ) );
        $this->usage( array( 'model_alias' => 'anthropic.xtd-measured', 'latency_ms' => 99000, 'error_class' => 'timeout' ) );
        $this->assertEqualsWithDelta( 5.0, expAiWebBudget::measuredSeconds( 'anthropic.xtd-measured', $config ), 0.001, 'failed requests do not count' );
        $this->assertFalse( expAiWebBudget::fits( 'anthropic.xtd-measured', $config ) );

        // a web request refuses, the command line does not
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
        $this->cleanup( static function () use ( $method ) { if ( $method === null ) unset( $_SERVER['REQUEST_METHOD'] ); else $_SERVER['REQUEST_METHOD'] = $method; } );
        unset( $_SERVER['REQUEST_METHOD'] );
        $this->assertFalse( expAiWebBudget::inWebRequest() );
        expAiWebBudget::assertFits( 'anthropic.xtd-measured', 'field', $config );
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertTrue( expAiWebBudget::inWebRequest() );
        expAiWebBudget::assertFits( 'anthropic.xtd-never', 'field', $config );
        $field = $this->expectFailure( function () use ( $config ) { expAiWebBudget::assertFits( 'anthropic.xtd-measured', 'field', $config ); } );
        $this->assertStringContainsString( 'too slow for field actions', $field->getMessage() );
        $this->assertSame( array( '%model' => 'Measured', '%seconds' => 2 ), $field->arguments() );
        $this->assertSame( 'web budget: measured', $field->detail() );
        $test = $this->expectFailure( function () use ( $config ) { expAiWebBudget::assertFits( 'xtdlocal.small', 'test', $config ); } );
        $this->assertStringContainsString( 'too slow to be tested', $test->getMessage() );
        $this->assertSame( 'web budget: slow', $test->detail() );
    }
}

/** A user whose access to expai is what the test says (the kernel's hasAccessTo() answer) */
class expAiCoreTestAccessUser extends eZUser
{
    private $access;

    public function __construct( array $access )
    {
        parent::__construct( array( 'contentobject_id' => 987654321, 'login' => 'xtd' ) );
        $this->access = $access;
    }

    public function isRegistered()
    {
        return true;
    }

    public function hasAccessTo( $module, $function = false )
    {
        return $this->access;
    }
}
