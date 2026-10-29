<?php
/**
 * File containing the expAiPlanEstimate class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The cost estimate of a plan (doc/plan-mode.md "The estimate"), shown as a range before the editor approves.
 *
 * Per item and language: r tool rounds ([VectorSettings] ExpectedRounds[<action>]); each round sends the frame (the
 * instructions and tool definitions, measured from the thread's conversation when it has one, else PlanFrameTokens),
 * a bounded history (PlanHistoryTokens) and the item's context (the text of the fields it touches, characters /
 * CharsPerToken), and the context grows by what the rounds read and wrote. Output: create about OutputPerField tokens
 * per field, update and translate 1.1 x the source text. Low = r - 1 rounds, high = r + HighExtraRounds.
 * Prices: the model's (expAiCost, #358 aliases) times each lane's price factor (expAiBudget::estimate()). Calibration:
 * the median of actual / expected of the last finished plans (at least 3), between 0.5 and 3, scales the result.
 */
class expAiPlanEstimate
{
    /** @var expAiConfig */
    protected $config;

    public function __construct( ?expAiConfig $config = null )
    {
        $this->config = $config ?: expAiConfig::instance();
    }

    /** @return float characters per token */
    protected function charsPerToken()
    {
        $value = (float) $this->config->get( 'VectorSettings', 'CharsPerToken', 3.5 );
        return $value >= 1 ? $value : 3.5;
    }

    /** @return int the expected rounds of an action */
    public function rounds( $action )
    {
        $rounds = (array) $this->config->get( 'VectorSettings', 'ExpectedRounds', array() );
        $defaults = array( 'create' => 3, 'update' => 3, 'translate' => 2 );
        $value = isset( $rounds[$action] ) ? (int) $rounds[$action] : ( isset( $defaults[$action] ) ? $defaults[$action] : 3 );
        return max( 1, min( 20, $value ) );
    }

    /** @return int tokens of the frame: the thread's stored instructions and tools, else PlanFrameTokens */
    public function frameTokens( ?expAiThread $thread = null )
    {
        $conversation = $thread ? $thread->conversation() : null;
        $frame = $conversation ? $conversation->frame() : null;
        if ( is_array( $frame ) && isset( $frame['system'] ) && $frame['system'] !== '' )
            return (int) ceil( ( mb_strlen( (string) $frame['system'], 'UTF-8' ) + strlen( json_encode( isset( $frame['tools'] ) ? $frame['tools'] : array() ) ) ) / $this->charsPerToken() );
        return $this->config->int( 'VectorSettings', 'PlanFrameTokens', 6000, 500 );
    }

    /**
     * Tokens of the text an item reads: the fields it names (all text fields when none) of the target's published
     * version; a new object: PlanNewObjectTokens.
     * @return int
     */
    public function contextTokens( array $item )
    {
        $objectID = isset( $item['target']['object_id'] ) ? (int) $item['target']['object_id'] : 0;
        if ( !$objectID && !empty( $item['target']['node_id'] ) )
        {
            $node = eZContentObjectTreeNode::fetch( (int) $item['target']['node_id'] );
            $objectID = $node ? (int) $node->attribute( 'contentobject_id' ) : 0;
        }
        $object = $objectID ? expAiObjects::fetch( $objectID ) : null;
        if ( !$object instanceof eZContentObject || ( isset( $item['action'] ) && $item['action'] === 'create' ) )
            return $this->config->int( 'VectorSettings', 'PlanNewObjectTokens', 300, 0 );
        $fields = isset( $item['fields'] ) ? array_map( 'strval', (array) $item['fields'] ) : array();
        $chars = 0;
        foreach ( expAiDiff::values( $object, (int) $object->attribute( 'current_version' ), (string) $object->attribute( 'initial_language_code' ) ) as $identifier => $row )
        {
            if ( $fields && !in_array( $identifier, $fields, true ) )
                continue;
            if ( !$fields && !in_array( $row['datatype'], array( 'ezstring', 'eztext', 'ezxmltext', 'ezkeyword' ), true ) )
                continue;
            $chars += mb_strlen( $row['datatype'] === 'ezxmltext' ? strip_tags( (string) $row['value'] ) : (string) $row['value'], 'UTF-8' );
        }
        return (int) min( 20000, ceil( $chars / $this->charsPerToken() ) + 200 );
    }

