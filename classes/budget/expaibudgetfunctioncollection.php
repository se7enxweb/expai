<?php
/**
 * File containing the expAiBudgetFunctionCollection class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The template fetch function fetch( 'expai', 'budget_status' ): the monthly site budget of queued requests
 * (expAiBudget::status()), for users with expai/log (it shows the site's figures); false for everybody else.
 */
class expAiBudgetFunctionCollection
{
    public function fetchStatus()
    {
        $user = eZUser::currentUser();
        $access = $user->hasAccessTo( 'expai', 'log' );
        if ( $access['accessWord'] === 'no' )
            return array( 'result' => false );
        return array( 'result' => ( new expAiBudget() )->status( 5 ) );
    }
}
