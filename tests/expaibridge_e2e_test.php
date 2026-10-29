<?php
/**
 * End-to-end test of the in-process bridge to expmcp (exp__<tool>) with two users: an administrator and a
 * restricted user the test creates for the run and removes again. The bridge tools are run the way the panel runs
 * them (expAiMcpBridgeTool::execute() with a tool context), through expmcp's bridge seam expMcpBridge:
 *
 *   - the restricted user reads one subtree below node 2 and gets nothing from a second one (gets refused, queries
 *     empty, search hits only readable), the administrator gets both;
 *   - the data policy of the panel's provider leaves rows out of nodes and items and refuses an answer about one
 *     object it keeps away;
 *   - a siteaccess off expmcp's allowlist, and arguments the tool does not take, are tool errors;
 *   - with an older expmcp (no expMcpBridge, simulated) only exp_content_query is offered, built the old way, and
 *     still answers as the current user.
 *
 * What it writes, and removes again (also when a check fails): a user group below the users' top node, a user in it
 * whose password hash is random bytes (nobody can sign in with it), and one role.
 *
 * Run from the installation root, on a siteaccess on expmcp.ini [Security] AllowedSiteAccesses, as the web server's
 * user (the caches it writes stay readable to it):
 *   php bin/php/ezexec.php -s admin extension/expai/tests/expaibridge_e2e_test.php
 * Prints one line per check and ends with PASS or FAIL (exit code 0 or 1).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

const EXPAI_BRIDGE_TEST_GROUP_REMOTE_ID = 'expai-bridge-test-group';
const EXPAI_BRIDGE_TEST_ROLE = 'expai bridge test';

$failures = 0;
$checks = 0;
$check = function ( $label, $actual, $expected ) use ( &$failures, &$checks )
{
    ++$checks;
    $ok = $actual === $expected;
    if ( !$ok )
        ++$failures;
    echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $label . ( $ok ? '' : ' => ' . var_export( $actual, true ) . ' (expected ' . var_export( $expected, true ) . ')' ) . "\n";
};
$script = eZScript::instance();
if ( !class_exists( 'expMcpBridge' ) )
{
    echo "FAIL the bridge seam of expmcp (expMcpBridge, 0.3.1 or later) is not installed\n";
    $script->shutdown( 1 );
}
$admin = eZUser::fetchByName( getenv( 'EXPAI_TEST_ADMIN' ) ?: 'admin' );
if ( !$admin instanceof eZUser )
{
    echo "FAIL the administrator account does not exist\n";
    $script->shutdown( 1 );
}

/** Makes $user the current user of the process, as a signed-in request has it. */
$become = function ( eZUser $user )
{
    $id = (int) $user->attribute( 'contentobject_id' );
    $GLOBALS['eZUserGlobalInstance_'] = $user;
    $GLOBALS["eZUserGlobalInstance_{$id}"] = $user;
};
/** Removes what an earlier, interrupted run may have left. */
$cleanup = function ()
{
    $group = eZContentObject::fetchByRemoteID( EXPAI_BRIDGE_TEST_GROUP_REMOTE_ID );
    if ( $group instanceof eZContentObject && $group->attribute( 'main_node_id' ) )
        eZContentObjectTreeNode::removeSubtrees( array( (int) $group->attribute( 'main_node_id' ) ), false );
    elseif ( $group instanceof eZContentObject )
        $group->purge();
    $role = eZRole::fetchByName( EXPAI_BRIDGE_TEST_ROLE );
    if ( $role instanceof eZRole )
        eZRole::removeRole( (int) $role->attribute( 'id' ) );
    eZRole::expireCache();
};

// the bridge of this run, in memory only
$ini = eZINI::instance( 'expai.ini' );
$ini->setVariable( 'McpBridge', 'Enabled', 'enabled' );
$ini->setVariable( 'McpBridge', 'Tools', array( 'exp_content_query', 'exp_content_get', 'exp_search', 'exp_content_relations', 'exp_content_versions',
                                                'exp_content_meta', 'exp_class_list', 'exp_class_describe', 'exp_fetch' ) );
$ini->setVariable( 'McpSettings', 'Servers', array() );
$config = expAiConfig::instance();

$become( $admin );
$tools = expAiMcp::tools( $config );
$check( 'admin: the allowlisted bridge-safe tools are offered, exp_fetch is not', array_keys( $tools ),
        array( 'exp__exp_content_query', 'exp__exp_content_get', 'exp__exp_search', 'exp__exp_content_relations', 'exp__exp_content_versions',
               'exp__exp_content_meta', 'exp__exp_class_list', 'exp__exp_class_describe' ) );

