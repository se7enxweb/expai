<?php
/**
 * The rules of [RequestVeto] that name content apply to every object a request reads (expAiGate::objectVeto(),
 * expAiContentReader): a vetoed object is refused, or withheld from a list with the rule's reason. No database: the
 * objects are made in memory.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

/** An object with a section, a class and locations, none of them read from the database. */
class expAiObjectVetoTestObject extends eZContentObject
{
    /** @var array */
    public $facts = array();

    public function attribute( $name, $noFunction = false )
    {
        return array_key_exists( $name, $this->facts ) ? $this->facts[$name] : null;
    }

    public function assignedNodes( $asObject = true, $checkVisibility = false, $offset = false, $limit = false, $sortField = false, $sortOrder = 'asc' )
    {
        $rows = array();
        foreach ( $this->facts['paths'] as $path )
            $rows[] = array( 'path_string' => $path );
        return $rows;
    }
}

/** A node of such an object. */
class expAiObjectVetoTestNode extends eZContentObjectTreeNode
{
    /** @var eZContentObject */
    public $testObject;

    public function object()
    {
        return $this->testObject;
    }

    public function attribute( $name, $noFunction = false )
    {
        return $name === 'node_id' ? 77 : null;
    }
}

class expAiObjectVetoTest extends expAiCoreTestCase
{
    private function rules( array $rules )
    {
        $settings = array( 'RequestVeto' => array( 'Rules' => array_keys( $rules ) ) );
        foreach ( $rules as $name => $conditions )
            $settings['RequestVeto_' . $name] = $conditions;
        return $this->config( $settings );
    }

    private static function object( $sectionID, $class, array $paths, $id = 42 )
    {
        $object = new expAiObjectVetoTestObject( array() );
        $object->facts = array( 'id' => $id, 'section_id' => $sectionID, 'class_identifier' => $class, 'paths' => $paths );
        return $object;
    }

    public function testARuleOnContentRefusesTheObjectsItNames(): void
    {
        $config = $this->rules( array( 'users' => array( 'Sections' => array( '2' ), 'Reason' => 'No AI on user accounts.' ),
                                       'hr' => array( 'Subtrees' => array( '61' ), 'Classes' => array( 'article' ) ) ) );
        $this->assertSame( array( 'No AI on user accounts.', 'rule:users' ),
                           expAiGate::objectVeto( self::object( 2, 'user', array( '/1/5/12/' ) ), array( 'siteaccess' => 'site' ), $config ) );
        $this->assertSame( array( '', '' ), expAiGate::objectVeto( self::object( 1, 'user', array( '/1/5/12/' ) ), array( 'siteaccess' => 'site' ), $config ) );
        // a second location inside the subtree is enough
        $this->assertSame( array( 'This request is not allowed on this site.', 'rule:hr' ),
                           expAiGate::objectVeto( self::object( 1, 'article', array( '/1/2/9/', '/1/2/61/70/' ) ), array( 'siteaccess' => 'site' ), $config ) );
        $this->assertSame( array( '', '' ), expAiGate::objectVeto( self::object( 1, 'folder', array( '/1/2/61/70/' ) ), array( 'siteaccess' => 'site' ), $config ) );
        $this->assertSame( array( '', '' ), expAiGate::objectVeto( self::object( 1, 'article', array( '/1/2/610/' ) ), array( 'siteaccess' => 'site' ), $config ) );
    }

    public function testRulesWithoutAContentConditionAreLeftToTheRequest(): void
    {
        $config = $this->rules( array( 'maintenance' => array( 'Reason' => 'Maintenance.' ), 'probes' => array( 'Purposes' => array( 'probe' ) ) ) );
        $this->assertSame( array( '', '' ), expAiGate::objectVeto( self::object( 2, 'user', array( '/1/5/' ) ), array(), $config ) );
    }

    public function testAConditionOnAnUnknownPartOfTheRequestCountsAsMet(): void
    {
        $config = $this->rules( array( 'vector_users' => array( 'Sections' => array( '2' ), 'Purposes' => array( 'vector' ), 'Providers' => array( 'cloud' ),
                                                                'SiteAccesses' => array( 'admin' ) ) ) );
        $object = self::object( 2, 'user', array( '/1/5/' ) );
        $this->assertSame( 'rule:vector_users', expAiGate::objectVeto( $object, array( 'siteaccess' => 'admin' ), $config )[1], 'purpose and provider unknown: refused' );
        $this->assertSame( 'rule:vector_users', expAiGate::objectVeto( $object, array( 'siteaccess' => 'admin', 'purpose' => 'vector', 'provider' => 'cloud' ), $config )[1] );
        $this->assertSame( '', expAiGate::objectVeto( $object, array( 'siteaccess' => 'admin', 'purpose' => 'panel' ), $config )[1] );
        $this->assertSame( '', expAiGate::objectVeto( $object, array( 'siteaccess' => 'admin', 'provider' => 'local' ), $config )[1] );
        $this->assertSame( '', expAiGate::objectVeto( $object, array( 'siteaccess' => 'site' ), $config )[1] );
    }

