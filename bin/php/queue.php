#!/usr/bin/env php
<?php
/**
 * ./console ext:expai:queue — Queued AI requests: lanes, jobs, batches, budgets.
 *
 *   lanes   [--model=<alias>]       the lanes of every model (type, price factor, latency, retention, usable)
 *   choose  --model=<alias> [--purpose=..] [--class=..] [--priority=..] [--deadline=<seconds>]   which lane a request would take
 *   jobs    [--state=..] [--limit=50]   the expai jobs with lane, model, estimate, reservation, cost
 *   batches [--limit=20]            the provider batches
 *   budget                          the monthly site budget, reservations, groups, held jobs
 *   resume                          resumes the jobs held by a budget that fit now
 *   request --model=<alias> --prompt=<text> [--lane=auto|standard|flex|batch|fast] [--purpose=..] [--max-tokens=..]
 *           [--group=<label>] [--group-budget=<amount>]  queues one request (the queue's workers run it)
 *   --login=<user>   the user (required for request)
 *
 * -s <siteaccess> is mandatory: the script prints the siteaccess and its database (stderr) and does not run
 * where expai is not active (expAiScript).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 * @description Queued AI requests: lanes, jobs, batches, budgets
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array( 'description' => "Queued requests of Exponential Intelligence: lanes, jobs, batches, budgets\n",
                                     'use-session' => false, 'use-modules' => true, 'use-extensions' => true ) );
$script->startup();
$options = $script->getOptions( '[model:][purpose:][class:][priority:][deadline:][state:][limit:][prompt:][lane:][max-tokens:][group:][group-budget:][login:]', '[command]', array(
    'model' => 'a model alias', 'purpose' => 'the purpose of the request ([LanePolicy] Order[<purpose>])', 'class' => 'the queue class',
    'priority' => '0..100', 'deadline' => 'seconds from now', 'state' => 'jobs: only this state', 'limit' => 'rows', 'prompt' => 'request: the text',
    'lane' => 'request: a lane or auto', 'max-tokens' => 'request: the longest answer', 'group' => 'request: a new job group with this label',
    'group-budget' => 'request: the budget of the new group', 'login' => 'the user' ) );
expAiScript::requireSiteAccess( $script, $options );
$script->initialize();
expAiScript::announce( $script );

$command = isset( $options['arguments'][0] ) ? (string) $options['arguments'][0] : '';
$config = expAiConfig::instance();
$fail = function ( $text ) use ( $cli, $script )
{
    $cli->error( 'FAIL ' . $text );
    $script->shutdown( 1 );
};
if ( $options['login'] )
{
    $user = eZUser::fetchByName( $options['login'] );
    if ( !$user )
        $fail( 'no user ' . $options['login'] );
    eZUser::setCurrentlyLoggedInUser( $user, (int) $user->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
}
$money = function ( $micros ) { return expAiBudget::money( (int) $micros ); };

try
{
    switch ( $command )
    {
        case 'lanes':
            foreach ( $config->registry()->models() as $alias => $model )
            {
                if ( $options['model'] && $options['model'] !== $alias )
                    continue;
                foreach ( expAiLane::forModel( $alias, $config ) as $lane )
                    $cli->output( sprintf( '%-34s %-9s %-5s x%-5s %7ss %-8s %s', $alias, $lane->name(), $lane->get( 'type' ),
                        $lane->get( 'multiplier' ) === null ? '?' : $lane->get( 'multiplier' ), $lane->get( 'expected_latency' ), $lane->retention(),
                        $lane->usable() ? 'usable' : ( $lane->get( 'enabled' ) ? 'no confirmed price' : 'disabled' ) ) );
            }
            break;

        case 'choose':
            if ( !$options['model'] )
                $fail( 'name --model' );
            $choice = ( new expAiLanePolicy( $config ) )->choose( $options['model'], array( 'purpose' => (string) $options['purpose'], 'class' => (string) $options['class'],
                'priority' => $options['priority'] !== null ? (int) $options['priority'] : 50, 'deadline' => $options['deadline'] ? time() + (int) $options['deadline'] : 0,
                'lane' => (string) $options['lane'] ) );
            $cli->output( 'lane ' . $choice['lane']->name() . ' (order ' . implode( ', ', $choice['order'] ) . ')' );
            foreach ( $choice['tried'] as $name => $why )
                $cli->output( "  not $name: $why" );
            break;

        case 'jobs':
            $filter = $options['state'] ? array( 'state' => (string) $options['state'] ) : array();
            foreach ( expAiQueue::jobs( $filter, $options['limit'] ? (int) $options['limit'] : 50 ) as $j )
                $cli->output( sprintf( '%s %-20s %-9s %-8s %-30s est %s res %s cost %s %s', substr( $j['uuid'], 0, 8 ), $j['type'], $j['state'], $j['lane'], $j['alias'],
                                       $money( $j['estimate'] ), $money( $j['reserved'] ), $money( $j['actual'] ), $j['error'] ) );
            break;

        case 'batches':
            foreach ( expAiBatch::fetchRecent( $options['limit'] ? (int) $options['limit'] : 20 ) as $b )
                $cli->output( sprintf( '#%d %-10s %-10s %-30s %-10s %s requests: %d ok, %d errored, %d expired; submitted %s%s', $b->attribute( 'id' ), $b->attribute( 'provider' ),
                                       $b->attribute( 'batch_api' ), $b->attribute( 'model_alias' ), $b->attribute( 'state' ), $b->attribute( 'request_count' ),
                                       $b->attribute( 'succeeded' ), $b->attribute( 'errored' ), $b->attribute( 'expired' ), date( 'Y-m-d H:i', (int) $b->attribute( 'submitted' ) ),
                                       $b->attribute( 'deleted' ) ? ', deleted at the provider' : '' ) );
            break;

        case 'budget':
            $status = ( new expAiBudget( $config ) )->status( 20 );
            $s = $status['site'];
            $cli->output( 'site budget: ' . ( $s['cap'] ? $s['cap_money'] : 'none' ) . '; spent ' . $s['spent_money'] . ', reserved ' . $s['reserved_money']
                          . ( $s['cap'] ? ', left ' . $s['remaining_money'] . ' (' . $s['percent'] . '%)' : '' ) . '; held jobs: ' . $status['held'] );
            foreach ( $status['groups'] as $g )
                $cli->output( sprintf( '  group %s %-40s %-9s budget %s spent %s reserved %s held %d', substr( $g['uuid'], 0, 8 ), $g['label'], $g['state'],
                                       $g['cap'] ? $g['cap_money'] : '-', $g['spent_money'], $g['reserved_money'], $g['held'] ) );
            break;

        case 'resume':
            $r = ( new expAiBudget( $config ) )->resumeHeld();
            $cli->output( 'PASS resumed ' . $r['resumed'] . ', still held ' . $r['still_held'] );
            break;

        case 'request':
            if ( !$options['login'] || !$options['prompt'] )
                $fail( 'name --login and --prompt' );
            $access = eZUser::currentUser()->hasAccessTo( 'expai', 'write' );
            if ( $access['accessWord'] === 'no' )
                $fail( 'the user lacks the policy expai/write' );
            $group = null;
            if ( $options['group'] )
                $group = expQueue::createGroup( (string) $options['group'], 'expai.request', array(
                    'budget_micros' => $options['group-budget'] !== null ? expAiBudget::micros( $options['group-budget'] ) : ( new expAiBudget( $config ) )->defaultGroupCap() ) );
            $r = expAiQueue::request( (string) $options['model'], array( 'messages' => array( array( 'role' => 'user', 'content' => (string) $options['prompt'] ) ),
                                                                         'max_tokens' => $options['max-tokens'] ? (int) $options['max-tokens'] : 400 ),
                                      array( 'lane' => $options['lane'] ? (string) $options['lane'] : 'auto', 'purpose' => (string) $options['purpose'], 'group' => $group,
                                             'class' => (string) $options['class'] ) );
            $cli->output( 'PASS queued ' . $r['job']->attribute( 'uuid' ) . ' on ' . $r['lane']->key() . ', estimate ' . $money( $r['estimate'] ) . ', reserved ' . $money( $r['reserved'] )
                          . ( $r['held'] !== '' ? '; HELD: ' . $r['held'] : '' ) );
            break;

        default:
            $fail( 'a command: lanes, choose, jobs, batches, budget, resume or request (see --help)' );
    }
}
catch ( expAiException $e )
{
    $fail( strtr( $e->getMessage(), $e->arguments() ) . ( $e->detail() !== '' ? ' (' . $e->detail() . ')' : '' ) );
}
catch ( expQueueException $e )
{
    $fail( $e->getMessage() );
}
$script->shutdown( 0 );
