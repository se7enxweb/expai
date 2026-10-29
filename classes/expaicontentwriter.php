<?php
/**
 * File containing the expAiContentWriter class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Writes drafts as the current user and only drafts: a new object under a node (content/create of the user, its
 * class, section, parent class and language limitations), or a new version of an existing object (content/edit).
 * Values go through each datatype's fromString(); rich text goes through the kernel's simplified XML input parser.
 * Nothing is published here.
 */
class expAiContentWriter
{
    /** @var expAiConfig */
    protected $config;

    /** @var array identifier => the guard of the last rich text written into the field (richTextToXml()) */
    public $guarded = array();

    public function __construct( ?expAiConfig $config = null )
    {
        $this->config = $config ?: expAiConfig::instance();
    }

    /** @return string the language a new draft is written in */
    public function defaultLanguage( $language = '' )
    {
        $language = (string) $language;
        if ( $language !== '' )
        {
            if ( !in_array( $language, eZContentLanguage::fetchLocaleList(), true ) )
                throw new expAiException( 'The language %language is not used in this installation.', '', 0, array( '%language' => $language ) );
            return $language;
        }
        return eZContentObject::defaultLanguage();
    }

    /**
     * A new object as a draft (version 1, status draft) owned by the current user.
     * @return array( eZContentObject, eZContentObjectVersion, array result of writeFields )
     * @throws expAiException
     */
    public function createDraft( eZContentObjectTreeNode $parentNode, $classIdentifier, array $fields, $language = '' )
    {
        $class = $this->fetchClass( $classIdentifier );
        if ( !$class instanceof eZContentClass )
            throw new expAiException( 'There is no content class "%identifier".', '', 0, array( '%identifier' => (string) $classIdentifier ) );
        // before anything is written: an unknown field must not leave a half-made object (or an open transaction)
        self::checkFieldIdentifiers( array_keys( $fields ), array_keys( (array) $class->dataMap() ) );
        if ( !$parentNode->canRead() )
            throw new expAiException( 'Node %id does not exist or you may not read it.', '', 0, array( '%id' => (int) $parentNode->attribute( 'node_id' ) ) );
        $language = $this->defaultLanguage( $language );
        $parentObject = $parentNode->object();
        if ( $parentNode->checkAccess( 'create', $class->attribute( 'id' ), $parentObject->attribute( 'contentclass_id' ), false, $language ) != 1 )
            throw new expAiException( 'You may not create %class objects in %language under this node.', '', 0,
                                      array( '%class' => (string) $class->attribute( 'identifier' ), '%language' => $language ) );
        $user = eZUser::currentUser();
        $db = eZDB::instance();
        $db->begin();
        try
        {
            $object = $class->instantiate( (int) $user->attribute( 'contentobject_id' ), (int) $parentObject->attribute( 'section_id' ),
                                           false, $language, eZContentObjectVersion::STATUS_DRAFT );
            if ( !$object instanceof eZContentObject )
                throw new expAiException( 'The draft could not be created.', 'instantiate failed' );
            $object->store();
            $assignment = eZNodeAssignment::create( array(
                'contentobject_id' => $object->attribute( 'id' ),
                'contentobject_version' => $object->attribute( 'current_version' ),
                'parent_node' => (int) $parentNode->attribute( 'node_id' ),
                'is_main' => 1,
                'sort_field' => $class->attribute( 'sort_field' ),
                'sort_order' => $class->attribute( 'sort_order' ) ) );
            $assignment->store();
            $version = $object->version( 1 );
            $version->setAttribute( 'modified', time() );
            $version->setAttribute( 'status', eZContentObjectVersion::STATUS_DRAFT );
            $version->store();
            $result = $this->writeFields( $object, $version, $language, $fields );
            $this->updateName( $object, $version, $language );
            $db->commit();
        }
        catch ( Throwable $e )
        {
            // a nested rollback (writeFields() inside createDraft()) has already ended the whole transaction
            if ( $db->transactionCounter() > 0 )
                $db->rollback();
            throw $e;
        }
        return array( $object, $version, $result );
    }

