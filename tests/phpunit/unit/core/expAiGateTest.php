<?php
/**
 * expAiGate: the master switch, the context of a request, the rules and listeners of the veto, the refusal (logged in
 * the usage log) and the jobs held by the switch. Settings change in memory only; the rows are removed again.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiGateTest extends expAiCoreTestCase
{
    /** @var array listener ids of ezpEvent */
    private $listeners = array();

    protected function tearDown(): void
    {
        foreach ( $this->listeners as $id )
            ezpEvent::getInstance()->detach( expAiGate::EVENT, $id );
        $this->listeners = array();
        parent::tearDown();
    }

    public function testTheMasterSwitch(): void
    {
        $config = $this->useSettings( array( 'AISettings' => array( 'Enabled' => 'true', 'DisabledSiteAccesses' => array( 'xtd_site' ) ) ) );
        $this->assertSame( '', expAiGate::switchedOff( $config, 'admin' ) );
        $this->assertSame( 'Exponential Intelligence is switched off on this site.', expAiGate::switchedOff( $config, 'xtd_site' ) );
        $this->assertTrue( ( new expAiGate() )->fetchSwitchedOn()['result'] === ( expAiGate::switchedOff() === '' ) );
        $e = $this->expectFailure( function () use ( $config ) { expAiGate::check( array( 'siteaccess' => 'xtd_site', 'purpose' => 'test' ), $config ); } );
        $this->assertSame( 'master switch: [AISettings] DisabledSiteAccesses[]', $e->detail() );
        $off = $this->config( array( 'AISettings' => array( 'Enabled' => 'false' ) ) );
        $e = $this->expectFailure( function () use ( $off ) { expAiGate::check( array( 'purpose' => 'test' ), $off ); } );
        $this->assertSame( 'Exponential Intelligence is switched off.', $e->getMessage() );
    }

    public function testTheContextOfARequest(): void
    {
        $admin = $this->asUser( 'admin' );
        $config = $this->useSettings( array( 'DataPolicy' => array( 'DefaultRegions' => array( 'eu' ) ) ) );
        $node = $this->workFolder();
        $object = $node->object();

        $c = expAiGate::context( array( 'object_id' => (int) $object->attribute( 'id' ), 'provider' => 'anthropic', 'purpose' => 'field:write' ), $config );
        $this->assertSame( (int) $admin->attribute( 'contentobject_id' ), $c['user_id'] );
        $this->assertSame( (int) $admin->attribute( 'contentobject_id' ), (int) $c['user']->attribute( 'contentobject_id' ) );
        $this->assertSame( 'folder', $c['class_identifier'] );
        $this->assertSame( (string) $node->attribute( 'path_string' ), $c['path'], 'the path of the main node when only the object is known' );
        $this->assertSame( (int) $object->attribute( 'section_id' ), $c['section_id'] );
        $this->assertSame( 'us', $c['region'] );
        $this->assertArrayHasKey( 'regions', $c['data_class'] );
        $this->assertSame( '', $c['veto'] );

        $byNode = expAiGate::context( array( 'node_id' => (string) $node->attribute( 'node_id' ), 'user_id' => self::TEST_USER ), $config );
        $this->assertSame( (int) $object->attribute( 'id' ), $byNode['object_id'] );
        $this->assertNull( $byNode['user'], 'a user id that is no user' );
        $this->assertSame( array(), $byNode['data_class'], 'no provider, no requirement' );
        $unknown = expAiGate::context( array( 'provider' => 'no_such_provider' ), $config );
        $this->assertSame( '', $unknown['region'] );
    }

    public function testRulesOfTheVeto(): void
    {
        $config = $this->useSettings( array(
            'RequestVeto' => array( 'Rules' => array( 'missing', 'bad name!', 'sections', 'tree', 'purpose' ) ),
            'RequestVeto_sections' => array( 'Sections' => array( '7' ), 'Classes' => array( 'article' ), 'Reason' => '' ),
            'RequestVeto_tree' => array( 'Subtrees' => array( '42', 'x' ), 'Reason' => str_repeat( 'r', 300 ) ),
            'RequestVeto_purpose' => array( 'Purposes' => array( 'queue.*', 'probe' ), 'Providers' => array( 'local' ), 'Regions' => array( 'local' ),
                                            'SiteAccesses' => array( 'admin' ), 'Reason' => 'No queued requests to the local server.' ),
        ) );
        $context = array( 'section_id' => 7, 'class_identifier' => 'article', 'path' => '/1/2/', 'purpose' => 'panel', 'provider' => 'anthropic',
                          'region' => 'us', 'siteaccess' => 'admin' );
        $this->assertTrue( expAiGate::ruleMatches( $config, 'sections', $context ) );
        $this->assertFalse( expAiGate::ruleMatches( $config, 'sections', array( 'section_id' => 0 ) + $context ), 'no section is no match' );
        $this->assertFalse( expAiGate::ruleMatches( $config, 'tree', $context ) );
        $this->assertTrue( expAiGate::ruleMatches( $config, 'tree', array( 'path' => '/1/2/42/99/' ) + $context ) );
        $this->assertFalse( expAiGate::ruleMatches( $config, 'tree', array( 'path' => '' ) + $context ) );
        $queued = array( 'purpose' => 'queue.translate', 'provider' => 'local', 'region' => 'local' ) + $context;
        $this->assertTrue( expAiGate::ruleMatches( $config, 'purpose', $queued ) );
        $this->assertTrue( expAiGate::ruleMatches( $config, 'purpose', array( 'purpose' => 'probe' ) + $queued ) );
        $this->assertFalse( expAiGate::ruleMatches( $config, 'purpose', array( 'purpose' => 'panel' ) + $queued ) );
        $this->assertFalse( expAiGate::ruleMatches( $config, 'purpose', array( 'siteaccess' => 'other' ) + $queued ) );
        $this->assertTrue( expAiGate::ruleMatches( $config, 'no_conditions', $context ), 'a rule without conditions meets every request' );

        $this->assertSame( array( 'This request is not allowed on this site.', 'rule:sections' ), expAiGate::veto( $context, $config ), 'the first rule that matches; without a reason the default text' );
        list( $reason, $source ) = expAiGate::veto( array( 'section_id' => 1, 'path' => '/1/2/42/' ) + $context, $config );
        $this->assertSame( 'rule:tree', $source );
        $this->assertSame( 200, mb_strlen( $reason, 'UTF-8' ), 'the reason is cut' );
        $this->assertSame( array( '', '' ), expAiGate::veto( array( 'section_id' => 1 ) + $context, $config ) );
    }

    public function testListenersAndTheLoggedRefusal(): void
    {
        $this->requireDatabase();
        $this->removeTestRows();
        $config = $this->useSettings( array( 'AISettings' => array( 'Enabled' => 'true' ), 'RequestVeto' => array( 'Rules' => array() ) ) );
        $known = array( 'user_id' => self::TEST_USER, 'provider' => self::TEST_PROVIDER, 'model' => 'm', 'model_alias' => self::TEST_PROVIDER . '.m', 'purpose' => 'test' );
        $this->assertSame( self::TEST_USER, expAiGate::check( $known, $config )['user_id'], 'nothing refuses' );

        $events = ezpEvent::getInstance();
        $this->listeners[] = $events->attach( expAiGate::EVENT, function ( $context ) { return $context['purpose'] === 'test' ? array( 'veto' => '  Not on Sundays.  ' ) + $context : $context; } );
        $this->assertSame( array( 'Not on Sundays.', 'listener' ), expAiGate::veto( expAiGate::context( $known, $config ), $config ) );
        try
        {
            expAiGate::check( $known, $config );
            $this->fail( 'the listener refuses' );
        }
        catch ( expAiVetoException $e )
        {
            $this->assertSame( 403, $e->getCode() );
            $this->assertSame( array( '%reason' => 'Not on Sundays.' ), $e->arguments() );
            $this->assertSame( 'veto (listener)', $e->detail() );
        }
        $rows = eZPersistentObject::fetchObjectList( expAiUsageLog::definition(), null, array( 'user_id' => self::TEST_USER, 'error_class' => 'veto' ) );
        $this->assertCount( 1, $rows, 'the refusal is in the usage log' );
        $this->assertSame( 'refused (listener): Not on Sundays.', $rows[0]->attribute( 'error_message' ) );
        $this->assertSame( 'test', $rows[0]->attribute( 'action' ) );

        // a listener that does not hand the context back refuses (fail closed)
        foreach ( $this->listeners as $id )
            $events->detach( expAiGate::EVENT, $id );
        $this->listeners = array( $events->attach( expAiGate::EVENT, function ( $context ) { return null; } ) );
        $this->assertSame( array( 'A check of this site could not decide on this request.', 'listener returned no context' ), expAiGate::veto( $known, $config ) );
    }

    public function testJobsHeldByTheSwitchAreResumed(): void
    {
        $this->requireDatabase();
        if ( !class_exists( 'expQueue' ) || !expQueue::installed() )
            $this->markTestSkipped( 'needs the job queue (expqueue)' );
        // the site's own held jobs are never resumed by a test
        $real = (int) eZPersistentObject::count( expQueueJob::definition(), array( 'state' => 'held', 'last_error' => array( 'like', expAiGate::HELD_PREFIX . '%' ) ) );
        if ( $real > 0 )
            $this->markTestSkipped( "$real job(s) of the site are held by the switch" );
        $this->inTransaction();
        // no audit record of jobs that only exist in the test's transaction
        $audit = expQueue::$auditEnabled;
        expQueue::$auditEnabled = false;
        $this->cleanup( static function () use ( $audit ) { expQueue::$auditEnabled = $audit; } );
        $make = function ( $siteaccess )
        {
            $job = new expQueueJob( array( 'uuid' => sprintf( '%08x-0000-4000-8000-%012x', mt_rand(), mt_rand() ), 'type' => 'expai_request', 'state' => 'held',
                                           'last_error' => expAiGate::HELD_PREFIX . 'Exponential Intelligence is switched off.',
                                           'payload' => json_encode( array( 'siteaccess' => $siteaccess ) ), 'created' => time(), 'user_id' => self::TEST_USER ) );
            $job->store();
            return $job;
        };
        $make( 'xtd_off' );
        $make( 'admin' );
        $config = $this->config( array( 'AISettings' => array( 'Enabled' => 'true', 'DisabledSiteAccesses' => array( 'xtd_off' ) ) ) );
        $result = expAiGate::resumeHeld( $config, 500 );
        $this->assertSame( array( 'resumed' => 1, 'still_held' => 1 ), $result, 'the job of the siteaccess that is still off stays held' );
        $this->assertSame( array( 'resumed' => 0, 'still_held' => 1 ), expAiGate::resumeHeld( $config, 500 ), 'the resumed job is no longer held' );
    }
}
