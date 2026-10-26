{* The Exponential Intelligence controls of the content edit form, added through content.ini [EditSettings]
   AdditionalTemplates[] (the hook ezautosave uses). The sections come from expai.ini [EditViewSettings] Sections[];
   expai.js adds the field actions next to the fields. Values are only put into the form: nothing is stored until
   the draft is stored or published as usual. *}
{if and( fetch( 'expai', 'switched_on' ),
         array( 'true', 'enabled' )|contains( ezini( 'EditViewSettings', 'Enabled', 'expai.ini' )|downcase ),
         fetch( 'user', 'has_access_to', hash( 'module', 'expai', 'function', 'write' ) ) )}
{def $exp_ai_attributes = array()
     $exp_ai_actions = array()
     $exp_ai_languages = array()}
{foreach $content_attributes as $exp_ai_attribute}
    {set $exp_ai_attributes = $exp_ai_attributes|append( hash( 'id', $exp_ai_attribute.id,
                                                              'identifier', $exp_ai_attribute.contentclass_attribute_identifier,
                                                              'datatype', $exp_ai_attribute.data_type_string,
                                                              'name', $exp_ai_attribute.contentclass_attribute_name ) )}
{/foreach}
{* an action that cannot be used at all (Translate without a translation service) is left out *}
{def $exp_ai_unavailable = fetch( 'expai', 'unavailable_field_actions' )}
{foreach ezini( 'EditViewSettings', 'FieldActions', 'expai.ini' ) as $exp_ai_action}
    {if $exp_ai_unavailable|contains( $exp_ai_action )|not}
    {set $exp_ai_actions = $exp_ai_actions|append( hash( 'name', $exp_ai_action,
                                                        'label', ezini( concat( 'FieldAction_', $exp_ai_action ), 'Label', 'expai.ini' )|i18n( 'extension/expai/fieldaction' ),
                                                        'input', ezini( concat( 'FieldAction_', $exp_ai_action ), 'Input', 'expai.ini' ),
                                                        'datatypes', ezini( concat( 'FieldAction_', $exp_ai_action ), 'Datatypes', 'expai.ini' ) ) )}
    {/if}
{/foreach}
{undef $exp_ai_unavailable}
{foreach fetch( 'content', 'prioritized_languages' ) as $exp_ai_language}
    {set $exp_ai_languages = $exp_ai_languages|append( hash( 'code', $exp_ai_language.locale, 'name', $exp_ai_language.name ) )}
{/foreach}
<section class="exp-ai exp-ai-edit" id="exp-ai-edit" aria-labelledby="exp-ai-edit-title">
    <header class="exp-ai-head">
        <span class="exp-ai-mark" aria-hidden="true"></span>
        <div>
            <h2 id="exp-ai-edit-title">{'Exponential Intelligence'|i18n( 'extension/expai/edit' )}</h2>
        </div>
        <input type="button" class="button exp-ai-btn exp-ai-toggle" aria-expanded="false" aria-controls="exp-ai-edit-body" value="{'Show'|i18n( 'extension/expai/edit' )}" />
    </header>
    {include uri='design:expai/edit/presets.tpl' object=$object content_attributes=$content_attributes}
    <div class="exp-ai-edit-body" id="exp-ai-edit-body" hidden="hidden">
{foreach ezini( 'EditViewSettings', 'Sections', 'expai.ini' ) as $exp_ai_section}
        {include uri=concat( 'design:', ezini( concat( 'EditSection_', $exp_ai_section ), 'Template', 'expai.ini' ) ) section=$exp_ai_section}
{/foreach}
    </div>
    <script type="application/json" class="exp-ai-edit-config">{hash(
        'object_id', $object.id,
        'version', $edit_version,
        'language', $edit_language,
        'attributes', $exp_ai_attributes,
        'actions', $exp_ai_actions,
        'writable', ezini( 'ToolSettings', 'WritableDatatypes', 'expai.ini' ),
        'hide_editor_ai', array( 'true', 'enabled' )|contains( ezini( 'EditViewSettings', 'HideActionsWithEditorAI', 'expai.ini' )|downcase ),
        'max_field_length', ezini( 'EditViewSettings', 'MaxFieldLength', 'expai.ini' ),
        'languages', $exp_ai_languages,
        'strings', hash(
            'show', 'Show'|i18n( 'extension/expai/edit' ),
            'hide', 'Hide'|i18n( 'extension/expai/edit' ),
            'actions', 'Exponential Intelligence'|i18n( 'extension/expai/edit' ),
            'actions_title', 'Exponential Intelligence'|i18n( 'extension/expai/edit' ),
            'instruction', 'What should be written?'|i18n( 'extension/expai/edit' ),
            'optional_instruction', 'Optional: how should it change?'|i18n( 'extension/expai/edit' ),
            'language', 'Into which language?'|i18n( 'extension/expai/edit' ),
            'go', 'Go'|i18n( 'extension/expai/edit' ),
            'cancel', 'Cancel'|i18n( 'extension/expai/edit' ),
            'working', 'Exponential Intelligence is working...'|i18n( 'extension/expai/edit' ),
            'filled', 'Filled in by Exponential Intelligence. Review it before you store the draft.'|i18n( 'extension/expai/edit' ),
            'filled_count', '%count field(s) filled in by Exponential Intelligence. Review them before you store the draft.'|i18n( 'extension/expai/edit' ),
            'undo', 'Undo'|i18n( 'extension/expai/edit' ),
            'undone', 'Restored the previous value.'|i18n( 'extension/expai/edit' ),
            'error', 'Something went wrong:'|i18n( 'extension/expai/edit' ),
            'timeout', 'The server did not answer in time. Please try again.'|i18n( 'extension/expai/edit' ),
            'nothing', 'Exponential Intelligence suggested no values.'|i18n( 'extension/expai/edit' ),
            'review_title', 'Review the answer'|i18n( 'extension/expai/edit' ),
            'review_note', 'Nothing was written into the field yet: review the answer below.'|i18n( 'extension/expai/edit' ),
            'review_changed', 'The field changed while the answer was written.'|i18n( 'extension/expai/edit' ),
            'review_lost', '%list would be lost.'|i18n( 'extension/expai/edit' ),
            'review_count', '%count answer(s) not written: review them at their fields.'|i18n( 'extension/expai/edit' ),
            'apply_anyway', 'Apply anyway'|i18n( 'extension/expai/edit' ),
            'keep_original', 'Keep the original'|i18n( 'extension/expai/edit' ),
            'copy_text', 'Copy text'|i18n( 'extension/expai/edit' ),
            'copied', 'Copied.'|i18n( 'extension/expai/edit' ),
            'copy_failed', 'The text could not be copied.'|i18n( 'extension/expai/edit' ),
            'kept', 'Kept the original value.'|i18n( 'extension/expai/edit' ),
            'review_truncated', 'The field is longer than the %count characters the assistant reads: only the beginning was edited.'|i18n( 'extension/expai/edit' ),
            'review_unchecked', 'The structure of the field could not be checked.'|i18n( 'extension/expai/edit' ),
            'lost_cell_1', '%count table cell'|i18n( 'extension/expai/edit' ),
            'lost_cell_n', '%count table cells'|i18n( 'extension/expai/edit' ),
            'lost_th_1', '%count header cell'|i18n( 'extension/expai/edit' ),
            'lost_th_n', '%count header cells'|i18n( 'extension/expai/edit' ),
            'lost_merge_1', '%count merged cell'|i18n( 'extension/expai/edit' ),
            'lost_merge_n', '%count merged cells'|i18n( 'extension/expai/edit' ),
            'lost_embed_1', '%count embedded object'|i18n( 'extension/expai/edit' ),
            'lost_embed_n', '%count embedded objects'|i18n( 'extension/expai/edit' ),
            'lost_table_1', '%count table'|i18n( 'extension/expai/edit' ),
            'lost_table_n', '%count tables'|i18n( 'extension/expai/edit' ),
            'lost_custom_1', '%count custom tag'|i18n( 'extension/expai/edit' ),
            'lost_custom_n', '%count custom tags'|i18n( 'extension/expai/edit' ),
            'lost_anchor_1', '%count anchor'|i18n( 'extension/expai/edit' ),
            'lost_anchor_n', '%count anchors'|i18n( 'extension/expai/edit' ),
            'lost_literal_1', '%count literal block'|i18n( 'extension/expai/edit' ),
            'lost_literal_n', '%count literal blocks'|i18n( 'extension/expai/edit' ),
            'lost_link_1', '%count internal link'|i18n( 'extension/expai/edit' ),
            'lost_link_n', '%count internal links'|i18n( 'extension/expai/edit' ),
            'lost_otherlink_1', '%count link that is not http(s) or mail'|i18n( 'extension/expai/edit' ),
            'lost_otherlink_n', '%count links that are not http(s) or mail'|i18n( 'extension/expai/edit' ),
            'lost_heading_1', '%count heading of level 1, 5 or 6'|i18n( 'extension/expai/edit' ),
            'lost_heading_n', '%count headings of level 1, 5 or 6'|i18n( 'extension/expai/edit' ),
            'lost_attribute_1', '%count formatting attribute'|i18n( 'extension/expai/edit' ),
            'lost_attribute_n', '%count formatting attributes'|i18n( 'extension/expai/edit' )
        )
    )|expai_json}</script>
</section>
{undef $exp_ai_attributes $exp_ai_actions $exp_ai_languages}
{/if}
