<?php
/**
 * File containing the expAiAudit class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * What Exponential Intelligence records in the audit trail (doc/audit-trail.md): one record per model request, per
 * draft written and per change of a draft's state, per chain action, model action, budget hold or resume, call of an
 * external MCP tool and policy migration, written by the kernel's audit writer (expAudit::event()) into the channel
 * "ai" (settings/audit.ini.append.php). The names and their defaults are in expAiAuditBranch.
 *
 * A record holds who (the kernel adds the actor), what, when, which node, model, provider and region, the token
 * counts and the estimated cost, and the result. It never holds a prompt, an answer, a prompt summary, a provider's
 * error text, a key or any personal data beyond the user id the kernel already records.
 *
 * Every method is safe to call where the audit is missing (an older kernel, a Velocity worker started before it):
 * it does nothing then, and it never throws into its caller.
 */
class expAiAudit
{
    /** The channel of the records */
    const CHANNEL = 'ai';

    /** The event of each draft state a draft can move to */
    const DRAFT_STATES = array( 'kept' => 'content.expai_draft.keep', 'pending' => 'content.expai_draft.submit',
                                'published' => 'content.expai_draft.publish', 'discarded' => 'content.expai_draft.discard',
                                'draft' => 'content.expai_draft.reopen' );

    /** @return bool the kernel's audit is there */
    public static function available()
    {
        return class_exists( 'expAudit' ) && class_exists( 'expAuditTaxonomy' );
    }

    /**
     * Records one event; never throws.
     *
     * @param string $name
     * @param array|callable $data
     * @return string|null the event id
     */
    public static function event( $name, $data )
    {
        if ( !self::available() )
            return null;
        try
        {
            if ( !expAudit::isOn( $name ) )
                return null;
            return expAudit::event( $name, is_callable( $data ) ? (array) $data() : (array) $data );
        }
        catch ( Throwable $e )
        {
            eZDebug::writeWarning( "expai: the audit event $name was not recorded: " . $e->getMessage(), __METHOD__ );
            return null;
        }
    }

