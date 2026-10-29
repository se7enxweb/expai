<?php
/**
 * File containing the expAiBudget class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The budgets of queued requests (expai.ini [Budgets]): a monthly budget of the whole site and a budget per job group
 * (an expqueue group: "translate this subtree").
 *
 *   site budget   [Budgets] SiteMonthly (in [Pricing] Currency), else [Limits] MaxCostPerSitePerMonth; it counts
 *                 what every request of the month cost (panel, field actions and queue: expai_usage_log) plus what
 *                 the active queued requests have reserved
 *   group budget  expqueue_group.budget_micros (set when the group is created; [Budgets] DefaultGroupBudget when the
 *                 producer names none); it counts the group's settled cost (spent_micros) plus its reservations
 *
 * A request reserves its estimate (expAiCost of the estimated tokens x the lane's price factor x ReserveFactor) when
 * it is queued. When the reservation does not fit, the job is held (state held, error class budget) at once; a job
 * that is about to call the provider checks again, because other requests may have cost more than estimated. Held
 * jobs are resumed by the cron part expai (expai_queue.php) and by ./console ext:expai:budget resume as soon as the
 * budget allows (a new month, a raised budget). When the usage of a request is recorded its reservation becomes its
 * actual cost (settle()).
 *
 * All amounts are micros: millionths of the currency unit.
 */
class expAiBudget
{
    const ERROR_CLASS = 'budget';

    /** @var expAiConfig */
    protected $config;

    /** @var int|null the cost of this month, read once per instance */
    protected $spent;

    /** @var int this month's requests without a price (#389), counted with $spent */
    protected $unpriced = 0;

    public function __construct( ?expAiConfig $config = null )
    {
        $this->config = $config ?: expAiConfig::instance();
    }

    /** @return int micros of an amount in the currency */
    public static function micros( $amount )
    {
        return (int) round( (float) $amount * 1000000 );
    }

    /** @return string an amount of micros as money */
    public static function money( $micros )
    {
        return expAiCost::money( (int) $micros / 1000000 );
    }

    /** @return int the monthly site budget in micros (0: none) */
    public function siteCap()
    {
        $value = $this->config->get( 'Budgets', 'SiteMonthly', '' );
        if ( !is_numeric( $value ) || (float) $value <= 0 )
            $value = $this->config->get( 'Limits', 'MaxCostPerSitePerMonth', '' );
        return is_numeric( $value ) && (float) $value > 0 ? self::micros( $value ) : 0;
    }

    /** @return int the default budget of a new job group in micros (0: none) */
    public function defaultGroupCap()
    {
        $value = $this->config->get( 'Budgets', 'DefaultGroupBudget', '' );
        return is_numeric( $value ) && (float) $value > 0 ? self::micros( $value ) : 0;
    }

    /** @return float the factor on an estimate that is reserved */
    public function reserveFactor()
    {
        $value = (float) $this->config->get( 'Budgets', 'ReserveFactor', 1.25 );
        return $value >= 1 ? $value : 1.0;
    }

    /** @return int what this month's requests cost (micros), from the usage log */
    public function spentThisMonth( $refresh = false )
    {
        if ( $this->spent === null || $refresh )
        {
            $agg = expAiUsage::aggregate( array( 'from' => expAiLimits::periodStart( 'month' ) ) );
            $this->spent = self::micros( $agg['totals']['cost'] );
            $this->unpriced = isset( $agg['totals']['unpriced'] ) ? (int) $agg['totals']['unpriced'] : 0;
        }
        return $this->spent;
    }

    /** @return string[] the job states whose reservation counts */
    public static function reservingStates()
    {
        return array( 'queued', 'ready', 'running', 'retry' );
    }

    /**
     * The reservations of the active expai jobs.
     * @param int $groupID only this group (0: all)
     * @param int $excludeJobID leave one job out
     * @return int micros
     */
    public function reserved( $groupID = 0, $excludeJobID = 0 )
    {
        $db = eZDB::instance();
        $sql = "SELECT SUM(cost_reserved_micros) AS total FROM expqueue_job WHERE type LIKE 'expai.%' AND state IN ( '"
             . implode( "', '", self::reservingStates() ) . "' )";
        if ( $groupID )
            $sql .= ' AND group_id = ' . (int) $groupID;
        if ( $excludeJobID )
            $sql .= ' AND id <> ' . (int) $excludeJobID;
        $rows = $db->arrayQuery( $sql );
        if ( !is_array( $rows ) || !isset( $rows[0] ) )
            return 0;
        $row = array_change_key_case( $rows[0], CASE_LOWER );
        return (int) $row['total'];
    }

