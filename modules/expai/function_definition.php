<?php
/**
 * Template fetch functions of expai:
 *
 *   fetch( 'expai', 'my_drafts', hash( 'limit', 5 ) )        the current user's log rows (expAiDashboard::myDrafts())
 *   fetch( 'expai', 'my_draft_count' )                       how many there are
 *   fetch( 'expai', 'waiting_for_my_approval' )              AI-written versions waiting for the user's approval
 *   fetch( 'expai', 'models' )                               the models the user may choose, the default and the current choice
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$FunctionList = array();
$FunctionList['my_drafts'] = array( 'name' => 'my_drafts',
                                    'operation_types' => array( 'read' ),
                                    'call_method' => array( 'class' => 'expAiFunctionCollection', 'method' => 'fetchMyDrafts' ),
                                    'parameter_type' => 'standard',
                                    'parameters' => array( array( 'name' => 'offset', 'type' => 'integer', 'required' => false, 'default' => 0 ),
                                                           array( 'name' => 'limit', 'type' => 'integer', 'required' => false, 'default' => 10 ) ) );
$FunctionList['my_draft_count'] = array( 'name' => 'my_draft_count',
                                         'operation_types' => array( 'read' ),
                                         'call_method' => array( 'class' => 'expAiFunctionCollection', 'method' => 'fetchMyDraftCount' ),
                                         'parameter_type' => 'standard',
                                         'parameters' => array() );
$FunctionList['waiting_for_my_approval'] = array( 'name' => 'waiting_for_my_approval',
                                                  'operation_types' => array( 'read' ),
                                                  'call_method' => array( 'class' => 'expAiFunctionCollection', 'method' => 'fetchWaitingForMyApproval' ),
                                                  'parameter_type' => 'standard',
                                                  'parameters' => array() );
// budgets (classes/budget/): fetch( 'expai', 'budget_status' ) the monthly site budget of queued requests (users with expai/log)
$FunctionList['budget_status'] = array( 'name' => 'budget_status',
                                        'operation_types' => array( 'read' ),
                                        'call_method' => array( 'class' => 'expAiBudgetFunctionCollection', 'method' => 'fetchStatus' ),
                                        'parameter_type' => 'standard',
                                        'parameters' => array() );
// AI chains (classes/chain/): fetch( 'expai', 'chains' ) the chains the user may start; fetch( 'expai', 'chain_runs',
// hash( 'limit', 5, 'node_id', 0 ) ) the user's own latest runs and how many wait for an editor
$FunctionList['chains'] = array( 'name' => 'chains',
                                 'operation_types' => array( 'read' ),
                                 'call_method' => array( 'class' => 'expAiChainFunctionCollection', 'method' => 'fetchChains' ),
                                 'parameter_type' => 'standard',
                                 'parameters' => array() );
$FunctionList['chain_runs'] = array( 'name' => 'chain_runs',
                                     'operation_types' => array( 'read' ),
                                     'call_method' => array( 'class' => 'expAiChainFunctionCollection', 'method' => 'fetchRuns' ),
                                     'parameter_type' => 'standard',
                                     'parameters' => array( array( 'name' => 'limit', 'type' => 'integer', 'required' => false, 'default' => 5 ),
                                                            array( 'name' => 'node_id', 'type' => 'integer', 'required' => false, 'default' => 0 ) ) );
$FunctionList['models'] = array( 'name' => 'models',
                                 'operation_types' => array( 'read' ),
                                 'call_method' => array( 'class' => 'expAiFunctionCollection', 'method' => 'fetchModels' ),
                                 'parameter_type' => 'standard',
                                 'parameters' => array() );
// threads (classes/vector/): fetch( 'expai', 'my_threads', hash( 'limit', 6 ) ) the current user's own threads
$FunctionList['my_threads'] = array( 'name' => 'my_threads',
                                     'operation_types' => array( 'read' ),
                                     'call_method' => array( 'class' => 'expAiThreadFunctionCollection', 'method' => 'fetchMyThreads' ),
                                     'parameter_type' => 'standard',
                                     'parameters' => array( array( 'name' => 'limit', 'type' => 'integer', 'required' => false, 'default' => 6 ) ) );
// the master switch (#370 M2, doc/switch-and-veto.md): fetch( 'expai', 'switched_on' ) [AISettings] Enabled and
// DisabledSiteAccesses[] for the current siteaccess
$FunctionList['switched_on'] = array( 'name' => 'switched_on',
                                      'operation_types' => array( 'read' ),
                                      'call_method' => array( 'class' => 'expAiGate', 'method' => 'fetchSwitchedOn' ),
                                      'parameter_type' => 'standard',
                                      'parameters' => array() );

// the field actions that cannot be used at all (doc/translation-providers.md): fetch( 'expai', 'unavailable_field_actions' ),
// e.g. Translate when no translation service is installed; the edit form leaves them out
$FunctionList['unavailable_field_actions'] = array( 'name' => 'unavailable_field_actions',
                                                    'operation_types' => array( 'read' ),
                                                    'call_method' => array( 'class' => 'expAiFunctionCollection', 'method' => 'fetchUnavailableFieldActions' ),
                                                    'parameter_type' => 'standard',
                                                    'parameters' => array() );

// alt text for images (#370 M5 alt text, classes/alttext/): fetch( 'expai', 'alt_text_model', hash( 'object', $object ) )
// the vision model the "Write alt text" button uses for the object's images, or why there is none
$FunctionList['alt_text_model'] = array( 'name' => 'alt_text_model',
                                         'operation_types' => array( 'read' ),
                                         'call_method' => array( 'class' => 'expAiAltTextFunctionCollection', 'method' => 'fetchModel' ),
                                         'parameter_type' => 'standard',
                                         'parameters' => array( array( 'name' => 'object', 'type' => 'object', 'required' => true ) ) );
// fetch( 'expai', 'alt_text_required', hash( 'attribute', $attribute ) ): whether the image field refuses an empty alt
// text (the class attribute option "Alternative text is required"; false on a kernel without it)
$FunctionList['alt_text_required'] = array( 'name' => 'alt_text_required',
                                            'operation_types' => array( 'read' ),
                                            'call_method' => array( 'class' => 'expAiAltTextFunctionCollection', 'method' => 'fetchAltTextRequired' ),
                                            'parameter_type' => 'standard',
                                            'parameters' => array( array( 'name' => 'attribute', 'type' => 'object', 'required' => true ) ) );

// editor presets and the Improve menu (classes/fieldactions/expaieditpresets.php): fetch( 'expai', 'edit_presets',
// hash( 'object', $object, 'attributes', $content_attributes ) ) the preset buttons, the Improve menu and the defaults line
$FunctionList['edit_presets'] = array( 'name' => 'edit_presets',
                                       'operation_types' => array( 'read' ),
                                       'call_method' => array( 'class' => 'expAiPresetFunctionCollection', 'method' => 'fetchEditPresets' ),
                                       'parameter_type' => 'standard',
                                       'parameters' => array( array( 'name' => 'object', 'type' => 'object', 'required' => true ),
                                                              array( 'name' => 'attributes', 'type' => 'array', 'required' => false, 'default' => array() ) ) );
