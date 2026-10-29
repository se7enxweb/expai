<?php
/**
 * File containing the expAiImageInput class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * An image prepared for a vision model (doc/alt-text.md): read from an ezimage field (the stored file of the
 * version, never an upload that is not stored yet), checked, scaled down to the provider's limits and encoded.
 *
 *   $image = expAiImageInput::fromAttribute( $attribute, expAiImageInput::limitsFor( $config, 'anthropic' ) );
 *   $request['messages'][0]['content'] = array( $image->block(), array( 'type' => 'text', 'text' => '...' ) );
 *
 * The block is the canonical (Anthropic) image block { type: image, source: { type: base64, media_type, data } };
 * expAiOpenAIChatProvider turns it into an image_url part with a data URL. The image travels inside the request
 * only: no URL is handed out, so the provider cannot fetch it later and the data policy covers it like any text.
 */
class expAiImageInput
{
    /** The formats the providers take as they are */
    const SENDABLE = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

    /** @var string media type of the data */
    public $mediaType = '';

    /** @var string the bytes */
    public $data = '';

    /** @var int */
    public $width = 0;

    /** @var int */
    public $height = 0;

    /** @var int width and height of the stored file */
    public $originalWidth = 0;

    /** @var int */
    public $originalHeight = 0;

    /** @var int bytes of the stored file */
    public $originalBytes = 0;

    /** @var bool scaled down or converted */
    public $resized = false;

    /**
     * The limits for a provider: [AltTextSettings] MaxEdge and MaxBytes, overridden per provider by
     * [Provider_<id>] ImageMaxEdge and ImageMaxBytes.
     * @return array max_edge (px), max_bytes, quality (JPEG 1-100)
     */
    public static function limitsFor( expAiConfig $config, $providerID )
    {
        $limits = array( 'max_edge' => $config->int( 'AltTextSettings', 'MaxEdge', 1568, 64, 8000 ),
                         'max_bytes' => $config->int( 'AltTextSettings', 'MaxBytes', 3750000, 10000, 20000000 ),
                         'quality' => $config->int( 'AltTextSettings', 'JpegQuality', 85, 30, 100 ) );
        $provider = $config->registry()->getProvider( (string) $providerID );
        if ( $provider )
        {
            if ( isset( $provider['ImageMaxEdge'] ) && (int) $provider['ImageMaxEdge'] >= 64 )
                $limits['max_edge'] = min( 8000, (int) $provider['ImageMaxEdge'] );
            if ( isset( $provider['ImageMaxBytes'] ) && (int) $provider['ImageMaxBytes'] >= 10000 )
                $limits['max_bytes'] = min( 20000000, (int) $provider['ImageMaxBytes'] );
        }
        return $limits;
    }

    /**
     * The file of an ezimage field of a stored version.
     * @return array( string bytes, string mime type, string original file name )
     * @throws expAiException when the field has no stored image
     */
    public static function fileOf( eZContentObjectAttribute $attribute, $aliasName = 'original' )
    {
        if ( (string) $attribute->attribute( 'data_type_string' ) !== 'ezimage' )
            throw new expAiException( 'This field is not an image.' );
        $handler = $attribute->attribute( 'content' );
        $alias = $handler instanceof eZImageAliasHandler ? $handler->imageAlias( (string) $aliasName ?: 'original' ) : null;
        if ( !$alias && $handler instanceof eZImageAliasHandler && $aliasName !== 'original' )
            $alias = $handler->imageAlias( 'original' );
        if ( !is_array( $alias ) || empty( $alias['is_valid'] ) || empty( $alias['url'] ) )
            throw new expAiException( 'There is no stored image in this field yet. Upload the image and store the draft first.' );
        $file = eZClusterFileHandler::instance( (string) $alias['url'] );
        $bytes = $file->exists() ? $file->fetchContents() : false;
        if ( !is_string( $bytes ) || $bytes === '' )
            throw new expAiException( 'The image file could not be read.', 'no file at ' . basename( (string) $alias['url'] ) );
        $original = $handler->imageAlias( 'original' );
        return array( $bytes, isset( $alias['mime_type'] ) ? (string) $alias['mime_type'] : '',
                      is_array( $original ) && isset( $original['original_filename'] ) ? (string) $original['original_filename'] : '' );
    }

    /**
     * The image of an ezimage field, ready for a model.
     * @return expAiImageInput
     * @throws expAiException
     */
    public static function fromAttribute( eZContentObjectAttribute $attribute, array $limits, $aliasName = 'original' )
    {
        list( $bytes, $mime ) = self::fileOf( $attribute, $aliasName );
        return self::prepare( $bytes, $mime, $limits );
    }

