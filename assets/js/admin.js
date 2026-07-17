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

	ready( function () {
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
