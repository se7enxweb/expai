/*!
 * expai - the command palette and the keyboard (doc/keyboard.md)
 * Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * GNU General Public License v2.0 (or any later version)
 *
 * Loaded by Vector (ezscript_require) and, on every other admin page, by expai.js on the first Ctrl/Cmd+K
 * (expaivector::palette returns this script, the texts and the user's key bindings). Three parts:
 *
 *   ExpAiKeys      bindings ("Mod+KeyK", "Alt+ArrowDown", "Escape Escape", "?") matched on KeyboardEvent.code with
 *                  Cmd on macOS and Ctrl elsewhere for "Mod"; contexts global | vector | list | composer
 *   ExpAi.commands the command registry: register( id, { label, group, keys, available(), run() } )
 *   ExpAiPalette   the palette (commands, threads, nodes, models, field actions of an edit form) and the shortcut
 *                  sheet (with the user's own bindings). Both are modal dialogs with a focus trap.
 */
(function ( window, document ) {
    'use strict';

    if ( window.ExpAiPalette ) {
        return;
    }
    var ExpAi = window.ExpAi;
    if ( !ExpAi ) {
        return;
    }
    var esc = ExpAi.esc;
    var fmt = ExpAi.format;
    var isMac = /mac|iphone|ipad|ipod/i.test( ( navigator.userAgentData && navigator.userAgentData.platform ) || navigator.platform || navigator.userAgent || '' );

    /* ------------------------------------------------------------------- keys */

    function parseChord( chord ) {
        var parts = String( chord ).split( '+' );
        var key = parts.pop();
        if ( key === '' ) {
            key = '+';
        }
        var c = { mod: false, ctrl: false, alt: false, shift: false, meta: false, code: '', char: '' };
        parts.forEach( function ( p ) {
            var k = p.toLowerCase();
            if ( c.hasOwnProperty( k ) ) {
                c[k] = true;
            }
        } );
        if ( /^[A-Z][A-Za-z0-9]+$/.test( key ) ) {
            c.code = key;
        } else {
            c.char = key;
        }
        return c;
    }

    function parse( keys ) {
        return String( keys || '' ).split( ' ' ).filter( Boolean ).map( parseChord );
    }

    function chordMatches( e, c ) {
        var wantCtrl = c.ctrl || ( c.mod && !isMac );
        var wantMeta = c.meta || ( c.mod && isMac );
        if ( !!e.ctrlKey !== wantCtrl || !!e.metaKey !== wantMeta || !!e.altKey !== c.alt ) {
            return false;
        }
        if ( c.char ) {
            // a character binding ("?") ignores Shift: the layout decides how the character is typed
            return e.key === c.char;
        }
        return !!e.shiftKey === c.shift && e.code === c.code;
    }

    var pending = { keys: '', at: 0 };

    /** @return bool the event completes the binding (a two-chord binding needs its first chord within 600 ms before) */
    function matches( e, keys ) {
        var chords = parse( keys );
        if ( !chords.length ) {
            return false;
        }
        if ( chords.length === 1 ) {
            return chordMatches( e, chords[0] );
        }
        if ( !chordMatches( e, chords[1] ) && !chordMatches( e, chords[0] ) ) {
            return false;
        }
        var now = Date.now();
        if ( pending.keys === keys && now - pending.at <= 600 && chordMatches( e, chords[1] ) ) {
            pending = { keys: '', at: 0 };
            return true;
        }
        if ( chordMatches( e, chords[0] ) ) {
            pending = { keys: keys, at: now };
        }
        return false;
    }

    /** @return bool the first chord of a two-chord binding was just pressed (for "press once more" hints) */
    function half( keys ) {
        return pending.keys === keys && Date.now() - pending.at <= 600;
    }

    function display( keys ) {
        return parse( keys ).map( function ( c ) {
            var out = [];
            if ( c.mod ) { out.push( isMac ? '⌘' : 'Ctrl' ); }
            if ( c.ctrl ) { out.push( 'Ctrl' ); }
            if ( c.meta ) { out.push( isMac ? '⌘' : 'Meta' ); }
            if ( c.alt ) { out.push( isMac ? '⌥' : 'Alt' ); }
            if ( c.shift ) { out.push( isMac ? '⇧' : 'Shift' ); }
            var names = { Enter: '↵', Escape: 'Esc', ArrowDown: '↓', ArrowUp: '↑', ArrowLeft: '←', ArrowRight: '→', Slash: '/', Space: 'Space' };
            var key = c.char || ( names[c.code] || c.code.replace( /^Key|^Digit/, '' ) );
            out.push( key );
            return out.join( isMac ? '' : ' ' );
        } ).join( ' ' );
    }

    /** @return string the binding of a keydown event ("Mod+KeyK"), for the shortcut sheet's recorder; '' for a lone modifier */
    function record( e ) {
        if ( /^(Shift|Control|Alt|Meta|OS)/.test( e.key ) ) {
            return '';
        }
        var parts = [];
        if ( isMac ? e.metaKey : e.ctrlKey ) { parts.push( 'Mod' ); }
        if ( isMac && e.ctrlKey ) { parts.push( 'Ctrl' ); }
        if ( e.altKey ) { parts.push( 'Alt' ); }
        if ( e.shiftKey && /^(Key|Digit|Arrow|Enter|Escape|Space|Tab|Backspace)/.test( e.code ) ) { parts.push( 'Shift' ); }
        if ( /^(Key[A-Z]|Digit\d|Arrow\w+|Enter|Escape|Space|F\d+|Backspace|Delete|Home|End|PageUp|PageDown|BracketLeft|BracketRight|Comma|Period|Slash|Minus|Equal)$/.test( e.code ) ) {
            parts.push( e.code );
        } else if ( e.key && e.key.length === 1 ) {
            return e.key;
        } else {
            return '';
        }
        return parts.join( '+' );
    }

    function isField( el ) {
        if ( !el || el.nodeType !== 1 ) {
            return false;
        }
        var tag = el.tagName;
        if ( tag === 'TEXTAREA' || tag === 'SELECT' ) {
            return true;
        }
        if ( tag === 'INPUT' ) {
            return !/^(button|submit|reset|checkbox|radio|image|file|range|color)$/i.test( el.type || 'text' );
        }
        return isRich( el );
    }

    /** a rich text editor (Online Editor, Tiptap, any contenteditable): Ctrl+K inserts a link there */
    function isRich( el ) {
        return !!( el && el.closest && ( el.isContentEditable || el.closest( '[contenteditable="true"], .ProseMirror, .tox, .mce-content-body, .mceEditor' ) ) );
    }

    /**
     * @param KeyboardEvent e
     * @param string when global | vector | list | composer
     * @param Element root the Vector root (null elsewhere)
     */
    function allowed( e, when, keys, root ) {
        var el = e.target;
        var inVector = !!( root && el && root.contains( el ) ) || !!( root && el === document.body );
        var field = isField( el );
        var strong = parse( keys ).every( function ( c ) { return c.mod || c.ctrl || c.alt || c.meta || c.code === 'Escape'; } );
        switch ( when ) {
            case 'global':
                return !isRich( el ) && ( !field || ( inVector && strong ) );
            case 'vector':
                return !!root && !isRich( el ) && ( !field || strong );
            case 'list':
                return !!root && !field;
            case 'composer':
                return !!root && !!el && !!el.closest && !!el.closest( '.exp-ai-v-composer' );
        }
        return false;
    }

    var Keys = window.ExpAiKeys = { parse: parse, matches: matches, half: half, display: display, record: record, allowed: allowed,
                                    isField: isField, isRich: isRich, isMac: isMac };

    /* --------------------------------------------------------------- commands */

    var commands = {};
    var waiting = ExpAi.commands && ExpAi.commands.pending ? ExpAi.commands.pending : [];
    var order = [];
    ExpAi.commands = {
        register: function ( id, def ) {
            if ( !commands[id] ) {
                order.push( id );
            }
            commands[id] = def || {};
        },
        get: function ( id ) { return commands[id]; },
        list: function () { return order.map( function ( id ) { return extend( { id: id }, commands[id] ); } ); },
        run: function ( id, arg ) {
            var c = commands[id];
            if ( c && ( !c.available || c.available() ) ) {
                c.run( arg );
                return true;
            }
            return false;
        }
    };
    // shallow merge that skips undefined values, as jQuery.extend did
    function extend( target ) {
        for ( var i = 1; i < arguments.length; i++ ) {
            var source = arguments[i] || {};
            for ( var key in source ) {
                if ( Object.prototype.hasOwnProperty.call( source, key ) && source[key] !== undefined ) {
                    target[key] = source[key];
                }
            }
        }
        return target;
    }
    // commands registered before the palette was loaded (expai.js keeps them)
    waiting.forEach( function ( c ) { ExpAi.commands.register( c[0], c[1] ); } );

    /* ---------------------------------------------------------------- palette */

    var config = { strings: {}, labels: {}, bindings: {}, has_vector: false, models: null, user_overrides: true };
    var S = function ( key, args ) { return fmt( config.strings[key] || key, args || {} ); };
    var overlay = null;
    var state = { open: false, mode: 'all', items: [], active: 0, restore: null, timer: null, seq: 0 };

    function url( path ) {
        return ExpAi.url ? ExpAi.url( path ) : '/' + path;
    }

    function trap( box, e ) {
        if ( e.key !== 'Tab' ) {
            return;
        }
        var focusable = Array.prototype.filter.call( box.querySelectorAll( 'button, input, select, textarea, [tabindex]:not([tabindex="-1"]), a[href]' ),
            function ( el ) { return !el.disabled && el.offsetParent !== null; } );
        if ( !focusable.length ) {
            return;
        }
        var first = focusable[0], last = focusable[focusable.length - 1];
        if ( e.shiftKey && document.activeElement === first ) {
            e.preventDefault();
            last.focus();
        } else if ( !e.shiftKey && document.activeElement === last ) {
            e.preventDefault();
            first.focus();
        }
    }

    function build() {
        if ( overlay ) {
            return overlay;
        }
        overlay = document.createElement( 'div' );
        overlay.className = 'exp-ai exp-ai-palette-overlay';
        overlay.hidden = true;
        overlay.innerHTML = '<div class="exp-ai-palette" role="dialog" aria-modal="true" aria-label="' + esc( S( 'title' ) ) + '">'
            + '<div class="exp-ai-palette-search"><span class="exp-ai-mark" aria-hidden="true"></span>'
            + '<input type="text" class="exp-ai-palette-input" role="combobox" aria-expanded="false" aria-controls="exp-ai-palette-list" aria-autocomplete="list" autocomplete="off" spellcheck="false" placeholder="' + esc( S( 'placeholder' ) ) + '" />'
            + '<button type="button" class="exp-ai-palette-close" aria-label="' + esc( S( 'close' ) ) + '">Esc</button></div>'
            + '<ul class="exp-ai-palette-list" id="exp-ai-palette-list" role="listbox" aria-label="' + esc( S( 'title' ) ) + '"></ul>'
            + '<p class="exp-ai-sr exp-ai-palette-status" role="status" aria-live="polite" aria-atomic="true"></p>'
            + '<p class="exp-ai-palette-foot">' + esc( S( 'hint' ) ) + '</p></div>';
        document.body.appendChild( overlay );
        var input = overlay.querySelector( 'input' );
        overlay.addEventListener( 'mousedown', function ( e ) {
            if ( e.target === overlay ) {
                close();
            }
        } );
        overlay.querySelector( '.exp-ai-palette-close' ).addEventListener( 'click', close );
        input.addEventListener( 'input', function () { refresh(); } );
        overlay.addEventListener( 'keydown', function ( e ) {
            // an input method composing text (Enter confirms the composition) or a key held down does not act
            if ( e.isComposing || e.keyCode === 229 || ( e.repeat && e.key !== 'ArrowDown' && e.key !== 'ArrowUp' ) ) {
                return;
            }
            if ( e.key === 'Escape' ) {
                e.preventDefault();
                e.stopPropagation();
                if ( state.mode !== 'all' ) {
                    state.mode = 'all';
                    input.value = '';
                    refresh();
                } else {
                    close();
                }
            } else if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
                e.preventDefault();
                move( e.key === 'ArrowDown' ? 1 : -1 );
            } else if ( e.key === 'Enter' ) {
                e.preventDefault();
                choose( state.active );
            } else {
                trap( overlay.querySelector( '.exp-ai-palette' ), e );
            }
        } );
        overlay.querySelector( '.exp-ai-palette-list' ).addEventListener( 'click', function ( e ) {
            var li = e.target.closest( '[data-index]' );
            if ( li ) {
                choose( parseInt( li.getAttribute( 'data-index' ), 10 ) );
            }
        } );
        return overlay;
    }

    function score( text, q ) {
        text = String( text || '' ).toLowerCase();
        if ( !q ) {
            return 1;
        }
        var i = text.indexOf( q );
        if ( i === 0 ) {
            return 3;
        }
        if ( i > 0 ) {
            return 2;
        }
        // all words of the query appear
        return q.split( /\s+/ ).every( function ( w ) { return text.indexOf( w ) !== -1; } ) ? 1 : 0;
    }

    function commandItems( q ) {
        return ExpAi.commands.list().filter( function ( c ) {
            return c.label && ( !c.available || c.available() ) && !c.hidden && score( typeof c.label === 'function' ? c.label() : c.label, q );
        } ).map( function ( c ) {
            var label = typeof c.label === 'function' ? c.label() : c.label;
            var keys = config.bindings[c.binding || c.id] ? config.bindings[c.binding || c.id].keys : '';
            return { group: S( 'group_commands' ), label: label, keys: keys, score: score( label, q ), run: function () { ExpAi.commands.run( c.id ); } };
        } ).sort( function ( a, b ) { return b.score - a.score; } );
    }

    function fieldItems( q ) {
        var out = [];
        Array.prototype.forEach.call( document.querySelectorAll( '.exp-ai-field-bar .exp-ai-chip' ), function ( chip ) {
            var block = chip.closest( '.block, .exp-ai-field, fieldset, div' );
            var bar = chip.closest( '.exp-ai-field-bar' );
            var owner = bar && bar.parentNode;
            var label = owner ? owner.querySelector( ':scope > label, :scope > fieldset > legend, :scope > legend' ) : null;
            var field = label ? label.textContent.replace( /[:*]/g, '' ).trim() : '';
            var text = S( 'field_action', { '%action': chip.textContent.trim(), '%field': field } );
            if ( score( text, q ) ) {
                out.push( { group: S( 'group_fields' ), label: text, score: score( text, q ), run: function () {
                    ( block || chip ).scrollIntoView( { block: 'center' } );
                    chip.focus();
                    chip.click();
                } } );
            }
        } );
        return out.slice( 0, 12 );
    }

    function modelItems( q ) {
        var out = [];
        var models = config.models;
        if ( !models ) {
            return out;
        }
        ( models.groups || [] ).forEach( function ( g ) {
            ( g.models || [] ).forEach( function ( m ) {
                var text = m.label + ' · ' + g.label;
                if ( score( text, q ) ) {
                    out.push( { group: S( 'group_models' ), label: text, hint: m.alias === models.current ? S( 'model_current' ) : '', score: score( text, q ),
                        run: function () {
                            if ( window.ExpAiVectorApp && window.ExpAiVectorApp.setModel( m.alias ) ) {
                                return;
                            }
                            ExpAi.api( 'model', { model: m.alias } ).then( function ( data ) {
                                models.current = data.model;
                                toast( S( 'model_switched', { '%model': data.label || m.label } ) );
                            } ).catch( function ( err ) { toast( S( 'error' ) + ' ' + ( err.message || '' ) ); } );
                        } } );
                }
            } );
        } );
        return out;
    }

    /** The live region: the number of results, or that the search runs / found nothing */
    function announce( text ) {
        var box = overlay && overlay.querySelector( '.exp-ai-palette-status' );
        if ( box ) {
            box.textContent = text;
        }
    }

    function render() {
        var list = overlay.querySelector( '.exp-ai-palette-list' );
        var input = overlay.querySelector( 'input' );
        if ( !state.items.length ) {
            var none = state.loading ? S( 'searching' ) : S( 'empty' );
            list.innerHTML = '<li class="exp-ai-palette-empty" role="presentation">' + esc( none ) + '</li>';
            input.removeAttribute( 'aria-activedescendant' );
            input.setAttribute( 'aria-expanded', 'false' );
            announce( none );
            return;
        }
        var html = '';
        var group = null;
        state.items.forEach( function ( item, i ) {
            if ( item.group !== group ) {
                if ( group !== null ) {
                    html += '</ul></li>';
                }
                group = item.group;
                html += '<li role="group" aria-label="' + esc( group ) + '" class="exp-ai-palette-groupbox"><div class="exp-ai-palette-group" aria-hidden="true">' + esc( group ) + '</div>'
                      + '<ul class="exp-ai-palette-sub" role="presentation">';
            }
            html += '<li id="exp-ai-palette-opt-' + i + '" role="option" data-index="' + i + '" aria-selected="' + ( i === state.active ? 'true' : 'false' ) + '"'
                  + ( i === state.active ? ' class="exp-ai-active"' : '' ) + '><span class="exp-ai-palette-label">' + esc( item.label ) + '</span>'
                  + ( item.hint ? '<span class="exp-ai-palette-hint">' + esc( item.hint ) + '</span>' : '' )
                  + ( item.keys ? '<kbd>' + esc( display( item.keys ) ) + '</kbd>' : '' ) + '</li>';
        } );
        list.innerHTML = html + '</ul></li>';
        input.setAttribute( 'aria-expanded', 'true' );
        input.setAttribute( 'aria-activedescendant', 'exp-ai-palette-opt-' + state.active );
        // while the search for threads and nodes still runs the count is not final: say so, then the number
        announce( state.loading ? S( 'searching' ) : S( state.items.length === 1 ? 'results_1' : 'results_n', { '%count': state.items.length } ) );
        var active = list.querySelector( '.exp-ai-active' );
        if ( active && active.scrollIntoView ) {
            active.scrollIntoView( { block: 'nearest' } );
        }
    }

    function move( delta ) {
        if ( !state.items.length ) {
            return;
        }
        state.active = ( state.active + delta + state.items.length ) % state.items.length;
        render();
    }

    function choose( i ) {
        var item = state.items[i];
        if ( !item ) {
            return;
        }
        if ( item.keep ) {
            item.run();
            return;
        }
        close( true );
        item.run();
    }

    function refresh() {
        var q = overlay.querySelector( 'input' ).value.trim().toLowerCase();
        var seq = ++state.seq;
        var items;
        if ( state.mode === 'models' ) {
            items = modelItems( q );
        } else {
            items = commandItems( q ).concat( fieldItems( q ) );
            if ( q.length >= 2 ) {
                items = items.concat( modelItems( q ).slice( 0, 4 ) );
            }
        }
        state.items = items;
        state.active = 0;
        state.loading = false;
        render();
        window.clearTimeout( state.timer );
        if ( state.mode !== 'all' ) {
            return;
        }
        // threads (recent ones, or matching titles) and nodes (at least 2 characters or an id), after a short pause
        state.loading = true;
        state.timer = window.setTimeout( function () {
            var asks = [];
            if ( config.has_vector ) {
                asks.push( ExpAi.api( 'expaivector::threads', { search: q.length >= 2 ? q : '', shared: '0', limit: q.length >= 2 ? 8 : 5 } ).then( function ( data ) {
                    return ( data.threads || [] ).map( function ( t ) {
                        return { group: S( 'group_threads' ), label: t.title, hint: t.cost || '', run: function () { openThread( t.uuid ); } };
                    } );
                } ).catch( function () { return []; } ) );
            }
            if ( q.length >= 2 || /^\d+$/.test( q ) ) {
                asks.push( ExpAi.api( 'expaivector::nodes', { q: q } ).then( function ( data ) {
                    return ( data.nodes || [] ).map( function ( n ) {
                        return { group: S( 'group_nodes' ), label: n.name, hint: n.parent ? n.parent + ' · ' + n.class : n.class,
                                 run: function () { window.location.href = url( n.url ); } };
                    } );
                } ).catch( function () { return []; } ) );
            }
            Promise.all( asks ).then( function ( lists ) {
                if ( seq !== state.seq || !state.open ) {
                    return;
                }
                state.loading = false;
                lists.forEach( function ( l ) { state.items = state.items.concat( l ); } );
                render();
            } );
        }, 200 );
    }

    function openThread( uuid ) {
        if ( window.ExpAiVectorApp ) {
            window.ExpAiVectorApp.open( uuid );
        } else {
            window.location.href = url( 'expai/vector/(thread)/' + uuid );
        }
    }

    function open( opts ) {
        build();
        if ( state.open ) {
            overlay.querySelector( 'input' ).focus();
            return;
        }
        state.open = true;
        state.mode = opts && opts.mode ? opts.mode : 'all';
        state.restore = document.activeElement;
        overlay.hidden = false;
        document.documentElement.classList.add( 'exp-ai-palette-open' );
        var input = overlay.querySelector( 'input' );
        input.value = '';
        refresh();
        input.focus();
    }

    function close( keepFocusMove ) {
        if ( !overlay || !state.open ) {
            return;
        }
        state.open = false;
        window.clearTimeout( state.timer );
        overlay.hidden = true;
        document.documentElement.classList.remove( 'exp-ai-palette-open' );
        if ( !keepFocusMove && state.restore && state.restore.focus ) {
            state.restore.focus();
        }
    }

    /* ------------------------------------------------------------------ toast */

    var toastBox = null;
    function toast( text ) {
        if ( window.ExpAiVectorApp && window.ExpAiVectorApp.toast ) {
            window.ExpAiVectorApp.toast( text );
            return;
        }
        if ( !toastBox ) {
            toastBox = document.createElement( 'div' );
            toastBox.className = 'exp-ai exp-ai-palette-toast';
            toastBox.setAttribute( 'role', 'status' );
            toastBox.setAttribute( 'aria-live', 'polite' );
            document.body.appendChild( toastBox );
        }
        toastBox.textContent = text;
        toastBox.hidden = false;
        window.clearTimeout( toastBox.timer );
        toastBox.timer = window.setTimeout( function () { toastBox.hidden = true; }, 4000 );
    }

    /* --------------------------------------------------------- shortcut sheet */

    var sheet = null;
    var recording = null;

    function sheetRows() {
        return Object.keys( config.bindings ).map( function ( id ) {
            var b = config.bindings[id];
            var changed = b.keys !== b['default'];
            return '<tr data-command="' + esc( id ) + '"><th scope="row">' + esc( config.labels[id] || id ) + '</th>'
                 + '<td><kbd>' + esc( display( b.keys ) ) + '</kbd>' + ( changed ? ' <span class="exp-ai-palette-hint">' + esc( S( 'changed' ) ) + '</span>' : '' ) + '</td>'
                 + ( config.user_overrides ? '<td><button type="button" class="exp-ai-link" data-record="' + esc( id ) + '">' + esc( S( 'change' ) ) + '</button>'
                   + ( changed ? ' <button type="button" class="exp-ai-link" data-reset="' + esc( id ) + '">' + esc( S( 'reset' ) ) + '</button>' : '' ) + '</td>' : '' )
                 + '</tr>';
        } ).join( '' );
    }

    function help() {
        close( true );
        if ( !sheet ) {
            sheet = document.createElement( 'div' );
            sheet.className = 'exp-ai exp-ai-palette-overlay exp-ai-sheet-overlay';
            document.body.appendChild( sheet );
            sheet.addEventListener( 'mousedown', function ( e ) {
                if ( e.target === sheet ) {
                    closeHelp();
                }
            } );
            sheet.addEventListener( 'keydown', function ( e ) {
                if ( recording ) {
                    e.preventDefault();
                    e.stopPropagation();
                    if ( e.key === 'Escape' && !e.ctrlKey && !e.metaKey && !e.altKey ) {
                        recording = null;
                        drawSheet();
                        return;
                    }
                    var keys = record( e );
                    if ( keys ) {
                        var id = recording;
                        recording = null;
                        save( id, keys );
                    }
                    return;
                }
                if ( e.key === 'Escape' ) {
                    e.preventDefault();
                    e.stopPropagation();
                    closeHelp();
                    return;
                }
                trap( sheet.querySelector( '.exp-ai-palette' ), e );
            } );
            sheet.addEventListener( 'click', function ( e ) {
                var rec = e.target.closest( '[data-record]' );
                var reset = e.target.closest( '[data-reset]' );
                if ( e.target.closest( '.exp-ai-palette-close' ) ) {
                    closeHelp();
                } else if ( rec ) {
                    recording = rec.getAttribute( 'data-record' );
                    drawSheet();
                } else if ( reset ) {
                    save( reset.getAttribute( 'data-reset' ), '' );
                } else if ( e.target.closest( '.exp-ai-sheet-reset-all' ) ) {
                    ExpAi.api( 'expaivector::keybindings', { reset: '1' } ).then( applied ).catch( failed );
                }
            } );
        }
        sheet.restore = document.activeElement;
        drawSheet();
        sheet.hidden = false;
        var first = sheet.querySelector( 'button' );
        if ( first ) {
            first.focus();
        }
    }

    function drawSheet() {
        sheet.innerHTML = '<div class="exp-ai-palette exp-ai-sheet" role="dialog" aria-modal="true" aria-labelledby="exp-ai-sheet-title">'
            + '<div class="exp-ai-sheet-head"><h2 id="exp-ai-sheet-title">' + esc( S( 'shortcuts' ) ) + '</h2>'
            + '<button type="button" class="exp-ai-palette-close" aria-label="' + esc( S( 'close' ) ) + '">Esc</button></div>'
            + ( recording ? '<p class="exp-ai-sheet-recording" role="status">' + esc( S( 'press_keys', { '%command': config.labels[recording] || recording } ) ) + '</p>' : '' )
            + '<p class="exp-ai-sheet-error" role="alert" hidden></p>'
            + '<table class="exp-ai-sheet-table"><tbody>' + sheetRows() + '</tbody></table>'
            + ( config.user_overrides ? '<p class="exp-ai-sheet-foot"><button type="button" class="exp-ai-link exp-ai-sheet-reset-all">' + esc( S( 'reset_all' ) ) + '</button></p>' : '' )
            + '</div>';
        if ( recording ) {
            var b = sheet.querySelector( '[data-record="' + recording + '"]' );
            if ( b ) {
                b.focus();
            }
        }
    }

    function applied( data ) {
        config.bindings = data.bindings || config.bindings;
        try { window.localStorage.setItem( ExpAi.paletteStoreKey ? ExpAi.paletteStoreKey() : 'exp-ai-palette-keys', config.bindings.palette ? config.bindings.palette.keys : '' ); } catch ( e ) { /* storage may be off */ }
        if ( window.ExpAiVectorApp ) {
            window.ExpAiVectorApp.bindingsChanged( config.bindings );
        }
        drawSheet();
    }

    function failed( err ) {
        var box = sheet && sheet.querySelector( '.exp-ai-sheet-error' );
        if ( box ) {
            box.hidden = false;
            box.textContent = S( 'error' ) + ' ' + ( err.message || '' );
        }
    }

    function save( id, keys ) {
        var data = {};
        data[id] = keys;
        ExpAi.api( 'expaivector::keybindings', { bindings: JSON.stringify( data ) } ).then( applied ).catch( failed );
    }

    function closeHelp() {
        if ( sheet ) {
            sheet.hidden = true;
            recording = null;
            if ( sheet.restore && sheet.restore.focus ) {
                sheet.restore.focus();
            }
        }
    }

    /* -------------------------------------------------------------- commands */

    function here() {
        var panel = document.getElementById( 'exp-ai-panel' );
        var m = window.location.pathname.match( /\/content\/view\/full\/(\d+)/ );
        var id = panel ? panel.getAttribute( 'data-node-id' ) : ( m ? m[1] : '' );
        var name = '';
        var title = document.querySelector( '#maincontent h1, .content-view-full h1, h1.context-title' );
        if ( title ) {
            name = title.textContent.trim();
        }
        return id ? { id: id, name: name } : null;
    }

    ExpAi.commands.register( 'open_vector', { label: function () { return S( 'open_vector' ); }, group: 'commands',
        available: function () { return config.has_vector && !window.ExpAiVectorApp; },
        run: function () { window.location.href = url( 'expai/vector' ); } } );
    ExpAi.commands.register( 'new_thread_here', { label: function () { var h = here(); return S( 'new_thread_here', { '%name': h && h.name ? h.name : '#' + ( h ? h.id : '' ) } ); },
        available: function () { return config.has_vector && !window.ExpAiVectorApp && !!here(); },
        run: function () { window.location.href = url( 'expai/vector/(node)/' + here().id ); } } );
    ExpAi.commands.register( 'switch_model', { label: function () { return S( 'switch_model' ); }, binding: 'model',
        available: function () { return !!config.models; },
        run: function () { window.setTimeout( function () { open( { mode: 'models' } ); }, 0 ); } } );
    ExpAi.commands.register( 'shortcuts', { label: function () { return S( 'shortcuts' ); }, binding: 'help',
        run: function () { window.setTimeout( help, 0 ); } } );

    window.ExpAiPalette = {
        configure: function ( cfg ) {
            config = extend( config, cfg || {} );
            config.strings = extend( { changed: 'changed', change: 'Change', reset: 'Reset', reset_all: 'Reset all shortcuts', press_keys: 'Press the new keys for "%command" (Esc cancels)' },
                                       config.strings || {} );
            if ( overlay ) {
                overlay.parentNode.removeChild( overlay );
                overlay = null;
            }
        },
        config: function () { return config; },
        open: open,
        close: close,
        isOpen: function () { return state.open || !!( sheet && !sheet.hidden ); },
        help: help,
        toast: toast
    };
})( window, document );
