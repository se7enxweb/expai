<?php
/**
 * File containing the expAiChangeStager class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Staged review (doc/review.md): in a Vector thread with Review=staged, create_draft and update_draft call this class
 * instead of writing a version. Each field becomes an expai_change: the value in storage form (rich text through the
 * kernel's input parser at staging time, so invalid markup is a tool error the model can fix), compared with the
 * published version (expAiDiff::compareValues()) into hunks the editor accepts or rejects. Nothing is written into
 * content here; expAiChangeApplier writes the accepted parts into drafts.
 *
 * A second proposal for the same object, language and field replaces the open one and keeps the decisions of hunks
 * that did not change (hunk ids are stable). Rules: the target lies in the thread's scope; the user may edit it (or
 * create under the parent); without an approved plan the thread may stage one object in one language
 * ([VectorSettings] BulkObjectThreshold, BulkLanguageThreshold): more is bulk work and needs propose_plan first.
 */
class expAiChangeStager
{
    /** @var string[] datatypes whose hunks the editor can edit in the review */
    public static $editable = array( 'ezstring', 'eztext' );

    /** @var expAiToolContext */
    protected $context;

    /** @var expAiThread */
    protected $thread;

    public function __construct( expAiToolContext $context, expAiThread $thread )
    {
        $this->context = $context;
        $this->thread = $thread;
    }

    /**
     * The stager of a tool call: when the context says the thread reviews staged changes.
     * @return expAiChangeStager|null
     */
    public static function forContext( expAiToolContext $context )
    {
        if ( empty( $context->staged ) || !$context->conversation || !expAiThread::available() )
            return null;
        $thread = expAiThread::fetchForConversation( $context->conversation );
        return $thread ? new self( $context, $thread ) : null;
    }

    /** @return expAiThread */
    public function thread()
    {
        return $this->thread;
    }

    /** @return string a readable name of a proposed new object: its first short text field */
    public static function nameOf( $fields )
    {
        foreach ( (array) $fields as $identifier => $value )
            if ( is_scalar( $value ) && in_array( (string) $identifier, array( 'title', 'name', 'short_title', 'short_name', 'headline' ), true ) && trim( (string) $value ) !== '' )
                return expAiText::cut( expAiText::oneLine( strip_tags( (string) $value ) ), 80 );
        foreach ( (array) $fields as $value )
            if ( is_scalar( $value ) && trim( (string) $value ) !== '' && mb_strlen( (string) $value, 'UTF-8' ) <= 120 )
                return expAiText::cut( expAiText::oneLine( strip_tags( (string) $value ) ), 80 );
        return '';
    }

    /* ---------------------------------------------------------------- scope */

    /** @return bool the node lies in the thread's scope (a scope node, or inside a scope subtree) */
    public static function nodeInScope( expAiThread $thread, eZContentObjectTreeNode $node, $asParent = false )
    {
        $scope = $thread->scope();
        if ( !$scope['nodes'] && !$scope['subtrees'] )
            return true;
        $id = (int) $node->attribute( 'node_id' );
        if ( in_array( $id, $scope['nodes'], true ) )
            return true;
        $path = (string) $node->attribute( 'path_string' );
        foreach ( $scope['subtrees'] as $subtree )
            if ( strpos( $path, '/' . (int) $subtree . '/' ) !== false )
                return true;
        return false;
    }

    /** @return bool one of the object's locations lies in the thread's scope (and its class, when classes are set) */
    public static function objectInScope( expAiThread $thread, eZContentObject $object )
    {
        $scope = $thread->scope();
        if ( $scope['classes'] && !in_array( (string) $object->attribute( 'class_identifier' ), $scope['classes'], true ) )
            return false;
        foreach ( (array) $object->assignedNodes( false ) as $row )
        {
            $node = eZContentObjectTreeNode::fetch( (int) $row['node_id'] );
            if ( $node && self::nodeInScope( $thread, $node ) )
                return true;
        }
        // an object that was never published has no location: the parents its draft is assigned to decide
        $version = $object->currentVersion();
        foreach ( $version ? (array) $version->nodeAssignments() : array() as $assignment )
        {
            $parent = eZContentObjectTreeNode::fetch( (int) $assignment->attribute( 'parent_node' ) );
            if ( $parent && self::nodeInScope( $thread, $parent, true ) )
                return true;
        }
        return false;
    }

