<?php
/**
 * File containing the expAiWebPolicy class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The value list of the limitation Tool of the policy expai/web (module.php): the tools that read outside the
 * content tree. The kernel instantiates the class with an empty array.
 */
class expAiWebPolicy
{
    const TOOLS = array( 'web_fetch' => 'Web page (web_fetch)', 'web_search' => 'Web search (web_search)', 'project_history' => 'Project history (project_history)' );

    public function __construct( $parameters = array() )
    {
    }

    /** @return array list of array( id, name ) */
    public function tools()
    {
        $out = array();
        foreach ( self::TOOLS as $id => $name )
            $out[] = array( 'id' => $id, 'name' => $name );
        return $out;
    }
}
