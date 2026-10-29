<?php
/**
 * The project history (expAiProjectHistory, expAiGitRunner) on a throwaway git repository the test makes below its
 * own temporary directory: commits with hidden names left out, the path and search filters, the branch check, the
 * runner's refusals, exit codes, time and output limits, and the release notes through a GitHub transport stub.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

class expAiProjectHistoryTest extends expAiTestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if ( !is_executable( '/usr/bin/git' ) )
            $this->markTestSkipped( 'needs /usr/bin/git' );
        $this->dir = expAiTestMockServer::tmp( 'history' );
        $dir = $this->dir;
        $this->cleanup( static function () use ( $dir ) { expAiTestMockServer::removeTree( $dir ); } );
    }

    protected function tearDown(): void
    {
        expAiProjectHistory::$githubTransport = null;
        parent::tearDown();
    }

    /** Runs git in the throwaway repository (author and committer fixed, no global settings). */
    private function git( array $arguments )
    {
        $env = array( 'PATH' => '/usr/bin:/bin', 'HOME' => $this->dir, 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null',
                      'GIT_AUTHOR_NAME' => 'XT Author', 'GIT_AUTHOR_EMAIL' => 'author@example.invalid', 'GIT_COMMITTER_NAME' => 'XT',
                      'GIT_COMMITTER_EMAIL' => 'committer@example.invalid', 'GIT_AUTHOR_DATE' => '2026-10-01T10:00:00', 'GIT_COMMITTER_DATE' => '2026-10-01T10:00:00' );
        $process = proc_open( array_merge( array( '/usr/bin/git', '-C', $this->dir . '/repo' ), $arguments ),
                              array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $this->dir, $env );
        stream_get_contents( $pipes[1] );
        stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $this->assertSame( 0, proc_close( $process ), 'git ' . implode( ' ', $arguments ) );
    }

    /** @return string the repository: three commits, one naming an excluded directory, one an untracked note */
    private function repository()
    {
        $repo = $this->dir . '/repo';
        mkdir( $repo . '/src', 0775, true );
        mkdir( $repo . '/ai', 0775, true );
        $this->git( array( 'init', '-q', '-b', 'main' ) );
        file_put_contents( $repo . '/src/bread.php', "<?php // bread\n" );
        $this->git( array( 'add', 'src/bread.php' ) );
        $this->git( array( 'commit', '-q', '-m', 'Added: Bread' ) );
        file_put_contents( $repo . '/ai/plan.txt', "private\n" );
        $this->git( array( 'add', 'ai/plan.txt' ) );
        $this->git( array( 'commit', '-q', '-m', 'Updated: the plan in ai/plan.txt' ) );
        file_put_contents( $repo . '/src/rolls.php', "<?php // rolls\n" );
        $this->git( array( 'add', 'src/rolls.php' ) );
        $this->git( array( 'commit', '-q', '-m', 'Added: Rolls, see notes.md' ) );
        file_put_contents( $repo . '/notes.md', "untracked note\n" );
        file_put_contents( $repo . '/README.md', "readme\n" );
        $this->git( array( 'add', 'README.md' ) );
        $this->git( array( 'commit', '-q', '-m', 'Added: README.md' ) );
        return $repo;
    }

    private function historyConfig( $repo, array $more = array() )
    {
        return $this->config( array( 'HistorySettings' => $more + array( 'Repositories' => array( 'test', 'Bad Id', 'nogroup' ), 'Roots' => array( $this->dir ),
                                                                          'ExcludePaths' => array( 'ai/*', '', 'secret.txt' ), 'HideUntrackedRootMarkdown' => 'enabled',
                                                                          'ShowAuthors' => 'disabled', 'MaxCommits' => '30', 'GitBinary' => '/usr/bin/git' ),
                                     'HistoryRepository_test' => array( 'Label' => 'Test repository', 'Path' => $repo, 'GitHub' => 'owner/name', 'Branch' => '' ),
                                     'HistoryRepository_nogroup_x' => array( 'Label' => 'x' ) ) );
    }

    public function testCommitsLeaveOutHiddenNames(): void
    {
        $repo = $this->repository();
        $history = new expAiProjectHistory( $this->historyConfig( $repo ) );
        $repos = $history->repositories();
        $this->assertSame( array( 'test' ), array_keys( $repos ), 'an invalid id and an id without its group are left out' );
        $this->assertSame( array( 'Test repository', 'owner/name' ), array( $repos['test']['label'], $repos['test']['github'] ) );
        $this->assertSame( realpath( $repo ), $history->checkedPath( $repo ) );

        $titles = array_column( $history->commits( $repos['test'], array( 'limit' => 10 ) ), 'title' );
        $this->assertSame( array( 'Added: README.md', 'Added: Bread' ), $titles, 'a title naming ai/ or an untracked root note is left out; a tracked one stays' );
        $this->assertSame( array( 'Added: Bread' ), array_column( $history->commits( $repos['test'], array( 'search' => 'bread' ) ), 'title' ) );
        $this->assertSame( array(), $history->commits( $repos['test'], array( 'since' => '2030-01-01' ) ) );
        $this->assertSame( array( 'Added: Bread' ), array_column( $history->commits( $repos['test'], array( 'path' => './src/bread.php' ) ), 'title' ) );
        $this->assertCount( 1, $history->commits( $repos['test'], array( 'limit' => 1 ) ) );

        $withAuthors = new expAiProjectHistory( $this->historyConfig( $repo, array( 'ShowAuthors' => 'enabled' ) ) );
        $this->assertSame( 'XT Author', $withAuthors->commits( $repos['test'], array( 'limit' => 1 ) )[0]['author'] );

        $this->assertRefused( function () use ( $history, $repos ) { $history->commits( array( 'branch' => '--all' ) + $repos['test'], array() ); }, 'invalid Branch setting' );
        $this->assertSame( 'Added: README.md', $history->commits( array( 'branch' => 'main' ) + $repos['test'], array( 'limit' => 1 ) )[0]['title'] );
        foreach ( array( '../etc/passwd', '/etc/passwd', '-x', 'src/*.php', ':(glob)x' ) as $bad )
            $this->assertRefused( function () use ( $history, $repos, $bad ) { $history->commits( $repos['test'], array( 'path' => $bad ) ); }, 'not valid' );
        $this->assertRefused( function () use ( $history, $repos ) { $history->commits( $repos['test'], array( 'path' => 'ai/plan.txt' ) ); }, 'not part of the public project history' );
        $this->assertRefused( function () use ( $history, $repos ) { $history->commits( $repos['test'], array( 'path' => 'notes.md' ) ); }, 'untracked path' );
        $this->assertTrue( $history->excludedPath( '/ai' ) );
        $this->assertTrue( $history->excludedPath( 'secret.txt' ) );
        $this->assertFalse( $history->excludedPath( 'src/ai.php' ) );

        // a clone outside Roots[], a directory that is no clone
        $outside = new expAiProjectHistory( $this->config( array( 'HistorySettings' => array( 'Roots' => array( 'extension' ) ) ) ) );
        $this->assertRefused( function () use ( $outside, $repo ) { $outside->checkedPath( $repo ); }, 'outside [HistorySettings] Roots[]' );
        $this->assertRefused( function () use ( $history ) { $history->checkedPath( $this->dir ); }, 'not a git clone' );
        $this->assertTrue( expAiProjectHistory::namesHidden( 'see ai/x', array( 'ai/' ) ) );
        $this->assertFalse( expAiProjectHistory::namesHidden( 'see mai/x', array( 'ai/' ) ) );
    }

    public function testTheRunnerRefusesAndLimits(): void
    {
        $repo = $this->repository();
        $this->assertRefused( function () use ( $repo ) { ( new expAiGitRunner( 'git' ) )->run( $repo, array( 'log' ) ); }, 'not an executable absolute path' );
        $git = new expAiGitRunner( '/usr/bin/git', 5 );
        $this->assertRefused( function () use ( $git, $repo ) { $git->run( $repo, array( 'push' ) ); }, 'subcommand not allowed' );
        $this->assertRefused( function () use ( $git, $repo ) { $git->run( $repo, array() ); }, 'subcommand not allowed' );
        $this->assertRefused( function () use ( $git, $repo ) { $git->run( $repo, array( 'log', "a\0b" ) ); }, 'invalid argument' );
        $this->assertRefused( function () use ( $git, $repo ) { $git->run( $repo, array( 'log', array( 'x' ) ) ); }, 'invalid argument' );
        $this->assertRefused( function () use ( $git, $repo ) { $git->run( $repo, array( 'rev-parse', '--verify', 'no-such-ref' ) ); }, 'git exit' );
        $this->assertSame( 40, strlen( trim( $git->run( $repo, array( 'rev-parse', 'HEAD' ) ) ) ) );

        // a binary that answers too slowly, one that answers too much
        $slow = $this->dir . '/slow.sh';
        file_put_contents( $slow, "#!/bin/sh\nsleep 5\n" );
        chmod( $slow, 0755 );
        $this->assertRefused( function () use ( $slow, $repo ) { ( new expAiGitRunner( $slow, 1 ) )->run( $repo, array( 'log' ) ); }, 'timed out after 1 s' );
        $loud = $this->dir . '/loud.sh';
        file_put_contents( $loud, "#!/bin/sh\nexec /usr/bin/yes aaaaaaaaaaaaaaaa\n" );
        chmod( $loud, 0755 );
        $this->assertSame( 4096, strlen( ( new expAiGitRunner( $loud, 5, 4096 ) )->run( $repo, array( 'log' ) ) ), 'the output is cut at the limit' );
    }

    public function testReleaseNotes(): void
    {
        $repo = $this->repository();
        $history = new expAiProjectHistory( $this->historyConfig( $repo, array( 'MaxReleaseNotesChars' => '100' ) ) );
        $repos = $history->repositories();
        $answers = array();
        expAiProjectHistory::$githubTransport = function ( $url ) use ( &$answers )
        {
            $this->assertSame( 'https://api.github.com/repos/owner/name/releases?per_page=20', $url );
            return array_shift( $answers );
        };
        $answers = array( array( 200, json_encode( array(
            array( 'tag_name' => 'v2', 'name' => '', 'published_at' => '2026-10-02T00:00:00Z', 'html_url' => 'https://github.com/owner/name/releases/v2',
                   'body' => "Bread\nsee ai/plan.txt\nRolls", 'draft' => false ),
            array( 'tag_name' => 'v3', 'name' => 'Draft', 'published_at' => '', 'draft' => true ),
            array( 'tag_name' => 'v1', 'name' => 'First', 'published_at' => '2026-01-01T00:00:00Z', 'html_url' => 'https://evil.example/x', 'body' => 'Start' ),
            'not a release' ) ) ) );
        $releases = $history->releases( $repos['test'], array() );
        $this->assertSame( array( 'v2', 'v1' ), array_column( $releases, 'tag' ) );
        $this->assertSame( array( 'v2', "Bread\nRolls", '2026-10-02' ), array( $releases[0]['name'], $releases[0]['notes'], $releases[0]['date'] ), 'a line naming ai/ is left out' );
        $this->assertSame( '', $releases[1]['url'], 'only github.com links' );

        $answers = array( array( 200, json_encode( array( array( 'tag_name' => 'v1', 'name' => 'First', 'published_at' => '2026-01-01T00:00:00Z', 'body' => 'Start' ),
                                                           array( 'tag_name' => 'v2', 'published_at' => '2026-10-02T00:00:00Z', 'body' => 'Bread' ) ) ) ) );
        $this->assertSame( array( 'v2' ), array_column( $history->releases( array( 'path' => '/nowhere' ) + $repos['test'], array( 'since' => '2026-06-01' ) ), 'tag' ) );
        $answers = array( array( 200, json_encode( array( array( 'tag_name' => 'v1', 'body' => 'Start' ), array( 'tag_name' => 'v2', 'body' => 'Bread' ) ) ) ) );
        $this->assertSame( array( 'v2' ), array_column( $history->releases( array( 'path' => '' ) + $repos['test'], array( 'search' => 'BREAD', 'limit' => 1 ) ), 'tag' ) );

        foreach ( array( array( array( 404, '' ), 'not public' ), array( array( 403, '' ), 'GitHub limits requests' ), array( array( 429, '' ), 'GitHub limits requests' ),
                         array( array( 500, '' ), 'GitHub HTTP 500' ), array( array( 200, 'no json' ), 'not JSON' ) ) as $case )
        {
            $answers = array( $case[0] );
            $this->assertRefused( function () use ( $history ) { $history->githubReleases( 'owner/name' ); }, $case[1] );
        }
        expAiProjectHistory::$githubTransport = null;
        $insecure = new expAiProjectHistory( $this->historyConfig( $repo, array( 'GitHubApi' => 'http://api.github.com', 'ReleasesCacheTTL' => '0' ) ) );
        $this->assertRefused( function () use ( $insecure ) { $insecure->githubReleases( 'owner/name' ); }, 'GitHubApi must be https' );
    }

    private function assertRefused( callable $fn, $needle )
    {
        try
        {
            $fn();
        }
        catch ( expAiException $e )
        {
            $this->assertStringContainsString( $needle, strtr( $e->getMessage(), $e->arguments() ) . ' ' . $e->detail() );
            return;
        }
        $this->fail( "no refusal containing '$needle'" );
    }
}
