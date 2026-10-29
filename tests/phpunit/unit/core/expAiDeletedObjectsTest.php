<?php
/**
 * Draft log rows whose content object was removed since (expAiObjects, expAiDraftLog, expAiLinks, expAiDashboard):
 * the dashboard, the log and the links list such a row as removed, without a link, and write no "Object not found"
 * error to the debug output; the cronjob marks the rows "deleted".
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiDeletedObjectsTest extends expAiCoreTestCase
{
    /** An object id far above any real one */
    const GONE = 999999937;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->removeTestRows();
        $this->keepSession();
        $this->assertFalse( eZContentObject::exists( self::GONE ), 'the id of the test is not taken' );
    }

    /**
     * Runs $fn with eZDebug collecting errors, and returns the "Object not found" errors it wrote.
     * @return string[]
     */
    private function objectNotFoundErrors( callable $fn )
    {
        $debug = eZDebug::instance();
        $saved = array( 'enabled' => isset( $GLOBALS['eZDebugEnabled'] ) ? $GLOBALS['eZDebugEnabled'] : null,
                        'logOnly' => isset( $GLOBALS['eZDebugLogOnly'] ) ? $GLOBALS['eZDebugLogOnly'] : null,
                        'show' => $debug->ShowTypes, 'output' => $debug->MessageOutput, 'handle' => $debug->HandleType,
                        'count' => count( $debug->DebugStrings ) );
        $GLOBALS['eZDebugEnabled'] = true;
        $GLOBALS['eZDebugLogOnly'] = false;
        $debug->ShowTypes = eZDebug::SHOW_ALL;
        $debug->MessageOutput = eZDebug::OUTPUT_MESSAGE_STORE;
        if ( $debug->HandleType == eZDebug::HANDLE_TO_PHP )
            $debug->HandleType = eZDebug::HANDLE_NONE;
        try
        {
            $fn();
        }
        finally
        {
            $written = array_slice( $debug->DebugStrings, $saved['count'] );
            $debug->DebugStrings = array_slice( $debug->DebugStrings, 0, $saved['count'] );
            $debug->ShowTypes = $saved['show'];
            $debug->MessageOutput = $saved['output'];
            $debug->HandleType = $saved['handle'];
            foreach ( array( 'enabled' => 'eZDebugEnabled', 'logOnly' => 'eZDebugLogOnly' ) as $key => $global )
                if ( $saved[$key] === null )
                    unset( $GLOBALS[$global] );
                else
                    $GLOBALS[$global] = $saved[$key];
        }
        $out = array();
        foreach ( $written as $entry )
            if ( strpos( (string) $entry['String'], 'Object not found' ) !== false )
                $out[] = (string) $entry['String'];
        return $out;
    }

    public function testTheCollectorSeesTheKernelError(): void
    {
        // the check below is only worth something if the kernel's own error is caught here
        $errors = $this->objectNotFoundErrors( static function () { eZContentObject::fetch( self::GONE ); } );
        $this->assertCount( 1, $errors );
    }

    public function testDashboardListsARemovedObjectWithoutLinkOrError(): void
    {
        $admin = $this->asUser( 'admin' );
        $adminID = (int) $admin->attribute( 'contentobject_id' );
        $node = $this->workFolder();
        $nodeID = (int) $node->attribute( 'node_id' );
        // a real object, removed for good (not into the trash) after its version was logged
        $removed = $this->publish( $nodeID, array( 'name' => 'XTD removed after logging' ), 'folder' );
        $removedID = (int) $removed->attribute( 'id' );
        $this->draftLog( array( 'contentobject_id' => $removedID, 'version' => 1, 'user_id' => $adminID, 'node_id' => $nodeID, 'created' => time() - 3 ) );
        eZContentObjectTreeNode::removeSubtrees( array( (int) $removed->attribute( 'main_node_id' ) ), false );
        unset( $GLOBALS['eZContentObjectContentObjectCache'][$removedID] );
        $this->assertFalse( eZContentObject::exists( $removedID ) );
        $this->draftLog( array( 'contentobject_id' => self::GONE, 'version' => 2, 'user_id' => $adminID, 'node_id' => $nodeID,
                                'prompt_summary' => 'Write about autumn', 'created' => time() - 2 ) );

        $rows = array();
        $errors = $this->objectNotFoundErrors( static function () use ( &$rows ) { $rows = array_column( expAiDashboard::myDrafts( 0, 10 ), null, 'key' ); } );
        $this->assertSame( array(), $errors, 'no "Object not found" error for a removed object' );
        foreach ( array( $removedID . ':1', self::GONE . ':2' ) as $key )
        {
            $this->assertArrayHasKey( $key, $rows, 'a removed object stays listed' );
            $row = $rows[$key];
            $this->assertTrue( $row['deleted'] );
            $this->assertSame( expAiDraftLog::STATUS_DELETED, $row['status'] );
            $this->assertSame( '', $row['urls']['view'] );
            $this->assertSame( '', $row['urls']['edit'] );
            $this->assertSame( 'deleted', $row['links']['state'] );
            $this->assertSame( array( '', '', '', '' ), array( $row['links']['view'], $row['links']['edit'], $row['links']['prompt'], $row['links']['tab'] ), 'no link' );
        }
    }

    public function testLookupsOfARemovedObjectWriteNoError(): void
    {
        $this->asUser( 'admin' );
        $log = $this->draftLog( array( 'contentobject_id' => self::GONE, 'version' => 1 ) );
        $fresh = expAiDraftLog::fetchByVersion( self::GONE, 1 );
        $results = array();
        $errors = $this->objectNotFoundErrors( static function () use ( &$results, $log, $fresh )
        {
            $results['links'] = expAiLinks::build( self::GONE, 1, 'eng-GB', 0, 0 );
            $results['object'] = $fresh->contentObject();
            $results['version'] = $fresh->contentVersion();
            $results['row'] = expAiAssistant::draftRow( $fresh );
            $results['fetch'] = expAiObjects::fetch( self::GONE );
            $marked = expAiDraftLog::markObjectsThatExist( array( $log ) );
            $results['marked'] = $marked[0]->objectExists();
            $results['marked_object'] = $marked[0]->contentObject();
        } );
        $this->assertSame( array(), $errors );
        $this->assertSame( 'deleted', $results['links']['state'] );
        $this->assertNull( $results['object'] );
        $this->assertNull( $results['version'] );
        $this->assertNull( $results['row'] );
        $this->assertNull( $results['fetch'] );
        $this->assertFalse( $results['marked'] );
        $this->assertNull( $results['marked_object'] );
        $this->assertFalse( $fresh->objectExists(), 'the entry remembers the object is gone' );
    }

    public function testExistingObjectsAreFoundInOneBatch(): void
    {
        $this->asUser( 'admin' );
        $node = $this->workFolder();
        $objectID = (int) $node->attribute( 'contentobject_id' );
        $this->assertSame( array( $objectID => true ), expAiObjects::existing( array( $objectID, self::GONE, 0, -4, $objectID ) ) );
        $this->assertSame( array(), expAiObjects::existing( array() ) );
        $this->assertSame( $objectID, (int) expAiObjects::fetch( $objectID )->attribute( 'id' ) );
        $here = $this->draftLog( array( 'contentobject_id' => $objectID, 'version' => 1 ) );
        expAiDraftLog::markObjectsThatExist( array( $here ) );
        $this->assertTrue( $here->objectExists() );
        $this->assertSame( $objectID, (int) $here->contentObject()->attribute( 'id' ) );
        $this->assertSame( 'ok', expAiLinks::build( $objectID, 0, '', 0, 0, true )['state'] );
    }

    public function testOrphanRowsAreFoundAndMarkedDeleted(): void
    {
        $this->asUser( 'admin' );
        $node = $this->workFolder();
        $gone = $this->draftLog( array( 'contentobject_id' => self::GONE, 'version' => 1 ) );
        $here = $this->draftLog( array( 'contentobject_id' => (int) $node->attribute( 'contentobject_id' ), 'version' => 1 ) );
        $goneID = (int) $gone->attribute( 'id' );
        $hereID = (int) $here->attribute( 'id' );

        $orphans = array_column( expAiDraftLog::orphanRows( false, array( $goneID, $hereID ) ), null, 'id' );
        $this->assertSame( array( $goneID ), array_keys( $orphans ) );
        $this->assertSame( self::GONE, $orphans[$goneID]['contentobject_id'] );

        // only the rows asked for: the test never marks rows of the installation
        $this->assertSame( 1, expAiDraftLog::markDeletedObjects( array( $goneID, $hereID ) ) );
        $this->assertSame( expAiDraftLog::STATUS_DELETED, eZPersistentObject::fetchObject( expAiDraftLog::definition(), null, array( 'id' => $goneID ) )->attribute( 'status' ) );
        $this->assertSame( 'draft', eZPersistentObject::fetchObject( expAiDraftLog::definition(), null, array( 'id' => $hereID ) )->attribute( 'status' ) );
        $this->assertSame( 0, expAiDraftLog::markDeletedObjects( array( $goneID, $hereID ) ), 'marked once' );
        $this->assertSame( array(), expAiDraftLog::orphanRows( true, array( $goneID ) ) );
    }
}
