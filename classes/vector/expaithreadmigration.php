<?php
/**
 * File containing the expAiThreadMigration class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Turns the panel conversations of an earlier expai into threads (origin "panel", scope: the conversation's node,
 * review "direct"), with a lead run each, and links the conversation, its usage rows and its draft log rows to the
 * thread. Idempotent: a conversation that has a thread (thread_id, or a run that names it) is left alone, so it can run
 * on every bin/php/expaischema.php --install and from bin/php/expaithreads.php --migrate. Reversible: the
 * conversations are unchanged apart from thread_id/run_id; dropping the threads gives the earlier state back.
 */
class expAiThreadMigration
{
    /**
     * @param bool $dryRun report only, write nothing
     * @param int $batch conversations per run
     * @return array { candidates, created, linked, skipped, threads: list of { conversation_id, thread_id, run_id, shelf, usage_rows, draft_rows } }
     */
    public static function run( $dryRun = false, $batch = 500 )
    {
        $report = array( 'candidates' => 0, 'created' => 0, 'linked' => 0, 'skipped' => 0, 'threads' => array() );
        if ( !expAiThread::available() )
        {
            $report['error'] = 'the thread tables are missing: run bin/php/expaischema.php --install first';
            return $report;
        }
        $config = expAiConfig::instance();
        $lifetime = $config->int( 'AssistantSettings', 'ConversationLifetime', 604800, 60 );
        $titleLength = $config->int( 'ThreadSettings', 'TitleLength', 80, 20, 255 );
        $conversations = eZPersistentObject::fetchObjectList( expAiConversation::definition(), null, array( 'thread_id' => 0 ),
                                                              array( 'id' => 'asc' ), array( 'offset' => 0, 'length' => max( 1, (int) $batch ) ) );
        $db = eZDB::instance();
        foreach ( (array) $conversations as $conversation )
        {
            $report['candidates']++;
            $conversationID = (int) $conversation->attribute( 'id' );
            // a Vector conversation has node 0 and always a thread; one without a node is not a panel conversation
            if ( !(int) $conversation->attribute( 'node_id' ) )
            {
                $report['skipped']++;
                continue;
            }
            $existingRun = expAiThreadRun::fetchForConversation( $conversationID );
            $usageRows = (int) eZPersistentObject::count( expAiUsageLog::definition(), array( 'conversation_id' => $conversationID ) );
            $draftRows = (int) eZPersistentObject::count( expAiDraftLog::definition(), array( 'conversation_id' => $conversationID ) );
            $shelf = (int) $conversation->attribute( 'modified' ) < time() - $lifetime ? 'settled' : 'active';
            $entry = array( 'conversation_id' => $conversationID, 'thread_id' => 0, 'run_id' => 0, 'shelf' => $shelf,
                            'usage_rows' => $usageRows, 'draft_rows' => $draftRows, 'action' => $existingRun ? 'link' : 'create' );
            if ( $dryRun )
            {
                $report[$existingRun ? 'linked' : 'created']++;
                $report['threads'][] = $entry;
                continue;
            }
            $db->begin();
            try
            {
                if ( $existingRun )
                {
                    // an earlier run of the migration created the thread; only the link back was lost
                    $thread = expAiThread::fetchByID( (int) $existingRun->attribute( 'thread_id' ) );
                    $run = $existingRun;
                    $report['linked']++;
                }
                else
                {
                    $thread = null;
                    $run = null;
                }
                if ( !$thread )
                {
                    $title = self::firstRequest( $conversation );
                    $thread = new expAiThread( array(
                        'uuid' => expAiThread::newUuid(), 'user_id' => (int) $conversation->attribute( 'user_id' ),
                        'title' => expAiText::cut( expAiText::oneLine( $title ), $titleLength ), 'origin' => expAiThread::ORIGIN_PANEL,
                        'node_id' => (int) $conversation->attribute( 'node_id' ), 'run_mode' => 'write', 'review_policy' => 'direct',
                        'model_alias' => (string) $conversation->attribute( 'model_alias' ), 'provider' => (string) $conversation->attribute( 'provider' ),
                        'lane' => 'auto', 'state' => 'idle', 'shelf' => $shelf,
                        'created' => (int) $conversation->attribute( 'created' ), 'modified' => (int) $conversation->attribute( 'modified' ) ) );
                    $thread->setScope( array( 'nodes' => array( (int) $conversation->attribute( 'node_id' ) ) ) );
                    $thread->store();
                    $run = new expAiThreadRun( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'conversation_id' => $conversationID, 'kind' => 'turn',
                                                      'state' => 'idle', 'label' => $thread->attribute( 'title' ), 'model_alias' => (string) $conversation->attribute( 'model_alias' ),
                                                      'started' => (int) $conversation->attribute( 'created' ), 'finished' => (int) $conversation->attribute( 'modified' ),
                                                      'created' => (int) $conversation->attribute( 'created' ), 'modified' => (int) $conversation->attribute( 'modified' ) ) );
                    $run->store();
                    $thread->setAttribute( 'lead_run_id', (int) $run->attribute( 'id' ) );
                    if ( !$existingRun )
                        $report['created']++;
                }
                $threadID = (int) $thread->attribute( 'id' );
                $runID = (int) $run->attribute( 'id' );
                // the links, without rewriting the conversation's messages
                eZPersistentObject::updateObjectList( array( 'definition' => expAiConversation::definition(),
                                                             'update_fields' => array( 'thread_id' => $threadID, 'run_id' => $runID ),
                                                             'conditions' => array( 'id' => $conversationID ) ) );
                eZPersistentObject::updateObjectList( array( 'definition' => expAiUsageLog::definition(),
                                                             'update_fields' => array( 'thread_id' => $threadID, 'run_id' => $runID ),
                                                             'conditions' => array( 'conversation_id' => $conversationID, 'thread_id' => 0 ) ) );
                eZPersistentObject::updateObjectList( array( 'definition' => expAiDraftLog::definition(),
                                                             'update_fields' => array( 'thread_id' => $threadID ),
                                                             'conditions' => array( 'conversation_id' => $conversationID, 'thread_id' => 0 ) ) );
                $thread->refreshCost();
                $thread->store();
                $db->commit();
            }
            catch ( Throwable $e )
            {
                if ( $db->transactionCounter() > 0 )
                    $db->rollback();
                throw $e;
            }
            $entry['thread_id'] = $threadID;
            $entry['run_id'] = $runID;
            $report['threads'][] = $entry;
        }
        return $report;
    }

    /** @return int panel conversations without a thread */
    public static function pending()
    {
        if ( !expAiThread::available() )
            return 0;
        return (int) eZPersistentObject::count( expAiConversation::definition(), array( 'thread_id' => 0, 'node_id' => array( '>', 0 ) ) );
    }

    /** @return string the editor's first message of a conversation ('' when none) */
    protected static function firstRequest( expAiConversation $conversation )
    {
        $data = json_decode( (string) $conversation->attribute( 'messages' ), true );
        if ( is_array( $data ) && isset( $data['history'][0]['user'] ) )
            return (string) $data['history'][0]['user'];
        foreach ( $conversation->messages() as $message )
            if ( $message['role'] === 'user' && isset( $message['content'] ) )
                foreach ( (array) $message['content'] as $block )
                    if ( isset( $block['type'], $block['text'] ) && $block['type'] === 'text' )
                        return (string) $block['text'];
        return '';
    }
}
