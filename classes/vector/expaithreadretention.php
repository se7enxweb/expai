<?php
/**
 * File containing the expAiThreadRetention class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The retention of thread data (owner decision 2026-10-11: events, proposals and checkpoints are kept 30 days),
 * prune() run by the cronjob part "expai" (cronjobs/expai_thread_prune.php) and by bin/php/expaithreads.php --prune:
 *
 *   expai_thread_event   older than [ThreadSettings] EventKeepDays
 *   expai_change         older than [ThreadSettings] ProposalKeepDays
 *   expai_checkpoint     older than [ThreadSettings] CheckpointKeepDays
 *   expai_plan           older than ProposalKeepDays and not running
 *   expai_followup       sent or dropped, older than EventKeepDays
 *
 * 0 keeps the rows. The threads themselves, their runs and conversations stay (the editor settles or archives them)
 * until pruneRecords() removes them:
 *
 *   expai_thread                 not modified for [RetentionSettings] ThreadKeepDays: removed with its runs, events,
 *                                follow-ups, checkpoints, plans, staged changes and conversations; not a pinned
 *                                thread, not one in review or waiting for an answer, and not one that runs (a run
 *                                that stopped reporting for 4 x [ThreadSettings] StaleRunSeconds does not count)
 *   expai_conversation           not modified for ConversationKeepDays and not part of a thread that stays
 *   expai_draft_log              prompt_summary (the editor's words) blanked after PromptSummaryKeepDays; the row stays
 *   threads and conversations of a user that no longer exists, whatever their age and shelf (except a thread that
 *   runs; the next run removes it)
 *
 * [RetentionSettings] Mode=report counts only.
 *
 * The kernel sends no event when a user is removed, so the rows of removed users are found by their user id.
 */
class expAiThreadRetention
{
    /** @return array a minimal persistent object definition of a thread table (for counts and removals) */
    public static function tableDefinition( $table )
    {
        $int = array( 'name' => 'X', 'datatype' => 'integer', 'default' => 0, 'required' => true );
        $str = array( 'name' => 'X', 'datatype' => 'string', 'default' => '', 'required' => true );
        $fields = array( 'id' => $int, 'thread_id' => $int, 'created' => $int );
        if ( in_array( $table, array( 'expai_followup', 'expai_plan' ), true ) )
            $fields['state'] = $str;
        return array( 'fields' => $fields, 'keys' => array( 'id' ), 'name' => $table, 'class_name' => false );
    }

    /**
     * @param bool $dryRun count only
     * @return array table => rows removed (or that would be removed)
     */
    public static function prune( $dryRun = false, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $out = array();
        if ( !expAiThread::available() )
            return $out;
        $now = time();
        $eventDays = $config->int( 'ThreadSettings', 'EventKeepDays', 30, 0 );
        $proposalDays = $config->int( 'ThreadSettings', 'ProposalKeepDays', 30, 0 );
        $checkpointDays = $config->int( 'ThreadSettings', 'CheckpointKeepDays', 30, 0 );
        $rules = array(
            'expai_thread_event' => array( $eventDays, '' ),
            'expai_change' => array( $proposalDays, '' ),
            'expai_checkpoint' => array( $checkpointDays, '' ),
            'expai_plan' => array( $proposalDays, " AND state <> 'running'" ),
            'expai_followup' => array( $eventDays, " AND state <> 'waiting'" ),
        );
        $db = eZDB::instance();
        foreach ( $rules as $table => $rule )
        {
            list( $days, $extra ) = $rule;
            if ( $days <= 0 )
            {
                $out[$table] = 0;
                continue;
            }
            $where = 'created < ' . (int) ( $now - $days * 86400 ) . $extra;
            $rows = $db->arrayQuery( "SELECT COUNT(*) AS n FROM $table WHERE $where" );
            $row = is_array( $rows ) && $rows ? array_change_key_case( $rows[0], CASE_LOWER ) : array( 'n' => 0 );
            $count = (int) $row['n'];
            if ( $count && !$dryRun )
                $db->query( "DELETE FROM $table WHERE $where" );
            $out[$table] = $count;
        }
        return $out;
    }

