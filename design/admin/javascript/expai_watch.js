/*!
 * expai - "Watch this run" on expai/dashboard (doc/dashboard.md "Start writing")
 * Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * GNU General Public License v2.0 (or any later version)
 *
 * Loaded by expai/dashboard.tpl. While the request sent with "Start writing" waits or works, the state line asks
 * expaiwatch::state every few seconds (POST with the form token, through ExpAi.api of expai.js; not while the tab is
 * hidden) and replaces itself with the answer, so the chip and the links follow the run. Without JavaScript a
 * reload shows the same.
 */
(function ( window, document ) {
    'use strict';

    function start() {
        var box = document.querySelector( '[data-exp-ai-watch]' );
        if ( !box || box.getAttribute( 'data-exp-ai-ready' ) ) {
            return;
        }
        if ( !window.ExpAi ) {
            if ( !start.waited ) {
                start.waited = true;
                window.addEventListener( 'load', start );
            }
            return;
        }
        box.setAttribute( 'data-exp-ai-ready', '1' );
        var interval = 4000;
        var timer = null;

        function schedule() {
            window.clearTimeout( timer );
            if ( box.getAttribute( 'data-active' ) === '1' ) {
                timer = window.setTimeout( poll, interval );
            }
        }

        function poll() {
            if ( document.hidden ) {
                schedule();
                return;
            }
            window.ExpAi.api( 'expaiwatch::state', {} ).then( function ( data ) {
                data = data || {};
                box.innerHTML = data.html || '';
                box.setAttribute( 'data-active', data.active ? '1' : '0' );
                interval = parseInt( data.poll, 10 ) || interval;
                schedule();
            }, function () {
                // a failed poll (a deploy, the network): slower, but keep following the run
                interval = Math.min( interval * 2, 60000 );
                schedule();
            } );
        }

        document.addEventListener( 'visibilitychange', function () {
            if ( !document.hidden && box.getAttribute( 'data-active' ) === '1' ) {
                poll();
            }
        } );
        schedule();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', start );
    } else {
        start();
    }
}( window, document ));
