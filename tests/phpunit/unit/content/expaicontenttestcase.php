<?php
/**
 * Shared helpers of the content tests (translation sync, alt text, presets, chains, diff, history): a stub provider
 * that exists only in this process's memory, settings of the site's expai.ini changed in memory and restored after
 * the test, articles with a translation below the test's folder, and ezjscore requests.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A provider that never leaves the process: it answers from a list the test sets (expAiContentStubProvider::$answers,
 * each an answer array, a text, or a Throwable to throw) and keeps every request it got.
 */
class expAiContentStubProvider implements expAiProvider
{
    /** @var array */
    public static $answers = array();

    /** @var array[] */
    public static $requests = array();

    /** @var array */
    protected $settings;

    public function __construct( array $settings = array() )
    {
        $this->settings = $settings;
    }

    public static function reset()
    {
        self::$answers = array();
        self::$requests = array();
    }

    /** @return array an answer of the Anthropic shape with one text block */
    public static function text( $text, $stop = 'end_turn' )
    {
        $content = array( array( 'type' => 'text', 'text' => (string) $text ) );
        return array( 'content' => $content, 'content_json' => json_encode( $content ), 'stop_reason' => $stop, 'model' => 'stub-model',
                      'usage' => array( 'input_tokens' => 120, 'output_tokens' => 30 ),
                      'tokens' => array( 'input' => 120, 'output' => 30, 'cache_write' => 0, 'cache_read' => 0 ), 'http_status' => 200 );
    }

    public function send( array $request )
    {
        self::$requests[] = $request;
        $next = self::$answers ? array_shift( self::$answers ) : self::text( '{}' );
        if ( $next instanceof Throwable )
            throw $next;
        return is_string( $next ) ? self::text( $next ) : $next;
    }

    public function modelName()
    {
        return isset( $this->settings['Model'] ) ? (string) $this->settings['Model'] : 'stub-model';
    }

    public function providerId()
    {
        return isset( $this->settings['ProviderId'] ) ? (string) $this->settings['ProviderId'] : 'xtc';
    }

    public function modelAlias()
    {
        return isset( $this->settings['ModelAlias'] ) ? (string) $this->settings['ModelAlias'] : 'xtc.m';
    }

    public function toolUse()
    {
        return 'native';
    }
}

abstract class expAiContentTestCase extends expAiTestCase
{
    const PROVIDER = 'xtc';
    const ALIAS = 'xtc.m';
    const SOURCE = 'eng-US';
    const TARGET = 'ger-DE';

    /** @var array the $_POST, $_GET, $_SERVER of the process before the test */
    private $globals = array();

    /**
     * The label a prompt gives a language: the international name of the kernel's locale and the code, for example
     * "German (Germany) (ger-DE)". The name comes from the kernel's locale files (CLDR), which can change with the
     * kernel, so a test never spells it out.
     */
    protected static function languageLabel( $code )
    {
        $name = (string) eZLocale::instance( $code )->attribute( 'intl_language_name' );
        self::assertNotSame( '', $name, "the kernel has a locale $code" );
        return $name . ' (' . $code . ')';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->globals = array( $_POST, $_GET, $_SERVER );
        expAiContentStubProvider::reset();
    }

    protected function tearDown(): void
    {
        list( $_POST, $_GET, $_SERVER ) = $this->globals;
        expAiContentStubProvider::reset();
        parent::tearDown();
    }

