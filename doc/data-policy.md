# Data policy: regions and retention

Every provider says where it processes requests and how long it keeps them:

```ini
[Provider_mistral]
Region=eu            # us | eu | local
Retention=30d        # none | zdr | 30d | unknown
TermsCheckedAt=2026-10-11
```

`local` means the data never leaves the server (a local Ollama). `Retention=zdr` means zero data retention is agreed
with the provider; `none` that it keeps nothing (local). Read the provider's terms for your contract and write the day
into `TermsCheckedAt`; expai/models shows "not checked" until you do. The values shipped for the examples are a
starting point, not a legal statement.

## Rules

```ini
[DataPolicy]
DefaultRegions[]                  # empty: every region
SectionRegions[6]=eu;local        # section 6 only to EU or local providers
ClassRegions[contract]=local      # contracts never leave the server
ZeroRetention=disabled
ZeroRetentionSections[]=7         # section 7 only to providers that keep nothing
ZeroRetentionClasses[]
```

The requirement of a content object is the intersection of the default regions, its section's and its class's. A
`local` provider meets every region requirement. Zero retention is met by `Retention=none` or `zdr`. A provider with
an unknown region meets no region requirement. Without any rule everything may go to every active provider.

## Where it is enforced

- **The panel**: before the first request, the node the panel is open on. The editor gets "The data policy keeps
  "<name>" (object <id>) away from <provider>: regions eu or local required; the provider is in region us ..." and
  can choose another model.
- **A change of model in a conversation**: the conversation notes every object whose content a tool returned (and
  the open node). When it goes on with another model - the editor chose one, or the fallback to the site default
  answers - these objects are checked against the new provider's policy and against the request veto
  (`[RequestVeto]` rules, with the new provider, its region and the purpose of the request). An object that cannot be
  loaded any more counts as not allowed (fail closed). When all may go there, the new model gets
  the earlier exchanges as a short summary as before; when one may not, it gets the editor's words only (each
  earlier answer is replaced by a placeholder, drafts by their object:version keys) and the exchange says so. The
  fallback prefers a model whose provider may take everything the conversation read.
- **Every tool**: `get_node`, `list_children`, `get_tree_context`, `search_content`, `create_draft` (the class and the parent's
  section), `update_draft`: a refused object is a tool error the model reads; lists leave such objects out and add
  `withheld_by_data_policy: <n>`; a path shows only the node id of a withheld ancestor.
- **The edit form**: field actions and "Fill the fields" check the object before anything is sent.
- **Translation services** ([translation-providers.md](translation-providers.md)): a machine translation service
  (DeepL, LibreTranslate, ...) has its own `[TranslationProvider_<id>] Region` and `Retention`. The object is checked
  against them when the service is chosen (a refusal tries `[TranslationSettings] Fallback`, else the translation's
  state is `blocked` with the rule), again in the job `expai.translation.run` before anything is sent, and in the field
  action Translate before its request. Such a service gets only the text of the blocks to translate (with
  `SendContext` also the blocks next to them), never other fields, the object's name or the current translation. The
  translation service `llm` follows the rules of its model's provider, checked by the queue as before.
- **MCP**: the in-process bridge leaves withheld nodes out of its results; an external server has its own `Region`
  and `Retention`, checked against the panel node before every call ([mcp.md](mcp.md)).

- **Web tools**: web_fetch sends nothing but the URL to an allowlisted site, so it has no data policy of its own; the
  page it returns is outside data, never site content. web_search sends its query to the search service, so the
  panel node is checked against `[SearchProvider_<id>] Region` and `Retention` (Brave: `us`, `unknown`) before every
  search; a refused node is a tool error. project_history reads the team's own repositories (commits and public
  release notes) and sends nothing about the content ([tools.md](tools.md#web-and-history-tools)).

The usage log stores the region and retention of the provider with every request; expai/log shows them per request
and filters by provider and region, and the dashboard shows a table by provider.

## Images (alt text)

The image an alt text suggestion sends ([alt-text.md](alt-text.md)) is content of its object: the section and class
rules above apply to it, and `[AltTextSettings] ImageRegions[]` and `ImageZeroRetention` can keep images to stricter
rules than text. The model is chosen among the vision models whose provider meets the requirement; when none does, the
editor gets the reason ("The data policy keeps the image of ... away from every model that can look at images: ...")
and nothing is sent. Queued suggestions check it again when the job runs. The image travels inside the request
(base64), never as a link the provider could fetch later, and the request log never keeps the pixels.