    /**
     * The estimate of a request in micros.
     * @return array micros, known (bool: the model has a price), input, output (tokens)
     */
    public function estimate( $alias, $inputTokens, $outputTokens, ?expAiLane $lane = null )
    {
        $model = $this->config->registry()->getModel( (string) $alias );
        $cost = expAiCost::of( $model ? $model['id'] : (string) $alias, (int) $inputTokens, (int) $outputTokens, 0, 0, (string) $alias );
        $factor = $lane ? $lane->factorPercent() / 100 : 1.0;
        return array( 'micros' => self::micros( $cost['amount'] * $factor ), 'known' => $cost['source'] !== 'unknown',
                      'input' => (int) $inputTokens, 'output' => (int) $outputTokens, 'factor' => $factor );
    }

    /**
     * Tokens of a request, estimated from its text (characters / CharsPerToken, default 3.5).
     * @return int
     */
    public function estimateInputTokens( array $request )
    {
        $chars = mb_strlen( isset( $request['system'] ) ? (string) $request['system'] : '', 'UTF-8' );
        foreach ( isset( $request['messages'] ) ? (array) $request['messages'] : array() as $message )
            $chars += mb_strlen( is_string( $message['content'] ) ? $message['content'] : json_encode( $message['content'] ), 'UTF-8' );
        if ( !empty( $request['output_schema'] ) )
            $chars += strlen( json_encode( $request['output_schema'] ) );
        $per = (float) $this->config->get( 'Budgets', 'CharsPerToken', 3.5 );
        return (int) ceil( $chars / ( $per > 0 ? $per : 3.5 ) ) + 10;
    }

    /**
     * Whether a reservation fits.
     * @param int $micros the new reservation
     * @param int $groupID
     * @param int $excludeJobID a job whose own reservation is already counted and is checked again
     * @return array|null null when it fits; else scope (site | group), cap, spent, reserved, needed (micros), message
     */
    public function refusal( $micros, $groupID = 0, $excludeJobID = 0 )
    {
        $micros = max( 0, (int) $micros );
        $cap = $this->siteCap();
        if ( $cap > 0 )
        {
            $spent = $this->spentThisMonth();
            $reserved = $this->reserved( 0, $excludeJobID );
            if ( $spent + $reserved + $micros > $cap )
                return array( 'scope' => 'site', 'cap' => $cap, 'spent' => $spent, 'reserved' => $reserved, 'needed' => $micros,
                              'message' => sprintf( 'the monthly site budget of %s is reached (%s spent, %s reserved, %s needed)',
                                                    self::money( $cap ), self::money( $spent ), self::money( $reserved ), self::money( $micros ) ) );
        }
        if ( $groupID && class_exists( 'expQueueGroup' ) && expAiConfig::extensionActive( 'expqueue' ) )
        {
            $group = expQueueGroup::fetchByID( (int) $groupID );
            $gcap = $group ? (int) $group->attribute( 'budget_micros' ) : 0;
            if ( $gcap > 0 )
            {
                $spent = (int) $group->attribute( 'spent_micros' );
                $reserved = $this->reserved( (int) $groupID, $excludeJobID );
                if ( $spent + $reserved + $micros > $gcap )
                    return array( 'scope' => 'group', 'cap' => $gcap, 'spent' => $spent, 'reserved' => $reserved, 'needed' => $micros,
                                  'message' => sprintf( 'the budget of the job group "%s" of %s is reached (%s spent, %s reserved, %s needed)',
                                                        $group->attribute( 'label' ), self::money( $gcap ), self::money( $spent ), self::money( $reserved ), self::money( $micros ) ) );
            }
        }
        return null;
    }

    /** Stores the reservation of a job. */
    public function reserve( expQueueJob $job, $micros )
    {
        $micros = max( 0, (int) $micros );
        eZDB::instance()->query( 'UPDATE expqueue_job SET cost_reserved_micros = ' . $micros . ' WHERE id = ' . (int) $job->attribute( 'id' ) );
        $job->setAttribute( 'cost_reserved_micros', $micros );
    }

    /**
     * The usage of a job's request was recorded: its reservation becomes its actual cost, and the cost counts for its
     * group.
     */
    public function settle( $jobID, $groupID, $micros )
    {
        $micros = max( 0, (int) $micros );
        $db = eZDB::instance();
        $db->query( 'UPDATE expqueue_job SET cost_reserved_micros = 0, cost_actual_micros = cost_actual_micros + ' . $micros . ' WHERE id = ' . (int) $jobID );
        if ( $groupID )
            $db->query( 'UPDATE expqueue_group SET spent_micros = spent_micros + ' . $micros . ' WHERE id = ' . (int) $groupID );
        if ( $this->spent !== null )
            $this->spent += $micros;
    }