    /**
     * The settings of the stub provider (one model with vision and JSON answers, no data policy rules).
     * @return array group => values
     */
    protected static function stubSettings( array $more = array() )
    {
        $settings = array(
            'AISettings' => array( 'Enabled' => 'true', 'Model' => '', 'AllowUserModelChoice' => 'disabled', 'AllowedModels' => array(), 'DisabledSiteAccesses' => array() ),
            'ProviderSettings' => array( 'Providers' => array( self::PROVIDER ), 'DefaultModel' => self::ALIAS, 'ProvidersWithoutPolicy' => array( self::PROVIDER ) ),
            'Provider_' . self::PROVIDER => array( 'Type' => 'custom', 'Class' => 'expAiContentStubProvider', 'Label' => 'Stub (tests)', 'Region' => 'eu',
                                                   'Retention' => 'zdr', 'ApiKey' => 'stub-key' ),
            'Model_' . self::ALIAS => array( 'Provider' => self::PROVIDER, 'Id' => 'stub-model', 'Label' => 'Stub model', 'ToolUse' => 'native',
                                             'Capabilities' => array( 'json_schema', 'vision' ), 'InputPerMTok' => '1', 'OutputPerMTok' => '5' ),
            'DataPolicy' => array( 'DefaultRegions' => array(), 'SectionRegions' => array(), 'ClassRegions' => array(), 'ZeroRetention' => 'disabled',
                                   'ZeroRetentionSections' => array(), 'ZeroRetentionClasses' => array() ),
            'Limits' => array( 'MaxCostPerSitePerMonth' => '', 'MaxCostPerUserPerDay' => '', 'MaxRequestsPerUserPerHour' => '' ),
            'Budgets' => array( 'SiteMonthly' => '' ),
            'RequestVeto' => array( 'Rules' => array() ),
        );
        foreach ( $more as $group => $values )
            $settings[$group] = isset( $settings[$group] ) ? array_merge( $settings[$group], $values ) : $values;
        return $settings;
    }

    /**
     * An expAiConfig on the shipped settings with the stub provider.
     * @return expAiConfig
     */
    protected function stubConfig( array $more = array() )
    {
        $this->forgetStubRows();
        return $this->config( self::stubSettings( $more ) );
    }

    /**
     * Sets values of the site's expai.ini (the instance every expAiConfig::instance() reads) in memory, restored after
     * the test. With $stub the stub provider is set too.
     * @return expAiConfig on the changed settings
     */
    protected function siteSettings( array $settings = array(), $stub = true )
    {
        $ini = eZINI::instance( 'expai.ini' );
        if ( $stub )
        {
            $this->forgetStubRows();
            $all = self::stubSettings( $settings );
        }
        else
            $all = $settings;
        foreach ( $all as $group => $values )
        {
            $hadGroup = $ini->hasGroup( $group );
            foreach ( $values as $name => $value )
            {
                $had = $ini->hasVariable( $group, $name );
                $old = $had ? $ini->variable( $group, $name ) : null;
                $this->cleanup( static function () use ( $ini, $group, $name, $had, $old, $hadGroup )
                {
                    if ( $had )
                        $ini->setVariable( $group, $name, $old );
                    elseif ( !$hadGroup )
                        $ini->removeGroup( $group );
                    else
                        $ini->removeSetting( $group, $name );
                } );
                $ini->setVariable( $group, $name, $value );
            }
        }
        if ( class_exists( 'expAiProviderHealth' ) && method_exists( 'expAiProviderHealth', 'reset' ) )
            expAiProviderHealth::reset();
        return new expAiConfig();
    }

    /** Removes, after the test, every row of the stub provider (usage, queue jobs and resources, batches). */
    private function forgetStubRows()
    {
        static $registered = array();
        $key = spl_object_id( $this );
        if ( isset( $registered[$key] ) && $registered[$key] === $this->name() )
            return;
        $registered[$key] = $this->name();
        $this->requireDatabase();
        foreach ( array( 'standard', 'fast', 'flex', 'batch' ) as $lane )
            expQueue::pause( 'resource', self::PROVIDER . ':' . $lane );
        $this->cleanup( static function ()
        {
            expAiScriptFixture::removeMockRows( array( self::PROVIDER ) );
        } );
    }

    /** @return string an ezxmltext document of paragraphs etc. */
    protected static function xml( $inner )
    {
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<section xmlns:image="http://ez.no/namespaces/ezpublish3/image/" '
             . 'xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/">' . $inner . '</section>' . "\n";
    }

    /**
     * An article in the source language below a test folder.
     * @return eZContentObject
     */
    protected function article( $parentNodeID, $title, $intro, $body )
    {
        $admin = eZUser::fetchByName( 'admin' );
        $object = eZContentFunctions::createAndPublishObject( array( 'parent_node_id' => (int) $parentNodeID, 'class_identifier' => 'article',
            'creator_id' => (int) $admin->attribute( 'contentobject_id' ), 'language' => self::SOURCE,
            'attributes' => array( 'title' => $title, 'intro' => self::xml( $intro ), 'body' => self::xml( $body ) ) ), false );
        $this->assertInstanceOf( 'eZContentObject', $object );
        $objectID = (int) $object->attribute( 'id' );
        $this->cleanup( static function () use ( $objectID )
        {
            eZPersistentObject::removeObject( expAiDraftLog::definition(), array( 'contentobject_id' => $objectID ) );
            expAiTranslationSync::forget( $objectID, array( self::SOURCE, self::TARGET ) );
        } );
        return $object;
    }

