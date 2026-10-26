# Providers and models

expai speaks one internal format (the shape of the Anthropic Messages API: `tool_use` and `tool_result` blocks)
everywhere: the panel's tool loop, the stored conversations, the limits and the usage log. A provider is an adapter
that translates both ways. Two adapters are built in:

| Type | For | Class |
| --- | --- | --- |
| `anthropic` | the Anthropic Messages API (and Anthropic-compatible servers with `AnthropicExtras=disabled`) | `expAiAnthropicProvider` |
| `openai_chat` | OpenAI Chat Completions and every compatible server: Hugging Face Inference Providers (router), OpenRouter, Mistral, Melious, xAI, Ollama, llama.cpp `llama-server` (with `--jinja`), vLLM, LM Studio, Azure OpenAI | `expAiOpenAiChatProvider` |
| `custom` | your own class implementing `expAiProvider` ([extending.md](extending.md)) | `Class=` |

What is proven against which provider is in [provider-support.md](provider-support.md).

Translation is chosen separately: the translation sync, the field action Translate and the chain step translate use a
**translation service**, by default `llm` (the models of this page), or a machine translation service such as DeepL,
per language pair and purpose ([translation-providers.md](translation-providers.md)).

## Registry

```ini
# settings/override/expai.ini.append.php
[ProviderSettings]
Providers[]=openrouter
DefaultModel=anthropic.claude-sonnet-5-5

[Provider_openrouter]
ApiKey=sk-or-...
TermsCheckedAt=2026-10-11
```

`anthropic` is always built from `[AISettings]` unless you add a `[Provider_anthropic]` group: an installation of
0.0.1.0 keeps working without a change, its models become the aliases `anthropic.<id>`, and a stored model choice with a
bare id (`claude-opus-5-5`) is read as that alias. The extension ships example groups for Hugging Face, OpenRouter,
Mistral and a local Ollama (`[Provider_huggingface]` ...) with example models; they are inactive until listed in
`Providers[]`. Every setting is in [configuration.md](configuration.md).

Keys belong in `settings/override` only (mode 0640). expai/models shows "key present", never the key, and warns about
a key in any other file. Error texts and the log never contain the key; a key-like string in a provider's error
message is replaced by `[redacted]`.

## How the openai_chat adapter translates

| expai (canonical) | Chat Completions |
| --- | --- |
| `system` | the first message `{role: system}` |
| assistant `text` + `tool_use {id, name, input}` | `{role: assistant, content, tool_calls: [{id, type: function, function: {name, arguments: "<JSON>"}}]}` (thinking blocks of another provider are not sent) |
| user `tool_result {tool_use_id, content, is_error}` | one `{role: tool, tool_call_id, content}` each (`Error: ` in front of an error; Mistral: with `name`), then the user's text |
| `tools [{name, description, input_schema}]` | `[{type: function, function: {name, description, parameters}}]` |
| `tool_choice none / any / auto / tool` | `"none"` / `"required"` (Mistral `"any"`) / `"auto"` / `{type: function, function: {name}}`; a server without tool_choice (Ollama) gets no tools on a call that must answer in words |
| `output_schema` | `response_format: {type: json_schema, ...}` (`Capabilities[]=json_schema`) or `{type: json_object}` (`json_object`); otherwise the instructions ask for JSON and the answer is read tolerantly (code fences, text around the object) |
| image blocks (base64, alt text) | user content parts `{type: image_url, image_url: {url: "data:<type>;base64,..."}}` for models with `Capabilities[]=vision` ([alt-text.md](alt-text.md)) |
| `Effort` | `reasoning_effort` (OpenRouter: `reasoning: {effort}`), with `Capabilities[]=reasoning_effort`; `none` switches thinking off |

The answer: `choices[0].message` becomes text and `tool_use` blocks. Arguments are decoded; arguments that are not a
JSON object are answered with an error asking for valid JSON (the call is never run). A call without an id gets one
(Mistral: 9 letters and digits). `finish_reason` `tool_calls` → `tool_use`, `length` → `max_tokens`, `stop` →
`end_turn` (or `tool_use` when calls came with it, as Ollama does), `content_filter` → `refusal`, `error` → an error.
An error object inside an HTTP 200 answer (OpenRouter) is an error. Reasoning markup is removed: a leading
`<think>...</think>` block, the text before a lone `</think>` and a line break, everything but the final channel of
the Harmony format; a text that only mentions such a tag keeps it.

