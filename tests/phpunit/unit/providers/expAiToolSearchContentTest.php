<?php
/**
 * The read tool search_content with a search engine in memory (no Solr, no database): the parameters it gives the
 * engine, the hits it returns, what the user may not read, what the data policy keeps away (withheld and counted),
 * and its refusals.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

/** A hit of the engine: a node with the attributes the tool reads */
class expAiToolSearchContentTestNode
{
    public $values;
    public $readable;
    public $object;

    public function __construct( array $values, $readable = true )
    {
        $this->values = $values;
        $this->readable = $readable;
        $this->object = (object) array( 'id' => $values['contentobject_id'] );
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
        return $this->readable;
    }

    public function object()
    {
        return $this->object;
    }
}

/** The tool with the engine, the subtree, the classes and the data policy in memory */
class expAiToolSearchContentTestTool extends expAiToolSearchContent
{
    public $calls = array();
    public $result = array();
    public $kept = array();

    protected function search( $query, array $params )
    {
        $this->calls[] = array( $query, $params );
        return $this->result;
    }

    protected function readableSubtree( $nodeID )
    {
        return $nodeID === 404 ? null : (object) array( 'node_id' => $nodeID );
    }

    protected function classID( $identifier )
    {
        return $identifier === 'article' ? 16 : 0;
    }

    protected function allowed( expAiToolContext $context, $object )
    {
        if ( in_array( $object->id, $this->kept, true ) )
            return false;
        $context->reader->noteRead( $object->id );
        return true;
    }
}

class expAiToolSearchContentTest extends expAiProviderTestCase
{
    private static function hit( $nodeID, $objectID, $name, $highlight = '', $readable = true )
    {
        return new expAiToolSearchContentTestNode( array( 'node_id' => $nodeID, 'contentobject_id' => $objectID, 'name' => $name, 'class_identifier' => 'article',
                                                          'path_identification_string' => 'news/' . strtolower( $name ), 'highlight' => $highlight ), $readable );
    }

    private function context()
    {
        $config = $this->config();
        $context = ( new ReflectionClass( 'expAiToolContext' ) )->newInstanceWithoutConstructor();
        $context->config = $config;
        $context->reader = new expAiContentReader( $config );
        $context->nodeID = 60;
        return $context;
    }

    public function testItSearchesTheOpenSubtreeAndReturnsTheHitsTheUserMayHave(): void
    {
        $tool = new expAiToolSearchContentTestTool( 'search_content' );
        $tool->result = array( 'SearchCount' => 4, 'SearchResult' => array(
            self::hit( 61, 161, 'Prices', 'The <b>prices</b> of 2026' ),
            self::hit( 62, 162, 'Secret', 'hidden', false ),
            self::hit( 63, 163, 'Kept away', 'local only' ),
            self::hit( 64, 164, 'Opening hours', 'We are open' ),
        ) );
        $tool->kept = array( 163 );
        $context = $this->context();
        $result = $tool->execute( array( 'query' => '  prices  ', 'limit' => 99 ), $context );
        $this->assertSame( array( 'prices', array( 'SearchSubTreeArray' => array( 60 ), 'SearchLimit' => 25, 'SearchOffset' => 0 ) ), $tool->calls[0] );
        $this->assertSame( array( 61, 64 ), array_column( $result['hits'], 'node_id' ) );
        $this->assertSame( array( 'node_id' => 61, 'object_id' => 161, 'name' => 'Prices', 'class' => 'article', 'path' => 'news/prices', 'snippet' => 'The prices of 2026' ),
                           $result['hits'][0] );
        $this->assertSame( array( 60 ), $result['subtrees'] );
        $this->assertFalse( $result['more_may_exist'], 'the count of the engine includes the left-out hits: never shown' );
        $tool->result['SearchCount'] = 9;
        $this->assertTrue( $tool->execute( array( 'query' => 'prices' ), $this->context() )['more_may_exist'] );
        $this->assertSame( 1, $context->reader->withheld, 'what the data policy keeps away is counted, like the other read tools' );
        $this->assertSame( array( 161, 164 ), $context->reader->takeRead() );
        $this->assertSame( 'Searched the content for "%query"', $tool->stepText( array() ) );
        $this->assertSame( array( '%query' => 'prices' ), $tool->stepArguments( array( 'query' => 'prices' ), $result ) );
    }

