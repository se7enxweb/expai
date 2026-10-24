<?php
/**
 * File containing the expAiTranslationProviderRegistry class.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Which translation service translates what (expai.ini [TranslationSettings], doc/translation-providers.md):
 *
 *   Provider=llm                              the default service: llm (the model providers of expai), none (no
 *                                             translation), or the id of a [TranslationProvider_<id>] group
 *   ProviderFor[<source>:<target>:<purpose>]  per language pair and purpose (patch, full, field), the most specific
 *                                             rule wins: <source>:<target>:<purpose>, <source>:<target>,
 *                                             *:<target>:<purpose>, *:<target>, <source>:*:<purpose>, <source>:*,
 *                                             *:*:<purpose>, then Provider
 *   Fallback=llm                              the service used when the chosen one is not configured, does not
 *                                             translate the pair or the data policy keeps the content away from it;
 *                                             none: the translation is reported as not possible. A failure while the
 *                                             service translates (an outage, a used-up quota) never falls back: it
 *                                             fails visibly, so cost and the destination of the data do not change
 *                                             silently.
 *   DoNotTranslate[]                          terms that are never translated
 *   Glossary[<source>:<target>]=<file>        a tab-separated file (term, translation) for the llm service
 *
 * Without a [TranslationSettings] group the default is llm, as before translation services existed.
 */
class expAiTranslationProviderRegistry
{
    /** the service of an installation without [TranslationSettings] */
    const DEFAULT_PROVIDER = 'llm';

    /** @var expAiConfig */
    protected $config;

    /** @var array id => expAiTranslationProvider|false */
    protected $instances = array();

    /** @var array id => why it cannot be created */
    protected $problems = array();

    /** @var array path => glossary, read once per process */
    protected static $glossaries = array();

    public function __construct( ?expAiConfig $config = null )
    {
        $this->config = $config ?: expAiConfig::instance();
    }

    /** @return bool the installation has a [TranslationSettings] group */
    public function hasSettings()
    {
        return $this->config->ini()->hasGroup( 'TranslationSettings' );
    }

    /** @return string the default service ([TranslationSettings] Provider, else llm) */
    public function defaultId()
    {
        $id = strtolower( trim( (string) $this->config->get( 'TranslationSettings', 'Provider', self::DEFAULT_PROVIDER ) ) );
        return $id !== '' ? $id : self::DEFAULT_PROVIDER;
    }

    /** @return string the fallback ([TranslationSettings] Fallback, default llm; none: no fallback) */
    public function fallbackId()
    {
        $id = strtolower( trim( (string) $this->config->get( 'TranslationSettings', 'Fallback', self::DEFAULT_PROVIDER ) ) );
        return $id !== '' ? $id : 'none';
    }

    /** @return array the rules of [TranslationSettings] ProviderFor[]: key => id (lower case, empty values left out) */
    public function rules()
    {
        $value = $this->config->get( 'TranslationSettings', 'ProviderFor', array() );
        $out = array();
        foreach ( is_array( $value ) ? $value : array() as $key => $id )
        {
            $id = strtolower( trim( (string) $id ) );
            if ( $id !== '' && is_string( $key ) && $key !== '' )
                $out[trim( $key )] = $id;
        }
        return $out;
    }

    /**
     * The service the rules name for a pair and purpose.
     * @return array( string id, string chosen_by (the rule: ProviderFor[...], Provider or default) )
     */
    public function chosenId( $source, $target, $purpose )
    {
        $rules = $this->rules();
        foreach ( array( "$source:$target:$purpose", "$source:$target", "*:$target:$purpose", "*:$target", "$source:*:$purpose", "$source:*", "*:*:$purpose" ) as $key )
            if ( isset( $rules[$key] ) )
                return array( $rules[$key], 'ProviderFor[' . $key . ']' );
        return array( $this->defaultId(), $this->hasSettings() ? 'Provider' : 'default' );
    }

    /** @return string[] the ids of every service: llm and each [TranslationProvider_<id>] group */
    public function ids()
    {
        $ids = array( self::DEFAULT_PROVIDER );
        foreach ( array_keys( (array) $this->config->ini()->groups() ) as $group )
            if ( strpos( $group, 'TranslationProvider_' ) === 0 )
                $ids[] = substr( $group, strlen( 'TranslationProvider_' ) );
        return array_values( array_unique( $ids ) );
    }

