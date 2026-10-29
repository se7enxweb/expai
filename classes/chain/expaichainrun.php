<?php
/**
 * File containing the expAiChainRun class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * One run of an AI chain (table expai_chain_run).
 *
 * state: running (a step is queued or running), waiting_human (the checkpoint: an editor approves, asks for changes
 *        or stops), held (the run's or the site's budget), done, failed, stopped
 * data:  JSON: document (field identifier => value, the text so far), review (the last review), seo (meta title,
 *        description, keywords), links (the link check), flags (what an editor should know at the checkpoint),
 *        note (the editor's request for changes), pending (the step a held run continues with), translate (the
 *        hand-off's outcome)
 */
class expAiChainRun extends eZPersistentObject
{
    const STATE_RUNNING = 'running';
    const STATE_WAITING = 'waiting_human';
    const STATE_HELD = 'held';
    const STATE_DONE = 'done';
    const STATE_FAILED = 'failed';
    const STATE_STOPPED = 'stopped';

    public static function definition()
    {
        $int = function ( $name ) { return array( 'name' => $name, 'datatype' => 'integer', 'default' => 0, 'required' => true ); };
        $str = function ( $name, $default = '' ) { return array( 'name' => $name, 'datatype' => 'string', 'default' => $default, 'required' => true ); };
        return array(
            'fields' => array(
                'id' => $int( 'ID' ),
                'uuid' => $str( 'UUID' ),
                'chain' => $str( 'Chain' ),
                'chain_hash' => $str( 'ChainHash' ),
                'label' => $str( 'Label' ),
                'user_id' => $int( 'UserID' ),
                'group_uuid' => $str( 'GroupUUID' ),
                'node_id' => $int( 'NodeID' ),
                'object_id' => $int( 'ObjectID' ),
                'version' => $int( 'Version' ),
                'language' => $str( 'Language' ),
                'class_identifier' => $str( 'ClassIdentifier' ),
                'brief' => $str( 'Brief' ),
                'item' => $int( 'Item' ),
                'item_count' => $int( 'ItemCount' ),
                'state' => $str( 'State', self::STATE_RUNNING ),
                'current_step' => $str( 'CurrentStep' ),
                'data' => $str( 'Data' ),
                'snapshot' => $str( 'Snapshot' ),
                'cost_micros' => $int( 'CostMicros' ),
                'input_tokens' => $int( 'InputTokens' ),
                'output_tokens' => $int( 'OutputTokens' ),
                'last_error' => $str( 'LastError' ),
                'created' => $int( 'Created' ),
                'modified' => $int( 'Modified' ),
                'finished' => $int( 'Finished' ),
            ),
            'keys' => array( 'id' ),
            'increment_key' => 'id',
            'function_attributes' => array( 'data_array' => 'dataArray', 'cost' => 'costMoney', 'state_label' => 'stateLabel',
                                            'is_final' => 'isFinal', 'node' => 'node', 'user' => 'user' ),
            'class_name' => 'expAiChainRun',
            'sort' => array( 'id' => 'desc' ),
            'name' => 'expai_chain_run',
        );
    }

    /** @return expAiChainRun|null by id or uuid */
    public static function fetch( $idOrUuid )
    {
        // #385 0 is no run (a run whose insert failed has no id; it must never be read as a stored row)
        if ( is_numeric( $idOrUuid ) && (int) $idOrUuid <= 0 )
            return null;
        $key = is_numeric( $idOrUuid ) ? array( 'id' => (int) $idOrUuid ) : array( 'uuid' => (string) $idOrUuid );
        $row = eZPersistentObject::fetchObject( self::definition(), null, $key );
        return $row instanceof self ? $row : null;
    }

    /**
     * @param array $conditions
     * @return expAiChainRun[]
     */
    public static function fetchList( array $conditions = array(), $offset = 0, $limit = 20 )
    {
        return (array) eZPersistentObject::fetchObjectList( self::definition(), null, $conditions ?: null, array( 'id' => 'desc' ),
                                                            array( 'offset' => (int) $offset, 'length' => (int) $limit ) );
    }

