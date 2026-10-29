<?php
/**
 * File containing the expAiProviderRegistry class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The providers and models of expai.ini:
 *
 *   [ProviderSettings] Providers[]=<id>        the active providers, in the order the model picker shows them
 *   [Provider_<id>]    Type, BaseURL, ApiKey (override only), Region, Retention, ...
 *   [Model_<alias>]    Provider, Id, Label, ToolUse, WriteTools, prices, ...
 *
 * Backward compatible: the provider "anthropic" without a [Provider_anthropic] group is built from [AISettings]
 * (Endpoint, ApiKey, Effort ...), and every model of [AISettings] Model / AllowedModels[] without a
 * [Model_anthropic.<id>] group becomes the alias anthropic.<id> (label from ModelLabels[], priced by [Pricing]). A
 * stored preference with a bare Anthropic id (0.0.1.0) maps to that alias.
 *
 * Read fresh for every instance (no static state: Velocity's workers serve many siteaccesses).
 */
class expAiProviderRegistry
{
    /** The provider types built in: Type => class */
    const TYPES = array( 'anthropic' => 'expAiAnthropicProvider', 'openai_chat' => 'expAiOpenAiChatProvider' );

    /** @var expAiConfig */
    protected $config;

    /** @var array|null id => settings */
    protected $providers;

    /** @var array|null alias => model */
    protected $models;

    public function __construct( expAiConfig $config )
    {
        $this->config = $config;
    }

    /** @return expAiProviderRegistry */
    public static function instance( ?expAiConfig $config = null )
    {
        return new self( $config ?: expAiConfig::instance() );
    }

    /** @return string[] the ids of [ProviderSettings] Providers[] (anthropic when the list is empty) */
    public function providerIds()
    {
        $ids = array();
        foreach ( $this->config->arr( 'ProviderSettings', 'Providers' ) as $id )
            if ( preg_match( '/^[a-z0-9_-]{1,40}$/', $id ) && !in_array( $id, $ids, true ) )
                $ids[] = $id;
        return $ids ?: array( 'anthropic' );
    }

    /**
     * The settings of every active provider (the key included: never log or output them; see describeProvider()).
     * @return array id => settings
     */
    public function providers()
    {
        if ( $this->providers !== null )
            return $this->providers;
        $this->providers = array();
        foreach ( $this->providerIds() as $id )
        {
            $group = 'Provider_' . $id;
            if ( $this->config->ini()->hasGroup( $group ) )
                $settings = $this->config->ini()->group( $group );
            elseif ( $id === 'anthropic' )
                $settings = $this->anthropicFromAISettings();
            else
            {
                eZDebug::writeWarning( "expai: provider $id has no [$group]", __METHOD__ );
                continue;
            }
            $settings = is_array( $settings ) ? $settings : array();
            $settings += array( 'Type' => 'openai_chat', 'Label' => $id, 'Region' => '', 'Retention' => 'unknown', 'TermsCheckedAt' => '' );
            $settings['ProviderId'] = $id;
            $settings['Region'] = self::cleanRegion( $settings['Region'] );
            $settings['Retention'] = self::cleanRetention( $settings['Retention'] );
            $this->providers[$id] = $settings;
        }
        return $this->providers;
    }

    /** @return array the provider anthropic from [AISettings] (0.0.1.0 settings) */
    protected function anthropicFromAISettings()
    {
        $settings = array( 'Type' => 'anthropic', 'Label' => 'Anthropic' );
        foreach ( array( 'Endpoint', 'AnthropicVersion', 'ApiKey', 'AnthropicWorkspaceId', 'Effort', 'RefusalFallback', 'PromptCaching', 'StrictTools', 'StrictToolsMax', 'StrictOptionalMax', 'StrictUnionMax',
                         'Timeout', 'ConnectTimeout', 'MaxResponseBytes', 'Region', 'Retention', 'TermsCheckedAt' ) as $name )
            $settings[$name] = $this->config->get( 'AISettings', $name, '' );
        if ( (string) $this->config->get( 'AISettings', 'Provider', 'anthropic' ) === 'custom' )
        {
            $settings['Type'] = 'custom';
            $settings['Class'] = (string) $this->config->get( 'AISettings', 'ProviderClass', '' );
        }
        $settings['Region'] = $settings['Region'] !== '' ? $settings['Region'] : 'us';
        $settings['Retention'] = $settings['Retention'] !== '' ? $settings['Retention'] : '30d';
        return $settings;
    }

