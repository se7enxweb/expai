<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * update_draft: writes fields into a draft of an existing object: the draft this conversation wrote for it when
 * there is one, else a new version (content/edit of the user). Never publishes.
 */
class expAiToolUpdateDraft extends expAiTool
{
    public function writes()
    {
        return true;
    }

    public function description()
    {
        return 'Changes an existing object by writing fields into a DRAFT: the draft you created or changed earlier in this '
             . 'conversation, or else a new draft version (the published version stays as it is until the editor publishes). '
             . 'Only the fields you give are changed. Same value formats as create_draft. In a thread with staged review it stages '
             . 'a proposal for the editor\'s review instead (no draft yet); calling it again for the same object replaces the proposal.';
    }

    public function inputSchema()
    {
        return array( 'type' => 'object', 'properties' => array(
            'object_id' => array( 'type' => 'integer', 'description' => 'The object to change' ),
            'fields' => array( 'type' => 'object', 'description' => 'field identifier => value',
                               'additionalProperties' => array( 'type' => array( 'string', 'number', 'boolean', 'array' ) ) ),
            'language' => array( 'type' => 'string', 'description' => 'Language code (default: the object\'s main language)' ),
        ), 'required' => array( 'object_id', 'fields' ), 'additionalProperties' => false );
    }

    public function execute( array $input, expAiToolContext $context )
    {
        if ( !isset( $input['fields'] ) || !is_array( $input['fields'] ) || !$input['fields'] )
            throw new expAiException( 'Give the fields to write.' );
        $object = expAiObjects::fetch( isset( $input['object_id'] ) ? (int) $input['object_id'] : 0 );
        if ( !$object instanceof eZContentObject || !$object->canRead() )
            throw new expAiException( 'Object %id does not exist or you may not read it.', '', 0, array( '%id' => isset( $input['object_id'] ) ? (int) $input['object_id'] : 0 ) );
        $context->reader->assertAllowed( $object );
        // staged review (Vector): a proposal per field for the editor's review instead of a draft
        $stager = expAiChangeStager::forContext( $context );
        $this->staged = (bool) $stager;
        if ( $stager )
            return $stager->stageUpdate( $object, isset( $input['language'] ) ? (string) $input['language'] : '', $input['fields'] );
        // rich text that would lose structure (a placeholder missing, repeated, invented; fewer custom tags, embeds ...)
        // is never written: in a Vector thread it goes to the editor's review instead, elsewhere it is refused
        $held = $this->guardRichText( $object, $input, $context );
        $this->held = (bool) $held['fields'];
        foreach ( array_keys( $held['fields'] ) as $identifier )
            unset( $input['fields'][$identifier] );
        if ( !$input['fields'] )
        {
            $this->staged = (bool) $held['staged'];
            return array( 'status' => $held['staged'] ? 'nothing written: the rich text lost placeholders and was staged for the editor\'s review instead'
                                                      : 'nothing written: the rich text lost placeholders',
                          'object_id' => (int) $object->attribute( 'id' ), 'name' => (string) $object->attribute( 'name' ),
                          'written' => array(), 'held_for_review' => $held['staged'], 'errors' => $held['errors'] );
        }
        $reuse = 0;
        foreach ( expAiDraftLog::fetchForUserNode( $context->user->attribute( 'contentobject_id' ), $context->nodeID ) as $log )
        {
            if ( (int) $log->attribute( 'contentobject_id' ) === (int) $object->attribute( 'id' )
                 && in_array( $log->attribute( 'status' ), array( expAiDraftLog::STATUS_DRAFT, expAiDraftLog::STATUS_KEPT ), true ) )
            {
                $reuse = (int) $log->attribute( 'version' );
                break;
            }
        }
        $language = isset( $input['language'] ) ? (string) $input['language'] : '';
        list( $version, $language, $created ) = $context->writer->draftFor( $object, $language, $reuse );
        if ( $created )
            $context->checkDraftLimit();
        $written = $context->writer->writeFields( $object, $version, $language, $input['fields'] );
        $mainNode = $object->mainNode();
        if ( $created || !expAiDraftLog::fetchByVersion( $object->attribute( 'id' ), $version->attribute( 'version' ) ) )
        {
            $log = expAiDraftLog::record( array(
                'contentobject_id' => (int) $object->attribute( 'id' ), 'version' => (int) $version->attribute( 'version' ),
                'user_id' => (int) $context->user->attribute( 'contentobject_id' ), 'node_id' => $context->nodeID,
                'parent_node_id' => $mainNode ? (int) $mainNode->attribute( 'parent_node_id' ) : 0, 'language_code' => $language,
                'conversation_id' => $context->conversation ? (int) $context->conversation->attribute( 'id' ) : 0,
                'action' => 'update', 'source' => 'panel', 'model' => $context->model, 'provider' => $context->provider, 'prompt_summary' => $context->request ) );
            $context->addWritten( $log );
        }
        else
        {
            $log = expAiDraftLog::fetchByVersion( $object->attribute( 'id' ), $version->attribute( 'version' ) );
            $log->setAttribute( 'modified', time() );
            $log->store();
            $context->written[] = $log;
        }
        $out = array( 'status' => 'draft written (not published)', 'object_id' => (int) $object->attribute( 'id' ),
                      'version' => (int) $version->attribute( 'version' ), 'language' => $language, 'new_version' => $created,
                      'name' => (string) $object->versionLanguageName( (int) $version->attribute( 'version' ), $language ),
                      'written' => $written['written'], 'skipped' => $written['skipped'], 'errors' => $written['errors'] + $held['errors'] );
        if ( $held['staged'] )
            $out['held_for_review'] = $held['staged'];
        return $out;
    }

