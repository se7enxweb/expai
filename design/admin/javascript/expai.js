/*!
 * expai - Exponential Intelligence
 * Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * GNU General Public License v2.0 (or any later version)
 *
 * Loaded on every admin page (design.ini [JavaScriptSettings] BackendJavaScriptList[]=expai.js). Plain JavaScript:
 * it needs neither jQuery nor ezjscore's jqueryio ($.ez). Three parts, each active only where its markup is:
 *
 *   ExpAi (core)   window.ExpAi: api() over ezjscore/call (fetch), events, field adapters, field action hooks, panel sections
 *   labels         the "Exponential Intelligence" label on versions the assistant wrote (draft lists, version
 *                  views, edit forms, approval items)
 *   panel          the full view panel (#exp-ai-panel)
 *   edit           the edit form controls (#exp-ai-edit) and the field actions
 *
 * Extension points (doc/extending.md):
 *   ExpAi.on( event, fn )                          events: panel:step, panel:done, drafts:changed, field:filled,
 *                                                  fill:done, labels:done
 *   ExpAi.fieldAdapters.register( datatype, { get( ctx ), set( ctx, value ) } )
 *   ExpAi.fieldActions.register( name, { before( ctx, params ), after( ctx, result ) } )
 *   ExpAi.guardedApply( { ctx, adapter, value, sent, host, strings, apply, kept } )   write unless structure would be
 *                                                  lost or the field changed meanwhile (expai_structure.js)
 *   ExpAi.panelSections.register( name, function ( panel ) { ... } )
 *   ExpAi.api( 'function', data ) -> Promise of the content of expai::<function>
 */
