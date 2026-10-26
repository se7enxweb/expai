<?php /* #?ini charset="utf-8"?

# The registries of Exponential Intelligence, counted and checked by the extension point survey
# (Setup > RAD tools > Extension point survey, setup/radsurvey/(show)/registries). What a block holds is
# described in settings/rad.ini of the kernel; doc/extending.md "Registries in the extension point survey".

[RegistrySettings]
Registries[]=expai_provider_types
Registries[]=expai_providers
Registries[]=expai_models
Registries[]=expai_batch_apis
Registries[]=expai_lanes
Registries[]=expai_tools
Registries[]=expai_search_providers
Registries[]=expai_translation_providers
Registries[]=expai_mcp_servers
Registries[]=expai_mcp_bridge_tools
Registries[]=expai_service_tools
Registries[]=expai_chains
Registries[]=expai_chain_steps
Registries[]=expai_field_actions
Registries[]=expai_presets
Registries[]=expai_appliers
Registries[]=expai_segmenters
Registries[]=expai_panes
Registries[]=expai_history_repositories
Registries[]=expai_data_policy
Registries[]=expai_request_veto

[Registry_expai_provider_types]
Title=AI provider types
Description=The adapters a provider block can use (Type=): each a class implementing expAiProvider.
Doc=extension/expai/doc/providers.md
TranslationContext=extension/expai/rad
List=expAiProviderRegistry::TYPES
Implements=expAiProvider

[Registry_expai_providers]
Title=AI providers
Description=The providers of expai.ini [Provider_<id>], each with a Type the provider registry knows.
Doc=extension/expai/doc/providers.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=Provider_*
Entries[Type]=in:expAiProviderRegistry::TYPES

[Registry_expai_models]
Title=AI models
Description=The models of expai.ini [Model_<provider>.<id>].
Doc=extension/expai/doc/providers.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=Model_*
Entries[Id]=name

[Registry_expai_batch_apis]
Title=AI batch APIs
Description=The batch interfaces a batch lane can use (BatchApi=): each a class extending expAiBatchApi.
Doc=extension/expai/doc/lanes.md
TranslationContext=extension/expai/rad
List=expAiBatchApi::SHAPES
Implements=expAiBatchApi

[Registry_expai_lanes]
Title=AI lanes
Description=The lanes of expai.ini [Lane_<provider>.<lane>]: sync or batch.
Doc=extension/expai/doc/lanes.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=Lane_*
Entries[Type]=one-of:sync,batch

[Registry_expai_tools]
Title=AI tools
Description=The tools a model can call, content, web and history tools alike (expai.ini [Tool_<name>] Class=, extending expAiTool).
Doc=extension/expai/doc/tools.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=Tool_*
Entries[Class]=expAiTool

[Registry_expai_search_providers]
Title=AI web search providers
Description=The search services of the tool web_search (expai.ini [SearchProvider_<name>] Class=, extending expAiWebSearchProvider).
Doc=extension/expai/doc/tools.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=SearchProvider_*
Entries[Class]=expAiWebSearchProvider

[Registry_expai_translation_providers]
Title=AI translation services
Description=The translation services of the translation sync and the field action Translate (expai.ini [TranslationProvider_<name>] Class=, implementing expAiTranslationProvider; chosen by [TranslationSettings]).
Doc=extension/expai/doc/translation-providers.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=TranslationProvider_*
Entries[Class]=expAiTranslationProvider

[Registry_expai_mcp_servers]
Title=AI MCP servers
Description=The remote MCP servers whose tools a model can call (expai.ini [McpSettings] Servers[], each with its [McpServer_<name>]).
Doc=extension/expai/doc/mcp.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=McpSettings
Entries[Servers]=section:McpServer_

[Registry_expai_mcp_bridge_tools]
Title=AI tools taken from the MCP bridge
Description=The tools of expmcp that a model can call in process (expai.ini [McpBridge] Tools[]); counted as tools of expmcp, so not added again.
Doc=extension/expai/doc/mcp.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=McpBridge
Entries[Tools]=in:expMcpBridge::toolNames
InTotal=disabled

[Registry_expai_service_tools]
Title=AI service tools
Description=The remote services of expservices a model can call (expai.ini [ServiceTools] Services[] and Domains[]); counted as remote services, so not added again.
Doc=extension/expai/doc/service-tools.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=ServiceTools
Entries[Services]=name
Entries[Domains]=name
InTotal=disabled

[Registry_expai_chains]
Title=AI chains
Description=The chains of expai.ini [ChainSettings] Chains[], each with its [Chain_<name>].
Doc=extension/expai/doc/chains.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=ChainSettings
Entries[Chains]=section:Chain_

[Registry_expai_chain_steps]
Title=AI chain steps
Description=The steps of the chains (expai.ini [ChainStep_<chain>.<step>]), each of a type the chain engine runs.
Doc=extension/expai/doc/chains.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=ChainStep_*
Entries[Type]=in:expAiChain::TYPES

[Registry_expai_field_actions]
Title=AI field actions
Description=The actions of the edit view's fields (expai.ini [FieldAction_<name>] Handler=, implementing expAiFieldAction).
Doc=extension/expai/doc/edit-view-integration.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=/^FieldAction_(?!preset_|improve_)(.+)$/
Entries[Handler]=expAiFieldAction

[Registry_expai_presets]
Title=AI presets
Description=The presets and the improve actions of the edit view (expai.ini [FieldAction_preset_<name>] and [FieldAction_improve_<name>]).
Doc=extension/expai/doc/presets.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Sections[]
Sections[]=FieldAction_preset_*
Sections[]=FieldAction_improve_*
Entries[Handler]=expAiFieldAction

[Registry_expai_appliers]
Title=AI request appliers
Description=What applies the answer of a queued request (expai.ini [QueueSettings] Appliers[<kind>]=, implementing expAiRequestApplier).
Doc=extension/expai/doc/extending.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=QueueSettings
Entries[Appliers]=expAiRequestApplier

[Registry_expai_segmenters]
Title=AI diff segmenters
Description=What splits a field of a datatype into segments for the diff (expai.ini [DiffSettings] Segmenters[<datatype>]= and FallbackSegmenter=, implementing expAiSegmenter).
Doc=extension/expai/doc/diff.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=DiffSettings
Entries[Segmenters]=expAiSegmenter
Entries[FallbackSegmenter]=expAiSegmenter

[Registry_expai_panes]
Title=AI panel sections and panes
Description=The templates of the panel sections, the edit view sections and the Vector panes (expai.ini [PanelSection_<name>], [EditSection_<name>], [VectorPane_<name>] Template=).
Doc=extension/expai/doc/full-view-panel.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Sections[]
Sections[]=PanelSection_*
Sections[]=EditSection_*
Sections[]=VectorPane_*
Entries[Template]=template

[Registry_expai_history_repositories]
Title=AI history repositories
Description=The repositories the tool project_history reads (expai.ini [HistorySettings] Repositories[], each with its [HistoryRepository_<name>]).
Doc=extension/expai/doc/tools.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=HistorySettings
Entries[Repositories]=section:HistoryRepository_

[Registry_expai_data_policy]
Title=AI data policy rules
Description=The regions and zero retention rules of sections and classes (expai.ini [DataPolicy]).
Doc=extension/expai/doc/data-policy.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=DataPolicy
Entries[SectionRegions]=name
Entries[ClassRegions]=name
Entries[ZeroRetentionSections]=name
Entries[ZeroRetentionClasses]=name

[Registry_expai_request_veto]
Title=AI request veto rules
Description=The rules that refuse a request (expai.ini [RequestVeto] Rules[], each with its [RequestVeto_<rule>]).
Doc=extension/expai/doc/switch-and-veto.md
TranslationContext=extension/expai/rad
Ini=expai.ini
Section=RequestVeto
Entries[Rules]=section:RequestVeto_

*/ ?>
