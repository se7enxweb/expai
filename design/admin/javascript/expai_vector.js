/*!
 * expai - Vector, the workspace of threads (doc/vector.md)
 * Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * GNU General Public License v2.0 (or any later version)
 *
 * Loaded only by expai/vector (ezscript_require in expai/vector.tpl), after expai.js and expai_palette.js. Everything
 * comes through ezjscore (expaivector::*): the thread list, the open thread, runs (foreground steps, as the panel),
 * stop, the queue of follow-ups, the scope. Events arrive by polling (expaivector::events) or, where the server
 * streams (bootstrap.transport = sse), by Server-Sent Events (expai/stream) with a fallback to polling.
 *
 *   window.ExpAiVectorApp = { open( uuid ), setModel( alias ), toast( text ), bindingsChanged( bindings ) }
 *   ExpAi events: vector:open, vector:step, vector:done
 */
(function ( window, document ) {
    'use strict';
    // the script is loaded in the page head: everything starts once the shell is there
    function init() {
        var ExpAi = window.ExpAi;
        var Keys = window.ExpAiKeys;
        var root = document.getElementById( 'exp-ai-vector' );
        if ( !ExpAi || !Keys || !root || !root.querySelector( '.exp-ai-v-grid' ) ) {
            return;
        }
        var esc = ExpAi.esc, fmt = ExpAi.format, url = ExpAi.url;
        var S = {};
        try { S = JSON.parse( root.querySelector( '.exp-ai-v-strings' ).textContent ) || {}; } catch ( e ) { S = {}; ( window.console && console.warn && console.warn( 'expai: a configuration block is not valid JSON', e ) ); }
        function T( key, args ) { return fmt( S[key] || key, args || {} ); }
        function api( fn, data ) { return ExpAi.api( 'expaivector::' + fn, data ); }
        function q( sel, ctx ) { return ( ctx || root ).querySelector( sel ); }
        function qa( sel, ctx ) { return Array.prototype.slice.call( ( ctx || root ).querySelectorAll( sel ) ); }

        var el = {
            list: q( '.exp-ai-v-list' ), filter: q( '.exp-ai-v-filter' ), empty: q( '.exp-ai-v-empty' ), thread: q( '.exp-ai-v-thread' ),
            title: q( '.exp-ai-v-title' ), state: q( '.exp-ai-v-state' ), chips: q( '.exp-ai-v-chips' ), note: q( '.exp-ai-v-note' ),
            model: q( '.exp-ai-v-model' ), lane: q( '.exp-ai-v-lane' ), review: q( '.exp-ai-v-review' ), log: q( '.exp-ai-v-log' ),
            older: q( '.exp-ai-v-older' ), working: q( '.exp-ai-v-working' ), workingText: q( '.exp-ai-v-working-text' ), error: q( '.exp-ai-v-thread .exp-ai-v-error' ),
            input: q( '.exp-ai-v-input' ), run: q( '.exp-ai-v-run' ), stop: q( '.exp-ai-v-stop' ), queueBtn: q( '.exp-ai-v-queue-btn' ), steerBtn: q( '.exp-ai-v-steer-btn' ),
            queue: q( '.exp-ai-v-queue' ), queueList: q( '.exp-ai-v-queue-list' ), hint: q( '.exp-ai-v-hint' ), live: q( '[data-live="steps"]' ),
            readonly: q( '.exp-ai-v-readonly' ), dialog: q( '.exp-ai-v-scope-dialog' ), toast: q( '.exp-ai-v-toast' ), side: q( '.exp-ai-v-side' ),
            threadsPane: q( '.exp-ai-v-threads' ), runs: q( '.exp-ai-v-runs' ), runsList: q( '.exp-ai-v-runs-list' ), runsProgress: q( '.exp-ai-v-runs-progress' )
        };

        var app = {
            boot: null, bindings: {}, shelf: root.getAttribute( 'data-shelf' ) || '', rows: [], shared: [], current: null, busy: false,
            stopRequested: false, followups: [], activeItem: -1, cursor: {}, oldest: null, pollTimer: null, stream: null, lanes: [], scopeDraft: null,
            records: {}
        };

        /* ------------------------------------------------------------ utilities */

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
        function stateLabel( s ) { return T( 'state_' + s ); }
        /** the records of the exchanges (expAiTurnRecord) from a history page or a step's answer */
        function takeRecords( data ) { Object.keys( ( data && data.records ) || {} ).forEach( function ( id ) { app.records[id] = data.records[id]; } ); }
        function money( t ) { return t || ''; }
        function timeText( seconds ) {
            var d = new Date( seconds * 1000 ), now = new Date();
            var hm = ( '0' + d.getHours() ).slice( -2 ) + ':' + ( '0' + d.getMinutes() ).slice( -2 );
            return d.toDateString() === now.toDateString() ? hm : d.toLocaleDateString() + ' ' + hm;
        }
        function showError( box, err ) {
            if ( !box ) { return; }
            if ( !err ) { box.hidden = true; box.textContent = ''; return; }
            box.hidden = false;
            box.textContent = err.kind === 'timeout' ? T( 'timeout' )
                : err.expired ? T( 'session_expired' ) : err.lost ? ( err.message || '' ) : T( 'error' ) + ' ' + ( err.message || '' );
        }
        var toastTimer = null, toastUndo = null;
        function toast( text, undo ) {
            if ( !el.toast ) { return; }
            q( '.exp-ai-v-toast-text' ).textContent = text;
            q( '.exp-ai-v-undo' ).hidden = !undo;
            toastUndo = undo || null;
            el.toast.hidden = false;
            window.clearTimeout( toastTimer );
            toastTimer = window.setTimeout( function () { el.toast.hidden = true; toastUndo = null; }, 5000 );
        }
        var liveAt = 0;
        function announce( text ) {
            // the live region speaks at most every 2 seconds
            if ( !el.live || Date.now() - liveAt < 2000 ) { return; }
            liveAt = Date.now();
            el.live.textContent = text;
        }
        function keysOf( command ) { return app.bindings[command] ? app.bindings[command].keys : ''; }
        function paintKeys() {
            qa( 'kbd[data-keys]', document ).forEach( function ( k ) {
                var keys = keysOf( k.getAttribute( 'data-keys' ) );
                k.textContent = keys ? Keys.display( keys ) : '';
                k.hidden = !keys;
            } );
            el.hint.textContent = app.busy
                ? ( keysOf( 'steer' ) ? T( 'hint_running_steer', { '%steer': Keys.display( keysOf( 'steer' ) ), '%queue': Keys.display( keysOf( 'queue' ) ), '%stop': Keys.display( keysOf( 'stop' ) ) } )
                                      : T( 'hint_running', { '%queue': Keys.display( keysOf( 'queue' ) ), '%stop': Keys.display( keysOf( 'stop' ) ) } ) )
                : T( 'hint_idle', { '%run': Keys.display( keysOf( 'run' ) ) } );
        }
        function canWrite() { return !!( app.current && app.current.can_write ); }

        /* ----------------------------------------------------------- the list */

        function section( row ) {
            if ( row.state === 'queued' || row.state === 'running' ) { return 'working'; }
            if ( row.state === 'review' || row.state === 'waiting_input' || row.state === 'failed' ) { return 'needs'; }
            if ( row.shelf === 'pinned' ) { return 'pinned'; }
            return 'recent';
        }

        function rowHtml( row ) {
            var selected = app.current && app.current.uuid === row.uuid;
            return '<div role="option" id="exp-ai-v-row-' + esc( row.uuid ) + '" class="exp-ai-v-row exp-ai-v-st-' + esc( row.state ) + ( selected ? ' exp-ai-v-row-open' : '' ) + '"'
                 + ' data-uuid="' + esc( row.uuid ) + '" aria-selected="' + ( selected ? 'true' : 'false' ) + '" title="' + esc( stateLabel( row.state ) + ( row.cost ? ' · ' + row.cost : '' ) ) + '">'
                 + '<span class="exp-ai-v-glyph" aria-hidden="true"></span>'
                 + '<span class="exp-ai-v-row-title' + ( row.untitled ? ' exp-ai-v-untitled' : '' ) + '">' + esc( row.title ) + '</span>'
                 + ( row.unread ? '<span class="exp-ai-v-unread" title="' + esc( T( 'unread' ) ) + '"><span class="exp-ai-sr">' + esc( T( 'unread' ) ) + '</span></span>' : '' )
                 + '<span class="exp-ai-v-row-meta">' + ( row.bulk ? '<span class="exp-ai-v-badge">' + esc( T( 'bulk' ) ) + '</span> ' : '' )
                 + esc( row.origin === 'panel' ? T( 'origin_panel' ) : stateLabel( row.state ) + ( row.progress ? ' ' + row.progress : '' ) ) + '</span></div>';
        }

        function renderList() {
            var groups = app.shelf ? [ [ app.shelf === 'settled' ? 'section_settled' : 'section_archived', app.rows ] ] : [
                [ 'section_working', app.rows.filter( function ( r ) { return section( r ) === 'working'; } ) ],
                [ 'section_needs', app.rows.filter( function ( r ) { return section( r ) === 'needs'; } ) ],
                [ 'section_pinned', app.rows.filter( function ( r ) { return section( r ) === 'pinned'; } ) ],
                [ 'section_recent', app.rows.filter( function ( r ) { return section( r ) === 'recent'; } ) ],
                [ 'section_shared', app.shared ]
            ];
            var html = '';
            groups.forEach( function ( g ) {
                if ( !g[1].length ) { return; }
                html += '<div class="exp-ai-v-section" role="group" aria-label="' + esc( T( g[0] ) ) + '"><h3>' + esc( T( g[0] ) ) + ' <span>' + g[1].length + '</span></h3>'
                      + g[1].map( rowHtml ).join( '' ) + '</div>';
            } );
            el.list.innerHTML = html || '<p class="exp-ai-v-list-empty">' + esc( el.filter.value.trim() ? T( 'no_match' ) : T( 'no_threads' ) ) + '</p>';
            if ( app.current ) {
                el.list.setAttribute( 'aria-activedescendant', 'exp-ai-v-row-' + app.current.uuid );
            }
            var needs = app.rows.filter( function ( r ) { return section( r ) === 'needs'; } ).length;
            var base = document.title.replace( /^\(\d+\)\s+/, '' );
            document.title = needs ? '(' + needs + ') ' + base : base;
            qa( '.exp-ai-v-shelves a' ).forEach( function ( a ) {
                a.setAttribute( 'aria-current', a.getAttribute( 'data-shelf' ) === app.shelf ? 'page' : 'false' );
            } );
        }

        var listSeq = 0;
        function loadList() {
            var seq = ++listSeq;
            return api( 'threads', { shelf: app.shelf || 'active', search: el.filter.value.trim() } ).then( function ( data ) {
                if ( seq !== listSeq ) { return; }
                app.rows = data.threads || [];
                app.shared = app.shelf ? [] : data.shared || [];
                [ 'settled', 'archived' ].forEach( function ( k ) {
                    var c = q( '[data-count="' + k + '"]' );
                    if ( c ) { c.textContent = data.counts && data.counts[k] ? data.counts[k] : ''; }
                } );
                renderList();
            } ).catch( function ( err ) { el.list.innerHTML = '<p class="exp-ai-v-error">' + esc( T( 'error' ) + ' ' + ( err.message || '' ) ) + '</p>'; } );
        }

        function orderedRows() {
            return qa( '.exp-ai-v-row', el.list ).map( function ( r ) { return r.getAttribute( 'data-uuid' ); } );
        }

        /* ---------------------------------------------------------- the thread */

        function setHeader( t ) {
            app.current = t;
            root.setAttribute( 'data-thread', t.uuid );
            el.empty.hidden = true;
            el.thread.hidden = false;
            el.title.value = t.untitled ? '' : t.title;
            el.title.placeholder = T( 'new_thread' );
            el.title.readOnly = !t.can_write;
            el.state.textContent = stateLabel( t.state ) + ( t.progress ? ' · ' + t.progress : '' );
            el.state.className = 'exp-ai-v-state exp-ai-v-st-' + t.state;
            el.readonly.hidden = t.can_write;
            var pin = q( '[data-command="pin"]' );
            pin.setAttribute( 'aria-pressed', t.shelf === 'pinned' ? 'true' : 'false' );
            qa( '.exp-ai-v-thread-actions button, .exp-ai-v-controls button, .exp-ai-v-controls select, .exp-ai-v-composer textarea, .exp-ai-v-composer button' ).forEach( function ( b ) {
                b.disabled = !t.can_write;
            } );
            renderChips( t );
            renderSettings( t );
            renderCost( t );
            renderRuns( t );
            renderCheckpoints( t );
            syncSide( t );
        }

        /* ------------------------------------------------ checkpoints (doc/checkpoints.md) */

        function renderCheckpoints( t ) {
            var box = q( '.exp-ai-v-checkpoints' );
            if ( !box ) { return; }
            var list = t.checkpoints || [];
            box.hidden = !list.length;
            // oldest first, the newest at the end next to the composer
            q( '.exp-ai-v-cp-list', box ).innerHTML = list.slice().reverse().map( function ( c ) {
                return '<li class="exp-ai-v-cp" data-cp="' + c.id + '"><span class="exp-ai-v-cp-mark" aria-hidden="true">◇</span> '
                     + '<span class="exp-ai-v-cp-label" title="' + esc( timeText( c.created ) ) + '">' + esc( c.label ) + '</span>'
                     + ( t.can_write ? ' <button type="button" class="exp-ai-v-link" data-restore-cp="' + c.id + '" aria-label="' + esc( T( 'cp_restore_to', { '%label': c.label } ) ) + '">'
                                       + esc( T( 'cp_restore' ) ) + '</button>' : '' ) + '</li>';
            } ).join( '' );
        }

        var restoreDialog = q( '.exp-ai-v-restore-dialog' ), restoreTarget = null;
        function closeRestore() { if ( restoreDialog ) { restoreDialog.close ? restoreDialog.close() : restoreDialog.removeAttribute( 'open' ); } }
        function previewRestore( id ) {
            if ( !app.current || !restoreDialog ) { return; }
            restoreTarget = id;
            showError( q( '.exp-ai-v-error', restoreDialog ), null );
            api( 'checkpoint_restore', { thread: app.current.uuid, checkpoint: id, preview: '1' } ).then( function ( data ) {
                var p = data.preview || {};
                var draftLi = function ( d ) { return '<li>' + esc( d.name + ' (' + d.language + ', v' + d.version + ')' ) + ( d.fields ? ': ' + esc( d.fields.join( ', ' ) ) : '' ) + ( d.why ? ' · ' + esc( d.why ) : '' ) + '</li>'; };
                var html = '';
                if ( p.nothing ) { html += '<p>' + esc( T( 'cp_nothing' ) ) + '</p>'; }
                if ( p.discard && p.discard.length ) { html += '<h3>' + esc( T( 'cp_discard' ) ) + '</h3><ul>' + p.discard.map( draftLi ).join( '' ) + '</ul>'; }
                if ( p.revert && p.revert.length ) { html += '<h3>' + esc( T( 'cp_revert' ) ) + '</h3><ul>' + p.revert.map( draftLi ).join( '' ) + '</ul>'; }
                if ( p.left && p.left.length ) { html += '<h3>' + esc( T( 'cp_left' ) ) + '</h3><ul>' + p.left.map( draftLi ).join( '' ) + '</ul>'; }
                var lines = [];
                if ( p.stale && p.stale.length ) { lines.push( T( 'cp_stale', { '%count': p.stale.length } ) ); }
                if ( p.restore && p.restore.length ) { lines.push( T( 'cp_restore_changes', { '%count': p.restore.length } ) ); }
                if ( p.exchanges ) { lines.push( T( 'cp_exchanges', { '%count': p.exchanges } ) ); }
                if ( p.exchanges_back ) { lines.push( T( 'cp_exchanges_back', { '%count': p.exchanges_back } ) ); }
                if ( p.followups && p.followups.length ) { lines.push( T( 'cp_followups', { '%count': p.followups.length } ) ); }
                if ( lines.length ) { html += '<ul class="exp-ai-v-restore-counts">' + lines.map( function ( l ) { return '<li>' + esc( l ) + '</li>'; } ).join( '' ) + '</ul>'; }
                if ( p.conversation_only ) { html += '<p class="exp-ai-v-hint">' + esc( T( 'cp_conversation_only' ) ) + '</p>'; }
                q( '.exp-ai-v-restore-label', restoreDialog ).textContent = p.checkpoint ? p.checkpoint.label : '';
                q( '.exp-ai-v-restore-list', restoreDialog ).innerHTML = html;
                var confirm = q( '[data-restore="confirm"]', restoreDialog );
                confirm.disabled = !!p.blocked || !!p.nothing;
                if ( p.blocked ) { showError( q( '.exp-ai-v-error', restoreDialog ), { message: p.blocked } ); }
                if ( restoreDialog.showModal ) { restoreDialog.showModal(); } else { restoreDialog.setAttribute( 'open', 'open' ); }
                ( confirm.disabled ? q( '[data-restore="cancel"]', restoreDialog ) : confirm ).focus();
            } ).catch( function ( err ) { toast( T( 'error' ) + ' ' + ( err.message || '' ) ); } );
        }
        function doRestore() {
            if ( !app.current || !restoreTarget ) { return; }
            var uuid = app.current.uuid;
            var confirm = q( '[data-restore="confirm"]', restoreDialog );
            confirm.disabled = true;
            api( 'checkpoint_restore', { thread: uuid, checkpoint: restoreTarget } ).then( function ( data ) {
                closeRestore();
                var errors = ( data.result && data.result.errors ) || [];
                toast( T( 'cp_restored' ) + ( errors.length ? ' ' + errors.join( '; ' ) : '' ) );
                // the conversation, drafts, changes and plan as they are now
                return open( uuid, false ).then( function () { review.seen = null; plan.seen = null; if ( app.current ) { syncSide( app.current ); } } );
            } ).catch( function ( err ) { showError( q( '.exp-ai-v-error', restoreDialog ), err ); confirm.disabled = false; } );
        }
        if ( restoreDialog ) {
            restoreDialog.addEventListener( 'click', function ( e ) {
                var b = e.target.closest( '[data-restore]' );
                if ( !b ) { return; }
                if ( b.getAttribute( 'data-restore' ) === 'confirm' ) { doRestore(); } else { closeRestore(); }
            } );
        }

        /** The items of a plan that runs side by side (doc/parallel-runs.md): one row per child run */
        function renderRuns( t ) {
            if ( !el.runs ) { return; }
            var r = t.runs || { runs: [] };
            el.runs.hidden = !r.runs.length;
            if ( !r.runs.length ) { return; }
            el.runsProgress.textContent = T( 'run_progress', { '%done': r.done, '%total': r.total } );
            el.runsList.innerHTML = r.runs.map( function ( run ) {
                var state = run.state === 'waiting' && run.depends_on.length ? T( 'run_state_waiting', { '%items': run.depends_on.join( ', ' ) } ) : T( 'run_state_' + run.state );
                var steps = run.state === 'running' && run.progress_total ? T( 'run_steps', { '%done': run.progress_done, '%total': run.progress_total } ) : '';
                return '<li class="exp-ai-v-run exp-ai-v-run-' + esc( run.state ) + '" data-run="' + run.id + '">'
                     + '<span class="exp-ai-v-run-n">' + esc( T( 'run_item', { '%n': run.item + 1 } ) ) + '</span> '
                     + '<span class="exp-ai-v-run-label">' + esc( run.label ) + '</span> '
                     + '<span class="exp-ai-v-badge exp-ai-v-badge-run-' + esc( run.state ) + '">' + esc( state ) + '</span>'
                     + ( steps ? ' <span class="exp-ai-v-muted">' + esc( steps ) + '</span>' : '' )
                     + ( run.actual ? ' <span class="exp-ai-v-muted">' + esc( run.actual ) + '</span>' : '' )
                     + ( run.last ? '<span class="exp-ai-v-run-last">' + esc( run.last ) + '</span>' : '' ) + '</li>';
            } ).join( '' );
        }

        function chip( node, kind ) {
            return '<span class="exp-ai-v-chip exp-ai-v-chip-' + kind + '" title="' + esc( T( 'scope_' + kind ) ) + '">'
                 + '<span class="exp-ai-v-chip-kind" aria-hidden="true">' + ( kind === 'subtree' ? '▾' : '●' ) + '</span>' + esc( node.name ) + '</span>';
        }

        function renderChips( t ) {
            var s = t.scope || {};
            var html = ( s.nodes || [] ).map( function ( n ) { return chip( n, 'node' ); } ).join( '' )
                     + ( s.subtrees || [] ).map( function ( n ) { return chip( n, 'subtree' ); } ).join( '' )
                     + ( s.classes || [] ).map( function ( c ) { return '<span class="exp-ai-v-chip exp-ai-v-chip-class">' + esc( c.name ) + '</span>'; } ).join( '' )
                     + ( s.languages || [] ).map( function ( l ) { return '<span class="exp-ai-v-chip exp-ai-v-chip-lang">' + esc( l.code ) + '</span>'; } ).join( '' );
            el.chips.innerHTML = html || '<span class="exp-ai-v-chip-none">' + esc( T( 'scope_none' ) ) + '</span>';
            if ( t.bulk ) {
                el.chips.insertAdjacentHTML( 'beforeend', '<span class="exp-ai-v-badge">' + esc( T( 'bulk' ) ) + '</span>' );
            }
        }

        function renderSettings( t ) {
            qa( '.exp-ai-v-seg [data-mode]' ).forEach( function ( b ) {
                var on = b.getAttribute( 'data-mode' ) === t.run_mode;
                b.setAttribute( 'aria-checked', on ? 'true' : 'false' );
                b.tabIndex = on ? 0 : -1;
                if ( b.getAttribute( 'data-mode' ) === 'write' && t.bulk ) { b.disabled = true; }
            } );
            // models: grouped by provider, from the models the user may use
            var models = app.boot ? app.boot.models : null;
            var html = '';
            var found = false;
            ( models ? models.groups : [] ).forEach( function ( g ) {
                html += '<optgroup label="' + esc( g.label ) + '">' + ( g.models || [] ).map( function ( m ) {
                    if ( m.alias === t.model_alias ) { found = true; }
                    return '<option value="' + esc( m.alias ) + '"' + ( m.alias === t.model_alias ? ' selected' : '' ) + '>' + esc( m.label + ( m.writes ? '' : ' · ' + T( 'model_reads_only' ) ) ) + '</option>';
                } ).join( '' ) + '</optgroup>';
            } );
            if ( !found && t.model_alias ) {
                html = '<option value="' + esc( t.model_alias ) + '" selected>' + esc( t.model_label || t.model_alias ) + '</option>' + html;
            }
            el.model.innerHTML = html;
            el.model.disabled = !t.can_write || !( app.boot && app.boot.model_choice );
            // a batch lane is for bulk work only: approved plans go to the provider as a batch (doc/parallel-runs.md)
            el.lane.innerHTML = ( app.lanes || [] ).map( function ( l ) {
                return '<option value="' + esc( l.name ) + '"' + ( l.name === t.lane ? ' selected' : '' ) + ( !l.usable || ( l.batch && !t.bulk ) ? ' disabled' : '' ) + '>'
                     + esc( l.label + ( l.batch ? ' · ' + T( t.bulk ? 'lane_batch_plan' : 'lane_batch_note' ) : '' ) ) + '</option>';
            } ).join( '' );
            var reviews = app.boot ? app.boot.choices.review : [ 'staged', 'direct' ];
            qa( 'option', el.review ).forEach( function ( o ) {
                o.disabled = reviews.indexOf( o.value ) === -1 || ( o.value === 'direct' && t.bulk && t.origin !== 'panel' );
                o.selected = o.value === t.review_policy;
            } );
            var notes = [];
            if ( t.bulk && t.run_mode === 'plan' ) { notes.push( T( 'bulk_note' ) ); }
            else if ( t.run_mode === 'plan' ) { notes.push( T( 'plan_note' ) ); }
            else if ( t.review_policy === 'staged' && t.origin !== 'panel' ) { notes.push( T( 'staged_note' ) ); }
            else { notes.push( T( 'direct_note' ) ); }
            if ( t.lane && t.lane !== 'auto' && t.lane !== 'standard' ) { notes.push( T( 'lane_foreground' ) ); }
            el.note.textContent = notes.join( ' ' );
            // the model select was rebuilt: the states of the models (stopped, unreachable) are applied again (expai.js)
            if ( app.current && app.current.uuid === t.uuid ) { ExpAi.emit( 'vector:open', { thread: t, header: true } ); }
        }

        function renderCost( t ) {
            var dl = q( '.exp-ai-v-cost' );
            if ( dl ) {
                var rows = [];
                if ( t.cost ) { rows.push( [ T( 'cost_spent' ), t.cost ] ); }
                rows.push( [ T( 'cost_tokens' ), t.tokens ], [ T( 'cost_requests' ), String( t.requests ) ], [ T( 'cost_model' ), t.model_label || t.model_alias || '-' ],
                           [ T( 'cost_lane' ), ( app.lanes.filter( function ( l ) { return l.name === t.lane; } )[0] || { label: t.lane } ).label ] );
                dl.innerHTML = rows.map( function ( r ) { return '<div><dt>' + esc( r[0] ) + '</dt><dd>' + esc( r[1] ) + '</dd></div>'; } ).join( '' );
            }
            // estimate vs actual per item of the plan (parallel runs and batch plans)
            var box = q( '.exp-ai-v-cost-items' );
            var runs = t.runs && t.runs.runs ? t.runs.runs : [];
            if ( box ) {
                // money is '' where costs are not shown: then there is nothing to compare
                box.hidden = !runs.some( function ( r ) { return r.estimate || r.actual; } );
                box.innerHTML = box.hidden ? '' : '<h4>' + esc( T( 'cost_items' ) ) + '</h4><table class="exp-ai-v-cost-table"><thead><tr><th scope="col">' + esc( T( 'cost_item' ) )
                    + '</th><th scope="col">' + esc( T( 'cost_estimate' ) ) + '</th><th scope="col">' + esc( T( 'cost_actual' ) ) + '</th></tr></thead><tbody>'
                    + runs.map( function ( r ) {
                        return '<tr class="exp-ai-v-run-' + esc( r.state ) + '"><td>' + ( r.item + 1 ) + ' · ' + esc( r.label ) + '</td><td>' + esc( r.estimate ) + '</td><td>'
                             + esc( r.actual ) + ( r.state === 'running' ? ' …' : '' ) + '</td></tr>';
                    } ).join( '' ) + '</tbody></table>';
            }
            var count = q( '[data-tab-count="cost"]' );
            if ( count ) { count.textContent = t.cost || ''; }
        }

        function renderDrafts( drafts ) {
            var ul = q( '.exp-ai-v-drafts' );
            if ( !ul ) { return; }
            ul.innerHTML = ( drafts || [] ).map( function ( d, i ) {
                return '<li role="option" id="exp-ai-v-draft-' + i + '" class="exp-ai-v-draft"><span class="exp-ai-mark" aria-hidden="true"></span><span class="exp-ai-v-draft-name">' + esc( d.name ) + '</span>'
                     + '<span class="exp-ai-v-draft-meta">' + esc( d.class_name + ' · ' + d.language + ' · ' + T( 'status_' + d.status ) ) + '</span>'
                     + '<span class="exp-ai-v-draft-links">' + ( d.urls.edit ? '<a href="' + esc( url( d.urls.edit ) ) + '">' + esc( T( 'edit' ) ) + '</a>' : '' )
                     + '<a href="' + esc( url( d.urls.view ) ) + '">' + esc( T( 'view' ) ) + '</a>'
                     // #382 "What changed" of a draft written directly: the diff against its base, revert per part (expai_whatchanged.js)
                     + ( d.is_draft && canWrite() ? '<button type="button" class="exp-ai-v-link" data-exp-ai-what-changed="' + esc( d.object_id + ':' + d.version ) + '">' + esc( T( 'what_changed' ) ) + '</button>' : '' )
                     + '</span></li>';
            } ).join( '' );
            var none = q( '[data-placeholder="drafts"]' );
            if ( none ) { none.hidden = !!( drafts && drafts.length ); }
        }

        /* ------------------------------------------- staged review (doc/review.md) */

        var review = { groups: [], counts: null, canDecide: false, active: null, seen: '', editing: null };

        function reviewBox() { return q( '.exp-ai-v-changes' ); }
        function wordDiff( a, b ) {
            // word level marks inside a replaced part; long texts are shown whole
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
        function linesHtml( text, cls, sign ) {
            return String( text ).split( '\n' ).map( function ( line ) {
                return '<div class="exp-ai-v-hl ' + cls + '"><span class="exp-ai-v-sign" aria-hidden="true">' + sign + '</span><span>' + line + '</span></div>';
            } ).join( '' );
        }
        function hunkHtml( c, h, n, count ) {
            var base = h.base_text, prop = h.prop_text, body = '';
            if ( h.context_before ) { body += linesHtml( esc( h.context_before ), 'exp-ai-v-hl-ctx', ' ' ); }
            if ( base && prop && base.split( '\n' ).length === prop.split( '\n' ).length ) {
                var bl = base.split( '\n' ), pl = prop.split( '\n' ), dl = '', il = '';
                bl.forEach( function ( line, k ) { var d = wordDiff( line, pl[k] ); dl += linesHtml( d[0], 'exp-ai-v-hl-del', '-' ); il += linesHtml( d[1], 'exp-ai-v-hl-ins', '+' ); } );
                body += dl + il;
            } else {
                if ( base ) { body += linesHtml( esc( base ).split( '\n' ).map( function ( l ) { return '<del>' + l + '</del>'; } ).join( '\n' ), 'exp-ai-v-hl-del', '-' ); }
                if ( prop ) { body += linesHtml( esc( prop ).split( '\n' ).map( function ( l ) { return '<ins>' + l + '</ins>'; } ).join( '\n' ), 'exp-ai-v-hl-ins', '+' ); }
            }
            if ( h.context_after ) { body += linesHtml( esc( h.context_after ), 'exp-ai-v-hl-ctx', ' ' ); }
            var open = [ 'pending', 'accepted', 'rejected', 'partial' ].indexOf( c.decision ) >= 0 && review.canDecide;
            var label = T( 'rv_part', { '%n': n, '%count': count } );
            var editing = review.editing && review.editing.change === c.id && review.editing.hunk === h.id;
            return '<div class="exp-ai-v-hunk exp-ai-v-hunk-' + esc( h.decision ) + '" tabindex="-1" role="group" aria-label="' + esc( c.name + ': ' + label + ', ' + T( 'rv_decision_' + h.decision ) ) + '"'
                 + ' data-change="' + c.id + '" data-hunk="' + esc( h.id ) + '">'
                 + '<div class="exp-ai-v-hunk-head"><span>' + esc( label ) + ( h.edited ? ' · ' + esc( T( 'rv_edited' ) ) : '' ) + '</span>'
                 + '<span class="exp-ai-v-badge exp-ai-v-badge-' + esc( h.decision ) + '">' + esc( T( 'rv_decision_' + h.decision ) ) + '</span></div>'
                 + ( editing ? '<label class="exp-ai-sr" for="exp-ai-v-hunk-edit">' + esc( T( 'rv_edit' ) ) + '</label><textarea id="exp-ai-v-hunk-edit" class="exp-ai-v-hunk-edit" rows="4">' + esc( prop ) + '</textarea>'
                             + '<div class="exp-ai-v-hunk-actions"><button type="button" class="exp-ai-v-btn exp-ai-v-btn-small exp-ai-v-btn-primary" data-rv="save">' + esc( T( 'rv_save' ) ) + '</button>'
                             + '<button type="button" class="exp-ai-v-btn exp-ai-v-btn-small" data-rv="cancel">' + esc( T( 'rv_cancel' ) ) + '</button></div>'
                           : '<div class="exp-ai-v-hunk-body">' + body + '</div>' )
                 + ( !h.mergeable ? '<p class="exp-ai-v-hint">' + esc( T( 'rv_not_alone' ) ) + '</p>' : '' )
                 // a part that would drop custom tags, embeds, ... cannot be accepted (the server refuses it too)
                 + ( h.loses ? '<p class="exp-ai-v-hint exp-ai-v-hint-loses" role="note">' + esc( T( 'rv_loses', { '%items': h.loses } ) ) + '</p>' : '' )
                 + ( open && !editing ? '<div class="exp-ai-v-hunk-actions">'
                     + ( h.loses ? '' : '<button type="button" class="exp-ai-v-btn exp-ai-v-btn-small" data-rv="accepted" aria-pressed="' + ( h.decision === 'accepted' ) + '">' + esc( T( 'rv_accept' ) ) + ' <kbd class="exp-ai-v-kbd" data-keys="accept"></kbd></button>' )
                     + '<button type="button" class="exp-ai-v-btn exp-ai-v-btn-small" data-rv="rejected" aria-pressed="' + ( h.decision === 'rejected' ) + '">' + esc( T( 'rv_reject' ) ) + ' <kbd class="exp-ai-v-kbd" data-keys="reject"></kbd></button>'
                     + ( h.editable ? '<button type="button" class="exp-ai-v-btn exp-ai-v-btn-small" data-rv="edit">' + esc( T( 'rv_edit' ) ) + ' <kbd class="exp-ai-v-kbd" data-keys="edit_hunk"></kbd></button>' : '' )
                     + ( h.decision !== 'pending' ? '<button type="button" class="exp-ai-v-link" data-rv="pending">' + esc( T( 'rv_undo' ) ) + '</button>' : '' )
                     + '</div>' : '' )
                 + '</div>';
        }
        function fieldHtml( g, c ) {
            var decision = c.decision === 'applied' ? T( 'rv_decision_applied', { '%version': c.applied_version } ) : T( 'rv_decision_' + c.decision );
            var open = [ 'pending', 'accepted', 'rejected', 'partial' ].indexOf( c.decision ) >= 0 && review.canDecide;
            var conflict = ( c.decision === 'conflict' || c.base_changed ) && c.decision !== 'applied';
            var hunks = c.decision === 'applied' || c.decision === 'stale' ? '' : c.hunks.map( function ( h, i ) { return hunkHtml( c, h, i + 1, c.hunks.length ); } ).join( '' );
            return '<div class="exp-ai-v-cf" data-change="' + c.id + '">'
                 + '<div class="exp-ai-v-cf-head"><strong>' + esc( c.name ) + '</strong> <span class="exp-ai-v-badge exp-ai-v-badge-' + esc( c.decision ) + '">' + esc( decision ) + '</span>'
                 + ( open ? '<span class="exp-ai-v-cf-actions"><button type="button" class="exp-ai-v-link" data-rv-field="accepted">' + esc( T( 'rv_accept_field' ) ) + '</button>'
                          + '<button type="button" class="exp-ai-v-link" data-rv-field="rejected">' + esc( T( 'rv_reject_field' ) ) + '</button></span>' : '' ) + '</div>'
                 + ( conflict ? '<div class="exp-ai-v-conflict" role="alert"><p>' + esc( T( 'rv_conflict' ) ) + '</p>' + ( review.canDecide ? '<p class="exp-ai-v-conflict-actions">'
                     + '<button type="button" class="exp-ai-v-btn exp-ai-v-btn-small" data-rv-conflict="keep">' + esc( T( 'rv_keep' ) ) + '</button>'
                     + '<button type="button" class="exp-ai-v-btn exp-ai-v-btn-small" data-rv-conflict="restage">' + esc( T( 'rv_restage' ) ) + '</button>'
                     + ( g.is_new ? '' : '<button type="button" class="exp-ai-v-btn exp-ai-v-btn-small" data-rv-conflict="rebase">' + esc( T( 'rv_rebase' ) ) + '</button>' ) + '</p>' : '' ) + '</div>' : '' )
                 + ( c.datatype === 'ezxmltext' && hunks ? '<p class="exp-ai-v-hint">' + esc( T( 'rv_markup' ) ) + '</p>' : '' )
                 + hunks + '</div>';
        }
        function renderReview() {
            var box = reviewBox();
            if ( !box ) { return; }
            var list = q( '.exp-ai-v-review-list', box );
            var c = review.counts || { pending_hunks: 0, open: 0, accepted: 0, total: 0 };
            var active = review.groups.filter( function ( g ) { return g.fields.some( function ( f ) { return f.decision !== 'stale'; } ); } );
            list.innerHTML = active.map( function ( g ) {
                var fields = g.fields.filter( function ( f ) { return f.decision !== 'stale'; } );
                return '<section class="exp-ai-v-cg' + ( g.conflict ? ' exp-ai-v-cg-conflict' : '' ) + '" data-key="' + esc( g.key ) + '">'
                     + '<header class="exp-ai-v-cg-head"><span class="exp-ai-mark" aria-hidden="true"></span> '
                     + ( g.is_new ? '<span class="exp-ai-v-badge exp-ai-v-badge-new">' + esc( T( 'rv_new' ) ) + '</span> ' : '' )
                     + ( g.url ? '<a href="' + esc( url( g.url ) ) + '">' + esc( g.name || g.class_name ) + '</a>' : '<strong>' + esc( g.name || g.class_name ) + '</strong>' )
                     + '<span class="exp-ai-v-cg-meta">' + esc( [ g.class_name, g.language_name, g.is_new && g.parent_name ? T( 'rv_under', { '%name': g.parent_name } ) : '' ].filter( Boolean ).join( ' · ' ) ) + '</span></header>'
                     + fields.map( function ( f ) { return fieldHtml( g, f ); } ).join( '' ) + '</section>';
            } ).join( '' );
            paintKeys();
            var none = q( '[data-placeholder="review"]', box );
            none.hidden = active.length > 0;
            var fieldsCount = active.reduce( function ( n, g ) { return n + g.fields.filter( function ( f ) { return f.decision !== 'stale' && f.decision !== 'applied'; } ).length; }, 0 );
            q( '.exp-ai-v-review-bar', box ).hidden = !c.open;
            q( '.exp-ai-v-review-summary', box ).textContent = T( 'rv_summary', { '%objects': active.length, '%fields': fieldsCount, '%pending': c.pending_hunks } );
            q( '.exp-ai-v-review-all', box ).hidden = !review.canDecide;
            q( '.exp-ai-v-review-foot', box ).hidden = !review.canDecide || !c.accepted;
            var count = q( '[data-tab-count="diff"]' );
            if ( count ) { count.textContent = c.pending_hunks ? String( c.pending_hunks ) : ''; }
            if ( review.active ) { focusHunk( review.active.change, review.active.hunk, false ); }
        }
        function takeReview( data ) {
            review.groups = data.groups || [];
            review.counts = data.counts || null;
            review.canDecide = !!data.can_decide;
            if ( data.thread && app.current && data.thread.uuid === app.current.uuid ) {
                review.seen = reviewKey( data.thread );
                setHeader( data.thread );
            }
            if ( data.drafts ) { renderDrafts( data.drafts ); }
            renderReview();
            return data;
        }
        function reviewKey( t ) { return t && t.changes ? [ t.changes.total, t.changes.open, t.changes.pending_hunks, t.changes.applied, t.changes.conflict, t.changes.accepted ].join( ':' ) : ''; }
        function loadReview() {
            if ( !app.current ) { return Promise.resolve(); }
            var uuid = app.current.uuid;
            return api( 'changes', { thread: uuid } ).then( function ( data ) {
                if ( app.current && app.current.uuid === uuid ) { takeReview( data ); }
            } ).catch( function ( err ) { showError( q( '.exp-ai-v-error', reviewBox() ), err ); } );
        }
        function reviewCall( fn, data ) {
            showError( q( '.exp-ai-v-error', reviewBox() ), null );
            return api( fn, extend( { thread: app.current.uuid }, data ) ).then( takeReview ).catch( function ( err ) {
                showError( q( '.exp-ai-v-error', reviewBox() ), err );
                throw err;
            } );
        }
        function hunkEls() { return qa( '.exp-ai-v-hunk', reviewBox() ); }
        function focusHunk( change, hunk, scroll ) {
            var el2 = hunkEls().filter( function ( h ) { return h.getAttribute( 'data-change' ) === String( change ) && h.getAttribute( 'data-hunk' ) === hunk; } )[0];
            hunkEls().forEach( function ( h ) { h.classList.toggle( 'exp-ai-v-hunk-active', h === el2 ); } );
            if ( el2 ) {
                review.active = { change: change, hunk: hunk };
                if ( scroll !== false ) { el2.focus(); el2.scrollIntoView( { block: 'nearest' } ); }
            }
            return el2;
        }
        function activeHunk() {
            var list = hunkEls();
            var cur = review.active ? list.filter( function ( h ) { return h.getAttribute( 'data-change' ) === String( review.active.change ) && h.getAttribute( 'data-hunk' ) === review.active.hunk; } )[0] : null;
            return cur || list.filter( function ( h ) { return h.classList.contains( 'exp-ai-v-hunk-pending' ); } )[0] || list[0] || null;
        }
        function moveHunk( delta ) {
            var list = hunkEls();
            if ( !list.length ) { return false; }
            var cur = activeHunk();
            var i = review.active && cur ? list.indexOf( cur ) + delta : 0;
            var next = list[Math.max( 0, Math.min( list.length - 1, i ) )];
            focusHunk( next.getAttribute( 'data-change' ), next.getAttribute( 'data-hunk' ) );
            return true;
        }
        function reviewVisible() {
            var pane = q( '[data-pane="diff"]' );
            return pane && !pane.hidden && hunkEls().length > 0;
        }
        function decideHunk( decision, h ) {
            h = h || activeHunk();
            if ( !h || !review.canDecide ) { toast( T( 'item_placeholder' ) ); return; }
            var change = h.getAttribute( 'data-change' ), hunk = h.getAttribute( 'data-hunk' );
            var list = hunkEls(), idx = list.indexOf( h );
            reviewCall( 'change_decide', { decisions: JSON.stringify( { list: [ { change: parseInt( change, 10 ), hunk: hunk, decision: decision } ] } ) } ).then( function () {
                // on to the next part that waits
                var after = hunkEls();
                var next = after.slice( idx + 1 ).concat( after.slice( 0, idx + 1 ) ).filter( function ( x ) { return x.classList.contains( 'exp-ai-v-hunk-pending' ); } )[0];
                if ( next ) { focusHunk( next.getAttribute( 'data-change' ), next.getAttribute( 'data-hunk' ) ); } else { focusHunk( change, hunk ); }
            } ).catch( function () {} );
        }
        function decideField( decision, changeID ) {
            if ( !changeID ) { var h = activeHunk(); changeID = h ? h.getAttribute( 'data-change' ) : 0; }
            if ( !changeID || !review.canDecide ) { return; }
            reviewCall( 'change_decide', { decisions: JSON.stringify( { list: [ { change: parseInt( changeID, 10 ), hunk: '', decision: decision } ] } ) } ).catch( function () {} );
        }
        function editHunk( h ) {
            h = h || activeHunk();
            if ( !h || !q( '[data-rv="edit"]', h ) ) { return; }
            review.editing = { change: parseInt( h.getAttribute( 'data-change' ), 10 ), hunk: h.getAttribute( 'data-hunk' ) };
            review.active = { change: h.getAttribute( 'data-change' ), hunk: h.getAttribute( 'data-hunk' ) };
            renderReview();
            var ta = q( '.exp-ai-v-hunk-edit', reviewBox() );
            if ( ta ) { ta.focus(); }
        }
        function bindReview() {
            var box = reviewBox();
            if ( !box ) { return; }
            box.addEventListener( 'click', function ( e ) {
                var b = e.target.closest( 'button' );
                if ( !b || b.disabled || !app.current ) { return; }
                var hunk = b.closest( '.exp-ai-v-hunk' ), field = b.closest( '.exp-ai-v-cf' );
                if ( hunk ) { review.active = { change: hunk.getAttribute( 'data-change' ), hunk: hunk.getAttribute( 'data-hunk' ) }; }
                var rv = b.getAttribute( 'data-rv' );
                if ( rv === 'accepted' || rv === 'rejected' || rv === 'pending' ) { decideHunk( rv, hunk ); return; }
                if ( rv === 'edit' ) { editHunk( hunk ); return; }
                if ( rv === 'cancel' ) { review.editing = null; renderReview(); return; }
                if ( rv === 'save' && review.editing ) {
                    var ed = review.editing;
                    reviewCall( 'change_edit', { change: ed.change, hunk: ed.hunk, text: q( '.exp-ai-v-hunk-edit', box ).value } ).then( function () { review.editing = null; renderReview(); } ).catch( function () {} );
                    return;
                }
                if ( b.getAttribute( 'data-rv-field' ) && field ) { decideField( b.getAttribute( 'data-rv-field' ), field.getAttribute( 'data-change' ) ); return; }
                var conflict = b.getAttribute( 'data-rv-conflict' );
                if ( conflict && field ) {
                    var id = parseInt( field.getAttribute( 'data-change' ), 10 );
                    if ( conflict === 'keep' ) { decideField( 'rejected', id ); }
                    else if ( conflict === 'restage' ) { reviewCall( 'change_restage', { change: id } ).catch( function () {} ); }
                    else if ( conflict === 'rebase' ) {
                        reviewCall( 'rebase', { changes: JSON.stringify( [ id ] ) } ).then( function ( data ) {
                            if ( data.message ) { toast( T( 'rv_rebasing' ) ); run( data.message ); }
                        } ).catch( function () {} );
                    }
                    return;
                }
                var action = b.getAttribute( 'data-review' );
                if ( action === 'accept_all' ) { reviewCall( 'change_decide', { all: 'accepted' } ).catch( function () {} ); }
                else if ( action === 'reject_all' ) {
                    // a five second undo before the decision is sent
                    var timer = window.setTimeout( function () { reviewCall( 'change_decide', { all: 'rejected' } ).catch( function () {} ); }, 5000 );
                    toast( T( 'rv_rejected_all' ), function () { window.clearTimeout( timer ); } );
                }
                else if ( action === 'apply' ) {
                    if ( !review.counts || !review.counts.accepted ) { toast( T( 'rv_nothing' ) ); return; }
                    b.disabled = true;
                    reviewCall( 'apply', {} ).then( function ( data ) {
                        var r = data.result || {};
                        var msg = T( 'rv_applied', { '%count': ( r.applied || [] ).length, '%drafts': ( r.drafts || [] ).length } );
                        if ( ( r.conflicts || [] ).length ) { msg += ' ' + T( 'rv_conflicts', { '%count': r.conflicts.length } ); }
                        ( r.drafts || [] ).forEach( function ( d ) { if ( d.missing && d.missing.length ) { msg += ' ' + T( 'rv_missing', { '%fields': d.missing.join( ', ' ) } ); } } );
                        ( r.errors || [] ).forEach( function ( x ) { msg += ' ' + x.message; } );
                        toast( msg );
                        announce( msg );
                    } ).catch( function () {} ).then( function () { b.disabled = false; } );
                }
            } );
            box.addEventListener( 'focusin', function ( e ) {
                var h = e.target.closest && e.target.closest( '.exp-ai-v-hunk' );
                if ( h ) { review.active = { change: h.getAttribute( 'data-change' ), hunk: h.getAttribute( 'data-hunk' ) }; hunkEls().forEach( function ( x ) { x.classList.toggle( 'exp-ai-v-hunk-active', x === h ); } ); }
            } );
        }

        /* ------------------------------------------------ plan mode (doc/plan-mode.md) */

        var plan = { data: null, selected: null, summaries: {}, seen: '', timer: null };

        function planBox() { return q( '.exp-ai-v-plan' ); }
        function planKey( t ) { return t && t.plan ? t.plan.id + ':' + t.plan.state : ''; }
        function renderPlan() {
            var box = planBox();
            if ( !box ) { return; }
            var d = plan.data, p = d && d.plan;
            box.hidden = !p;
            q( '[data-placeholder="plan"]' ).hidden = !!p;
            var count = q( '[data-tab-count="plan"]' );
            if ( count ) { count.textContent = p && p.state === 'proposed' ? '1' : ''; }
            if ( !p ) { return; }
            var proposed = p.state === 'proposed' && d.can_decide;
            var items = p.items || [];
            if ( plan.selected === null ) { plan.selected = items.filter( function ( i ) { return i.selected && !i.refused; } ).map( function ( i ) { return i.index; } ); }
            q( '.exp-ai-v-plan-title', box ).textContent = T( 'pl_title', { '%count': items.length } );
            q( '.exp-ai-v-plan-state', box ).textContent = T( 'pl_state_' + p.state );
            q( '.exp-ai-v-plan-items', box ).innerHTML = '<table class="exp-ai-v-plan-table"><thead><tr><th scope="col">' + esc( T( 'pl_run' ) ) + '</th><th scope="col">#</th><th scope="col">' + esc( T( 'pl_action' ) )
                + '</th><th scope="col">' + esc( T( 'pl_target' ) ) + '</th><th scope="col">' + esc( T( 'pl_languages' ) ) + '</th><th scope="col">' + esc( T( 'pl_fields' ) ) + '</th>'
                + ( p.cost_enabled ? '<th scope="col">' + esc( T( 'pl_estimate_col' ) ) + '</th>' : '' ) + '</tr></thead><tbody>'
                + items.map( function ( i ) {
                    var on = plan.selected.indexOf( i.index ) >= 0 && !i.refused;
                    var money = p.item_money && p.item_money[i.index] !== undefined ? p.item_money[i.index] : '';
                    return '<tr class="' + ( i.refused ? 'exp-ai-v-plan-refused' : '' ) + ( on ? '' : ' exp-ai-v-plan-off' ) + '" data-index="' + i.index + '">'
                         + '<td><input type="checkbox" data-plan-item="' + i.index + '" aria-label="' + esc( T( 'pl_run' ) + ' ' + ( i.index + 1 ) ) + '"' + ( on ? ' checked' : '' ) + ( proposed && !i.refused ? '' : ' disabled' ) + ' /></td>'
                         + '<td>' + ( i.index + 1 ) + '</td><td>' + esc( T( 'pl_action_' + i.action ) ) + ( i.action === 'create' && i.class ? ' <span class="exp-ai-v-muted">' + esc( i.class ) + '</span>' : '' ) + '</td>'
                         + '<td>' + esc( i.target_name ) + '</td><td>' + esc( ( i.languages || [] ).join( ', ' ) ) + '</td><td>' + esc( ( i.fields || [] ).join( ', ' ) ) + '</td>'
                         + ( p.cost_enabled ? '<td>' + ( on ? esc( money ) : '' ) + '</td>' : '' ) + '</tr>'
                         + '<tr class="exp-ai-v-plan-sub' + ( on ? '' : ' exp-ai-v-plan-off' ) + '"><td></td><td colspan="' + ( p.cost_enabled ? 6 : 5 ) + '">'
                         + ( i.refused ? '<span class="exp-ai-v-plan-why">' + esc( T( 'pl_refused', { '%reason': i.refused } ) ) + '</span>'
                           : ( proposed ? '<label class="exp-ai-sr" for="exp-ai-v-plan-s' + i.index + '">' + esc( T( 'pl_instruction', { '%n': i.index + 1 } ) ) + '</label>'
                                          + '<input type="text" id="exp-ai-v-plan-s' + i.index + '" class="exp-ai-v-plan-summary" data-plan-summary="' + i.index + '" maxlength="500" value="'
                                          + esc( plan.summaries[i.index] !== undefined ? plan.summaries[i.index] : i.summary ) + '" />'
                                        : esc( i.summary ) ) )
                         + '</td></tr>';
                } ).join( '' ) + '</tbody></table>';
            var e = p.estimate || {}, m = p.money || {}, rows = [];
            if ( p.cost_enabled ) {
                rows.push( [ T( 'pl_estimate' ), e.known === false || !e.expected_micros ? T( 'pl_free' ) : T( 'pl_range', { '%expected': m.expected, '%low': m.low, '%high': m.high } )
                             + ( e.calibration && e.calibration.from >= 3 ? ' · ' + T( 'pl_calibrated', { '%count': e.calibration.from } ) : '' ) ] );
                ( p.lane_money || [] ).forEach( function ( l ) {
                    if ( e.expected_micros ) { rows.push( [ l.batch ? T( 'pl_lane_batch', { '%lane': l.label } ) : l.label, l.expected ] ); }
                } );
                if ( m.actual ) { rows.push( [ T( 'pl_actual' ), m.actual ] ); }
                if ( app.current && app.current.reserved && p.state === 'running' ) { rows.push( [ T( 'pl_reserved' ), app.current.reserved ] ); }
            }
            rows.push( [ T( 'pl_tokens' ), p.tokens || '' ], [ T( 'pl_duration' ), T( 'pl_minutes', { '%count': Math.max( 1, Math.round( ( e.duration_s || 0 ) / 60 ) ) } ) ] );
            q( '.exp-ai-v-plan-estimate', box ).innerHTML = rows.map( function ( r ) { return '<div><dt>' + esc( r[0] ) + '</dt><dd>' + esc( r[1] ) + '</dd></div>'; } ).join( '' );
            var limits = ( p.limits && p.limits.rows ) || [];
            q( '.exp-ai-v-plan-limits', box ).innerHTML = limits.map( function ( l ) {
                return '<li class="' + ( l.fits ? '' : 'exp-ai-v-plan-over' ) + '">' + esc( T( 'pl_limit', { '%label': l.label, '%percent': l.percent, '%after': l.after } ) ) + '</li>';
            } ).join( '' );
            var meta = p.meta || {};
            q( '.exp-ai-v-plan-meta', box ).innerHTML = [ 'assumptions', 'questions', 'risks' ].filter( function ( k ) { return meta[k] && meta[k].length; } ).map( function ( k ) {
                return '<h4>' + esc( T( 'pl_' + k ) ) + '</h4><ul>' + meta[k].map( function ( x ) { return '<li>' + esc( x ) + '</li>'; } ).join( '' ) + '</ul>';
            } ).join( '' );
            showError( q( '.exp-ai-v-error', box ), p.limits && p.limits.message ? { message: p.limits.message } : null );
            q( '.exp-ai-v-plan-actions', box ).hidden = !proposed;
            var approve = q( '[data-plan="approve"]', box );
            if ( approve ) { approve.disabled = !plan.selected.length || !!( p.limits && !p.limits.ok ); }
        }
        function takePlan( data ) {
            var id = data && data.plan ? data.plan.id : 0;
            if ( !plan.data || !plan.data.plan || plan.data.plan.id !== id ) {
                plan.selected = null;
                plan.summaries = {};
                // a new plan that waits for the editor comes to the front
                if ( data.plan && data.plan.state === 'proposed' && data.can_decide ) { showTab( 'plan' ); }
            }
            plan.data = data;
            if ( data.thread && app.current && data.thread.uuid === app.current.uuid ) {
                plan.seen = planKey( data.thread );
                setHeader( data.thread );
            } else if ( app.current ) {
                plan.seen = planKey( app.current );
            }
            renderPlan();
            return data;
        }
        function loadPlan() {
            if ( !app.current ) { return Promise.resolve(); }
            var uuid = app.current.uuid;
            return api( 'plan', { thread: uuid } ).then( function ( data ) {
                if ( app.current && app.current.uuid === uuid ) { takePlan( data ); }
            } ).catch( function () {} );
        }
        function planEdits() {
            return { plan: plan.data.plan.id, selected: JSON.stringify( plan.selected ), summaries: JSON.stringify( plan.summaries ) };
        }
        function reestimate() {
            window.clearTimeout( plan.timer );
            plan.timer = window.setTimeout( function () {
                if ( !plan.data || !plan.data.plan || !app.current ) { return; }
                api( 'plan_estimate', extend( { thread: app.current.uuid }, planEdits() ) ).then( function ( data ) {
                    plan.data = data;
                    renderPlan();
                } ).catch( function ( err ) { showError( q( '.exp-ai-v-error', planBox() ), err ); } );
            }, 300 );
        }
        function bindPlan() {
            var box = planBox();
            if ( !box ) { return; }
            box.addEventListener( 'change', function ( e ) {
                var idx = e.target.getAttribute( 'data-plan-item' );
                if ( idx === null ) { return; }
                idx = parseInt( idx, 10 );
                plan.selected = plan.selected.filter( function ( i ) { return i !== idx; } );
                if ( e.target.checked ) { plan.selected.push( idx ); }
                reestimate();
            } );
            box.addEventListener( 'input', function ( e ) {
                var idx = e.target.getAttribute( 'data-plan-summary' );
                if ( idx !== null ) { plan.summaries[idx] = e.target.value; }
            } );
            box.addEventListener( 'click', function ( e ) {
                var b = e.target.closest( '[data-plan]' );
                if ( !b || b.disabled || !app.current || !plan.data || !plan.data.plan ) { return; }
                var op = b.getAttribute( 'data-plan' );
                if ( op === 'ask' ) {
                    el.input.value = T( 'pl_ask' ) + ' ';
                    el.input.focus();
                    return;
                }
                if ( op === 'approve' && !plan.selected.length ) { toast( T( 'pl_none_selected' ) ); return; }
                b.disabled = true;
                showError( q( '.exp-ai-v-error', box ), null );
                api( 'plan_decide', extend( { thread: app.current.uuid, op: op }, planEdits() ) ).then( function ( data ) {
                    takePlan( data );
                    if ( op === 'reject' ) { toast( T( 'pl_rejected' ) ); return; }
                    var r = data.run || {};
                    var key = { background: 'pl_approved_bg', parallel: 'pl_approved_parallel', batch: 'pl_approved_batch' }[r.mode] || 'pl_approved_fg';
                    toast( T( key, { '%count': r.items || 0, '%parallel': r.parallel || 1, '%lane': r.lane || '' } ) );
                    loadList();
                    // a batch waits for the provider (minutes to hours): the thread is followed by the regular poll
                    if ( r.mode === 'batch' ) {
                        open( app.current.uuid, false );
                    } else if ( r.mode === 'background' || r.mode === 'parallel' ) {
                        el.workingText.textContent = T( 'queued_background' );
                        setBusy( true );
                        follow( app.current.uuid ).then( function () { setBusy( false ); loadReview(); loadPlan(); loadList(); } );
                    } else {
                        app.followups = data.followups || [];
                        renderFollowups();
                        nextFollowup();
                    }
                } ).catch( function ( err ) { showError( q( '.exp-ai-v-error', box ), err ); } ).then( function () { b.disabled = false; } );
            } );
        }

        /** The side panes follow the thread: changes and plan reload when the thread's counts or plan changed */
        function syncSide( t ) {
            if ( !t ) { return; }
            if ( reviewKey( t ) !== review.seen ) { review.seen = reviewKey( t ); loadReview(); }
            if ( planKey( t ) !== plan.seen ) { plan.seen = planKey( t ); loadPlan(); }
        }

        function stepsHtml( steps ) {
            // consecutive reads collapse into one line; writes and failures always show
            var out = [], reads = [];
            function flush() {
                if ( !reads.length ) { return; }
                out.push( reads.length === 1 ? '<li class="exp-ai-step">' + esc( reads[0].text ) + '</li>'
                    : '<li class="exp-ai-step exp-ai-v-reads"><details><summary>' + esc( T( 'lookups', { '%count': reads.length } ) ) + '</summary><ul>'
                      + reads.map( function ( s ) { return '<li>' + esc( s.text ) + '</li>'; } ).join( '' ) + '</ul></details></li>' );
                reads = [];
            }
            ( steps || [] ).forEach( function ( s ) {
                // a steering note that reached the model always shows, it is not a lookup
                if ( s.ok && !s.writes && !s.steer ) { reads.push( s ); return; }
                flush();
                out.push( '<li class="exp-ai-step' + ( s.ok ? '' : ' exp-ai-step-failed' ) + ( s.writes ? ' exp-ai-step-write' : '' ) + ( s.steer ? ' exp-ai-step-steer' : '' ) + '">'
                        + esc( s.text ) + '</li>' );
            } );
            flush();
            return out.join( '' );
        }

        function exchangeHtml( x ) {
            var drafts = ( x.drafts || [] ).map( function ( d ) {
                return '<a class="exp-ai-chip-link" href="' + esc( url( d.view ) ) + '"><span class="exp-ai-mark" aria-hidden="true"></span>' + esc( d.name ) + '</a>';
            } ).join( ' ' );
            var steps = stepsHtml( x.steps );
            return ( x.notice ? '<p class="exp-ai-fallback-notice" role="status">' + esc( x.notice ) + '</p>' : '' )
                 + '<div class="exp-ai-v-msg exp-ai-v-msg-user"><span class="exp-ai-v-who">' + esc( T( 'you' ) ) + ' <time>' + esc( timeText( x.time ) ) + '</time></span>'
                 + '<div class="exp-ai-v-body">' + ExpAi.renderText( x.user ) + '</div></div>'
                 + ( steps ? '<ul class="exp-ai-steps exp-ai-v-steps">' + steps + '</ul>' : '' )
                 + '<div class="exp-ai-v-msg exp-ai-v-msg-ai"><span class="exp-ai-v-who"><span class="exp-ai-mark" aria-hidden="true"></span> ' + esc( T( 'assistant' ) ) + '</span>'
                 + '<div class="exp-ai-v-body">' + ( x.reply ? ExpAi.renderText( x.reply ) : '<p class="exp-ai-v-muted">' + esc( T( 'no_reply' ) ) + '</p>' ) + '</div>'
                 + ( drafts ? '<div class="exp-ai-v-drafts-inline"><span>' + esc( T( 'drafts_written' ) ) + ':</span> ' + drafts + '</div>' : '' ) + '</div>'
                 // the record of the turn: model, steps, duration, tokens, cost, result (expAiTurnRecord)
                 + ( app.records[x.id] ? ExpAi.turnRecord( app.records[x.id] ) : '' );
        }

        function showExchange( x, prepend ) {
            var li = el.log.querySelector( 'li[data-id="' + x.id + '"]' );
            if ( !li ) {
                li = document.createElement( 'li' );
                li.className = 'exp-ai-v-x';
                li.setAttribute( 'data-id', x.id );
                li.tabIndex = -1;
                if ( prepend ) { el.log.insertBefore( li, el.log.firstChild ); } else { el.log.appendChild( li ); }
            }
            li.innerHTML = exchangeHtml( x );
            if ( app.oldest === null || x.id < app.oldest ) { app.oldest = x.id; }
            return li;
        }

        function scrollDown() {
            var box = q( '.exp-ai-v-scroll' );
            if ( box ) { box.scrollTop = box.scrollHeight; }
        }

        function renderFollowups() {
            el.queue.hidden = !app.followups.length;
            el.queueList.innerHTML = app.followups.map( function ( f ) {
                return '<li data-id="' + f.id + '" class="exp-ai-v-queue-' + esc( f.delivery ) + '">'
                     + ( f.delivery === 'steer' ? '<span class="exp-ai-v-badge exp-ai-v-badge-steer" title="' + esc( T( 'steer_hint' ) ) + '">' + esc( T( 'steer' ) ) + '</span> ' : '' )
                     + '<span>' + esc( f.body ) + '</span> <button type="button" class="exp-ai-v-link" data-drop="' + f.id + '" aria-label="'
                     + esc( T( 'queue_remove' ) ) + '">×</button></li>';
            } ).join( '' );
        }

        var openSeq = 0;
        function open( uuid, push ) {
            var seq = ++openSeq;
            showError( el.error, null );
            return api( 'thread', { thread: uuid } ).then( function ( data ) {
                if ( seq !== openSeq ) { return; }
                app.lanes = data.lanes || [];
                var switching = !app.current || app.current.uuid !== uuid;
                if ( switching ) {
                    // another thread: its own changes and plan
                    review.seen = null; review.active = null; review.editing = null;
                    plan.seen = null; plan.data = null; plan.selected = null; plan.summaries = {};
                }
                setHeader( data.thread );
                el.log.innerHTML = '';
                app.records = {};
                takeRecords( data.history );
                app.oldest = null;
                app.activeItem = -1;
                ( data.history.exchanges || [] ).forEach( function ( x ) { showExchange( x, false ); } );
                el.older.hidden = !data.history.has_older;
                renderDrafts( data.drafts );
                app.followups = data.followups || [];
                renderFollowups();
                app.cursor[uuid] = data.thread.last_event_id;
                setBusy( false );
                if ( data.pending && canWrite() ) {
                    showError( el.error, { message: T( 'interrupted' ) } );
                }
                if ( push !== false ) {
                    var target = url( 'expai/vector/(thread)/' + uuid );
                    if ( window.location.pathname + window.location.search !== target ) {
                        window.history.pushState( { uuid: uuid }, '', target );
                    }
                }
                var row = app.rows.filter( function ( r ) { return r.uuid === uuid; } )[0];
                if ( row ) { row.unread = false; }
                renderList();
                scrollDown();
                if ( switching ) { closeDrawers(); }
                ExpAi.emit( 'vector:open', { thread: data.thread } );
                schedulePoll( 0 );
                connectStream();
            } ).catch( function ( err ) {
                if ( seq !== openSeq ) { return; }
                el.empty.hidden = true;
                el.thread.hidden = false;
                showError( el.error, err.kind === 'server' ? { message: err.message } : err );
            } );
        }

        function newThread( nodeID ) {
            return api( 'thread_save', nodeID ? { node: nodeID } : {} ).then( function ( data ) {
                app.rows.unshift( data.thread );
                return open( data.thread.uuid ).then( function () {
                    if ( !nodeID ) { openScope(); } else { el.input.focus(); }
                } );
            } ).catch( function ( err ) { toast( T( 'error' ) + ' ' + ( err.message || '' ) ); } );
        }

        function save( fields, quiet ) {
            if ( !app.current || !canWrite() ) { return Promise.resolve(); }
            return api( 'thread_save', extend( { thread: app.current.uuid }, fields ) ).then( function ( data ) {
                if ( data.lanes ) { app.lanes = data.lanes; }
                setHeader( data.thread );
                ( data.notes || [] ).forEach( function ( n ) { toast( n ); } );
                var i = app.rows.map( function ( r ) { return r.uuid; } ).indexOf( data.thread.uuid );
                if ( i >= 0 ) { app.rows[i] = data.thread; }
                if ( !quiet ) { renderList(); }
                return data;
            } ).catch( function ( err ) {
                toast( T( 'error' ) + ' ' + ( err.message || '' ) );
                if ( app.current ) { setHeader( app.current ); }
            } );
        }

        /* --------------------------------------------------------------- running */

        function setBusy( on ) {
            app.busy = on;
            root.classList.toggle( 'exp-ai-busy', on );
            el.working.hidden = !on;
            el.stop.hidden = !on;
            el.queueBtn.hidden = !on;
            if ( el.steerBtn ) { el.steerBtn.hidden = !on; }
            el.run.hidden = on;
            if ( !on ) { app.stopRequested = false; }
            paintKeys();
        }

        var stepCount = 0;
        function step( params ) {
            return api( 'turn', extend( { thread: app.current.uuid }, params ) ).then( function ( data ) {
                takeRecords( data );
                ExpAi.watchChip( q( '.exp-ai-v-watch' ), data.watch || null, [ 'vector' ] );
                if ( data.exchange ) {
                    showExchange( data.exchange, false );
                    scrollDown();
                }
                ( data.steps || [] ).forEach( function ( s ) { announce( s.text ); } );
                if ( data.thread ) { setHeader( data.thread ); }
                if ( data.drafts ) { renderDrafts( data.drafts ); }
                // notes that steered this step leave the queue
                if ( data.followups ) { app.followups = data.followups; renderFollowups(); }
                if ( data.status === 'queued' ) {
                    // a slow model: the turn runs in the background; follow its events
                    el.workingText.textContent = T( 'queued_background' );
                    return follow( app.current.uuid );
                }
                ExpAi.emit( 'vector:step', { data: data } );
                stepCount++;
                el.workingText.textContent = T( 'working' ) + ' ' + T( 'working_step', { '%count': stepCount + 1 } );
                if ( data.status === 'continue' && !app.stopRequested ) {
                    return step( { 'continue': '1' } );
                }
                if ( data.status === 'continue' && app.stopRequested ) {
                    return api( 'stop', { thread: app.current.uuid } ).then( function ( s ) {
                        setHeader( s.thread );
                        toast( T( 'stopped' ) );
                    } );
                }
                ExpAi.emit( 'vector:done', { data: data } );
                return data;
            } );
        }

        /**
         * Follows a background turn until the thread is no longer queued or running: its events (polled; the stream
         * handles other threads), the exchange reloaded when steps arrive, the last step as the working text. Stop
         * cancels the job (expaivector::stop); the worker honors it after the current step.
         */
        function follow( uuid ) {
            var stopSent = false;
            var lastError = '';
            var steps = 0;
            // its own cursor (T373 stream): the open stream advances app.cursor too, and a shared one let it take the
            // steps away from here, so the exchange was never reloaded and the answer never shown
            var seen = app.cursor[uuid] || 0;
            var guard = ExpAi.pollGuard( 3000, { session_expired: T( 'session_expired' ), poll_lost: T( 'poll_lost' ) } );
            return new Promise( function ( resolve ) {
                function tick() {
                    guard.whenVisible( poll );
                }
                function poll() {
                    if ( !app.current || app.current.uuid !== uuid ) { resolve( null ); return; }
                    var after = {};
                    after[uuid] = seen;
                    var ask = app.stopRequested && !stopSent ? api( 'stop', { thread: uuid } ).then( function () { stopSent = true; } ) : Promise.resolve();
                    ask.then( function () {
                        return api( 'events', { threads: uuid, after: JSON.stringify( after ), open: uuid } );
                    } ).then( function ( data ) {
                        guard.ok();
                        var news = ( data.events || [] ).filter( function ( ev ) { return ev.thread === uuid; } );
                        news.forEach( function ( ev ) {
                            seen = Math.max( seen, ev.id );
                            app.cursor[uuid] = Math.max( app.cursor[uuid] || 0, ev.id );
                            if ( ev.kind === 'error' && ev.payload ) { lastError = ev.payload.message || ''; }
                            if ( ev.kind === 'status' && ev.payload && ev.payload.held ) { el.workingText.textContent = ev.payload.held; announce( ev.payload.held ); }
                            if ( ev.kind === 'step_end' && ev.payload && ev.payload.text ) {
                                steps++;
                                el.workingText.textContent = T( 'working_background', { '%count': steps } ) + ' ' + ev.payload.text;
                                announce( ev.payload.text );
                            }
                        } );
                        var st = ( data.threads || [] ).filter( function ( t ) { return t.uuid === uuid; } )[0];
                        var done = !st || ( st.state !== 'queued' && st.state !== 'running' );
                        showSlow( done ? null : st.slow, uuid );
                        if ( st && st.watch ) { ExpAi.watchChip( q( '.exp-ai-v-watch' ), st.watch, [ 'vector' ] ); }
                        var reload = news.length ? api( 'thread', { thread: uuid } ).then( function ( d ) {
                            takeRecords( d.history );
                            ( d.history.exchanges || [] ).forEach( function ( x ) { showExchange( x, false ); } );
                            renderDrafts( d.drafts );
                            // a steering note the background turn took leaves the queue
                            app.followups = d.followups || [];
                            renderFollowups();
                            setHeader( d.thread );
                            scrollDown();
                        } ) : Promise.resolve();
                        return reload.then( function () {
                            if ( done ) {
                                if ( st && st.state === 'stopped' && !app.afterSwitch ) { toast( T( 'stopped' ) ); }
                                if ( st && st.state === 'failed' ) { showError( el.error, { message: lastError || T( 'background_failed' ) } ); }
                                ExpAi.emit( 'vector:done', { data: { status: st ? st.state : 'done' } } );
                                resolve( null );
                            } else {
                                window.setTimeout( tick, Math.min( data.next_ms || 1000, 3000 ) );
                            }
                        } );
                    } ).catch( function ( err ) {
                        var verdict = guard.fail( err );
                        if ( verdict.stop ) {
                            showError( el.error, verdict.error );
                            resolve( null );
                        } else {
                            window.setTimeout( tick, verdict.delay );
                        }
                    } );
                }
                window.setTimeout( tick, 800 );
            } );
        }

        /** A turn on a slow model (doc/parallel-runs.md "Slow models"): the status line and "Switch to <model>" */
        function showSlow( slow, uuid ) {
            ExpAi.slowStatus( q( '.exp-ai-v-slow' ), slow, function () {
                api( 'switch_model', { thread: uuid } ).then( function ( d ) {
                    app.afterSwitch = d['switch'] || null;
                    toast( slow.switched || '' );
                    if ( d.thread && app.current && app.current.uuid === uuid ) { setHeader( d.thread ); }
                    // a turn that ran in the background of this window: it is followed until the old job ends; one that
                    // was only seen by the poll goes on right here
                    if ( !app.busy ) { continueAfterSwitch(); }
                } ).catch( function ( err ) { showError( el.error, err ); } );
            } );
        }
        function continueAfterSwitch() {
            var s = app.afterSwitch;
            app.afterSwitch = null;
            if ( !s || !s.pending || !app.current ) { return; }
            if ( s.background ) {
                setBusy( true );
                el.workingText.textContent = T( 'queued_background' );
                follow( app.current.uuid ).then( function () { setBusy( false ); loadList(); } );
            } else {
                run( null );
            }
        }

        function run( text ) {
            if ( !app.current || !canWrite() ) { return; }
            var t = app.current;
            if ( !( t.scope.nodes.length || t.scope.subtrees.length ) ) {
                showError( el.error, { message: T( 'empty_scope' ) } );
                openScope();
                return;
            }
            showError( el.error, null );
            stepCount = 0;
            el.workingText.textContent = T( 'working' );
            setBusy( true );
            var uuid = t.uuid;
            step( text === null ? { 'continue': '1' } : { message: text } ).catch( function ( err ) {
                showError( el.error, err );
            } ).then( function () {
                setBusy( false );
                showSlow( null, uuid );
                loadList();
                if ( app.current && app.current.uuid === uuid ) {
                    el.input.focus();
                    // switched to another model while it ran: the same message goes on there
                    if ( app.afterSwitch ) { continueAfterSwitch(); return; }
                    nextFollowup();
                }
            } );
        }

        function submit() {
            var text = el.input.value.trim();
            if ( !text ) { return; }
            if ( app.busy ) {
                queue( text );
                return;
            }
            el.input.value = '';
            run( text );
        }

        function queue( text, delivery ) {
            text = text || el.input.value.trim();
            if ( !text || !app.current ) { return; }
            api( 'followup', { thread: app.current.uuid, op: 'add', body: text, delivery: delivery || 'queue' } ).then( function ( data ) {
                el.input.value = '';
                app.followups = data.followups || [];
                renderFollowups();
                toast( T( delivery === 'steer' ? 'steered_one' : 'queued_one' ) );
            } ).catch( function ( err ) { toast( T( 'error' ) + ' ' + ( err.message || '' ) ); } );
        }

        /** Alt+Enter: while a turn runs, a note that reaches the model at its next step; otherwise the message runs */
        function steer() {
            if ( app.busy ) { queue( null, 'steer' ); } else { submit(); }
        }

        function nextFollowup() {
            if ( !app.followups.length || app.busy || !app.current ) { return; }
            api( 'followup', { thread: app.current.uuid, op: 'take' } ).then( function ( data ) {
                app.followups = data.followups || [];
                renderFollowups();
                if ( data.taken ) {
                    toast( T( 'sending_queued' ) );
                    run( data.taken.body );
                }
            } ).catch( function () { /* the queue stays as it is */ } );
        }

        function stop() {
            if ( !app.current ) { return; }
            if ( app.busy ) {
                app.stopRequested = true;
                el.workingText.textContent = T( 'stopping' );
                return;
            }
            api( 'stop', { thread: app.current.uuid } ).then( function ( data ) { setHeader( data.thread ); } ).catch( function () {} );
        }

        /* ------------------------------------------------------------- shelves */

        function shelve( shelf ) {
            if ( !app.current || !canWrite() ) { return; }
            var t = app.current;
            var previous = t.shelf;
            if ( shelf === 'pinned' && previous === 'pinned' ) { shelf = 'active'; }
            if ( shelf === 'pinned' || shelf === 'active' ) {
                save( { shelf: shelf } ).then( function () { toast( T( shelf === 'pinned' ? 'pinned' : 'unpinned' ) ); loadList(); } );
                return;
            }
            // settle and archive wait 5 seconds before they are sent: Undo needs no server round trip
            var row = el.list.querySelector( '[data-uuid="' + t.uuid + '"]' );
            if ( row ) { row.hidden = true; }
            var done = false;
            var timer = window.setTimeout( function () {
                done = true;
                api( 'thread_save', { thread: t.uuid, shelf: shelf } ).then( loadList ).catch( function ( err ) { toast( T( 'error' ) + ' ' + ( err.message || '' ) ); loadList(); } );
            }, 5000 );
            toast( T( shelf === 'settled' ? 'settled' : 'archived' ), function () {
                if ( done ) {
                    api( 'thread_save', { thread: t.uuid, shelf: previous } ).then( loadList );
                } else {
                    window.clearTimeout( timer );
                    if ( row ) { row.hidden = false; }
                }
                toast( T( 'restored' ) );
            } );
        }

        /* ---------------------------------------------------------------- scope */

        function openScope() {
            if ( !app.current || !canWrite() || !el.dialog ) { return; }
            var s = app.current.scope;
            app.scopeDraft = { nodes: s.nodes.slice(), subtrees: s.subtrees.slice(),
                               classes: s.classes.map( function ( c ) { return c.identifier; } ), languages: s.languages.map( function ( l ) { return l.code; } ) };
            var choices = app.boot ? app.boot.choices.scope : [ 'node', 'subtree', 'class' ];
            q( '.exp-ai-v-scope-classes', el.dialog ).innerHTML = ( app.boot ? app.boot.classes : [] ).map( function ( c ) {
                return '<label><input type="checkbox" value="' + esc( c.identifier ) + '"' + ( app.scopeDraft.classes.indexOf( c.identifier ) !== -1 ? ' checked' : '' )
                     + ( choices.indexOf( 'class' ) === -1 ? ' disabled' : '' ) + ' /> ' + esc( c.name ) + '</label>';
            } ).join( '' );
            q( '.exp-ai-v-scope-languages', el.dialog ).innerHTML = ( app.boot ? app.boot.languages : [] ).map( function ( l ) {
                return '<label><input type="checkbox" value="' + esc( l.code ) + '"' + ( app.scopeDraft.languages.indexOf( l.code ) !== -1 ? ' checked' : '' ) + ' /> '
                     + esc( l.name ) + ' <code>' + esc( l.code ) + '</code></label>';
            } ).join( '' );
            renderScopeNodes();
            showError( q( '.exp-ai-v-error', el.dialog ), null );
            q( '.exp-ai-v-node-search', el.dialog ).value = '';
            q( '.exp-ai-v-node-results', el.dialog ).hidden = true;
            if ( el.dialog.showModal ) { el.dialog.showModal(); } else { el.dialog.setAttribute( 'open', 'open' ); }
            q( '.exp-ai-v-node-search', el.dialog ).focus();
        }

        function renderScopeNodes() {
            var d = app.scopeDraft;
            var names = {};
            ( app.current.scope.nodes.concat( app.current.scope.subtrees ) ).forEach( function ( n ) { names[n.id] = n.name; } );
            extend( names, app.scopeNames || {} );
            var choices = app.boot ? app.boot.choices.scope : [ 'node', 'subtree', 'class' ];
            var item = function ( id, kind ) {
                var name = names[id] || '#' + id;
                return '<li><span class="exp-ai-v-chip exp-ai-v-chip-' + kind + '">' + esc( name ) + '</span>'
                     + '<label class="exp-ai-v-subtree-toggle"><input type="checkbox" data-subtree="' + id + '"' + ( kind === 'subtree' ? ' checked' : '' )
                     + ( choices.indexOf( kind === 'subtree' ? 'node' : 'subtree' ) === -1 ? ' disabled' : '' ) + ' /> ' + esc( T( 'scope_make_subtree' ) ) + '</label>'
                     + '<button type="button" class="exp-ai-v-link" data-remove="' + id + '" aria-label="' + esc( T( 'scope_remove', { '%name': name } ) ) + '">×</button></li>';
            };
            q( '.exp-ai-v-scope-nodes', el.dialog ).innerHTML = d.nodes.map( function ( id ) { return item( id, 'node' ); } ).join( '' )
                                                           + d.subtrees.map( function ( id ) { return item( id, 'subtree' ); } ).join( '' );
            q( '[data-count="classes"]', el.dialog ).textContent = d.classes.length ? '(' + d.classes.length + ')' : '';
            q( '[data-count="languages"]', el.dialog ).textContent = d.languages.length ? '(' + d.languages.length + ')' : '';
        }

        var searchTimer = null, searchSeq = 0, results = [], resultActive = -1;
        function searchNodes( text ) {
            var box = q( '.exp-ai-v-node-results', el.dialog );
            var input = q( '.exp-ai-v-node-search', el.dialog );
            window.clearTimeout( searchTimer );
            if ( text.length < 2 && !/^\d+$/.test( text ) ) {
                box.hidden = true;
                input.setAttribute( 'aria-expanded', 'false' );
                return;
            }
            var seq = ++searchSeq;
            searchTimer = window.setTimeout( function () {
                api( 'nodes', { q: text } ).then( function ( data ) {
                    if ( seq !== searchSeq ) { return; }
                    results = data.nodes || [];
                    resultActive = results.length ? 0 : -1;
                    drawResults();
                } ).catch( function () {} );
            }, 200 );
        }

        function drawResults() {
            var box = q( '.exp-ai-v-node-results', el.dialog );
            var input = q( '.exp-ai-v-node-search', el.dialog );
            box.innerHTML = results.length ? results.map( function ( n, i ) {
                return '<li role="option" id="exp-ai-v-node-' + i + '" data-index="' + i + '" aria-selected="' + ( i === resultActive ? 'true' : 'false' ) + '"'
                     + ( i === resultActive ? ' class="exp-ai-active"' : '' ) + '><strong>' + esc( n.name ) + '</strong> <span>' + esc( ( n.parent ? n.parent + ' · ' : '' ) + n['class'] ) + '</span></li>';
            } ).join( '' ) : '<li class="exp-ai-v-muted" role="presentation">' + esc( T( 'no_nodes' ) ) + '</li>';
            box.hidden = false;
            input.setAttribute( 'aria-expanded', 'true' );
            if ( resultActive >= 0 ) { input.setAttribute( 'aria-activedescendant', 'exp-ai-v-node-' + resultActive ); }
        }

        function addNode( n ) {
            var d = app.scopeDraft;
            if ( d.nodes.indexOf( n.id ) === -1 && d.subtrees.indexOf( n.id ) === -1 ) {
                var choices = app.boot ? app.boot.choices.scope : [ 'node' ];
                ( choices.indexOf( 'node' ) !== -1 ? d.nodes : d.subtrees ).push( n.id );
            }
            app.scopeNames = app.scopeNames || {};
            app.scopeNames[n.id] = n.name;
            renderScopeNodes();
            var input = q( '.exp-ai-v-node-search', el.dialog );
            input.value = '';
            q( '.exp-ai-v-node-results', el.dialog ).hidden = true;
            input.setAttribute( 'aria-expanded', 'false' );
            input.focus();
        }

        function saveScope() {
            var d = app.scopeDraft;
            d.classes = qa( '.exp-ai-v-scope-classes input:checked', el.dialog ).map( function ( i ) { return i.value; } );
            d.languages = qa( '.exp-ai-v-scope-languages input:checked', el.dialog ).map( function ( i ) { return i.value; } );
            api( 'thread_save', { thread: app.current.uuid, scope: JSON.stringify( d ) } ).then( function ( data ) {
                if ( data.lanes ) { app.lanes = data.lanes; }
                setHeader( data.thread );
                ( data.notes || [] ).forEach( function ( n ) { toast( n ); } );
                el.dialog.close ? el.dialog.close() : el.dialog.removeAttribute( 'open' );
                loadList();
                el.input.focus();
            } ).catch( function ( err ) { showError( q( '.exp-ai-v-error', el.dialog ), { message: err.message } ); } );
        }

        function bindScopeDialog() {
            if ( !el.dialog ) { return; }
            var input = q( '.exp-ai-v-node-search', el.dialog );
            input.addEventListener( 'input', function () { searchNodes( input.value.trim() ); } );
            input.addEventListener( 'keydown', function ( e ) {
                var box = q( '.exp-ai-v-node-results', el.dialog );
                if ( box.hidden || !results.length ) {
                    if ( e.key === 'Enter' ) { e.preventDefault(); }
                    return;
                }
                if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
                    e.preventDefault();
                    resultActive = ( resultActive + ( e.key === 'ArrowDown' ? 1 : -1 ) + results.length ) % results.length;
                    drawResults();
                } else if ( e.key === 'Enter' ) {
                    e.preventDefault();
                    if ( results[resultActive] ) { addNode( results[resultActive] ); }
                } else if ( e.key === 'Escape' ) {
                    e.preventDefault();
                    e.stopPropagation();
                    box.hidden = true;
                }
            } );
            el.dialog.addEventListener( 'click', function ( e ) {
                var r = e.target.closest( '.exp-ai-v-node-results [data-index]' );
                var rm = e.target.closest( '[data-remove]' );
                if ( r ) { addNode( results[parseInt( r.getAttribute( 'data-index' ), 10 )] ); }
                if ( rm ) {
                    var id = parseInt( rm.getAttribute( 'data-remove' ), 10 );
                    app.scopeDraft.nodes = app.scopeDraft.nodes.filter( function ( n ) { return n !== id; } );
                    app.scopeDraft.subtrees = app.scopeDraft.subtrees.filter( function ( n ) { return n !== id; } );
                    renderScopeNodes();
                }
                if ( e.target.closest( '[data-dialog="save"]' ) ) { saveScope(); }
                if ( e.target.closest( '[data-dialog="cancel"]' ) ) { el.dialog.close ? el.dialog.close() : el.dialog.removeAttribute( 'open' ); }
            } );
            el.dialog.addEventListener( 'change', function ( e ) {
                var t = e.target.closest( '[data-subtree]' );
                if ( !t ) { return; }
                var id = parseInt( t.getAttribute( 'data-subtree' ), 10 );
                var d = app.scopeDraft;
                d.nodes = d.nodes.filter( function ( n ) { return n !== id; } );
                d.subtrees = d.subtrees.filter( function ( n ) { return n !== id; } );
                ( t.checked ? d.subtrees : d.nodes ).push( id );
                renderScopeNodes();
            } );
        }

        /* ------------------------------------------------------- events: poll, SSE */

        function visible() { return document.visibilityState !== 'hidden'; }

        function schedulePoll( ms ) {
            window.clearTimeout( app.pollTimer );
            if ( !visible() ) { return; }
            app.pollTimer = window.setTimeout( poll, ms );
        }

        function poll() {
            if ( !visible() ) { return; }
            var watched = app.rows.filter( function ( r ) { return r.state === 'queued' || r.state === 'running'; } ).map( function ( r ) { return r.uuid; } );
            if ( app.current && watched.indexOf( app.current.uuid ) === -1 && !app.stream ) { watched.unshift( app.current.uuid ); }
            if ( !watched.length ) {
                schedulePoll( app.boot ? app.boot.poll.idle : 15000 );
                return;
            }
            var after = {};
            watched.forEach( function ( u ) { after[u] = app.cursor[u] || 0; } );
            api( 'events', { threads: watched.join( ',' ), after: JSON.stringify( after ), open: app.current ? app.current.uuid : '' } ).then( function ( data ) {
                handleEvents( data.events || [], data.threads || [] );
                schedulePoll( data.next_ms || 15000 );
            } ).catch( function () { schedulePoll( 15000 ); } );
        }

        var reloadTimer = null;
        function handleEvents( events, states ) {
            var changed = false;
            states.forEach( function ( s ) {
                app.rows.forEach( function ( r ) {
                    if ( r.uuid === s.uuid && r.state !== s.state ) { r.state = s.state; changed = true; }
                    if ( r.uuid === s.uuid && s.progress !== undefined && r.progress !== s.progress ) { r.progress = s.progress; changed = true; }
                } );
                // the open thread works on a slow model elsewhere (opened again, another window): its status and the switch
                if ( app.current && s.uuid === app.current.uuid && !app.busy ) { showSlow( s.state === 'queued' || s.state === 'running' ? s.slow : null, s.uuid ); }
            } );
            events.forEach( function ( ev ) {
                app.cursor[ev.thread] = Math.max( app.cursor[ev.thread] || 0, ev.id );
                // a thread that works elsewhere (the panel, another window): show its new steps when it is open here
                if ( app.current && ev.thread === app.current.uuid && !app.busy ) {
                    window.clearTimeout( reloadTimer );
                    reloadTimer = window.setTimeout( function () { open( app.current.uuid, false ); }, 400 );
                }
            } );
            if ( changed ) { renderList(); }
        }

        function connectStream() {
            if ( app.stream ) { app.stream.close(); app.stream = null; }
            var usable = app.boot && app.boot.transport === 'sse' && window.EventSource && app.current;
            try { usable = usable && window.sessionStorage.getItem( 'exp-ai-v-sse' ) !== 'off'; } catch ( e ) { /* storage may be off */ }
            if ( !usable ) { return; }
            var uuid = app.current.uuid;
            var source = new window.EventSource( url( 'expai/stream/(thread)/' + uuid + '/(after)/' + ( app.cursor[uuid] || 0 ) ), { withCredentials: true } );
            var first = false;
            // no first line within 2 seconds: this server does not stream; poll for the rest of the session
            var guard = window.setTimeout( function () {
                if ( !first ) {
                    source.close();
                    app.stream = null;
                    try { window.sessionStorage.setItem( 'exp-ai-v-sse', 'off' ); } catch ( e ) { /* storage may be off */ }
                    schedulePoll( 0 );
                }
            }, 2000 );
            source.onopen = function () { first = true; window.clearTimeout( guard ); };
            source.addEventListener( 'thread', function ( m ) {
                try { handleEvents( [ JSON.parse( m.data ) ], [] ); } catch ( e ) { /* a broken event is skipped */ }
            } );
            source.onerror = function () {
                if ( !first ) { return; }
                // the browser reconnects with Last-Event-ID; a 204 (all streams taken) ends it: poll
                if ( source.readyState === 2 ) { app.stream = null; schedulePoll( 0 ); }
            };
            app.stream = source;
        }

        /* ------------------------------------------------------------- keyboard */

        var escAt = 0;
        function items() { return qa( '.exp-ai-v-x', el.log ); }
        function moveItem( delta ) {
            var list = items();
            if ( !list.length ) { return; }
            app.activeItem = Math.max( 0, Math.min( list.length - 1, ( app.activeItem < 0 ? list.length : app.activeItem ) + delta ) );
            list.forEach( function ( li, i ) { li.classList.toggle( 'exp-ai-v-x-active', i === app.activeItem ); } );
            list[app.activeItem].focus();
            list[app.activeItem].scrollIntoView( { block: 'nearest' } );
        }
        function switchThread( delta ) {
            var order = orderedRows();
            if ( !order.length ) { return; }
            var i = app.current ? order.indexOf( app.current.uuid ) : -1;
            var next = order[Math.max( 0, Math.min( order.length - 1, i + delta ) )];
            if ( next && ( !app.current || next !== app.current.uuid ) ) { open( next ); }
        }
        function showTab( name ) {
            qa( '.exp-ai-v-tabs [role="tab"]' ).forEach( function ( b ) {
                var on = b.getAttribute( 'data-tab' ) === name;
                b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
                b.tabIndex = on ? 0 : -1;
            } );
            qa( '.exp-ai-v-pane' ).forEach( function ( p ) { p.hidden = p.getAttribute( 'data-pane' ) !== name; } );
            try { window.localStorage.setItem( 'exp-ai-v-tab', name ); } catch ( e ) { /* storage may be off */ }
        }
        function closeDrawers() {
            root.classList.remove( 'exp-ai-v-threads-open', 'exp-ai-v-side-open' );
            qa( '.exp-ai-v-threads-toggle, .exp-ai-v-side-toggle' ).forEach( function ( b ) { b.setAttribute( 'aria-expanded', 'false' ); } );
        }
        function toggleDrawer( cls, button ) {
            var on = !root.classList.contains( cls );
            closeDrawers();
            root.classList.toggle( cls, on );
            if ( button ) { button.setAttribute( 'aria-expanded', on ? 'true' : 'false' ); }
            // an opened drawer is brought into the window (the page may be scrolled down)
            var drawer = on && q( cls === 'exp-ai-v-side-open' ? '.exp-ai-v-side' : '.exp-ai-v-threads' );
            if ( drawer && drawer.getBoundingClientRect().bottom > window.innerHeight ) { drawer.scrollIntoView( { block: 'nearest' } ); }
            fitDrawers();
        }
        /**
         * A drawer ends inside the window: it lies over a page that may be shorter than the drawer (a long plan at
         * 200 % zoom), so its height is what is left below its top; its pane scrolls within it.
         */
        function fitDrawers() {
            [ '.exp-ai-v-side', '.exp-ai-v-threads' ].forEach( function ( sel ) {
                var d = q( sel );
                if ( !d ) { return; }
                if ( window.getComputedStyle( d ).position !== 'absolute' || !d.offsetParent ) { d.style.maxHeight = ''; return; }
                var top = Math.max( 8, d.getBoundingClientRect().top );
                d.style.maxHeight = Math.max( 240, window.innerHeight - top - 8 ) + 'px';
            } );
        }
        var fitTimer = null;
        [ 'scroll', 'resize' ].forEach( function ( type ) {
            window.addEventListener( type, function () { window.clearTimeout( fitTimer ); fitTimer = window.setTimeout( fitDrawers, 60 ); }, { passive: true } );
        } );
        function placeholder() { toast( T( 'item_placeholder' ) ); }

        var actions = {
            palette: function () { if ( window.ExpAiPalette ) { window.ExpAiPalette.open(); } },
            help: function () { if ( window.ExpAiPalette ) { window.ExpAiPalette.help(); } },
            run: function () { if ( app.busy ) { queue(); } else { submit(); } },
            queue: function () { if ( app.busy ) { queue(); } else { submit(); } },
            steer: steer,
            stop: stop,
            // j/k move through the parts of the Changes tab while it is shown, else through the exchanges
            next: function () { if ( !reviewVisible() || !moveHunk( 1 ) ) { moveItem( 1 ); } },
            previous: function () { if ( !reviewVisible() || !moveHunk( -1 ) ) { moveItem( -1 ); } },
            accept: function () { if ( hunkEls().length ) { showTab( 'diff' ); decideHunk( 'accepted' ); } else { placeholder(); } },
            reject: function () { if ( hunkEls().length ) { showTab( 'diff' ); decideHunk( 'rejected' ); } else { placeholder(); } },
            accept_field: function () { if ( hunkEls().length ) { showTab( 'diff' ); decideField( 'accepted' ); } else { placeholder(); } },
            reject_field: function () { if ( hunkEls().length ) { showTab( 'diff' ); decideField( 'rejected' ); } else { placeholder(); } },
            edit_hunk: function () { if ( reviewVisible() ) { editHunk(); } },
            thread_next: function () { switchThread( 1 ); },
            thread_previous: function () { switchThread( -1 ); },
            new_thread: function () { newThread( 0 ); },
            new_thread_here: function () { newThread( parseInt( root.getAttribute( 'data-node' ), 10 ) || 0 ); },
            mode: function () { if ( app.current && canWrite() ) { save( { run_mode: app.current.run_mode === 'plan' ? 'write' : 'plan' } ); } },
            model: function () { el.model.focus(); },
            lane: function () { el.lane.focus(); },
            scope: openScope,
            tab_plan: function () { showTab( 'plan' ); },
            tab_diff: function () { showTab( 'diff' ); },
            tab_cost: function () { showTab( 'cost' ); },
            pin: function () { shelve( 'pinned' ); },
            settle: function () { shelve( 'settled' ); },
            archive: function () { shelve( 'archived' ); },
            toggle_threads: function ( b ) { toggleDrawer( 'exp-ai-v-threads-open', b ); },
            toggle_side: function ( b ) { toggleDrawer( 'exp-ai-v-side-open', b ); }
        };

        document.addEventListener( 'keydown', function ( e ) {
            if ( e.defaultPrevented || ( window.ExpAiPalette && window.ExpAiPalette.isOpen() ) || ( el.dialog && el.dialog.open ) || ( restoreDialog && restoreDialog.open ) ) { return; }
            // a keystroke of an input method composing a character is never a shortcut
            if ( e.isComposing || e.keyCode === 229 ) { return; }
            var ids = Object.keys( app.bindings );
            for ( var i = 0; i < ids.length; i++ ) {
                var id = ids[i], b = app.bindings[id];
                if ( !actions[id] || !Keys.allowed( e, b.when, b.keys, root ) ) { continue; }
                // a keystroke with Cmd/Ctrl held (the palette's, when it was not handled, as in a field) never runs
                // a single-key shortcut such as j or k
                if ( ( e.ctrlKey || e.metaKey ) && !/(^|\s)(Mod|Ctrl|Meta)\+/.test( b.keys ) ) { continue; }
                if ( Keys.matches( e, b.keys ) ) {
                    e.preventDefault();
                    if ( id === 'stop' ) { escAt = 0; }
                    actions[id]();
                    return;
                }
                if ( id === 'stop' && Keys.half( b.keys ) && app.busy && Date.now() - escAt > 700 ) {
                    escAt = Date.now();
                    toast( T( 'stop_again' ) );
                }
            }
        } );

        /* --------------------------------------------------------------- wiring */

        root.addEventListener( 'click', function ( e ) {
            var cmd = e.target.closest( '[data-command]' );
            if ( cmd && actions[cmd.getAttribute( 'data-command' )] && !cmd.disabled ) {
                e.preventDefault();
                actions[cmd.getAttribute( 'data-command' )]( cmd );
                return;
            }
            var row = e.target.closest( '.exp-ai-v-row' );
            if ( row ) { open( row.getAttribute( 'data-uuid' ) ); return; }
            var mode = e.target.closest( '.exp-ai-v-seg [data-mode]' );
            if ( mode && !mode.disabled && app.current && mode.getAttribute( 'data-mode' ) !== app.current.run_mode ) { save( { run_mode: mode.getAttribute( 'data-mode' ) } ); return; }
            var tab = e.target.closest( '.exp-ai-v-tabs [role="tab"]' );
            if ( tab ) { showTab( tab.getAttribute( 'data-tab' ) ); return; }
            var cp = e.target.closest( '[data-restore-cp]' );
            if ( cp && app.current ) { previewRestore( parseInt( cp.getAttribute( 'data-restore-cp' ), 10 ) ); return; }
            var drop = e.target.closest( '[data-drop]' );
            if ( drop && app.current ) {
                api( 'followup', { thread: app.current.uuid, op: 'drop', id: drop.getAttribute( 'data-drop' ) } ).then( function ( data ) {
                    app.followups = data.followups || [];
                    renderFollowups();
                } );
                return;
            }
            var shelfLink = e.target.closest( '.exp-ai-v-shelves a' );
            if ( shelfLink ) {
                e.preventDefault();
                app.shelf = shelfLink.getAttribute( 'data-shelf' ) || '';
                window.history.pushState( {}, '', shelfLink.href );
                loadList();
            }
        } );
        qa( '.exp-ai-v-tabs [role="tab"]' ).forEach( function ( tab, i, all ) {
            tab.addEventListener( 'keydown', function ( e ) {
                if ( e.key === 'ArrowRight' || e.key === 'ArrowLeft' ) {
                    e.preventDefault();
                    var next = all[( i + ( e.key === 'ArrowRight' ? 1 : -1 ) + all.length ) % all.length];
                    showTab( next.getAttribute( 'data-tab' ) );
                    next.focus();
                }
            } );
        } );
        el.list.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
                e.preventDefault();
                switchThread( e.key === 'ArrowDown' ? 1 : -1 );
            }
        } );
        q( '.exp-ai-v-undo' ).addEventListener( 'click', function () {
            if ( toastUndo ) { var u = toastUndo; toastUndo = null; u(); }
        } );
        q( '.exp-ai-v-composer' ).addEventListener( 'submit', function ( e ) { e.preventDefault(); submit(); } );
        el.older.addEventListener( 'click', function () {
            if ( !app.current ) { return; }
            api( 'history', { thread: app.current.uuid, before: app.oldest } ).then( function ( page ) {
                takeRecords( page );
                ( page.exchanges || [] ).slice().reverse().forEach( function ( x ) { showExchange( x, true ); } );
                el.older.hidden = !page.has_older;
            } );
        } );
        var titleTimer = null;
        el.title.addEventListener( 'input', function () {
            window.clearTimeout( titleTimer );
            titleTimer = window.setTimeout( function () { save( { title: el.title.value }, false ); }, 700 );
        } );
        el.title.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Enter' ) { e.preventDefault(); el.input.focus(); } } );
        el.model.addEventListener( 'change', function () { save( { model_alias: el.model.value } ); } );
        el.lane.addEventListener( 'change', function () { save( { lane: el.lane.value } ); } );
        el.review.addEventListener( 'change', function () { save( { review_policy: el.review.value } ); } );
        var filterTimer = null;
        el.filter.addEventListener( 'input', function () {
            window.clearTimeout( filterTimer );
            filterTimer = window.setTimeout( loadList, 250 );
        } );
        bindScopeDialog();
        bindReview();
        bindPlan();
        window.addEventListener( 'popstate', function () {
            var m = window.location.pathname.match( /\(thread\)\/([0-9a-f-]{36})/ );
            if ( m ) { open( m[1], false ); }
        } );
        document.addEventListener( 'visibilitychange', function () {
            if ( visible() ) {
                schedulePoll( 0 );
                if ( app.current && !app.stream ) { connectStream(); }
            } else {
                window.clearTimeout( app.pollTimer );
                if ( app.stream ) { app.stream.close(); app.stream = null; }
            }
        } );

        // the palette knows Vector's commands
        function label( id ) { return function () { return ( window.ExpAiPalette ? window.ExpAiPalette.config().labels[id] : '' ) || id; }; }
        [ 'new_thread', 'run', 'stop', 'mode', 'scope', 'lane', 'thread_next', 'thread_previous', 'tab_plan', 'tab_diff', 'tab_cost' ].forEach( function ( id ) {
            ExpAi.commands.register( 'vector_' + id, { label: label( id ), binding: id, run: function () { window.setTimeout( function () { actions[id](); }, 0 ); },
                available: function () { return id === 'new_thread' || !!app.current; } } );
        } );

        window.ExpAiVectorApp = {
            open: function ( uuid ) { open( uuid ); },
            setModel: function ( alias ) {
                if ( !app.current || !canWrite() ) { return false; }
                save( { model_alias: alias } );
                return true;
            },
            toast: function ( text ) { toast( text ); },
            bindingsChanged: function ( bindings ) { app.bindings = bindings; paintKeys(); }
        };

        /* --------------------------------------------------------------- start */

        Promise.all( [ api( 'bootstrap', {} ), api( 'palette', {} ).catch( function () { return null; } ) ] ).then( function ( r ) {
            app.boot = r[0];
            app.bindings = app.boot.bindings || {};
            if ( r[1] && window.ExpAiPalette ) { window.ExpAiPalette.configure( r[1] ); }
            paintKeys();
            var tab = root.getAttribute( 'data-tab' );
            try { tab = tab || window.localStorage.getItem( 'exp-ai-v-tab' ); } catch ( e ) { /* storage may be off */ }
            showTab( tab && q( '[data-pane="' + tab + '"]' ) ? tab : ( qa( '.exp-ai-v-pane' )[0] || { getAttribute: function () { return ''; } } ).getAttribute( 'data-pane' ) );
            return loadList();
        } ).then( function () {
            var uuid = root.getAttribute( 'data-thread' );
            var node = parseInt( root.getAttribute( 'data-node' ), 10 );
            if ( uuid ) {
                open( uuid, false );
            } else if ( node ) {
                // (node)/<id>: the editor's newest active thread on that node, else a new one there
                var existing = app.rows.filter( function ( r ) { return r.node_id === node && r.origin === 'vector' && r.shelf !== 'archived'; } )[0];
                if ( existing ) { open( existing.uuid ); } else { newThread( node ); }
            }
            schedulePoll( app.boot ? app.boot.poll.idle : 15000 );
        } ).catch( function ( err ) {
            el.list.innerHTML = '<p class="exp-ai-v-error" role="alert">' + esc( T( 'error' ) + ' ' + ( err.message || '' ) ) + '</p>';
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
})( window, document );
