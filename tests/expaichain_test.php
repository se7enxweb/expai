<?php
/**
 * Unit test of AI chains (doc/chains.md): the definition of the shipped chain "article" and the errors of broken
 * definitions, pass criteria, the review/revise loop limits, the run budget, the cleaning of answers, the offline link
 * check, the prompt templates and placeholders, and the output schemas. Reads the settings shipped in
 * extension/expai/settings/expai.ini.append.php (not the site's overrides). No database writes, no network.
 *
 * Run from the installation root:
 *   php bin/php/ezexec.php extension/expai/tests/expaichain_test.php
 * It prints one line per check and ends with PASS or FAIL (exit code 0 or 1).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$shipped = eZINI::fetchFromFile( 'extension/expai/settings/expai.ini.append.php' );
$config = new expAiConfig( $shipped );
$failures = 0;
$checks = 0;
$check = function ( $label, $actual, $expected ) use ( &$failures, &$checks )
{
    ++$checks;
    $ok = $actual === $expected;
    if ( !$ok )
        ++$failures;
    echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $label . ( $ok ? '' : ' => ' . var_export( $actual, true ) . ' (expected ' . var_export( $expected, true ) . ')' ) . "\n";
};
$refused = function ( $fn )
{
    try
    {
        $fn();
    }
    catch ( expAiException $e )
    {
        return $e->detail();
    }
    return '';
};

// ------------------------------------------------------------------ the shipped chain
$check( 'Chains[] lists article', expAiChain::ids( $config ), array( 'article' ) );
$chain = expAiChain::load( 'article', $config );
$check( 'article: order', $chain->order(), array( 'write', 'review', 'seo', 'checkpoint', 'translate' ) );
$check( 'article: revise is reached through ReviseStep', in_array( 'revise', $chain->stepNamesAll(), true ), true );
$check( 'article: class and fields', array( $chain->classIdentifier(), $chain->fields() ), array( 'article', array( 'title', 'intro', 'body' ) ) );
$check( 'article: run budget $1.00', $chain->maxCostMicros(), 1000000 );
$check( 'article: Opus writes', $chain->step( 'write' )['model'], 'anthropic.claude-opus-5-5' );
$check( 'article: Sonnet reviews', $chain->step( 'review' )['model'], 'anthropic.claude-sonnet-5-5' );
$check( 'article: Haiku does SEO and QA', $chain->step( 'seo' )['model'], 'anthropic.claude-haiku-5-5' );
$check( 'article: Opus revises', $chain->step( 'revise' )['model'], 'anthropic.claude-opus-5-5' );
$check( 'article: the checkpoint comes after the review and before the translation',
        array_slice( $chain->order(), 2, 3 ), array( 'seo', 'checkpoint', 'translate' ) );
$check( 'article: checkpoint is a human step', $chain->step( 'checkpoint' )['type'], 'human' );
$check( 'article: review criteria', array_map( function ( $c ) { return $c['text']; }, $chain->step( 'review' )['criteria'] ),
        array( 'score >= 80', 'issues.high == 0', 'checklist.failed == 0' ) );
$check( 'article: review loop at most 3 reviews, then escalate', array( $chain->step( 'review' )['max_iterations'], $chain->step( 'review' )['on_exhausted'] ), array( 3, 'escalate' ) );
$check( 'article: prompts are in the snapshot', strpos( $chain->step( 'write' )['prompt_text'], '{$brief}' ) !== false, true );
$check( 'article: write gathers read-only context', $chain->step( 'write' )['tools'], array( 'get_tree_context', 'list_children' ) );
foreach ( array( 'write', 'review', 'revise', 'seo' ) as $name )
{
    $text = $chain->step( $name )['prompt_text'];
    $stripped = preg_replace( array( '/\{\*.*?\*\}/s', '/\{\$[a-z_]+\}/' ), '', $text );
    $check( "prompt $name: only placeholders and comments use braces (a valid template)", strpos( $stripped, '{' ) === false && strpos( $stripped, '}' ) === false, true );
    preg_match_all( '/\{\$([a-z_]+)\}/', $text, $m );
    $known = array( 'brief', 'chain', 'class_name', 'class_identifier', 'fields', 'language', 'location', 'context', 'item', 'item_count',
                    'document', 'issues', 'checklist', 'note', 'seo' );
    $check( "prompt $name: every placeholder is known", array_values( array_diff( $m[1], $known ) ), array() );
}
$snapshot = expAiChain::fromSnapshot( $chain->toArray() );
$check( 'snapshot round trip keeps the hash', $snapshot->hash(), $chain->hash() );

// ------------------------------------------------------------------ order and the loop
$check( 'next after write', $chain->nextAfter( 'write' ), 'review' );
$check( 'next after review', $chain->nextAfter( 'review' ), 'seo' );
$check( 'next after revise (Next=review)', $chain->nextAfter( 'revise', 'review' ), 'review' );
$check( 'next after translate: the end', $chain->nextAfter( 'translate' ), '' );
$review = $chain->step( 'review' );
$check( 'loop: 1st failed review -> revise', expAiChain::onFailure( $review, 1 ), 'revise' );
$check( 'loop: 2nd failed review -> revise', expAiChain::onFailure( $review, 2 ), 'revise' );
$check( 'loop: 3rd failed review -> escalate (MaxIterations=3)', expAiChain::onFailure( $review, 3 ), 'escalate' );
$check( 'loop: OnExhausted=stop', expAiChain::onFailure( array( 'on_fail' => 'revise', 'max_iterations' => 2, 'on_exhausted' => 'stop' ), 2 ), 'stop' );
$check( 'loop: OnFail=stop stops at once', expAiChain::onFailure( array( 'on_fail' => 'stop', 'max_iterations' => 5, 'on_exhausted' => 'escalate' ), 1 ), 'stop' );
$check( 'loop: OnFail=escalate', expAiChain::onFailure( array( 'on_fail' => 'escalate', 'max_iterations' => 5, 'on_exhausted' => 'stop' ), 1 ), 'escalate' );

// ------------------------------------------------------------------ the run budget
$check( 'budget: within', expAiChain::budgetAllows( 400000, 500000, 1000000 ), true );
$check( 'budget: exactly at the cap', expAiChain::budgetAllows( 500000, 500000, 1000000 ), true );
$check( 'budget: over -> held', expAiChain::budgetAllows( 600000, 500000, 1000000 ), false );
$check( 'budget: no cap', expAiChain::budgetAllows( 99000000, 500000, 0 ), true );

// ------------------------------------------------------------------ broken definitions
$group = array( 'Label' => 'T', 'Class' => 'article', 'Fields' => array( 'title' ), 'Steps' => array( 'w', 'r' ) );
$w = array( 'Type' => 'generate', 'Model' => 'm', 'PromptText' => 'x' );
$r = array( 'Type' => 'review', 'Model' => 'm', 'PromptText' => 'x', 'PassCriteria' => array( 'score>=70' ), 'OnFail' => 'revise', 'ReviseStep' => 'w' );
$check( 'valid minimal chain', $refused( function () use ( $group, $w, $r ) { expAiChain::fromArrays( 't', $group, array( 'w' => $w, 'r' => $r ) ); } ), '' );
$check( 'refused: empty Steps[]', strpos( $refused( function () use ( $group, $w ) { expAiChain::fromArrays( 't', array( 'Steps' => array() ) + $group, array( 'w' => $w ) ); } ), 'Steps[] is empty' ) !== false, true );
$check( 'refused: no Fields[]', strpos( $refused( function () use ( $group, $w, $r ) { expAiChain::fromArrays( 't', array( 'Fields' => array() ) + $group, array( 'w' => $w, 'r' => $r ) ); } ), 'Fields[]' ) !== false, true );
$check( 'refused: missing step group', strpos( $refused( function () use ( $group, $w ) { expAiChain::fromArrays( 't', $group, array( 'w' => $w, 'r' => null ) ); } ), 'no group' ) !== false, true );
$check( 'refused: unknown type', strpos( $refused( function () use ( $group, $w, $r ) { expAiChain::fromArrays( 't', $group, array( 'w' => $w, 'r' => array( 'Type' => 'magic' ) + $r ) ); } ), 'Type must be' ) !== false, true );
$check( 'refused: review without criteria', strpos( $refused( function () use ( $group, $w, $r ) { expAiChain::fromArrays( 't', $group, array( 'w' => $w, 'r' => array( 'PassCriteria' => array() ) + $r ) ); } ), 'needs PassCriteria' ) !== false, true );
$check( 'refused: a criterion that is not a comparison', strpos( $refused( function () use ( $group, $w, $r ) { expAiChain::fromArrays( 't', $group, array( 'w' => $w, 'r' => array( 'PassCriteria' => array( 'quality is good' ) ) + $r ) ); } ), 'not understood' ) !== false, true );
$check( 'refused: an unknown metric', strpos( $refused( function () use ( $group, $w, $r ) { expAiChain::fromArrays( 't', $group, array( 'w' => $w, 'r' => array( 'PassCriteria' => array( 'vibes>=3' ) ) + $r ) ); } ), 'not understood' ) !== false, true );
$check( 'refused: ReviseStep is not a generate step', strpos( $refused( function () use ( $group, $w, $r ) { expAiChain::fromArrays( 't', $group, array( 'w' => $w, 'r' => array( 'ReviseStep' => 'r' ) + $r ) ); } ), 'must be a generate step' ) !== false, true );
$check( 'refused: Next names no step', strpos( $refused( function () use ( $group, $w, $r ) { expAiChain::fromArrays( 't', $group, array( 'w' => array( 'Next' => 'nowhere' ) + $w, 'r' => $r ) ); } ), 'is not a step' ) !== false, true );
$check( 'refused: the first step must generate', strpos( $refused( function () use ( $group, $w, $r ) { expAiChain::fromArrays( 't', array( 'Steps' => array( 'r', 'w' ) ) + $group, array( 'w' => $w, 'r' => $r ) ); } ), 'first step' ) !== false, true );
$check( 'refused: a model step without Model', strpos( $refused( function () use ( $group, $w, $r ) { expAiChain::fromArrays( 't', $group, array( 'w' => array( 'Model' => '' ) + $w, 'r' => $r ) ); } ), 'Model is required' ) !== false, true );
$check( 'refused: a prompt path that leaves the templates', strpos( $refused( function () use ( $group, $w, $r ) { expAiChain::fromArrays( 't', $group, array( 'w' => array( 'PromptText' => '', 'PromptTemplate' => '../../../settings/override/x.tpl' ) + $w, 'r' => $r ) ); } ), 'relative .tpl path' ) !== false, true );
$check( 'refused: OnFail=retry', strpos( $refused( function () use ( $group, $w, $r ) { expAiChain::fromArrays( 't', $group, array( 'w' => $w, 'r' => array( 'OnFail' => 'retry' ) + $r ) ); } ), 'OnFail must be' ) !== false, true );
$check( 'refused: a chain not in Chains[]', strpos( $refused( function () use ( $config ) { expAiChain::load( 'nosuchchain', $config ); } ), 'not in [ChainSettings]' ) !== false, true );
$check( 'MaxIterations is capped at 10', expAiChain::fromArrays( 't', $group, array( 'w' => $w, 'r' => array( 'MaxIterations' => '99' ) + $r ) )->step( 'r' )['max_iterations'], 10 );

// ------------------------------------------------------------------ pass criteria
$criteria = expAiChainCriteria::parse( array( 'score>=80', 'issues.high==0', 'checklist.failed==0', 'issues <= 5' ) );
$pass = array( 'score' => 86, 'issues' => array( array( 'severity' => 'low', 'text' => 'a' ), array( 'severity' => 'medium', 'text' => 'b' ) ),
               'checklist' => array( array( 'item' => 'x', 'ok' => true ) ) );
$e = expAiChainCriteria::evaluate( $criteria, $pass );
$check( 'criteria: a good review passes', array( $e['pass'], $e['failed'] ), array( true, array() ) );
$e = expAiChainCriteria::evaluate( $criteria, array( 'score' => 79 ) + $pass );
$check( 'criteria: score 79 fails score>=80', $e['failed'], array( 'score >= 80 (was 79)' ) );
$e = expAiChainCriteria::evaluate( $criteria, array( 'issues' => array( array( 'severity' => 'HIGH', 'text' => 'wrong' ) ) ) + $pass );
$check( 'criteria: one high issue fails (severity case-insensitive)', $e['failed'], array( 'issues.high == 0 (was 1)' ) );
$e = expAiChainCriteria::evaluate( $criteria, array( 'checklist' => array( array( 'item' => 'x', 'ok' => false ) ) ) + $pass );
$check( 'criteria: a failed checklist item fails', $e['failed'], array( 'checklist.failed == 0 (was 1)' ) );
$e = expAiChainCriteria::evaluate( $criteria, array( 'issues' => array_fill( 0, 6, array( 'severity' => 'low', 'text' => 'x' ) ) ) + $pass );
$check( 'criteria: six issues fail issues<=5', $e['failed'], array( 'issues <= 5 (was 6)' ) );
$e = expAiChainCriteria::evaluate( $criteria, array( 'score' => null ) + $pass );
$check( 'criteria: no score fails', $e['failed'], array( 'score >= 80 (no score)' ) );
$e = expAiChainCriteria::evaluate( expAiChainCriteria::parse( array( 'links.broken==0' ) ), array( 'links' => array( array( 'ok' => true ), array( 'ok' => false ) ) ) );
$check( 'criteria: a broken link fails links.broken==0', $e['pass'], false );
foreach ( array( array( 5, '>', 4, true ), array( 4, '<', 4, false ), array( 4, '!=', 4, false ), array( 4, '==', 4.0, true ) ) as $t )
    $check( "compare {$t[0]} {$t[1]} {$t[2]}", expAiChainCriteria::compare( $t[0], $t[1], $t[2] ), $t[3] );

// ------------------------------------------------------------------ answers
$r = expAiChainEngine::cleanReview( array( 'score' => 0.87, 'summary' => 'Good.', 'issues' => array( array( 'severity' => 'urgent', 'field' => 'body', 'text' => "two\nlines" ), array( 'text' => '' ) ),
                                           'checklist' => array( array( 'item' => 'It does what the brief asks for', 'ok' => true ) ) ), $chain->step( 'review' ) );
$check( 'review: a 0..1 score becomes 0..100', $r['score'], 87 );
$check( 'review: unknown severity becomes medium, empty issues dropped', $r['issues'], array( array( 'severity' => 'medium', 'field' => 'body', 'text' => 'two lines' ) ) );
$check( 'review: every checklist item of the step, unanswered ones not met', array_map( function ( $c ) { return $c['ok']; }, $r['checklist'] ), array( true, false, false, false ) );
$check( 'review: score is clamped', expAiChainEngine::cleanReview( array( 'score' => 140 ), $chain->step( 'review' ) )['score'], 100 );
$check( 'fields: an object, only allowed ones', expAiChainEngine::cleanFields( array( 'title' => ' T ', 'secret' => 'x', 'intro' => array( 'no' ) ), array( 'title', 'intro' ) ), array( 'title' => 'T' ) );
$check( 'fields: a list of identifier/value', expAiChainEngine::cleanFields( array( array( 'identifier' => 'body', 'value' => '<p>B</p>' ) ), array( 'body' ) ), array( 'body' => '<p>B</p>' ) );
$links = expAiChainEngine::checkLinks( array( 'body' => '<p><a href="https://example.org/a">a</a> <a href="http://bad host/">b</a> <a href="mailto:editor@example.org">c</a> <a href="javascript:alert(1)">d</a> <a href="/relative">e</a></p>' ) );
$check( 'links: https ok, space in host broken, mailto ok, javascript and relative broken', array_map( function ( $l ) { return $l['ok']; }, $links ), array( true, false, true, false, false ) );

// ------------------------------------------------------------------ prompts and schemas
$check( 'render: placeholders and comments', expAiChainPrompt::render( "{* note *}\nHello {\$brief}, {\$unknown}.\n\n\n\nEnd", array( 'brief' => 'world' ) ), "Hello world, .\n\nEnd" );
$check( 'render: values are not re-expanded', expAiChainPrompt::render( 'A {$brief}', array( 'brief' => '{$note}', 'note' => 'X' ) ), 'A {$note}' );
$check( 'find: the shipped template', expAiChainPrompt::find( 'expai/chains/article_write.tpl', $config ), 'extension/expai/design/admin/templates/expai/chains/article_write.tpl' );
$check( 'find: no path out of the template directories', expAiChainPrompt::find( '../../../../config.php', $config ), null );
$schema = expAiChainEngine::outputSchema( $chain->step( 'write' ), $chain );
$check( 'schema write: fields title, intro, body, all required', $schema['properties']['fields']['required'], array( 'title', 'intro', 'body' ) );
$schema = expAiChainEngine::outputSchema( $chain->step( 'review' ), $chain );
$check( 'schema review: score, summary, issues, checklist', $schema['required'], array( 'score', 'summary', 'issues', 'checklist' ) );
$schema = expAiChainEngine::outputSchema( $chain->step( 'seo' ), $chain );
$check( 'schema seo: short_title and meta', array( $schema['properties']['fields']['required'], $schema['properties']['meta']['required'] ), array( array( 'short_title' ), array( 'title', 'description', 'keywords' ) ) );
$check( 'uuid v4', (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', expAiChainEngine::uuid() ), true );

// ------------------------------------------------------------------ schema
$def = expAiSchema::definition();
$check( 'schema: chain tables', array( isset( $def['expai_chain_run'] ), isset( $def['expai_chain_step'] ) ), array( true, true ) );
$check( 'schema: run fields match the persistent object', array_diff( array_keys( expAiChainRun::definition()['fields'] ), array_keys( $def['expai_chain_run']['fields'] ) ), array() );
$check( 'schema: step fields match the persistent object', array_diff( array_keys( expAiChainStep::definition()['fields'] ), array_keys( $def['expai_chain_step']['fields'] ) ), array() );
foreach ( array( 'mysql', 'postgresql', 'sqlite', 'oracle' ) as $engine )
{
    $sql = implode( "\n", expAiSchema::sqlStatements( $engine, array( 'expai_chain_run', 'expai_chain_step' ) ) );
    $check( "schema: $engine creates both tables", substr_count( strtolower( $sql ), 'create table' ) >= 2, true );
}

echo ( $failures ? 'FAIL' : 'PASS' ) . " expaichain: $checks checks, $failures failure(s)\n";
eZScript::instance()->shutdown( $failures ? 1 : 0 );
