<?php
/**
 * File containing the expAiWebSearchProvider class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A web search service for web_search. [SearchSettings] Provider=<id> names a [SearchProvider_<id>] group with
 * Class=<a subclass>; the group's settings arrive in the constructor. Brave Search (expAiWebSearchBrave) ships;
 * another service (Bing, SerpAPI, Tavily ...) is one subclass with search() and a group in expai.ini. The API key is
 * read from [SearchProvider_<id>] ApiKey, which belongs in settings/override (or an untracked [ProviderSettings] KeyLocations[] location).
 */
abstract class expAiWebSearchProvider
{
    /** @var array the settings of [SearchProvider_<id>] plus ProviderId */
    protected $settings;

    public function __construct( array $settings )
    {
        $this->settings = $settings;
    }

    /**
     * The configured provider, null when web search is not usable (no provider, no class, no key).
     * @return expAiWebSearchProvider|null
     */
    public static function fromConfig( expAiConfig $config )
    {
        $id = (string) $config->get( 'SearchSettings', 'Provider', '' );
        if ( !preg_match( '/^[a-z0-9_]{1,30}$/', $id ) || !$config->ini()->hasGroup( 'SearchProvider_' . $id ) )
            return null;
        $settings = $config->ini()->group( 'SearchProvider_' . $id );
        $settings['ProviderId'] = $id;
        $class = isset( $settings['Class'] ) ? (string) $settings['Class'] : '';
        if ( $class === '' || !class_exists( $class ) || !is_subclass_of( $class, __CLASS__ ) )
            return null;
        $provider = new $class( $settings );
        return $provider->isConfigured() ? $provider : null;
    }

    /** @return string */
    public function id()
    {
        return isset( $this->settings['ProviderId'] ) ? (string) $this->settings['ProviderId'] : '';
    }

    /** @return string */
    public function label()
    {
        return $this->setting( 'Label', $this->id() );
    }

    /** @return string the setting, $default when missing or empty */
    public function setting( $name, $default = '' )
    {
        return isset( $this->settings[$name] ) && $this->settings[$name] !== '' && $this->settings[$name] !== null && !is_array( $this->settings[$name] )
             ? (string) $this->settings[$name] : (string) $default;
    }

    /** @return bool usable: a key is set (a provider without a key overrides this) */
    public function isConfigured()
    {
        return $this->setting( 'ApiKey' ) !== '';
    }

    /** @return string us | eu | local, for the data policy (the query leaves the site) */
    public function region()
    {
        return expAiProviderRegistry::cleanRegion( $this->setting( 'Region', '' ) );
    }

    /** @return string none | zdr | 30d | unknown */
    public function retention()
    {
        return expAiProviderRegistry::cleanRetention( $this->setting( 'Retention', '' ) );
    }

    /**
     * @param string $query
     * @param int $count
     * @return array list of array( title, url, snippet )
     * @throws expAiException
     */
    abstract public function search( $query, $count );
}