    /**
     * Checks and scales an image: a sendable format within the limits goes as it is; anything larger, or another
     * format GD can read (BMP, ...), is scaled to fit max_edge and encoded as JPEG (PNG when it has transparency),
     * with lower quality and size until it fits max_bytes.
     * @param string $bytes
     * @param string $mime the stored type (the bytes decide; this is only a hint)
     * @param array $limits max_edge, max_bytes, quality
     * @return expAiImageInput
     * @throws expAiException
     */
    public static function prepare( $bytes, $mime, array $limits )
    {
        $limits += array( 'max_edge' => 1568, 'max_bytes' => 3750000, 'quality' => 85 );
        $bytes = (string) $bytes;
        $info = $bytes !== '' ? @getimagesizefromstring( $bytes ) : false;
        if ( !$info || empty( $info[0] ) || empty( $info[1] ) )
            throw new expAiException( 'This file is not an image Exponential Intelligence can look at (SVG and damaged files are not supported).' );
        $image = new self();
        $image->originalWidth = (int) $info[0];
        $image->originalHeight = (int) $info[1];
        $image->originalBytes = strlen( $bytes );
        $type = (string) $info['mime'];
        $fits = max( $image->originalWidth, $image->originalHeight ) <= (int) $limits['max_edge'] && strlen( $bytes ) <= (int) $limits['max_bytes'];
        if ( $fits && in_array( $type, self::SENDABLE, true ) )
        {
            $image->mediaType = $type;
            $image->data = $bytes;
            $image->width = $image->originalWidth;
            $image->height = $image->originalHeight;
            return $image;
        }
        if ( !function_exists( 'imagecreatefromstring' ) )
            throw new expAiException( 'The image is too large to be sent and cannot be scaled down here (the PHP extension GD is missing).' );
        // a decompression bomb is refused before it is decoded
        if ( $image->originalWidth * $image->originalHeight > 100000000 )
            throw new expAiException( 'The image is too large to be prepared (more than 100 megapixels).' );
        $source = @imagecreatefromstring( $bytes );
        if ( !$source )
            throw new expAiException( 'This image format cannot be sent to Exponential Intelligence (%type).', '', 0, array( '%type' => $type ) );
        $alpha = in_array( $type, array( 'image/png', 'image/gif', 'image/webp' ), true ) && self::hasTransparency( $source );
        $edge = (int) $limits['max_edge'];
        $quality = (int) $limits['quality'];
        for ( $try = 0; $try < 8; $try++ )
        {
            $scale = min( 1, $edge / max( $image->originalWidth, $image->originalHeight ) );
            $w = max( 1, (int) round( $image->originalWidth * $scale ) );
            $h = max( 1, (int) round( $image->originalHeight * $scale ) );
            $target = imagecreatetruecolor( $w, $h );
            if ( $alpha )
            {
                imagealphablending( $target, false );
                imagesavealpha( $target, true );
                imagefill( $target, 0, 0, imagecolorallocatealpha( $target, 0, 0, 0, 127 ) );
            }
            else
                imagefill( $target, 0, 0, imagecolorallocate( $target, 255, 255, 255 ) );
            imagecopyresampled( $target, $source, 0, 0, 0, 0, $w, $h, $image->originalWidth, $image->originalHeight );
            ob_start();
            if ( $alpha )
                imagepng( $target, null, 9 );
            else
                imagejpeg( $target, null, $quality );
            $out = (string) ob_get_clean();
            if ( strlen( $out ) <= (int) $limits['max_bytes'] )
            {
                $image->mediaType = $alpha ? 'image/png' : 'image/jpeg';
                $image->data = $out;
                $image->width = $w;
                $image->height = $h;
                $image->resized = true;
                return $image;
            }
            // too many bytes: less quality first, then fewer pixels
            if ( !$alpha && $quality > 60 )
                $quality -= 10;
            else
                $edge = (int) floor( $edge * 0.75 );
            if ( $edge < 64 )
                break;
        }
        throw new expAiException( 'The image could not be made small enough to be sent.' );
    }

    /** @return bool the image has a pixel that is not opaque (sampled on a grid) */
    protected static function hasTransparency( $gd )
    {
        if ( imageistruecolor( $gd ) === false && imagecolortransparent( $gd ) >= 0 )
            return true;
        $w = imagesx( $gd );
        $h = imagesy( $gd );
        $step = max( 1, (int) floor( max( $w, $h ) / 64 ) );
        for ( $y = 0; $y < $h; $y += $step )
            for ( $x = 0; $x < $w; $x += $step )
                if ( ( ( imagecolorat( $gd, $x, $y ) >> 24 ) & 0x7F ) > 0 )
                    return true;
        return false;
    }

    /** @return array the canonical image block (base64) */
    public function block()
    {
        return array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'media_type' => $this->mediaType, 'data' => base64_encode( $this->data ) ) );
    }

    /**
     * The input tokens the image is estimated to cost: Anthropic counts about width x height / 750 (the image is
     * scaled to 1568 px on its long edge first); the provider's own count is in the usage row.
     * @return int
     */
    public function estimatedTokens()
    {
        return self::tokensFor( $this->width, $this->height );
    }

    /** @return int */
    public static function tokensFor( $width, $height )
    {
        $width = max( 1, (int) $width );
        $height = max( 1, (int) $height );
        $scale = min( 1, 1568 / max( $width, $height ) );
        return (int) ceil( ( $width * $scale ) * ( $height * $scale ) / 750 );
    }

    /** @return array what the usage log keeps about the image (no pixels) */
    public function facts()
    {
        return array( 'name' => 'image_input', 'media_type' => $this->mediaType, 'width' => $this->width, 'height' => $this->height,
                      'bytes' => strlen( $this->data ), 'original_width' => $this->originalWidth, 'original_height' => $this->originalHeight,
                      'original_bytes' => $this->originalBytes, 'resized' => $this->resized, 'image_tokens' => $this->estimatedTokens(), 'ms' => 0 );
    }

    /**
     * A request with every image replaced by a short note, for logs (LogRequests): the pixels are never stored.
     * @return array
     */
    public static function redact( array $request )
    {
        if ( empty( $request['messages'] ) || !is_array( $request['messages'] ) )
            return $request;
        foreach ( $request['messages'] as $i => $message )
        {
            if ( !isset( $message['content'] ) || !is_array( $message['content'] ) )
                continue;
            foreach ( $message['content'] as $j => $block )
                if ( is_array( $block ) && isset( $block['type'] ) && $block['type'] === 'image' && isset( $block['source']['data'] ) )
                    $request['messages'][$i]['content'][$j]['source']['data'] = '[image, ' . strlen( (string) $block['source']['data'] ) . ' base64 characters, not logged]';
        }
        return $request;
    }
}
