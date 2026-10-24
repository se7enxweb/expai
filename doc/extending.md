# Extending expai

Everything below is added from an extension of your own, by settings and small classes; expai itself is not changed.
The worked examples are the extension in [examples/expaiexample](examples/expaiexample), which was installed and run
for 0.0.1.0 (results below each example). Its class files end in `.php.example` inside expai; rename them when you
copy the example.

## 0. Your extension

```
extension/myai/
    settings/expai.ini.append.php       what you add to expai's lists
    settings/design.ini.append.php      DesignExtensions[], BackendJavaScriptList[] (optional)
    settings/site.ini.append.php        TranslationExtensions[] (optional)
    classes/...                         tools, field action handlers, a provider
    design/admin/templates/...          panel or edit form sections
    design/admin/javascript/...         JavaScript hooks
```

Activate it where expai is active (for example `ActiveAccessExtensions[]=myai` after `ActiveAccessExtensions[]=expai`
in each admin siteaccess), then:

```
php bin/php/ezpgenerateautoloads.php -e
php bin/php/ezcache.php --clear-tag=ini
php bin/php/ezcache.php --clear-id=template,template-override,content,template-block,ezjscore-packer
```

The template caches matter: the panel and edit form templates read expai.ini when they are compiled, and the packed
script bundle lists BackendJavaScriptList. On Exponential Velocity restart it afterwards (`exp:velocity restart`, or
`./console exp:velocity deploy --packer`), because its workers keep loaded classes and settings.

expai's lists are never emptied in expai itself, so your entries are added whatever the extension order. They may
come before expai's own (the settings of access extensions are read in the reverse order of activation); to control
the order, empty and rewrite the list in settings/override.

## 1. A field action, by configuration only

```ini
# settings/expai.ini.append.php
[EditViewSettings]
FieldActions[]=headline

[FieldAction_headline]
Label=Headline
Handler=expAiPromptFieldAction
Datatypes[]
Datatypes[]=ezstring
Input=none
Instruction=Write a short, catchy headline (at most eight words) for this content, based on its other fields.
```

`expAiPromptFieldAction` gives the model the object, the field (name, datatype, format, maximum length), its current
value, the other fields of the form, your `Instruction` and the editor's input, and asks for JSON `{value, note}`; the
value is cleaned for the datatype. `Input` is `none`, `instruction`, `optional_instruction` or `language`. Translate
the label in the context `extension/expai/fieldaction` (translations.md).

*Run for 0.0.1.0*: the edit form showed `Headline, Write, Rewrite, Translate, Suggest` next to the ezstring fields;
Headline on the title of a draft filled a seven word headline with the note "Seven words, plain text. Alternative:
...".

**A handler of your own** implements `expAiFieldAction` (classes/fieldactions/expaifieldaction.php):

```php
interface expAiFieldAction
{
    public function __construct( $name, expAiConfig $config );
    public function name();
    public function label();        // English, translated in extension/expai/fieldaction
    public function datatypes();    // string[]
    public function input();        // none | instruction | optional_instruction | language
    public function run( expAiFieldRequest $request, expAiProvider $provider );   // array( 'value' => ..., 'note' => ..., 'model' => ... )
}
```

`expAiFieldRequest` has `object`, `version`, `language`, `attribute`, `classAttribute`, `datatype`, `value`,
`context` (identifier => value), `instruction`, `targetLanguage`, and helpers `describeObject()`, `describeField()`,
`describeContext()`, `clean( $value )`. Extending `expAiPromptFieldAction` and overriding `userMessage()` or `run()`
is usually enough. Throw `expAiException` with an English message for the editor.

## 2. A tool the assistant can call

```ini
# settings/expai.ini.append.php
[ToolSettings]
Tools[]=count_children

[Tool_count_children]
Class=expAiExampleToolCountChildren
```

