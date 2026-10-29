<?php
/**
 * Unit test of the segmenters, the diff engine and the translation decision: sentences (English and German with
 * abbreviations), plain text, keywords, rich text (headings, links, lists, tables, embeds), block and sentence
 * alignment, metrics, hunk ids, merge (accept and reject per hunk), compose with placeholders, and the decision
 * matrix of the translation sync. No database writes, no network.
 *
 * Run from the installation root:
 *   php bin/php/ezexec.php extension/expai/tests/expaidiff_test.php
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
    $ok = is_float( $expected ) ? abs( (float) $actual - $expected ) < 1e-6 : $actual === $expected;
    if ( !$ok )
        ++$failures;
    echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $label . ( $ok ? '' : ' => ' . var_export( $actual, true ) . ' (expected ' . var_export( $expected, true ) . ')' ) . "\n";
};

/* ------------------------------------------------------------ sentences */

$check( 'normalize quotes, dashes, spaces', expAiSentences::normalize( "  \u{201E}Hallo\u{201C} \u{2013}  Welt\u{00A0}! " ), '"Hallo" - Welt !' );
$check( 'family ger-DE', expAiSentences::family( 'ger-DE' ), 'ger' );
$check( 'family de_DE', expAiSentences::family( 'de_DE' ), 'ger' );
$check( 'family eng-GB', expAiSentences::family( 'eng-GB' ), 'eng' );
$check( 'english abbreviations, initials, a.m.',
        expAiSentences::split( 'Dr. Smith arrived at 9 a.m. on Monday. He met Mr. J. R. Jones, e.g. at the U.S. office. It went well! Did it?', 'eng-GB' ),
        array( 'Dr. Smith arrived at 9 a.m. on Monday.', 'He met Mr. J. R. Jones, e.g. at the U.S. office.', 'It went well!', 'Did it?' ) );
$check( 'english: a sentence ending in a number',
        expAiSentences::split( 'We sold 15. Then we stopped.', 'eng' ), array( 'We sold 15.', 'Then we stopped.' ) );
$check( 'german abbreviations with spaces, ordinals',
        expAiSentences::split( 'Er kam z. B. am 3. Oktober. Dann ging er nach Hause. Prof. Müller sagt ca. 10 Wörter, d. h. wenig. Ende.', 'ger-DE' ),
        array( 'Er kam z. B. am 3. Oktober.', 'Dann ging er nach Hause.', 'Prof. Müller sagt ca. 10 Wörter, d. h. wenig.', 'Ende.' ) );
$check( 'german: Nr., bzw., usw. inside a sentence',
        expAiSentences::split( 'Siehe Nr. 5 bzw. Abs. 2 usw. bis zum Ende. Danke.', 'ger' ), array( 'Siehe Nr. 5 bzw. Abs. 2 usw. bis zum Ende.', 'Danke.' ) );
$check( 'quotes after the stop', expAiSentences::split( 'Er sagte: "Komm." Sie kam.', 'ger' ), array( 'Er sagte: "Komm."', 'Sie kam.' ) );
$check( 'empty text', expAiSentences::split( '   ', 'eng' ), array() );

/* ------------------------------------------------------------ plain segmenters */

$text = new expAiSegmenterText();
$blocks = $text->segment( "First paragraph. Two sentences.\n\nSecond one\nwith a line break.\r\n\r\n\r\nThird.", array( 'language' => 'eng' ) );
$check( 'eztext: three paragraphs', count( $blocks ), 3 );
$check( 'eztext: sentences of the first', count( $blocks[0]['sentences'] ), 2 );
$check( 'eztext: line break kept in the block text', $blocks[1]['text'], "Second one\nwith a line break." );
$check( 'eztext: chars normalised', $blocks[1]['chars'], mb_strlen( 'Second one with a line break.' ) );

$string = new expAiSegmenterString();
$check( 'ezstring: one block', count( $string->segment( 'A title. With two sentences.' ) ), 1 );
$check( 'ezstring: empty', $string->segment( '' ), array() );

