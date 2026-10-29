<?php
/**
 * File containing the expAiEditPresets class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Editor presets and the Improve menu of the content edit form (doc/presets.md):
 *
 *   - a preset ([EditPresets] Presets[], [FieldAction_preset_<name>]) is a one-click field action with no prompt to
 *     type; [Presets] Class_<class>[<preset>]=<target>,... says which class offers it and which fields it fills
 *     (an attribute, or <attribute>.<part> of an xrowmetadata field);
 *   - the Improve menu ([EditPresets] Improve[], [FieldAction_improve_<name>]) is offered on every field whose
 *     datatype is in [EditPresets] ImproveDatatypes[];
 *   - the defaults line names the model, its region, the lane and the estimated cost of one click.
 *
 * Like the other field actions they only answer with a value: expai_presets.js puts it into the form, with Undo,
 * and nothing is stored until the editor stores the draft. The checks are those of expAiEditForm (the editor's own
 * draft, editable, the data policy, the limits, the fallback model) plus: the preset is mapped to that field of the
 * object's class, or the field's datatype has the Improve menu.
 */
class expAiEditPresets extends expAiEditForm
{
    /** @return bool presets and the Improve menu are switched on (with the edit form controls and the master switch) */
    public static function enabled( expAiConfig $config )
    {
        return $config->isEnabled() && $config->flag( 'EditViewSettings', 'Enabled', true ) && $config->flag( 'EditPresets', 'Enabled', true );
    }

    /** @return string[] the preset names of [EditPresets] Presets[] */
    public static function presetNames( expAiConfig $config )
    {
        return array_values( array_filter( $config->arr( 'EditPresets', 'Presets' ), function ( $n ) { return preg_match( '/^[a-z][a-z0-9_]{0,40}$/', $n ); } ) );
    }

    /** @return string[] the entries of the Improve menu */
    public static function improveNames( expAiConfig $config )
    {
        return array_values( array_filter( $config->arr( 'EditPresets', 'Improve' ), function ( $n ) { return preg_match( '/^[a-z][a-z0-9_]{0,40}$/', $n ); } ) );
    }

    /**
     * The targets of a preset value.
     * @return array list of array( identifier, part )
     */
    public static function parseTargets( $value )
    {
        $out = array();
        foreach ( explode( ',', (string) $value ) as $target )
        {
            $target = trim( $target );
            if ( !preg_match( '/^([a-z0-9_]+)(?:\.([a-z]+))?$/', $target, $m ) )
                continue;
            $part = isset( $m[2] ) ? $m[2] : '';
            if ( $part !== '' && !in_array( $part, expAiPresetFieldRequest::METADATA_PARTS, true ) )
                continue;
            $out[] = array( $m[1], $part );
        }
        return $out;
    }

    /**
     * The presets of a class.
     * @return array preset name => list of array( identifier, part ), in the order of [EditPresets] Presets[]
     */
    public static function classPresets( expAiConfig $config, $classIdentifier )
    {
        $map = $config->get( 'Presets', 'Class_' . $classIdentifier, array() );
        $map = is_array( $map ) ? $map : array();
        $out = array();
        foreach ( self::presetNames( $config ) as $name )
            if ( isset( $map[$name] ) && ( $targets = self::parseTargets( $map[$name] ) ) )
                $out[$name] = $targets;
        return $out;
    }

    /** @return string the English label of a field action section */
    protected static function label( expAiConfig $config, $section, $default )
    {
        return (string) $config->get( $section, 'Label', $default );
    }

