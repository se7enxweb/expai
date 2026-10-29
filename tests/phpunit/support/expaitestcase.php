<?php
/**
 * File containing the expAiTestCase class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Base class of the expai unit tests.
 *
 *  - config(): an expAiConfig on the settings this extension ships (extension/expai/settings/expai.ini.append.php),
 *    never the site's override, with the values a test sets in memory. No key of the site is read through it.
 *  - asUser(): runs the test as another user; the user of the process is restored after the test.
 *  - cleanup(): a callable run after the test (last registered first), also when the test fails. Tests that write
 *    rows register their removal here.
 *  - folder(): a hidden folder below node 2 for the test, removed with everything below it after the test (not
 *    moved to the trash).
 */
abstract class expAiTestCase extends PHPUnit\Framework\TestCase
{
    /** @var callable[] */
    private $cleanups = array();

    /** @var eZUser|null */
    private $previousUser = null;

    /** @var int the transactions the test opened itself (inTransaction()); guards count only the ones beyond them */
    protected $testTransactions = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // the server functions set a time limit for a web request (the model's timeout plus 30 s); in the suite it
        // would count on through every later test, so every test starts without one
        if ( function_exists( 'set_time_limit' ) )
            set_time_limit( 0 );
        chdir( EXPAI_TEST_ROOT );
        expAiTestInstallation::forceFileMail();
        expAiTestInstallation::englishTexts();
        expAiTestInstallation::blockProviders();
        // again before every test: a test of the audit may have reset its settings
        if ( defined( 'EXPAI_TEST_AUDIT_DIR' ) )
            expAiTestAudit::isolate( EXPAI_TEST_AUDIT_DIR );
        $this->previousUser = class_exists( 'eZUser' ) && eZDB::hasInstance() ? eZUser::currentUser() : null;
    }

    protected function tearDown(): void
    {
        $errors = array();
        while ( $this->cleanups )
        {
            $cleanup = array_pop( $this->cleanups );
            try
            {
                $cleanup();
            }
            catch ( Throwable $e )
            {
                $errors[] = $e->getMessage();
            }
        }
        // the code under test must close every transaction it opens: one left open would hold the rest of a request
        // and be rolled back at its end
        if ( class_exists( 'eZDB', false ) && eZDB::hasInstance() && eZDB::instance()->transactionCounter() > 0 )
        {
            $errors[] = 'the test left ' . eZDB::instance()->transactionCounter() . ' transaction(s) open';
            eZDB::instance()->rollback();
        }
        if ( $this->previousUser instanceof eZUser )
            eZUser::setCurrentlyLoggedInUser( $this->previousUser, (int) $this->previousUser->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
        $this->previousUser = null;
        parent::tearDown();
        if ( $errors )
            $this->fail( 'cleanup failed: ' . implode( '; ', $errors ) );
    }

    /**
     * @param callable $cleanup
     */
    protected function cleanup( callable $cleanup )
    {
        $this->cleanups[] = $cleanup;
    }

    /** @return eZINI the shipped expai.ini, read fresh */
    protected static function shippedIni()
    {
        return eZINI::fetchFromFile( 'extension/expai/settings/expai.ini.append.php' );
    }

    /**
     * @param array $settings array( group => array( name => value ) ), set on top of the shipped settings
     * @return expAiConfig
     */
    protected function config( array $settings = array() )
    {
        $ini = self::shippedIni();
        foreach ( $settings as $group => $values )
            foreach ( $values as $name => $value )
                $ini->setVariable( $group, $name, $value );
        return new expAiConfig( $ini );
    }

    /** Skips the test unless the installation's database is usable. */
    protected function requireDatabase()
    {
        expAiTestInstallation::requireOrSkip();
    }

    /**
     * Runs the test method; a test that reaches the database without asking requireDatabase() first is skipped
     * with the reason instead of erroring, when the installation has no usable database (a DB-less run). With a
     * usable database the exception is the test's result, as before. PHPUnit 13 calls this hook; older versions
     * do not have it and run the method themselves.
     *
     * @param string $methodName
     * @param array $testArguments
     * @return mixed
     */
    protected function invokeTestMethod( string $methodName, array $testArguments ): mixed
    {
        try
        {
            return parent::invokeTestMethod( $methodName, $testArguments );
        }
        catch ( eZDBNoConnectionException $e )
        {
            if ( expAiTestInstallation::unavailableReason() === null )
                throw $e;
            $this->markTestSkipped( expAiTestInstallation::unavailableReason() );
        }
    }

    /**
     * Opens a transaction that is rolled back after the test: the rows the test writes through eZDB never stay.
     * The test fails when the code under test left a transaction of its own open inside it.
     * Keep such tests short (the site's writers wait for the transaction), and do not create content inside one
     * (content writes files and caches too; use folder() and publish(), which remove what they made).
     */
    protected function inTransaction()
    {
        $this->requireDatabase();
        $db = eZDB::instance();
        $db->begin();
        ++$this->testTransactions;
        $this->cleanup( static function () use ( $db )
        {
            $open = $db->transactionCounter();
            if ( $open > 0 )
                $db->rollback();
            if ( $open > 1 )
                throw new RuntimeException( ( $open - 1 ) . ' transaction(s) of the code under test were left open' );
        } );
    }

    /**
     * @param string $login
     * @return eZUser
     */
    protected function asUser( $login = 'admin' )
    {
        $this->requireDatabase();
        $user = eZUser::fetchByName( $login );
        if ( !$user instanceof eZUser )
            $this->markTestSkipped( "needs the user '$login'" );
        eZUser::setCurrentlyLoggedInUser( $user, (int) $user->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
        return $user;
    }

    /**
     * @param string $name
     * @return eZContentObjectTreeNode a hidden folder below node 2, removed (not trashed) after the test
     */
    protected function folder( $name = 'expai unit test' )
    {
        $admin = $this->asUser( 'admin' );
        $object = eZContentFunctions::createAndPublishObject( array( 'parent_node_id' => 2, 'class_identifier' => 'folder',
            'creator_id' => (int) $admin->attribute( 'contentobject_id' ),
            'attributes' => array( 'name' => $name . ' ' . getmypid() . '-' . mt_rand() ) ), false );
        $this->assertInstanceOf( 'eZContentObject', $object, 'the test folder could not be created' );
        $node = $object->mainNode();
        eZContentObjectTreeNode::hideSubTree( $node );
        $nodeID = (int) $node->attribute( 'node_id' );
        $this->cleanup( static function () use ( $nodeID )
        {
            // as the administrator: the test may end as a user it made, and removed, itself
            $admin = eZUser::fetchByName( 'admin' );
            eZUser::setCurrentlyLoggedInUser( $admin, (int) $admin->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
            if ( eZContentObjectTreeNode::fetch( $nodeID ) )
                eZContentObjectTreeNode::removeSubtrees( array( $nodeID ), false );
        } );
        return $node;
    }

    /**
     * @param int $parentNodeID
     * @param array $attributes
     * @param string $class
     * @return eZContentObject published below the parent; removed with the folder it is in
     */
    protected function publish( $parentNodeID, array $attributes, $class = 'article' )
    {
        $admin = eZUser::fetchByName( 'admin' );
        $object = eZContentFunctions::createAndPublishObject( array( 'parent_node_id' => (int) $parentNodeID, 'class_identifier' => $class,
            'creator_id' => (int) $admin->attribute( 'contentobject_id' ), 'attributes' => $attributes ), false );
        $this->assertInstanceOf( 'eZContentObject', $object, "the test $class could not be created" );
        return $object;
    }

    /**
     * Calls a protected or private method.
     *
     * @param object|string $objectOrClass
     * @param string $method
     * @param array $arguments
     * @return mixed
     */
    protected static function invoke( $objectOrClass, $method, array $arguments = array() )
    {
        $reflection = new ReflectionMethod( $objectOrClass, $method );
        return $reflection->invokeArgs( is_object( $objectOrClass ) ? $objectOrClass : null, $arguments );
    }
}
