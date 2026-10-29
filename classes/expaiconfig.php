<?php
/**
 * File containing the expAiConfig class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The settings of expai.ini, read fresh on every call (no static state: Velocity's persistent workers serve many
 * requests and siteaccesses with one process).
 */
class expAiConfig
{
    /** @var eZINI */
    protected $ini;

    public function __construct( $ini = null )
    {
        $this->ini = $ini ?: eZINI::instance( 'expai.ini' );
    }

    /** @return expAiConfig */
    public static function instance()
    {
        return new self();
    }

    /**
     * An extension expai works with (expqueue, expservices, expmcp) is used only when it is active in the current
     * siteaccess: the class autoload of an installation lists the classes of every extension in the tree, active or
     * not, so class_exists() alone would use an extension the site has switched off.
     * @param string $extension the directory name of the extension
     * @param string[]|null $active the active extensions (default eZExtension::activeExtensions())
     * @return bool
     */
    public static function extensionActive( $extension, ?array $active = null )
    {
        if ( $active === null )
            $active = class_exists( 'eZExtension' ) ? (array) eZExtension::activeExtensions() : array();
        return in_array( (string) $extension, $active, true );
    }

    /** @return eZINI */
    public function ini()
    {
        return $this->ini;
    }

    /**
     * @param string $group
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function get( $group, $name, $default = null )
    {
        if ( !$this->ini->hasVariable( $group, $name ) )
            return $default;
        return $this->ini->variable( $group, $name );
    }

    /** @return int */
    public function int( $group, $name, $default, $min = null, $max = null )
    {
        $value = $this->get( $group, $name, $default );
        $value = is_numeric( $value ) ? (int) $value : (int) $default;
        if ( $min !== null )
            $value = max( $min, $value );
        if ( $max !== null )
            $value = min( $max, $value );
        return $value;
    }

    /** @return bool true for enabled / true / 1 / yes */
    public function flag( $group, $name, $default = false )
    {
        $value = $this->get( $group, $name, null );
        if ( $value === null )
            return $default;
        return in_array( strtolower( trim( (string) $value ) ), array( 'enabled', 'true', '1', 'yes', 'on' ), true );
    }

    /** @return array */
    public function arr( $group, $name )
    {
        $value = $this->get( $group, $name, array() );
        if ( !is_array( $value ) )
            return $value === '' || $value === null ? array() : array( $value );
        return array_values( array_filter( $value, static function ( $v ) { return $v !== '' && $v !== null; } ) );
    }

    /**
     * @return bool the assistant is switched on (it may still lack a key, see isConfigured()): the master switch
     *              [AISettings] Enabled and, for the current siteaccess, DisabledSiteAccesses[] (expAiGate)
     */
    public function isEnabled()
    {
        // a long-running worker started before expAiGate was added knows only the flag
        return class_exists( 'expAiGate' ) ? expAiGate::switchedOff( $this ) === '' : $this->flag( 'AISettings', 'Enabled', false );
    }

    /** @return bool a provider can be called: enabled, a default model, and its provider has a key or needs none */
    public function isConfigured()
    {
        if ( !$this->isEnabled() )
            return false;
        $registry = $this->registry();
        $model = $registry->getModel( $registry->defaultAlias() );
        return $model && $registry->providerReady( $model['provider'] );
    }

    /** @return expAiProviderRegistry */
    public function registry()
    {
        return new expAiProviderRegistry( $this );
    }

    /** @return string the model id of [AISettings] Model (0.0.1.0: the only model setting) */
    public function defaultModelId()
    {
        return trim( (string) $this->get( 'AISettings', 'Model', '' ) );
    }

    /** @return string the alias of the site default model ([ProviderSettings] DefaultModel, else anthropic.<[AISettings] Model>) */
    public function model()
    {
        return $this->registry()->defaultAlias();
    }

    /**
     * The settings of the provider of the current user's model, with that model (the key included: never log or
     * output this array).
     * @return array
     */
    public function providerSettings()
    {
        return $this->registry()->settingsFor( $this->effectiveModel() );
    }

    /** @return bool users may choose a model ([AISettings] AllowUserModelChoice) */
    public function modelChoiceEnabled()
    {
        return $this->flag( 'AISettings', 'AllowUserModelChoice', false );
    }

    /**
     * The models the current user may choose, as alias => label: the site default always; with model choice on,
     * the models of [ProviderSettings] ProvidersWithoutPolicy[] (default anthropic) and of the providers the policy
     * expai/model_use allows (limitation Provider).
     * @return array alias => label
     */
    public function allowedModels()
    {
        $out = array();
        foreach ( $this->allowedModelRows() as $alias => $model )
            $out[$alias] = $model['label'];
        return $out;
    }

    /** @return array alias => model (expAiProviderRegistry::models()) of allowedModels() */
    public function allowedModelRows()
    {
        $registry = $this->registry();
        $default = $registry->defaultAlias();
        $models = $registry->models();
        $out = array();
        if ( isset( $models[$default] ) )
            $out[$default] = $models[$default];
        if ( !$this->modelChoiceEnabled() )
            return $out;
        $open = $this->get( 'ProviderSettings', 'ProvidersWithoutPolicy', null ) === null ? array( 'anthropic' ) : $this->arr( 'ProviderSettings', 'ProvidersWithoutPolicy' );
        $policy = expAiPolicy::limitationValues( 'model_use', 'Provider' );
        foreach ( $models as $alias => $model )
        {
            if ( isset( $out[$alias] ) )
                continue;
            if ( in_array( $model['provider'], $open, true ) || $policy === true || ( is_array( $policy ) && in_array( $model['provider'], $policy, true ) ) )
                $out[$alias] = $model;
        }
        return $out;
    }