    /**
     * What the edit form needs: the preset buttons per field, the Improve menu, the defaults line.
     * @param eZContentObjectAttribute[] $attributes the attributes of the form
     * @return array presets (list of { name, label, attribute_id, identifier, part, datatype }), improve (list of
     *               { name, label }), improve_datatypes, defaults ({ model, alias, region, lane, cost, cost_known }) or
     *               false when switched off
     */
    public static function describe( expAiConfig $config, eZContentObject $object, array $attributes )
    {
        if ( !self::enabled( $config ) )
            return false;
        $byIdentifier = array();
        foreach ( $attributes as $attribute )
            if ( $attribute instanceof eZContentObjectAttribute )
                $byIdentifier[(string) $attribute->attribute( 'contentclass_attribute_identifier' )] = $attribute;
        $writable = $config->writableDatatypes();
        $presets = array();
        foreach ( self::classPresets( $config, (string) $object->attribute( 'class_identifier' ) ) as $name => $targets )
        {
            foreach ( $targets as $target )
            {
                list( $identifier, $part ) = $target;
                if ( !isset( $byIdentifier[$identifier] ) )
                    continue;
                $datatype = (string) $byIdentifier[$identifier]->attribute( 'data_type_string' );
                if ( !self::targetFits( $datatype, $part, $writable ) )
                    continue;
                $presets[] = array( 'name' => $name, 'label' => ezpI18n::tr( 'extension/expai/preset', self::label( $config, 'FieldAction_preset_' . $name, ucfirst( $name ) ) ),
                                    'attribute_id' => (int) $byIdentifier[$identifier]->attribute( 'id' ), 'identifier' => $identifier,
                                    'part' => $part, 'datatype' => $datatype );
            }
        }
        $improve = array();
        foreach ( self::improveNames( $config ) as $name )
            $improve[] = array( 'name' => $name, 'label' => ezpI18n::tr( 'extension/expai/preset', self::label( $config, 'FieldAction_improve_' . $name, ucfirst( $name ) ) ) );
        return array( 'presets' => $presets, 'improve' => $improve,
                      'improve_datatypes' => array_values( array_intersect( $config->arr( 'EditPresets', 'ImproveDatatypes' ), $writable ) ),
                      'defaults' => $config->flag( 'EditPresets', 'DefaultsLine', true ) ? self::defaults( $config ) : false );
    }

    /** @return bool a preset may write a field of this datatype (an xrowmetadata field only through a part) */
    protected static function targetFits( $datatype, $part, array $writable )
    {
        if ( $part !== '' )
            return $datatype === 'xrowmetadata';
        return $datatype !== 'xrowmetadata' && in_array( $datatype, $writable, true );
    }

    /**
     * The defaults line: the current user's model, its provider's region, the lane of the edit form (standard) and
     * the estimate of one click ([EditPresets] EstimateInputTokens / EstimateOutputTokens at the model's prices).
     * @return array model (label), alias, provider, region (us|eu|local|''), retention, lane, cost (formatted), cost_known
     */
    public static function defaults( expAiConfig $config )
    {
        $registry = $config->registry();
        $alias = $config->effectiveModel();
        $model = $registry->getModel( $alias );
        $provider = $model ? $registry->getProvider( $model['provider'] ) : null;
        $estimate = ( new expAiBudget( $config ) )->estimate( $alias, $config->int( 'EditPresets', 'EstimateInputTokens', 2500, 1 ),
                                                              $config->int( 'EditPresets', 'EstimateOutputTokens', 300, 1 ) );
        $costs = expAiCost::enabled( $config );
        return array( 'model' => $model ? (string) $model['label'] : $alias, 'alias' => $alias,
                      'provider' => $provider ? (string) $provider['Label'] : '',
                      'region' => $provider ? (string) $provider['Region'] : '', 'retention' => $provider ? (string) $provider['Retention'] : '',
                      'lane' => 'standard',
                      'cost' => $costs && $estimate['known'] ? expAiCost::money( $estimate['micros'] / 1000000 ) : '',
                      'cost_known' => $costs && $estimate['known'],
                      // a model without a price per token (a local model, a free tier) costs nothing per click
                      'free' => $estimate['known'] && (int) $estimate['micros'] === 0 && expAiCost::costClass( $alias, $config ) === 'free' );
    }

