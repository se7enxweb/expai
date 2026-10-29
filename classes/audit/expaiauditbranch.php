<?php
/**
 * File containing the expAiAuditBranch class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The audit taxonomy branch of Exponential Intelligence (audit.ini [AuditEventSettings] Branches[expai]): the names
 * expAiAudit records, all into the channel "ai", all on by default (switch one off with Disabled[] in audit.ini).
 * Labels are translated in the context kernel/audit, like the kernel's own. doc/audit-trail.md lists them.
 */
class expAiAuditBranch implements expAuditTaxonomyBranch
{
    public function events()
    {
        $on = function ( $label, $severity = 'info' )
        {
            return array( 'label' => $label, 'severity' => $severity, 'default' => 'on', 'channel' => expAiAudit::CHANNEL );
        };
        return array(
            'system.expai.request' => $on( 'Model request' ),
            'system.expai.request.failed' => $on( 'Model request failed', 'warning' ),
            'system.expai.request.refused' => $on( 'Model request refused', 'notice' ),
            'content.expai_draft.write' => $on( 'Draft written by Exponential Intelligence' ),
            'content.expai_draft.keep' => $on( 'Draft kept' ),
            'content.expai_draft.submit' => $on( 'Draft sent for approval' ),
            'content.expai_draft.publish' => $on( 'Draft published', 'notice' ),
            'content.expai_draft.discard' => $on( 'Draft discarded', 'notice' ),
            'content.expai_draft.reopen' => $on( 'Draft back in progress' ),
            'content.expai_chain.start' => $on( 'Chain started' ),
            'content.expai_chain.approve' => $on( 'Chain checkpoint approved' ),
            'content.expai_chain.changes' => $on( 'Chain changes requested' ),
            'content.expai_chain.stop' => $on( 'Chain stopped', 'notice' ),
            'content.expai_chain.resume' => $on( 'Chain resumed' ),
            'system.expai.model.action' => $on( 'Model action', 'notice' ),
            'system.expai.budget.hold' => $on( 'Job held by a budget', 'notice' ),
            'system.expai.budget.resume' => $on( 'Held jobs resumed', 'notice' ),
            'system.expai.mcp.call' => $on( 'External MCP tool called' ),
            'system.expai.mcp.call.failed' => $on( 'External MCP tool failed', 'warning' ),
            'system.expai.mcp.call.refused' => $on( 'External MCP tool refused', 'notice' ),
            'system.expai.policy.migrate' => $on( 'Policies migrated', 'notice' ),
        );
    }
}
