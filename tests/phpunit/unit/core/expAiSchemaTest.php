<?php
/**
 * expAiSchema: the generic definition, the generated files and SQL of every engine, and ensure() (create, upgrade,
 * drop the removed tables) against a throwaway SQLite database in memory, never alpha's own.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiSchemaTest extends expAiCoreTestCase
{
    /** @return eZDBInterface a new SQLite database in memory; the installation's connection stays the instance */
    private function memoryDatabase()
    {
        if ( !class_exists( 'SQLite3' ) )
            $this->markTestSkipped( 'needs the SQLite3 extension' );
        $live = eZDB::hasInstance() ? eZDB::instance() : null;
        $db = eZDB::instance( 'sqlite3', array( 'database' => ':memory:', 'use_defaults' => false, 'show_errors' => false ), true );
        if ( $live )
            eZDB::setInstance( $live );
        if ( !$db->isConnected() )
            $this->markTestSkipped( 'no SQLite database in memory' );
        return $db;
    }

    public function testTheDefinitionAndItsFiles(): void
    {
        $tables = expAiSchema::tables();
        foreach ( array( 'expai_conversation', 'expai_usage_log', 'expai_draft_log', 'expai_probe_result' ) as $table )
            $this->assertContains( $table, $tables );
        $this->assertGreaterThan( count( expAiSchema::definition( false ) ) - 1, count( expAiSchema::definition() ) - 1,
                                  'the queue and chain tables come on top' );
        $this->assertNotContains( 'expai_package', $tables, 'a removed table is not defined' );
        foreach ( expAiSchema::definition() as $name => $table )
        {
            $this->assertSame( $name, $table['name'] );
            $this->assertLessThanOrEqual( 30, strlen( $name ), 'Oracle\'s limit for names' );
            $this->assertSame( array( 'id' ), $table['indexes']['PRIMARY']['fields'] );
            foreach ( array_keys( $table['indexes'] ) as $index )
                $this->assertLessThanOrEqual( 30, strlen( $index ) );
        }

        $dba = expAiSchema::dbaText();
        $this->assertStringStartsWith( "<?php\n", $dba );
        $this->assertStringContainsString( 'Do not edit by hand', $dba );
        $schema = null;
        eval( '?>' . $dba );
        $this->assertSame( array( 'format' => 'generic' ), $schema['_info'] );
        $listed = array_values( array_diff( array_keys( $schema ), array( '_info' ) ) );
        $sorted = $tables;
        sort( $sorted );
        $this->assertSame( $sorted, $listed, 'every table, sorted by name' );
        $this->assertSame( expAiSchema::definition()['expai_usage_log'], $schema['expai_usage_log'] );
    }

    public function testSqlOfEachEngine(): void
    {
        $this->assertNull( expAiSchema::handler( 'db2', array() ) );
        $this->assertSame( array(), expAiSchema::sqlStatements( 'db2' ) );
        foreach ( array( 'mysql' => 'ENGINE=InnoDB', 'postgresql' => 'CREATE SEQUENCE', 'sqlite' => 'AUTOINCREMENT' ) as $engine => $marker )
        {
            $sql = expAiSchema::sqlStatements( $engine );
            $this->assertNotEmpty( $sql, $engine );
            $all = implode( "\n", $sql );
            $this->assertStringContainsString( 'expai_usage_log', $all, $engine );
            $this->assertStringContainsString( $marker, $all, $engine );
            foreach ( $sql as $statement )
                $this->assertStringEndsNotWith( ';', $statement, "$engine: statements are separate, without the semicolon" );
        }
        $one = expAiSchema::sqlStatements( 'sqlite', array( 'expai_probe_result' ) );
        $this->assertNotEmpty( $one );
        foreach ( $one as $statement )
            $this->assertStringContainsString( 'expai_probe_result', $statement );
        $oracle = expAiSchema::sqlStatements( 'oracle', array( 'expai_conversation' ) );
        if ( $oracle )
            $this->assertNotEmpty( array_filter( $oracle, function ( $s ) { return stripos( $s, 'expai_conversation' ) !== false; } ) );
    }

    public function testEngineNames(): void
    {
        $names = array( 'mysql' => 'mysql', 'postgresql' => 'postgresql', 'pgsql' => 'postgresql', 'sqlite3' => 'sqlite', 'mongodb' => 'mongodb', 'oracle' => 'oracle' );
        foreach ( $names as $name => $engine )
            $this->assertSame( $engine, expAiSchema::engineOf( new expAiCoreTestNamedDb( $name ) ) );
        $this->assertSame( 'db2', expAiSchema::engineOf( new expAiCoreTestNamedDb( 'DB2' ) ) );
        $this->assertNull( expAiSchema::existingColumns( 'expai_usage_log', new expAiCoreTestNamedDb( 'db2' ) ), 'an engine that cannot tell' );
        $this->assertNull( expAiSchema::existingIndexes( 'expai_usage_log', new expAiCoreTestNamedDb( 'db2' ) ) );
    }

    public function testEnsureCreatesUpgradesAndDrops(): void
    {
        $db = $this->memoryDatabase();
        $this->assertSame( 'sqlite', expAiSchema::engineOf( $db ) );
        $this->assertSame( expAiSchema::tables(), expAiSchema::missingTables( $db ) );
        $this->assertSame( array(), expAiSchema::existingTables( $db ) );

        // an installation of an earlier state: one table with a column and an index missing, and a removed table
        $old = expAiSchema::definition();
        $probe = $old['expai_probe_result'];
        unset( $probe['fields']['probe_mode'], $probe['indexes']['expai_probe_result_alias'] );
        $handler = expAiSchema::handler( 'sqlite', array( 'expai_probe_result' => $probe ) );
        $local = array( 'expai_probe_result' => $probe, '_info' => array( 'format' => 'generic' ) );
        $handler->transformSchema( $local, true );
        foreach ( $handler->generateTableSQLList( 'expai_probe_result', $local['expai_probe_result'], array( 'format' => 'local' ), false ) as $statement )
            $db->query( rtrim( trim( $statement ), ';' ) );
        $db->query( 'CREATE TABLE expai_package ( id INTEGER PRIMARY KEY )' );
        $this->assertContains( 'expai_package', expAiSchema::databaseTables( $db ) );
        $this->assertSame( array( 'expai_probe_result' ), expAiSchema::existingTables( $db ) );
        $missing = expAiSchema::missingColumns( $db );
        $this->assertContains( 'expai_probe_result.probe_mode', $missing );
        $this->assertContains( 'expai_probe_result index expai_probe_result_alias', $missing );
        $upgrade = expAiSchema::upgradeStatements( $db );
        $this->assertNotEmpty( array_filter( $upgrade, function ( $s ) { return stripos( $s, 'probe_mode' ) !== false; } ) );
        $this->assertNotContains( 'probe_mode', expAiSchema::existingColumns( 'expai_probe_result', $db ) );

        list( $created, $errors ) = expAiSchema::ensure( $db );
        $this->assertSame( array(), $errors );
        $this->assertContains( 'dropped: expai_package', $created );
        $this->assertNotEmpty( array_filter( $created, function ( $c ) { return strpos( $c, 'upgrade: ' ) === 0; } ) );
        $this->assertContains( 'expai_usage_log', $created );
        $this->assertSame( array(), expAiSchema::missingTables( $db ) );
        $this->assertSame( array(), expAiSchema::missingColumns( $db ) );
        $this->assertNotContains( 'expai_package', expAiSchema::databaseTables( $db ) );
        $this->assertContains( 'probe_mode', expAiSchema::existingColumns( 'expai_probe_result', $db ) );
        $this->assertContains( 'expai_usage_log_created', expAiSchema::existingIndexes( 'expai_usage_log', $db ) );

        // idempotent
        list( $again, $againErrors ) = expAiSchema::ensure( $db );
        $this->assertSame( array( array(), array() ), array( $again, $againErrors ) );
        $this->assertSame( array( array(), array() ), expAiSchema::dropRemoved( $db ) );
        // closed by its destructor, here inside the test (a second close() would warn)
        unset( $db );
        gc_collect_cycles();
    }

    public function testTheInstallationsTablesAreComplete(): void
    {
        $this->requireDatabase();
        $this->assertSame( array(), expAiSchema::missingTables(), 'every table is installed on this site' );
        $this->assertNotContains( 'expai_package', expAiSchema::databaseTables() );
        $this->assertContains( 'id', expAiSchema::existingColumns( 'expai_usage_log' ) );
    }

    public function testTheCatalogOfEachEngine(): void
    {
        $rows = array( array( 'NAME' => 'EXPAI_USAGE_LOG' ), array( 'name' => 'expai_package' ) );
        foreach ( array( 'mysql' => 'information_schema.tables', 'postgresql' => 'pg_class', 'oracle' => 'user_tables' ) as $engine => $catalog )
        {
            $db = new expAiCoreTestNamedDb( $engine, array( '/expai%/i' => $rows ) );
            $this->assertSame( array( 'expai_usage_log', 'expai_package' ), expAiSchema::databaseTables( $db ), $engine );
            $this->assertStringContainsString( $catalog, $db->statements[0] );
            $this->assertSame( array( 'expai_usage_log' ), expAiSchema::existingTables( $db ) );
        }
        $this->assertSame( array( 'expai_usage_log', 'ezuser' ), expAiSchema::databaseTables( new expAiCoreTestNamedDb( 'db2' ) ), 'another engine: the driver\'s table list' );
        $this->assertSame( array( 'expai_usage_log' ), expAiSchema::existingTables( new expAiCoreTestNamedDb( 'db2' ) ) );
        $this->assertSame( array( 'expai_usage_log', 'expai_package', 'other' ), expAiSchema::databaseTables( new expAiCoreTestMongoDb( 'mongodb' ) ) );

        foreach ( array( 'mysql' => 'information_schema.columns', 'postgresql' => 'information_schema.columns', 'oracle' => 'user_tab_columns' ) as $engine => $catalog )
        {
            $db = new expAiCoreTestNamedDb( $engine, array( '/column/i' => array( array( 'NAME' => 'ID' ), array( 'name' => 'created' ), array( 'other' => 'x' ) ) ) );
            $this->assertSame( array( 'id', 'created' ), expAiSchema::existingColumns( 'expai_usage_log; DROP', $db ), $engine );
            $this->assertStringContainsString( $catalog, $db->statements[0] );
            $this->assertStringNotContainsString( 'DROP', $db->statements[0], 'the table name is cleaned' );
        }
        foreach ( array( 'mysql' => 'information_schema.statistics', 'postgresql' => 'pg_indexes', 'oracle' => 'user_indexes' ) as $engine => $catalog )
        {
            $db = new expAiCoreTestNamedDb( $engine, array( '/index/i' => array( array( 'NAME' => 'EXPAI_USAGE_LOG_CREATED' ) ) ) );
            $this->assertSame( array( 'expai_usage_log_created' ), expAiSchema::existingIndexes( 'expai_usage_log', $db ), $engine );
            $this->assertStringContainsString( $catalog, $db->statements[0] );
        }
        $mongo = new expAiCoreTestMongoDb( 'mongodb' );
        $this->assertNull( expAiSchema::existingColumns( 'expai_usage_log', $mongo ), 'MongoDB has no fixed columns' );
        $this->assertSame( array(), expAiSchema::missingColumns( $mongo ) );
    }

    public function testRemovedTablesOfEachEngine(): void
    {
        $package = array( '/expai%/i' => array( array( 'name' => 'expai_package' ) ) );
        $pg = new expAiCoreTestNamedDb( 'postgresql', $package );
        $this->assertSame( array( array( 'expai_package' ), array() ), expAiSchema::dropRemoved( $pg ) );
        $this->assertContains( 'DROP SEQUENCE IF EXISTS expai_package_id_seq', $pg->statements );
        $oracle = new expAiCoreTestNamedDb( 'oracle', $package );
        expAiSchema::dropRemoved( $oracle );
        $this->assertNotEmpty( array_filter( $oracle->statements, function ( $s ) { return strpos( $s, 'DROP SEQUENCE se_expai_package' ) !== false; } ) );
        $refused = new expAiCoreTestNamedDb( 'mysql', $package, array( '/^DROP TABLE/' ) );
        $this->assertSame( array( array(), array( 'mysql: DROP TABLE expai_package' ) ), expAiSchema::dropRemoved( $refused ) );

        $mongo = new expAiCoreTestMongoDb( 'mongodb' );
        $this->assertSame( array( array( 'expai_package' ), array() ), expAiSchema::dropRemoved( $mongo ) );
        $this->assertSame( array( 'expai_package' ), $mongo->dropped );
    }

    public function testEnsureWithoutAHandlerAndWithFailingStatements(): void
    {
        list( $created, $errors ) = expAiSchema::ensure( new expAiCoreTestNamedDb( 'db2' ) );
        $this->assertSame( array(), $created );
        $this->assertSame( array( 'no schema handler for the engine db2' ), $errors );

        // MySQL: every table missing, every CREATE refused by the database
        $db = new expAiCoreTestNamedDb( 'mysql', array(), array( '/^CREATE/i' ) );
        list( $created, $errors ) = expAiSchema::ensure( $db );
        $this->assertSame( array(), $created );
        $this->assertNotEmpty( $errors );
        $this->assertStringStartsWith( 'mysql: CREATE TABLE', $errors[0] );
        $this->assertSame( array(), expAiSchema::upgradeStatements( new expAiCoreTestNamedDb( 'db2' ) ), 'no handler, no upgrade' );
    }
}