// two subtrees below node 2 that hold content, as the administrator sees them
$candidates = array();
foreach ( (array) eZContentObjectTreeNode::subTreeByNodeID( array( 'Depth' => 1, 'Limitation' => array(), 'IgnoreVisibility' => false ), 2 ) as $child )
{
    $count = (int) $child->subTreeCount( array( 'Limitation' => array(), 'IgnoreVisibility' => false ) );
    if ( $count > 0 )
        $candidates[] = array( $count, $child );
}
usort( $candidates, function ( $a, $b ) { return $b[0] <=> $a[0]; } );
if ( count( $candidates ) < 2 )
{
    echo "FAIL the test needs two visible subtrees with content below node 2\n";
    $script->shutdown( 1 );
}
$allowed = $candidates[0][1];
$forbidden = $candidates[1][1];
$allowedID = (int) $allowed->attribute( 'node_id' );
$forbiddenID = (int) $forbidden->attribute( 'node_id' );
$forbiddenPath = (string) $forbidden->attribute( 'path_string' );
$word = 'a';
$first = eZContentObjectTreeNode::subTreeByNodeID( array( 'Depth' => 1, 'Limit' => 1, 'Limitation' => array() ), $forbiddenID );
if ( $first )
    foreach ( preg_split( '/[^\p{L}\p{N}]+/u', (string) $first[0]->attribute( 'name' ) ) as $part )
        if ( mb_strlen( $part ) > 3 )
        {
            $word = $part;
            break;
        }
echo "allowed subtree $allowedID, forbidden subtree $forbiddenID, search word '$word'\n";

/** Runs a bridge tool as the panel does; an expAiException becomes array( 'exception' => message ). */
$run = function ( $name, array $input, ?expAiContentReader $reader = null ) use ( $config, $allowedID )
{
    $tool = expAiMcpBridgeTool::create( $name );
    if ( !$tool )
        return array( 'exception' => 'not offered' );
    $context = new expAiToolContext( $config, $allowedID );
    if ( $reader )
        $context->reader = $reader;
    try
    {
        return $tool->execute( $input, $context );
    }
    catch ( expAiException $e )
    {
        return array( 'exception' => $e->getMessage() );
    }
};
$outside = function ( array $rows ) use ( $forbiddenPath )
{
    foreach ( $rows as $row )
    {
        $node = !empty( $row['node_id'] ) ? eZContentObjectTreeNode::fetch( (int) $row['node_id'] ) : null;
        if ( $node && strpos( (string) $node->attribute( 'path_string' ), $forbiddenPath ) === 0 )
            return false;
    }
    return true;
};

