<?php
/**
 * The CSV export of expai/log cannot carry a spreadsheet formula (expAiUsage::csvRow()), and the document of a chain
 * run is shown only to the user who started it, holders of expai/log and users who may edit its object
 * (expAiChainPolicy::maySee()). No database: the users, runs and objects are made in memory.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiExportTestUser extends eZUser
{
    public $testID = 0;
    public $testLog = 'no';
    public $testChain = 'yes';

    public function isRegistered()
    {
        return true;
    }

    public function attribute( $name, $noFunction = false )
    {
        return $name === 'contentobject_id' ? $this->testID : null;
    }

    public function hasAccessTo( $module, $function = false )
    {
        if ( $module === 'expai' && $function === 'log' )
            return array( 'accessWord' => $this->testLog );
        if ( $module === 'expai' && $function === 'chain' )
            return array( 'accessWord' => $this->testChain );
        return array( 'accessWord' => 'no' );
    }
}

/** An object that answers checkAccess( 'edit' ) for the users it lists, and records whom it was asked for. */
class expAiExportTestObject extends eZContentObject
{
    /** @var int[] */
    public $editors = array();

    /** @var array */
    public $askedFor = array();

    public function checkAccess( $functionName, $originalClassID = false, $parentClassID = false, $returnAccessList = false, $language = false, $userID = false )
    {
        $this->askedFor[] = $userID;
        return $functionName === 'edit' && in_array( (int) $userID, $this->editors, true ) ? 1 : 0;
    }

    public function canEdit( $originalClassID = false, $parentClassID = false, $returnAccessList = false, $language = false )
    {
        throw new LogicException( 'canEdit() checks the current user, not the user asked about' );
    }
}

/** The chain policy with its objects from the test. */
class expAiExportTestChainPolicy extends expAiChainPolicy
{
    /** @var eZContentObject[] */
    public static $objects = array();

    protected static function fetchObject( $objectID )
    {
        return isset( self::$objects[$objectID] ) ? self::$objects[$objectID] : null;
    }
}

class expAiExportAndChainAccessTest extends expAiCoreTestCase
{
    public function testACsvCellNeverStartsAFormula(): void
    {
        $this->assertSame( array( "'=HYPERLINK(\"x\")", "'+1+cmd", "'-2+3", "'@SUM(A1)", "'\tx", "'\rx", 'claude-x', '12', '', '0.5', '' ),
                           expAiUsage::csvRow( array( '=HYPERLINK("x")', '+1+cmd', '-2+3', '@SUM(A1)', "\tx", "\rx", 'claude-x', 12, '', 0.5, null ) ) );
        $this->assertSame( array( '-2', '-0.000125', '+1' ), expAiUsage::csvRow( array( -2, '-0.000125', '+1' ) ), 'numbers stay numbers' );
    }

    public function testTheLogExportUsesTheSafeRow(): void
    {
        $this->assertStringContainsString( 'fputcsv( $out, expAiUsage::csvRow( array( $row->attribute( \'id\' )',
                                           (string) file_get_contents( EXPAI_TEST_CHECKOUT . '/modules/expai/log.php' ) );
    }

    public function testOnlyTheOwnerLogHoldersAndEditorsSeeAChainRun(): void
    {
        $object = new expAiExportTestObject( array() );
        expAiExportTestChainPolicy::$objects = array( 40 => $object );
        $this->cleanup( static function () { expAiExportTestChainPolicy::$objects = array(); } );
        $run = new expAiChainRun( array( 'user_id' => 14, 'object_id' => 40, 'node_id' => 2, 'chain' => 'xtd' ) );
        $user = new expAiExportTestUser( array() );

        $user->testID = 14;
        $this->assertTrue( expAiExportTestChainPolicy::maySee( $run, $user ), 'the owner' );
        $user->testID = 15;
        $this->assertFalse( expAiExportTestChainPolicy::maySee( $run, $user ), 'a user who may not edit the object' );
        $object->editors = array( 15 );
        $this->assertTrue( expAiExportTestChainPolicy::maySee( $run, $user ), 'an editor of the object who may use the chain' );
        $this->assertSame( 15, end( $object->askedFor ), 'the access is checked for the user asked about' );
        $user->testChain = 'no';
        $this->assertFalse( expAiExportTestChainPolicy::maySee( $run, $user ), 'an editor who may not use the chain' );
        $this->assertFalse( expAiExportTestChainPolicy::mayAct( $run, $user ) );
        $user->testChain = 'yes';
        $object->editors = array();
        $user->testLog = 'yes';
        $this->assertTrue( expAiExportTestChainPolicy::maySee( $run, $user ), 'a holder of expai/log' );
        $user->testLog = 'no';

        // a run without its object yet: not the editors of the parent node, only the owner and the log
        $pending = new expAiChainRun( array( 'user_id' => 14, 'object_id' => 0, 'node_id' => 2, 'chain' => 'xtd' ) );
        $object->editors = array( 15 );
        $this->assertFalse( expAiExportTestChainPolicy::maySee( $pending, $user ) );
        $this->assertFalse( expAiExportTestChainPolicy::mayAct( $pending, $user ) );
        $user->testLog = 'yes';
        $this->assertTrue( expAiExportTestChainPolicy::maySee( $pending, $user ) );
        $user->testID = 14;
        $user->testLog = 'no';
        $this->assertTrue( expAiExportTestChainPolicy::maySee( $pending, $user ) );
    }
}
