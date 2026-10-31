/**
 * "Check now": runs the scan queue batch by batch from the browser, so it
 * works even when WP-Cron is disabled or broken. No dependencies.
 */
( function () {
	'use strict';

	var cfg = window.abpcAdmin;
	var button = document.getElementById( 'abpc-check-now' );
	var box = document.getElementById( 'abpc-progress' );
	var bar = document.getElementById( 'abpc-progress-bar' );
	var text = document.getElementById( 'abpc-progress-text' );

	if ( ! cfg || ! button || ! box || ! bar || ! text ) {
		return;
	}

	var running = false;

	function sleep( ms ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, ms );
		} );
	}

	function format( template, done, total ) {
		return template.replace( '%1$d', String( done ) ).replace( '%2$d', String( total ) );
	}

	function request( action ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );

		return window
			.fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.then( function ( response ) {
				return response.json().catch( function () {
					throw new Error( cfg.i18n.failed );
				} );
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || cfg.i18n.failed );
				}
				return json.data;
			} );
	}

	function show( data ) {
		var total = Math.max( 0, data.total || 0 );
		var done = Math.min( total, data.done || 0 );

		bar.max = total || 1;
		bar.value = done;

		if ( data.busy ) {
			text.textContent = cfg.i18n.waiting;
		} else if ( total ) {
			text.textContent = format( cfg.i18n.progress, done, total );
		}
	}

	async function run( startNew ) {
		if ( running ) {
			return;
		}
		running = true;
		button.disabled = true;
		box.hidden = false;
		text.textContent = cfg.i18n.starting;

		try {
			var data = startNew ? await request( 'abpc_scan_start' ) : { complete: false };

			while ( ! data.complete ) {
				if ( data.busy ) {
					await sleep( 3000 );
				}
				data = await request( 'abpc_scan_step' );
				show( data );
			}

			text.textContent = cfg.i18n.done;
			window.location.reload();
		} catch ( error ) {
			text.textContent = error.message || cfg.i18n.failed;
			button.disabled = false;
			running = false;
		}
	}

	button.addEventListener( 'click', function () {
		run( true );
	} );

	if ( cfg.resume ) {
		run( false );
	} else if ( cfg.start ) {
		run( true );
	}
} )();