    /** the most rows of a table pruneRecords() removes in one run (the next run goes on) */
    const BATCH = 1000;

    /** the rows one page of a scan reads (pruneRecords() reads at most SCAN_PAGES pages per table and run) */
    const PAGE = 500;
    const SCAN_PAGES = 20;

    /**
     * @return bool [RetentionSettings] Mode=delete: the retention removes rows. Any other value (report, the default of
     *              this release, or a value it does not know) only reports what it would remove.
     */
    public static function deletes( expAiConfig $config )
    {
        return strtolower( trim( (string) $config->get( 'RetentionSettings', 'Mode', 'report' ) ) ) === 'delete';
    }

    /**
     * Removes old threads and conversations, blanks old request summaries, and removes the threads and conversations
     * of removed users ([RetentionSettings]; 0 keeps the rows). Unless [RetentionSettings] Mode=delete nothing is
     * changed (as $dryRun). A thread that cannot be removed is logged and skipped; the next one is tried.
     * @param bool $dryRun count only
     * @return array expai_thread, expai_conversation, expai_draft_log.prompt_summary => rows (that would be) changed,
     *               removed_users => users whose rows were found, failed => threads that could not be removed,
     *               dry_run => whether nothing was changed
     */
    public static function pruneRecords( $dryRun = false, ?expAiConfig $config = null, $now = null )
    {
        $config = $config ?: expAiConfig::instance();
        $now = $now === null ? time() : (int) $now;
        $dryRun = $dryRun || !self::deletes( $config );
        $cutoffs = self::cutoffs( $config, $now );
        $staleBefore = $now - 4 * $config->int( 'ThreadSettings', 'StaleRunSeconds', 900, 60 );
        $out = array( 'expai_thread' => 0, 'expai_conversation' => 0, 'expai_draft_log.prompt_summary' => 0, 'removed_users' => 0, 'failed' => 0,
                      'dry_run' => $dryRun );
        $db = eZDB::instance();
        $threads = expAiThread::available();
        $gone = array_merge( self::removedUsers( 'expai_conversation' ), $threads ? self::removedUsers( 'expai_thread' ) : array() );
        $gone = array_values( array_unique( $gone ) );
        $out['removed_users'] = count( $gone );

        $removed = array();
        if ( $threads )
        {
            $conds = array();
            if ( $gone )
                $conds[] = 'user_id IN (' . implode( ', ', $gone ) . ')';
            if ( $cutoffs['thread'] )
                $conds[] = 'modified < ' . $cutoffs['thread'];
            if ( $conds )
                self::scan( 'expai_thread', 'id, user_id, state, shelf, modified', '(' . implode( ' OR ', $conds ) . ')',
                    function ( array $row ) use ( $gone, $cutoffs, $staleBefore, $dryRun, &$removed, &$out )
                    {
                        if ( !self::threadMayGo( $row, in_array( (int) $row['user_id'], $gone, true ), $cutoffs['thread'], $staleBefore ) )
                            return false;
                        $thread = expAiThread::fetchByID( (int) $row['id'] );
                        if ( !$thread )
                            return false;
                        if ( !$dryRun )
                        {
                            try
                            {
                                $thread->removeAll( true );
                            }
                            catch ( Throwable $e )
                            {
                                // one thread that cannot go must not hold up the others (the next run tries it again)
                                eZDebug::writeError( 'expai retention: thread ' . (int) $row['id'] . ' could not be removed: ' . $e->getMessage(), __METHOD__ );
                                $out['failed']++;
                                return false;
                            }
                        }
                        $removed[] = (int) $row['id'];
                        $out['expai_thread']++;
                        return true;
                    } );
        }

        $conds = array();
        if ( $gone )
            $conds[] = 'user_id IN (' . implode( ', ', $gone ) . ')';
        if ( $cutoffs['conversation'] )
            $conds[] = 'modified < ' . $cutoffs['conversation'];
        if ( $conds )
            self::scan( 'expai_conversation', 'id' . ( $threads ? ', thread_id' : '' ), '(' . implode( ' OR ', $conds ) . ')',
                function ( array $row ) use ( $threads, $dryRun, &$removed, &$out )
                {
                    $threadID = $threads ? (int) $row['thread_id'] : 0;
                    if ( $threadID && in_array( $threadID, $removed, true ) )
                    {
                        // goes with its thread (counted there)
                        if ( !$dryRun )
                            eZPersistentObject::removeObject( expAiConversation::definition(), array( 'id' => (int) $row['id'] ) );
                        return false;
                    }
                    // a conversation of a thread that stays stays with it (the thread's own retention removes it)
                    if ( $threadID && expAiThread::fetchByID( $threadID ) )
                        return false;
                    $out['expai_conversation']++;
                    if ( !$dryRun )
                        eZPersistentObject::removeObject( expAiConversation::definition(), array( 'id' => (int) $row['id'] ) );
                    return true;
                } );

        if ( $cutoffs['prompt_summary'] )
        {
            $where = 'created < ' . $cutoffs['prompt_summary'] . ' AND ' . self::notEmpty( $db, 'prompt_summary' );
            $rows = $db->arrayQuery( "SELECT COUNT(*) AS n FROM expai_draft_log WHERE $where" );
            $row = is_array( $rows ) && $rows ? array_change_key_case( $rows[0], CASE_LOWER ) : array( 'n' => 0 );
            $out['expai_draft_log.prompt_summary'] = (int) $row['n'];
            if ( $out['expai_draft_log.prompt_summary'] && !$dryRun )
                $db->query( "UPDATE expai_draft_log SET prompt_summary = '' WHERE $where" );
        }
        return $out;
    }

