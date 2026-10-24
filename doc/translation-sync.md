# Translation sync: keeping translations in step with their source

When the source language of an object changes, its translations are out of date. The translation sync finds what
changed ([diff.md](diff.md)), decides per translation how to update it, queues the cheap cases at once and lets an
editor approve the others. The result is always a **draft** of the translation, labeled "Exponential Intelligence"
like every draft the assistant writes; nothing is published.

## The decision

For each translation (every language of the object except the source, or `[TranslationSync] TargetLanguages[]`):

1. **The base**: the source version the translation was made from. The newest of
   - the last published version whose initial language is the translation's language (whoever edited the
     translation saw the source as it was in that version),
   - the source version of a draft this sync wrote, once that draft is published,
   - a base set by **Skip** or by a check that found nothing to do.
2. **The diff** of the source language between the base and the current version, only the attributes that can be
   translated and whose datatype is in `[TranslationSync] Datatypes[]`.
3. **Alignment**: a patch is possible only where the translation still has the blocks of its base (the same number
   and kinds of paragraphs, headings, list items, cells per rich text or text attribute).
4. **The decision**:

| Decision | When | What happens |
| --- | --- | --- |
| `noop` | nothing translatable changed | the base moves forward; "Up to date" |
| `patch` | changed ratio up to `PatchMaxChangedRatio` (0.25), at most `PatchMaxSegments` (40) changed blocks, estimate at or below `CostThreshold` (0.02), `AutoQueuePatch` enabled | **queued at once** (job class `translation_patch`) |
| `patch`, waits | a patch above the cost threshold, with more blocks, between `PatchMaxChangedRatio` and `FullMinChangedRatio`, or with `AutoQueuePatch` disabled | waits for an editor ("Approve") |
| `full` | changed ratio from `FullMinChangedRatio` (0.6), or the translation does not have the structure of its base (`FullIfStructureMismatch`), or no base was found | waits for an editor ("Approve"); job class `translation_full` |

