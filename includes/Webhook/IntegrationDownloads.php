<?php
/**
 * Builds and streams the downloads offered from a webhook batch's detail
 * screen (T044): the JSON received (original and pretty-printed),
 * the response sent, every item's result as one `.json`, the event
 * timeline as `.json`/`.txt`, and the whole batch as a `.zip`.
 *
 * Every text/JSON download is streamed directly (same pattern as
 * `Activity\ActivityExporter::send_headers()`); the `.zip` is the one
 * exception that needs an actual file on disk (`ZipArchive` cannot write to
 * `php://output`), built in `sys_get_temp_dir()` and deleted in a `finally`
 * block regardless of outcome -- **never** under `uploads/`, since a host
 * like many managed hosts' nginx ignores `.htaccess` there (the same reason
 * the activity log's monthly archives are stored in the database instead of
 * as files). Every caller (`Admin\IntegrationLogController::handle_download()`)
 * has already checked `Support\Capabilities::current_user_can_export_integration_log()`
 * and verified the download nonce before this class ever runs.
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

use B2BPriceLists\Repository\Webhook\EventsRepository;
use B2BPriceLists\Repository\Webhook\ItemsRepository;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IntegrationDownloads
 */
class IntegrationDownloads {

	/**
	 * Items repository.
	 *
	 * @var ItemsRepository
	 */
	private $items;

	/**
	 * Events repository.
	 *
	 * @var EventsRepository
	 */
	private $events;

	/**
	 * Constructor.
	 *
	 * @param ItemsRepository  $items  Items repository.
	 * @param EventsRepository $events Events repository.
	 */
	public function __construct( ItemsRepository $items, EventsRepository $events ) {
		$this->items  = $items;
		$this->events = $events;
	}

	/**
	 * Sends one download and ends the request.
	 *
	 * @param array<string,mixed> $row  `webhook_requests` row.
	 * @param string              $file One of: `received-original`,
	 *                                  `received-formatted`, `response`,
	 *                                  `items`, `events-json`, `events-txt`,
	 *                                  `zip`.
	 * @return void
	 */
	public function send( array $row, $file ) {
		$request_id = (int) $row['id'];

		switch ( $file ) {
			case 'received-original':
				self::send_text( self::filename( $row, 'recibido-original', 'txt' ), (string) ( $row['raw_body'] ?? '' ) );
				break;

			case 'received-formatted':
				self::send_text(
					self::filename( $row, 'recibido-formateado', 'json' ),
					JsonFormatter::pretty_print_json_string( (string) ( $row['payload'] ?? $row['raw_body'] ?? '' ) ),
					'application/json; charset=utf-8'
				);
				break;

			case 'response':
				self::send_text(
					self::filename( $row, 'respuesta', 'json' ),
					JsonFormatter::pretty_print_json_string( (string) ( $row['response_body'] ?? '' ) ),
					'application/json; charset=utf-8'
				);
				break;

			case 'items':
				self::send_text(
					self::filename( $row, 'items', 'json' ),
					JsonFormatter::pretty_print( $this->items_payload( $request_id ) ),
					'application/json; charset=utf-8'
				);
				break;

			case 'events-json':
				self::send_text(
					self::filename( $row, 'eventos', 'json' ),
					JsonFormatter::pretty_print( $this->events_payload( $request_id ) ),
					'application/json; charset=utf-8'
				);
				break;

			case 'events-txt':
				self::send_text(
					self::filename( $row, 'eventos', 'txt' ),
					self::events_as_text( $this->events->get_by_request( $request_id ) )
				);
				break;

			case 'zip':
				$this->send_zip( $row );
				break;

			default:
				wp_die( esc_html__( 'Unknown download.', 'b2b-price-lists' ), '', array( 'response' => 400 ) );
		}

		exit;
	}

	/**
	 * One item row, shaped for the `items.json` download: sub-fields
	 * decoded back into structured JSON rather than left as escaped text.
	 *
	 * @param int $request_id Request id.
	 * @return array<int,array<string,mixed>>
	 */
	private function items_payload( $request_id ) {
		return array_map(
			static function ( $item ) {
				return array(
					'index'        => (int) $item['item_index'],
					'ref'          => $item['ref'],
					'entity'       => $item['entity'],
					'action'       => $item['action'],
					'external_ref' => $item['external_ref'],
					'status'       => $item['status'],
					'attempts'     => (int) $item['attempts'],
					'input'        => self::decode_or_null( $item['input'] ?? null ),
					'result'       => self::decode_or_null( $item['result'] ?? null ),
					'errors'       => self::decode_or_null( $item['errors'] ?? null ),
				);
			},
			$this->items->get_by_request( $request_id )
		);
	}

	/**
	 * Every event row, shaped for the `eventos.json` download.
	 *
	 * @param int $request_id Request id.
	 * @return array<int,array<string,mixed>>
	 */
	private function events_payload( $request_id ) {
		return array_map(
			static function ( $event ) {
				return array(
					'created_at' => $event['created_at'],
					'level'      => $event['level'],
					'code'       => $event['code'],
					'message'    => $event['message'],
					'item_id'    => isset( $event['item_id'] ) ? (int) $event['item_id'] : null,
					'context'    => self::decode_or_null( $event['context'] ?? null ),
				);
			},
			$this->events->get_by_request( $request_id )
		);
	}

