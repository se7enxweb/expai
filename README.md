# expai: Exponential Intelligence

Exponential Intelligence is a writing assistant for the administration interface of Exponential 6. Editors ask in
plain language for new content or for changes ("write three short articles about our spring events under this
folder", "rewrite the intro of this article, shorter"). The assistant looks up what it needs (class definitions, the
current content, the content tree around it) and writes **drafts**, as the logged-in editor and with that editor's
own permissions. Nothing is ever published on its own: the editor keeps the drafts or sends them through the normal
publishing, where an approval workflow holds them for the approver.

Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
Licensed under the GNU General Public License v2.0 (or any later version), see [LICENSE](LICENSE).

Version 0.0.1.3. What is new: [doc/changelog/0.0.1.3.md](doc/changelog/0.0.1.3.md).

## Why Exponential Intelligence

- **Drafts, never surprises.** Exponential Intelligence writes drafts as the logged-in editor, with that editor's
  permissions; publishing always goes through your normal workflow, including approvals.
- **Your data, your rules.** Choose providers by region (US, EU or on your own server) and retention, and decide per
  section and content class which content may go where; every request records where it went.
- **Costs you can see and cap.** Estimated cost per request, dashboards, limits per user, day, month and site, and
  budgets for queued work, with cheaper batch processing for bulk jobs.
- **Any model, including your own.** Anthropic, any OpenAI-compatible service, or a local open-weight model
  managed from the administration interface.
- **Built to extend.** One declared service is usable by people over HTTP, by AI agents over MCP and by the
  assistant itself, each with the caller's own permissions (read services in this release).

## Features

**For editors**

- **The Intelligence tab** on the content full view: a conversation per user and node. The assistant answers, asks
  back when a request is unclear, shows each lookup and each draft as a compact step, and lists the drafts it wrote
  with links to edit, view and discard them. The history is one collapsible row per exchange
  ([doc/full-view-panel.md](doc/full-view-panel.md)).
- **Save as drafts** and **Send for approval**: "Send for approval" runs the regular publish operation as the editor,
  so an approval workflow holds the version for the approver; each draft says beforehand which will happen
  ([doc/approval-workflow.md](doc/approval-workflow.md)).
- **In the edit form**: "Fill the fields" from a brief, per-field actions (Write, Rewrite, Shorten, Translate,
  Suggest), one-click presets (title, summary, meta description, keywords), an **Improve** menu on every text field
  and a "Defaults in use" line with the model, region and cost of a click. Values go into the form for review;
  autosave, the Online Editor and Tiptap keep working ([doc/edit-view-integration.md](doc/edit-view-integration.md),
  [doc/presets.md](doc/presets.md)).
- **Alt text for images**: a vision model suggests the alt text of an image field, within the data policy; the
  "Missing alt text" report queues suggestions for a subtree as drafts ([doc/alt-text.md](doc/alt-text.md)).
- **Vector**, the workspace of threads: scope, plan and write modes, model and lane per thread, runs in the
  background, live progress, staged review of every change per field and part before anything is written, plan mode
  with a cost estimate for bulk work, and a command palette (Ctrl/Cmd+K) ([doc/vector.md](doc/vector.md),
  [doc/threads.md](doc/threads.md), [doc/review.md](doc/review.md), [doc/plan-mode.md](doc/plan-mode.md),
  [doc/keyboard.md](doc/keyboard.md)).
- **AI chains**: multi-step writing through the job queue (write, review, revise, SEO, a human checkpoint, then
  translate) ([doc/chains.md](doc/chains.md)).
- **Translation sync**: after the source language changed, each translation is classified as unchanged, patch or
  full retranslation, with cost thresholds and the drafts waiting for an editor
  ([doc/translation-sync.md](doc/translation-sync.md), [doc/diff.md](doc/diff.md)).
- **Translation services**: the model translates by default; DeepL or a local LibreTranslate can take full
  translations, patches or the field action per language pair, with the data policy, budgets, the usage log and the
  audit trail as for a model, and a fallback only for configuration problems
  ([doc/translation-providers.md](doc/translation-providers.md)).
- **The "Exponential Intelligence" label** on every version it wrote: in My drafts, the drafts page, version views,
  edit forms and approval items.
- **The dashboard** (Dashboard > Exponential Intelligence): estimated cost, tokens, requests and drafts with trends,
  a chart per day, limits, Start writing at a preselected location, recent drafts, versions waiting for your approval
  ([doc/dashboard.md](doc/dashboard.md)).
- **Help in the administration interface**: every page of this documentation in the help center of the
  administration (Dashboard > EI: Help, `exphelp/extensions/expai`), searchable with the rest of the help, with a "?"
  link on each expai page ([doc/index.md](doc/index.md)).

**For administrators**

- **Providers and models**: a registry of providers (`anthropic`, `openai_chat` for every OpenAI-compatible service,
  or a class of your own) and models, with a picker that shows region, relative cost and state, and a fallback to the
  site default when the chosen model cannot answer ([doc/providers.md](doc/providers.md)).
- **Data policy**: region (`us`, `eu`, `local`) and retention per provider, rules per section and class, enforced in
  the panel, every tool, the edit form, alt text and MCP, and recorded per request ([doc/data-policy.md](doc/data-policy.md)).
- **Write gate and probe**: only models that passed a live tool-calling probe (or are trusted by configuration) may
  write drafts ([doc/probe.md](doc/probe.md)).