    /**
     * @param string $identifier
     * @return eZContentClass|null
     */
    protected function fetchClass( $identifier )
    {
        return eZContentClass::fetchByIdentifier( (string) $identifier );
    }

    /**
     * Refuses field identifiers the class does not have. Called before a transaction is opened.
     * @param string[] $identifiers the fields to write
     * @param string[] $valid the identifiers of the class
     * @throws expAiException for an unknown identifier (the message lists the valid ones)
     */
    public static function checkFieldIdentifiers( array $identifiers, array $valid )
    {
        $unknown = array_diff( $identifiers, $valid );
        if ( $unknown )
            throw new expAiException( 'Unknown field(s) %unknown. The fields of this class are: %valid.', '', 0,
                                      array( '%unknown' => implode( ', ', $unknown ), '%valid' => implode( ', ', $valid ) ) );
    }

    /**
     * The draft of an existing object to write into: $reuseVersion when it is a draft of the current user, else a
     * new version (content/edit of the user).
     * @return array( eZContentObjectVersion, string language, bool created )
     * @throws expAiException
     */
    public function draftFor( eZContentObject $object, $language = '', $reuseVersion = 0 )
    {
        $user = eZUser::currentUser();
        $language = (string) $language !== '' ? $this->defaultLanguage( $language ) : (string) $object->attribute( 'initial_language_code' );
        if ( !$object->canRead() )
            throw new expAiException( 'Object %id does not exist or you may not read it.', '', 0, array( '%id' => (int) $object->attribute( 'id' ) ) );
        if ( $reuseVersion )
        {
            $version = $object->version( (int) $reuseVersion );
            if ( $version && (int) $version->attribute( 'status' ) === eZContentObjectVersion::STATUS_DRAFT
                 && (int) $version->attribute( 'creator_id' ) === (int) $user->attribute( 'contentobject_id' ) )
                return array( $version, $version->initialLanguageCode(), false );
        }
        if ( !$object->canEdit( false, false, false, $language ) )
            throw new expAiException( 'You may not edit object %id in %language.', '', 0, array( '%id' => (int) $object->attribute( 'id' ), '%language' => $language ) );
        if ( (int) $object->attribute( 'status' ) !== eZContentObject::STATUS_PUBLISHED )
            throw new expAiException( 'Object %id is not published; open its draft in the edit form instead.', '', 0, array( '%id' => (int) $object->attribute( 'id' ) ) );
        $db = eZDB::instance();
        $db->begin();
        try
        {
            $copyFrom = in_array( $language, array_keys( $object->allLanguages() ), true ) ? false : (string) $object->attribute( 'initial_language_code' );
            $version = $object->createNewVersionIn( $language, $copyFrom, false, true, eZContentObjectVersion::STATUS_DRAFT );
            $version->setAttribute( 'modified', time() );
            $version->setAttribute( 'creator_id', (int) $user->attribute( 'contentobject_id' ) );
            $version->store();
            $db->commit();
        }
        catch ( Throwable $e )
        {
            // a nested rollback (writeFields() inside createDraft()) has already ended the whole transaction
            if ( $db->transactionCounter() > 0 )
                $db->rollback();
            throw $e;
        }
        return array( $version, $language, true );
    }