    /** @return bool the language is in the scope (any when the scope names none) */
    public static function languageInScope( expAiThread $thread, $language )
    {
        $scope = $thread->scope();
        return !$scope['languages'] || in_array( (string) $language, $scope['languages'], true );
    }

    /** @return expAiPlan|null the approved plan of the thread (approved, running or done) */
    public static function approvedPlan( expAiThread $thread )
    {
        $plan = class_exists( 'expAiPlan' ) ? expAiPlan::fetchByID( (int) $thread->attribute( 'plan_id' ) ) : null;
        return $plan && in_array( $plan->attribute( 'state' ), array( 'approved', 'running', 'done' ), true ) ? $plan : null;
    }

    /**
     * Bulk work without an approved plan is refused: one object in one language may be staged at once.
     * @throws expAiException
     */
    protected function guardBulk( $targetKey, $language )
    {
        if ( self::approvedPlan( $this->thread ) )
            return;
        $config = $this->context->config;
        $objects = array( $targetKey => true );
        $languages = array( (string) $language => true );
        foreach ( expAiChange::fetchForThread( (int) $this->thread->attribute( 'id' ), array( 'pending', 'accepted', 'partial', 'conflict', 'applied' ) ) as $change )
        {
            $objects[$change->isNew() ? 'n' . $change->attribute( 'new_key' ) : 'o' . (int) $change->attribute( 'contentobject_id' )] = true;
            $languages[(string) $change->attribute( 'language_code' )] = true;
        }
        if ( count( $objects ) > $config->int( 'VectorSettings', 'BulkObjectThreshold', 1, 1 )
             || count( $languages ) > $config->int( 'VectorSettings', 'BulkLanguageThreshold', 1, 1 ) )
            throw new expAiException( 'This is bulk work (more than one object or language): propose a plan first with propose_plan; the editor approves it before anything more is staged.' );
    }

    /* --------------------------------------------------------------- values */

    /**
     * A value in storage form, checked as the datatype would check it.
     * @param eZContentObject $parserObject the object the rich text belongs to (the parent's object for a new one)
     * @return array( string|null value, string error )
     */
    public function storageValue( $datatype, $value, $classAttribute, eZContentObject $parserObject, ?array $sources = null, &$guard = null )
    {
        $guard = null;
        // rich text with placeholders: restored from $sources; an answer that loses structure is staged as far as it
        // can be restored, and its hunks that would drop an element cannot be accepted (expAiDiff "loses")
        if ( !is_array( $value ) && $value !== null && expAiRichTextTokens::handles( $datatype, $this->context->config ) )
        {
            $messages = array();
            $urlIDs = array();
            $xml = $this->context->writer->richTextToXml( $parserObject, (string) $value, $urlIDs, $messages, (array) $sources, $guard, true );
            if ( $xml === false )
                return array( null, 'the rich text could not be read: ' . implode( ' ', array_map( 'strval', (array) $messages ) ) );
            return array( (string) $xml, '' );
        }
        if ( is_array( $value ) )
            $value = $datatype === 'ezkeyword' ? implode( ', ', array_map( 'strval', $value ) ) : null;
        if ( $value === null )
            return array( null, 'the value must be a string, number or boolean' );
        switch ( $datatype )
        {
            case 'ezstring':
                $string = expAiText::oneLine( strip_tags( (string) $value ) );
                $max = $classAttribute ? (int) $classAttribute->attribute( 'data_int1' ) : 0;
                if ( $max > 0 && mb_strlen( $string, 'UTF-8' ) > $max )
                    return array( null, "longer than the maximum of $max characters" );
                return array( $string, '' );
            case 'eztext':
                $text = (string) $value;
                if ( preg_match( '/<\s*(p|br|h[1-6]|li)\b/i', $text ) )
                    $text = expAiText::htmlToText( $text );
                return array( trim( $text ), '' );
            case 'ezxmltext':
                $messages = array();
                $urlIDs = array();
                $xml = $this->context->writer->richTextToXml( $parserObject, (string) $value, $urlIDs, $messages );
                if ( $xml === false )
                    return array( null, 'the rich text could not be read: ' . implode( ' ', array_map( 'strval', (array) $messages ) ) );
                return array( (string) $xml, '' );
            case 'ezkeyword':
                return array( implode( ', ', array_filter( array_map( 'trim', explode( ',', strip_tags( (string) $value ) ) ) ) ), '' );
            case 'ezboolean':
                $bool = is_bool( $value ) ? $value : in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
                return array( $bool ? '1' : '0', '' );
            case 'ezinteger':
                return filter_var( $value, FILTER_VALIDATE_INT ) === false ? array( null, 'not an integer' ) : array( (string) (int) $value, '' );
            case 'ezfloat':
                return !is_numeric( $value ) ? array( null, 'not a number' ) : array( (string) (float) $value, '' );
            case 'ezdate':
            case 'ezdatetime':
                $time = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
                return !$time ? array( null, 'not a date (use YYYY-MM-DD or YYYY-MM-DD HH:MM)' ) : array( (string) $time, '' );
            case 'ezemail':
                $mail = trim( (string) $value );
                return $mail !== '' && !filter_var( $mail, FILTER_VALIDATE_EMAIL ) ? array( null, 'not an e-mail address' ) : array( $mail, '' );
            case 'ezurl':
                $parts = explode( '|', (string) $value, 2 );
                if ( !preg_match( '~^(https?://|mailto:)~i', trim( $parts[0] ) ) )
                    return array( null, 'not an http(s) or mailto URL' );
                return array( trim( $parts[0] ) . ( isset( $parts[1] ) ? '|' . trim( $parts[1] ) : '' ), '' );
        }
        return array( (string) $value, '' );
    }

