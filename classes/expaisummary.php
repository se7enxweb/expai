<?php
/**
 * File containing the expAiSummary class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The content of the "EI: Summary" page (expai/summary, doc/summary.md): what goes where, kept how long, used by whom and at
 * what cost, read from the settings, the roles, the probe results and the usage log as they are now. Never a key:
 * only where a key comes from (the settings file) and whether one is set.
 */
class expAiSummary
{
    /** @var expAiConfig */
    protected $config;

    /** @var expAiProviderRegistry */
    protected $registry;

    /** @var array|null group => name => path (the INI placements) */
    protected $placements;

    public function __construct( ?expAiConfig $config = null )
    {
        $this->config = $config ?: expAiConfig::instance();
        $this->registry = $this->config->registry();
    }

    /** @return array every fact, for the template */
    public function collect()
    {
        $roles = $this->roles();
        return array(
            'generated' => time(),
            'site' => (string) eZINI::instance()->variable( 'SiteSettings', 'SiteName' ),
            'version' => $this->version(),
            'contact' => trim( (string) $this->config->get( 'SummarySettings', 'Contact', '' ) ),
            'note' => trim( (string) $this->config->get( 'SummarySettings', 'Note', '' ) ),
            'switches' => $this->switches(),
            'providers' => $this->providers( $roles ),
            'translation' => $this->translation(),
            'policy' => $this->dataPolicy(),
            'limits' => $this->limits(),
            'retention' => $this->retention(),
            'tools' => $this->tools(),
            'roles' => $roles,
            'currency' => expAiUsage::currency(),
            'costs' => expAiCost::enabled( $this->config ),
        );
    }

    /**
     * #389 the summed cost of usage totals (expAiUsage::aggregate()): the priced requests, "≈" when fallback prices
     * were used, and "(15 requests without a price)" when some had none; the same figure as the dashboard and the log.
     * @return string
     */
    public static function costText( array $totals )
    {
        $text = expAiCost::money( $totals['cost'], !empty( $totals['cost_approx'] ) );
        $note = expAiUsage::unpricedNote( isset( $totals['unpriced'] ) ? $totals['unpriced'] : 0 );
        return $note !== '' ? $text . ' (' . $note . ')' : $text;
    }

    /** @return string a region in words: US, EU, this server, unknown (translated) */
    public static function regionLabel( $region )
    {
        $labels = array( 'us' => 'US', 'eu' => 'EU', 'local' => 'this server' );
        return ezpI18n::tr( 'extension/expai/summary', isset( $labels[$region] ) ? $labels[$region] : 'unknown' );
    }

    /** @return string the version of expai (ezinfo.php) */
    protected function version()
    {
        if ( class_exists( 'expaiInfo' ) )
        {
            $info = expaiInfo::info();
            return isset( $info['Version'] ) ? (string) $info['Version'] : '';
        }
        return '';
    }

    /** @return array name => bool: the master switch and the switches of the surfaces */
    protected function switches()
    {
        $c = $this->config;
        $out = array(
            'master' => $c->isEnabled(),
            'configured' => $c->isConfigured(),
            'edit_form' => $c->flag( 'EditViewSettings', 'Enabled', true ),
            'presets' => $c->flag( 'EditPresets', 'Enabled', true ),
            'vector' => $c->flag( 'VectorSettings', 'Enabled', true ),
            'translation_sync' => $c->flag( 'TranslationSync', 'Enabled', false ),
            'mcp_bridge' => $c->flag( 'McpBridge', 'Enabled', false ),
            'user_model_choice' => $c->modelChoiceEnabled(),
        );
        if ( class_exists( 'expAiServiceTools' ) )
            $out['service_tools'] = expAiServiceTools::available() && expAiServiceTools::enabled( $c );
        return $out;
    }

    /** @return array group => name => path */
    protected function placements()
    {
        if ( $this->placements === null )
        {
            $ini = $this->config->ini();
            $ini->loadPlacement( false );
            $this->placements = $ini->groupPlacements();
        }
        return $this->placements;
    }

    /**
     * Where a secret setting comes from: the file (relative to the installation) and its kind, never the value.
     * @return array set (bool), file, placement (override, siteaccess, extension, default, ...)
     */
    public function secretSource( $group, $name )
    {
        $value = trim( (string) $this->config->get( $group, $name, '' ) );
        if ( $value === '' )
            return array( 'set' => false, 'file' => '', 'placement' => '' );
        $placements = $this->placements();
        $path = isset( $placements[$group][$name] ) ? $placements[$group][$name] : '';
        $path = is_array( $path ) ? (string) reset( $path ) : (string) $path;
        return array( 'set' => true, 'file' => ltrim( str_replace( eZSys::rootDir(), '', $path ), '/' ),
                      'placement' => $path !== '' ? (string) $this->config->ini()->findSettingPlacement( $path ) : '' );
    }