    /** @return int */
    public static function fetchCount( array $conditions = array() )
    {
        return (int) eZPersistentObject::count( self::definition(), $conditions ?: null );
    }

    /** @return array */
    public function dataArray()
    {
        $data = json_decode( (string) $this->attribute( 'data' ), true );
        return is_array( $data ) ? $data : array();
    }

    /** Merges values into data (null removes a key). */
    public function mergeData( array $values )
    {
        $data = $this->dataArray();
        foreach ( $values as $key => $value )
        {
            if ( $value === null )
                unset( $data[$key] );
            else
                $data[$key] = $value;
        }
        $this->setAttribute( 'data', json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
    }

    /** @return expAiChain the definition the run started with */
    public function chain()
    {
        $def = json_decode( (string) $this->attribute( 'snapshot' ), true );
        if ( !is_array( $def ) )
            throw new expAiException( 'The chain run cannot be continued.', 'run ' . $this->attribute( 'id' ) . ' has no snapshot' );
        return expAiChain::fromSnapshot( $def );
    }

    /** @return string */
    public function costMoney()
    {
        return expAiBudget::money( (int) $this->attribute( 'cost_micros' ) );
    }

    /** @return bool */
    public function isFinal()
    {
        return in_array( $this->attribute( 'state' ), array( self::STATE_DONE, self::STATE_FAILED, self::STATE_STOPPED ), true );
    }

    /** @return string */
    public function stateLabel()
    {
        return self::label( (string) $this->attribute( 'state' ) );
    }

    /** @return string the translated name of a run state */
    public static function label( $state )
    {
        $labels = array(
            self::STATE_RUNNING => ezpI18n::tr( 'extension/expai/chain', 'Running' ),
            self::STATE_WAITING => ezpI18n::tr( 'extension/expai/chain', 'Waiting for an editor' ),
            self::STATE_HELD => ezpI18n::tr( 'extension/expai/chain', 'Held by a budget' ),
            self::STATE_DONE => ezpI18n::tr( 'extension/expai/chain', 'Done' ),
            self::STATE_FAILED => ezpI18n::tr( 'extension/expai/chain', 'Failed' ),
            self::STATE_STOPPED => ezpI18n::tr( 'extension/expai/chain', 'Stopped' ),
        );
        return isset( $labels[$state] ) ? $labels[$state] : (string) $state;
    }

    /** @return eZContentObjectTreeNode|null the location the run writes under */
    public function node()
    {
        return eZContentObjectTreeNode::fetch( (int) $this->attribute( 'node_id' ) ) ?: null;
    }

    /** @return eZUser|null */
    public function user()
    {
        return eZUser::fetch( (int) $this->attribute( 'user_id' ) ) ?: null;
    }

    /** @return expAiChainStep[] the steps, oldest first */
    public function steps()
    {
        return (array) eZPersistentObject::fetchObjectList( expAiChainStep::definition(), null, array( 'run_id' => (int) $this->attribute( 'id' ) ),
                                                            array( 'id' => 'asc' ), null, true );
    }

    /** Stores with the modified time. */
    public function touch()
    {
        $this->setAttribute( 'modified', time() );
        $this->store();
    }

    /**
     * #385 a run is inserted once (without an id: the database gives it one) and updated by its id afterwards; a run
     * whose insert failed keeps the id 0, and storing it again would insert the row 0 (SQLite accepts an explicit 0 in
     * an AUTOINCREMENT key). Such a store is refused.
     */
    public function store( $fieldFilters = null )
    {
        // a new run has no id (null) until its insert; an id of 0 means that insert failed
        $id = $this->attribute( 'id' );
        if ( $id !== null && (string) $id !== '' && (int) $id <= 0 )
        {
            eZDebug::writeError( 'expai: the chain run ' . $this->attribute( 'uuid' ) . ' has no id (its insert failed); not stored', __METHOD__ );
            return;
        }
        parent::store( $fieldFilters );
    }
}