    /**
     * The hunks of a proposal against a base, with the decisions of earlier hunks of the same ids carried over.
     * @return array list of hunks for hunks_json
     */
    public static function hunksFor( $datatype, $baseValue, $proposed, $language, $attribute, array $previous = array(), $newDecision = 'pending' )
    {
        $decided = array();
        foreach ( $previous as $hunk )
            if ( isset( $hunk['id'] ) )
                $decided[(string) $hunk['id']] = $hunk;
        $diff = expAiDiff::compareValues( $datatype, (string) $baseValue, (string) $proposed, array( 'language' => (string) $language, 'attribute' => (string) $attribute ) );
        $out = array();
        foreach ( $diff['hunks'] as $hunk )
        {
            $old = isset( $decided[$hunk['id']] ) ? $decided[$hunk['id']] : null;
            $out[] = array( 'id' => (string) $hunk['id'], 'op' => (string) $hunk['op'], 'base' => $hunk['base'], 'prop' => $hunk['prop'],
                            'base_text' => expAiText::cut( (string) $hunk['base_text'], 8000 ), 'prop_text' => expAiText::cut( (string) $hunk['prop_text'], 8000 ),
                            'context_before' => expAiText::cut( (string) $hunk['context_before'], 300 ), 'context_after' => expAiText::cut( (string) $hunk['context_after'], 300 ),
                            'mergeable' => (bool) $hunk['mergeable'], 'chars' => (int) $hunk['chars'],
                            'loses' => isset( $hunk['loses'] ) ? (array) $hunk['loses'] : array(),
                            'decision' => self::carriedDecision( $hunk, $old, $newDecision ),
                            'edited' => $old && !empty( $old['edited'] ) );
        }
        return $out;
    }

    /**
     * The decision a hunk gets when a proposal is compared again: the earlier one of the same hunk, else $newDecision;
     * a hunk that would drop elements ("loses") is never accepted, so an earlier "accepted" becomes "pending" (an
     * editor's "rejected" stays).
     * @return string
     */
    public static function carriedDecision( array $hunk, $old, $newDecision )
    {
        $decision = $old && isset( $old['decision'] ) ? (string) $old['decision'] : (string) $newDecision;
        return !empty( $hunk['loses'] ) && $decision === 'accepted' ? 'pending' : $decision;
    }

    /* -------------------------------------------------------------- staging */

