/*!
 * expai - Exponential Intelligence
 * Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * GNU General Public License v2.0 (or any later version)
 *
 * Editor presets and the Improve menu of the content edit form (doc/presets.md). Loaded by expai/edit/presets.tpl
 * after expai.js; reads the JSON block .exp-ai-preset-config and, once expai.js has drawn its field bars:
 *
 *   - adds the preset buttons ("Suggest title", "Write summary", "Meta description", "Keywords") next to every field
 *     a preset is mapped to, including the parts (title, description, keywords) of an xrowmetadata field;
 *   - adds one "Improve" menu to every text field (shorten, expand, clarify, fix grammar, ...);
 *   - registers each preset and each Improve menu in the command palette (ExpAi.commands, doc/keyboard.md).
 *
 * A click sends expaipreset::preset and puts the answer into the form, highlighted, with Undo; nothing is stored
 * until the editor stores the draft. An answer that would lose structure of a rich text field, or a field changed
 * while the answer was written, is shown for review instead (ExpAi.guardedApply, strings of the edit form).
 * Events: ExpAi.emit( 'field:filled', { field, value, previous, preset } ).
 */
(function ( window, document ) {
    'use strict';

    function start() {
        var ExpAi = window.ExpAi;
        var root = document.getElementById( 'exp-ai-edit' );
        var node = root ? root.querySelector( '.exp-ai-preset-config' ) : null;
        if ( !ExpAi || !root || !node || root.getAttribute( 'data-exp-ai-presets' ) ) {
            return;
        }
        root.setAttribute( 'data-exp-ai-presets', '1' );
        var C;
        try { C = JSON.parse( node.textContent ) || {}; } catch ( e ) { ( window.console && console.warn && console.warn( 'expai: a configuration block is not valid JSON', e ) ); return; }
        var S = C.strings || {};
        var esc = ExpAi.esc;
        var edit = ExpAi.readJSON ? ExpAi.readJSON( root, '.exp-ai-edit-config' ) : {};
        var form = root.closest( 'form' ) || document.getElementById( 'editform' );
        if ( !form ) {
            return;
        }
        var R = edit.strings || {};
        var writable = edit.writable || [];
        var attributes = edit.attributes || [];

        /* ---- reading and writing a field or a part of an xrowmetadata field ---- */

        function partInput( ctx ) {
            var suffix = '_xrowmetadata_data_array_' + ctx.attribute.id + '[' + ctx.part + ']';
            var inputs = form.querySelectorAll( 'input[name$="' + suffix.replace( /"/g, '' ) + '"]' );
            return inputs.length ? inputs[0] : null;
        }

        var partAdapter = {
            get: function ( ctx ) { var el = partInput( ctx ); return el ? el.value : ''; },
            set: function ( ctx, value ) {
                var el = partInput( ctx );
                if ( !el ) {
                    return false;
                }
                el.value = value;
                el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
                el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
                return true;
            }
        };

        function adapter( ctx ) {
            return ctx.part ? partAdapter : ExpAi.fieldAdapters.get( ctx.attribute.datatype );
        }

        function blockOf( identifier ) {
            return form.querySelector( '.ezcca-edit-' + identifier );
        }

        // the other fields of the form, as context (like the field actions of expai.js)
        function formContext() {
            var out = {}, size = 0;
            attributes.forEach( function ( attribute ) {
                var block = blockOf( attribute.identifier );
                if ( !block || writable.indexOf( attribute.datatype ) === -1 ) {
                    return;
                }
                var value = ExpAi.fieldAdapters.get( attribute.datatype ).get( { attribute: attribute, block: block } );
                if ( typeof value === 'string' && value.trim() !== '' && size < 30000 ) {
                    out[attribute.identifier] = value.slice( 0, 6000 );
                    size += Math.min( value.length, 6000 );
                }
            } );
            return JSON.stringify( out );
        }

        /* ---- one bar per field (or per part) ---- */

        var bars = {};

        function labelText( ctx ) {
            var label = ctx.part ? ( ctx.host.querySelector( 'label' ) ) : ctx.block.querySelector( ':scope > label, :scope > fieldset > legend, :scope > .translation > label' );
            // the label's own text, without the marks inside it ("(required)", the asterisk)
            var own = label ? Array.prototype.filter.call( label.childNodes, function ( n ) { return n.nodeType === 3; } ).map( function ( n ) { return n.textContent; } ).join( ' ' ) : '';
            var text = own.replace( /\s+/g, ' ' ).replace( /[:*]/g, '' ).trim() || ( label ? label.textContent.replace( /\s+/g, ' ' ).replace( /[:*]/g, '' ).trim() : '' );
            return ctx.part ? ( ctx.attribute.name || ctx.attribute.identifier ) + ' / ' + ( text || ctx.part ) : ( text || ctx.attribute.name || ctx.attribute.identifier );
        }

        function barFor( attribute, part ) {
            var key = attribute.id + ':' + ( part || '' );
            if ( bars[key] ) {
                return bars[key];
            }
            var block = blockOf( attribute.identifier );
            if ( !block ) {
                return null;
            }
            var ctx = { attribute: attribute, block: block, part: part || '', host: block };
            var bar = null;
            if ( part ) {
                var input = partInput( ctx );
                if ( !input ) {
                    return null;
                }
                ctx.host = input.closest( '.block' ) || input.parentNode;
            } else {
                // join the bar of the field actions when expai.js drew one
                bar = block.querySelector( '.exp-ai-field-bar' );
            }
            var own = !bar;
            if ( own ) {
                bar = document.createElement( 'div' );
                bar.className = 'exp-ai exp-ai-field-bar';
                bar.innerHTML = '<span class="exp-ai-field-title" title="' + esc( S.presets ) + '"><span class="exp-ai-mark" aria-hidden="true"></span>' + esc( S.presets ) + '</span>';
                var label = ctx.host.querySelector( ':scope > label, :scope > fieldset > legend, :scope > .translation > label' );
                if ( label && label.parentNode === ctx.host ) {
                    label.insertAdjacentElement( 'afterend', bar );
                } else {
                    ctx.host.insertBefore( bar, ctx.host.firstChild );
                }
            }
            bar.classList.add( 'exp-ai-has-presets' );
            var group = document.createElement( 'span' );
            group.className = 'exp-ai-preset-group';
            var anchor = bar.querySelector( '.exp-ai-field-form' );
            bar.insertBefore( group, anchor );
            var note = document.createElement( 'p' );
            note.className = 'exp-ai-field-note exp-ai-preset-note';
            note.setAttribute( 'aria-live', 'polite' );
            bar.appendChild( note );
            ctx.bar = bar;
            ctx.group = group;
            ctx.note = note;
            ctx.undo = null;
            ctx.label = labelText( ctx );
            bars[key] = ctx;
            return ctx;
        }

        function busy( ctx, on ) {
            ctx.bar.classList.toggle( 'exp-ai-busy', on );
            Array.prototype.forEach.call( ctx.group.querySelectorAll( 'button' ), function ( b ) { b.disabled = on; } );
            if ( on ) {
                if ( ExpAi.clearReview ) {
                    ExpAi.clearReview( ctx.bar );
                }
                ctx.note.innerHTML = '<span class="exp-ai-spinner" aria-hidden="true"></span> ' + esc( S.working );
            }
        }

        function flash( ctx ) {
            var target = ctx.part ? ctx.host : ctx.block;
            target.classList.add( 'exp-ai-filled' );
            window.setTimeout( function () { target.classList.remove( 'exp-ai-filled' ); }, 2500 );
        }

        function run( ctx, kind, name ) {
            var value = adapter( ctx ).get( ctx );
            if ( kind === 'improve' && String( value || '' ).replace( /<[^>]*>/g, '' ).trim() === '' ) {
                ctx.note.textContent = S.empty;
                return;
            }
            busy( ctx, true );
            ExpAi.api( 'expaipreset::preset', {
                object_id: edit.object_id, version: edit.version, language: edit.language,
                attribute_id: ctx.attribute.id, kind: kind, action: name, part: ctx.part,
                value: value, context: formContext()
            } ).then( function ( result ) {
                // a failure after the request was sent: the error and the link to its record
                if ( result && result.error ) {
                    ctx.note.innerHTML = esc( ( S.error + ' ' + result.error ).trim() ) + recordLinks( result.record );
                    return;
                }
                // the model gave no text, usually with a reason ("there is nothing to summarize"): shown as such, nothing is
                // written and nothing is marked as filled
                if ( result && ( result.refused || String( result.value || '' ).replace( /<[^>]*>/g, '' ).trim() === '' ) ) {
                    ctx.note.innerHTML = '<span class="exp-ai-note-refused">' + esc( result.note || S.no_text ) + '</span>'
                        + ( result.fallback ? ' <span class="exp-ai-fallback-notice">' + esc( result.fallback.notice ) + '</span>' : '' )
                        + recordLinks( result.record );
                    return;
                }
                var extras = ( result.note ? ' <em>' + esc( result.note ) + '</em>' : '' )
                    + ( result.fallback ? ' <span class="exp-ai-fallback-notice">' + esc( result.fallback.notice ) + '</span>' : '' );
                var apply = function () {
                    var previous = adapter( ctx ).get( ctx );
                    if ( !adapter( ctx ).set( ctx, result.value ) ) {
                        ctx.note.textContent = S.error;
                        return false;
                    }
                    ctx.undo = previous;
                    flash( ctx );
                    ExpAi.emit( 'field:filled', { field: ctx, value: result.value, previous: previous, preset: kind + ':' + name } );
                    ctx.note.innerHTML = esc( S.filled ) + extras
                        + ' <button type="button" class="exp-ai-link exp-ai-preset-undo">' + esc( S.undo ) + '</button>'
                        + recordLinks( result.record );
                    return true;
                };
                // not written when the answer would lose structure or the field changed meanwhile: a review instead
                var outcome = ExpAi.guardedApply ? ExpAi.guardedApply( {
                    ctx: ctx, adapter: adapter( ctx ), value: result.value, sent: value, host: ctx.bar, strings: R, apply: apply,
                    kept: function () { ctx.note.textContent = R.kept; }
                } ) : { written: apply(), review: false };
                if ( outcome.review ) {
                    ctx.note.innerHTML = esc( R.review_note ) + extras + recordLinks( result.record );
                }
            } ).catch( function ( err ) {
                ctx.note.textContent = err && err.kind === 'timeout' ? S.timeout : ( S.error + ' ' + ( err && err.message || '' ) ).trim();
            } ).then( function () {
                busy( ctx, false );
                if ( ctx.note.querySelector( '.exp-ai-spinner' ) ) {
                    ctx.note.textContent = '';
                }
            } );
        }

        function bindUndo( ctx ) {
            ctx.note.addEventListener( 'click', function ( e ) {
                if ( !e.target.closest( '.exp-ai-preset-undo' ) || ctx.undo === null ) {
                    return;
                }
                adapter( ctx ).set( ctx, ctx.undo );
                ctx.undo = null;
                ctx.note.textContent = S.undone;
            } );
        }

        /** "View record" / "View job" after a preset or an Improve entry (ExpAi.requestLinks) */
        function recordLinks( record ) {
            return ExpAi.requestLinks ? ExpAi.requestLinks( record ) : '';
        }

        function fmt( text, args ) {
            return ExpAi.format ? ExpAi.format( text, args ) : text;
        }

        /* ---- presets ---- */

        ( C.presets || [] ).forEach( function ( p ) {
            var attribute = attributes.filter( function ( a ) { return String( a.id ) === String( p.attribute_id ); } )[0];
            if ( !attribute ) {
                return;
            }
            var fresh = !bars[attribute.id + ':' + ( p.part || '' )];
            var ctx = barFor( attribute, p.part );
            if ( !ctx ) {
                return;
            }
            if ( fresh ) {
                bindUndo( ctx );
            }
            var button = document.createElement( 'button' );
            button.type = 'button';
            button.className = 'exp-ai-preset-chip';
            button.setAttribute( 'data-preset', p.name );
            button.textContent = p.label;
            button.addEventListener( 'click', function () { run( ctx, 'preset', p.name ); } );
            ctx.group.appendChild( button );
            if ( ExpAi.commands ) {
                ExpAi.commands.register( 'preset_' + p.name + '_' + p.attribute_id + ( p.part ? '_' + p.part : '' ), {
                    label: fmt( S.command_preset, { '%action': p.label, '%field': ctx.label } ),
                    run: function () {
                        ctx.host.scrollIntoView( { block: 'center' } );
                        button.focus();
                        button.click();
                    }
                } );
            }
        } );

        /* ---- the Improve menu ---- */

        var openMenu = null;

        function closeMenu( focusButton ) {
            if ( !openMenu ) {
                return;
            }
            openMenu.menu.hidden = true;
            openMenu.button.setAttribute( 'aria-expanded', 'false' );
            if ( focusButton ) {
                openMenu.button.focus();
            }
            openMenu = null;
        }

        function improveMenu( ctx ) {
            var wrap = document.createElement( 'span' );
            wrap.className = 'exp-ai-improve';
            var id = 'exp-ai-improve-' + ctx.attribute.id;
            wrap.innerHTML = '<button type="button" class="exp-ai-preset-chip exp-ai-improve-button" aria-haspopup="menu" aria-expanded="false" aria-controls="' + id
                + '" title="' + esc( S.improve_title ) + '">' + esc( S.improve ) + ' <span aria-hidden="true">&#9662;</span></button>'
                + '<span class="exp-ai-improve-menu" role="menu" id="' + id + '" hidden="hidden">'
                + ( C.improve || [] ).map( function ( i ) {
                    return '<button type="button" role="menuitem" tabindex="-1" class="exp-ai-improve-item" data-improve="' + esc( i.name ) + '">' + esc( i.label ) + '</button>';
                } ).join( '' ) + '</span>';
            ctx.group.appendChild( wrap );
            var button = wrap.querySelector( '.exp-ai-improve-button' );
            var menu = wrap.querySelector( '.exp-ai-improve-menu' );
            var items = Array.prototype.slice.call( menu.querySelectorAll( '.exp-ai-improve-item' ) );
            function open() {
                closeMenu( false );
                menu.hidden = false;
                button.setAttribute( 'aria-expanded', 'true' );
                openMenu = { menu: menu, button: button };
                if ( items[0] ) {
                    items[0].focus();
                }
            }
            button.addEventListener( 'click', function () {
                if ( openMenu && openMenu.menu === menu ) {
                    closeMenu( true );
                } else {
                    open();
                }
            } );
            button.addEventListener( 'keydown', function ( e ) {
                if ( e.key === 'ArrowDown' ) {
                    e.preventDefault();
                    open();
                }
            } );
            menu.addEventListener( 'keydown', function ( e ) {
                var i = items.indexOf( document.activeElement );
                if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
                    e.preventDefault();
                    var next = ( i + ( e.key === 'ArrowDown' ? 1 : -1 ) + items.length ) % items.length;
                    items[next].focus();
                } else if ( e.key === 'Home' || e.key === 'End' ) {
                    e.preventDefault();
                    items[e.key === 'Home' ? 0 : items.length - 1].focus();
                } else if ( e.key === 'Escape' ) {
                    e.preventDefault();
                    e.stopPropagation();
                    closeMenu( true );
                } else if ( e.key === 'Tab' ) {
                    closeMenu( false );
                }
            } );
            menu.addEventListener( 'click', function ( e ) {
                var item = e.target.closest( '.exp-ai-improve-item' );
                if ( !item ) {
                    return;
                }
                closeMenu( true );
                run( ctx, 'improve', item.getAttribute( 'data-improve' ) );
            } );
            if ( ExpAi.commands ) {
                ExpAi.commands.register( 'improve_' + ctx.attribute.id, {
                    label: fmt( S.command_improve, { '%field': ctx.label } ),
                    run: function () {
                        ctx.block.scrollIntoView( { block: 'center' } );
                        // after the palette has closed and given the focus back
                        window.setTimeout( open, 0 );
                    }
                } );
            }
        }

        if ( ( C.improve || [] ).length ) {
            attributes.forEach( function ( attribute ) {
                if ( ( C.improve_datatypes || [] ).indexOf( attribute.datatype ) === -1 ) {
                    return;
                }
                var fresh = !bars[attribute.id + ':'];
                var ctx = barFor( attribute, '' );
                if ( !ctx ) {
                    return;
                }
                if ( fresh ) {
                    bindUndo( ctx );
                }
                improveMenu( ctx );
            } );
        }

        document.addEventListener( 'click', function ( e ) {
            if ( openMenu && !e.target.closest( '.exp-ai-improve' ) ) {
                closeMenu( false );
            }
        } );
    }

    // after expai.js has drawn the field bars (it starts on DOMContentLoaded, the edit form one tick later)
    function later() {
        window.setTimeout( start, 30 );
    }
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', later );
    } else {
        later();
    }
})( window, document );
