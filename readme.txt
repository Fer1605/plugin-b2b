=== B2B Price Lists ===
Contributors: fer1605
Tags: woocommerce, b2b, wholesale, pricing
Requires at least: 7.0.2
Tested up to: 7.1
Requires PHP: 8.0.30
WC requires at least: 10.9.4
WC tested up to: 11.1.0
Stable tag: 3.7.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage centralized B2B price lists for WooCommerce and assign them directly to individual users.

== Description ==

B2B Price Lists lets a store administrator create centralized price lists and assign each one directly to an individual user, independent of WordPress roles. A user without an assigned list keeps the standard WooCommerce (B2C) experience untouched.

The plugin supports simple and variable products, general rules applied to all variations of a product, and rules scoped to specific variations. All B2B prices are resolved and enforced on the server, across catalog, single product, cart and checkout.

Main features:

* Price lists with per-product, per-variation or all-variations rules, quantity tiers, minimum quantities and percentage offers.
* B2B prices enforced on the server in catalog, product page, cart, checkout and the Store API / Cart Block.
* B2B orders identified and filterable in WooCommerce -> Orders (HPOS compatible), with an audit snapshot on every B2B order line.
* Activity history with configurable retention and CSV export.
* Optional private webhook (disabled by default) for an external system (ERP) to manage lists, prices, customers and products.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the ZIP through the WordPress admin.
2. Activate the plugin through the "Plugins" screen.
3. WooCommerce must be installed and active for the plugin to fully initialize; if it is not, or if it does not meet the minimum required version, an admin notice explains why.

== Frequently Asked Questions ==

= Does this plugin work without WooCommerce? =

The plugin activates safely without WooCommerce active, but it will only display an admin notice and will not initialize further functionality until WooCommerce is installed, active, and meets the minimum required version.

= Does this plugin use user roles to determine prices? =

No. Price lists are assigned directly to individual users, never inferred from WordPress roles.

== Changelog ==

= 3.7.0 =
* Current release.
