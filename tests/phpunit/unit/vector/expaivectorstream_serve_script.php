<?php
/**
 * Run by expAiVectorStreamServeTest in a process of its own (the stream drops every output buffer, also PHPUnit's):
 * a thread of the administrator with two events, then expAiVectorStream::serve() for [VectorSettings]
 * StreamSeconds=5 after the first event. The thread is removed at the end. The test reads the stream from the output.
 *   php bin/php/ezexec.php extension/expai/tests/phpunit/unit/vector/expaivectorstream_serve_script.php -s admin
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

$admin = eZUser::fetchByName( 'admin' );
eZUser::setCurrentlyLoggedInUser( $admin, (int) $admin->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
eZINI::instance( 'expai.ini' )->setVariable( 'VectorSettings', 'StreamSeconds', 5 );
$thread = expAiThread::create( array( 'user_id' => (int) $admin->attribute( 'contentobject_id' ), 'title' => 'expai unit test stream', 'scope' => array() ) );
try
{
    $first = $thread->addEvent( 'status', array( 'text' => 'first, before the cursor' ) );
    $thread->addEvent( 'text', array( 'text' => 'second, streamed' ) );
    $thread->store();
    echo 'THREAD ' . $thread->attribute( 'uuid' ) . "\n";
    expAiVectorStream::serve( $thread, (int) $first->attribute( 'id' ) );
    echo "\nEND OF STREAM\n";
}
finally
{
    $thread->removeAll( true );
}
echo ( expAiThread::fetchByID( (int) $thread->attribute( 'id' ) ) ? 'FAIL  ' : 'ok    ' ) . "the thread of the stream is removed\n";
echo "PASS\n";
