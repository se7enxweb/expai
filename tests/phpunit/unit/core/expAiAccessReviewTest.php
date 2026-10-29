<?php
/**
 * Access and retention rules found in review: the follow-ups of a thread are the owner's, the reader knows the
 * purpose of the request for the veto, object relations name only objects the policy and the veto let through, a
 * mistyped siteaccess stops a script, the retention keeps what the editor kept or still works on, and service tools,
 * bridges and search_content apply the veto also without a data policy. No database: everything is made in memory.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

/** An object with its facts in memory (section, class, locations, name, read access). */
class expAiAccessReviewObject extends eZContentObject
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

    public function canRead()
    {
        return !empty( $this->facts['readable'] );
    }

    public function dataMap()
    {
        return array();
    }
}

/** A reader whose related objects come from the test. */
class expAiAccessReviewReader extends expAiContentReader
{
    /** @var eZContentObject[] */
    public $objects = array();

    protected function fetchRelated( $objectID )
    {
        return isset( $this->objects[$objectID] ) ? $this->objects[$objectID] : null;
    }
}

/** A hit of the search engine. */
class expAiAccessReviewHit
{
    public $values;
    public $object;

    public function __construct( array $values, $object )
    {
        $this->values = $values;
        $this->object = $object;
    }

    public function attribute( $name )
    {
        return $this->values[$name];
    }

    public function hasAttribute( $name )
    {
        return isset( $this->values[$name] );
    }

    public function canRead()
    {
        return true;
    }

    public function object()
    {
        return $this->object;
    }
}

/** search_content with the engine in memory, but the reader's own allowed() (policy and veto). */
class expAiAccessReviewSearch extends expAiToolSearchContent
{
    public $result = array();

    protected function search( $query, array $params )
    {
        return $this->result;
    }

    protected function readableSubtree( $nodeID )
    {
        return (object) array( 'node_id' => $nodeID );
    }
}

class expAiAccessReviewTest extends expAiCoreTestCase
{
    private static function object( $id, $sectionID, $name, $readable = true )
    {
        $object = new expAiAccessReviewObject( array() );
        $object->facts = array( 'id' => $id, 'section_id' => $sectionID, 'class_identifier' => 'article', 'paths' => array( '/1/2/' . $id . '/' ),
                                'name' => $name, 'readable' => $readable );
        return $object;
    }

    private function vetoSectionTwo( array $extra = array() )
    {
        return $this->config( array( 'RequestVeto' => array( 'Rules' => array( 'hr' ) ),
                                     'RequestVeto_hr' => array( 'Sections' => array( '2' ), 'Reason' => 'HR stays out.' ) + $extra ) );
    }

    public function testTheFollowupsOfAThreadAreTheOwnersText(): void
    {
        $waiting = array( new expAiFollowup( array( 'id' => 1, 'thread_id' => 1, 'body' => 'Also raise the salary of Jane', 'delivery' => 'queue', 'position' => 1 ) ),
                          new expAiFollowup( array( 'id' => 2, 'thread_id' => 1, 'body' => 'And of John', 'delivery' => 'steer', 'position' => 2 ) ) );
        $owner = expAiVector::visibleFollowups( $waiting, true );
        $this->assertSame( 2, $owner['count'] );
        $this->assertSame( 'Also raise the salary of Jane', $owner['list'][0]['body'] );
        $approver = expAiVector::visibleFollowups( $waiting, false );
        $this->assertSame( array( 'list' => array(), 'count' => 2 ), $approver );
        $this->assertStringContainsString( "expAiVector::visibleFollowups( expAiFollowup::waiting( (int) \$thread->attribute( 'id' ) ), \$thread->isOwner( \$userID ) )",
                                           (string) file_get_contents( EXPAI_TEST_CHECKOUT . '/classes/vector/expaivectorserverfunctions.php' ), 'thread() answers with the filtered follow-ups' );
    }

    public function testTheReaderKnowsThePurposeOfTheRequest(): void
    {
        $config = $this->vetoSectionTwo( array( 'Purposes' => array( 'vector' ) ) );
        $object = self::object( 10, 2, 'Salaries' );
        $panel = new expAiContentReader( $config );
        $panel->setRequest( array( 'purpose' => 'panel', 'siteaccess' => 'site' ) );
        $this->assertTrue( $panel->allowed( $object ), 'a rule for Vector does not refuse the panel' );
        $vector = new expAiContentReader( $config );
        $vector->setRequest( array( 'purpose' => 'vector', 'siteaccess' => 'site' ) );
        $this->assertFalse( $vector->allowed( $object ) );
        $unknown = new expAiContentReader( $config );
        $this->assertFalse( $unknown->allowed( $object ), 'an unknown purpose fails closed' );
        $source = (string) file_get_contents( EXPAI_TEST_CHECKOUT . '/classes/expaiassistant.php' );
        $this->assertStringContainsString( '$reader->setRequest( $vetoRequest );', $source );
        $this->assertStringContainsString( '$context->reader->setRequest( $vetoRequest );', $source );
    }

    public function testARelationNamesOnlyWhatThePolicyAndTheVetoLetThrough(): void
    {
        $reader = new expAiAccessReviewReader( $this->vetoSectionTwo() );
        $reader->setRequest( array( 'purpose' => 'panel', 'siteaccess' => 'site' ) );
        $reader->objects = array( 10 => self::object( 10, 2, 'Salaries 2026' ), 11 => self::object( 11, 1, 'Opening hours' ),
                                  12 => self::object( 12, 1, 'Private', false ) );
        $this->assertSame( '(object 10 withheld); Opening hours (object 11)', $reader->relationValue( '10-11-12-99', 1000 ) );
    }

