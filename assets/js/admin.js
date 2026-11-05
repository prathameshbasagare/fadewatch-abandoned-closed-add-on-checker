/**
 * Report page and Plugins screen: opt-in dialog and "Check now".
 *
 * Runs the scan queue batch by batch from the browser, so checks also work
 * when WP-Cron is disabled or broken. No dependencies.
 */
( function () {
	'use strict';

	var cfg = window.fadewatchAdmin;
	var box = document.getElementById( 'fadewatch-progress' );
	var bar = document.getElementById( 'fadewatch-progress-bar' );
	var text = document.getElementById( 'fadewatch-progress-text' );
	var checkNow = document.getElementById( 'fadewatch-check-now' );
	var consentForm = document.getElementById( 'fadewatch-consent-form' );
	var dialog = document.getElementById( 'fadewatch-consent-dialog' );

	if ( ! cfg ) {
		return;
	}

	if ( dialog ) {
		Array.prototype.forEach.call( dialog.querySelectorAll( '[data-fadewatch-close]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				dialog.close();
			} );
		} );

		// "Save and scan now" stays disabled until checks are allowed. The
		// checkbox is also `required`, which covers browsers without JS.
		var checksBox = document.getElementById( 'fadewatch-consent-checks' );
		var saveButton = document.getElementById( 'fadewatch-consent-save' );
		if ( checksBox && saveButton ) {
			var syncSave = function () {
				saveButton.disabled = ! checksBox.checked;
			};
			checksBox.addEventListener( 'change', syncSave );
			syncSave();
		}

		// "Allow checks" opens the dialog. If the browser has no <dialog>
		// support, the button falls back to submitting the consent form.
		if ( typeof dialog.showModal === 'function' ) {
			Array.prototype.forEach.call( document.querySelectorAll( '[data-fadewatch-open-consent]' ), function ( button ) {
				button.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					dialog.showModal();
				} );
			} );

			// Opens by itself only once, right after activation.
			if ( cfg.autoConsent ) {
				dialog.showModal();
			}
		}
	}

	// Everything below needs the report page. On the Plugins screen the
	// consent form simply posts and lands on the report page, where the
	// first scan then runs with a progress bar.
	if ( ! box || ! bar || ! text ) {
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

	function request( action, extra ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( extra || {} ).forEach( function ( key ) {
			body.append( key, extra[ key ] );
		} );

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

	function setDisabled( disabled ) {
		if ( checkNow ) {
			checkNow.disabled = disabled;
		}
		if ( consentForm ) {
			Array.prototype.forEach.call( consentForm.querySelectorAll( 'button' ), function ( button ) {
				button.disabled = disabled;
			} );
		}
	}

	/**
	 * Runs a scan to completion.
	 *
	 * @param {string|null} firstAction AJAX action that starts the scan
	 *                                  (start or consent), or null to resume.
	 * @param {Object}      extra       Extra POST fields for the first request.
	 */
	async function run( firstAction, extra ) {
		if ( running ) {
			return;
		}
		running = true;
		setDisabled( true );
		box.hidden = false;
		text.textContent = cfg.i18n.starting;

		try {
			var data = firstAction ? await request( firstAction, extra ) : { complete: false };

			while ( ! data.complete ) {
				if ( data.busy ) {
					await sleep( 3000 );
				}
				data = await request( 'fadewatch_scan_step' );
				show( data );
			}

			text.textContent = cfg.i18n.done;
			window.location.reload();
		} catch ( error ) {
			text.textContent = error.message || cfg.i18n.failed;
			setDisabled( false );
			running = false;
		}
	}

	if ( checkNow ) {
		checkNow.addEventListener( 'click', function () {
			run( 'fadewatch_scan_start' );
		} );
	}

	// Opt-in. Without JavaScript the form posts to admin-post.php instead.
	if ( consentForm ) {
		consentForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( dialog && dialog.open ) {
				dialog.close();
			}
			var checks = document.getElementById( 'fadewatch-consent-checks' );
			var column = document.getElementById( 'fadewatch-consent-column' );
			run( 'fadewatch_grant_consent', {
				fadewatch_allow_checks: checks && checks.checked ? '1' : '',
				fadewatch_show_column: column && column.checked ? '1' : '',
			} );
		} );
	}

	if ( cfg.askConsent ) {
		return;
	}

	if ( cfg.resume ) {
		run( null );
	} else if ( cfg.start ) {
		run( 'fadewatch_scan_start' );
	}
} )();
