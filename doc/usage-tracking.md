# Usage tracking, costs and limits

expai records every request it sends to the model, shows the usage on the dashboard and in the log, estimates its
cost and can stop requests when a limit is reached.

## What is recorded

One row in `expai_usage_log` per request (columns in [database.md](database.md)):

- who and where: the user object id (no names, no e-mail addresses), the node, the panel conversation and turn, the
  action (`panel`, `fill`, `field:<action>`), a hash of the login session;
- what: the provider, the model that answered, the tokens the API reported (input, output, cache write, cache read),
  the stop reason;
- how it went: HTTP status, retries, time of the call, the tools the answer asked for (names, outcome and time; never
  their input), drafts written, the error of a failed request;
- the rate limit headers of the response (`anthropic-ratelimit-*`, `retry-after`);
- for a machine translation service ([translation-providers.md](translation-providers.md)): `provider` = the
  service's id, `model` = its engine, `model_alias` = `translation.<id>`, no tokens, the characters it billed
  (`billed_chars`) and the cost in `reported_cost_micros`.

The texts of requests and answers are **not** stored. Only with `[UsageSettings] LogRequests=enabled` is the full
request kept with each row, for debugging; it contains content and the editors' words, so leave it disabled in
production. The request summary of the draft log (`[LogSettings] MaxPromptSummaryLength`) is separate.

Failed requests are recorded too, with their HTTP status and error, also a request that fails in an unexpected way (a
PHP error: its class and message). A request stopped by a limit of `[Limits]` is not sent and not recorded: the
editor sees the limit and when it resets. A refusal (stop reason `refusal`) records the category the provider named
(`stop_details`, e.g. "refusal, category cyber"), never its explanation.

While a limit is set, a request's row is written before it is sent, with its estimate in `reserved_tokens` and
`reserved_micros`, and settled with the real numbers after the answer; until then the limits count it as reserved
(never the request's own reservation in its own check), and every other reader (the log, turns, statistics,
latencies, provider health, threads) leaves it out. A reservation older than 15 minutes (a process that ended
without settling it) is removed on the next reservation and by the usage pruning.

### Turns

A turn is one panel message, one field action or one "Fill the fields". One turn can lead to several requests (each
tool round is one; at most `[Limits] MaxRequestsPerTurn` or `[AssistantSettings] MaxToolIterations`). The log shows
turns with their totals; opening a turn shows its requests.

### Providers

The provider normalizes the usage it reports into the four token counts (an OpenAI-style provider's cached tokens are
taken out of the input and counted as cache reads, as Anthropic reports them), plus reasoning tokens and a cost it
reported. The row stores the provider id, the model alias, the provider's region and retention, and the tool mode, so
every provider's rows are counted the same way ([providers.md](providers.md), extending.md).

When another model answered because the one asked for could not ([providers.md](providers.md), "When a model cannot
answer"), the row of the answer has `requested_model_alias` (the model asked for) and `fallback_reason` (`stopped`,
`unreachable`, `timeout`, `not_allowed`, `removed` or `data_policy`); `model_alias` is the model that answered. Both are
empty when the requested model answered. A request that failed at the connection first keeps its own row with the
error.

The cost of a row is, in this order: the cost the provider reported (OpenRouter, shown "reported"), the prices of its
model alias (`[Model_<alias>] InputPerMTok` ...; a local model is free), the `[Pricing]` table by model id; a model of
another provider without prices has an unknown cost rather than a Claude price. The limits use the same cost.
`[Limits] ProviderMaxTokensPerMonth[<provider>]` and `ProviderMaxCostPerMonth[<provider>]` cap one provider.

## Where to see it

- **Dashboard** (`expai/dashboard`): the figures, the chart, the limits of the chosen period; the user's own usage,
  or with `expai/log` the whole site and the top users. See [dashboard.md](dashboard.md).
- **Log** (`expai/log`): every turn and request, filtered by date, user, node, action, model and errors, sortable,
  with totals per day and per user, and a CSV export (no request texts, user ids only; a value that starts with `=`, `+`, `-`, `@`, a tab or a carriage
  return gets a leading `'`, so that a spreadsheet shows it as text instead of running it as a formula). `(model)/<alias>` filters by
  the model alias, `(request)/<id>` shows one request, `(thread)/<id>` the requests of one thread. The edit form
  links `(request)/<id>` as **View record** after each field action, preset and Improve entry.
