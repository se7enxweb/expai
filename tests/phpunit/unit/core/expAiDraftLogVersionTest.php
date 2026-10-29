<?php
/**
 * The label of a version: the kernel reuses the number of a discarded version, so a draft log entry older than the
 * version's "created" belongs to the removed version and gives the new one no label (expAiDraftLog::fetchByVersion(),
 * fetchForVersions()).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiDraftLogVersionTest extends expAiCoreTestCase
{
    public function testAnEntryOlderThanTheVersionBelongsToAnEarlierOne(): void
    {
        $this->assertTrue( expAiDraftLog::belongsToVersion( 1000, 1000 ) );
        $this->assertTrue( expAiDraftLog::belongsToVersion( 1005, 1000 ) );
        $this->assertFalse( expAiDraftLog::belongsToVersion( 999, 1000 ) );
        $this->assertTrue( expAiDraftLog::belongsToVersion( 999, 0 ), 'no such version: the entry is kept (as before)' );
    }

    public function testTheLabelIgnoresTheEntryOfADiscardedVersionWithTheSameNumber(): void
    {
        $this->requireDatabase();
        $folder = $this->folder();
        $object = $folder->object();
        $versionNo = (int) $object->attribute( 'current_version' );
        $version = $object->version( $versionNo );
        $created = (int) $version->attribute( 'created' );
        $objectID = (int) $object->attribute( 'id' );
        $old = expAiDraftLog::record( array( 'contentobject_id' => $objectID, 'version' => $versionNo, 'user_id' => self::TEST_USER,
                                             'action' => 'xtd_old', 'created' => $created - 60, 'modified' => $created - 60 ) );
        $this->cleanup( static function () use ( $objectID ) { eZPersistentObject::removeObject( expAiDraftLog::definition(), array( 'contentobject_id' => $objectID ) ); } );
        $this->assertNull( expAiDraftLog::fetchByVersion( $objectID, $versionNo ), 'the entry of the discarded version labelled the new one' );
        $this->assertSame( array(), expAiDraftLog::fetchForVersions( array( array( $objectID, $versionNo ) ) ) );
        $new = expAiDraftLog::record( array( 'contentobject_id' => $objectID, 'version' => $versionNo, 'user_id' => self::TEST_USER, 'action' => 'xtd_new' ) );
        $this->assertSame( (int) $new->attribute( 'id' ), (int) expAiDraftLog::fetchByVersion( $objectID, $versionNo )->attribute( 'id' ) );
        $this->assertSame( (int) $new->attribute( 'id' ), (int) expAiDraftLog::fetchForVersions( array( array( $objectID, $versionNo ) ) )[$objectID . ':' . $versionNo]->attribute( 'id' ) );
        $this->assertNotSame( (int) $old->attribute( 'id' ), (int) $new->attribute( 'id' ) );
    }
}