    /**
     * Runs a preset or an Improve entry on one field of the editor's draft.
     * @param array $post object_id, version, language, attribute_id, kind (preset|improve), action, part, value, context
     * @return array value, note, action, kind, part, datatype, fallback, record (requestRecord())
     * @throws expAiException
     */
    public static function run( expAiConfig $config, array $post )
    {
        if ( !self::enabled( $config ) )
            throw new expAiException( 'Presets are switched off.' );
        list( $object, $version, $language ) = self::draft( $post['object_id'], $post['version'], $post['language'] );
        $kind = (string) $post['kind'] === 'improve' ? 'improve' : 'preset';
        $name = (string) $post['action'];
        $part = (string) $post['part'];
        $names = $kind === 'improve' ? self::improveNames( $config ) : self::presetNames( $config );
        if ( !in_array( $name, $names, true ) )
            throw new expAiException( 'Unknown action.' );
        $attribute = null;
        foreach ( $object->fetchDataMap( (int) $version->attribute( 'version' ), $language ) as $candidate )
            if ( (int) $candidate->attribute( 'id' ) === (int) $post['attribute_id'] )
                $attribute = $candidate;
        if ( !$attribute )
            throw new expAiException( 'The field was not found in this draft.' );
        $datatype = (string) $attribute->attribute( 'data_type_string' );
        $identifier = (string) $attribute->attribute( 'contentclass_attribute_identifier' );
        $writable = $config->writableDatatypes();
        if ( $kind === 'preset' )
        {
            $mapped = false;
            $presets = self::classPresets( $config, (string) $object->attribute( 'class_identifier' ) );
            foreach ( isset( $presets[$name] ) ? $presets[$name] : array() as $target )
                if ( $target[0] === $identifier && $target[1] === $part )
                    $mapped = true;
            if ( !$mapped || !self::targetFits( $datatype, $part, $writable ) )
                throw new expAiException( 'This action is not available for this field.' );
        }
        else
        {
            $part = '';
            if ( !in_array( $datatype, $config->arr( 'EditPresets', 'ImproveDatatypes' ), true ) || !in_array( $datatype, $writable, true ) )
                throw new expAiException( 'This action is not available for this field.' );
        }
        $section = 'FieldAction_' . $kind . '_' . $name;
        $class = (string) $config->get( $section, 'Handler', 'expAiPromptFieldAction' );
        if ( !class_exists( $class ) || !in_array( 'expAiFieldAction', class_implements( $class ), true ) )
            throw new expAiException( 'Unknown action.', "no usable handler $class for $section" );
        $action = new $class( $kind . '_' . $name, $config );
        $request = new expAiPresetFieldRequest();
        $request->object = $object;
        $request->version = $version;
        $request->language = $language;
        $request->attribute = $attribute;
        $request->classAttribute = $attribute->contentClassAttribute();
        // a part of an xrowmetadata field is written like a plain text field
        $request->datatype = $part === '' ? $datatype : ( $part === 'keywords' ? 'ezkeyword' : 'ezstring' );
        $request->part = $part;
        $request->value = (string) $post['value'];
        $request->context = self::context( $post['context'] );
        if ( $kind === 'improve' && trim( strip_tags( $request->value ) ) === '' )
            throw new expAiException( 'The field is empty: there is nothing to improve.' );
        $fallback = null;
        list( $result, $recorder ) = self::withFallback( $config, $object, $kind . ':' . $name, function ( $recorder ) use ( $action, $request )
        {
            return $action->run( $request, $recorder );
        }, $fallback );
        // an empty value is a refusal ("there is nothing to summarize"), not a result: the page shows the note and writes nothing
        $refused = trim( strip_tags( (string) $result['value'] ) ) === '';
        // the draft log labels a version as written by the assistant: not for an answer that wrote nothing
        if ( !$refused )
            self::mark( $object, $version, $language, 'field', $kind . ':' . $name . ( $part !== '' ? ' (' . $identifier . '.' . $part . ')' : '' ),
                        $result['model'], $recorder->providerId() );
        return array( 'value' => $result['value'], 'note' => $result['note'], 'action' => $name, 'kind' => $kind, 'part' => $part,
                      'datatype' => $datatype, 'refused' => $refused, 'fallback' => $fallback, 'record' => self::requestRecord() );
    }
}