$keyword = new expAiSegmenterKeyword();
$kb = $keyword->segment( 'beta, Alpha, beta , gamma' );
$check( 'ezkeyword: a sorted set', array_column( $kb, 'text' ), array( 'Alpha', 'beta', 'gamma' ) );
$kd = expAiDiff::compareValues( 'ezkeyword', 'alpha, beta, gamma', 'alpha, gamma, delta', array( 'attribute' => 'tags', 'config' => $config ) );
$check( 'ezkeyword diff: one removed, one added', array( $kd['metrics']['inserted'], $kd['metrics']['deleted'] ), array( 1, 1 ) );
$merged = expAiSegmenterRegistry::forDatatype( 'ezkeyword', $config )->merge( 'alpha, beta, gamma', 'alpha, gamma, delta',
    array_map( function ( $op ) { return $op + array( 'accept' => $op['op'] === 'insert' ); }, $kd['ops'] ) );
$check( 'ezkeyword merge: add only', $merged, 'alpha, beta, delta, gamma' );

$check( 'registry: ezxmltext', expAiSegmenterRegistry::className( 'ezxmltext', $config ), 'expAiSegmenterRichText' );
$check( 'registry: unknown -> generic', expAiSegmenterRegistry::className( 'ezmatrix', $config ), 'expAiSegmenterGeneric' );

/* ------------------------------------------------------------ rich text */

