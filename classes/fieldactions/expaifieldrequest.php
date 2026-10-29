<?php
/**
 * File containing the expAiFieldRequest class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * What a field action works on: the draft (checked: the current user's own draft, editable), the field, its
 * current value in the form, the other fields of the form and the editor's input.
 */
class expAiFieldRequest
{
    /** @var eZContentObject */
    public $object;

    /** @var eZContentObjectVersion */
    public $version;

    /** @var string */
    public $language = '';

    /** @var eZContentObjectAttribute */
    public $attribute;

    /** @var eZContentClassAttribute */
    public $classAttribute;

    /** @var string */
    public $datatype = '';

    /** @var string the value in the form (rich text: the editor's HTML) */
    public $value = '';

    /** @var array identifier => value of the other fields in the form */
    public $context = array();

    /** @var string */
    public $instruction = '';

    /** @var string target language code (translate) */
    public $targetLanguage = '';

    /** @return string a description of the field for a prompt */
    public function describeField()
    {
        $reader = new expAiContentReader();
        $text = 'Field: "' . $this->classAttribute->attribute( 'name' ) . '" (identifier ' . $this->classAttribute->attribute( 'identifier' )
              . ', datatype ' . $this->datatype . ', format: ' . $reader->formatHint( $this->datatype );
        if ( $this->classAttribute->attribute( 'is_required' ) )
            $text .= ', required';
        if ( $this->datatype === 'ezstring' && (int) $this->classAttribute->attribute( 'data_int1' ) > 0 )
            $text .= ', at most ' . (int) $this->classAttribute->attribute( 'data_int1' ) . ' characters';
        $description = trim( (string) $this->classAttribute->attribute( 'description' ) );
        if ( $description !== '' )
            $text .= ', description: ' . expAiText::cut( expAiText::oneLine( $description ), 200 );
        return $text . ')';
    }

    /** @return string a description of the object for a prompt */
    public function describeObject()
    {
        $class = $this->object->contentClass();
        return 'Object: "' . expAiText::oneLine( $this->object->versionLanguageName( (int) $this->version->attribute( 'version' ), $this->language ) ?: $this->object->attribute( 'name' ) )
             . '" (class ' . $class->attribute( 'name' ) . ', language ' . $this->language . ')';
    }

    /** @return string the other fields, shortened */
    public function describeContext( $max = 8000 )
    {
        $lines = array();
        foreach ( $this->context as $identifier => $value )
        {
            if ( $identifier === $this->classAttribute->attribute( 'identifier' ) || !is_scalar( $value ) || trim( (string) $value ) === '' )
                continue;
            $lines[] = $identifier . ': ' . expAiText::cut( trim( (string) $value ), 3000 );
        }
        return expAiText::cut( implode( "\n", $lines ), $max );
    }

    /** @return string the answer cleaned for the field's datatype */
    public function clean( $value )
    {
        $value = (string) $value;
        switch ( $this->datatype )
        {
            case 'ezxmltext':
                return expAiText::sanitizeHtml( $value );
            case 'ezstring':
                $value = expAiText::oneLine( strip_tags( $value ) );
                $max = (int) $this->classAttribute->attribute( 'data_int1' );
                return $max > 0 ? mb_substr( $value, 0, $max, 'UTF-8' ) : $value;
            case 'ezkeyword':
                return implode( ', ', array_filter( array_map( 'trim', explode( ',', strip_tags( $value ) ) ) ) );
            default:
                return preg_match( '/<\s*(p|br|li|h[1-6])\b/i', $value ) ? expAiText::htmlToText( $value ) : trim( $value );
        }
    }
}
