<?php
/**
 * The output budget of reasoning models (expAiModelTest::isReasoningModel(), outputBudget()): a reasoning model
 * spends output tokens on thinking, so a request to it never gets fewer than REASONING_MIN_TOKENS; another model
 * keeps the configured value. The alt text request uses it. No database, no request.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiReasoningBudgetTest extends expAiCoreTestCase
{
    private function models( array $settings = array() )
    {
        return $this->useSettings( array_replace_recursive( array(
            'ProviderSettings' => array( 'Providers' => array( 'anthropic', self::TEST_PROVIDER ) ),
            'Provider_' . self::TEST_PROVIDER => array( 'Type' => 'openai_chat', 'BaseURL' => 'http://127.0.0.1:9/v1', 'ApiKey' => '' ),
            'Model_xtd.thinker' => array( 'Provider' => self::TEST_PROVIDER, 'Id' => 'thinker-120b', 'Capabilities' => array( 'json_schema', 'reasoning_effort' ) ),
            'Model_xtd.effort' => array( 'Provider' => self::TEST_PROVIDER, 'Id' => 'effort-model', 'Effort' => 'low' ),
            'Model_xtd.flagged' => array( 'Provider' => self::TEST_PROVIDER, 'Id' => 'flagged-model', 'Reasoning' => 'true' ),
            'Model_xtd.plain' => array( 'Provider' => self::TEST_PROVIDER, 'Id' => 'plain-model', 'Capabilities' => array( 'json_schema' ), 'Effort' => 'none' ),
        ), $settings ) );
    }

    public function testWhichModelIsAReasoningModel(): void
    {
        $this->assertFalse( expAiModelTest::isReasoningModel( null ) );
        $this->assertFalse( expAiModelTest::isReasoningModel( array() ) );
        $this->assertTrue( expAiModelTest::isReasoningModel( array( 'capabilities' => array( 'reasoning_effort' ) ) ) );
        $this->assertTrue( expAiModelTest::isReasoningModel( array( 'effort' => 'high' ) ) );
        $this->assertFalse( expAiModelTest::isReasoningModel( array( 'effort' => 'none' ) ), 'reasoning switched off' );
        $this->assertTrue( expAiModelTest::isReasoningModel( array( 'settings' => array( 'Reasoning' => 'enabled' ) ) ) );
        $this->assertFalse( expAiModelTest::isReasoningModel( array( 'settings' => array( 'Reasoning' => 'false' ) ) ) );

        $registry = $this->models()->registry();
        foreach ( array( 'xtd.thinker' => true, 'xtd.effort' => true, 'xtd.flagged' => true, 'xtd.plain' => false ) as $alias => $reasoning )
            $this->assertSame( $reasoning, expAiModelTest::isReasoningModel( $registry->getModel( $alias ) ), $alias );
    }

    public function testAReasoningModelNeverGetsASmallBudget(): void
    {
        $config = $this->models();
        $this->assertSame( 1000, expAiModelTest::REASONING_MIN_TOKENS );
        $this->assertSame( 1000, expAiModelTest::outputBudget( 'xtd.thinker', 120, $config ) );
        $this->assertSame( 4000, expAiModelTest::outputBudget( 'xtd.thinker', 4000, $config ), 'a larger budget stays' );
        $this->assertSame( 120, expAiModelTest::outputBudget( 'xtd.plain', 120, $config ) );
        $this->assertSame( 120, expAiModelTest::outputBudget( '', 120, $config ) );
        $this->assertSame( 120, expAiModelTest::outputBudget( 'xtd.unknown', 120, $config ) );
    }

    public function testTheAltTextBudget(): void
    {
        $config = $this->models( array( 'AltTextSettings' => array( 'MaxTokens' => '300' ) ) );
        $this->assertSame( 300, expAiAltText::maxTokens( $config ) );
        $this->assertSame( 300, expAiAltText::maxTokens( $config, 'xtd.plain' ) );
        $this->assertSame( 1000, expAiAltText::maxTokens( $config, 'xtd.thinker' ) );
        $this->assertSame( 1000, expAiAltText::maxTokens( $config, 'xtd.flagged' ) );
    }
}
