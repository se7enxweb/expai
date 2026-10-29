/*!
 * expai - Exponential Intelligence: alt text for images (doc/alt-text.md)
 * Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * GNU General Public License v2.0 (or any later version)
 *
 * Loaded by expai/edit/alttext.tpl in the content edit form. Next to every image field (ezimage) it adds
 *   "Write alt text"               a vision model looks at the stored image (expaialttext::suggest) and the answer is
 *                                  put into the alternative text field of the image (and into the companion text
 *                                  fields of [AltTextSettings] CompanionAttributes[] that repeat it), with undo
 *   "Decorative (empty alt text)"  the editor's explicit choice for an image without information: the field is
 *                                  emptied (with undo); no request is made. It is disabled (with the reason as its
 *                                  tooltip) for a field whose class requires an alternative text (alt_text_required)
 * Nothing is stored: the editor stores or publishes the draft as usual.
 * Extension points: window.ExpAi.altTextHooks = { before( ctx, params ), after( ctx, result ) } (return false to stop) and
 * the event field:filled (with decorative: true for the explicit choice).
 */
(function ( window, document ) {
    'use strict';

    function start() {
        var ExpAi = window.ExpAi;
        var node = document.querySelector( '.exp-ai-alttext-config' );
        if ( !ExpAi || !node ) {
            return;
        }
        var C;
        try { C = JSON.parse( node.textContent ) || {}; } catch ( e ) { ( window.console && console.warn && console.warn( 'expai: a configuration block is not valid JSON', e ) ); return; }
        var S = C.strings || {};
        var esc = ExpAi.esc;
        var form = node.closest( 'form' ) || document.getElementById( 'editform' );
        if ( !form || form.getAttribute( 'data-exp-ai-alttext' ) ) {
            return;
        }
        form.setAttribute( 'data-exp-ai-alttext', '1' );

        function altInput( attributeId ) {
            return form.querySelector( 'input[name$="_data_imagealttext_' + attributeId + '"]' );
        }

        function blockOf( identifier ) {
            return form.querySelector( '.ezcca-edit-' + identifier );
        }

        function textInput( attributeId ) {
            var list = form.querySelectorAll( 'input[type="text"], input:not([type]), textarea' );
            for ( var i = 0; i < list.length; i++ ) {
                var name = list[i].getAttribute( 'name' ) || '';
                if ( name.slice( -( String( attributeId ).length + 1 ) ) === '_' + attributeId && name.indexOf( '_data_imagealttext_' ) === -1 ) {
                    return list[i];
                }
            }
            return null;
        }

        function setValue( el, value ) {
            el.value = value;
            el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
            el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
        }

        /** the text of the other fields, through expai's field adapters (rich text editors included) */
        function context() {
            var out = {}, size = 0;
            ( C.texts || [] ).forEach( function ( t ) {
                var block = blockOf( t.identifier );
                if ( !block ) {
                    return;
                }
                var value = ExpAi.fieldAdapters.get( t.datatype ).get( { attribute: t, block: block } );
                if ( typeof value === 'string' && value.trim() !== '' && size < 12000 ) {
                    out[t.identifier] = value.slice( 0, 3000 );
                    size += Math.min( value.length, 3000 );
                }
            } );
            return JSON.stringify( out );
        }

        /** writes the alt text and the companion fields that repeat it; returns what to restore */
        function fill( image, value ) {
            var input = altInput( image.id );
            var previous = { alt: input.value, companions: [] };
            ( C.companions || [] ).forEach( function ( c ) {
                var el = textInput( c.id );
                // only a companion that is empty or repeats the image's alt text follows it
                if ( el && ( el.value.trim() === '' || el.value === input.value ) ) {
                    previous.companions.push( { el: el, value: el.value } );
                    setValue( el, value );
                }
            } );
            setValue( input, value );
            return previous;
        }

        function restore( image, previous ) {
            setValue( altInput( image.id ), previous.alt );
            previous.companions.forEach( function ( c ) { setValue( c.el, c.value ); } );
        }

        ( C.images || [] ).forEach( function ( image ) {
            var block = blockOf( image.identifier );
            var input = altInput( image.id );
            if ( !block || !input || block.querySelector( '.exp-ai-alttext-bar' ) ) {
                return;
            }
            var ctx = { attribute: { id: image.id, identifier: image.identifier, datatype: 'ezimage', name: image.name }, block: block, input: input };
            var model = C.model || {};
            var disabled = !model.available || !image.has_image;
            var why = !model.available ? ( S.unavailable + ' ' + ( model.reason || '' ) ) : ( !image.has_image ? S.no_image : '' );
            // a field that requires an alternative text refuses an empty one: no "Decorative" choice there
            var required = !!image.alt_text_required;
            var bar = document.createElement( 'div' );
            bar.className = 'exp-ai exp-ai-field-bar exp-ai-alttext-bar';
            bar.innerHTML = '<span class="exp-ai-field-title" title="' + esc( S.actions_title ) + '"><span class="exp-ai-mark" aria-hidden="true"></span>' + esc( S.actions ) + '</span>'
                + '<button type="button" class="exp-ai-chip exp-ai-alttext-write" data-action="alt_text"' + ( disabled ? ' disabled="disabled"' : '' )
                + ' title="' + esc( disabled ? why : S.write_title ) + '">' + esc( S.write ) + '</button>'
                + '<button type="button" class="exp-ai-chip exp-ai-alttext-decorative"' + ( required ? ' disabled="disabled" aria-disabled="true"' : '' )
                + ' title="' + esc( required ? ( S.decorative_required || '' ) : S.decorative_title ) + '">' + esc( S.decorative ) + '</button>'
                + '<p class="exp-ai-field-note" aria-live="polite">' + ( disabled ? esc( why ) : '' ) + '</p>';
            // right above the alt text field of the image
            var holder = input.closest( '.block, .form-group, div' ) || block;
            holder.parentNode.insertBefore( bar, holder );
            var note = bar.querySelector( '.exp-ai-field-note' );
            var write = bar.querySelector( '.exp-ai-alttext-write' );
            var undo = null;

            function done( text, previous ) {
                undo = previous;
                note.innerHTML = text + ' <button type="button" class="exp-ai-link exp-ai-undo">' + esc( S.undo ) + '</button>';
                block.classList.add( 'exp-ai-filled' );
                window.setTimeout( function () { block.classList.remove( 'exp-ai-filled' ); }, 2500 );
            }

            bar.addEventListener( 'click', function ( e ) {
                if ( e.target.closest( '.exp-ai-undo' ) ) {
                    if ( undo ) {
                        restore( image, undo );
                        undo = null;
                        note.textContent = S.undone;
                    }
                    return;
                }
                if ( e.target.closest( '.exp-ai-alttext-decorative' ) ) {
                    if ( required ) {
                        return;
                    }
                    done( esc( S.decorative_done ), fill( image, '' ) );
                    ExpAi.emit( 'field:filled', { field: ctx, value: '', previous: undo.alt, decorative: true } );
                    return;
                }
                if ( !e.target.closest( '.exp-ai-alttext-write' ) || write.disabled ) {
                    return;
                }
                var hooks = ExpAi.altTextHooks || {};
                var data = { object_id: C.object_id, version: C.version, language: C.language, attribute_id: image.id, value: input.value, context: context() };
                if ( hooks.before && hooks.before( ctx, data ) === false ) {
                    return;
                }
                bar.classList.add( 'exp-ai-busy' );
                write.disabled = true;
                note.innerHTML = '<span class="exp-ai-spinner" aria-hidden="true"></span> ' + esc( S.working );
                ExpAi.api( 'expaialttext::suggest', data ).then( function ( r ) {
                    if ( hooks.after && hooks.after( ctx, r ) === false ) {
                        note.textContent = '';
                        return;
                    }
                    var previous = fill( image, r.value );
                    var img = r.image || {};
                    var facts = ExpAi.format( r.cost ? S.facts : S.facts_nocost, { '%model': r.model_label || r.model, '%width': img.width, '%height': img.height,
                                                                                  '%tokens': img.image_tokens, '%cost': r.cost } );
                    done( esc( S.filled ) + ( r.note ? ' <em>' + esc( r.note ) + '</em>' : '' )
                          + ( r.decorative && !required ? ' <strong>' + esc( S.decorative_hint ) + '</strong>' : '' )
                          + ' <span class="exp-ai-alttext-facts">' + esc( facts ) + '</span>', previous );
                    ExpAi.emit( 'field:filled', { field: ctx, value: r.value, previous: previous.alt } );
                } ).catch( function ( err ) {
                    note.textContent = err.kind === 'timeout' ? S.timeout : ( S.error + ' ' + ( err.message || '' ) ).trim();
                } ).then( function () {
                    bar.classList.remove( 'exp-ai-busy' );
                    write.disabled = false;
                } );
            } );
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', start );
    } else {
        start();
    }
})( window, document );
