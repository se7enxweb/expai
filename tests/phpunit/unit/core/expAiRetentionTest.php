<?php
/**
 * The retention of the editors' words ([RetentionSettings], expAiThreadRetention::pruneRecords()): the periods, the
 * users that no longer exist, the empty test of Oracle, and (with the database, inside a transaction that is rolled
 * back) the removal of the rows of a removed user and of old conversations, and the blanked request summaries.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

/** Only the engine name of a database. */
class expAiRetentionTestDb
{
    /** @var string */
    public $name;

    public function __construct( $name )
    {
        $this->name = $name;
    }

    public function databaseName()
    {
        return $this->name;
    }
}

class expAiRetentionTest extends expAiCoreTestCase
{
    public function testThePeriodsDefaultToAYearAndZeroKeepsTheRows(): void
    {
        $now = 1800000000;
        $this->assertSame( array( 'thread' => $now - 365 * 86400, 'conversation' => $now - 365 * 86400, 'prompt_summary' => $now - 365 * 86400 ),
                           expAiThreadRetention::cutoffs( $this->config(), $now ) );
        $config = $this->config( array( 'RetentionSettings' => array( 'ThreadKeepDays' => '0', 'ConversationKeepDays' => '30', 'PromptSummaryKeepDays' => '7' ) ) );
        $this->assertSame( array( 'thread' => 0, 'conversation' => $now - 30 * 86400, 'prompt_summary' => $now - 7 * 86400 ),
                           expAiThreadRetention::cutoffs( $config, $now ) );
    }

    public function testTheUsersThatNoLongerExist(): void
    {
        $this->assertSame( array( 99, 7 ), expAiThreadRetention::missing( array( 14, 99, '7', 0, 14 ), array( 14 ) ) );
        $this->assertSame( array(), expAiThreadRetention::missing( array( 14 ), array( '14' ) ) );
    }

    public function testTheShippedModeOnlyReports(): void
    {
        $this->assertFalse( expAiThreadRetention::deletes( $this->useSettings() ), 'this release ships Mode=report' );
        $this->assertTrue( expAiThreadRetention::deletes( $this->useSettings( array( 'RetentionSettings' => array( 'Mode' => 'delete' ) ) ) ), 'an explicit delete stays' );
        $this->assertFalse( expAiThreadRetention::deletes( $this->useSettings( array( 'RetentionSettings' => array( 'Mode' => 'remove' ) ) ) ), 'an unknown value only reports' );
    }

    public function testTheLookupOfRemovedUsersFailsClosed(): void
    {
        $exists = function ( array $users ) { return function ( array $chunk ) use ( $users ) { return array_values( array_intersect( $chunk, $users ) ); }; };
        $this->assertSame( array( 9 ), expAiThreadRetention::goneUsers( array( 1, 2, 9, 0 ), $exists( array( 1, 2 ) ), 't' ) );
        $this->assertSame( array(), expAiThreadRetention::goneUsers( array(), $exists( array() ), 't' ) );
        $this->assertSame( array(), expAiThreadRetention::goneUsers( array( 1, 2, 9 ), function () { return null; }, 't' ), 'a failed lookup removes no one' );
        $this->assertSame( array(), expAiThreadRetention::goneUsers( array( 1, 2, 9 ), function () { return false; }, 't' ), 'a failed query removes no one' );
        $this->assertSame( array(), expAiThreadRetention::goneUsers( array( 1, 2, 9 ), $exists( array() ), 't' ), 'none found although asked: no one' );
        $this->assertSame( array(), expAiThreadRetention::goneUsers( array( 1, 2, 3, 9 ), $exists( array( 1 ) ), 't' ), 'more than half look gone: no one' );
        $this->assertSame( array( 3, 9 ), expAiThreadRetention::goneUsers( array( 1, 2, 3, 9 ), $exists( array( 1, 2 ) ), 't' ), 'half is still believed' );
        // a lookup in chunks: one failed chunk fails the whole run
        $ids = range( 1, 1001 );
        $calls = 0;
        $chunked = function ( array $chunk ) use ( &$calls ) { $calls++; return $calls === 2 ? null : $chunk; };
        $this->assertSame( array(), expAiThreadRetention::goneUsers( $ids, $chunked, 't' ) );
    }

