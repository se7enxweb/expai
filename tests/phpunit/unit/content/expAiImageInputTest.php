<?php
/**
 * expAiImageInput: the checks of an image before it goes to a vision model (the field, the stored file, the format,
 * the decompression bomb guard, scaling down until it fits, transparency) and the redaction of requests for logs.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicontenttestcase.php';

class expAiImageInputTest extends expAiContentTestCase
{
    /** @return string a PNG of the size, drawn with GD (noise, so it does not compress away) */
    private static function png( $width, $height, $transparent = false )
    {
        $gd = imagecreatetruecolor( $width, $height );
        if ( $transparent )
        {
            imagealphablending( $gd, false );
            imagesavealpha( $gd, true );
            imagefill( $gd, 0, 0, imagecolorallocatealpha( $gd, 0, 0, 0, 127 ) );
        }
        mt_srand( 386 );
        for ( $i = 0; $i < $width * $height / 4; $i++ )
            imagesetpixel( $gd, mt_rand( 0, $width - 1 ), mt_rand( 0, $height - 1 ), imagecolorallocatealpha( $gd, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ), $transparent ? 60 : 0 ) );
        ob_start();
        imagepng( $gd );
        return (string) ob_get_clean();
    }

    /** @return string a PNG header that claims the size, with no pixels */
    private static function pngHeader( $width, $height )
    {
        $ihdr = pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 );
        return "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . $ihdr . pack( 'N', crc32( 'IHDR' . $ihdr ) );
    }

    private function refused( callable $fn, $needle )
    {
        try
        {
            $fn();
        }
        catch ( expAiException $e )
        {
            $this->assertStringContainsString( $needle, strtr( $e->getMessage(), $e->arguments() ) );
            return;
        }
        $this->fail( "not refused: expected '$needle'" );
    }

    public function testAnImageThatFitsGoesAsItIs(): void
    {
        $bytes = self::png( 40, 30 );
        $image = expAiImageInput::prepare( $bytes, 'image/png', array() );
        $this->assertSame( array( 'image/png', 40, 30, false ), array( $image->mediaType, $image->width, $image->height, $image->resized ) );
        $this->assertSame( $bytes, $image->data );
    }

    public function testALargeImageIsScaledDownToTheEdgeAsJpeg(): void
    {
        $image = expAiImageInput::prepare( self::png( 400, 200 ), 'image/png', array( 'max_edge' => 100 ) );
        $this->assertTrue( $image->resized );
        $this->assertSame( array( 'image/jpeg', 100, 50 ), array( $image->mediaType, $image->width, $image->height ) );
        $this->assertSame( array( 400, 200 ), array( $image->originalWidth, $image->originalHeight ) );
        $this->assertSame( 'image/jpeg', getimagesizefromstring( $image->data )['mime'] );
    }

    public function testATransparentImageStaysPngWhenScaled(): void
    {
        $image = expAiImageInput::prepare( self::png( 300, 300, true ), 'image/png', array( 'max_edge' => 60 ) );
        $this->assertSame( array( 'image/png', 60, 60 ), array( $image->mediaType, $image->width, $image->height ) );
        $info = getimagesizefromstring( $image->data );
        $this->assertSame( 'image/png', $info['mime'] );
    }

    public function testAPaletteImageWithATransparentColorCountsAsTransparent(): void
    {
        $gd = imagecreate( 200, 100 );
        $clear = imagecolorallocate( $gd, 255, 0, 255 );
        imagecolortransparent( $gd, $clear );
        imagefilledrectangle( $gd, 10, 10, 50, 50, imagecolorallocate( $gd, 0, 0, 255 ) );
        ob_start();
        imagegif( $gd );
        $image = expAiImageInput::prepare( (string) ob_get_clean(), 'image/gif', array( 'max_edge' => 50 ) );
        $this->assertSame( 'image/png', $image->mediaType, 'the transparent palette color keeps the PNG' );
    }

    public function testAnImageThatCannotGetSmallEnoughIsRefused(): void
    {
        $this->refused( function () { expAiImageInput::prepare( self::png( 600, 600 ), 'image/png', array( 'max_edge' => 600, 'max_bytes' => 50 ) ); },
                        'could not be made small enough' );
    }

    public function testADecompressionBombIsRefusedBeforeItIsDecoded(): void
    {
        $this->refused( function () { expAiImageInput::prepare( self::pngHeader( 20000, 20000 ), 'image/png', array() ); }, 'more than 100 megapixels' );
    }

    public function testAnImageGdCannotDecodeIsRefusedWithItsType(): void
    {
        // a header that is too big to go as it is, and no pixel data after it
        $this->refused( function () { expAiImageInput::prepare( self::pngHeader( 3000, 2000 ), 'image/png', array() ); }, 'image/png' );
    }

    public function testFilesThatAreNoImagesAreRefused(): void
    {
        $this->refused( function () { expAiImageInput::prepare( '<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml', array() ); }, 'SVG and damaged files' );
        $this->refused( function () { expAiImageInput::prepare( '', 'image/png', array() ); }, 'SVG and damaged files' );
    }

    public function testTheFieldMustBeAnImageWithAStoredFile(): void
    {
        $this->requireDatabase();
        $this->asUser( 'admin' );
        $folder = $this->folder( 'expai unit test image input' );
        $article = $this->publish( (int) $folder->attribute( 'node_id' ), array( 'title' => 'Not an image' ) );
        $title = $article->dataMap()['title'];
        $this->refused( function () use ( $title ) { expAiImageInput::fileOf( $title ); }, 'This field is not an image.' );

        $empty = $this->publish( (int) $folder->attribute( 'node_id' ), array( 'name' => 'No file yet' ), 'image' );
        $attribute = $empty->dataMap()['image'];
        $this->refused( function () use ( $attribute ) { expAiImageInput::fileOf( $attribute, 'medium' ); }, 'There is no stored image in this field yet.' );

        // a stored image: an unknown alias falls back to the original
        $object = $this->image( (int) $folder->attribute( 'node_id' ), 'With a file', 32, 24 );
        list( $bytes, $mime ) = expAiImageInput::fileOf( self::imageAttribute( $object ), 'no_such_alias_t' );
        $this->assertSame( array( 32, 24 ), array_slice( getimagesizefromstring( $bytes ), 0, 2 ) );
        $this->assertStringStartsWith( 'image/', $mime );
    }

    public function testRedactLeavesRequestsWithoutImagesAlone(): void
    {
        $this->assertSame( array( 'model' => 'x' ), expAiImageInput::redact( array( 'model' => 'x' ) ) );
        $request = array( 'messages' => array( array( 'role' => 'user', 'content' => 'plain text' ),
                                               array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => 'hi' ) ) ) ) );
        $this->assertSame( $request, expAiImageInput::redact( $request ) );
    }
}