    /** @return array the providers with their models, lanes, key source, who may use them and this month's usage */
    protected function providers( array $roles )
    {
        $without = $this->config->arr( 'ProviderSettings', 'ProvidersWithoutPolicy' );
        $default = $this->registry->defaultAlias();
        $threshold = expAiProbe::threshold( $this->config );
        $monthStart = expAiLimits::periodStart( 'month' );
        $limits = expAiLimits::providerDefinitions( $this->config );
        $out = array();
        foreach ( $this->registry->providers() as $id => $settings )
        {
            $p = $this->registry->describeProvider( $id );
            $group = $this->config->ini()->hasGroup( 'Provider_' . $id ) ? 'Provider_' . $id : 'AISettings';
            $header = strtolower( (string) ( isset( $settings['ApiKeyHeader'] ) ? $settings['ApiKeyHeader'] : '' ) );
            $key = $this->secretSource( $group, 'ApiKey' );
            $key['needed'] = !( $header === 'none' || $settings['Region'] === 'local' );
            $key['group'] = $group;
            $models = array();
            foreach ( $p['models'] as $m )
            {
                $live = expAiProbe::latest( $m['alias'], 'live' );
                $models[] = array(
                    'alias' => $m['alias'], 'label' => $m['label'], 'id' => $m['id'], 'default' => $m['alias'] === $default,
                    'tool_use' => $m['tool_use'], 'write_gate' => $m['write_gate'],
                    'writes' => expAiProbe::writeAllowed( $m['alias'], $this->config ),
                    'write_refusal' => expAiProbe::writeRefusal( $m['alias'], $this->config ),
                    'probe' => $live ? array( 'created' => (int) $live->attribute( 'created' ), 'pass_rate' => (int) $live->attribute( 'pass_rate' ),
                                              'model' => (string) $live->attribute( 'model' ) ) : false,
                    'cost_class' => expAiCost::costClass( $m['alias'], $this->config ),
                    'capabilities' => $m['capabilities'],
                );
            }
            $lanes = array();
            foreach ( class_exists( 'expAiLane' ) ? expAiLane::forProvider( $id, $this->config ) : array() as $name => $lane )
            {
                $data = $lane->toArray();
                if ( $name !== 'standard' && empty( $data['configured'] ) )
                    continue;
                $lanes[] = array( 'name' => $name, 'batch' => $lane->isBatch(), 'usable' => $lane->usable(), 'retention' => $lane->retention(),
                                  'zero_retention' => $lane->zeroRetention(), 'factor' => $lane->factorPercent(),
                                  'delete_after_fetch' => !empty( $data['delete_after_fetch'] ) && $lane->isBatch(),
                                  'verified' => isset( $data['verified'] ) ? (string) $data['verified'] : '' );
            }
            $usage = expAiUsage::aggregate( array( 'provider' => $id, 'from' => $monthStart ) );
            $providerLimits = array();
            foreach ( $limits as $def )
                if ( $def[4] === $id )
                    $providerLimits[] = array( 'unit' => $def[1], 'max' => (float) $def[5] );
            $out[] = array(
                'id' => $id, 'label' => $p['label'], 'type' => $p['type'], 'host' => $p['host'], 'region' => $p['region'], 'region_label' => self::regionLabel( $p['region'] ),
                'retention' => $p['retention'], 'terms_checked_at' => $p['terms_checked_at'], 'ready' => $p['ready'],
                'key' => $key, 'everyone' => in_array( $id, $without, true ),
                'roles' => $this->rolesForProvider( $roles, $id, in_array( $id, $without, true ) ),
                'models' => $models, 'lanes' => $lanes, 'threshold' => $threshold,
                'usage' => array( 'requests' => (int) $usage['totals']['requests'], 'tokens' => (int) ( $usage['totals']['input'] + $usage['totals']['output']
                                  + $usage['totals']['cache_write'] + $usage['totals']['cache_read'] ),
                                  'cost' => self::costText( $usage['totals'] ) ),
                'limits' => $providerLimits,
            );
        }
        return $out;
    }

    /**
     * The translation services (doc/translation-providers.md): the default and the fallback, the rules per language
     * pair and purpose, every service with its region, retention, the source of its key and why it cannot be used.
     * @return array default, fallback, rules (list of rule, provider), services (expAiTranslationProviderRegistry::describe()
     *               rows with key and region_label)
     */
    protected function translation()
    {
        $registry = new expAiTranslationProviderRegistry( $this->config );
        $services = array();
        foreach ( $registry->describe() as $row )
        {
            $key = $row['group'] !== '' ? $this->secretSource( $row['group'], 'ApiKey' ) : array( 'set' => false, 'file' => '', 'placement' => '' );
            $key['needed'] = $row['kind'] === 'mt' && $row['region'] !== 'local';
            $services[] = $row + array( 'key' => $key, 'region_label' => self::regionLabel( $row['region'] ) );
        }
        $rules = array();
        foreach ( $registry->rules() as $rule => $id )
            $rules[] = array( 'rule' => 'ProviderFor[' . $rule . ']', 'provider' => $id );
        return array( 'default' => $registry->defaultId(), 'fallback' => $registry->fallbackId(), 'rules' => $rules, 'services' => $services );
    }