This is the owner's rule: **patches below the cost threshold are queued automatically; full translations and anything
above the threshold wait for an editor.** The changed ratio is the share of the source's characters that changed
([diff.md](diff.md#metrics)); a change of markup only (a new link target) is a patch with ratio 0.

### What a patch sends

Only the changed blocks, each with its old source, its new source and the current translation of that block, the
text of the blocks around it as context, and the instruction to change the translation only where the source changed.
New blocks are sent with their source only. Single-value attributes (`ezstring`) and keyword lists are sent whole
(old source, new source, current translation). Markup travels as placeholders (`<1>…</1>`, `<2/>`), so links,
emphasis and embedded objects stay exactly as they are.

The answer is put together with the **new source's structure**: unchanged blocks keep the existing translation
(byte for byte, with its own markup), changed and new blocks get the answer, removed blocks disappear, moved blocks
take their translation with them. Attributes without a change are not written.

### A full translation

Every block of every translatable attribute is sent with its source only. The draft has the structure of the
current source.

### Cost

The estimate uses the cost model of the queue: the request's characters / `[Budgets] CharsPerToken` as input
tokens, about 1.2 times the source characters as output, the prices of the model alias and the factor of the lane
the queue would choose (`expAiBudget::estimate()`). A queued request also reserves its estimate in the monthly
budget ([budgets.md](budgets.md)); a request the budget cannot take is held ("Held by the budget").

The model is `[TranslationSync] Model` (for example `anthropic.claude-haiku-5-5`), else the site default. The lanes are
`PatchLane` and `FullLane` (`auto`: `[LanePolicy] ClassOrder[translation_patch]` and `ClassOrder[translation_full]`,
so a batch lane is used where the provider offers one and the job waits for the provider's batch).

## Who translates: the translation service

The decision above does not depend on who translates. The translation itself is done by the **translation service**
of the language pair and purpose (`patch` or `full`), chosen in `[TranslationSettings]`
([translation-providers.md](translation-providers.md)):

- `llm` (the default, and the only service without the group): the model above; the request is queued as an
  `expai.request` job with its lane, as before.
- a machine translation service (DeepL, LibreTranslate): the job `expai.translation.run`, class
  `translation_patch` or `translation_full`, resource `translation:<id>`. A patch by such a service translates the
  new text of the changed blocks (it gets neither the old source nor the current translation); unchanged blocks keep
  their translation byte for byte, as with the model. The usual split is `ProviderFor[*:*:full]=deepl`: full
  translations by DeepL, patches by the model.

**Cost with a machine translation service**: the estimate is the characters to translate (without markup) x
`[TranslationProvider_<id>] PricePerMChars`, and `CostThreshold` compares that estimate as it is. A free local
service (`PricePerMChars=0`) queues every small patch at once.

**No service**: when no service can take the translation (`Provider=none`, the chosen service not configured or not
translating the pair and `Fallback=none`, or the data policy refusing every service), the decision is still made and
stored, with the state **No translation service** (`no_provider`) or **Blocked by the data policy** (`blocked`) and
the reason. Nothing is queued; Approve and Translate in full are not offered, and Approve over ezjscore or the command
line refuses with the reason. Runtime failures of a service (an outage, a used-up quota) never fall back to another
service: the job fails and the section shows "Failed" with the reason.

## Triggers

### After a publish: the workflow event

Add the event **Exponential Intelligence: translation sync** (`event_expaitranslationsync`) to a workflow and connect
it to the trigger **content / publish / after** (Setup > Workflows, Setup > Triggers). When the published version is in
the object's source language (`[TranslationSync] SourceLanguages[]`, else the object's main language) and the object
has translations, it queues a job `expai.translation.check` (class `system`, no model call; idempotent per object
and version) that decides for every translation and queues the small patches. With `QueueCheck=disabled`, or without
the queue, the check runs during the publish. The event never stops a publish: every outcome is "accepted", failures
are written to the debug log.

If a post-publish trigger already has a workflow, add the event to that workflow (a trigger has one workflow).

### In the Intelligence tab

The section **Translations** of the Intelligence tab (objects with more than one language) lists per translation the
status, the decision, the changed share and blocks, the estimate and lane (or "Translated by DeepL" for a machine
translation service), the job (a link to `expqueue/job/<uuid>`
for users with `expqueue/view`) and the draft (View draft). Buttons, for users with `expai/translation_sync` who may
edit the object in that language:

| Button | Does |
| --- | --- |
| Check translations | decides again for every translation (and queues small patches) |
| Approve | queues what was decided (patch or full) |
| Translate in full | queues a full translation instead of a patch |
| Skip | marks the translation as up to date with the current source version (a queued job is canceled) |

The section loads through ezjscore (`expaitranslation::status`, `check`, `approve`, `skip`, POST with the form
token), so nothing personal is in the cached view; while a job is queued it asks again every 5 seconds.

### On the command line

```
./console ext:expai:translation-sync --object=<id|remote id> [--languages=ger-DE] [--dry-run] [--diff]
./console ext:expai:translation-sync --object=<id> --status
./console ext:expai:translation-sync --object=<id> --languages=ger-DE --approve [--mode=patch|full] --login=<user>
./console ext:expai:translation-sync --object=<id> --languages=ger-DE --skip --login=<user>
./console ext:expai:translation-sync --object=<id> --languages=ger-DE --approve --provider=deepl --login=<user>
```

`--provider=<id>` makes that translation service translate (no rule, no fallback). Every line names the service;
when no service can take a translation the reason is printed and the script exits with 2.

Without `--approve`, `--skip` or `--status` it does what the workflow event does. `--dry-run` decides and prints
without storing or queueing anything; `--diff` also prints the hunks of the source change; `--json` prints the report
as JSON.

## The result

The applier `translation_sync` (`[QueueSettings] Appliers[translation_sync]`) writes the answer into a new draft of
the translation language, as the user who queued the job (the publisher for automatic patches, the editor for
approvals), with that user's permissions (`content/edit` in the language). The draft is logged in `expai_draft_log`
with `source` `translation` and the job's uuid, so it carries the label and appears under the user's drafts and on
`expai/log`. It is idempotent: a rerun of the job finds the draft of its uuid.

The applier refuses (the job ends dead, the section shows "Failed" with the reason):

- an answer whose placeholders do not fit the block (an embed dropped, numbers changed or wrongly nested),
- an answer without a text for a block, or cut off at `max_tokens`,
- a patch when the translation was edited after the patch was planned (check again).

## Where the state is kept

Per object and translation in `ezsite_data` under `expai_tsync:<object id>:<language>` (JSON: decision, base, versions,
metrics, estimate, job uuid, draft version, who checked, approved or skipped, and the translation service: `provider`,
`provider_label`, `provider_kind`, `provider_reason`; a row without `provider` was stored for `llm`). No table of its own.

## Permissions

| Needs | For |
| --- | --- |
| `expai/write` | to see the Translations section |
| `expai/translation_sync` | Check translations, Approve, Translate in full, Skip |
| `content/edit` in the language | Approve and Skip of that language; the draft itself |
| `expqueue/view` | the link to the job |

## Limits

- Kernel version history: when the base version was removed (`[VersionManagement]` keeps a limited number of
  versions), the diff cannot be made against it: the decision is `full`.
- Language-specific values outside text blocks (custom tag attributes, embed sizes, table attributes) follow the new
  source in a patch or full translation.
- Attributes of datatypes outside `Datatypes[]` are not translated (relations, images, booleans keep what the kernel
  copies).
- A patch is made per block; a translation that merged or split paragraphs differently from its source cannot be
  patched (`full`).

## Tests

- `php bin/php/ezexec.php extension/expai/tests/expaidiff_test.php`: segmenters, diff, merge, compose, the decision
  matrix (no database, no network).
- `extension/expai/tests/expaitranslationsync_e2e_test.php` (environment `EXPAI_TEST_LOGIN`, `EXPAI_TEST_PARENT`,
  optional `EXPAI_TEST_MODEL`, `EXPAI_TEST_TARGET`, `EXPAI_KEEP`): creates an article in two languages, edits the source
  slightly (workflow event, check job, patch queued at once and run: only the changed block differs, links kept, a
  draft with the label), then rewrites it (full, waits for an editor, approved and run), then Skip; removes the article
  at the end. It calls the model (a few cents with Haiku).
