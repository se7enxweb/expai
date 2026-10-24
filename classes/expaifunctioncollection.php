<?php
/**
 * File containing the expAiFunctionCollection class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The template fetch functions of expai (modules/expai/function_definition.php). Each returns nothing for users
 * without the policy expai/write.
 */
class expAiFunctionCollection
{
    public function fetchMyDrafts( $offset, $limit )
    {
        if ( !expAiServerFunctions::hasPolicy() )
            return array( 'result' => array() );
        return array( 'result' => expAiDashboard::myDrafts( max( 0, (int) $offset ), max( 1, min( 100, (int) $limit ) ) ) );
    }

    /**
     * @return array the names of the field actions that cannot be used at all (expAiFieldActionRegistry::describe()
     *               leaves them out), e.g. Translate without any translation service
     */
    public function fetchUnavailableFieldActions()
    {
        $out = array();
        foreach ( ( new expAiFieldActionRegistry( expAiConfig::instance() ) )->actions() as $name => $action )
            if ( method_exists( $action, 'unavailableReason' ) && $action->unavailableReason() !== '' )
                $out[] = (string) $name;
        return array( 'result' => $out );
    }

    public function fetchMyDraftCount()
    {
        return array( 'result' => expAiServerFunctions::hasPolicy() ? expAiDashboard::myDraftCount() : 0 );
    }

    public function fetchWaitingForMyApproval()
    {
        return array( 'result' => expAiServerFunctions::hasPolicy() ? expAiDashboard::waitingForMyApproval() : array() );
    }

    /**
     * @return array allowed (alias => label), groups (by provider, expAiConfig::modelChoices()), default, current, choice
     *               (bool), refused (bool: a stored choice is no longer allowed), pricing
     */
    public function fetchModels()
    {
        $config = expAiConfig::instance();
        return array( 'result' => array( 'allowed' => $config->allowedModels(), 'groups' => $config->modelChoices(), 'default' => $config->model(),
                                         'current' => $config->effectiveModel(), 'choice' => $config->modelChoiceEnabled(),
                                         'refused' => $config->preferredModelRefused(),
                                         'slow' => expAiUsage::slowNotice( $config->effectiveModel(), $config ),
                                         'placement' => expAiServerFunctions::hasPolicy( 'settings' ) ? $config->modelPlacement() : false,
                                         'pricing' => expAiUsage::pricingInfo(),
                                         // stopped or unreachable models (alias => { state, text, disabled }) and the link to expai/models
                                         'states' => $config->modelStates(), 'manage' => expAiServerFunctions::hasPolicy( 'model_manage' ) ) );
    }
}