    /** @return array in, out (tokens) of one item in one language with r rounds */
    public function itemTokens( array $item, $rounds, $frame, $context )
    {
        $action = isset( $item['action'] ) ? (string) $item['action'] : 'update';
        $fields = isset( $item['fields'] ) ? count( (array) $item['fields'] ) : 0;
        $perField = $this->config->int( 'VectorSettings', 'OutputPerField', 250, 10 );
        $out = $action === 'create' ? $perField * max( 1, $fields ) : (int) round( 1.1 * $context ) + 100;
        $out = min( 16000, $out ) + 120 * $rounds;
        $history = $this->config->int( 'VectorSettings', 'PlanHistoryTokens', 1500, 0 );
        $in = $rounds * ( $frame + $history + $context ) + (int) ceil( ( $rounds - 1 ) * ( $context + $out ) / 2 );
        return array( 'in' => (int) $in, 'out' => (int) $out );
    }

    /** @var array|null the ratios of finished items: list of { action, class, ratio } (read once per estimate) */
    protected $itemRatios = null;

    /**
     * The actual / expected ratios of the items of the last finished plans that ran as child runs (their actual cost
     * per item is recorded, doc/parallel-runs.md).
     * @return array list of { action, class, ratio }
     */
    protected function itemRatios()
    {
        if ( $this->itemRatios !== null )
            return $this->itemRatios;
        $this->itemRatios = array();
        foreach ( expAiPlan::fetchDone( 40 ) as $plan )
        {
            $e = $plan->estimate();
            if ( empty( $e['actual_items'] ) || empty( $e['items'] ) )
                continue;
            $expected = array();
            foreach ( (array) $e['items'] as $row )
                $expected[(int) $row['index']] = isset( $row['micros'] ) ? (int) $row['micros'] : 0;
            foreach ( $plan->items() as $item )
            {
                $i = (int) $item['index'];
                if ( empty( $expected[$i] ) || !isset( $e['actual_items'][$i] ) || (int) $e['actual_items'][$i] <= 0 )
                    continue;
                $this->itemRatios[] = array( 'action' => (string) $item['action'], 'class' => (string) $item['class'],
                                             'ratio' => (int) $e['actual_items'][$i] / $expected[$i] );
            }
        }
        return $this->itemRatios;
    }

    /** @return float|null the median of ratios between 0.5 and 3, null below 3 values */
    protected static function median( array $ratios )
    {
        if ( count( $ratios ) < 3 )
            return null;
        sort( $ratios );
        $mid = (int) floor( count( $ratios ) / 2 );
        $median = count( $ratios ) % 2 ? $ratios[$mid] : ( $ratios[$mid - 1] + $ratios[$mid] ) / 2;
        return round( max( 0.5, min( 3.0, $median ) ), 3 );
    }

    /**
     * The calibration of one item: the median actual / expected of finished items with the same action and class (at
     * least 3), else the same action, else the plan-level calibration.
     * @return array factor, from (items or plans used), by (action_class, action, plan)
     */
    public function itemCalibration( $action, $class )
    {
        $same = array();
        $sameAction = array();
        foreach ( $this->itemRatios() as $row )
        {
            if ( $row['action'] !== (string) $action )
                continue;
            $sameAction[] = $row['ratio'];
            if ( $row['class'] === (string) $class )
                $same[] = $row['ratio'];
        }
        if ( ( $f = self::median( $same ) ) !== null )
            return array( 'factor' => $f, 'from' => count( $same ), 'by' => 'action_class' );
        if ( ( $f = self::median( $sameAction ) ) !== null )
            return array( 'factor' => $f, 'from' => count( $sameAction ), 'by' => 'action' );
        return $this->calibration() + array( 'by' => 'plan' );
    }

    /** @var array|null the plan-level calibration (read once per estimate) */
    protected $planCalibration = null;

    /** @return array factor (1.0 without data), from (finished plans used) */
    public function calibration()
    {
        if ( $this->planCalibration !== null )
            return $this->planCalibration;
        return $this->planCalibration = $this->planLevelCalibration();
    }

