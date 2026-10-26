<?php
/**
 * File containing the expAiFakeTranslationProvider class.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A machine translation service that never leaves the process (the tests of translation services): it "translates"
 * by putting the target's code in front of every text, keeps the markup it got, and records every batch. A test sets
 * $failure (a Throwable thrown by the next batch) or $answer (a callable( array texts, string format ): string[]).
 * Configure it as [TranslationProvider_fake] Class=expAiFakeTranslationProvider; no key, no endpoint needed.
 */
class expAiFakeTranslationProvider extends expAiTranslationProviderBase
{
    /** @var array list of array( texts, format, context, source, target ) */
    public static $batches = array();

    /** @var Throwable|null thrown by the next batch */
    public static $failure = null;

    /** @var callable|null function( array $texts, string $format ): string[] */
    public static $answer = null;

    /** @var int|null the characters the next batches report as billed (null: not reported) */
    public static $billed = null;

    public static function reset()
    {
        self::$batches = array();
        self::$failure = null;
        self::$answer = null;
        self::$billed = null;
    }

    protected function defaultEndpoint()
    {
        return 'https://translation.invalid/translate';
    }

    protected function keyRequired()
    {
        return $this->flag( 'ApiKeyRequired', false );
    }

    protected function sendBatch( array $texts, $format, $context, expAiTranslationRequest $request )
    {
        self::$batches[] = array( $texts, $format, $context, $this->sourceCode( $request->source ), $this->targetCode( $request->target ) );
        if ( self::$failure )
        {
            $failure = self::$failure;
            self::$failure = null;
            throw $failure;
        }
        $prefix = '[' . $this->targetCode( $request->target ) . '] ';
        $out = self::$answer ? call_user_func( self::$answer, $texts, $format ) : array_map( function ( $text ) use ( $prefix ) { return $prefix . $text; }, $texts );
        return array( 'texts' => $out, 'billed' => self::$billed, 'status' => 200, 'retries' => 0, 'engine' => 'fake-mt' );
    }
}
