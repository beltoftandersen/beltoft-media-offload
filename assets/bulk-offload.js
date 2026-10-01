( function () {
	'use strict';

	var startBtn = document.getElementById( 'bmo-bulk-start' );
	var progress = document.getElementById( 'bmo-bulk-progress' );
	var errorList = document.getElementById( 'bmo-bulk-errors' );
	if ( ! startBtn ) {
		return;
	}

	// A request that dies (timeout, network drop, PHP fatal) is retried;
	// the server skips whatever attachment it was stuck on, so retries make
	// progress. Only this many failures in a row end the run.
	var MAX_CONSECUTIVE_FAILURES = 3;

	// Attachment IDs that failed during this page session; sent with each
	// request so the server skips them and later attachments get reached.
	var failedIds = [];
	var consecutiveFailures = 0;

	function mergeFailed( ids ) {
		( ids || [] ).forEach( function ( id ) {
			id = parseInt( id, 10 );
			if ( id && failedIds.indexOf( id ) === -1 ) {
				failedIds.push( id );
			}
		} );
	}

	function showErrors( errors ) {
		( errors || [] ).forEach( function ( message ) {
			var li = document.createElement( 'li' );
			li.textContent = message;
			errorList.appendChild( li );
		} );
	}

	function finish( message ) {
		progress.textContent = message;
		startBtn.disabled = false;
	}

	function requestFailed( reason ) {
		consecutiveFailures++;
		if ( consecutiveFailures >= MAX_CONSECUTIVE_FAILURES ) {
			finish( 'Stopped after ' + consecutiveFailures + ' failed requests in a row (' + reason + '). Click to resume.' );
			return;
		}
		progress.textContent = reason + ' Retrying…';
		window.setTimeout( runBatch, 2000 );
	}

	function handleResponse( data ) {
		if ( ! data.success ) {
			finish( 'Error: ' + ( data.data && data.data.message ? data.data.message : 'unknown' ) );
			return;
		}
		consecutiveFailures = 0;

		// Another batch (an earlier request still running on the server, or
		// another tab) holds the lock: wait for it instead of competing.
		if ( data.data.busy ) {
			progress.textContent = 'Waiting for a previous batch to finish… Remaining: ' + data.data.remaining;
			window.setTimeout( runBatch, 5000 );
			return;
		}

		var remaining = data.data.remaining;
		mergeFailed( data.data.failed_ids );
		showErrors( data.data.errors );
		var skipped = failedIds.length ? ' (skipped ' + failedIds.length + ' failed: ' + failedIds.join( ', ' ) + ')' : '';
		progress.textContent = 'Remaining: ' + remaining + skipped;

		// Known failures are excluded from later batches, so every batch
		// either makes progress or grows the exclude list. Only an empty
		// batch (nothing left to try) ends the run.
		if ( data.data.processed === 0 ) {
			if ( remaining > 0 ) {
				finish( 'Stopped: no progress possible, all remaining attachments failed. Remaining: ' + remaining + skipped );
			} else {
				finish( 'Done. Remaining: ' + remaining + skipped );
			}
			return;
		}
		if ( remaining > 0 ) {
			runBatch();
		} else {
			finish( 'Done. Remaining: ' + remaining + skipped );
		}
	}

	function runBatch() {
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', bmoBulk.ajaxUrl, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.onload = function () {
			if ( xhr.status === 403 ) {
				finish( 'Request refused (HTTP 403). Reload the page and try again.' );
				return;
			}
			if ( xhr.status !== 200 ) {
				requestFailed( 'Request failed (HTTP ' + xhr.status + ').' );
				return;
			}
			var data;
			try {
				data = JSON.parse( xhr.responseText );
			} catch ( e ) {
				requestFailed( 'Unexpected server response.' );
				return;
			}
			handleResponse( data );
		};
		xhr.onerror = function () {
			requestFailed( 'Network error.' );
		};
		xhr.send(
			'action=bmo_bulk_batch&nonce=' + encodeURIComponent( bmoBulk.nonce ) +
			'&exclude=' + encodeURIComponent( failedIds.join( ',' ) )
		);
	}

	startBtn.addEventListener( 'click', function () {
		startBtn.disabled = true;
		consecutiveFailures = 0;
		progress.textContent = 'Starting…';
		runBatch();
	} );
} )();
