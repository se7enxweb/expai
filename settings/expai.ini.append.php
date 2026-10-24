<?php /* #?ini charset="utf-8"?

# expai - Exponential Intelligence
# The lists below (Tools[], WritableDatatypes[], Sections[], FieldActions[], Datatypes[]) are not emptied first, so
# another extension can add to them whatever its place in the extension order; to replace a list, empty it in
# settings/override (e.g. "FieldActions[]" alone on a line, then the entries you want).
# Every setting is described in doc/configuration.md. Put the API key ONLY in
# settings/override/expai.ini.append.php (never committed).

[AISettings]
# Off by default. When enabled, users with the policy expai/write see the
# "Exponential Intelligence" tab on the content full view and the controls in
# the content edit form. The browser never talks to the provider.
# The master switch: off means no tab, no edit form controls, no Vector, nothing queued, queued jobs held and no
# request to any provider (doc/switch-and-veto.md).
Enabled=false
# The siteaccesses on which expai is off although Enabled is on (a queued request: the siteaccess it was queued
# from). A siteaccess can also set Enabled in its own settings/siteaccess/<siteaccess>/expai.ini.append.php.
DisabledSiteAccesses[]

# anthropic: the Anthropic Messages API (the provider of the 0.0.1.0 settings; every other provider, OpenAI-compatible
#            services and local models included, is set up under [ProviderSettings] below)
# custom:    the class named in ProviderClass (implements expAiProvider)
Provider=anthropic
ProviderClass=

# The provider's API URL.
Endpoint=https://api.anthropic.com/v1/messages
AnthropicVersion=2023-06-01

# The model name the provider expects.
Model=claude-sonnet-5-5

# Users may choose another model from AllowedModels[] (dashboard, panel, edit form; preference expai_model).
# The site default (Model) is always allowed. Each entry was checked against the Anthropic Models API for 0.0.1.0.
AllowUserModelChoice=enabled
AllowedModels[]=claude-sonnet-5-5
AllowedModels[]=claude-opus-5-5
AllowedModels[]=claude-haiku-5-5
ModelLabels[claude-sonnet-5-5]=Claude Sonnet 5.5
ModelLabels[claude-opus-5-5]=Claude Opus 5.5
ModelLabels[claude-haiku-5-5]=Claude Haiku 5.5

# The API key. Put it in settings/override/expai.ini.append.php (or in an untracked location of
# [ProviderSettings] KeyLocations[]), never in a file git tracks.
ApiKey=
# Only for a key that is not scoped to a workspace: the workspace every request names.
AnthropicWorkspaceId=

# The longest answer (tokens) one call may produce, thinking included. Requests are sent without streaming; with
# Timeout=115 an answer that long may run out of time first: the limit then still keeps a cut-off answer readable.
MaxTokens=16000

# How much the model thinks before it answers: low, medium, high (empty: the model's default).
Effort=medium

# enabled: a request the model declines for a safety reason is retried by the API on the model
# Anthropic recommends for that case (server-side fallback, Claude API only). Sent only for the models that accept it
# (Claude Fable 5.1, Mythos 5.1, Opus 5.5, Opus 5, Sonnet 5.5; not Claude Haiku 5.5), or for a model with the capability
# refusal_fallback ([Model_<alias>] Capabilities[]); no_refusal_fallback turns it off for one.
RefusalFallback=enabled

# enabled: the API caches the stable start of each request (tools, instructions, earlier turns).
PromptCaching=enabled

# enabled: tool definitions go with strict: true (the API keeps every call to the tool's schema), for each tool whose
# schema allows it; disabled: as they are. The arguments of every call are checked against the schema before it runs.
StrictTools=enabled
# The limits of strict tools in one request (tools, optional parameters, parameters with more than one type, all
# strict tools together): a tool that would pass one is sent as it is.
StrictToolsMax=20
StrictOptionalMax=24
StrictUnionMax=16

# Seconds to wait for one provider call, and for the connection. Keep Timeout below the web
# server's and PHP's request time limits (see doc/architecture.md, "Long turns").
Timeout=115
ConnectTimeout=10

# When the model a request asked for (the user's choice, a thread's model, the model a conversation is bound to)
# cannot answer - stopped (a local server that does not run), unreachable (connection refused, name or TLS failure,
# no byte within the time limit), not allowed any more, removed, or kept away by the data policy - the request goes to
# the site default model instead (or the first allowed model that may take the content), and the editor sees one
# line saying so. Never for failures that would repeat on any model. disabled: the failure is shown as before.
FallbackToDefaultModel=enabled
# Seconds a provider's state (running, stopped, unreachable) is kept per process for the model pickers and the
# fallback (0: checked on every use). A local server is asked on the loopback address, never by running a command.
HealthCacheSeconds=30

# The longest answer accepted from the provider, in bytes.
MaxResponseBytes=4194304

# Where Anthropic processes the requests (us, eu or local) and how long it keeps them (none, zdr, 30d or unknown),
# for the data policy ([DataPolicy]) and the region shown per request. 30d: the commercial terms keep API data up
# to 30 days for abuse monitoring unless zero data retention was agreed. Check your own contract and write the day
# you did into TermsCheckedAt (YYYY-MM-DD; empty: not checked).
Region=us
Retention=30d
TermsCheckedAt=

[ProviderSettings]
# The providers the model picker offers, in this order. "anthropic" without a [Provider_anthropic] group is built
# from [AISettings] above (the settings of 0.0.1.0), with one model anthropic.<id> per [AISettings] AllowedModels[].
# Add another with Providers[]=<id> and a [Provider_<id>] group; its models are the [Model_<alias>] groups that name
# it. The groups below are examples that are not active until listed here (and given a key in settings/override).
Providers[]=anthropic

# The site default model alias (empty: anthropic.<[AISettings] Model>).
DefaultModel=

# The models of these providers can be chosen by every user with expai/write (as in 0.0.1.0). The models of every
# other provider need the policy expai/model_use (limitation Provider). Empty it ("ProvidersWithoutPolicy[]" alone)
# to require the policy for every choice but the site default.
ProvidersWithoutPolicy[]=anthropic

# Where an ApiKey or Token may be set besides settings/override: fnmatch patterns relative to the installation. A file
# there is accepted only when it is not shipped with the code (git does not track it; in a release without git: it
# lies outside the release, e.g. a symlinked shared directory). The default is the settings directory of any
# extension, for a git-ignored extension of secrets. A tracked file is always reported.
KeyLocations[]=settings/override
# (a star directly followed by a slash would end the PHP comment around this file; fnmatch() lets the star match
# slashes, so extension/*settings covers extension/<name>/settings/...)
KeyLocations[]=extension/*settings

# A provider: Type=anthropic | openai_chat | custom (Class=<class implementing expAiProvider>)
#   BaseURL         the API (openai_chat: the base, /chat/completions is added; anthropic: the messages URL)
#   ApiKey          in settings/override or a [ProviderSettings] KeyLocations[] location git does not track; ApiKeyHeader authorization (Bearer, default of openai_chat) |
#                   x-api-key (default of anthropic) | api-key (Azure) | x-goog-api-key | none (a local server)
#   Headers[]       extra request headers ("Name: value"; key headers are refused)
#   Flavor          openai | huggingface | openrouter | mistral | xai | ollama | llamacpp | generic (small differences)
#   Region          us | eu | local; Retention none | zdr | 30d | unknown; TermsCheckedAt YYYY-MM-DD
#   ExtraBody       a JSON object merged into every request body (e.g. OpenRouter provider routing)
#   Timeout, ConnectTimeout, MaxResponseBytes, RateLimitHeaders[] (glob patterns of headers kept for the log),
#   RetryAfterMax (seconds of retry-after waited for, default and most 5), RetryOnServerError (enabled: up to two retries)
#   Label           the name in the picker and in error texts; Managed=ollama: a local server expai/models manages
#
# Example: Hugging Face Inference Providers (router). The model id may end in :fastest, :cheapest, :preferred or
# :<provider> to choose the inference provider; pin one provider for a known data processor.
[Provider_huggingface]
Type=openai_chat
Flavor=huggingface
Label=Hugging Face
BaseURL=https://router.huggingface.co/v1
ApiKey=
Region=us
Retention=unknown
TermsCheckedAt=
Timeout=115

# Example: OpenRouter. It reports the cost of every request (usage.cost), which the log shows as "reported".
# ExtraBody keeps requests away from endpoints that store or train on data.
[Provider_openrouter]
Type=openai_chat
Flavor=openrouter
Label=OpenRouter
BaseURL=https://openrouter.ai/api/v1
ApiKey=
Headers[]
Headers[]=X-OpenRouter-Title: Exponential Intelligence
ExtraBody={"provider":{"data_collection":"deny","zdr":true}}
Region=us
Retention=zdr
TermsCheckedAt=
Timeout=115

# Example: Mistral (EU hosting).
[Provider_mistral]
Type=openai_chat
Flavor=mistral
Label=Mistral
BaseURL=https://api.mistral.ai/v1
ApiKey=
Region=eu
Retention=30d
TermsCheckedAt=
Timeout=115

# Example: Melious (EU inference, open-weight models; doc/providers.md "Melious"). Every request runs on a provider
# inside the EU, prompts and answers are not kept and no provider trains on them (melious.ai/docs/concepts/privacy,
# read 2026-10-12; it is not a data processing agreement). Prices are in EUR (PriceCurrency, converted with [Pricing]
# ExchangeRates[EUR]); every answer reports its cost (billing_cost.credits), which the log shows as "reported".
[Provider_melious]
Type=openai_chat
Flavor=melious
Label=Melious
BaseURL=https://api.melious.ai/v1
ApiKey=
Region=eu
Retention=zdr
TermsCheckedAt=2026-10-12
PriceCurrency=EUR
# Melious streams (SSE): the test prompt of expai/models is streamed to measure the time to the first token
Streaming=enabled
Timeout=115

# Example: a local Ollama on this server (see doc/local-models.md). No key; nothing leaves the server.
[Provider_local]
Type=openai_chat
Flavor=ollama
Label=Local model
BaseURL=http://127.0.0.1:11434/v1
ApiKeyHeader=none
Region=local
Retention=none
TermsCheckedAt=
Timeout=280
Managed=ollama
# #377 background runs (the queue worker: Vector, the Intelligence tab with a slow model) are not bound by a web
# server's limit: up to 900 s per call (the job's lease is 900 s + 60 s). A timeout there is shown, never a fallback.
TimeoutBackground=900
# Keep the model in memory this long after each request (an Ollama duration), so the next turn of an active thread
# finds it loaded and its prompt prefix cached; the idle timer stops Ollama after its own idle time.
KeepAlive=30m

# A model: Provider, Id (what the API expects), Label, ToolUse=native | prompted | none, WriteTools=enabled (drafts;
# also needs a passed probe unless WriteGate=trusted), Capabilities[] (json_schema, json_object, reasoning_effort,
# tool_choice_none, no_tool_choice, vision), MaxOutput, Effort, prices per million tokens (InputPerMTok,
# OutputPerMTok, CacheWritePerMTok, CacheReadPerMTok; 0 = free; none: [Pricing] by Id, else "cost unknown"),
# Slow=true ("slow" in the picker; default for Region=local), MaxTokensParameter, ParallelToolCalls, ExtraBody.
# The ids below are the examples of the providers' documentation on 2026-10-11: check the provider's model list.
[Model_huggingface.gpt-oss-120b]
Provider=huggingface
Id=openai/gpt-oss-120b:fastest
Label=gpt-oss-120b (Hugging Face)
ToolUse=native
WriteTools=enabled
Capabilities[]=json_schema
Capabilities[]=reasoning_effort
Effort=low
MaxOutput=8000

[Model_openrouter.gpt-oss-120b]
Provider=openrouter
Id=openai/gpt-oss-120b
Label=gpt-oss-120b (OpenRouter)
ToolUse=native
WriteTools=enabled
Capabilities[]=json_schema
Capabilities[]=reasoning_effort
Effort=low
MaxOutput=8000

[Model_local.qwen3.5-4b]
Provider=local
Id=qwen3.5:4b
Label=Qwen3.5 4B (local)
ToolUse=native
WriteTools=enabled
Capabilities[]=json_object
Capabilities[]=reasoning_effort
Capabilities[]=no_tool_choice
# thinking off: much faster on a CPU
Effort=none
MaxOutput=2000
InputPerMTok=0
OutputPerMTok=0
Slow=true
# #377 the compact prompt (doc/local-models.md "Making a local model usable"): a short system prompt, only the tools
# the message needs (from this list), a short snapshot of the open page. compact is the default of Region=local.
PromptProfile=compact
Tools[]
Tools[]=get_node
Tools[]=list_children
Tools[]=get_class_definition
Tools[]=create_draft
Tools[]=update_draft
Tools[]=propose_plan

# #377 the smaller, faster local option for simple prompts: Qwen3.5 2B (Apache-2.0, tool calling), created as
# exp-qwen3.5:2b from share/systemd/exp-qwen3.5-2b.Modelfile after a pull (expai/models). Reads only until it passes
# the probe; never the default unless chosen.
[Model_local.qwen3.5-2b]
Provider=local
Id=exp-qwen3.5:2b
Label=Qwen3.5 2B (local, faster)
ToolUse=native
WriteTools=enabled
Capabilities[]=json_object
Capabilities[]=reasoning_effort
Capabilities[]=no_tool_choice
Effort=none
MaxOutput=2000
InputPerMTok=0
OutputPerMTok=0
Slow=true
PromptProfile=compact

# The models of Melious the partner demo uses (GET https://api.melious.ai/v1/models?include_meta=true, 2026-10-12):
# prices in EUR per million tokens, ContextTokens the context window. All four call tools natively; glm-5.3-flash
# always reasons, the others are hybrid. Each may write drafts only after it passed the probe on expai/models.
[Model_melious.glm-5.3-flash]
Provider=melious
Id=glm-5.3-flash
Label=GLM 5.3 Flash (Melious)
ToolUse=native
WriteTools=enabled
Capabilities[]=json_object
Capabilities[]=reasoning_effort
Effort=low
MaxOutput=8000
ContextTokens=1000000
InputPerMTok=0.10
OutputPerMTok=0.40
CacheReadPerMTok=0.02

[Model_melious.glm-5.3]
Provider=melious
Id=glm-5.3
Label=GLM 5.3 (Melious)
ToolUse=native
WriteTools=enabled
Capabilities[]=json_object
Capabilities[]=reasoning_effort
Effort=low
MaxOutput=8000
ContextTokens=1000000
InputPerMTok=1.00
OutputPerMTok=3.00
CacheReadPerMTok=0.20

[Model_melious.deepseek-v4-flash-0731]
Provider=melious
Id=deepseek-v4-flash-0731
Label=DeepSeek V4 Flash 0731 (Melious)
ToolUse=native
WriteTools=enabled
Capabilities[]=json_schema
Capabilities[]=reasoning_effort
Effort=low
MaxOutput=8000
ContextTokens=1000000
InputPerMTok=0.10
OutputPerMTok=0.25
CacheReadPerMTok=0.02

[Model_melious.deepseek-v4.1-flash]
Provider=melious
Id=deepseek-v4.1-flash
Label=DeepSeek V4.1 Flash (Melious)
ToolUse=native
WriteTools=enabled
Capabilities[]=json_object
Capabilities[]=reasoning_effort
Effort=low
MaxOutput=8000
ContextTokens=1000000
InputPerMTok=0.20
OutputPerMTok=1.00
CacheReadPerMTok=0.01

# Examples of providers with lanes (not active until listed in [ProviderSettings] Providers[] and given a key).
[Provider_openai]
Type=openai_chat
Flavor=openai
Label=OpenAI
BaseURL=https://api.openai.com/v1
ApiKey=
Region=us
Retention=30d
TermsCheckedAt=
Timeout=115

# Gemini through its OpenAI-compatible endpoint; its batch lane uses the native API ([Lane_gemini.batch] BatchURL).
[Provider_gemini]
Type=openai_chat
Flavor=generic
Label=Google Gemini
BaseURL=https://generativelanguage.googleapis.com/v1beta/openai
ApiKey=
Region=us
Retention=unknown
TermsCheckedAt=
Timeout=115

# Lanes: [Lane_<provider>.<lane>] (doc/lanes.md). Every provider has the lane standard; the others are defined here.
# Type sync | batch; Enabled; PriceMultiplier (the factor on the list price; empty: not confirmed, the lane is not
# used); ExpectedLatency and MaxLatency (seconds); Retention inherit | none | zdr | 30d | 29d | 6w | unknown;
# RequestParams[<name>]=<value> merged into the request; Headers[]; FallbackLane; OnUnavailable retry | escalate;
# Timeout; Models[] (only these aliases); batch lanes: BatchApi anthropic | openai | gemini | mistral | openrouter,
# BatchURL, MaxRequestsPerBatch, BatchCollectSeconds, PollSeconds, DeleteAfterFetch, ExpiredLane. Verified and Source
# say when and where the offer was read. The concurrency of a lane is expqueue.ini [QueueResource_<provider>:<lane>].
[Lane_anthropic.standard]
Type=sync
PriceMultiplier=1.0
ExpectedLatency=60

# Message Batches: 50% of the standard prices; most batches end within an hour, unfinished requests expire after 24
# hours (not billed); requests and results are kept up to 29 days unless the batch is deleted, which expai does after
# fetching the results.
[Lane_anthropic.batch]
Type=batch
BatchApi=anthropic
Enabled=true
PriceMultiplier=0.5
ExpectedLatency=3600
MaxLatency=86400
MaxRequestsPerBatch=2000
BatchCollectSeconds=300
PollSeconds=300
Retention=29d
DeleteAfterFetch=enabled
ExpiredLane=standard
Verified=2026-10-11
Source=https://platform.claude.com/docs/en/build-with-claude/batch-processing

# Fast mode (research preview, needs access): Opus 5.5 at 2x ($8/$40 instead of $4/$20); falls back to standard when
# busy. Never chosen by the lane policy on its own: a request asks for it, and the user needs expai/lane_fast.
[Lane_anthropic.fast]
Type=sync
Enabled=false
PriceMultiplier=2.0
ExpectedLatency=20
RequestParams[]
RequestParams[speed]=fast
Headers[]
Headers[]=anthropic-beta: fast-mode-2026-02-01
FallbackLane=standard
Models[]
Models[]=anthropic.claude-opus-5-5
Verified=2026-10-11
Source=https://platform.claude.com/docs/en/build-with-claude/fast-mode

# Flex processing: batch prices (0.5x) for synchronous requests, slower; "429 Resource Unavailable" is not charged.
[Lane_openai.flex]
Type=sync
Enabled=true
PriceMultiplier=0.5
RequestParams[]
RequestParams[service_tier]=flex
Timeout=900
ExpectedLatency=300
OnUnavailable=escalate
FallbackLane=standard
Verified=2026-10-11
Source=https://developers.openai.com/api/docs/guides/flex-processing

[Lane_openai.batch]
Type=batch
BatchApi=openai
Enabled=true
PriceMultiplier=0.5
ExpectedLatency=3600
MaxLatency=86400
MaxRequestsPerBatch=2000
BatchCollectSeconds=300
PollSeconds=300
Retention=30d
DeleteAfterFetch=enabled
ExpiredLane=standard
Verified=2026-10-11
Source=https://developers.openai.com/api/docs/guides/batch

[Lane_gemini.batch]
Type=batch
BatchApi=gemini
BatchURL=https://generativelanguage.googleapis.com/v1beta
Enabled=true
PriceMultiplier=0.5
ExpectedLatency=3600
MaxLatency=86400
MaxRequestsPerBatch=500
BatchCollectSeconds=300
PollSeconds=300
Retention=6w
DeleteAfterFetch=enabled
ExpiredLane=standard
Verified=2026-10-11
Source=https://ai.google.dev/gemini-api/docs/batch-api

# Flex inference (50%, 1-15 minutes, sheddable with 429/503) is documented for the native API; whether the
# OpenAI-compatible endpoint takes service_tier was not shown: disabled until it is tested.
[Lane_gemini.flex]
Type=sync
Enabled=false
PriceMultiplier=0.5
RequestParams[]
RequestParams[service_tier]=flex
Timeout=900
ExpectedLatency=900
OnUnavailable=escalate
FallbackLane=standard
Verified=2026-10-11
Source=https://ai.google.dev/gemini-api/docs/flex-inference

[Lane_mistral.batch]
Type=batch
BatchApi=mistral
Enabled=true
PriceMultiplier=0.5
ExpectedLatency=3600
MaxLatency=86400
MaxRequestsPerBatch=2000
BatchCollectSeconds=300
PollSeconds=300
Retention=30d
DeleteAfterFetch=enabled
ExpiredLane=standard
Verified=2026-10-11
Source=https://docs.mistral.ai/studio/batch-processing

# OpenRouter: "typically billed at 50%"; the :batch model variants carry their own prices. Confirm the price of the
# model you use, then enable.
[Lane_openrouter.batch]
Type=batch
BatchApi=openrouter
Enabled=false
PriceMultiplier=0.5
ExpectedLatency=3600
MaxLatency=86400
MaxRequestsPerBatch=2000
BatchCollectSeconds=300
PollSeconds=300
Retention=30d
DeleteAfterFetch=enabled
ExpiredLane=standard
Verified=2026-10-11
Source=https://openrouter.ai/docs/batch-quickstart

# service_tier flex (OpenAI and Google endpoints only; never falls back); the price depends on the endpoint.
[Lane_openrouter.flex]
Type=sync
Enabled=false
PriceMultiplier=
RequestParams[]
RequestParams[service_tier]=flex
Timeout=900
ExpectedLatency=300
OnUnavailable=escalate
FallbackLane=standard
Verified=2026-10-11
Source=https://openrouter.ai/docs/guides/features/service-tiers

[LanePolicy]
# The lanes a queued request tries, in order, by its purpose (expAiQueue::request() option purpose), else by its
# queue class, else Order[default]. Lanes separated by ";". standard is always the last resort; fast is never taken
# from an order (only when a request asks for it).
Order[]
Order[default]=standard
Order[bulk]=batch;flex;standard
Order[translate]=flex;batch;standard
Order[translate_full]=batch;flex;standard
ClassOrder[]
ClassOrder[interactive]=standard
ClassOrder[editorial]=standard;flex
ClassOrder[chain]=standard;flex
ClassOrder[translation_patch]=flex;batch;standard
ClassOrder[translation_full]=batch;flex;standard
ClassOrder[bulk]=batch;flex;standard
# The queue class of a purpose (default: editorial)
PurposeClass[]
PurposeClass[default]=editorial
PurposeClass[bulk]=bulk
PurposeClass[translate]=translation_patch
PurposeClass[translate_full]=translation_full
# A request of this priority or higher skips flex and batch lanes (0: never)
UrgentPriority=80
# enabled: a deadline is checked against a batch lane's MaxLatency instead of its ExpectedLatency
StrictDeadline=disabled

[Budgets]
# The monthly budget of the whole site (in [Pricing] Currency) for queued requests: what every request of the month
# cost plus what queued requests reserved must stay below it, or new and waiting requests are held. Empty: [Limits]
# MaxCostPerSitePerMonth, else none.
SiteMonthly=
# The budget of a new job group when its producer names none (empty: none)
DefaultGroupBudget=
# A queued request reserves its estimate times this factor
ReserveFactor=1.25
# Estimates: characters per token of the request text, and the share of max_tokens expected as output
CharsPerToken=3.5
OutputShareOfMax=0.5

[QueueSettings]
# Classes that apply the answer of a queued request: Appliers[<name>]=<class implementing expAiRequestApplier>
Appliers[]
# The longest answer kept in a job's checkpoint, in bytes
MaxStoredAnswerBytes=200000

[AssistantSettings]
# The most provider calls one message of the editor may lead to (each tool round is one call).
# The last allowed call is made without tools, so the assistant always answers in words.
MaxToolIterations=10

# The longest result of one tool, in bytes (longer results are shortened and say so).
MaxToolResultBytes=16000

# The longest stored conversation, in bytes. Longer conversations must be started anew.
MaxConversationBytes=400000

# The longest message of the editor, in characters.
MaxMessageLength=4000

# The most drafts the assistant may create or change in one conversation.
MaxDraftsPerConversation=10

# Seconds a conversation is kept after its last message (older ones start anew).
ConversationLifetime=604800

# The exchanges (an editor message and what followed) sent to the provider. When the window is full, the older
# exchanges become a short summary (cut from their text, no extra call) and the last half are kept word for word;
# the panel still shows the whole history.
ContextWindowTurns=8

# #377 the longest snapshot of the open page in a compact prompt ([Model_<alias>] PromptProfile=compact), in characters.
CompactContextChars=1800

# Extra instructions appended to the built-in system prompt (one line per entry), e.g.
# ExtraInstructions[]=Write in British English.

# Where the write tools of a conversation may write: subtree (the open node and below in the panel, the scope in a
# Vector thread) or site (anywhere the editor may write).
WriteScope=subtree

[ToolSettings]
# The tools the assistant can call, in this order. Each is described in [Tool_<name>].
Tools[]=get_node
Tools[]=list_children
Tools[]=get_tree_context
Tools[]=list_creatable_classes
Tools[]=get_class_definition
Tools[]=search_content
Tools[]=create_draft
Tools[]=update_draft

# Datatypes the assistant may write. Everything else (images, files, relations, ...) is skipped and
# reported back to the assistant.
WritableDatatypes[]=ezstring
WritableDatatypes[]=eztext
WritableDatatypes[]=ezxmltext
WritableDatatypes[]=ezkeyword
WritableDatatypes[]=ezboolean
WritableDatatypes[]=ezinteger
WritableDatatypes[]=ezfloat
WritableDatatypes[]=ezdate
WritableDatatypes[]=ezdatetime
WritableDatatypes[]=ezemail
WritableDatatypes[]=ezurl

# The most children / siblings one lookup returns.
ListLimit=25

# The longest field value the lookup tools return, in characters (rich text: [RichTextSettings] MaxLength).
FieldValueMaxLength=3000

[RichTextSettings]
# Rich text the model reads and writes (the tools, Vector, a plan's batch requests; doc/configuration.md
# "[RichTextSettings]"). enabled: what the HTML subset cannot express is a placeholder such as ⟦C1⟧ (a formula, custom tag, embed, table, internal link,
# formatting, the rest of a long field); an answer is written only when every placeholder comes back exactly once and
# the value keeps all its custom tags, embeds, tables, ... (otherwise Vector shows it for review). disabled: the old
# plain HTML view, which loses that structure.
Placeholders=enabled
# The datatypes whose stored value is ezxmltext and that get placeholders. A datatype of another extension that
# stores ezxmltext can be added here.
Datatypes[]=ezxmltext
# The characters of a rich text field the model reads. A longer field ends in one placeholder for its rest, which is
# written back unchanged. Empty: [EditViewSettings] MaxFieldLength.
MaxLength=
# The elements that are always kept whole as a placeholder, with the letter of the placeholder (C, E, T, P, A, or any
# other capital except K, L, Q, R and X; Q stands for a ⟦ or ⟧ of the text). Elements outside the HTML subset that are
# not listed become X placeholders; links the subset cannot keep become L. Custom tags (math_latex, math_mathml, ...)
# are kept with all their attributes.
Protected[custom]=C
Protected[embed]=E
Protected[embed-inline]=E
Protected[table]=T
Protected[literal]=P
Protected[anchor]=A

[Tool_get_node]
Class=expAiToolGetNode

[Tool_list_children]
Class=expAiToolListChildren

[Tool_get_tree_context]
Class=expAiToolGetTreeContext

[Tool_list_creatable_classes]
Class=expAiToolListCreatableClasses

[Tool_get_class_definition]
Class=expAiToolGetClassDefinition

[Tool_search_content]
Class=expAiToolSearchContent

[Tool_create_draft]
Class=expAiToolCreateDraft

[Tool_update_draft]
Class=expAiToolUpdateDraft

[PanelSettings]
# The sections of the full view panel, in this order: [PanelSection_<name>] Template=
Sections[]=conversation
Sections[]=drafts
# threads on this node and "Open in Vector" ([PanelSection_threads], doc/threads.md)
Sections[]=threads

[PanelSection_conversation]
Template=expai/panel/conversation.tpl

[PanelSection_drafts]
Template=expai/panel/drafts.tpl

# ------------------------------------------------------------------ AI chains (#359 P4)
# doc/chains.md: multi-step writing pipelines. Each model step is one queued request (expqueue), the run has a job
# group with the run budget, the result is one draft, a human checkpoint comes before anything else happens.

# "Write with a chain" in the Intelligence tab (users with expai/chain)
[PanelSettings]
Sections[]=chains

[PanelSection_chains]
Template=expai/panel/chains.tpl

# The applier that records a chain step's answer and queues the next step
[QueueSettings]
Appliers[chain]=expAiChainApplier

[ChainSettings]
# The chains users may start (policy expai/chain, limitation Chain): [Chain_<id>] and [ChainStep_<id>.<step>]
Chains[]
Chains[]=article
# Where prompt templates (PromptTemplate=) are looked up, relative to the installation; the first that has the file
# wins, extension/expai/design/admin/templates comes last. Put site prompts in your own extension's directory.
PromptDirectories[]
# Added to the system prompt of every chain step (house style, audience)
SystemPrompt=
# The longest brief, in characters
MaxBriefLength=4000
# The longest context the read tools of a step (Tools[]) may add to its prompt, in characters
MaxContextChars=12000
# The longest step output kept in expai_chain_step.output, in bytes
MaxStoredOutputBytes=200000
# The class that takes the translation step (a static method chainTranslate( expAiChainRun $run, array $languages )
# that queues the translations of the run's draft; expAiChainTranslateSync hands it to the translation sync,
# doc/translation-sync.md); empty: the step is skipped
TranslateHandler=expAiChainTranslateSync

# The default chain: Opus writes, Sonnet reviews (revised until it passes, at most three reviews), Haiku prepares
# title, summary and keywords and checks the links, then an editor approves before the translation.
[Chain_article]
Label=Article: write, review, SEO, approve
Description=Writes an article from a brief, reviews and revises it until it passes, adds title, summary and keywords; an editor approves the draft before it is translated.
Class=article
Fields[]
Fields[]=title
Fields[]=intro
Fields[]=body
Steps[]
Steps[]=write
Steps[]=review
Steps[]=seo
Steps[]=checkpoint
Steps[]=translate
# USD; the run is held when its next step would go beyond it (also the budget of its job group)
MaxCostPerRun=1.00
# The most items (runs) one start may ask for
MaxItems=5

[ChainStep_article.write]
Type=generate
Label=Write
Model=anthropic.claude-opus-5-5
Lane=auto
PromptTemplate=expai/chains/article_write.tpl
Tools[]
Tools[]=get_tree_context
Tools[]=list_children
MaxTokens=6000

[ChainStep_article.review]
Type=review
Label=Review
Model=anthropic.claude-sonnet-5-5
Lane=auto
PromptTemplate=expai/chains/article_review.tpl
Checklist[]
Checklist[]=It does what the brief asks for
Checklist[]=No invented facts, figures, names or quotes
Checklist[]=The intro stands on its own and matches the body
Checklist[]=Clear structure and readable sentences
PassCriteria[]
PassCriteria[]=score>=80
PassCriteria[]=issues.high==0
PassCriteria[]=checklist.failed==0
MaxIterations=3
OnFail=revise
ReviseStep=revise
OnExhausted=escalate
MaxTokens=3000

[ChainStep_article.revise]
Type=generate
Label=Revise
Model=anthropic.claude-opus-5-5
Lane=auto
PromptTemplate=expai/chains/article_revise.tpl
Next=review
MaxTokens=6000

[ChainStep_article.seo]
Type=transform
Label=SEO and QA
Model=anthropic.claude-haiku-5-5
Lane=auto
PromptTemplate=expai/chains/article_seo.tpl
OutputFields[]
OutputFields[]=short_title
PassCriteria[]
PassCriteria[]=links.broken==0
PassCriteria[]=issues.high==0
OnFail=escalate
MaxTokens=1500

[ChainStep_article.checkpoint]
Type=human
Label=Editor approves

[ChainStep_article.translate]
Type=translate
Label=Translate
Languages[]
Languages[]=all

[EditViewSettings]
# The controls in the content edit form (content.ini [EditSettings] AdditionalTemplates[]).
Enabled=true

# true: above a rich text field (ezxmltext) whose editor has an assistant of its own that keeps formulas, embeds and
# tables (Tiptap, exp_oe_tiptap, with its AI enabled), the field actions (Write, Rewrite, Shorten, Translate ...) and the
# Improve menu are not shown: they only know the simple HTML subset and would ask for a review of everything else.
# The presets of the field (e.g. Write summary) stay. The editor is read live, so TinyMCE or no editor shows them again.
# false: the field actions are shown above every field, whatever the editor.
HideActionsWithEditorAI=true

# The most characters of a field's current value the model gets (field actions, presets and the Improve menu). A longer
# field is cut there; the edit form then shows the answer for review instead of writing it over the whole field.
MaxFieldLength=20000

# The sections of the edit view area, in this order: [EditSection_<name>] Template=
Sections[]=assistant

# The actions offered next to each writable field, in this order: [FieldAction_<name>]
FieldActions[]=write
FieldActions[]=rewrite
FieldActions[]=shorten
FieldActions[]=translate
FieldActions[]=suggest

[EditSection_assistant]
Template=expai/edit/assistant.tpl

# A field action: Label (translated in context extension/expai/fieldaction), Handler (a class implementing
# expAiFieldAction), Datatypes[] (where it is offered), Input (none, instruction, optional_instruction or language)
# and Instruction (what the model is asked to do; the handler adds the field, the object and the rules).
[FieldAction_write]
Label=Write
Handler=expAiPromptFieldAction
Datatypes[]=ezstring
Datatypes[]=eztext
Datatypes[]=ezxmltext
Input=instruction
Instruction=Write a new value for this field following the editor's instruction. Use the other fields of the object as context.

[FieldAction_rewrite]
Label=Rewrite
Handler=expAiPromptFieldAction
Datatypes[]=ezstring
Datatypes[]=eztext
Datatypes[]=ezxmltext
Input=optional_instruction
Instruction=Rewrite the current value of this field: clearer and better written, same meaning, same language, about the same length. Follow the editor's instruction when one is given.

[FieldAction_shorten]
Label=Shorten
Handler=expAiPromptFieldAction
Datatypes[]=eztext
Datatypes[]=ezxmltext
Input=none
Instruction=Shorten the current value of this field to about half its length. Keep the key facts and the language.

[FieldAction_translate]
Label=Translate
# the translation service of the pair ([TranslationSettings], purpose field); with llm the prompt below
Handler=expAiTranslateFieldAction
Datatypes[]=ezstring
Datatypes[]=eztext
Datatypes[]=ezxmltext
Input=language
Instruction=Translate the current value of this field into the target language. Keep the meaning, the tone and the formatting.

[FieldAction_suggest]
Label=Suggest
Handler=expAiPromptFieldAction
Datatypes[]=ezstring
Datatypes[]=ezkeyword
Input=none
Instruction=Suggest a good value for this field based on the other fields of the object (for a keyword field: five to eight comma separated keywords).

[DashboardSettings]
# The location "Start writing" on expai/dashboard uses by default: the editor's request then works on this node
# and everything below it (always within the editor's own permissions). Empty: content.ini [NodeSettings] RootNode.
# Users who may not read it or create below it choose a location instead.
DefaultNodeID=

[LabelSettings]
# enabled: draft lists, version views, edit forms and approval items show the
# "Exponential Intelligence" label on versions the assistant wrote.
Enabled=enabled

[LogSettings]
# The longest stored summary of the editor's request, in characters.
MaxPromptSummaryLength=200


[UsageSettings]
# Every request to the model is recorded in expai_usage_log (tokens, latency, tools, errors; user ids, no names).
# Rows older than this many days are removed by the cronjob part "expai" (0 keeps everything).
RetentionDays=365
# enabled: the full request (instructions, messages, tool definitions) is stored with each row, for debugging.
# It contains content and the editors' words: leave it disabled in production.
LogRequests=disabled

[RetentionSettings]
# How long the editors' words are kept, in days; 0 keeps them. Applied on every run of the cronjob part "expai"
# (cronjobs/expai_prune.php) and by bin/php/expaithreads.php --prune (--dry-run counts only).
# report (the default of this release): the cronjob only says what it would remove, to check the periods before they
# apply; delete: the rows go. A later release may switch the default to delete: set Mode explicitly to keep a choice.
Mode=report
# A thread (with its runs, events, proposals, checkpoints, plans and conversations) not modified for ThreadKeepDays
# is removed, unless it is pinned, in review, waiting for an answer, or running (a run that stopped reporting for
# 4 x [ThreadSettings] StaleRunSeconds does not count as running).
ThreadKeepDays=365
# A conversation not modified for ConversationKeepDays is removed, unless it belongs to a thread that stays.
ConversationKeepDays=365
# The summary of the editor's request in the draft log (expai_draft_log.prompt_summary) is blanked after
# PromptSummaryKeepDays; the row itself stays as the record of the draft.
PromptSummaryKeepDays=365
# Independent of these periods: the threads and conversations of a user that no longer exists are removed.

[Limits]
# Every limit is optional: empty or 0 means no limit. Tokens are input + output + cache write + cache read; costs are
# the [Pricing] estimate. Checked before every request to the model; the editor sees which limit and when it resets.
# Days and months follow the site's time zone.
# Per panel conversation (resets with "New conversation") and per login session (resets when the user logs in again):
MaxTokensPerConversation=
MaxTokensPerSession=
# Per user and day (reset at midnight) and month (reset on the 1st):
MaxTokensPerUserPerDay=
MaxCostPerUserPerDay=
MaxTokensPerUserPerMonth=
MaxCostPerUserPerMonth=
# All users together per month:
MaxTokensPerSitePerMonth=
MaxCostPerSitePerMonth=
# The most model calls one editor message may lead to; empty: [AssistantSettings] MaxToolIterations.
MaxRequestsPerTurn=
# All users together on one provider per month, e.g. ProviderMaxCostPerMonth[openrouter]=20
ProviderMaxTokensPerMonth[]
ProviderMaxCostPerMonth[]
# A request to a model without a price ([Pricing]) while a cost limit applies: block refuses it (the limit could not be
# kept), ignore lets it through and counts it as free.
UnpricedCost=block
# While any limit is set, every request reserves its estimate (input from its length, its MaxTokens as output) before
# it is sent, and the requests under way count against the limits until they are settled.

[Pricing]
# Prices per million tokens per model (USD) for the ESTIMATED cost on expai/log and the dashboard. Anthropic's invoice
# is authoritative. Cache write is the 5-minute cache price (expai uses the default 5-minute cache). Not applied unless
# you configure them: the US-only inference_geo multiplier (1.1x), fast mode premiums (use Multiplier=).
# Models are matched by id, then without a date or -latest suffix, then by family and version; a model without an
# entry is priced with the Default* prices and shown as approximate. Override any entry in settings/override.
# Enabled=false hides costs everywhere.
Enabled=true
Currency=USD
PricesAsOf=2026-10-11
Source=https://platform.claude.com/docs/en/about-claude/pricing
Multiplier=1.0
# Providers that price in another currency ([Provider_<id>] or [Model_<alias>] PriceCurrency, e.g. Melious in EUR) are
# converted into Currency with the reference rates of the European Central Bank (ecb, the default: fetched once a day
# by the job expai.exchange.rates, the cronjob part expai_exchange_rates.php or ./console ext:expai:exchange-rates; a
# failed fetch keeps the last rates), shown with their date on expai/models. ExchangeRates[<code>] (what one unit of
# that currency is in Currency) is the fallback while there is no ECB rate; ExchangeRateSource=settings uses only
# ExchangeRates[] (an override). Without any rate the cost is "unknown".
ExchangeRateSource=ecb
ExchangeRatesMaxAgeHours=26
ExchangeRates[]
ExchangeRates[EUR]=1.16
ExchangeRatesAsOf=2026-10-12

# Fallback for models without an entry (the prices of the default model, Claude Sonnet 5.5)
DefaultInputPerMTok=2
DefaultCacheWritePerMTok=2.50
DefaultCacheReadPerMTok=0.20
DefaultOutputPerMTok=10

InputPerMTok[claude-fable-5-1]=10
CacheWritePerMTok[claude-fable-5-1]=12.50
CacheReadPerMTok[claude-fable-5-1]=0.25
OutputPerMTok[claude-fable-5-1]=50

InputPerMTok[claude-opus-5-5]=4
CacheWritePerMTok[claude-opus-5-5]=5
CacheReadPerMTok[claude-opus-5-5]=0.20
OutputPerMTok[claude-opus-5-5]=20

InputPerMTok[claude-opus-5]=5
CacheWritePerMTok[claude-opus-5]=6.25
CacheReadPerMTok[claude-opus-5]=0.50
OutputPerMTok[claude-opus-5]=25

InputPerMTok[claude-sonnet-5-5]=2
CacheWritePerMTok[claude-sonnet-5-5]=2.50
CacheReadPerMTok[claude-sonnet-5-5]=0.20
OutputPerMTok[claude-sonnet-5-5]=10

InputPerMTok[claude-sonnet-5]=2
CacheWritePerMTok[claude-sonnet-5]=2.50
CacheReadPerMTok[claude-sonnet-5]=0.20
OutputPerMTok[claude-sonnet-5]=10

InputPerMTok[claude-sonnet-4-6]=3
CacheWritePerMTok[claude-sonnet-4-6]=3.75
CacheReadPerMTok[claude-sonnet-4-6]=0.30
OutputPerMTok[claude-sonnet-4-6]=15

# Claude Haiku 5.5: two tiers; a request whose prompt (input + cache write + cache read tokens) is over the threshold
# is priced with the *Long prices
InputPerMTok[claude-haiku-5-5]=0.10
CacheWritePerMTok[claude-haiku-5-5]=0.125
CacheReadPerMTok[claude-haiku-5-5]=0.01
OutputPerMTok[claude-haiku-5-5]=0.50
LongContextThreshold[claude-haiku-5-5]=100000
InputPerMTokLong[claude-haiku-5-5]=0.50
CacheWritePerMTokLong[claude-haiku-5-5]=0.625
CacheReadPerMTokLong[claude-haiku-5-5]=0.05
OutputPerMTokLong[claude-haiku-5-5]=2.50

InputPerMTok[claude-haiku-4-5-20251001]=1
CacheWritePerMTok[claude-haiku-4-5-20251001]=1.25
CacheReadPerMTok[claude-haiku-4-5-20251001]=0.10
OutputPerMTok[claude-haiku-4-5-20251001]=5

[DataPolicy]
# Where content may be sent (doc/data-policy.md). Every provider has a Region (us, eu, local) and a Retention (none,
# zdr, 30d, unknown). A requirement names the regions content may go to, separated by ";"; a local provider meets
# every region requirement. Zero retention is met by Retention=none or zdr. Without any rule everything is allowed.
# Regions any content may go to (empty: every region):
DefaultRegions[]
# SectionRegions[<section id>]=eu;local   ClassRegions[<class identifier>]=local
SectionRegions[]
ClassRegions[]
# enabled: every request needs zero retention
ZeroRetention=disabled
ZeroRetentionSections[]
ZeroRetentionClasses[]

# ------------------------------------------------------------------ the veto hook and service tools (#370)
# doc/switch-and-veto.md, doc/service-tools.md

[RequestVeto]
# Rules checked before every request to a provider and before a request is queued, in this order; the first rule
# whose conditions all match refuses the request with its Reason (shown to the editor, logged in the usage log with
# error_class veto). Then the ezpEvent filter expai/request/veto (site.ini [Event] Listeners[]) may refuse it too.
# Each rule is a group [RequestVeto_<name>] with any of: Sections[] (section ids), Classes[] (class identifiers),
# Subtrees[] (node ids), SiteAccesses[], Providers[] (provider ids), Regions[] (us, eu, local), Purposes[] (panel,
# vector, test, probe, a field action name, queue.<purpose>; a trailing * matches the rest) and Reason=. A rule
# without conditions refuses every request (maintenance). Example (not active):
#   Rules[]=no_ai_on_users
#   [RequestVeto_no_ai_on_users]
#   Sections[]=2
#   Reason=Exponential Intelligence is not used for user accounts.
Rules[]

[ServiceTools]
# The read services of the extension expservices the assistant may call (tools exp_svc_<domain>_<method>). A service
# opts in in its declaration ('ai' => true, or 'ai' => array( 'summary' => '...' )); a site can opt in more here
# without changing code. A service that writes is never offered. Every user also needs the policy expai/services
# (limitation Domain), ezjscore/call and the access the service declares.
# disabled: no service is offered
Enabled=enabled
# <domain>::<method>, or <domain>::* for every read service of a domain
Services[]
# whole domains (every read service of the domain)
Domains[]
# never offered (same patterns as Services[]), e.g. node::countByClass
Exclude[]

[ProbeSettings]
# The pass rate (percent) of a live probe (bin/php/expaiprobe.php --model=<alias> --live) a model needs before it may
# write drafts (WriteTools=enabled, ToolUse=native, and not WriteGate=trusted).
WritePassRate=90
# The tasks a model must pass before it may write, whatever its pass rate: a model that does not use a tool result,
# follows an instruction inside content, or cannot create or change a draft stays read only. MandatoryTasks[] with no
# value after it makes no task mandatory.
MandatoryTasks[]
MandatoryTasks[]=use_result
MandatoryTasks[]=injection
MandatoryTasks[]=create_draft
MandatoryTasks[]=update_draft
# The node and class the probe tasks name (nothing is read or written: the tools are not run). Empty: the content root.
NodeID=
ClassIdentifier=folder
# A task marked follow_ok (create_draft, update_draft) whose model looks things up first is answered with scripted
# lookup results this many times (0..5), then the next call is graded; the result records how often (doc/probe.md).
FollowRounds=2

[LocalModelSettings]
# A local Ollama managed through expai/models (doc/local-models.md). The web process never runs commands: it writes a
# request into the spool directory, which a root-owned helper (share/systemd/) carries out.
SpoolDirectory=/var/lib/expai-llm/spool
StatusFile=/var/lib/expai-llm/status.json
# The models the view offers to pull and remove (the helper has its own allowlist, /etc/expai-llm/models.allow).
Models[]=qwen3.5:4b
# #377 the smaller, faster option for simple prompts (Apache-2.0, tool calling; model local.qwen3.5-2b)
Models[]=qwen3.5:2b

[McpBridge]
# enabled: the panel can use the bridge-safe read tools of the extension expmcp in process, as the current user,
# named exp__<tool> (policy expai/mcp with Server "exp"). Tools[] is the opt-in allowlist; a name expmcp does not
# declare bridge-safe (expMcpBridge::isBridgeSafe(), expmcp 0.3.1 or later) is ignored. With an older expmcp only
# exp_content_query is offered. Also bridge-safe, not listed by default: exp_url, exp_access_check.
Enabled=disabled
Tools[]
Tools[]=exp_content_query
Tools[]=exp_content_get
Tools[]=exp_search
Tools[]=exp_content_relations
Tools[]=exp_content_versions
Tools[]=exp_content_meta
Tools[]=exp_class_list
Tools[]=exp_class_describe

[McpSettings]
# External MCP servers (streamable HTTP), each [McpServer_<id>] (id: a-z and 0-9, at most 20 characters).
Servers[]

# Example (not active until listed in Servers[]):
[McpServer_docs]
Label=Documentation search
# https only; http only for a loopback address with AllowLoopback=enabled
URL=https://mcp.example.org/mcp
# in settings/override or a [ProviderSettings] KeyLocations[] location that git does not track
Token=
# The tools the panel may call; everything else the server offers is ignored.
AllowedTools[]=search_docs
Region=us
Retention=unknown
MaxResultBytes=16000
Timeout=20
ToolsCacheTTL=3600
ProtocolVersion=2025-06-18
AllowLoopback=disabled
AllowPrivate=disabled

# ------------------------------------------------------------------ threads, Vector and the keyboard (#360)
# doc/threads.md, doc/vector.md, doc/keyboard.md

[PanelSection_threads]
Template=expai/panel/threads.tpl

[ThreadSettings]
# Threads: the panel's conversations and Vector's units of work (tables expai_thread, expai_thread_run,
# expai_thread_event, ...). The list shows at most ListLimit threads per shelf; titles are cut to TitleLength.
ListLimit=100
TitleLength=80
# A foreground run that has not reported for this many seconds (closed tab, request timed out) is shown as stopped
StaleRunSeconds=900
# The largest event payload (bytes); bigger ones keep their keys with long strings cut
MaxEventPayloadBytes=16384
# Retention in days (cronjob part "expai", bin/php/expaithreads.php --prune); 0 keeps the rows.
# Owner decision: events, staged proposals and checkpoints are kept 30 days.
EventKeepDays=30
ProposalKeepDays=30
CheckpointKeepDays=30

[VectorSettings]
# Vector (expai/vector, policy expai/vector): the workspace with threads, scope, mode, model and lane.
Enabled=true
# The tabs of the right pane, in this order: [VectorPane_<name>] Template=
Panes[]=plan
Panes[]=diff
Panes[]=cost
# A new thread: write | plan (bulk scopes always start in plan mode)
DefaultMode=write
# A new thread: staged (changes are reviewed before they are written) | direct (one object, one language only)
DefaultReview=staged
# Bulk work: more objects or languages than these (or any subtree or class) start with a plan
BulkObjectThreshold=1
BulkLanguageThreshold=1
# How often Vector asks for changes (expaivector::events, milliseconds): while the open thread runs, while another
# thread works, and otherwise. Doubled while the host is busy; no polling while the browser tab is hidden. Each poll
# costs one kernel bootstrap (about 60-80 ms CPU), writes nothing and releases the session lock at once.
PollActiveMs=1000
PollWorkingMs=3000
PollIdleMs=15000
# How events reach the browser (expai/stream): auto | poll | sse. auto: Server-Sent Events under PHP-FPM/Apache
# (the proxy must flush each packet: flushpackets=on) and under Velocity (its own stream cap: 503 past it); polling elsewhere.
# A browser that gets no first line within 2 s falls back to polling.
Transport=auto
# Background runs (owner decision: slow models never run inside one web request): auto | always | never.
# auto: in the background when the model is slow ([Model_<alias>] RunInBackground=always|never, else its Slow flag;
# local models are slow). Needs the extension expqueue; the panel uses it too.
Background=auto
# Without a running worker, start one detached worker for interactive jobs right after queueing (the cron part of
# expqueue stays the safety net). PhpCli: the PHP command line binary for it (empty: the one of this PHP).
KickWorker=enabled
PhpCli=
# Pass --allow-root-user to the started worker. Off: a worker refuses to run as root. Enable it only for a site whose
# PHP itself runs as root (then the files the worker writes belong to root).
WorkerAllowRoot=disabled
# Streams open at once (one PHP process each: at most 25 % of PHP-FPM pm.max_children); over it the browser polls
MaxStreams=4
# Seconds one stream is held before the browser reconnects (Last-Event-ID)
StreamSeconds=25

[VectorPane_plan]
Template=expai/vector/pane_plan.tpl

[VectorPane_diff]
Template=expai/vector/pane_diff.tpl

[VectorPane_cost]
Template=expai/vector/pane_cost.tpl

[KeyBindings]
# The command palette and the shortcuts (doc/keyboard.md). Binding[<command>]=<keys>: modifiers Mod (Cmd on macOS,
# Ctrl elsewhere), Ctrl, Alt, Shift, Meta joined with + to a KeyboardEvent.code (KeyK, Enter, Escape, ArrowDown,
# Digit1) or one character (?); two chords separated by a space must follow within 600 ms ("Escape Escape").
# When[<command>]: global (any admin page, never inside a field or a rich text editor), vector (Vector, also in a
# field for combinations with Mod/Alt and Escape), list (Vector, not in a field), composer (the message field).
# The palette on every admin page: GlobalPalette=enabled|disabled (Vector always has it).
GlobalPalette=enabled
# Users may change their shortcuts in Vector's shortcut sheet (preference expai_keybindings)
UserOverrides=enabled
Binding[palette]=Mod+KeyK
When[palette]=global
Binding[run]=Mod+Enter
When[run]=vector
Binding[queue]=Alt+Enter
When[queue]=composer
Binding[stop]=Escape Escape
When[stop]=vector
Binding[next]=KeyJ
When[next]=list
Binding[previous]=KeyK
When[previous]=list
Binding[accept]=KeyA
When[accept]=list
Binding[reject]=KeyX
When[reject]=list
Binding[thread_next]=Alt+ArrowDown
When[thread_next]=vector
Binding[thread_previous]=Alt+ArrowUp
When[thread_previous]=vector
Binding[new_thread]=KeyN
When[new_thread]=list
Binding[mode]=Alt+KeyP
When[mode]=vector
Binding[model]=Alt+KeyM
When[model]=vector
Binding[lane]=Alt+KeyL
When[lane]=vector
Binding[scope]=Alt+KeyS
When[scope]=vector
Binding[tab_plan]=Digit1
When[tab_plan]=list
Binding[tab_diff]=Digit2
When[tab_diff]=list
Binding[tab_cost]=Digit3
When[tab_cost]=list
Binding[help]=?
When[help]=list

# ------------------------------------------------------------------ staged review and plan mode (#360 V4, V6)
# doc/review.md, doc/plan-mode.md

[VectorSettings]
# Staged review: the most open staged changes one thread may hold; the longest text of an edited part (characters)
MaxStagedChanges=500
MaxEditLength=20000
# Plan mode: the most items of one plan
MaxPlanItems=50
# The estimate of a plan: expected tool rounds per item and action (low = one less, high = HighExtraRounds more),
# the size of the instructions and tools when the thread has none yet (PlanFrameTokens), the history sent with each
# round, a new object's context, the output per field of a new object, characters per token, seconds per round.
# The result is calibrated with the actual cost of the last finished plans.
ExpectedRounds[create]=3
ExpectedRounds[update]=3
ExpectedRounds[translate]=2
HighExtraRounds=2
PlanFrameTokens=6000
PlanHistoryTokens=1500
PlanNewObjectTokens=300
OutputPerField=250
CharsPerToken=3.5
SecondsPerRound=8

[KeyBindings]
# The Changes tab: Shift+A / Shift+X decide the whole field of the current part, E edits it (plain text fields)
Binding[accept_field]=Shift+KeyA
When[accept_field]=list
Binding[reject_field]=Shift+KeyX
When[reject_field]=list
Binding[edit_hunk]=KeyE
When[edit_hunk]=list

# ---- Parallel runs, steering (doc/parallel-runs.md) -------------------------------------------------------------
[VectorSettings]
# enabled: each item of an approved plan is a child run of its own in the job queue (job expai.thread.item), side by
# side with the others; translations wait for the item of their source. disabled: the items run one after the other
# as turns of the thread (one job)
ParallelRuns=enabled
# Items of one thread that run at once (the queue resource expai.thread:<thread id>; the queue's [ResourceSettings]
# MaxConcurrent and the class interactive's MaxRunning are the ceilings)
MaxParallelRunsPerThread=4
# Workers started right after a job is queued (KickWorker): another one when a job is ready and every live worker is
# busy (a long turn of a slow model never holds up a short job), at most this many live workers in all (cron and
# systemd workers included)
MaxKickedWorkers=4
# A model is slow (its running turn shows its time per step and "Switch to <default model>") when its provider is a
# managed local server (Managed=ollama), it is marked slow, or the median latency of its last requests is at least this
SlowStepSeconds=20

[QueueSettings]
# The answers of a plan that runs on a batch lane are staged as changes of the item's run
Appliers[vector_plan_item]=expAiPlanItemApplier

[KeyBindings]
# Steering: Alt+Enter while a turn runs sends a note that reaches the model at its next step; queued follow-ups
# (sent when the turn ends) move to Mod+Shift+Enter (Mod+Enter queues as well while a turn runs)
Binding[steer]=Alt+Enter
When[steer]=composer
Binding[queue]=Mod+Shift+Enter
When[queue]=composer

# ---- Checkpoints (doc/checkpoints.md) ---------------------------------------------------------------------------
[VectorSettings]
# enabled: a checkpoint at the start of every turn of a Vector thread, before accepted changes are written and before
# an approved plan runs; the editor restores the thread to one of them
Checkpoints=enabled
# The largest checkpoint in bytes; over it the checkpoint keeps the conversation and the decisions, not the values
MaxCheckpointBytes=1048576
# The newest checkpoints kept per thread (older ones go; all go after [ThreadSettings] CheckpointKeepDays)
MaxCheckpointsPerThread=50

# ------------------------------------------------------------------ web and history tools (#369)
# doc/tools.md (web_fetch, web_search, project_history), doc/security.md. Each tool is offered only to users with
# the policy expai/web (limitation Tool) and only once it is configured below.

[ToolSettings]
Tools[]=web_fetch
Tools[]=web_search
Tools[]=project_history

[Tool_web_fetch]
Class=expAiToolWebFetch

[Tool_web_search]
Class=expAiToolWebSearch

[Tool_project_history]
Class=expAiToolProjectHistory

[WebSettings]
# The sites web_fetch may read: an exact host (docs.example.org) or *.domain for its subdomains (not the domain
# itself). Empty: web_fetch is not offered.
AllowedDomains[]
# Hosts that may resolve to private, loopback or link-local addresses (an intranet documentation server). Empty:
# only public addresses.
PrivateHosts[]
# Ports besides 80 and 443
AllowedPorts[]
# The most bytes read of a page (the rest is cut), seconds for the whole fetch, same-host redirects followed
MaxBytes=524288
Timeout=10
MaxRedirects=3
# The most characters of text a result returns to the model
MaxResultChars=12000
MaxFetchesPerTurn=5
# robots.txt (product token ExponentialIntelligence), cached RobotsCacheTTL seconds
RespectRobots=enabled
RobotsCacheTTL=3600
# enabled: drafts may still be written in the same turn after a web_fetch or web_search result (default: not, as
# after an external MCP tool; the editor asks again in the next message)
WritesAfterWebResults=disabled

[SearchSettings]
# The search service: [SearchProvider_<id>]. web_search is offered only when its ApiKey is set.
Provider=brave
MaxResults=8
MaxSearchesPerTurn=3
# enabled: the URL of a search result in the conversation may be fetched although its host is not in
# AllowedDomains[] (the SSRF guard still applies)
SearchResultsFetchable=disabled

[SearchProvider_brave]
Label=Brave Search
Class=expAiWebSearchBrave
Endpoint=https://api.search.brave.com/res/v1/web/search
# in settings/override or a [ProviderSettings] KeyLocations[] location that git does not track (X-Subscription-Token)
ApiKey=
# The query leaves the site: the data policy checks the panel's node against these
Region=us
Retention=unknown
Timeout=10
SafeSearch=moderate
# Optional: a country code (de) and a search language (en)
Country=
SearchLang=

[HistorySettings]
# The repositories project_history reads, each [HistoryRepository_<id>] (id: a-z, 0-9, _). Empty: not offered.
Repositories[]
# Local clones must lie under one of these directories (absolute, or relative to the installation root)
Roots[]
# Paths never named or read (fnmatch; dir/* covers the directory); commit titles and release note lines naming
# such a directory are left out
ExcludePaths[]=ai/*
# Leave out titles that name a *.md file in a clone's root that git does not track (private notes)
HideUntrackedRootMarkdown=enabled
# enabled: commits carry the author's name
ShowAuthors=disabled
GitBinary=/usr/bin/git
Timeout=10
MaxCommits=30
MaxCallsPerTurn=5
MaxReleaseNotesChars=1500
# The GitHub API (read without a token) and the cache of release lists
GitHubApi=https://api.github.com
ReleasesCacheTTL=3600

# Example (not active until listed in Repositories[]):
[HistoryRepository_example]
Label=Example extension
# A local clone (relative to the installation root or absolute), under Roots[]
Path=extension/example
# The public GitHub repository for release notes (owner/name)
GitHub=example/example
# The branch to read (default: the checked-out HEAD)
Branch=

# ---------------------------------------------------------------------------------------------------------------
# Diff and translation sync (classes/diff/, doc/diff.md, doc/translation-sync.md)

[DiffSettings]
# The segmenter of each datatype: Segmenters[<datatype>]=<class implementing expAiSegmenter>. Datatypes not listed
# use FallbackSegmenter (the value as one block).
Segmenters[]
Segmenters[ezstring]=expAiSegmenterString
Segmenters[ezemail]=expAiSegmenterString
Segmenters[ezisbn]=expAiSegmenterString
Segmenters[eztext]=expAiSegmenterText
Segmenters[ezxmltext]=expAiSegmenterRichText
Segmenters[ezrichtext]=expAiSegmenterRichText
Segmenters[ezkeyword]=expAiSegmenterKeyword
FallbackSegmenter=expAiSegmenterGeneric
# Two blocks left over between equal blocks are one modified block when this share of their words is the same
# (0..1), else one deleted and one inserted block
ModifiedSimilarity=0.5
# More abbreviations for the sentence splitter, per language family (eng, ger, ...), separated by ";", without the
# final dot, e.g. Abbreviations[ger]=Bhf;Kfz
Abbreviations[]

[TranslationSync]
# enabled: translations are checked after a publish in the source language (workflow event expaitranslationsync),
# with ./console ext:expai:translation-sync and in the Intelligence tab
Enabled=enabled
# The source language of objects (the first one an object has); empty: the object's main language
SourceLanguages[]
# Only these translations are kept in step; empty: every other translation of the object
TargetLanguages[]
# The datatypes whose changes are translated (others are copied as the kernel copies them)
Datatypes[]
Datatypes[]=ezstring
Datatypes[]=eztext
Datatypes[]=ezxmltext
Datatypes[]=ezkeyword
# A change up to this share of the source characters is a patch (only the changed blocks are sent)
PatchMaxChangedRatio=0.25
# From this share on, the translation is done again in full (between the two: a patch that waits for an editor)
FullMinChangedRatio=0.6
# A patch with more changed blocks than this waits for an editor
PatchMaxSegments=40
# Owner rule: a patch estimated at or below this cost (in [Pricing] Currency) is queued at once; a full translation
# and anything above waits for an editor (Approve in the Intelligence tab)
CostThreshold=0.02
# disabled: every patch waits for an editor
AutoQueuePatch=enabled
# A translation whose blocks do not match its base (other count or kinds of blocks) cannot be patched: full
FullIfStructureMismatch=enabled
# The model alias (e.g. anthropic.claude-haiku-5-5); empty: the site default model
Model=
# The lanes (auto: [LanePolicy] ClassOrder[translation_patch] / ClassOrder[translation_full])
PatchLane=auto
FullLane=auto
# The most output tokens of one request
MaxOutputTokens=8000
# enabled: the workflow event queues the check as a job (expai.translation.check); disabled or no queue: it runs
# during the publish
QueueCheck=enabled
# Extra instructions for the translator (glossary, tone), appended to the system prompt
Instructions=

[PanelSettings]
# the translations of the object: decision, change, estimate, Approve / Skip ([PanelSection_translations])
Sections[]=translations

[PanelSection_translations]
Template=expai/panel/translations.tpl

[QueueSettings]
# translation patches and full translations write their answer into a draft of the target language
Appliers[translation_sync]=expAiTranslationSyncApplier

# Translation services (doc/translation-providers.md): who translates for the translation sync, the field action
# Translate and the chain step translate. Without this group: llm, as before.
[TranslationSettings]
# The default service: llm (the model providers of expai: [TranslationSync] Model, Instructions and the lanes), none
# (no translation at all), or the id of a [TranslationProvider_<id>] group
Provider=llm
# Per language pair and purpose (patch, full, field), the most specific rule wins: <source>:<target>:<purpose>,
# <source>:<target>, *:<target>:<purpose>, *:<target>, <source>:*:<purpose>, <source>:*, *:*:<purpose>, then Provider.
# Example: full translations by DeepL, patches (which keep the wording of the existing translation) by the model:
#   ProviderFor[*:*:full]=deepl
ProviderFor[]
# When the chosen service is not configured, does not translate the pair, or the data policy keeps the content away
# from it: llm, the id of another service, or none (the translation is reported as not possible). A failure while a
# service translates (an outage, a used-up quota) never falls back to another service: it fails visibly.
Fallback=llm
# Terms that are never translated (names of products and brands), for every service
DoNotTranslate[]
# A glossary of a pair for the llm service: Glossary[ger-DE:eng-GB]=<file>, a UTF-8 file with one term, a tab and its
# translation per line, relative to the installation. DeepL uses a glossary of its own: GlossaryId[] below.
Glossary[]

# The model providers of expai (the default service)
[TranslationProvider_llm]
Class=expAiTranslationProviderLlm
Label=Exponential Intelligence model

# DeepL. Not used until Provider or ProviderFor[] names it and it has a key.
[TranslationProvider_deepl]
Class=expAiTranslationProviderDeepl
Label=DeepL
# The DeepL API Pro; the Free plan: https://api-free.deepl.com/v2/translate
Endpoint=https://api.deepl.com/v2/translate
# in settings/override or a [ProviderSettings] KeyLocations[] location that git does not track
ApiKey=
Region=eu
# unknown until you have read the terms of your DeepL plan: the Pro plans promise to delete the texts after the
# translation (then none, and the day in TermsCheckedAt); the Free plan may keep them. Zero retention rules of the
# data policy refuse a service whose retention is unknown.
Retention=unknown
TermsCheckedAt=
# The price of a million characters, in PriceCurrency (converted to [Pricing] Currency); empty: the cost is unknown
PricePerMChars=20
PriceCurrency=EUR
# default, more, less, prefer_more or prefer_less (more and less fail for a target language without formality)
Formality=default
# LanguageMap[<eZ locale>]=<DeepL code>, when the built-in rules do not fit (e.g. LanguageMap[esl-MX]=ES-419)
LanguageMap[]
# The base codes DeepL translates (empty: the built-in list of expAiTranslationLocale)
Languages[]
# A DeepL glossary per pair: GlossaryId[ger-DE:eng-GB]=<id of the glossary in your DeepL account>
GlossaryId[]
Timeout=30
MaxTextsPerRequest=50
MaxBytesPerRequest=120000
# Retries of a busy service (429) or a server error (5xx) within one job; a longer retry-after is left to the queue
MaxRetries=2
RetryAfterMax=10
# enabled: the text of the blocks next to a block goes along as context (not translated, not billed; one block per
# request then)
SendContext=enabled

# LibreTranslate, usually on this server (Region=local meets every rule of the data policy). Not used until Provider
# or ProviderFor[] names it.
[TranslationProvider_libretranslate]
Class=expAiTranslationProviderLibreTranslate
Label=LibreTranslate
Endpoint=http://127.0.0.1:5000/translate
# only when the server asks for one (then ApiKeyRequired=enabled)
ApiKey=
ApiKeyRequired=disabled
Region=local
Retention=none
TermsCheckedAt=
PricePerMChars=0
LanguageMap[]
Languages[]
Timeout=60
MaxTextsPerRequest=25
MaxBytesPerRequest=60000
MaxRetries=2
RetryAfterMax=10


# ---- Alt text for images (doc/alt-text.md, #370 M5 alt text) ----------------------------------------------------
# "Write alt text" next to every image field of the edit form (expai_alttext.js) and the report expai/alttext.
[EditViewSettings]
Sections[]=alttext
FieldActions[]=alt_text

[EditSection_alttext]
Template=expai/edit/alttext.tpl

# The field action of image fields. Its button is drawn by expai_alttext.js (the image fields are not in
# [ToolSettings] WritableDatatypes[]: only the alt text is written, and only into the form).
[FieldAction_alt_text]
Label=Write alt text
Handler=expAiAltTextFieldAction
Datatypes[]=ezimage
Input=none

[AltTextSettings]
# enabled: the button in the edit form, the report and its jobs
Enabled=enabled
# The model that looks at the images (an alias with Capabilities[]=vision). Empty: the user's model, then the site
# default, then any other vision model, the first one the user may use, that runs and that the data policy allows.
Model=
# The image alias that is sent (original: the stored file, scaled down here to the limits below)
ImageAlias=original
# The longest edge in pixels and the most bytes of the image that is sent; per provider [Provider_<id>]
# ImageMaxEdge and ImageMaxBytes (Anthropic: 1568 px is what the model sees, 5 MB per image)
MaxEdge=1568
MaxBytes=3750000
# JPEG quality of a scaled-down image (1-100)
JpegQuality=85
# Images may only go to these regions (us, eu, local), on top of the object's section and class rules of
# [DataPolicy]; empty: as the object
ImageRegions[]
# enabled: images only go to providers with zero retention (none or zdr), whatever the section says
ImageZeroRetention=disabled
# The longest alt text in characters (the suggestion is cut at a word)
MaxLength=150
# The most output tokens of one suggestion
MaxTokens=600
# The longest text of the page sent as context, in characters
MaxContextLength=4000
# Plain text fields (ezstring) of the same object that repeat the alt text (e.g. the alternative_text field of an
# image class): filled too when they are empty or equal to the image's alt text
CompanionAttributes[]
CompanionAttributes[]=alternative_text
CompanionAttributes[]=alt_text
# House rules added to the prompt (e.g. "Name our products by their full name.")
ExtraInstructions=
# The report: the most objects looked at under a node
ReportMaxObjects=500
# Queue suggestions: the most jobs per click, the default budget of a job group (in [Pricing] Currency; 0: none)
MaxQueuePerRequest=100
GroupBudget=0
# The estimate of one queued suggestion: the text tokens (prompt, page text) and the answer, besides the image
EstimateTextTokens=1200
EstimateOutputTokens=120

# ---- Editor presets and the Improve menu (doc/presets.md) ----------------------------------------------------------
[EditPresets]
# enabled: one-click presets next to the mapped fields of the edit form, and an "Improve" menu on every text field.
# Like every field action they only fill the form; nothing is stored until the editor stores the draft.
Enabled=true
# enabled: one line above the fields names the model, its region, the lane and the estimated cost of one click
DefaultsLine=enabled
# The estimate of one click: the tokens sent (the field, the other fields, the instructions) and written
EstimateInputTokens=2500
EstimateOutputTokens=300
# The presets, in this order; each is [FieldAction_preset_<name>] (Label, Instruction, Datatypes[])
Presets[]=title
Presets[]=summary
Presets[]=meta_description
Presets[]=keywords
# The entries of the Improve menu, in this order; each is [FieldAction_improve_<name>]
Improve[]=shorten
Improve[]=expand
Improve[]=clarify
Improve[]=grammar
Improve[]=simplify
Improve[]=formal
Improve[]=casual
# The datatypes that get the Improve menu (also needed in [ToolSettings] WritableDatatypes[])
ImproveDatatypes[]=ezstring
ImproveDatatypes[]=eztext
ImproveDatatypes[]=ezxmltext

[Presets]
# Which presets a class offers and where each writes: Class_<class identifier>[<preset>]=<target>[,<target>...]
# A target is an attribute identifier, or <attribute>.<part> for the parts of an xrowmetadata field (title,
# description, keywords). A preset shows its button next to every target field. Classes without a line get no
# presets (the Improve menu is still there). Alt text for images is a separate field action.
Class_article[title]=title
Class_article[summary]=intro
Class_blog_post[title]=title
Class_blog_post[meta_description]=metadata.description
Class_blog_post[keywords]=tags,metadata.keywords
Class_folder[title]=name
Class_folder[summary]=short_description
Class_folder[keywords]=tags
Class_ng_article[title]=title,metadata.title
Class_ng_article[summary]=teaser_intro,full_intro
Class_ng_article[meta_description]=metadata.description
Class_ng_article[keywords]=metadata.keywords
Class_ng_blog_post[title]=title,metadata.title
Class_ng_blog_post[summary]=teaser_intro,full_intro
Class_ng_blog_post[meta_description]=metadata.description
Class_ng_blog_post[keywords]=metadata.keywords
Class_ng_news[title]=title,metadata.title
Class_ng_news[summary]=teaser_intro,full_intro
Class_ng_news[meta_description]=metadata.description
Class_ng_news[keywords]=metadata.keywords
Class_ng_landing_page[title]=title,metadata.title
Class_ng_landing_page[summary]=teaser_intro,full_intro
Class_ng_landing_page[meta_description]=metadata.description
Class_ng_landing_page[keywords]=metadata.keywords
Class_ng_category[title]=title,metadata.title
Class_ng_category[summary]=teaser_intro,full_intro
Class_ng_category[meta_description]=metadata.description
Class_ng_category[keywords]=metadata.keywords

[FieldAction_preset_title]
Label=Suggest title
Handler=expAiPromptFieldAction
Datatypes[]=ezstring
Datatypes[]=xrowmetadata
Input=none
Instruction=Suggest a clear, specific title for this content based on its other fields: at most about 60 characters, no quotation marks, no final period, in the language of the content. For a page title (metadata) write what a search result should show.

[FieldAction_preset_summary]
Label=Write summary
Handler=expAiPromptFieldAction
Datatypes[]=ezstring
Datatypes[]=eztext
Datatypes[]=ezxmltext
Input=none
Instruction=Write a short summary of this content from its other fields (mainly the body text): two or three sentences that make a reader want to read on, in the language of the content. Do not invent facts.

[FieldAction_preset_meta_description]
Label=Meta description
Handler=expAiPromptFieldAction
Datatypes[]=ezstring
Datatypes[]=eztext
Datatypes[]=xrowmetadata
Input=none
Instruction=Write the meta description of this page for search results from its other fields: one or two sentences, at most 150 characters, plain text, in the language of the content. Do not invent facts.

[FieldAction_preset_keywords]
Label=Keywords
Handler=expAiPromptFieldAction
Datatypes[]=ezkeyword
Datatypes[]=ezstring
Datatypes[]=xrowmetadata
Input=none
Instruction=Suggest five to eight keywords for this content based on its other fields, comma separated, in the language of the content.

[FieldAction_improve_shorten]
Label=Shorten
Handler=expAiPromptFieldAction
Input=none
Instruction=Shorten the current value of this field to about half its length. Keep the key facts, the language and the formatting.

[FieldAction_improve_expand]
Label=Expand
Handler=expAiPromptFieldAction
Input=none
Instruction=Expand the current value of this field: add detail and explanation from the other fields of the object, about half again as long. Do not invent facts. Keep the language and the tone.

[FieldAction_improve_clarify]
Label=Clarify
Handler=expAiPromptFieldAction
Input=none
Instruction=Make the current value of this field clearer: plain words, one idea per sentence, the same meaning, the same language, about the same length.

[FieldAction_improve_grammar]
Label=Fix grammar
Handler=expAiPromptFieldAction
Input=none
Instruction=Correct spelling, grammar and punctuation of the current value of this field. Change nothing else: keep the wording, the style, the language and the formatting.

[FieldAction_improve_simplify]
Label=Simplify for new readers
Handler=expAiPromptFieldAction
Input=none
Instruction=Rewrite the current value of this field for readers who are new to the subject: short sentences, common words, explain or avoid jargon. Keep the meaning and the language.

[FieldAction_improve_formal]
Label=More formal
Handler=expAiPromptFieldAction
Input=none
Instruction=Rewrite the current value of this field in a more formal, professional tone. Keep the meaning, the language, the formatting and about the same length.

[FieldAction_improve_casual]
Label=More casual
Handler=expAiPromptFieldAction
Input=none
Instruction=Rewrite the current value of this field in a more casual, friendly tone. Keep the meaning, the language, the formatting and about the same length.

# ---- The "EI: Summary" page (doc/summary.md) ----------------------------------------------------------------------
[SummarySettings]
# expai/summary: one printable page for a data protection officer (policy expai/summary). Nothing is configured here
# but the free text below; every fact on the page is read from the settings and the usage log as they are.
# The contact for questions about the processing (a role or a mailbox, shown on the page; empty: not shown)
Contact=
# A note printed under the title (e.g. the reference of the record of processing activities)
Note=

# Test prompt and Load model of expai/models (doc/local-models.md "Test prompt and Load model"): both run as jobs of
# the job queue (expai.model.test), never in the page request; the page asks for the state every PollSeconds.
[ModelTest]
# Seconds between two questions of the page while a job waits or runs (2 to 30)
PollSeconds=3
# How long a finished test or load is shown on the page (minutes; 0: only while the page is open)
ShowResultMinutes=60
# How long Ollama keeps a model in memory after "Load model" (an Ollama duration: 30m, 2h, or seconds)
LoadKeepAlive=30m

# The web budget (doc/troubleshooting.md "504 Gateway Timeout"): what one model call from a web request may take.
# A web server ends a request after its own limit and the browser sees a 504; a model that cannot answer within
# Seconds is not called from a web request. The field actions and the fill of the edit form refuse it with a message;
# the test prompt and Vector turns run it as a job.
[WebBudget]
Seconds=25
# queue: slow models (Slow=true, a local server with Managed=ollama) never run in a web request; allow: they do,
# e.g. behind a web server with a long timeout. [Model_<alias>] RunInBackground=always|never decides for one model.
SlowModels=queue
# A model counts as slow by measurement when at least this many of its last 20 successful requests took longer than
# Seconds on average
MinSamples=5

# The "Models" card of expai/dashboard (doc/dashboard.md "Models"): the figures of a period and scope are kept for
# CacheSeconds in a small cache file (var/<site>/cache/expai/model-stats/), so the card costs a file read on most page
# views; 0 computes them on every view.
[ModelStats]
CacheSeconds=60

*/ ?>