    public function testAnEmptyColumnOnEveryEngine(): void
    {
        $this->assertSame( "prompt_summary <> ''", expAiThreadRetention::notEmpty( new expAiRetentionTestDb( 'mysql' ), 'prompt_summary' ) );
        $this->assertSame( 'prompt_summary IS NOT NULL', expAiThreadRetention::notEmpty( new expAiRetentionTestDb( 'oracle' ), 'prompt_summary' ) );
    }

    public function testTheRowsOfARemovedUserAndOldRowsGo(): void
    {
        $this->inTransaction();
        $this->useSettings( array( 'RetentionSettings' => array( 'Mode' => 'delete', 'ThreadKeepDays' => '0', 'ConversationKeepDays' => '30', 'PromptSummaryKeepDays' => '30' ) ) );
        $old = time() - 40 * 86400;
        $admin = eZUser::fetchByName( 'admin' );
        if ( !$admin instanceof eZUser )
            $this->markTestSkipped( "needs the user 'admin'" );
        $adminID = (int) $admin->attribute( 'contentobject_id' );
        // rows of other removed users (left by earlier runs) would make more than half look removed, which the
        // retention refuses (goneUsers()): out of the way inside this test's transaction
        foreach ( array( 'expai_conversation', 'expai_thread' ) as $table )
            eZDB::instance()->query( "DELETE FROM $table WHERE user_id NOT IN ( SELECT contentobject_id FROM ezuser )" );
        $gone = new expAiConversation( array( 'user_id' => self::TEST_USER, 'node_id' => 2, 'messages' => '[]', 'created' => time(), 'modified' => time() ) );
        $gone->store();
        $aged = new expAiConversation( array( 'user_id' => $adminID, 'node_id' => 2, 'messages' => '[]', 'created' => $old, 'modified' => $old ) );
        $aged->store();
        $fresh = new expAiConversation( array( 'user_id' => $adminID, 'node_id' => 2, 'messages' => '[]', 'created' => time(), 'modified' => time() ) );
        $fresh->store();
        $log = expAiDraftLog::record( array( 'contentobject_id' => 0, 'version' => 1, 'user_id' => $adminID, 'prompt_summary' => 'an old request',
                                             'created' => $old, 'modified' => $old ) );

        $dry = expAiThreadRetention::pruneRecords( true );
        $this->assertGreaterThanOrEqual( 1, $dry['removed_users'] );
        $this->assertNotNull( self::conversation( $gone ), 'a dry run removes nothing' );

        $done = expAiThreadRetention::pruneRecords();
        $this->assertGreaterThanOrEqual( 2, $done['expai_conversation'] );
        $this->assertGreaterThanOrEqual( 1, $done['expai_draft_log.prompt_summary'] );
        $this->assertNull( self::conversation( $gone ), 'the conversation of a removed user stayed' );
        $this->assertNull( self::conversation( $aged ), 'an old conversation stayed' );
        $this->assertNotNull( self::conversation( $fresh ) );
        $row = eZDB::instance()->arrayQuery( 'SELECT prompt_summary FROM expai_draft_log WHERE id = ' . (int) $log->attribute( 'id' ) );
        $this->assertSame( '', (string) array_change_key_case( $row[0], CASE_LOWER )['prompt_summary'] );
    }

    /** @return expAiConversation|null the stored row of a conversation */
    private static function conversation( expAiConversation $conversation )
    {
        return eZPersistentObject::fetchObject( expAiConversation::definition(), null, array( 'id' => (int) $conversation->attribute( 'id' ) ) );
    }
}