    /**
     * The roles that may use a provider's models: with expai/write when the provider needs no policy, else with
     * expai/model_use (unlimited, or limited to the provider). The site default model is open to expai/write always.
     * @return string[] role names
     */
    protected function rolesForProvider( array $roles, $providerID, $everyone )
    {
        $out = array();
        foreach ( $roles as $role )
            foreach ( $role['policies'] as $policy )
            {
                $fn = $policy['function'];
                if ( $everyone && ( $fn === 'write' || $fn === '*' ) )
                    $out[] = $role['name'];
                elseif ( !$everyone && ( $fn === 'model_use' || $fn === '*' ) )
                {
                    $providers = isset( $policy['limitations']['Provider'] ) ? $policy['limitations']['Provider'] : null;
                    if ( $providers === null || in_array( $providerID, $providers, true ) )
                        $out[] = $role['name'];
                }
            }
        return array_values( array_unique( $out ) );
    }

    /**
     * Every role with a policy of the module expai (or of every module).
     * @return array list of { name, policies: list of { function, limitations: identifier => values } }
     */
    protected function roles()
    {
        $out = array();
        foreach ( eZRole::fetchList() as $role )
        {
            $policies = array();
            foreach ( $role->policyList() as $policy )
            {
                $module = (string) $policy->attribute( 'module_name' );
                if ( $module !== 'expai' && $module !== '*' )
                    continue;
                $limitations = array();
                foreach ( $policy->limitationList() as $limitation )
                    $limitations[(string) $limitation->attribute( 'identifier' )] = array_map( 'strval', (array) $limitation->allValues() );
                $policies[] = array( 'function' => $module === '*' ? '*' : (string) $policy->attribute( 'function_name' ), 'limitations' => $limitations );
            }
            if ( $policies )
                $out[] = array( 'name' => (string) $role->attribute( 'name' ), 'policies' => $policies );
        }
        return $out;
    }

    /** @return array the data policy rules in words (section and class names resolved) */
    protected function dataPolicy()
    {
        $c = $this->config;
        $named = function ( $setting, $section )
        {
            $rows = array();
            $values = $this->config->get( 'DataPolicy', $setting, array() );
            foreach ( is_array( $values ) ? $values : array() as $key => $value )
            {
                if ( $value === '' || $value === null )
                    continue;
                $name = (string) $key;
                if ( $section )
                {
                    $s = eZSection::fetch( (int) $key );
                    $name = $s ? $s->attribute( 'name' ) . ' (' . (int) $key . ')' : '#' . (int) $key;
                }
                $rows[] = array( 'what' => $name, 'regions' => implode( ', ', array_filter( array_map( 'trim', explode( ';', (string) $value ) ) ) ) );
            }
            return $rows;
        };
        $sections = array();
        foreach ( $c->arr( 'DataPolicy', 'ZeroRetentionSections' ) as $id )
        {
            $s = eZSection::fetch( (int) $id );
            $sections[] = $s ? $s->attribute( 'name' ) . ' (' . (int) $id . ')' : '#' . (int) $id;
        }
        return array(
            'active' => ( new expAiDataPolicy( $c, array() ) )->active(),
            'default_regions' => $c->arr( 'DataPolicy', 'DefaultRegions' ),
            'sections' => $named( 'SectionRegions', true ),
            'classes' => $named( 'ClassRegions', false ),
            'zero_retention' => $c->flag( 'DataPolicy', 'ZeroRetention', false ),
            'zero_retention_sections' => $sections,
            'zero_retention_classes' => $c->arr( 'DataPolicy', 'ZeroRetentionClasses' ),
        );
    }