    /** @return string us | eu | local | '' (unknown) */
    public static function cleanRegion( $region )
    {
        $region = strtolower( trim( (string) $region ) );
        return in_array( $region, array( 'us', 'eu', 'local' ), true ) ? $region : '';
    }

    /** @return string none | zdr | 30d | unknown */
    public static function cleanRetention( $retention )
    {
        $retention = strtolower( trim( (string) $retention ) );
        return in_array( $retention, array( 'none', 'zdr', '30d' ), true ) ? $retention : 'unknown';
    }

    /**
     * Every model of the active providers.
     * @return array alias => array( alias, provider, id, label, tool_use (native|prompted|none), write_tools (bool),
     *               write_gate (probe|trusted), capabilities, max_output, effort, prices (input, output, cache_write,
     *               cache_read: float or null), slow (bool), settings (the [Model_<alias>] values) )
     */
    public function models()
    {
        if ( $this->models !== null )
            return $this->models;
        $providers = $this->providers();
        $explicit = array();
        foreach ( $this->config->ini()->groups() as $group => $values )
        {
            if ( strpos( $group, 'Model_' ) !== 0 || !is_array( $values ) )
                continue;
            $alias = substr( $group, 6 );
            $provider = isset( $values['Provider'] ) ? (string) $values['Provider'] : '';
            if ( !preg_match( '/^[A-Za-z0-9._:-]{1,100}$/', $alias ) || !isset( $providers[$provider] ) || empty( $values['Id'] ) )
                continue;
            $explicit[$provider][$alias] = $this->model( $alias, $provider, $values );
        }
        $this->models = array();
        foreach ( array_keys( $providers ) as $provider )
        {
            if ( isset( $explicit[$provider] ) )
                $this->models += $explicit[$provider];
            if ( $provider === 'anthropic' )
                foreach ( $this->anthropicModelIds() as $id => $label )
                    if ( !isset( $this->models['anthropic.' . $id] ) )
                        $this->models['anthropic.' . $id] = $this->model( 'anthropic.' . $id, 'anthropic',
                            array( 'Id' => $id, 'Label' => $label, 'ToolUse' => 'native', 'WriteTools' => 'enabled', 'WriteGate' => 'trusted',
                                   'MaxOutput' => $this->config->get( 'AISettings', 'MaxTokens', '' ), 'Effort' => $this->config->get( 'AISettings', 'Effort', '' ),
                                   'Capabilities' => array( 'json_schema', 'tool_choice_none', 'prompt_cache', 'vision' ) ) );
        }
        return $this->models;
    }

    /** @return array id => label of [AISettings] Model and AllowedModels[] */
    protected function anthropicModelIds()
    {
        $labels = $this->config->get( 'AISettings', 'ModelLabels', array() );
        $labels = is_array( $labels ) ? $labels : array();
        $out = array();
        foreach ( array_merge( array( $this->config->defaultModelId() ), $this->config->arr( 'AISettings', 'AllowedModels' ) ) as $id )
        {
            $id = trim( (string) $id );
            if ( $id !== '' && !isset( $out[$id] ) )
                $out[$id] = isset( $labels[$id] ) && $labels[$id] !== '' ? (string) $labels[$id] : $id;
        }
        return $out;
    }

