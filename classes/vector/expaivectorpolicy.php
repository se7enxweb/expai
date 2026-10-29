<?php
/**
 * File containing the expAiVectorPolicy class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The value lists the role editor offers for the limitations Scope and Review of expai/vector (module.php). The
 * kernel instantiates the class with an empty array. Subtree and Section are the kernel's own limitation kinds.
 */
class expAiVectorPolicy
{
    public function __construct( $parameters = array() )
    {
    }

    /** @return array the kinds of scope entries: node, subtree, class */
    public function scopes()
    {
        return array(
            array( 'id' => 'node', 'name' => ezpI18n::tr( 'extension/expai/vector', 'Single nodes' ) ),
            array( 'id' => 'subtree', 'name' => ezpI18n::tr( 'extension/expai/vector', 'Subtrees' ) ),
            array( 'id' => 'class', 'name' => ezpI18n::tr( 'extension/expai/vector', 'Content classes' ) ),
        );
    }

    /** @return array the review policies: staged, direct */
    public function reviews()
    {
        return array(
            array( 'id' => 'staged', 'name' => ezpI18n::tr( 'extension/expai/vector', 'Staged (review before writing)' ) ),
            array( 'id' => 'direct', 'name' => ezpI18n::tr( 'extension/expai/vector', 'Direct (write drafts at once)' ) ),
        );
    }
}
