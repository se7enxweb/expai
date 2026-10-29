<?php
/**
 * File containing the expAiFollowup class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A message queued while a run works (table expai_followup). delivery "queue": sent as the next message when the
 * turn ends; "steer": delivered at the next call boundary of the lead run, as text after the tool results of its next
 * step (takeSteers(), foreground and background alike); a turn that ends in words first makes it a queued follow-up.
 * state: waiting, sent, dropped. In foreground mode Vector sends the first waiting follow-up itself when a turn ends.
 */
class expAiFollowup extends eZPersistentObject
{
    public static function definition()
    {
        $int = function ( $name ) { return array( 'name' => $name, 'datatype' => 'integer', 'default' => 0, 'required' => true ); };
        $str = function ( $name, $default = '' ) { return array( 'name' => $name, 'datatype' => 'string', 'default' => $default, 'required' => true ); };
        return array(
            'fields' => array(
                'id' => $int( 'ID' ),
                'thread_id' => $int( 'ThreadID' ),
                'position' => $int( 'Position' ),
                'body' => $str( 'Body' ),
                'delivery' => $str( 'Delivery', 'queue' ),
                'state' => $str( 'State', 'waiting' ),
                'created' => $int( 'Created' ),
                'modified' => $int( 'Modified' ),
            ),
            'keys' => array( 'id' ),
            'increment_key' => 'id',
            'class_name' => 'expAiFollowup',
            'sort' => array( 'position' => 'asc' ),
            'name' => 'expai_followup',
        );
    }

    /** @return expAiFollowup[] the waiting follow-ups of a thread in their order */
    public static function waiting( $threadID )
    {
        return (array) eZPersistentObject::fetchObjectList( self::definition(), null, array( 'thread_id' => (int) $threadID, 'state' => 'waiting' ),
                                                            array( 'position' => 'asc', 'id' => 'asc' ), array( 'offset' => 0, 'length' => 50 ) );
    }

    /** @return expAiFollowup|null */
    public static function fetchByID( $id )
    {
        $object = eZPersistentObject::fetchObject( self::definition(), null, array( 'id' => (int) $id ) );
        return $object instanceof self ? $object : null;
    }

    /**
     * Steering (doc/parallel-runs.md "Steering"): the notes sent with delivery "steer" while the lead run of the
     * conversation's thread works, taken at the next call boundary (after the tool results of a step). They are marked
     * sent, with a followup event "steered".
     * @param expAiConversation $conversation the conversation of the step
     * @return string[] the notes, oldest first ([] for another run's conversation or when none waits)
     */
    public static function takeSteers( $conversation )
    {
        if ( !$conversation instanceof expAiConversation || !(int) $conversation->attribute( 'thread_id' ) )
            return array();
        $thread = expAiThread::fetchByID( (int) $conversation->attribute( 'thread_id' ) );
        // only the lead run talks to the editor; child runs of a plan are not steered
        if ( !$thread || (int) $thread->attribute( 'lead_run_id' ) !== (int) $conversation->attribute( 'run_id' ) )
            return array();
        $out = array();
        foreach ( self::waiting( (int) $thread->attribute( 'id' ) ) as $followup )
        {
            if ( $followup->attribute( 'delivery' ) !== 'steer' )
                continue;
            $followup->setAttribute( 'state', 'sent' );
            $followup->setAttribute( 'modified', time() );
            $followup->store();
            $out[] = (string) $followup->attribute( 'body' );
            $thread->addEvent( 'followup', array( 'op' => 'steered', 'id' => (int) $followup->attribute( 'id' ) ) );
        }
        if ( $out )
            $thread->storeActivity();
        return $out;
    }

    /**
     * A turn ended in words while steering notes still waited: they cannot reach that turn any more, so they wait as
     * queued follow-ups (sent as the next message), in front of the others.
     * @return int notes turned into follow-ups
     */
    public static function steersToQueue( $threadID )
    {
        $n = 0;
        $first = null;
        foreach ( self::waiting( (int) $threadID ) as $followup )
        {
            if ( $first === null )
                $first = (int) $followup->attribute( 'position' );
            if ( $followup->attribute( 'delivery' ) !== 'steer' )
                continue;
            $followup->setAttribute( 'delivery', 'queue' );
            $followup->setAttribute( 'position', $first - 1 - $n );
            $followup->setAttribute( 'modified', time() );
            $followup->store();
            $n++;
        }
        return $n;
    }

    /** @return array for the browser */
    public function present()
    {
        return array( 'id' => (int) $this->attribute( 'id' ), 'body' => (string) $this->attribute( 'body' ),
                      'delivery' => (string) $this->attribute( 'delivery' ), 'position' => (int) $this->attribute( 'position' ) );
    }
}
