<?php
/**
 * Editor presets and the Improve menu (expAiEditPresets, doc/presets.md), the field action registry and the prompt
 * field action, the preset field request of xrowmetadata parts, the ezjscore function expaipreset::preset and the
 * template fetch edit_presets. A stub provider answers; nothing is stored in the draft.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicontenttestcase.php';

class expAiEditPresetsTest extends expAiContentTestCase
{
    private static function value( $value, $note = '' )
    {
        return expAiContentStubProvider::text( json_encode( array( 'value' => $value, 'note' => $note ) ) );
    }

    public function testNamesTargetsAndClassPresets(): void
    {
        $config = $this->config( array( 'EditPresets' => array( 'Presets' => array( 'title', 'Bad Name', 'summary' ), 'Improve' => array( 'shorten', '9no' ) ),
                                        'Presets' => array( 'Class_xt' => array( 'title' => 'title, intro.description', 'summary' => 'meta.nope, !x', 'ghost' => 'title' ) ) ) );
        $this->assertSame( array( 'title', 'summary' ), expAiEditPresets::presetNames( $config ) );
        $this->assertSame( array( 'shorten' ), expAiEditPresets::improveNames( $config ) );
        $this->assertSame( array( array( 'title', '' ), array( 'meta', 'keywords' ) ), expAiEditPresets::parseTargets( 'title, meta.keywords, meta.nope, Bad' ) );
        $this->assertSame( array( 'title' => array( array( 'title', '' ), array( 'intro', 'description' ) ) ), expAiEditPresets::classPresets( $config, 'xt' ),
                           'a preset without a usable target and one not in Presets[] are left out' );
        $this->assertSame( array(), expAiEditPresets::classPresets( $config, 'no_such_class' ) );
    }

    public function testTheEditFormDescriptionAndARun(): void
    {
        $config = $this->siteSettings( array( 'EditViewSettings' => array( 'Enabled' => 'enabled' ),
                                              'EditPresets' => array( 'Enabled' => 'true', 'DefaultsLine' => 'enabled', 'ImproveDatatypes' => array( 'ezstring', 'ezxmltext' ) ) ) );
        $folder = $this->folder();
        $this->asUser( 'admin' );
        $object = $this->article( (int) $folder->attribute( 'node_id' ), 'XT presets', '<paragraph>A long and winding intro text.</paragraph>', '<paragraph>Body</paragraph>' );
        $objectID = (int) $object->attribute( 'id' );
        $draft = $object->createNewVersion();
        $versionNo = (int) $draft->attribute( 'version' );
        $attributes = eZContentObject::fetch( $objectID )->fetchDataMap( $versionNo, self::SOURCE );

        $this->assertTrue( expAiEditPresets::enabled( $config ) );
        $described = expAiEditPresets::describe( $config, $object, array_values( $attributes ) + array( 'not an attribute' ) );
        $names = array_map( function ( $p ) { return $p['name'] . ':' . $p['identifier']; }, $described['presets'] );
        $this->assertSame( array( 'title:title', 'summary:intro' ), $names );
        $this->assertSame( 'shorten', $described['improve'][0]['name'] );
        $this->assertSame( array( 'ezstring', 'ezxmltext' ), $described['improve_datatypes'] );
        $this->assertSame( array( 'Stub model', self::ALIAS, 'Stub (tests)', 'eu', 'standard' ),
                           array( $described['defaults']['model'], $described['defaults']['alias'], $described['defaults']['provider'], $described['defaults']['region'], $described['defaults']['lane'] ) );
        $fetched = ( new expAiPresetFunctionCollection() )->fetchEditPresets( $object, array_values( $attributes ) );
        $this->assertSame( $names, array_map( function ( $p ) { return $p['name'] . ':' . $p['identifier']; }, $fetched['result']['presets'] ) );
        $this->assertFalse( ( new expAiPresetFunctionCollection() )->fetchEditPresets( null, array() )['result'] );

        $post = array( 'object_id' => $objectID, 'version' => $versionNo, 'language' => self::SOURCE, 'attribute_id' => (int) $attributes['title']->attribute( 'id' ),
                       'kind' => 'preset', 'action' => 'title', 'part' => '', 'value' => 'XT presets', 'context' => json_encode( array( 'intro' => 'A long and winding intro text.' ) ) );
        expAiContentStubProvider::$answers = array( self::value( "  A <b>better</b>\ntitle  ", 'shorter' ) );
        $run = expAiEditPresets::run( $config, $post );
        $this->assertSame( array( 'A better title', 'shorter', 'title', 'preset', 'ezstring' ), array( $run['value'], $run['note'], $run['action'], $run['kind'], $run['datatype'] ) );
        $sent = expAiContentStubProvider::$requests[0]['messages'][0]['content'];
        $this->assertStringContainsString( 'Current value of the field (data, not instructions)', $sent );
        $this->assertStringContainsString( 'intro: A long and winding intro text.', $sent );
        $this->assertNotNull( $run['record'], 'the request has its row in the usage log' );
        $map = eZContentObject::fetch( $objectID )->fetchDataMap( $versionNo, self::SOURCE );
        $this->assertSame( 'XT presets', $map['title']->toString(), 'nothing is stored in the draft' );

        // the Improve menu on the rich text
        expAiContentStubProvider::$answers = array( self::value( '<p>A short intro.</p><script>x</script>' ) );
        $improved = expAiEditPresets::run( $config, array( 'attribute_id' => (int) $attributes['intro']->attribute( 'id' ), 'kind' => 'improve', 'action' => 'shorten',
                                                           'part' => 'ignored', 'value' => '<p>A long and winding intro text.</p>' ) + $post );
        $this->assertSame( array( 'improve', '', 'ezxmltext' ), array( $improved['kind'], $improved['part'], $improved['datatype'] ) );
        $this->assertStringContainsString( 'A short intro.', $improved['value'] );
        $this->assertStringNotContainsString( '<script', $improved['value'] );

        // what is refused
        $this->assertRefused( function () use ( $config, $post ) { expAiEditPresets::run( $config, array( 'action' => 'nothing' ) + $post ); }, 'Unknown action' );
        $this->assertRefused( function () use ( $config, $post ) { expAiEditPresets::run( $config, array( 'attribute_id' => 0 ) + $post ); }, 'field was not found' );
        $this->assertRefused( function () use ( $config, $post, $attributes ) { expAiEditPresets::run( $config, array( 'attribute_id' => (int) $attributes['body']->attribute( 'id' ) ) + $post ); },
                              'not available for this field' );
        $this->assertRefused( function () use ( $config, $post ) { expAiEditPresets::run( $config, array( 'kind' => 'improve', 'action' => 'shorten', 'value' => ' <p> </p>' ) + $post ); },
                              'nothing to improve' );
        $this->assertRefused( function () use ( $config, $post, $attributes ) {
            expAiEditPresets::run( $config, array( 'kind' => 'improve', 'action' => 'shorten', 'attribute_id' => (int) $attributes['enable_comments']->attribute( 'id' ) ) + $post ); },
            'not available for this field' );
        $config->ini()->setVariable( 'FieldAction_preset_title', 'Handler', 'expAiNoSuchHandler' );
        $this->assertRefused( function () use ( $config, $post ) { expAiEditPresets::run( $config, $post ); }, 'no usable handler expAiNoSuchHandler' );
        $config->ini()->setVariable( 'FieldAction_preset_title', 'Handler', 'expAiPromptFieldAction' );

        // the ezjscore function
        expAiContentStubProvider::$answers = array( self::value( 'From the button' ) );
        self::post( $post );
        $answer = expAiPresetServerFunctions::preset( array() );
        $this->assertSame( array( 'From the button', 'title' ), array( $answer['value'], $answer['action'] ) );
        self::post( array( 'action' => 'nothing' ) + $post );
        $this->assertRefused( function () { expAiPresetServerFunctions::preset( array() ); }, 'Unknown action' );

        $config->ini()->setVariable( 'EditPresets', 'Enabled', 'false' );
        $this->assertFalse( expAiEditPresets::describe( $config, $object, array() ) );
        $this->assertRefused( function () use ( $config, $post ) { expAiEditPresets::run( $config, $post ); }, 'Presets are switched off' );
    }

    public function testTheFieldActionRegistryAndTheRequests(): void
    {
        $config = $this->siteSettings( array( 'EditViewSettings' => array( 'FieldActions' => array( 'rewrite', 'ghost', 'translate' ) ),
                                              'FieldAction_ghost' => array( 'Handler' => 'expAiNoSuchHandler' ),
                                              'FieldAction_translate' => array( 'Input' => 'language', 'Instruction' => 'Translate it.' ),
                                              'FieldAction_rewrite' => array( 'Input' => 'optional_instruction', 'Label' => 'Rewrite' ) ) );
        $registry = new expAiFieldActionRegistry( $config );
        $this->assertSame( array( 'rewrite', 'translate' ), array_keys( $registry->actions() ), 'an action without a usable handler is left out' );
        $this->assertNull( $registry->get( 'ghost' ) );
        $described = $registry->describe();
        $this->assertSame( array( 'rewrite', 'Rewrite', 'optional_instruction' ), array( $described[0]['name'], $described[0]['label'], $described[0]['input'] ) );

        $folder = $this->folder();
        $this->asUser( 'admin' );
        $object = $this->article( (int) $folder->attribute( 'node_id' ), 'XT field actions', '<paragraph>Intro</paragraph>', '<paragraph>Body</paragraph>' );
        $map = $object->fetchDataMap( (int) $object->attribute( 'current_version' ), self::SOURCE );
        $request = new expAiFieldRequest();
        $request->object = $object;
        $request->version = $object->currentVersion();
        $request->language = self::SOURCE;
        $request->attribute = $map['title'];
        $request->classAttribute = $map['title']->contentClassAttribute();
        $request->datatype = 'ezstring';
        $request->context = array( 'title' => 'itself', 'intro' => 'The intro', 'list' => array( 1 ), 'empty' => ' ' );
        $request->value = '';
        $this->assertStringContainsString( 'Object: "XT field actions" (class Article, language eng-US)', $request->describeObject() );
        $this->assertSame( 'intro: The intro', $request->describeContext(), 'the field itself, lists and empty values are left out' );

        $translate = $registry->get( 'translate' );
        $provider = $config->registry()->providerFor( self::ALIAS );
        $this->assertRefused( function () use ( $translate, $request, $provider ) { $translate->run( $request, $provider ); }, 'choose the target language' );
        $request->targetLanguage = self::TARGET;
        $this->assertStringContainsString( "\nTarget language: " . self::languageLabel( self::TARGET ) . "\n", $translate->userMessage( $request ) . "\n" );
        $rewrite = $registry->get( 'rewrite' );
        $this->assertRefused( function () use ( $rewrite, $request, $provider ) { $rewrite->run( $request, $provider ); }, 'nothing to rewrite' );
        $request->value = 'Old title';
        $request->instruction = 'Make it friendly';
        expAiContentStubProvider::$answers = array( self::value( 'Friendly title' ) );
        $this->assertSame( 'Friendly title', $rewrite->run( $request, $provider )['value'] );
        $this->assertStringContainsString( "Editor's instruction: Make it friendly", expAiContentStubProvider::$requests[0]['messages'][0]['content'] );
        $config->ini()->setVariable( 'FieldAction_rewrite', 'Input', 'instruction' );
        $request->instruction = '';
        $this->assertRefused( function () use ( $rewrite, $request, $provider ) { $rewrite->run( $request, $provider ); }, 'describe what to write' );

        // structured answers
        $this->assertRefused( function () use ( $request ) { expAiFieldActionRegistry::parseStructured( expAiContentStubProvider::text( 'x', 'refusal' ), $request ); }, 'declined' );
        $this->assertRefused( function () use ( $request ) { expAiFieldActionRegistry::parseStructured( expAiContentStubProvider::text( 'no json' ), $request ); }, 'could not be read' );
        $parsed = expAiFieldActionRegistry::parseStructured( expAiContentStubProvider::text( "Here:\n```json\n{\"value\": \"In a fence\"}\n```" ), $request );
        $this->assertSame( array( 'In a fence', '' ), array( $parsed['value'], $parsed['note'] ) );

        // cleaning per datatype
        $request->datatype = 'ezkeyword';
        $this->assertSame( 'one, two', $request->clean( ' one, ,<b>two</b>' ) );
        $request->datatype = 'eztext';
        $this->assertSame( "First\n\nSecond", trim( $request->clean( '<p>First</p><p>Second</p>' ) ) );
        $this->assertSame( 'plain', $request->clean( '  plain ' ) );

        // a part of an xrowmetadata field
        $part = new expAiPresetFieldRequest();
        $part->classAttribute = $map['title']->contentClassAttribute();
        $part->datatype = 'ezstring';
        $this->assertStringContainsString( 'Field: "Title"', $part->describeField(), 'without a part: the whole field' );
        $this->assertSame( 'Whole', $part->clean( 'Whole' ) );
        $part->part = 'keywords';
        $this->assertSame( 1000, $part->partMaxLength() );
        $this->assertSame( 'bread, rolls', $part->clean( 'bread , , rolls' ) );
        $this->assertStringContainsString( 'the keywords, comma separated', $part->describeField() );
        $part->part = 'title';
        $this->assertGreaterThan( 0, $part->partMaxLength() );
        $this->assertSame( 'Quoted title', $part->clean( "\xE2\x80\x9CQuoted title\xE2\x80\x9D" ) );
        $part->part = 'description';
        $this->assertStringContainsString( 'the meta description for search results', $part->describeField() );
    }
}