    /** @return array a model from its [Model_<alias>] values */
    protected function model( $alias, $provider, array $values )
    {
        $flag = function ( $name, $default ) use ( $values )
        {
            $value = isset( $values[$name] ) && $values[$name] !== '' ? $values[$name] : $default;
            return in_array( strtolower( trim( (string) $value ) ), array( 'enabled', 'true', '1', 'yes', 'on' ), true );
        };
        $providers = $this->providers();
        // prices in another currency ([Model_<alias>] or [Provider_<id>] PriceCurrency, e.g. EUR) are converted into
        // [Pricing] Currency; without a rate they are unknown
        $currency = isset( $values['PriceCurrency'] ) && trim( (string) $values['PriceCurrency'] ) !== '' ? (string) $values['PriceCurrency']
                  : ( isset( $providers[$provider]['PriceCurrency'] ) ? (string) $providers[$provider]['PriceCurrency'] : '' );
        $rate = expAiCost::rate( $currency, $this->config );
        $price = function ( $name ) use ( $values, $rate )
        {
            return $rate !== null && isset( $values[$name] ) && is_numeric( $values[$name] ) ? (float) $values[$name] * $rate : null;
        };
        $toolUse = isset( $values['ToolUse'] ) ? strtolower( trim( (string) $values['ToolUse'] ) ) : 'native';
        $toolUse = in_array( $toolUse, array( 'native', 'prompted', 'none' ), true ) ? $toolUse : 'native';
        $capabilities = isset( $values['Capabilities'] ) ? (array) $values['Capabilities'] : array();
        $capabilities = array_values( array_filter( array_map( 'strtolower', array_map( 'trim', array_map( 'strval', $capabilities ) ) ), 'strlen' ) );
        return array(
            'alias' => $alias,
            'provider' => $provider,
            'id' => trim( (string) $values['Id'] ),
            'label' => isset( $values['Label'] ) && $values['Label'] !== '' ? (string) $values['Label'] : $alias,
            'tool_use' => $toolUse,
            'write_tools' => $toolUse === 'native' && $flag( 'WriteTools', 'disabled' ),
            'write_gate' => isset( $values['WriteGate'] ) && strtolower( (string) $values['WriteGate'] ) === 'trusted' ? 'trusted' : 'probe',
            'capabilities' => $capabilities,
            'max_output' => isset( $values['MaxOutput'] ) && (int) $values['MaxOutput'] > 0 ? (int) $values['MaxOutput'] : 0,
            'effort' => isset( $values['Effort'] ) ? (string) $values['Effort'] : '',
            // the context window in tokens ([Model_<alias>] ContextTokens; 0: not stated), shown on expai/models
            'context_tokens' => isset( $values['ContextTokens'] ) && (int) $values['ContextTokens'] > 0 ? (int) $values['ContextTokens'] : 0,
            'price_currency' => strtoupper( trim( $currency ) ),
            'prices' => array( 'input' => $price( 'InputPerMTok' ), 'output' => $price( 'OutputPerMTok' ),
                               'cache_write' => $price( 'CacheWritePerMTok' ), 'cache_read' => $price( 'CacheReadPerMTok' ) ),
            'slow' => $flag( 'Slow', isset( $providers[$provider]['Region'] ) && $providers[$provider]['Region'] === 'local' ? 'true' : 'false' ),
            'settings' => $values,
        );
    }

    /** @return array|null */
    public function getModel( $alias )
    {
        $models = $this->models();
        return isset( $models[$alias] ) ? $models[$alias] : null;
    }

    /** @return string the provider id of a model alias, '' when the alias is not a known model (never a guessed provider) */
    public function providerOf( $alias )
    {
        $model = $this->getModel( (string) $alias );
        return $model ? (string) $model['provider'] : '';
    }

    /** @return array|null the settings of a provider (with the key) */
    public function getProvider( $id )
    {
        $providers = $this->providers();
        return isset( $providers[$id] ) ? $providers[$id] : null;
    }

    /**
     * The alias a stored value means: an alias, or a bare model id of 0.0.1.0 (anthropic.<id>, or the alias whose Id
     * it is).
     * @return string '' when it means nothing known
     */
    public function resolveAlias( $value )
    {
        $value = trim( (string) $value );
        if ( $value === '' )
            return '';
        $models = $this->models();
        if ( isset( $models[$value] ) )
            return $value;
        if ( isset( $models['anthropic.' . $value] ) )
            return 'anthropic.' . $value;
        foreach ( $models as $alias => $model )
            if ( $model['id'] === $value )
                return $alias;
        return '';
    }

