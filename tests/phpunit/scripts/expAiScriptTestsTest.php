<?php
/**
 * The script tests of extension/expai/tests, run by the suite the way their headers say (php bin/php/ezexec.php
 * extension/expai/tests/<name>_test.php ...), each in a process of its own. A script passes when it exits with 0,
 * ends with PASS, prints no FAIL line, and reports at least as many "ok" checks as it had when it was brought into
 * the suite (a check that disappears fails the test); where its closing line counts the checks, every one of them
 * must have printed an ok line.
 *
 *  - group scripts: the unit-style scripts (no model call; some write rows of their own in the database, below a
 *    hidden folder this test makes, and remove them again)
 *  - group e2e: the end-to-end scripts against the mock servers of tests/mock, started on free loopback ports
 *  - group provider: the end-to-end script that calls a real model (it costs money). Excluded by default; it runs
 *    only with --group provider and EXPAI_TEST_ALLOW_PAID=1.
 *
 * What a script leaves below its folder (drafts, a folder it moved to the trash, log rows, conversations, threads)
 * and the rows of the mock providers that exist only in its memory are removed after it, also when it fails.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

class expAiScriptTestsTest extends expAiTestCase
{
    /**
     * name => array( arguments of ezexec.php, environment, needs a folder (EXPAI_TEST_PARENT), ok checks at least )
     */
    public static function unitScripts(): array
    {
        $admin = array( 'EXPAI_TEST_LOGIN' => 'admin' );
        return array(
            'expaialttext' => array( 'expaialttext', array(), array(), false, 48 ),
            'expaiaudit' => array( 'expaiaudit', array( '-s', 'admin' ), $admin, false, 48 ),
            'expaichain' => array( 'expaichain', array(), array(), false, 87 ),
            'expaicompactprompt' => array( 'expaicompactprompt', array( '-s', 'admin' ), array(), false, 66 ),
            'expaicost' => array( 'expaicost', array(), array(), false, 92 ),
            'expaidiff' => array( 'expaidiff', array(), array(), false, 70 ),
            'expaifallback' => array( 'expaifallback', array(), array(), false, 47 ),
            'expaigate' => array( 'expaigate', array( '-s', 'admin' ), $admin, false, 47 ),
            'expaimelious' => array( 'expaimelious', array(), array(), false, 98 ),
            'expaimodelstats' => array( 'expaimodelstats', array( '-s', 'admin' ), $admin, false, 45 ),
            'expaimodeltest' => array( 'expaimodeltest', array( '-s', 'admin' ), $admin, false, 51 ),
            'expaipresets' => array( 'expaipresets', array( '-s', 'admin' ), array(), false, 82 ),
            'expaiprovider' => array( 'expaiprovider', array(), array(), false, 159 ),
            'expaiservicetools' => array( 'expaiservicetools', array( '-s', 'admin' ), $admin, false, 54 ),
            'expaidraftrevert' => array( 'expaidraftrevert', array( '-s', 'admin' ), $admin, true, 31 ),
            'expaivectorcheckpoint' => array( 'expaivectorcheckpoint', array( '-s', 'admin' ), $admin, true, 30 ),
            'expaivectorparallel' => array( 'expaivectorparallel', array( '-s', 'admin' ), $admin, true, 85 ),
            'expaivectorreview' => array( 'expaivectorreview', array( '-s', 'admin' ), $admin, true, 68 ),
            'expaiweb' => array( 'expaiweb', array( '-s', 'admin' ), $admin, false, 140 ),
        );
    }

    /**
     * name => array( arguments, environment, mock router, mock provider ids, queue group labels, needs a folder (EXPAI_TEST_NODE), ok checks at least )
     */
    public static function e2eScripts(): array
    {
        $admin = array( 'EXPAI_TEST_LOGIN' => 'admin' );
        return array(
            'expaibridge_e2e' => array( 'expaibridge_e2e', array( '-s', 'admin' ), array(), '', array(), array(), false, 28 ),
            'expaimock_e2e' => array( 'expaimock_e2e', array( '-s', 'admin' ), $admin + array( 'url' => 'EXPAI_MOCK_URL' ), 'server.php',
                                      array( 'hfmock', 'ormock', 'hfbad' ), array(), true, 100 ),
            'expaifallback_e2e' => array( 'expaifallback_e2e', array( '-s', 'admin' ), $admin + array( 'url' => 'EXPAI_MOCK_URL' ), 'server.php',
                                          array( 'hfmock', 'ormock', 'deadlocal', 'deadremote' ), array(), true, 19 ),
            'expaichain_e2e' => array( 'expaichain_e2e', array( '-s', 'admin' ), $admin + array( 'url' => 'EXPAI_CHAIN_MOCK_URL' ), 'chain_server.php',
                                       array( 'tch' ), array(), true, 43 ),
            'expaiqueue_e2e' => array( 'expaiqueue_e2e', array( '-s', 'admin' ), $admin + array( 'url' => 'EXPAI_BATCH_MOCK_URL' ), 'batch_server.php',
                                       array( 'tbanth', 'tboai', 'tbgem', 'tbmis', 'tbor' ), array( 'tb test group' ), false, 167 ),
        );
    }

    #[DataProvider( 'unitScripts' )]
    #[Group( 'scripts' )]
    public function testUnitScript( string $name, array $arguments, array $env, bool $folder, int $minimum ): void
    {
        if ( $folder || $env )
            $this->requireDatabase();
        $parent = null;
        if ( $folder )
        {
            $parent = expAiScriptFixture::folder( "expai script test $name" );
            $env['EXPAI_TEST_PARENT'] = (int) $parent->attribute( 'node_id' );
            $this->cleanup( static function () use ( $parent ) { expAiScriptFixture::removeFolder( $parent ); } );
        }
        if ( $name === 'expaiweb' )
            $env['EXPAI_WEB_TEST_URL'] = $this->webDocroot();
        $this->assertScriptPasses( $name, expAiScriptRunner::run( $name . '_test.php', $env, $arguments ), $minimum );
    }

    #[DataProvider( 'e2eScripts' )]
    #[Group( 'e2e' )]
    public function testMockEndToEndScript( string $name, array $arguments, array $env, string $router, array $providers, array $groups, bool $folder, int $minimum ): void
    {
        $this->requireDatabase();
        $state = null;
        if ( $router !== '' )
        {
            $mock = expAiTestMockServer::start( $router );
            $state = $mock['state'];
            $env[$env['url']] = $mock['url'];
            $env['EXPAI_MOCK_STATE'] = $state;
            file_put_contents( $state . '/requests.jsonl', '' );
        }
        unset( $env['url'] );
        $this->cleanup( static function () use ( $providers, $groups, &$state ) { expAiScriptFixture::removeMockRows( $providers, $groups, $state ); } );
        if ( $folder )
        {
            $node = expAiScriptFixture::folder( "expai script test $name" );
            $env['EXPAI_TEST_NODE'] = (int) $node->attribute( 'node_id' );
            $this->cleanup( static function () use ( $node ) { expAiScriptFixture::removeFolder( $node ); } );
        }
        $this->assertScriptPasses( $name, expAiScriptRunner::run( $name . '_test.php', $env, $arguments ), $minimum );
    }

    #[Group( 'provider' )]
    public function testTranslationSyncWithARealModel(): void
    {
        if ( getenv( 'EXPAI_TEST_ALLOW_PAID' ) !== '1' )
            $this->markTestSkipped( 'calls a real model and costs money: set EXPAI_TEST_ALLOW_PAID=1 (and EXPAI_TEST_MODEL) to run it' );
        $this->requireDatabase();
        $parent = expAiScriptFixture::folder( 'expai script test expaitranslationsync_e2e' );
        $this->cleanup( static function () use ( $parent ) { expAiScriptFixture::removeFolder( $parent ); } );
        $env = array( 'EXPAI_TEST_LOGIN' => 'admin', 'EXPAI_TEST_PARENT' => (int) $parent->attribute( 'node_id' ) );
        $this->assertScriptPasses( 'expaitranslationsync_e2e', expAiScriptRunner::run( 'expaitranslationsync_e2e_test.php', $env, array( '-s', 'admin' ) ), 1 );
    }

    /**
     * @return string the URL of a web server with the pages expaiweb_test.php fetches (a 100 KB page, a slow one)
     */
    private function webDocroot()
    {
        $docroot = expAiTestMockServer::tmp( 'www' );
        file_put_contents( $docroot . '/big.html', '<html><body><p>' . str_repeat( 'a', 102400 ) . "\n" );
        file_put_contents( $docroot . '/slow.php', "<?php sleep( 10 ); echo \"late\";\n" );
        $this->cleanup( static function () use ( $docroot ) { expAiTestMockServer::removeTree( $docroot ); } );
        $server = expAiTestMockServer::start( '', $docroot );
        return $server['url'];
    }

    /**
     * @param string $name
     * @param array $result of expAiScriptRunner::run()
     * @param int $minimum
     */
    private function assertScriptPasses( $name, array $result, $minimum )
    {
        $tail = implode( "\n", array_slice( preg_split( '/\r?\n/', trim( $result['output'] ) ), -15 ) );
        $this->assertSame( array(), $result['fail'], "$name: failed checks\n" . implode( "\n", $result['fail'] ) );
        $this->assertSame( 'PASS', $result['status'], "$name: does not end with PASS\n$tail" );
        $this->assertSame( 0, $result['exit'], "$name: exit code {$result['exit']}\n$tail" );
        $this->assertGreaterThanOrEqual( $minimum, $result['ok'], "$name: fewer checks than before ({$result['ok']} < $minimum)" );
        if ( $result['checks'] !== null )
            $this->assertSame( $result['checks'], $result['ok'], "$name: the script counts {$result['checks']} checks, {$result['ok']} printed ok" );
    }
}