    /**
     * Writes values into a draft.
     * @param array $fields identifier => value
     * @return array written (identifiers), skipped (identifier => reason), errors (identifier => message)
     * @throws expAiException for an unknown identifier (the message lists the valid ones)
     */
    public function writeFields( eZContentObject $object, eZContentObjectVersion $version, $language, array $fields )
    {
        if ( (int) $version->attribute( 'status' ) !== eZContentObjectVersion::STATUS_DRAFT )
            throw new expAiException( 'Only drafts can be written.', 'version status ' . $version->attribute( 'status' ) );
        $dataMap = $object->fetchDataMap( (int) $version->attribute( 'version' ), $language );
        self::checkFieldIdentifiers( array_keys( $fields ), array_keys( $dataMap ) );
        $writable = $this->config->writableDatatypes();
        $result = array( 'written' => array(), 'skipped' => array(), 'errors' => array() );
        $db = eZDB::instance();
        $db->begin();
        try
        {
            foreach ( $fields as $identifier => $value )
            {
                $attribute = $dataMap[$identifier];
                $datatype = (string) $attribute->attribute( 'data_type_string' );
                if ( !in_array( $datatype, $writable, true ) )
                {
                    $result['skipped'][$identifier] = "the datatype $datatype is not written by the assistant; the editor sets it in the edit form";
                    continue;
                }
                if ( !$attribute->attribute( 'can_translate' ) && $language !== $object->attribute( 'initial_language_code' ) )
                {
                    $result['skipped'][$identifier] = 'not translatable: it keeps the value of the main language';
                    continue;
                }
                $error = $this->writeValue( $object, $attribute, $datatype, $value );
                if ( $error !== true )
                    $result['errors'][$identifier] = $error;
                else
                    $result['written'][] = $identifier;
            }
            $version->setAttribute( 'modified', time() );
            $version->store();
            $db->commit();
        }
        catch ( Throwable $e )
        {
            // a nested rollback (writeFields() inside createDraft()) has already ended the whole transaction
            if ( $db->transactionCounter() > 0 )
                $db->rollback();
            throw $e;
        }
        $this->updateName( $object, $version, $language );
        return $result;
    }

    /** @return true|string true or what was wrong */
    protected function writeValue( eZContentObject $object, eZContentObjectAttribute $attribute, $datatype, $value )
    {
        if ( is_array( $value ) )
            $value = $datatype === 'ezkeyword' ? implode( ', ', array_map( 'strval', $value ) ) : null;
        if ( $value === null )
            return 'the value must be a string, number or boolean';
        $classAttribute = $attribute->contentClassAttribute();
        switch ( $datatype )
        {
            case 'ezstring':
                $string = expAiText::oneLine( strip_tags( (string) $value ) );
                $max = (int) $classAttribute->attribute( 'data_int1' );
                if ( $max > 0 && mb_strlen( $string, 'UTF-8' ) > $max )
                    return "longer than the maximum of $max characters";
                $attribute->fromString( $string );
                break;
            case 'eztext':
                $text = (string) $value;
                if ( preg_match( '/<\s*(p|br|h[1-6]|li)\b/i', $text ) )
                    $text = expAiText::htmlToText( $text );
                $attribute->fromString( trim( $text ) );
                break;
            case 'ezxmltext':
                return $this->writeRichText( $object, $attribute, (string) $value );
            case 'ezkeyword':
                $attribute->fromString( implode( ', ', array_filter( array_map( 'trim', explode( ',', strip_tags( (string) $value ) ) ) ) ) );
                break;
            case 'ezboolean':
                $bool = is_bool( $value ) ? $value : in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
                $attribute->fromString( $bool ? '1' : '0' );
                break;
            case 'ezinteger':
                if ( filter_var( $value, FILTER_VALIDATE_INT ) === false )
                    return 'not an integer';
                $attribute->fromString( (string) (int) $value );
                break;
            case 'ezfloat':
                if ( !is_numeric( $value ) )
                    return 'not a number';
                $attribute->fromString( (string) (float) $value );
                break;
            case 'ezdate':
            case 'ezdatetime':
                $time = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
                if ( !$time )
                    return 'not a date (use YYYY-MM-DD or YYYY-MM-DD HH:MM)';
                $attribute->fromString( (string) $time );
                break;
            case 'ezemail':
                if ( trim( (string) $value ) !== '' && !filter_var( trim( (string) $value ), FILTER_VALIDATE_EMAIL ) )
                    return 'not an e-mail address';
                $attribute->fromString( trim( (string) $value ) );
                break;
            case 'ezurl':
                $parts = explode( '|', (string) $value, 2 );
                if ( !preg_match( '~^(https?://|mailto:)~i', trim( $parts[0] ) ) )
                    return 'not an http(s) or mailto URL';
                if ( $attribute->fromString( trim( $parts[0] ) . ( isset( $parts[1] ) ? '|' . trim( $parts[1] ) : '' ) ) === false )
                    return 'the URL was refused';
                break;
            default:
                if ( $attribute->fromString( (string) $value ) === false )
                    return 'the value was refused by the datatype';
        }
        $attribute->store();
        return true;
    }

