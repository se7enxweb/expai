<?php
/**
 * An answer with neither words nor a tool call (a reasoning model that only thought) in the panel's tool loop
 * (expAiAssistant::answerOrRetry()): the step is asked once more with a short note, then it fails with a clear
 * message; at the token limit it fails at once; after tools ran in the turn it ends the turn. The provider is
 * scripted in memory; no request leaves the server. No database.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiEmptyAnswerTestProvider implements expAiProvider
{
    public $answers = array();
    public $requests = array();

    public function __construct( array $answers )
    {
        $this->answers = $answers;
    }

    public function send( array $request )
    {
        $this->requests[] = $request;
        $answer = array_shift( $this->answers );
        if ( $answer === null )
            throw new RuntimeException( 'expAiEmptyAnswerTestProvider: no answer left' );
        return $answer;
    }

    public function modelName()
    {
        return 'stub-model';
    }

    public function providerId()
    {
        return 'xtd';
    }

    /** an answer with these content blocks */
    public static function answer( array $blocks, $stop = 'end_turn' )
    {
        return array( 'content' => $blocks, 'content_json' => json_encode( $blocks ), 'stop_reason' => $stop, 'model' => 'stub-model',
                      'tokens' => array( 'input' => 10, 'output' => 5 ) );
    }
}

/** expAiAssistant with the retry of an empty answer reachable from the test */
class expAiEmptyAnswerTestAssistant extends expAiAssistant
{
    public function withProvider( expAiProvider $provider )
    {
        $this->provider = $provider;
        return $this;
    }

    public function retry( array $call, array $answer, $toolsRan = false )
    {
        return $this->answerOrRetry( $call, $answer, $toolsRan );
    }
}

class expAiEmptyAnswerTest extends expAiCoreTestCase
{
    private static function call( $content = null )
    {
        return array( 'system' => 's', 'tools' => array(),
                      'messages' => array( array( 'role' => 'user', 'content' => $content !== null ? $content : array( array( 'type' => 'text', 'text' => 'Hello' ) ) ) ) );
    }

    private function assistant( expAiProvider $provider )
    {
        $assistant = new expAiEmptyAnswerTestAssistant( $this->useSettings() );
        return $assistant->withProvider( $provider );
    }

    public function testWhatCountsAsAnEmptyAnswer(): void
    {
        $this->assertTrue( expAiAssistant::isEmptyAnswer( expAiEmptyAnswerTestProvider::answer( array() ) ) );
        $this->assertTrue( expAiAssistant::isEmptyAnswer( expAiEmptyAnswerTestProvider::answer( array( array( 'type' => 'text', 'text' => "  \n " ) ) ) ) );
        $this->assertTrue( expAiAssistant::isEmptyAnswer( expAiEmptyAnswerTestProvider::answer( array( array( 'type' => 'thinking', 'thinking' => 'Let me think.' ) ) ) ),
                           'reasoning alone is no answer' );
        $this->assertTrue( expAiAssistant::isEmptyAnswer( expAiEmptyAnswerTestProvider::answer( array( array( 'type' => 'text', 'text' => '<think>Only thoughts.</think>' ) ) ) ) );
        $this->assertTrue( expAiAssistant::isEmptyAnswer( expAiEmptyAnswerTestProvider::answer( array(), 'max_tokens' ) ) );
        $this->assertFalse( expAiAssistant::isEmptyAnswer( expAiEmptyAnswerTestProvider::answer( array( array( 'type' => 'text', 'text' => 'Hi.' ) ) ) ) );
        $this->assertFalse( expAiAssistant::isEmptyAnswer( expAiEmptyAnswerTestProvider::answer( array( array( 'type' => 'tool_use', 'id' => 't1', 'name' => 'get_node', 'input' => array() ) ) ) ) );
        $this->assertFalse( expAiAssistant::isEmptyAnswer( expAiEmptyAnswerTestProvider::answer( array(), 'refusal' ) ), 'a refusal is handled as a refusal' );
    }

    public function testAnAnswerWithWordsIsTakenAsItIs(): void
    {
        $provider = new expAiEmptyAnswerTestProvider( array() );
        $answer = expAiEmptyAnswerTestProvider::answer( array( array( 'type' => 'text', 'text' => 'Done.' ) ) );
        $this->assertSame( $answer, $this->assistant( $provider )->retry( self::call(), $answer ) );
        $this->assertCount( 0, $provider->requests, 'no second request' );
    }

    public function testAnEmptyAnswerIsAskedOnceMoreWithANote(): void
    {
        $second = expAiEmptyAnswerTestProvider::answer( array( array( 'type' => 'text', 'text' => 'The node is called Home.' ) ) );
        $provider = new expAiEmptyAnswerTestProvider( array( $second ) );
        $call = self::call();
        $answer = $this->assistant( $provider )->retry( $call, expAiEmptyAnswerTestProvider::answer( array( array( 'type' => 'thinking', 'thinking' => '...' ) ) ) );
        $this->assertSame( $second, $answer );
        $this->assertCount( 1, $provider->requests );
        $messages = $provider->requests[0]['messages'];
        $this->assertCount( 1, $messages, 'the note joins the last user message' );
        $this->assertSame( array( array( 'type' => 'text', 'text' => 'Hello' ), array( 'type' => 'text', 'text' => expAiAssistant::EMPTY_ANSWER_NUDGE ) ),
                           $messages[0]['content'] );
        $this->assertSame( 'Answer the editor in words now, or call a tool.', expAiAssistant::EMPTY_ANSWER_NUDGE );
        $this->assertSame( array( 'type' => 'text', 'text' => 'Hello' ), $call['messages'][0]['content'][0], 'the request of the step is unchanged' );
        $this->assertCount( 1, $call['messages'][0]['content'] );
    }

