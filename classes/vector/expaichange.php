<?php
/**
 * File containing the expAiChange class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A staged proposal (table expai_change, doc/review.md): one object x language x attribute that a write tool of a
 * thread with staged review proposed instead of writing it. A new object has contentobject_id 0; its fields share a
 * new_key, with parent_node_id and class_identifier.
 *
 *   base_version, base_hash   the published version the proposal was compared with and the sha256 of its value (a
 *                             different hash at apply time is a conflict)
 *   proposed                  the value in storage form (ezxmltext XML, other datatypes as fromString() takes them)
 *   hunks_json                the hunks of expAiDiff::compareValues() (id, op, base_text, prop_text, context, chars,
 *                             mergeable) with the editor's decision per hunk: pending, accepted, rejected
 *   decision                  pending (a hunk waits), accepted, rejected, partial (some accepted), applied, conflict,
 *                             stale (replaced by a newer proposal or a rebase)
 */
class expAiChange extends eZPersistentObject
{
    /** @var string[] decisions that can still be reviewed or applied */
    public static $open = array( 'pending', 'accepted', 'rejected', 'partial', 'conflict' );

    public static function definition()
    {
        $int = function ( $name ) { return array( 'name' => $name, 'datatype' => 'integer', 'default' => 0, 'required' => true ); };
        $str = function ( $name, $default = '' ) { return array( 'name' => $name, 'datatype' => 'string', 'default' => $default, 'required' => true ); };
        return array(
            'fields' => array(
                'id' => $int( 'ID' ),
                'thread_id' => $int( 'ThreadID' ),
                'run_id' => $int( 'RunID' ),
                'contentobject_id' => $int( 'ContentObjectID' ),
                'new_key' => $str( 'NewKey' ),
                'parent_node_id' => $int( 'ParentNodeID' ),
                'class_identifier' => $str( 'ClassIdentifier' ),
                'language_code' => $str( 'LanguageCode' ),
                'attribute_identifier' => $str( 'AttributeIdentifier' ),
                'datatype' => $str( 'Datatype' ),
                'base_version' => $int( 'BaseVersion' ),
                'base_hash' => $str( 'BaseHash' ),
                'proposed' => $str( 'Proposed' ),
                'hunks_json' => $str( 'HunksJSON', '[]' ),
                'decision' => $str( 'Decision', 'pending' ),
                'applied_version' => $int( 'AppliedVersion' ),
                'decided_by' => $int( 'DecidedBy' ),
                'decided' => $int( 'Decided' ),
                'created' => $int( 'Created' ),
                'modified' => $int( 'Modified' ),
            ),
            'keys' => array( 'id' ),
            'increment_key' => 'id',
            'class_name' => 'expAiChange',
            'sort' => array( 'id' => 'asc' ),
            'name' => 'expai_change',
        );
    }

    /** @return expAiChange|null */
    public static function fetchByID( $id )
    {
        if ( !(int) $id )
            return null;
        $object = eZPersistentObject::fetchObject( self::definition(), null, array( 'id' => (int) $id ) );
        return $object instanceof self ? $object : null;
    }

    /**
     * @param array|null $decisions only these decisions (default: the open ones and applied)
     * @return expAiChange[] the changes of a thread, oldest first
     */
    public static function fetchForThread( $threadID, $decisions = null, $limit = 500 )
    {
        $conds = array( 'thread_id' => (int) $threadID );
        $conds['decision'] = array( array_values( $decisions === null ? array_merge( self::$open, array( 'applied' ) ) : (array) $decisions ) );
        return (array) eZPersistentObject::fetchObjectList( self::definition(), null, $conds, array( 'id' => 'asc' ),
                                                            array( 'offset' => 0, 'length' => max( 1, (int) $limit ) ) );
    }

