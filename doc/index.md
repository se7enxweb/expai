# expai documentation

| Document | What it covers |
| --- | --- |
| [../README.md](../README.md) | What expai is, why, features, provider support, requirements, quick start |
| [../INSTALL.md](../INSTALL.md) | Requirements, activation, keys, tables, roles and policies, caches, Velocity, cron parts, streaming, checks, the install and upgrade checklist |
| [../USAGE.md](../USAGE.md) | For editors: the Intelligence tab, the edit form, the label, approval, the dashboard |
| [manual/README.md](manual/README.md) | The user manual: one question per page (ask the assistant, Vector, alt text, chains, drafts, usage), with who may do it, the steps and what can go wrong |
| [architecture.md](architecture.md) | How the parts fit: browser, ezjscore, the step loop, tools, provider, storage, long turns |
| [configuration.md](configuration.md) | Every setting of expai.ini and the other settings files, with default and effect |
| [providers.md](providers.md) | The provider registry, the anthropic and openai_chat adapters, models, the picker, tool modes, errors and retries |
| [demo-melious.md](demo-melious.md) | A demo of expai on Melious (EU, open-weight models): what to click, what it shows, the measured timings and cost |
| [provider-support.md](provider-support.md) | What is proven with which provider: live, against docs-derived mocks, or not yet |
| [data-policy.md](data-policy.md) | Regions and retention of providers, rules per section and class, where they are enforced |
| [switch-and-veto.md](switch-and-veto.md) | The master switch (on, off, off per siteaccess) at every entry point, and the veto hook: rules and the ezpEvent filter expai/request/veto |
| [probe.md](probe.md) | The tool-calling probe and the write gate |
| [local-models.md](local-models.md) | A local open-weight model: install, limits, the helper, expai/models |
| [mcp.md](mcp.md) | MCP tools: the in-process expmcp bridge and external servers |
| [lanes.md](lanes.md) | Lanes (standard, flex, batch, fast), the lane policy, queued requests, provider batches of five APIs |
| [budgets.md](budgets.md) | The monthly site budget and job group budgets: reservation, hold, resume, figures |
| [chains.md](chains.md) | AI chains: write, review, revise, SEO, the human checkpoint; defining a chain (worked example), prompts, pass criteria, budgets, configuration, permissions, database |
| [packages.md](packages.md) | Content packages are the kernel's (Setup > Packages, ezpm, the JSON format): what expai adds, the provenance metadata and the draft label |
| [package-authoring-for-agents.md](package-authoring-for-agents.md) | How a local CLI agent writes a package in the kernel's JSON format and brings it to the site with Setup > Packages or ezpm (terms, data, the validation loop, a minimal package) |
| [diff.md](diff.md) | The diff engine: segmenters per datatype, sentences, hunks with stable ids, metrics, compare and apply |
| [translation-providers.md](translation-providers.md) | Translation services: llm (the default), DeepL, LibreTranslate; rules per language pair and purpose, the fallback, no service, markup, language codes, cost, the run job, a service of your own |
| [translation-sync.md](translation-sync.md) | Translation sync: no-op / patch / full after a source change, thresholds, the workflow event, the Translations section, the CLI |
| [permissions.md](permissions.md) | Policies, and how the editor's own permissions limit everything the assistant does |
| [full-view-panel.md](full-view-panel.md) | The Intelligence tab: sections, conversation, history, context window, drafts |
| [threads.md](threads.md) | Threads: what a thread is, states and shelves, who sees one, events, retention, the migration |
| [vector.md](vector.md) | Vector (expai/vector): scope, plan and write, review, model and lane, run/stop/queue, polling and streaming, functions |
| [review.md](review.md) | Staged review in Vector: proposals instead of drafts, the Changes tab, accept/reject/edit per part and field, conflicts and rebase, apply into drafts |
| [plan-mode.md](plan-mode.md) | Plan mode for bulk work: propose_plan, the Plan tab, the estimate and its calibration, limits and reservation, the run of an approved plan |
| [parallel-runs.md](parallel-runs.md) | Parallel runs of an approved plan: child runs, dependencies, one field per run (held), progress and cost per item, the batch lane, workers, steering a running turn, slow models and the switch |
| [checkpoints.md](checkpoints.md) | Checkpoints of a Vector thread (each turn, before writing, before a plan): the strip, the restore preview, discard and set back drafts, cut the conversation, undo a restore |
| [keyboard.md](keyboard.md) | The command palette (Ctrl/Cmd+K), the shortcuts, your own keys, commands of your own |
| [edit-view-integration.md](edit-view-integration.md) | The edit form hook, field actions, how values reach Tiptap / the Online Editor |
| [alt-text.md](alt-text.md) | Alt text for images: the "Write alt text" button, vision models only, the data policy for images, image preparation and limits, the accessibility rules, the "Missing alt text" report and its draft jobs, cost |
| [presets.md](presets.md) | Editor presets per class (title, summary, meta description, keywords), the Improve menu, the defaults line, the palette entries |
| [summary.md](summary.md) | The printable "EI: Summary" page (expai/summary) for a data protection officer: providers, regions, key sources, roles, lanes, limits, tools |
| [dashboard.md](dashboard.md) | expai/dashboard (figures, chart, limits, Start writing, view parameters), the menu entries, the dashboard block, the log page |
| [usage-tracking.md](usage-tracking.md) | What is recorded per request, turns, the estimated cost and how to check it, limits, retention, privacy |
| [audit-trail.md](audit-trail.md) | What expai records in the system's audit trail (channel ai): the events and their fields, never prompts or answers; the panel, sidebar entries, links, chart and alert rule in the audit console; settings and checks |
| [tools.md](tools.md) | Every tool the model can call: input, result, permission checks, limits |
| [service-tools.md](service-tools.md) | Service tools: one expservices declaration as HTTP endpoint, MCP tool and assistant tool; opting in, checks, the tested example |
| [approval-workflow.md](approval-workflow.md) | Drafts, ezapprove, the collaboration item and the label, the tested path |
| [security.md](security.md) | The key, permissions as the user, no auto-publish, prompt injection, logging and privacy |
| [database.md](database.md) | The 15 tables, per engine, the install and upgrade script |
| [extending.md](extending.md) | Adding field actions, panel sections, tools, providers, JavaScript hooks (worked examples) |
| [customization.md](customization.md) | Template overrides, CSS tokens, labels, translations, instructions |
| [translations.md](translations.md) | Contexts, the shipped languages, adding one |
| [testing.md](testing.md) | The PHPUnit suite: running it, suites and groups, what the tests may touch, measuring coverage, the current figures |
| [troubleshooting.md](troubleshooting.md) | Failures and their fixes |
| [faq.md](faq.md) | Short answers |
| [changelog/0.0.1.15.md](changelog/0.0.1.15.md) | EI: Help, the dashboard's Help link and the "?" links open the help center directly |
| [changelog/0.0.1.16.md](changelog/0.0.1.16.md) | Translations in French, Ukrainian, Arabic, Persian, Pashto and Kurdish (Kurmanji) |
| [changelog/0.0.1.17.md](changelog/0.0.1.17.md) | The old help reader is removed; the documentation needs the help center of Exponential 6.0.15 |
| [changelog/0.0.1.19.md](changelog/0.0.1.19.md) | Translation services: DeepL and LibreTranslate next to the model, per language pair and purpose |
| [changelog/0.0.1.20.md](changelog/0.0.1.20.md) | What 0.0.1.20 changes: translations into more languages |
| [changelog/0.0.1.21.md](changelog/0.0.1.21.md) | What 0.0.1.21 changes: the admin stylesheets use the shared admin tokens |
| [changelog/0.0.1.2.md](changelog/0.0.1.2.md) | Every translation file validates again, so expai is translated |
| [changelog/0.0.1.3.md](changelog/0.0.1.3.md) | Draft log entries of removed objects show as removed, without an "Object not found" error |
| [changelog/0.0.1.1.md](changelog/0.0.1.1.md) | No "Decorative" choice for a field that requires an alt text |
| [changelog/0.0.1.0.md](changelog/0.0.1.0.md) | The first release |
| [examples/expaiexample](examples/expaiexample) | The example extension of extending.md |