    /**
     * A model request, from its usage log row (expAiUsage::record()): system.expai.request, .failed (an error or a
     * refusal of the model) or .refused (the veto, the master switch).
     *
     * @param expAiUsageLog $log
     */
    public static function request( $log )
    {
        if ( !self::available() || !$log )
            return;
        $row = array();
        foreach ( array_keys( expAiUsageLog::definition()['fields'] ) as $field )
            $row[$field] = $log->attribute( $field );
        $error = (string) $row['error_class'];
        $name = $error === '' ? 'system.expai.request' : ( $error === 'veto' ? 'system.expai.request.refused' : 'system.expai.request.failed' );
        self::event( $name, function () use ( $row, $error, $name )
        {
            $after = array( 'provider' => (string) $row['provider'], 'model' => (string) $row['model'], 'model_alias' => (string) $row['model_alias'],
                            'region' => (string) $row['region'], 'retention' => (string) $row['retention'], 'action' => (string) $row['action'],
                            'lane' => (string) $row['lane'], 'service_tier' => (string) $row['service_tier'], 'tool_mode' => (string) $row['tool_mode'],
                            // the token counts under "usage" (in, out, cache_write, cache_read, reasoning): the audit masks every field
                            // whose name has "token" in it as a secret
                            'usage' => self::compact( array( 'in' => (int) $row['input_tokens'], 'out' => (int) $row['output_tokens'],
                                             'cache_write' => (int) $row['cache_write_tokens'], 'cache_read' => (int) $row['cache_read_tokens'],
                                             'reasoning' => (int) $row['reasoning_tokens'] ) ), 'latency_ms' => (int) $row['latency_ms'],
                            'http_status' => (int) $row['http_status'], 'retries' => (int) $row['retries'], 'stop_reason' => (string) $row['stop_reason'],
                            'conversation_id' => (int) $row['conversation_id'], 'turn' => (int) $row['turn'],
                            'thread_id' => (int) $row['thread_id'], 'run_id' => (int) $row['run_id'], 'job' => (string) $row['job_uuid'],
                            'batch_id' => (int) $row['batch_id'],
                            'fallback_from' => (string) $row['requested_model_alias'], 'fallback_reason' => (string) $row['fallback_reason'] );
            // the estimated cost in millionths of the currency (integers only in the record); unknown prices: none
            $cost = class_exists( 'expAiUsage' ) ? expAiUsage::cost( $row['model'], $row['input_tokens'], $row['output_tokens'], $row['cache_write_tokens'],
                                                                     $row['cache_read_tokens'], (string) $row['model_alias'], (int) $row['reported_cost_micros'],
                                                                     (int) $row['price_factor'] ?: 100 ) : null;
            if ( $cost !== null )
                $after += array( 'cost_micros' => (int) round( $cost * 1000000 ), 'currency' => expAiUsage::currency() );
            // a request made for another user (a queued job, a batch collected by the cron part)
            if ( (int) $row['user_id'] && (int) $row['user_id'] !== (int) eZUser::currentUserID() )
                $after['user_id'] = (int) $row['user_id'];
            $data = array( 'object' => array( 'type' => 'expai_request', 'id' => (int) $row['id'], 'name' => (string) ( $row['model_alias'] !== '' ? $row['model_alias'] : $row['model'] ) ),
                           'after' => self::compact( $after ) );
            if ( (int) $row['node_id'] )
                $data['target'] = array( 'type' => 'node', 'id' => (int) $row['node_id'] );
            if ( $name === 'system.expai.request.refused' )
                $data += array( 'result' => 'refused', 'reason' => 'veto' );
            elseif ( $error !== '' )
                // the class of the error, never the provider's text (it can quote the request)
                $data += array( 'result' => 'failed', 'reason' => $error === 'refusal' ? 'refusal' : 'error',
                                'error' => array( 'ref' => $error, 'message' => 'HTTP ' . (int) $row['http_status'] ) );
            return $data;
        } );
    }

    /**
     * A request refused before anything was sent because Exponential Intelligence is switched off.
     *
     * @param array $known the request's context (user_id, node_id, provider, model, model_alias, purpose)
     * @param string $where "site" ([AISettings] Enabled) or "siteaccess" (DisabledSiteAccesses[])
     */
    public static function switchedOff( array $known, $where )
    {
        self::event( 'system.expai.request.refused', function () use ( $known, $where )
        {
            $data = array( 'object' => array( 'type' => 'expai_request', 'id' => 0,
                                              'name' => (string) ( !empty( $known['model_alias'] ) ? $known['model_alias'] : ( isset( $known['model'] ) ? $known['model'] : '' ) ) ),
                           'after' => self::compact( array( 'provider' => isset( $known['provider'] ) ? (string) $known['provider'] : '',
                                                            'action' => isset( $known['purpose'] ) ? (string) $known['purpose'] : '', 'switched_off' => (string) $where ) ),
                           'result' => 'refused', 'reason' => 'switched_off' );
            if ( !empty( $known['node_id'] ) )
                $data['target'] = array( 'type' => 'node', 'id' => (int) $known['node_id'] );
            return $data;
        } );
    }

    /**
     * A version written by Exponential Intelligence (expAiDraftLog::record()): content.expai_draft.write.
     *
     * @param expAiDraftLog $log
     */
    public static function draftWritten( $log )
    {
        if ( !self::available() || !$log )
            return;
        self::event( 'content.expai_draft.write', function () use ( $log )
        {
            return array( 'object' => self::draftObject( $log ), 'target' => self::draftTarget( $log ),
                          'after' => self::compact( array( 'source' => (string) $log->attribute( 'source' ), 'action' => (string) $log->attribute( 'action' ),
                                                           'version' => (int) $log->attribute( 'version' ), 'language' => (string) $log->attribute( 'language_code' ),
                                                           'status' => (string) $log->attribute( 'status' ), 'model' => (string) $log->attribute( 'model' ),
                                                           'provider' => (string) $log->attribute( 'provider' ),
                                                           'conversation_id' => (int) $log->attribute( 'conversation_id' ),
                                                           'thread_id' => (int) $log->attribute( 'thread_id' ), 'job' => (string) $log->attribute( 'job_uuid' ),
                                                           'package' => (string) $log->attribute( 'package_uuid' ), 'draft_id' => (int) $log->attribute( 'id' ) ) ) );
        } );
    }