    /**
     * The open change of a thread for one target field (an existing object or a new one by its key).
     * @return expAiChange|null
     */
    public static function fetchTarget( $threadID, $objectID, $newKey, $language, $attribute )
    {
        $conds = array( 'thread_id' => (int) $threadID, 'contentobject_id' => (int) $objectID, 'new_key' => (string) $newKey,
                        'language_code' => (string) $language, 'attribute_identifier' => (string) $attribute,
                        'decision' => array( self::$open ) );
        $list = eZPersistentObject::fetchObjectList( self::definition(), null, $conds, array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => 1 ) );
        return $list ? $list[0] : null;
    }

    /** @return expAiChange[] the open changes of a proposed new object of a thread */
    public static function fetchNew( $threadID, $newKey )
    {
        return (array) eZPersistentObject::fetchObjectList( self::definition(), null,
            array( 'thread_id' => (int) $threadID, 'contentobject_id' => 0, 'new_key' => (string) $newKey, 'decision' => array( self::$open ) ),
            array( 'id' => 'asc' ), array( 'offset' => 0, 'length' => 200 ) );
    }

    /** @return array the hunks with their decisions */
    public function hunks()
    {
        $hunks = json_decode( (string) $this->attribute( 'hunks_json' ), true );
        return is_array( $hunks ) ? $hunks : array();
    }

    public function setHunks( array $hunks )
    {
        $this->setAttribute( 'hunks_json', json_encode( array_values( $hunks ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) );
    }

    /** @return bool the change belongs to a new object */
    public function isNew()
    {
        return !(int) $this->attribute( 'contentobject_id' );
    }

    /** @return string the target of the change: o<object id> or n<new key>, with the language */
    public function targetKey()
    {
        return ( $this->isNew() ? 'n' . $this->attribute( 'new_key' ) : 'o' . (int) $this->attribute( 'contentobject_id' ) ) . '|' . $this->attribute( 'language_code' );
    }

    /** @return string[] ids of the accepted hunks */
    public function acceptedHunkIDs()
    {
        $out = array();
        foreach ( $this->hunks() as $hunk )
            if ( isset( $hunk['decision'] ) && $hunk['decision'] === 'accepted' )
                $out[] = (string) $hunk['id'];
        return $out;
    }

    /**
     * The decision of the change from its hunks: pending while one waits, accepted or rejected when all agree,
     * partial when some are accepted. Applied, conflict and stale stay.
     * @return string
     */
    public function deriveDecision()
    {
        if ( in_array( $this->attribute( 'decision' ), array( 'applied', 'stale' ), true ) )
            return (string) $this->attribute( 'decision' );
        $counts = array( 'pending' => 0, 'accepted' => 0, 'rejected' => 0 );
        foreach ( $this->hunks() as $hunk )
        {
            $d = isset( $hunk['decision'] ) && isset( $counts[$hunk['decision']] ) ? $hunk['decision'] : 'pending';
            $counts[$d]++;
        }
        if ( $counts['pending'] || !array_sum( $counts ) )
            return 'pending';
        if ( !$counts['rejected'] )
            return 'accepted';
        return $counts['accepted'] ? 'partial' : 'rejected';
    }

    /**
     * Sets the decision of hunks (all when $hunkID is ''), then the change's decision.
     * @return int hunks changed
     */
    public function decide( $hunkID, $decision, $userID )
    {
        if ( !in_array( $decision, array( 'pending', 'accepted', 'rejected' ), true ) )
            throw new expAiException( 'Unknown decision.' );
        $hunks = $this->hunks();
        $n = 0;
        foreach ( $hunks as $i => $hunk )
        {
            if ( $hunkID !== '' && (string) $hunk['id'] !== (string) $hunkID )
                continue;
            $want = $decision;
            // a part that would drop custom tags, embeds, ... is never written: accepting the whole field rejects it
            if ( $decision === 'accepted' && !empty( $hunk['loses'] ) )
            {
                if ( $hunkID !== '' )
                    throw new expAiException( 'This part would remove %items from the text and cannot be accepted. Reject it, or ask for a new proposal that keeps them.',
                                              'hunk loses structure', 0, array( '%items' => expAiRichTextTokens::describe( (array) $hunk['loses'] ) ) );
                $want = 'rejected';
            }
            if ( !isset( $hunk['decision'] ) || $hunk['decision'] !== $want )
            {
                $hunks[$i]['decision'] = $want;
                $n++;
            }
        }
        $this->setHunks( $hunks );
        // a conflict decided by the editor ("keep current" = reject) leaves the conflict state
        if ( $this->attribute( 'decision' ) === 'conflict' && $decision === 'rejected' && $hunkID === '' )
            $this->setAttribute( 'decision', 'rejected' );
        elseif ( $this->attribute( 'decision' ) !== 'conflict' )
            $this->setAttribute( 'decision', $this->deriveDecision() );
        $this->setAttribute( 'decided_by', (int) $userID );
        $this->setAttribute( 'decided', time() );
        $this->setAttribute( 'modified', time() );
        return $n;
    }

    /** @return string sha256 of a value (the base at staging time and at apply time) */
    public static function hash( $value )
    {
        return hash( 'sha256', (string) $value );
    }

    /**
     * The current base value of the change: the attribute of the object's published version in the language ('' when
     * the language does not exist yet, or for a new object).
     * @return array value, version, exists (the object is still there)
     */
    public function currentBase()
    {
        if ( $this->isNew() )
            return array( 'value' => '', 'version' => 0, 'exists' => true );
        $object = expAiObjects::fetch( (int) $this->attribute( 'contentobject_id' ) );
        if ( !$object instanceof eZContentObject )
            return array( 'value' => '', 'version' => 0, 'exists' => false );
        return self::baseOf( $object, (string) $this->attribute( 'language_code' ), (string) $this->attribute( 'attribute_identifier' ) );
    }

    /** @return array value, version, exists: an attribute of the published version of an object in a language */
    public static function baseOf( eZContentObject $object, $language, $identifier )
    {
        $version = (int) $object->attribute( 'current_version' );
        $values = in_array( $language, array_keys( $object->allLanguages() ), true ) ? expAiDiff::values( $object, $version, $language ) : array();
        return array( 'value' => isset( $values[$identifier] ) ? (string) $values[$identifier]['value'] : '', 'version' => $version, 'exists' => true );
    }

    /** @return bool the base changed since the change was staged */
    public function baseChanged()
    {
        if ( $this->isNew() )
            return false;
        $base = $this->currentBase();
        return !$base['exists'] || self::hash( $base['value'] ) !== (string) $this->attribute( 'base_hash' );
    }

    /**
     * Counts of a thread's changes: pending hunks, changes per decision.
     * @return array pending_hunks, open (changes not applied, rejected or stale), accepted (changes with accepted hunks), applied, conflict
     */
    public static function counts( $threadID )
    {
        $out = array( 'pending_hunks' => 0, 'open' => 0, 'accepted' => 0, 'applied' => 0, 'conflict' => 0, 'total' => 0 );
        foreach ( self::fetchForThread( $threadID ) as $change )
        {
            $decision = (string) $change->attribute( 'decision' );
            $out['total']++;
            if ( $decision === 'applied' )
            {
                $out['applied']++;
                continue;
            }
            if ( $decision === 'conflict' )
                $out['conflict']++;
            if ( $decision !== 'rejected' )
                $out['open']++;
            if ( in_array( $decision, array( 'accepted', 'partial' ), true ) )
                $out['accepted']++;
            if ( $decision !== 'conflict' )
                foreach ( $change->hunks() as $hunk )
                    if ( !isset( $hunk['decision'] ) || $hunk['decision'] === 'pending' )
                        $out['pending_hunks']++;
        }
        return $out;
    }

    /** @return array the change for the browser (texts only, never markup) */
    public function present()
    {
        $hunks = array();
        foreach ( $this->hunks() as $hunk )
            $hunks[] = array( 'id' => (string) $hunk['id'], 'op' => (string) $hunk['op'], 'decision' => isset( $hunk['decision'] ) ? (string) $hunk['decision'] : 'pending',
                              'base_text' => (string) $hunk['base_text'], 'prop_text' => (string) $hunk['prop_text'],
                              'context_before' => isset( $hunk['context_before'] ) ? (string) $hunk['context_before'] : '',
                              'context_after' => isset( $hunk['context_after'] ) ? (string) $hunk['context_after'] : '',
                              'mergeable' => !isset( $hunk['mergeable'] ) || $hunk['mergeable'], 'edited' => !empty( $hunk['edited'] ),
                              'loses' => !empty( $hunk['loses'] ) ? expAiRichTextTokens::describe( (array) $hunk['loses'] ) : '',
                              'editable' => in_array( $this->attribute( 'datatype' ), expAiChangeStager::$editable, true ) && (string) $hunk['prop_text'] !== '' );
        return array( 'id' => (int) $this->attribute( 'id' ), 'attribute' => (string) $this->attribute( 'attribute_identifier' ),
                      'datatype' => (string) $this->attribute( 'datatype' ), 'decision' => (string) $this->attribute( 'decision' ),
                      'applied_version' => (int) $this->attribute( 'applied_version' ), 'base_version' => (int) $this->attribute( 'base_version' ),
                      'run_id' => (int) $this->attribute( 'run_id' ), 'hunks' => $hunks );
    }
}
