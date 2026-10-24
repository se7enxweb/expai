# Translation services: who translates

The translation sync ([translation-sync.md](translation-sync.md)), the field action **Translate** of the edit form
([edit-view-integration.md](edit-view-integration.md)) and the chain step `translate` ([chains.md](chains.md)) hand
their texts to a **translation service**. Without any setting this is `llm`, the model providers of expai, exactly as
before translation services existed. A machine translation service such as DeepL or LibreTranslate can take some or
all of the work, per language pair and purpose.

"Provider" in expai means the model backends ([providers.md](providers.md)). A translation service is chosen
separately, in `[TranslationSettings]`, and configured in a `[TranslationProvider_<id>]` group of `expai.ini`.

## Shipped services

| Id | Class | What | Region, retention | Cost |
| --- | --- | --- | --- | --- |
| `llm` | `expAiTranslationProviderLlm` | the model of `[TranslationSync] Model` (else the site default), its lanes and instructions; queued as `expai.request` jobs | those of the model's provider | the model's token prices |
| `deepl` | `expAiTranslationProviderDeepl` | the DeepL API (Pro, or the Free plan with its own endpoint) | `eu`, `unknown` until you have checked your plan | `PricePerMChars` (20 EUR per million characters as shipped), from DeepL's billed characters |
| `libretranslate` | `expAiTranslationProviderLibreTranslate` | a LibreTranslate server, usually on this server | `local`, `none` | `PricePerMChars=0` |

`deepl` and `libretranslate` are not used until a rule names them, and `deepl` needs a key.

## Choosing the service

```ini
[TranslationSettings]
Provider=llm
ProviderFor[]
ProviderFor[*:*:full]=deepl
Fallback=llm
```

- `Provider` is the default: `llm`, `none` (no translation at all) or the id of a `[TranslationProvider_<id>]` group.
- `ProviderFor[<source>:<target>:<purpose>]` chooses per language pair (eZ locales such as `ger-DE`) and purpose:
  `patch` (a patch of the translation sync), `full` (a full translation) and `field` (the field action). The most
  specific rule wins: `<source>:<target>:<purpose>`, `<source>:<target>`, `*:<target>:<purpose>`, `*:<target>`,
  `<source>:*:<purpose>`, `<source>:*`, `*:*:<purpose>`, then `Provider`.
- `Fallback` is used when the chosen service is **not configured** (no key, an invalid endpoint, a class that does not
  exist), **does not translate the pair** (a language the service does not know, such as `tlh-QO` for DeepL) or the
  **data policy** keeps the content away from it. `none` reports the translation as not possible instead.
- A failure **while** a service translates (an outage, a used-up quota, a refused key) never falls back to another
  service: the job fails visibly with the reason. A silent fallback would change the cost and the place the content
  goes to without anyone deciding it.

