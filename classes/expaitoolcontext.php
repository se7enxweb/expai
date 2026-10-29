<?php
/**
 * File containing the expAiToolContext class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * What a tool knows about the call: the user (always eZUser::currentUser()), the node the panel is open on, the
 * conversation, the model, the editor's request (for the log) and the drafts written during this step.
 */
class expAiToolContext
{
    /** @var expAiConfig */
    public $config;

    /** @var expAiContentReader */
    public $reader;

    /** @var expAiContentWriter */
    public $writer;

    /** @var eZUser */
    public $user;

    /** @var int */
    public $nodeID;

    /** @var expAiConversation|null */
    public $conversation;

    /** @var string */
    public $model = '';

    /** @var string the provider id of the model */
    public $provider = '';

    /** @var string the model alias */
    public $modelAlias = '';

    /** @var string the editor's last message */
    public $request = '';

    /** @var array drafts written in this step: list of expAiDraftLog */
    public $written = array();

    /** @var bool a Vector thread with staged review: the write tools stage proposals (expAiChangeStager), never versions */
    public $staged = false;

    public function __construct( expAiConfig $config, $nodeID, $conversation = null, ?expAiDataPolicy $policy = null )
    {
        $this->config = $config;
        $this->reader = new expAiContentReader( $config, $policy );
        $this->writer = new expAiContentWriter( $config );
        $this->user = eZUser::currentUser();
        $this->nodeID = (int) $nodeID;
        $this->conversation = $conversation;
    }

    /** @return int drafts written in the conversation so far */
    public function draftCount()
    {
        return $this->conversation ? (int) $this->conversation->attribute( 'draft_count' ) : count( $this->written );
    }

    /** Counts a written draft against [AssistantSettings] MaxDraftsPerConversation */
    public function checkDraftLimit()
    {
        $max = $this->config->int( 'AssistantSettings', 'MaxDraftsPerConversation', 10, 1, 1000 );
        if ( $this->draftCount() >= $max )
            throw new expAiException( 'This conversation has reached its limit of %max drafts. Start a new conversation to write more.', '', 0, array( '%max' => $max ) );
    }

    public function addWritten( expAiDraftLog $log )
    {
        $this->written[] = $log;
        if ( $this->conversation )
            $this->conversation->setAttribute( 'draft_count', (int) $this->conversation->attribute( 'draft_count' ) + 1 );
    }
}