    /**
     * Rich text: the HTML subset, cleaned, through eZSimplifiedXMLInputParser (as the plain XML editor does).
     * @return true|string
     */
    public function writeRichText( eZContentObject $object, eZContentObjectAttribute $attribute, $html )
    {
        $classAttribute = $attribute->contentClassAttribute();
        $identifier = $classAttribute ? (string) $classAttribute->attribute( 'identifier' ) : '';
        // the placeholders of the answer come from the value it replaces (or, for a translation, another language)
        $sources = self::richTextSources( $object, $identifier, (string) $attribute->attribute( 'data_text' ) );
        $xml = $this->richTextToXml( $object, $html, $urlIDs, $messages, $sources, $guard );
        $this->guarded[$identifier] = $guard;
        if ( $xml === false && $guard && !$guard['ok'] )
            return 'not written: ' . $guard['reason_model'];
        if ( $xml === false )
            return 'the rich text could not be read: ' . implode( ' ', $messages );
        $attribute->setAttribute( 'data_text', $xml );
        $attribute->store();
        if ( $urlIDs )
            eZSimplifiedXMLInput::updateUrlObjectLinks( $attribute, $urlIDs );
        return true;
    }

    /**
     * The ezxmltext XML of an HTML text. With placeholders ([RichTextSettings] Placeholders, expAiRichTextTokens) the
     * placeholders of the answer are put back as the elements they stand for, taken from the first of $sources whose
     * placeholders the answer uses. Nothing is returned (false, $guard['ok'] false) when a placeholder is missing,
     * repeated, invented or misplaced, or when the value would have less structure than the first of $sources that is
     * not empty (the value it replaces; for a new language the field in another language); with $bestEffort the value is returned anyway (the
     * known placeholders restored, the others removed, the rest of a long field kept) for a review that shows it.
     * @param string[]|null $sources stored values: the value written into first, then the other languages
     * @param array|null $guard ok, problems, loss, reason (translated, for the editor), reason_model (English)
     * @return string|false
     */
    public function richTextToXml( eZContentObject $object, $html, &$urlIDs = array(), &$messages = array(), ?array $sources = null, &$guard = null, $bestEffort = false )
    {
        $guard = array( 'ok' => true, 'problems' => array(), 'loss' => array(), 'reason' => '', 'reason_model' => '' );
        $tokens = expAiRichTextTokens::enabled( $this->config ) ? new expAiRichTextTokens( $this->config ) : null;
        $read = null;
        if ( $tokens )
        {
            $read = $tokens->resolve( (string) $html, (array) $sources );
            if ( !expAiRichTextTokens::clean( $read['problems'] ) && !$bestEffort )
                return $this->refuse( $guard, $messages, $read['problems'], array() );
        }
        $clean = expAiText::sanitizeHtml( $html, (bool) $tokens );
        $parser = new eZSimplifiedXMLInputParser( (int) $object->attribute( 'id' ), true, eZXMLInputParser::ERROR_ALL, true );
        $document = $parser->process( $clean );
        $messages = (array) $parser->getMessages();
        if ( !is_object( $document ) )
            return false;
        $urlIDs = (array) $parser->getUrlIDArray();
        if ( !$tokens )
            return eZXMLTextType::domString( $document );
        $problems = $read['problems'];
        if ( $read['tokens'] || expAiRichTextTokens::keys( $html ) )
            foreach ( $tokens->restore( $document, $read, true ) as $name => $list )
                $problems[$name] = array_values( array_unique( array_merge( $problems[$name], $list ) ) );
        $xml = eZXMLTextType::domString( $document );
        // the structure the value replaces is all there; a language that does not exist yet keeps the structure of the
        // first language that has the field (a translation never drops a formula either)
        $base = '';
        // (an empty <section/>, as the kernel stores a field never filled, does not count)
        foreach ( (array) $sources as $source )
            if ( !expAiRichTextTokens::isEmpty( $source ) )
            {
                $base = (string) $source;
                break;
            }
        $loss = $base !== '' ? $tokens->loss( $base, $xml ) : array();
        if ( !expAiRichTextTokens::clean( $problems ) || $loss )
        {
            $this->refuse( $guard, $messages, $problems, $loss );
            if ( !$bestEffort )
                return false;
        }
        return $xml;
    }