    public function testAMistypedSiteAccessStopsTheScript(): void
    {
        $this->assertSame( '', expAiScript::unknownSiteAccess( 'site_admin', array( 'site', 'site_admin' ), true ) );
        $this->assertStringStartsWith( "there is no siteaccess 'site_admn'", expAiScript::unknownSiteAccess( 'site_admn', array( 'site', 'site_admin' ), true ) );
        $this->assertStringContainsString( 'no settings directory', expAiScript::unknownSiteAccess( 'site', array( 'site' ), false ) );
    }

    public function testTheRetentionKeepsWhatTheEditorKeptOrStillWorksOn(): void
    {
        $now = 1800000000;
        $cutoff = $now - 365 * 86400;
        $stale = $now - 3600;
        $old = $cutoff - 10;
        $row = function ( $state, $shelf, $modified ) { return array( 'state' => $state, 'shelf' => $shelf, 'modified' => $modified ); };
        $this->assertTrue( expAiThreadRetention::threadMayGo( $row( 'idle', 'settled', $old ), false, $cutoff, $stale ) );
        $this->assertFalse( expAiThreadRetention::threadMayGo( $row( 'idle', 'settled', $cutoff + 10 ), false, $cutoff, $stale ), 'not old enough' );
        $this->assertFalse( expAiThreadRetention::threadMayGo( $row( 'idle', 'pinned', $old ), false, $cutoff, $stale ), 'pinned' );
        $this->assertFalse( expAiThreadRetention::threadMayGo( $row( 'review', 'active', $old ), false, $cutoff, $stale ), 'in review' );
        $this->assertFalse( expAiThreadRetention::threadMayGo( $row( 'waiting_input', 'active', $old ), false, $cutoff, $stale ), 'waits for an answer' );
        $this->assertTrue( expAiThreadRetention::threadMayGo( $row( 'running', 'active', $old ), false, $cutoff, $stale ), 'a run that went away long ago' );
        $this->assertFalse( expAiThreadRetention::threadMayGo( $row( 'idle', 'settled', $old ), false, 0, $stale ), 'ThreadKeepDays=0 keeps' );
        // a removed user: every thread goes, except one that still runs
        $this->assertTrue( expAiThreadRetention::threadMayGo( $row( 'review', 'pinned', $now ), true, $cutoff, $stale ) );
        $this->assertFalse( expAiThreadRetention::threadMayGo( $row( 'running', 'active', $now ), true, $cutoff, $stale ) );
        $this->assertFalse( expAiThreadRetention::threadMayGo( $row( 'queued', 'active', $now - 60 ), true, $cutoff, $stale ) );
        $this->assertTrue( expAiThreadRetention::threadMayGo( $row( 'queued', 'active', $stale - 1 ), true, $cutoff, $stale ) );
    }

    public function testServicesAndBridgesFilterAlsoWithoutADataPolicy(): void
    {
        $this->assertFalse( ( new expAiContentReader( $this->config() ) )->filters(), 'no policy, no veto: nothing to filter' );
        $this->assertTrue( ( new expAiContentReader( $this->vetoSectionTwo() ) )->filters(), 'a veto on content filters without a policy' );
        $this->assertFalse( expAiGate::hasContentRules( $this->config( array( 'RequestVeto' => array( 'Rules' => array( 'm' ) ),
                                                                                'RequestVeto_m' => array( 'Purposes' => array( 'probe' ) ) ) ) ) );
        // a service answer: the row of a vetoed object is left out and counted
        $reader = new expAiContentReader( $this->vetoSectionTwo() );
        $reader->setRequest( array( 'purpose' => 'panel', 'siteaccess' => 'site' ) );
        $withheld = 0;
        $kept = expAiServiceTool::filter( array( array( 'name' => 'x' ) ), $reader, $withheld );
        $this->assertSame( array( array( 'name' => 'x' ) ), $kept );
        foreach ( array( 'classes/tools/expaiservicetool.php', 'classes/mcp/expaimcpbridgetool.php' ) as $file )
            $this->assertStringNotContainsString( 'reader->policy()', (string) file_get_contents( EXPAI_TEST_CHECKOUT . '/' . $file ), $file );
    }

    public function testSearchContentWithholdsVetoedHits(): void
    {
        $config = $this->vetoSectionTwo();
        $context = ( new ReflectionClass( 'expAiToolContext' ) )->newInstanceWithoutConstructor();
        $context->config = $config;
        $context->reader = new expAiContentReader( $config );
        $context->reader->setRequest( array( 'purpose' => 'panel', 'siteaccess' => 'site' ) );
        $context->nodeID = 2;
        $tool = new expAiAccessReviewSearch( 'search_content' );
        $hit = function ( $object ) { return new expAiAccessReviewHit( array( 'node_id' => $object->facts['id'] + 100, 'contentobject_id' => $object->facts['id'],
            'name' => $object->facts['name'], 'class_identifier' => 'article', 'path_identification_string' => 'x', 'highlight' => 'text of ' . $object->facts['name'] ), $object ); };
        $tool->result = array( 'SearchCount' => 2, 'SearchResult' => array( $hit( self::object( 10, 2, 'Salaries 2026' ) ), $hit( self::object( 11, 1, 'Opening hours' ) ) ) );
        $result = $tool->execute( array( 'query' => 'salary', 'subtree' => 2 ), $context );
        $this->assertSame( array( 'Opening hours' ), array_column( $result['hits'], 'name' ) );
        $this->assertSame( 1, $context->reader->withheld );
        $this->assertStringNotContainsString( 'Salaries', json_encode( $result ) );
    }
}
