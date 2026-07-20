/**
 * Links a visitor's anonymous Ortto tracking-code session to the contact
 * identified when they submit a Gravity Form.
 *
 * Ortto only merges an anonymous browsing session into an identified
 * contact when both sides agree on the value of a custom field configured
 * in Ortto as an allowed tracking-code merge key. This script:
 *
 *   1. Reads (or mints) a stable per-browser id, persisted in a first-party
 *      cookie so it survives across page views.
 *   2. Writes that id into any Hidden Gravity Forms field whose Default
 *      Value was set (in the form editor) to the documented sentinel
 *      string -- see the plugin README for the setup recipe. That field
 *      then just needs mapping to the configured Ortto field id in the
 *      form's Ortto feed; the value flows through unchanged from there.
 *   3. Tags the current Ortto tracking session with the same id via
 *      ap3c.track(), so Ortto has something to match the later server-side
 *      submission against.
 *
 * Enqueued only when "Enable Web Session Linking" is on (see
 * Alpha_Ortto_AddOn::maybe_enqueue_web_session_script()).
 */
( function () {
	'use strict';

	var config = window.AlphaOrttoWebSession || {};

	var FIELD_ID    = config.field || 'str:cm:web-session';
	var SENTINEL    = config.sentinel || 'ortto-web-session';
	var COOKIE_NAME = 'alpha_ortto_wsid';
	var COOKIE_DAYS = 395;

	function getCookie( name ) {
		var escaped = name.replace( /([.$?*|{}()\[\]\\\/+^])/g, '\\$1' );
		var match   = document.cookie.match( new RegExp( '(?:^|; )' + escaped + '=([^;]*)' ) );
		return match ? decodeURIComponent( match[ 1 ] ) : null;
	}

	function setCookie( name, value, days ) {
		var date = new Date();
		date.setTime( date.getTime() + days * 24 * 60 * 60 * 1000 );
		document.cookie = name + '=' + encodeURIComponent( value ) + '; expires=' + date.toUTCString() + '; path=/; SameSite=Lax';
	}

	function uuid() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
			var r = ( Math.random() * 16 ) | 0;
			var v = c === 'x' ? r : ( r & 0x3 ) | 0x8;
			return v.toString( 16 );
		} );
	}

	var sessionId = getCookie( COOKIE_NAME );
	if ( ! sessionId ) {
		sessionId = uuid();
		setCookie( COOKIE_NAME, sessionId, COOKIE_DAYS );
	}

	function fillHiddenFields() {
		var inputs = document.querySelectorAll( 'input[type="hidden"].gform_hidden' );
		for ( var i = 0; i < inputs.length; i++ ) {
			if ( inputs[ i ].value === SENTINEL ) {
				inputs[ i ].value = sessionId;
			}
		}
	}

	fillHiddenFields();

	// Gravity Forms AJAX-reloads the form on validation errors and paged
	// forms; re-fill on the jQuery event it fires after each re-render.
	if ( window.jQuery ) {
		window.jQuery( document ).on( 'gform_post_render', fillHiddenFields );
	}

	function tagOrttoSession() {
		if ( window.ap3c && typeof window.ap3c.track === 'function' ) {
			window.ap3c.track( { ac: [ { fi: FIELD_ID, v: sessionId } ] } );
			return true;
		}
		return false;
	}

	// The tracking code may still be loading when this script runs; retry
	// briefly rather than requiring a strict load order.
	if ( ! tagOrttoSession() ) {
		var attempts = 0;
		var interval = setInterval( function () {
			attempts++;
			if ( tagOrttoSession() || attempts > 20 ) {
				clearInterval( interval );
			}
		}, 250 );
	}
} )();