    public function testMissingFactsOfTheContentFailClosed(): void
    {
        $config = $this->rules( array( 'hr' => array( 'Subtrees' => array( '61' ), 'Reason' => 'HR stays out.' ),
                                       'users' => array( 'Sections' => array( '2' ), 'Classes' => array( 'user' ), 'Reason' => 'Users stay out.' ) ) );
        $request = array( 'siteaccess' => 'site' );
        // a new draft has no location yet: it may belong below node 61
        $this->assertSame( array( 'HR stays out.', 'rule:hr' ), expAiGate::objectVeto( self::object( 1, 'article', array() ), $request, $config ) );
        $this->assertSame( array( '', '' ), expAiGate::objectVeto( self::object( 1, 'article', array( '/1/2/9/' ) ), $request, $config ),
                           'an object in another subtree is allowed' );
        // section 0: the known class decides
        $this->assertSame( 'rule:users', expAiGate::objectVeto( self::object( 0, 'user', array( '/1/5/' ) ), $request, $config )[1] );
        $this->assertSame( '', expAiGate::objectVeto( self::object( 0, 'folder', array( '/1/5/' ) ), $request, $config )[1],
                           'a known condition that excludes the object still excludes it' );
        // no class: the known section decides
        $this->assertSame( 'rule:users', expAiGate::objectVeto( self::object( 2, '', array( '/1/5/' ) ), $request, $config )[1] );
        $this->assertSame( '', expAiGate::objectVeto( self::object( 1, '', array( '/1/5/' ) ), $request, $config )[1] );
        $sectionOnly = $this->rules( array( 'users' => array( 'Sections' => array( '2' ) ) ) );
        $this->assertSame( 'rule:users', expAiGate::objectVeto( self::object( 0, 'folder', array( '/1/5/' ) ), $request, $sectionOnly )[1] );
    }

    public function testTheRequestLevelCheckKeepsMissingFactsAsNoMatch(): void
    {
        $config = $this->rules( array( 'users' => array( 'Sections' => array( '2' ) ), 'hr' => array( 'Subtrees' => array( '61' ) ) ) );
        $this->assertSame( array( '', '' ), expAiGate::veto( array( 'section_id' => 0, 'class_identifier' => '', 'path' => '', 'siteaccess' => 'site' ), $config ),
                           'a request without content (the model test, a probe) matches no content rule' );
    }

    public function testAnInvalidRuleIsSkipped(): void
    {
        $config = $this->config( array( 'RequestVeto' => array( 'Rules' => array( 'missing_group', 'bad name!' ) ) ) );
        $this->assertFalse( expAiGate::validRule( $config, 'missing_group' ) );
        $this->assertFalse( expAiGate::validRule( $config, 'bad name!' ) );
        $this->assertSame( array( '', '' ), expAiGate::objectVeto( self::object( 2, 'user', array() ), array(), $config ) );
    }

    public function testTheReaderWithholdsAVetoedObjectWithTheReason(): void
    {
        $config = $this->rules( array( 'users' => array( 'Sections' => array( '2' ), 'Reason' => 'No AI on user accounts.' ) ) );
        $reader = new expAiContentReader( $config );
        $reader->setRequest( array( 'siteaccess' => 'site', 'purpose' => 'panel' ) );
        $vetoed = self::object( 2, 'user', array( '/1/5/12/' ), 42 );
        $free = self::object( 1, 'article', array( '/1/2/9/' ), 43 );
        $this->assertFalse( $reader->allowed( $vetoed ) );
        $this->assertTrue( $reader->allowed( $free ) );
        $this->assertSame( 'No AI on user accounts.', $reader->vetoReason( $vetoed ) );
        try
        {
            $reader->assertAllowed( $vetoed );
            $this->fail( 'a vetoed object was let through' );
        }
        catch ( expAiException $e )
        {
            $this->assertNotInstanceOf( 'expAiVetoException', $e, 'one object refused is no refusal of the whole request' );
            $this->assertSame( 'This request was refused: %reason', $e->getMessage() );
            $this->assertSame( array( '%reason' => 'No AI on user accounts.' ), $e->arguments() );
            $this->assertSame( 'veto (object 42, rule:users)', $e->detail() );
        }
        $reader->assertAllowed( $free );
        $node = new expAiObjectVetoTestNode( array() );
        $node->testObject = $vetoed;
        $this->assertSame( array( 'node_id' => 77, 'withheld' => 'refused: No AI on user accounts.' ), $reader->shortNode( $node ) );
    }
}
