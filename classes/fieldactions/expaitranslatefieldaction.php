<?php
/**
 * File containing the expAiTranslateFieldAction class.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The field action Translate ([FieldAction_translate] Handler=expAiTranslateFieldAction): the translation service of
 * the pair (expai.ini [TranslationSettings], purpose field) translates the field's value.
 *
 * With the llm service it is expAiPromptFieldAction as before: the same prompt, the model of the user, the fallback
 * to the default model. With a machine translation service the edit form takes the direct path (runDirect()): the
 * data policy of that service decides first, expAiTranslationRecorder checks the master switch, the veto and the
 * cost limits and writes the usage row; only the field's value is sent (never the other fields or the object's
 * name). Rich text goes as the editor's HTML, plain text line by line, keywords one by one.
 */
class expAiTranslateFieldAction extends expAiPromptFieldAction
{
    /** @return expAiTranslationProviderRegistry */
    protected function registry()
    {
        return new expAiTranslationProviderRegistry( $this->config );
    }

    /**
     * @return string '' or why no translation service can translate a field (the edit form then leaves the action out)
     */
    public function unavailableReason()
    {
        return $this->registry()->availableFor( 'field' ) ? '' : 'no translation service is installed for the field action ([TranslationSettings])';
    }

    /**
     * The machine translation service of the field's pair, null when the llm service translates it. Without a target
     * language, or into the field's own language, it is null too: the prompt of the llm service handles those as it
     * did before translation services existed.
     * @return expAiTranslationProvider|null
     * @throws expAiException when no service can translate it
     */
    public function directService( expAiFieldRequest $request )
    {
        if ( $request->targetLanguage === '' || $request->targetLanguage === $request->language )
            return null;
        $choice = $this->registry()->forPair( $request->language, $request->targetLanguage, 'field', $request->object );
        $arguments = array( '%source' => $request->language, '%target' => $request->targetLanguage );
        if ( $choice['state'] === 'blocked' )
            throw new expAiException( 'The data policy keeps this content away from every translation service for %source → %target.', $choice['reason'], 0, $arguments );
        if ( !$choice['provider'] )
            throw new expAiException( 'No translation service is installed for %source → %target.', $choice['reason'], 0, $arguments );
        return expAiTranslationSync::queuesRequest( $choice['provider'] ) ? null : $choice['provider'];
    }

    public function run( expAiFieldRequest $request, expAiProvider $provider )
    {
        $service = $request->object instanceof eZContentObject ? $this->directService( $request ) : null;
        if ( $service )
        {
            $result = $this->runDirect( $request, $service );
            unset( $result['recorder'] );
            return $result;
        }
        return parent::run( $request, $provider );
    }

    /**
     * The units of the field's value.
     * @return array( expAiTranslationRequest, array separators: the text between the lines of plain text, by unit index )
     */
    public function translationRequest( expAiFieldRequest $request )
    {
        $value = expAiText::cut( (string) $request->value, self::maxValueLength( $this->config ) );
        $translation = new expAiTranslationRequest();
        $translation->source = $request->language;
        $translation->target = $request->targetLanguage;
        $translation->purpose = 'field';
        $translation->doNotTranslate = $this->registry()->terms();
        $translation->glossary = $this->registry()->glossary( $request->language, $request->targetLanguage );
        $translation->action = expAiText::cut( 'field:' . $this->name, 40 );
        if ( $request->object instanceof eZContentObject )
        {
            $translation->objectIDs = array( (int) $request->object->attribute( 'id' ) );
            $main = $request->object->mainNode();
            $translation->nodeID = $main ? (int) $main->attribute( 'node_id' ) : 0;
        }
        $layout = array();
        switch ( $request->datatype )
        {
            case 'ezxmltext':
                $translation->units[] = expAiTranslationRequest::unit( array( 'id' => 'f1', 'kind' => 'html', 'format' => 'html', 'text' => $value ) );
                break;
            case 'ezkeyword':
                $translation->units[] = expAiTranslationRequest::unit( array( 'id' => 'f1', 'kind' => 'keywords', 'format' => 'text', 'text' => $value ) );
                break;
            case 'eztext':
                // line by line: the line breaks stay where they were
                foreach ( preg_split( '/(\R+)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE ) as $part )
                {
                    if ( $part === '' || preg_match( '/^\R+$/u', $part ) || trim( $part ) === '' )
                    {
                        $layout[] = $part;
                        continue;
                    }
                    $id = 'f' . ( count( $translation->units ) + 1 );
                    $translation->units[] = expAiTranslationRequest::unit( array( 'id' => $id, 'kind' => 'text', 'format' => 'text', 'text' => $part ) );
                    $layout[] = array( $id );
                }
                break;
            default:
                $translation->units[] = expAiTranslationRequest::unit( array( 'id' => 'f1', 'kind' => 'text', 'format' => 'text', 'text' => $value ) );
        }
        if ( !$layout )
            foreach ( $translation->units as $unit )
                $layout[] = array( $unit['id'] );
        return array( $translation, $layout );
    }

    /** @return expAiTranslationRecorder the recorder of a request to the service (gate, cost limits, usage row) */
    protected function recorder( expAiTranslationProvider $service, array $context )
    {
        return new expAiTranslationRecorder( $service, $context );
    }

    /**
     * Translates the field with a machine translation service.
     * @return array value, note, model (the engine), recorder (expAiTranslationRecorder: its usage row is the record)
     * @throws expAiException
     */
    public function runDirect( expAiFieldRequest $request, expAiTranslationProvider $service )
    {
        if ( $request->targetLanguage === '' )
            throw new expAiException( 'Please choose the target language.' );
        // the value of the form goes to the service: its data policy decides first
        $policy = $service->dataPolicy();
        if ( $policy && $request->object instanceof eZContentObject )
            $policy->assertObject( $request->object );
        list( $translation, $layout ) = $this->translationRequest( $request );
        if ( !$translation->units )
            throw new expAiException( 'The field is empty: there is nothing to translate.' );
        $recorder = $this->recorder( $service, array( 'node_id' => $translation->nodeID, 'action' => $translation->action ) );
        $result = $recorder->translate( $translation );
        if ( !$result->complete() )
            throw new expAiException( 'The translation does not fit the markup of the text.', implode( '; ', $result->errors ) );
        $value = '';
        foreach ( $layout as $part )
            $value .= is_array( $part ) ? $result->units[$part[0]] : $part;
        return array( 'value' => $request->clean( $value ), 'note' => '', 'model' => $result->engine, 'recorder' => $recorder );
    }
}
