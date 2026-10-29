<?php
/**
 * File containing the expAiDataPolicy class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Where content may be sent: expai.ini [DataPolicy]. Every provider has a region ([Provider_<id>] Region=us, eu or
 * local) and a retention (Retention=none, zdr, 30d or unknown; TermsCheckedAt= the day the terms were read).
 * Sections and classes can require regions and zero retention:
 *
 *   [DataPolicy]
 *   DefaultRegions[]                     regions any content may go to (empty: every region)
 *   SectionRegions[<section id>]=eu;local
 *   ClassRegions[<class identifier>]=local
 *   ZeroRetention=disabled               enabled: every request needs zero retention (none or zdr)
 *   ZeroRetentionSections[]=<section id>
 *   ZeroRetentionClasses[]=<class identifier>
 *
 * The regions of a content object are the intersection of the default, its section's and its class's lists; a
 * provider in the region local satisfies every region requirement (the data stays on this server). Zero retention
 * is met by Retention=none or zdr. A provider with an unknown region meets no region requirement.
 *
 * Enforced where content is read for a model: the panel's node (expAiAssistant), every object a tool reads or writes
 * (expAiContentReader, refusals go back to the model as tool errors and lists leave the objects out), the edit form's
 * field actions, the MCP bridge's results.
 */
class expAiDataPolicy
{
    /** @var expAiConfig */
    protected $config;

    /** @var array the provider's settings: ProviderId, Label, Region, Retention */
    protected $provider;

    public function __construct( expAiConfig $config, array $provider )
    {
        $this->config = $config;
        $this->provider = $provider + array( 'ProviderId' => '', 'Label' => '', 'Region' => '', 'Retention' => 'unknown' );
    }

    /** @return expAiDataPolicy the policy for requests to a provider */
    public static function forProvider( expAiConfig $config, $providerID )
    {
        $settings = $config->registry()->getProvider( $providerID );
        return new self( $config, $settings ?: array( 'ProviderId' => (string) $providerID, 'Label' => (string) $providerID ) );
    }

    /** @return bool any rule is configured (without rules the policy allows everything) */
    public function active()
    {
        return $this->config->arr( 'DataPolicy', 'DefaultRegions' ) || $this->listOf( 'SectionRegions' ) || $this->listOf( 'ClassRegions' )
            || $this->config->flag( 'DataPolicy', 'ZeroRetention', false ) || $this->config->arr( 'DataPolicy', 'ZeroRetentionSections' )
            || $this->config->arr( 'DataPolicy', 'ZeroRetentionClasses' );
    }

    /** @return array key => value of a [DataPolicy] array setting */
    protected function listOf( $name )
    {
        $value = $this->config->get( 'DataPolicy', $name, array() );
        return is_array( $value ) ? array_filter( $value, function ( $v ) { return $v !== '' && $v !== null; } ) : array();
    }

    /** @return string[] regions of a "eu;local" value */
    protected static function regions( $value )
    {
        $out = array();
        foreach ( preg_split( '/[;,\s]+/', strtolower( (string) $value ) ) as $region )
            if ( in_array( $region, array( 'us', 'eu', 'local' ), true ) )
                $out[] = $region;
        return $out;
    }

    /**
     * The requirement of a section and class.
     * @return array regions (string[] or null: any), zero_retention (bool), reasons (string[])
     */
    public function requirementFor( $sectionID, $classIdentifier )
    {
        $regions = null;
        $reasons = array();
        $narrow = function ( array $list, $reason ) use ( &$regions, &$reasons )
        {
            $regions = $regions === null ? $list : array_values( array_intersect( $regions, $list ) );
            $reasons[] = $reason;
        };
        $default = array();
        foreach ( $this->config->arr( 'DataPolicy', 'DefaultRegions' ) as $value )
            $default = array_merge( $default, self::regions( $value ) );
        if ( $default )
            $narrow( array_unique( $default ), 'DefaultRegions' );
        $sections = $this->listOf( 'SectionRegions' );
        if ( isset( $sections[(string) (int) $sectionID] ) )
            $narrow( self::regions( $sections[(string) (int) $sectionID] ), 'section ' . (int) $sectionID );
        $classes = $this->listOf( 'ClassRegions' );
        if ( $classIdentifier !== '' && isset( $classes[$classIdentifier] ) )
            $narrow( self::regions( $classes[$classIdentifier] ), 'class ' . $classIdentifier );
        $zero = $this->config->flag( 'DataPolicy', 'ZeroRetention', false )
             || in_array( (string) (int) $sectionID, $this->config->arr( 'DataPolicy', 'ZeroRetentionSections' ), true )
             || ( $classIdentifier !== '' && in_array( (string) $classIdentifier, $this->config->arr( 'DataPolicy', 'ZeroRetentionClasses' ), true ) );
        return array( 'regions' => $regions, 'zero_retention' => $zero, 'reasons' => $reasons );
    }

