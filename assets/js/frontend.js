/**
 * B2B Price Lists -- storefront presentation only.
 *
 * This script never calculates or displays a price by itself using its own
 * business rules: the price/quantity data it reads
 * (`variation.b2bpl_minimum_quantity`, `variation.b2bpl_price_table_html`,
 * `variation.b2bpl_pricing`, `B2BPriceListsFrontend.simpleProduct`) is
 * already fully resolved on the server -- for a variable product, delivered
 * through WooCommerce's own official variation AJAX payload
 * (`woocommerce_available_variation` filter); for a simple product,
 * localized once at page load (`Frontend\Assets::build_frontend_data()`).
 * This script only mirrors the server's own tier-selection arithmetic
 * (which range covers a given quantity -- never a price it invents) to
 * update the DOM live as the buyer changes quantity or variation, without
 * any further request: the cart total remains authoritative on the server
 * via `woocommerce_before_calculate_totals` regardless of what this script
 * shows.
 *
 * Page-builder note: a page-builder single-product template (e.g. an
 * Elementor "Price" widget) commonly renders the product price as an
 * independent element outside WooCommerce's classic
 * `.summary`/`.woocommerce-variation-price` structure -- often duplicated
 * per responsive breakpoint. Every price host below is therefore
 * discovered generically via this plugin's own `.b2bpl-price` marker
 * (never a global `.price`/`.qty` selector), always scoped to the exact
 * product driving the event via `.closest('.product')`.
 *
 * @package B2BPriceLists
 */

