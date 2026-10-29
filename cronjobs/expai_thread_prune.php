<?php
/**
 * Cronjob expai_thread_prune (part "expai"): removes thread events, staged proposals, checkpoints and finished plans
 * and follow-ups older than expai.ini [ThreadSettings] EventKeepDays, ProposalKeepDays, CheckpointKeepDays
 * (30 days each by default; 0 keeps them). The threads, their runs and conversations stay.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/** @var eZCLI $cli */
if ( !expAiThread::available() )
{
    if ( !$isQuiet )
        $cli->output( 'expai: no thread tables (run extension/expai/bin/php/expaischema.php --install)' );
    return;
}
$parts = array();
foreach ( expAiThreadRetention::prune() as $table => $count )
    $parts[] = "$table $count";
if ( !$isQuiet )
    $cli->output( 'expai: removed old thread rows: ' . implode( ', ', $parts ) );