    /** @return string the site default alias: [ProviderSettings] DefaultModel, else anthropic.<[AISettings] Model>, else the first model */
    public function defaultAlias()
    {
        $alias = $this->resolveAlias( (string) $this->config->get( 'ProviderSettings', 'DefaultModel', '' ) );
        if ( $alias === '' )
            $alias = $this->resolveAlias( $this->config->defaultModelId() );
        if ( $alias === '' )
        {
            $models = $this->models();
            $alias = $models ? (string) key( $models ) : '';
        }
        return $alias;
    }

    /**
     * The settings a provider object is built with: the provider's, then the model's (Id becomes Model, MaxOutput
     * MaxTokens), and ProviderId, ModelAlias, ToolUse, Capabilities.
     * @return array (contains the key)
     */
    public function settingsFor( $alias )
    {
        $model = $this->getModel( $alias );
        if ( !$model )
            return array();
        $settings = $this->getProvider( $model['provider'] );
        foreach ( $model['settings'] as $name => $value )
            if ( !in_array( $name, array( 'Provider', 'Id', 'Label', 'ApiKey' ), true ) && $value !== '' && $value !== array() )
                $settings[$name] = $value;
        $settings['Model'] = $model['id'];
        if ( $model['max_output'] > 0 )
            $settings['MaxTokens'] = $model['max_output'];
        elseif ( empty( $settings['MaxTokens'] ) )
            $settings['MaxTokens'] = $this->config->get( 'AISettings', 'MaxTokens', 8000 );
        if ( $model['effort'] !== '' )
            $settings['Effort'] = $model['effort'];
        // #377 background runs (the queue worker, the command line: no web request and no web server's time limit) get
        // their own, longer time limit: [Provider_<id>] / [Model_<alias>] TimeoutBackground (seconds)
        if ( isset( $settings['TimeoutBackground'] ) && (int) $settings['TimeoutBackground'] > 0 && class_exists( 'expAiWebBudget' ) && !expAiWebBudget::inWebRequest() )
            $settings['Timeout'] = (int) $settings['TimeoutBackground'];
        $settings['ProviderId'] = $model['provider'];
        $settings['ModelAlias'] = $alias;
        $settings['ToolUse'] = $model['tool_use'];
        $settings['Capabilities'] = $model['capabilities'];
        return $settings;
    }

