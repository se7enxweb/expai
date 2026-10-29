<?php
/**
 * File containing the expAiChainPrompt class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The prompts of chain steps. A prompt is a template file (PromptTemplate=expai/chains/article_write.tpl, looked up in
 * the templates directories of [ChainSettings] PromptDirectories[], the first that has it wins) or a settings text
 * (PromptText=). Both use the same placeholders, written like template variables so the files stay valid templates:
 *
 *   {$brief} {$chain} {$class_name} {$class_identifier} {$fields} {$language} {$location} {$context} {$item}
 *   {$item_count} {$document} {$issues} {$checklist} {$note} {$seo}
 *
 * Template comments {* ... *} are removed. A placeholder without a value becomes empty. The text is not run through
 * the template engine: the prompt is data handed to a model and the values come from people and earlier steps.
 */
class expAiChainPrompt
{
    /**
     * The file of a prompt template.
     * @return string|null
     */
    public static function find( $relative, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $relative = ltrim( (string) $relative, '/' );
        if ( $relative === '' || strpos( $relative, '..' ) !== false )
            return null;
        $dirs = $config->arr( 'ChainSettings', 'PromptDirectories' );
        $dirs[] = 'extension/expai/design/admin/templates';
        foreach ( $dirs as $dir )
        {
            $dir = rtrim( trim( (string) $dir ), '/' );
            if ( $dir === '' || strpos( $dir, '..' ) !== false )
                continue;
            $file = $dir . '/' . $relative;
            if ( is_file( $file ) )
                return $file;
        }
        return null;
    }

    /**
     * Fills a prompt.
     * @param string $text
     * @param array $values name => string
     * @return string
     */
    public static function render( $text, array $values )
    {
        $text = preg_replace( '/\{\*.*?\*\}\s*/s', '', (string) $text );
        $text = preg_replace_callback( '/\{\$([a-z_]+)\}/', function ( $m ) use ( $values )
        {
            return isset( $values[$m[1]] ) && is_scalar( $values[$m[1]] ) ? (string) $values[$m[1]] : '';
        }, $text );
        return trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
    }

    /** The system prompt every chain step shares ([ChainSettings] SystemPrompt adds to it). */
    public static function system( array $step, expAiConfig $config )
    {
        $extra = trim( (string) $config->get( 'ChainSettings', 'SystemPrompt', '' ) );
        return implode( "\n", array(
            'You are one step ("' . $step['label'] . '") of an editorial pipeline of a content management system. Other steps and an editor review your work before anything is published.',
            'Answer only with the JSON object the response format describes, nothing before or after it.',
            expAiPrompts::rules(),
        ) ) . ( $extra !== '' ? "\n" . $extra : '' );
    }
}
