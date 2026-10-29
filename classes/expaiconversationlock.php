<?php
/**
 * File containing the expAiConversationLock class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The lock of a conversation while one step runs (expAiAssistant::step()): a second step of the same conversation -
 * a second browser tab, a double click, the queue and the panel at once - is refused instead of running the tools
 * a second time. The lock is a pair of columns of expai_conversation (busy_owner, busy_until) taken by compare-and-set
 * with plain SQL, so it works the same on every relational engine and across the hosts of a cluster; it expires on
 * its own after the step's time limit, so a process that ended without releasing it does not block the conversation.
 * The columns are not part of expAiConversation::definition(): storing the conversation never touches the lock.
 * MongoDB has no SQL: there the lock is not taken (a step runs as before).
 */
class expAiConversationLock
{
    /** @var eZDBInterface|object|null the database (null: eZDB::instance()) */
    protected $db;

    /** @var string this process's id as the owner of a lock */
    protected $owner;

    /** @var int|null the current time (tests) */
    public $now = null;

    public function __construct( $db = null, $owner = null )
    {
        $this->db = $db;
        $this->owner = $owner !== null ? (string) $owner : self::processOwner();
    }

    /** @return string host:pid:random, at most 60 characters */
    public static function processOwner()
    {
        static $id = null;
        if ( $id === null )
            $id = substr( preg_replace( '/[^A-Za-z0-9._-]/', '', (string) gethostname() ), 0, 30 ) . ':' . getmypid() . ':' . bin2hex( random_bytes( 6 ) );
        return $id;
    }

    /** @return string */
    public function owner()
    {
        return $this->owner;
    }

    /** @return eZDBInterface|object */
    protected function db()
    {
        return $this->db ?: eZDB::instance();
    }

    /** @return int */
    protected function now()
    {
        return $this->now !== null ? (int) $this->now : time();
    }

    /** @var bool the warning about the missing columns was written in this process */
    protected static $warned = false;

    /** @var callable|null function( string $table ): string[]|null, the columns of the table (tests; default the database) */
    public static $columnLister = null;

    /**
     * @return bool the engine runs SQL (every one but MongoDB) and the table has the lock's columns. An installation
     *              that was not upgraded (expaischema.php --install) runs its steps without the lock, with one warning
     *              per process instead of a failed statement on every step. Only a positive answer is kept for the
     *              process; a missing column is asked again after a few minutes (expAiSchema::hasColumns()), so the
     *              upgrade takes effect in long-running workers too. A database given to the constructor (tests) is
     *              taken as it is.
     */
    protected function usable()
    {
        $db = $this->db();
        if ( method_exists( $db, 'databaseName' ) && strpos( strtolower( (string) $db->databaseName() ), 'mongo' ) === 0 )
            return false;
        if ( $this->db )
            return true;
        return self::columnsPresent();
    }

    /** @return bool expai_conversation has busy_owner and busy_until (see usable()) */
    public static function columnsPresent()
    {
        $ok = expAiSchema::hasColumns( 'expai_conversation', array( 'busy_owner', 'busy_until' ), self::$columnLister );
        if ( !$ok && !self::$warned )
        {
            self::$warned = true;
            eZDebug::writeWarning( 'expai: expai_conversation has no columns busy_owner and busy_until, so steps run without the conversation lock. '
                                   . 'Run php extension/expai/bin/php/expaischema.php --install -s <siteaccess>.', __METHOD__ );
        }
        return $ok;
    }

    /**
     * Takes the lock of a conversation for $seconds (free or expired locks are taken; the owner takes its own again
     * and extends it).
     * @return bool taken; false when another step holds it
     */
    public function acquire( $conversationID, $seconds )
    {
        $id = (int) $conversationID;
        if ( $id <= 0 || !$this->usable() )
            return true;
        $db = $this->db();
        $now = $this->now();
        $until = $now + max( 1, (int) $seconds );
        $me = $db->escapeString( $this->owner );
        $db->query( "UPDATE expai_conversation SET busy_owner = '$me', busy_until = $until WHERE id = $id AND ( busy_until < $now OR busy_owner = '$me' )" );
        $rows = $db->arrayQuery( "SELECT busy_owner FROM expai_conversation WHERE id = $id" );
        if ( !is_array( $rows ) || !isset( $rows[0] ) )
            // the conversation is gone: nothing to protect
            return true;
        $row = array_change_key_case( $rows[0], CASE_LOWER );
        return isset( $row['busy_owner'] ) && (string) $row['busy_owner'] === $this->owner;
    }

    /** Gives the lock back (only the owner's own lock) */
    public function release( $conversationID )
    {
        $id = (int) $conversationID;
        if ( $id <= 0 || !$this->usable() )
            return;
        $db = $this->db();
        $me = $db->escapeString( $this->owner );
        $db->query( "UPDATE expai_conversation SET busy_owner = '', busy_until = 0 WHERE id = $id AND busy_owner = '$me'" );
    }

    /**
     * @param bool $started the step had begun (it lost its lock to another one midway) rather than being refused before
     *                      anything happened
     * @return expAiConversationBusyException the refusal of a second step
     */
    public static function busy( $started = false )
    {
        return new expAiConversationBusyException( 'A step of this conversation is already running. Wait until it has finished, then try again.', 'conversation lock held', 0, array(), $started );
    }
}

/**
 * Another step of the conversation holds its lock (expAiConversationLock). An expAiException, so every existing catch
 * still sees it; a queued turn that meets it before its step began (started() false) is tried again a little later
 * instead of failing for good, as the other step ends or its lock runs out on its own.
 */
class expAiConversationBusyException extends expAiException
{
    /** @var bool */
    protected $started;

    public function __construct( $message, $detail = '', $code = 0, array $arguments = array(), $started = false )
    {
        parent::__construct( $message, $detail, $code, $arguments );
        $this->started = (bool) $started;
    }

    /**
     * A busy conversation is the expected answer to a second click while a step runs, not a fault: it is told
     * to the user (and retried by the queue), and kept out of the warning log.
     * @return expAiException
     */
    public function translated()
    {
        eZDebug::writeDebug( $this->getMessage() . ' (' . $this->detail . ')', __METHOD__ );
        return new expAiException( $this->translatedMessage(), $this->detail, $this->getCode() );
    }

    /** how long a queued turn waits before it tries a busy conversation again */
    const RETRY_SECONDS = 20;

    /** @return bool the step had begun and lost its lock midway (its work so far is in the conversation) */
    public function started()
    {
        return $this->started;
    }

    /**
     * @return bool a queued step that failed with $e may simply run again later: the conversation was busy and the
     *              step had not begun (nothing was sent, nothing written)
     */
    public static function retryable( $e )
    {
        return $e instanceof self && !$e->started();
    }
}
