/* Woo Stock Sync admin scripts: progress polling, load-columns helper, confirm dialogs. */
( function () {
	'use strict';

	var cfg = window.wssAdmin || {};

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function i18n( key ) {
		return ( cfg.i18n && cfg.i18n[ key ] ) || '';
	}

	function pollProgress( region ) {
		var runId = region.getAttribute( 'data-run-id' );
		var terminal = [ 'previewed', 'applied', 'rolled_back', 'cancelled', 'failed' ];
		var fill = region.querySelector( '.wss-progress-fill' );
		var processedEl = region.querySelector( '.wss-progress-processed' );
		var totalEl = region.querySelector( '.wss-progress-total' );
		var percentEl = region.querySelector( '.wss-progress-percent' );
		var timer = null;

		function tick() {
			var params = new URLSearchParams();
			params.append( 'action', 'wss_run_progress' );
			params.append( 'nonce', cfg.progressNonce || '' );
			params.append( 'run_id', runId );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: params.toString()
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( res ) {
					if ( ! res || ! res.success || ! res.data ) {
						return;
					}
					var data = res.data;
					var total = data.rows_total || 0;
					var processed = data.rows_processed || 0;
					var pct = total > 0 ? Math.min( 100, Math.round( ( processed / total ) * 100 ) ) : 0;

					if ( processedEl ) {
						processedEl.textContent = processed;
					}
					if ( totalEl ) {
						totalEl.textContent = total;
					}
					if ( percentEl ) {
						percentEl.textContent = pct + '%';
					}
					if ( fill ) {
						fill.style.width = pct + '%';
					}

					if ( terminal.indexOf( data.status ) !== -1 ) {
						if ( timer ) {
							clearInterval( timer );
						}
						window.location.reload();
					}
				} )
				.catch( function () {} );
		}

		tick();
		timer = setInterval( tick, cfg.pollInterval || 4000 );
	}

	ready( function () {
		// Confirmation dialogs for destructive-ish actions (apply, roll back, release lock).
		var confirmForms = document.querySelectorAll( 'form[data-wss-confirm]' );
		Array.prototype.forEach.call( confirmForms, function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				var message = form.getAttribute( 'data-wss-confirm' );
				if ( message && ! window.confirm( message ) ) {
					event.preventDefault();
					return;
				}
				var button = form.querySelector( 'button[type="submit"], input[type="submit"]' );
				if ( button ) {
					button.setAttribute( 'aria-disabled', 'true' );
					button.classList.add( 'disabled' );
				}
			} );
		} );

		// Live progress polling on the run detail screen.
		var progress = document.querySelector( '.wss-progress[data-run-id]' );
		if ( progress ) {
			pollProgress( progress );
		}

		var btn = document.getElementById( 'wss-load-columns' );
		if ( ! btn ) {
			return;
		}

		var spinner = document.querySelector( '.wss-load-spinner' );
		var status = document.querySelector( '.wss-load-status' );

		function setBusy( busy, message ) {
			if ( spinner ) {
				spinner.classList.toggle( 'is-active', !! busy );
			}
			if ( status ) {
				status.textContent = message || '';
			}
			btn.disabled = !! busy;
		}

		function populate( columns ) {
			var selects = document.querySelectorAll( '.wss-map-select' );
			Array.prototype.forEach.call( selects, function ( sel ) {
				var current = sel.value;
				while ( sel.options.length > 1 ) {
					sel.remove( 1 );
				}
				columns.forEach( function ( col ) {
					var opt = document.createElement( 'option' );
					opt.value = col;
					opt.textContent = col;
					if ( col === current ) {
						opt.selected = true;
					}
					sel.appendChild( opt );
				} );
			} );
		}

		btn.addEventListener( 'click', function () {
			var form = btn.closest( 'form' );
			if ( ! form ) {
				return;
			}

			setBusy( true, i18n( 'loading' ) );

			var params = new URLSearchParams();
			params.append( 'action', 'wss_feed_columns' );
			params.append( 'nonce', cfg.columnsNonce || '' );

			var source = form.querySelector( 'input[name="source_type"]:checked' );
			params.append( 'source_type', source ? source.value : 'upload' );

			[ 'feed_url', 'auth_header_name', 'auth_header_value' ].forEach( function ( name ) {
				var field = form.querySelector( '[name="' + name + '"]' );
				if ( field ) {
					params.append( name, field.value );
				}
			} );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: params.toString()
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( res ) {
					if ( res && res.success && res.data && res.data.columns ) {
						populate( res.data.columns );
						setBusy( false, i18n( 'loaded' ) );
					} else {
						var message = ( res && res.data && res.data.message ) || i18n( 'error' );
						setBusy( false, message );
					}
				} )
				.catch( function () {
					setBusy( false, i18n( 'error' ) );
				} );
		} );
	} );
}() );