    public function testTheNoteFollowsToolResultsAndPlainText(): void
    {
        $words = expAiEmptyAnswerTestProvider::answer( array( array( 'type' => 'text', 'text' => 'Ok.' ) ) );
        $provider = new expAiEmptyAnswerTestProvider( array( $words, $words ) );
        $assistant = $this->assistant( $provider );
        $results = array( array( 'type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '{}' ) );
        $assistant->retry( self::call( $results ), expAiEmptyAnswerTestProvider::answer( array() ) );
        $this->assertSame( array( 'tool_result', 'text' ), array_column( $provider->requests[0]['messages'][0]['content'], 'type' ) );

        $assistant->retry( self::call( 'Plain text' ), expAiEmptyAnswerTestProvider::answer( array() ) );
        $this->assertSame( array( array( 'type' => 'text', 'text' => 'Plain text' ), array( 'type' => 'text', 'text' => expAiAssistant::EMPTY_ANSWER_NUDGE ) ),
                           $provider->requests[1]['messages'][0]['content'] );
    }

    public function testAToolCallInTheSecondAnswerIsAnAnswer(): void
    {
        $tool = expAiEmptyAnswerTestProvider::answer( array( array( 'type' => 'tool_use', 'id' => 't2', 'name' => 'get_node', 'input' => array() ) ), 'tool_use' );
        $provider = new expAiEmptyAnswerTestProvider( array( $tool ) );
        $this->assertSame( $tool, $this->assistant( $provider )->retry( self::call(), expAiEmptyAnswerTestProvider::answer( array() ) ) );
    }

    public function testTwoEmptyAnswersFailWithAClearMessage(): void
    {
        $provider = new expAiEmptyAnswerTestProvider( array( expAiEmptyAnswerTestProvider::answer( array() ) ) );
        try
        {
            $this->assistant( $provider )->retry( self::call(), expAiEmptyAnswerTestProvider::answer( array() ) );
            $this->fail( 'two empty answers must fail' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'The model gave no answer. Try again, or choose another model.', $e->getMessage() );
            $this->assertSame( 'empty answer twice', $e->detail() );
        }
        $this->assertCount( 1, $provider->requests, 'asked once more, not again and again' );
    }

    public function testTwoEmptyAnswersAtTheTokenLimitNameTheLimit(): void
    {
        $provider = new expAiEmptyAnswerTestProvider( array( expAiEmptyAnswerTestProvider::answer( array(), 'max_tokens' ) ) );
        $this->expectException( expAiException::class );
        $this->expectExceptionMessage( 'The answer was cut off at the token limit. Raise MaxTokens or lower Effort for this model.' );
        $this->assistant( $provider )->retry( self::call(), expAiEmptyAnswerTestProvider::answer( array( array( 'type' => 'text', 'text' => '' ) ) ) );
    }

    public function testAnEmptyAnswerAtTheTokenLimitIsNotAskedAgainWithTheSameLimit(): void
    {
        $provider = new expAiEmptyAnswerTestProvider( array( expAiEmptyAnswerTestProvider::answer( array( array( 'type' => 'text', 'text' => 'Hi.' ) ) ) ) );
        try
        {
            $this->assistant( $provider )->retry( self::call(), expAiEmptyAnswerTestProvider::answer( array( array( 'type' => 'thinking', 'thinking' => '...' ) ), 'max_tokens' ) );
            $this->fail( 'an empty answer at the token limit must fail at once' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'The answer was cut off at the token limit. Raise MaxTokens or lower Effort for this model.', $e->getMessage() );
            $this->assertSame( 'empty answer, stop_reason max_tokens', $e->detail() );
        }
        $this->assertCount( 0, $provider->requests, 'not asked again with the same budget' );
    }

    public function testAnEmptyAnswerAfterToolsRanEndsTheTurn(): void
    {
        $provider = new expAiEmptyAnswerTestProvider( array() );
        $assistant = $this->assistant( $provider );
        $results = array( array( 'type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '{"draft": "1:2"}' ) );
        $answer = $assistant->retry( self::call( $results ), expAiEmptyAnswerTestProvider::answer( array( array( 'type' => 'thinking', 'thinking' => 'Done.' ),
                                                                                                           array( 'type' => 'text', 'text' => '<think>All written.</think>' ) ) ), true );
        $this->assertSame( array( 'thinking' ), array_column( $answer['content'], 'type' ), 'no reasoning as the reply' );
        $this->assertSame( 'end_turn', $answer['stop_reason'] );
        // also at the token limit: what the tools did stays
        $answer = $assistant->retry( self::call( $results ), expAiEmptyAnswerTestProvider::answer( array(), 'max_tokens' ), true );
        $this->assertSame( array(), $answer['content'] );
        $this->assertCount( 0, $provider->requests, 'no second request' );
    }

    public function testAFailureOfTheSecondRequestReachesTheCaller(): void
    {
        $failure = new expAiException( 'Exponential Intelligence could not be reached.', 'curl error 7' );
        $provider = new class( $failure ) implements expAiProvider
        {
            public $failure;
            public function __construct( $failure ) { $this->failure = $failure; }
            public function send( array $request ) { throw $this->failure; }
            public function modelName() { return 'stub-model'; }
            public function providerId() { return 'xtd'; }
        };
        try
        {
            $this->assistant( $provider )->retry( self::call(), expAiEmptyAnswerTestProvider::answer( array() ) );
            $this->fail( 'the failure of the second request must reach the caller' );
        }
        catch ( expAiException $e )
        {
            // as it is: the step's failure handling (the fallback) reads its detail
            $this->assertSame( $failure, $e );
            $this->assertSame( expAiModelFallback::UNREACHABLE, expAiModelFallback::failureKind( $e ) );
        }
    }
}