## Help in the administration interface

Every page above is also part of the administration interface, in its help center (Help in the top bar, the
module `exphelp` of Exponential 6.0.15 and later): the help center shows the documentation of every active extension
in the folder and category **Extensions**, so this documentation is read, searched and printed there with the same
viewer, contents and search as the documentation of Exponential itself.

- **Dashboard > EI: Help** and the dashboard's Help link open the extension's index in the help center directly,
  `exphelp/extensions/expai`: every page of this documentation by folder, and a search limited to it.
- A page has the id `ext/expai/<path below doc/>` (`exphelp/viewer/ext/expai/vector.md`); the files at the top of the
  extension are `ext/expai/README.md`, `ext/expai/INSTALL.md`, `ext/expai/USAGE.md`, and a changelog is
  `ext/expai/changelog/<version>.md`.
- Each expai page (the dashboard, the Intelligence tab, Vector, the "Missing alt text" report, chains) has a "?" link
  straight to its page there. The templates name only the page (`{expai_help_url( 'vector' )|ezurl}`); the address
  comes from `expAiHelpLinks::link()` (classes/expaihelplinks.php), which maps the page names.
- Policy: `exphelp/read`, the policy of the help center (editors and administrators have it). The menu entry EI: Help
  is shown to users who hold it, whatever expai policy they have (a data protection officer with only `expai/summary`
  sees it too).

**The help center is required.** expai needs the help center of Exponential 6.0.15 or later. An older kernel
(6.0.14) has none: there EI: Help, the dashboard's Help link and the "?" links open nothing useful (the kernel's
"not found"). Read the documentation in the extension's `doc/` folder or on GitHub instead. The old redirect view
`expai/help`, its GitHub fallback and the menu fallback for such kernels were removed in 0.0.1.17.

The help center shows only the files the extension's repository tracks (or its release lists), never source files,
and renders them safely: every character of a document is escaped, raw HTML stays text, and links are kept only to
pages of the help, anchors and http(s) and mailto addresses. The pages are read from the installed files, so they
always match the installed version. Tests: `tests/phpunit/unit/core/expAiHelpLinksTest.php`, and the help center's
own (`tests/tests/kernel/classes/help/expHelpExtensionDocsTest.php` in Exponential).