    /**
     * update_draft in staged review: one change per field of an existing object.
     * @return array the tool result
     * @throws expAiException
     */
    public function stageUpdate( eZContentObject $object, $language, array $fields )
    {
        $language = (string) $language !== '' ? $this->context->writer->defaultLanguage( $language ) : (string) $object->attribute( 'initial_language_code' );
        if ( !self::objectInScope( $this->thread, $object ) )
            throw new expAiException( 'Object %id lies outside the scope of this thread.', '', 0, array( '%id' => (int) $object->attribute( 'id' ) ) );
        if ( !self::languageInScope( $this->thread, $language ) )
            throw new expAiException( 'The language %language is not in the scope of this thread.', '', 0, array( '%language' => $language ) );
        if ( !$object->canEdit( false, false, false, $language ) )
            throw new expAiException( 'You may not edit object %id in %language.', '', 0, array( '%id' => (int) $object->attribute( 'id' ), '%language' => $language ) );
        $this->guardBulk( 'o' . (int) $object->attribute( 'id' ), $language );
        $class = $object->contentClass();
        $base = array( 'version' => (int) $object->attribute( 'current_version' ) );
        $values = in_array( $language, array_keys( $object->allLanguages() ), true ) ? expAiDiff::values( $object, $base['version'], $language ) : array();
        $result = $this->stageFields( $class, $fields, $language, $object, (int) $object->attribute( 'id' ), '', 0, $values, $base['version'],
                                      $language !== (string) $object->attribute( 'initial_language_code' ) );
        return $result + array( 'object_id' => (int) $object->attribute( 'id' ), 'language' => $language,
                                'name' => (string) $object->attribute( 'name' ) );
    }

    /**
     * create_draft in staged review: one change per field of a proposed new object (grouped by new_key).
     * @param string $newKey the key of an earlier proposal of this object to replace ('' for a new one)
     * @return array the tool result
     * @throws expAiException
     */
    public function stageCreate( eZContentObjectTreeNode $parent, $classIdentifier, array $fields, $language, $newKey = '' )
    {
        $class = eZContentClass::fetchByIdentifier( (string) $classIdentifier );
        if ( !$class instanceof eZContentClass )
            throw new expAiException( 'There is no content class "%identifier".', '', 0, array( '%identifier' => (string) $classIdentifier ) );
        $language = $this->context->writer->defaultLanguage( $language );
        if ( !self::nodeInScope( $this->thread, $parent ) )
            throw new expAiException( 'Node %id lies outside the scope of this thread.', '', 0, array( '%id' => (int) $parent->attribute( 'node_id' ) ) );
        $scope = $this->thread->scope();
        if ( $scope['classes'] && !in_array( (string) $class->attribute( 'identifier' ), $scope['classes'], true ) )
            throw new expAiException( 'The class "%identifier" is not in the scope of this thread.', '', 0, array( '%identifier' => (string) $class->attribute( 'identifier' ) ) );
        if ( !self::languageInScope( $this->thread, $language ) )
            throw new expAiException( 'The language %language is not in the scope of this thread.', '', 0, array( '%language' => $language ) );
        $parentObject = $parent->object();
        if ( $parent->checkAccess( 'create', $class->attribute( 'id' ), $parentObject->attribute( 'contentclass_id' ), false, $language ) != 1 )
            throw new expAiException( 'You may not create %class objects in %language under this node.', '', 0,
                                      array( '%class' => (string) $class->attribute( 'identifier' ), '%language' => $language ) );
        $newKey = preg_match( '/^[a-f0-9]{8,32}$/', (string) $newKey ) ? (string) $newKey : '';
        if ( $newKey !== '' && !expAiChange::fetchNew( (int) $this->thread->attribute( 'id' ), $newKey ) )
            $newKey = '';
        if ( $newKey === '' )
            $newKey = bin2hex( random_bytes( 8 ) );
        $this->guardBulk( 'n' . $newKey, $language );
        $result = $this->stageFields( $class, $fields, $language, $parentObject, 0, $newKey, (int) $parent->attribute( 'node_id' ), array(), 0, false );
        return $result + array( 'new_key' => $newKey, 'language' => $language, 'parent_node_id' => (int) $parent->attribute( 'node_id' ),
                                'class_identifier' => (string) $class->attribute( 'identifier' ) );
    }