    /** @return array the requirement of a content object (its section and class) */
    public function requirement( eZContentObject $object )
    {
        return $this->requirementFor( (int) $object->attribute( 'section_id' ), (string) $object->attribute( 'class_identifier' ) );
    }

    /** @return bool the provider meets the requirement */
    public function meets( array $requirement )
    {
        $region = (string) $this->provider['Region'];
        $retention = (string) $this->provider['Retention'];
        if ( $requirement['regions'] !== null && $region !== 'local' && !in_array( $region, $requirement['regions'], true ) )
            return false;
        if ( $requirement['zero_retention'] && !in_array( $retention, array( 'none', 'zdr' ), true ) && $region !== 'local' )
            return false;
        return true;
    }

    /** @return bool the object may be sent to the provider */
    public function allowsObject( eZContentObject $object )
    {
        return $this->meets( $this->requirement( $object ) );
    }

    /**
     * @throws expAiException naming the content, the rule and the provider (safe for the editor and the model)
     */
    public function assertObject( eZContentObject $object )
    {
        $requirement = $this->requirement( $object );
        if ( $this->meets( $requirement ) )
            return;
        throw new expAiException( 'The data policy keeps "%name" (object %id) away from %provider: %rule.', 'data policy', 0, array(
            '%name' => expAiText::cut( (string) $object->attribute( 'name' ), 80 ), '%id' => (int) $object->attribute( 'id' ),
            '%provider' => (string) $this->provider['Label'], '%rule' => $this->describe( $requirement ) ) );
    }

    /** @throws expAiException when a new object of the class below the parent's section would not be allowed */
    public function assertClass( $classIdentifier, $sectionID )
    {
        $requirement = $this->requirementFor( $sectionID, (string) $classIdentifier );
        if ( $this->meets( $requirement ) )
            return;
        throw new expAiException( 'The data policy keeps content of the class %class away from %provider: %rule.', 'data policy', 0, array(
            '%class' => (string) $classIdentifier, '%provider' => (string) $this->provider['Label'], '%rule' => $this->describe( $requirement ) ) );
    }

    /** @return string the requirement in words (English, for the model and the log; the editor gets it translated) */
    public function describe( array $requirement )
    {
        $parts = array();
        if ( $requirement['regions'] !== null )
            $parts[] = 'regions ' . ( $requirement['regions'] ? implode( ' or ', $requirement['regions'] ) : '(none)' ) . ' required';
        if ( $requirement['zero_retention'] )
            $parts[] = 'zero data retention required';
        $parts[] = 'the provider is in region ' . ( $this->provider['Region'] !== '' ? $this->provider['Region'] : 'unknown' )
                 . ', retention ' . $this->provider['Retention'];
        return implode( '; ', $parts ) . ( $requirement['reasons'] ? ' (' . implode( ', ', $requirement['reasons'] ) . ')' : '' );
    }

    /** @return string the id of the provider the policy is for ('' when not known) */
    public function providerID()
    {
        return (string) $this->provider['ProviderId'];
    }

    /** @return string the region of the provider ('' when not known) */
    public function providerRegion()
    {
        return (string) $this->provider['Region'];
    }

    /** @return array the provider's region and retention for display */
    public function providerFacts()
    {
        return array( 'region' => (string) $this->provider['Region'], 'retention' => (string) $this->provider['Retention'],
                      'terms_checked_at' => isset( $this->provider['TermsCheckedAt'] ) ? (string) $this->provider['TermsCheckedAt'] : '' );
    }
}
