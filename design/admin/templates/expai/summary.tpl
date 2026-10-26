{* expai/summary (policy expai/summary): the "EI: Summary" page for a data protection officer (doc/summary.md). Everything is
   read from the settings, the roles, the probe results and the usage log as they are now. Never shows a key: only
   where it comes from. Printable: expai_summary.css hides the admin chrome for print (the browser's "Save as PDF"). *}
{ezcss_require( array( 'expai_summary.css' ) )}
{def $yes = 'on'|i18n( 'extension/expai/summary' )
     $no = 'off'|i18n( 'extension/expai/summary' )}
<div class="exp-ai exp-ai-summary" id="exp-ai-summary">
    <header class="exp-ai-head">
        <span class="exp-ai-mark" aria-hidden="true"></span>
        <div>
            <h1 class="context-title">{'EI: Summary'|i18n( 'extension/expai/summary' )}</h1>
            <p class="exp-ai-sub">{'What Exponential Intelligence sends where, how long it is kept, who may use it and what it costs. As of %date, %site, expai %version.'|i18n( 'extension/expai/summary',, hash( '%date', $summary.generated|l10n( 'shortdatetime' ), '%site', $summary.site, '%version', $summary.version ) )|wash}</p>
{if $summary.note}            <p class="exp-ai-summary-note">{$summary.note|wash}</p>{/if}
{if $summary.contact}            <p class="exp-ai-summary-note">{'Contact for questions about this processing: %contact'|i18n( 'extension/expai/summary',, hash( '%contact', $summary.contact ) )|wash}</p>{/if}
        </div>
        {include uri='design:expai/parts/help_link.tpl' page='summary' label='EI: Summary'|i18n( 'extension/expai/summary' ) text='?'}
        <p class="exp-ai-summary-noprint"><input type="button" class="button exp-ai-btn" data-exp-ai-print="1" value="{'Print or save as PDF'|i18n( 'extension/expai/summary' )}" /></p>
    </header>

    <section class="exp-ai-card exp-ai-summary-section" id="exp-ai-summary-switch" aria-labelledby="exp-ai-summary-switch-title">
        <h2 id="exp-ai-summary-switch-title">{'Master switch and surfaces'|i18n( 'extension/expai/summary' )}</h2>
        <dl class="exp-ai-figures">
            <div><dt>{'Exponential Intelligence ([AISettings] Enabled)'|i18n( 'extension/expai/summary' )}</dt><dd class="exp-ai-summary-state-{if $summary.switches.master}on{else}off{/if}">{if $summary.switches.master}{$yes}{else}{$no}{/if}{if and( $summary.switches.master, $summary.switches.configured|not )} ({'not configured'|i18n( 'extension/expai/summary' )}){/if}</dd></div>
            <div><dt>{'Edit form controls'|i18n( 'extension/expai/summary' )}</dt><dd>{if $summary.switches.edit_form}{$yes}{else}{$no}{/if}</dd></div>
            <div><dt>{'Editor presets and Improve menu'|i18n( 'extension/expai/summary' )}</dt><dd>{if $summary.switches.presets}{$yes}{else}{$no}{/if}</dd></div>
            <div><dt>{'Vector workspace'|i18n( 'extension/expai/summary' )}</dt><dd>{if $summary.switches.vector}{$yes}{else}{$no}{/if}</dd></div>
            <div><dt>{'Translation sync'|i18n( 'extension/expai/summary' )}</dt><dd>{if $summary.switches.translation_sync}{$yes}{else}{$no}{/if}</dd></div>
            <div><dt>{'Users may choose a model'|i18n( 'extension/expai/summary' )}</dt><dd>{if $summary.switches.user_model_choice}{$yes}{else}{$no}{/if}</dd></div>
{if is_set( $summary.switches.service_tools )}
            <div><dt>{'Service tools'|i18n( 'extension/expai/summary' )}</dt><dd>{if $summary.switches.service_tools}{$yes}{else}{$no}{/if}</dd></div>
{/if}
        </dl>
        <p class="exp-ai-hint">{'Every request runs as the editor, within the editor\'s own permissions, and writes drafts only; nothing is published without the usual publish or approval.'|i18n( 'extension/expai/summary' )}</p>
    </section>

    <section class="exp-ai-card exp-ai-summary-section" id="exp-ai-summary-providers" aria-labelledby="exp-ai-summary-providers-title">
        <h2 id="exp-ai-summary-providers-title">{'Providers'|i18n( 'extension/expai/summary' )}</h2>
{foreach $summary.providers as $p}
        <div class="exp-ai-summary-provider" data-provider="{$p.id|wash}">
            <h3>{$p.label|wash} <small>({$p.id|wash}{if $p.host}, {$p.host|wash}{/if})</small></h3>
            <dl class="exp-ai-figures">
                <div><dt>{'Region'|i18n( 'extension/expai/summary' )}</dt><dd>{$p.region_label|wash}</dd></div>
                <div><dt>{'Retention'|i18n( 'extension/expai/summary' )}</dt><dd>{$p.retention|expai_retention|wash}</dd></div>
                <div><dt>{'Terms checked'|i18n( 'extension/expai/summary' )}</dt><dd>{if $p.terms_checked_at}{$p.terms_checked_at|wash}{else}{'not checked'|i18n( 'extension/expai/summary' )}{/if}</dd></div>
                <div><dt>{'Key'|i18n( 'extension/expai/summary' )}</dt><dd>{if $p.key.set}{'From %file (%placement)'|i18n( 'extension/expai/summary',, hash( '%file', $p.key.file, '%placement', $p.key.placement ) )|wash}{elseif $p.key.needed|not}{'No key needed'|i18n( 'extension/expai/summary' )}{else}<span class="exp-ai-step-failed">{'No key'|i18n( 'extension/expai/summary' )}</span>{/if}</dd></div>
                <div><dt>{'Who may use it'|i18n( 'extension/expai/summary' )}</dt><dd>{if $p.everyone}{'every user with expai/write'|i18n( 'extension/expai/summary' )}{else}{'users with expai/model_use for this provider'|i18n( 'extension/expai/summary' )}{/if}{if $p.roles|count}: {$p.roles|implode( ', ' )|wash}{/if}</dd></div>
                <div><dt>{'This month'|i18n( 'extension/expai/summary' )}</dt><dd>{'%requests requests, %tokens tokens, %cost'|i18n( 'extension/expai/summary',, hash( '%requests', $p.usage.requests, '%tokens', $p.usage.tokens, '%cost', $p.usage.cost ) )|wash}{foreach $p.limits as $l} &middot; {'limit %max per month'|i18n( 'extension/expai/summary',, hash( '%max', $l.max ) )|wash} ({$l.unit|wash}){/foreach}</dd></div>
            </dl>
            <table class="list exp-ai-summary-table" cellspacing="0">
                <thead><tr><th>{'Model'|i18n( 'extension/expai/summary' )}</th><th>{'Tools'|i18n( 'extension/expai/summary' )}</th><th>{'Write gate'|i18n( 'extension/expai/summary' )}</th><th>{'Probe'|i18n( 'extension/expai/summary' )}</th><th>{'Cost'|i18n( 'extension/expai/summary' )}</th></tr></thead>
                <tbody>
{foreach $p.models as $m}
                    <tr>
                        <td>{$m.label|wash}{if $m.default} <em>{'(site default)'|i18n( 'extension/expai/summary' )}</em>{/if}<br /><code>{$m.id|wash}</code></td>
                        <td>{$m.tool_use|wash}</td>
                        <td>{if $m.writes}{'may write drafts'|i18n( 'extension/expai/summary' )}{if eq( $m.write_gate, 'trusted' )} ({'trusted'|i18n( 'extension/expai/summary' )}){/if}{else}{'read only'|i18n( 'extension/expai/summary' )}<br /><small>{$m.write_refusal|wash}</small>{/if}</td>
                        <td>{if $m.probe}{'%rate% on %date'|i18n( 'extension/expai/summary',, hash( '%rate', $m.probe.pass_rate, '%date', $m.probe.created|l10n( 'shortdate' ) ) )|wash}{else}{'no live probe'|i18n( 'extension/expai/summary' )}{/if}</td>
                        <td>{$m.cost_class|wash}</td>
                    </tr>
{/foreach}
                </tbody>
            </table>
        </div>
{/foreach}
        <p class="exp-ai-hint">{'A model writes drafts only with native tool calling and a live probe pass rate of at least %threshold%, unless it is marked trusted.'|i18n( 'extension/expai/summary',, hash( '%threshold', first_set( $summary.providers.0.threshold, 90 ) ) )|wash}</p>
    </section>

    <section class="exp-ai-card exp-ai-summary-section" id="exp-ai-summary-translation" aria-labelledby="exp-ai-summary-translation-title">
        <h2 id="exp-ai-summary-translation-title">{'Translation services'|i18n( 'extension/expai/summary' )}</h2>
        <p>{'Default service: %default; when the chosen service cannot translate a pair: %fallback.'|i18n( 'extension/expai/summary',, hash( '%default', $summary.translation.default, '%fallback', $summary.translation.fallback ) )|wash}</p>
{if $summary.translation.rules|count}
        <table class="list exp-ai-summary-table" cellspacing="0">
            <thead><tr><th>{'Rule'|i18n( 'extension/expai/summary' )}</th><th>{'Service'|i18n( 'extension/expai/summary' )}</th></tr></thead>
            <tbody>
{foreach $summary.translation.rules as $r}
                <tr><td><code>{$r.rule|wash}</code></td><td>{$r.provider|wash}</td></tr>
{/foreach}
            </tbody>
        </table>
{/if}
        <table class="list exp-ai-summary-table" cellspacing="0">
            <thead><tr><th>{'Service'|i18n( 'extension/expai/summary' )}</th><th>{'Kind'|i18n( 'extension/expai/summary' )}</th><th>{'Region'|i18n( 'extension/expai/summary' )}</th><th>{'Retention'|i18n( 'extension/expai/summary' )}</th><th>{'Key'|i18n( 'extension/expai/summary' )}</th><th>{'In use'|i18n( 'extension/expai/summary' )}</th></tr></thead>
            <tbody>
{foreach $summary.translation.services as $t}
                <tr>
                    <td>{$t.label|wash}<br /><code>{$t.id|wash}</code></td>
                    <td>{if eq( $t.kind, 'mt' )}{'machine translation'|i18n( 'extension/expai/summary' )}{else}{'model'|i18n( 'extension/expai/summary' )}{/if}</td>
                    <td>{$t.region_label|wash}</td>
                    <td>{$t.retention|expai_retention|wash}{if $t.terms_checked_at} ({$t.terms_checked_at|wash}){/if}</td>
                    <td>{if $t.key.set}{'From %file (%placement)'|i18n( 'extension/expai/summary',, hash( '%file', $t.key.file, '%placement', $t.key.placement ) )|wash}{elseif $t.key.needed|not}{'No key needed'|i18n( 'extension/expai/summary' )}{else}{'No key'|i18n( 'extension/expai/summary' )}{/if}</td>
                    <td>{if $t.configured}{$yes}{else}{$no}<br /><small>{$t.reason|wash}</small>{/if}</td>
                </tr>
{/foreach}
            </tbody>
        </table>
        <p class="exp-ai-hint">{'Only the text of the blocks to translate goes to a machine translation service (with SendContext also the blocks next to them), never other fields or the name of the object.'|i18n( 'extension/expai/summary' )}</p>
    </section>

    <section class="exp-ai-card exp-ai-summary-section" id="exp-ai-summary-lanes" aria-labelledby="exp-ai-summary-lanes-title">
        <h2 id="exp-ai-summary-lanes-title">{'Lanes and their retention'|i18n( 'extension/expai/summary' )}</h2>
        <table class="list exp-ai-summary-table" cellspacing="0">
            <thead><tr><th>{'Provider'|i18n( 'extension/expai/summary' )}</th><th>{'Lane'|i18n( 'extension/expai/summary' )}</th><th>{'Kind'|i18n( 'extension/expai/summary' )}</th><th>{'Retention'|i18n( 'extension/expai/summary' )}</th><th>{'Price'|i18n( 'extension/expai/summary' )}</th><th>{'In use'|i18n( 'extension/expai/summary' )}</th></tr></thead>
            <tbody>
{foreach $summary.providers as $p}{foreach $p.lanes as $l}
                <tr>
                    <td>{$p.label|wash}</td>
                    <td>{$l.name|wash}</td>
                    <td>{if $l.batch}{'batch'|i18n( 'extension/expai/summary' )}{else}{'direct'|i18n( 'extension/expai/summary' )}{/if}</td>
                    <td>{$l.retention|expai_retention|wash}{if $l.zero_retention} ({'nothing kept'|i18n( 'extension/expai/summary' )}){/if}{if $l.delete_after_fetch}<br /><small>{'deleted by expai after the results are fetched'|i18n( 'extension/expai/summary' )}</small>{/if}</td>
                    <td>{$l.factor}%</td>
                    <td>{if $l.usable}{$yes}{else}{$no}{/if}</td>
                </tr>
{/foreach}{/foreach}
            </tbody>
        </table>
    </section>

    <section class="exp-ai-card exp-ai-summary-section" id="exp-ai-summary-policy" aria-labelledby="exp-ai-summary-policy-title">
        <h2 id="exp-ai-summary-policy-title">{'Data policy'|i18n( 'extension/expai/summary' )}</h2>
{if $summary.policy.active|not}
        <p>{'No data policy rule is set: content may go to every active provider above.'|i18n( 'extension/expai/summary' )}</p>
{else}
        <dl class="exp-ai-figures">
            <div><dt>{'All content'|i18n( 'extension/expai/summary' )}</dt><dd>{if $summary.policy.default_regions|count}{$summary.policy.default_regions|implode( ', ' )|wash}{else}{'every region'|i18n( 'extension/expai/summary' )}{/if}</dd></div>
            <div><dt>{'Zero retention for all content'|i18n( 'extension/expai/summary' )}</dt><dd>{if $summary.policy.zero_retention}{$yes}{else}{$no}{/if}</dd></div>
        </dl>
{if or( $summary.policy.sections|count, $summary.policy.classes|count )}
        <table class="list exp-ai-summary-table" cellspacing="0">
            <thead><tr><th>{'Content'|i18n( 'extension/expai/summary' )}</th><th>{'May go to the regions'|i18n( 'extension/expai/summary' )}</th></tr></thead>
            <tbody>
{foreach $summary.policy.sections as $r}<tr><td>{'Section %name'|i18n( 'extension/expai/summary',, hash( '%name', $r.what ) )|wash}</td><td>{$r.regions|wash}</td></tr>{/foreach}
{foreach $summary.policy.classes as $r}<tr><td>{'Class %name'|i18n( 'extension/expai/summary',, hash( '%name', $r.what ) )|wash}</td><td>{$r.regions|wash}</td></tr>{/foreach}
            </tbody>
        </table>
{/if}
{if or( $summary.policy.zero_retention_sections|count, $summary.policy.zero_retention_classes|count )}
        <p>{'Zero retention is required for: %list'|i18n( 'extension/expai/summary',, hash( '%list', $summary.policy.zero_retention_sections|merge( $summary.policy.zero_retention_classes )|implode( ', ' ) ) )|wash}</p>
{/if}
{/if}
        <p class="exp-ai-hint">{'Images, files and relations are never sent; only the text fields the editor works on and their context.'|i18n( 'extension/expai/summary' )}</p>
    </section>

    <section class="exp-ai-card exp-ai-summary-section" id="exp-ai-summary-limits" aria-labelledby="exp-ai-summary-limits-title">
        <h2 id="exp-ai-summary-limits-title">{'Limits, budget and usage this month'|i18n( 'extension/expai/summary' )}</h2>
        <p>{'All users this month: %requests requests, %tokens tokens, %cost (estimated from list prices; the provider\'s invoice is authoritative).'|i18n( 'extension/expai/summary',, hash( '%requests', $summary.limits.month.requests, '%tokens', $summary.limits.month.tokens, '%cost', $summary.limits.month.cost ) )|wash}</p>
{if $summary.limits.budget}
        <p>{'Monthly budget of queued requests: %budget, spent %spent.'|i18n( 'extension/expai/summary',, hash( '%budget', $summary.limits.budget, '%spent', $summary.limits.budget_spent ) )|wash}</p>
{/if}
{if $summary.limits.rows|count}
        <table class="list exp-ai-summary-table" cellspacing="0">
            <thead><tr><th>{'Limit'|i18n( 'extension/expai/summary' )}</th><th>{'For'|i18n( 'extension/expai/summary' )}</th><th>{'Maximum'|i18n( 'extension/expai/summary' )}</th><th>{'Used'|i18n( 'extension/expai/summary' )}</th></tr></thead>
            <tbody>
{foreach $summary.limits.rows as $r}
                <tr><td><code>{$r.setting|wash}{if $r.provider}[{$r.provider|wash}]{/if}</code></td><td>{if eq( $r.scope, 'site' )}{'the whole site'|i18n( 'extension/expai/summary' )}{else}{'each user'|i18n( 'extension/expai/summary' )}{/if}, {$r.period|wash}</td><td>{$r.max|wash}</td><td>{if $r.used}{$r.used|wash} ({$r.percent}%){else}-{/if}</td></tr>
{/foreach}
            </tbody>
        </table>
{else}
        <p>{'No limit is set ([Limits] in expai.ini).'|i18n( 'extension/expai/summary' )}</p>
{/if}
    </section>

    <section class="exp-ai-card exp-ai-summary-section" id="exp-ai-summary-tools" aria-labelledby="exp-ai-summary-tools-title">
        <h2 id="exp-ai-summary-tools-title">{'Tools that reach outside the site'|i18n( 'extension/expai/summary' )}</h2>
        <dl class="exp-ai-figures">
            <div><dt>web_fetch</dt><dd>{if $summary.tools.web_fetch.configured}{'on, for: %domains'|i18n( 'extension/expai/summary',, hash( '%domains', $summary.tools.web_fetch.domains|implode( ', ' ) ) )|wash}{if $summary.tools.web_fetch.robots} ({'respects robots.txt'|i18n( 'extension/expai/summary' )}){/if}{else}{$no}{/if}</dd></div>
            <div><dt>web_search</dt><dd>{if $summary.tools.web_search.configured}{'on: %provider, region %region, retention %retention'|i18n( 'extension/expai/summary',, hash( '%provider', $summary.tools.web_search.provider, '%region', $summary.tools.web_search.region_label, '%retention', $summary.tools.web_search.retention ) )|wash}{if $summary.tools.web_search.key.set}; {'key from %file'|i18n( 'extension/expai/summary',, hash( '%file', $summary.tools.web_search.key.file ) )|wash}{/if}{else}{$no}{/if}</dd></div>
            <div><dt>project_history</dt><dd>{if $summary.tools.project_history.configured}{'on, %count repositories'|i18n( 'extension/expai/summary',, hash( '%count', $summary.tools.project_history.repositories ) )|wash}{else}{$no}{/if}</dd></div>
            <div><dt>{'MCP bridge (site tools in process)'|i18n( 'extension/expai/summary' )}</dt><dd>{if $summary.tools.mcp_bridge.enabled}{'on: %tools'|i18n( 'extension/expai/summary',, hash( '%tools', $summary.tools.mcp_bridge.tools|implode( ', ' ) ) )|wash}{else}{$no}{/if}</dd></div>
            <div><dt>{'External MCP servers'|i18n( 'extension/expai/summary' )}</dt><dd>{if $summary.tools.mcp_servers|count}{foreach $summary.tools.mcp_servers as $s}{$s.label|wash} ({$s.host|wash}, {$s.region_label|wash}{if $s.token.set}, {'token from %file'|i18n( 'extension/expai/summary',, hash( '%file', $s.token.file ) )|wash}{/if}){delimiter}; {/delimiter}{/foreach}{else}{'none'|i18n( 'extension/expai/summary' )}{/if}</dd></div>
        </dl>
    </section>

    <section class="exp-ai-card exp-ai-summary-section" id="exp-ai-summary-retention" aria-labelledby="exp-ai-summary-retention-title">
        <h2 id="exp-ai-summary-retention-title">{'What this site keeps'|i18n( 'extension/expai/summary' )}</h2>
        <dl class="exp-ai-figures">
            <div><dt>{'Usage log (tokens, cost, model; user ids, no names)'|i18n( 'extension/expai/summary' )}</dt><dd>{if $summary.retention.usage_days}{'%days days'|i18n( 'extension/expai/summary',, hash( '%days', $summary.retention.usage_days ) )|wash}{else}{'kept'|i18n( 'extension/expai/summary' )}{/if}</dd></div>
            <div><dt>{'Full requests in the log'|i18n( 'extension/expai/summary' )}</dt><dd>{if $summary.retention.log_requests}<span class="exp-ai-step-failed">{$yes}</span>{else}{$no}{/if}</dd></div>
            <div><dt>{'Summary of the editor\'s request in the draft log'|i18n( 'extension/expai/summary' )}</dt><dd>{'at most %chars characters'|i18n( 'extension/expai/summary',, hash( '%chars', $summary.retention.prompt_summary ) )|wash}</dd></div>
            <div><dt>{'Thread events, proposals, checkpoints'|i18n( 'extension/expai/summary' )}</dt><dd>{'%events / %proposals / %checkpoints days'|i18n( 'extension/expai/summary',, hash( '%events', $summary.retention.thread_events, '%proposals', $summary.retention.thread_proposals, '%checkpoints', $summary.retention.thread_checkpoints ) )|wash}</dd></div>
        </dl>
    </section>

    <section class="exp-ai-card exp-ai-summary-section" id="exp-ai-summary-roles" aria-labelledby="exp-ai-summary-roles-title">
        <h2 id="exp-ai-summary-roles-title">{'Roles with Exponential Intelligence policies'|i18n( 'extension/expai/summary' )}</h2>
{if $summary.roles|count}
        <table class="list exp-ai-summary-table" cellspacing="0">
            <thead><tr><th>{'Role'|i18n( 'extension/expai/summary' )}</th><th>{'Policies'|i18n( 'extension/expai/summary' )}</th></tr></thead>
            <tbody>
{foreach $summary.roles as $r}
                <tr><td>{$r.name|wash}</td><td>{foreach $r.policies as $pol}<code>expai/{$pol.function|wash}</code>{if $pol.limitations|count} ({foreach $pol.limitations as $id => $values}{$id|wash}: {$values|implode( ', ' )|wash}{delimiter}; {/delimiter}{/foreach}){/if}{delimiter}, {/delimiter}{/foreach}</td></tr>
{/foreach}
            </tbody>
        </table>
{else}
        <p>{'No role has a policy of Exponential Intelligence.'|i18n( 'extension/expai/summary' )}</p>
{/if}
    </section>
</div>
{undef $yes $no}
{* "Print or save as PDF" (data-exp-ai-print): expai_page_actions.js, no inline code *}
{ezscript( array( 'expai_page_actions.js' ) )}