- **Models card** of the dashboard: per model the requests, success rate, errors by kind (timeout, unreachable,
  HTTP error, refused, limit, budget), how often the fallback replaced it, median and 95th percentile latency,
  tokens per second, time per turn, cost, latency per day, the newest test prompt and probe; each model links to its
  requests and its errors in the log. See [dashboard.md](dashboard.md) "Models".

## The estimated cost

The cost is computed when it is shown, from the tokens and the `[Pricing]` table (configuration.md):

```
cost = ( input × Input + output × Output + cache write × CacheWrite + cache read × CacheRead ) / 1 000 000 × Multiplier
```

with the prices per million tokens of the model's row. A model is matched by its id, then without a provider prefix,
date or `-latest` suffix, then by family and version; a model without a row uses the `Default*` prices and its cost
is shown as approximate (`≈`). Claude Haiku 5.5 has a second tier: a request whose prompt (input + cache write + cache
read) is over `LongContextThreshold` uses the `*Long` prices.

A machine translation service is priced per character, not per token: its row stores the cost when it is made,
billed characters x `[TranslationProvider_<id>] PricePerMChars` (in `PriceCurrency`, converted to `[Pricing]
Currency`), and that stored cost is shown (source "reported"). A local service with `PricePerMChars=0` costs nothing.
Only the cost limits of `[Limits]` apply to it; the token limits do not count characters.

Because nothing is stored as money, correcting the price table corrects every past model figure too. The estimate is not
an invoice: Anthropic's invoice is authoritative. `[Pricing] Enabled=false` hides costs everywhere.

Check the matcher against the shipped table:

```
php bin/php/ezexec.php extension/expai/tests/expaicost_test.php
```

### Checking the numbers

- A row's tokens are the `usage` object of the API response, unchanged: compare a request in the log with the
  Usage page of the Claude Console for the same minute and model.
- Compute one row by hand with the formula above. Example, Claude Sonnet 5.5 (2 / 2.50 / 0.20 / 10 per million): 4
  input, 136 output, 6 324 cache write tokens = (4 × 2 + 136 × 10 + 6 324 × 2.50) / 1 000 000 = $0.017178.
- The dashboard's total for a period equals the sum of the log's "Estimated cost" for the same dates.

## Limits

`[Limits]` (configuration.md) sets optional limits per conversation, per login session, per user and day or month,
and for the whole site per month, in tokens or in estimated cost, plus the most requests one turn may make. Each is
checked before a request is sent; when one is reached the editor sees which limit and when it resets, and the request
is not sent. Each request reserves its estimate first, and the check counts what the requests under way reserved:
parallel requests cannot pass a limit together. A model without a price is refused while a cost limit applies
(`UnpricedCost=block`, the default; `ignore` counts it as free); a model of a provider with `Region=local` is always free. The dashboard shows a bar for every limit that is set.

Anthropic's own limits apply in addition: the rate limits of the key (the dashboard shows what the last response
reported; a rate-limited request is retried (up to twice) when its `retry-after` is short (at most `RetryAfterMax` seconds, [providers.md](providers.md)), otherwise it fails with "busy or over its limit, try again later" and is recorded with its `retry-after`) and the organization's
monthly spend limit, which an API key cannot read: see it in the Claude Console.

## Retention

The cronjob part `expai` removes rows older than `[UsageSettings] RetentionDays` (365; `0` keeps everything):

```
php runcronjobs.php -s <admin siteaccess> expai
```

Add it to the server's crontab next to the other cronjob parts, e.g. once a night.

## Privacy

- User ids only, never names or e-mail addresses, in the table, the CSV and the dashboard's top users (the name shown
  there is read from the user object when the page is rendered, for those who may see it).
- No request or answer texts unless `LogRequests` is enabled.
- The session is stored as a hash, not as the session id.
- Users with `expai/write` see only their own rows; everyone's needs `expai/log`.