Usage: `prompt_tokens` minus `prompt_tokens_details.cached_tokens` and `cache_write_tokens` is the input (as Anthropic
counts it), the cached tokens are cache reads, `completion_tokens` the output (it includes reasoning),
`completion_tokens_details.reasoning_tokens` is stored as reasoning tokens, and `usage.cost` (OpenRouter) as the
reported cost.

The message as the provider sent it is kept as `native_json` next to the canonical `content_json`, and sent back
unchanged only to the same provider and model (some providers need their reasoning fields back); arguments that were
not JSON are sent back as `{}`.

## A conversation belongs to its model

A panel conversation is bound to the model alias and provider it runs on (`expai_conversation.model_alias`,
`provider`). When the editor switches model, the conversation goes on from a fresh window: the earlier exchanges as
plain words ([full-view-panel.md](full-view-panel.md), the context window), no thinking blocks or provider state, and
instructions and tools for the new model. The history the panel shows is unchanged.

## Choosing a model

The picker (dashboard, panel, edit form) groups the models by provider with the region, the relative cost (`free`,
`$`, `$$`, `$$$` by the output price), "slow" for a local model, "prompted tools" / "no tools", and "read only" for a
model that may not write. It offers the site default, the models of `[ProviderSettings] ProvidersWithoutPolicy[]`
(default `anthropic`), and the models of the providers the policy `expai/model_use` allows (limitation Provider).
A model posted that is not on that list is refused ([permissions.md](permissions.md)).

