<?php
/**
 * File containing the expAiThreadEvent class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The append-only event stream of a thread (table expai_thread_event). The id is the cursor a client reads from
 * (expai::events, "after"). Kinds: status, text, text_delta, step_start, step_end, question, plan, change, draft, cost,
 * checkpoint, followup, error. Payloads are JSON of at most [ThreadSettings] MaxEventPayloadBytes (16 KiB); bigger
 * ones keep their keys with long strings cut ("truncated": true). Events hold content text: they are pruned after
 * [ThreadSettings] EventKeepDays (expAiThreadRetention) and never written to logs.
 */
class expAiThreadEvent extends eZPersistentObject
{
    /** @var string[] */
    public static $kinds = array( 'status', 'text', 'text_delta', 'step_start', 'step_end', 'question', 'plan', 'change', 'draft', 'cost',
                                  'checkpoint', 'followup', 'error',
                                  // parallel runs (doc/parallel-runs.md): a child run started or ended; a field held by another run
                                  'run', 'held' );

    public static function definition()
    {
        $int = function ( $name ) { return array( 'name' => $name, 'datatype' => 'integer', 'default' => 0, 'required' => true ); };
        return array(
            'fields' => array(
                'id' => $int( 'ID' ),
                'thread_id' => $int( 'ThreadID' ),
                'run_id' => $int( 'RunID' ),
                'kind' => array( 'name' => 'Kind', 'datatype' => 'string', 'default' => '', 'required' => true ),
                'payload' => array( 'name' => 'Payload', 'datatype' => 'string', 'default' => '{}', 'required' => true ),
                'created' => $int( 'Created' ),
            ),
            'keys' => array( 'id' ),
            'increment_key' => 'id',
            'class_name' => 'expAiThreadEvent',
            'sort' => array( 'id' => 'asc' ),
            'name' => 'expai_thread_event',
        );
    }

    /** @return expAiThreadEvent stored */
    public static function append( $threadID, $runID, $kind, array $payload )
    {
        if ( !in_array( $kind, self::$kinds, true ) )
            $kind = 'status';
        $event = new self( array( 'thread_id' => (int) $threadID, 'run_id' => (int) $runID, 'kind' => $kind,
                                  'payload' => self::encode( $payload ), 'created' => time() ) );
        $event->store();
        return $event;
    }

    /** @return string JSON within the payload cap */
    public static function encode( array $payload )
    {
        $max = expAiConfig::instance()->int( 'ThreadSettings', 'MaxEventPayloadBytes', 16384, 1024, 65536 );
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = (string) json_encode( $payload, $flags );
        if ( strlen( $json ) <= $max )
            return $json;
        // a structural summary: the keys stay, long strings are cut, lists keep their first entries
        $cut = function ( $value, $budget ) use ( &$cut )
        {
            if ( is_string( $value ) )
                return expAiText::cut( $value, max( 40, $budget ) );
            if ( is_array( $value ) )
            {
                $out = array();
                foreach ( array_slice( $value, 0, 20, true ) as $k => $v )
                    $out[$k] = $cut( $v, (int) ( $budget / 2 ) );
                return $out;
            }
            return $value;
        };
        $summary = $cut( $payload, (int) ( $max / 4 ) );
        $summary['truncated'] = true;
        $json = (string) json_encode( $summary, $flags );
        return strlen( $json ) <= $max ? $json : json_encode( array( 'truncated' => true ) );
    }

    /** @return array the decoded payload */
    public function payload()
    {
        $data = json_decode( (string) $this->attribute( 'payload' ), true );
        return is_array( $data ) ? $data : array();
    }

    /**
     * Events of a thread after a cursor.
     * @return expAiThreadEvent[] oldest first, at most $limit
     */
    public static function fetchAfter( $threadID, $after = 0, $limit = 200 )
    {
        return (array) eZPersistentObject::fetchObjectList( self::definition(), null,
            array( 'thread_id' => (int) $threadID, 'id' => array( '>', (int) $after ) ), array( 'id' => 'asc' ),
            array( 'offset' => 0, 'length' => max( 1, min( 500, (int) $limit ) ) ) );
    }

    /** @return array for the browser: id, run_id, kind, created, payload */
    public function present()
    {
        return array( 'id' => (int) $this->attribute( 'id' ), 'run_id' => (int) $this->attribute( 'run_id' ), 'kind' => (string) $this->attribute( 'kind' ),
                      'created' => (int) $this->attribute( 'created' ), 'payload' => $this->payload() );
    }
}
