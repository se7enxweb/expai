<?php
/**
 * The text of a model's answer: reasoning that is not part of the answer (think tags, the Harmony channels, the
 * reasoning fields of a Chat Completions message) and the JSON object in an answer written as prose. No network, no
 * database.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiModelTextTest extends expAiProviderTestCase
{
    // ------------------------------------------------------------------ expAiText::stripReasoning()

    public function testHarmonyKeepsOnlyTheFinalChannel(): void
    {
        $text = '<|start|>assistant<|channel|>analysis<|message|>The user wants a short answer.<|end|>'
              . '<|start|>assistant<|channel|>final<|message|>The answer.<|return|>';
        $this->assertSame( 'The answer.', expAiText::stripReasoning( $text ) );
        $this->assertSame( '{"a":1}', expAiText::stripReasoning( '<|channel|>analysis<|message|>x<|end|><|channel|>final <|constrain|>json<|message|>{"a":1}<|call|>' ) );
        $this->assertSame( 'Open end', expAiText::stripReasoning( '<|channel|>final<|message|> Open end ' ), 'without an end marker the text runs to the end' );
        $this->assertSame( '', expAiText::stripReasoning( '<|channel|>analysis<|message|>only thinking<|end|>' ), 'no final channel: no answer' );
    }

    public function testHarmonyEndsAtChatTemplateTokensAndDropsKnownTokens(): void
    {
        $this->assertSame( 'Hallo', expAiText::stripReasoning( '<|channel|>final<|message|>Hallo <|im_end|>' ) );
        $this->assertSame( 'Hi', expAiText::stripReasoning( '<|channel|>final<|message|>Hi <|im_start|>user' ) );
        $this->assertSame( 'A B', expAiText::stripReasoning( '<|channel|>final<|message|>A <|constrain|>B<|eot_id|>' ) );
        // an unknown token in the final channel is text
        $this->assertSame( 'Use x <|a|> y', expAiText::stripReasoning( '<|channel|>final<|message|>Use x <|a|> y<|return|>' ) );
        // a mention of the channel token without the Harmony structure is text
        $this->assertSame( 'The <|channel|> token starts a header.', expAiText::stripReasoning( 'The <|channel|> token starts a header.' ) );
    }

    public function testALeadingThinkBlockIsRemoved(): void
    {
        $this->assertSame( 'Hello', expAiText::stripReasoning( "<think>\nsome reasoning\n</think>\n\nHello" ) );
        $this->assertSame( 'A', expAiText::stripReasoning( " \n<THINK reason=\"x\">one</THINK ><think>two</think>A" ) );
        $this->assertSame( 'Plain answer.', expAiText::stripReasoning( "  Plain answer.\n" ) );
        // a block later in the text is not a leading reasoning block
        $this->assertSame( 'A <think>two</think>B', expAiText::stripReasoning( 'A <think>two</think>B' ) );
    }

    public function testALoneClosingTagOnItsOwnLineEndsTheReasoning(): void
    {
        // the opening tag is part of the chat template (Qwen)
        $this->assertSame( 'Answer', expAiText::stripReasoning( "reasoning here\n</think>\n\nAnswer" ) );
        $this->assertSame( 'Answer', expAiText::stripReasoning( "reasoning here</think>\n\nAnswer" ) );
        $this->assertSame( 'Answer', expAiText::stripReasoning( "</think>\n\nAnswer" ) );
        $this->assertSame( "b\n</think>\nc", expAiText::stripReasoning( "a</think>\nb\n</think>\nc" ), 'the first closing tag ends the reasoning' );
        // reasoning without an answer
        $this->assertSame( '', expAiText::stripReasoning( "only reasoning\n</think>\n" ) );
    }

    public function testLegitimateMentionsOfTagsAndTokensAreKept(): void
    {
        foreach ( array( 'The <think> tag is used by Qwen.', 'Use x <|a|> y in Haskell', 'Close the block with </think> and go on.',
                         'Write <think>...</think> around the reasoning.', 'Hello </think', 'Is <|im_end|> a token? Yes.' ) as $text )
            $this->assertSame( $text, expAiText::stripReasoning( $text ) );
    }

    public function testAnOpenThinkAtTheStartOrALeakedTokenAtTheEnd(): void
    {
        $this->assertSame( '', expAiText::stripReasoning( '<think>still thinking when the tokens ran out' ) );
        $this->assertSame( '', expAiText::stripReasoning( "<think>done</think>\n<think>still thinking" ) );
        $this->assertSame( 'Hello', expAiText::stripReasoning( 'Hello<|im_end|>' ) );
        $this->assertSame( 'Hello', expAiText::stripReasoning( "Hello <|eot_id|>\n<|endoftext|>" ) );
    }

    /** 1.5 MB of text: no deprecation and no failure of the regular expression engine (its limits never make an answer '') */
    public function testALongTextNeverFailsOnTheRegularExpressionEngine(): void
    {
        $long = str_repeat( 'Ein langer Satz mit {Klammern} und <Zeichen>. ', 33000 );
        $this->assertGreaterThan( 1500000, strlen( $long ) );
        $deprecations = array();
        set_error_handler( function ( $no, $message ) use ( &$deprecations ) { $deprecations[] = $message; return true; } );
        try
        {
            $this->assertSame( trim( $long ), expAiText::stripReasoning( $long ) );
            $this->assertSame( 'Answer', expAiText::stripReasoning( "<think>" . $long . "</think>\n\nAnswer" ) );
            $this->assertSame( 'Answer', expAiText::stripReasoning( $long . "\n</think>\n\nAnswer" ) );
            $this->assertSame( '', expAiText::stripReasoning( '<think ' . $long ) );
            $harmony = '<|channel|>analysis<|message|>' . $long . '<|end|><|channel|>final<|message|>' . $long . '<|return|>';
            $this->assertSame( trim( $long ), expAiText::stripReasoning( $harmony ) );
        }
        finally
        {
            restore_error_handler();
        }
        $this->assertSame( array(), $deprecations );
    }

    public function testInvalidUtf8IsReplaced(): void
    {
        $text = expAiText::stripReasoning( "Gr\xFC\xDFe <think>x</think>ok" );
        $this->assertTrue( mb_check_encoding( $text, 'UTF-8' ) );
        $this->assertStringEndsWith( 'ok', $text );
        $this->assertStringStartsWith( 'Gr', $text );
    }

    // ------------------------------------------------------------------ expAiText::extractJson()

    public function testJsonInProseWithBracesInStrings(): void
    {
        $this->assertSame( array( 'answer' => 'use {x} and "}" here' ),
                           expAiText::extractJson( 'Here it is: {"answer": "use {x} and \"}\" here"} Hope that helps.' ) );
        $this->assertSame( array( 'a' => 1 ), expAiText::extractJson( '{"a":1}' ) );
    }

    public function testAFencedJsonBlockIsPreferred(): void
    {
        $this->assertSame( array( 'a' => 1 ), expAiText::extractJson( "Before {\"b\":2,\"long\":\"xxxxxxxxxxxxxxxx\"}\n```json\n{\"a\":1}\n```\nafter" ) );
        $this->assertSame( array( 'c' => 3 ), expAiText::extractJson( "```\n{\"c\":3}\n```" ) );
        $this->assertSame( array( 'd' => 4 ), expAiText::extractJson( "```text\nnot json\n```\n```json\n{\"d\":4}\n```" ) );
    }

    public function testTheLongestCandidateThatDecodesWins(): void
    {
        $this->assertSame( array( 'b' => 2, 'c' => array( 'd' => 3 ) ), expAiText::extractJson( 'First {"a":1} and then {"b":2,"c":{"d":3}}.' ) );
        $this->assertSame( array( 'outer' => array( 'inner' => 1 ) ), expAiText::extractJson( 'x {"outer":{"inner":1}} y' ) );
        $this->assertSame( array( 'ok' => true ), expAiText::extractJson( '{not json} {"ok":true}' ) );
        $this->assertSame( array( 'ok' => true ), expAiText::extractJson( '{ unbalanced {"ok":true}' ) );
    }

    public function testAnAnswerFullOfUnbalancedBracesIsSearchedInBoundedTime(): void
    {
        // every start would scan to the end: quadratic without the limit (4 MB is the default response size)
        $start = microtime( true );
        $this->assertNull( expAiText::extractJson( str_repeat( '{', 4000000 ) ) );
        $this->assertNull( expAiText::extractJson( str_repeat( '{"a":', 400000 ) ) );
        $this->assertLessThan( 5.0, microtime( true ) - $start, 'the scan is bounded by EXTRACT_JSON_MAX_STEPS' );
        $this->assertSame( array( 'ok' => true ), expAiText::extractJson( 'Here: {"ok":true} and an open { brace' ), 'a normal answer still works' );
    }

    public function testNoJsonIsNull(): void
    {
        $this->assertNull( expAiText::extractJson( 'no json here' ) );
        $this->assertNull( expAiText::extractJson( 'still {not json' ) );
        $this->assertNull( expAiText::extractJson( '{"a":' ) );
        $this->assertNull( expAiText::extractJson( '' ) );
    }

    public function testThePromptedProtocolReadsJsonWithoutTheReasoning(): void
    {
        $this->assertSame( array( 'answer' => 'y' ), expAiPromptedToolProvider::extractJson( '<think>{"tool":"x","input":{"long":"reasoning"}}</think>{"answer":"y"}' ) );
        $this->assertSame( array( 'tool' => 'get_node', 'input' => array( 'node_id' => 2 ) ),
                           expAiPromptedToolProvider::extractJson( 'I will call {the tool} now: {"tool":"get_node","input":{"node_id":2}}' ) );
        $this->assertSame( array( 'answer', 'Done.' ), expAiPromptedToolProvider::interpret( '<|channel|>analysis<|message|>{"tool":"x"}<|end|><|channel|>final<|message|>{"answer":"Done."}<|return|>', array(), true ) );
    }

    // ------------------------------------------------------------------ the providers

    private static function openai( array $answers )
    {
        return new expAiOpenAiChatProvider( array( 'ApiKey' => 'test-not-a-key', 'Model' => 'm-1', 'BaseURL' => 'http://127.0.0.1:9/v1', 'ProviderId' => 'xteoai' ),
                                            new expAiProviderTestHttp( $answers ) );
    }

    public function testChatCompletionsTextIsTheFinalAnswerOnly(): void
    {
        $answer = self::openai( array() )->parseAnswer( array( 'choices' => array( array( 'message' => array(
            'content' => '<|channel|>analysis<|message|>hmm<|end|><|start|>assistant<|channel|>final<|message|>Hello.<|return|>' ), 'finish_reason' => 'stop' ) ) ) );
        $this->assertSame( array( array( 'type' => 'text', 'text' => 'Hello.' ) ), $answer['content'] );

        $answer = self::openai( array() )->parseAnswer( array( 'choices' => array( array( 'message' => array( 'content' => "plan</think>\nHi" ) ) ) ) );
        $this->assertSame( 'Hi', $answer['content'][0]['text'] );
    }

    public function testReasoningFieldsNeverBecomeTheAnswer(): void
    {
        foreach ( array( null, '', '<think>unfinished' ) as $content )
        {
            $answer = self::openai( array() )->parseAnswer( array( 'choices' => array( array( 'message' => array(
                'content' => $content, 'reasoning_content' => 'secret thoughts', 'reasoning' => 'more thoughts' ), 'finish_reason' => 'length' ) ) ) );
            $this->assertSame( array(), $answer['content'], var_export( $content, true ) );
            $this->assertSame( 'max_tokens', $answer['stop_reason'] );
            $this->assertStringNotContainsString( 'secret thoughts', $answer['content_json'] );
        }
        $assembled = expAiOpenAiChatProvider::assembleStream( "data: {\"choices\":[{\"delta\":{\"reasoning_content\":\"thinking\"}}]}\n\n"
                                                            . "data: {\"choices\":[{\"delta\":{\"content\":\"Answer\"},\"finish_reason\":\"stop\"}]}\n\n" );
        $answer = self::openai( array() )->parseAnswer( $assembled );
        $this->assertSame( array( array( 'type' => 'text', 'text' => 'Answer' ) ), $answer['content'] );
    }

    public function testOllamaNativeAnswerWithoutReasoning(): void
    {
        $answer = expAiOllamaNativeProvider::parseAnswer( array( 'message' => array( 'content' => "thinking</think>\n\nWaves roll in." ) ) );
        $this->assertSame( 'Waves roll in.', $answer['content'][0]['text'] );
        $answer = expAiOllamaNativeProvider::parseAnswer( array( 'message' => array( 'content' => '<think>cut off' ) ) );
        $this->assertSame( '', $answer['content'][0]['text'] );
    }
}