```php
<?php
class expAiExampleToolCountChildren extends expAiTool
{
    public function description()
    {
        return 'Counts the children of a node per class (only those you may read). Without node_id: the open node.';
    }

    public function inputSchema()
    {
        return array( 'type' => 'object', 'properties' => array(
            'node_id' => array( 'type' => 'integer', 'description' => 'The node (default: the open node)' ),
        ), 'additionalProperties' => false );
    }

    public function execute( array $input, expAiToolContext $context )
    {
        $node = $context->reader->readableNode( $this->nodeID( $input, $context ) );
        $counts = array();
        foreach ( $context->reader->children( $node, 200 ) as $child )
            $counts[$child['class_identifier']] = ( isset( $counts[$child['class_identifier']] ) ? $counts[$child['class_identifier']] : 0 ) + 1;
        return array( 'node_id' => (int) $node->attribute( 'node_id' ), 'children_per_class' => $counts );
    }

    public function stepText( array $input )
    {
        return 'Counted the children of node %id';
    }

    public function stepArguments( array $input, $result )
    {
        return array( '%id' => isset( $result['node_id'] ) ? $result['node_id'] : '' );
    }
}
```

Rules for tools:

- Run as the current user and use its checks: `$context->reader` (expAiContentReader: `readableNode()`,
  `nodeSummary()`, `fields()`, `children()`, `classDefinition()`, `creatableClasses()`) and `$context->writer`
  (expAiContentWriter: `createDraft()`, `draftFor()`, `writeFields()`) already do.
- A tool that writes returns `true` from `writes()`, calls `$context->checkDraftLimit()` before creating something,
  records what it wrote with `expAiDraftLog::record( array( ... ) )` and `$context->addWritten( $log )`, and never
  publishes. See expAiToolCreateDraft.
- Throw `expAiException` for anything the model should hear about; the message (English) is the error result.
- `stepText()` is the line in the panel (English, translated in `extension/expai/step` with the placeholders of
  `stepArguments()`).
- Conversations keep the tool definitions they started with: a new tool is offered in new conversations.

*Run for 0.0.1.0*: asked "How many children does this folder have, per class? Use the count_children tool.", the panel
showed the step "Counted the children of node 32303" and the answer "... 2 children in this folder, both of class
article".

A tool may also implement `available( expAiConfig $config )` (the registry leaves it out when it returns false),
`external()` (true: the rest of the turn may not write drafts) and `logFields()` (extra fields for the usage log's
tool entry). The web tools get all three from `expAiWebTool`.

**Another web search service** (Bing, SerpAPI, Tavily ...): a class extending `expAiWebSearchProvider` with
`search( $query, $count )` returning a list of `title`, `url`, `snippet` (throw `expAiException` on failure; use
`expAiWebGuard` to resolve, check and pin the endpoint as `expAiWebSearchBrave` does), and a settings group:

```ini
[SearchSettings]
Provider=tavily

[SearchProvider_tavily]
Label=Tavily
Class=myTavilySearchProvider
Endpoint=https://api.tavily.com/search
ApiKey=            # settings/override or an untracked KeyLocations[] location
Region=us
Retention=unknown
```

`isConfigured()` decides whether web_search is offered (default: an `ApiKey` is set).

## 3. A panel section

```ini
# settings/expai.ini.append.php
[PanelSettings]
Sections[]=tips

[PanelSection_tips]
Template=expai/panel/tips.tpl
```

```
{* design/admin/templates/expai/panel/tips.tpl: the variables node and section are given *}
<div class="exp-ai-section exp-ai-tips" data-node-id="{$node.node_id}">
    <h3>{'Tips'|i18n( 'extension/myai' )}</h3>
    <p class="exp-ai-hint">{'Name the class, the number of items and the tone.'|i18n( 'extension/myai' )}</p>
    <p class="exp-ai-hint exp-ai-tips-count"></p>
</div>
```

The panel is part of the cached full view: put nothing user-specific into the template; load it with JavaScript
(below). Edit form sections work the same with `[EditViewSettings] Sections[]` and `[EditSection_<name>]
Template=`; their template has the edit form's variables.

## 4. JavaScript hooks

expai.js defines `window.ExpAi` while the page is parsed and starts the panel, the edit form and the labels on
DOMContentLoaded. BackendJavaScriptList may load your file before or after expai.js (it follows the extension order),
so register on DOMContentLoaded:

