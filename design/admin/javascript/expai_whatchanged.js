/*!
 * expai - Exponential Intelligence
 * Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * GNU General Public License v2.0 (or any later version)
 *
 * "What changed" of a draft the assistant wrote directly (doc/review.md, "Direct mode: what changed, and revert"):
 * a dialog with the diff of the draft against the version it was based on, part by part and field by field, and
 * Revert for a part or a whole field into the same draft (expairevert::changes, expairevert::revert). Nothing is
 * published. Loaded on every admin page after expai.js (design.ini BackendJavaScriptList[]).
 *
 * Any button with data-exp-ai-what-changed="<object id>:<version>" opens it (the Intelligence tab's draft list, the
 * drafts of a Vector thread). Keys inside the dialog: j / k next / previous part, x revert the part, Shift+X revert
 * the field, Esc close.
 *
 *   ExpAi.whatChanged.open( objectID, version )   -> Promise
 *   ExpAi.on( 'draft:reverted', fn )              { object_id, version, written }
 *   ExpAi.diffView.wordDiff( a, b )               [ left html, right html ] with <del>/<ins> per word
 */
(function ( window, document ) {
    'use strict';

    var ExpAi = window.ExpAi;
    if ( !ExpAi || !ExpAi.api ) {
        return;
    }
    var esc = ExpAi.esc, fmt = ExpAi.format;

    /** word level marks inside a replaced part; long texts are shown whole (the same as Vector's Changes tab) */
    function wordDiff( a, b ) {
        var x = String( a ).split( /(\s+)/ ), y = String( b ).split( /(\s+)/ );
        if ( x.length * y.length > 250000 ) { return [ '<del>' + esc( a ) + '</del>', '<ins>' + esc( b ) + '</ins>' ]; }
        var n = x.length, m = y.length, i, j, L = [];
        for ( i = 0; i <= n; i++ ) { L.push( new Array( m + 1 ).fill( 0 ) ); }
        for ( i = n - 1; i >= 0; i-- ) { for ( j = m - 1; j >= 0; j-- ) { L[i][j] = x[i] === y[j] ? L[i + 1][j + 1] + 1 : Math.max( L[i + 1][j], L[i][j + 1] ); } }
        var left = '', right = '';
        i = 0; j = 0;
        while ( i < n || j < m ) {
            if ( i < n && j < m && x[i] === y[j] ) { left += esc( x[i] ); right += esc( y[j] ); i++; j++; }
            else if ( j < m && ( i >= n || L[i][j + 1] >= L[i + 1][j] ) ) { right += '<ins>' + esc( y[j] ) + '</ins>'; j++; }
            else { left += '<del>' + esc( x[i] ) + '</del>'; i++; }
        }
        return [ left, right ];
    }
    function linesHtml( html, cls, sign ) {
        return String( html ).split( '\n' ).map( function ( line ) {
            return '<div class="exp-ai-wc-line ' + cls + '"><span class="exp-ai-wc-sign" aria-hidden="true">' + sign + '</span><span>' + line + '</span></div>';
        } ).join( '' );
    }
    /** the body of a part: context, what was there (base), what the draft has; every text escaped */
    function hunkBody( h ) {
        var base = h.base_text || '', prop = h.prop_text || '', body = '';
        if ( h.context_before ) { body += linesHtml( esc( h.context_before ), 'exp-ai-wc-ctx', ' ' ); }
        if ( base && prop && base.split( '\n' ).length === prop.split( '\n' ).length ) {
            var bl = base.split( '\n' ), pl = prop.split( '\n' ), dl = '', il = '';
            bl.forEach( function ( line, k ) { var d = wordDiff( line, pl[k] ); dl += linesHtml( d[0], 'exp-ai-wc-del', '-' ); il += linesHtml( d[1], 'exp-ai-wc-ins', '+' ); } );
            body += dl + il;
        } else {
            if ( base ) { body += linesHtml( esc( base ).split( '\n' ).map( function ( l ) { return '<del>' + l + '</del>'; } ).join( '\n' ), 'exp-ai-wc-del', '-' ); }
            if ( prop ) { body += linesHtml( esc( prop ).split( '\n' ).map( function ( l ) { return '<ins>' + l + '</ins>'; } ).join( '\n' ), 'exp-ai-wc-ins', '+' ); }
        }
        if ( h.context_after ) { body += linesHtml( esc( h.context_after ), 'exp-ai-wc-ctx', ' ' ); }
        return body;
    }

    var state = { dialog: null, data: null, S: {}, key: '', active: 0, busy: false };

    function T( key, args ) { return fmt( state.S[key] || key, args || {} ); }

    function dialog() {
        if ( state.dialog ) { return state.dialog; }
        var d = document.createElement( 'dialog' );
        d.className = 'exp-ai exp-ai-wc';
        d.setAttribute( 'aria-labelledby', 'exp-ai-wc-title' );
        d.innerHTML = '<div class="exp-ai-wc-head"><h2 id="exp-ai-wc-title"></h2><button type="button" class="exp-ai-wc-close" data-wc="close"></button></div>'
                    + '<p class="exp-ai-wc-meta"></p><p class="exp-ai-wc-status" role="status" aria-live="polite"></p>'
                    + '<div class="exp-ai-wc-body"></div><p class="exp-ai-wc-keys"></p>';
        document.body.appendChild( d );
        d.addEventListener( 'click', onClick );
        d.addEventListener( 'keydown', onKey );
        d.addEventListener( 'close', function () { state.key = ''; } );
        state.dialog = d;
        return d;
    }

    function hunks() { return Array.prototype.slice.call( dialog().querySelectorAll( '.exp-ai-wc-hunk' ) ); }

    function focusHunk( index ) {
        var list = hunks();
        if ( !list.length ) { return; }
        state.active = Math.max( 0, Math.min( list.length - 1, index ) );
        list.forEach( function ( h, i ) { h.classList.toggle( 'exp-ai-wc-active', i === state.active ); } );
        list[state.active].focus();
        if ( list[state.active].scrollIntoView ) { list[state.active].scrollIntoView( { block: 'nearest' } ); }
    }

    function status( text, kind ) {
        var el = dialog().querySelector( '.exp-ai-wc-status' );
        el.textContent = text || '';
        el.className = 'exp-ai-wc-status' + ( kind ? ' exp-ai-wc-status-' + kind : '' );
    }

    function render() {
        var d = dialog(), c = state.data;
        d.querySelector( '#exp-ai-wc-title' ).textContent = T( 'title' ) + ': ' + ( c.name || ( '#' + c.object_id ) );
        d.querySelector( '.exp-ai-wc-close' ).textContent = T( 'close' );
        var meta = [ c.base_version ? T( 'base', { '%base': c.base_version } ) : T( 'base_none' ), c.language ];
        var links = '<a href="' + esc( ExpAi.url( c.urls.view ) ) + '">' + esc( T( 'view' ) ) + '</a>'
                  + ( c.urls.edit ? ' · <a href="' + esc( ExpAi.url( c.urls.edit ) ) + '">' + esc( T( 'edit' ) ) + '</a>' : '' );
        d.querySelector( '.exp-ai-wc-meta' ).innerHTML = esc( meta.join( ' · ' ) ) + ' · ' + links
            + '<br>' + esc( T( 'intro' ) )
            + ( c.published_newer ? '<br><strong>' + esc( T( 'published_newer', { '%published': c.published_version, '%base': c.base_version } ) ) + '</strong>' : '' )
            + ( c.refusal ? '<br><strong>' + esc( c.refusal ) + '</strong>' : '' );
        var html = '';
        if ( !c.fields.length ) {
            html = '<p class="exp-ai-wc-none">' + esc( T( 'none' ) ) + '</p>';
        }
        c.fields.forEach( function ( f ) {
            html += '<section class="exp-ai-wc-field" data-field="' + esc( f.identifier ) + '"><div class="exp-ai-wc-field-head"><strong>' + esc( f.name ) + '</strong>'
                  + ( c.can_revert ? ' <button type="button" class="exp-ai-wc-btn" data-wc="field">' + esc( T( 'revert_field' ) ) + ' <kbd>Shift+X</kbd></button>' : '' ) + '</div>'
                  + ( f.datatype === 'ezxmltext' ? '<p class="exp-ai-wc-hint">' + esc( T( 'markup' ) ) + '</p>' : '' );
            f.hunks.forEach( function ( h, i ) {
                var label = T( 'part', { '%n': i + 1, '%count': f.hunks.length } );
                html += '<div class="exp-ai-wc-hunk" tabindex="-1" role="group" aria-label="' + esc( f.name + ': ' + label ) + '" data-hunk="' + esc( h.id ) + '">'
                      + '<div class="exp-ai-wc-hunk-head"><span>' + esc( label ) + '</span>'
                      + ( c.can_revert ? '<button type="button" class="exp-ai-wc-btn" data-wc="hunk">' + esc( T( 'revert' ) ) + ' <kbd>x</kbd></button>' : '' ) + '</div>'
                      + '<div class="exp-ai-wc-hunk-body">' + hunkBody( h ) + '</div>'
                      + ( !h.mergeable ? '<p class="exp-ai-wc-hint">' + esc( T( 'not_alone' ) ) + '</p>' : '' ) + '</div>';
            } );
            html += '</section>';
        } );
        d.querySelector( '.exp-ai-wc-body' ).innerHTML = html;
        d.querySelector( '.exp-ai-wc-keys' ).textContent = c.fields.length ? T( 'keys' ) : '';
    }

    function seen() {
        var out = {};
        ( state.data ? state.data.fields : [] ).forEach( function ( f ) { out[f.identifier] = f.hash; } );
        return out;
    }

    function revert( hunkIDs, fields ) {
        if ( state.busy || !state.data || !state.data.can_revert ) { return Promise.resolve(); }
        state.busy = true;
        var c = state.data, keep = state.active;
        status( T( 'loading' ) );
        return ExpAi.api( 'expairevert::revert', {
            object_id: c.object_id, version: c.version, hunks: JSON.stringify( hunkIDs || [] ), fields: JSON.stringify( fields || [] ), seen: JSON.stringify( seen() )
        } ).then( function ( r ) {
            state.data = r.changes;
            render();
            if ( r.status === 'conflict' ) { status( T( 'conflict' ), 'warn' ); }
            else if ( r.status === 'unchanged' ) { status( T( 'unchanged' ) ); }
            else {
                status( T( 'reverted' ), 'ok' );
                ExpAi.emit( 'draft:reverted', { object_id: c.object_id, version: c.version, written: r.written || [] } );
            }
            focusHunk( keep );
            if ( !hunks().length ) { dialog().querySelector( '.exp-ai-wc-close' ).focus(); }
        } ).catch( function ( err ) {
            status( T( 'error' ) + ' ' + ( err && err.message ? err.message : '' ), 'error' );
        } ).then( function () { state.busy = false; } );
    }

    function hunkTarget( h ) {
        if ( !h ) { return; }
        state.active = hunks().indexOf( h );
        return revert( [ h.getAttribute( 'data-hunk' ) ], [] );
    }
    function fieldTarget( section ) {
        if ( !section ) { return; }
        var first = section.querySelector( '.exp-ai-wc-hunk' );
        state.active = first ? hunks().indexOf( first ) : state.active;
        return revert( [], [ section.getAttribute( 'data-field' ) ] );
    }

    function onClick( e ) {
        var b = e.target.closest ? e.target.closest( '[data-wc]' ) : null;
        if ( !b ) {
            var h = e.target.closest ? e.target.closest( '.exp-ai-wc-hunk' ) : null;
            if ( h ) { focusHunk( hunks().indexOf( h ) ); }
            return;
        }
        var what = b.getAttribute( 'data-wc' );
        if ( what === 'close' ) { dialog().close(); return; }
        if ( what === 'hunk' ) { hunkTarget( b.closest( '.exp-ai-wc-hunk' ) ); return; }
        if ( what === 'field' ) { fieldTarget( b.closest( '.exp-ai-wc-field' ) ); }
    }

    function onKey( e ) {
        // the page's own keys (Vector, the palette) do not act while the dialog is open
        e.stopPropagation();
        if ( e.ctrlKey || e.metaKey || e.altKey ) { return; }
        var tag = e.target && e.target.tagName ? e.target.tagName.toLowerCase() : '';
        if ( tag === 'input' || tag === 'textarea' || tag === 'select' ) { return; }
        var list = hunks(), current = list[state.active];
        if ( e.key === 'j' || e.key === 'k' ) {
            e.preventDefault();
            e.stopPropagation();
            focusHunk( state.active + ( e.key === 'j' ? 1 : -1 ) );
        } else if ( e.key === 'x' && !e.shiftKey ) {
            e.preventDefault();
            e.stopPropagation();
            hunkTarget( current );
        } else if ( e.key === 'X' && e.shiftKey ) {
            e.preventDefault();
            e.stopPropagation();
            fieldTarget( current ? current.closest( '.exp-ai-wc-field' ) : null );
        } else if ( e.key === 'Escape' ) {
            e.stopPropagation();
        }
    }

    function open( objectID, version ) {
        var d = dialog();
        state.key = objectID + ':' + version;
        state.data = null;
        state.active = 0;
        d.querySelector( '.exp-ai-wc-body' ).innerHTML = '';
        d.querySelector( '.exp-ai-wc-meta' ).textContent = '';
        d.querySelector( '.exp-ai-wc-keys' ).textContent = '';
        status( state.S.loading || '…' );
        if ( !d.open ) { d.showModal ? d.showModal() : d.setAttribute( 'open', 'open' ); }
        return ExpAi.api( 'expairevert::changes', { object_id: objectID, version: version } ).then( function ( c ) {
            state.S = c.strings || state.S;
            state.data = c;
            render();
            status( '' );
            if ( hunks().length ) { focusHunk( 0 ); } else { d.querySelector( '.exp-ai-wc-close' ).focus(); }
        } ).catch( function ( err ) {
            d.querySelector( '.exp-ai-wc-close' ).textContent = state.S.close || '×';
            status( ( state.S.error || '' ) + ' ' + ( err && err.message ? err.message : '' ), 'error' );
        } );
    }

    document.addEventListener( 'click', function ( e ) {
        var b = e.target.closest ? e.target.closest( '[data-exp-ai-what-changed]' ) : null;
        if ( !b ) { return; }
        e.preventDefault();
        var parts = String( b.getAttribute( 'data-exp-ai-what-changed' ) ).split( ':' );
        open( parseInt( parts[0], 10 ), parseInt( parts[1], 10 ) );
    } );

    ExpAi.whatChanged = { open: open };
    ExpAi.diffView = { wordDiff: wordDiff, hunkBody: hunkBody };
})( window, document );