    /** @return array the limits that are set, the site's usage this month and the budget of queued requests */
    protected function limits()
    {
        $rows = array();
        foreach ( expAiLimits::status( array( 'user_id' => 0 ) ) as $key => $row )
        {
            if ( $row['max'] <= 0 )
                continue;
            $site = $row['scope'] === 'site';
            $rows[] = array( 'key' => $key, 'setting' => $row['setting'], 'scope' => $row['scope'], 'period' => $row['period'],
                             'provider' => isset( $row['provider'] ) ? $row['provider'] : '',
                             'max' => $row['unit'] === 'cost' ? expAiCost::money( $row['max'] ) : expAiCost::tokens( $row['max'] ),
                             'used' => $site ? ( $row['unit'] === 'cost' ? expAiCost::money( $row['used'] ) : expAiCost::tokens( $row['used'] ) ) : '',
                             'percent' => $site ? $row['percent'] : null );
        }
        $usage = expAiUsage::aggregate( array( 'from' => expAiLimits::periodStart( 'month' ) ) );
        $budget = class_exists( 'expAiBudget' ) ? new expAiBudget( $this->config ) : null;
        return array(
            'rows' => $rows,
            'month' => array( 'requests' => (int) $usage['totals']['requests'],
                              'tokens' => expAiCost::tokens( $usage['totals']['input'] + $usage['totals']['output'] + $usage['totals']['cache_write'] + $usage['totals']['cache_read'] ),
                              'cost' => self::costText( $usage['totals'] ) ),
            'budget' => $budget && $budget->siteCap() > 0 ? expAiBudget::money( $budget->siteCap() ) : '',
            'budget_spent' => $budget ? expAiBudget::money( $budget->spentThisMonth() ) : '',
        );
    }

    /** @return array how long expai itself keeps what it records */
    protected function retention()
    {
        $c = $this->config;
        return array(
            'usage_days' => $c->int( 'UsageSettings', 'RetentionDays', 365, 0 ),
            'log_requests' => $c->flag( 'UsageSettings', 'LogRequests', false ),
            'prompt_summary' => $c->int( 'LogSettings', 'MaxPromptSummaryLength', 200, 0 ),
            'thread_events' => $c->int( 'ThreadSettings', 'EventKeepDays', 30, 0 ),
            'thread_proposals' => $c->int( 'ThreadSettings', 'ProposalKeepDays', 30, 0 ),
            'thread_checkpoints' => $c->int( 'ThreadSettings', 'CheckpointKeepDays', 30, 0 ),
        );
    }

    /** @return array the tools that reach outside the site: web, search, history, MCP */
    protected function tools()
    {
        $c = $this->config;
        $out = array();
        $tools = $c->arr( 'ToolSettings', 'Tools' );
        foreach ( array( 'web_fetch', 'web_search', 'project_history' ) as $name )
        {
            $class = (string) $c->get( 'Tool_' . $name, 'Class', '' );
            $listed = in_array( $name, $tools, true );
            $configured = false;
            if ( $listed && $class !== '' && class_exists( $class ) )
            {
                $tool = new $class( $name );
                $configured = method_exists( $tool, 'configured' ) ? (bool) $tool->configured( $c ) : true;
            }
            $out[$name] = array( 'listed' => $listed, 'configured' => $configured );
        }
        $out['web_fetch']['domains'] = $c->arr( 'WebSettings', 'AllowedDomains' );
        $out['web_fetch']['robots'] = $c->flag( 'WebSettings', 'RespectRobots', true );
        $searchID = (string) $c->get( 'SearchSettings', 'Provider', '' );
        $out['web_search']['provider'] = (string) $c->get( 'SearchProvider_' . $searchID, 'Label', $searchID );
        $out['web_search']['region'] = expAiProviderRegistry::cleanRegion( $c->get( 'SearchProvider_' . $searchID, 'Region', '' ) );
        $out['web_search']['region_label'] = self::regionLabel( $out['web_search']['region'] );
        $out['web_search']['retention'] = expAiProviderRegistry::cleanRetention( $c->get( 'SearchProvider_' . $searchID, 'Retention', '' ) );
        $out['web_search']['key'] = $this->secretSource( 'SearchProvider_' . $searchID, 'ApiKey' );
        $out['project_history']['repositories'] = count( $c->arr( 'HistorySettings', 'Repositories' ) );
        $servers = array();
        foreach ( $c->arr( 'McpSettings', 'Servers' ) as $id )
            $servers[] = array( 'id' => $id, 'label' => (string) $c->get( 'McpServer_' . $id, 'Label', $id ),
                                'host' => (string) parse_url( (string) $c->get( 'McpServer_' . $id, 'URL', '' ), PHP_URL_HOST ),
                                'region' => expAiProviderRegistry::cleanRegion( $c->get( 'McpServer_' . $id, 'Region', '' ) ),
                                'region_label' => self::regionLabel( expAiProviderRegistry::cleanRegion( $c->get( 'McpServer_' . $id, 'Region', '' ) ) ),
                                'tools' => $c->arr( 'McpServer_' . $id, 'AllowedTools' ),
                                'token' => $this->secretSource( 'McpServer_' . $id, 'Token' ) );
        $out['mcp_servers'] = $servers;
        $out['mcp_bridge'] = array( 'enabled' => $c->flag( 'McpBridge', 'Enabled', false ), 'tools' => $c->arr( 'McpBridge', 'Tools' ) );
        return $out;
    }
}
