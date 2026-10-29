<?php
/**
 * Numbers in machine-readable text do not follow the locale: under a German LC_NUMERIC (decimal comma) the reasons of
 * expAiTranslationSync::decide() still read "0.60", as the stored state and its tests expect. No database.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicoretestcase.php';

class expAiLocaleFormatTest extends expAiCoreTestCase
{
    public function testTheReasonsOfATranslationDecisionUseADecimalPoint(): void
    {
        $before = setlocale( LC_NUMERIC, '0' );
        $this->cleanup( static function () use ( $before ) { setlocale( LC_NUMERIC, $before ); } );
        if ( setlocale( LC_NUMERIC, 'de_DE.UTF-8', 'de_DE.utf8', 'de_DE' ) === false )
            $this->markTestSkipped( 'no German locale on this machine' );
        $this->assertSame( '0,60', sprintf( '%.2f', 0.6 ), 'the locale writes a decimal comma' );

        $config = $this->useSettings( array( 'TranslationSync' => array( 'PatchMaxChangedRatio' => '0.25', 'FullMinChangedRatio' => '0.6', 'PatchMaxSegments' => '40',
                                                                          'CostThreshold' => '1', 'AutoQueuePatch' => 'enabled', 'FullIfStructureMismatch' => 'enabled' ) ) );
        $metrics = function ( $ratio ) { return array( 'change_ratio' => $ratio, 'blocks_changed' => 1, 'hunks' => 1 ); };
        $full = expAiTranslationSync::decide( $metrics( 0.7 ), true, 1, 0, $config );
        $this->assertSame( 'changed ratio 0.70 reaches FullMinChangedRatio 0.60', $full['reason'] );
        $patch = expAiTranslationSync::decide( $metrics( 0.4 ), true, 1, 0, $config );
        $this->assertSame( 'changed ratio 0.40 above PatchMaxChangedRatio 0.25', $patch['reason'] );
    }
}