    /**
     * Whether a thread may be removed by the retention.
     * @param array $row state, shelf, modified
     * @param bool $userGone its user no longer exists
     * @param int $cutoff threads not modified since go (0: none goes for its age)
     * @param int $staleBefore a running or queued thread not modified since counts as stale (its run went away)
     * @return bool
     */
    public static function threadMayGo( array $row, $userGone, $cutoff, $staleBefore )
    {
        $state = isset( $row['state'] ) ? (string) $row['state'] : '';
        $modified = isset( $row['modified'] ) ? (int) $row['modified'] : 0;
        // a run that still works is never cut off; one that stopped reporting long ago is
        if ( in_array( $state, array( 'running', 'queued' ), true ) && $modified >= (int) $staleBefore )
            return false;
        if ( $userGone )
            return true;
        if ( !(int) $cutoff || $modified >= (int) $cutoff )
            return false;
        // the editor kept it (pinned), or it waits for the editor (review, an answer)
        if ( ( isset( $row['shelf'] ) && $row['shelf'] === 'pinned' ) || in_array( $state, array( 'review', 'waiting_input' ), true ) )
            return false;
        return true;
    }

    /**
     * Reads the rows of a table that meet a condition in pages, by id (a row the callback leaves does not keep the
     * rows after it from being looked at), until BATCH rows were taken or SCAN_PAGES pages read.
     * @param callable $take function( array $row ): bool (taken)
     */
    protected static function scan( $table, $columns, $where, callable $take )
    {
        $db = eZDB::instance();
        $cursor = 0;
        $taken = 0;
        for ( $page = 0; $page < self::SCAN_PAGES && $taken < self::BATCH; $page++ )
        {
            $rows = (array) $db->arrayQuery( "SELECT $columns FROM $table WHERE $where AND id > $cursor ORDER BY id", array( 'offset' => 0, 'limit' => self::PAGE ) );
            foreach ( $rows as $row )
            {
                $row = array_change_key_case( $row, CASE_LOWER );
                $cursor = (int) $row['id'];
                if ( $take( $row ) && ++$taken >= self::BATCH )
                    return;
            }
            if ( count( $rows ) < self::PAGE )
                return;
        }
    }

