/**
 * Beltoft Media Offload - License activation / deactivation.
 */
( function () {
	'use strict';

	var p = window.bmoLicense;
	if ( ! p ) {
		return;
	}

	var msgEl = document.getElementById( 'bmo-license-message' );

	function show( text, ok ) {
		msgEl.textContent = text;
		msgEl.style.color = ok ? '#00a32a' : '#d63638';
	}

	function post( action, extra ) {
		var body = 'action=' + encodeURIComponent( action ) + '&nonce=' + encodeURIComponent( p.nonce );
		if ( extra ) {
			body += '&' + extra;
		}
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', p.ajaxUrl, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		return { xhr: xhr, body: body };
	}

	var activateBtn = document.getElementById( 'bmo-activate-license' );
	if ( activateBtn ) {
		activateBtn.addEventListener( 'click', function () {
			var keyInput = document.getElementById( 'bmo-license-key' );
			var key = keyInput ? keyInput.value.trim() : '';
			if ( ! key ) {
				return;
			}
			activateBtn.disabled = true;
			activateBtn.textContent = p.i18n.activating;
			msgEl.textContent = '';

			var req = post( 'bmo_activate_license', 'license_key=' + encodeURIComponent( key ) );
			req.xhr.onload = function () {
				var data = null;
				try {
					data = JSON.parse( req.xhr.responseText );
				} catch ( e ) {}
				if ( data && data.success ) {
					show( data.data.message, true );
					setTimeout( function () {
						window.location.reload();
					}, 1000 );
				} else {
					show( data && data.data && data.data.message ? data.data.message : p.i18n.activationFailed, false );
					activateBtn.disabled = false;
					activateBtn.textContent = p.i18n.activate;
				}
			};
			req.xhr.onerror = function () {
				show( p.i18n.requestFailed, false );
				activateBtn.disabled = false;
				activateBtn.textContent = p.i18n.activate;
			};
			req.xhr.send( req.body );
		} );
	}

	var deactivateBtn = document.getElementById( 'bmo-deactivate-license' );
	if ( deactivateBtn ) {
		deactivateBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( p.i18n.confirmDeactivate ) ) {
				return;
			}
			deactivateBtn.disabled = true;
			deactivateBtn.textContent = p.i18n.deactivating;
			msgEl.textContent = '';

			var req = post( 'bmo_deactivate_license', null );
			req.xhr.onload = function () {
				var data = null;
				try {
					data = JSON.parse( req.xhr.responseText );
				} catch ( e ) {}
				if ( data && data.success ) {
					show( data.data.message, true );
					setTimeout( function () {
						window.location.reload();
					}, 500 );
				} else {
					show( data && data.data && data.data.message ? data.data.message : p.i18n.deactivationFailed, false );
					deactivateBtn.disabled = false;
					deactivateBtn.textContent = p.i18n.deactivate;
				}
			};
			req.xhr.onerror = function () {
				show( p.i18n.requestFailed, false );
				deactivateBtn.disabled = false;
				deactivateBtn.textContent = p.i18n.deactivate;
			};
			req.xhr.send( req.body );
		} );
	}
} )();