    /** @return false, with the reasons of a refused rich text in $guard and $messages */
    protected function refuse( array &$guard, &$messages, array $problems, array $loss )
    {
        $guard['ok'] = false;
        $guard['problems'] = $problems;
        $guard['loss'] = $loss;
        $guard['reason'] = expAiRichTextTokens::reason( $problems, $loss, true );
        $guard['reason_model'] = expAiRichTextTokens::reason( $problems, $loss, false );
        $messages = array( $guard['reason_model'] );
        return false;
    }

    /**
     * The values an answer for a rich text field may take its placeholders from: $first (the value it is written
     * into), then the field in the published version in every language of the object, the main language first.
     * @return string[]
     */
    public static function richTextSources( eZContentObject $object, $identifier, $first = '' )
    {
        $out = array( (string) $first );
        if ( (string) $identifier === '' || !(int) $object->attribute( 'id' ) || (int) $object->attribute( 'status' ) !== eZContentObject::STATUS_PUBLISHED )
            return $out;
        $main = (string) $object->attribute( 'initial_language_code' );
        $languages = array_keys( (array) $object->allLanguages() );
        usort( $languages, function ( $a, $b ) use ( $main ) { return ( $b === $main ) - ( $a === $main ); } );
        foreach ( $languages as $language )
        {
            $values = expAiDiff::values( $object, (int) $object->attribute( 'current_version' ), $language );
            if ( isset( $values[$identifier] ) && !expAiRichTextTokens::isEmpty( $values[$identifier]['value'] ) )
                $out[] = (string) $values[$identifier]['value'];
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Checks a rich text answer for an existing object before anything is written (no draft is made for an answer
     * that would lose structure): against the published value in the language and the other languages.
     * @return array the guard of richTextToXml() (ok, problems, loss, reason, reason_model)
     */
    public function checkRichText( eZContentObject $object, $identifier, $language, $html )
    {
        $values = in_array( (string) $language, array_keys( (array) $object->allLanguages() ), true )
            ? expAiDiff::values( $object, (int) $object->attribute( 'current_version' ), (string) $language ) : array();
        $base = isset( $values[$identifier] ) ? (string) $values[$identifier]['value'] : '';
        $this->richTextToXml( $object, (string) $html, $urlIDs, $messages, self::richTextSources( $object, $identifier, $base ), $guard );
        return $guard;
    }

    public function updateName( eZContentObject $object, eZContentObjectVersion $version, $language )
    {
        $class = $object->contentClass();
        $name = $class->contentObjectName( $object, (int) $version->attribute( 'version' ), $language );
        $object->setName( $name, (int) $version->attribute( 'version' ), $language );
    }

    /**
     * Required fields of a draft that have no content.
     * @return string[] field names
     */
    public function missingRequired( eZContentObject $object, eZContentObjectVersion $version )
    {
        $missing = array();
        foreach ( $object->fetchDataMap( (int) $version->attribute( 'version' ), $version->initialLanguageCode() ) as $identifier => $attribute )
        {
            $classAttribute = $attribute->contentClassAttribute();
            if ( $classAttribute && $classAttribute->attribute( 'is_required' ) && !$attribute->hasContent() )
                $missing[] = (string) $classAttribute->attribute( 'name' );
        }
        return $missing;
    }
}
