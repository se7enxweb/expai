#!/usr/bin/env php
<?php
/**
 * Threads of expai (doc/threads.md).
 *
 *   php extension/expai/bin/php/expaithreads.php --report  -s <siteaccess>      counts of threads per origin, state and
 *                                                                               shelf, events, panel conversations left
 *                                                                               to migrate (no content, no names)
 *   php extension/expai/bin/php/expaithreads.php --migrate [--dry-run]          turns panel conversations into threads
 *                                                                               (idempotent; --install does it too)
 *   php extension/expai/bin/php/expaithreads.php --prune   [--dry-run]          removes events, proposals, checkpoints
 *                                                                               and old follow-ups after
 *                                                                               [ThreadSettings] *KeepDays (the cronjob
 *                                                                               part "expai" does it too)
 *
 * -s <siteaccess> is mandatory: the script prints the siteaccess and its database (stderr) and does not run
 * where expai is not active (expAiScript).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array( 'description' => "Threads of expai (Exponential Intelligence)\n",
                                     'use-session' => false, 'use-modules' => false, 'use-extensions' => true ) );
$script->startup();
$options = $script->getOptions( '[report][migrate][prune][dry-run]', '', array(
    'report' => 'Counts of threads per origin, state and shelf (no content)',
    'migrate' => 'Turn the panel conversations without a thread into threads (idempotent)',
    'prune' => 'Remove events, proposals and checkpoints older than [ThreadSettings] EventKeepDays / ProposalKeepDays / CheckpointKeepDays, and threads, conversations and request summaries after [RetentionSettings]',
    'dry-run' => 'With --migrate or --prune: report what would change, write nothing' ) );
expAiScript::requireSiteAccess( $script, $options );
$script->initialize();
expAiScript::announce( $script );

$failed = false;
$dry = (bool) $options['dry-run'];
if ( !expAiThread::available() )
{
    $cli->error( 'FAIL the thread tables are missing: run extension/expai/bin/php/expaischema.php --install' );
    $script->shutdown( 1 );
}
if ( $options['migrate'] )
{
    $total = array( 'created' => 0, 'linked' => 0, 'skipped' => 0 );
    do
    {
        $report = expAiThreadMigration::run( $dry );
        foreach ( $total as $key => $value )
            $total[$key] += $report[$key];
        foreach ( $report['threads'] as $entry )
            $cli->output( sprintf( '%s conversation %d -> thread %d (run %d, shelf %s, %d usage rows, %d draft rows)', $dry ? 'would ' . $entry['action'] : $entry['action'],
                                   $entry['conversation_id'], $entry['thread_id'], $entry['run_id'], $entry['shelf'], $entry['usage_rows'], $entry['draft_rows'] ) );
    }
    while ( !$dry && $report['created'] + $report['linked'] > 0 && $report['candidates'] >= 500 );
    $cli->output( sprintf( 'PASS %s%d thread(s) created, %d linked, %d skipped (not panel conversations)', $dry ? 'dry run: ' : '', $total['created'], $total['linked'], $total['skipped'] ) );
}
elseif ( $options['prune'] )
{
    foreach ( expAiThreadRetention::prune( $dry ) as $table => $count )
        $cli->output( sprintf( '%s %s: %d row(s)', $dry ? 'would remove from' : 'removed from', $table, $count ) );
    $retention = expAiThreadRetention::pruneRecords( $dry );
    foreach ( $retention as $what => $count )
        if ( $what !== 'dry_run' )
            $cli->output( sprintf( '%s %s: %d', $retention['dry_run'] ? 'retention, would change' : 'retention, changed', $what, $count ) );
    $cli->output( 'PASS prune' . ( $dry ? ' (dry run)' : '' ) );
}
else
{
    $db = eZDB::instance();
    foreach ( array( 'origin', 'state', 'shelf' ) as $column )
    {
        $parts = array();
        foreach ( (array) $db->arrayQuery( "SELECT $column AS v, COUNT(*) AS n FROM expai_thread GROUP BY $column" ) as $row )
        {
            $row = array_change_key_case( $row, CASE_LOWER );
            $parts[] = $row['v'] . ' ' . (int) $row['n'];
        }
        $cli->output( "threads by $column: " . ( $parts ? implode( ', ', $parts ) : '-' ) );
    }
    $events = $db->arrayQuery( 'SELECT COUNT(*) AS n FROM expai_thread_event' );
    $cli->output( 'events: ' . (int) ( $events ? reset( $events[0] ) : 0 ) );
    $pending = expAiThreadMigration::pending();
    $cli->output( 'panel conversations without a thread: ' . $pending . ( $pending ? ' (run --migrate)' : '' ) );
    $cli->output( 'PASS report' );
}
$script->shutdown( $failed ? 1 : 0 );
