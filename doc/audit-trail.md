# The audit trail

Next to its own usage log (`expai/log`, [usage-tracking.md](usage-tracking.md)) and draft log, expai records what it
does in the system's **audit trail**: the kernel's tamper-evident log of who did what and when (Audit tab,
`doc/en/us/bc/6.0/audit.md` of the Exponential distribution). The records are written by the kernel's audit writer, as
canonical JSON lines with a hash chain, into a channel of their own, **`ai`** (`var/log/audit/ai-<day>.jsonl`), so
they can be verified, archived, exported and forwarded to a SIEM like every other audit record, and given to an
auditor without the content channel.

The audit trail records **actions and metadata**. It never holds a prompt, an answer, the request summary of the
draft log, a provider's error text, a key, or personal data beyond the user id the kernel records for every event
(the login and the truncated address follow the kernel's privacy settings, `[AuditPrivacySettings]`).

## What is recorded

| Event | When | Object, target | Fields (`after`) |
|---|---|---|---|
| `system.expai.request` | every request sent to a model (panel, field action, preset, Vector, chain step, translation sync, alt text, test prompt, queued or batch job) | `expai_request` (the usage log row's id, named after the model alias); the node | provider, model, model alias, region, retention, purpose (action), lane, service tier, tool mode, the token counts under `usage` (`in`, `out`, `cache_write`, `cache_read`, `reasoning`: the audit masks every field whose name contains "token" as a secret), latency, HTTP status, retries, stop reason, conversation, turn, thread, run, job, batch, the fallback (the alias asked for and why), the estimated cost in millionths (`cost_micros`, with the currency) when the model has a price, and `user_id` when the request was made for another user (a queued job) |
| `system.expai.request.failed` | the request failed or the model refused (`reason` error or refusal) | as above | as above; `error.ref` is the error class, `error.message` only "HTTP <status>" |
| `system.expai.request.refused` | the veto refused it (`reason` veto), or the master switch is off (`reason` switched_off, `after.switched_off` site or siteaccess) | as above (id 0 when nothing was sent) | provider, model, purpose |
| `content.expai_draft.write` | a version written by expai (any source: panel, edit, preset, vector, chain, translation, revert, diff, queue, package) | the node, with the object id and version; for a new object the parent node as target | source, action, version, language, status, model, provider, conversation, thread, job, package, the draft log id |
| `content.expai_draft.keep`, `.submit`, `.publish`, `.discard`, `.reopen` | the draft's state changed (kept, sent for approval, published, discarded, back to draft) | as above | `before.status`, `after.status`, version, source |
| `content.expai_chain.start`, `.approve`, `.changes`, `.stop`, `.resume` | an editor started a chain or acted on a run | `expai_chain_run` (the first run's id, named after the chain); the node | chain, the run ids of a start with several runs |
| `system.expai.model.action` | Setup > EI: Models: a local model loaded or unloaded, a model test or load queued | `expai_model` (the alias or local model) | action |
| `system.expai.budget.hold` | a queued job held by a budget | `expqueue_job` (its UUID, named after its type) | group, reason budget |
| `system.expai.budget.resume` | Setup > EI: Budgets: "Resume held jobs" | the view | resumed, still held |
| `system.expai.mcp.call`, `.failed`, `.refused` | the model called a tool of an external MCP server; failed, or kept from leaving by the node's data policy | `expai_mcp_tool` (server:tool); the node | server, tool (never the arguments or the result) |
| `system.expai.policy.migrate` | `expaischema.php --install` renamed role policies of pre-release builds | module expai | count, per policy: id, role id, from, to |

Severities: info, except failed requests and failed MCP calls (warning), refusals, published and discarded drafts,
chain stops, model actions, budget holds and resumes and policy migrations (notice). All are **on** by default.

Not recorded here because the kernel already records them: changes of expai's settings, including the master switch
(`[AISettings] Enabled`) and the data policy, are `system.setting.write` records of the `system` channel when they are
written through `exp:ini` or the settings views; role changes are `access.role.*`. Service tools never write
(`'write' => true` services are refused, [service-tools.md](service-tools.md)), so their use is the request that
asked for them.

A request to a machine translation service (DeepL, LibreTranslate, [translation-providers.md](translation-providers.md))
writes the same `system.expai.request` (or `.failed`) event as a model request, with `provider` = the service's id
(`deepl`), `model` = its engine, `model alias` = `translation.<id>`, no token counts and the cost of its billed
characters. Its draft is audited by `content.expai_draft.write` as before (source `translation`, provider = the
service's id). No event of its own is needed.

## Where it is shown

With expai active, the audit console has an **Exponential Intelligence** source
(`classes/audit/expaiauditsource.php`, registered in `settings/audit.ini.append.php`):

- **Audit > Dashboard**: the panel "Exponential Intelligence": requests today and in 7 days, failed and refused
  requests, drafts written and published, jobs held by a budget, the estimated cost of the 7 days (from the usage
  log), the top users and models; links to the console filtered on the channel and, with `expai/log`, to EI: Log
  (all, errors only), EI: Drafts and EI: Budgets.
- **The sidebar** of the Audit tab: "AI events" (the console on the channel `ai`, with `audit/read`) and "EI: Log"
  (with `expai/write` and `expai/log`). A user whose `audit/read` is limited to other channels does not see "AI
  events": the kernel's audit menu leaves out every link into a channel the user may not read.
- **Export**: the CSV of the channel `ai` (`audit/export/(channel)/ai/(format)/csv`, or `exp:audit export
  --channel=ai --format=csv` with expai active) has, after the generic columns, `provider`, `model`, `model_alias`,
  `region`, `purpose`, `lane`, `tokens_in`, `tokens_out`, `latency_ms`, `http_status`, `status` (of a draft),
  `fallback_from`, `fallback_reason`, `cost_micros` and `currency` (`expAiAuditSource::exportColumns()`). Never a
  prompt, an answer, a draft's text or a key; the JSON exports carry the records as written.
- **Console, Recent events, Charts, Alerts, Export**: the channel `ai` in the channel filter ("ai (Exponential
  Intelligence)"), and a line of links: the AI events, failed requests, drafts, and EI: Log with the console's user,
  node and day range carried over as view parameters (`expai/log/(user)/14/(node)/275/(from)/2026-10-01`).
- **Charts**: "Exponential Intelligence per day": requests, failed, refused, drafts written, drafts published.
- **An event**: a card "Exponential Intelligence" with the provider, model, region and retention, purpose, tokens,
  estimated cost, latency, HTTP status, lane, fallback, draft source, version, language, chain, MCP server and tool;
  links to the request in EI: Log (`expai/log/(request)/<id>`, for `expai/log`, or for one's own request with
  `expai/write`), the thread's requests, EI: Drafts, the chain run, the queue job and EI: Budgets, each only with the
  policy its page needs. The node tab "Audit" of a node lists the drafts expai wrote for it.
- **Alerts**: the rule `expai_request_failures` (20 failed requests within 10 minutes for one model: a provider outage,
  a revoked key, a budget at the provider), written to syslog.
- **Roles**: the `Channel` limitation of `audit/read` offers "Exponential Intelligence" (`ai`): an AI auditor can be
  given exactly this channel and nothing of the content, access or system channels.

With expai not active none of this exists: its settings are not read, so the console shows exactly the kernel's
channels and cards. Files of the channel written while it was active stay in `var/log/audit/` and in the archives.

## Settings

`settings/audit.ini.append.php` of the extension registers the branch (`[AuditEventSettings] Branches[expai]`), the
channel (`[AuditChannelSettings] Channels[]=ai`, `[AuditChannel_ai]`: 90 days live, 730 archived, gzip), the source
(`[AuditSourceSettings] Sources[expai]`) and the alert rule. Change them in `settings/override/audit.ini.append.php`:

```ini
[AuditEventSettings]
# no record per model request, only failures, refusals and drafts
Disabled[]=system.expai.request

[AuditChannel_ai]
# one year
ArchiveDays=365
Sinks[]=syslog
```

The volume: one line (about 1 KB) per model request and per draft. A busy site with 5 000 requests a day writes about
5 MB a day into the channel.

## Checking it

```bash
./console exp:audit tail --channel=ai --allow-root-user
./console exp:audit search --name=system.expai.request.failed --allow-root-user
./console exp:audit verify --channel=ai --allow-root-user
```

`tests/expaiaudit_test.php` records every event through the real code paths with a stub provider, checks the channel,
the fields and that no prompt, answer or summary reaches the file, the CSV columns of the channel and the sidebar rule,
and removes what it wrote:

```bash
php bin/php/ezexec.php extension/expai/tests/expaiaudit_test.php -s admin
```
