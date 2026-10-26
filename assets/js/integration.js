/**
 * B2B Price Lists -- "Integration" screen interaction only (T044).
 *
 * The only behavior here is copying the already-rendered, already
 * server-formatted JSON text to the clipboard. It never fetches, computes
 * or validates anything: every value on screen was produced by
 * `Webhook\JsonFormatter` on the server, and `<details>` (native HTML)
 * already handles the fold/unfold interaction without any script.
 *
 * @package B2BPriceLists
 */

var B2BPriceLists = window.B2BPriceLists || {};

( function ( ns ) {
	'use strict';

	/**
	 * Wires every "Copy" button inside this plugin's own admin screens
	 * (never a bare, global selector -- always scoped under `.b2bpl-admin`).
	 */
	function initCopyButtons() {
		var buttons = document.querySelectorAll( '.b2bpl-admin [data-b2bpl-copy-target]' );

		if ( ! buttons.length || ! window.navigator || ! window.navigator.clipboard ) {
			return;
		}

		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				handleCopyClick( button );
			} );
		} );
	}

	/**
	 * Copies the text content of the button's target element, briefly
	 * swapping the button's own label to confirm it.
	 *
	 * @param {HTMLElement} button Clicked "Copy" button.
	 */
	function handleCopyClick( button ) {
		var targetId = button.getAttribute( 'data-b2bpl-copy-target' );
		var target   = targetId ? document.getElementById( targetId ) : null;

		if ( ! target ) {
			return;
		}

		var text           = target.innerText || target.textContent || '';
		var originalLabel  = button.textContent;
		var copiedLabel    = button.getAttribute( 'data-b2bpl-copied-label' );

		window.navigator.clipboard.writeText( text ).then( function () {
			if ( ! copiedLabel ) {
				return;
			}

			button.textContent = copiedLabel;

			window.setTimeout( function () {
				button.textContent = originalLabel;
			}, 2000 );
		} );
	}

	/**
	 * Media library picker for the notification email logo (Settings tab).
	 */
	function initEmailLogo() {
		var box = document.querySelector( '.b2bpl-admin [data-b2bpl-email-logo]' );

		if ( ! box || ! window.wp || ! window.wp.media ) {
			return;
		}

		var idInput = box.querySelector( '[data-b2bpl-email-logo-id]' );
		var preview = box.querySelector( '[data-b2bpl-email-logo-preview]' );
		var choose  = box.querySelector( '[data-b2bpl-email-logo-choose]' );
		var remove  = box.querySelector( '[data-b2bpl-email-logo-remove]' );
		var frame   = null;

		choose.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			if ( ! frame ) {
				frame = window.wp.media( {
					title: choose.getAttribute( 'data-b2bpl-title' ),
					button: { text: choose.getAttribute( 'data-b2bpl-button' ) },
					library: { type: 'image' },
					multiple: false,
				} );

				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();

					idInput.value  = attachment.id;
					preview.src    = attachment.url;
					preview.hidden = false;
				} );
			}

			frame.open();
		} );

		remove.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			idInput.value = '0';
			preview.removeAttribute( 'src' );
			preview.hidden = true;
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initCopyButtons();
		initEmailLogo();
	} );

	ns.Integration = {
		initCopyButtons: initCopyButtons,
		initEmailLogo: initEmailLogo,
	};

	window.B2BPriceLists = ns;
} )( B2BPriceLists );