    /**
     * A service by its id.
     * @return expAiTranslationProvider|null null when the id, the group or the class is not usable (problem() says why)
     */
    public function get( $id )
    {
        $id = (string) $id;
        if ( array_key_exists( $id, $this->instances ) )
            return $this->instances[$id] ?: null;
        $this->instances[$id] = false;
        if ( !preg_match( '/^[a-z0-9_]{1,30}$/', $id ) || $id === 'none' )
        {
            $this->problems[$id] = 'not a valid translation service id';
            return null;
        }
        $group = 'TranslationProvider_' . $id;
        $ini = $this->config->ini();
        if ( !$ini->hasGroup( $group ) && $id !== self::DEFAULT_PROVIDER )
        {
            $this->problems[$id] = 'there is no [' . $group . '] group';
            return null;
        }
        $settings = $ini->hasGroup( $group ) ? (array) $ini->group( $group ) : array();
        $class = isset( $settings['Class'] ) && (string) $settings['Class'] !== '' ? (string) $settings['Class']
               : ( $id === self::DEFAULT_PROVIDER ? 'expAiTranslationProviderLlm' : '' );
        if ( $class === '' || !class_exists( $class ) || !in_array( 'expAiTranslationProvider', class_implements( $class ), true ) )
        {
            $this->problems[$id] = 'the class ' . ( $class !== '' ? $class : '(none)' ) . ' of [' . $group . '] does not implement expAiTranslationProvider';
            eZDebug::writeWarning( 'expai: translation service ' . $id . ': ' . $this->problems[$id], __METHOD__ );
            return null;
        }
        $this->instances[$id] = new $class( $id, $settings, $this->config );
        return $this->instances[$id];
    }

    /** @return string why a service cannot be created ('' when it can) */
    public function problem( $id )
    {
        $this->get( $id );
        return isset( $this->problems[(string) $id] ) ? $this->problems[(string) $id] : '';
    }

    /**
     * Whether a service can take a translation.
     * @return array( expAiTranslationProvider|null, string problem ('' when usable), bool blocked by the data policy )
     */
    protected function usable( $id, $source, $target, $purpose, $object )
    {
        $provider = $this->get( $id );
        if ( !$provider )
            return array( null, $this->problem( $id ), false );
        if ( !$provider->isConfigured() )
        {
            eZDebug::writeWarning( 'expai: translation service ' . $id . ' is not configured: ' . $provider->unavailableReason(), __METHOD__ );
            return array( null, $provider->unavailableReason(), false );
        }
        if ( !$provider->supports( $source, $target, $purpose ) )
            return array( null, 'does not translate ' . $source . ' into ' . $target, false );
        $policy = $object instanceof eZContentObject ? $provider->dataPolicy() : null;
        if ( $policy && !$policy->allowsObject( $object ) )
            return array( null, 'the data policy keeps the object away from it: ' . $policy->describe( $policy->requirement( $object ) ), true );
        return array( $provider, '', false );
    }

    /**
     * The service for a translation: the rule's choice, else the fallback for a configuration problem.
     * @param string $source eZ locale
     * @param string $target eZ locale
     * @param string $purpose patch | full | field
     * @param eZContentObject|null $object the content (the data policy of the service)
     * @param string $forced a service id that must be used (--provider of the command line): no rule, no fallback
     * @return array provider (expAiTranslationProvider|null), state (ok | no_provider | blocked), reason (English,
     *               '' when the chosen service takes it), chosen_by, fallback (bool: the fallback took it)
     */
    public function forPair( $source, $target, $purpose, $object = null, $forced = '' )
    {
        $forced = strtolower( trim( (string) $forced ) );
        list( $id, $chosenBy ) = $forced !== '' ? array( $forced, 'option' ) : $this->chosenId( $source, $target, $purpose );
        $pair = $source . ' -> ' . $target;
        if ( $id === 'none' )
            return array( 'provider' => null, 'state' => 'no_provider', 'fallback' => false, 'chosen_by' => $chosenBy,
                          'reason' => 'No translation service is installed for ' . $pair . ' ([TranslationSettings] ' . $chosenBy . '=none)' );
        list( $provider, $problem, $blocked ) = $this->usable( $id, $source, $target, $purpose, $object );
        if ( $provider )
            return array( 'provider' => $provider, 'state' => 'ok', 'reason' => '', 'chosen_by' => $chosenBy, 'fallback' => false );
        $reasons = array( $id . ': ' . $problem );
        $fallback = $forced === '' ? $this->fallbackId() : 'none';
        if ( $fallback !== 'none' && $fallback !== $id )
        {
            list( $other, $otherProblem, $otherBlocked ) = $this->usable( $fallback, $source, $target, $purpose, $object );
            if ( $other )
                return array( 'provider' => $other, 'state' => 'ok', 'chosen_by' => 'Fallback', 'fallback' => true,
                              'reason' => 'the fallback ' . $fallback . ' translates (' . $reasons[0] . ')' );
            $reasons[] = $fallback . ': ' . $otherProblem;
            $blocked = $blocked && $otherBlocked;
        }
        return array( 'provider' => null, 'state' => $blocked ? 'blocked' : 'no_provider', 'fallback' => false, 'chosen_by' => $chosenBy,
                      'reason' => ( $blocked ? 'The data policy keeps this content away from the translation service for ' : 'No translation service is installed for ' )
                                  . $pair . ' (' . implode( '; ', $reasons ) . ')' );
    }