The example above is the usual one: full translations by DeepL (cheap, fast), patches by the model. A patch keeps
the wording of the existing translation, which a model does well ("change the translation only where the source
changed"); a machine translation service translates the changed blocks afresh, so their wording can differ from
the blocks around them. Every combination is possible, MT for everything included.

Without a `[TranslationSettings]` group (an installation whose settings predate it) the service is `llm`.

### No service

With `Provider=none`, or when neither the chosen service nor the fallback can take a translation, the translation
sync still decides, but stores the state `no_provider` (or `blocked` when the data policy refused) with the reason,
for example "No translation service is installed for ger-DE -> eng-GB (deepl: no API key is set ...)". Nothing is
queued.

- The Translations section shows "No translation service" or "Blocked by the data policy" with the reason, and no
  Approve or Translate in full. Approve over ezjscore or the command line refuses with the same text.
- The edit form leaves **Translate** out when no service can translate fields at all
  (`fetch( 'expai', 'unavailable_field_actions' )`, `expAiFieldActionRegistry::describe()`); for a single pair the
  field action answers with the reason.
- The chain step `translate` is **skipped** with the reason when no language could be handed over.
- `./console ext:expai:translation-sync` prints the reason and exits with 2.
- EI: Summary lists the default, the fallback, every rule and every service with its region, retention, the file its
  key comes from and why it cannot be used ([summary.md](summary.md)).

## DeepL

```ini
# settings/override/expai.ini.append.php
[TranslationSettings]
ProviderFor[*:*:full]=deepl

[TranslationProvider_deepl]
ApiKey=<your DeepL key>
Retention=none
TermsCheckedAt=2026-10-24
```

| Setting | Default | Effect |
| --- | --- | --- |
| `Endpoint` | `https://api.deepl.com/v2/translate` | the Free plan: `https://api-free.deepl.com/v2/translate` |
| `ApiKey` | (empty) | only in `settings/override` or an untracked `[ProviderSettings] KeyLocations[]` location; sent as `Authorization: DeepL-Auth-Key <key>` |
| `Region`, `Retention`, `TermsCheckedAt` | `eu`, `unknown`, (empty) | for the data policy; set `Retention=none` after reading the terms of your plan (the Pro plans promise to delete the texts after the translation; the Free plan may keep them) |
| `PricePerMChars`, `PriceCurrency` | `20`, `EUR` | the price of a million billed characters, converted to `[Pricing] Currency`; empty: the cost is unknown |
| `Formality` | `default` | `more`, `less`, `prefer_more`, `prefer_less` (`more`/`less` fail for a target language without formality) |
| `LanguageMap[<eZ locale>]` | | a DeepL code when the rules below do not fit (`LanguageMap[esl-MX]=ES-419`) |
| `Languages[]` | (built-in list) | the base codes DeepL translates |
| `GlossaryId[<source>:<target>]` | | the id of a glossary in your DeepL account |
| `Timeout`, `MaxTextsPerRequest`, `MaxBytesPerRequest` | `30`, `50`, `120000` | one request carries at most this many texts and bytes |
| `MaxRetries`, `RetryAfterMax` | `2`, `10` | retries of a 429 or 5xx within one job, after the `retry-after` (at most this many seconds); a longer wait is left to the queue |
| `SendContext` | `enabled` | the text of the blocks next to a block goes along as DeepL's `context` (not translated, not billed); such a block is sent alone |

What is sent: the texts (`text[]`), `source_lang`, `target_lang`, `tag_handling=xml` with `non_splitting_tags=x`,
`ignore_tags=k` when terms are never translated, `formality`, `glossary_id`, `context`, `show_billed_characters`.
Errors: 403 (the key), 456 (the quota is used up) and 400 are permanent; 429 and 5xx are retried and then handed back
to the queue, which tries again with backoff.

## LibreTranslate

```ini
[TranslationSettings]
Provider=libretranslate

[TranslationProvider_libretranslate]
Endpoint=http://127.0.0.1:5000/translate
```

A server on this machine (`Region=local`) meets every rule of the data policy, so it is the answer for content with
`ClassRegions[...]=local` or zero retention rules. Plain `http` is accepted for `Region=local` only. `ApiKey` (with
`ApiKeyRequired=enabled`) when the server asks for one. The language codes are the lower-case base codes (`de`, `en`;
`zt` for traditional Chinese); `Languages[]` limits them to the models installed on the server.

LibreTranslate's HTML handling moves or drops tags more often than DeepL. A block whose placeholders come back wrong
is refused (the job fails with the block), never written half right; route rich text to another service with
`ProviderFor[]` where that happens often (capability `markup_reliable` is false).

## Language codes

`expAiTranslationLocale::forProvider()` resolves an eZ locale in this order:

1. `LanguageMap[<eZ locale>]` (also `LanguageMap[<eZ locale>:source]` and `:target` when the roles differ);
2. the HTTP locale of the locale file (`ger-DE`: `de-DE`);
3. the rules of the service: DeepL takes the base language as source (`DE`, `EN`) and needs a variant for some
   targets (`EN-GB` for British, Irish, Australian, ... English, else `EN-US`; `PT-BR` or `PT-PT`; `ZH-HANS` or
   `ZH-HANT`); LibreTranslate the lower-case base language; another service the HTTP locale.

A locale the service does not know (no locale file, or a language outside `Languages[]`, such as `tlh-QO`, `kal-GL`,
`lat-VA` or `pus-AF` for DeepL) is not supported: the rules fall back. The list of a service is static (no extra
request to the service to ask for its languages).

## Markup, terms and glossaries

A block of the translation sync is coded: XML-escaped text with numbered placeholders (`<1>...</1>` wraps a link or
emphasis, `<2/>` is an embedded object or a line break). A machine translation service gets them as its own markup
(`expAiTranslationMarkup`): `<x i="1">...</x>` and `<x i="2"/>` for DeepL, `<span data-x="1">...</span>` and
`<br data-x="2">` for LibreTranslate. The answer is turned back and checked before it is returned: every placeholder of
the source exactly once, in the same form, properly nested, nothing new. A block that fails is a unit error; the job
then fails, and no draft is written from a partial answer. The applier checks the placeholders once more when it
composes the draft.

| Setting | Effect |
| --- | --- |
| `[TranslationSettings] DoNotTranslate[]` | terms never translated (names of products and brands), matched as whole words and never inside an entity such as `&amp;`: DeepL gets them in `<k>` (`ignore_tags`), in the editor's HTML of the field action as `<span translate="no" class="notranslate">` (honoured by DeepL's HTML mode; LibreTranslate keeps the span on a best-effort basis), LibreTranslate in `<span translate="no">`, the model a line of its prompt. The wrappers are removed from the answer |
| `[TranslationSettings] Glossary[<source>:<target>]=<file>` | for the model: a UTF-8 file with one term, a tab and its translation per line (relative to the installation) |
| `[TranslationProvider_deepl] GlossaryId[<source>:<target>]` | a glossary that exists in your DeepL account (expai does not upload or sync glossaries) |