	/**
	 * Decodes a stored JSON column, or `null` when empty/not valid JSON.
	 *
	 * @param string|null $value Stored JSON text.
	 * @return mixed
	 */
	private static function decode_or_null( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$decoded = json_decode( (string) $value, true );

		return ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) ? (string) $value : $decoded;
	}

	/**
	 * The plain-text event timeline (T044's "eventos (.txt legible)"):
	 * one readable line per event, oldest first. Pure and unit-testable.
	 *
	 * @param array<int,array<string,mixed>> $events Event rows, as returned
	 *                                                by `EventsRepository::get_by_request()`.
	 * @return string
	 */
	public static function events_as_text( array $events ) {
		$lines = array();

		foreach ( $events as $event ) {
			$line = sprintf(
				'[%1$s] %2$s %3$s — %4$s',
				(string) ( $event['created_at'] ?? '' ),
				strtoupper( (string) ( $event['level'] ?? '' ) ),
				(string) ( $event['code'] ?? '' ),
				(string) ( $event['message'] ?? '' )
			);

			if ( ! empty( $event['item_id'] ) ) {
				$line .= sprintf( ' (item #%d)', (int) $event['item_id'] );
			}

			$lines[] = $line;
		}

		return empty( $lines ) ? '' : implode( "\n", $lines ) . "\n";
	}

	/**
	 * A safe, descriptive file name for one download. Pure and
	 * unit-testable: does not depend on `sanitize_file_name()` (WordPress)
	 * so the exact rule can be tested without WordPress loaded.
	 *
	 * @param array<string,mixed> $row    `webhook_requests` row (only `batch_id` is used).
	 * @param string              $suffix What this particular file holds.
	 * @param string              $ext    File extension, no leading dot.
	 * @return string
	 */
	public static function filename( array $row, $suffix, $ext ) {
		$batch_id = isset( $row['batch_id'] ) && '' !== $row['batch_id'] ? (string) $row['batch_id'] : 'lote';
		$name     = 'b2bpl-webhook-' . $batch_id . '-' . $suffix . '.' . $ext;
		$name     = (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name );

		return trim( $name, '-' );
	}

	/**
	 * Sends the headers and body of a plain-text/JSON download, without
	 * ending the request (the caller's `send()` does that once, after the
	 * `switch`).
	 *
	 * @param string $filename     File name offered to the browser.
	 * @param string $content      File content.
	 * @param string $content_type Defaults to plain text.
	 * @return void
	 */
	private static function send_text( $filename, $content, $content_type = 'text/plain; charset=utf-8' ) {
		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a downloaded text/JSON file body, not HTML; every value inside it was already produced by JsonFormatter/this class, never raw request input.
	}

	/**
	 * Builds and streams the `.zip` download: every other download's
	 * content, as its own file inside the archive (`ZipArchive`
	 * in `sys_get_temp_dir()`, deleted in `finally`, never under `uploads/`).
	 *
	 * @param array<string,mixed> $row `webhook_requests` row.
	 * @return void
	 */
	private function send_zip( array $row ) {
		if ( ! class_exists( ZipArchive::class ) ) {
			wp_die(
				esc_html__( 'ZIP downloads are not available on this server.', 'b2b-price-lists' ),
				'',
				array( 'response' => 501 )
			);
		}

		$request_id = (int) $row['id'];
		$tmp_path   = trailingslashit( sys_get_temp_dir() ) . 'b2bpl-webhook-' . wp_generate_password( 12, false, false ) . '.zip';
		$zip        = new ZipArchive();

		if ( true !== $zip->open( $tmp_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'Could not build the ZIP file.', 'b2b-price-lists' ), '', array( 'response' => 500 ) );
		}

		try {
			$zip->addFromString( 'recibido-original.txt', (string) ( $row['raw_body'] ?? '' ) );
			$zip->addFromString( 'recibido-formateado.json', JsonFormatter::pretty_print_json_string( (string) ( $row['payload'] ?? $row['raw_body'] ?? '' ) ) );
			$zip->addFromString( 'respuesta.json', JsonFormatter::pretty_print_json_string( (string) ( $row['response_body'] ?? '' ) ) );
			$zip->addFromString( 'items.json', JsonFormatter::pretty_print( $this->items_payload( $request_id ) ) );
			$zip->addFromString( 'eventos.json', JsonFormatter::pretty_print( $this->events_payload( $request_id ) ) );
			$zip->addFromString( 'eventos.txt', self::events_as_text( $this->events->get_by_request( $request_id ) ) );
			$zip->close();

			nocache_headers();
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . self::filename( $row, 'lote-completo', 'zip' ) . '"' );
			header( 'Content-Length: ' . (string) filesize( $tmp_path ) );
			header( 'X-Content-Type-Options: nosniff' );

			readfile( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a just-built temporary ZIP (never anything under uploads/) straight to the browser; there is nothing to filter through WP_Filesystem here.
		} finally {
			if ( file_exists( $tmp_path ) ) {
				wp_delete_file( $tmp_path );
			}
		}
	}
}
