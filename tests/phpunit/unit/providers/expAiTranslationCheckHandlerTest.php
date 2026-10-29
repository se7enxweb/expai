<?php
/**
 * expAiTranslationCheckHandler, the queue job that runs the translation sync's check after a publish.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaiprovidertesthelpers.php';

class expAiTranslationCheckHandlerTest extends expAiProviderTestCase
{
    private static function job( array $payload )
    {
        return new expQueueJob( array( 'id' => 0, 'uuid' => '00000000-0000-4000-8000-000000000387', 'type' => 'expai.translation.check', 'state' => 'running',
                                       'payload' => json_encode( $payload ), 'user_id' => 14 ) );
    }

    public function testValidationAndDescription(): void
    {
        $handler = new expAiTranslationCheckHandler();
        $this->assertSame( 'object_id is required', $handler->validate( array() ) );
        $this->assertNull( $handler->validate( array( 'object_id' => 5 ) ) );
        $this->assertSame( 'Translation check of object 5 version 3', $handler->describe( self::job( array( 'object_id' => 5, 'version' => 3 ) ) ) );
        $this->assertSame( 'Translation check of object 5', $handler->describe( self::job( array( 'object_id' => '5' ) ) ) );
    }

    public function testAnObjectThatIsGoneIsAPermanentFailure(): void
    {
        $this->requireDatabase();
        $job = self::job( array( 'object_id' => 999999999 ) );
        try
        {
            ( new expAiTranslationCheckHandler() )->run( $job, new expQueueContext( $job, 'xte' ) );
            $this->fail( 'no exception' );
        }
        catch ( expQueuePermanentException $e )
        {
            $this->assertSame( 'the object is gone', $e->getMessage() );
        }
    }

    public function testTheCheckIsSkippedWhenTheSyncIsOffAndReportsEveryLanguageOtherwise(): void
    {
        $this->requireDatabase();
        $folder = $this->folder( 'expai unit test translation check' );
        $job = self::job( array( 'object_id' => (int) $folder->attribute( 'contentobject_id' ) ) );
        $context = new expQueueContext( $job, 'xte' );

        $this->site( array( 'TranslationSync' => array( 'Enabled' => 'false' ) ) );
        $this->assertSame( array( 'skipped' => 'disabled' ), ( new expAiTranslationCheckHandler() )->run( $job, $context ) );

        // a folder in one language: no translation to check
        $this->site( array( 'TranslationSync' => array( 'Enabled' => 'true' ) ) );
        $this->assertSame( array( 'languages' => array() ), ( new expAiTranslationCheckHandler() )->run( $job, $context ) );
    }
}
