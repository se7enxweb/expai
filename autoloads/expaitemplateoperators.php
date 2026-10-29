<?php
/**
 * File containing the expAiTemplateOperators class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * {$amount|expai_money( $approx )}  "$0.06", "< $0.01", with "≈" for an approximate cost (expAiCost::money())
 * {$tokens|expai_tokens}            "950", "21.6k", "1.2M" (expAiCost::tokens())
 * {$value|expai_number( 2 )}        the locale's separators (expAiCost::number())
 * {$retention|expai_retention}     "unknown" translated, every other retention as written
 * {$data|expai_json}                JSON for a <script type="application/json"> block: <, >, &, ' and " are escaped
 *                                   invalid UTF-8 becomes U+FFFD instead of failing
 * {expai_help_url( 'vector' )|ezurl} the address of a help page in the help center
 *                                   (expAiHelpLinks::link()); expai_help_url() the index
 */
class expAiTemplateOperators
{
    public function operatorList()
    {
        return array( 'expai_money', 'expai_tokens', 'expai_number', 'expai_json', 'expai_retention', 'expai_help_url' );
    }

    public function namedParameterPerOperator()
    {
        return true;
    }

    public function namedParameterList()
    {
        return array(
            'expai_money' => array( 'approx' => array( 'type' => 'boolean', 'required' => false, 'default' => false ) ),
            'expai_tokens' => array(),
            'expai_number' => array( 'decimals' => array( 'type' => 'integer', 'required' => false, 'default' => 0 ) ),
            'expai_json' => array(),
            'expai_retention' => array(),
            'expai_help_url' => array( 'page' => array( 'type' => 'string', 'required' => false, 'default' => '' ) ),
        );
    }

    public function modify( $tpl, $operatorName, $operatorParameters, $rootNamespace, $currentNamespace, &$operatorValue, $namedParameters )
    {
        switch ( $operatorName )
        {
            case 'expai_money':
                $operatorValue = expAiCost::money( $operatorValue, !empty( $namedParameters['approx'] ) );
                break;
            case 'expai_tokens':
                $operatorValue = expAiCost::tokens( $operatorValue );
                break;
            case 'expai_number':
                $operatorValue = expAiCost::number( $operatorValue, (int) $namedParameters['decimals'] );
                break;
            case 'expai_json':
                $operatorValue = self::json( $operatorValue );
                break;
            case 'expai_retention':
                $operatorValue = self::retention( $operatorValue );
                break;
            case 'expai_help_url':
                $operatorValue = expAiHelpLinks::link( (string) $namedParameters['page'] );
                break;
        }
    }

    /**
     * A retention as the INI writes it (none, zdr, 30d, unknown): "unknown" is the only word and is translated, the
     * others are values the provider's terms use and stay as they are.
     *
     * @param mixed $value
     * @return string
     */
    public static function retention( $value )
    {
        $value = (string) $value;
        return strtolower( $value ) === 'unknown' ? ezpI18n::tr( 'extension/expai/models', 'unknown' ) : $value;
    }

    /**
     * JSON that is safe inside a <script> element, whatever the strings contain: a "</script>" or "<!--" in a
     * value cannot end or change the element, and a broken UTF-8 sequence does not turn the whole block into the
     * empty string (json_encode() would return false). An unencodable value gives "null" and a warning in the debug log, and the page script reads
     * it as "no configuration".
     *
     * @param mixed $value
     * @return string
     */
    public static function json( $value )
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode( $value, $flags );
        if ( $json === false )
        {
            if ( class_exists( 'eZDebug', false ) )
                eZDebug::writeWarning( 'expai_json: the value cannot be encoded (' . json_last_error_msg() . '); the block is null', __METHOD__ );
            return 'null';
        }
        return $json;
    }
}