    /**
     * @return array thread, conversation, prompt_summary => the timestamp before which rows go (0: kept)
     */
    public static function cutoffs( expAiConfig $config, $now )
    {
        $out = array();
        foreach ( array( 'thread' => 'ThreadKeepDays', 'conversation' => 'ConversationKeepDays', 'prompt_summary' => 'PromptSummaryKeepDays' ) as $key => $name )
        {
            $days = $config->int( 'RetentionSettings', $name, 365, 0 );
            $out[$key] = $days > 0 ? (int) $now - $days * 86400 : 0;
        }
        return $out;
    }

    /**
     * @param string[] $existing the user ids of the table that still exist
     * @return int[] the user ids of the table without a user
     */
    public static function missing( array $ids, array $existing )
    {
        $ids = array_map( 'intval', $ids );
        $existing = array_map( 'intval', $existing );
        return array_values( array_filter( array_diff( $ids, $existing ), function ( $id ) { return $id > 0; } ) );
    }

    /** @return int[] the user ids of a table (user_id) that are no user any more */
    protected static function removedUsers( $table )
    {
        $ids = self::ids( "SELECT DISTINCT user_id AS id FROM $table" );
        if ( $ids === null )
        {
            eZDebug::writeWarning( "expai retention: the users of $table could not be read; no user counts as removed in this run", __METHOD__ );
            return array();
        }
        return self::goneUsers( $ids, function ( array $chunk )
        {
            return expAiThreadRetention::ids( 'SELECT contentobject_id AS id FROM ezuser WHERE contentobject_id IN (' . implode( ', ', $chunk ) . ')' );
        }, $table );
    }

    /**
     * The users of $ids that are no user any more. Fail closed, as their threads and conversations are removed: when
     * the lookup of the existing users fails (null), or finds none of them although ids were asked, no user counts
     * as removed; and when more than half of the asked users look removed, the run removes none of them and logs a
     * warning (a lookup against the wrong database, a broken ezuser table, a mass removal to look at first).
     * @param int[] $ids the user ids asked for
     * @param callable $lookup function( int[] $chunk ): int[]|null, the ids of the chunk that are users (null: failed)
     * @return int[]
     */
    public static function goneUsers( array $ids, $lookup, $label = '' )
    {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), function ( $id ) { return $id > 0; } ) ) );
        if ( !$ids )
            return array();
        $existing = array();
        foreach ( array_chunk( $ids, 500 ) as $chunk )
        {
            $found = call_user_func( $lookup, $chunk );
            if ( !is_array( $found ) )
            {
                eZDebug::writeWarning( "expai retention: the users of $label could not be looked up; no user counts as removed in this run", __METHOD__ );
                return array();
            }
            $existing = array_merge( $existing, $found );
        }
        if ( !$existing )
        {
            eZDebug::writeWarning( "expai retention: none of the " . count( $ids ) . " users of $label was found; no user counts as removed in this run", __METHOD__ );
            return array();
        }
        $gone = self::missing( $ids, $existing );
        if ( count( $gone ) * 2 > count( $ids ) )
        {
            eZDebug::writeWarning( "expai retention: " . count( $gone ) . " of the " . count( $ids ) . " users of $label look removed (more than half); "
                                   . 'their threads and conversations are kept. Check the user table, then remove them by hand if they are gone.', __METHOD__ );
            return array();
        }
        return $gone;
    }

    /**
     * @return int[]|null the column id of the rows of a query; null when the query failed or a row has no id (the
     *                    caller must not take a failed lookup for "no rows")
     */
    public static function ids( $sql )
    {
        $rows = eZDB::instance()->arrayQuery( $sql );
        if ( !is_array( $rows ) )
            return null;
        $out = array();
        foreach ( $rows as $row )
        {
            $row = is_array( $row ) ? array_change_key_case( $row, CASE_LOWER ) : array();
            if ( !isset( $row['id'] ) || !is_numeric( $row['id'] ) )
                return null;
            $out[] = (int) $row['id'];
        }
        return $out;
    }

    /** @return string the condition "the column is not empty" ('' is NULL on Oracle) */
    public static function notEmpty( $db, $column )
    {
        return $db->databaseName() === 'oracle' ? "$column IS NOT NULL" : "$column <> ''";
    }
}
