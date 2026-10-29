<?php
/**
 * Helpers of the Vector unit tests: in-memory settings of the site's expai.ini (restored after the test), POST
 * requests to the ezjscore functions, threads, and users with a role of their own (removed after the test).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

abstract class expAiVectorTestCase extends expAiTestCase
{
    /** @var array request globals before the test */
    private $savedGlobals = null;

    protected function tearDown(): void
    {
        if ( $this->savedGlobals !== null )
        {
            $_POST = $this->savedGlobals['post'];
            if ( $this->savedGlobals['method'] === null )
                unset( $_SERVER['REQUEST_METHOD'] );
            else
                $_SERVER['REQUEST_METHOD'] = $this->savedGlobals['method'];
            $this->savedGlobals = null;
        }
        parent::tearDown();
    }

    /**
     * The folder of expAiTestCase, removed as the administrator: a test may end as a user of its own (already
     * removed by then), who may not remove the folder.
     */
    protected function folder( $name = 'expai unit test' )
    {
        $node = parent::folder( $name );
        $this->cleanup( static function ()
        {
            $admin = eZUser::fetchByName( 'admin' );
            eZUser::setCurrentlyLoggedInUser( $admin, (int) $admin->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
        } );
        return $node;
    }

    /**
     * Sets a value of a site INI file in memory; the previous value (or its absence) comes back after the test.
     *
     * @param string $group
     * @param string $name
     * @param mixed $value
     * @param string $file
     */
    protected function setIni( $group, $name, $value, $file = 'expai.ini' )
    {
        $ini = eZINI::instance( $file );
        $had = $ini->hasVariable( $group, $name );
        $old = $had ? $ini->variable( $group, $name ) : null;
        $hadGroup = $ini->hasGroup( $group );
        $ini->setVariable( $group, $name, $value );
        $this->cleanup( static function () use ( $ini, $group, $name, $had, $old, $hadGroup )
        {
            if ( $had )
                $ini->setVariable( $group, $name, $old );
            elseif ( $hadGroup )
                $ini->removeSetting( $group, $name );
            else
                $ini->removeGroup( $group );
        } );
    }

    /**
     * Makes the request a POST with these fields and the current form token.
     *
     * @param array $fields
     */
    protected function post( array $fields = array() )
    {
        if ( $this->savedGlobals === null )
            $this->savedGlobals = array( 'post' => $_POST, 'method' => isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null );
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $token = class_exists( 'ezxFormToken' ) ? ezxFormToken::getToken() : '';
        $_POST = $fields + array( 'ezxform_token' => $token );
    }

    /**
     * @param string $function a function of expAiVectorServerFunctions
     * @param array $fields POST
     * @return mixed its answer
     */
    protected function call( $function, array $fields = array() )
    {
        $this->post( $fields );
        return call_user_func( array( 'expAiVectorServerFunctions', $function ), array() );
    }

    /**
     * @param callable $fn
     * @param string $needle a part of the expected message
     * @return expAiException
     */
    protected function assertRefused( callable $fn, $needle )
    {
        try
        {
            $fn();
        }
        catch ( expAiException $e )
        {
            $message = strtr( $e->getMessage(), $e->arguments() );
            $this->assertStringContainsString( $needle, $message );
            return $e;
        }
        $this->fail( "no refusal, expected: $needle" );
    }

    /**
     * @param array $row of expAiThread::create() (user_id defaults to the current user)
     * @return expAiThread
     */
    protected function thread( array $row = array() )
    {
        $row += array( 'user_id' => (int) eZUser::currentUser()->attribute( 'contentobject_id' ), 'title' => 'expai unit test thread',
                       'scope' => array(), 'run_mode' => 'write', 'review_policy' => 'staged', 'model_alias' => '' );
        return expAiThread::create( $row );
    }

    /**
     * A user that was never published (no groups: only the role given here applies), with a role of its own;
     * the user, its object and the role are removed after the test.
     *
     * @param array $policies array( array( module, function, limitations ), ... )
     * @param bool $login log the user in
     * @param array $assignment array( limit identifier, limit value ) of the role assignment (subtree, section), or none
     * @return eZUser
     */
    protected function userWithRole( array $policies, $login = true, array $assignment = array() )
    {
        $this->requireDatabase();
        $name = 'expai-unit-' . bin2hex( random_bytes( 4 ) );
        $admin = eZUser::fetchByName( 'admin' );
        $object = eZContentClass::fetchByIdentifier( 'user' )->instantiate( (int) $admin->attribute( 'contentobject_id' ) );
        $userID = (int) $object->attribute( 'id' );
        $role = null;
        $this->cleanup( static function () use ( $userID, &$role )
        {
            if ( $role instanceof eZRole )
                $role->removeThis();
            $object = eZContentObject::fetch( $userID );
            if ( $object instanceof eZContentObject )
                $object->purge();
            if ( eZUser::fetch( $userID ) )
                eZUser::removeUser( $userID );
            eZUser::purgeUserCacheByUserId( $userID );
            eZRole::expireCache();
            eZContentObject::clearCache();
        } );
        $user = eZUser::fetch( $userID ) ?: eZUser::create( $userID );
        $user->setAttribute( 'login', $name );
        $user->setAttribute( 'email', $name . '@x1.example.invalid' );
        $user->setAttribute( 'password_hash', eZUser::createHash( $name, bin2hex( random_bytes( 12 ) ), eZUser::site(), eZUser::hashType() ) );
        $user->setAttribute( 'password_hash_type', eZUser::hashType() );
        $user->store();
        $role = eZRole::create( 'expai unit test ' . $name );
        $role->store();
        foreach ( $policies as $policy )
            $role->appendPolicy( $policy[0], $policy[1], isset( $policy[2] ) ? $policy[2] : array() );
        if ( $assignment )
            $role->assignToUser( $userID, $assignment[0], $assignment[1] );
        else
            $role->assignToUser( $userID );
        eZRole::expireCache();
        eZUser::purgeUserCacheByUserId( $userID );
        $user = eZUser::fetch( $userID );
        if ( $login )
            eZUser::setCurrentlyLoggedInUser( $user, $userID, eZUser::NO_SESSION_REGENERATE );
        return $user;
    }

    /** @return string rich text XML of paragraphs */
    protected static function xml( array $paragraphs )
    {
        $out = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<section xmlns:image="http://ez.no/namespaces/ezpublish3/image/" xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/">';
        foreach ( $paragraphs as $p )
            $out .= '<paragraph>' . htmlspecialchars( $p, ENT_XML1 ) . '</paragraph>';
        return $out . "</section>\n";
    }
}
