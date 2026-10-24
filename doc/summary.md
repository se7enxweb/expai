# The "EI: Summary" page

`expai/summary` (Setup > EI: Summary, and a link in the Details of `expai/dashboard`) is one printable page for a data
protection officer or anyone who has to answer "what does the AI feature send where?". It is read-only and has no
view parameters: every fact is read from the settings, the roles, the probe results and the usage log at the moment
the page is opened, so it never goes stale.

Policy: `expai/summary` (no limitation). The Administrator role has it; give it to a role of its own for a data
protection officer, who then needs no other expai policy and no access to content.

## What it shows

| Section | What it lists |
| --- | --- |
| Master switch and surfaces | `[AISettings] Enabled` (and whether a model is configured), the edit form controls, the presets, Vector, the translation sync, whether users may choose a model, the service tools |
| Providers | per active provider: host, region, retention, the date the terms were checked (`TermsCheckedAt`), **where the key comes from** (the settings file and its placement, e.g. `settings/override/expai.ini.append.php (override)`; never the key), who may use it (every user with `expai/write` for providers in `ProvidersWithoutPolicy[]`, else the roles with `expai/model_use` for it), this month's requests, tokens and estimated cost, the provider's monthly limits; per model: id, tool mode, the write gate (may write drafts, or read only and why), the latest live probe (pass rate and date), the price class |
| Translation services | the default service and the fallback of `[TranslationSettings]`, every rule `ProviderFor[]`, and every translation service (llm, DeepL, LibreTranslate, ...): its kind (model or machine translation), region, retention, where its key comes from and whether it can be used (else why) ([translation-providers.md](translation-providers.md)) |
| Lanes and their retention | every configured lane of every provider: direct or batch, retention (and whether nothing is kept), whether expai deletes a batch after fetching its results, the price factor, whether the lane is usable |
| Data policy | the default regions, the regions per section (with the section's name) and per class, the zero-retention rules; or that no rule is set |
| Limits, budget and usage this month | every limit that is set ([Limits]), for the whole site with its use this month; the monthly budget of queued requests and what was spent; all requests of the month with tokens and estimated cost |
| Tools that reach outside the site | web_fetch (the allowed domains, robots.txt), web_search (the search service, its region and retention, where its key comes from), project_history, the MCP bridge and its tools, external MCP servers (host, region, where the token comes from) |
| What this site keeps | the usage log retention (`[UsageSettings] RetentionDays`), whether full requests are logged, the length of the request summary in the draft log, the retention of thread events, proposals and checkpoints |
| Roles with Exponential Intelligence policies | every role with an `expai/*` policy (or every module), its functions and limitations |

Two free texts come from `[SummarySettings]`: `Contact` (who answers questions about the processing) and `Note` (for
example the reference of the record of processing activities). Both are printed under the title.

## Printing and PDF

**Print or save as PDF** opens the browser's print dialog. The print stylesheet (`expai_summary.css`) hides the admin
chrome (menus, header, path, footer, debug output) and prints the page black on white with section headings kept
with their tables; "Save as PDF" in the print dialog gives the PDF. Nothing is generated on the server.

## Never a key

The page names the settings file a key or token is set in and its placement, and says "No key" when one is needed
and missing, or "No key needed" (a local server, `ApiKeyHeader=none`). The key itself is never read into the page.
Costs are estimates from list prices ([usage-tracking.md](usage-tracking.md)); the provider's invoice is
authoritative.

## Files

| File | Part |
| --- | --- |
| `classes/expaisummary.php` | `expAiSummary::collect()`: every fact |
| `modules/expai/summary.php` | the view `expai/summary` |
| `design/admin/templates/expai/summary.tpl` | the page |
| `design/admin/stylesheets/expai_summary.css` | screen and print styles |