$restricted = null;
$cleanup();
try
{
    // the restricted user: a group of his own, a random password hash, one role reading the allowed subtree
    $placement = eZContentObjectTreeNode::fetch( (int) eZINI::instance()->variable( 'UserSettings', 'DefaultUserPlacement' ) );
    $usersTop = $placement instanceof eZContentObjectTreeNode ? (int) $placement->attribute( 'parent_node_id' ) : 5;
    $groupObject = eZContentFunctions::createAndPublishObject( array( 'parent_node_id' => $usersTop, 'class_identifier' => 'user_group',
        'creator_id' => (int) $admin->attribute( 'contentobject_id' ), 'remote_id' => EXPAI_BRIDGE_TEST_GROUP_REMOTE_ID,
        'attributes' => array( 'name' => 'expai bridge test' ) ) );
    if ( !$groupObject instanceof eZContentObject )
        throw new RuntimeException( 'could not create the test user group' );
    $login = 'expai_bridge_test_' . bin2hex( random_bytes( 3 ) );
    $userObject = eZContentFunctions::createAndPublishObject( array( 'parent_node_id' => (int) $groupObject->attribute( 'main_node_id' ),
        'class_identifier' => 'user', 'creator_id' => (int) $admin->attribute( 'contentobject_id' ),
        'attributes' => array( 'first_name' => 'expai', 'last_name' => 'bridge test',
                               'user_account' => $login . '|' . $login . '@example.invalid|' . bin2hex( random_bytes( 16 ) ) . '|md5_user|1' ) ) );
    $restricted = $userObject instanceof eZContentObject ? eZUser::fetch( (int) $userObject->attribute( 'id' ) ) : null;
    if ( !$restricted instanceof eZUser )
        throw new RuntimeException( 'could not create the restricted test user' );
    $role = eZRole::create( EXPAI_BRIDGE_TEST_ROLE );
    $role->store();
    $subtree = array( 'Subtree' => array( (string) $allowed->attribute( 'path_string' ) ) );
    $role->appendPolicy( 'content', 'read', $subtree );
    $role->appendPolicy( 'content', 'versionread', $subtree );
    $role->appendPolicy( 'expai', 'mcp' );
    $role->assignToUser( (int) $restricted->attribute( 'contentobject_id' ) );
    eZRole::expireCache();
    $restricted = eZUser::fetch( (int) $restricted->attribute( 'contentobject_id' ) );

    // ---------------------------------------------------------------- the restricted user
    $become( $restricted );
    $check( 'restricted: the test user is the current user', (int) eZUser::currentUser()->attribute( 'contentobject_id' ), (int) $restricted->attribute( 'contentobject_id' ) );
    $check( 'restricted: the bridge is offered (expai/mcp)', isset( expAiMcp::tools( $config )['exp__exp_content_get'] ), true );
    $answer = $run( 'exp_content_get', array( 'node_id' => $forbiddenID ) );
    $check( 'restricted: exp_content_get of the forbidden subtree is refused', isset( $answer['error'] ), true );
    $answer = $run( 'exp_content_get', array( 'node_id' => $allowedID ) );
    $check( 'restricted: exp_content_get of the allowed subtree answers', isset( $answer['error'] ) ? $answer['error'] : ( $answer['node_id'] ?? null ), $allowedID );
    $answer = $run( 'exp_content_query', array( 'parent_node_id' => $forbiddenID, 'limit' => 10 ) );
    $check( 'restricted: exp_content_query below the forbidden subtree is empty', isset( $answer['error'] ) || ( isset( $answer['nodes'] ) && $answer['nodes'] === array() ), true );
    $answer = $run( 'exp_content_query', array( 'parent_node_id' => $allowedID, 'limit' => 10 ) );
    $check( 'restricted: exp_content_query below the allowed subtree has rows', !empty( $answer['nodes'] ), true );
    $answer = $run( 'exp_search', array( 'text' => $word, 'limit' => 25 ) );
    $check( 'restricted: exp_search answers', isset( $answer['items'] ) || isset( $answer['error'] ), true );
    $check( 'restricted: exp_search has no hit in the forbidden subtree', $outside( (array) ( $answer['items'] ?? array() ) ), true );
    $answer = $run( 'exp_content_versions', array( 'action' => 'list', 'node_id' => $forbiddenID ) );
    $check( 'restricted: exp_content_versions of the forbidden subtree is refused', isset( $answer['error'] ), true );
    $answer = $run( 'exp_content_relations', array( 'action' => 'locations', 'node_id' => $forbiddenID ) );
    $check( 'restricted: exp_content_relations of the forbidden subtree is refused', isset( $answer['error'] ), true );
    $answer = $run( 'exp_class_list', array() );
    $check( 'restricted: exp_class_list answers', is_array( $answer ) && !isset( $answer['exception'] ), true );

    // an older expmcp without the seam: exp_content_query only, built the old way, still as the current user
    expAiMcp::$bridgeSeam = false;
    $check( 'old expmcp: only exp_content_query is offered', array_keys( expAiMcp::tools( $config ) ), array( 'exp__exp_content_query' ) );
    $answer = $run( 'exp_content_query', array( 'parent_node_id' => $forbiddenID, 'limit' => 10 ) );
    $check( 'old expmcp: the restricted user gets nothing below the forbidden subtree', isset( $answer['error'] ) || ( isset( $answer['nodes'] ) && $answer['nodes'] === array() ), true );
    $answer = $run( 'exp_content_query', array( 'parent_node_id' => $allowedID, 'limit' => 10 ) );
    $check( 'old expmcp: rows below the allowed subtree', !empty( $answer['nodes'] ), true );
    expAiMcp::$bridgeSeam = null;

    // ---------------------------------------------------------------- the administrator
    $become( $admin );
    $answer = $run( 'exp_content_get', array( 'node_id' => $forbiddenID ) );
    $check( 'admin: exp_content_get of the second subtree answers', isset( $answer['error'] ) ? $answer['error'] : ( $answer['node_id'] ?? null ), $forbiddenID );
    $answer = $run( 'exp_content_query', array( 'parent_node_id' => $forbiddenID, 'limit' => 10 ) );
    $check( 'admin: exp_content_query below the second subtree has rows', !empty( $answer['nodes'] ), true );

    // the data policy: a reader that keeps the first child of the allowed subtree away
    $rows = $run( 'exp_content_query', array( 'parent_node_id' => $allowedID, 'limit' => 10 ) );
    $denyID = isset( $rows['nodes'][0]['object_id'] ) ? (int) $rows['nodes'][0]['object_id'] : 0;
    $denyNodeID = isset( $rows['nodes'][0]['node_id'] ) ? (int) $rows['nodes'][0]['node_id'] : 0;
    $reader = new class( $config ) extends expAiContentReader
    {
        public $deny = array();
        public function policy() { return true; }
        public function allowed( eZContentObject $object ) { return !in_array( (int) $object->attribute( 'id' ), $this->deny, true ); }
        public function assertAllowed( eZContentObject $object )
        {
            if ( !$this->allowed( $object ) )
                throw new expAiException( 'kept away by the test policy', 'data policy' );
        }
    };
    $reader->deny = array( $denyID );
    $answer = $run( 'exp_content_query', array( 'parent_node_id' => $allowedID, 'limit' => 10 ), $reader );
    $check( 'policy: exp_content_query leaves the kept-away row out of nodes', in_array( $denyID, array_column( (array) ( $answer['nodes'] ?? array() ), 'object_id' ), true ), false );
    $check( 'policy: and counts it', $reader->withheld, 1 );
    $reader->withheld = 0;
    $name = (string) eZContentObject::fetch( $denyID )->attribute( 'name' );
    $searchWord = 'a';
    foreach ( preg_split( '/[^\p{L}\p{N}]+/u', $name ) as $part )
        if ( mb_strlen( $part ) > 3 )
        {
            $searchWord = $part;
            break;
        }
    $plain = $run( 'exp_search', array( 'text' => $searchWord, 'limit' => 50 ) );
    $answer = $run( 'exp_search', array( 'text' => $searchWord, 'limit' => 50 ), $reader );
    $check( 'policy: exp_search leaves the kept-away row out of items', in_array( $denyID, array_column( (array) ( $answer['items'] ?? array() ), 'object_id' ), true ), false );
    $check( 'policy: exp_search withheld what the plain search had', $reader->withheld, in_array( $denyID, array_column( (array) ( $plain['items'] ?? array() ), 'object_id' ), true ) ? 1 : 0 );
    $reader->withheld = 0;
    $answer = $run( 'exp_content_get', array( 'node_id' => $denyNodeID ), $reader );
    $check( 'policy: exp_content_get of a kept-away object is a tool error', $answer['exception'] ?? null, 'kept away by the test policy' );
    $answer = $run( 'exp_content_meta', array( 'action' => 'object', 'object_id' => $denyID ), $reader );
    $check( 'policy: exp_content_meta of a kept-away object is a tool error', $answer['exception'] ?? ( $answer['error'] ?? null ), 'kept away by the test policy' );
    $answer = $run( 'exp_content_versions', array( 'action' => 'list', 'object_id' => $denyID ), $reader );
    $check( 'policy: exp_content_versions of a kept-away object is a tool error', $answer['exception'] ?? null, 'kept away by the test policy' );

    // tool errors
    $answer = $run( 'exp_content_get', array( 'node_id' => $allowedID, 'bogus' => 1 ) );
    $check( 'errors: an argument the tool does not take is a tool error', isset( $answer['exception'] ), true );
    $saved = $GLOBALS['eZCurrentAccess'] ?? null;
    $GLOBALS['eZCurrentAccess']['name'] = 'expai_bridge_test_not_allowed';
    $answer = $run( 'exp_class_list', array() );
    if ( $saved === null )
        unset( $GLOBALS['eZCurrentAccess'] );
    else
        $GLOBALS['eZCurrentAccess'] = $saved;
    $check( 'errors: a siteaccess off expmcp\'s allowlist is a tool error', isset( $answer['exception'] ) || isset( $answer['error'] ), true );
}
catch ( Throwable $e )
{
    $check( 'the run: ' . get_class( $e ) . ': ' . $e->getMessage(), false, true );
}
finally
{
    expAiMcp::$bridgeSeam = null;
    $become( $admin );
    $cleanup();
    $check( 'cleanup: the test group is removed', eZContentObject::fetchByRemoteID( EXPAI_BRIDGE_TEST_GROUP_REMOTE_ID ), null );
    $check( 'cleanup: the test role is removed', eZRole::fetchByName( EXPAI_BRIDGE_TEST_ROLE ) instanceof eZRole, false );
}

echo ( $failures ? 'FAIL' : 'PASS' ) . " $checks checks, $failures failure(s)\n";
$script->shutdown( $failures ? 1 : 0 );
