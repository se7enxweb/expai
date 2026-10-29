/*!
 * expai - "Test prompt" and "Load model" on expai/models (doc/local-models.md "Test prompt and Load model")
 * Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * GNU General Public License v2.0 (or any later version)
 *
 * Loaded by expai/models.tpl. The buttons queue a job (expaimodel::test, expaimodel::load: POST with the form token,
 * through ExpAi.api of expai.js) instead of waiting in the page request, which the web server would end long before
 * a local model has loaded. While a job waits or runs the page asks expaimodel::status every few seconds (not while
 * the browser tab is hidden) and shows the state and then the result. Without JavaScript the forms post to
 * expai/models, which queues the same job; a reload shows the state.
 */
(function ( window, document ) {
    'use strict';

    function start() {
        var ExpAi = window.ExpAi;
        var page = document.querySelector( '[data-exp-ai-models]' );
        if ( !page || page.getAttribute( 'data-exp-ai-ready' ) ) {
            return;
        }
        if ( !ExpAi ) {
            // expai.js not there yet: once more when the page has loaded
            if ( !start.waited ) {
                start.waited = true;
                window.addEventListener( 'load', start );
            }
            return;
        }
        page.setAttribute( 'data-exp-ai-ready', '1' );
        init( ExpAi, page );
    }

    function init( ExpAi, page ) {
        var strings = {};
        try { strings = JSON.parse( page.querySelector( '.exp-ai-models-strings' ).textContent || '{}' ); } catch ( e ) { strings = {}; ( window.console && console.warn && console.warn( 'expai: a configuration block is not valid JSON', e ) ); }
        var interval = parseInt( page.getAttribute( 'data-poll' ), 10 ) || 3000;
        var timer = null;
        var busy = false;
        var active = {};

        function cells( alias, selector ) {
            return Array.prototype.filter.call( page.querySelectorAll( selector ), function ( el ) {
                return el.getAttribute( 'data-alias' ) === alias;
            } );
        }

        function note( text, isError ) {
            var box = page.querySelector( '.exp-ai-models-live' );
            if ( box ) {
                box.textContent = text || '';
                box.className = 'exp-ai-models-live ' + ( isError ? 'exp-ai-error' : 'exp-ai-notice' );
                box.hidden = !text;
            }
        }

        function render( data ) {
            ( data.models || [] ).forEach( function ( m ) {
                cells( m.alias, '.exp-ai-model-state' ).forEach( function ( el ) { el.innerHTML = m.state_html || ''; } );
                cells( m.alias, '.exp-ai-test-row' ).forEach( function ( row ) {
                    var cell = row.querySelector( '.exp-ai-test-result' );
                    if ( cell ) {
                        cell.innerHTML = m.result_html || '';
                    }
                    row.hidden = !m.result_html;
                } );
                cells( m.alias, '.exp-ai-model-actions button, .exp-ai-model-actions input[type="submit"]' ).forEach( function ( b ) { b.disabled = !!m.active; } );
                if ( m.active ) {
                    active[m.alias] = true;
                } else {
                    delete active[m.alias];
                }
            } );
            if ( data.poll ) {
                interval = data.poll;
            }
            schedule();
        }

        function schedule() {
            window.clearTimeout( timer );
            timer = null;
            if ( Object.keys( active ).length && !document.hidden ) {
                timer = window.setTimeout( poll, interval );
            }
        }

        function poll() {
            timer = null;
            var aliases = Object.keys( active );
            if ( !aliases.length || busy || document.hidden ) {
                return;
            }
            busy = true;
            ExpAi.api( 'expaimodel::status', { models: aliases.join( ',' ) } ).then( function ( data ) {
                busy = false;
                render( data );
            } ).catch( function ( err ) {
                busy = false;
                note( ExpAi.format( strings.failed, { '%error': err && err.message ? err.message : '' } ), true );
                schedule();
            } );
        }

        page.addEventListener( 'submit', function ( event ) {
            var form = event.target;
            if ( !form.classList || !form.classList.contains( 'exp-ai-model-actions' ) ) {
                return;
            }
            var button = event.submitter || form.querySelector( 'input[type="submit"]' );
            var fn = button && button.name === 'ExpAiLoadModelButton' ? 'load' : 'test';
            var alias = form.getAttribute( 'data-alias' );
            event.preventDefault();
            cells( alias, '.exp-ai-model-actions input[type="submit"]' ).forEach( function ( b ) { b.disabled = true; } );
            cells( alias, '.exp-ai-model-state' ).forEach( function ( el ) {
                el.innerHTML = '<span class="exp-ai-working exp-ai-model-busy" role="status"><span class="exp-ai-spinner" aria-hidden="true"></span> ' + ExpAi.esc( strings.starting ) + '</span>';
            } );
            ExpAi.api( 'expaimodel::' + fn, { model: alias } ).then( function ( data ) {
                note( data.message, false );
                render( data );
            } ).catch( function ( err ) {
                cells( alias, '.exp-ai-model-actions input[type="submit"]' ).forEach( function ( b ) { b.disabled = false; } );
                cells( alias, '.exp-ai-model-state' ).forEach( function ( el ) { el.innerHTML = ''; } );
                note( ExpAi.format( strings.failed, { '%error': err && err.message ? err.message : '' } ), true );
            } );
        } );

        document.addEventListener( 'visibilitychange', function () {
            if ( document.hidden ) {
                window.clearTimeout( timer );
                timer = null;
            } else {
                poll();
            }
        } );

        // jobs that were already waiting or running when the page was loaded
        Array.prototype.forEach.call( page.querySelectorAll( '.exp-ai-model-actions[data-active="1"]' ), function ( form ) {
            active[form.getAttribute( 'data-alias' )] = true;
        } );
        schedule();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', start );
    } else {
        start();
    }
})( window, document );