(function ( window, document ) {
    'use strict';

    /* ------------------------------------------------------------------ core */

    var listeners = {};

    function token() {
        var node = document.getElementById( 'ezxform_token_js' );
        if ( node ) {
            return node.getAttribute( 'title' ) || '';
        }
        var input = document.querySelector( 'input[name="ezxform_token"]' );
        return input ? input.value : '';
    }

    /** The Exp configuration blocks ({exp_config()}, written by page_head_exp.tpl in the <head> of the admin designs) */
    function configBlocks() {
        return document.querySelectorAll( 'script[type="application/json"]#exp-config, script[type="application/json"][data-exp-config]' );
    }

    /** The installation's root: ezjscore's $.ez where a page still loads it, else the Exp configuration block of the admin <head> */
    function rootUrl() {
        var jq = window.jQuery;
        if ( jq && jq.ez && jq.ez.root_url ) {
            return jq.ez.root_url;
        }
        var root = '';
        Array.prototype.forEach.call( configBlocks(), function ( block ) {
            try {
                var data = JSON.parse( block.textContent );
                if ( !root && data && data.config && data.config.root ) {
                    root = String( data.config.root );
                }
            } catch ( e ) {}
        } );
        return root || '/';
    }

    /** Shallow copy of the sources into target, skipping undefined values (as jQuery.extend did) */
    function extend( target ) {
        for ( var i = 1; i < arguments.length; i++ ) {
            var source = arguments[i];
            if ( source === null || source === undefined ) {
                continue;
            }
            for ( var key in source ) {
                if ( source[key] !== undefined ) {
                    target[key] = source[key];
                }
            }
        }
        return target;
    }

    /** Form encoding of an object, nested objects and arrays as key[sub] and key[] (as jQuery.param, which PHP reads back) */
    function param( data ) {
        var out = [];
        var add = function ( key, value ) {
            value = typeof value === 'function' ? value() : value;
            out.push( encodeURIComponent( key ) + '=' + encodeURIComponent( value === null || value === undefined ? '' : value ) );
        };
        var build = function ( prefix, value ) {
            if ( Array.isArray( value ) ) {
                value.forEach( function ( v, i ) {
                    if ( /\[\]$/.test( prefix ) ) {
                        add( prefix, v );
                    } else {
                        build( prefix + '[' + ( typeof v === 'object' && v !== null ? i : '' ) + ']', v );
                    }
                } );
            } else if ( Object.prototype.toString.call( value ) === '[object Object]' ) {
                for ( var name in value ) {
                    build( prefix + '[' + name + ']', value[name] );
                }
            } else {
                add( prefix, value );
            }
        };
        for ( var key in data ) {
            build( key, data[key] );
        }
        return out.join( '&' ).replace( /%20/g, '+' );
    }

    /** POST expai::<fn> (or <group>::<fn>, e.g. expaivector::threads) through ezjscore; resolves with the content, rejects with { message } */
    function api( fn, data ) {
        var post = extend( {}, data || {} );
        post.ezjscServer_function_arguments = String( fn ).indexOf( '::' ) > 0 ? fn : 'expai::' + fn;
        post.ezxform_token = token();
        return new Promise( function ( resolve, reject ) {
            var failed = function ( status, code, text ) {
                // an HTML page where JSON was asked for (status 200, parsererror): the login page of a lost session
                var loginPage = status === 'parsererror' && /^\s*</.test( String( text || '' ) );
                reject( { message: status === 'timeout' ? '' : ( code ? 'HTTP ' + code : '' ),
                          kind: status === 'timeout' || code === 504 || code === 502 ? 'timeout' : 'http',
                          status: code,
                          // 401/403, or the login page instead of JSON: the session is gone (or the permission); polling again cannot help
                          expired: code === 401 || code === 403 || loginPage } );
            };
            var abort = window.AbortController ? new window.AbortController() : null;
            var timedOut = false;
            var timer = window.setTimeout( function () {
                timedOut = true;
                if ( abort ) {
                    abort.abort();
                }
                failed( 'timeout', 0, '' );
            }, 180000 );
            window.fetch( rootUrl() + 'ezjscore/call', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json, text/javascript, */*; q=0.01',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': post.ezxform_token
                },
                body: param( post ),
                signal: abort ? abort.signal : undefined
            } ).then( function ( response ) {
                return response.text().then( function ( text ) {
                    window.clearTimeout( timer );
                    if ( timedOut ) {
                        return;
                    }
                    var code = response.status;
                    if ( !( code >= 200 && code < 300 || code === 304 ) ) {
                        failed( 'error', code, text );
                        return;
                    }
                    if ( code === 204 ) {
                        reject( { message: '', kind: 'invalid' } );
                        return;
                    }
                    var json;
                    try {
                        json = JSON.parse( text );
                    } catch ( e ) {
                        failed( 'parsererror', code, text );
                        return;
                    }
                    if ( !json || typeof json !== 'object' ) {
                        reject( { message: '', kind: 'invalid' } );
                    } else if ( json.error_text ) {
                        reject( { message: String( json.error_text ), kind: 'server' } );
                    } else {
                        resolve( json.content );
                    }
                } );
            } ).catch( function () {
                window.clearTimeout( timer );
                if ( !timedOut ) {
                    failed( 'error', 0, '' );
                }
            } );
        } );
    }

    /**
     * The bookkeeping of a polling loop. fail( err ) says whether to go on: after five failures in a row, or at once
     * when the session has expired, it answers { stop: true, error: <the error to show> }; otherwise { delay } grows
     * from the base interval (doubling, at most 60 s, like expai_watch.js). ok() resets it. whenVisible( fn ) runs fn
     * now, or when the tab is shown again: a hidden tab does not poll.
     */
    function pollGuard( baseMs, strings ) {
        var failures = 0;
        var texts = strings || {};
        return {
            ok: function () { failures = 0; },
            fail: function ( err ) {
                failures++;
                if ( err && err.expired ) {
                    return { stop: true, error: { message: texts.session_expired || 'Your session has expired. Reload the page and sign in again.', kind: 'http', expired: true } };
                }
                if ( failures >= 5 ) {
                    return { stop: true, error: { message: texts.poll_lost || 'The connection to the run was lost. Reload the page to follow it.', kind: 'http', lost: true } };
                }
                return { stop: false, delay: Math.min( baseMs * Math.pow( 2, failures ), 60000 ) };
            },
            whenVisible: function ( fn ) {
                if ( !document.hidden ) {
                    fn();
                    return;
                }
                var onShow = function () {
                    if ( !document.hidden ) {
                        document.removeEventListener( 'visibilitychange', onShow );
                        fn();
                    }
                };
                document.addEventListener( 'visibilitychange', onShow );
            }
        };
    }

    function on( event, fn ) {
        ( listeners[event] = listeners[event] || [] ).push( fn );
    }

    function emit( event, payload ) {
        ( listeners[event] || [] ).forEach( function ( fn ) {
            try { fn( payload ); } catch ( e ) { if ( window.console ) { window.console.error( e ); } }
        } );
    }

    function esc( text ) {
        return String( text === undefined || text === null ? '' : text )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
    }

    function fmt( text, args ) {
        text = String( text || '' );
        Object.keys( args || {} ).forEach( function ( key ) {
            text = text.split( key ).join( String( args[key] ) );
        } );
        return text;
    }

    /**
     * The key under which this browser remembers the palette's state for the signed-in user: a shared browser must not
     * carry one user's "off" or key bindings over to the next. admin4's user menu names the login of the user
     * (.a4-user-menu-login, also while the menu is inactive); a design without it gets the plain key.
     */
    function paletteStoreKey() {
        var login = document.querySelector( '#a4-user-menu .a4-user-menu-login' );
        var who = login ? login.textContent.trim() : '';
        return 'exp-ai-palette-keys' + ( who ? ':' + who : '' );
    }

    /** The same text without its Markdown marks, on one line: the collapsed header of an exchange shows this, not "## **Title**" */
    function plainText( text ) {
        return String( text || '' ).replace( /\r/g, '' )
            .replace( /```[a-z]*\n?/gi, '' )
            .replace( /!?\[([^\]]*)\]\([^)]*\)/g, '$1' )
            .replace( /^\s{0,3}(#{1,6}\s+|>\s?|[-*+]\s+|\d+[.)]\s+|[-*_]{3,}\s*$)/gm, '' )
            .replace( /(\*\*|__)(.+?)\1/g, '$2' )
            .replace( /(^|[\s(])[*_]([^*_\n]+)[*_](?=[\s).,;:!?]|$)/g, '$1$2' )
            .replace( /`([^`]*)`/g, '$1' )
            .replace( /\|/g, ' ' )
            .replace( /\s+/g, ' ' ).trim();
    }

    /** Assistant text (plain with a little Markdown) as safe HTML */
    function renderText( text ) {
        var blocks = String( text || '' ).replace( /\r/g, '' ).split( /\n\s*\n/ );
        return blocks.map( function ( block ) {
            var lines = block.split( '\n' ).filter( function ( l ) { return l.trim() !== ''; } );
            if ( !lines.length ) {
                return '';
            }
            var inline = function ( s ) {
                return esc( s ).replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' ).replace( /`([^`]+)`/g, '<code>$1</code>' );
            };
            if ( lines.every( function ( l ) { return /^\s*([-*]|\d+[.)])\s+/.test( l ); } ) ) {
                var ordered = /^\s*\d/.test( lines[0] );
                return ( ordered ? '<ol>' : '<ul>' ) + lines.map( function ( l ) {
                    return '<li>' + inline( l.replace( /^\s*([-*]|\d+[.)])\s+/, '' ) ) + '</li>';
                } ).join( '' ) + ( ordered ? '</ol>' : '</ul>' );
            }
            if ( lines.length === 1 && /^#{1,4}\s+/.test( lines[0] ) ) {
                return '<p><strong>' + inline( lines[0].replace( /^#{1,4}\s+/, '' ) ) + '</strong></p>';
            }
            return '<p>' + lines.map( inline ).join( '<br>' ) + '</p>';
        } ).join( '' );
    }

    function readJSON( root, selector ) {
        var node = root.querySelector( selector );
        if ( !node ) {
            return {};
        }
        try {
            return JSON.parse( node.textContent ) || {};
        } catch ( e ) {
            if ( window.console && console.warn ) {
                console.warn( 'expai: the configuration block ' + selector + ' is not valid JSON', e );
            }
            return {};
        }
    }

    function url( path ) {
        return rootUrl() + String( path ).replace( /^\/+/, '' );
    }

    /* -------------------------------------------------------- field adapters */

    function fieldInput( ctx ) {
        var id = ctx.attribute.id;
        var block = ctx.block;
        var candidates = block ? block.querySelectorAll( 'input[type="text"], input:not([type]), textarea' ) : [];
        for ( var i = 0; i < candidates.length; i++ ) {
            var name = candidates[i].getAttribute( 'name' ) || '';
            if ( name.slice( -( String( id ).length + 1 ) ) === '_' + id ) {
                return candidates[i];
            }
        }
        return null;
    }

    function setNative( el, value ) {
        el.value = value;
        el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
        el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
    }

    var adapters = {
        _default: {
            get: function ( ctx ) { var el = fieldInput( ctx ); return el ? el.value : ''; },
            set: function ( ctx, value ) { var el = fieldInput( ctx ); if ( el ) { setNative( el, value ); return true; } return false; }
        },
        ezxmltext: {
            get: function ( ctx ) {
                var el = fieldInput( ctx );
                if ( !el ) {
                    return '';
                }
                var tiptap = window.ExpOETiptap && window.ExpOETiptap.get ? window.ExpOETiptap.get( el ) : null;
                if ( tiptap && tiptap.getHTML ) {
                    return tiptap.getHTML();
                }
                var mce = window.tinyMCE && window.tinyMCE.get ? window.tinyMCE.get( el.id ) : null;
                if ( mce && mce.getContent ) {
                    return mce.getContent();
                }
                return el.value;
            },
            set: function ( ctx, value ) {
                var el = fieldInput( ctx );
                if ( !el ) {
                    return false;
                }
                var tiptap = window.ExpOETiptap && window.ExpOETiptap.get ? window.ExpOETiptap.get( el ) : null;
                if ( tiptap && tiptap.editor && tiptap.editor.commands ) {
                    tiptap.editor.commands.setContent( value );
                    tiptap.dirty = true;
                    if ( tiptap.sync ) {
                        tiptap.sync();
                    }
                    return true;
                }
                var mce = window.tinyMCE && window.tinyMCE.get ? window.tinyMCE.get( el.id ) : null;
                if ( mce && mce.setContent ) {
                    mce.setContent( value );
                    if ( mce.save ) {
                        mce.save();
                    }
                    if ( mce.nodeChanged ) {
                        mce.nodeChanged();
                    }
                    el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
                    return true;
                }
                setNative( el, value );
                return true;
            }
        }
    };

    function adapterFor( datatype ) {
        return adapters[datatype] || adapters._default;
    }

    /* ------------------------------------------- editors with an assistant of their own */

    // An editor "has its own structure-safe AI" when it has an assistant that keeps what the field actions cannot
    // (formulas, embeds, tables ...). Each detector gets the field's textarea and answers true or false; the field
    // actions are hidden above such a field ([EditViewSettings] HideActionsWithEditorAI). The editor is asked live,
    // so an editor started later, switched or removed is picked up by the observer in edit().
    var ownAIDetectors = {
        // Tiptap (exp_oe_tiptap) with its AI panel switched on in the editor's options
        tiptap: function ( el ) {
            var tiptap = window.ExpOETiptap && window.ExpOETiptap.get ? window.ExpOETiptap.get( el ) : null;
            return !!( tiptap && tiptap.options && tiptap.options.ai && tiptap.options.ai.enabled === true );
        }
    };

    function editorHasOwnAI( el ) {
        return !!el && Object.keys( ownAIDetectors ).some( function ( name ) {
            try { return !!ownAIDetectors[name]( el ); } catch ( e ) { return false; }
        } );
    }

    // the longest value the server reads ([EditViewSettings] MaxFieldLength); set by edit()
    var limits = { fieldLength: 0 };

    // the raw INI value clamped as the server does (expAiPromptFieldAction::maxValueLength): default 20000, 1000 to 200000
    function fieldLengthLimit( raw ) {
        var n = /^\s*-?\d+(\.\d+)?\s*$/.test( String( raw === undefined || raw === null ? '' : raw ) ) ? parseInt( raw, 10 ) : 20000;
        return Math.min( 200000, Math.max( 1000, n ) );
    }

    /* --------------------------------------------- structure and stale guard */

    var structureWarned = false;

    function clearReview( host ) {
        Array.prototype.forEach.call( host ? host.querySelectorAll( ':scope > .exp-ai-review' ) : [], function ( box ) { box.remove(); } );
    }

    /**
     * Puts an answer into a field unless that would lose structure or overwrite a change (doc/edit-view-integration.md
     * "Structure is never lost silently"). A rich text field (ezxmltext, not a part of a field) whose answer has fewer
     * embedded objects, tables, table cells, header cells, merged cells, custom tags, anchors, literals, internal links
     * or formatting and link attributes than the field now has (ExpAiStructure), every rich text answer when the
     * detector is missing, and any field that changed since the request was sent, is not written: a review in `host`
     * shows the answer, what would be lost or that the field changed, and "Apply anyway", "Keep the original" and,
     * with the clipboard API, "Copy text". Only "Apply anyway" writes.
     *
     * A field longer than the limit (o.limit, else ExpAi.limits.fieldLength) was cut when it was sent: the answer was
     * made from its beginning only, so it is shown for review too (review_truncated).
     *
     * o: { ctx, adapter, value (the answer), sent (the field's value when the request was sent; undefined: no check),
     *      host (element the review goes into), strings, apply() (writes, returns false when it could not),
     *      kept() (optional, after "Keep the original") }
     * @return { written: bool, review: bool }
     */
    function guardedApply( o ) {
        var S = o.strings || {};
        var Structure = window.ExpAiStructure;
        var current = o.adapter.get( o.ctx );
        var rich = !!( o.ctx.attribute && o.ctx.attribute.datatype === 'ezxmltext' && !o.ctx.part );
        var stale = o.sent !== undefined && o.sent !== null && !( Structure ? Structure.same( current, o.sent ) : current === o.sent );
        var limit = o.limit || limits.fieldLength;
        var truncated = !!limit && typeof o.sent === 'string' && o.sent.length > limit;
        var lost = rich && Structure ? Structure.loss( current, o.value ) : [];
        // without the detector rich text is never overwritten unseen
        var unchecked = rich && !Structure;
        if ( unchecked && !structureWarned && window.console ) {
            structureWarned = true;
            window.console.warn( 'expai: expai_structure.js is not loaded; answers to rich text fields are shown for review' );
        }
        clearReview( o.host );
        if ( !stale && !lost.length && !unchecked && !truncated ) {
            return { written: o.apply() !== false, review: false };
        }
        var text = Structure ? Structure.toText( o.value ) : String( o.value || '' );
        var reasons = [];
        if ( stale ) {
            reasons.push( S.review_changed );
        }
        if ( truncated ) {
            reasons.push( fmt( S.review_truncated, { '%count': limit } ) );
        }
        if ( lost.length ) {
            reasons.push( fmt( S.review_lost, { '%list': Structure.describe( lost, S ) } ) );
        }
        if ( unchecked ) {
            reasons.push( S.review_unchecked );
        }
        var clipboard = window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText;
        var box = document.createElement( 'div' );
        box.className = 'exp-ai exp-ai-review';
        box.setAttribute( 'role', 'group' );
        box.setAttribute( 'aria-label', S.review_title || '' );
        box.innerHTML = '<p class="exp-ai-review-reason"><strong>' + esc( S.review_title ) + ':</strong> ' + reasons.map( esc ).join( ' ' ) + '</p>'
            + '<div class="exp-ai-review-text">' + renderText( text ) + '</div>'
            + '<p class="exp-ai-review-actions">'
            + '<input type="button" class="button exp-ai-btn exp-ai-review-apply" value="' + esc( S.apply_anyway ) + '" /> '
            + '<input type="button" class="defaultbutton exp-ai-btn exp-ai-review-keep" value="' + esc( S.keep_original ) + '" />'
            + ( clipboard ? ' <button type="button" class="exp-ai-link exp-ai-review-copy">' + esc( S.copy_text ) + '</button>' : '' )
            + '</p>';
        o.host.appendChild( box );
        box.querySelector( '.exp-ai-review-apply' ).addEventListener( 'click', function () {
            box.remove();
            o.apply();
        } );
        box.querySelector( '.exp-ai-review-keep' ).addEventListener( 'click', function () {
            box.remove();
            if ( o.kept ) {
                o.kept();
            }
        } );
        var copy = box.querySelector( '.exp-ai-review-copy' );
        if ( copy ) {
            copy.addEventListener( 'click', function () {
                window.navigator.clipboard.writeText( text ).then( function () {
                    copy.textContent = S.copied;
                }, function () {
                    copy.textContent = S.copy_failed;
                } );
            } );
        }
        return { written: false, review: true };
    }

    var fieldActionHooks = {};
    var panelSectionInits = {};

    var ExpAi = window.ExpAi = {
        version: '0.1.0',
        api: api,
        on: on,
        emit: emit,
        esc: esc,
        format: fmt,
        renderText: renderText,
        url: url,
        fieldAdapters: {
            register: function ( datatype, adapter ) { adapters[datatype] = adapter; },
            get: adapterFor
        },
        guardedApply: guardedApply,
        limits: limits,
        ownAIEditors: {
            register: function ( name, detect ) { ownAIDetectors[name] = detect; },
            has: editorHasOwnAI
        },
        clearReview: clearReview,
        fieldActions: {
            register: function ( name, hooks ) { fieldActionHooks[name] = hooks || {}; }
        },
        panelSections: {
            register: function ( name, init ) { panelSectionInits[name] = init; }
        },
        // the command palette's registry (doc/keyboard.md); commands registered before the palette is loaded wait here
        commands: {
            pending: [],
            register: function ( id, def ) { this.pending.push( [ id, def ] ); }
        },
        slowStatus: slowStatus,
        turnRecord: turnRecord,
        watchChip: watchChip,
        requestLinks: requestLinks,
        pollGuard: pollGuard,
        readJSON: readJSON,
        plainText: plainText,
        paletteStoreKey: paletteStoreKey
    };

    /**
     * "View record" (and "View job" when the request ran as a job) after a field action, a preset or an Improve entry:
     * links to the request's row in the usage log and its job, in a new tab so the unsaved form stays.
     * record: expAiEditForm::requestRecord() or null (nothing).
     */
    function requestLinks( record ) {
        if ( !record || !record.log ) {
            return '';
        }
        var t = record.texts || {};
        var link = function ( path, text, title ) {
            return '<a class="exp-ai-link exp-ai-request-link" href="' + esc( url( path ) ) + '" target="_blank" rel="noopener" title="' + esc( title || '' )
                 + '" aria-label="' + esc( ( text || '' ) + ( title ? ' - ' + title : '' ) ) + '">' + esc( text || 'View record' ) + '</a>';
        };
        return ' <span class="exp-ai-request-links">' + link( record.log, t.log, t.log_title )
             + ( record.job ? ' · ' + link( record.job, t.job || 'View job', t.job_title ) : '' ) + '</span>';
    }

    /**
     * The record of a turn (expAiTurnRecord, doc/parallel-runs.md "The record of a turn"), collapsed to one line: the
     * model that answered, steps, duration, tokens, cost, result; open: the fallback, each step with its duration,
     * tools, tokens and cost and a link to its request in the log, and the links of the turn.
     * @return string HTML ('' without a record)
     */
    function turnRecord( r ) {
        if ( !r ) {
            return '';
        }
        var t = r.texts || {};
        var steps = ( r.steps || [] ).map( function ( s, i ) {
            return '<li class="exp-ai-record-step' + ( s.error ? ' exp-ai-record-error' : '' ) + '">' + ( i + 1 ) + '. ' + esc( [ s.model, s.duration, s.tools, s.tokens + '', s.cost, s.error ].filter( Boolean ).join( ' · ' ) )
                 + ' <a href="' + esc( url( s.log ) ) + '">' + esc( t.request || 'Request' ) + '</a></li>';
        } ).join( '' );
        var links = [ [ r.links && r.links.log, t.log ], [ r.links && r.links.job, t.job ], [ r.links && r.links.vector, t.vector ] ].filter( function ( l ) { return l[0]; } )
            .map( function ( l ) { return '<a href="' + esc( url( l[0] ) ) + '">' + esc( l[1] ) + '</a>'; } ).join( ' · ' );
        return '<details class="exp-ai-record exp-ai-record-' + esc( r.result ) + '"><summary title="' + esc( t.record || '' ) + '">' + esc( r.line ) + '</summary>'
             + ( r.fallback_text ? '<p class="exp-ai-record-fallback">' + esc( r.fallback_text ) + '</p>' : '' )
             + ( steps ? '<ol class="exp-ai-record-steps">' + steps + '</ol>' : '' )
             + ( links ? '<p class="exp-ai-record-links">' + links + '</p>' : '' ) + '</details>';
    }

    /**
     * "Watch this run" right after a turn was accepted: a chip with its live state (queued, running step k, done,
     * failed, stopped) and the links to its live record (its requests in the log, its job, the thread in Vector).
     * box: an element (.exp-ai-watch); watch: expAiTurnRecord::watch() or null (hides it); skip: link kinds to leave out.
     */
    function watchChip( box, watch, skip ) {
        if ( !box ) {
            return;
        }
        box.hidden = !watch;
        if ( !watch ) {
            return;
        }
        var t = watch.texts || {};
        skip = skip || [];
        var links = [ [ 'log', t.log ], [ 'job', t.job ], [ 'vector', t.vector ] ].filter( function ( l ) { return watch.links[l[0]] && skip.indexOf( l[0] ) === -1; } )
            .map( function ( l ) { return '<a href="' + esc( url( watch.links[l[0]] ) ) + '">' + esc( l[1] ) + '</a>'; } ).join( ' · ' );
        box.innerHTML = '<span class="exp-ai-watch-title">' + esc( t.watch || '' ) + ' →</span> <span class="exp-ai-watch-chip exp-ai-watch-' + esc( watch.state ) + '">'
                      + esc( watch.label ) + '</span>' + ( links ? ' <span class="exp-ai-watch-links">' + links + '</span>' : '' );
    }

    /**
     * A running turn on a slow model (doc/parallel-runs.md "Slow models"), in the Intelligence tab and in Vector: the
     * line "Running on <model> - about N s per step (step k)" with the time elapsed (a timer of its own) and the button
     * "Switch to <model>". box: an element with .exp-ai-slow-text, .exp-ai-slow-time, .exp-ai-slow-switch; slow: the
     * status of expaivector::events (null hides it); onSwitch( slow ) runs on the button.
     */
    function slowStatus( box, slow, onSwitch ) {
        if ( !box ) {
            return;
        }
        window.clearInterval( box.expAiTimer );
        box.hidden = !slow;
        if ( !slow ) {
            return;
        }
        var text = box.querySelector( '.exp-ai-slow-text' ), time = box.querySelector( '.exp-ai-slow-time' ), button = box.querySelector( '.exp-ai-slow-switch' );
        if ( text ) { text.textContent = slow.text || ''; }
        if ( button ) {
            button.hidden = !slow.switch_to;
            button.textContent = slow.button || '';
            button.disabled = false;
            button.onclick = function () { button.disabled = true; onSwitch( slow ); };
        }
        var tick = function () {
            var s = Math.max( 0, Math.round( Date.now() / 1000 - ( slow.since || 0 ) ) );
            if ( time ) { time.textContent = '· ' + Math.floor( s / 60 ) + ':' + ( '0' + ( s % 60 ) ).slice( -2 ); }
        };
        tick();
        box.expAiTimer = window.setInterval( tick, 1000 );
    }

    /* ---------------------------------------------------------------- labels */

    var VERSION_LINK = /\/content\/(?:versionview|edit)\/(\d+)\/(\d+)(?:[\/?#]|$)/;
    var COLLAB_LINK = /\/collaboration\/item\/full\/(\d+)(?:[\/?#]|$)/;

    function labels() {
        if ( !( window.jQuery && window.jQuery.ez ) && !configBlocks().length && !document.getElementById( 'ezxform_token_js' ) ) {
            return;
        }
        var pairs = {}, collab = {}, anchors = [];
        var here = window.location.pathname;
        var pageVersion = here.match( VERSION_LINK );
        var pageCollab = here.match( COLLAB_LINK );
        if ( pageVersion ) {
            pairs[pageVersion[1] + ':' + pageVersion[2]] = true;
        }
        if ( pageCollab ) {
            collab[pageCollab[1]] = true;
        }
        Array.prototype.forEach.call( document.querySelectorAll( 'a[href]' ), function ( a ) {
            if ( a.closest( '.exp-ai' ) ) {
                return;
            }
            var href = a.getAttribute( 'href' );
            var m = href.match( VERSION_LINK );
            if ( m ) {
                pairs[m[1] + ':' + m[2]] = true;
                anchors.push( { a: a, key: m[1] + ':' + m[2] } );
                return;
            }
            var c = href.match( COLLAB_LINK );
            if ( c && !pageCollab ) {
                collab[c[1]] = true;
                anchors.push( { a: a, collab: c[1] } );
            }
        } );
        var pairList = Object.keys( pairs ), collabList = Object.keys( collab );
        if ( !pairList.length && !collabList.length ) {
            return;
        }
        api( 'labels', { pairs: pairList.slice( 0, 200 ).join( ',' ), collab: collabList.slice( 0, 100 ).join( ',' ) } ).then( function ( data ) {
            if ( !data || !data.labels ) {
                return;
            }
            // No badge next to every link to a version written by Exponential Intelligence (it did nothing and cluttered
            // the lists); only the page's own version shows the banner below.
            var pageKey = pageVersion ? pageVersion[1] + ':' + pageVersion[2] : ( pageCollab && data.collab ? data.collab[pageCollab[1]] : '' );
            if ( pageKey && data.labels[pageKey] ) {
                var info = data.labels[pageKey];
                var banner = '<div class="exp-ai exp-ai-banner" role="note"><span class="exp-ai-mark" aria-hidden="true"></span><div><strong>'
                    + esc( data.texts.full ) + '</strong><br>' + esc( data.texts.written )
                    + ( info.model ? ' <span class="exp-ai-model">' + esc( data.texts.model ) + ': ' + esc( info.model ) + '</span>' : '' ) + '</div></div>';
                var anchor = document.querySelector( '#exp-collab .cb-head' )
                    || document.querySelector( '#exp-ai-edit' )
                    || document.querySelector( '.context-block .box-header, .content-view-full .attribute-header, #maincontent h1, .context-title' );
                if ( anchor && !document.querySelector( '.exp-ai-banner' ) ) {
                    if ( anchor.id === 'exp-ai-edit' ) {
                        anchor.insertAdjacentHTML( 'beforebegin', banner );
                    } else {
                        anchor.insertAdjacentHTML( 'afterend', banner );
                    }
                }
            }
            emit( 'labels:done', data );
        } ).catch( function () {} );
    }

    /* ----------------------------------------------------------------- panel */

    function panel( root ) {
        var nodeID = root.getAttribute( 'data-node-id' );
        var S = readJSON( root, '.exp-ai-strings' );
        var log = root.querySelector( '.exp-ai-log' );
        var working = root.querySelector( '.exp-ai-working' );
        // the step messages and the end of a turn, spoken through a live region of their own (the log itself is not
        // live: a long answer or a reloaded history would be read out whole); at most one message every 2 seconds
        var live = root.querySelector( '.exp-ai-steps-live' );
        var liveAt = 0;
        function announce( message, force ) {
            if ( !live || !message || ( !force && Date.now() - liveAt < 2000 ) ) {
                return;
            }
            liveAt = Date.now();
            live.textContent = message;
        }
        var errorBox = root.querySelector( '.exp-ai-error' );
        var input = root.querySelector( '.exp-ai-input' );
        var sendButton = root.querySelector( '.exp-ai-send' );
        var continueButton = root.querySelector( '.exp-ai-continue' );
        var resetButton = root.querySelector( '.exp-ai-reset' );
        var form = root.querySelector( '.exp-ai-form' );
        var draftList = root.querySelector( '.exp-ai-draft-list' );
        var summary = root.querySelector( '.exp-ai-send-summary' );
        var keepButton = root.querySelector( '.exp-ai-keep' );
        var approvalButton = root.querySelector( '.exp-ai-send-approval' );
        var actNote = root.querySelector( '.exp-ai-act-note' );
        var historyBar = root.querySelector( '.exp-ai-history-bar' );
        var olderButton = root.querySelector( '.exp-ai-older' );
        var busy = false;
        var drafts = [];
        var oldestId = null;

        /* ---- history: one row per exchange, collapsed except the latest; toggling changes nothing on the server */

        var storeKey = 'exp-ai-history-' + nodeID;
        var remembered = {};
        try { remembered = JSON.parse( window.localStorage.getItem( storeKey ) || '{}' ) || {}; } catch ( e ) { remembered = {}; }

        function remember( id, open ) {
            remembered[id] = open;
            try { window.localStorage.setItem( storeKey, JSON.stringify( remembered ) ); } catch ( e ) { /* private mode */ }
        }

        function setOpen( li, open, save ) {
            var button = li.querySelector( '.exp-ai-x-toggle' );
            var body = li.querySelector( '.exp-ai-x-body' );
            if ( !button || !body ) {
                return;
            }
            button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
            body.hidden = !open;
            li.classList.toggle( 'exp-ai-x-open', open );
            if ( save ) {
                remember( li.getAttribute( 'data-id' ), open );
            }
        }

        function timeText( seconds ) {
            try {
                return new Date( seconds * 1000 ).toLocaleString( document.documentElement.lang || undefined,
                    { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' } );
            } catch ( e ) {
                return '';
            }
        }

        function chips( x ) {
            return ( x.drafts || [] ).map( function ( d ) {
                return '<a class="exp-ai-chip-link" href="' + esc( url( d.view ) ) + '"><span class="exp-ai-mark" aria-hidden="true"></span>' + esc( d.name ) + '</a>';
            } ).join( ' ' );
        }

        function exchangeHtml( x ) {
            var bodyId = 'exp-ai-x-' + nodeID + '-' + x.id;
            var lookups = x.lookups ? '<span class="exp-ai-x-count">' + esc( fmt( S.lookups, { '%count': x.lookups } ) ) + '</span>' : '';
            var steps = ( x.steps || [] ).map( function ( step ) {
                return '<li class="exp-ai-step' + ( step.ok ? '' : ' exp-ai-step-failed' ) + ( step.writes ? ' exp-ai-step-write' : '' ) + '">' + esc( step.text ) + '</li>';
            } ).join( '' );
            return '<div class="exp-ai-x-head">'
                + '<button type="button" class="exp-ai-x-toggle" aria-expanded="false" aria-controls="' + bodyId + '">'
                + '<span class="exp-ai-x-time">' + esc( timeText( x.time ) ) + '</span>'
                + '<span class="exp-ai-x-line"><strong>' + esc( S.you ) + ':</strong> ' + esc( plainText( x.user_snippet ) ) + '</span>'
                + ( x.snippet && x.reply ? '<span class="exp-ai-x-line exp-ai-x-reply"><strong>' + esc( S.assistant_short ) + ':</strong> ' + esc( plainText( x.snippet ) ) + '</span>' : '' )
                + lookups + '</button>'
                + '<span class="exp-ai-x-chips">' + chips( x ) + '</span></div>'
                + '<div class="exp-ai-x-body" id="' + bodyId + '" hidden="hidden">'
                + '<div class="exp-ai-msg exp-ai-msg-user"><span class="exp-ai-who">' + esc( S.you ) + '</span><div class="exp-ai-body">' + renderText( x.user ) + '</div></div>'
                + ( x.notice ? '<p class="exp-ai-fallback-notice" role="status">' + esc( x.notice ) + '</p>' : '' )
                + ( steps ? '<ul class="exp-ai-steps">' + steps + '</ul>' : '' )
                + ( x.reply ? '<div class="exp-ai-msg exp-ai-msg-assistant"><span class="exp-ai-who">' + esc( S.assistant ) + '</span><div class="exp-ai-body">' + renderText( x.reply ) + '</div></div>' : '' )
                // #389 what became of the drafts since (published, sent for approval): a system note, the answer stays as written
                + ( x.outcomes || [] ).map( function ( o ) { return '<p class="exp-ai-x-outcome" role="note">' + esc( o ) + '</p>'; } ).join( '' )
                + '</div>';
        }

        /** Puts an exchange into the list (replacing the row of the same id), open or closed */
        // the record of each exchange (expAiTurnRecord): from the history and from each step's answer
        var records = {};
        function takeRecords( data ) {
            Object.keys( ( data && data.records ) || {} ).forEach( function ( id ) { records[id] = data.records[id]; } );
        }

        function showExchange( x, open, prepend ) {
            if ( !log || !x ) {
                return null;
            }
            var li = log.querySelector( 'li[data-id="' + x.id + '"]' );
            if ( !li ) {
                li = document.createElement( 'li' );
                li.className = 'exp-ai-x';
                li.setAttribute( 'data-id', x.id );
                if ( prepend ) {
                    log.insertBefore( li, log.firstChild );
                } else {
                    log.appendChild( li );
                }
            }
            li.innerHTML = exchangeHtml( x ) + ( records[x.id] ? ExpAi.turnRecord( records[x.id] ) : '' );
            setOpen( li, open, false );
            if ( oldestId === null || x.id < oldestId ) {
                oldestId = x.id;
            }
            if ( historyBar ) {
                historyBar.hidden = log.children.length < 2 && !( olderButton && !olderButton.hidden );
            }
            return li;
        }

        function collapseOthers( keepId ) {
            Array.prototype.forEach.call( log ? log.children : [], function ( li ) {
                if ( li.getAttribute( 'data-id' ) !== String( keepId ) && remembered[li.getAttribute( 'data-id' )] !== true ) {
                    setOpen( li, false, false );
                }
            } );
        }

        function showHistory( page, prepend ) {
            var list = page && page.exchanges ? page.exchanges : [];
            takeRecords( page );
            var latest = list.length ? list[list.length - 1].id : null;
            ( prepend ? list.slice().reverse() : list ).forEach( function ( x ) {
                var saved = remembered[x.id];
                showExchange( x, saved === undefined ? ( !prepend && x.id === latest ) : saved, prepend );
            } );
            if ( olderButton ) {
                olderButton.hidden = !( page && page.has_older );
            }
            if ( historyBar && log ) {
                historyBar.hidden = log.children.length < 2 && olderButton && olderButton.hidden;
            }
        }

        if ( log ) {
            log.addEventListener( 'click', function ( e ) {
                var button = e.target.closest( '.exp-ai-x-toggle' );
                if ( button ) {
                    var li = button.closest( '.exp-ai-x' );
                    setOpen( li, button.getAttribute( 'aria-expanded' ) !== 'true', true );
                }
            } );
        }
        if ( historyBar ) {
            historyBar.addEventListener( 'click', function ( e ) {
                var all = e.target.closest( '.exp-ai-expand-all, .exp-ai-collapse-all' );
                if ( all ) {
                    var open = all.classList.contains( 'exp-ai-expand-all' );
                    Array.prototype.forEach.call( log.children, function ( li ) { setOpen( li, open, true ); } );
                }
            } );
        }
        // a draft chip in the header of an exchange links to a version that may have been discarded since (a 404): the
        // link is checked when it is followed, and a gone version shows a note instead of an error page
        if ( log ) {
            log.addEventListener( 'click', function ( e ) {
                var link = e.target.closest ? e.target.closest( 'a.exp-ai-chip-link' ) : null;
                if ( !link || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || !window.fetch ) {
                    return;
                }
                e.preventDefault();
                var target = link.href;
                window.fetch( target, { method: 'HEAD', credentials: 'same-origin' } ).then( function ( r ) {
                    if ( r.status === 404 || r.status === 410 ) {
                        var gone = document.createElement( 'span' );
                        gone.className = 'exp-ai-chip-gone';
                        gone.title = S.draft_gone || 'This draft no longer exists.';
                        gone.innerHTML = link.innerHTML;
                        link.parentNode.replaceChild( gone, link );
                        announce( S.draft_gone, true );
                    } else {
                        window.location.href = target;
                    }
                } ).catch( function () { window.location.href = target; } );
            } );
        }
        if ( olderButton ) {
            olderButton.addEventListener( 'click', function () {
                olderButton.disabled = true;
                api( 'history', { node_id: nodeID, before: oldestId || 0 } ).then( function ( page ) {
                    showHistory( page, true );
                } ).catch( showError ).then( function () { olderButton.disabled = false; } );
            } );
        }

        function setBusy( on ) {
            busy = on;
            if ( working ) { working.hidden = !on; }
            [ sendButton, resetButton, keepButton, approvalButton, continueButton ].forEach( function ( b ) { if ( b ) { b.disabled = on; } } );
            if ( input ) { input.disabled = on; }
            root.classList.toggle( 'exp-ai-busy', on );
        }

        function showError( err ) {
            if ( !errorBox ) {
                return;
            }
            if ( !err ) {
                errorBox.hidden = true;
                errorBox.textContent = '';
                return;
            }
            errorBox.hidden = false;
            errorBox.textContent = err.kind === 'timeout' ? S.timeout
                : err.expired ? ( S.session_expired || err.message || '' ) : err.lost ? ( err.message || '' ) : ( S.error + ' ' + ( err.message || '' ) ).trim();
        }

        function note( text ) {
            if ( actNote ) {
                actNote.textContent = text || '';
            }
        }

        function statusText( d ) {
            return S['status_' + d.status] || d.status;
        }

        function renderDrafts() {
            if ( !draftList ) {
                return;
            }
            if ( !drafts.length ) {
                draftList.innerHTML = '<p class="exp-ai-empty">' + esc( S.no_drafts ) + '</p>';
                updateSummary();
                return;
            }
            var html = '<table class="list exp-ai-draft-table"><tbody>';
            drafts.forEach( function ( d ) {
                var key = d.object_id + ':' + d.version;
                html += '<tr data-key="' + esc( key ) + '" data-approval="' + esc( d.approval ) + '" class="exp-ai-draft-' + esc( d.status ) + '">'
                    + '<td class="exp-ai-check">' + ( d.is_draft ? '<input type="checkbox" checked="checked" aria-label="' + esc( d.name ) + '" />' : '' ) + '</td>'
                    + '<td class="exp-ai-draft-main"><a href="' + esc( url( d.urls.view ) ) + '">' + esc( d.name || ( '#' + d.object_id ) ) + '</a> '
                    + '<span class="exp-ai-badge" title="' + esc( S.label_full + ( d.model ? ' - ' + d.model : '' ) ) + '"><span class="exp-ai-mark" aria-hidden="true"></span>' + esc( S.label ) + '</span>'
                    + '<div class="exp-ai-draft-meta">' + esc( d.class_name ) + ' &middot; ' + esc( fmt( S.version, { '%version': d.version } ) ) + ' &middot; ' + esc( d.language )
                    + ' &middot; <span class="exp-ai-status exp-ai-status-' + esc( d.status ) + '">' + esc( statusText( d ) ) + '</span>'
                    + ( d.is_draft && d.approval ? '<br><span class="exp-ai-approval exp-ai-approval-' + esc( d.approval ) + '">' + esc( S['approval_' + d.approval] ) + '</span>' : '' )
                    + '</div></td>'
                    + '<td class="exp-ai-draft-links">'
                    + ( d.urls.edit ? '<a href="' + esc( url( d.urls.edit ) ) + '">' + esc( S.edit ) + '</a>' : '' )
                    + ' <a href="' + esc( url( d.urls.view ) ) + '">' + esc( S.view ) + '</a>'
                    // #382 "What changed": the diff against the base version, revert per part (expai_whatchanged.js)
                    + ( d.is_draft ? ' <button type="button" class="exp-ai-link exp-ai-what-changed" data-exp-ai-what-changed="' + esc( key ) + '">' + esc( S.what_changed || 'What changed' ) + '</button>' : '' )
                    + ( d.urls.node && !d.is_draft ? ' <a href="' + esc( url( d.urls.node ) ) + '">' + esc( S.open ) + '</a>' : '' )
                    + ( d.is_draft ? ' <button type="button" class="exp-ai-link exp-ai-discard">' + esc( S.discard ) + '</button>' : '' )
                    + '</td></tr>';
            } );
            draftList.innerHTML = html + '</tbody></table>';
            updateSummary();
        }

        function selected() {
            return Array.prototype.map.call( draftList ? draftList.querySelectorAll( 'tr input[type="checkbox"]:checked' ) : [], function ( box ) {
                var row = box.closest( 'tr' );
                return { key: row.getAttribute( 'data-key' ), approval: row.getAttribute( 'data-approval' ) };
            } );
        }

        function summaryText( list ) {
            var counts = { approval: 0, possible: 0, direct: 0 };
            list.forEach( function ( s ) { if ( counts[s.approval] !== undefined ) { counts[s.approval]++; } } );
            return [ 'approval', 'possible', 'direct' ].filter( function ( k ) { return counts[k] > 0; } ).map( function ( k ) {
                return fmt( S['summary_' + k], { '%count': counts[k] } );
            } ).join( '; ' );
        }

        function updateSummary() {
            var list = selected();
            var hasDrafts = drafts.some( function ( d ) { return d.is_draft; } );
            if ( keepButton ) { keepButton.hidden = !hasDrafts; }
            if ( approvalButton ) { approvalButton.hidden = !hasDrafts; }
            if ( summary ) {
                summary.textContent = list.length ? summaryText( list ) + '.' : '';
            }
        }

        function setDrafts( list ) {
            drafts = list || [];
            renderDrafts();
            emit( 'drafts:changed', { panel: root, drafts: drafts } );
        }

        /**
         * A slow model: the turn runs in the background (doc/vector.md "Background runs"). The panel follows the
         * thread's events and shows the exchange as its steps arrive, until the thread is no longer queued or running.
         */
        function followQueued( data ) {
            var uuid = data.thread;
            var cursor = 0;
            var text = working ? working.querySelector( '.exp-ai-working-text' ) : null;
            var slowBox = root.querySelector( '.exp-ai-slow' );
            var switched = null;
            if ( text && data.notice ) {
                text.textContent = data.notice;
            }
            var guard = pollGuard( 3000, S );
            return new Promise( function ( resolve, reject ) {
                function tick() {
                    guard.whenVisible( poll );
                }
                function poll() {
                    var after = {};
                    after[uuid] = cursor;
                    api( 'expaivector::events', { threads: uuid, after: JSON.stringify( after ), open: uuid } ).then( function ( ev ) {
                        guard.ok();
                        var news = ( ev.events || [] ).filter( function ( e ) { return e.thread === uuid; } );
                        var failed = '';
                        news.forEach( function ( e ) {
                            cursor = Math.max( cursor, e.id );
                            if ( e.kind === 'step_end' && e.payload && e.payload.text && text ) {
                                text.textContent = e.payload.text;
                                announce( e.payload.text );
                            }
                            if ( e.kind === 'error' && e.payload ) {
                                failed = e.payload.message || '';
                            }
                        } );
                        var st = ( ev.threads || [] )[0];
                        var done = !st || ( st.state !== 'queued' && st.state !== 'running' );
                        if ( st && st.watch ) { ExpAi.watchChip( root.querySelector( '.exp-ai-watch' ), st.watch ); }
                        // a slow model: its time per step and the switch to the default model
                        ExpAi.slowStatus( slowBox, done || !st ? null : st.slow, function () {
                            api( 'expaivector::switch_model', { thread: uuid } ).then( function ( r ) {
                                switched = r.switch || null;
                                if ( text && r.switch ) { text.textContent = r.switch.label; }
                            } ).catch( function ( err ) { showError( err ); } );
                        } );
                        var refresh = news.length ? api( 'history', { node_id: nodeID } ).then( function ( page ) {
                            var list = page.exchanges || [];
                            takeRecords( page );
                            if ( list.length ) {
                                var x = list[list.length - 1];
                                var li = showExchange( x, true, false );
                                collapseOthers( x.id );
                                if ( li ) {
                                    li.scrollIntoView( { block: 'nearest' } );
                                }
                            }
                        } ) : Promise.resolve();
                        return refresh.then( function () {
                            if ( !done ) {
                                window.setTimeout( tick, Math.max( 1000, Math.min( ev.next_ms || 2000, 3000 ) ) );
                                return;
                            }
                            api( 'drafts', { node_id: nodeID } ).then( function ( d ) { setDrafts( d.drafts || [] ); } ).catch( function () {} );
                            if ( text && S.working ) {
                                text.textContent = S.working;
                            }
                            if ( st && st.state === 'failed' ) {
                                reject( { message: failed, kind: 'server' } );
                            } else {
                                resolve( { status: switched ? 'switched' : 'done', 'switch': switched } );
                            }
                        } );
                    } ).catch( function ( err ) {
                        var verdict = guard.fail( err );
                        if ( verdict.stop ) {
                            reject( verdict.error );
                        } else {
                            window.setTimeout( tick, verdict.delay );
                        }
                    } );
                }
                window.setTimeout( tick, 1000 );
            } );
        }

        function step( params ) {
            return api( 'chat', extend( { node_id: nodeID }, params ) ).then( function ( data ) {
                // "Watch this run": the state of the accepted turn with the links to its live record
                takeRecords( data );
                ExpAi.watchChip( root.querySelector( '.exp-ai-watch' ), data.watch || null );
                if ( data.status === 'queued' && data.thread ) {
                    if ( data.drafts ) {
                        setDrafts( data.drafts );
                    }
                    return followQueued( data ).then( function ( r ) {
                        // switched to another model: it goes on with the same message (in the queue, or here)
                        if ( r.status === 'switched' && r['switch'] && r['switch'].pending ) {
                            return r['switch'].background ? followQueued( { thread: data.thread } ) : step( { 'continue': '1' } );
                        }
                        announce( S.answer_ready, true );
                        emit( 'panel:done', { panel: root, data: r } );
                        return r;
                    } );
                }
                if ( data.exchange ) {
                    var li = showExchange( data.exchange, true, false );
                    collapseOthers( data.exchange.id );
                    if ( li ) {
                        li.scrollIntoView( { block: 'nearest' } );
                    }
                }
                if ( data.drafts ) {
                    setDrafts( data.drafts );
                }
                emit( 'panel:step', { panel: root, data: data } );
                if ( data.status === 'continue' ) {
                    return step( { 'continue': '1' } );
                }
                if ( continueButton ) { continueButton.hidden = true; }
                announce( S.answer_ready, true );
                emit( 'panel:done', { panel: root, data: data } );
                return data;
            } );
        }

        function run( params ) {
            showError( null );
            note( '' );
            setBusy( true );
            return step( params ).catch( function ( err ) {
                showError( err );
                if ( continueButton ) { continueButton.hidden = false; }
            } ).then( function () {
                setBusy( false );
                if ( input ) { input.focus(); }
            } );
        }

        if ( form ) {
            form.addEventListener( 'submit', function ( e ) {
                e.preventDefault();
                var text = input ? input.value.trim() : '';
                if ( busy || !text ) {
                    return;
                }
                input.value = '';
                run( { message: text } );
            } );
        }
        if ( input ) {
            input.addEventListener( 'keydown', function ( e ) {
                if ( e.key === 'Enter' && ( e.ctrlKey || e.metaKey ) && form ) {
                    e.preventDefault();
                    form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
                }
            } );
        }
        if ( continueButton ) {
            continueButton.addEventListener( 'click', function () { if ( !busy ) { run( { 'continue': '1' } ); } } );
        }
        if ( resetButton ) {
            resetButton.addEventListener( 'click', function () {
                if ( busy || !window.confirm( S.reset_confirm ) ) {
                    return;
                }
                setBusy( true );
                api( 'reset', { node_id: nodeID } ).then( function ( data ) {
                    if ( log ) { log.innerHTML = ''; }
                    oldestId = null;
                    remembered = {};
                    try { window.localStorage.removeItem( storeKey ); } catch ( e ) { /* private mode */ }
                    showHistory( data.history, false );
                    setDrafts( data.drafts );
                    showError( null );
                } ).catch( showError ).then( function () { setBusy( false ); } );
            } );
        }
        if ( draftList ) {
            draftList.addEventListener( 'change', updateSummary );
            draftList.addEventListener( 'click', function ( e ) {
                var button = e.target.closest( '.exp-ai-discard' );
                if ( !button || busy ) {
                    return;
                }
                var row = button.closest( 'tr' );
                var name = row.querySelector( '.exp-ai-draft-main a' ).textContent;
                if ( !window.confirm( fmt( S.discard_confirm, { '%name': name } ) ) ) {
                    return;
                }
                act( 'discard', [ row.getAttribute( 'data-key' ) ] );
            } );
        }

        function act( fn, keys ) {
            showError( null );
            setBusy( true );
            return api( fn, { node_id: nodeID, drafts: keys.join( ',' ) } ).then( function ( data ) {
                var messages = [];
                ( data.results || [] ).forEach( function ( r ) {
                    var d = drafts.filter( function ( x ) { return x.object_id === r.object_id && x.version === r.version; } )[0] || {};
                    if ( !r.ok ) {
                        messages.push( ( d.name || '#' + r.object_id ) + ': ' + r.error );
                    } else if ( fn === 'send' ) {
                        messages.push( fmt( S['sent_' + r.status] || r.status, { '%name': d.name || '#' + r.object_id } ) );
                    }
                } );
                if ( fn === 'keep' && ( data.results || [] ).some( function ( r ) { return r.ok; } ) ) {
                    messages.push( S.kept );
                }
                setDrafts( data.drafts );
                note( messages.join( ' ' ) );
                // #389 a decision changes the history (system notes), the thread state and, once published, the page header
                var decided = ( data.results || [] ).filter( function ( r ) { return r.ok; } );
                if ( decided.length ) {
                    decided.forEach( function ( r ) {
                        if ( fn === 'send' && r.status === 'published' ) {
                            Array.prototype.forEach.call( document.querySelectorAll( '.exp-object-version[data-object-id="' + r.object_id + '"] .exp-object-version-number' ), function ( el ) {
                                el.textContent = String( r.version );
                            } );
                        }
                    } );
                    api( 'history', { node_id: nodeID } ).then( function ( page ) { showHistory( page, false ); } ).catch( function () { /* the next load shows it */ } );
                    emit( 'panel:decided', { panel: root, data: data } );
                }
            } ).catch( showError ).then( function () { setBusy( false ); } );
        }

        if ( keepButton ) {
            keepButton.addEventListener( 'click', function () {
                var list = selected();
                if ( !list.length ) {
                    showError( { message: S.select_some } );
                    return;
                }
                act( 'keep', list.map( function ( s ) { return s.key; } ) );
            } );
        }
        if ( approvalButton ) {
            approvalButton.addEventListener( 'click', function () {
                var list = selected();
                if ( !list.length ) {
                    showError( { message: S.select_some } );
                    return;
                }
                if ( !window.confirm( fmt( S.send_confirm, { '%count': list.length, '%summary': summaryText( list ) } ) ) ) {
                    return;
                }
                act( 'send', list.map( function ( s ) { return s.key; } ) );
            } );
        }

        Object.keys( panelSectionInits ).forEach( function ( name ) {
            try { panelSectionInits[name]( root ); } catch ( e ) { if ( window.console ) { window.console.error( e ); } }
        } );

        setBusy( true );
        api( 'load', { node_id: nodeID } ).then( function ( data ) {
            showHistory( data.history, false );
            if ( data.model ) {
                Array.prototype.forEach.call( root.querySelectorAll( '.exp-ai-model-select' ), function ( select ) { select.value = data.model.current; } );
                Array.prototype.forEach.call( root.querySelectorAll( '.exp-ai-model-note' ), function ( n ) { n.textContent = data.model.refused || data.model.slow || ''; } );
                // the fallback: the select of the cached page learns which models are stopped
                applyModelStates( root, data.model.states, data.model.manage );
            }
            if ( data.prefill && input ) {
                input.value = data.prefill;
                window.setTimeout( function () { input.focus(); }, 0 );
            }
            setDrafts( data.drafts );
            if ( data.pending && continueButton ) {
                continueButton.hidden = false;
                note( S.interrupted );
            }
        } ).catch( showError ).then( function () { setBusy( false ); } );
    }

    /* ------------------------------------------------------------------ edit */

    function edit( root ) {
        var C = readJSON( root, '.exp-ai-edit-config' );
        var S = C.strings || {};
        var form = root.closest( 'form' ) || document.getElementById( 'editform' );
        var body = root.querySelector( '.exp-ai-edit-body' );
        var toggle = root.querySelector( '.exp-ai-toggle' );
        var writable = C.writable || [];
        var fields = [];
        limits.fieldLength = fieldLengthLimit( C.max_field_length );

        if ( toggle && body ) {
            var stored = null;
            try { stored = window.localStorage.getItem( 'exp-ai-edit-open' ); } catch ( e ) { stored = null; }
            var setOpen = function ( open ) {
                body.hidden = !open;
                toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
                toggle.value = open ? S.hide : S.show;
                try { window.localStorage.setItem( 'exp-ai-edit-open', open ? '1' : '0' ); } catch ( e ) { /* private mode */ }
            };
            setOpen( stored === '1' );
            toggle.addEventListener( 'click', function () { setOpen( body.hidden ); } );
        }

        ( C.attributes || [] ).forEach( function ( attribute ) {
            var block = form ? form.querySelector( '.ezcca-edit-' + attribute.identifier ) : null;
            if ( !block || writable.indexOf( attribute.datatype ) === -1 ) {
                return;
            }
            fields.push( { attribute: attribute, block: block } );
        } );

        function ctxValue( ctx ) {
            return adapterFor( ctx.attribute.datatype ).get( ctx );
        }

        function formContext() {
            var out = {}, size = 0;
            fields.forEach( function ( ctx ) {
                var value = ctxValue( ctx );
                if ( typeof value === 'string' && value.trim() !== '' && size < 30000 ) {
                    out[ctx.attribute.identifier] = value.slice( 0, 6000 );
                    size += Math.min( value.length, 6000 );
                }
            } );
            return JSON.stringify( out );
        }

        function base() {
            return { object_id: C.object_id, version: C.version, language: C.language };
        }

        function flash( ctx ) {
            ctx.block.classList.add( 'exp-ai-filled' );
            window.setTimeout( function () { ctx.block.classList.remove( 'exp-ai-filled' ); }, 2500 );
        }

        function fill( ctx, value ) {
            var previous = ctxValue( ctx );
            var ok = adapterFor( ctx.attribute.datatype ).set( ctx, value );
            if ( ok ) {
                flash( ctx );
                emit( 'field:filled', { field: ctx, value: value, previous: previous } );
            }
            return { ok: ok, previous: previous };
        }

        function fieldBar( ctx ) {
            var actions = ( C.actions || [] ).filter( function ( a ) { return ( a.datatypes || [] ).indexOf( ctx.attribute.datatype ) !== -1; } );
            if ( !actions.length ) {
                return;
            }
            // drawn with the page (content/edit/expai_attribute.tpl, through content.ini [EditSettings]
            // AttributeTemplates[]), so the form does not move: taken on, its buttons enabled; otherwise drawn here
            var bar = ctx.block.querySelector( '.exp-ai-field-bar[data-exp-ai-attribute="' + String( ctx.attribute.id ).replace( /\D/g, '' ) + '"]' );
            if ( bar && bar.querySelector( '.exp-ai-field-form' ) && bar.querySelector( '.exp-ai-field-note' ) ) {
                Array.prototype.forEach.call( bar.querySelectorAll( '.exp-ai-chip' ), function ( chip ) { chip.disabled = false; } );
            } else {
                bar = document.createElement( 'div' );
                bar.className = 'exp-ai exp-ai-field-bar';
                bar.innerHTML = '<span class="exp-ai-field-title" title="' + esc( S.actions_title ) + '"><span class="exp-ai-mark" aria-hidden="true"></span>' + esc( S.actions ) + '</span>'
                    + actions.map( function ( a ) {
                        return '<button type="button" class="exp-ai-chip" data-action="' + esc( a.name ) + '">' + esc( a.label ) + '</button>';
                    } ).join( '' )
                    + '<div class="exp-ai-field-form" hidden="hidden"></div><p class="exp-ai-field-note" aria-live="polite"></p>';
                var label = ctx.block.querySelector( ':scope > label, :scope > fieldset > legend, :scope > .translation > label' );
                if ( label && label.parentNode === ctx.block ) {
                    label.insertAdjacentElement( 'afterend', bar );
                } else {
                    ctx.block.insertBefore( bar, ctx.block.firstChild );
                }
            }
            var formBox = bar.querySelector( '.exp-ai-field-form' );
            var note = bar.querySelector( '.exp-ai-field-note' );
            var undo = null;

            function busy( on ) {
                bar.classList.toggle( 'exp-ai-busy', on );
                Array.prototype.forEach.call( bar.querySelectorAll( 'button, input[type="button"]' ), function ( b ) { b.disabled = on; } );
                if ( on ) {
                    clearReview( bar );
                    note.innerHTML = '<span class="exp-ai-spinner" aria-hidden="true"></span> ' + esc( S.working );
                }
            }

            function runAction( action, params ) {
                var hooks = fieldActionHooks[action.name] || {};
                // the value as sent: when the field differs once the answer is there, it is not overwritten
                var sent = ctxValue( ctx );
                var data = extend( base(), { attribute_id: ctx.attribute.id, action: action.name, value: sent, context: formContext() }, params );
                if ( hooks.before && hooks.before( ctx, data ) === false ) {
                    return;
                }
                busy( true );
                api( 'field', data ).then( function ( result ) {
                    // a failure after the request was sent: the error and the link to its record
                    if ( result && result.error ) {
                        note.innerHTML = esc( ( S.error + ' ' + result.error ).trim() ) + requestLinks( result.record );
                        return;
                    }
                    if ( hooks.after && hooks.after( ctx, result ) === false ) {
                        note.textContent = '';
                        return;
                    }
                    var extras = ( result.note ? ' <em>' + esc( result.note ) + '</em>' : '' )
                        + ( result.fallback ? ' <span class="exp-ai-fallback-notice">' + esc( result.fallback.notice ) + '</span>' : '' );
                    var outcome = guardedApply( {
                        ctx: ctx, adapter: adapterFor( ctx.attribute.datatype ), value: result.value, sent: sent, host: bar, strings: S,
                        apply: function () {
                            var done = fill( ctx, result.value );
                            undo = done.previous;
                            note.innerHTML = esc( S.filled ) + extras
                                + ' <button type="button" class="exp-ai-link exp-ai-undo">' + esc( S.undo ) + '</button>'
                                + requestLinks( result.record );
                            return done.ok;
                        },
                        kept: function () { note.textContent = S.kept; }
                    } );
                    if ( outcome.review ) {
                        note.innerHTML = esc( S.review_note ) + extras + requestLinks( result.record );
                    }
                } ).catch( function ( err ) {
                    note.textContent = err.kind === 'timeout' ? S.timeout : ( S.error + ' ' + ( err.message || '' ) ).trim();
                } ).then( function () {
                    bar.classList.remove( 'exp-ai-busy' );
                    Array.prototype.forEach.call( bar.querySelectorAll( 'button, input[type="button"]' ), function ( b ) { b.disabled = false; } );
                } );
            }

            bar.addEventListener( 'click', function ( e ) {
                var undoButton = e.target.closest( '.exp-ai-undo' );
                if ( undoButton ) {
                    if ( undo !== null ) {
                        adapterFor( ctx.attribute.datatype ).set( ctx, undo );
                        undo = null;
                        note.textContent = S.undone;
                    }
                    return;
                }
                var chip = e.target.closest( '.exp-ai-chip' );
                if ( !chip ) {
                    return;
                }
                var action = actions.filter( function ( a ) { return a.name === chip.getAttribute( 'data-action' ); } )[0];
                if ( !action ) {
                    return;
                }
                if ( action.input === 'none' ) {
                    formBox.hidden = true;
                    runAction( action, {} );
                    return;
                }
                var inputHtml;
                if ( action.input === 'language' ) {
                    inputHtml = '<label class="exp-ai-label">' + esc( S.language ) + ' <select class="exp-ai-field-input">'
                        + ( C.languages || [] ).filter( function ( l ) { return l.code !== C.language; } ).map( function ( l ) {
                            return '<option value="' + esc( l.code ) + '">' + esc( l.name ) + '</option>';
                        } ).join( '' ) + '</select></label>';
                } else {
                    inputHtml = '<label class="exp-ai-label">' + esc( action.input === 'instruction' ? S.instruction : S.optional_instruction )
                        + ' <input type="text" class="exp-ai-field-input box no-autosave" maxlength="1000" /></label>';
                }
                formBox.innerHTML = inputHtml + ' <input type="button" class="defaultbutton exp-ai-btn exp-ai-field-go" value="' + esc( S.go ) + '" />'
                    + ' <input type="button" class="button exp-ai-btn exp-ai-field-cancel" value="' + esc( S.cancel ) + '" />';
                formBox.hidden = false;
                var field = formBox.querySelector( '.exp-ai-field-input' );
                field.focus();
                var go = function () {
                    var value = field.value;
                    formBox.hidden = true;
                    runAction( action, action.input === 'language' ? { target_language: value } : { instruction: value } );
                };
                formBox.querySelector( '.exp-ai-field-go' ).addEventListener( 'click', go );
                formBox.querySelector( '.exp-ai-field-cancel' ).addEventListener( 'click', function () { formBox.hidden = true; } );
                field.addEventListener( 'keydown', function ( ev ) {
                    if ( ev.key === 'Enter' ) {
                        ev.preventDefault();
                        go();
                    } else if ( ev.key === 'Escape' ) {
                        formBox.hidden = true;
                    }
                } );
            } );
        }

        fields.forEach( fieldBar );

        /**
         * Above a rich text field whose editor has its own assistant (editorHasOwnAI) the field actions and the Improve
         * menu are hidden (class exp-ai-editor-ai on the block); the bar goes with them when no preset is left in it
         * (exp-ai-bar-off). Runs after every change in the block: the editor starts after this script, and a destroyed
         * editor gives the buttons back.
         */
        function syncOwnAI( ctx ) {
            var own = C.hide_editor_ai === true && ctx.attribute.datatype === 'ezxmltext' && editorHasOwnAI( fieldInput( ctx ) );
            ctx.block.classList.toggle( 'exp-ai-editor-ai', own );
            var bar = ctx.block.querySelector( '.exp-ai-field-bar' );
            if ( bar ) {
                bar.classList.toggle( 'exp-ai-bar-off', own && !bar.querySelector( '.exp-ai-preset-chip[data-preset]' ) );
            }
        }
        if ( C.hide_editor_ai === true ) {
            fields.filter( function ( ctx ) { return ctx.attribute.datatype === 'ezxmltext'; } ).forEach( function ( ctx ) {
                var later = null;
                var schedule = function () { window.clearTimeout( later ); later = window.setTimeout( function () { syncOwnAI( ctx ); }, 50 ); };
                syncOwnAI( ctx );
                if ( window.MutationObserver ) {
                    // only added or removed nodes: the class changes above are attribute changes and do not loop
                    new MutationObserver( schedule ).observe( ctx.block, { childList: true, subtree: true } );
                }
                window.addEventListener( 'load', schedule );
            } );
        }

        /** Where the review of an answer to this field goes: its bar of field actions (when shown), or a box of its own after the label */
        function reviewHost( ctx ) {
            var host = ctx.block.querySelector( '.exp-ai-field-bar:not(.exp-ai-bar-off)' ) || ctx.block.querySelector( '.exp-ai-review-host' );
            if ( host ) {
                return host;
            }
            host = document.createElement( 'div' );
            host.className = 'exp-ai exp-ai-review-host';
            var label = ctx.block.querySelector( ':scope > label, :scope > fieldset > legend, :scope > .translation > label' );
            if ( label && label.parentNode === ctx.block ) {
                label.insertAdjacentElement( 'afterend', host );
            } else {
                ctx.block.insertBefore( host, ctx.block.firstChild );
            }
            return host;
        }

        // "Fill the fields" from a brief
        var brief = root.querySelector( '#exp-ai-brief' );
        var goButton = root.querySelector( '.exp-ai-fill-go' );
        var undoButton = root.querySelector( '.exp-ai-fill-undo' );
        var fillWorking = root.querySelector( '.exp-ai-fill .exp-ai-working' );
        var fillNote = root.querySelector( '.exp-ai-fill .exp-ai-note' );
        var fillUndo = [];
        if ( goButton && brief ) {
            goButton.addEventListener( 'click', function () {
                var text = brief.value.trim();
                if ( !text ) {
                    brief.focus();
                    return;
                }
                goButton.disabled = true;
                if ( fillWorking ) { fillWorking.hidden = false; }
                if ( fillNote ) { fillNote.textContent = ''; }
                // the values as sent: a field that differs once the answer is there is not overwritten
                var sent = {};
                fields.forEach( function ( ctx ) {
                    sent[ctx.attribute.id] = ctxValue( ctx );
                    clearReview( ctx.block.querySelector( '.exp-ai-field-bar, .exp-ai-review-host' ) );
                    clearReview( ctx.block.querySelector( '.exp-ai-review-host' ) );
                } );
                api( 'fill', extend( base(), { instruction: text, context: formContext() } ) ).then( function ( data ) {
                    fillUndo = [];
                    var count = 0, reviews = 0;
                    ( data.fields || [] ).forEach( function ( f ) {
                        var ctx = fields.filter( function ( x ) { return x.attribute.id === f.attribute_id; } )[0];
                        if ( !ctx ) {
                            return;
                        }
                        var outcome = guardedApply( {
                            ctx: ctx, adapter: adapterFor( ctx.attribute.datatype ), value: f.value, sent: sent[ctx.attribute.id], host: reviewHost( ctx ), strings: S,
                            // formContext() sends the first 6000 characters of a field
                            limit: 6000,
                            apply: function () {
                                var done = fill( ctx, f.value );
                                if ( done.ok ) {
                                    fillUndo.push( { ctx: ctx, value: done.previous } );
                                    if ( undoButton ) { undoButton.hidden = false; }
                                }
                                return done.ok;
                            }
                        } );
                        count += outcome.written ? 1 : 0;
                        reviews += outcome.review ? 1 : 0;
                    } );
                    if ( fillNote ) {
                        fillNote.innerHTML = esc( count ? fmt( S.filled_count, { '%count': count } ) : ( reviews ? '' : S.nothing ) )
                            + ( reviews ? ' ' + esc( fmt( S.review_count, { '%count': reviews } ) ) : '' )
                            + ( data.note ? ' <em>' + esc( data.note ) + '</em>' : '' )
                            + ( data.fallback ? ' <span class="exp-ai-fallback-notice">' + esc( data.fallback.notice ) + '</span>' : '' );
                    }
                    if ( undoButton ) { undoButton.hidden = !count; }
                    emit( 'fill:done', { root: root, data: data, count: count } );
                } ).catch( function ( err ) {
                    if ( fillNote ) { fillNote.textContent = err.kind === 'timeout' ? S.timeout : ( S.error + ' ' + ( err.message || '' ) ).trim(); }
                } ).then( function () {
                    goButton.disabled = false;
                    if ( fillWorking ) { fillWorking.hidden = true; }
                } );
            } );
        }
        if ( undoButton ) {
            undoButton.addEventListener( 'click', function () {
                fillUndo.forEach( function ( u ) { adapterFor( u.ctx.attribute.datatype ).set( u.ctx, u.value ); } );
                fillUndo = [];
                undoButton.hidden = true;
                if ( fillNote ) { fillNote.textContent = S.undone; }
            } );
        }
    }

    /* ----------------------------------------------------------------- chains */

    /**
     * #392 The chain pickers (the Intelligence tab, the dashboard's Start writing): the hint names the budget of the
     * chosen chain and the item count is capped by its MaxItems, also after the select changes.
     */
    function chainPickers() {
        [ [ 'exp-ai-chain-pick', 'exp-ai-chain-count' ], [ 'exp-ai-dash-chain', 'exp-ai-dash-count' ] ].forEach( function ( ids ) {
            var select = document.getElementById( ids[ 0 ] );
            if ( !select ) {
                return;
            }
            var count = document.getElementById( ids[ 1 ] );
            var form = select.form;
            var hint = form ? form.querySelector( '.exp-ai-chain-hint' ) : null;
            var apply = function () {
                var option = select.options[ select.selectedIndex ];
                if ( !option ) {
                    return;
                }
                var items = parseInt( option.getAttribute( 'data-max-items' ) || '', 10 );
                if ( count && items > 0 ) {
                    count.max = String( items );
                    if ( parseInt( count.value, 10 ) > items ) {
                        count.value = String( items );
                    }
                }
                if ( hint && hint.getAttribute( 'data-template' ) && option.hasAttribute( 'data-max-cost' ) ) {
                    hint.textContent = hint.getAttribute( 'data-template' ).split( '%max' ).join( option.getAttribute( 'data-max-cost' ) );
                }
            };
            select.addEventListener( 'change', apply );
            apply();
        } );
    }

    /* ----------------------------------------------------------------- model */

    /** The model selects (panel, edit form, dashboard): a change is saved as the user's choice (expai::model) */
    function models() {
        Array.prototype.forEach.call( document.querySelectorAll( '.exp-ai-model' ), function ( box ) {
            var select = box.querySelector( '.exp-ai-model-select' );
            var note = box.querySelector( '.exp-ai-model-note' );
            var reset = box.querySelector( '.exp-ai-model-reset' );
            if ( !select ) {
                return;
            }
            var save = function ( value ) {
                select.disabled = true;
                api( 'model', { model: value } ).then( function ( data ) {
                    select.value = data.model;
                    // keep every model select of the page in step
                    Array.prototype.forEach.call( document.querySelectorAll( '.exp-ai-model-select' ), function ( s ) { s.value = data.model; } );
                    // a slow (local) model says how long a step takes
                    Array.prototype.forEach.call( document.querySelectorAll( '.exp-ai-model-note' ), function ( n ) { n.textContent = data.slow || ''; } );
                    if ( reset ) { reset.hidden = data.model === data['default']; }
                    emit( 'model:changed', data );
                } ).catch( function ( err ) {
                    if ( note ) { note.textContent = err.message || ''; }
                } ).then( function () { select.disabled = false; } );
            };
            select.addEventListener( 'change', function () { save( select.value ); } );
            if ( reset ) {
                reset.addEventListener( 'click', function () { save( '' ); } );
            }
        } );
    }

    /* -------------------------------------------- model states and fallback */

    /**
     * Marks the stopped or unreachable models of the model selects below root (states: alias => { state, text,
     * disabled }, from expai::load or expai::states): the state after the label, not selectable; the link to
     * expai/models for users with expai/model_manage.
     */
    function applyModelStates( root, states, manage, selector ) {
        states = states || {};
        Array.prototype.forEach.call( root.querySelectorAll( selector || '.exp-ai-model-select' ), function ( select ) {
            Array.prototype.forEach.call( select.options, function ( option ) {
                // the text as built (without a state), kept once; the state goes after the model's name
                var base = option.getAttribute( 'data-text' );
                if ( base === null ) {
                    base = option.textContent;
                    option.setAttribute( 'data-text', base );
                }
                var label = option.getAttribute( 'data-label' ) || base;
                var rest = base.indexOf( label ) === 0 ? base.slice( label.length ) : '';
                var st = states[option.value];
                option.textContent = ( base.indexOf( label ) === 0 ? label : base ) + ( st ? ' · ' + st.text : '' ) + rest;
                option.disabled = !!( st && st.disabled );
            } );
        } );
        var any = Object.keys( states ).some( function ( alias ) { return states[alias].state === 'stopped'; } );
        Array.prototype.forEach.call( root.querySelectorAll( '.exp-ai-model-manage' ), function ( link ) { link.hidden = !( manage && any ); } );
    }

    /** Vector: its model select is built in the browser; the states come from expai::states, the notice from the step */
    function modelStatesHook() {
        var known = null;
        on( 'vector:open', function () {
            ( known ? Promise.resolve( known ) : api( 'states', {} ) ).then( function ( data ) {
                known = data;
                window.setTimeout( function () { known = null; }, 30000 );
                applyModelStates( document, data.states, data.manage, '.exp-ai-v-model' );
            } ).catch( function () { /* the select stays as built */ } );
        } );
        on( 'vector:step', function ( payload ) {
            var data = payload && payload.data;
            if ( !data || !data.fallback || !data.exchange ) {
                return;
            }
            var li = document.querySelector( '.exp-ai-v-x[data-id="' + data.exchange.id + '"]' );
            if ( li && !li.querySelector( '.exp-ai-fallback-notice' ) ) {
                var p = document.createElement( 'p' );
                p.className = 'exp-ai-fallback-notice';
                p.setAttribute( 'role', 'status' );
                p.textContent = data.fallback.notice;
                li.insertBefore( p, li.firstChild );
            }
        } );
    }

    /* ------------------------------------------------------------- dashboard */

    /** expai/dashboard: the buttons of the draft list (keep, send, discard), then the page reloads */
    function dashboard( root ) {
        root.addEventListener( 'click', function ( e ) {
            var button = e.target.closest( '.exp-ai-dash-action' );
            if ( !button || button.disabled ) {
                return;
            }
            var question = button.getAttribute( 'data-confirm' );
            if ( question && !window.confirm( question ) ) {
                return;
            }
            button.disabled = true;
            var note = root.querySelector( '.exp-ai-dash-note' );
            api( button.getAttribute( 'data-fn' ), { node_id: '0', drafts: button.getAttribute( 'data-key' ) } ).then( function ( data ) {
                var failed = ( data.results || [] ).filter( function ( r ) { return !r.ok; } );
                if ( failed.length && note ) {
                    note.textContent = failed.map( function ( r ) { return r.error; } ).join( ' ' );
                    button.disabled = false;
                    return;
                }
                window.location.reload();
            } ).catch( function ( err ) {
                if ( note ) {
                    note.textContent = err.message || '';
                }
                button.disabled = false;
            } );
        } );
    }

    /* ------------------------------------------------------------------ start */

    // admin4's switch and the system setting are handled by the stylesheet alone (expai.css, html[data-a4-theme]);
    // this marks the exceptions by the surface an element sits on: admin4l keeps its content boxes light in dark mode
    // (exp-ai-on-light), other designs make them dark without a switch (exp-ai-on-dark). Elements added later
    // (field bars, preset and alternative text bars) are picked up by the observer below.
    function surfaces() {
        function isDark( el ) {
            for ( var node = el; node && node.nodeType === 1; node = node.parentElement ) {
                var m = ( window.getComputedStyle( node ).backgroundColor || '' ).match( /rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?/ );
                if ( m && ( m[4] === undefined || parseFloat( m[4] ) > 0.5 ) ) {
                    return ( 0.2126 * m[1] + 0.7152 * m[2] + 0.0722 * m[3] ) < 128;
                }
            }
            return false;
        }
        function update() {
            var els = document.querySelectorAll( '.exp-ai, .exp-ai-badge' );
            var themeDark = document.documentElement.getAttribute( 'data-a4-theme' ) === 'dark';
            for ( var i = 0; i < els.length; i++ ) {
                var dark = isDark( els[i].parentElement );
                els[i].classList.toggle( 'exp-ai-on-dark', dark );
                els[i].classList.toggle( 'exp-ai-on-light', themeDark && !dark );
            }
        }
        update();
        if ( window.MutationObserver && document.body ) {
            var later = null;
            // only what was added and is, or holds, an Intelligence element: not a rescan on every change of the page
            var holdsExpAi = function ( node ) {
                return node.nodeType === 1 && ( ( node.matches && node.matches( '.exp-ai, .exp-ai-badge' ) ) || ( node.querySelector && node.querySelector( '.exp-ai, .exp-ai-badge' ) ) );
            };
            new MutationObserver( function ( mutations ) {
                var relevant = mutations.some( function ( m ) {
                    return Array.prototype.some.call( m.addedNodes, holdsExpAi );
                } );
                if ( relevant ) {
                    window.clearTimeout( later );
                    later = window.setTimeout( update, 100 );
                }
            } ).observe( document.body, { childList: true, subtree: true } );
        }
        if ( window.MutationObserver ) {
            new MutationObserver( function () { window.setTimeout( update, 50 ); } )
                .observe( document.documentElement, { attributes: true, attributeFilter: [ 'data-a4-theme', 'class' ] } );
        }
        if ( window.matchMedia ) {
            var mq = window.matchMedia( '(prefers-color-scheme: dark)' );
            if ( mq.addEventListener ) {
                mq.addEventListener( 'change', update );
            }
        }
        on( 'labels:done', update );
    }

    /* ------------------------------------------------- threads (doc/threads.md) */

    // the panel section "threads": the user's threads on this node, refreshed after each answer
    panelSectionInits.threads = function ( panelRoot ) {
        var box = panelRoot.querySelector( '.exp-ai-threads' );
        if ( !box ) {
            return;
        }
        var T = readJSON( box, '.exp-ai-thread-strings' );
        var list = box.querySelector( '.exp-ai-thread-list' );
        function load() {
            api( 'expaivector::threads', { node: box.getAttribute( 'data-node-id' ), shared: '0', limit: 5 } ).then( function ( data ) {
                var rows = data.threads || [];
                box.querySelector( '.exp-ai-thread-empty' ).hidden = !!rows.length;
                box.querySelector( '.exp-ai-thread-links' ).hidden = !data.has_vector;
                list.innerHTML = rows.map( function ( t, i ) {
                    var title = esc( t.title );
                    return '<li class="exp-ai-thread-row exp-ai-thread-' + esc( t.state ) + '"><span class="exp-ai-thread-dot" aria-hidden="true"></span>'
                         + ( data.has_vector ? '<a href="' + esc( url( 'expai/vector/(thread)/' + t.uuid ) ) + '" title="' + esc( T.open ) + '">' + title + '</a>' : title )
                         + '<span class="exp-ai-thread-meta">' + esc( ( i === 0 && t.origin === 'panel' && t.shelf !== 'settled' ? T.this_conversation + ' · ' : '' )
                         + ( T['state_' + t.state] || t.state ) + ( t.cost ? ' · ' + t.cost : '' ) ) + '</span></li>';
                } ).join( '' );
            } ).catch( function () {
                box.hidden = true;
            } );
        }
        load();
        on( 'panel:done', load );
        on( 'panel:decided', load );
    };

    // Ctrl/Cmd+K on any admin page (outside fields and rich text editors): the palette, loaded on first use
    function paletteHook() {
        var stored = '';
        var storeKey = paletteStoreKey();
        try { stored = window.localStorage.getItem( storeKey ) || ''; } catch ( e ) { stored = ''; }
        // "off:<time>": no palette for this user (no access, or switched off); asked again after a day
        if ( stored.indexOf( 'off' ) === 0 ) {
            if ( Date.now() - ( parseInt( stored.slice( 4 ), 10 ) || 0 ) < 86400000 ) {
                return;
            }
            stored = '';
        }
        var loading = null;
        function defaultMatch( e ) {
            var mac = /mac|iphone|ipad|ipod/i.test( ( navigator.userAgentData && navigator.userAgentData.platform ) || navigator.platform || navigator.userAgent || '' );
            return e.code === 'KeyK' && ( mac ? e.metaKey && !e.ctrlKey : e.ctrlKey && !e.metaKey ) && !e.altKey && !e.shiftKey;
        }
        function load() {
            if ( window.ExpAiPalette && window.ExpAiPalette.config().strings.title ) {
                return Promise.resolve();
            }
            if ( !loading ) {
                loading = api( 'expaivector::palette', {} ).then( function ( cfg ) {
                    if ( cfg.global === false ) {
                        // [KeyBindings] GlobalPalette=disabled: the palette only in Vector
                        try { window.localStorage.setItem( storeKey, 'off:' + Date.now() ); } catch ( e ) { /* storage may be off */ }
                        throw { message: '', kind: 'off' };
                    }
                    var gotScripts = window.ExpAiPalette ? Promise.resolve() : Promise.all( ( cfg.scripts || [] ).map( function ( src ) {
                        return new Promise( function ( resolve, reject ) {
                            var s = document.createElement( 'script' );
                            s.src = src;
                            s.onload = resolve;
                            s.onerror = reject;
                            document.head.appendChild( s );
                        } );
                    } ) );
                    return gotScripts.then( function () {
                        window.ExpAiPalette.configure( cfg );
                        try { window.localStorage.setItem( storeKey, cfg.bindings && cfg.bindings.palette ? cfg.bindings.palette.keys : 'off:' + Date.now() ); } catch ( e ) { /* storage may be off */ }
                    } );
                } ).catch( function ( err ) {
                    loading = null;
                    // no access to Exponential Intelligence: leave Ctrl+K to the browser from now on
                    if ( err && err.kind === 'server' ) {
                        try { window.localStorage.setItem( storeKey, 'off:' + Date.now() ); } catch ( e ) { /* storage may be off */ }
                    }
                    throw err;
                } );
            }
            return loading;
        }
        document.addEventListener( 'keydown', function ( e ) {
            // Vector handles its own keys
            if ( window.ExpAiVectorApp || e.defaultPrevented || e.isComposing || e.repeat ) {
                return;
            }
            var keys = window.ExpAiKeys && window.ExpAiPalette && window.ExpAiPalette.config().bindings.palette ? window.ExpAiPalette.config().bindings.palette.keys : stored;
            var hit = window.ExpAiKeys && keys ? window.ExpAiKeys.matches( e, keys ) : defaultMatch( e );
            if ( !hit ) {
                return;
            }
            var t = e.target;
            var inEditor = t && t.closest && ( t.isContentEditable || t.closest( '[contenteditable="true"], .ProseMirror, .tox, .mce-content-body' ) );
            var inField = t && /^(INPUT|TEXTAREA|SELECT)$/.test( t.tagName );
            if ( inEditor || inField ) {
                return;
            }
            e.preventDefault();
            load().then( function () { window.ExpAiPalette.open(); } ).catch( function () { /* the palette stays closed */ } );
        } );
    }

    function start() {
        var panelRoot = document.getElementById( 'exp-ai-panel' );
        // #exp-ai in the address (the dashboard's "Start writing") opens the tab
        if ( panelRoot && window.location.hash === '#exp-ai' && !panelRoot.offsetParent ) {
            var tabLink = document.querySelector( '#node-tab-expai a, #node-tab-expai_media a' );
            if ( tabLink ) {
                // after the admin's tab script has bound its click handler
                window.addEventListener( 'load', function () { window.setTimeout( function () { tabLink.click(); }, 50 ); } );
            }
        }
        if ( panelRoot ) {
            panel( panelRoot );
        }
        var dashRoot = document.getElementById( 'exp-ai-dashboard' );
        if ( dashRoot ) {
            dashboard( dashRoot );
        }
        var editRoot = document.getElementById( 'exp-ai-edit' );
        if ( editRoot ) {
            // after the editors (Online Editor, Tiptap) had their turn
            window.setTimeout( function () { edit( editRoot ); }, 0 );
        }
        models();
        modelStatesHook();
        chainPickers();
        surfaces();
        labels();
        paletteHook();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', start );
    } else {
        start();
    }
})( window, document );
