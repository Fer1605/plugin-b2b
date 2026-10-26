<?php
/**
 * SQL transaction helper, scoped exclusively to the plugin's own
 * `b2bpl_*` tables.
 *
 * This must never be used to wrap WooCommerce/HPOS order tables: those
 * manage their own integrity and are written to exclusively through
 * `WC_Order_Item` CRUD APIs, never inside a transaction started
 * by this class.
 *
 * @package B2BPriceLists\Database
 */

namespace B2BPriceLists\Database;

use Exception;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Transaction
 *
 * Nests correctly: MySQL implicitly commits an outer
 * transaction the moment a second `START TRANSACTION` runs inside it, so a
 * webhook item processed with `Transaction::run()` while the batch itself
 * (or a later phase's `list.delete` cascading into per-rule writes) is
 * already inside one would otherwise silently commit everything written so
 * far -- exactly the "partial data on a mid-batch failure" bug this class
 * exists to prevent. The outermost call still uses a real
 * `START TRANSACTION`/`COMMIT`/`ROLLBACK`; every call nested inside it uses
 * a named `SAVEPOINT` instead, released on success or rolled back to on
 * failure, leaving the outer transaction itself untouched and able to
 * continue (e.g. with the batch's next item).
 */
class Transaction {

	/**
	 * Current nesting depth: `0` outside of any `run()` call, `1` inside the
	 * outermost one, `2` inside a call nested one level deeper, and so on.
	 * A plain static counter is enough because, exactly like `$wpdb` itself,
	 * there is only ever one MySQL connection/transaction per request.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Current nesting depth, exposed for tests and diagnostics.
	 *
	 * @return int
	 */
	public static function depth() {
		return self::$depth;
	}

	/**
	 * The `SAVEPOINT` name used for a given nesting depth. Deterministic and
	 * prefixed with the plugin's own namespace so it can never collide with
	 * a savepoint some other plugin's code might have created on the same
	 * connection.
	 *
	 * @param int $depth Nesting depth (as returned by `depth()` *before*
	 *                    entering this level).
	 * @return string
	 */
	private static function savepoint_name( $depth ) {
		return 'b2bpl_sp_' . (int) $depth;
	}

	/**
	 * Runs a callback inside a SQL transaction (or, when already inside
	 * one, a `SAVEPOINT`) over the `b2bpl_*` tables, committing/releasing on
	 * success and rolling back on any exception.
	 *
	 * @param callable $callback function(\wpdb $wpdb): mixed. Should throw
	 *                           on failure (e.g. \RuntimeException) rather
	 *                           than returning false, so this wrapper can
	 *                           roll back reliably.
	 * @return mixed The callback's return value.
	 * @throws Throwable Re-throws whatever the callback threw, after a
	 *                   rollback (or rollback-to-savepoint) was attempted.
	 */
	public static function run( callable $callback ) {
		global $wpdb;

		$depth = self::$depth;
		++self::$depth;

		try {
			if ( 0 === $depth ) {
				$wpdb->query( 'START TRANSACTION' );
			} else {
				$wpdb->query( 'SAVEPOINT ' . self::savepoint_name( $depth ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- `savepoint_name()` builds a fixed, hard-coded prefix plus an internally-tracked integer depth, never request input; `SAVEPOINT`/`ROLLBACK TO`/`RELEASE SAVEPOINT` identifiers are not values MySQL accepts as `?`/`%d` placeholders anyway.
			}

			$result = $callback( $wpdb );

			if ( 0 === $depth ) {
				$wpdb->query( 'COMMIT' );
			} else {
				$wpdb->query( 'RELEASE SAVEPOINT ' . self::savepoint_name( $depth ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see the SAVEPOINT call above.
			}

			return $result;
		} catch ( Throwable $exception ) {
			if ( 0 === $depth ) {
				$wpdb->query( 'ROLLBACK' );
			} else {
				$wpdb->query( 'ROLLBACK TO SAVEPOINT ' . self::savepoint_name( $depth ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see the SAVEPOINT call above.
			}

			throw $exception;
		} finally {
			// Always restored to the depth this call started at, whether it
			// returned normally or the exception above was re-thrown, so a
			// caller that catches that exception and keeps going (exactly
			// what the webhook batch processor does between items) never
			// leaves the counter permanently off by one.
			self::$depth = $depth;
		}
	}
}
