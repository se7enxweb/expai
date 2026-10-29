<?php
/**
 * File containing the expAiPlan class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A plan of a thread (table expai_plan, doc/plan-mode.md): what propose_plan stored for the editor to approve.
 *
 *   items_json     list of { action (create, update, translate), target { node_id, object_id, parent_node_id }, target_name,
 *                  class, languages, fields, summary, refused (why the item cannot run, '' when it can), selected }
 *   estimate_json  the estimate (expAiPlanEstimate) plus meta: assumptions, questions, risks, model; after the run
 *                  actual_micros and the thread's cost at approval (start_micros)
 *   state          proposed, approved, rejected, superseded, running, done
 */
class expAiPlan extends eZPersistentObject
{
    public static function definition()
    {
        $int = function ( $name ) { return array( 'name' => $name, 'datatype' => 'integer', 'default' => 0, 'required' => true ); };
        $str = function ( $name, $default = '' ) { return array( 'name' => $name, 'datatype' => 'string', 'default' => $default, 'required' => true ); };
        return array(
            'fields' => array(
                'id' => $int( 'ID' ),
                'thread_id' => $int( 'ThreadID' ),
                'items_json' => $str( 'ItemsJSON', '[]' ),
                'estimate_json' => $str( 'EstimateJSON', '{}' ),
                'state' => $str( 'State', 'proposed' ),
                'approved_by' => $int( 'ApprovedBy' ),
                'approved' => $int( 'Approved' ),
                'created' => $int( 'Created' ),
                'modified' => $int( 'Modified' ),
            ),
            'keys' => array( 'id' ),
            'increment_key' => 'id',
            'class_name' => 'expAiPlan',
            'sort' => array( 'id' => 'desc' ),
            'name' => 'expai_plan',
        );
    }

    /** @return expAiPlan|null */
    public static function fetchByID( $id )
    {
        if ( !(int) $id )
            return null;
        $object = eZPersistentObject::fetchObject( self::definition(), null, array( 'id' => (int) $id ) );
        return $object instanceof self ? $object : null;
    }

    /** @return expAiPlan|null the newest plan of a thread that is not superseded */
    public static function fetchCurrent( $threadID )
    {
        $list = eZPersistentObject::fetchObjectList( self::definition(), null,
            array( 'thread_id' => (int) $threadID, 'state' => array( array( 'proposed', 'approved', 'running', 'done', 'rejected' ) ) ),
            array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => 1 ) );
        return $list ? $list[0] : null;
    }

    /** @return expAiPlan[] the newest finished plans (for the calibration of estimates) */
    public static function fetchDone( $limit = 20 )
    {
        return (array) eZPersistentObject::fetchObjectList( self::definition(), null, array( 'state' => 'done' ), array( 'id' => 'desc' ),
                                                            array( 'offset' => 0, 'length' => max( 1, (int) $limit ) ) );
    }

    /** @return array */
    public function items()
    {
        $items = json_decode( (string) $this->attribute( 'items_json' ), true );
        return is_array( $items ) ? $items : array();
    }

    public function setItems( array $items )
    {
        $this->setAttribute( 'items_json', json_encode( array_values( $items ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) );
    }

    /** @return array */
    public function estimate()
    {
        $estimate = json_decode( (string) $this->attribute( 'estimate_json' ), true );
        return is_array( $estimate ) ? $estimate : array();
    }

    public function setEstimate( array $estimate )
    {
        $this->setAttribute( 'estimate_json', json_encode( $estimate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) );
    }

    /** @return array[] the items that will run: selected and not refused */
    public function runnableItems()
    {
        return array_values( array_filter( $this->items(), function ( $item ) { return !empty( $item['selected'] ) && empty( $item['refused'] ); } ) );
    }

    /** @return array the plan for the browser */
    public function present()
    {
        $estimate = $this->estimate();
        return array( 'id' => (int) $this->attribute( 'id' ), 'state' => (string) $this->attribute( 'state' ), 'items' => $this->items(),
                      'estimate' => $estimate, 'created' => (int) $this->attribute( 'created' ), 'approved' => (int) $this->attribute( 'approved' ),
                      'meta' => isset( $estimate['meta'] ) ? $estimate['meta'] : array() );
    }
}
