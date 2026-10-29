<?php
/**
 * Cronjob expai_prune: marks draft log rows whose content object was removed as "deleted"; removes old threads and
 * conversations, blanks old request summaries of the draft log and removes the threads and conversations of removed
 * users (expai.ini [RetentionSettings], expAiThreadRetention::pruneRecords()); removes rows of expai_usage_log older
 * than [UsageSettings] RetentionDays.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** @var eZCLI $cli */
// draft log rows whose content object was removed (or purged from the trash) since: marked "deleted", kept as record
$marked = expAiDraftLog::markDeletedObjects();
if ( !$isQuiet )
    $cli->output( "expai: marked $marked draft log row(s) of removed content objects as deleted" );

// threads, conversations and request summaries after [RetentionSettings], and those of removed users
$parts = array();
$retention = expAiThreadRetention::pruneRecords();
foreach ( $retention as $what => $count )
    if ( $what !== 'dry_run' )
        $parts[] = "$what $count";
// always said, also in quiet mode: rows of the editors go here
$cli->output( 'expai: retention ([RetentionSettings]' . ( $retention['dry_run'] ? ' Mode=report, nothing removed' : '' ) . '): ' . implode( ', ', $parts ) );

$days = expAiConfig::instance()->int( 'UsageSettings', 'RetentionDays', 365, 0 );
if ( $days <= 0 )
{
    if ( !$isQuiet )
        $cli->output( 'expai: RetentionDays is 0, usage rows are kept' );
    return;
}
$removed = expAiUsage::prune( $days );
if ( !$isQuiet )
    $cli->output( "expai: removed $removed usage row(s) older than $days days" );
