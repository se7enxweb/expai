<?php
/**
 * The readers of structured (JSON) answers: a chain step (expAiChainEngine::answerValue()), an alt text
 * (expAiAltText::parse()) and a field action (expAiFieldActionRegistry::parseStructured()). An answer cut off at the
 * token limit is never read, it fails with a hint for the editor; JSON in words, a code fence or after reasoning is
 * found. No database.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiStructuredAnswerTest extends expAiCoreTestCase
{
    const HINT = 'The answer was cut off at the token limit. Raise MaxTokens or lower Effort for this model.';

    private static function answer( $text, $stop = 'end_turn' )
    {
        return array( 'content' => array( array( 'type' => 'text', 'text' => $text ) ), 'stop_reason' => $stop, 'model' => 'stub-model' );
    }

    private function assertCutOff( callable $read ): void
    {
        try
        {
            $read();
            $this->fail( 'an answer cut off at the token limit must not be read' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( self::HINT, $e->getMessage() );
            $this->assertStringContainsString( 'max_tokens', $e->detail() );
        }
    }

    public function testAChainStepReadsItsValueAndRefusesACutOffAnswer(): void
    {
        $this->assertSame( array( 'note' => 'v' ), expAiChainEngine::answerValue( array( 'value' => array( 'note' => 'v' ), 'text' => 'ignored', 'stop_reason' => 'end_turn' ) ) );
        $this->assertSame( array( 'note' => 'in words' ),
                           expAiChainEngine::answerValue( array( 'text' => "Here it is:\n```json\n{\"note\": \"in words\"}\n```\nDone.", 'stop_reason' => 'end_turn' ) ) );
        $this->assertSame( array( 'note' => 'after thinking' ),
                           expAiChainEngine::answerValue( array( 'text' => '<think>{"draft": 1}</think>{"note": "after thinking"}', 'stop_reason' => 'end_turn' ) ) );
        $this->assertNull( expAiChainEngine::answerValue( array( 'text' => 'No JSON here.', 'stop_reason' => 'end_turn' ) ) );
        $this->assertNull( expAiChainEngine::answerValue( array() ) );
        // a complete inner object of a cut answer is not the answer, nor a value that was read before the check
        $this->assertCutOff( function () { expAiChainEngine::answerValue( array( 'text' => '{"fields": {"title": "Spring"}, "note": "', 'stop_reason' => 'max_tokens' ) ); } );
        $this->assertCutOff( function () { expAiChainEngine::answerValue( array( 'value' => array( 'fields' => array() ), 'stop_reason' => 'max_tokens' ) ); } );
    }

    public function testAnAltTextAnswerIsReadOrRefused(): void
    {
        $data = expAiAltText::parse( self::answer( 'Sure. {"alt_text": "A red bicycle {rented}", "decorative": false, "note": ""} Hope this helps.' ) );
        $this->assertSame( 'A red bicycle {rented}', $data['alt_text'] );
        $this->assertCutOff( function () { expAiAltText::parse( self::answer( '{"alt_text": "A red bi', 'max_tokens' ) ); } );
        $this->assertCutOff( function () { expAiAltText::parse( self::answer( '{"alt_text": "Complete", "decorative": false}', 'max_tokens' ) ); } );
        try
        {
            expAiAltText::parse( self::answer( '{"note": "no alt text"}' ) );
            $this->fail( 'an answer without alt_text is not read' );
        }
        catch ( expAiException $e )
        {
            $this->assertSame( 'The answer of Exponential Intelligence could not be read.', $e->getMessage() );
        }
        $this->expectExceptionMessage( 'Exponential Intelligence declined this request.' );
        expAiAltText::parse( self::answer( '', 'refusal' ) );
    }

    public function testAFieldActionAnswerIsReadOrRefused(): void
    {
        $request = new expAiFieldRequest();
        $request->datatype = 'eztext';
        $result = expAiFieldActionRegistry::parseStructured( self::answer( "```json\n{\"value\": \"Shorter text.\", \"note\": \"cut a sentence\"}\n```" ), $request );
        $this->assertSame( array( 'value' => 'Shorter text.', 'note' => 'cut a sentence', 'model' => 'stub-model' ), $result );
        $this->assertCutOff( function () use ( $request ) { expAiFieldActionRegistry::parseStructured( self::answer( '{"value": "Shorter te', 'max_tokens' ), $request ); } );
        $this->expectExceptionMessage( 'The answer of Exponential Intelligence could not be read.' );
        expAiFieldActionRegistry::parseStructured( self::answer( '<think>{"value": "a thought"}' ), $request );
    }

    public function testAQueuedAnswerCutOffAtTheTokenLimitHasNoValue(): void
    {
        // the cut list of a translation: its first element decodes, but it is not the answer
        $cut = '{"units": [{"id": 1, "text": "Hallo"}, {"id": 2, "text": "Wel';
        $stored = expAiRequestHandler::storedAnswer( self::answer( $cut, 'max_tokens' ), 'standard' );
        $this->assertNull( $stored['value'] );
        $this->assertSame( 'max_tokens', $stored['stop_reason'] );
        $this->assertSame( $cut, $stored['text'] );
        // a value read from the text would hand the appliers a part of the answer
        $this->assertSame( array( 'id' => 1, 'text' => 'Hallo' ), expAiText::extractJson( $cut ) );
        // a complete answer keeps its value
        $stored = expAiRequestHandler::storedAnswer( self::answer( '{"units": [{"id": 1, "text": "Hallo"}]}' ), 'standard' );
        $this->assertSame( array( 'units' => array( array( 'id' => 1, 'text' => 'Hallo' ) ) ), $stored['value'] );
    }
}
