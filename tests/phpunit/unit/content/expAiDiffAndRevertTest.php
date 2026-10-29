<?php
/**
 * expAiDiffResult, expAiDiff::apply()/writeDraft() edge cases, expAiDraftRevert (a new translation, a revert of a
 * whole field, refusals) and the ezjscore functions expairevert::changes and ::revert.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaicontenttestcase.php';

class expAiDiffAndRevertTest extends expAiContentTestCase
{
    public function testTheResultOfAComparisonOfValues(): void
    {
        $config = $this->config();
        $diff = expAiDiff::compare(
            array( 'values' => array( 'title' => 'Opening hours', 'note' => array( 'datatype' => 'eztext', 'value' => "One line.\n\nTwo lines." ) ), 'language' => 'eng-US' ),
            array( 'values' => array( 'title' => 'Opening hours at Main Street', 'note' => array( 'datatype' => 'eztext', 'value' => "One line.\n\nTwo lines." ) ), 'language' => 'eng-US' ),
            array( 'config' => $config ) );
        $this->assertSame( 'eng-US', $diff->get( 'language' ) );
        $this->assertNull( $diff->get( 'nothing' ) );
        $this->assertNull( $diff->attribute( 'missing' ) );
        $this->assertSame( 'ezstring', $diff->attribute( 'title' )['datatype'] );
        $this->assertFalse( $diff->isEmpty() );
        $ids = $diff->hunkIds();
        $this->assertCount( 1, $ids, 'only the title changed' );
        $this->assertSame( $ids[0], $diff->hunk( $ids[0] )['id'] );
        $this->assertNull( $diff->hunk( 'no-such-hunk' ) );
        $metrics = $diff->metrics();
        $this->assertSame( array( 1, 1 ), array( $metrics['hunks'], $metrics['attributes_changed'] ) );
        $this->assertGreaterThan( 0, $metrics['change_ratio'] );
        $this->assertFalse( $metrics['structural_only'] );

        $short = $diff->toArray();
        $this->assertArrayNotHasKey( 'base_value', $short['attributes']['title'] );
        $this->assertArrayNotHasKey( 'op_indexes', $short['attributes']['title']['hunks'][0] );
        $full = $diff->toArray( true );
        $this->assertSame( 'Opening hours at Main Street', $full['attributes']['title']['prop_value'] );
        $this->assertSame( 'note', $full['attributes']['note']['name'] );

        // apply: the accepted hunk only, unknown ids reported
        $applied = expAiDiff::apply( $diff, array( $ids[0], 'h-unknown' ) );
        $this->assertSame( array( 'title' => 'Opening hours at Main Street' ), $applied['values'] );
        $this->assertSame( array( 'h-unknown' ), $applied['unknown'] );
        $this->assertSame( array(), expAiDiff::apply( $diff, array() )['values'] );
        $this->assertRefused( function () { expAiDiff::apply( new stdClass(), array() ); }, 'needs a diff' );
        $this->assertRefused( function () use ( $diff ) { expAiDiff::apply( $diff, array( 'x' ), array( 'write' => true ) ); }, 'no object to write the draft of' );
        $this->assertRefused( function () { expAiDiff::compare( 'nothing', array( 'values' => array() ) ); }, 'neither an object nor values' );

        $same = expAiDiff::compare( array( 'values' => array( 'a' => 'x' ) ), array( 'values' => array( 'a' => 'x' ) ), array( 'config' => $config ) );
        $this->assertTrue( $same->isEmpty() );
        $this->assertSame( 0.0, (float) $same->metrics()['block_ratio'] );
    }

    public function testWriteDraftReportsWhatItCouldNotWrite(): void
    {
        $this->siteSettings();
        $folder = $this->folder();
        $this->asUser( 'admin' );
        $object = $this->article( (int) $folder->attribute( 'node_id' ), 'XT write', '<paragraph>Intro</paragraph>', '<paragraph>Body</paragraph>' );
        $url = eZURL::registerURL( 'https://example.org/xt-write' );
        $result = expAiDiff::writeDraft( $object, self::SOURCE, array(
            'nothing' => 'x',
            'intro' => '<section><paragraph>broken',
            'body' => self::xml( '<paragraph>See <link url_id="' . (int) $url . '">our page</link>.</paragraph>' ),
            'title' => 'XT written' ), array( 'intro' => 'ezxmltext', 'body' => 'ezxmltext' ), array( 'source' => 'test', 'prompt_summary' => 'XT' ) );
        $this->assertSame( 'no such attribute', $result['errors']['nothing'] );
        $this->assertSame( 'the rich text is not well-formed', $result['errors']['intro'] );
        $this->assertContains( 'body', $result['written'] );
        $this->assertContains( 'title', $result['written'] );
        $this->assertTrue( $result['created'] );
        $log = expAiDraftRevert::writtenBy( (int) $object->attribute( 'id' ), $result['version'] );
        $this->assertSame( array( 'test', 'XT' ), array( (string) $log->attribute( 'source' ), (string) $log->attribute( 'prompt_summary' ) ) );
        $links = eZURLObjectLink::hasObjectLinkList( (int) $url );
        $this->assertTrue( (bool) $links, 'the link of the rich text is registered for the draft' );
        // the rich text only: the name is updated, no plain field written
        $again = expAiDiff::writeDraft( eZContentObject::fetch( (int) $object->attribute( 'id' ) ), self::SOURCE,
            array( 'body' => self::xml( '<paragraph>Only the body.</paragraph>' ) ), array( 'body' => 'ezxmltext' ), array( 'reuse_version' => $result['version'] ) );
        $this->assertSame( array( $result['version'], false, array( 'body' ) ), array( $again['version'], $again['created'], $again['written'] ) );
    }

    public function testRevertOfAWholeFieldAndTheRefusals(): void
    {
        $this->siteSettings();
        $folder = $this->folder();
        $admin = $this->asUser( 'admin' );
        $object = $this->article( (int) $folder->attribute( 'node_id' ), 'XT revert', '<paragraph>First.</paragraph><paragraph>Second.</paragraph>', '<paragraph>Body</paragraph>' );
        $objectID = (int) $object->attribute( 'id' );
        $write = expAiDiff::writeDraft( $object, self::SOURCE, array( 'title' => 'XT revert changed', 'intro' => self::xml( '<paragraph>First, changed.</paragraph><paragraph>Second.</paragraph>' ) ),
                                        array( 'title' => 'ezstring', 'intro' => 'ezxmltext' ), array( 'source' => 'panel' ) );
        $draftNo = (int) $write['version'];

        $this->assertRefused( function () { expAiDraftRevert::forDraft( 0, 1 ); }, 'does not exist' );
        $this->assertRefused( function () use ( $objectID ) { expAiDraftRevert::forDraft( $objectID, 1 ); }, 'not written by Exponential Intelligence' );
        $anonymous = eZUser::fetch( eZUser::anonymousId() );
        if ( $object->canRead( $anonymous ) )
            $this->assertRefused( function () use ( $objectID, $draftNo, $anonymous ) { expAiDraftRevert::forDraft( $objectID, $draftNo, $anonymous ); }, 'Only the editor' );

        $revert = expAiDraftRevert::forDraft( $objectID, $draftNo );
        $this->assertTrue( $revert->isOwnDraft() );
        $this->assertSame( '', $revert->revertRefusal() );
        $changes = $revert->changes();
        $this->assertSame( array( 1, 2 ), array( $changes['base_version'], count( $changes['fields'] ) ) );
        $seen = array();
        foreach ( $changes['fields'] as $field )
            $seen[$field['identifier']] = $field['hash'];

        // a stale fingerprint is a conflict, an unknown part too
        $conflict = $revert->revert( array(), array( 'title' ), array( 'title' => 'stale' ) );
        $this->assertSame( array( 'conflict', 'changed' ), array( $conflict['status'], $conflict['conflicts']['title'] ) );
        $unknown = $revert->revert( array( 'no-such-part' ), array( 'body' ), $seen );
        $this->assertSame( array( 'part', 'field' ), array( $unknown['conflicts']['#no-such-part'], $unknown['conflicts']['body'] ) );

        // the whole title back
        $done = $revert->revert( array(), array( 'title' ), $seen );
        $this->assertSame( array( 'reverted', array( 'title' ), 1 ), array( $done['status'], $done['written'], $done['reverted'] ) );
        $map = eZContentObject::fetch( $objectID )->fetchDataMap( $draftNo, self::SOURCE );
        $this->assertSame( 'XT revert', $map['title']->toString() );
        $this->assertCount( 1, $done['changes']['fields'], 'only the intro is still changed' );

        // the draft published: no longer a draft
        eZOperationHandler::execute( 'content', 'publish', array( 'object_id' => $objectID, 'version' => $draftNo ) );
        $published = expAiDraftRevert::forDraft( $objectID, $draftNo );
        $this->assertStringContainsString( 'no longer a draft', $published->revertRefusal() );
        $this->assertSame( (int) $admin->attribute( 'contentobject_id' ), (int) eZContentObject::fetch( $objectID )->version( $draftNo )->attribute( 'creator_id' ) );
    }

    public function testRevertOfANewTranslation(): void
    {
        $this->siteSettings();
        $folder = $this->folder();
        $this->asUser( 'admin' );
        $object = $this->article( (int) $folder->attribute( 'node_id' ), 'XT new translation', '<paragraph>Intro</paragraph>', '<paragraph>Body</paragraph>' );
        $objectID = (int) $object->attribute( 'id' );
        $write = expAiDiff::writeDraft( $object, self::TARGET, array( 'title' => 'XT neue Übersetzung' ), array( 'title' => 'ezstring' ), array( 'source' => 'translation' ) );
        $revert = expAiDraftRevert::forDraft( $objectID, (int) $write['version'] );
        $changes = $revert->changes();
        $fields = array_column( $changes['fields'], 'identifier' );
        $this->assertContains( 'title', $fields, 'against an empty base every field of the new translation is a change' );
        $seen = array();
        foreach ( $changes['fields'] as $field )
            $seen[$field['identifier']] = $field['hash'];
        $done = $revert->revert( array(), array( 'title' ), $seen );
        $this->assertSame( 'reverted', $done['status'] );
        $this->assertRefused( function () use ( $revert, $seen ) { $revert->revert( array(), array(), $seen ); }, 'Choose a part or a field' );
    }

    public function testTheEzjscoreFunctions(): void
    {
        $this->siteSettings();
        $folder = $this->folder();
        $this->asUser( 'admin' );
        $object = $this->article( (int) $folder->attribute( 'node_id' ), 'XT ezjscore revert', '<paragraph>Intro</paragraph>', '<paragraph>Body</paragraph>' );
        $objectID = (int) $object->attribute( 'id' );
        $write = expAiDiff::writeDraft( $object, self::SOURCE, array( 'title' => 'XT ezjscore changed' ), array( 'title' => 'ezstring' ), array( 'source' => 'panel' ) );

        self::post( array( 'object_id' => $objectID, 'version' => $write['version'] ), 'GET' );
        $this->assertRefused( function () { expAiDraftRevertServerFunctions::changes( array() ); }, 'only accepts POST' );
        if ( class_exists( 'ezxFormToken' ) && ezxFormToken::isEnabled() )
        {
            self::post( array( 'object_id' => $objectID, 'version' => $write['version'], 'ezxform_token' => 'wrong' ) );
            $this->assertRefused( function () { expAiDraftRevertServerFunctions::revert( array() ); }, 'form token' );
        }
        self::post( array( 'object_id' => $objectID, 'version' => 1 ) );
        $this->assertRefused( function () { expAiDraftRevertServerFunctions::changes( array() ); }, 'not written by' );
        $this->assertRefused( function () { expAiDraftRevertServerFunctions::revert( array() ); }, 'not written by' );

        self::post( array( 'object_id' => $objectID, 'version' => $write['version'] ) );
        $changes = expAiDraftRevertServerFunctions::changes( array() );
        $this->assertSame( 'What changed', $changes['strings']['title'] );
        $this->assertSame( 'title', $changes['fields'][0]['identifier'] );
        self::post( array( 'object_id' => $objectID, 'version' => $write['version'], 'fields' => json_encode( array( 'title' ) ),
                           'seen' => json_encode( array( 'title' => $changes['fields'][0]['hash'], 'odd' => array( 1 ) ) ), 'hunks' => 'not json' ) );
        $done = expAiDraftRevertServerFunctions::revert( array() );
        $this->assertSame( array( 'reverted', array( 'title' ) ), array( $done['status'], $done['written'] ) );
        $strings = expAiDraftRevertServerFunctions::strings();
        $this->assertStringContainsString( '%base', $strings['base'] );
    }
}
