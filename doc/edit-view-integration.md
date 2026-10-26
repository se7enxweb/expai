# The edit form (content/edit)

## The hook

The edit form templates of the admin designs (admin, admin3, admin4; admin4l and the editor design use these)
include every template of content.ini `[EditSettings] AdditionalTemplates[]` above the fields. ezautosave uses this
hook for its autosave; expai adds `content/edit/expai.tpl` the same way:

```ini
[EditSettings]
AdditionalTemplates[]=content/edit/expai.tpl
```

The template has the edit form's variables (`$object`, `$edit_version`, `$edit_language`, `$content_attributes`)
and draws, for users with `expai/write` while `[AISettings] Enabled` and `[EditViewSettings] Enabled` are on:

- the "Exponential Intelligence" area (closed until **Show**; the choice is remembered in localStorage
  `exp-ai-edit-open`), with the sections of `[EditViewSettings] Sections[]` (by default `assistant`: "Fill the
  fields");
- a JSON block (`.exp-ai-edit-config`) with the object id, version, language, the attributes (id, identifier,
  datatype, name), the field actions, the writable datatypes, the site's languages and the translated texts.

No edit form template is overridden. The bar of field actions is drawn with the page through the kernel's second hook,
content.ini `[EditSettings] AttributeTemplates[]` (Exponential 6.0.15 and later), which includes
`content/edit/expai_attribute.tpl` in every field's block, after its label (first in the block when the field has its
own fieldset or is being translated): the bar is there at the first paint, so the form does not move down when
expai.js starts (no layout shift). Its buttons are disabled until expai.js takes the bar on. On an older kernel, or a
design whose `edit_attribute.tpl` has no such hook, expai.js draws the bars itself as before.

```ini
[EditSettings]
AttributeTemplates[]=content/edit/expai_attribute.tpl
```

This differs from the older approach of ezgoogletranslate (archived), which overrode `content/edit.tpl`,
`content/edit_attribute.tpl` and datatype edit templates per design to place "translate" links: expai needs no copy
of any template, so it keeps working when a design's edit form changes.

## The field actions

For each attribute whose datatype is writable (`[ToolSettings] WritableDatatypes[]`) and offered by at least one
action (`[FieldAction_<name>] Datatypes[]`), expai.js finds the attribute's block (`.ezcca-edit-<identifier>` inside
the form, which every admin design renders) and puts a bar of buttons after its label:

```
* Exponential Intelligence  [Write] [Rewrite] [Shorten] [Translate]
```

