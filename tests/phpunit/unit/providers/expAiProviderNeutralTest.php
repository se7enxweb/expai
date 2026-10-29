<?php
/**
 * Provider-neutral records and prompts: the provider of a model alias, and the model the system prompt names.
 * Settings in memory only.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiProviderNeutralTest extends expAiProviderTestCase
{
    private function settings()
    {
        return array( 'ProviderSettings' => array( 'Providers' => array( 'xtea' ), 'DefaultModel' => 'xtea.one' ),
                      'Provider_xtea' => array( 'Type' => 'openai_chat', 'Label' => 'Example Cloud', 'BaseURL' => 'http://127.0.0.1:9/v1', 'ApiKey' => 'k' ),
                      'Model_xtea.one' => array( 'Provider' => 'xtea', 'Id' => 'vendor/model-one', 'Label' => 'Model One' ) );
    }

    public function testProviderOfAKnownAndAnUnknownAlias(): void
    {
        $registry = new expAiProviderRegistry( $this->config( $this->settings() ) );
        $this->assertSame( 'xtea', $registry->providerOf( 'xtea.one' ) );
        $this->assertSame( '', $registry->providerOf( 'unknown.model' ), 'never a guessed provider' );
        $this->assertSame( '', $registry->providerOf( '' ) );
    }

    public function testThePromptNamesTheModelThatAnswersAndNoneForAnUnknownModel(): void
    {
        $config = $this->config( $this->settings() );
        $this->assertSame( ', powered by the model Model One via Example Cloud', expAiPrompts::poweredBy( $config, 'xtea.one' ) );
        $this->assertSame( '', expAiPrompts::poweredBy( $config, 'unknown.model' ) );
    }
}