    /**
     * Whether any service can be used for a purpose at all (the edit form leaves Translate out when not).
     * @return bool
     */
    public function availableFor( $purpose )
    {
        $ids = array( $this->defaultId() );
        foreach ( $this->rules() as $key => $id )
        {
            $parts = explode( ':', $key );
            if ( count( $parts ) < 3 || $parts[2] === $purpose )
                $ids[] = $id;
        }
        // none never falls back: only a rule that names a service can reach the fallback
        $ids = array_diff( array_unique( $ids ), array( 'none' ) );
        if ( $ids )
            $ids[] = $this->fallbackId();
        foreach ( array_unique( $ids ) as $id )
        {
            if ( $id === 'none' )
                continue;
            $provider = $this->get( $id );
            if ( $provider && $provider->isConfigured() )
                return true;
        }
        return false;
    }

    /** @return string[] the terms that are never translated ([TranslationSettings] DoNotTranslate[]) */
    public function terms()
    {
        return array_values( array_unique( array_map( 'trim', $this->config->arr( 'TranslationSettings', 'DoNotTranslate' ) ) ) );
    }

    /**
     * The glossary of a pair: [TranslationSettings] Glossary[<source>:<target>]=<file>, a UTF-8 file with one term,
     * a tab and its translation per line (lines starting with # are comments). The path is relative to the
     * installation, or absolute.
     * @return array term => translation
     */
    public function glossary( $source, $target )
    {
        $files = $this->config->get( 'TranslationSettings', 'Glossary', array() );
        $key = $source . ':' . $target;
        $path = is_array( $files ) && isset( $files[$key] ) ? trim( (string) $files[$key] ) : '';
        if ( $path === '' )
            return array();
        $file = $path[0] === '/' ? $path : rtrim( (string) eZSys::rootDir(), '/' ) . '/' . $path;
        if ( !isset( self::$glossaries[$file] ) )
        {
            $terms = array();
            if ( is_file( $file ) && is_readable( $file ) )
            {
                foreach ( preg_split( '/\r\n|\r|\n/', (string) file_get_contents( $file ) ) as $line )
                {
                    if ( trim( $line ) === '' || $line[0] === '#' || strpos( $line, "\t" ) === false )
                        continue;
                    list( $term, $translation ) = array_map( 'trim', explode( "\t", $line, 2 ) );
                    if ( $term !== '' && $translation !== '' )
                        $terms[$term] = $translation;
                    if ( count( $terms ) >= 500 )
                        break;
                }
            }
            else
                eZDebug::writeWarning( 'expai: the glossary ' . $path . ' of ' . $key . ' cannot be read', __METHOD__ );
            self::$glossaries[$file] = $terms;
        }
        return self::$glossaries[$file];
    }

    /**
     * Every service for the summary and the extension point survey.
     * @return array list of { id, label, kind, class, configured, reason, region, retention, terms_checked_at, group }
     */
    public function describe()
    {
        $out = array();
        foreach ( $this->ids() as $id )
        {
            $provider = $this->get( $id );
            $group = 'TranslationProvider_' . $id;
            $out[] = array( 'id' => $id, 'group' => $this->config->ini()->hasGroup( $group ) ? $group : '',
                            'label' => $provider ? $provider->label() : $id, 'kind' => $provider ? $provider->kind() : '',
                            'class' => $provider ? get_class( $provider ) : (string) $this->config->get( $group, 'Class', '' ),
                            'configured' => $provider ? $provider->isConfigured() : false,
                            'reason' => $provider ? $provider->unavailableReason() : $this->problem( $id ),
                            'region' => $provider ? $provider->region() : '', 'retention' => $provider ? $provider->retention() : '',
                            'terms_checked_at' => $provider ? $provider->termsCheckedAt() : '' );
        }
        return $out;
    }
}
