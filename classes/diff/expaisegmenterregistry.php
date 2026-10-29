<?php
/**
 * File containing the expAiSegmenterRegistry class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The segmenter of a datatype: expai.ini [DiffSettings] Segmenters[<datatype>]=<class implementing expAiSegmenter>,
 * else FallbackSegmenter (expAiSegmenterGeneric). A class that does not exist or does not implement the interface
 * is ignored (the fallback is used and a warning is logged).
 */
class expAiSegmenterRegistry
{
    /** the built-in map, used when the settings name none */
    protected static $defaults = array( 'ezstring' => 'expAiSegmenterString', 'ezemail' => 'expAiSegmenterString', 'ezisbn' => 'expAiSegmenterString',
                                        'eztext' => 'expAiSegmenterText', 'ezxmltext' => 'expAiSegmenterRichText', 'ezrichtext' => 'expAiSegmenterRichText',
                                        'ezkeyword' => 'expAiSegmenterKeyword' );

    /** @return string the class name of the segmenter of a datatype */
    public static function className( $datatype, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $map = $config->get( 'DiffSettings', 'Segmenters', array() );
        $map = is_array( $map ) && $map ? $map : self::$defaults;
        $class = isset( $map[$datatype] ) ? (string) $map[$datatype] : '';
        if ( $class !== '' && class_exists( $class ) && in_array( 'expAiSegmenter', class_implements( $class ), true ) )
            return $class;
        if ( $class !== '' )
            eZDebug::writeWarning( "The segmenter $class of $datatype does not exist or does not implement expAiSegmenter", __METHOD__ );
        $fallback = (string) $config->get( 'DiffSettings', 'FallbackSegmenter', 'expAiSegmenterGeneric' );
        return class_exists( $fallback ) && in_array( 'expAiSegmenter', class_implements( $fallback ), true ) ? $fallback : 'expAiSegmenterGeneric';
    }

    /** @return expAiSegmenter */
    public static function forDatatype( $datatype, ?expAiConfig $config = null )
    {
        $class = self::className( (string) $datatype, $config );
        return new $class();
    }
}
