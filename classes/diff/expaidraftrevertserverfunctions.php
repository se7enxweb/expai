<?php
/**
 * File containing the expAiDraftRevertServerFunctions class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * "What changed" of a draft written directly (ezjscore.ini [ezjscServer_expairevert], doc/review.md):
 *
 *   expairevert::changes  object_id, version                   -> the diff against the base version (read only)
 *   expairevert::revert   object_id, version, hunks (JSON list), fields (JSON list), seen (JSON identifier => hash)
 *                         -> status reverted | conflict | unchanged, and the diff as it is now
 *
 * Both check POST, the form token and the policy expai/write; expAiDraftRevert checks that the draft is the
 * editor's (a thread's: its owner's), and revert checks content/edit in the draft's language. Nothing is published.
 */
class expAiDraftRevertServerFunctions extends ezjscServerFunctions
{
    /** @return string */
    protected static function post( $name, $default = '' )
    {
        return isset( $_POST[$name] ) && is_scalar( $_POST[$name] ) ? (string) $_POST[$name] : $default;
    }

    /** @return array a JSON value of the request, as an array */
    protected static function json( $name )
    {
        $value = json_decode( self::post( $name, '' ), true );
        return is_array( $value ) ? $value : array();
    }

    /** @throws expAiException */
    protected static function guard()
    {
        if ( !isset( $_SERVER['REQUEST_METHOD'] ) || strtoupper( $_SERVER['REQUEST_METHOD'] ) !== 'POST' )
            throw ( new expAiException( 'Exponential Intelligence only accepts POST requests.' ) )->translated();
        if ( class_exists( 'ezxFormToken' ) && ezxFormToken::isEnabled() )
        {
            $given = self::post( 'ezxform_token', isset( $_SERVER['HTTP_X_CSRF_TOKEN'] ) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : '' );
            if ( $given === '' || !hash_equals( (string) ezxFormToken::getToken(), $given ) )
                throw ( new expAiException( 'The form token is missing or wrong. Please reload the page.' ) )->translated();
        }
        if ( !expAiServerFunctions::hasPolicy( 'write' ) )
            throw ( new expAiException( 'You do not have access to Exponential Intelligence.' ) )->translated();
    }

    /** @return expAiDraftRevert */
    protected static function draft()
    {
        return expAiDraftRevert::forDraft( (int) self::post( 'object_id', '0' ), (int) self::post( 'version', '0' ) );
    }

    /** changes: what the draft changed against the version it was based on */
    public static function changes( $args )
    {
        self::guard();
        try
        {
            return self::draft()->changes() + array( 'strings' => self::strings() );
        }
        catch ( expAiException $e )
        {
            throw $e->translated();
        }
    }

    /** revert: parts and whole fields back to the base, into the same draft */
    public static function revert( $args )
    {
        self::guard();
        try
        {
            $seen = array();
            foreach ( self::json( 'seen' ) as $identifier => $hash )
                if ( is_scalar( $hash ) )
                    $seen[(string) $identifier] = (string) $hash;
            $hunks = array_filter( self::json( 'hunks' ), 'is_scalar' );
            $fields = array_filter( self::json( 'fields' ), 'is_scalar' );
            return self::draft()->revert( array_slice( $hunks, 0, 500 ), array_slice( $fields, 0, 100 ), $seen );
        }
        catch ( expAiException $e )
        {
            throw $e->translated();
        }
    }

    /** @return array the texts of the dialog, in the interface language */
    public static function strings()
    {
        $t = function ( $text ) { return ezpI18n::tr( 'extension/expai/revert', $text ); };
        return array(
            'title' => $t( 'What changed' ),
            'intro' => $t( 'The draft against the version it was based on. Revert takes a part back into this draft; the published version stays as it is.' ),
            'base' => $t( 'Compared with version %base' ),
            'base_none' => $t( 'Compared with an empty object (the draft is new)' ),
            'published_newer' => $t( 'Version %published was published after this draft was written; reverting brings back the text of version %base.' ),
            'none' => $t( 'This draft changes nothing in the fields Exponential Intelligence writes.' ),
            'part' => $t( 'Part %n of %count' ),
            'revert' => $t( 'Revert' ),
            'revert_field' => $t( 'Revert field' ),
            'not_alone' => $t( 'This part cannot be taken back alone; revert the whole field.' ),
            'markup' => $t( 'Rich text is shown as text; links, formatting and embeds are kept.' ),
            'reverted' => $t( 'Reverted. The draft was updated; the published version is unchanged.' ),
            'unchanged' => $t( 'Nothing to revert: the draft already has this text.' ),
            'conflict' => $t( 'The draft changed since you opened this. Here is what it changes now; check it and revert again.' ),
            'close' => $t( 'Close' ),
            'edit' => $t( 'Edit draft' ),
            'view' => $t( 'View version' ),
            'keys' => $t( 'j / k: next / previous part · x: revert the part · Shift+X: revert the field · Esc: close' ),
            'loading' => $t( 'Loading...' ),
            'error' => $t( 'Something went wrong:' ),
        );
    }
}
