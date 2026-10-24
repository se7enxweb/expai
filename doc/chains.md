# AI chains

An AI chain writes content in steps instead of in one answer: one model writes, a second reviews the text against a
score and a checklist, the first revises until the review passes, a small model prepares title, summary and keywords
and checks the links, and then an editor decides before anything else happens. Every step is a queued job with its
own model, lane, tokens and cost. The result is one draft; nothing is published by a chain.

- [Running a chain](#running-a-chain)
- [How a run proceeds](#how-a-run-proceeds)
- [The checkpoint](#the-checkpoint)
- [Defining a chain](#defining-a-chain)
- [Worked example: a news brief chain](#worked-example-a-news-brief-chain)
- [Prompt templates](#prompt-templates)
- [Pass criteria](#pass-criteria)
- [Budgets](#budgets)
- [Configuration](#configuration)
- [Permissions](#permissions)
- [Database](#database)
- [Command line](#command-line)
- [For developers: the engine, the translation hand-off, Vector](#for-developers-the-engine-the-translation-hand-off-vector)

## Running a chain

Chains need the job queue (extension expqueue, with a worker or its cron part) and the chain tables
(`php extension/expai/bin/php/expaischema.php --install`).

- **Intelligence tab**: the section "Write with a chain" under the conversation. Pick the chain ("Use chain: Article"),
  the number of items, write the brief, press "Start the chain". The drafts are created under the page you are on.
- **Dashboard**: in "Start writing", next to "Write", choose "Use chain: Article", the number of items, and press
  "Start the chain". The request becomes the brief, the location of Start writing the location.

Each item is one run with its own job group. One item opens its run page; several open the list `expai/chain`.
The dashboard's card "Chains" lists your latest runs and how many wait for you.

## How a run proceeds

The shipped chain `article` (owner decision: Opus writes, Sonnet reviews, Haiku does SEO and QA, the human checkpoint
comes after the review and before the translation):

```
write (Opus) -> review (Sonnet) --fail--> revise (Opus) -> review ... (at most 3 reviews)
                     |pass
                     v
               SEO and QA (Haiku) -> checkpoint (editor) -> translate (hand-off) -> done
```

1. **write** (`generate`): the brief, the location, the class's fields and the read-only context of the tools
   `get_tree_context` and `list_children` go to Claude Opus; the answer is the fields as JSON (title, intro, body).
2. **review** (`review`): Claude Sonnet returns a structured review: `score` (0 to 100), `summary`, `issues`
   (severity high, medium or low; field; text) and every checklist item with `ok`. The pass criteria decide.
3. **revise** (`generate`): on a failed review, Claude Opus gets the text and the issues and returns every field again;
   then the review runs again. After three failed reviews (MaxIterations=3) the run is escalated: it goes on, and the
   editor sees the reasons at the checkpoint.
4. **SEO and QA** (`transform`): Claude Haiku returns a short title (written into `short_title`), a meta title,
   meta description and keywords, and last issues; every link of the text is checked offline (an http(s) or mailto
   address that parses, an internal link to a node or object that exists). A broken link or a high issue is flagged
   for the editor. When the class has an `xrowmetadata` field, the meta title, description and keywords are written
   into it (its other values are kept).
5. **checkpoint** (`human`): the draft is written now (one new object under the location, as the user who started the
   run, labeled "Exponential Intelligence" like every AI-written version, `expai_draft_log.source` = `chain`) and the
   run waits for an editor.
6. **translate** (`translate`): the object is handed to `[ChainSettings] TranslateHandler`, shipped as
   `expAiChainTranslateSync`, which uses the translation sync ([translation-sync.md](translation-sync.md)). The sync
   keeps the existing translations of a published object in step: for a published object with translations in the
   step's languages they are checked now (no-op, small patches queued at once, the rest waiting for an editor, as the
   sync decides). A new draft, the usual result of a chain, is not published and has no translations yet: the step is
   skipped and says so; once the editor publishes, later changes reach the translations through the sync's publish
   event. With `TranslateHandler` empty the step is always skipped. Who translates is the translation service of each
   pair ([translation-providers.md](translation-providers.md)); when no service can take any of the step's languages
   (none installed, or the data policy refuses every one), the step is skipped with that reason.

The text between the steps lives in the run (`expai_chain_run.data`), not in content. Each step's answer, verdict,
score, tokens and cost is a row of `expai_chain_step`, and each model call has its usage row (`expai/log`, action
`queue.chain`, with the job and the lane).

A step is queued only when the one before it is done (the applier of the finished job queues the next one), so the
order needs no other bookkeeping, and a job that runs again after a crash finds its step already recorded and changes
nothing.

## The checkpoint

The run page `expai/chain/(run)/<id>` shows the state, cost, tokens, time, the draft (version view and edit links),
the planned steps, every step with its model, lane, verdict, score, issues, checklist and cost, the meta data and the
link check, and the text so far. It shows the content's values, so only the editor who started the run, holders of
`expai/log`, and users who may use the chain (`expai/chain`) and edit the run's object see it. A run that has not
created its object yet is seen by its starter and the holders of `expai/log` only: who may edit the parent node does
not see another editor's unpublished document. The same rule decides who may act on a run; it is checked for the user
asked about, not for whoever is logged in.

While the run waits, an editor who may act on it (below) has three buttons:

- **Approve and continue**: the draft is read back (so changes you made in the edit form are kept) and the run goes on
  with the step after the checkpoint.
- **Ask for changes** (a note is required): the note goes to the revise step as a high-severity issue, then the review
  runs again (with a fresh count of three), then SEO and QA, and the run is back at the checkpoint. The same draft is
  updated; no second object is created.
- **Stop the run**: the run's queued jobs are canceled. The draft stays a draft; discard it like any other.

A run that is held by its budget offers "Continue with a higher run budget"; any run that has not ended can be stopped.

## Defining a chain

A chain is settings in `expai.ini` (override in `settings/override/expai.ini.append.php` or in your extension) plus
prompt templates.

```ini
[ChainSettings]
Chains[]=article
Chains[]=newsbrief

[Chain_<id>]
Label=...                 # the name in the pickers and the policy limitation
Description=...
Class=article             # the class of the draft
Fields[]=title            # the fields the generate steps write (identifiers of the class)
Steps[]=write             # the steps in order; steps reached by ReviseStep= or Next= need not be listed
MaxCostPerRun=1.00        # the run budget in the [Pricing] currency (0: none); also the job group's budget
MaxItems=5                # the most items one start may ask for

[ChainStep_<id>.<step>]
Type=generate | review | transform | human | translate
Label=...                 # the name on the run page (and the step name the model is told)
Model=anthropic.claude-opus-5-5   # a model alias of the provider registry (models.md / providers.md)
Lane=auto                 # auto, standard, flex, batch, fast (lanes.md)
PromptTemplate=expai/chains/<file>.tpl   # or PromptText=<text>
Tools[]=get_tree_context  # read tools run before the call; their results become {$context}
PassCriteria[]=score>=80  # all must hold (review steps need at least one)
Checklist[]=...           # items the reviewer answers one by one ({$checklist})
MaxIterations=3           # reviews before OnExhausted applies (1..10)
OnFail=revise | stop | escalate
ReviseStep=revise         # the generate step a failed check sends the text to
OnExhausted=escalate | stop
Checkpoint=human | none   # human: the run waits for an editor after this step
Next=review               # the step after this one (for a revise step: back to the review)
MaxTokens=6000            # the longest answer of the step (a reasoning model gets at least 1000)
OutputFields[]=short_title   # transform steps: the fields they may write; generate steps: instead of Fields[]
Languages[]=all           # translate steps: target languages (all: every language of the site but the run's)
```

Rules the definition is checked against when a run starts (a broken chain is not offered and cannot start):
the first step generates; every step has a group; `Type` is one of the five; model steps have `Model` and a prompt;
a `PromptTemplate` is a relative `.tpl` path; a review has `PassCriteria[]`; `ReviseStep` names a generate step;
`Next` names a step; criteria are comparisons of known metrics.

A run keeps a snapshot of its definition with the prompt texts (`expai_chain_run.snapshot`): editing the settings or
a template never changes a run that has started. The run page says when the chain changed since.

## Worked example: a news brief chain

A short news item: Sonnet writes, Haiku checks, no SEO step, the editor approves, then the end.

`settings/override/expai.ini.append.php` (or your extension's settings):

```ini
[ChainSettings]
Chains[]=newsbrief
PromptDirectories[]=extension/mysite/design/admin/templates

[Chain_newsbrief]
Label=News brief
Description=A 120-word news item, checked once
Class=article
Fields[]=title
Fields[]=intro
Steps[]=write
Steps[]=check
Steps[]=approve
MaxCostPerRun=0.20
MaxItems=10

[ChainStep_newsbrief.write]
Type=generate
Model=anthropic.claude-sonnet-5-5
PromptTemplate=mysite/chains/newsbrief_write.tpl
MaxTokens=1500

[ChainStep_newsbrief.check]
Type=review
Label=Check
Model=anthropic.claude-haiku-5-5
PromptText=Check this news item against the brief {$brief}. Item: {$document}. Answer each checklist item: {$checklist}
Checklist[]=At most 120 words
Checklist[]=Names who, what, when and where
PassCriteria[]=score>=70
PassCriteria[]=checklist.failed==0
OnFail=revise
ReviseStep=write
MaxIterations=2
OnExhausted=stop

[ChainStep_newsbrief.approve]
Type=human
Label=Editor approves
```

`extension/mysite/design/admin/templates/mysite/chains/newsbrief_write.tpl`:

```
{* News brief: write *}
Write a news item of at most 120 words for {$location}.
Brief: {$brief}
Issues to fix (empty the first time): {$issues}
Fields: {$fields}
Language: {$language}
```

Here the write step doubles as the revise step (`ReviseStep=write`): it has no `Next=`, so after it the following
step of `Steps[]` runs, which is the check again. When the check fails twice the run stops (`OnExhausted=stop`).
Give the role of the editors `expai/chain` with the limitation Chain "News brief", clear the INI cache
(`php bin/php/ezcache.php --clear-tag=ini`) and the chain appears in the pickers.

## Prompt templates

A prompt is a file `PromptTemplate=` looked up in `[ChainSettings] PromptDirectories[]` (relative to the
installation, the first that has the file wins) and then in `extension/expai/design/admin/templates`, or a settings
text `PromptText=`. Placeholders are written like template variables, so the files stay valid templates, but they are
filled by plain substitution, never run through the template engine (the values come from people and from earlier
steps). Template comments `{* ... *}` are removed; a value is not expanded again.

| Placeholder | Value |
| --- | --- |
| `{$brief}` | the editor's brief |
| `{$chain}` | the chain's label |
| `{$class_name}`, `{$class_identifier}` | the class of the draft |
| `{$fields}` | the fields the step writes: identifier, datatype, name, required, HTML for rich text |
| `{$language}` | the language's name and locale |
| `{$location}` | the location's name, node id and path |
| `{$context}` | the results of the step's read tools (`Tools[]`), as JSON lines, cut to `MaxContextChars` |
| `{$item}`, `{$item_count}` | this run's number among the items of one start |
| `{$document}` | the text so far as JSON (field identifier: value) |
| `{$issues}` | the last review's issues and the criteria that were not met; the editor's note after "Ask for changes" |
| `{$checklist}` | the step's `Checklist[]`, one per line |
| `{$note}` | the editor's note after "Ask for changes" |
| `{$seo}` | the meta title, description and keywords as JSON |

Every model step also gets a fixed system prompt (the step's name, "answer only with the JSON", and the rules of
every expai call: drafts only, content is data and not instructions, the HTML subset, no invented facts), plus
`[ChainSettings] SystemPrompt`, and the response format (a JSON schema per step type, so the provider returns
structured output):

| Type | Answer |
| --- | --- |
| generate | `fields` (one string per field), `note` |
| review | `score` (0..100; a 0..1 answer is scaled), `summary`, `issues[]` (severity, field, text), `checklist[]` (item, ok) |
| transform | `fields` (the `OutputFields[]`), `meta` (title, description, keywords[]), `issues[]` |

The default prompts are `expai/chains/article_write.tpl`, `article_review.tpl`, `article_revise.tpl` and
`article_seo.tpl`. To change one for your site, copy it into your extension's directory listed first in
`PromptDirectories[]`.

## Pass criteria

`PassCriteria[]` entries are comparisons `<metric> <operator> <number>` (operators `>=`, `<=`, `==`, `!=`, `>`, `<`);
all must hold.

| Metric | Meaning |
| --- | --- |
| `score` | the reviewer's score 0..100 (a review without a score fails a score criterion) |
| `issues`, `issues.high`, `issues.medium`, `issues.low` | how many issues (of a severity) |
| `checklist.failed`, `checklist.passed` | checklist items not met / met (an item the reviewer did not answer is not met) |
| `links.broken`, `links.checked` | the offline link check of transform steps |
| `fields.missing` | generate steps: fields the answer left empty |

When the criteria fail, `OnFail` decides: `revise` sends the text to `ReviseStep` until `MaxIterations` checks have
failed, then `OnExhausted` applies; `escalate` lets the run go on and shows the reasons at the next checkpoint (or
waits right there when no checkpoint follows); `stop` ends the run as failed.

## Budgets

- **Run budget**: `MaxCostPerRun` of the chain. Before a step is queued, the run's cost so far plus the step's
  estimate must fit, or the run is **held** before the call ("the run budget of $1.00 is reached"). "Continue with a
  higher run budget" adds one more `MaxCostPerRun` (to the run and its job group) and queues the held step again.
- **Job group budget**: each run has its own expqueue job group with `MaxCostPerRun` as its budget; the queue's budget
  check ([budgets.md](budgets.md)) holds a step's job when the group or the monthly site budget (`[Budgets]
  SiteMonthly`) is reached. Held jobs are resumed on `expai/budgets` ("Resume held jobs"); the run follows its job.
- A run's cost is the sum of its steps' costs (from the usage rows, at the lane's price factor), shown on the run page,
  in the list and on the dashboard card.

Measured on 2026-10-11 with the shipped chain and the real models for a short article (about 250 words), the review
passing at the first attempt (score 88): write (Opus) 1,614 input / 698 output tokens, about $0.022, 11 s; review
(Sonnet) 1,940 / 509, about $0.010, 7 s; SEO and QA (Haiku) 1,835 / 1,147, about $0.001 to $0.007, 7.5 s; together
$0.033 and 28 s from the start to the checkpoint (queue waits included, a worker running). Each revision adds about
the cost of a write and a review.

## Configuration

`[ChainSettings]`: `Chains[]` (the chains offered), `PromptDirectories[]`, `SystemPrompt`, `MaxBriefLength` (4000),
`MaxContextChars` (12000), `MaxStoredOutputBytes` (200000), `TranslateHandler` (`expAiChainTranslateSync`). `[PanelSettings]
Sections[]=chains` with `[PanelSection_chains]` puts "Write with a chain" into the Intelligence tab;
`[QueueSettings] Appliers[chain]=expAiChainApplier` connects the queue to the engine (do not remove it while chains
are used). The chain and step groups are described above; every setting is listed in
[configuration.md](configuration.md#ai-chains). The queue class of chain steps is `chain` (expqueue
`[QueueClass_chain]`, lane order `[LanePolicy] ClassOrder[chain]`).

## Permissions

| Policy | Allows |
| --- | --- |
| `expai/chain` | starting runs of the chains in the limitation **Chain** (all without it), with `expai/write`; the view `expai/chain`; acting at checkpoints |

- Starting a run needs `expai/chain` for that chain, `expai/write`, read access to the location and content/create of
  the chain's class there (checked before anything is queued). The draft is written with the starting user's own
  permissions, as everything expai writes.
- One run started opens its page (`expai/chain/(run)/<id>`, with its job group and the job of every step); several
  started at once open the list, which names each of them with a link to the run and to its job group
  (`expqueue/group/<uuid>`, with `expqueue/view`).
- Seeing a run: the user who started it, users with `expai/log`, and users with `expai/chain` who can read the run's
  location.
- Acting on a run (approve, ask for changes, stop, continue): the user who started it, or a user with `expai/chain`
  for that chain who may edit the run's draft (a second editor reviewing). The steps after that keep running as the
  user who started the run, who owns the draft.
- The forms post with the form token (ezformtoken); the run page takes view parameters only.

## Database

Two tables, defined in `expAiChainSchema` (classes/chain/) and merged into `expAiSchema::definition()`, so
`bin/php/expaischema.php --install` creates and upgrades them on MySQL, PostgreSQL, SQLite, Oracle and MongoDB, and
`--write-files` writes them into `share/db_schema.dba` and `sql/<engine>/schema.sql`. See [database.md](database.md#ai-chains).

| Table | One row per | Main columns |
| --- | --- | --- |
| `expai_chain_run` | run | `uuid`, `chain`, `chain_hash`, `label`, `user_id`, `group_uuid`, `node_id`, `object_id`, `version`, `language`, `class_identifier`, `brief`, `item`/`item_count`, `state`, `current_step`, `data` (JSON), `snapshot` (JSON), `cost_micros`, `input_tokens`, `output_tokens`, `last_error`, `created`, `modified`, `finished` |
| `expai_chain_step` | step and iteration | `run_id`, `step`, `step_type`, `iteration`, `language`, `job_uuid`, `model`, `provider`, `lane`, `service_tier`, `state`, `verdict`, `score`, `issue_count`, tokens, `cost_micros`, `usage_log_id`, `input_hash`, `output` (JSON, capped), `note`, `created`, `finished` |

Run states: `running`, `waiting_human`, `held`, `done`, `failed`, `stopped`. Step states: `queued`, `done`,
`skipped`, `waiting`, `held`, `failed`.

## Command line

There is no chain CLI yet; runs are started from the admin. For tests and scripts, `expAiChainEngine` is the API
(below). Queue jobs of chains are ordinary `expai.request` jobs: `./console ext:expqueue:list`, `show`, `cancel`.

## For developers: the engine, the translation hand-off, Vector

```php
$engine = new expAiChainEngine();
$runs = $engine->start( 'article', $nodeID, 'Brief ...', 1, array( 'language' => 'eng-GB', 'lane' => '' ) );
$engine->approve( $run );                     // at the checkpoint
$engine->requestChanges( $run, 'Add dates' ); // at the checkpoint
$engine->stop( $run );
$engine->resume( $run );                      // a held run
$engine->reconcile( $run );                   // brings a run in line with its job (dead -> failed, held -> held)
```

- **Translation hand-off**: `[ChainSettings] TranslateHandler=<class>` with a static method
  `chainTranslate( expAiChainRun $run, array $languages )` that queues the translations of the run's draft
  (`object_id`, `version`, `language`) and returns a summary array; it runs when the run reaches the translate step
  after the checkpoint. A summary with the key `skipped` (the reason) marks the step skipped. The shipped handler
  `expAiChainTranslateSync` hands the object to `expAiTranslationSync::check()`. Without a handler the step is skipped.
- **Vector integration point**: Vector (expai/vector) can offer a chain as a mode by calling
  `expAiChainEngine::start()` with the thread's scope node and the user's brief, and linking the thread to the run
  page `expai/chain/(run)/<id>`; `expAiChainFunctionCollection::row()` gives the state, step and cost for a thread
  card, and `fetch( 'expai', 'chain_runs', hash( 'node_id', <node> ) )` lists the runs of a location. A thread event
  can be written from the applier result (`run`, `step`, `verdict`, `score`), which every finished step job returns.
- **Template fetch functions**: `fetch( 'expai', 'chains' )` (the chains the user may start) and
  `fetch( 'expai', 'chain_runs', hash( 'limit', 5, 'node_id', 0 ) )` (the user's own latest runs and how many wait).
- **Tests**: `extension/expai/tests/expaichain_test.php` (definitions, criteria, loop limits, budgets, answers,
  prompts, schema; no database writes) and `extension/expai/tests/expaichain_e2e_test.php` (full runs against the
  mock `tests/mock/chain_server.php`).
