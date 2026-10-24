{* The bar of field actions of one field of the content edit form, added through content.ini [EditSettings]
   AttributeTemplates[] (content/edit_attribute.tpl of the admin designs includes it in every field's block, after the
   label). Drawn with the page, so the form does not move down when expai.js starts: expai.js takes the bar on (the
   buttons are disabled until then), the presets join it. The same conditions as content/edit/expai.tpl and expai.js:
   the datatype is writable ([ToolSettings] WritableDatatypes[]) and offered by at least one field action. *}
{if and( is_set( $edit_version ), is_set( $attribute ),
         fetch( 'expai', 'switched_on' ),
         array( 'true', 'enabled' )|contains( ezini( 'EditViewSettings', 'Enabled', 'expai.ini' )|downcase ),
         ezini( 'ToolSettings', 'WritableDatatypes', 'expai.ini' )|contains( $attribute.data_type_string ),
         fetch( 'user', 'has_access_to', hash( 'module', 'expai', 'function', 'write' ) ) )}
{def $exp_ai_bar_actions = array()
     $exp_ai_unavailable = fetch( 'expai', 'unavailable_field_actions' )}
{foreach ezini( 'EditViewSettings', 'FieldActions', 'expai.ini' ) as $exp_ai_action}
    {if and( ezini( concat( 'FieldAction_', $exp_ai_action ), 'Datatypes', 'expai.ini' )|contains( $attribute.data_type_string ), $exp_ai_unavailable|contains( $exp_ai_action )|not )}
        {set $exp_ai_bar_actions = $exp_ai_bar_actions|append( $exp_ai_action )}
    {/if}
{/foreach}
{if $exp_ai_bar_actions|count}
<div class="exp-ai exp-ai-field-bar" data-exp-ai-attribute="{$attribute.id}"><span class="exp-ai-field-title" title="{'Exponential Intelligence'|i18n( 'extension/expai/edit' )|wash}"><span class="exp-ai-mark" aria-hidden="true"></span>{'Exponential Intelligence'|i18n( 'extension/expai/edit' )|wash}</span>{foreach $exp_ai_bar_actions as $exp_ai_action}<button type="button" class="exp-ai-chip" data-action="{$exp_ai_action|wash}" disabled="disabled">{ezini( concat( 'FieldAction_', $exp_ai_action ), 'Label', 'expai.ini' )|i18n( 'extension/expai/fieldaction' )|wash}</button>{/foreach}<div class="exp-ai-field-form" hidden="hidden"></div><p class="exp-ai-field-note" aria-live="polite"></p></div>
{/if}
{undef $exp_ai_bar_actions $exp_ai_unavailable}
{/if}
