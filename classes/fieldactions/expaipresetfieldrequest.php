<?php
/**
 * File containing the expAiPresetFieldRequest class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A field request of a preset or an Improve entry (expAiEditPresets). Besides a whole field it can address one part
 * of an xrowmetadata field (title, description, keywords): the part is described to the model as a plain text field
 * with the length xrowmetadata.ini allows, and the answer is cleaned the same way.
 */
class expAiPresetFieldRequest extends expAiFieldRequest
{
    /** The parts of an xrowmetadata field a preset may write */
    const METADATA_PARTS = array( 'title', 'description', 'keywords' );

    /** @var string '' (the whole field) or a part of an xrowmetadata field */
    public $part = '';

    /** @return int the most characters of the part (0: no limit) */
    public function partMaxLength()
    {
        if ( $this->part === 'keywords' )
            return 1000;
        $ini = eZINI::instance( 'xrowmetadata.ini' );
        $name = $this->part === 'title' ? 'MaxTitle' : 'MaxDescription';
        $max = $ini->hasVariable( 'EditorInputSettings', $name ) ? (int) $ini->variable( 'EditorInputSettings', $name ) : 0;
        return $max > 0 ? $max : 255;
    }

    public function describeField()
    {
        if ( $this->part === '' )
            return parent::describeField();
        $what = array( 'title' => 'the page title for search results and sharing', 'description' => 'the meta description for search results',
                       'keywords' => 'the keywords, comma separated' );
        return 'Field: the ' . $this->part . ' of "' . $this->classAttribute->attribute( 'name' ) . '" (identifier '
             . $this->classAttribute->attribute( 'identifier' ) . '.' . $this->part . ', page metadata: ' . $what[$this->part]
             . ', format: plain text on one line, at most ' . $this->partMaxLength() . ' characters)';
    }

    public function clean( $value )
    {
        if ( $this->part === '' )
            return parent::clean( $value );
        $value = expAiText::oneLine( strip_tags( (string) $value ) );
        if ( $this->part === 'keywords' )
            $value = implode( ', ', array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
        else
            $value = (string) preg_replace( '/^[\s"\'\x{201C}\x{201D}\x{201E}]+|[\s"\'\x{201C}\x{201D}]+$/u', '', $value );
        return mb_substr( $value, 0, $this->partMaxLength(), 'UTF-8' );
    }
}
