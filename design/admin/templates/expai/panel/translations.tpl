{* Panel section "translations" ([PanelSection_translations], doc/translation-sync.md): per translation of the object
   the decision after the last change of the source language, the share that changed, the estimate (with the lane, or
   the translation service that translates it), the queue job, and Approve / Skip. Filled by expai_translations.js (expaitranslation::*), so nothing of it is part of the cached
   view. Shown only for objects with more than one language. *}
{if gt( $node.object.available_languages|count, 1 )}
<div class="exp-ai-section exp-ai-translations" data-node-id="{$node.node_id}" hidden="hidden">
    <h3>{'Translations'|i18n( 'extension/expai/translation' )}</h3>
    <p class="exp-ai-hint exp-ai-tr-source"></p>
    <table class="list exp-ai-tr-table" cellspacing="0">
        <thead>
            <tr>
                <th>{'Language'|i18n( 'extension/expai/translation' )}</th>
                <th>{'Status'|i18n( 'extension/expai/translation' )}</th>
                <th>{'Changed'|i18n( 'extension/expai/translation' )}</th>
                <th>{'Estimate'|i18n( 'extension/expai/translation' )}</th>
                <th>{'Job'|i18n( 'extension/expai/translation' )}</th>
                <th class="tight"></th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
    <p class="exp-ai-act-note exp-ai-tr-note" aria-live="polite"></p>
    <div class="exp-ai-actions">
        <input type="button" class="button exp-ai-btn exp-ai-tr-check" value="{'Check translations'|i18n( 'extension/expai/translation' )}" hidden="hidden" />
    </div>
    <p class="exp-ai-hint">{'Small changes of the source are queued as translation patches at once; full translations and larger or more expensive changes wait for your approval. Results are drafts of the translation; nothing is published.'|i18n( 'extension/expai/translation' )}</p>
    <script type="application/json" class="exp-ai-tr-strings">{hash(
        'source', 'Source language: %language, version %version'|i18n( 'extension/expai/translation' ),
        'state_unchecked', 'Not checked yet'|i18n( 'extension/expai/translation' ),
        'state_up_to_date', 'Up to date'|i18n( 'extension/expai/translation' ),
        'state_waiting', 'Waits for an editor'|i18n( 'extension/expai/translation' ),
        'state_queued', 'Queued'|i18n( 'extension/expai/translation' ),
        'state_held', 'Held by the budget'|i18n( 'extension/expai/translation' ),
        'state_draft', 'Draft written'|i18n( 'extension/expai/translation' ),
        'state_failed', 'Failed'|i18n( 'extension/expai/translation' ),
        'state_skipped', 'Skipped'|i18n( 'extension/expai/translation' ),
        'state_no_provider', 'No translation service'|i18n( 'extension/expai/translation' ),
        'state_blocked', 'Blocked by the data policy'|i18n( 'extension/expai/translation' ),
        'translated_by', 'Translated by %provider'|i18n( 'extension/expai/translation' ),
        'decision_noop', 'no change'|i18n( 'extension/expai/translation' ),
        'decision_patch', 'patch'|i18n( 'extension/expai/translation' ),
        'decision_full', 'full translation'|i18n( 'extension/expai/translation' ),
        'stale', 'The source changed again; check the translations.'|i18n( 'extension/expai/translation' ),
        'blocks', '%count block(s)'|i18n( 'extension/expai/translation' ),
        'approve', 'Approve'|i18n( 'extension/expai/translation' ),
        'approve_full', 'Translate in full'|i18n( 'extension/expai/translation' ),
        'skip', 'Skip'|i18n( 'extension/expai/translation' ),
        'skip_confirm', 'Mark the %language translation as up to date without changing it?'|i18n( 'extension/expai/translation' ),
        'full_confirm', 'Translate the whole object into %language again? Estimated cost: %estimate.'|i18n( 'extension/expai/translation' ),
        'view_draft', 'View draft'|i18n( 'extension/expai/translation' ),
        'job', 'Open the job'|i18n( 'extension/expai/translation' ),
        'checking', 'Checking...'|i18n( 'extension/expai/translation' ),
        'queued_note', 'Queued. The draft appears here when the job is done.'|i18n( 'extension/expai/translation' ),
        'skipped_note', 'Marked as up to date.'|i18n( 'extension/expai/translation' ),
        'error', 'Something went wrong:'|i18n( 'extension/expai/panel' )
    )|expai_json}</script>
    <script src={'javascript/expai_translations.js'|ezdesign}></script>
</div>
{/if}
