/*!
 * expai - the "Translations" section of the Intelligence tab (doc/translation-sync.md)
 * Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * GNU General Public License v2.0 (or any later version)
 *
 * Loaded by expai/panel/translations.tpl. Reads expaitranslation::status, and sends check, approve and skip (POST
 * with the form token, through ExpAi.api of expai.js). While a job of the object is queued or running it asks again
 * every 5 seconds (not while the browser tab is hidden). A machine translation service has no lane: the estimate
 * names the service instead (doc/translation-providers.md). Without a translation service (no_provider) or with the
 * data policy against every one (blocked) the row says why, and Approve and Translate in full are not offered.
 */
(function ( window, document ) {
    'use strict';

    function start() {
        var ExpAi = window.ExpAi;
        if ( !ExpAi ) {
            return;
        }
        Array.prototype.forEach.call( document.querySelectorAll( '.exp-ai-translations' ), function ( box ) {
            if ( box.getAttribute( 'data-exp-ai-ready' ) ) {
                return;
            }
            box.setAttribute( 'data-exp-ai-ready', '1' );
            init( ExpAi, box );
        } );
    }

    function init( ExpAi, box ) {
        var esc = ExpAi.esc;
        var fmt = ExpAi.format;
        var node = box.getAttribute( 'data-node-id' );
        var strings = {};
        try { strings = JSON.parse( box.querySelector( '.exp-ai-tr-strings' ).textContent || '{}' ); } catch ( e ) { strings = {}; ( window.console && console.warn && console.warn( 'expai: a configuration block is not valid JSON', e ) ); }
        var body = box.querySelector( 'tbody' );
        var note = box.querySelector( '.exp-ai-tr-note' );
        var checkButton = box.querySelector( '.exp-ai-tr-check' );
        var timer = null;
        var busy = false;

        function call( fn, data ) {
            busy = true;
            return ExpAi.api( 'expaitranslation::' + fn, Object.assign( { node: node }, data || {} ) ).then( function ( result ) {
                busy = false;
                render( result );
                return result;
            } ).catch( function ( err ) {
                busy = false;
                note.textContent = ( strings.error || '' ) + ' ' + ( err && err.message ? err.message : '' );
                throw err;
            } );
        }

        function render( data ) {
            var rows = data.languages || [];
            box.hidden = !rows.length;
            box.querySelector( '.exp-ai-tr-source' ).textContent = fmt( strings.source, { '%language': data.source_name, '%version': data.current_version } );
            checkButton.hidden = !data.can_act;
            var active = false;
            body.innerHTML = rows.map( function ( row, i ) {
                var state = row.state || 'unchecked';
                if ( state === 'queued' || state === 'held' || row.job_state === 'running' ) {
                    active = true;
                }
                var status = esc( strings['state_' + state] || state );
                if ( row.decision && state !== 'up_to_date' && state !== 'skipped' ) {
                    status += ' <span class="exp-ai-hint">(' + esc( strings['decision_' + row.decision] || row.decision ) + ')</span>';
                }
                if ( row.reason && state === 'waiting' ) {
                    status += '<br><span class="exp-ai-hint">' + esc( row.reason ) + '</span>';
                }
                if ( row.provider_reason && ( state === 'no_provider' || state === 'blocked' ) ) {
                    status += '<br><span class="exp-ai-hint">' + esc( row.provider_reason ) + '</span>';
                }
                if ( row.error ) {
                    status += '<br><span class="exp-ai-hint">' + esc( row.error ) + '</span>';
                }
                if ( row.stale ) {
                    status += '<br><span class="exp-ai-hint">' + esc( strings.stale ) + '</span>';
                }
                var changed = row.ratio === null || row.ratio === undefined ? '' : esc( row.ratio + '%' ) + ( row.blocks ? '<br><span class="exp-ai-hint">' + esc( fmt( strings.blocks, { '%count': row.blocks } ) ) + '</span>' : '' );
                var job = '';
                if ( row.job_uuid ) {
                    job = data.has_queue_view ? '<a href="' + esc( ExpAi.url( 'expqueue/job/' + row.job_uuid ) ) + '" title="' + esc( strings.job ) + '">' + esc( row.job_state || '?' ) + '</a>' : esc( row.job_state );
                }
                if ( row.draft_version ) {
                    job += ( job ? '<br>' : '' ) + '<a href="' + esc( ExpAi.url( 'content/versionview/' + data.object_id + '/' + row.draft_version + '/' + row.language ) ) + '">' + esc( strings.view_draft ) + '</a>';
                }
                var actions = '';
                // no translation service can take it: nothing to approve until the settings change (Check translations asks again)
                var unserved = state === 'no_provider' || state === 'blocked';
                if ( data.can_act && row.can_edit ) {
                    var waiting = state === 'waiting' || state === 'failed' || state === 'unchecked' || row.stale;
                    if ( waiting && !unserved && row.decision && row.decision !== 'noop' ) {
                        actions += '<input type="button" class="button exp-ai-btn" data-act="approve" data-i="' + i + '" value="' + esc( strings.approve ) + '"> ';
                    }
                    if ( !unserved && state !== 'queued' && state !== 'held' && row.decision !== 'full' ) {
                        actions += '<input type="button" class="button exp-ai-btn" data-act="full" data-i="' + i + '" value="' + esc( strings.approve_full ) + '"> ';
                    }
                    if ( state !== 'up_to_date' && state !== 'skipped' ) {
                        actions += '<input type="button" class="button exp-ai-btn" data-act="skip" data-i="' + i + '" value="' + esc( strings.skip ) + '">';
                    }
                }
                // the lane of a model request; a machine translation service has none: its name instead
                var by = '';
                if ( row.provider_kind === 'mt' && row.provider_label ) {
                    by = fmt( strings.translated_by, { '%provider': row.provider_label } );
                } else if ( row.lane ) {
                    by = row.lane;
                }
                return '<tr class="' + ( i % 2 ? 'bgdark' : 'bglight' ) + '"><td>' + esc( row.name ) + '</td><td>' + status + '</td><td>' + changed + '</td><td>'
                     + esc( row.estimate || '' ) + ( by ? '<br><span class="exp-ai-hint">' + esc( by ) + '</span>' : '' ) + '</td><td>' + job + '</td><td>' + actions + '</td></tr>';
            } ).join( '' );
            Array.prototype.forEach.call( body.querySelectorAll( 'input[data-act]' ), function ( button ) {
                button.addEventListener( 'click', function () {
                    var row = rows[+button.getAttribute( 'data-i' )];
                    var act = button.getAttribute( 'data-act' );
                    if ( act === 'skip' && !window.confirm( fmt( strings.skip_confirm, { '%language': row.name } ) ) ) {
                        return;
                    }
                    if ( ( act === 'full' || ( act === 'approve' && row.decision === 'full' ) ) && !window.confirm( fmt( strings.full_confirm, { '%language': row.name, '%estimate': row.estimate || '?' } ) ) ) {
                        return;
                    }
                    button.disabled = true;
                    call( act === 'skip' ? 'skip' : 'approve', { language: row.language, mode: act === 'full' ? 'full' : '' } ).then( function () {
                        note.textContent = act === 'skip' ? strings.skipped_note : strings.queued_note;
                    } ).catch( function () { button.disabled = false; } );
                } );
            } );
            window.clearTimeout( timer );
            if ( active ) {
                timer = window.setTimeout( poll, 5000 );
            }
        }

        function poll() {
            if ( document.hidden || busy ) {
                timer = window.setTimeout( poll, 5000 );
                return;
            }
            call( 'status' ).catch( function () {} );
        }

        checkButton.addEventListener( 'click', function () {
            checkButton.disabled = true;
            note.textContent = strings.checking;
            call( 'check' ).then( function () { note.textContent = ''; } ).catch( function () {} ).then( function () { checkButton.disabled = false; } );
        } );
        call( 'status' ).catch( function () { box.hidden = true; } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', start );
    } else {
        start();
    }
}( window, document ));
