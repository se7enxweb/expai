<?php
/**
 * expAiText (the HTML subset of the assistant, text and stored rich text) and expAiJsonSchema (the validation of
 * tool arguments). No database.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiTextTest extends expAiCoreTestCase
{
    public function testCutIsMultibyteSafeAndMarksTheCut(): void
    {
        $this->assertSame( 'abc', expAiText::cut( 'abc', 3 ) );
        $this->assertSame( 'abc', expAiText::cut( 'abc', 0 ) );
        $this->assertSame( "äö\u{2026}", expAiText::cut( 'äöüß', 3 ) );
        $this->assertSame( 3, mb_strlen( expAiText::cut( 'äöüß', 3 ), 'UTF-8' ) );
        $this->assertSame( 'a b c', expAiText::oneLine( " a\n\tb   c " ) );
    }

    public function testSanitizeHtmlKeepsTheSubsetAndDropsTheRest(): void
    {
        $this->assertSame( '', expAiText::sanitizeHtml( '   ' ) );
        $html = expAiText::sanitizeHtml( '<h1>Title</h1><h5>Small</h5><p class="x" onclick="evil()">Hi <b>bold</b> <i>it</i><br>'
            . '<a href="javascript:alert(1)">bad</a> <a href="https://example.invalid/a?b=1&c=2">good</a> <a href="mailto:x@example.invalid">mail</a></p>'
            . '<script>alert(1)</script><style>p{}</style><img src="x"><span>kept text</span><p> </p><ul><li></li></ul><!-- c -->' );
        $this->assertStringContainsString( '<h2>Title</h2>', $html );
        $this->assertStringContainsString( '<h4>Small</h4>', $html );
        $this->assertStringContainsString( '<p>Hi <strong>bold</strong> <em>it</em><br />', $html );
        $this->assertStringContainsString( 'bad <a href="https://example.invalid/a?b=1&amp;c=2">good</a>', $html );
        $this->assertStringContainsString( '<a href="mailto:x@example.invalid">mail</a>', $html );
        $this->assertStringContainsString( 'kept text', $html );
        $this->assertStringNotContainsString( '<ul>', $html, 'a list with only empty items goes' );
        foreach ( array( 'script', 'style', 'img', 'onclick', 'class=', 'javascript', '<span', '<!--', '<p></p>', '<p> </p>' ) as $gone )
            $this->assertStringNotContainsString( $gone, $html );
    }

    public function testPlainTextWithMarkdownBecomesHtml(): void
    {
        $html = expAiText::sanitizeHtml( "## Heading\n\nA **bold** and *em* line\nsecond line\n\n- one\n- two & three" );
        $this->assertSame( '<h2>Heading</h2><p>A <strong>bold</strong> and <em>em</em> line<br />second line</p><ul><li>one</li><li>two &amp; three</li></ul>', $html );
        // an asterisk in a word is not emphasis
        $this->assertSame( '<p>2*3*4 a * b</p>', expAiText::textToHtml( '2*3*4 a * b' ) );
    }

    public function testHtmlToText(): void
    {
        $this->assertSame( "Title\n\nLine one\nline two\n\nitem & more", expAiText::htmlToText( '<h2>Title</h2><p>Line one<br/>line   two</p><ul><li>item &amp; more</li></ul>' ) );
    }

    public function testStoredRichTextAsHtml(): void
    {
        $this->assertSame( '', expAiText::xmlTextToHtml( '' ) );
        $this->assertSame( '', expAiText::xmlTextToHtml( '<not xml' ) );
        $xml = '<?xml version="1.0" encoding="utf-8"?><section xmlns:image="http://ez.no/namespaces/ezpublish3/image/"><section>'
             . '<header level="1">Head &amp; more</header><header level="5">Deep</header><paragraph>A <strong>b</strong> <emphasize>e</emphasize>'
             . '<link url_id="0">nolink</link><line>l1</line><line>l2</line></paragraph><paragraph><ul><li><paragraph>x</paragraph></li></ul>'
             . '<embed object_id="12"/><embed-inline node_id="34"/><custom name="factbox">c</custom></paragraph></section></section>';
        $this->assertSame( '<h2>Head &amp; more</h2><h4>Deep</h4><p>A <strong>b</strong> <em>e</em>nolinkl1<br />l2<br /></p>'
                         . '<p><ul><li><p>x</p></li></ul>[embedded object 12][embedded object 34]c</p>', expAiText::xmlTextToHtml( $xml ) );
    }

    public function testStoredLinkKeepsItsUrl(): void
    {
        $this->requireDatabase();
        $rows = eZDB::instance()->arrayQuery( 'SELECT id, url FROM ezurl', array( 'offset' => 0, 'limit' => 1 ) );
        if ( !$rows )
            $this->markTestSkipped( 'no ezurl row in this installation' );
        $xml = '<section><paragraph><link url_id="' . (int) $rows[0]['id'] . '">here</link></paragraph></section>';
        $this->assertSame( '<p><a href="' . htmlspecialchars( $rows[0]['url'], ENT_QUOTES, 'UTF-8' ) . '">here</a></p>', expAiText::xmlTextToHtml( $xml ) );
    }

    public function testJsonSchemaValidation(): void
    {
        $schema = array( 'type' => 'object', 'required' => array( 'node_id' ), 'additionalProperties' => false,
                         'properties' => array( 'node_id' => array( 'type' => 'integer' ), 'mode' => array( 'type' => 'string', 'enum' => array( 'a', 'b' ) ),
                                                'tags' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                                                'flag' => array( 'type' => array( 'boolean', 'null' ) ), 'n' => array( 'type' => 'number' ) ) );
        $this->assertSame( '', expAiJsonSchema::validate( 'not a schema', 1 ) );
        $this->assertSame( '', expAiJsonSchema::validate( $schema, array( 'node_id' => 2, 'mode' => 'a', 'tags' => array( 'x', 'y' ), 'flag' => null, 'n' => '1.5' ) ) );
        $this->assertSame( '', expAiJsonSchema::validate( $schema, array( 'node_id' => '42' ) ), 'digits as a string are an integer' );
        $this->assertSame( '', expAiJsonSchema::validate( $schema, array( 'node_id' => 3.0 ) ) );
        $this->assertSame( 'input must be object', expAiJsonSchema::validate( $schema, 'x' ) );
        $this->assertSame( 'input.node_id is required', expAiJsonSchema::validate( $schema, array( 'mode' => 'a' ) ) );
        $this->assertSame( 'input.node_id must be integer', expAiJsonSchema::validate( $schema, array( 'node_id' => 'two' ) ) );
        $this->assertSame( 'input.mode must be one of "a", "b"', expAiJsonSchema::validate( $schema, array( 'node_id' => 1, 'mode' => 'c' ) ) );
        $this->assertSame( 'input.tags[1] must be string', expAiJsonSchema::validate( $schema, array( 'node_id' => 1, 'tags' => array( 'x', 5 ) ) ) );
        $this->assertSame( 'input.flag must be boolean or null', expAiJsonSchema::validate( $schema, array( 'node_id' => 1, 'flag' => 'yes' ) ) );
        $this->assertSame( 'input.n must be number', expAiJsonSchema::validate( $schema, array( 'node_id' => 1, 'n' => 'many' ) ) );
        $this->assertSame( 'input.other is not a known field (allowed: node_id, mode, tags, flag, n)', expAiJsonSchema::validate( $schema, array( 'node_id' => 1, 'other' => 1 ) ) );
        $this->assertSame( 'input must be array', expAiJsonSchema::validate( array( 'type' => 'array' ), array( 'a' => 1 ) ) );
        $this->assertSame( '', expAiJsonSchema::validate( array( 'type' => 'unknowntype' ), 'anything' ), 'an unknown type is not refused' );

        // additionalProperties as a schema: every extra field must match it
        $map = array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'string' ) );
        $this->assertSame( '', expAiJsonSchema::validate( $map, array( 'a' => 'x', 'b' => 'y' ) ) );
        $this->assertSame( 'input.b must be string', expAiJsonSchema::validate( $map, array( 'a' => 'x', 'b' => 2 ) ) );
    }
}