A button with `Input=none` runs at once; the others open a small input (an instruction, or a language select) with Go
and Cancel. The request `expai::field` sends the object, version, language, attribute id, action, the field's current
value (as the form has it now), the editor's input and the other fields' current values (as context, at most about 30
KB). The server checks that the version is the editor's own draft and editable, that the attribute belongs to it and
that the action is offered for its datatype, runs the action's handler and returns `{value, note}`. expai.js puts the
value into the field, highlights it, shows the note and an **Undo** link, unless the answer would lose structure of
the field or the field changed while the answer was written (see [below](#structure-is-never-lost-silently)).

The answer also carries `record` (`expAiEditForm::requestRecord()`: `usage_id`, `log` = `expai/log/(request)/<id>`,
and `job` = `expqueue/job/<uuid>` when the request ran as a job and the user has `expqueue/view`). The note shows it
as **View record** (and **View job**), opening in a new tab. A failure after the request was sent answers
`{error, record}` instead of an ezjscore error, so the link is there next to the error too; a failure before anything
was sent (no row) stays an ezjscore error. Presets and the Improve menu (`expaipreset::preset`) do the same.

**Translate** (`[FieldAction_translate] Handler=expAiTranslateFieldAction`) uses the translation service of the pair
(the draft's language into the chosen one, `[TranslationSettings]` purpose `field`,
[translation-providers.md](translation-providers.md)). With `llm` it is the prompt above, unchanged. With a machine
translation service the request goes there directly: its data policy, the master switch, the veto and the cost limits
apply, and only the field's value is sent (rich text as the editor's HTML, plain text line by line, keywords one by
one), not the other fields. The record links to its usage row like any other. Without any translation service for
fields the button is left out (`fetch( 'expai', 'unavailable_field_actions' )`).

The version is marked in the log (action `field` or `fill`), so it carries the label.

## Getting values in and out of the fields

expai.js reads and writes a field through an adapter per datatype (`ExpAi.fieldAdapters`):

| Datatype | Read | Write |
| --- | --- | --- |
| ezxmltext with Tiptap (exp_oe_tiptap) | `ExpOETiptap.get( textarea ).getHTML()` | `instance.editor.commands.setContent( html )`, then `instance.dirty = true; instance.sync()` (the textarea gets the HTML and an `input` event) |
| ezxmltext with the Online Editor (TinyMCE) | `tinyMCE.get( id ).getContent()` | `setContent( html )`, `save()`, `nodeChanged()`, `change` event |
| ezxmltext without an editor | the textarea | the textarea (the simplified XML input accepts the HTML subset) |
| every other datatype | the input or textarea in the block whose name ends with `_<attribute id>` | sets its value and fires `input` and `change` |

The `input`/`change` events are what ezautosave watches, so a filled field is autosaved like a typed one. Rich text
is the HTML subset `p, h2, h3, h4, strong, em, ul, ol, li, a href` (cleaned on the server).

## Structure is never lost silently

Writing an answer replaces the whole field (`setContent()`), and the answer only has the subset above. Everything
else the field holds would be gone, and ezautosave would store the loss a moment later. So before an answer is put
into a field, expai.js (`ExpAi.guardedApply()`) checks two things:

- **Structure** (ezxmltext only): `expai_structure.js` (`window.ExpAiStructure`, loaded before expai.js through
  `BackendJavaScriptList[]`) counts the structure-bearing elements of the field as it is now and of the answer:

  | Kind | Counted |
  | --- | --- |
  | embedded objects | `<img>`, elements with `id="eZObject_<id>"`/`eZNode_<id>`, `ezoeItemNonEditable`/`ezoeItemContentType*` embeds of both editors, `data-ezelement="ezembed*"`, `<embed>`/`<embed-inline>` of the simplified XML, `<object>`, `<iframe>`, `<video>`, `<audio>`; the preview inside an embed is not counted |
  | tables | `<table>` |
  | table cells | `<td>`, `<th>` (a row or a cell less) |
  | header cells | `<th>` (a header cell turned into a data cell) |
  | merged cells | what `colspan`/`rowspan` above 1 span beyond their own cell (merged cells undone) |
  | custom tags | `class="ezoeItemCustomTag ..."`, `type="custom"`, `data-ezelement="eztemplate*"`, `<custom>`, `<sub>`, `<sup>` |
  | anchors | `<a name>`/`<a id>` without `href`, `<anchor>`, `<header anchor_name>` |
  | literals | `<pre>`, `<literal>` |
  | internal links | links to `ezobject://`, `eznode://`, a path on the site (`/about`), or with `object_id`/`node_id` |
  | other links | external links the answer cannot keep either: anything but `http(s)://` and `mailto:` (`tel:`, `ftp:`, `www.example.com`, protocol-relative ones, `#fragment` links); `expAiText::sanitizeHtml()` removes exactly these |
  | headings | `<h1>`, `<h5>`, `<h6>` (the answer has `<h2>` to `<h4>` only) |
  | formatting attributes | each `align`, `valign`, `width`, `border`, `cellpadding`, `cellspacing`, `customattributes`, `target`, `title`, `view`, `style` and `custom:*` attribute, a class of its own (the editors' `ezoe*`/`mce*` classes do not count) and the `id` of a link, on any element but an embed: blocks, tables and cells, links (external ones too) and anchors |

  It reads the HTML of the Online Editor (TinyMCE) and of Tiptap (exp_oe_tiptap) and the simplified XML of a textarea
  without an editor alike, with a small tag scanner (no DOM). Comments, CDATA sections and the content of `script`,
  `style`, `pre`/`literal` and of embeds (the editor's preview) are not read as markup. An embed or literal left open
  does not swallow the rest: it ends with the element around it, and when it is still open at the end the value is
  read once more with a skipped part also ending where the next block starts. When the answer has fewer of any kind,
  it is not written. When `expai_structure.js` is not loaded, every answer to a rich text field is shown for review
  ("The structure of the field could not be checked.") and the console says so once.
- **A change meanwhile** (every datatype, also the parts of an xrowmetadata field): the value the request sent is kept;
  when the field differs once the answer is there (white space between tags aside), the answer is not written.
- **A cut value** (every datatype): the model gets the first `[EditViewSettings] MaxFieldLength` characters of the
  field (default 20000, 1000 to 200000; "Fill the fields" sends 6000 per field). When the field was longer, the answer
  was made from its beginning only and would replace the whole field, so it is not written ("The field is longer than
  the %count characters the assistant reads: only the beginning was edited.", `review_truncated`).

Instead of writing, the field's bar shows a review: the answer (as plain text, rendered safely), the reason ("2
embedded objects, 1 table would be lost.", "The field changed while the answer was written.") and the buttons **Apply
anyway**, **Keep the original** and, where the browser offers the clipboard API, **Copy text**. Only **Apply anyway**
writes; it reads the field again at that moment, so **Undo** restores what was there right before. The note says
"Nothing was written into the field yet". A new request in the same field removes an open review.

The same check runs for the field actions, the presets and the Improve menu (`expai_presets.js`) and "Fill the
fields": a field of a fill that needs a review gets it in its bar (or in a box of its own after the label when the
field has no bar), and the note of the fill counts the answers not written. The strings come from the edit form's
configuration (`content/edit/expai.tpl`, context `extension/expai/edit`): `review_*`, `apply_anyway`,
`keep_original`, `copy_text`, `copied`, `copy_failed`, `kept`, `review_unchecked`, `review_truncated` and `lost_<kind>_1`/`lost_<kind>_n` per kind.

An extension that writes answers into fields itself calls
`ExpAi.guardedApply( { ctx, adapter, value, sent, host, strings, apply, kept, limit } )`; it returns
`{written, review}`. `limit` is the number of characters the request sent of the field (default
`ExpAi.limits.fieldLength`).

### Editors with an assistant of their own

An editor whose own assistant keeps formulas, embeds and tables does that job better than the field actions, which
only know the subset above and would ask for a review of everything else. Above an ezxmltext field whose editor has
one, the field actions and the Improve menu are hidden; the presets of the field stay, and the bar goes only when no
preset is left in it. Tiptap (exp_oe_tiptap) counts when its AI panel is switched on (`options.ai.enabled`). The editor
is read live (a `MutationObserver` on the field's block): an editor that starts later is picked up, and switching to
TinyMCE or removing the editor brings the buttons back. Other editors register a detector that gets the field's
textarea:

```js
ExpAi.ownAIEditors.register( 'myeditor', function ( textarea ) { return !!MyEditor.aiFor( textarea ); } );
```

`[EditViewSettings] HideActionsWithEditorAI=false` shows the field actions above every field, whatever the editor.

## Presets and the Improve menu

Next to the field actions, `expai_presets.js` adds the presets of the object's class ("Suggest title", "Write summary",
"Meta description", "Keywords", also on the parts of an xrowmetadata field) and one "Improve" menu per text field, and
the line "Defaults in use" above the fields. They fill the form the same way, with Undo. See [presets.md](presets.md).

## "Fill the fields"

`expai::fill` sends the brief and the form's current values; the model gets the writable fields of the class (with
format hints, required flags, maximum lengths) and answers `{fields: [{identifier, value}], note}`; values are cleaned
per datatype and put into the form. **Undo** restores all of them.

## Designs

Tested in the admin4l, admin4 and admin3 designs and the editor siteaccess (the admin design has the same hook and
blocks, see design/admin/templates/content/edit.tpl and edit_attribute.tpl): the hook and the
`.ezcca-edit-<identifier>` blocks are the same in all of them. A design whose edit form does not include
`AdditionalTemplates[]` gets neither the area nor the buttons.
