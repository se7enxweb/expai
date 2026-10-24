<?php /* #?ini charset="utf-8"?

# The job types of expai in the job queue (extension expqueue), and the concurrency of the lanes of the built-in
# providers. See doc/lanes.md and doc/budgets.md. Override in settings/override/expqueue.ini.append.php.

# One request to a model on a lane (expAiQueue::request()). The class comes from the producer
# (expai.ini [LanePolicy] PurposeClass[]); the resource is <provider>:<lane>.
[JobType_expai.request]
Handler=expAiRequestHandler
Class=editorial
MaxAttempts=5
Timeout=300
RetryBase=30
MaxBackoff=1800
Description=One request to a model on a lane (standard, flex, fast or batch)

# Collects the waiting requests of one model and batch lane into a provider batch.
[JobType_expai.batch.submit]
Handler=expAiBatchSubmitHandler
Class=system
MaxAttempts=6
Timeout=300
RetryBase=60
MaxBackoff=1800
Description=Submits the waiting requests of a batch lane as one provider batch

# Polls one provider batch until it ended, then hands its results to the requests.
[JobType_expai.batch.poll]
Handler=expAiBatchPollHandler
Class=system
MaxAttempts=20
Timeout=600
RetryBase=60
MaxBackoff=3600
Description=Polls a provider batch and hands its results to the waiting requests

# The concurrency of each lane: jobs of the resource <provider>:<lane> at once across all workers. A 429 or a
# retry-after halves it for a while (Adaptive); the remaining-requests headers lower it (LowWater).
[QueueResource_anthropic:standard]
MaxConcurrent=4

[QueueResource_anthropic:fast]
MaxConcurrent=2

# Requests of a batch lane only wait in the queue; one submit or poll at a time per provider calls the batch API.
[QueueResource_anthropic:batch]
MaxConcurrent=50
Adaptive=disabled

[QueueResource_anthropic:batch-api]
MaxConcurrent=1

[QueueResource_openai:flex]
MaxConcurrent=4

[QueueResource_openrouter:flex]
MaxConcurrent=4

# Threads (doc/vector.md "Background runs"): one turn of a thread (every step until the model answers) run by the
# worker as the thread's owner. One attempt more after a crash (the message is never sent twice); a model error
# ends the turn (the editor decides). Timeout per lease: each step extends it.
[JobType_expai.thread.turn]
Handler=expAiThreadTurnHandler
Class=interactive
MaxAttempts=2
Timeout=900
RetryBase=10
MaxBackoff=60
Description=One turn of a thread of Exponential Intelligence (Vector, the Intelligence tab) in the background

# Parallel runs (doc/parallel-runs.md): one item of an approved plan as a child run of its thread, side by side with
# the other items (resource expai.thread:<thread id>, at most [VectorSettings] MaxParallelRunsPerThread at once).
[JobType_expai.thread.item]
Handler=expAiThreadItemHandler
Class=interactive
MaxAttempts=2
Timeout=900
RetryBase=10
MaxBackoff=60
Description=One item of an approved plan of a Vector thread, run side by side with the other items

# The translation sync: after a publish in the source language, decides for each translation and queues the small
# patches (no model call; the patches themselves are expai.request jobs of the class translation_patch).
[JobType_expai.translation.check]
Handler=expAiTranslationCheckHandler
Class=system
MaxAttempts=3
Timeout=120
RetryBase=30
MaxBackoff=600
Description=Checks the translations of an object after its source language was published

# The translation sync with a machine translation service (doc/translation-providers.md): one translation of an object
# by the service ([TranslationSettings]), written into a draft of the target language. The class comes from the sync
# (translation_patch or translation_full); the resource is translation:<service id>.
[JobType_expai.translation.run]
Handler=expAiTranslationRunHandler
Class=translation_patch
MaxAttempts=5
Timeout=300
RetryBase=30
MaxBackoff=1800
Description=Translates an object with a translation service and writes a draft of the target language

# Requests of each translation service at once across all workers
[QueueResource_translation:deepl]
MaxConcurrent=4

[QueueResource_translation:libretranslate]
MaxConcurrent=1


# Alt text for images (#370 M5 alt text, doc/alt-text.md): one suggestion of the "Missing alt text" report, written
# into a new draft of the object (never published). The resource is <provider>:standard.
[JobType_expai.alttext]
Handler=expAiAltTextJobHandler
Class=bulk
MaxAttempts=4
Timeout=300
RetryBase=60
MaxBackoff=1800
Description=Writes the alt text of an image into a new draft (a vision model looks at the image)

# Test prompt and Load model of expai/models (doc/local-models.md "Test prompt and Load model"): one short prompt to
# a model, or loading a model of a local server, run by the worker as the user who clicked. The class interactive:
# the page starts a worker for it when none runs. One attempt: a failed test is a result. Timeout: a local model
# may load for a minute and then write a few tokens per second.
[JobType_expai.model.test]
Handler=expAiModelTestHandler
Class=interactive
MaxAttempts=1
Timeout=420
RetryBase=10
MaxBackoff=60
Description=Test prompt or load of a model (expai/models)
# The reference rates of the European Central Bank (#392, classes/expaiexchangerates.php): queued once a day when the
# stored rates are older than expai.ini [Pricing] ExchangeRatesMaxAgeHours; no model call, no personal data.
[JobType_expai.exchange.rates]
Handler=expAiExchangeRatesHandler
Class=system
MaxAttempts=3
Timeout=60
RetryBase=300
MaxBackoff=3600
Description=Fetches the reference exchange rates of the European Central Bank


*/ ?>