    /**
     * The provider object of a model alias (a bare id of 0.0.1.0 is resolved).
     * @return expAiProvider
     * @throws expAiException
     */
    public function providerFor( $alias )
    {
        $resolved = $this->resolveAlias( $alias );
        if ( $resolved === '' )
            $resolved = $this->defaultAlias();
        $settings = $this->settingsFor( $resolved );
        if ( !$settings )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'no model ' . $alias . ' in the provider registry' );
        $type = (string) $settings['Type'];
        if ( $type === 'custom' )
        {
            $class = trim( (string) ( isset( $settings['Class'] ) ? $settings['Class'] : '' ) );
            if ( $class === '' || !class_exists( $class ) || !in_array( 'expAiProvider', class_implements( $class ), true ) )
                throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'ProviderClass does not implement expAiProvider' );
            return new $class( $settings );
        }
        $types = self::TYPES;
        if ( !isset( $types[$type] ) )
            throw new expAiException( 'Exponential Intelligence is not configured correctly.', 'unknown provider Type ' . preg_replace( '/[^\w-]/', '', $type ) );
        $class = $types[$type];
        return new $class( $settings );
    }

    /** @return bool the provider can be called: a key, or one is not needed */
    public function providerReady( $id )
    {
        $settings = $this->getProvider( $id );
        if ( !$settings )
            return false;
        if ( $settings['Type'] === 'custom' )
            return !empty( $settings['Class'] );
        $header = strtolower( trim( (string) ( isset( $settings['ApiKeyHeader'] ) ? $settings['ApiKeyHeader'] : '' ) ) );
        $optional = isset( $settings['KeyOptional'] ) && in_array( strtolower( (string) $settings['KeyOptional'] ), array( 'true', 'enabled', '1', 'yes' ), true );
        return $header === 'none' || $optional || trim( (string) ( isset( $settings['ApiKey'] ) ? $settings['ApiKey'] : '' ) ) !== '';
    }

    /**
     * A provider for the admin views: everything but the key ("key present" instead).
     * @return array
     */
    public function describeProvider( $id )
    {
        $settings = $this->getProvider( $id );
        if ( !$settings )
            return array();
        $url = (string) ( isset( $settings['BaseURL'] ) && $settings['BaseURL'] !== '' ? $settings['BaseURL'] : ( isset( $settings['Endpoint'] ) ? $settings['Endpoint'] : '' ) );
        if ( $url === '' && $settings['Type'] === 'anthropic' )
            $url = expAiAnthropicProvider::DEFAULT_ENDPOINT;
        $models = array_values( array_filter( $this->models(), function ( $m ) use ( $id ) { return $m['provider'] === $id; } ) );
        return array(
            'id' => $id,
            'label' => (string) $settings['Label'],
            'type' => (string) $settings['Type'],
            'flavor' => (string) ( isset( $settings['Flavor'] ) ? $settings['Flavor'] : '' ),
            'host' => (string) parse_url( $url, PHP_URL_HOST ),
            'url' => preg_replace( '/[?#].*$/', '', $url ),
            'region' => (string) $settings['Region'],
            'retention' => (string) $settings['Retention'],
            'terms_checked_at' => (string) $settings['TermsCheckedAt'],
            'key_present' => trim( (string) ( isset( $settings['ApiKey'] ) ? $settings['ApiKey'] : '' ) ) !== '',
            'ready' => $this->providerReady( $id ),
            'managed' => (string) ( isset( $settings['Managed'] ) ? $settings['Managed'] : '' ),
            // #377 prompt_profile: compact (a short prompt and the tools a message needs) or full, expAiCompactPrompt
            'models' => array_map( function ( $m ) { unset( $m['settings'] ); return $m + array( 'prompt_profile' => class_exists( 'expAiCompactPrompt' ) ? expAiCompactPrompt::profile( $this->config, $m['alias'] ) : 'full' ); }, $models ),
        );
    }

    /**
     * Problems of the key placement, for the administrator: an ApiKey or Token in a file that is not an override
     * (the extension's settings, a siteaccess file inside an extension) is shipped with the code. Checked: [AISettings],
     * [Provider_<id>], [McpServer_<id>], the web search services [SearchProvider_<id>] and the translation services
     * [TranslationProvider_<id>].
     * @return string[] readable warnings (paths relative to the installation, never the key)
     */
    public function keyPlacementProblems()
    {
        $ini = $this->config->ini();
        $ini->loadPlacement( false );
        $placements = $ini->groupPlacements();
        $problems = array();
        foreach ( $placements as $group => $names )
        {
            if ( !is_array( $names ) || !self::keyGroup( $group ) )
                continue;
            foreach ( array( 'ApiKey', 'Token' ) as $name )
            {
                if ( !isset( $names[$name] ) || trim( (string) $ini->variable( $group, $name ) ) === '' )
                    continue;
                $path = is_array( $names[$name] ) ? (string) reset( $names[$name] ) : (string) $names[$name];
                $file = ltrim( str_replace( eZSys::rootDir(), '', $path ), '/' );
                $placement = $path !== '' ? (string) $ini->findSettingPlacement( $path ) : '';
                if ( $placement !== 'override' && strpos( $file, 'settings/override/' ) !== 0 && !preg_match( '~^settings/siteaccess/~', $file )
                     && !$this->acceptedKeyLocation( $file, $path ) )
                    $problems[] = "[$group] $name is set in $file ($placement); keys belong in settings/override or in a listed location that git does not track";
                elseif ( is_file( $path ) && ( fileperms( $path ) & 0004 ) )
                    $problems[] = "$file holds [$group] $name and is readable by every user of the server (use mode 0640)";
            }
        }
        return array_values( array_unique( $problems ) );
    }

    /** @return bool the group may hold a key (ApiKey, Token) whose placement keyPlacementProblems() checks */
    public static function keyGroup( $group )
    {
        $group = (string) $group;
        if ( $group === 'AISettings' )
            return true;
        foreach ( array( 'Provider_', 'McpServer_', 'SearchProvider_', 'TranslationProvider_' ) as $prefix )
            if ( strpos( $group, $prefix ) === 0 )
                return true;
        return false;
    }

    /**
     * A file that matches [ProviderSettings] KeyLocations[] (fnmatch patterns relative to the installation, default
     * settings/override and the settings directory of any extension) and is not shipped with the code: git does not track it, or, in a
     * release directory without git, it lies outside the release (a symlink to shared files). A tracked file never
     * qualifies: its key is in the repository.
     *
     * @param string $file the path relative to the installation
     * @param string $path the path as the INI placement names it
     * @return bool
     */
    protected function acceptedKeyLocation( $file, $path )
    {
        $patterns = array_filter( array_map( 'trim', array_map( 'strval', $this->config->arr( 'ProviderSettings', 'KeyLocations' ) ) ), 'strlen' );
        if ( !$patterns )
            $patterns = array( 'settings/override', 'extension/*settings' );
        $matches = false;
        foreach ( $patterns as $pattern )
        {
            $pattern = rtrim( $pattern, '/' );
            if ( fnmatch( $pattern . '/*', $file ) || fnmatch( $pattern, $file ) )
            {
                $matches = true;
                break;
            }
        }
        // true: in a repository; false: not; null: git could not say (a broken checkout, no git): counted as shipped, so
        // the warning stays (a key that may be in a repository is not accepted on a guess)
        return $matches && self::shippedWithTheCode( $file, $path ) === false;
    }

    /**
     * @return bool|null the file is part of the code: tracked by the nearest git work tree that contains it (true; an
     *                   extension is often a clone of its own, which the installation's repository does not see), not
     *                   tracked (false), git cannot say (null); without a git work tree: inside the release's real path
     *                   (true) or outside it (false)
     */
    protected static function shippedWithTheCode( $file, $path )
    {
        $root = rtrim( (string) eZSys::rootDir(), '/' );
        $absolute = $path !== '' && $path[0] === '/' ? $path : $root . '/' . $file;
        $repository = self::nearestWorkTree( dirname( $absolute ), $root );
        if ( $repository !== null && function_exists( 'exec' ) )
        {
            $output = array();
            $status = 1;
            $relative = ltrim( substr( $absolute, strlen( $repository ) ), '/' );
            // safe.directory for this one call: the web server's user often differs from the checkout's owner, and git
            // then refuses to answer (dubious ownership) although only a file list is read
            @exec( 'git -c safe.directory=' . escapeshellarg( $repository ) . ' -C ' . escapeshellarg( $repository )
                   . ' ls-files --error-unmatch -- ' . escapeshellarg( $relative ) . ' 2>/dev/null', $output, $status );
            // 0: tracked. 1: not tracked. Anything else (127 no git): git cannot say
            if ( $status === 0 )
                return true;
            return $status === 1 ? false : null;
        }
        $real = realpath( $absolute );
        $realRoot = realpath( $root );
        return $real === false || $realRoot === false || strpos( $real, $realRoot . '/' ) === 0;
    }

    /**
     * @param string $directory where the search starts
     * @param string $root the installation; the search does not go above it
     * @return string|null the nearest directory from $directory up to $root that holds a .git (directory or file), null
     *                     when there is none
     */
    protected static function nearestWorkTree( $directory, $root )
    {
        $directory = rtrim( (string) $directory, '/' );
        $root = rtrim( (string) $root, '/' );
        if ( $root === '' || ( $directory !== $root && strpos( $directory, $root . '/' ) !== 0 ) )
            return file_exists( $root . '/.git' ) ? $root : null;
        while ( true )
        {
            if ( file_exists( $directory . '/.git' ) )
                return $directory;
            if ( $directory === $root || strlen( $directory ) <= strlen( $root ) )
                return null;
            $directory = dirname( $directory );
        }
    }
}