$ns = 'xmlns:image="http://ez.no/namespaces/ezpublish3/image/" xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/"';
$xml = function ( $inner ) use ( $ns ) { return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<section ' . $ns . '>' . $inner . '</section>' . "\n"; };
$base = $xml( '<section><header>Our shop</header><paragraph>We are open daily. Visit <link url_id="7">our shop</link> on <strong>Main Street</strong>.</paragraph>'
            . '<paragraph><embed view="embed" size="medium" object_id="123"/></paragraph>'
            . '<paragraph><ul><li><paragraph>Bread</paragraph></li><li><paragraph>Cake</paragraph></li><li><paragraph>Coffee</paragraph></li></ul></paragraph>'
            . '<paragraph><table class="list" width="90%"><tr><th><paragraph>Day</paragraph></th><th><paragraph>Hours</paragraph></th></tr>'
            . '<tr><td><paragraph>Monday</paragraph></td><td><paragraph>9 to 5</paragraph></td></tr></table></paragraph>'
            . '<paragraph>Thanks for reading. See you soon.</paragraph></section>' );
$rich = new expAiSegmenterRichText();
$rb = $rich->segment( $base, array( 'language' => 'eng' ) );
$check( 'rich text: types', array_column( $rb, 'type' ),
        array( 'heading', 'paragraph', 'paragraph', 'list_item', 'list_item', 'list_item', 'table_cell', 'table_cell', 'table_cell', 'table_cell', 'paragraph' ) );
$types = array_column( $rb, 'type' );
$check( 'rich text: first is a heading, embed paragraph, items, cells, last paragraph',
        array( $types[0], $types[2], $types[3], $types[6], $types[9] ), array( 'heading', 'paragraph', 'list_item', 'table_cell', 'table_cell' ) );
$check( 'rich text: coded inline markup', $rb[1]['coded'], 'We are open daily. Visit <1>our shop</1> on <2>Main Street</2>.' );
$check( 'rich text: sentences of the paragraph', count( $rb[1]['sentences'] ), 2 );
$check( 'rich text: the embed is an atom', $rb[2]['atoms'], array( 'embed object 123' ) );
$check( 'rich text: the embed coded', $rb[2]['coded'], '<1/>' );
$check( 'rich text: cells are not mergeable alone', $rb[6]['mergeable'], false );
$check( 'rich text: items are mergeable', $rb[3]['mergeable'], true );
// the full count: 1 heading + 1 paragraph + 1 embed + 3 items + 4 cells + 1 paragraph = 11
$check( 'rich text: block count with the last paragraph', count( $rb ), 11 );
$html = $rich->segment( '<h2>Title</h2><p>One <a href="https://example.org/">link</a>.</p><ul><li>a</li><li>b</li></ul>', array( 'language' => 'eng' ) );
$check( 'rich text (HTML): blocks', array_column( $html, 'type' ), array( 'heading', 'paragraph', 'list_item', 'list_item' ) );

/* ------------------------------------------------------------ diff */

$edit = str_replace( 'See you soon.', 'See you next week.', $base );
$d = expAiDiff::compareValues( 'ezxmltext', $base, $edit, array( 'attribute' => 'body', 'language' => 'eng', 'config' => $config ) );
$check( 'diff: one hunk for one changed sentence', count( $d['hunks'] ), 1 );
$check( 'diff: the hunk modifies one block', array( $d['hunks'][0]['op'], $d['metrics']['modified'] ), array( 'replace', 1 ) );
$check( 'diff: one sentence of the block changed', $d['metrics']['sentences_changed'], 1 );
$check( 'diff: changed chars = the longer sentence', $d['metrics']['changed_chars'], mb_strlen( 'See you next week.' ) );
$check( 'diff: a small ratio', $d['metrics']['change_ratio'] > 0 && $d['metrics']['change_ratio'] < 0.2, true );
$again = expAiDiff::compareValues( 'ezxmltext', $base, $edit, array( 'attribute' => 'body', 'language' => 'eng', 'config' => $config ) );
$check( 'hunk ids are stable', $again['hunks'][0]['id'], $d['hunks'][0]['id'] );
$other = expAiDiff::compareValues( 'ezxmltext', $base, str_replace( 'Our shop', 'Our bakery', $edit ), array( 'attribute' => 'body', 'language' => 'eng', 'config' => $config ) );
$check( 'an unrelated change keeps the other hunk id', in_array( $d['hunks'][0]['id'], array_column( $other['hunks'], 'id' ), true ), true );
$check( 'two hunks for two separate changes', count( $other['hunks'] ), 2 );

$link = str_replace( 'url_id="7"', 'url_id="8"', $base );
$dl = expAiDiff::compareValues( 'ezxmltext', $base, $link, array( 'attribute' => 'body', 'config' => $config ) );
$check( 'a changed link is a change of markup only', array( count( $dl['hunks'] ), $dl['metrics']['changed_chars'], $dl['metrics']['structural_only'] ), array( 1, 0, true ) );

$items = str_replace( '<li><paragraph>Cake</paragraph></li>', '', $base );
$items = str_replace( '<li><paragraph>Coffee</paragraph></li>', '<li><paragraph>Coffee</paragraph></li><li><paragraph>Tea and juice</paragraph></li>', $items );
$di = expAiDiff::compareValues( 'ezxmltext', $base, $items, array( 'attribute' => 'body', 'config' => $config ) );
$check( 'list: one deleted and one inserted item', array( $di['metrics']['deleted'], $di['metrics']['inserted'] ), array( 1, 1 ) );
$check( 'list: two hunks', array_column( $di['hunks'], 'op' ), array( 'delete', 'insert' ) );

$moved = $xml( '<paragraph>First block here.</paragraph><paragraph>Second block here.</paragraph><paragraph>Third block here.</paragraph>' );
$movedTo = $xml( '<paragraph>Second block here.</paragraph><paragraph>Third block here.</paragraph><paragraph>First block here.</paragraph>' );
$dm = expAiDiff::compareValues( 'ezxmltext', $moved, $movedTo, array( 'attribute' => 'body', 'config' => $config ) );
$check( 'moved block: counted as moved, no changed chars', array( $dm['metrics']['moved'], $dm['metrics']['changed_chars'] ), array( 1, 0 ) );

$whole = expAiDiff::compareValues( 'eztext', "One sentence.\n\nAnother one.", "Completely different words here.\n\nNothing alike at all.", array( 'attribute' => 'intro', 'config' => $config ) );
$check( 'a rewrite: ratio 1', $whole['metrics']['change_ratio'], 1.0 );
$same = expAiDiff::compareValues( 'eztext', "Same text.\n\nSame.", "Same  text.\n\nSame.", array( 'attribute' => 'intro', 'config' => $config ) );
$check( 'white space only: no hunk', count( $same['hunks'] ), 0 );

$result = expAiDiff::compare( array( 'values' => array( 'title' => array( 'datatype' => 'ezstring', 'value' => 'Hello world' ),
                                                       'body' => array( 'datatype' => 'ezxmltext', 'value' => $base ) ), 'language' => 'eng-GB' ),
                              array( 'values' => array( 'title' => array( 'datatype' => 'ezstring', 'value' => 'Hello world' ),
                                                       'body' => array( 'datatype' => 'ezxmltext', 'value' => $edit ) ), 'language' => 'eng-GB' ),
                              array( 'config' => $config ) );
$m = $result->metrics();
$check( 'object metrics: one attribute changed', array( $m['attributes_changed'], $m['hunks'] ), array( 1, 1 ) );
$check( 'object metrics: chars include both attributes', $m['chars'], mb_strlen( 'Hello world' ) + array_sum( array_column( $rb, 'chars' ) ) );

/* ------------------------------------------------------------ merge (apply) */

$both = str_replace( 'Our shop', 'Our bakery', $edit );
$r2 = expAiDiff::compare( array( 'values' => array( 'body' => array( 'datatype' => 'ezxmltext', 'value' => $base ) ) ),
                          array( 'values' => array( 'body' => array( 'datatype' => 'ezxmltext', 'value' => $both ) ) ), array( 'config' => $config ) );
$ids = $r2->hunkIds();
$onlySecond = expAiDiff::apply( $r2, array( $ids[1] ) );
$check( 'apply: one of two hunks', array( strpos( $onlySecond['values']['body'], 'Our shop' ) !== false, strpos( $onlySecond['values']['body'], 'See you next week.' ) !== false ), array( true, true ) );
$check( 'apply: the applied list', $onlySecond['applied'], array( $ids[1] ) );
$all = expAiDiff::apply( $r2, $ids );
$check( 'apply: all hunks = the proposal (blocks)', array_column( $rich->segment( $all['values']['body'] ), 'hash' ), array_column( $rich->segment( $both ), 'hash' ) );
$none = expAiDiff::apply( $r2, array( 'hnope' ) );
$check( 'apply: unknown ids reported, nothing merged', array( $none['values'], $none['unknown'] ), array( array(), array( 'hnope' ) ) );

$r3 = expAiDiff::compare( array( 'values' => array( 'body' => array( 'datatype' => 'ezxmltext', 'value' => $base ) ) ),
                          array( 'values' => array( 'body' => array( 'datatype' => 'ezxmltext', 'value' => $items ) ) ), array( 'config' => $config ) );
$h = $r3->hunks();
$ins = expAiDiff::apply( $r3, array( $h[1]['id'] ) );
$insBlocks = array_column( $rich->segment( $ins['values']['body'] ), 'text' );
$check( 'apply insert: the new item is in the list, the deleted one kept', array( in_array( 'Tea and juice', $insBlocks, true ), in_array( 'Cake', $insBlocks, true ) ), array( true, true ) );
$check( 'apply insert: inside the same list', strpos( $ins['values']['body'], '<li><paragraph>Coffee</paragraph></li><li><paragraph>Tea and juice</paragraph></li>' ) !== false, true );
$del = expAiDiff::apply( $r3, array( $h[0]['id'] ) );
$check( 'apply delete: the item is gone', strpos( $del['values']['body'], 'Cake' ), false );
$newList = $xml( '<paragraph>Intro text here.</paragraph>' );
$withList = $xml( '<paragraph>Intro text here.</paragraph><paragraph><ul><li><paragraph>New point</paragraph></li></ul></paragraph>' );
$r4 = expAiDiff::compare( array( 'values' => array( 'b' => array( 'datatype' => 'ezxmltext', 'value' => $newList ) ) ),
                          array( 'values' => array( 'b' => array( 'datatype' => 'ezxmltext', 'value' => $withList ) ) ), array( 'config' => $config ) );
$nl = expAiDiff::apply( $r4, $r4->hunkIds() );
$check( 'apply insert: a list created after a paragraph', strpos( $nl['values']['b'], '<paragraph><ul><li><paragraph>New point</paragraph></li></ul></paragraph>' ) !== false, true );

/* ------------------------------------------------------------ compose (placeholders) */

$doc = $rich->parse( $base );
$nodes = $rich->blockNodes( $doc );
list( $coded, $map ) = $rich->encode( $nodes[1] );
$translated = $rich->compose( $base, array( 1 => array( 'text' => 'Wir haben täglich geöffnet. Besuchen Sie <1>unseren Laden</1> in der <2>Hauptstraße</2>.' ) ) );
$check( 'compose: the link and its url_id kept', strpos( $translated, '<link url_id="7">unseren Laden</link>' ) !== false, true );
$check( 'compose: strong kept', strpos( $translated, '<strong>Hauptstraße</strong>' ) !== false, true );
$thrown = '';
try
{
    $rich->compose( $base, array( 2 => array( 'text' => 'Kein Bild' ) ) );
}
catch ( expAiException $e )
{
    $thrown = 'missing';
}
$check( 'compose: a dropped embed is refused', $thrown, 'missing' );
$thrown = '';
try
{
    $rich->compose( $base, array( 1 => array( 'text' => '<1>a<2>b</1></2>' ) ) );
}
catch ( expAiException $e )
{
    $thrown = 'nesting';
}
$check( 'compose: wrong nesting is refused', $thrown, 'nesting' );
$check( 'compose: escaped text', strpos( $rich->compose( $base, array( 10 => array( 'text' => 'a &lt; b &amp; c' ) ) ), 'a &lt; b &amp; c' ) !== false, true );
$check( 'compose plain text', $text->compose( "A.\n\nB.", array( 1 => 'Bee.' ) ), "A.\n\nBee." );
$check( 'compose keywords', $keyword->compose( 'a, b', array( 'x' => 'Brot, Kuchen, Brot' ) ), 'Brot, Kuchen' );

/* ------------------------------------------------------------ decision matrix */

$metrics = function ( $ratio, $blocks = 1 ) { return array( 'change_ratio' => $ratio, 'blocks_changed' => $blocks, 'hunks' => $ratio > 0 ? 1 : 0 ); };
$cases = array(
    // ratio, aligned, units, estimate micros => decision, needs editor, auto
    array( 0.0, true, 0, 0, 'noop', false, true ),
    array( 0.05, true, 1, 2000, 'patch', false, true ),
    array( 0.05, true, 1, 900000, 'patch', true, false ),        // above the cost threshold
    array( 0.30, true, 3, 5000, 'patch', true, false ),          // between PatchMaxChangedRatio and FullMinChangedRatio
    array( 0.80, true, 9, 5000, 'full', true, false ),
    array( 0.05, false, 1, 2000, 'full', true, false ),          // the target does not have the structure of the base
    array( 0.05, true, 99, 2000, 'patch', true, false ),         // more than PatchMaxSegments units
);
foreach ( $cases as $c )
{
    $decision = expAiTranslationSync::decide( $metrics( $c[0] ), $c[1], $c[2], $c[3], $config );
    $check( sprintf( 'decide( ratio %.2f, aligned %s, %d units, %d micros )', $c[0], $c[1] ? 'yes' : 'no', $c[2], $c[3] ),
            array( $decision['decision'], $decision['needs_editor'], $decision['auto'] ), array( $c[4], $c[5], $c[6] ) );
}
$structural = expAiTranslationSync::decide( array( 'change_ratio' => 0.0, 'blocks_changed' => 1, 'hunks' => 1 ), true, 1, 1000, $config );
$check( 'decide: markup-only change is a patch', $structural['decision'], 'patch' );

echo ( $failures ? 'FAIL' : 'PASS' ) . " $checks checks, $failures failed\n";
eZScript::instance()->shutdown( $failures ? 1 : 0 );
