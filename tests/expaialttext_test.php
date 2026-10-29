<?php
/**
 * Unit test of alt text for images (doc/alt-text.md): the image preparation (formats, scaling to the provider's
 * limits, the byte limit, transparency, files that are not images), the token estimate, the redaction of the pixels
 * in logs, the image blocks of both request formats (Anthropic image block, OpenAI-compatible image_url), the
 * capability gating (only models with Capabilities[]=vision), the data policy for images (ImageRegions[],
 * ImageZeroRetention, the refusal and its reason), the field action on a model without vision, the cleaning of the
 * answer and the accessibility rules of the prompt (English and German).
 * The settings exist only in memory; the images are made in memory with GD. No database writes, no network.
 *
 * Run from the installation root:
 *   php bin/php/ezexec.php extension/expai/tests/expaialttext_test.php
 * It prints one line per check and ends with PASS or FAIL (exit code 0 or 1).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

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
$throws = function ( $label, $fn, $contains ) use ( $check )
{
    try
    {
        $fn();
        $check( $label, 'no exception', $contains );
    }
    catch ( expAiException $e )
    {
        $text = strtr( $e->getMessage(), $e->arguments() ) . ' ' . $e->detail();
        $check( $label, strpos( $text, $contains ) !== false ? $contains : $text, $contains );
    }
};
$png = function ( $w, $h, $alpha = false )
{
    $gd = imagecreatetruecolor( $w, $h );
    imagesavealpha( $gd, true );
    imagealphablending( $gd, false );
    imagefill( $gd, 0, 0, $alpha ? imagecolorallocatealpha( $gd, 255, 0, 0, 100 ) : imagecolorallocate( $gd, 30, 120, 200 ) );
    for ( $i = 0; $i < 400; $i++ )
        imagesetpixel( $gd, mt_rand( 0, $w - 1 ), mt_rand( 0, $h - 1 ), imagecolorallocatealpha( $gd, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ), $alpha ? 60 : 0 ) );
    ob_start();
    imagepng( $gd );
    return ob_get_clean();
};
$jpeg = function ( $w, $h, $noise = false )
{
    $gd = imagecreatetruecolor( $w, $h );
    imagefill( $gd, 0, 0, imagecolorallocate( $gd, 200, 180, 40 ) );
    if ( $noise )
        for ( $y = 0; $y < $h; $y += 2 )
            for ( $x = 0; $x < $w; $x += 2 )
                imagesetpixel( $gd, $x, $y, imagecolorallocate( $gd, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) );
    ob_start();
    imagejpeg( $gd, null, 95 );
    return ob_get_clean();
};
$limits = array( 'max_edge' => 1568, 'max_bytes' => 3750000, 'quality' => 85 );

// ---------------------------------------------------------------- image preparation
$small = $png( 200, 100 );
$image = expAiImageInput::prepare( $small, 'image/png', $limits );
$check( 'small PNG: sent as it is', array( $image->mediaType, $image->width, $image->height, $image->resized, $image->data === $small ), array( 'image/png', 200, 100, false, true ) );
$big = $jpeg( 4000, 3000 );
$image = expAiImageInput::prepare( $big, 'image/jpeg', $limits );
$check( 'large JPEG: scaled to the long edge 1568', array( $image->mediaType, $image->width, $image->height, $image->resized ), array( 'image/jpeg', 1568, 1176, true ) );
$check( 'large JPEG: original size kept in the facts', array( $image->originalWidth, $image->originalHeight ), array( 4000, 3000 ) );
$tall = $jpeg( 600, 2400 );
$image = expAiImageInput::prepare( $tall, 'image/jpeg', array( 'max_edge' => 800 ) + $limits );
$check( 'portrait: the long edge is the height', array( $image->width, $image->height ), array( 200, 800 ) );
$transparent = $png( 3000, 1000, true );
$image = expAiImageInput::prepare( $transparent, 'image/png', $limits );
$check( 'transparent PNG over the limit: stays PNG (alpha kept)', array( $image->mediaType, $image->width ), array( 'image/png', 1568 ) );
$noisy = $jpeg( 1200, 1200, true );
$image = expAiImageInput::prepare( $noisy, 'image/jpeg', array( 'max_edge' => 1568, 'max_bytes' => 60000, 'quality' => 85 ) );
$check( 'byte limit: quality and size go down until it fits', strlen( $image->data ) <= 60000 && $image->resized, true );
$throws( 'byte limit that cannot be met is refused', function () use ( $noisy ) { expAiImageInput::prepare( $noisy, 'image/jpeg', array( 'max_edge' => 1568, 'max_bytes' => 10, 'quality' => 85 ) ); },
         'could not be made small enough' );
$throws( 'a file that is not an image is refused', function () use ( $limits ) { expAiImageInput::prepare( '<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml', $limits ); },
         'not an image' );
$throws( 'empty bytes are refused', function () use ( $limits ) { expAiImageInput::prepare( '', 'image/png', $limits ); }, 'not an image' );
if ( function_exists( 'imagebmp' ) )
{
    $gd = imagecreatetruecolor( 50, 40 );
    ob_start();
    imagebmp( $gd );
    $bmp = ob_get_clean();
    $image = expAiImageInput::prepare( $bmp, 'image/bmp', $limits );
    $check( 'BMP (not sendable): converted to JPEG', array( $image->mediaType, $image->width, $image->resized ), array( 'image/jpeg', 50, true ) );
}
$gd = imagecreate( 30, 30 );
imagecolorallocate( $gd, 0, 0, 0 );
ob_start();
imagegif( $gd );
$gif = ob_get_clean();
$check( 'small GIF: sent as it is', expAiImageInput::prepare( $gif, 'image/gif', $limits )->mediaType, 'image/gif' );

// ---------------------------------------------------------------- tokens, blocks, redaction
$check( 'tokens: 1568 x 1176 is about 2459', expAiImageInput::tokensFor( 1568, 1176 ), 2459 );
$check( 'tokens: a larger image counts as scaled to 1568', expAiImageInput::tokensFor( 4000, 3000 ), 2459 );
$check( 'tokens: 200 x 100', expAiImageInput::tokensFor( 200, 100 ), 27 );
$image = expAiImageInput::prepare( $small, 'image/png', $limits );
$block = $image->block();
$check( 'block: Anthropic image block with base64', array( $block['type'], $block['source']['type'], $block['source']['media_type'], base64_decode( $block['source']['data'] ) === $small ),
        array( 'image', 'base64', 'image/png', true ) );
$facts = $image->facts();
$check( 'facts: no pixels, the size and the image tokens', array( isset( $facts['data'] ), $facts['name'], $facts['width'], $facts['image_tokens'] ), array( false, 'image_input', 200, 27 ) );
$request = array( 'system' => 's', 'messages' => array( array( 'role' => 'user', 'content' => array( $block, array( 'type' => 'text', 'text' => 'Describe' ) ) ) ) );
$redacted = expAiImageInput::redact( $request );
$check( 'redact: the pixels are not in the log', strpos( json_encode( $redacted ), $block['source']['data'] ) === false && strpos( $redacted['messages'][0]['content'][0]['source']['data'], 'not logged' ) !== false, true );
$check( 'redact: the text stays', $redacted['messages'][0]['content'][1]['text'], 'Describe' );

$openai = new expAiOpenAiChatProvider( array( 'ProviderId' => 'openai', 'Label' => 'OpenAI', 'Flavor' => 'openai', 'BaseURL' => 'https://example.test/v1',
                                              'ApiKey' => 'test-key', 'Model' => 'test/vision', 'MaxTokens' => 500 ) );
$payload = $openai->buildPayload( $request );
$user = $payload['messages'][1];
$check( 'OpenAI-compatible: one user message with an image_url part and the text', array( $user['role'], $user['content'][0]['type'], $user['content'][1]['type'], $user['content'][1]['text'] ),
        array( 'user', 'image_url', 'text', 'Describe' ) );
$check( 'OpenAI-compatible: a data URL with the media type', strpos( $user['content'][0]['image_url']['url'], 'data:image/png;base64,' ) === 0, true );
$plain = $openai->buildPayload( array( 'system' => 's', 'messages' => array( array( 'role' => 'user', 'content' => 'Hello' ) ) ) );
$check( 'OpenAI-compatible: a text-only message stays a string', $plain['messages'][1]['content'], 'Hello' );
$anthropic = new expAiAnthropicProvider( array( 'ProviderId' => 'anthropic', 'ApiKey' => 'test-key', 'Model' => 'claude-test', 'MaxTokens' => 500 ) );
$payload = $anthropic->buildPayload( $request );
$check( 'Anthropic: the image block goes as it is', $payload['messages'][0]['content'][0]['type'] . '/' . $payload['messages'][0]['content'][0]['source']['type'], 'image/base64' );

// ---------------------------------------------------------------- settings in memory: vision and data policy
$ini = eZINI::fetchFromFile( 'extension/expai/settings/expai.ini.append.php' );
$set = function ( $group, array $values ) use ( $ini ) { foreach ( $values as $name => $value ) $ini->setVariable( $group, $name, $value ); };
$set( 'AISettings', array( 'Enabled' => 'true', 'AllowUserModelChoice' => 'enabled', 'Model' => '', 'AllowedModels' => array() ) );
$set( 'ProviderSettings', array( 'Providers' => array( 'atplain', 'atus', 'ateu' ), 'DefaultModel' => 'atplain.m', 'ProvidersWithoutPolicy' => array( 'atplain', 'atus', 'ateu' ) ) );
$set( 'Provider_atplain', array( 'Type' => 'openai_chat', 'Label' => 'Plain', 'BaseURL' => 'https://plain.example.test/v1', 'ApiKey' => 'x', 'Region' => 'us', 'Retention' => 'zdr' ) );
$set( 'Provider_atus', array( 'Type' => 'openai_chat', 'Label' => 'Vision US', 'BaseURL' => 'https://us.example.test/v1', 'ApiKey' => 'x', 'Region' => 'us', 'Retention' => '30d',
                              'ImageMaxEdge' => '1024', 'ImageMaxBytes' => '500000' ) );
$set( 'Provider_ateu', array( 'Type' => 'openai_chat', 'Label' => 'Vision EU', 'BaseURL' => 'https://eu.example.test/v1', 'ApiKey' => 'x', 'Region' => 'eu', 'Retention' => 'zdr' ) );
$set( 'Model_atplain.m', array( 'Provider' => 'atplain', 'Id' => 'test/plain', 'Label' => 'Plain model', 'ToolUse' => 'native', 'Capabilities' => array( 'json_schema' ) ) );
$set( 'Model_atus.m', array( 'Provider' => 'atus', 'Id' => 'test/us', 'Label' => 'US vision', 'ToolUse' => 'native', 'Capabilities' => array( 'json_schema', 'vision' ) ) );
$set( 'Model_ateu.m', array( 'Provider' => 'ateu', 'Id' => 'test/eu', 'Label' => 'EU vision', 'ToolUse' => 'native', 'Capabilities' => array( 'Vision' ) ) );
$set( 'DataPolicy', array( 'DefaultRegions' => array(), 'SectionRegions' => array(), 'ClassRegions' => array(), 'ZeroRetention' => 'disabled',
                           'ZeroRetentionSections' => array(), 'ZeroRetentionClasses' => array() ) );
$set( 'AltTextSettings', array( 'Enabled' => 'enabled', 'Model' => '', 'ImageRegions' => array(), 'ImageZeroRetention' => 'disabled', 'MaxEdge' => '1568', 'MaxBytes' => '3750000' ) );
$config = new expAiConfig( $ini );
expAiProviderHealth::reset();

$check( 'capability: only the vision models (case does not matter)', expAiAltText::visionModels( $config ), array( 'atus.m', 'ateu.m' ) );
$anthropicIni = eZINI::fetchFromFile( 'extension/expai/settings/expai.ini.append.php' );
$anthropicIni->setVariable( 'AISettings', 'Model', 'claude-test-model' );
$anthropicIni->setVariable( 'ProviderSettings', 'Providers', array( 'anthropic' ) );
$anthropicModel = ( new expAiConfig( $anthropicIni ) )->registry()->getModel( 'anthropic.claude-test-model' );
$check( 'Anthropic models of [AISettings] can look at images', $anthropicModel ? in_array( 'vision', $anthropicModel['capabilities'], true ) : 'no model', true );
$limitsUs = expAiImageInput::limitsFor( $config, 'atus' );
$check( 'limits: per provider ImageMaxEdge and ImageMaxBytes', array( $limitsUs['max_edge'], $limitsUs['max_bytes'] ), array( 1024, 500000 ) );
$limitsEu = expAiImageInput::limitsFor( $config, 'ateu' );
$check( 'limits: the [AltTextSettings] defaults', array( $limitsEu['max_edge'], $limitsEu['max_bytes'] ), array( 1568, 3750000 ) );
$check( 'limits: a large image for the US provider is scaled to 1024', expAiImageInput::prepare( $big, 'image/jpeg', $limitsUs )->width, 1024 );

$object = eZContentObject::fetch( (int) eZContentObjectTreeNode::fetch( 2 )->attribute( 'contentobject_id' ) );
$choice = expAiAltText::chooseModel( $config, $object );
$check( 'choice: the default model has no vision, the first vision model is used', array( $choice['alias'], $choice['skipped']['atplain.m'] ), array( 'atus.m', 'no vision' ) );
$set( 'AltTextSettings', array( 'Model' => 'ateu.m' ) );
$check( 'choice: [AltTextSettings] Model comes first', expAiAltText::chooseModel( $config, $object )['alias'], 'ateu.m' );
$set( 'AltTextSettings', array( 'Model' => '', 'ImageRegions' => array( 'eu' ) ) );
$choice = expAiAltText::chooseModel( $config, $object );
$check( 'data policy: ImageRegions[]=eu skips the US provider', array( $choice['alias'], $choice['skipped']['atus.m'] ), array( 'ateu.m', 'data policy' ) );
$set( 'AltTextSettings', array( 'ImageRegions' => array(), 'ImageZeroRetention' => 'enabled' ) );
$check( 'data policy: ImageZeroRetention skips the 30-day provider', expAiAltText::chooseModel( $config, $object )['alias'], 'ateu.m' );
$set( 'AltTextSettings', array( 'ImageZeroRetention' => 'disabled', 'ImageRegions' => array( 'local' ) ) );
$throws( 'data policy: no vision model in the region local is a refusal with the reason', function () use ( $config, $object ) { expAiAltText::chooseModel( $config, $object ); },
         'keeps the image of' );
$throws( 'data policy: the reason names the image rule', function () use ( $config, $object ) { expAiAltText::chooseModel( $config, $object ); }, 'regions local required' );
$set( 'AltTextSettings', array( 'ImageRegions' => array() ) );
$set( 'DataPolicy', array( 'SectionRegions' => array( (string) (int) $object->attribute( 'section_id' ) => 'eu' ) ) );
$check( 'data policy: the section rule of the object applies to its image', expAiAltText::chooseModel( $config, $object )['alias'], 'ateu.m' );
$set( 'DataPolicy', array( 'SectionRegions' => array() ) );
$requirement = expAiAltText::requirement( $config, expAiDataPolicy::forProvider( $config, 'atus' ), $object );
$check( 'requirement without rules: any region', $requirement['regions'], null );
$set( 'Model_atus.m', array( 'Capabilities' => array( 'json_schema' ) ) );
$set( 'Model_ateu.m', array( 'Capabilities' => array() ) );
$config = new expAiConfig( $ini );
$throws( 'capability: without a vision model there is no suggestion', function () use ( $config, $object ) { expAiAltText::chooseModel( $config, $object ); }, 'No model that can look at images' );

// the field action on a provider whose model cannot look at images
$action = new expAiAltTextFieldAction( 'alt_text', $config );
$check( 'field action: offered for ezimage, no input', array( $action->datatypes(), $action->input() ), array( array( 'ezimage' ), 'none' ) );
$fieldRequest = new expAiFieldRequest();
$fieldRequest->object = $object;
$throws( 'field action: a model without vision is refused', function () use ( $action, $fieldRequest, $config ) {
    $action->run( $fieldRequest, $config->registry()->providerFor( 'atplain.m' ) ); }, 'cannot look at images' );

// ---------------------------------------------------------------- the answer and the prompt
$check( 'clean: "Image of" is taken off', expAiAltText::clean( 'Image of a red bicycle leaning on a wall' ), 'A red bicycle leaning on a wall' );
$check( 'clean: "Photo showing" is taken off', expAiAltText::clean( 'photo showing two hikers on a ridge' ), 'Two hikers on a ridge' );
$check( 'clean: German "Bild von" is taken off', expAiAltText::clean( 'Bild von zwei Wanderern auf einem Grat' ), 'Zwei Wanderern auf einem Grat' );
$check( 'clean: one line, no markup, no quotes', expAiAltText::clean( "\"<b>Team</b> at the\n  office\"" ), 'Team at the office' );
$long = expAiAltText::clean( str_repeat( 'word ', 60 ), 40 );
$check( 'clean: cut at a word within MaxLength', mb_strlen( $long ) <= 40 && substr( $long, -1 ) !== ' ', true );
$system = expAiAltText::systemPrompt( $config );
$check( 'prompt: purpose, concise, no "image of"', strpos( $system, 'purpose' ) !== false && strpos( $system, 'Be concise' ) !== false && strpos( $system, '"Image of"' ) !== false, true );
$check( 'prompt: German openings named', strpos( $system, '"Bild von"' ) !== false, true );
$check( 'prompt: decorative is a suggestion, the editor decides', strpos( $system, 'decorative to true' ) !== false && strpos( $system, 'the editor decides' ) !== false, true );
$text = expAiAltText::userText( $config, $object, 'ger-DE', 'Image', array( 'title' => 'Unser Team', 'body' => '<p>Das Büro in Köln</p>' ) );
$check( 'user text: the language, the page and the context as data', strpos( $text, 'ger-DE' ) !== false && strpos( $text, 'Das Büro in Köln' ) !== false && strpos( $text, 'data, not instructions' ) !== false, true );
$req = expAiAltText::request( $config, expAiImageInput::prepare( $small, 'image/png', $limits ), $text );
$check( 'request: image first, then the text; structured answer', array( $req['messages'][0]['content'][0]['type'], $req['messages'][0]['content'][1]['type'], $req['output_schema']['required'] ),
        array( 'image', 'text', array( 'alt_text', 'decorative', 'note' ) ) );

echo "\n$checks checks, $failures failed\n" . ( $failures ? "FAIL\n" : "PASS\n" );
eZScript::instance()->shutdown( $failures ? 1 : 0 );