```js
document.addEventListener( 'DOMContentLoaded', function () {
    if ( !window.ExpAi ) {
        return;
    }
    // a panel section: runs once when the panel starts
    window.ExpAi.panelSections.register( 'tips', function ( panel ) {
        var box = panel.querySelector( '.exp-ai-tips-count' );
        window.ExpAi.on( 'drafts:changed', function ( e ) {
            if ( box ) {
                box.textContent = e.drafts.length + ' draft(s) in this panel';
            }
        } );
    } );
    // a field action: change the request before it is sent (return false to cancel), or the answer before it is filled in
    window.ExpAi.fieldActions.register( 'headline', {
        after: function ( field, result ) {
            result.value = result.value.replace( /[.!]+$/, '' );
        }
    } );
    // every field filled by Exponential Intelligence
    window.ExpAi.on( 'field:filled', function ( e ) {
        document.documentElement.setAttribute( 'data-exp-ai-last-filled', e.field.attribute.identifier );
    } );
} );
```

| API | Use |
| --- | --- |
| `ExpAi.api( fn, data )` | POST `expai::<fn>` through ezjscore with the form token; a Promise of the content, rejected with `{message, kind}` |
| `ExpAi.on( event, fn )` | `panel:step` `{panel, data}`, `panel:done` `{panel, data}`, `drafts:changed` `{panel, drafts}`, `field:filled` `{field, value, previous}`, `fill:done` `{root, data, count}`, `labels:done` (the labels answer) |
| `ExpAi.fieldAdapters.register( datatype, { get( ctx ), set( ctx, value ) } )` | read and write a datatype's field; `ctx` is `{attribute: {id, identifier, datatype, name}, block}`; `set` returns true when it wrote |
| `ExpAi.fieldActions.register( name, { before( ctx, data ), after( ctx, result ) } )` | hooks around a field action |
| `ExpAi.panelSections.register( name, init( panel ) )` | runs when the panel starts |
| `ExpAi.esc`, `ExpAi.format`, `ExpAi.renderText`, `ExpAi.url` | escaping, `%placeholder` replacement, the panel's text rendering, a URL below the siteaccess root |

*Run for 0.0.1.0*: the section showed "3 draft(s) in this panel" after the panel loaded.

## 5. A provider

expai speaks the Anthropic Messages API shape internally. Another backend translates both ways:

```ini
# settings/override/expai.ini.append.php
[AISettings]
Provider=custom
ProviderClass=expAiExampleEchoProvider
Model=echo
```

```php
<?php
class expAiExampleEchoProvider implements expAiProvider
{
    protected $settings;

    public function __construct( array $settings )   // the [AISettings] values, the key included
    {
        $this->settings = $settings;
    }

    public function providerId()                     // stored in expai_usage_log.provider
    {
        return 'echo';
    }

    public function modelName()
    {
        return isset( $this->settings['Model'] ) ? (string) $this->settings['Model'] : 'echo';
    }

    public function send( array $request )
    {
        $last = end( $request['messages'] );
        $text = is_string( $last['content'] ) ? $last['content'] : 'your message';
        if ( !empty( $request['output_schema'] ) )
            $text = json_encode( array( 'value' => 'Echo: ' . mb_substr( $text, 0, 60 ), 'note' => 'expAiExampleEchoProvider' ) );
        else
            $text = 'Echo: ' . mb_substr( is_string( $text ) ? $text : 'tool results received', 0, 200 );
        $content = array( array( 'type' => 'text', 'text' => $text ) );
        return array( 'content' => $content, 'content_json' => json_encode( $content ), 'stop_reason' => 'end_turn',
                      'model' => $this->modelName(), 'usage' => array() );
    }
}
```

The request: `system`, `messages` (user turns with `content` as a string or a list of blocks; earlier assistant turns
as `content_json`, to be passed on unchanged), optionally `tools`, `tool_choice`, `output_schema` (a JSON schema the
answer text must follow) and `max_tokens`. The answer: `content` (blocks: `text`, `tool_use` with `id`, `name`,
`input`), `content_json`, `stop_reason` (`end_turn`, `tool_use`, `max_tokens`, `refusal`), `model`, `usage`.
Throw `expAiException` on failure (its code is the HTTP status; set `$e->rateLimits` to the rate limit headers if the
API sent any). A provider for a chat completions API maps tools and tool calls to that API's form.

