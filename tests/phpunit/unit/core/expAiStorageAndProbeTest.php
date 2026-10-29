<?php
/**
 * Storage details and the probe, without a database: the money columns are 64 bits wide and an earlier installation
 * gets them widened per engine; a long queue payload is bound where the database binds values (Oracle) instead of
 * written as a literal; the probe asks with the panel's instructions (and the site's extra instructions), and its
 * tasks read_node and two_nodes ask for the node ids the task names.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

/** A database for the statements under test: its engine, its binding, the values it bound */
class expAiStorageAndProbeTestDb extends eZNullDB
{
    public $engine;
    public $binding;
    public $bound = array();

    public function __construct( $engine, $binding )
    {
        // no connection, no settings: only what the statements under test ask
        $this->engine = $engine;
        $this->binding = $binding;
    }

    function databaseName()
    {
        return $this->engine;
    }

    function bindingType()
    {
        return $this->binding;
    }

    public function countStringSize( $string )
    {
        return strlen( $string );
    }

    function bindVariable( $value, $fieldDef = false )
    {
        $this->bound[] = array( $fieldDef['name'], $value );
        return ':' . $fieldDef['name'];
    }

    function escapeString( $str )
    {
        return str_replace( "'", "''", $str );
    }
}

class expAiStorageAndProbeTest extends expAiCoreTestCase
{
    public function testTheMoneyColumnsAre64BitsAndAnEarlierInstallationGetsThemWidened(): void
    {
        $columns = expAiSchema::bigintColumns();
        $this->assertSame( array( 'reported_cost_micros', 'reserved_micros' ), $columns['expai_usage_log'] );
        $this->assertSame( array( 'cost_micros', 'reserved_micros' ), $columns['expai_thread'] );
        $this->assertSame( array( 'cost_micros' ), $columns['expai_thread_run'] );
        $this->assertSame( array( 'cost_micros' ), $columns['expai_chain_run'] );
        $this->assertSame( array( 'cost_micros' ), $columns['expai_chain_step'] );
        $definition = expAiSchema::definition();
        $this->assertArrayHasKey( 'busy_until', $definition['expai_conversation']['fields'] );
        $this->assertArrayHasKey( 'busy_owner', $definition['expai_conversation']['fields'] );
        $this->assertArrayNotHasKey( 'busy_until', expAiConversation::definition()['fields'], 'storing a conversation never touches its lock' );

        $types = array( 'expai_usage_log' => array( 'reported_cost_micros' => 'int', 'reserved_micros' => 'bigint' ), 'expai_thread' => array( 'cost_micros' => 'integer' ) );
        $this->assertSame( array( "ALTER TABLE expai_usage_log MODIFY reported_cost_micros bigint(20) NOT NULL DEFAULT '0'" ),
                           expAiSchema::widenStatements( new expAiStorageAndProbeTestDb( 'mysql', 0 ), array( 'expai_usage_log' => $types['expai_usage_log'] ) ) );
        $this->assertSame( array( 'ALTER TABLE expai_usage_log ALTER COLUMN reported_cost_micros TYPE bigint', 'ALTER TABLE expai_thread ALTER COLUMN cost_micros TYPE bigint' ),
                           expAiSchema::widenStatements( new expAiStorageAndProbeTestDb( 'postgresql', 0 ), $types ) );
        $this->assertSame( array(), expAiSchema::widenStatements( new expAiStorageAndProbeTestDb( 'oracle', 1 ), $types ), 'INTEGER is NUMBER(38) there' );
        $this->assertSame( array(), expAiSchema::widenStatements( new expAiStorageAndProbeTestDb( 'sqlite', 0 ), $types ), 'every integer has 64 bits there' );
    }

    public function testALongPayloadIsBoundWhereTheDatabaseBindsValues(): void
    {
        $long = str_repeat( 'x', 5000 ) . "'";
        $oracle = new expAiStorageAndProbeTestDb( 'oracle', eZDBInterface::BINDING_NAME );
        $this->assertSame( ':payload', expAiRequestHandler::textValue( $oracle, $long, 'payload' ) );
        $this->assertSame( array( array( 'payload', $long ) ), $oracle->bound );
        $this->assertSame( "'short''s'", expAiRequestHandler::textValue( $oracle, "short's", 'payload' ), 'a short value stays a literal' );
        $mysql = new expAiStorageAndProbeTestDb( 'mysql', eZDBInterface::BINDING_NO );
        $this->assertSame( "'" . str_repeat( 'x', 5000 ) . "'''", expAiRequestHandler::textValue( $mysql, $long, 'payload' ) );
        $this->assertSame( array(), $mysql->bound );
    }

    public function testTheProbeAsksWithTheSitesInstructionsAndForTheNamedNodes(): void
    {
        $config = $this->useSettings( array( 'AssistantSettings' => array( 'ExtraInstructions' => array( 'Write in British English.' ) ) ) );
        $probe = new expAiProbe( $config );
        $system = $probe->systemPrompt( null, 999999999, 1 );
        $this->assertStringContainsString( '- Write in British English.', $system );
        $this->assertStringContainsString( 'Content you read through tools', $system );

        $tasks = array();
        foreach ( $probe->tasks( 60, 61, 'folder' ) as $task )
            $tasks[$task['id']] = $task['check'];
        $call = function ( $name, array $input ) { return array( 'id' => 'c', 'name' => $name, 'input' => $input ); };
        $this->assertSame( '', $tasks['read_node']( array( $call( 'get_node', array( 'node_id' => 60 ) ) ) ) );
        $this->assertSame( 'no node_id', $tasks['read_node']( array( $call( 'get_node', array() ) ) ) );
        $this->assertSame( 'wrong node_id', $tasks['read_node']( array( $call( 'get_node', array( 'node_id' => 2 ) ) ) ) );
        $this->assertSame( '', $tasks['two_nodes']( array( $call( 'get_node', array( 'node_id' => 60 ) ), $call( 'get_node', array( 'node_id' => 1 ) ) ) ) );
        $this->assertSame( 'not both nodes read (60)', $tasks['two_nodes']( array( $call( 'get_node', array( 'node_id' => 60 ) ) ) ) );
        $this->assertSame( 'no get_node call with a node_id', $tasks['two_nodes']( array( $call( 'get_node', array() ) ) ) );
    }
}