    /**
     * An image object (class image) with a small PNG and no alt text below a test folder.
     * @return eZContentObject
     */
    protected function image( $parentNodeID, $name, $width = 64, $height = 48 )
    {
        $dir = expAiTestMockServer::tmp( 'image' );
        $this->cleanup( static function () use ( $dir ) { expAiTestMockServer::removeTree( $dir ); } );
        $file = $dir . '/xt.png';
        $gd = imagecreatetruecolor( (int) $width, (int) $height );
        imagefill( $gd, 0, 0, imagecolorallocate( $gd, 200, 30, 30 ) );
        imagepng( $gd, $file );
        $admin = eZUser::fetchByName( 'admin' );
        $object = eZContentFunctions::createAndPublishObject( array( 'parent_node_id' => (int) $parentNodeID, 'class_identifier' => 'image',
            'creator_id' => (int) $admin->attribute( 'contentobject_id' ), 'language' => self::SOURCE,
            'attributes' => array( 'name' => $name, 'image' => $file . '|' ) ), false );
        $this->assertInstanceOf( 'eZContentObject', $object );
        $objectID = (int) $object->attribute( 'id' );
        $this->cleanup( static function () use ( $objectID )
        {
            eZPersistentObject::removeObject( expAiDraftLog::definition(), array( 'contentobject_id' => $objectID ) );
        } );
        return eZContentObject::fetch( $objectID );
    }

    /** @return eZContentObjectAttribute the ezimage attribute of a version */
    protected static function imageAttribute( eZContentObject $object, $version = 0, $language = self::SOURCE )
    {
        $map = $object->fetchDataMap( $version ?: (int) $object->attribute( 'current_version' ), $language );
        return $map['image'];
    }

    /**
     * Publishes a new version of the object in a language (a new translation copies the initial language first).
     * @param array $values identifier => value (ezxmltext: the document, else fromString)
     * @return int the version number
     */
    protected static function publishVersion( eZContentObject $object, $language, array $values )
    {
        $copyFrom = in_array( $language, array_keys( $object->allLanguages() ), true ) ? false : (string) $object->attribute( 'initial_language_code' );
        $version = $object->createNewVersionIn( $language, $copyFrom, false, true, eZContentObjectVersion::STATUS_DRAFT );
        $version->setAttribute( 'creator_id', eZUser::currentUserID() );
        $version->store();
        foreach ( $object->fetchDataMap( (int) $version->attribute( 'version' ), $language ) as $identifier => $attribute )
            if ( isset( $values[$identifier] ) )
            {
                if ( $attribute->attribute( 'data_type_string' ) === 'ezxmltext' )
                    $attribute->setAttribute( 'data_text', $values[$identifier] );
                else
                    $attribute->fromString( $values[$identifier] );
                $attribute->store();
            }
        $object->setName( $object->contentClass()->contentObjectName( $object, (int) $version->attribute( 'version' ), $language ), (int) $version->attribute( 'version' ), $language );
        eZOperationHandler::execute( 'content', 'publish', array( 'object_id' => (int) $object->attribute( 'id' ), 'version' => (int) $version->attribute( 'version' ) ) );
        eZContentObject::clearCache( array( (int) $object->attribute( 'id' ) ) );
        return (int) $version->attribute( 'version' );
    }

    /** Makes the request of an ezjscore call: POST with these values and the form token. */
    protected static function post( array $values, $method = 'POST' )
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST = $values;
        if ( class_exists( 'ezxFormToken' ) && ezxFormToken::isEnabled() && !isset( $values['ezxform_token'] ) )
            $_POST['ezxform_token'] = ezxFormToken::getToken();
    }

    /**
     * Asserts that the callable throws an expAiException whose message (with its arguments) contains $needle.
     * @return expAiException
     */
    protected function assertRefused( callable $fn, $needle )
    {
        try
        {
            $fn();
        }
        catch ( expAiException $e )
        {
            $text = strtr( $e->getMessage(), $e->arguments() ) . ' ' . $e->detail();
            $this->assertStringContainsString( $needle, $text );
            return $e;
        }
        $this->fail( "no refusal containing '$needle'" );
    }
}