For the usage log and the limits, a provider returns optionally `tokens` — the usage normalized to
`array( 'input' => ..., 'output' => ..., 'cache_write' => ..., 'cache_read' => ... )` (without it the Anthropic
`usage` fields are read) — and `http_status`, `retries` and `rate_limits` (header name => value). `providerId()`
is stored with every row, so the log and the dashboard can tell providers apart. Costs come from the `[Pricing]`
table by model id; add the provider's models there, or they are estimated with the `Default*` prices (shown as `≈`).

*Run for 0.0.1.0*: with the settings above (switched on in-process), a plain request answered "Echo: Hello provider
(model echo, stop_reason end_turn)" and a structured one `{"value":"Echo: Make a headline","note":"expAiExampleEchoProvider"}`.

**Another Anthropic model** needs no code: `[AISettings] Model=<model id>` (and `Effort=` as that model supports).

**An OpenAI-compatible server or another Anthropic-compatible one** needs no code either: a `[Provider_<id>]` group
with `Type=openai_chat` (or `anthropic`) and `[Model_<alias>]` groups ([providers.md](providers.md)). A provider class
of your own in the registry: `[Provider_<id>] Type=custom`, `Class=<your class>`; its constructor receives the
provider's settings merged with the model's (`Model`, `MaxTokens`, `ProviderId`, `ModelAlias`, `ToolUse`,
`Capabilities`). Extending `expAiProviderBase` gives you the settings, the key header, the extra headers, one retry
and the error texts that name the provider. Optional answer fields since the registry: `tokens.reasoning`,
`tokens.reported_cost` (in the currency), `native_json` + `native_for` (your API's own form of the turn, sent back
to you only), `invalid_tool_input` (call id => the arguments that could not be read: the assistant answers that call
with an error).

## 6. The server side of the browser

The ezjscore functions (expAiServerFunctions) are the browser's API: `load`, `history`, `chat`, `reset`, `drafts`,
`keep`, `send`, `discard`, `field`, `fill`, `labels` (architecture.md). To add functions, register your own ezjscore
server class in your extension's ezjscore.ini rather than changing expai's.

## 7. Registries in the extension point survey