    /**
     * A draft that changed state (expAiDraftLog::setStatus()): keep, submit (for approval), publish, discard, reopen.
     *
     * @param expAiDraftLog $log
     * @param string $from the state before
     * @param string $to
     */
    public static function draftState( $log, $from, $to )
    {
        if ( !self::available() || !$log || (string) $from === (string) $to || !isset( self::DRAFT_STATES[(string) $to] ) )
            return;
        self::event( self::DRAFT_STATES[(string) $to], function () use ( $log, $from, $to )
        {
            return array( 'object' => self::draftObject( $log ), 'target' => self::draftTarget( $log ),
                          'before' => array( 'status' => (string) $from ),
                          'after' => self::compact( array( 'status' => (string) $to, 'version' => (int) $log->attribute( 'version' ),
                                                           'source' => (string) $log->attribute( 'source' ), 'draft_id' => (int) $log->attribute( 'id' ) ) ) );
        } );
    }

    /**
     * A chain action of an editor (modules/expai/chain.php): start, approve, request changes, stop, resume.
     *
     * @param string $action start | approve | changes | stop | resume
     * @param int[] $runIDs
     * @param int $nodeID
     * @param string $chain the chain's identifier
     * @param string $error the class of the error when it failed, '' when it worked
     */
    public static function chain( $action, array $runIDs, $nodeID = 0, $chain = '', $error = '' )
    {
        if ( !in_array( $action, array( 'start', 'approve', 'changes', 'stop', 'resume' ), true ) )
            return;
        self::event( 'content.expai_chain.' . $action, function () use ( $runIDs, $nodeID, $chain, $error )
        {
            $data = array( 'object' => array( 'type' => 'expai_chain_run', 'id' => $runIDs ? (int) reset( $runIDs ) : 0, 'name' => (string) $chain ),
                           'after' => self::compact( array( 'chain' => (string) $chain, 'runs' => count( $runIDs ) > 1 ? array_map( 'intval', $runIDs ) : null ) ) );
            if ( $nodeID )
                $data['target'] = array( 'type' => 'node', 'id' => (int) $nodeID );
            if ( $error !== '' )
                $data += array( 'result' => 'failed', 'reason' => 'error', 'error' => array( 'ref' => (string) $error ) );
            return $data;
        } );
    }

    /**
     * An action on a model (Setup > EI: Models): load or unload a local model, test or load a model.
     *
     * @param string $action
     * @param string $model the model alias or the local model's name
     * @param string $error the class of the error when it failed
     */
    public static function modelAction( $action, $model, $error = '' )
    {
        self::event( 'system.expai.model.action', function () use ( $action, $model, $error )
        {
            $data = array( 'object' => array( 'type' => 'expai_model', 'id' => (string) $model ),
                           'after' => array( 'action' => (string) $action ) );
            if ( $error !== '' )
                $data += array( 'result' => 'failed', 'reason' => 'error', 'error' => array( 'ref' => (string) $error ) );
            return $data;
        } );
    }

    /**
     * A queued job held because of a budget (expAiBudget::hold()).
     *
     * @param expQueueJob $job
     */
    public static function budgetHold( $job )
    {
        if ( !$job )
            return;
        self::event( 'system.expai.budget.hold', function () use ( $job )
        {
            return array( 'object' => array( 'type' => 'expqueue_job', 'id' => (string) $job->attribute( 'uuid' ), 'name' => (string) $job->attribute( 'type' ) ),
                          'after' => self::compact( array( 'group_id' => (int) $job->attribute( 'group_id' ), 'reason' => 'budget' ) ),
                          'result' => 'refused', 'reason' => 'budget' );
        } );
    }