/**
 * A database connection with a name and answers the test gives (expAiSchema's queries of each engine's catalog):
 * the statements are recorded, a query matching a pattern of $answers gets its rows, query() fails for the patterns
 * of $failing.
 */
class expAiCoreTestNamedDb extends eZDBInterface
{
    public $statements = array();
    private $name;
    private $answers;
    private $failing;

    public function __construct( $name, array $answers = array(), array $failing = array() )
    {
        $this->name = $name;
        $this->answers = $answers;
        $this->failing = $failing;
    }

    public function databaseName()
    {
        return $this->name;
    }

    public function arrayQuery( $sql, $params = array(), $server = false )
    {
        $this->statements[] = $sql;
        foreach ( $this->answers as $pattern => $rows )
            if ( preg_match( $pattern, $sql ) )
                return $rows;
        return array();
    }

    public function query( $sql, $server = false )
    {
        $this->statements[] = $sql;
        foreach ( $this->failing as $pattern )
            if ( preg_match( $pattern, $sql ) )
                return false;
        return true;
    }

    public function eZTableList( $server = self::SERVER_MASTER )
    {
        return array( 'EXPAI_USAGE_LOG' => 1, 'ezuser' => 1 );
    }
}

/** A MongoDB connection: collections instead of tables */
class expAiCoreTestMongoDb extends expAiCoreTestNamedDb
{
    public $dropped = array();

    public function listCollectionNames()
    {
        return array( 'expai_usage_log', 'EXPAI_PACKAGE', 'other' );
    }

    public function dropCollection( $name )
    {
        $this->dropped[] = $name;
        return $name === 'expai_package' ? true : false;
    }
}
