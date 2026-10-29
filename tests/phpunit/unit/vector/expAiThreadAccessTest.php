<?php
/**
 * expAiThreadAccess: who reads and writes a thread (the owner; approvers read), and what the limitations of
 * expai/vector (Subtree, Section, Scope, Review) allow. Users and roles of their own; threads, draft log rows and
 * approval items in a transaction that is rolled back.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectortestcase.php';

class expAiThreadAccessTest extends expAiVectorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        if ( !expAiThread::available() )
            $this->markTestSkipped( 'the thread tables are missing' );
    }

    /** @return array( folder node, article ) */
    private function content()
    {
        $this->asUser( 'admin' );
        $folder = $this->folder( 'expai unit test access' );
        $article = $this->publish( (int) $folder->attribute( 'node_id' ), array( 'title' => 'Access article' ) );
        return array( $folder, $article );
    }

    private static function scope( array $scope )
    {
        return expAiThread::cleanScope( $scope );
    }

    public function testOnlyTheOwnerReadsAndWrites(): void
    {
        list( $folder ) = $this->content();
        $other = $this->userWithRole( array( array( 'expai', 'write' ), array( 'content', 'read' ) ), false );
        $this->asUser( 'admin' );
        $this->inTransaction();
        $thread = $this->thread( array( 'scope' => array( 'nodes' => array( (int) $folder->attribute( 'node_id' ) ) ) ) );
        $admin = eZUser::fetchByName( 'admin' );
        $this->assertTrue( expAiThreadAccess::canRead( $thread ) );
        $this->assertTrue( expAiThreadAccess::canWrite( $thread ) );
        $this->assertTrue( expAiThreadAccess::canWrite( $thread, $admin ) );
        $this->assertFalse( expAiThreadAccess::canRead( $thread, $other ), 'another editor does not see the thread' );
        $this->assertFalse( expAiThreadAccess::canWrite( $thread, $other ) );
        $anonymous = eZUser::fetch( (int) eZINI::instance()->variable( 'UserSettings', 'AnonymousUserID' ) );
        $this->assertFalse( expAiThreadAccess::canRead( $thread, $anonymous ) );
        $this->assertFalse( expAiThreadAccess::canWrite( $thread, $anonymous ) );
    }

    public function testAnApproverOfAVersionTheThreadWroteReadsIt(): void
    {
        list( $folder, $article ) = $this->content();
        $approver = $this->userWithRole( array( array( 'expai', 'write' ), array( 'content', 'read' ), array( 'collaboration', '*' ) ), false );
        $this->asUser( 'admin' );
        $this->inTransaction();
        $admin = eZUser::fetchByName( 'admin' );
        $adminID = (int) $admin->attribute( 'contentobject_id' );
        $thread = $this->thread( array( 'scope' => array( 'nodes' => array( (int) $article->attribute( 'main_node_id' ) ) ) ) );
        $objectID = (int) $article->attribute( 'id' );
        $version = (int) $article->attribute( 'current_version' );
        $this->assertFalse( expAiThreadAccess::isApprover( $thread, $approver ), 'no approval yet' );

        // the thread wrote the version (draft log), which waits for the approver (an ezapprove item, no notification)
        $log = new expAiDraftLog( array( 'contentobject_id' => $objectID, 'version' => $version, 'thread_id' => (int) $thread->attribute( 'id' ),
                                         'user_id' => $adminID, 'node_id' => (int) $article->attribute( 'main_node_id' ), 'model' => 'unit', 'created' => time(), 'modified' => time() ) );
        $log->store();
        $item = eZCollaborationItem::create( 'ezapprove', $adminID );
        $item->setAttribute( 'data_int1', $objectID );
        $item->setAttribute( 'data_int2', $version );
        $item->setAttribute( 'data_int3', false );
        $item->store();
        foreach ( array( array( $adminID, eZCollaborationItemParticipantLink::ROLE_AUTHOR ),
                         array( (int) $approver->attribute( 'contentobject_id' ), eZCollaborationItemParticipantLink::ROLE_APPROVER ) ) as $participant )
        {
            eZCollaborationItemParticipantLink::create( (int) $item->attribute( 'id' ), $participant[0], $participant[1], eZCollaborationItemParticipantLink::TYPE_USER )->store();
            eZCollaborationItemGroupLink::addItem( eZCollaborationProfile::instance( $participant[0] )->attribute( 'main_group' ), (int) $item->attribute( 'id' ), $participant[0] );
        }

        $this->assertTrue( expAiThreadAccess::isApprover( $thread, $approver ) );
        $this->assertTrue( expAiThreadAccess::canRead( $thread, $approver ), 'the approver reads the thread' );
        $this->assertFalse( expAiThreadAccess::canWrite( $thread, $approver ), 'but does not change or run it' );

        eZUser::setCurrentlyLoggedInUser( $approver, (int) $approver->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
        $shared = expAiThreadAccess::sharedWithMe();
        if ( !class_exists( 'expCollaborationInbox' ) )
            $this->assertSame( array(), $shared );
        else
            $this->assertSame( array( (int) $thread->attribute( 'id' ) ), array_map( function ( $t ) { return (int) $t->attribute( 'id' ); }, $shared ) );
        // the list of Vector shows it under "shared", not as the approver's own
        $list = $this->call( 'threads' );
        if ( class_exists( 'expCollaborationInbox' ) )
            $this->assertContains( (string) $thread->attribute( 'uuid' ), array_column( $list['shared'], 'uuid' ) );
        $this->assertNotContains( (string) $thread->attribute( 'uuid' ), array_column( $list['threads'], 'uuid' ) );
        // the author of the version sees nothing shared: the thread is the author's own
        $this->asUser( 'admin' );
        $this->assertNotContains( (int) $thread->attribute( 'id' ), array_map( function ( $t ) { return (int) $t->attribute( 'id' ); }, expAiThreadAccess::sharedWithMe() ) );
    }

    public function testVectorPoliciesOfAnAdministratorANoneAndALimitedUser(): void
    {
        list( $folder ) = $this->content();
        $this->assertTrue( expAiThreadAccess::vectorPolicies() );
        $this->assertTrue( expAiThreadAccess::hasVector() );
        $this->assertSame( array( 'scope' => array( 'node', 'subtree', 'class' ), 'review' => array( 'staged', 'direct' ) ), expAiThreadAccess::choices() );

        $none = $this->userWithRole( array( array( 'expai', 'write' ) ), false );
        $this->assertFalse( expAiThreadAccess::vectorPolicies( $none ) );
        $this->assertFalse( expAiThreadAccess::hasVector( $none ) );
        $this->assertSame( array( 'scope' => array(), 'review' => array() ), expAiThreadAccess::choices( $none ) );
        $this->assertSame( 'You do not have access to Vector.', expAiThreadAccess::check( self::scope( array( 'nodes' => array( 2 ) ) ), 'staged', $none ) );

        $path = (string) $folder->attribute( 'path_string' );
        $limited = $this->userWithRole( array( array( 'content', 'read' ), array( 'expai', 'vector', array( 'Subtree' => array( $path ), 'Scope' => array( 'node', 'subtree' ), 'Review' => array( 'staged' ) ) ),
                                                array( 'expai', 'vector', array( 'Section' => array( '1' ), 'Scope' => array( 'class' ) ) ) ), false );
        $policies = expAiThreadAccess::vectorPolicies( $limited );
        $this->assertCount( 2, $policies );
        $byScope = array();
        foreach ( $policies as $limits )
            $byScope[implode( ',', $limits['Scope'] )] = $limits;
        $this->assertSame( array( $path ), $byScope['node,subtree']['Subtree'] );
        $this->assertSame( array( 'staged' ), $byScope['node,subtree']['Review'] );
        $this->assertSame( array( '1' ), $byScope['class']['Section'] );
        $choices = expAiThreadAccess::choices( $limited );
        $this->assertSame( array( 'node', 'subtree', 'class' ), $choices['scope'] );
        $this->assertSame( array( 'staged', 'direct' ), $choices['review'], 'the section policy has no Review limitation' );
        $this->assertFalse( expAiThreadAccess::vectorPolicies( eZUser::fetch( (int) eZINI::instance()->variable( 'UserSettings', 'AnonymousUserID' ) ) ) );
    }

    public function testARoleAssignedForASubtreeLimitsVectorToIt(): void
    {
        list( $folder, $article ) = $this->content();
        $assigned = $this->userWithRole( array( array( 'content', 'read' ), array( 'expai', 'vector' ) ), false, array( 'subtree', (int) $folder->attribute( 'node_id' ) ) );
        $policies = expAiThreadAccess::vectorPolicies( $assigned );
        $this->assertIsArray( $policies );
        $this->assertSame( array( (string) $folder->attribute( 'path_string' ) ), $policies[0]['Subtree'], 'User_Subtree of the assignment is the Subtree limitation' );
        $this->assertSame( '', expAiThreadAccess::check( self::scope( array( 'nodes' => array( (int) $article->attribute( 'main_node_id' ) ) ) ), 'direct', $assigned ) );
        $this->assertStringContainsString( 'outside the subtrees', expAiThreadAccess::check( self::scope( array( 'nodes' => array( 2 ) ) ), 'direct', $assigned ) );
    }

    public function testANewThreadTakesTheReviewTheRoleAllows(): void
    {
        list( $folder, $article ) = $this->content();
        $this->setIni( 'VectorSettings', 'DefaultReview', 'staged' );
        $this->userWithRole( array( array( 'content', 'read' ), array( 'expai', 'write' ), array( 'expai', 'vector', array( 'Review' => array( 'direct' ) ) ) ) );
        $this->inTransaction();
        $answer = $this->call( 'thread_save', array( 'thread' => '', 'node' => (int) $article->attribute( 'main_node_id' ) ) );
        $this->assertSame( 'direct', (string) expAiThread::fetchByUuid( $answer['thread']['uuid'] )->attribute( 'review_policy' ), 'staged is not allowed: the allowed policy' );
    }

    public function testCheckReadsTheScopeAgainstTheSite(): void
    {
        list( $folder ) = $this->content();
        $this->assertSame( '', expAiThreadAccess::check( self::scope( array( 'subtrees' => array( (int) $folder->attribute( 'node_id' ) ), 'classes' => array( 'article' ) ) ), 'direct' ) );
        $this->assertStringContainsString( 'Node 999999999 does not exist', expAiThreadAccess::check( self::scope( array( 'nodes' => array( 999999999 ) ) ), 'staged' ) );
        $this->assertStringContainsString( 'The class "nosuchclassx" does not exist', expAiThreadAccess::check( self::scope( array( 'classes' => array( 'nosuchclassx' ) ) ), 'staged' ) );
        $this->assertStringContainsString( 'The language "xxx-XX" is not used', expAiThreadAccess::check( self::scope( array( 'languages' => array( 'xxx-XX' ) ) ), 'staged' ) );
        // an unknown review policy is read as staged
        $this->assertSame( '', expAiThreadAccess::check( self::scope( array( 'nodes' => array( (int) $folder->attribute( 'node_id' ) ) ) ), 'whatever' ) );
    }

    public function testCheckAppliesTheLimitationsOfEveryPolicy(): void
    {
        list( $folder, $article ) = $this->content();
        $path = (string) $folder->attribute( 'path_string' );
        $section = (string) $article->attribute( 'section_id' );
        $limited = $this->userWithRole( array( array( 'content', 'read' ),
            array( 'expai', 'vector', array( 'Subtree' => array( $path ), 'Scope' => array( 'node' ), 'Review' => array( 'staged' ) ) ) ), false );
        $inside = self::scope( array( 'nodes' => array( (int) $article->attribute( 'main_node_id' ) ) ) );
        $this->assertSame( '', expAiThreadAccess::check( $inside, 'staged', $limited ) );
        $this->assertStringContainsString( 'does not allow this review policy', expAiThreadAccess::check( $inside, 'direct', $limited ) );
        $this->assertStringContainsString( 'does not allow this kind of scope (subtree)',
            expAiThreadAccess::check( self::scope( array( 'subtrees' => array( (int) $folder->attribute( 'node_id' ) ) ) ), 'staged', $limited ) );
        $this->assertStringContainsString( 'outside the subtrees your role allows', expAiThreadAccess::check( self::scope( array( 'nodes' => array( 2 ) ) ), 'staged', $limited ) );
        $this->assertStringContainsString( 'choose a location inside them', expAiThreadAccess::check( self::scope( array( 'languages' => array( (string) $article->attribute( 'initial_language_code' ) ) ) ), 'staged', $limited ) );
        $this->assertStringContainsString( 'does not allow this kind of scope (class)', expAiThreadAccess::check( self::scope( array( 'classes' => array( 'article' ) ) ), 'staged', $limited ) );

        // the protected rule of one policy, for the section limitation
        $nodes = array( array( 'kind' => 'node', 'path' => $path, 'section' => $section ) );
        $fails = function ( array $limits, array $nodes, array $kinds, array $scope ) { return self::invoke( 'expAiThreadAccess', 'failsPolicy', array( $limits, $nodes, $kinds, self::scope( $scope ), 'staged' ) ); };
        $this->assertSame( '', $fails( array( 'Section' => array( $section ) ), $nodes, array( 'node' ), array( 'nodes' => array( 1 ) ) ) );
        $this->assertStringContainsString( 'in a section your role does not allow', $fails( array( 'Section' => array( 'no-such-section' ) ), $nodes, array( 'node' ), array( 'nodes' => array( 1 ) ) ) );
        $this->assertStringContainsString( 'limits Vector to some sections', $fails( array( 'Section' => array( $section ) ), array(), array( 'class' ), array( 'classes' => array( 'article' ) ) ) );
        $this->assertSame( '', $fails( array( 'Subtree' => array( '', $path ) ), $nodes, array( 'node' ), array( 'nodes' => array( 1 ) ) ), 'an empty subtree value matches nothing, the other does' );
    }
}
