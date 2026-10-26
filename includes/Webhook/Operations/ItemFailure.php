<?php
/**
 * Thrown by `Webhook\Operations\{List,Product}Operations` to abort a single
 * webhook item's work,
 * caught by `Webhook\BatchProcessor` -- which is also where the item's
 * `Database\Transaction::run()` wrapper lives, so throwing this is what
 * rolls back whatever that item had already written before the failure.
 *
 * Deliberately a plain, final failure carrier (code/message/path), never a
 * signal of a *bug*: `BatchProcessor` treats it as an expected, business-rule
 * outcome (an `error` item result with the given `errors[]` entry), not as
 * something to log as unexpected via `Support\Logger`.
 *
 * @package B2BPriceLists\Webhook\Operations
 */

namespace B2BPriceLists\Webhook\Operations;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ItemFailure
 */
class ItemFailure extends RuntimeException {

	/**
	 * Item error code (contract: `list_not_found`, `sku_not_found`, ...).
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * JSON-pointer-ish path of the offending field, if known (contract's
	 * `errors[].path`).
	 *
	 * @var string|null
	 */
	private $path;

	/**
	 * Constructor.
	 *
	 * @param string      $code    Item error code.
	 * @param string      $message Human-readable message.
	 * @param string|null $path    Offending field path, if known.
	 */
	public function __construct( $code, $message, $path = null ) {
		parent::__construct( $message );

		$this->error_code = (string) $code;
		$this->path       = null === $path ? null : (string) $path;
	}

	/**
	 * The item error code.
	 *
	 * @return string
	 */
	public function get_error_code() {
		return $this->error_code;
	}

	/**
	 * The offending field path, if known.
	 *
	 * @return string|null
	 */
	public function get_path() {
		return $this->path;
	}

	/**
	 * The `errors[]` entry shape used in `GET /webhook?batch_id=` (contract).
	 *
	 * @return array{code:string,message:string,path?:string}
	 */
	public function to_error_entry() {
		$entry = array(
			'code'    => $this->error_code,
			'message' => $this->getMessage(),
		);

		if ( null !== $this->path ) {
			$entry['path'] = $this->path;
		}

		return $entry;
	}
}