    /** @return array factor, from: the median of actual / expected of the last finished plans */
    protected function planLevelCalibration()
    {
        $ratios = array();
        foreach ( expAiPlan::fetchDone( 20 ) as $plan )
        {
            $e = $plan->estimate();
            if ( !empty( $e['expected_micros'] ) && isset( $e['actual_micros'] ) && (int) $e['actual_micros'] > 0 )
                $ratios[] = (int) $e['actual_micros'] / (int) $e['expected_micros'];
        }
        if ( count( $ratios ) < 3 )
            return array( 'factor' => 1.0, 'from' => count( $ratios ) );
        sort( $ratios );
        $mid = (int) floor( count( $ratios ) / 2 );
        $median = count( $ratios ) % 2 ? $ratios[$mid] : ( $ratios[$mid - 1] + $ratios[$mid] ) / 2;
        return array( 'factor' => round( max( 0.5, min( 3.0, $median ) ), 3 ), 'from' => count( $ratios ) );
    }

    /**
     * The estimate of items run with a model.
     * @param array $items the runnable items of a plan
     * @param string $alias the model alias
     * @param string $laneName the thread's lane (auto: standard)
     * @return array items (per item in, out, micros), tokens_in, tokens_out, low_micros, expected_micros, high_micros,
     *               lanes (name, label, batch, low, expected, high micros), lane, known, duration_s, calibration, rounds
     */
    public function estimate( array $items, $alias, $laneName = 'auto', ?expAiThread $thread = null )
    {
        $budget = new expAiBudget( $this->config );
        $frame = $this->frameTokens( $thread );
        $extra = $this->config->int( 'VectorSettings', 'HighExtraRounds', 2, 0, 20 );
        $calibration = $this->calibration();
        $lanes = array();
        if ( class_exists( 'expAiLane' ) )
            foreach ( expAiLane::forModel( (string) $alias, $this->config ) as $name => $lane )
                if ( $lane->usable() )
                    $lanes[(string) $name] = $lane;
        $totals = array( 'low' => array( 0, 0 ), 'expected' => array( 0, 0 ), 'high' => array( 0, 0 ) );
        // the tokens weighted by the calibration of each item (per action and class, else per plan): what is priced
        $weighted = $totals;
        $byItem = false;
        $rows = array();
        $roundsTotal = 0;
        foreach ( array_values( $items ) as $n => $item )
        {
            $action = isset( $item['action'] ) ? (string) $item['action'] : 'update';
            $languages = max( 1, count( isset( $item['languages'] ) ? (array) $item['languages'] : array() ) );
            $context = $this->contextTokens( $item );
            $r = $this->rounds( $action );
            $sizes = array( 'low' => max( 1, $r - 1 ), 'expected' => $r, 'high' => $r + $extra );
            $row = array( 'index' => isset( $item['index'] ) ? (int) $item['index'] : $n, 'languages' => $languages );
            $own = $this->itemCalibration( $action, isset( $item['class'] ) ? (string) $item['class'] : '' );
            $f = (float) $own['factor'];
            if ( $own['by'] !== 'plan' )
            {
                $byItem = true;
                $row['calibration'] = $own;
            }
            foreach ( $sizes as $key => $rounds )
            {
                $t = $this->itemTokens( $item, $rounds, $frame, $context );
                $totals[$key][0] += $t['in'] * $languages;
                $totals[$key][1] += $t['out'] * $languages;
                $weighted[$key][0] += $t['in'] * $languages * $f;
                $weighted[$key][1] += $t['out'] * $languages * $f;
                if ( $key === 'expected' )
                {
                    $row['in'] = $t['in'] * $languages;
                    $row['out'] = $t['out'] * $languages;
                    $cost = $budget->estimate( $alias, $row['in'], $row['out'] );
                    $row['micros'] = (int) round( $cost['micros'] * $f );
                }
            }
            $roundsTotal += $r * $languages;
            $rows[] = $row;
        }
        $price = function ( $key, $lane ) use ( $budget, $alias, $weighted )
        {
            $cost = $budget->estimate( $alias, (int) round( $weighted[$key][0] ), (int) round( $weighted[$key][1] ), $lane );
            return array( (int) $cost['micros'], $cost['known'] );
        };
        if ( $byItem )
            $calibration['per_item'] = true;
        $chosen = $laneName !== '' && $laneName !== 'auto' && isset( $lanes[$laneName] ) ? $laneName : ( isset( $lanes['standard'] ) ? 'standard' : '' );
        $laneRows = array();
        foreach ( $lanes as $name => $lane )
        {
            $laneRows[] = array( 'name' => $name, 'label' => expAiVector::laneLabel( $name ), 'batch' => (bool) $lane->isBatch(), 'factor' => (int) $lane->factorPercent(),
                                 'low' => $price( 'low', $lane )[0], 'expected' => $price( 'expected', $lane )[0], 'high' => $price( 'high', $lane )[0] );
        }
        $chosenLane = $chosen !== '' ? $lanes[$chosen] : null;
        list( $low, $known ) = $price( 'low', $chosenLane );
        list( $expected ) = $price( 'expected', $chosenLane );
        list( $high ) = $price( 'high', $chosenLane );
        return array( 'items' => $rows, 'tokens_in' => $totals['expected'][0], 'tokens_out' => $totals['expected'][1],
                      'tokens' => $totals['expected'][0] + $totals['expected'][1],
                      'low_micros' => $low, 'expected_micros' => $expected, 'high_micros' => $high,
                      'lanes' => $laneRows, 'lane' => $chosen ?: 'standard', 'known' => (bool) $known, 'alias' => (string) $alias,
                      'duration_s' => $roundsTotal * $this->config->int( 'VectorSettings', 'SecondsPerRound', 8, 1 ),
                      'calibration' => $calibration, 'frame_tokens' => $frame );
    }