    /**
     * The models of allowedModels() grouped by provider, for the model picker.
     * @return array list of { id, label, region, retention, models: list of { alias, label, tool_use, cost (free, $, $$, $$$), slow, writes } }
     */
    public function modelChoices()
    {
        $registry = $this->registry();
        $groups = array();
        foreach ( $this->allowedModelRows() as $alias => $model )
        {
            $id = $model['provider'];
            if ( !isset( $groups[$id] ) )
            {
                $provider = $registry->getProvider( $id );
                $groups[$id] = array( 'id' => $id, 'label' => (string) $provider['Label'], 'region' => (string) $provider['Region'],
                                      'retention' => (string) $provider['Retention'], 'models' => array() );
            }
            $groups[$id]['models'][] = array( 'alias' => $alias, 'label' => $model['label'], 'tool_use' => $model['tool_use'],
                                              'cost' => expAiCost::costClass( $alias ), 'slow' => $model['slow'],
                                              'writes' => expAiProbe::writeAllowed( $alias, $this ),
                                              // stopped (a local server that does not run) or unreachable: shown, not selectable
                                              // (the site default always is); expAiProviderHealth, kept briefly per process
                                              'state' => $state = $this->modelState( $alias, $model ),
                                              'disabled' => $state !== '' && $alias !== $registry->defaultAlias() );
        }
        return array_values( $groups );
    }

    /**
     * The state of a model for the pickers: '' (usable), stopped (its local server does not run) or unreachable.
     * @return string
     */
    public function modelState( $alias, ?array $model = null )
    {
        $model = $model ?: $this->registry()->getModel( (string) $alias );
        return $model ? expAiProviderHealth::state( $this, $model['provider'] ) : '';
    }

    /**
     * The state of every model the current user may choose that is not usable now, with the text of the pickers.
     * @return array alias => { state, text, disabled }
     */
    public function modelStates()
    {
        $default = $this->model();
        $manage = expAiServerFunctions::hasPolicy( 'model_manage' );
        $out = array();
        foreach ( $this->allowedModelRows() as $alias => $model )
        {
            $state = $this->modelState( $alias, $model );
            if ( $state === '' )
                continue;
            $out[$alias] = array( 'state' => $state, 'disabled' => $alias !== $default,
                                  'text' => $state === expAiProviderHealth::STOPPED
                                          ? ( $manage ? ezpI18n::tr( 'extension/expai/model', 'stopped — start it under Models' ) : ezpI18n::tr( 'extension/expai/model', 'stopped' ) )
                                          : ezpI18n::tr( 'extension/expai/model', 'unreachable' ) );
        }
        return $out;
    }

    /** @return string the model the current user chose (preference expai_model, an alias), '' when none */
    public function preferredModel()
    {
        $user = eZUser::currentUser();
        if ( !$user instanceof eZUser || !$user->isRegistered() )
            return '';
        $value = eZPreferences::value( 'expai_model', $user );
        return is_string( $value ) && trim( $value ) !== '' ? $this->registry()->resolveAlias( $value ) ?: trim( $value ) : '';
    }

    /** @return bool the user chose a model that is no longer allowed (the default is used instead) */
    public function preferredModelRefused()
    {
        $preferred = $this->preferredModel();
        return $preferred !== '' && ( !$this->modelChoiceEnabled() || !isset( $this->allowedModels()[$preferred] ) );
    }

    /** @return string the alias requests use: the user's allowed choice, else the site default */
    public function effectiveModel()
    {
        $preferred = $this->preferredModel();
        if ( $preferred !== '' && $this->modelChoiceEnabled() && isset( $this->allowedModels()[$preferred] ) )
            return $preferred;
        return $this->model();
    }

    /** @return bool the current user may use the model (the default, or allowed by the policy) */
    public function mayUseModel( $alias )
    {
        return isset( $this->allowedModels()[$alias] );
    }

    /**
     * Where the site default model is set, for administrators.
     * @return array placement (default, extension:<name>, siteaccess, override ...), file
     */
    public function modelPlacement()
    {
        // loadPlacement() with its default $reset = true empties the values of the shared instance: keep them
        $this->ini->loadPlacement( false );
        $placements = $this->ini->groupPlacements();
        $path = isset( $placements['ProviderSettings']['DefaultModel'] ) && trim( (string) $this->get( 'ProviderSettings', 'DefaultModel', '' ) ) !== ''
              ? $placements['ProviderSettings']['DefaultModel'] : ( isset( $placements['AISettings']['Model'] ) ? $placements['AISettings']['Model'] : '' );
        $file = is_array( $path ) ? ( isset( $path[0] ) ? $path[0] : '' ) : (string) $path;
        return array( 'placement' => $path ? $this->ini->findSettingPlacement( $path ) : '',
                      'file' => ltrim( str_replace( eZSys::rootDir(), '', $file ), '/' ) );
    }

    /** @return string[] datatype strings the tools and field actions may write */
    public function writableDatatypes()
    {
        return $this->arr( 'ToolSettings', 'WritableDatatypes' );
    }
}
