<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * create_draft: a new object as a draft of the current user under a node (content/create of the user). Never
 * publishes. Logged in expai_draft_log, so the draft carries the "Exponential Intelligence" label.
 */
class expAiToolCreateDraft extends expAiTool
{
    public function writes()
    {
        return true;
    }

    public function description()
    {
        return 'Creates a NEW content object as an unpublished draft owned by the editor, under parent_node_id (default: the open node). '
             . 'fields maps field identifiers (see get_class_definition) to values in the format of their datatype; rich text '
             . '(ezxmltext) is HTML using only p, h2, h3, h4, strong, em, ul, ol, li and a href. Fields you cannot write are '
             . 'reported as skipped. Nothing is published: the editor reviews the drafts and decides. Call it once per object. '
             . 'In a thread with staged review it stages a proposal for the editor\'s review instead (no draft yet); call it again '
             . 'with the returned new_key to revise that proposal.';
    }

    public function inputSchema()
    {
        return array( 'type' => 'object', 'properties' => array(
            'parent_node_id' => array( 'type' => 'integer', 'description' => 'Where to create it (default: the open node)' ),
            'class_identifier' => array( 'type' => 'string', 'description' => 'The class of the new object' ),
            'fields' => array( 'type' => 'object', 'description' => 'field identifier => value',
                               'additionalProperties' => array( 'type' => array( 'string', 'number', 'boolean', 'array' ) ) ),
            'language' => array( 'type' => 'string', 'description' => 'Language code, e.g. eng-GB (default: the default content language)' ),
            'new_key' => array( 'type' => 'string', 'description' => 'Staged review only: the new_key of an earlier proposal of this new object, to revise it' ),
        ), 'required' => array( 'class_identifier', 'fields' ), 'additionalProperties' => false );
    }

    public function execute( array $input, expAiToolContext $context )
    {
        if ( !isset( $input['fields'] ) || !is_array( $input['fields'] ) || !$input['fields'] )
            throw new expAiException( 'Give the fields to write.' );
        // staged review (Vector): a proposal per field for the editor's review instead of a draft
        $stager = expAiChangeStager::forContext( $context );
        $this->staged = (bool) $stager;
        if ( $stager )
        {
            $parent = $context->reader->readableNode( $this->nodeID( $input, $context, 'parent_node_id' ) );
            if ( $context->reader->policy() )
                $context->reader->policy()->assertClass( isset( $input['class_identifier'] ) ? (string) $input['class_identifier'] : '',
                                                         (int) $parent->object()->attribute( 'section_id' ) );
            $result = $stager->stageCreate( $parent, isset( $input['class_identifier'] ) ? (string) $input['class_identifier'] : '', $input['fields'],
                                            isset( $input['language'] ) ? (string) $input['language'] : '', isset( $input['new_key'] ) ? (string) $input['new_key'] : '' );
            $result['name'] = isset( $input['fields'] ) ? expAiChangeStager::nameOf( $input['fields'] ) : '';
            return $result;
        }
        $context->checkDraftLimit();
        $parent = $context->reader->readableNode( $this->nodeID( $input, $context, 'parent_node_id' ) );
        if ( $context->reader->policy() )
            $context->reader->policy()->assertClass( isset( $input['class_identifier'] ) ? (string) $input['class_identifier'] : '',
                                                     (int) $parent->object()->attribute( 'section_id' ) );
        list( $object, $version, $written ) = $context->writer->createDraft( $parent, (string) $input['class_identifier'], $input['fields'],
                                                                         isset( $input['language'] ) ? (string) $input['language'] : '' );
        $language = $version->initialLanguageCode();
        $log = expAiDraftLog::record( array(
            'contentobject_id' => (int) $object->attribute( 'id' ), 'version' => (int) $version->attribute( 'version' ),
            'user_id' => (int) $context->user->attribute( 'contentobject_id' ), 'node_id' => $context->nodeID,
            'parent_node_id' => (int) $parent->attribute( 'node_id' ), 'language_code' => $language,
            'conversation_id' => $context->conversation ? (int) $context->conversation->attribute( 'id' ) : 0,
            'action' => 'create', 'source' => 'panel', 'model' => $context->model, 'provider' => $context->provider, 'prompt_summary' => $context->request ) );
        $context->addWritten( $log );
        $object = eZContentObject::fetch( (int) $object->attribute( 'id' ) );
        return array( 'status' => 'draft created (not published)', 'object_id' => (int) $object->attribute( 'id' ),
                      'version' => (int) $version->attribute( 'version' ), 'language' => $language,
                      'name' => (string) $object->versionLanguageName( (int) $version->attribute( 'version' ), $language ),
                      'written' => $written['written'], 'skipped' => $written['skipped'], 'errors' => $written['errors'] );
    }

    /** @var bool the last call staged a proposal (staged review) */
    protected $staged = false;

    public function stepText( array $input )
    {
        return $this->staged ? 'Proposed the new object "%name" for review' : 'Created the draft "%name"';
    }

    public function stepArguments( array $input, $result )
    {
        return array( '%name' => isset( $result['name'] ) ? $result['name'] : '' );
    }
}