    /**
     * The fields of one object as changes.
     * @param array $values the base values (identifier => datatype, value) of the published version
     * @return array status, change_ids, staged, unchanged, skipped, errors
     */
    protected function stageFields( eZContentClass $class, array $fields, $language, eZContentObject $parserObject, $objectID, $newKey, $parentNodeID, array $values, $baseVersion, $translation )
    {
        if ( !$fields )
            throw new expAiException( 'Give the fields to write.' );
        $classAttributes = array();
        foreach ( (array) $class->fetchAttributes() as $attribute )
            $classAttributes[(string) $attribute->attribute( 'identifier' )] = $attribute;
        $unknown = array_diff( array_map( 'strval', array_keys( $fields ) ), array_keys( $classAttributes ) );
        if ( $unknown )
            throw new expAiException( 'Unknown field(s) %unknown. The fields of this class are: %valid.', '', 0,
                                      array( '%unknown' => implode( ', ', $unknown ), '%valid' => implode( ', ', array_keys( $classAttributes ) ) ) );
        $max = $this->context->config->int( 'VectorSettings', 'MaxStagedChanges', 500, 1 );
        $threadID = (int) $this->thread->attribute( 'id' );
        if ( count( expAiChange::fetchForThread( $threadID, expAiChange::$open, $max + 1 ) ) >= $max )
            throw new expAiException( 'This thread has %max staged changes waiting. Ask the editor to review them first.', '', 0, array( '%max' => $max ) );
        $writable = $this->context->config->writableDatatypes();
        $runID = $this->context->conversation ? (int) $this->context->conversation->attribute( 'run_id' ) : 0;
        $result = array( 'change_ids' => array(), 'staged' => array(), 'unchanged' => array(), 'skipped' => array(), 'errors' => array(), 'held' => array(), 'guarded' => array() );
        $now = time();
        $db = eZDB::instance();
        $db->begin();
        try
        {
            foreach ( $fields as $identifier => $value )
            {
                $identifier = (string) $identifier;
                $classAttribute = $classAttributes[$identifier];
                $datatype = (string) $classAttribute->attribute( 'data_type_string' );
                if ( !in_array( $datatype, $writable, true ) )
                {
                    $result['skipped'][$identifier] = "the datatype $datatype is not written by the assistant; the editor sets it in the edit form";
                    continue;
                }
                if ( $translation && !$classAttribute->attribute( 'can_translate' ) )
                {
                    $result['skipped'][$identifier] = 'not translatable: it keeps the value of the main language';
                    continue;
                }
                $baseValue = isset( $values[$identifier] ) ? (string) $values[$identifier]['value'] : '';
                $sources = $objectID ? $this->sources( $parserObject, $identifier, $baseValue, $language ) : array( $baseValue );
                list( $proposed, $error ) = $this->storageValue( $datatype, $value, $classAttribute, $parserObject, $sources, $guard );
                if ( $proposed === null )
                {
                    $result['errors'][$identifier] = $error;
                    continue;
                }
                if ( $guard && !$guard['ok'] )
                    $result['guarded'][$identifier] = $guard['reason_model'];
                $existing = expAiChange::fetchTarget( $threadID, $objectID, $newKey, $language, $identifier );
                // one field is staged by one run at a time (doc/parallel-runs.md): another run that still works holds it
                $holder = $objectID ? self::holder( $existing, $runID, $objectID, $language, $identifier ) : null;
                if ( $holder )
                {
                    $result['held'][$identifier] = 'held by ' . $holder->shortName();
                    continue;
                }
                if ( $proposed === $baseValue )
                {
                    // the proposal is the published value: nothing to review (an earlier proposal of the field goes)
                    if ( $existing )
                    {
                        $existing->setAttribute( 'decision', 'stale' );
                        $existing->setAttribute( 'modified', $now );
                        $existing->store();
                    }
                    $result['unchanged'][] = $identifier;
                    continue;
                }
                $hunks = self::hunksFor( $datatype, $baseValue, $proposed, $language, $identifier, $existing ? $existing->hunks() : array() );
                $change = $existing ?: new expAiChange( array( 'thread_id' => $threadID, 'contentobject_id' => (int) $objectID, 'new_key' => (string) $newKey,
                                                               'parent_node_id' => (int) $parentNodeID, 'class_identifier' => (string) $class->attribute( 'identifier' ),
                                                               'language_code' => (string) $language, 'attribute_identifier' => $identifier, 'created' => $now ) );
                $change->setAttribute( 'run_id', $runID );
                $change->setAttribute( 'datatype', $datatype );
                $change->setAttribute( 'base_version', (int) $baseVersion );
                $change->setAttribute( 'base_hash', expAiChange::hash( $baseValue ) );
                $change->setAttribute( 'proposed', $proposed );
                $change->setHunks( $hunks );
                $change->setAttribute( 'decision', 'pending' );
                $change->setAttribute( 'decision', $change->deriveDecision() );
                $change->setAttribute( 'modified', $now );
                $change->store();
                $result['change_ids'][] = (int) $change->attribute( 'id' );
                $result['staged'][] = $identifier;
            }
            if ( $result['change_ids'] )
                $this->thread->addEvent( 'change', array( 'object_id' => (int) $objectID, 'new_key' => (string) $newKey, 'language' => (string) $language,
                                                          'fields' => $result['staged'], 'changes' => $result['change_ids'] ), $runID );
            if ( $result['held'] )
                $this->thread->addEvent( 'held', array( 'object_id' => (int) $objectID, 'language' => (string) $language, 'fields' => $result['held'] ), $runID );
            // only the events and the time: other runs of the thread may change its state meanwhile
            if ( $result['change_ids'] || $result['held'] )
                $this->thread->storeActivity();
            $db->commit();
        }
        catch ( Throwable $e )
        {
            if ( $db->transactionCounter() > 0 )
                $db->rollback();
            throw $e;
        }
        if ( !$result['change_ids'] && !$result['unchanged'] && $result['held'] )
            throw new expAiException( 'Not staged: another run is changing these fields (%fields). Leave them alone: do the rest of your item, or end your turn and say which fields were held.',
                                      '', 0, array( '%fields' => implode( '; ', array_map( function ( $k, $v ) { return "$k $v"; }, array_keys( $result['held'] ), $result['held'] ) ) ) );
        if ( !$result['change_ids'] && !$result['unchanged'] && $result['errors'] )
            throw new expAiException( 'Nothing was staged: %errors', '', 0, array( '%errors' => implode( '; ', array_map( function ( $k, $v ) { return "$k: $v"; },
                                                                                                                       array_keys( $result['errors'] ), $result['errors'] ) ) ) );
        $result['status'] = $result['change_ids']
            ? 'staged for the editor\'s review (nothing is written until the editor accepts and applies it)'
              . ( $result['held'] ? '; the fields under "held" are being changed by another run and were not staged' : '' )
              . ( $result['guarded'] ? '; the rich text fields under "guarded" lost placeholders: the parts that would drop an element cannot be accepted. '
                                       . 'Call the tool again for this object with every placeholder kept exactly once' : '' )
            : 'nothing to stage: the values equal the published version';
        if ( !$result['guarded'] )
            unset( $result['guarded'] );
        return $result;
    }