A model whose provider cannot be reached right now is shown with its state and cannot be chosen (the site default
always can): "stopped — start it under Models" for a local server (`Managed=ollama`) that does not run, with a link to
expai/models for users with `expai/model_manage` ("stopped" for everyone else), and "unreachable" for a provider on
the loopback address that refuses connections or a provider whose last request failed at the connection. The state is
read the way expai/models reads it (Ollama's `/api/version` on the loopback address; never by running a command) and
kept `[AISettings] HealthCacheSeconds` (default 30) per process, so the picker adds no noticeable time. The panel's
select is part of the cached full view: expai.js marks it from `expai::load`; Vector's select from `expai::states`.

## When a model cannot answer

With `[AISettings] FallbackToDefaultModel=enabled` (the default), a request whose model (the editor's choice, a
thread's model, the model a conversation is bound to) cannot answer goes to the site default model instead:

| Reason | When |
| --- | --- |
| stopped | a local server (`Managed=ollama`) does not run: seen before the request, or its connection is refused |
| unreachable | connection refused, host not resolved, TLS failure (curl errors), or the provider was marked unreachable |
| timeout | not a single byte came back within the provider's `Timeout` |
| not allowed | the user may not use the model any more (`expai/model_use`, `AllowUserModelChoice`, `AllowedModels[]`) |
| removed | the model is not in the registry any more |
| data policy | the data policy keeps the content away from the model's provider |

When the default may not take the content either (the user's permissions, the data policy), the first of the user's
allowed models that may answers; when none can, the editor gets a message naming the model and why ("Qwen3.5 4B
(local) is stopped, and no other model you may use can answer for this content."). A request that asked for the site
default itself never moves to another provider. Failures that would repeat on any model (the default's own provider
refusing access, a request or answer that is too long, a refusal, any HTTP error of a reachable provider) are shown as
before.

The conversation goes on with the default as after a change of model (the earlier exchanges compacted into a fresh
window, see above). The panel, the field actions, "Fill the fields", Vector and a request started from the dashboard
("Start writing") show one line: "Claude Sonnet 5.5 answered instead: Qwen3.5 4B (local) is stopped." The exchange keeps
it in the history. The usage log row of the answer names the model asked for (`requested_model_alias`) and the reason
(`fallback_reason`); a request that failed at the connection keeps its own row with the error
([usage-tracking.md](usage-tracking.md)). The choice of model stays as it was: once the local server runs again, the
next request goes to it.

## Tool modes and the write gate

| `ToolUse` | The panel | Drafts |
| --- | --- | --- |
| `native` | the API's tool calling | with `WriteTools=enabled` and a passed probe (or `WriteGate=trusted`) |
| `prompted` | the tools are described in the instructions; the model answers with exactly one JSON object `{"tool", "input"}` or `{"answer"}`, which is checked against the tool's schema; one retry with the error (it counts against `MaxRequestsPerTurn`), then the text is the answer | never |
| `none` | no tools; the instructions carry a read-only snapshot of the node | never |

The gate is enforced twice: the write tools are not in the definitions sent to the model, and a write tool the
model names anyway is refused when it is run. Field actions only fill the edit form and are offered with every model.
See [probe.md](probe.md).

## Errors, retries, rate limits

401/403 "refused the request (access)", 402 "no credit left", 404 "does not know the configured model", 408/504 "did
not answer in time", 413 "too long", 429/503/529 "busy or over its limit": each names the provider's `Label`; the log
row has the status, the provider's error type and message. Up to two retries within one deadline (the provider's `Timeout` over all attempts), waiting 1 s, then 2 s (with a
little jitter), or the `retry-after` (seconds or an HTTP date) when it is at most `RetryAfterMax` seconds: after a
408/425/429 (also without `retry-after`), a 500/502/503/504/529, or a connection that was never made (refused, name
not found). Never after a timeout or an answer that broke off, nor after a 408/502/504 of an attempt that took longer
than 30 seconds: the provider may already bill the request. A 400 that refuses an optional parameter as unknown or
unsupported (`reasoning_effort`, `reasoning`, `stream_options`) is sent once more without it; an invalid value of it
fails as it is. The response
headers of `RateLimitHeaders[]` are stored with the row.

## Examples

Hugging Face Inference Providers: `BaseURL=https://router.huggingface.co/v1`, a fine-grained token with "Make calls to
Inference Providers", model ids such as `openai/gpt-oss-120b:fastest` (`:cheapest`, `:preferred`, `:<provider>`).
Pin one inference provider (`:<provider>`) when you need a known data processor; the router does not report a cost,
so give the model prices or the log shows "cost unknown".

OpenRouter: `BaseURL=https://openrouter.ai/api/v1`, `Headers[]=X-OpenRouter-Title: ...`, and
`ExtraBody={"provider":{"data_collection":"deny","zdr":true}}` to keep requests away from endpoints that store or
train on prompts. Every answer reports its cost, which the log shows as "reported".

Mistral (EU): `Flavor=mistral`, `BaseURL=https://api.mistral.ai/v1`, `Region=eu`.

Melious (EU, open-weight models): `Flavor=melious`, `BaseURL=https://api.melious.ai/v1`, `Region=eu`, `Retention=zdr`,
`PriceCurrency=EUR`; see [Melious](#melious) below.

A local Ollama: `Flavor=ollama`, `BaseURL=http://127.0.0.1:11434/v1`, `ApiKeyHeader=none`, `Region=local`,
`Retention=none`, a longer `Timeout`; see [local-models.md](local-models.md).

## Melious

[Melious](https://melious.ai/docs/reference) offers open-weight models (GLM, DeepSeek, Qwen, Kimi, Mistral ...) through
an OpenAI-compatible API, run by providers inside the EU. expai speaks to it with the `openai_chat` adapter and
`Flavor=melious`. The shipped `[Provider_melious]` and the four models below are inactive until `melious` is listed in
`[ProviderSettings] Providers[]` and the key is in settings/override:

```ini
# settings/override/expai.ini.append.php (mode 0640, never committed)
[ProviderSettings]
Providers[]=melious

[Provider_melious]
ApiKey=sk-mel-...
```

What Melious states and what expai makes of it (read 2026-10-12):

| Topic | Melious | In expai |
| --- | --- | --- |
| API | `POST https://api.melious.ai/v1/chat/completions`, `Authorization: Bearer <key>` (or `x-api-key`); `GET /v1/models?include_meta=true` lists every model with its capabilities, context length and prices | `BaseURL=https://api.melious.ai/v1`, the default Bearer header |
| Region | "Every inference request runs on a provider inside the EU"; a request may be retried on the next-best EU provider (the answer names it in `environment_impact.provider_id`, `location`) | `Region=eu` |
| Retention and training | No prompt or answer content is kept, only operational metadata (request id, tokens, model, provider, energy, cost); no provider on the network trains on the traffic. Providers may keep short-term operational logs. This is the documentation, not a data processing agreement | `Retention=zdr`, `TermsCheckedAt=2026-10-12`; ask Melious for a DPA before personal data goes there |
| Prices | per model, in EUR per million tokens (input, output, cache read) | `[Model_melious.*]` prices in EUR with `PriceCurrency=EUR`, converted with the ECB reference rate of the day (`[Pricing] ExchangeRateSource=ecb`; `ExchangeRates[EUR]` is the fallback), shown with its date on expai/models |
| Cost per request | every answer carries `billing_cost` (`credits`: the EUR amount, also when the plan pays with "energy" units) | stored as the reported cost (in the site's currency), shown as "reported" |
| Tool calling | OpenAI `tools` / `tool_choice` (`auto`, `none`, `required`, a function); answers with `tool_calls` and DeepSeek's `reasoning_content` | native tool use; the answer is sent back as it came (null fields and LiteLLM's `provider_specific_fields` left out) |
| Structured output | `response_format` `json_object` or `json_schema` (only some models list `json_schema`) | `Capabilities[]=json_schema` for DeepSeek V4 Flash 0731, `json_object` for the others |
| Reasoning | `reasoning_effort` low / medium / high; usage reports `reasoning_tokens` | `Effort=low`, `Capabilities[]=reasoning_effort`; reasoning tokens stored per request |
| Prompt caching | automatic; usage reports `prompt_tokens_details.cached_tokens` | counted as cache reads, priced at the cache read price |
| Streaming | SSE (`stream: true`, `stream_options.include_usage` for the usage in the last chunk) | `Streaming=enabled`: the test prompt of expai/models is streamed to measure the time to the first token; every other request asks for the whole answer (Vector streams its own steps to the browser) |
| Rate limits | per plan; headers `x-ratelimit-limit`, `x-ratelimit-remaining`, `x-ratelimit-reset`; 429 with `Retry-After` (code `AUTH_1028`) | headers kept with the usage row; one retry after a short `Retry-After` |
| Errors | `{"error": {"message", "type", "code"}}` (e.g. `model_not_found` 404, `invalid_api_key` 401, `BILLING_2001` out of credit, `INFERENCE_3207` context too long) | mapped to the editor's texts by status; the code is in the log row |

The models of the partner demo (all with native tool calling and a context of one million tokens):

| Alias | Prices EUR in / out / cache read per M | Notes |
| --- | --- | --- |
| `melious.glm-5.3-flash` | 0.10 / 0.40 / 0.02 | always reasons (short reasoning at `Effort=low`) |
| `melious.glm-5.3` | 1.00 / 3.00 / 0.20 | hybrid |
| `melious.deepseek-v4-flash-0731` | 0.10 / 0.25 / 0.02 | hybrid, `json_schema`; the fastest in the demo |
| `melious.deepseek-v4.1-flash` | 0.20 / 1.00 / 0.01 | hybrid; text and images in |

Each may write drafts only after a live probe of at least 90 % ([probe.md](probe.md)). On 2026-10-12 all four passed
(GLM 5.3 Flash, GLM 5.3 and DeepSeek V4 Flash 0731 93 %, DeepSeek V4.1 Flash 100 %): they read the class or the object
before they write, which the probe follows (`follow_ok`). Field actions, presets, chain steps and the translation sync
work with any model, as they write through their own code. The demo and what was measured are in
[demo-melious.md](demo-melious.md).

The Melious command line tool (`melious run "..." --model glm-5.3`, `melious launch`) is meant for your own machine;
it is not needed on the server and is not installed there.
