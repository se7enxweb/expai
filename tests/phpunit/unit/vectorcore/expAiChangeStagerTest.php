<?php
/**
 * expAiChangeStager: the write tools in staged review. Scope checks, the bulk guard, values in storage form per
 * datatype, fields held by another run, restaging and editing a hunk.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

require_once __DIR__ . '/expaivectorcoretestcase.php';

class expAiChangeStagerTest extends expAiVectorCoreTestCase
{
    /** @return array( expAiChangeStager, expAiThread, eZContentObject article, int folder node ) */
    private function stager( array $scope = array(), $title = 'stager' )
    {
        $article = $this->article( 'XTB stager article', array( 'Alpha sentence.', 'Beta sentence.' ) );
        $folderNodeID = (int) eZContentObjectTreeNode::fetch( $article->mainNodeID() )->attribute( 'parent_node_id' );
        $thread = $this->thread( array( 'title' => $title, 'model_alias' => 'xtb.stub',
                                        'scope' => $scope ?: array( 'nodes' => array( (int) $article->mainNodeID() ) ) ) );
        $context = new expAiToolContext( expAiConfig::instance(), $folderNodeID, $thread->conversation() );
        $context->staged = true;
        $stager = expAiChangeStager::forContext( $context );
        $this->assertInstanceOf( 'expAiChangeStager', $stager );
        return array( $stager, $thread, $article, $folderNodeID );
    }

    private static function error( callable $fn )
    {
        try
        {
            $fn();
        }
        catch ( expAiException $e )
        {
            return strtr( $e->getMessage(), $e->arguments() );
        }
        return '';
    }

    public function testForContextOnlyWithStagedReviewAndAThread(): void
    {
        $context = new expAiToolContext( expAiConfig::instance(), 2, null );
        $this->assertNull( expAiChangeStager::forContext( $context ) );
        $context->staged = true;
        $this->assertNull( expAiChangeStager::forContext( $context ), 'no conversation' );
        $conversation = new expAiConversation( array( 'user_id' => $this->userID, 'thread_id' => 0 ) );
        $this->assertNull( expAiChangeStager::forContext( new expAiToolContext( expAiConfig::instance(), 2, $conversation ) ), 'not staged' );
        $context = new expAiToolContext( expAiConfig::instance(), 2, $conversation );
        $context->staged = true;
        $this->assertNull( expAiChangeStager::forContext( $context ), 'a conversation without a thread' );
        $thread = $this->thread( array( 'title' => 'for context' ) );
        $context = new expAiToolContext( expAiConfig::instance(), 2, $thread->conversation() );
        $context->staged = true;
        $this->assertSame( (int) $thread->attribute( 'id' ), (int) expAiChangeStager::forContext( $context )->thread()->attribute( 'id' ) );
    }

    public function testNameOfAProposedObject(): void
    {
        $this->assertSame( 'Main Street', expAiChangeStager::nameOf( array( 'intro' => 'x', 'title' => '<b>Main</b> Street' ) ) );
        $this->assertSame( 'Short intro', expAiChangeStager::nameOf( array( 'body' => str_repeat( 'long ', 40 ), 'intro' => 'Short intro' ) ) );
        $this->assertSame( '', expAiChangeStager::nameOf( array( 'body' => str_repeat( 'long ', 40 ), 'list' => array( 'a' ) ) ) );
        $this->assertSame( 'not fields', expAiChangeStager::nameOf( 'not fields' ), 'a single value is its own name' );
    }

    public function testScopeOfNodesObjectsAndLanguages(): void
    {
        $article = $this->article( 'XTB scope article' );
        $node = $article->mainNode();
        $folderNodeID = (int) $node->attribute( 'parent_node_id' );
        $any = $this->thread( array( 'title' => 'any scope' ) );
        $this->assertTrue( expAiChangeStager::nodeInScope( $any, $node ), 'a thread without nodes or subtrees reaches everything' );
        $subtree = $this->thread( array( 'title' => 'subtree', 'scope' => array( 'subtrees' => array( $folderNodeID ), 'classes' => array( 'folder' ), 'languages' => array( 'ger-DE' ) ) ) );
        $this->assertTrue( expAiChangeStager::nodeInScope( $subtree, $node ) );
        $this->assertFalse( expAiChangeStager::nodeInScope( $subtree, eZContentObjectTreeNode::fetch( 2 ) ) );
        $this->assertFalse( expAiChangeStager::objectInScope( $subtree, $article ), 'the class is not in the scope' );
        $this->assertTrue( expAiChangeStager::languageInScope( $subtree, 'ger-DE' ) );
        $this->assertFalse( expAiChangeStager::languageInScope( $subtree, 'eng-GB' ) );
        $this->assertTrue( expAiChangeStager::languageInScope( $any, 'eng-GB' ) );
        $other = $this->thread( array( 'title' => 'other node', 'scope' => array( 'nodes' => array( 2 ) ) ) );
        $this->assertFalse( expAiChangeStager::objectInScope( $other, $article ) );

        $this->assertNull( expAiChangeStager::approvedPlan( $any ) );
        foreach ( array( 'proposed' => false, 'approved' => true, 'running' => true, 'done' => true, 'rejected' => false ) as $state => $approved )
        {
            $plan = new expAiPlan( array( 'thread_id' => (int) $any->attribute( 'id' ), 'state' => $state, 'created' => time(), 'modified' => time() ) );
            $plan->store();
            $any->setAttribute( 'plan_id', (int) $plan->attribute( 'id' ) );
            $this->assertSame( $approved, expAiChangeStager::approvedPlan( $any ) !== null, $state );
        }
    }

    public function testStorageValuesPerDatatype(): void
    {
        list( $stager, $thread, $article ) = $this->stager();
        $short = new eZContentClassAttribute( array( 'data_int1' => 5 ) );
        $cases = array(
            array( 'ezstring', "<b>Two</b>\nlines", null, array( 'Two lines', '' ) ),
            array( 'ezstring', 'Too long', $short, array( null, 'longer than the maximum of 5 characters' ) ),
            array( 'eztext', '<p>One</p><p>Two</p>', null, array( "One\n\nTwo", '' ) ),
            array( 'eztext', '  plain  ', null, array( 'plain', '' ) ),
            array( 'ezkeyword', array( 'a', 'b' ), null, array( 'a, b', '' ) ),
            array( 'ezkeyword', ' a, , <i>b</i> ', null, array( 'a, b', '' ) ),
            array( 'ezboolean', true, null, array( '1', '' ) ),
            array( 'ezboolean', 'no', null, array( '0', '' ) ),
            array( 'ezboolean', 'Yes', null, array( '1', '' ) ),
            array( 'ezinteger', '42', null, array( '42', '' ) ),
            array( 'ezinteger', '4.2', null, array( null, 'not an integer' ) ),
            array( 'ezfloat', '4.5', null, array( '4.5', '' ) ),
            array( 'ezfloat', 'x', null, array( null, 'not a number' ) ),
            array( 'ezdate', '2026-10-12', null, array( (string) strtotime( '2026-10-12' ), '' ) ),
            array( 'ezdatetime', '1760000000', null, array( '1760000000', '' ) ),
            array( 'ezdate', 'someday', null, array( null, 'not a date (use YYYY-MM-DD or YYYY-MM-DD HH:MM)' ) ),
            array( 'ezemail', ' info@example.invalid ', null, array( 'info@example.invalid', '' ) ),
            array( 'ezemail', '', null, array( '', '' ) ),
            array( 'ezemail', 'not mail', null, array( null, 'not an e-mail address' ) ),
            array( 'ezurl', 'https://example.invalid/ | Example ', null, array( 'https://example.invalid/|Example', '' ) ),
            array( 'ezurl', 'mailto:a@example.invalid', null, array( 'mailto:a@example.invalid', '' ) ),
            array( 'ezurl', 'javascript:alert(1)', null, array( null, 'not an http(s) or mailto URL' ) ),
            array( 'ezselection', 7, null, array( '7', '' ) ),
            array( 'ezstring', array( 'x' ), null, array( null, 'the value must be a string, number or boolean' ) ),
        );
        foreach ( $cases as $case )
            $this->assertSame( $case[3], $stager->storageValue( $case[0], $case[1], $case[2], $article ), $case[0] . ' ' . json_encode( $case[1] ) );
        list( $xml, $error ) = $stager->storageValue( 'ezxmltext', '<p>Rich <strong>text</strong></p>', null, $article );
        $this->assertSame( '', $error );
        $this->assertStringContainsString( '<paragraph>', $xml );
        $this->assertStringContainsString( '<strong>text</strong>', $xml );
    }

    public function testStageUpdateRefusals(): void
    {
        list( $stager, $thread, $article ) = $this->stager( array(), 'refusals' );
        $other = $this->publish( (int) $article->mainNode()->attribute( 'parent_node_id' ), array( 'title' => 'XTB other article' ) );
        $this->assertSame( 'Object ' . (int) $other->attribute( 'id' ) . ' lies outside the scope of this thread.',
                           self::error( function () use ( $stager, $other ) { $stager->stageUpdate( $other, '', array( 'title' => 'X' ) ); } ) );
        $this->assertSame( 'Give the fields to write.', self::error( function () use ( $stager, $article ) { $stager->stageUpdate( $article, '', array() ); } ) );
        $this->assertStringStartsWith( 'Unknown field(s) nofield.', self::error( function () use ( $stager, $article ) { $stager->stageUpdate( $article, '', array( 'nofield' => 'x' ) ); } ) );
        $this->assertStringStartsWith( 'Nothing was staged: title:', self::error( function () use ( $stager, $article ) {
            $stager->stageUpdate( $article, '', array( 'title' => array( 'not', 'a string' ) ) ); } ) );
        $result = $stager->stageUpdate( $article, '', array( 'image' => '12', 'title' => 'XTB stager article' ) );
        $this->assertArrayHasKey( 'image', $result['skipped'] );
        $this->assertSame( array( 'title' ), $result['unchanged'] );
        $this->assertSame( 'nothing to stage: the values equal the published version', $result['status'] );

        $language = (string) $article->attribute( 'initial_language_code' );
        // a scope language that is not the article's (on a ger-DE site the article is ger-DE)
        $scoped = $this->thread( array( 'title' => 'language scope', 'scope' => array( 'nodes' => array( (int) $article->mainNodeID() ), 'languages' => array( $language === 'ger-DE' ? 'eng-GB' : 'ger-DE' ) ) ) );
        $context = new expAiToolContext( expAiConfig::instance(), 2, $scoped->conversation() );
        $context->staged = true;
        $this->assertSame( "The language $language is not in the scope of this thread.",
                           self::error( function () use ( $context, $article ) { expAiChangeStager::forContext( $context )->stageUpdate( $article, '', array( 'title' => 'X' ) ); } ) );
    }

    public function testTheLimitOfStagedChangesAndAProposalEqualToThePublishedValue(): void
    {
        list( $stager, $thread, $article ) = $this->stager( array(), 'limit' );
        $first = $stager->stageUpdate( $article, '', array( 'title' => 'XTB stager article (new)' ) );
        $this->assertCount( 1, $first['change_ids'] );
        $this->assertStringStartsWith( 'staged for the editor', $first['status'] );
        // the same proposal as the published value again: the earlier proposal of the field goes stale
        $back = $stager->stageUpdate( $article, '', array( 'title' => 'XTB stager article' ) );
        $this->assertSame( array( 'title' ), $back['unchanged'] );
        $this->assertSame( 'stale', expAiChange::fetchByID( $first['change_ids'][0] )->attribute( 'decision' ) );

        $stager->stageUpdate( $article, '', array( 'title' => 'XTB stager article (again)' ) );
        $this->ini( 'VectorSettings', 'MaxStagedChanges', '1' );
        $this->assertSame( 'This thread has 1 staged changes waiting. Ask the editor to review them first.',
                           self::error( function () use ( $stager, $article ) { $stager->stageUpdate( $article, '', array( 'short_title' => 'Short' ) ); } ) );
    }

    public function testAFieldHeldByAnotherRunIsNotStaged(): void
    {
        list( $stager, $thread, $article ) = $this->stager( array(), 'held' );
        $language = (string) $article->attribute( 'initial_language_code' );
        $other = $this->thread( array( 'title' => 'holder' ) );
        $run = $other->leadRun();
        $run->setAttribute( 'state', 'running' );
        $run->setAttribute( 'modified', time() );
        $run->store();
        $change = new expAiChange( array( 'thread_id' => (int) $other->attribute( 'id' ), 'run_id' => (int) $run->attribute( 'id' ),
                                          'contentobject_id' => (int) $article->attribute( 'id' ), 'language_code' => $language, 'attribute_identifier' => 'title',
                                          'datatype' => 'ezstring', 'proposed' => 'Held title', 'decision' => 'pending', 'created' => time(), 'modified' => time() ) );
        $change->store();
        $this->assertSame( (int) $run->attribute( 'id' ), (int) expAiChangeStager::holder( null, 0, (int) $article->attribute( 'id' ), $language, 'title' )->attribute( 'id' ) );
        $this->assertNull( expAiChangeStager::holder( null, (int) $run->attribute( 'id' ), (int) $article->attribute( 'id' ), $language, 'title' ), 'a run never holds against itself' );

        $message = self::error( function () use ( $stager, $article ) { $stager->stageUpdate( $article, '', array( 'title' => 'Mine' ) ); } );
        $this->assertStringStartsWith( 'Not staged: another run is changing these fields (title held by run ' . (int) $run->attribute( 'id' ), $message );
        $mixed = $stager->stageUpdate( $article, '', array( 'title' => 'Mine', 'short_title' => 'Mine too' ) );
        $this->assertSame( array( 'short_title' ), $mixed['staged'] );
        $this->assertArrayHasKey( 'title', $mixed['held'] );
        $this->assertStringContainsString( 'held', $mixed['status'] );

        // a run that stopped reporting holds nothing
        $run->setAttribute( 'modified', time() - 7200 );
        $run->store();
        $this->assertNull( expAiChangeStager::holder( null, 0, (int) $article->attribute( 'id' ), $language, 'title' ) );
    }

    public function testStageCreateRefusals(): void
    {
        list( $stager, $thread, $article, $folderNodeID ) = $this->stager( array(), 'create refusals' );
        $folder = eZContentObjectTreeNode::fetch( $folderNodeID );
        $this->assertSame( 'There is no content class "noclass".', self::error( function () use ( $stager, $folder ) { $stager->stageCreate( $folder, 'noclass', array( 'title' => 'x' ), '' ); } ) );
        $this->assertSame( "Node $folderNodeID lies outside the scope of this thread.",
                           self::error( function () use ( $stager, $folder ) { $stager->stageCreate( $folder, 'article', array( 'title' => 'x' ), '' ); } ) );

        $classes = $this->thread( array( 'title' => 'class scope', 'scope' => array( 'subtrees' => array( $folderNodeID ), 'classes' => array( 'folder' ) ) ) );
        $context = new expAiToolContext( expAiConfig::instance(), $folderNodeID, $classes->conversation() );
        $context->staged = true;
        $scoped = expAiChangeStager::forContext( $context );
        $this->assertSame( 'The class "article" is not in the scope of this thread.',
                           self::error( function () use ( $scoped, $folder ) { $scoped->stageCreate( $folder, 'article', array( 'title' => 'x' ), '' ); } ) );
        $languages = $this->thread( array( 'title' => 'language scope', 'scope' => array( 'subtrees' => array( $folderNodeID ), 'languages' => array( 'xxx-XX' ) ) ) );
        $context = new expAiToolContext( expAiConfig::instance(), $folderNodeID, $languages->conversation() );
        $context->staged = true;
        $this->assertStringContainsString( 'is not in the scope of this thread.',
                                           self::error( function () use ( $context, $folder ) { expAiChangeStager::forContext( $context )->stageCreate( $folder, 'article', array( 'title' => 'x' ), '' ); } ) );

        // a proposal is replaced through its key; a key of nothing starts a new proposal
        $open = $this->thread( array( 'title' => 'create', 'scope' => array( 'subtrees' => array( $folderNodeID ) ) ) );
        $context = new expAiToolContext( expAiConfig::instance(), $folderNodeID, $open->conversation() );
        $context->staged = true;
        $create = expAiChangeStager::forContext( $context );
        $first = $create->stageCreate( $folder, 'article', array( 'title' => 'XTB new one' ), '' );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{16}$/', $first['new_key'] );
        $again = $create->stageCreate( $folder, 'article', array( 'title' => 'XTB new one, renamed' ), '', $first['new_key'] );
        $this->assertSame( $first['new_key'], $again['new_key'] );
        $this->assertSame( $first['change_ids'], $again['change_ids'], 'the same change is updated' );
        $this->assertSame( 'XTB new one, renamed', expAiChange::fetchByID( $again['change_ids'][0] )->attribute( 'proposed' ) );
        $this->assertStringStartsWith( 'This is bulk work', self::error( function () use ( $create, $folder ) { $create->stageCreate( $folder, 'article', array( 'title' => 'XTB' ), '', 'abcdef0123456789' ); } ), 'an unknown key is a second new object' );
        $this->ini( 'VectorSettings', 'BulkObjectThreshold', '5' );
        $unknownKey = $create->stageCreate( $folder, 'article', array( 'title' => 'XTB' ), '', 'abcdef0123456789' );
        $this->assertNotSame( 'abcdef0123456789', $unknownKey['new_key'] );
    }

    public function testRestageAndEditHunk(): void
    {
        list( $stager, $thread, $article ) = $this->stager( array(), 'restage' );
        $result = $stager->stageUpdate( $article, '', array( 'title' => 'XTB stager article, new', 'intro' => '<p>Alpha sentence, new.</p><p>Beta sentence.</p>' ) );
        $changes = array();
        foreach ( $result['change_ids'] as $id )
        {
            $change = expAiChange::fetchByID( $id );
            $changes[$change->attribute( 'attribute_identifier' )] = $change;
        }
        $this->assertSame( 'This field cannot be edited here; accept it and edit the draft in the edit form.',
                           self::error( function () use ( $changes ) { expAiChangeStager::editHunk( $changes['intro'], 'x', 'y' ); } ) );
        $title = $changes['title'];
        $this->assertSame( 'This part of the change cannot be edited.', self::error( function () use ( $title ) { expAiChangeStager::editHunk( $title, 'nohunk', 'y' ); } ) );
        $hunk = $title->hunks()[0];
        $edited = expAiChangeStager::editHunk( $title, $hunk['id'], "XTB stager article,\n edited" );
        $this->assertSame( 'XTB stager article, edited', $edited->attribute( 'proposed' ) );
        $this->assertTrue( (bool) array_filter( $edited->hunks(), function ( $h ) { return $h['edited'] && $h['decision'] === 'accepted'; } ) );
        $back = expAiChangeStager::editHunk( $edited, $edited->hunks()[0]['id'], 'XTB stager article' );
        $this->assertSame( 'stale', $back->attribute( 'decision' ), 'edited back to the published value: nothing left' );

        $applied = expAiChange::fetchByID( (int) $title->attribute( 'id' ) );
        $applied->setAttribute( 'decision', 'applied' );
        $this->assertSame( 'This change can no longer be edited.', self::error( function () use ( $applied ) { expAiChangeStager::editHunk( $applied, 'x', 'y' ); } ) );

        // the published version changes: editing is refused until it is restaged against it
        $fresh = $stager->stageUpdate( $article, '', array( 'title' => 'XTB stager article, third' ) );
        $title = expAiChange::fetchByID( $fresh['change_ids'][0] );
        eZContentFunctions::updateAndPublishObject( eZContentObject::fetch( (int) $article->attribute( 'id' ) ), array( 'attributes' => array( 'title' => 'XTB changed elsewhere' ) ) );
        $this->assertTrue( $title->baseChanged() );
        $this->assertSame( 'The content changed since this was proposed. Review it against the current version first.',
                           self::error( function () use ( $title ) { expAiChangeStager::editHunk( $title, 'x', 'y' ); } ) );
        $restaged = expAiChangeStager::restage( $title );
        $this->assertFalse( $restaged->baseChanged() );
        $this->assertSame( (int) eZContentObject::fetch( (int) $article->attribute( 'id' ) )->attribute( 'current_version' ), (int) $restaged->attribute( 'base_version' ) );
        $this->assertSame( 'pending', $restaged->attribute( 'decision' ) );

        $gone = new expAiChange( array( 'thread_id' => (int) $thread->attribute( 'id' ), 'contentobject_id' => 2147483000, 'language_code' => 'eng-GB',
                                        'attribute_identifier' => 'title', 'datatype' => 'ezstring', 'proposed' => 'x' ) );
        $this->assertSame( 'The object of this change no longer exists.', self::error( function () use ( $gone ) { expAiChangeStager::restage( $gone ); } ) );
    }

    public function testEditAHunkOfPlainText(): void
    {
        $change = new expAiChange( array( 'contentobject_id' => 0, 'new_key' => 'abcdef0123456789', 'language_code' => 'eng-GB', 'attribute_identifier' => 'body',
                                          'datatype' => 'eztext', 'proposed' => "First paragraph.\n\nSecond paragraph.", 'decision' => 'pending' ) );
        $change->setHunks( expAiChangeStager::hunksFor( 'eztext', '', (string) $change->attribute( 'proposed' ), 'eng-GB', 'body' ) );
        $hunk = $change->hunks()[0];
        $this->assertNotEmpty( $hunk['prop'] );
        // in memory only: store() is part of editHunk, so the change gets a thread of this test
        $thread = $this->thread( array( 'title' => 'plain text' ) );
        $change->setAttribute( 'thread_id', (int) $thread->attribute( 'id' ) );
        $edited = expAiChangeStager::editHunk( $change, $hunk['id'], 'Only one paragraph now.' );
        $this->assertSame( 'Only one paragraph now.', $edited->attribute( 'proposed' ) );
    }
}