    /**
     * What the plan does to the limits and budgets: per cost limit of the user (day, month) and the site budget, the
     * share used now and after the plan, and whether the expected amount fits.
     * @param int $micros the amount to reserve
     * @return array list of { key, label, used, max, percent, after, fits }, ok (all fit), message (the first that does not)
     */
    public function limits( $micros, $userID = 0 )
    {
        $amount = (int) $micros / 1000000;
        $rows = array();
        $message = '';
        foreach ( expAiLimits::status( array( 'user_id' => $userID ?: (int) eZUser::currentUserID() ) ) as $key => $limit )
        {
            if ( $limit['unit'] !== 'cost' || $limit['max'] <= 0 || !in_array( $limit['period'], array( 'day', 'month' ), true ) )
                continue;
            $after = (float) $limit['used'] + $amount;
            $fits = $after <= (float) $limit['max'];
            $rows[] = array( 'key' => $key, 'label' => expAiLimits::label( $key ), 'used' => expAiCost::money( (float) $limit['used'] ),
                             'max' => expAiCost::money( (float) $limit['max'] ), 'percent' => (float) $limit['percent'],
                             'after' => min( 999, round( 100 * $after / (float) $limit['max'], 1 ) ), 'fits' => $fits );
            if ( !$fits && $message === '' )
                $message = ezpI18n::tr( 'extension/expai/vector', 'This plan needs about %needed; %left is left of the limit "%limit".', null,
                                        array( '%needed' => expAiCost::money( $amount ), '%left' => expAiCost::money( max( 0, (float) $limit['max'] - (float) $limit['used'] ) ),
                                               '%limit' => expAiLimits::label( $key ) ) );
        }
        $budget = new expAiBudget( $this->config );
        $refusal = $budget->refusal( (int) round( $micros * $budget->reserveFactor() ) );
        $cap = $budget->siteCap();
        if ( $cap > 0 )
        {
            $used = $budget->spentThisMonth() + $budget->reserved();
            $rows[] = array( 'key' => 'site_budget', 'label' => ezpI18n::tr( 'extension/expai/vector', 'Site budget this month' ),
                             'used' => expAiBudget::money( $used ), 'max' => expAiBudget::money( $cap ), 'percent' => round( 100 * $used / $cap, 1 ),
                             'after' => min( 999, round( 100 * ( $used + $micros ) / $cap, 1 ) ), 'fits' => $refusal === null );
        }
        if ( $refusal !== null && $message === '' )
            $message = ezpI18n::tr( 'extension/expai/vector', 'This plan needs about %needed; the site budget has %left left this month.', null,
                                    array( '%needed' => expAiBudget::money( $micros ), '%left' => expAiBudget::money( max( 0, $refusal['cap'] - $refusal['spent'] - $refusal['reserved'] ) ) ) );
        return array( 'rows' => $rows, 'ok' => $message === '', 'message' => $message );
    }
}
