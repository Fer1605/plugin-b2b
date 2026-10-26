/**
 * B2B Price Lists -- admin UI interaction only.
 *
 * This script never calculates or displays a final price: it only helps
 * the administrator pick a product/variations and build the price range
 * rows that get validated and stored by the server. All
 * network calls go through the nonce-protected, capability-gated AJAX
 * endpoints already registered server-side.
 *
 * @package B2BPriceLists
 */

/* global B2BPriceListsAdmin */

var B2BPriceLists = window.B2BPriceLists || {};

( function ( ns ) {
	'use strict';

	var config = window.B2BPriceListsAdmin || {};

	/**
	 * Small debounce helper.
	 *
	 * @param {Function} fn    Function to debounce.
	 * @param {number}   delay Delay in milliseconds.
	 * @return {Function} Debounced function.
	 */
	function debounce( fn, delay ) {
		var timer = null;

		return function () {
			var args = arguments;
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				fn.apply( null, args );
			}, delay );
		};
	}

	/**
	 * Performs an AJAX GET against admin-ajax.php with the shared nonce.
	 *
	 * @param {string}   action Registered AJAX action name.
	 * @param {Object}   params Extra query params.
	 * @param {Function} onSuccess Called with the response `data` on success.
	 * @param {Function} onError   Called on any failure.
	 */
	function ajaxGet( action, params, onSuccess, onError ) {
		if ( ! config.ajaxUrl ) {
			return;
		}

		var query = [ 'action=' + encodeURIComponent( action ), 'nonce=' + encodeURIComponent( config.nonce || '' ) ];

		Object.keys( params || {} ).forEach( function ( key ) {
			query.push( encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] ) );
		} );

		window
			.fetch( config.ajaxUrl + '?' + query.join( '&' ), { credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( json && json.success ) {
					onSuccess( json.data );
				} else if ( onError ) {
					onError( json && json.data ? json.data.message : '' );
				}
			} )
			.catch( function () {
				if ( onError ) {
					onError( '' );
				}
			} );
	}

	/**
	 * Wires the product search box -- used both to pick a product when
	 * creating a rule and to change the product of an existing one (Fase
	 * 14, point 5). Picking a new product always clears any previously
	 * loaded variation checklist, since it belonged to the old product.
	 */
	function initProductSearch() {
		var input       = document.getElementById( 'b2bpl-product-search' );
		var resultsBox  = document.getElementById( 'b2bpl-product-results' );
		var hiddenId    = document.getElementById( 'b2bpl-product-id' );
		var hiddenType  = document.getElementById( 'b2bpl-product-type' );
		var selectedTxt = document.getElementById( 'b2bpl-product-selected' );

		if ( ! input || ! resultsBox || ! hiddenId ) {
			return;
		}

		var search = debounce( function () {
			var term = input.value.trim();

			if ( term.length < 1 ) {
				resultsBox.hidden = true;
				resultsBox.innerHTML = '';
				return;
			}

			ajaxGet( 'b2bpl_search_products', { term: term, page: 1, per_page: 20 }, function ( data ) {
				renderResults( data.items || [] );
			} );
		}, 300 );

		function renderResults( items ) {
			resultsBox.innerHTML = '';

			if ( ! items.length ) {
				var empty = document.createElement( 'div' );
				empty.className = 'b2bpl-search-empty';
				empty.textContent = ( config.i18n && config.i18n.noResults ) || 'No results.';
				resultsBox.appendChild( empty );
				resultsBox.hidden = false;
				return;
			}

			items.forEach( function ( item ) {
				var row = document.createElement( 'div' );
				row.className = 'b2bpl-search-result';
				row.tabIndex = 0;

				var name = document.createElement( 'span' );
				name.className = 'b2bpl-search-result__name';
				name.textContent = item.name || item.text;
				row.appendChild( name );

				var meta = document.createElement( 'span' );
				meta.className = 'b2bpl-search-result__meta';

				var id = document.createElement( 'span' );
				id.className = 'b2bpl-search-result__id';
				id.textContent = '#' + item.id;
				meta.appendChild( id );

				// The SKU is what warehouse/purchasing staff actually search
				// by, so it gets its own visible chip -- and nothing at all
				// is rendered when the product has no SKU, rather than an
				// empty "SKU:" label.
				if ( item.sku ) {
					var sku = document.createElement( 'span' );
					sku.className = 'b2bpl-search-result__sku';
					sku.textContent = ( ( config.i18n && config.i18n.skuLabel ) || 'SKU' ) + ': ' + item.sku;
					meta.appendChild( sku );
				}

				row.appendChild( meta );

				row.addEventListener( 'click', function () {
					selectProduct( item );
				} );
				row.addEventListener( 'keydown', function ( event ) {
					if ( 'Enter' === event.key || ' ' === event.key ) {
						event.preventDefault();
						selectProduct( item );
					}
				} );

				resultsBox.appendChild( row );
			} );

			resultsBox.hidden = false;
		}

		function selectProduct( item ) {
			hiddenId.value = item.id;
			hiddenType.value = item.type;
			input.value = item.text;
			resultsBox.hidden = true;
			resultsBox.innerHTML = '';

			if ( selectedTxt ) {
				selectedTxt.textContent = ( ( config.i18n && config.i18n.currentlySelected ) || 'Selected' ) + ': ' + item.text;
			}

			ns.updateScopeVisibility();

			// Clear any previously loaded variation list from a different
			// product, so stale checkboxes never get submitted.
			var list = document.getElementById( 'b2bpl-variations-list' );
			if ( list ) {
				list.innerHTML = '';
				list.dataset.loadedFor = '';
			}

			ns.maybeLoadVariations();
		}

		input.addEventListener( 'input', search );

		document.addEventListener( 'click', function ( event ) {
			if ( event.target !== input && ! resultsBox.contains( event.target ) ) {
				resultsBox.hidden = true;
			}
		} );
	}

	/**
	 * Shows/hides the scope options and the variation checklist row
	 * depending on the currently known product type.
	 */
	ns.updateScopeVisibility = function () {
		var typeField = document.getElementById( 'b2bpl-product-type' );
		var type      = typeField ? typeField.value : '';

		document.querySelectorAll( '.b2bpl-scope-option' ).forEach( function ( label ) {
			var scope = label.getAttribute( 'data-scope' );
			var applies =
				'' === type || // Unknown yet (edit mode with server-rendered value, or nothing picked yet): show everything, server validates anyway.
				( 'simple' === scope && 'simple' === type ) ||
				( 'simple' !== scope && 'variable' === type );

			label.style.display = applies ? '' : 'none';
		} );

		ns.updateVariationsVisibility();
	};

	/**
	 * Shows the variation checklist row only when scope=variation_group is
	 * selected.
	 */
	ns.updateVariationsVisibility = function () {
		var row      = document.getElementById( 'b2bpl-variations-row' );
		var selected = document.querySelector( 'input[name="scope"]:checked' );

		if ( ! row ) {
			return;
		}

		row.style.display = selected && 'variation_group' === selected.value ? '' : 'none';
	};

	/**
	 * Fetches and renders the variation checklist for the currently
	 * selected product, when scope=variation_group is active and the
	 * list has not already been loaded for this product (edit mode
	 * renders it server-side already).
	 */
	ns.maybeLoadVariations = function () {
		var selected = document.querySelector( 'input[name="scope"]:checked' );

		if ( ! selected || 'variation_group' !== selected.value ) {
			return;
		}

		var list = document.getElementById( 'b2bpl-variations-list' );
		var idField = document.getElementById( 'b2bpl-product-id' );

		if ( ! list || ! idField || ! idField.value ) {
			return;
		}

		if ( list.dataset.loadedFor === idField.value || list.children.length > 0 ) {
			return;
		}

		ajaxGet( 'b2bpl_search_variations', { product_id: idField.value, page: 1, per_page: 100 }, function ( data ) {
			list.innerHTML = '';
			( data.items || [] ).forEach( function ( item ) {
				var label = document.createElement( 'label' );
				label.className = 'b2bpl-variation-option';

				var checkbox = document.createElement( 'input' );
				checkbox.type = 'checkbox';
				checkbox.name = 'variation_ids[]';
				checkbox.value = item.id;

				label.appendChild( checkbox );
				label.appendChild( document.createTextNode( ' ' + item.text ) );
				list.appendChild( label );
			} );

			list.dataset.loadedFor = idField.value;
		} );
	};

	/**
	 * Wires the pricing mode radios (fixed vs. tiered) to show/hide their
	 * respective field groups.
	 */
	function initPricingModeToggle() {
		var radios   = document.querySelectorAll( 'input[name="pricing_mode"]' );
		var fixedRow = document.getElementById( 'b2bpl-fixed-row' );
		var tieredRow = document.getElementById( 'b2bpl-tiered-row' );

		if ( ! radios.length ) {
			return;
		}

		function update() {
			var selected = document.querySelector( 'input[name="pricing_mode"]:checked' );
			var mode = selected ? selected.value : 'fixed';

			if ( fixedRow ) {
				fixedRow.style.display = 'fixed' === mode ? '' : 'none';
			}
			if ( tieredRow ) {
				tieredRow.style.display = 'tiered' === mode ? '' : 'none';
			}
		}

		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', update );
		} );

		update();
	}

	/**
	 * Wires the scope radios.
	 */
	function initScopeToggle() {
		var radios = document.querySelectorAll( 'input[name="scope"]' );

		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				ns.updateVariationsVisibility();
				ns.maybeLoadVariations();
			} );
		} );

		ns.updateScopeVisibility();
	}

	/**
	 * Wires "Add range" / "Remove range" for the tiered price ranges
	 * table.
	 */
	function initRangeRows() {
		var addButton = document.getElementById( 'b2bpl-add-range' );
		var body      = document.getElementById( 'b2bpl-ranges-body' );
		var template  = document.getElementById( 'b2bpl-range-row-template' );

		if ( ! addButton || ! body || ! template ) {
			return;
		}

		addButton.addEventListener( 'click', function () {
			var clone = template.content.firstElementChild.cloneNode( true );
			body.appendChild( clone );
		} );

		body.addEventListener( 'click', function ( event ) {
			if ( event.target && event.target.classList.contains( 'b2bpl-remove-range' ) ) {
				var confirmMessage = ( config.i18n && config.i18n.confirmRemoveRange ) || 'Remove this row?';

				if ( window.confirm( confirmMessage ) ) { // eslint-disable-line no-alert
					event.target.closest( 'tr' ).remove();
				}
			}
		} );
	}

	/**
	 * Wires a confirmation prompt on "Remove user from list" buttons.
	 */
	function initRemoveUserConfirm() {
		document.querySelectorAll( '.b2bpl-confirm-remove-user' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				var confirmMessage = ( config.i18n && config.i18n.confirmRemoveUser ) || 'Remove this user?';

				if ( ! window.confirm( confirmMessage ) ) { // eslint-disable-line no-alert
					event.preventDefault();
				}
			} );
		} );
	}

	/**
	 * Shows only the explanation that belongs to the selected table
	 * position. The <select> itself carries short labels, because a browser
	 * sizes a select to its widest option and a sentence-long label made the
	 * whole settings page scroll sideways.
	 */
	function initPositionNotes() {
		var select = document.getElementById( 'b2bpl-position' );
		var notes  = document.querySelectorAll( '.b2bpl-position-note' );

		if ( ! select || ! notes.length ) {
			return;
		}

		select.addEventListener( 'change', function () {
			notes.forEach( function ( note ) {
				note.hidden = note.getAttribute( 'data-position' ) !== select.value;
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initProductSearch();
		initPricingModeToggle();
		initScopeToggle();
		initRangeRows();
		initRemoveUserConfirm();
		initPositionNotes();
	} );

	window.B2BPriceLists = ns;
} )( B2BPriceLists );