The extension point survey of the kernel (Setup > RAD tools > Extension point survey, `setup/radsurvey/(show)/registries`)
counts and checks every entry of the registries expai declares in `settings/rad.ini.append.php` (the format is described
in the kernel's `settings/rad.ini` and in doc/en/us/bc/6.0/rad-extension-surface.md, "Registries declared in rad.ini"). A
provider, tool or field action you add in your own settings appears there at once, and one whose class, template or
block is missing is shown as broken.

| Registry | Read from | Each entry must be |
| --- | --- | --- |
| `expai_provider_types` | `expAiProviderRegistry::TYPES` | a class implementing `expAiProvider` |
| `expai_providers` | `[Provider_<id>] Type=` | a type of `expAiProviderRegistry::TYPES` |
| `expai_models` | `[Model_<provider>.<id>] Id=` | any id |
| `expai_batch_apis` | `expAiBatchApi::SHAPES` | a class extending `expAiBatchApi` |
| `expai_lanes` | `[Lane_<provider>.<lane>] Type=` | `sync` or `batch` |
| `expai_tools` | `[Tool_<name>] Class=` | a class extending `expAiTool` |
| `expai_search_providers` | `[SearchProvider_<name>] Class=` | a class extending `expAiWebSearchProvider` |
| `expai_translation_providers` | `[TranslationProvider_<name>] Class=` | a class implementing `expAiTranslationProvider` |
| `expai_mcp_servers` | `[McpSettings] Servers[]` | a block `[McpServer_<name>]` |
| `expai_mcp_bridge_tools` | `[McpBridge] Tools[]` | a tool of `expMcpBridge` (listed, not added: counted by expmcp) |
| `expai_service_tools` | `[ServiceTools] Services[]`, `Domains[]` | any (listed, not added: counted as remote services) |
| `expai_chains` | `[ChainSettings] Chains[]` | a block `[Chain_<name>]` |
| `expai_chain_steps` | `[ChainStep_<chain>.<step>] Type=` | a type of `expAiChain::TYPES` |
| `expai_field_actions` | `[FieldAction_<name>] Handler=` | a class implementing `expAiFieldAction` |
| `expai_presets` | `[FieldAction_preset_<name>]`, `[FieldAction_improve_<name>] Handler=` | a class implementing `expAiFieldAction` |
| `expai_appliers` | `[QueueSettings] Appliers[<kind>]` | a class implementing `expAiRequestApplier` |
| `expai_segmenters` | `[DiffSettings] Segmenters[<datatype>]`, `FallbackSegmenter` | a class implementing `expAiSegmenter` |
| `expai_panes` | `[PanelSection_<name>]`, `[EditSection_<name>]`, `[VectorPane_<name>] Template=` | a template some design has |
| `expai_history_repositories` | `[HistorySettings] Repositories[]` | a block `[HistoryRepository_<name>]` |
| `expai_data_policy` | `[DataPolicy] SectionRegions[]`, `ClassRegions[]`, `ZeroRetentionSections[]`, `ZeroRetentionClasses[]` | any |
| `expai_request_veto` | `[RequestVeto] Rules[]` | a block `[RequestVeto_<rule>]` |

The job types of expai (`settings/expqueue.ini.append.php`) are counted by expqueue's registry `expqueue_job_types`, its
listener of `package/install/drafts` by the kernel's registry `eventlisteners`, its server functions by the kernel's
ezjscore registry, and its policy functions with their limitations in the survey's modules section.

## 8. A translation service

A machine translation service of your own (doc/translation-providers.md) is one class and one group. Extending
`expAiTranslationProviderBase` gives you the settings, the key, the endpoint check, the language codes
(`expAiTranslationLocale`), the markup of the placeholders and its check (`expAiTranslationMarkup`), batching,
retries of 429 and 5xx, the cost from `PricePerMChars` and the data policy from `Region` and `Retention`. You write
one request:

```php
class myTranslationProvider extends expAiTranslationProviderBase
{
    protected function defaultEndpoint()
    {
        return 'https://mt.example.org/v1/translate';
    }

    protected function sendBatch( array $texts, $format, $context, expAiTranslationRequest $request )
    {
        list( $status, $body, $headers, $retries ) = $this->post( $this->endpoint(), array( 'Authorization: Bearer ' . $this->setting( 'ApiKey' ) ),
            json_encode( array( 'texts' => $texts, 'from' => $this->sourceCode( $request->source ), 'to' => $this->targetCode( $request->target ),
                                'markup' => $format === 'html' ? 'html' : 'xml' ) ) );
        if ( $status < 200 || $status >= 300 )
            throw $this->failure( $status, $body, $headers );
        $data = json_decode( $body, true );
        return array( 'texts' => $data['translations'], 'billed' => null, 'status' => $status, 'retries' => $retries, 'engine' => 'mymt' );
    }
}
```

```ini
# settings/expai.ini.append.php of your extension (the key in settings/override)
[TranslationProvider_mymt]
Class=myTranslationProvider
Label=My MT
Region=eu
Retention=none
PricePerMChars=10
LanguageRules=generic

[TranslationSettings]
ProviderFor[*:*:full]=mymt
```

`$texts` carry the placeholders as `<x i="1">...</x>` and `<x i="2"/>` (override `markupStyle()` to get
`expAiTranslationMarkup::STYLE_HTML` instead); the base turns your answers back into the coded form and refuses a text
whose placeholders changed. `$context` is the text around the block when `SendContext` is enabled. `billed` is the
number of characters the service billed, or null to price the characters sent. Throw `expAiTranslationException` for
your own errors: `transient()` (429, 5xx, timeouts) lets the queue retry, everything else fails the job. A service
that is not a machine translation (another model gateway, a human translation agency with a queue of its own)
implements `expAiTranslationProvider` directly. The registry `expai_translation_providers` lists it in the extension
point survey; `php bin/php/ezpgenerateautoloads.php -e` after adding the class.