Without terms and glossaries the model's request is byte for byte the request of earlier versions.

## What goes where

A machine translation service gets the text of the blocks to translate and, with `SendContext`, the text of the
blocks next to them. Never the other fields, the name of the object, the current translation or the old source: a
patch by a machine translation service translates the new text of the changed blocks, and the unchanged blocks keep
their translation byte for byte. The field action sends the field's value only: the editor's HTML for rich text, plain
text line by line, keywords one by one.

The data policy ([data-policy.md](data-policy.md)) is checked with the service's `Region` and `Retention` when the
service is chosen, again in the job before anything is sent, and in the field action before its request.

## Cost, usage, budgets

Every request of a machine translation service leaves one row in `expai_usage_log` (`expAiTranslationRecorder`):
`provider` = the service's id, `model` = its engine (`deepl`), `model_alias` = `translation.<id>`, no tokens,
`reported_cost_micros` = billed characters x `PricePerMChars` (converted to `[Pricing] Currency`), `billed_chars` =
the characters the service billed (DeepL's `billed_characters`, else the characters sent), region, retention, latency,
HTTP status, retries and the job's uuid. The row writes the audit event `system.expai.request` (or `.failed`) like a
model request ([audit-trail.md](audit-trail.md)).

Before the request the master switch and the veto apply (`expAiGate`, with the service's id as provider, so
`[RequestVeto] Providers[]=deepl` refuses it), and the **cost** limits of `[Limits]` (the token limits do not count
characters). A queued translation reserves its estimate in the budgets of queued requests, is held when it does not
fit and settles its real cost ([budgets.md](budgets.md)). `CostThreshold` of the translation sync gets the estimate of
the service that would translate the patch: billed characters x price.

`billed_chars` is a column added by `php extension/expai/bin/php/expaischema.php --install -s <siteaccess>`. An
installation not upgraded yet still records the cost (`reported_cost_micros`), without the characters, and writes one
warning per process.

## The job expai.translation.run

A machine translation of the translation sync is the job `expai.translation.run` (`expAiTranslationRunHandler`,
`settings/expqueue.ini.append.php`): class `translation_patch` or `translation_full`, resource
`translation:<id>` (`[QueueResource_translation:deepl] MaxConcurrent=4`), the estimate reserved, the idempotency key of
the sync with `:<id>` added. It holds while expai is switched off and while a budget does not allow it, checks the
service and the data policy again, plans again exactly as the applier does (a patch over a translation edited
meanwhile is refused before anything is sent), translates through the recorder, stores the answer in the job's
checkpoint (a rerun never pays twice), settles the reservation and writes the draft through the applier of the
translation sync. When a later request of a translation fails with a transient error, the texts of the requests
answered before it are kept in the checkpoint and the retry sends only the rest; the failed attempt's usage row
carries the cost of what was billed. A timeout **after** a request was sent is transient too, although the service
may have translated and billed it: the retry sends that request again. Placeholders that come back wrong fail the job
without a draft, but the cost of what was sent is settled. Errors that are not the service's (the gate, a limit, a
bug) are never retried as a service failure: a limit holds the job (`expAiLimitException`), anything else fails it.
Model translations stay `expai.request` jobs, so their lanes (batch, flex) work as before; jobs
queued before the update finish on the path they were queued on.

## The state rows

The stored state of a translation names the service: `provider`, `provider_label`, `provider_kind` (`llm` or `mt`)
and `provider_reason`. A row stored before translation services existed is read as `llm`. The Translations section
shows the lane of a model request and "Translated by DeepL" for a machine translation.

## A service of your own

A class implementing `expAiTranslationProvider`, usually extending `expAiTranslationProviderBase` with one method
`sendBatch()` (one request with a list of texts) and a group in `expai.ini`:
[extending.md](extending.md#8-a-translation-service).

## Tests

`tests/phpunit/unit/providers/expAiTranslation*Test.php` (registry, markup, language codes, DeepL and LibreTranslate
against a fake HTTP client, the run job, the snapshot of the model request), `tests/phpunit/unit/content/expAiTranslationSyncProviderTest.php`
and `tests/phpunit/unit/core/expAiTranslationServicesTest.php`; no test calls a service. The fake service of the
tests is `tests/phpunit/support/expaifaketranslationprovider.php`.