- **Local models**: a pinned Ollama on the loopback address with systemd limits, started, stopped and pulled from
  `expai/models` through a root-owned helper with an allowlist; the web process never runs a command
  ([doc/local-models.md](doc/local-models.md)).
- **Usage, cost and limits**: every request recorded (tokens, time, tools, errors; user ids only), the cost estimated
  from a price table, limits per conversation, session, user, day, month, site and provider
  ([doc/usage-tracking.md](doc/usage-tracking.md)).
- **Lanes, batches and budgets**: standard, flex, batch and fast lanes, provider batch APIs for bulk work, a monthly
  site budget and job group budgets with reservations ([doc/lanes.md](doc/lanes.md), [doc/budgets.md](doc/budgets.md)).
- **Content packages** are the kernel's (Setup > Packages, `ezpm`, classic or JSON format): AI provenance as package
  metadata, and the drafts of a package written with AI carry the Exponential Intelligence label
  ([doc/packages.md](doc/packages.md), [doc/package-authoring-for-agents.md](doc/package-authoring-for-agents.md)).
- **"EI: Summary"** (Setup > EI: Summary, `expai/summary`): one printable page for a data protection officer with every provider's region,
  retention, terms date and key source (never the key), roles, lanes, limits and outside tools
  ([doc/summary.md](doc/summary.md)).
- **Master switch and veto hook**: on, off, or off per siteaccess at every entry point, and rules or an event
  listener that can refuse a request with a reason ([doc/switch-and-veto.md](doc/switch-and-veto.md)).

**For developers**

- **Tools** the model can call (content lookups, drafts, web fetch, web search, project history), the in-process
  **expmcp bridge** and a client for external **MCP servers** with an allowlist and an SSRF guard
  ([doc/tools.md](doc/tools.md), [doc/mcp.md](doc/mcp.md)).
- **Service tools**: a read service declared once for expservices is also an MCP tool and an assistant tool, with the
  caller's permissions ([doc/service-tools.md](doc/service-tools.md)).
- **Extension points**: tools, field actions, panel and edit form sections, providers and JavaScript hooks, through
  INI settings and small classes, with a worked example extension ([doc/extending.md](doc/extending.md)).

Works on Apache with PHP-FPM and on Exponential Velocity, on every database engine of the kernel (MySQL/MariaDB,
PostgreSQL, SQLite, Oracle, MongoDB), in the admin, admin3 and admin4 designs; translations eng-US and ger-DE.

## Provider support

What has been run, and how ([doc/provider-support.md](doc/provider-support.md) has the full matrix):

| Provider | State |
| --- | --- |
| Anthropic (Claude) | proven live: the panel, tools, drafts, field actions, usage and cost, rate limits, Message Batches |
| A local open-weight model (Ollama, Qwen3.5 4B, through `openai_chat`) | proven live: the step loop, native tool calls, a probe of 14 of 15 tasks, a draft, a field action; slow on a shared CPU host (minutes per step) |
| Hugging Face router, OpenRouter | proven against mock servers built from the providers' documentation, plus a live keyless request (the 401 round trip); not yet with a key |
| OpenAI, Gemini, Mistral and other OpenAI-compatible services | the same adapter, proven against mocks only (lanes and batches included) |

## Requirements

- Exponential 6.0.14 or later, PHP 8.1 or later with `curl`, `dom`, `json` and `mbstring`; the documentation in the
  administration (EI: Help, the "?" links) needs the help center of Exponential 6.0.15.
- The extension **ezjscore**; recommended **ezformtoken**. For queued work (lanes, batches, chains, translation sync,
  alt text reports, large package imports, background runs of Vector) the extension **expqueue**.
- An API key of at least one provider, or a local model. Outbound HTTPS to the hosted providers you use.

## Quick start

1. Put the extension in `extension/expai` (`composer require se7enxweb/expai`, or clone it) and activate it for the
   admin siteaccess(es) ([INSTALL.md](INSTALL.md)).
2. Put the API key into `settings/override/expai.ini.append.php` (never commit that file):

   ```ini
   [AISettings]
   Enabled=true
   ApiKey=<your Anthropic API key>
   ```

3. Create the tables: `php extension/expai/bin/php/expaischema.php --install -s <admin siteaccess>`
4. Give editors the policy `expai/write`, regenerate the autoloads, clear the caches, and add the cron parts
   ([INSTALL.md](INSTALL.md), "Checklist").
5. Open a folder in the admin, choose the **Intelligence** tab and ask for something.

How it is used day to day: [USAGE.md](USAGE.md). All documentation: [doc/index.md](doc/index.md). The test suite
and its coverage: [doc/testing.md](doc/testing.md).

## Screenshots

Taken at 960 px width and device scale 2 during the tests of 0.0.1.0 and kept outside the repository, because they show
a real installation: the empty panel and a conversation with its steps and drafts; the history collapsed and
expanded; the edit form with "Fill the fields", the field actions, the presets and the Improve menu; the label in My
drafts, a version view and an approval item; the dashboard in light and dark and in German; the models page with the
local model; Vector with a thread, its scope, the command palette and the shortcuts; the job queue pages; the panel in
the admin, admin3 and admin4 designs, on Apache and on Velocity.

## Links

- Source: https://github.com/se7enxweb/expai
- Exponential: https://github.com/se7enxweb/exponential
- Anthropic API documentation: https://docs.anthropic.com/