    /**
     * Held jobs resumed by hand (Setup > EI: Budgets).
     *
     * @param array $result resumed, still_held
     */
    public static function budgetResume( array $result )
    {
        self::event( 'system.expai.budget.resume', array( 'object' => array( 'type' => 'view', 'id' => 'expai/budgets' ),
            'after' => array( 'resumed' => (int) ( isset( $result['resumed'] ) ? $result['resumed'] : 0 ),
                              'still_held' => (int) ( isset( $result['still_held'] ) ? $result['still_held'] : 0 ) ) ) );
    }

    /**
     * A call of an external MCP server's tool: system.expai.mcp.call, .failed, or .refused (the node's data policy).
     *
     * @param string $server the server id
     * @param string $tool the tool's name on the server
     * @param int $nodeID
     * @param string $outcome success | failed | refused
     */
    public static function mcpCall( $server, $tool, $nodeID, $outcome )
    {
        $name = 'system.expai.mcp.call' . ( $outcome === 'failed' ? '.failed' : ( $outcome === 'refused' ? '.refused' : '' ) );
        self::event( $name, function () use ( $server, $tool, $nodeID, $outcome )
        {
            $data = array( 'object' => array( 'type' => 'expai_mcp_tool', 'id' => (string) $server . ':' . (string) $tool ),
                           'after' => array( 'server' => (string) $server, 'tool' => (string) $tool ) );
            if ( $nodeID )
                $data['target'] = array( 'type' => 'node', 'id' => (int) $nodeID );
            if ( $outcome === 'failed' )
                $data += array( 'result' => 'failed', 'reason' => 'error' );
            elseif ( $outcome === 'refused' )
                $data += array( 'result' => 'refused', 'reason' => 'data_policy' );
            return $data;
        } );
    }

    /**
     * The role policies of pre-release builds renamed (expAiPolicyMigration::run()).
     *
     * @param array[] $rows id, role_id, role, from, to
     */
    public static function policyMigration( array $rows )
    {
        if ( !$rows )
            return;
        $renamed = array();
        foreach ( $rows as $r )
            $renamed[] = array( 'policy' => (int) $r['id'], 'role' => (int) $r['role_id'], 'from' => (string) $r['from'], 'to' => (string) $r['to'] );
        self::event( 'system.expai.policy.migrate', array( 'object' => array( 'type' => 'module', 'id' => 'expai' ),
                                                           'after' => array( 'policies' => count( $rows ), 'renamed' => $renamed ) ) );
    }

    /** @return array the draft's node (what the node tab "Audit" lists), with the object and version */
    protected static function draftObject( $log )
    {
        $node = (int) $log->attribute( 'node_id' );
        $object = array( 'object_id' => (int) $log->attribute( 'contentobject_id' ), 'version' => (int) $log->attribute( 'version' ) );
        return $node ? array( 'type' => 'node', 'id' => $node ) + $object
                     : array( 'type' => 'object', 'id' => (int) $log->attribute( 'contentobject_id' ), 'version' => (int) $log->attribute( 'version' ) );
    }

    /** @return array|null the parent node of a new object's draft */
    protected static function draftTarget( $log )
    {
        $parent = (int) $log->attribute( 'parent_node_id' );
        return $parent && !(int) $log->attribute( 'node_id' ) ? array( 'type' => 'node', 'id' => $parent ) : null;
    }

    /** @return array the values that say something: no empty strings, no zero ids, no nulls */
    protected static function compact( array $values )
    {
        return array_filter( $values, function ( $v ) { return $v !== null && $v !== '' && $v !== 0 && $v !== array(); } );
    }
}