    /**
     * The values a rich text answer for an existing object may take its placeholders from: the published value in the
     * language, the published values in the other languages, then the open proposals of this thread for the field in
     * other languages (a translation that works from a staged source text).
     * @return string[]
     */
    protected function sources( eZContentObject $object, $identifier, $baseValue, $language )
    {
        $sources = expAiContentWriter::richTextSources( $object, $identifier, $baseValue );
        $changes = eZPersistentObject::fetchObjectList( expAiChange::definition(), null,
            array( 'thread_id' => (int) $this->thread->attribute( 'id' ), 'contentobject_id' => (int) $object->attribute( 'id' ),
                   'attribute_identifier' => (string) $identifier, 'decision' => array( array( 'pending', 'accepted', 'partial', 'applied' ) ) ),
            array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => 20 ) );
        foreach ( (array) $changes as $change )
            if ( (string) $change->attribute( 'language_code' ) !== (string) $language )
                $sources[] = (string) $change->attribute( 'proposed' );
        return array_values( array_unique( $sources ) );
    }

    /**
     * The run that holds a field: a run other than this one that still works (queued, running, waiting) and has an
     * open proposal for the same object, language and field, in this thread or another (doc/parallel-runs.md).
     * @param expAiChange|null $existing the open change of this thread for the field
     * @return expAiThreadRun|null
     */
    public static function holder( $existing, $runID, $objectID, $language, $identifier )
    {
        $candidates = $existing ? array( $existing ) : array();
        $others = eZPersistentObject::fetchObjectList( expAiChange::definition(), null,
            array( 'contentobject_id' => (int) $objectID, 'language_code' => (string) $language, 'attribute_identifier' => (string) $identifier,
                   'decision' => array( array( 'pending', 'accepted', 'partial' ) ) ), array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => 20 ) );
        foreach ( array_merge( $candidates, (array) $others ) as $change )
        {
            $other = (int) $change->attribute( 'run_id' );
            if ( !$other || $other === (int) $runID )
                continue;
            $run = expAiThreadRun::fetchByID( $other );
            // a run that stopped reporting (a closed browser window) holds nothing after [ThreadSettings] StaleRunSeconds
            if ( $run && $run->isActive()
                 && (int) $run->attribute( 'modified' ) >= time() - expAiConfig::instance()->int( 'ThreadSettings', 'StaleRunSeconds', 900, 60 ) )
                return $run;
        }
        return null;
    }

    /* -------------------------------------------------- after the editor */

    /**
     * Compares a change again with the current published value (the base changed since staging): new hunks, the
     * decisions of hunks that are still there kept, the conflict cleared.
     * @return expAiChange
     */
    public static function restage( expAiChange $change )
    {
        $base = $change->currentBase();
        if ( !$base['exists'] )
            throw new expAiException( 'The object of this change no longer exists.' );
        $hunks = self::hunksFor( (string) $change->attribute( 'datatype' ), $base['value'], (string) $change->attribute( 'proposed' ),
                                 (string) $change->attribute( 'language_code' ), (string) $change->attribute( 'attribute_identifier' ), $change->hunks() );
        $change->setAttribute( 'base_version', (int) $base['version'] );
        $change->setAttribute( 'base_hash', expAiChange::hash( $base['value'] ) );
        $change->setHunks( $hunks );
        $change->setAttribute( 'decision', $hunks ? 'pending' : 'stale' );
        if ( $hunks )
            $change->setAttribute( 'decision', $change->deriveDecision() );
        $change->setAttribute( 'modified', time() );
        $change->store();
        return $change;
    }

    /**
     * The editor rewrites the proposed side of a hunk (plain text datatypes): the proposal is rebuilt and compared
     * again; the hunks the edit produced count as accepted.
     * @return expAiChange
     */
    public static function editHunk( expAiChange $change, $hunkID, $text )
    {
        $datatype = (string) $change->attribute( 'datatype' );
        if ( !in_array( $datatype, self::$editable, true ) )
            throw new expAiException( 'This field cannot be edited here; accept it and edit the draft in the edit form.' );
        if ( !in_array( $change->attribute( 'decision' ), array( 'pending', 'accepted', 'rejected', 'partial' ), true ) )
            throw new expAiException( 'This change can no longer be edited.' );
        if ( $change->baseChanged() )
            throw new expAiException( 'The content changed since this was proposed. Review it against the current version first.' );
        $target = null;
        foreach ( $change->hunks() as $hunk )
            if ( (string) $hunk['id'] === (string) $hunkID )
                $target = $hunk;
        if ( !$target || empty( $target['prop'] ) )
            throw new expAiException( 'This part of the change cannot be edited.' );
        $text = trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) );
        $language = (string) $change->attribute( 'language_code' );
        if ( $datatype === 'ezstring' )
            $proposed = expAiText::oneLine( $text );
        else
        {
            $segmenter = expAiSegmenterRegistry::forDatatype( $datatype );
            $pieces = array();
            foreach ( $segmenter->segment( (string) $change->attribute( 'proposed' ), array( 'language' => $language ) ) as $block )
                $pieces[(int) $block['index']] = (string) $block['text'];
            $first = (int) min( $target['prop'] );
            $out = array();
            $placed = false;
            foreach ( $pieces as $i => $piece )
            {
                if ( in_array( $i, $target['prop'] ) )
                {
                    // the edited text takes the place of the hunk's first proposed block
                    if ( $i === $first && $text !== '' )
                        $out[] = $text;
                    $placed = $placed || $i === $first;
                    continue;
                }
                $out[] = $piece;
            }
            if ( !$placed && $text !== '' )
                $out[] = $text;
            $proposed = implode( "\n\n", $out );
        }
        $base = $change->currentBase();
        $old = $change->hunks();
        $ids = array_map( function ( $h ) { return (string) $h['id']; }, $old );
        $hunks = self::hunksFor( $datatype, $base['value'], $proposed, $language, (string) $change->attribute( 'attribute_identifier' ), $old );
        foreach ( $hunks as $i => $hunk )
            if ( !in_array( $hunk['id'], $ids, true ) )
            {
                $hunks[$i]['decision'] = 'accepted';
                $hunks[$i]['edited'] = true;
            }
        $change->setAttribute( 'proposed', $proposed );
        $change->setHunks( $hunks );
        $change->setAttribute( 'decision', $hunks ? 'pending' : 'stale' );
        if ( $hunks )
            $change->setAttribute( 'decision', $change->deriveDecision() );
        $change->setAttribute( 'modified', time() );
        $change->store();
        return $change;
    }
}
