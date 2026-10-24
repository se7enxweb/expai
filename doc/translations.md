# Translations

expai ships `translations/eng-US`, `translations/ger-DE` and `translations/untranslated` (every message, untranslated,
as the start for a new language). site.ini `[RegionalSettings] TranslationExtensions[]=expai` makes them active.

| Context | Where |
| --- | --- |
| `extension/expai/tab` | the tab label "Intelligence" |
| `extension/expai/panel` | the panel |
| `extension/expai/step` | the step lines of the panel (from the tools' `stepText()`) |
| `extension/expai/label` | the label and the note in version views and approval items |
| `extension/expai/edit` | the edit form area and buttons |
| `extension/expai/fieldaction` | the field action labels (`[FieldAction_<name>] Label`) |
| `extension/expai/dashboard` | the dashboard page, the block, the browser description |
| `extension/expai/log` | the log page |
| `extension/expai/error` | messages of `expAiException` shown to the editor |
| `extension/expai/usage`, `extension/expai/limits`, `extension/expai/links` | figures, limits and the icon links of the dashboard and the log |
| `extension/expai/model`, `extension/expai/models` | the model picker; the models and providers page |
| `extension/expai/threads`, `extension/expai/vector`, `extension/expai/keyboard` | threads, Vector, the command palette and the shortcuts |
| `extension/expai/chain`, `extension/expai/translation` | AI chains; translation sync and translation services (the Translations section) |
| `extension/expai/budget` | budgets and lanes |
| `extension/expai/preset`, `extension/expai/alttext`, `extension/expai/summary` | presets and the Improve menu; alt text; the "EI: Summary" page |
| `extension/expai/help` | the "?" links and the dashboard's Help entry |
| `design/admin/parts/setup/menu` | the Setup menu entries (EI: Summary, EI: Models, EI: Budgets, Job queue) |
| `design/admin/parts/my/menu` | the Dashboard menu entries (Exponential Intelligence, EI: Vector, EI: Chains, EI: Log, EI: Help) |
| `design/admin/parts/design/menu` | the Design menu entry (Missing alt text) |

Not translated, in every language: "Exponential Intelligence" and the names of providers and models. German: the tab is
"Intelligenz".

Texts the browser shows are rendered into the page by the templates (JSON blocks) or come translated from the server
(error messages, step lines, the label texts of `expai::labels`), so expai.js has no texts of its own.

## A new language

1. Copy `translations/untranslated/translation.ts` to `translations/<locale>/translation.ts` (e.g. `fre-FR`), set
   `language="fr_FR"` and fill every `<translation>`.
2. Clear the translation cache: `php bin/php/ezcache.php --clear-id=translation`.
3. Open the full view, the edit form, the dashboard, Vector and the log in a siteaccess with that locale; the admin's debug
   output lists any "Missing translation" for them.

The model answers in the language the editor writes in, whatever the interface language.

The help center shows the extension's documentation in English in every interface language.
