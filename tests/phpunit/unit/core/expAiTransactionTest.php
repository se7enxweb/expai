<?php
/**
 * Transactions: a failure between begin() and commit() rolls the transaction back before the exception leaves the
 * method, and the content writer refuses unknown fields before it opens one. A transaction left open would run the
 * rest of the request inside it, and the end of the request would roll back everything written after it.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

/** A content class with a fixed data map: nothing of it is read from the database. */
class expAiTransactionTestClass extends eZContentClass
{
    public function dataMap()
    {
        return array( 'title' => null, 'body' => null );
    }
}

/** A parent node that fails the test when the writer asks it anything. */
class expAiTransactionTestNode extends eZContentObjectTreeNode
{
    public function canRead()
    {
        throw new LogicException( 'the parent node was read before the fields were checked' );
    }
}

/** A writer whose class comes from the test, and that can fail inside the transaction. */
class expAiTransactionTestWriter extends expAiContentWriter
{
    /** @var eZContentClass|null */
    public $class = null;

    /** @var bool */
    public $failInName = false;

    protected function fetchClass( $identifier )
    {
        return $this->class ?: parent::fetchClass( $identifier );
    }

    public function updateName( eZContentObject $object, eZContentObjectVersion $version, $language )
    {
        if ( $this->failInName )
            throw new RuntimeException( 'failure inside the transaction' );
        parent::updateName( $object, $version, $language );
    }
}

class expAiTransactionTest extends expAiCoreTestCase
{
    public function testUnknownFieldIdentifiersAreRefusedWithTheValidOnes(): void
    {
        expAiContentWriter::checkFieldIdentifiers( array( 'title' ), array( 'title', 'body' ) );
        expAiContentWriter::checkFieldIdentifiers( array(), array( 'title' ) );
        try
        {
            expAiContentWriter::checkFieldIdentifiers( array( 'title', 'colour' ), array( 'title', 'body' ) );
            $this->fail( 'an unknown field was accepted' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'Unknown field(s) %unknown. The fields of this class are: %valid.', $e->getMessage() );
            $this->assertSame( array( '%unknown' => 'colour', '%valid' => 'title, body' ), $e->arguments() );
        }
    }

    public function testCreateDraftChecksTheFieldsBeforeItReadsTheNodeOrOpensATransaction(): void
    {
        $writer = new expAiTransactionTestWriter( $this->config() );
        $writer->class = new expAiTransactionTestClass( array( 'id' => 0, 'identifier' => 'xtd_class',
            'serialized_name_list' => serialize( array( 'eng-GB' => 'Test', 'always-available' => 'eng-GB' ) ),
            'serialized_description_list' => serialize( array( 'eng-GB' => '', 'always-available' => 'eng-GB' ) ) ) );
        try
        {
            $writer->createDraft( new expAiTransactionTestNode( array() ), 'xtd_class', array( 'title' => 'T', 'colour' => 'red' ) );
            $this->fail( 'an unknown field was accepted' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( array( '%unknown' => 'colour', '%valid' => 'title, body' ), $e->arguments() );
        }
    }

    /** Every begin() in classes/ is followed by a try whose catch rolls back. */
    public function testEveryTransactionOfTheExtensionRollsBackOnAFailure(): void
    {
        $found = 0;
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( EXPAI_TEST_CHECKOUT . '/classes', FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $file )
        {
            if ( $file->getExtension() !== 'php' )
                continue;
            $lines = file( $file->getPathname(), FILE_IGNORE_NEW_LINES );
            foreach ( $lines as $n => $line )
            {
                if ( !preg_match( '/^(\s*)\$db->begin\(\);$/', $line, $m ) )
                    continue;
                $found++;
                $where = substr( $file->getPathname(), strlen( EXPAI_TEST_CHECKOUT ) + 1 ) . ':' . ( $n + 1 );
                $this->assertSame( $m[1] . 'try', isset( $lines[$n + 1] ) ? $lines[$n + 1] : '', "$where: begin() without a try after it" );
                $catch = '';
                for ( $i = $n + 2; $i < count( $lines ); $i++ )
                {
                    if ( $lines[$i] === $m[1] . 'catch ( Throwable $e )' )
                    {
                        $catch = implode( ' ', array_map( 'trim', array_slice( $lines, $i + 1, 5 ) ) );
                        break;
                    }
                }
                $this->assertStringContainsString( 'if ( $db->transactionCounter() > 0 ) $db->rollback(); throw $e;', $catch, "$where: no catch that rolls back" );
            }
        }
        $this->assertGreaterThan( 10, $found );
    }

    public function testAFailingCreateDraftLeavesNoTransactionOpen(): void
    {
        $this->requireDatabase();
        $folder = $this->folder();
        $db = eZDB::instance();
        $before = $db->transactionCounter();
        $writer = new expAiTransactionTestWriter( $this->config() );
        $writer->failInName = true;
        $count = (int) eZPersistentObject::count( eZContentObject::definition(), array( 'owner_id' => (int) eZUser::currentUserID() ) );
        try
        {
            $writer->createDraft( $folder, 'folder', array( 'name' => 'expai transaction test' ) );
            $this->fail( 'the failure inside the transaction was lost' );
        }
        catch ( RuntimeException $e )
        {
            $this->assertSame( 'failure inside the transaction', $e->getMessage() );
        }
        $this->assertSame( 0, $before );
        $this->assertSame( 0, $db->transactionCounter() );
        $this->assertSame( $count, (int) eZPersistentObject::count( eZContentObject::definition(), array( 'owner_id' => (int) eZUser::currentUserID() ) ),
                           'the draft of the failed call stayed' );

        try
        {
            $writer->createDraft( $folder, 'folder', array( 'xtd_unknown' => 'x' ) );
            $this->fail( 'an unknown field was accepted' );
        }
        catch ( expAiException $e )
        {
            $this->assertStringStartsWith( 'Unknown field(s)', $e->getMessage() );
        }
        $this->assertSame( 0, $db->transactionCounter() );
    }
}
