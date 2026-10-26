<?php
/**
 * Holds the "who/why" of the code currently executing on behalf of a
 * webhook batch, so `Activity\ActivityLog::record` can
 * attribute an action to the webhook without every call site in
 * `Domain\ListService`/`Domain\RuleGroupService`/
 * `Domain\UserAssignmentService` having to be changed to accept and thread
 * through a `source`/`batch_id`/`actor_*` parameter explicitly.
 *
 * The full W3 flow this exists for: `Webhook\BatchProcessor` (not part of
 * this release) will call `wp_set_current_user()` with the integration user
 * running the batch and wrap each item's work in
 * `Context::run( [ 'source' => 'webhook', 'batch_id' => $batch_id ], ... )`.
 * Every `ActivityLog::record()` call made by the services while that
 * callback runs then picks up `source`/`batch_id` automatically, and every
 * call made *outside* of one (the admin panel, exactly as before 1.5.0)
 * sees an empty context and keeps today's behavior unchanged.
 *
 * A small stack, not a single value, so a future nested use (an event
 * logged with a narrower `actor_user_id` while an outer webhook context is
 * still active, for instance) can override just what it needs to and
 * `pop()` back to the previous context on the way out -- never leaking a
 * context past the code that pushed it, even if that code throws.
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Context
 */
class Context {

	/**
	 * Active contexts, innermost last. Empty outside of any `run()` call.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static $stack = array();

	/**
	 * The currently active context (the innermost `run()`/`push()`), or an
	 * empty array when none is active.
	 *
	 * @return array<string,mixed> {
	 *     @type string $source        'webhook'.
	 *     @type string $batch_id      Webhook batch UUID.
	 *     @type int    $actor_user_id The integration user running the batch.
	 *     @type string $actor_name    Snapshotted display name.
	 * }
	 */
	public static function get() {
		if ( empty( self::$stack ) ) {
			return array();
		}

		return end( self::$stack );
	}

	/**
	 * Pushes a new active context. Callers should prefer `run()`, which
	 * guarantees the matching `pop()` even on exception; this is exposed
	 * separately only for callers that cannot express their work as a
	 * single callback.
	 *
	 * @param array<string,mixed> $context See `get()`.
	 * @return void
	 */
	public static function push( array $context ) {
		self::$stack[] = $context;
	}

	/**
	 * Removes the innermost active context.
	 *
	 * @return void
	 */
	public static function pop() {
		array_pop( self::$stack );
	}

	/**
	 * Runs a callback with a context active for its whole duration, popping
	 * it again afterward -- including when the callback throws, so a
	 * failed webhook item never leaves a stale context attributing whatever
	 * runs next (e.g. the batch's following item) to it.
	 *
	 * @param array<string,mixed> $context  See `get()`.
	 * @param callable            $callback function(): mixed.
	 * @return mixed The callback's return value.
	 * @throws Throwable Re-throws whatever the callback threw, after popping.
	 */
	public static function run( array $context, callable $callback ) {
		self::push( $context );

		try {
			return $callback();
		} finally {
			self::pop();
		}
	}

	/**
	 * Clears every active context. Exposed for tests only, so one test's
	 * `push()` without a matching `pop()` (e.g. an assertion failure
	 * partway through) can never leak into the next test in the same
	 * process.
	 *
	 * @return void
	 */
	public static function reset_for_tests() {
		self::$stack = array();
	}
}
