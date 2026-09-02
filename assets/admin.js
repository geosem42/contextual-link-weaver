( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var btn         = document.getElementById( 'interpost-index-all-btn' );
		var statusText  = document.getElementById( 'interpost-index-status-text' );
		var progressBar = document.getElementById( 'interpost-progress-bar' );
		var progressWrap = document.getElementById( 'interpost-progress-bar-container' );
		var indexedEl   = document.getElementById( 'interpost-indexed-count' );
		var totalEl     = document.getElementById( 'interpost-total-count' );
		var i18n        = interpostAdmin.i18n || {};

		if ( ! btn ) {
			return;
		}

		// Posts that could not be embedded this run. They are sent back with
		// every batch so the server stops handing us the same ones forever.
		var failed = [];

		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			failed = [];
			progressWrap.style.display = 'block';
			statusText.textContent = i18n.starting;
			processBatch();
		} );

		function processBatch() {
			var formData = new FormData();
			formData.append( 'action', 'interpost_bulk_index' );
			formData.append( 'nonce', interpostAdmin.nonce );

			for ( var i = 0; i < failed.length; i++ ) {
				formData.append( 'failed[]', failed[ i ] );
			}

			fetch( interpostAdmin.ajax_url, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( result ) {
					if ( ! result.success ) {
						statusText.textContent = i18n.error + ' ' + ( result.data || i18n.unknownError );
						btn.disabled = false;
						return;
					}

					var data    = result.data;
					var indexed = data.indexed;
					var total   = data.total;
					var pct     = total > 0 ? Math.round( ( indexed / total ) * 100 ) : 0;

					indexedEl.textContent = indexed;
					totalEl.textContent   = total;
					progressBar.style.width = pct + '%';
					statusText.textContent = i18n.progress
						.replace( '%1$s', indexed )
						.replace( '%2$s', total )
						.replace( '%3$s', pct );

					if ( Array.isArray( data.failed ) ) {
						failed = data.failed;
					}

					if ( data.errors && data.errors.length > 0 ) {
						statusText.textContent += '. ' + i18n.batchErrors.replace( '%s', data.errors.length );
					}

					// Nothing left that this run can index.
					if ( ! data.attempted ) {
						statusText.textContent = failed.length > 0
							? i18n.finishedWithErrors.replace( '%s', failed.length )
							: i18n.complete;
						btn.disabled = false;
						return;
					}

					// The batch tried and got nowhere, so trying again would
					// just repeat it. Stop and say so.
					if ( ! data.processed ) {
						statusText.textContent = i18n.stalled.replace( '%s', failed.length );
						btn.disabled = false;
						return;
					}

					processBatch();
				} )
				.catch( function ( err ) {
					statusText.textContent = i18n.networkError + ' ' + err.message;
					btn.disabled = false;
				} );
		}
	} );
} )();
