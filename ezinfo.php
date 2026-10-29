<?php
/**
 * @package expai
 * @class   expaiInfo
 **/

class expaiInfo
{
    public static function info()
    {
        return array(
            'Name'      => 'Exponential Intelligence',
            'Version'   => "0.0.1.22",
            'Author'    => '7x',
            'Copyright' => "Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.",
            'License'   => "GNU General Public License v2.0 (or any later version)",
            'Info_url'  => 'https://github.com/se7enxweb/expai',
            'Uses the following service' => array(
                'Name'      => 'Anthropic Messages API (Claude)',
                'Version'   => 'API version 2023-06-01; default model claude-sonnet-5-5',
                'Copyright' => 'Claude and Anthropic are trademarks of Anthropic, PBC. The service is used under the account holder\'s own agreement with Anthropic; no Anthropic software is included.',
                'License'   => 'Anthropic Commercial Terms of Service (https://www.anthropic.com/legal/commercial-terms)',
                'Info_url'  => 'https://docs.anthropic.com/',
            ),
        );
    }
}
