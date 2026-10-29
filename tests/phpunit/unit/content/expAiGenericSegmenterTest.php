<?php
/**
 * expAiSegmenterGeneric: a datatype without a segmenter of its own (ezinteger, ezurl ...) is one value; a change
 * of it is accepted or refused as a whole.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

class expAiGenericSegmenterTest extends expAiTestCase
{
    public function testAnUnknownDatatypeIsOneValueThatIsTakenOrLeftAsAWhole(): void
    {
        $config = $this->config();
        $segmenter = expAiSegmenterRegistry::forDatatype( 'ezinteger', $config );
        $this->assertInstanceOf( 'expAiSegmenterGeneric', $segmenter );

        $blocks = $segmenter->segment( ' 42 ' );
        $this->assertCount( 1, $blocks );
        $this->assertSame( array( 'value', '42' ), array( $blocks[0]['type'], $blocks[0]['text'] ) );
        $this->assertSame( array(), $segmenter->segment( '   ' ), 'an empty value has no block' );

        $ops = array( array( 'op' => 'modify', 'base' => 0, 'prop' => 0 ) );
        $this->assertSame( '43', $segmenter->merge( '42', '43', array( array( 'accept' => true ) + $ops[0] ) ) );
        $this->assertSame( '42', $segmenter->merge( '42', '43', $ops ), 'a change that is not accepted keeps the value' );
        $this->assertSame( '7', $segmenter->compose( '42', array( 0 => ' 7 ' ) ) );
    }
}
