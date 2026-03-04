( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var btn         = document.getElementById( 'clw-index-all-btn' );
		var statusText  = document.getElementById( 'clw-index-status-text' );
		var progressBar = document.getElementById( 'clw-progress-bar' );
		var progressWrap = document.getElementById( 'clw-progress-bar-container' );
		var indexedEl   = document.getElementById( 'clw-indexed-count' );
		var totalEl     = document.getElementById( 'clw-total-count' );

		if ( ! btn ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			progressWrap.style.display = 'block';
			statusText.textContent = 'Starting...';
			processBatch();
		} );

		function processBatch() {
			var formData = new FormData();
			formData.append( 'action', 'clw_bulk_index' );
			formData.append( 'nonce', clwAdmin.nonce );

			fetch( clwAdmin.ajax_url, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( result ) {
					if ( ! result.success ) {
						statusText.textContent = 'Error: ' + ( result.data || 'Unknown error' );
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
					statusText.textContent  = 'Indexed ' + indexed + ' of ' + total + ' (' + pct + '%)';

					if ( data.errors && data.errors.length > 0 ) {
						statusText.textContent += ' — ' + data.errors.length + ' error(s) in this batch';
					}

					if ( data.remaining > 0 ) {
						processBatch();
					} else {
						statusText.textContent = 'Indexing complete!';
						btn.disabled = false;
					}
				} )
				.catch( function ( err ) {
					statusText.textContent = 'Network error: ' + err.message;
					btn.disabled = false;
				} );
		}
	} );
} )();