    /**
     * The rich text fields of a direct write that would lose structure (expAiContentWriter::checkRichText(), against
     * the published values), before any draft is made. In a Vector thread they are staged for the editor's review
     * (expAiChangeStager; the parts that would drop an element cannot be accepted there); elsewhere, or when staging
     * is refused, they are errors the model can fix.
     * @return array fields (identifier => guard), staged (identifier => reason), errors (identifier => reason)
     */
    protected function guardRichText( eZContentObject $object, array $input, expAiToolContext $context )
    {
        $out = array( 'fields' => array(), 'staged' => array(), 'errors' => array() );
        if ( !expAiRichTextTokens::enabled( $context->config ) )
            return $out;
        $language = isset( $input['language'] ) && (string) $input['language'] !== '' ? (string) $input['language'] : (string) $object->attribute( 'initial_language_code' );
        $datatypes = array();
        foreach ( (array) $object->contentClass()->fetchAttributes() as $classAttribute )
            $datatypes[(string) $classAttribute->attribute( 'identifier' )] = (string) $classAttribute->attribute( 'data_type_string' );
        foreach ( $input['fields'] as $identifier => $value )
        {
            if ( !isset( $datatypes[$identifier] ) || !is_scalar( $value ) || !expAiRichTextTokens::handles( $datatypes[$identifier], $context->config ) )
                continue;
            $guard = $context->writer->checkRichText( $object, (string) $identifier, $language, (string) $value );
            if ( !$guard['ok'] )
                $out['fields'][$identifier] = $guard;
        }
        if ( !$out['fields'] )
            return $out;
        $thread = $context->conversation && expAiThread::available() ? expAiThread::fetchForConversation( $context->conversation ) : null;
        if ( $thread )
        {
            try
            {
                $values = array();
                foreach ( array_keys( $out['fields'] ) as $identifier )
                    $values[$identifier] = $input['fields'][$identifier];
                ( new expAiChangeStager( $context, $thread ) )->stageUpdate( $object, $language, $values );
                foreach ( $out['fields'] as $identifier => $guard )
                    $out['staged'][$identifier] = $guard['reason_model'] . ' It was staged for the editor\'s review instead of being written; '
                                                . 'call update_draft again with every placeholder kept to write it.';
                return $out;
            }
            catch ( expAiException $e )
            {
                // staging refused (scope, bulk work, ...): the fields stay unwritten
            }
        }
        foreach ( $out['fields'] as $identifier => $guard )
            $out['errors'][$identifier] = 'not written: ' . $guard['reason_model'];
        return $out;
    }

    /** @var bool the last call staged a proposal (staged review) */
    protected $staged = false;

    /** @var bool the last call held rich text back because it would lose structure */
    protected $held = false;

    public function stepText( array $input )
    {
        if ( $this->held && !$this->staged )
            return 'Changed "%name"; rich text that would lose its structure was not written';
        return $this->staged ? 'Proposed changes to "%name" for review' : 'Wrote the draft "%name"';
    }

    public function stepArguments( array $input, $result )
    {
        return array( '%name' => isset( $result['name'] ) ? $result['name'] : '' );
    }
}
