<?php
/**
 * expAiModelFallback's texts: the notice of every reason a model answered instead, and the failure when none is left.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiModelFallbackTextsTest extends expAiProviderTestCase
{
    public function testEveryReasonHasItsNoticeAndItsFailure(): void
    {
        $config = $this->config( array( 'ProviderSettings' => array( 'Providers' => array( 'xtef' ) ),
                                        'Provider_xtef' => array( 'Type' => 'openai_chat', 'BaseURL' => 'http://127.0.0.1:1/v1', 'ApiKey' => 'k' ),
                                        'Model_xtef.a' => array( 'Provider' => 'xtef', 'Id' => 'a', 'Label' => 'Model A' ),
                                        'Model_xtef.b' => array( 'Provider' => 'xtef', 'Id' => 'b', 'Label' => 'Model B' ) ) );
        $cases = array(
            expAiModelFallback::STOPPED => array( 'Model B answered instead: Model A is stopped.', 'Model A is stopped, and no other model you may use can answer for this content.' ),
            expAiModelFallback::TIMEOUT => array( 'Model B answered instead: Model A did not answer in time.', 'Model A did not answer in time, and no other model you may use can answer for this content.' ),
            expAiModelFallback::NOT_ALLOWED => array( 'Model B answered instead: Model A is not allowed.', 'Model A is not allowed, and no other model you may use can answer for this content.' ),
            expAiModelFallback::REMOVED => array( 'Model B answered instead: Model A is no longer available.', 'Model A is no longer available, and no other model you may use can answer for this content.' ),
            expAiModelFallback::DATA_POLICY => array( 'Model B answered instead: the data policy keeps this content away from Model A.',
                                                      'The data policy keeps this content away from Model A, and no other model you may use can answer for it.' ),
            'unreachable' => array( 'Model B answered instead: Model A could not be reached.', 'Model A could not be reached, and no other model you may use can answer for this content.' ),
        );
        foreach ( $cases as $reason => $texts )
        {
            $this->assertSame( $texts[0], expAiModelFallback::notice( $config, 'xtef.a', 'xtef.b', $reason ), $reason );
            $e = expAiModelFallback::noneLeft( $config, 'xtef.a', $reason );
            $this->assertSame( $texts[1], strtr( $e->getMessage(), $e->arguments() ), $reason );
            $this->assertSame( 'fallback: none left', $e->detail() );
        }
    }
}