( function ( $ ) {
	'use strict';

	if ( ! $ ) {
		return;
	}

	var config = window.B2BPriceListsFrontend || {};
	var priceFormat = config.priceFormat || {
		decimals: 2,
		decimalSeparator: '.',
		thousandSeparator: ',',
		currencySymbol: '',
		currencyPos: 'left'
	};

	/**
	 * Reads the quantity input's original ("before selection") `min`
	 * attribute the first time it is seen, so `reset_data` can restore it
	 * exactly instead of guessing a hard-coded fallback.
	 *
	 * @param {jQuery} $qty Quantity input, scoped to one form.
	 * @return {string} The stored/default minimum value.
	 */
	function getDefaultMin( $qty ) {
		var stored = $qty.attr( 'data-b2bpl-default-min' );

		if ( undefined === stored ) {
			stored = $qty.attr( 'min' ) || '1';
			$qty.attr( 'data-b2bpl-default-min', stored );
		}

		return stored;
	}

	/**
	 * Formats a numeric amount the same way `wc_price()` renders it on the
	 * server (grouped thousands, fixed decimals, currency symbol positioned
	 * per the site's configured `woocommerce_currency_pos`), so a
	 * JS-recomputed price never looks visually different from the
	 * server-rendered one.
	 *
	 * @param {number} amount Amount to format.
	 * @return {string} Safe HTML (only ever built from this numeric input).
	 */
	function formatPrice( amount ) {
		var decimals = priceFormat.decimals;
		var negative = amount < 0;
		var fixed    = Math.abs( amount ).toFixed( decimals );
		var parts    = fixed.split( '.' );
		var intPart  = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, priceFormat.thousandSeparator );
		var number   = decimals > 0 ? intPart + priceFormat.decimalSeparator + parts[ 1 ] : intPart;

		if ( negative ) {
			number = '-' + number;
		}

		var symbol = priceFormat.currencySymbol;
		var amountHtml;

		switch ( priceFormat.currencyPos ) {
			case 'right':
				amountHtml = number + symbol;
				break;
			case 'left_space':
				amountHtml = symbol + '&nbsp;' + number;
				break;
			case 'right_space':
				amountHtml = number + '&nbsp;' + symbol;
				break;
			default:
				amountHtml = symbol + number;
		}

		return '<span class="woocommerce-Price-amount amount"><bdi>' + amountHtml + '</bdi></span>';
	}

	/**
	 * Mirrors the server's `PriceResolutionEngine::price_from_candidate()`
	 * tier selection exactly: clamps the effective quantity up to the
	 * rule's minimum, then finds the range that covers it. Fixed pricing
	 * never changes with quantity.
	 *
	 * Returns the whole resolved tier (final price, pre-offer price and
	 * offer percentage) rather than a bare number: the offer was already
	 * computed and rounded on the server, so this script only ever picks
	 * which tier applies -- it never calculates a discount itself.
	 *
	 * @param {Object} pricing  `b2bpl_pricing` payload.
	 * @param {number} quantity Current quantity input value.
	 * @return {Object|null} {unitPrice, basePrice, discountPercent}
	 */
	function resolveTier( pricing, quantity ) {
		if ( ! pricing ) {
			return null;
		}

		if ( 'fixed' === pricing.pricingMode ) {
			if ( null === pricing.unitPrice || undefined === pricing.unitPrice ) {
				return null;
			}

			return {
				unitPrice: pricing.unitPrice,
				basePrice: ( null === pricing.basePrice || undefined === pricing.basePrice ) ? pricing.unitPrice : pricing.basePrice,
				discountPercent: ( undefined === pricing.discountPercent ) ? null : pricing.discountPercent
			};
		}

		var effectiveQty = Math.max( quantity, pricing.minimumQuantity );
		var ranges       = pricing.ranges || [];

		for ( var i = 0; i < ranges.length; i++ ) {
			var range = ranges[ i ];

			if ( effectiveQty >= range.minQty && ( null === range.maxQty || effectiveQty <= range.maxQty ) ) {
				return {
					unitPrice: range.unitPrice,
					basePrice: ( null === range.basePrice || undefined === range.basePrice ) ? range.unitPrice : range.basePrice,
					discountPercent: ( undefined === range.discountPercent ) ? null : range.discountPercent
				};
			}
		}

		return null;
	}

	/**
	 * Renders the same "-20%" badge the server emits
	 * (`PriceDisplay::discount_badge_html()`), trimming the trailing zeros
	 * of the stored 4-decimal percentage exactly like
	 * `Discount::format_percent()` does.
	 *
	 * @param {number|null} percent Offer percentage.
	 * @return {string} Empty string when there is no offer.
	 */
	function discountBadge( percent ) {
		if ( null === percent || undefined === percent ) {
			return '';
		}

		var text = parseFloat( percent ).toFixed( 4 ).replace( /0+$/, '' ).replace( /\.$/, '' );

		return ' <span class="b2bpl-discount-badge">-' + text + '%</span>';
	}

	/**
	 * The hard purchasable-quantity ceiling implied by a tiered rule: the
	 * `maxQty` of whichever range has the highest `minQty` (the "last"
	 * tier), or `null` when that tier is open-ended -- mirrors
	 * `RuleGroupValidator::validate_ranges()`'s own invariant that only the
	 * last range (by minimum quantity) may ever be open. Fixed pricing has
	 * no ranges and therefore no derived ceiling.
	 *
	 * @param {Object} pricing `b2bpl_pricing` payload.
	 * @return {number|null}
	 */
	function resolveMaxQuantity( pricing ) {
		if ( ! pricing || 'tiered' !== pricing.pricingMode ) {
			return null;
		}

		var ranges = pricing.ranges || [];

		if ( ! ranges.length ) {
			return null;
		}

		var last = ranges[ 0 ];

		for ( var i = 1; i < ranges.length; i++ ) {
			if ( ranges[ i ].minQty > last.minQty ) {
				last = ranges[ i ];
			}
		}

		return last.maxQty;
	}

	/**
	 * Rebuilds the exact `<span class="b2bpl-price">` markup the server
	 * renders (`PriceDisplay::format_exact_result()`), for a given quantity.
	 *
	 * @param {Object} pricing  `b2bpl_pricing` payload.
	 * @param {number} quantity Current quantity input value.
	 * @return {string|null} Null when no range covers this quantity (should
	 *                        not happen for valid, already-validated data).
	 */
	function buildPriceHtml( pricing, quantity ) {
		var tier = resolveTier( pricing, quantity );

		if ( null === tier ) {
			return null;
		}

		var hasOffer = null !== tier.discountPercent && undefined !== tier.discountPercent;
		var hasRegular = null !== pricing.regularPrice && undefined !== pricing.regularPrice;

		var attributes = ' data-b2bpl-final="' + tier.unitPrice + '" data-b2bpl-min-qty="' + pricing.minimumQuantity + '"';

		if ( hasOffer ) {
			attributes += ' data-b2bpl-base="' + tier.basePrice + '"';
		}

		if ( 'stacked' === config.singleLayout ) {
			// Mirrors `Frontend\PriceMarkup::render_stacked()`: the price
			// paid and its badge on the first line; the small print -- the
			// minimum quantity and the compared price, named rather than
			// struck -- together on the second.
			var labels = config.referenceLabels || {};
			var primary = '<span class="b2bpl-price__primary"><ins class="b2bpl-price__final">' + formatPrice( tier.unitPrice ) + '</ins>'
				+ ( hasOffer ? discountBadge( tier.discountPercent ) : '' )
				+ '</span>';

			var secondary = '';

			if ( pricing.minimumQuantity > 1 && config.shortMinTemplate ) {
				secondary = '<em class="b2bpl-price__min">' + config.shortMinTemplate.replace( '{{qty}}', pricing.minimumQuantity ) + '</em>';
			}

			if ( hasOffer ) {
				secondary += ( '' === secondary ? '' : ' ' ) + '<span class="b2bpl-price__reference b2bpl-price__reference--base"><span class="b2bpl-price__reference-label">' + ( labels.base || '' ) + '</span> <span class="b2bpl-price__reference-amount">' + formatPrice( tier.basePrice ) + '</span></span>';
			} else if ( hasRegular ) {
				secondary += ( '' === secondary ? '' : ' ' ) + '<span class="b2bpl-price__reference b2bpl-price__reference--regular"><span class="b2bpl-price__reference-label">' + ( labels.regular || '' ) + '</span> <span class="b2bpl-price__reference-amount">' + formatPrice( pricing.regularPrice ) + '</span></span>';
			}

			return '<span class="b2bpl-price b2bpl-price--stacked b2bpl-price--single"' + attributes + '>' + primary + '<span class="b2bpl-price__secondary">' + secondary + '</span></span>';
		}

		// Mirrors `Frontend\PriceMarkup::render_inline()` element for element,
		// so a price rewritten here is indistinguishable from one the server
		// rendered -- same classes for a theme to style, same structure for a
		// theme to read.
		var html = '<ins class="b2bpl-price__final">' + formatPrice( tier.unitPrice ) + '</ins>';

		if ( null !== tier.discountPercent && undefined !== tier.discountPercent ) {
			// Offer in force: strike the price this tier was written at, not
			// the B2C regular price -- same rule as the server.
			html = '<del class="b2bpl-price__base" aria-hidden="true">' + formatPrice( tier.basePrice ) + '</del> ' + html + discountBadge( tier.discountPercent );
		} else if ( null !== pricing.regularPrice && undefined !== pricing.regularPrice ) {
			html = '<del class="b2bpl-price__regular" aria-hidden="true">' + formatPrice( pricing.regularPrice ) + '</del> ' + html;
		}

		if ( pricing.minimumQuantity > 1 && config.minQtyNoteTemplate ) {
			html += ' <span class="b2bpl-min-qty-note">' + config.minQtyNoteTemplate.replace( '{{qty}}', pricing.minimumQuantity ) + '</span>';
		}

		return '<span class="b2bpl-price b2bpl-price--inline b2bpl-price--single"' + attributes + '>' + html + '</span>';
	}

	/**
	 * Discovers every "current price" host for the given product (the
	 * classic `.woocommerce-variation-price` block plus any independent
	 * page-builder "Price" widget instance for the SAME product), caching
	 * each host's pristine pre-selection markup on first sight -- on the
	 * host's stable parent container (`b2bplHosts`, kept on the form via
	 * `.data()`), never on the `.b2bpl-price` span itself, since that span
	 * gets replaced on every update and would lose any data attached to it.
	 *
	 * @param {jQuery} $form `.variations_form` or `form.cart` element.
	 * @return {Array<{container: Element, originalHtml: string}>}
	 */
	function getOrDiscoverHosts( $form ) {
		var hosts = $form.data( 'b2bplHosts' );

		if ( hosts && hosts.length ) {
			return hosts;
		}

		hosts = [];

		$form.closest( '.product' ).find( '.b2bpl-price' ).each( function () {
			var container = this.parentNode;

			if ( ! container ) {
				return;
			}

			hosts.push( { container: container, originalHtml: container.innerHTML } );
		} );

		// Deliberately not cached when empty: a page builder can mount its
		// price widget markup asynchronously/lazily, after this ran the
		// first time (the exact class of bug this file exists to fix) --
		// retrying discovery on the next update/reset call, instead of
		// memoizing "nothing found" forever, is what keeps this resilient
		// to that timing instead of silently going stale for the rest of
		// the page's life.
		if ( hosts.length ) {
			$form.data( 'b2bplHosts', hosts );
		}

		return hosts;
	}

	/**
	 * Writes the same price HTML into every discovered host for this
	 * product, so a page builder's duplicated "Price" widgets (desktop/
	 * tablet/mobile, etc.) never fall out of sync with each other or with
	 * quantity/variation changes.
	 *
	 * @param {jQuery} $form Form element.
	 * @param {string} html  Markup to write (already includes the
	 *                        `.b2bpl-price` wrapper).
	 * @return {void}
	 */
	function writeToHosts( $form, html ) {
		var hosts = getOrDiscoverHosts( $form );

		for ( var i = 0; i < hosts.length; i++ ) {
			hosts[ i ].container.innerHTML = html;
		}
	}

	/**
	 * Restores every discovered host to its pristine pre-selection markup
	 * -- used both by `reset_data` (variation cleared) and when the buyer
	 * switches to a variation this rule does not cover, so no stale B2B
	 * price/note is left showing next to a now-plain-B2C variation.
	 *
	 * @param {jQuery} $form Form element.
	 * @return {void}
	 */
	function restoreHosts( $form ) {
		var hosts = $form.data( 'b2bplHosts' );

		if ( ! hosts ) {
			return;
		}

		for ( var i = 0; i < hosts.length; i++ ) {
			hosts[ i ].container.innerHTML = hosts[ i ].originalHtml;
		}
	}

	/**
	 * Re-renders every price host for the current quantity, using the
	 * pricing payload currently cached on the form (set by `found_variation`
	 * for a variable product, or `B2BPriceListsFrontend.simpleProduct` for a
	 * simple one). A no-op when the setting is off, when this
	 * product/variation has no applicable B2B rule, or when no host exists
	 * in the DOM -- never manipulates an element that shouldn't be touched.
	 *
	 * @param {jQuery} $form Form element.
	 * @return {void}
	 */
	function updatePriceForQuantity( $form ) {
		var pricing = $form.data( 'b2bplPricing' );

		if ( ! pricing ) {
			return;
		}

		var $qty     = $form.find( '.quantity .qty' );
		var quantity = $qty.length ? parseInt( $qty.val(), 10 ) : pricing.minimumQuantity;

		if ( ! quantity || isNaN( quantity ) ) {
			quantity = pricing.minimumQuantity;
		}

		var html = buildPriceHtml( pricing, quantity );

		if ( null !== html ) {
			writeToHosts( $form, html );
		}
	}

	/**
	 * Clamps the quantity input to the currently applicable minimum/maximum
	 * ( presentation layer): never lets a value below the rule's
	 * minimum or above a closed last tier's maximum survive, while leaving
	 * a value the buyer is still actively typing (e.g. "1" on the way to
	 * "12") alone until it settles -- enforcement happens on `change`
	 * (blur, stepper click, or a completed keyboard entry), never
	 * mid-keystroke on `input`, so a multi-digit quantity can still be
	 * typed normally. The server re-validates independently regardless.
	 *
	 * @param {jQuery} $qty    Quantity input.
	 * @param {Object} pricing `b2bpl_pricing` payload, or null.
	 * @return {void}
	 */
	function clampQuantity( $qty, pricing ) {
		if ( ! pricing || ! $qty.length ) {
			return;
		}

		var value = parseInt( $qty.val(), 10 );

		if ( isNaN( value ) ) {
			return;
		}

		var min = pricing.minimumQuantity;
		var max = resolveMaxQuantity( pricing );
		var clamped = value;

		if ( min && clamped < min ) {
			clamped = min;
		}

		if ( null !== max && clamped > max ) {
			clamped = max;
		}

		if ( clamped !== value ) {
			$qty.val( clamped );
		}
	}

	$( document ).on( 'found_variation', '.variations_form', function ( event, variation ) {
		var $form = $( this );
		var $qty  = $form.find( '.quantity .qty' );
		var $wrap = $form.closest( '.product' ).find( '.b2bpl-price-table-placeholder' );

		if ( $qty.length && variation && variation.b2bpl_minimum_quantity ) {
			getDefaultMin( $qty );
			$qty.attr( 'min', variation.b2bpl_minimum_quantity );

			if ( parseInt( $qty.val(), 10 ) < parseInt( variation.b2bpl_minimum_quantity, 10 ) ) {
				$qty.val( variation.b2bpl_minimum_quantity );
			}
		}

		if ( $wrap.length && variation && 'undefined' !== typeof variation.b2bpl_price_table_html ) {
			$wrap.html( variation.b2bpl_price_table_html );
		}

		var pricing = ( variation && variation.b2bpl_pricing ) ? variation.b2bpl_pricing : null;

		$form.data( 'b2bplPricing', pricing );

		if ( pricing ) {
			updatePriceForQuantity( $form );
		} else {
			// This variation has no applicable B2B rule (partial
			// `variation_group` coverage, DEC point 6): drop back to
			// whatever this product showed before any variation was
			// selected -- never leave a previous variation's B2B price
			// or minimum-quantity note showing next to a plain B2C one.
			restoreHosts( $form );
		}
	} );

	$( document ).on( 'reset_data', '.variations_form', function () {
		var $form = $( this );
		var $qty  = $form.find( '.quantity .qty' );
		var $wrap = $form.closest( '.product' ).find( '.b2bpl-price-table-placeholder' );

		if ( $qty.length ) {
			$qty.attr( 'min', getDefaultMin( $qty ) );
		}

		if ( $wrap.length ) {
			$wrap.empty();
		}

		$form.removeData( 'b2bplPricing' );
		restoreHosts( $form );
	} );

	// Variable product: quantity changes while a variation is selected ->
	// live-update every price host (if this variation has an applicable
	// rule) and clamp the quantity to the rule's min/max.
	$( document ).on( 'input change', '.variations_form .quantity .qty', function ( event ) {
		var $qty  = $( this );
		var $form = $qty.closest( '.variations_form' );

		updatePriceForQuantity( $form );

		if ( 'change' === event.type ) {
			clampQuantity( $qty, $form.data( 'b2bplPricing' ) );
			updatePriceForQuantity( $form );
		}
	} );

	// Simple product: quantity changes -> live-update every price host.
	// Always active (not covered by the inline-variation-price setting,
	// which only concerns the post-selection variable product block).
	$( document ).on( 'input change', 'form.cart:not(.variations_form) .quantity .qty', function ( event ) {
		var $qty  = $( this );
		var $form = $qty.closest( 'form.cart' );
		var pricing = config.simpleProduct && config.simpleProduct.applies ? config.simpleProduct : null;

		$form.data( 'b2bplPricing', pricing );
		updatePriceForQuantity( $form );

		if ( 'change' === event.type ) {
			clampQuantity( $qty, pricing );
			updatePriceForQuantity( $form );
		}
	} );

	// Quantity "+"/"-" stepper buttons (when the theme provides them) fire
	// a native `change` event on the input already, which the handlers
	// above pick up -- no separate binding needed.
} )( window.jQuery );