    public function testMoreMayExistCountsOnlyWhatThisCallWithheld(): void
    {
        $tool = new expAiToolSearchContentTestTool( 'search_content' );
        $tool->result = array( 'SearchCount' => 3, 'SearchResult' => array( self::hit( 61, 161, 'Prices' ), self::hit( 63, 163, 'Kept away' ) ) );
        $tool->kept = array( 163 );
        $context = $this->context();
        // earlier calls of the same step withheld other objects: they are not results of this search
        $context->reader->withheld = 5;
        $result = $tool->execute( array( 'query' => 'prices' ), $context );
        $this->assertTrue( $result['more_may_exist'], 'a third hit exists beyond the two this page saw' );
        $this->assertSame( 6, $context->reader->withheld, 'the step\'s counter still adds this call\'s withheld hit' );
    }

    public function testAClassASubtreeAndPaging(): void
    {
        $tool = new expAiToolSearchContentTestTool( 'search_content' );
        $tool->execute( array( 'query' => 'x', 'class' => 'article', 'subtree' => 2, 'offset' => 10, 'limit' => 5 ), $this->context() );
        $this->assertSame( array( 'SearchSubTreeArray' => array( 2 ), 'SearchLimit' => 5, 'SearchOffset' => 10, 'SearchContentClassID' => array( 16 ) ), $tool->calls[0][1] );
    }

    public function testWithoutAnOpenNodeItSearchesFromTheContentRoot(): void
    {
        $tool = new expAiToolSearchContentTestTool( 'search_content' );
        $context = $this->context();
        $context->nodeID = 0;
        $result = $tool->execute( array( 'query' => 'x' ), $context );
        $root = (int) eZINI::instance( 'content.ini' )->variable( 'NodeSettings', 'RootNode' ) ?: 2;
        $this->assertSame( array( $root ), $tool->calls[0][1]['SearchSubTreeArray'] );
        $this->assertSame( array( $root ), $result['subtrees'] );
    }

    public function testItsRefusals(): void
    {
        $tool = new expAiToolSearchContentTestTool( 'search_content' );
        foreach ( array( array( array( 'query' => ' ' ), 'Give the words to search for.' ),
                         array( array( 'query' => 'x', 'subtree' => 404 ), 'Node %id does not exist or you may not read it.' ),
                         array( array( 'query' => 'x', 'class' => 'nope' ), 'There is no content class "%identifier".' ) ) as $case )
        {
            try
            {
                $tool->execute( $case[0], $this->context() );
                $this->fail( 'no refusal for ' . json_encode( $case[0] ) );
            }
            catch ( expAiException $e )
            {
                $this->assertSame( $case[1], $e->getMessage() );
            }
        }
        $this->assertSame( array(), $tool->calls );
        $schema = $tool->inputSchema();
        $this->assertSame( array( 'query' ), $schema['required'] );
        $this->assertSame( '', expAiJsonSchema::validate( $schema, array( 'query' => 'a', 'limit' => 3 ) ) );
        $this->assertNotSame( '', expAiJsonSchema::validate( $schema, array( 'limit' => 3 ) ) );
    }

    public function testItIsRegisteredInTheShippedSettings(): void
    {
        $config = $this->config();
        $this->assertContains( 'search_content', $config->arr( 'ToolSettings', 'Tools' ) );
        $this->assertSame( 'expAiToolSearchContent', $config->get( 'Tool_search_content', 'Class', '' ) );
    }
}
