<?php
/**
 * The guards of the suite itself: no test can reach a real provider through the site's settings, mail goes to
 * files, and the audit writes into the run's throwaway directory. The messages name settings, never their values.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

class expAiSuiteGuardsTest extends expAiTestCase
{
    public function testTheSiteSettingsHaveNoKeyAndNoRemoteEndpoint(): void
    {
        $open = array();
        foreach ( eZINI::instance( 'expai.ini' )->groups() as $group => $values )
        {
            if ( !preg_match( '/^(AISettings|Provider_|SearchProvider_|McpServer_)/', $group ) )
                continue;
            foreach ( (array) $values as $name => $value )
            {
                if ( !is_string( $value ) || $value === '' )
                    continue;
                if ( preg_match( '/(Key|Token|Secret)$/', $name ) || preg_match( '#^(https?|wss?)://(?!127\.0\.0\.1[:/]|localhost[:/])#i', $value ) )
                    $open[] = "$group/$name";
            }
        }
        $this->assertSame( array(), $open, 'settings that could reach a real provider' );
    }

    public function testNoProviderOfTheSiteIsReady(): void
    {
        $registry = expAiConfig::instance()->registry();
        $ready = array();
        foreach ( $registry->providerIds() as $id )
        {
            $settings = $registry->getProvider( $id );
            $local = is_array( $settings ) && isset( $settings['Type'] ) && in_array( $settings['Type'], array( 'ollama', 'local' ), true );
            if ( $registry->providerReady( $id ) && !$local )
            {
                $describe = $registry->describeProvider( $id );
                $url = isset( $describe['url'] ) ? (string) $describe['url'] : '';
                if ( $url === '' || !preg_match( '#^https?://(127\.0\.0\.1|localhost)[:/]#', $url ) )
                    $ready[] = $id;
            }
        }
        $this->assertSame( array(), $ready, 'providers of the site a test could call' );
    }

    public function testMailGoesToFiles(): void
    {
        $this->assertSame( 'file', eZINI::instance( 'site.ini' )->variable( 'MailSettings', 'Transport' ) );
    }

    public function testTheAuditWritesIntoTheThrowawayDirectory(): void
    {
        if ( !class_exists( 'expAuditConfig' ) )
            $this->markTestSkipped( 'a kernel without the audit' );
        $config = expAuditConfig::get();
        $this->assertStringStartsWith( rtrim( EXPAI_TEST_AUDIT_DIR, '/' ) . '/', rtrim( $config['logDir'], '/' ) . '/' );
        $this->assertStringNotContainsString( '/log/audit', $config['logDir'] );
    }
}
