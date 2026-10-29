<?php
/**
 * The template operators of expai: expai_money, expai_tokens, expai_number, expai_json, expai_retention, expai_help_url (expAiTemplateOperators).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$eZTemplateOperatorArray = array();
$eZTemplateOperatorArray[] = array( 'script' => 'extension/expai/autoloads/expaitemplateoperators.php',
                                    'class' => 'expAiTemplateOperators',
                                    'operator_names' => array( 'expai_money', 'expai_tokens', 'expai_number', 'expai_json', 'expai_retention', 'expai_help_url' ) );
