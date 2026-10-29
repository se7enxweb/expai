/*!
 * expai - Exponential Intelligence: small actions of the report pages, without inline code
 * Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * GNU General Public License v2.0 (or any later version)
 *
 * Printed by expai/alttext.tpl and expai/summary.tpl (ezscript). One listener per event on the document, set up once:
 *   form[data-exp-ai-node-form]          "Where" of the missing alt text report: sends the node ID of its number field
 *                                        as the view parameter /(node)/<id> of the form's address
 *   input[data-exp-ai-check-all="name"]  ticks or clears every checkbox named <name> (the rows of the report)
 *   [data-exp-ai-print]                  opens the browser's print dialog (EI: Summary, "Print or save as PDF")
 * As files they run under a Content Security Policy without 'unsafe-inline'.
 */
(function ( window, document ) {
    'use strict';
    if ( window.expAiPageActionsReady ) { return; }
    window.expAiPageActionsReady = true;

    document.addEventListener( 'submit', function ( e ) {
        var form = e.target;
        if ( !form || !form.hasAttribute || !form.hasAttribute( 'data-exp-ai-node-form' ) ) { return; }
        var field = form.querySelector( 'input' );
        form.action = form.action.replace( /\/$/, '' ) + '/(node)/' + encodeURIComponent( field ? field.value : '' );
    } );

    document.addEventListener( 'change', function ( e ) {
        var all = e.target;
        if ( !all || !all.hasAttribute || !all.hasAttribute( 'data-exp-ai-check-all' ) ) { return; }
        var name = all.getAttribute( 'data-exp-ai-check-all' );
        Array.prototype.forEach.call( document.querySelectorAll( 'input[type="checkbox"]' ), function ( c ) {
            if ( c.name === name ) { c.checked = all.checked; }
        } );
    } );

    document.addEventListener( 'click', function ( e ) {
        var button = e.target && e.target.closest ? e.target.closest( '[data-exp-ai-print]' ) : null;
        if ( !button ) { return; }
        e.preventDefault();
        window.print();
    } );
}( window, document ));