    /**
     * Holds a job that is not running (queued, ready, retry) because of the budget.
     * @return bool
     */
    public function hold( expQueueJob $job, $message )
    {
        $from = (string) $job->attribute( 'state' );
        if ( !in_array( $from, array( 'queued', 'ready', 'retry' ), true ) )
            return false;
        $ok = expQueue::casUpdate( (int) $job->attribute( 'id' ), array( 'state' => $from ),
                                   array( 'state' => 'held', 'error_class' => self::ERROR_CLASS, 'last_error' => expQueueJob::shorten( 'budget: ' . $message, 255 ) ) );
        if ( $ok )
        {
            $job->setAttribute( 'state', 'held' );
            expQueueEvent::add( $job->attribute( 'id' ), $job->attribute( 'group_id' ), 'hold', 'held: ' . $message, array( 'reason' => 'budget' ) );
            if ( class_exists( 'expAiAudit' ) )
                expAiAudit::budgetHold( $job );
        }
        return $ok;
    }

    /**
     * Resumes held jobs whose reservation fits now, oldest first by priority.
     * @param int $limit
     * @return array resumed, still_held
     */
    public function resumeHeld( $limit = 200 )
    {
        $rows = (array) eZPersistentObject::fetchObjectList( expQueueJob::definition(), null,
            array( 'state' => 'held', 'error_class' => self::ERROR_CLASS ), array( 'priority' => 'desc', 'id' => 'asc' ),
            array( 'offset' => 0, 'length' => max( 1, (int) $limit ) ), true );
        $resumed = 0;
        $held = 0;
        foreach ( $rows as $job )
        {
            if ( $this->refusal( (int) $job->attribute( 'cost_reserved_micros' ), (int) $job->attribute( 'group_id' ), (int) $job->attribute( 'id' ) ) !== null )
            {
                ++$held;
                continue;
            }
            if ( expQueue::retry( $job ) )
                ++$resumed;
        }
        return array( 'resumed' => $resumed, 'still_held' => $held );
    }

    /** @return int jobs held by a budget */
    public static function heldCount()
    {
        if ( !expAiQueue::available() )
            return 0;
        return expQueue::count( array( 'state' => 'held', 'error_class' => self::ERROR_CLASS ) );
    }

    /**
     * The figures of the dashboard and expai/budgets.
     * @return array site (cap, spent, reserved, remaining, percent, level), held, groups (the newest groups with a
     *               budget or expai jobs), currency
     */
    public function status( $groups = 10 )
    {
        $queue = expAiQueue::available();
        $cap = $this->siteCap();
        $spent = $this->spentThisMonth();
        $reserved = $queue ? $this->reserved() : 0;
        $used = $spent + $reserved;
        $percent = $cap > 0 ? min( 100, round( 100 * $used / $cap, 1 ) ) : 0;
        $site = array( 'cap' => $cap, 'spent' => $spent, 'reserved' => $reserved, 'remaining' => $cap > 0 ? max( 0, $cap - $used ) : 0,
                       'percent' => $percent, 'level' => $cap <= 0 ? 'none' : ( $used >= $cap ? 'over' : ( $percent >= 90 ? 'high' : ( $percent >= 75 ? 'warn' : 'ok' ) ) ),
                       'cap_money' => self::money( $cap ), 'spent_money' => self::money( $spent ), 'reserved_money' => self::money( $reserved ),
                       'remaining_money' => self::money( max( 0, $cap - $used ) ), 'resets' => expAiLimits::periodEnd( 'month' ),
                       // the spent figure sums the priced requests; these had no price (expai/log/(unpriced)/1)
                       'unpriced' => $this->unpriced, 'unpriced_path' => '/(from)/' . date( 'Y-m-d', expAiLimits::periodStart( 'month' ) ) );
        $list = array();
        if ( $queue )
            foreach ( expQueueGroup::fetchRecent( max( 1, (int) $groups ) ) as $group )
            {
                $gid = (int) $group->attribute( 'id' );
                $jobs = expQueue::count( array( 'group_id' => $gid, 'type' => array( 'expai.request' ) ) );
                $gcap = (int) $group->attribute( 'budget_micros' );
                if ( !$jobs && $gcap <= 0 )
                    continue;
                $gspent = (int) $group->attribute( 'spent_micros' );
                $greserved = $this->reserved( $gid );
                $list[] = array( 'uuid' => (string) $group->attribute( 'uuid' ), 'label' => (string) $group->attribute( 'label' ),
                                 'state' => (string) $group->attribute( 'state' ), 'jobs' => $jobs, 'cap' => $gcap, 'spent' => $gspent, 'reserved' => $greserved,
                                 'cap_money' => $gcap > 0 ? self::money( $gcap ) : '', 'spent_money' => self::money( $gspent ), 'reserved_money' => self::money( $greserved ),
                                 'percent' => $gcap > 0 ? min( 100, round( 100 * ( $gspent + $greserved ) / $gcap, 1 ) ) : 0,
                                 'held' => expQueue::count( array( 'group_id' => $gid, 'state' => 'held', 'error_class' => self::ERROR_CLASS ) ) );
            }
        return array( 'site' => $site, 'held' => $queue ? self::heldCount() : 0, 'groups' => $list, 'queue' => $queue, 'currency' => expAiUsage::currency() );
    }
}
