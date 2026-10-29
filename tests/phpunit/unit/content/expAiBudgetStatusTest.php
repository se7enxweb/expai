<?php
/**
 * Budgets of queued requests (expAiBudget, doc/budgets.md): the site and group refusals, settling a job's cost into
 * its group, holding and resuming a job, the status of the dashboard and the template fetch budget_status. The job
 * is queued for the stub model (its resource is paused) and removed with its group after the test.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicontenttestcase.php';

class expAiBudgetStatusTest extends expAiContentTestCase
{
    public function testSiteAndGroupBudgetsHoldAndResume(): void
    {
        $config = $this->siteSettings( array( 'Budgets' => array( 'SiteMonthly' => '' ) ) );
        $this->asUser( 'admin' );
        $group = expQueue::createGroup( 'XT budget group', 'expai.test', array( 'budget_micros' => 1000 ) );
        $groupID = (int) $group->attribute( 'id' );
        $this->cleanup( static function () use ( $groupID )
        {
            $db = eZDB::instance();
            $db->query( 'DELETE FROM expqueue_event WHERE group_id = ' . $groupID );
            $db->query( 'DELETE FROM expqueue_job WHERE group_id = ' . $groupID );
            $db->query( 'DELETE FROM expqueue_group WHERE id = ' . $groupID );
        } );
        $budget = new expAiBudget( $config );
        $this->assertNull( $budget->refusal( 500, $groupID ) );

        $queued = expAiQueue::request( self::ALIAS, array( 'messages' => array( array( 'role' => 'user', 'content' => 'Hello' ) ), 'max_tokens' => 100 ),
                                       array( 'purpose' => 'test', 'group' => (string) $group->attribute( 'uuid' ), 'lane' => 'standard', 'label' => 'XT budget job' ) );
        $job = $queued['job'];
        $budget->reserve( $job, 300 );
        $this->assertSame( 300, (int) expQueue::fetch( (string) $job->attribute( 'uuid' ) )->attribute( 'cost_reserved_micros' ) );
        $budget->settle( (int) $job->attribute( 'id' ), $groupID, 900 );
        $fresh = expQueue::fetch( (string) $job->attribute( 'uuid' ) );
        $this->assertSame( array( 0, 900 ), array( (int) $fresh->attribute( 'cost_reserved_micros' ), (int) $fresh->attribute( 'cost_actual_micros' ) ) );
        $refusal = $budget->refusal( 200, $groupID );
        $this->assertSame( 'group', $refusal['scope'] );
        $this->assertStringContainsString( 'the budget of the job group "XT budget group"', $refusal['message'] );

        // held while the budget does not allow it, resumed when it does
        $this->assertTrue( $budget->hold( $fresh, 'XT test hold' ) );
        $held = expQueue::fetch( (string) $job->attribute( 'uuid' ) );
        $this->assertSame( array( 'held', 'budget: XT test hold' ), array( (string) $held->attribute( 'state' ), (string) $held->attribute( 'last_error' ) ) );
        $this->assertFalse( $budget->hold( $held, 'again' ), 'a held job is not held twice' );
        $this->assertGreaterThanOrEqual( 1, expAiBudget::heldCount() );
        eZDB::instance()->query( 'UPDATE expqueue_job SET cost_reserved_micros = 500 WHERE id = ' . (int) $job->attribute( 'id' ) );
        $still = $budget->resumeHeld();
        $this->assertGreaterThanOrEqual( 1, $still['still_held'] );
        $this->assertSame( 'held', (string) expQueue::fetch( (string) $job->attribute( 'uuid' ) )->attribute( 'state' ) );
        eZDB::instance()->query( 'UPDATE expqueue_group SET budget_micros = 0 WHERE id = ' . $groupID );
        $budget->resumeHeld();
        $this->assertNotSame( 'held', (string) expQueue::fetch( (string) $job->attribute( 'uuid' ) )->attribute( 'state' ) );

        // the status: the group with its job
        eZDB::instance()->query( 'UPDATE expqueue_group SET budget_micros = 1000 WHERE id = ' . $groupID );
        $status = $budget->status( 50 );
        $this->assertTrue( $status['queue'] );
        $mine = null;
        foreach ( $status['groups'] as $row )
            if ( $row['uuid'] === (string) $group->attribute( 'uuid' ) )
                $mine = $row;
        $this->assertNotNull( $mine );
        $this->assertSame( array( 1, 1000, 900, 500, 100.0 ), array( $mine['jobs'], $mine['cap'], $mine['spent'], $mine['reserved'], (float) $mine['percent'] ), 'spent and reserved count, at most 100' );
        $this->assertSame( 'none', $status['site']['level'], 'no site budget' );

        // a site budget that is reached
        $config->ini()->setVariable( 'Budgets', 'SiteMonthly', '0.000001' );
        $site = ( new expAiBudget( $config ) )->refusal( 1000 );
        $this->assertSame( 'site', $site['scope'] );
        $this->assertStringContainsString( 'the monthly site budget of', $site['message'] );
        $this->assertSame( 'over', ( new expAiBudget( $config ) )->status( 1 )['site']['level'] );

        // the template fetch: the figures for users with expai/log only
        $fetched = ( new expAiBudgetFunctionCollection() )->fetchStatus()['result'];
        $this->assertArrayHasKey( 'site', $fetched );
        $anonymous = eZUser::fetch( eZUser::anonymousId() );
        eZUser::setCurrentlyLoggedInUser( $anonymous, (int) $anonymous->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
        $this->assertFalse( ( new expAiBudgetFunctionCollection() )->fetchStatus()['result'] );
        $this->asUser( 'admin' );
    }
}
