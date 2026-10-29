<?php
/**
 * File containing the expAiBatch class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * One provider batch (table expai_batch): which provider, lane and model, the provider's id, the state and counts,
 * when it was submitted, ended, its results fetched and it was deleted at the provider. The requests of the batch
 * are the expqueue jobs of type expai.request whose external_ref is "expai-batch:<id>" (custom_id = job uuid).
 *
 * States: submitted, in_progress, canceling, ended, failed, expired, canceled; done (results applied).
 */
class expAiBatch extends eZPersistentObject
{
    const REF_PREFIX = 'expai-batch:';

    public static function definition()
    {
        $int = function ( $name ) { return array( 'name' => $name, 'datatype' => 'integer', 'default' => 0, 'required' => true ); };
        $str = function ( $name, $default = '' ) { return array( 'name' => $name, 'datatype' => 'string', 'default' => $default, 'required' => true ); };
        return array(
            'fields' => array(
                'id' => $int( 'ID' ),
                'created' => $int( 'Created' ),
                'modified' => $int( 'Modified' ),
                'provider' => $str( 'Provider' ),
                'lane' => $str( 'Lane' ),
                'model_alias' => $str( 'ModelAlias' ),
                'model' => $str( 'Model' ),
                'batch_api' => $str( 'BatchApi' ),
                'remote_id' => $str( 'RemoteID' ),
                'input_ref' => $str( 'InputRef' ),
                'output_ref' => $str( 'OutputRef' ),
                'state' => $str( 'State', 'submitted' ),
                'remote_status' => $str( 'RemoteStatus' ),
                'request_count' => $int( 'RequestCount' ),
                'succeeded' => $int( 'Succeeded' ),
                'errored' => $int( 'Errored' ),
                'expired' => $int( 'Expired' ),
                'canceled' => $int( 'Canceled' ),
                'submitted' => $int( 'Submitted' ),
                'ended' => $int( 'Ended' ),
                'expires_at' => $int( 'ExpiresAt' ),
                'results_fetched' => $int( 'ResultsFetched' ),
                'deleted' => $int( 'Deleted' ),
                'polls' => $int( 'Polls' ),
                'submit_job_uuid' => $str( 'SubmitJobUUID' ),
                'user_id' => $int( 'UserID' ),
                'retention' => $str( 'Retention' ),
                'last_error' => $str( 'LastError' ),
            ),
            'keys' => array( 'id' ),
            'increment_key' => 'id',
            'function_attributes' => array( 'ref' => 'ref', 'is_open' => 'isOpen' ),
            'class_name' => 'expAiBatch',
            'sort' => array( 'id' => 'desc' ),
            'name' => 'expai_batch',
        );
    }

    /** @return expAiBatch|null */
    public static function fetch( $id )
    {
        return eZPersistentObject::fetchObject( self::definition(), null, array( 'id' => (int) $id ), true );
    }

    /** @return expAiBatch|null the batch an external_ref of a job names */
    public static function fromRef( $ref )
    {
        return preg_match( '/^' . preg_quote( self::REF_PREFIX, '/' ) . '(\d+)$/', (string) $ref, $m ) ? self::fetch( (int) $m[1] ) : null;
    }

    /** @return expAiBatch[] the newest batches */
    public static function fetchRecent( $limit = 20, array $conds = array() )
    {
        return (array) eZPersistentObject::fetchObjectList( self::definition(), null, $conds ?: null, array( 'id' => 'desc' ),
                                                            array( 'offset' => 0, 'length' => (int) $limit ), true );
    }

    /** @return string the external_ref its jobs carry */
    public function ref()
    {
        return self::REF_PREFIX . (int) $this->attribute( 'id' );
    }

    /** @return bool the provider still works on it */
    public function isOpen()
    {
        return in_array( $this->attribute( 'state' ), array( 'submitted', 'in_progress', 'canceling' ), true );
    }

    /** @return string[] the custom ids (job uuids) of its requests, in the order they were submitted */
    public function customIds()
    {
        $db = eZDB::instance();
        $rows = $db->arrayQuery( "SELECT uuid FROM expqueue_job WHERE external_ref = '" . $db->escapeString( $this->ref() ) . "' ORDER BY id" );
        $out = array();
        foreach ( is_array( $rows ) ? $rows : array() as $row )
        {
            $row = array_change_key_case( $row, CASE_LOWER );
            $out[] = (string) $row['uuid'];
        }
        return $out;
    }

    /** Stores with the modification time. */
    public function touch( array $set = array() )
    {
        foreach ( $set as $name => $value )
            $this->setAttribute( $name, $value );
        $this->setAttribute( 'modified', time() );
        $this->store();
    }
}
