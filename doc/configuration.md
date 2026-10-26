# Configuration

All of expai's own settings are in `expai.ini` (defaults in `extension/expai/settings/expai.ini.append.php`).
Change them in `settings/override/expai.ini.append.php` (or a siteaccess's `expai.ini.append.php`), never in the
extension. After a change, clear the INI and template caches (the templates read some settings when they are
compiled) and, on Velocity, restart it:

```
php bin/php/ezcache.php --clear-tag=ini
php bin/php/ezcache.php --clear-id=template,template-override,content,template-block,ezjscore-packer
```

**Lists.** expai's lists (`Tools[]`, `WritableDatatypes[]`, `Sections[]`, `FieldActions[]`, `Datatypes[]`) are not
emptied in the extension, so another extension can add to them whatever its place in the extension order. To replace
a list, empty it where you set it: a line with only `FieldActions[]`, then the entries you want.

## [AISettings]: the provider

| Setting | Default | Effect |
| --- | --- | --- |
| `Enabled` | `false` | The master switch: `true` or `enabled` switches the assistant on. Off: no tab, no edit form controls, no Vector, nothing queued, queued jobs held, no request to any provider; the dashboard says "Switched off" ([switch-and-veto.md](switch-and-veto.md)). |
| `DisabledSiteAccesses[]` | (none) | Siteaccesses on which expai is off although `Enabled` is on; a queued request is checked against the siteaccess it was queued from. |
| `Provider` | `anthropic` | `anthropic` (the built-in Anthropic Messages API) or `custom` (the class in `ProviderClass`). |
| `ProviderClass` | (empty) | With `Provider=custom`: a class implementing `expAiProvider` (extending.md). |
| `Endpoint` | `https://api.anthropic.com/v1/messages` | The Messages API URL (http or https, no credentials in it). |
| `AnthropicVersion` | `2023-06-01` | The `anthropic-version` header. |
| `Model` | `claude-sonnet-5-5` | The model. The dashboard shows it; the log records the model that answered. |
| `AllowUserModelChoice` | `enabled` | Users may pick another model from `AllowedModels[]` (dashboard, panel, edit form); the choice is the preference `expai_model`. A choice that is no longer allowed falls back to `Model`. |
| `AllowedModels[]` | `claude-sonnet-5-5`, `claude-opus-5-5`, `claude-haiku-5-5` | The models offered. `Model` is always offered. |
| `ModelLabels[<id>]` | Claude Sonnet 5.5, ... | The name shown for a model id (the id when not set). |
| `ApiKey` | (empty) | The API key. In settings/override or an untracked `KeyLocations[]` location, never committed. Without it expai is "not configured". |
| `AnthropicWorkspaceId` | (empty) | For a key that is not scoped to a workspace: sent as `anthropic-workspace-id`. |
| `MaxTokens` | `16000` | The longest answer of one call, in tokens, thinking included. Requests are sent without streaming; with `Timeout=115` an answer this long may run out of time first. |
| `Effort` | `medium` | How much the model thinks: `low`, `medium`, `high` (`xhigh`, `max` where the model has them); empty: the model's default. |
| `RefusalFallback` | `enabled` | Sends `fallbacks: "default"`: a request the model declines for a safety reason is retried on the model Anthropic recommends (Claude API only; set `disabled` for other endpoints). Only for the models that accept it: Claude Fable 5.1, Mythos 5.1, Opus 5.5, Opus 5 and Sonnet 5.5 (and their dated snapshots), never Claude Haiku 5.5; another model gets it with the capability `refusal_fallback` (`[Model_<alias>] Capabilities[]`), `no_refusal_fallback` turns it off for a listed one. |
| `StrictTools` | `enabled` | Tool definitions go with `strict: true` where the tool's schema allows it (every object closed with `additionalProperties: false`, only `type`, `properties`, `required`, `items`, `enum`, `description`); other tools, MCP tools among them, as they are. `disabled`: none strict. `StrictToolsMax` (20), `StrictOptionalMax` (24) and `StrictUnionMax` (16) cap the strict tools of one request, their optional parameters and their parameters with more than one type; a tool that would pass a cap goes as it is. The arguments of every call are checked against the tool's schema before the tool runs, with any provider. |
| `PromptCaching` | `enabled` | Asks the API to cache the stable start of each request (instructions, tools, earlier turns). |
| `Timeout` | `115` | Seconds for one call. Keep it below the web server's and PHP's request limits (architecture.md, "Long turns"). |
| `ConnectTimeout` | `10` | Seconds to connect. |
| `MaxResponseBytes` | `4194304` | The largest answer accepted, in bytes. |
| `FallbackToDefaultModel` | `enabled` | When the model a request asked for cannot answer (stopped, unreachable, not allowed, removed, kept away by the data policy), the site default answers instead (or the first allowed model that may take the content) and the editor sees one line saying so. `disabled`: the failure is shown as before. See [providers.md](providers.md), "When a model cannot answer". |
| `HealthCacheSeconds` | `30` | Seconds a provider's state (running, stopped, unreachable) is kept per process for the model pickers and the fallback; `0` checks on every use. |
| `Region` | `us` | Where Anthropic processes the requests (`us`, `eu`, `local`), for the data policy and the region the log shows per request. |
| `Retention` | `30d` | How long Anthropic keeps API data: `none`, `zdr` (zero data retention agreed), `30d`, `unknown`. Check your contract. |
| `TermsCheckedAt` | (empty) | The day you checked the terms (YYYY-MM-DD); expai/models shows "not checked" while empty. |

The settings above describe the provider "anthropic" as long as there is no `[Provider_anthropic]` group (the 0.0.1.0
settings keep working unchanged). More providers: the next three sections and [providers.md](providers.md).

## [ProviderSettings]: the providers

| Setting | Default | Effect |
| --- | --- | --- |
| `Providers[]` | `anthropic` | The active providers, in the order the model picker shows them. Each needs a `[Provider_<id>]` group, except `anthropic`, which falls back to `[AISettings]`. |
| `DefaultModel` | (empty) | The alias of the site default model; empty: `anthropic.<[AISettings] Model>`. |
| `ProvidersWithoutPolicy[]` | `anthropic` | The models of these providers can be chosen by everyone with `expai/write` (as in 0.0.1.0); every other provider's models need `expai/model_use` (limitation Provider). Empty it to require the policy for everything but the default. |
| `KeyLocations[]` | `settings/override`, `extension/*settings` | Where an `ApiKey` or `Token` may be set besides `settings/override` (fnmatch patterns relative to the installation). A file there is accepted only when it is not shipped with the code: git does not track it, or, in a release without git, it lies outside the release (a symlink to shared files). A tracked file is always reported on `expai/models`. |

## [Provider_<id>]: one provider

| Setting | Effect |
| --- | --- |
| `Type` | `anthropic` (Messages API), `openai_chat` (OpenAI Chat Completions and every compatible server), or `custom` with `Class=` (a class implementing `expAiProvider`; its constructor receives these settings with the model's). |
| `Flavor` | `openai_chat` only: `openai`, `huggingface`, `openrouter`, `mistral`, `xai`, `ollama`, `llamacpp`, `melious`, `generic`: the small differences (tool_choice support, Mistral's tool names, `max_completion_tokens`, OpenRouter's `reasoning` object, the cost Melious reports in `billing_cost.credits`). |
| `Label` | The name in the picker, in error texts and on expai/models. |
| `BaseURL` | The API: for `openai_chat` the base (`https://router.huggingface.co/v1`; `/chat/completions` is added, a query such as Azure's `api-version` is kept); for `anthropic` the messages URL. |
| `ApiKey` | The key, in settings/override or an untracked `[ProviderSettings] KeyLocations[]` location. expai/models warns about a key in any other file (and about one in a tracked file). |
| `ApiKeyHeader` | `authorization` (Bearer, default of `openai_chat`), `x-api-key` (default of `anthropic`), `api-key` (Azure), `x-goog-api-key`, or `none` (a local server). |
| `KeyOptional` | `true`: send no key header when `ApiKey` is empty (a server that accepts both). |
| `Headers[]` | Extra request headers, `Name: value` (e.g. OpenRouter's `X-OpenRouter-Title`). Key headers, `Host` and line breaks are refused. |
| `ExtraBody` | A JSON object merged into every request body, e.g. OpenRouter's routing `{"provider":{"data_collection":"deny","zdr":true}}`. It cannot change the model, the messages or the tools. |
| `Region`, `Retention`, `TermsCheckedAt` | As in `[AISettings]`; used by the data policy ([data-policy.md](data-policy.md)) and shown per request. |
| `Timeout`, `ConnectTimeout`, `MaxResponseBytes` | As in `[AISettings]`, for this provider (a local CPU model needs a longer `Timeout`, e.g. 280). |
| `TimeoutBackground` | Seconds one call may take outside a web request (the queue worker of Vector and the Intelligence tab, the command line); empty: `Timeout`. The shipped `[Provider_local]` has 900 (the job lease of a turn is 900 + 60 s). A timeout there is shown to the editor and never hands the turn to another model ([local-models.md](local-models.md), "Making a local model usable"). |
| `KeepAlive` | `Flavor=ollama` only: how long Ollama keeps the model (and the prompt it has cached) in memory after a request, an Ollama duration (`30m`) or seconds; sent as `keep_alive`. |
| `RateLimitHeaders[]` | Glob patterns of the response headers kept in the usage log (default: `anthropic-ratelimit-*` and `retry-after` for anthropic; `x-ratelimit-*`, `retry-after`, `x-request-id` for openai_chat). |
| `RetryAfterMax` | Seconds of a `retry-after` that expai waits before retrying (default and most 5); a longer wait is reported to the editor at once. |
| `RetryOnServerError` | `enabled` (default): up to two retries (1 s, 2 s) on 408/425/429, 500/502/503/504/529 or a connection that was never made, within one deadline; never after a timeout or an answer that broke off, nor after a 408/502/504 of an attempt longer than 30 s ([providers.md](providers.md), "Errors, retries, rate limits"). `disabled`: only a 429/503/529 with a short `retry-after`. |
| `AnthropicExtras` | `anthropic` only: `disabled` leaves out prompt caching, the refusal fallback, the workspace header and the beta headers (for an Anthropic-compatible server such as Ollama's `/v1/messages`). |
| `Managed` | `ollama`: a local Ollama that expai/models can start, stop and pull models for ([local-models.md](local-models.md)). |
| `PriceCurrency` | The currency of the prices of its models and of the cost it reports (Melious: `EUR`); empty: `[Pricing] Currency`. Converted with the ECB reference rate (or `[Pricing] ExchangeRates[]`); without a rate the cost is unknown. A `[Model_<alias>] PriceCurrency` wins. |
| `Streaming` | `enabled`: the server streams answers (SSE); the test prompt of expai/models is then streamed to measure the time to the first token (Melious). Everything else asks for whole answers. |

## [Model_<alias>]: one model

The alias is what users choose and what the usage log records (letters, digits, `.`, `_`, `:`, `-`).

| Setting | Effect |
| --- | --- |
| `Provider` | The provider id. Models of a provider that is not in `Providers[]` are ignored. |
| `Id` | What the API expects (Hugging Face: may end in `:fastest`, `:cheapest`, `:preferred` or `:<provider>`). |
| `Label` | The name in the picker. |
| `ToolUse` | `native` (the API's tool calling), `prompted` (tools described in the instructions, the model answers with JSON: read tools only), `none` (no tools: a read-only snapshot of the node). |
| `WriteTools` | `enabled`: the model may create and change drafts, if `ToolUse=native` and it passed the probe ([probe.md](probe.md)) or `WriteGate=trusted`. |
| `WriteGate` | `probe` (default) or `trusted` (no probe needed; the built-in Anthropic models are trusted). |
| `Capabilities[]` | `json_schema` / `json_object` (structured answers), `reasoning_effort` (sends `Effort`), `no_tool_choice` (the server ignores tool_choice), `tool_choice_none`, `prompt_cache`, `vision`. |
| `MaxOutput` | The longest answer of one call (tokens); default `[AISettings] MaxTokens`. |
| `Effort` | `none`, `minimal`, `low`, `medium`, `high`, `xhigh` (with `Capabilities[]=reasoning_effort`; `none` switches thinking off, e.g. for Qwen on a CPU). |
| `InputPerMTok`, `OutputPerMTok`, `CacheWritePerMTok`, `CacheReadPerMTok` | Prices per million tokens (0: free). Without them the model is priced by `[Pricing]` by its id; a model of another provider without prices has an unknown cost (never a Claude price). A cost the provider reports (OpenRouter) always wins. |
| `ContextTokens` | The context window in tokens, shown on expai/models (`context 1M tokens`); information only. |
| `PriceCurrency` | As in `[Provider_<id>]`, for this model. |
| `Slow` | `true` marks the model "slow" in the picker (default for providers with `Region=local`). |
| `PromptProfile` | `compact` (default for providers with `Region=local`) or `full`: a compact prompt is a short system prompt, only the tools the editor's message needs and a short snapshot of the open page for a question about it ([local-models.md](local-models.md), "Making a local model usable"). |
| `Tools[]` | With `PromptProfile=compact`: the tools a compact prompt may offer this model (empty: `get_node`, `list_children`, `get_class_definition`, `create_draft`, `update_draft`, `propose_plan`). |
| `MaxTokensParameter`, `ParallelToolCalls`, `StrictTools`, `ExtraBody` | `max_tokens` or `max_completion_tokens`; `enabled`/`disabled` sends `parallel_tool_calls`; `enabled` sends `strict: true` on tool definitions; a JSON object merged into the body for this model. |

## [AssistantSettings]: the conversation

| Setting | Default | Effect |
| --- | --- | --- |
| `MaxToolIterations` | `10` | The most model calls one editor message may lead to (each tool round is one). The last one is made without tools, so the model answers in words; a model that still calls tools has them run at most two more times, after that its calls are not run and the turn ends ("Stopped. The steps so far are kept."). |
| `MaxToolResultBytes` | `16000` | The longest tool result sent to the model; longer results are cut and say so. |
| `MaxConversationBytes` | `400000` | The largest window (messages sent to the model); beyond it the editor is asked to start a new conversation. |
| `MaxMessageLength` | `4000` | The longest message or brief, in characters (also the textarea's maxlength). |
| `MaxDraftsPerConversation` | `10` | The most drafts one conversation may create (new objects and new versions). |
| `ConversationLifetime` | `604800` | Seconds after its last message a conversation is still continued (older: a new one starts). |
| `ContextWindowTurns` | `8` | Exchanges in the window sent to the model. When it is full, the older exchanges become a short summary (cut from their own text, no extra call) and the last half are kept word for word. The panel still shows the whole history. |
| `CompactContextChars` | `1800` | The longest snapshot of the open page in a compact prompt (`[Model_<alias>] PromptProfile=compact`), in characters: its writable text fields as plain text, each cut to 600. |
| `ExtraInstructions[]` | (none) | Lines added to the instructions of the panel and the edit form, e.g. `ExtraInstructions[]=Write in British English.` New conversations get them; running ones keep the instructions they started with. |
| `WriteScope` | `subtree` | Where the write tools may write: `subtree` (the open node and below in the panel, the thread's scope in Vector; a target outside is refused with an error the model can react to) or `site` (anywhere the editor may write). |

## [ToolSettings]: what the assistant may use

| Setting | Default | Effect |
| --- | --- | --- |
| `Tools[]` | the seven tools of tools.md | The tools offered to the model, each defined in `[Tool_<name>] Class=`. |
| `WritableDatatypes[]` | ezstring, eztext, ezxmltext, ezkeyword, ezboolean, ezinteger, ezfloat, ezdate, ezdatetime, ezemail, ezurl | The datatypes the tools and field actions may write. Others (images, files, relations, ...) are skipped and reported. |
| `ListLimit` | `25` | The most children / siblings one lookup returns. |
| `FieldValueMaxLength` | `3000` | The longest field value a lookup returns, in characters (rich text: `[RichTextSettings] MaxLength`). |

`[Tool_<name>] Class=` names the class of each tool (`expAiToolGetNode`, `expAiToolListChildren`,
`expAiToolGetTreeContext`, `expAiToolListCreatableClasses`, `expAiToolGetClassDefinition`, `expAiToolCreateDraft`,
`expAiToolUpdateDraft`).

## [RichTextSettings]: rich text with placeholders

The model reads and writes rich text (ezxmltext) in the HTML subset `p`, `h1`-`h6`, `strong`, `em`, `ul`, `ol`, `li`,
`a href` (http(s), mailto), `br` (expAiRichTextTokens). Everything the subset cannot express becomes a placeholder in
the format of exp_oe_tiptap's assistant: `⟦C1⟧` custom tag (math_latex, math_mathml with all its attributes), `⟦E1⟧`
embed, `⟦T1⟧` table, `⟦P1⟧` literal, `⟦A1⟧` anchor, `⟦L1⟧` a link to a node or object (or any address but http(s) and
mailto), `⟦B1⟧` a list with attributes, `⟦X1⟧` any other element, `⟦K1⟧` at the start of a block or inline element:
its attributes (class, align, custom attributes), `⟦R1⟧` the rest of a field longer than `MaxLength`. Placeholders are
numbered in document order, so no map is stored: an answer is restored against the value it is written into, else
the field in the object's other languages (a translation), else, in Vector, the thread's open proposals of the field.

An answer is written only when every placeholder comes back exactly once and the value has no fewer custom tags (per
name), embeds, tables, literals, anchors, kept links and attributed elements than the value it replaces. Otherwise:
in the panel the field is not written and the model is told why; in a Vector thread writing directly the field is
staged for review instead (expai_change); in staged review it is staged as far as it can be restored, the rest of a
long field always kept. A review part (hunk) whose proposal has fewer of these elements than its base shows what it
would remove and cannot be accepted; "accept field" rejects it. Applying checks the merged value once more and writes
nothing that loses structure.

| Setting | Default | Effect |
| --- | --- | --- |
| `Placeholders` | `enabled` | `disabled`: the old plain HTML view, which loses that structure when written back. |
| `Datatypes[]` | ezxmltext | The datatypes whose stored value is ezxmltext and that get placeholders. |
| `MaxLength` | empty (`[EditViewSettings] MaxFieldLength`, 20000) | The characters of a rich text field the model reads; the rest is one `⟦R…⟧` placeholder per section level, written back unchanged. |
| `Protected[<element>]=<letter>` | custom=C, embed=E, embed-inline=E, table=T, literal=P, anchor=A | Elements always kept whole. An element the subset knows (e.g. `strong`) can be added to keep it whole as well. The letters K, L, Q (a ⟦ or ⟧ of the text itself), R and X are reserved. |

## [PanelSettings]: the Intelligence tab

| Setting | Default | Effect |
| --- | --- | --- |
| `Sections[]` | `conversation`, `drafts` | The sections of the panel, in this order; each `[PanelSection_<name>] Template=` (a template path below design:). |

## [EditViewSettings]: the edit form

| Setting | Default | Effect |
| --- | --- | --- |
| `Enabled` | `true` | The Exponential Intelligence area and the field actions in content/edit. |
| `Sections[]` | `assistant` | The sections of the edit form area; each `[EditSection_<name>] Template=`. |
| `FieldActions[]` | write, rewrite, shorten, translate, suggest | The buttons next to the fields, in this order. |
| `HideActionsWithEditorAI` | `true` | Hides the field actions and the Improve menu above a rich text field whose editor has an assistant of its own that keeps formulas, embeds and tables (Tiptap with its AI enabled); the field's presets stay. See [edit-view-integration.md](edit-view-integration.md). |
| `MaxFieldLength` | `20000` | The most characters of a field's current value the model gets (field actions, presets, Improve menu), 1000 to 200000. The answer to a longer field is shown for review instead of being written. |

Each `[FieldAction_<name>]`:

| Setting | Effect |
| --- | --- |
| `Label` | The button text (translated in the context `extension/expai/fieldaction`). |
| `Handler` | A class implementing `expAiFieldAction`; `expAiPromptFieldAction` uses `Instruction`. |
| `Datatypes[]` | Where the button is offered (and only if the datatype is in `WritableDatatypes[]`). |
| `Input` | `none` (runs at once), `instruction` (asks what to write), `optional_instruction`, or `language` (asks for one of the site's languages). |
| `Instruction` | The task given to the model; the handler adds the object, the field, its value, the other fields and the editor's input. |

## [DashboardSettings]

| Setting | Default | Effect |
| --- | --- | --- |
| `DefaultNodeID` | (empty) | The location "Start writing" uses by default: the request then works there and anywhere below it, within the editor's permissions. Empty: `content.ini [NodeSettings] RootNode`. Users who may not read it or create below it pick a location instead. |

## [LabelSettings] and [LogSettings]

| Setting | Default | Effect |
| --- | --- | --- |
| `[LabelSettings] Enabled` | `enabled` | The "Exponential Intelligence" label in lists, version views, edit forms and approval items. |
| `[LogSettings] MaxPromptSummaryLength` | `200` | The longest request summary stored in the log (characters, at most 255). |

## [UsageSettings]: the usage log

| Setting | Default | Effect |
| --- | --- | --- |
| `RetentionDays` | `365` | Rows of `expai_usage_log` older than this are removed by the cronjob part `expai` (`0` keeps everything). |
| `LogRequests` | `disabled` | `enabled` stores the full request with each row (debugging only: it contains content and the editors' words), and lets "Prompt again" fill in the earlier request. |

See [usage-tracking.md](usage-tracking.md).

## [RetentionSettings]: how long the editors' words are kept

| Setting | Default | Effect |
| --- | --- | --- |
| `Mode` | `report` | `report` (the default of this release): the cronjob only says what it would remove; `delete`: the rows go. A later release may switch the default to `delete`; set it explicitly to keep a choice. |
| `ThreadKeepDays` | `365` | A thread not modified for this many days is removed with its runs, events, proposals, checkpoints, plans and conversations; not when it is pinned, in review, waiting for an answer, or running. |
| `ConversationKeepDays` | `365` | A conversation not modified for this many days is removed, unless it belongs to a thread that stays. |
| `PromptSummaryKeepDays` | `365` | The request summary of a draft log row is blanked after this many days; the row stays. |

`0` keeps the rows. Independent of the periods, the threads and conversations of a user that no longer exists are
removed. A failed user lookup, or more than half of the users looking removed, removes none of them (a
warning is logged). The cronjob part `expai` (`expai_prune.php`) and `bin/php/expaithreads.php --prune` do it
([threads.md](threads.md#retention)).

## [Limits]: optional limits

Every limit is optional: empty or `0` is no limit. Tokens are input + output + cache write + cache read; costs are the
`[Pricing]` estimate. They are checked before every request to the model; the editor is told which limit was reached
and when it resets. Days and months follow the site's time zone. The dashboard shows a bar for each limit that is set.

| Setting | Resets |
| --- | --- |
| `MaxTokensPerConversation` | with "New conversation" in the panel |
| `MaxTokensPerSession` | when the user logs in again |
| `MaxTokensPerUserPerDay`, `MaxCostPerUserPerDay` | at midnight |
| `MaxTokensPerUserPerMonth`, `MaxCostPerUserPerMonth` | on the 1st |
| `MaxTokensPerSitePerMonth`, `MaxCostPerSitePerMonth` | on the 1st (all users together) |
| `MaxRequestsPerTurn` | the most model calls one message may lead to; empty: `[AssistantSettings] MaxToolIterations` (a retry of the prompted tool protocol counts) |
| `ProviderMaxTokensPerMonth[<provider>]`, `ProviderMaxCostPerMonth[<provider>]` | on the 1st: all users together on one provider; only requests to that provider are stopped |
| `UnpricedCost` | `block` (default): a request to a model without a price is refused while a cost limit applies to it, as the limit could not be kept; `ignore`: it goes and counts as free. A model of a provider with `Region=local` (it runs on the site's own hardware) always counts as free and is never refused for its missing price. |

While any limit is set, every request reserves its estimate before it is sent (input from the length of the request,
its `MaxTokens` as output) and counts with it until its row is settled with the real numbers. Parallel requests
therefore cannot pass a limit together: a request that would pass it with the requests under way is refused ("would
be passed together with the requests under way"). A reservation older than 15 minutes (a process that ended without
settling it) no longer counts; such rows are removed by the cronjob part `expai` and, at most once a minute per
process, before a request.

Anthropic's own limits apply as well: the rate limits of the key (the dashboard shows the last values the API
reported) and the spend limit of the organization, set in the Claude Console.

## [Pricing]: the estimated cost

| Setting | Default | Effect |
| --- | --- | --- |
| `Enabled` | `true` | `false` hides costs everywhere (dashboard, log, CSV) and the cost limits are not checked. |
| `Currency` | `USD` | The currency of the prices; shown with its symbol. |
| `PricesAsOf`, `Source` | the release date, Anthropic's pricing page | Shown under the cost so readers know how old the table is. |
| `Multiplier` | `1.0` | Applied to every price (regional inference, fast mode, a reseller's margin). |
| `ExchangeRateSource` | `ecb` | `ecb`: providers with `PriceCurrency` (Melious prices in EUR) are converted with the reference rates of the European Central Bank (https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml), fetched once a day by the job `expai.exchange.rates`, the cronjob part `expai_exchange_rates.php` or `./console ext:expai:exchange-rates`, kept with their date in ezsite_data and shown on expai/models; a failed fetch keeps the last rates and logs a warning. `settings`: only `ExchangeRates[]`. |
| `ExchangeRatesMaxAgeHours` | `26` | Stored ECB rates older than this are refreshed (the job is queued at most once a day). |
| `ExchangeRates[<code>]`, `ExchangeRatesAsOf` | `EUR`: 1.16, 2026-10-12 | The fallback while there is no ECB rate (and the override with `ExchangeRateSource=settings`): what one unit of the currency is in `Currency`. Without any rate the cost is unknown. |
| `Default*PerMTok` | Claude Sonnet 5.5 | Prices for a model without an entry; such costs are shown as approximate (`≈`). |
| `InputPerMTok[<model>]`, `CacheWritePerMTok[...]`, `CacheReadPerMTok[...]`, `OutputPerMTok[...]` | the shipped table | Prices per million tokens. Cache write is the 5-minute cache price. |
| `LongContextThreshold[<model>]` and `*PerMTokLong[<model>]` | Claude Haiku 5.5 | A request whose prompt (input + cache write + cache read) is above the threshold uses the `Long` prices. |

A model id is matched exactly, then without a provider prefix (`anthropic.`, `us.anthropic.`), a date (`-20261001`,
`@20261001`), `-latest` or a `-v1:0` suffix, then by family and version (the longest known id it starts with). The
unit test `php bin/php/ezexec.php extension/expai/tests/expaicost_test.php` checks every shipped id.
The estimate is not an invoice: Anthropic's invoice is authoritative.

## [DataPolicy]: where content may go

See [data-policy.md](data-policy.md). Without any rule every active provider may receive content.

| Setting | Default | Effect |
| --- | --- | --- |
| `DefaultRegions[]` | (none) | Regions any content may go to (`us`, `eu`, `local`). |
| `SectionRegions[<section id>]` | (none) | Regions the content of a section may go to, separated by `;` (e.g. `eu;local`). |
| `ClassRegions[<class identifier>]` | (none) | The same per class. Section, class and default narrow each other. |
| `ZeroRetention` | `disabled` | `enabled`: every request needs a provider with `Retention=none` or `zdr`. |
| `ZeroRetentionSections[]`, `ZeroRetentionClasses[]` | (none) | Sections and classes whose content needs zero retention. |

## [RequestVeto] and [RequestVeto_<rule>]: the veto hook

See [switch-and-veto.md](switch-and-veto.md). Checked before every request to a provider and before a request is
queued; after the rules, the ezpEvent filter `expai/request/veto` (site.ini `[Event] Listeners[]`) may refuse too.

| Setting | Default | Effect |
| --- | --- | --- |
| `[RequestVeto] Rules[]` | (none) | The rules, in order; the first whose conditions all match refuses the request with its `Reason`. |
| `[RequestVeto_<rule>] Sections[]`, `Classes[]`, `Subtrees[]` | (none) | Section ids, class identifiers, node ids (at or below) of the request's content. |
| `[RequestVeto_<rule>] SiteAccesses[]`, `Providers[]`, `Regions[]` | (none) | The siteaccess, the provider id, the provider's region. |
| `[RequestVeto_<rule>] Purposes[]` | (none) | `panel`, `vector`, a field action, `test`, `probe`, `queue.<purpose>`; a trailing `*` matches the rest. |
| `[RequestVeto_<rule>] Reason` | "This request is not allowed on this site." | Shown to the editor and logged. A rule without conditions refuses every request. |

## [ServiceTools]: service tools

See [service-tools.md](service-tools.md). The read services of expservices the assistant may call
(`exp_svc_<domain>_<method>`); a service opts in in its declaration with `'ai' => true`.

| Setting | Default | Effect |
| --- | --- | --- |
| `Enabled` | `enabled` | `disabled`: no service is offered. |
| `Services[]` | (none) | More services offered without code changes: `<domain>::<method>`, or `<domain>::*`. Never a write. |
| `Domains[]` | (none) | Every read service of these domains. |
| `Exclude[]` | (none) | Never offered (same patterns as `Services[]`), e.g. `node::countByClass`. |

## [ProbeSettings]: the write gate

| Setting | Default | Effect |
| --- | --- | --- |
| `WritePassRate` | `90` | The pass rate (percent) of a live probe a model needs to write drafts ([probe.md](probe.md)). |
| `NodeID` | (empty) | The node the probe tasks name (nothing is read or written); empty: the content root. |
| `ClassIdentifier` | `folder` | The class the probe tasks name. |
| `FollowRounds` | `2` | How often a lookup before the write of the tasks create_draft and update_draft is answered with a scripted result before the next call is graded (0 to 5; [probe.md](probe.md), "A lookup before the write"). |

## [LocalModelSettings]: a local Ollama

| Setting | Default | Effect |
| --- | --- | --- |
| `SpoolDirectory` | `/var/lib/expai-llm/spool` | Where expai/models writes requests for the root-owned helper ([local-models.md](local-models.md)). |
| `StatusFile` | `/var/lib/expai-llm/status.json` | The status the helper writes (read by expai/models). |
| `Models[]` | `qwen3.5:4b` | The models the view offers to pull and remove (the helper has its own allowlist). |

## [ModelTest] and [WebBudget]: test prompt, load model and the web budget

"Test prompt" and "Load model" of expai/models run as jobs of the job queue (type `expai.model.test`, expqueue.ini
`[JobType_expai.model.test]`; [local-models.md](local-models.md), "Test prompt and Load model"). The page follows
them through `expaimodel::status`, `expaimodel::test` and `expaimodel::load` (ezjscore.ini `[ezjscServer_expaimodel]`,
policy `expai/model_manage`).

| Setting | Default | Effect |
| --- | --- | --- |
| `[ModelTest] PollSeconds` | `3` | Seconds between two questions of the page while a job waits or runs (2 to 30). |
| `[ModelTest] ShowResultMinutes` | `60` | How long a finished test or load stays on the page, also after a reload. |
| `[ModelTest] LoadKeepAlive` | `30m` | How long Ollama keeps a model in memory after "Load model" (`30m`, `2h`, or seconds). |
| `[WebBudget] Seconds` | `25` | What one model call from a web request may take. Keep it below the web server's request timeout. |
| `[WebBudget] SlowModels` | `queue` | `queue`: slow models (`Slow=true`, a provider with `Managed=ollama`) are never called from a web request: the field actions and the fill of the edit form refuse them with a message, the test prompt and Vector turns run them as jobs. `allow`: they are called anyway (a web server with a long timeout). `[Model_<alias>] RunInBackground=always` or `never` decides for one model. |
| `[WebBudget] MinSamples` | `5` | Another model counts as too slow when at least this many of its last 20 successful requests took longer than `Seconds` on average. |

## [ModelStats]: the Models card of the dashboard

The "Models" card of expai/dashboard ([dashboard.md](dashboard.md), "Models") shows the track record of each model in
the period and scope of the page. Its figures are computed once per request (`expAiModelStats::forPeriod()`) and kept
in a small cache file per period, scope and siteaccess (`var/<site>/cache/expai/model-stats/`). A model is flagged
slow by the same threshold as the web budget, `[WebBudget] Seconds`.

| Setting | Default | Effect |
| --- | --- | --- |
| `[ModelStats] CacheSeconds` | `60` | How long the figures of a period are kept (0 to 3600 seconds); `0` computes them on every view. |

## Lanes

### [Lane_<provider>.<lane>]: one lane

A way of sending requests to a provider ([lanes.md](lanes.md)). Every provider has the lane `standard`, with or without
a group. Shipped: `anthropic.standard`, `anthropic.batch`, `anthropic.fast` (disabled), `openai.flex`, `openai.batch`,
`gemini.batch`, `gemini.flex` (disabled), `mistral.batch`, `openrouter.batch` and `openrouter.flex` (disabled).

| Setting | Default | Effect |
| --- | --- | --- |
| `Type` | `sync` (`batch` for a lane named batch) | `sync`: one call answered in the request; `batch`: submitted with others, polled, results fetched later. |
| `Enabled` | `true` with a group (standard always) | `false` keeps the lane out of every choice. |
| `PriceMultiplier` | `1` for standard, else empty | The factor on the list price (batch `0.5`, fast `2.0`). Empty: the price is not confirmed and the lane is never used. |
| `ExpectedLatency`, `MaxLatency` | 60 / 3600 s (batch), MaxLatency 86400 for batch | Typical and longest seconds until the answer, for deadlines. |
| `Retention` | `inherit` | What the provider keeps of a request on this lane: `inherit` (the provider's), `none`, `zdr`, `30d`, `29d`, `6w`, `unknown`. Only `none` and `zdr` meet a zero-retention rule. |
| `RequestParams[<name>]` | (none) | Values merged into the request body (`speed=fast`, `service_tier=flex`). |
| `Headers[]` | (none) | Extra request headers (`anthropic-beta: fast-mode-2026-02-01`); key headers are refused. |
| `FallbackLane` | (none) | The lane a request moves to when this one answers 429, 503 or 529. |
| `OnUnavailable` | `retry` | `escalate`: a flex lane's "resource unavailable" moves the request to `FallbackLane` at once. |
| `Timeout` | the provider's | Seconds of one call on a sync lane (flex: 900). |
| `Models[]` | (all) | Only these model aliases (or ids) use the lane. |
| `BatchApi` | the provider type | `anthropic`, `openai`, `gemini`, `mistral` or `openrouter`. |
| `BatchURL` | derived from the provider | The batch API base where it is not the provider's URL (Gemini: its native API). |
| `MaxRequestsPerBatch` | `2000` | Requests per provider batch (Mistral at most 9999 inline). |
| `BatchCollectSeconds` | `300` | How long waiting requests are collected before a batch is submitted. |
| `PollSeconds` | `300` | Seconds between two polls of a batch. |
| `DeleteAfterFetch` | `enabled` | Delete the batch (and its files) at the provider after its results were fetched. |
| `ExpiredLane` | `standard` | Where an expired or canceled batch request goes. |
| `Verified`, `Source`, `Label` | | When and where the offer was read; a label. |

`[Provider_<id>] Lanes[]` and `[Model_<alias>] Lanes[]`, when set, restrict the lanes of a provider and a model. The
concurrency of a lane is `expqueue.ini [QueueResource_<provider>:<lane>] MaxConcurrent`.

### [LanePolicy]: which lane a queued request takes

| Setting | Default | Effect |
| --- | --- | --- |
| `Order[<purpose>]` | `default` standard, `bulk` batch;flex;standard, `translate` flex;batch;standard, `translate_full` batch;flex;standard | The lanes a request of that purpose tries, in order (`;` separated). |
| `ClassOrder[<queue class>]` | per class of expqueue | The order for a request whose purpose has none. |
| `PurposeClass[<purpose>]` | `default` editorial, `bulk` bulk, `translate` translation_patch, `translate_full` translation_full | The queue class of a request of that purpose. |
| `UrgentPriority` | `80` | From this priority on, flex and batch are skipped (0: never). |
| `StrictDeadline` | `disabled` | `enabled`: a deadline is checked against a batch lane's `MaxLatency`. |

## [Budgets]: budgets of queued requests

See [budgets.md](budgets.md).

| Setting | Default | Effect |
| --- | --- | --- |
| `SiteMonthly` | (empty: `[Limits] MaxCostPerSitePerMonth`) | The monthly budget of the site; requests of the month plus reservations must stay below it, else queued requests are held. |
| `DefaultGroupBudget` | (none) | The budget of a new job group whose producer names none. |
| `ReserveFactor` | `1.25` | A queued request reserves its estimate times this. |
| `CharsPerToken` | `3.5` | Characters per token for the estimate of the input. |
| `OutputShareOfMax` | `0.5` | The share of `max_tokens` estimated as output (unless the producer gives `est_output`). |

## [QueueSettings]: queued requests

| Setting | Default | Effect |
| --- | --- | --- |
| `Appliers[<name>]` | (none) | Classes implementing `expAiRequestApplier` that write the answer of a queued request somewhere (option `apply`). |
| `MaxStoredAnswerBytes` | `200000` | The longest answer kept in a job's checkpoint. |

## AI chains

See [chains.md](chains.md). The block "AI chains" of expai.ini adds `[PanelSettings] Sections[]=chains` (with
`[PanelSection_chains]`, "Write with a chain" in the Intelligence tab) and `[QueueSettings] Appliers[chain]=expAiChainApplier`.

`[ChainSettings]`:

| Setting | Default | Effect |
| --- | --- | --- |
| `Chains[]` | `article` | The chains users may start (policy expai/chain, limitation Chain). |
| `PromptDirectories[]` | (none) | Directories (relative to the installation) searched for prompt templates before `extension/expai/design/admin/templates`. |
| `SystemPrompt` | (empty) | Added to the system prompt of every chain step. |
| `MaxBriefLength` | `4000` | The longest brief, in characters. |
| `MaxContextChars` | `12000` | The longest context the read tools of a step add to its prompt. |
| `MaxStoredOutputBytes` | `200000` | The longest step output kept in `expai_chain_step.output`. |
| `TranslateHandler` | `expAiChainTranslateSync` | The class whose static `chainTranslate( $run, $languages )` takes the translate step (the shipped one hands a published object to the translation sync); empty: the step is skipped. |

`[Chain_<id>]` (shipped: `[Chain_article]`): `Label`, `Description`, `Class`, `Fields[]` (the fields the generate
steps write), `Steps[]` (the steps in order), `MaxCostPerRun` (the run and job group budget, `1.00`), `MaxItems`
(items per start, `5`).

`[ChainStep_<id>.<step>]` (shipped: `[ChainStep_article.write]`, `[ChainStep_article.review]`,
`[ChainStep_article.revise]`, `[ChainStep_article.seo]`, `[ChainStep_article.checkpoint]`,
`[ChainStep_article.translate]`): `Type` (generate, review, transform, human, translate), `Label`, `Model` (a model
alias), `Lane` (`auto`), `PromptTemplate` or `PromptText`, `Tools[]` (read tools run before the call), `Checklist[]`,
`PassCriteria[]`, `MaxIterations`, `OnFail` (revise, stop, escalate), `ReviseStep`, `OnExhausted` (escalate, stop),
`Checkpoint` (human, none), `Next`, `MaxTokens` (at least 1000 for a reasoning model), `OutputFields[]`, `Languages[]`.
Every one is explained with an example in [chains.md](chains.md#defining-a-chain).

## [McpBridge], [McpSettings], [McpServer_<id>]: MCP tools

See [mcp.md](mcp.md).

| Setting | Default | Effect |
| --- | --- | --- |
| `[McpBridge] Enabled` | `disabled` | `enabled`: the bridge-safe read tools of the extension expmcp, in process as the current user (`exp__<tool>`, policy `expai/mcp` with Server `exp`). |
| `[McpBridge] Tools[]` | `exp_content_query`, `exp_content_get`, `exp_search`, `exp_content_relations`, `exp_content_versions`, `exp_content_meta`, `exp_class_list`, `exp_class_describe` | The opt-in allowlist of expmcp tools offered; names expmcp does not declare bridge-safe are ignored (with expmcp before 0.3.1 only `exp_content_query`). |
| `[McpSettings] Servers[]` | (none) | External MCP servers (streamable HTTP), each `[McpServer_<id>]` (id: a-z, 0-9, at most 20 characters). |

| `[McpServer_<id>]` setting | Effect |
| --- | --- |
| `Label` | The name shown with its tools. |
| `URL` | The endpoint: https, or http to a loopback address with `AllowLoopback=enabled`. |
| `Token` | Sent as a Bearer token; in settings/override or an untracked `KeyLocations[]` location. |
| `AllowedTools[]` | The tools the panel may call (`mcp__<id>__<tool>`); everything else the server offers is ignored. |
| `Region`, `Retention` | Checked against the panel node's data policy before every call. |
| `MaxResultBytes` | The longest result passed to the model (default 16000). |
| `Timeout` | Seconds per request (default 20). |
| `ToolsCacheTTL` | Seconds the tool list is cached (default 3600; 0: no cache). |
| `ProtocolVersion` | The MCP protocol version offered in `initialize` (default `2025-06-18`; the server's answer is used). |
| `AllowLoopback`, `AllowPrivate` | `enabled` lets the URL resolve to a loopback, or a private / link-local / reserved address (refused by default). |

## The other settings files of the extension

| File | What it sets |
| --- | --- |
| `module.ini` | `ModuleList[]=expai`: the module with every policy of [permissions.md](permissions.md) and the views expai/dashboard, log, vector, stream, chain, alttext, models, budgets, packages, summary and help |
| `ezjscore.ini` | `[ezjscServer] FunctionList[]=expai`, `[ezjscServer_expai] Class=expAiServerFunctions`, `Functions[]=expai` |
| `design.ini` | `DesignExtensions[]=expai`, `BackendJavaScriptList[]=expai.js`, `BackendCSSFileList[]=expai.css` |
| `admininterface.ini` | the tabs `expai` (content navigation part) and `expai_media` (media), header template `expai_header.tpl`, body `expai.tpl` |
| `content.ini` | `[EditSettings] AdditionalTemplates[]=content/edit/expai.tpl` |
| `content.ini` | `[EditSettings] AttributeTemplates[]=content/edit/expai_attribute.tpl` (the field bars drawn with the page) |
| `menu.ini` | the entries of the Dashboard menu (`[Leftmenu_my]`: Exponential Intelligence, EI: Vector, EI: Chains, EI: Log, EI: Help), of Setup (`[Leftmenu_setup]`: EI: Summary, EI: Models, EI: Budgets, Job queue) and of Design (`[Leftmenu_design]`: Missing alt text), each with its policies ([dashboard.md](dashboard.md)) |
| `cronjob.ini` | the cronjob part `expai`: `expai_queue.php` (resumes held jobs, releases lost batch submits), `expai_prune.php` (usage rows older than `RetentionDays`; draft log rows of removed objects marked `deleted`; threads, conversations and request summaries after `[RetentionSettings]`, and those of removed users), `expai_thread_prune.php` (thread events, proposals, checkpoints); run it with `-s <admin siteaccess>` |
| `dashboard.ini` | the dashboard block `expai_drafts` (Priority 35, 5 items, `expai/write`) |
| `browse.ini` | `[ExpAiStartWriting]`: the content browser of "Change location" (single node) |
| `site.ini` | `TranslationExtensions[]=expai`, `[TemplateSettings] ExtensionAutoloadPath[]=expai` (the template operators `expai_money`, `expai_tokens`, `expai_number`) |

## Threads, Vector and the keyboard

[threads.md](threads.md), [vector.md](vector.md), [keyboard.md](keyboard.md).

**[PanelSettings]** `Sections[]=threads` adds the section *Threads on this page* to the Intelligence tab
(**[PanelSection_threads]** `Template=expai/panel/threads.tpl`).

**[ThreadSettings]**

| Setting | Default | Effect |
| --- | --- | --- |
| `ListLimit` | 100 | threads per shelf in Vector's list |
| `TitleLength` | 80 | a thread's title is its first request, cut to this |
| `StaleRunSeconds` | 900 | a `running` thread without a change for this long is shown as stopped (a closed tab never reports its end) |
| `MaxEventPayloadBytes` | 16384 | the largest event payload; bigger ones keep their keys with long strings cut |
| `EventKeepDays` | 30 | events (and sent or dropped follow-ups) are removed after this; 0 keeps them |
| `ProposalKeepDays` | 30 | staged proposals and plans that are not running |
| `CheckpointKeepDays` | 30 | checkpoints |

**[VectorSettings]**

| Setting | Default | Effect |
| --- | --- | --- |
| `Enabled` | true | the view expai/vector and its functions |
| `Panes[]` | plan, diff, cost | the tabs of the right pane, each `[VectorPane_<name>] Template=` |
| `DefaultMode` | write | mode of a new thread (`plan` or `write`; bulk scopes always start in plan mode) |
| `DefaultReview` | staged | review of a new thread (`staged`, or `direct` for one object in one language) |
| `BulkObjectThreshold`, `BulkLanguageThreshold` | 1, 1 | more objects or languages than these (or any subtree or class) is bulk work: plan first, staged |
| `PollActiveMs` | 1000 | poll interval while the open thread runs |
| `PollWorkingMs` | 3000 | poll interval while another thread works |
| `PollIdleMs` | 15000 | poll interval otherwise (doubled while the host is busy; no polling while the tab is hidden) |
| `Transport` | auto | `auto` (Server-Sent Events under PHP-FPM/Apache and Velocity, with a fallback to polling), `poll`, `sse` |
| `MaxStreams` | 4 | streams open at once (one PHP process each: at most 25 % of PHP-FPM `pm.max_children`) |
| `StreamSeconds` | 25 | how long one stream is held before the browser reconnects |
| `Background` | auto | turns of slow models run as queue jobs (`auto`), every turn (`always`), none (`never`); [vector.md](vector.md#background-runs) |
| `KickWorker` | enabled | without a running worker, start one detached worker for interactive jobs after queueing (setsid where the system has it, else nohup alone) |
| `WorkerAllowRoot` | disabled | pass `--allow-root-user` to the started worker; only for a site whose PHP runs as root |
| `PhpCli` | (empty) | the PHP command line binary of that worker (empty: the one of this PHP) |
| `MaxStagedChanges` | 500 | staged changes one thread may hold open ([review.md](review.md)); over it the write tools ask for a review first |
| `MaxEditLength` | 20000 | the longest text of an edited part in the Changes tab (characters) |
| `MaxPlanItems` | 50 | the most items of one plan ([plan-mode.md](plan-mode.md)) |
| `ExpectedRounds[<action>]` | create 3, update 3, translate 2 | tool rounds an item of a plan is expected to take (the estimate; low = one less) |
| `HighExtraRounds` | 2 | rounds added for the high end of the estimate |
| `PlanFrameTokens` | 6000 | the size of the instructions and tool definitions when the thread has none stored yet |
| `PlanHistoryTokens` | 1500 | the bounded history sent with each round |
| `PlanNewObjectTokens` | 300 | the context of an item that creates a new object |
| `OutputPerField` | 250 | output tokens per field of a new object |
| `CharsPerToken` | 3.5 | characters per token for text whose tokens are not counted |
| `SecondsPerRound` | 8 | the duration shown with the estimate |
| `ParallelRuns` | enabled | each item of an approved plan is a child run of its own, side by side, translations after their source ([parallel-runs.md](parallel-runs.md)); `disabled`: one job, the items one after the other |
| `MaxParallelRunsPerThread` | 4 | items of one thread that run at once (the queue resource `expai.thread:<thread id>`) |
| `MaxKickedWorkers` | 4 | live queue workers at most when expai starts one after queueing: another one starts when a job is ready and every live worker is busy ([parallel-runs.md](parallel-runs.md#workers)) |
| `SlowStepSeconds` | 20 | a model whose median time per step reaches this is slow: its running turn shows the time per step and "Switch to <default model>" ([parallel-runs.md](parallel-runs.md#slow-models)) |

`[QueueSettings] Appliers[vector_plan_item]=expAiPlanItemApplier` stages the answers of a plan that runs on a batch
lane; `expqueue.ini [JobType_expai.thread.item]` is the job of one item of a plan.

**[VectorPane_<name>]** `Template=`: the template of a right-pane tab (`expai/vector/pane_plan.tpl`, ...).

**[KeyBindings]**

| Setting | Default | Effect |
| --- | --- | --- |
| `GlobalPalette` | enabled | Ctrl/Cmd+K opens the palette on every admin page (never in a field or rich text editor); Vector always has it |
| `Checkpoints` | enabled | a checkpoint at the start of every turn, before accepted changes are written and before an approved plan runs ([checkpoints.md](checkpoints.md)) |
| `MaxCheckpointBytes` | 1048576 | the largest checkpoint; over it the checkpoint keeps the conversation and the decisions, not the values |
| `MaxCheckpointsPerThread` | 50 | the newest checkpoints kept per thread |
| `UserOverrides` | enabled | editors may change their shortcuts (preference `expai_keybindings`) |
| `Binding[<command>]` | see [keyboard.md](keyboard.md) | the keys of a command: `Mod+KeyK`, `Alt+ArrowDown`, `Escape Escape`, `?` |
| `When[<command>]` | | where the keys work: `global`, `vector`, `list`, `composer` |

Other settings files: `menu.ini` `Links[expai_vector]=expai/vector` (with `expai/write` and `expai/vector`),
`ezjscore.ini` `[ezjscServer_expaivector] Class=expAiVectorServerFunctions` (`Functions[]=expai`), `cronjob.ini`
`Scripts[]=expai_thread_prune.php` in the part `expai`.

## [WebSettings], [SearchSettings], [SearchProvider_<id>], [HistorySettings], [HistoryRepository_<id>]: web and history tools

See [tools.md](tools.md#web-and-history-tools) and [security.md](security.md#web-content). The tools are listed in
`[ToolSettings] Tools[]` (`web_fetch`, `web_search`, `project_history`) and offered to users with `expai/web` once
configured.

| Setting | Default | Effect |
| --- | --- | --- |
| `[WebSettings] AllowedDomains[]` | (none) | The sites web_fetch may read: an exact host or `*.domain` (subdomains only). Empty: web_fetch is not offered. |
| `[WebSettings] PrivateHosts[]` | (none) | Hosts that may resolve to private, loopback or link-local addresses (an intranet documentation server). |
| `[WebSettings] AllowedPorts[]` | (none) | Ports allowed besides 80 and 443. |
| `[WebSettings] MaxBytes` | `524288` | The most bytes read of a page; the rest is cut. |
| `[WebSettings] Timeout` | `10` | Seconds for the whole fetch, redirects included. |
| `[WebSettings] MaxRedirects` | `3` | Same-host redirects followed. |
| `[WebSettings] MaxResultChars` | `12000` | The most characters of page text returned to the model. |
| `[WebSettings] MaxFetchesPerTurn` | `5` | web_fetch calls per turn. |
| `[WebSettings] RespectRobots` | `enabled` | Obey robots.txt (product token `ExponentialIntelligence`). |
| `[WebSettings] RobotsCacheTTL` | `3600` | Seconds a robots.txt is cached (var cache `expai/web`). |
| `[WebSettings] WritesAfterWebResults` | `disabled` | `enabled`: drafts may be written in the same turn after a web_fetch or web_search result. |
| `[SearchSettings] Provider` | `brave` | The `[SearchProvider_<id>]` used by web_search. |
| `[SearchSettings] MaxResults` | `8` | Results per search (at most 20). |
| `[SearchSettings] MaxSearchesPerTurn` | `3` | web_search calls per turn. |
| `[SearchSettings] SearchResultsFetchable` | `disabled` | `enabled`: a result URL of the conversation may be fetched although its host is not allowlisted. |
| `[HistorySettings] Repositories[]` | (none) | Repository ids, each `[HistoryRepository_<id>]`. Empty: project_history is not offered. |
| `[HistorySettings] Roots[]` | (none) | Local clones must lie under one of these (absolute or relative to the installation root). |
| `[HistorySettings] ExcludePaths[]` | `ai/*` | Paths never named or read; titles and note lines naming such a directory are left out. |
| `[HistorySettings] HideUntrackedRootMarkdown` | `enabled` | Leave out titles naming a `*.md` in a clone's root that git does not track. |
| `[HistorySettings] ShowAuthors` | `disabled` | `enabled`: commits carry the author's name. |
| `[HistorySettings] GitBinary` | `/usr/bin/git` | The absolute path of git. |
| `[HistorySettings] Timeout` | `10` | Seconds per git command or GitHub request. |
| `[HistorySettings] MaxCommits` | `30` | The most commits per repository and call. |
| `[HistorySettings] MaxCallsPerTurn` | `5` | project_history calls per turn. |
| `[HistorySettings] MaxReleaseNotesChars` | `1500` | Release notes are cut to this length. |
| `[HistorySettings] GitHubApi` | `https://api.github.com` | The GitHub API base (https). |
| `[HistorySettings] ReleasesCacheTTL` | `3600` | Seconds a release list is cached (var cache `expai/history`). |

| `[SearchProvider_<id>]` setting | Effect |
| --- | --- |
| `Label` | The name in results and the data policy. |
| `Class` | A class extending `expAiWebSearchProvider` (`expAiWebSearchBrave` for `[SearchProvider_brave]`). |
| `Endpoint` | The API address (https). |
| `ApiKey` | In settings/override or an untracked `KeyLocations[]` location; without it web_search is not offered. |
| `Region`, `Retention` | Checked against the panel node's data policy before every search (the query leaves the site). |
| `Timeout` | Seconds per search (default 10). |
| `SafeSearch`, `Country`, `SearchLang` | Brave's `safesearch`, `country` and `search_lang` parameters (optional). |

| `[HistoryRepository_<id>]` setting | Effect |
| --- | --- |
| `Label` | The name shown to the model. |
| `Path` | The local clone (relative to the installation root or absolute), under `Roots[]`; empty: releases only. |
| `GitHub` | `owner/name` of the public repository for release notes; empty: commits only. |
| `Branch` | The branch read (default: the checked-out HEAD). |

The shipped `[SearchProvider_brave]` and the example `[HistoryRepository_example]` (not listed in `Repositories[]`)
show the settings.

## [DiffSettings], [TranslationSync]: diff and translation sync

See [diff.md](diff.md) and [translation-sync.md](translation-sync.md).

| `[DiffSettings]` setting | Default | Effect |
| --- | --- | --- |
| `Segmenters[<datatype>]` | string, text, rich text, keyword segmenters | The class (implementing `expAiSegmenter`) that cuts values of a datatype into blocks and sentences. |
| `FallbackSegmenter` | `expAiSegmenterGeneric` | The segmenter of datatypes not listed: the value as one block. |
| `ModifiedSimilarity` | `0.5` | Two leftover blocks with this share of words in common are one modified block; below it, one deleted and one inserted block. |
| `Abbreviations[<family>]` | (none) | More abbreviations for the sentence splitter per language family (`eng`, `ger`, ...), separated by `;`, without the final dot. |

| `[TranslationSync]` setting | Default | Effect |
| --- | --- | --- |
| `Enabled` | `enabled` | The workflow event, the CLI and the Translations section of the Intelligence tab. |
| `SourceLanguages[]` | (empty) | The source language of objects (the first one an object has); empty: the object's main language. |
| `TargetLanguages[]` | (empty) | Only these translations are kept in step; empty: every other translation. |
| `Datatypes[]` | `ezstring`, `eztext`, `ezxmltext`, `ezkeyword` | The datatypes whose changes are translated. |
| `PatchMaxChangedRatio` | `0.25` | Up to this share of changed source characters a patch is queued at once (below the cost threshold). |
| `FullMinChangedRatio` | `0.6` | From this share on: a full translation (waits for an editor). Between the two: a patch that waits for an editor. |
| `PatchMaxSegments` | `40` | A patch with more changed blocks waits for an editor. |
| `CostThreshold` | `0.02` | In `[Pricing] Currency`: a patch estimated at or below it is queued at once; above it, it waits for an editor. |
| `AutoQueuePatch` | `enabled` | `disabled`: every patch waits for an editor. |
| `FullIfStructureMismatch` | `enabled` | A translation without the block structure of its base is translated in full. |
| `Model` | (site default) | The model alias of the sync, e.g. `anthropic.claude-haiku-5-5`. |
| `PatchLane`, `FullLane` | `auto` | The lanes of patches and full translations (`auto`: `[LanePolicy] ClassOrder[translation_patch]`, `ClassOrder[translation_full]`). |
| `MaxOutputTokens` | `8000` | The most output tokens of one request. |
| `QueueCheck` | `enabled` | The workflow event queues the check as a job (`expai.translation.check`); `disabled` (or no queue): it runs during the publish. |
| `Instructions` | (empty) | Extra instructions for the translator (glossary, tone), appended to the system prompt. |

The section is added to the panel with `[PanelSettings] Sections[]=translations` (`[PanelSection_translations]
Template=expai/panel/translations.tpl`), and the answers are written by `[QueueSettings]
Appliers[translation_sync]=expAiTranslationSyncApplier`.

Other files: `expqueue.ini [JobType_expai.translation.check]` (the check job, class `system`), `expqueue.ini
[JobType_expai.translation.run]` (a translation by a machine translation service, `[QueueResource_translation:<id>]`
its concurrency), `ezjscore.ini [ezjscServer_expaitranslation]` (the functions `expaitranslation::status`, `check`,
`approve`, `skip`), and `workflow.ini [EventSettings]` (the event type `event_expaitranslationsync`). `Model`,
`Instructions`, `PatchLane` and `FullLane` apply to the translation service `llm` only.

## [TranslationSettings], [TranslationProvider_<id>]: translation services

See [translation-providers.md](translation-providers.md). Who translates for the translation sync, the field action
Translate and the chain step translate. Without a `[TranslationSettings]` group: `llm`, as before.

| `[TranslationSettings]` setting | Default | Effect |
| --- | --- | --- |
| `Provider` | `llm` | The default service: `llm` (the model providers of expai), `none` (no translation) or the id of a `[TranslationProvider_<id>]` group. |
| `ProviderFor[<source>:<target>:<purpose>]` | (empty) | Per language pair and purpose (`patch`, `full`, `field`), the most specific rule wins: `<source>:<target>:<purpose>`, `<source>:<target>`, `*:<target>:<purpose>`, `*:<target>`, `<source>:*:<purpose>`, `<source>:*`, `*:*:<purpose>`, then `Provider`. Example: `ProviderFor[*:*:full]=deepl`. |
| `Fallback` | `llm` | When the chosen service is not configured, does not translate the pair or the data policy keeps the content away from it: another id, or `none` (reported as not possible). A failure while translating never falls back. |
| `DoNotTranslate[]` | (empty) | Terms that are never translated, for every service. |
| `Glossary[<source>:<target>]` | (empty) | A tab-separated file (term, translation) for the `llm` service, relative to the installation. |

| `[TranslationProvider_<id>]` setting | Default (deepl / libretranslate) | Effect |
| --- | --- | --- |
| `Class` | `expAiTranslationProviderDeepl` / `expAiTranslationProviderLibreTranslate` | A class implementing `expAiTranslationProvider` (`[TranslationProvider_llm] Class=expAiTranslationProviderLlm`). |
| `Label` | `DeepL` / `LibreTranslate` | The name shown in the Translations section, EI: Summary and error texts. |
| `Endpoint` | `https://api.deepl.com/v2/translate` / `http://127.0.0.1:5000/translate` | The API; plain `http` only with `Region=local`. |
| `ApiKey`, `ApiKeyRequired` | (empty), enabled / (empty), disabled | The key: only in `settings/override` or an untracked `[ProviderSettings] KeyLocations[]` location (EI: Summary and the key placement check report it otherwise). |
| `Region`, `Retention`, `TermsCheckedAt` | `eu`, `unknown` / `local`, `none` | For the data policy ([data-policy.md](data-policy.md)). |
| `PricePerMChars`, `PriceCurrency` | `20`, `EUR` / `0` | The price of a million billed characters, converted to `[Pricing] Currency`; empty: the cost is unknown. |
| `Formality` | `default` (DeepL) | `more`, `less`, `prefer_more`, `prefer_less`. |
| `LanguageMap[<eZ locale>]`, `Languages[]` | (empty) | The service's code of a locale; the base codes it translates (empty: the built-in rules). |
| `GlossaryId[<source>:<target>]` | (empty) | A DeepL glossary id. |
| `Timeout`, `MaxTextsPerRequest`, `MaxBytesPerRequest` | `30`, `50`, `120000` / `60`, `25`, `60000` | The limits of one request. |
| `MaxRetries`, `RetryAfterMax` | `2`, `10` | Retries of 429 and 5xx within one job; a longer `retry-after` is left to the queue. |
| `SendContext` | `enabled` (DeepL) | The neighbouring blocks go along as context (one block per request then). |

The field action is `[FieldAction_translate] Handler=expAiTranslateFieldAction` (purpose `field`).

## [AltTextSettings]: alt text for images

See [alt-text.md](alt-text.md). The button is the field action `[FieldAction_alt_text]` (`Handler=expAiAltTextFieldAction`,
`Datatypes[]=ezimage`), listed in `[EditViewSettings] FieldActions[]`, and drawn by the edit section
`[EditSection_alttext]` (`Sections[]=alttext`, `Template=expai/edit/alttext.tpl`).

| Setting | Default | Effect |
| --- | --- | --- |
| `Enabled` | enabled | The "Write alt text" button, the report expai/alttext and its jobs. |
| `Model` | (empty) | The alias of the vision model to use first. Empty: the user's model, the site default, then any other model with `Capabilities[]=vision`; the first the user may use, that runs and that the data policy allows. |
| `ImageAlias` | original | The image alias whose file is sent (scaled down here to the limits). |
| `MaxEdge` | 1568 | The longest edge in pixels of the image sent; per provider `[Provider_<id>] ImageMaxEdge`. |
| `MaxBytes` | 3750000 | The most bytes of the image sent (before base64); per provider `[Provider_<id>] ImageMaxBytes`. |
| `JpegQuality` | 85 | The JPEG quality of a scaled-down image (lowered when the bytes do not fit). |
| `ImageRegions[]` | (empty) | Regions (us, eu, local) images may go to, on top of the object's `[DataPolicy]` rules. |
| `ImageZeroRetention` | disabled | enabled: images only go to providers with zero retention (none, zdr). |
| `MaxLength` | 150 | The longest alt text; the answer is cut at a word. |
| `MaxTokens` | 600 | The most output tokens of one suggestion. A reasoning model (capability `reasoning_effort`, an `Effort`, or `Reasoning=true`) gets at least 1000, since it spends output tokens thinking. |
| `MaxContextLength` | 4000 | The longest page text sent as context, in characters. |
| `CompanionAttributes[]` | alternative_text, alt_text | ezstring fields of the same object that repeat the alt text; filled when empty or equal to it. |
| `ExtraInstructions` | (empty) | House rules added to the prompt. |
| `ReportMaxObjects` | 500 | The most objects the report looks at below a node. |
| `MaxQueuePerRequest` | 100 | The most jobs one click on "Queue suggestions as drafts" creates. |
| `GroupBudget` | 0 | The default budget of the job group of one click, in `[Pricing] Currency` (0: none). |
| `EstimateTextTokens` | 1200 | The text tokens of one queued suggestion's estimate, besides the image tokens. |
| `EstimateOutputTokens` | 120 | The output tokens of one queued suggestion's estimate. |

Other files: `ezjscore.ini [ezjscServer_expaialttext]` (the function `expaialttext::suggest`), `expqueue.ini
[JobType_expai.alttext]` (the job type of the report), and `Capabilities[]=vision` in `[Model_<alias>]` (the models
that can look at images; the Anthropic models of `[AISettings]` have it).

## [EditPresets], [Presets]: editor presets and the Improve menu

See [presets.md](presets.md).

| Setting | Default | Effect |
| --- | --- | --- |
| `[EditPresets] Enabled` | `true` | The preset buttons, the Improve menu and the defaults line in the edit form (with `[EditViewSettings] Enabled` and the master switch). |
| `DefaultsLine` | `enabled` | The line above the fields with the model, its region, the lane and the estimated cost of one click. |
| `EstimateInputTokens`, `EstimateOutputTokens` | `2500`, `300` | The tokens one click is estimated at, for the defaults line. |
| `Presets[]` | title, summary, meta_description, keywords | The presets, in this order; each is `[FieldAction_preset_<name>]` (Label, Handler, Datatypes[], Input, Instruction, as any field action). |
| `Improve[]` | shorten, expand, clarify, grammar, simplify, formal, casual | The entries of the Improve menu; each is `[FieldAction_improve_<name>]`. |
| `ImproveDatatypes[]` | ezstring, eztext, ezxmltext | The datatypes that get the Improve menu (also needed in `[ToolSettings] WritableDatatypes[]`). |
| `[Presets] Class_<class identifier>[<preset>]` | article, blog_post, folder, ng_article, ng_blog_post, ng_news, ng_landing_page, ng_category | The fields a preset fills in a class: attribute identifiers, or `<attribute>.<part>` for an xrowmetadata part (title, description, keywords), separated by commas. A class without a line gets no presets. |

## [SummarySettings]: the "EI: Summary" page

See [summary.md](summary.md).

| Setting | Default | Effect |
| --- | --- | --- |
| `Contact` | (empty) | The contact for questions about the processing, printed under the title of `expai/summary`. |
| `Note` | (empty) | A note printed under the title (e.g. the reference of the record of processing activities). |

Other files: `ezjscore.ini [ezjscServer_expaipreset]` (the function `expaipreset::preset`), `menu.ini
[Leftmenu_setup]` (the entry Setup > EI: Summary, policy `expai/summary`).
