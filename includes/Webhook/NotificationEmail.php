<?php
/**
 * HTML layout for webhook notification emails.
 *
 * Builds a structured email (title, summary, detail rows, errors, button)
 * from a notification context and renders it as an email-safe HTML table
 * layout with inline styles. Colors come from the site's own WooCommerce
 * email settings (base color, header image), so every site gets its own
 * branding without hard-coding any site's palette; the
 * `b2bpl_webhook_email_palette` filter can override them.
 *
 * Everything except `palette()` is pure and unit-testable.
 *
 * @package B2BPriceLists\Webhook
 */

namespace B2BPriceLists\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NotificationEmail
 */
class NotificationEmail {

	/**
	 * Fallback base color when WooCommerce has none (WordPress admin blue).
	 *
	 * @var string
	 */
	const DEFAULT_BASE_COLOR = '#2271b1';

	/**
	 * Semantic color for errors (independent of the site palette, so an
	 * error always reads as an error).
	 *
	 * @var string
	 */
	const ERROR_COLOR = '#b32d2e';

	/**
	 * The site's palette for notification emails.
	 *
	 * @return array{base:string,text:string,header_image:string}
	 */
	public static function palette() {
		$base = (string) get_option( 'woocommerce_email_base_color', '' );
		$text = (string) get_option( 'woocommerce_email_text_color', '' );

		$palette = array(
			'base'         => self::sanitize_hex( $base, self::DEFAULT_BASE_COLOR ),
			'text'         => self::sanitize_hex( $text, '#1e1e1e' ),
			'header_image' => self::logo_url(),
		);

		/**
		 * Filters the colors and header image used by webhook notification emails.
		 *
		 * @param array{base:string,text:string,header_image:string} $palette Palette.
		 */
		$filtered = apply_filters( 'b2bpl_webhook_email_palette', $palette );

		return is_array( $filtered ) ? array_merge( $palette, $filtered ) : $palette;
	}

	/**
	 * The email logo: the one chosen in Integration → Settings, otherwise
	 * the WooCommerce email header image.
	 *
	 * @return string
	 */
	private static function logo_url() {
		$logo_id = (int) ( Settings::get()['notification_logo_id'] ?? 0 );
		$url     = $logo_id > 0 ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';

		return is_string( $url ) && '' !== $url ? $url : (string) get_option( 'woocommerce_email_header_image', '' );
	}

	/**
	 * A 6-digit hex color, or the fallback.
	 *
	 * @param string $color    Candidate color.
	 * @param string $fallback Fallback color.
	 * @return string
	 */
	public static function sanitize_hex( $color, $fallback ) {
		$color = trim( (string) $color );

		if ( preg_match( '/^#([0-9a-fA-F]{3})$/', $color, $m ) ) {
			$color = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}

		return preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ? strtolower( $color ) : $fallback;
	}

	/**
	 * Mixes two hex colors. `$weight` is the share of `$other` (0..1).
	 *
	 * @param string $color  Base color.
	 * @param string $other  Color mixed in.
	 * @param float  $weight Share of `$other`.
	 * @return string
	 */
	public static function mix( $color, $other, $weight ) {
		$a      = sscanf( self::sanitize_hex( $color, '#000000' ), '#%02x%02x%02x' );
		$b      = sscanf( self::sanitize_hex( $other, '#000000' ), '#%02x%02x%02x' );
		$weight = max( 0.0, min( 1.0, (float) $weight ) );
		$out    = '#';

		for ( $i = 0; $i < 3; $i++ ) {
			$out .= sprintf( '%02x', (int) round( $a[ $i ] * ( 1 - $weight ) + $b[ $i ] * $weight ) );
		}

		return $out;
	}

	/**
	 * A plain-language explanation of a rejection, for non-experts.
	 *
	 * @param string $code        Machine error code.
	 * @param int    $http_status HTTP status.
	 * @return string
	 */
	public static function explain( $code, $http_status ) {
		$map = array(
			'b2bpl_invalid_credentials'    => __( 'Wrong webhook username or password.', 'b2b-price-lists' ),
			'rest_not_logged_in'           => __( 'The request arrived without the webhook username and password.', 'b2b-price-lists' ),
			'rest_no_route'                => __( 'The address does not exist, or the webhook is turned off.', 'b2b-price-lists' ),
			'b2bpl_rate_limited'           => __( 'Too many requests in a short time. The sender must wait and retry.', 'b2b-price-lists' ),
			'b2bpl_invalid_payload'        => __( 'The JSON does not have the expected format. Nothing was applied.', 'b2b-price-lists' ),
			'b2bpl_invalid_json'           => __( 'The body is not valid JSON.', 'b2b-price-lists' ),
			'b2bpl_payload_too_large'      => __( 'The send is larger than the allowed maximum.', 'b2b-price-lists' ),
			'b2bpl_unsupported_media_type' => __( 'The request must be sent as application/json.', 'b2b-price-lists' ),
			'b2bpl_idempotency_conflict'   => __( 'The idempotency_key was already used for a different send.', 'b2b-price-lists' ),
			'b2bpl_https_required'         => __( 'The request must use HTTPS.', 'b2b-price-lists' ),
			'b2bpl_log_unavailable'        => __( 'The site could not record the request, so it was not processed.', 'b2b-price-lists' ),
		);

		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}

		if ( 401 === (int) $http_status ) {
			return $map['b2bpl_invalid_credentials'];
		}

		return (int) $http_status >= 500
			? __( 'Server error while receiving the request.', 'b2b-price-lists' )
			: __( 'The request was refused. Nothing from it was applied.', 'b2b-price-lists' );
	}

	/**
	 * Structured email for a rejected request.
	 *
	 * @param array<string,mixed> $context Notification context.
	 * @return array<string,mixed>
	 */
	public static function rejection( array $context ) {
		$http = (int) ( $context['http_status'] ?? 0 );
		$code = (string) ( $context['rejection_code'] ?? '' );
		$rows = array(
			array( __( 'What happened', 'b2b-price-lists' ), self::explain( $code, $http ) ),
			array( __( 'HTTP status', 'b2b-price-lists' ), $http ? (string) $http : '' ),
			array( __( 'Error code', 'b2b-price-lists' ), $code ),
			array( __( 'Message', 'b2b-price-lists' ), (string) ( $context['message'] ?? '' ) ),
			array( __( 'Type', 'b2b-price-lists' ), self::type_label( $context ) ),
			array( __( 'Request', 'b2b-price-lists' ), trim( ( $context['method'] ?? '' ) . ' ' . ( $context['route'] ?? '' ) ) ),
			array( __( 'Username attempted', 'b2b-price-lists' ), (string) ( $context['username_attempted'] ?? '' ) ),
			array( __( 'IP address', 'b2b-price-lists' ), (string) ( $context['ip'] ?? '' ) ),
			array( __( 'Received (UTC)', 'b2b-price-lists' ), (string) ( $context['received_at'] ?? '' ) ),
			array( __( 'Batch', 'b2b-price-lists' ), (string) ( $context['batch_id'] ?? '' ) ),
		);

		return array(
			'title'      => __( 'Request rejected', 'b2b-price-lists' ),
			'summary'    => __( 'The B2B webhook refused a request. Nothing from it was applied.', 'b2b-price-lists' ),
			'rows'       => $rows,
			'errors'     => is_array( $context['errors'] ?? null ) ? $context['errors'] : array(),
			'detail_url' => (string) ( $context['detail_url'] ?? '' ),
		);
	}

	/**
	 * Structured email for a batch that finished with errors or failed.
	 *
	 * @param array<string,mixed> $context Notification context.
	 * @return array<string,mixed>
	 */
	public static function batch( array $context ) {
		$status = (string) ( $context['status'] ?? '' );
		$counts = is_array( $context['counts'] ?? null ) ? $context['counts'] : array();
		$failed = 'failed' === $status;

		$rows = array(
			array( __( 'Type', 'b2b-price-lists' ), self::type_label( $context ) ),
			array(
				__( 'Operations', 'b2b-price-lists' ),
				empty( $counts ) ? '' : sprintf(
					/* translators: 1: total operations, 2: successful, 3: with errors, 4: partial, 5: skipped. */
					__( '%1$d in total: %2$d successful, %3$d with errors, %4$d partial, %5$d skipped', 'b2b-price-lists' ),
					(int) ( $counts['total'] ?? 0 ),
					(int) ( $counts['success'] ?? 0 ),
					(int) ( $counts['error'] ?? 0 ),
					(int) ( $counts['partial'] ?? 0 ),
					(int) ( $counts['skipped'] ?? 0 )
				),
			),
			array( __( 'Received (UTC)', 'b2b-price-lists' ), (string) ( $context['received_at'] ?? '' ) ),
			array( __( 'Batch', 'b2b-price-lists' ), (string) ( $context['batch_id'] ?? '' ) ),
		);

		return array(
			'title'      => $failed ? __( 'Batch failed', 'b2b-price-lists' ) : __( 'Batch finished with errors', 'b2b-price-lists' ),
			'summary'    => $failed
				? __( 'A batch stopped because of an internal error. Once the cause is fixed it can be reprocessed from the log.', 'b2b-price-lists' )
				: __( 'A batch finished, but some operations failed or were only partially applied. The rest were applied normally.', 'b2b-price-lists' ),
			'rows'       => $rows,
			'errors'     => is_array( $context['errors'] ?? null ) ? $context['errors'] : array(),
			'detail_url' => (string) ( $context['detail_url'] ?? '' ),
		);
	}

	/**
	 * Structured email for the hourly digest.
	 *
	 * @param array<int,array{subject:string,body:string}> $items    Queued notifications.
	 * @param int                                          $overflow Extra notifications not itemized.
	 * @return array<string,mixed>
	 */
	public static function digest( array $items, $overflow ) {
		$blocks = array();

		foreach ( $items as $item ) {
			$blocks[] = array(
				'subject' => (string) ( $item['subject'] ?? '' ),
				'body'    => (string) ( $item['body'] ?? '' ),
			);
		}

		return array(
			'title'      => __( 'Summary of webhook errors', 'b2b-price-lists' ),
			'summary'    => __( 'These notices were grouped into one email because the hourly limit of individual emails was reached.', 'b2b-price-lists' ),
			'rows'       => array(),
			'errors'     => array(),
			'blocks'     => $blocks,
			'overflow'   => (int) $overflow,
			'detail_url' => '',
		);
	}

	/**
	 * Human label for the batch type, when known.
	 *
	 * @param array<string,mixed> $context Notification context.
	 * @return string
	 */
	private static function type_label( array $context ) {
		$type = (string) ( $context['endpoint'] ?? '' );

		if ( 'lists' === $type ) {
			return __( 'Lists and products', 'b2b-price-lists' );
		}

		if ( 'users' === $type ) {
			return __( 'Users', 'b2b-price-lists' );
		}

		return '';
	}

	/**
	 * Renders a structured email as email-safe HTML (inline styles, tables).
	 *
	 * @param array<string,mixed>                                $email     From `rejection()`/`batch()`/`digest()`.
	 * @param array{base:string,text:string,header_image:string} $palette   From `palette()`.
	 * @param string                                             $site_name Site name.
	 * @param string                                             $site_url  Site URL.
	 * @return string
	 */
	public static function render( array $email, array $palette, $site_name, $site_url ) {
		$base    = self::sanitize_hex( $palette['base'] ?? '', self::DEFAULT_BASE_COLOR );
		$text    = self::sanitize_hex( $palette['text'] ?? '', '#1e1e1e' );
		$dark    = self::mix( $base, '#000000', 0.3 );
		$page_bg = self::mix( $base, '#ffffff', 0.9 );
		$soft    = self::mix( $base, '#ffffff', 0.94 );
		$line    = self::mix( $base, '#ffffff', 0.8 );
		$muted   = self::mix( $text, '#ffffff', 0.4 );
		$font    = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
		$image   = (string) ( $palette['header_image'] ?? '' );

		$html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( (string) $email['title'] ) . '</title></head>';
		$html .= '<body style="margin:0;padding:0;background:' . $page_bg . ';">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $page_bg . ';padding:32px 12px;font-family:' . $font . ';color:' . $text . ';"><tr><td align="center">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid ' . $line . ';">';

		if ( '' !== $image ) {
			$html .= '<tr><td align="center" style="padding:22px 24px 18px;background:#ffffff;"><img src="' . esc_url( $image ) . '" alt="' . esc_attr( $site_name ) . '" style="max-width:220px;max-height:70px;height:auto;border:0;"></td></tr>';
		}

		$html .= '<tr><td style="background:' . $dark . ';padding:22px 28px;">';
		$html .= '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#ffffff;opacity:.85;">' . esc_html__( 'B2B webhook', 'b2b-price-lists' ) . '</div>';
		$html .= '<div style="font-size:22px;font-weight:700;color:#ffffff;margin-top:4px;">' . esc_html( (string) $email['title'] ) . '</div>';
		$html .= '</td></tr>';

		$html .= '<tr><td style="padding:24px 28px 8px;">';
		$html .= '<div style="border-left:4px solid ' . self::ERROR_COLOR . ';background:#fcf0f1;padding:12px 16px;border-radius:6px;font-size:15px;line-height:1.5;">' . esc_html( (string) $email['summary'] ) . '</div>';
		$html .= '</td></tr>';

		$rows = array_filter(
			(array) $email['rows'],
			static function ( $row ) {
				return is_array( $row ) && '' !== trim( (string) ( $row[1] ?? '' ) );
			}
		);

		if ( ! empty( $rows ) ) {
			$html .= '<tr><td style="padding:12px 28px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">';

			foreach ( $rows as $row ) {
				$html .= '<tr><td style="padding:9px 12px 9px 0;border-bottom:1px solid ' . $line . ';color:' . $muted . ';width:38%;vertical-align:top;">' . esc_html( (string) $row[0] ) . '</td>';
				$html .= '<td style="padding:9px 0;border-bottom:1px solid ' . $line . ';vertical-align:top;word-break:break-word;">' . esc_html( (string) $row[1] ) . '</td></tr>';
			}

			$html .= '</table></td></tr>';
		}

		$errors = (array) $email['errors'];

		if ( ! empty( $errors ) ) {
			$shown = array_slice( $errors, 0, Notifier::MAX_ERROR_ENTRIES );
			$html .= '<tr><td style="padding:16px 28px 4px;"><div style="font-size:16px;font-weight:700;color:' . $dark . ';">' . esc_html__( 'Errors', 'b2b-price-lists' ) . '</div></td></tr>';

			foreach ( $shown as $error ) {
				$error = is_array( $error ) ? $error : array();
				$head  = trim( implode( ' · ', array_filter( array( (string) ( $error['ref'] ?? '' ), (string) ( $error['action'] ?? '' ) ) ) ) );
				$html .= '<tr><td style="padding:6px 28px;"><div style="background:' . $soft . ';border:1px solid ' . $line . ';border-radius:8px;padding:10px 14px;font-size:14px;line-height:1.45;">';

				if ( '' !== $head ) {
					$html .= '<div style="font-weight:600;">' . esc_html( $head ) . '</div>';
				}

				if ( ! empty( $error['code'] ) ) {
					$html .= '<div style="font-family:Menlo,Consolas,monospace;font-size:12px;color:' . self::ERROR_COLOR . ';margin-top:2px;">' . esc_html( (string) $error['code'] ) . ( ! empty( $error['path'] ) ? ' — ' . esc_html( (string) $error['path'] ) : '' ) . '</div>';
				}

				if ( ! empty( $error['message'] ) ) {
					$html .= '<div style="margin-top:4px;">' . esc_html( (string) $error['message'] ) . '</div>';
				}

				$html .= '</div></td></tr>';
			}

			if ( count( $errors ) > count( $shown ) ) {
				$html .= '<tr><td style="padding:6px 28px;font-size:13px;color:' . $muted . ';">' . esc_html(
					sprintf(
						/* translators: %d: number of errors not listed in the email. */
						_n( '%d more error is not shown. See the full detail in the admin.', '%d more errors are not shown. See the full detail in the admin.', count( $errors ) - count( $shown ), 'b2b-price-lists' ),
						count( $errors ) - count( $shown )
					)
				) . '</td></tr>';
			}
		}

		foreach ( (array) ( $email['blocks'] ?? array() ) as $block ) {
			$html .= '<tr><td style="padding:6px 28px;"><div style="background:' . $soft . ';border:1px solid ' . $line . ';border-radius:8px;padding:10px 14px;">';
			$html .= '<div style="font-weight:600;font-size:14px;">' . esc_html( (string) $block['subject'] ) . '</div>';
			$html .= '<pre style="white-space:pre-wrap;word-break:break-word;font-family:Menlo,Consolas,monospace;font-size:12px;margin:6px 0 0;color:' . $text . ';">' . esc_html( (string) $block['body'] ) . '</pre>';
			$html .= '</div></td></tr>';
		}

		if ( ! empty( $email['overflow'] ) ) {
			$html .= '<tr><td style="padding:6px 28px;font-size:13px;color:' . $muted . ';">' . esc_html(
				sprintf(
					/* translators: %d: number of additional notifications not itemized. */
					_n( '%d more notice occurred and could not be listed individually.', '%d more notices occurred and could not be listed individually.', (int) $email['overflow'], 'b2b-price-lists' ),
					(int) $email['overflow']
				)
			) . '</td></tr>';
		}

		if ( ! empty( $email['detail_url'] ) ) {
			$html .= '<tr><td align="center" style="padding:22px 28px 8px;"><a href="' . esc_url( (string) $email['detail_url'] ) . '" style="display:inline-block;background:' . $dark . ';color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;padding:12px 26px;border-radius:8px;">' . esc_html__( 'View details in the admin', 'b2b-price-lists' ) . '</a></td></tr>';
		}

		$html .= '<tr><td style="padding:22px 28px 26px;font-size:12px;line-height:1.5;color:' . $muted . ';border-top:1px solid ' . $line . ';">';
		$html .= esc_html(
			sprintf(
				/* translators: %s: site name. */
				__( 'Automatic notice from the B2B webhook of %s. Recipients are set in B2B Price Lists → Integration → Settings.', 'b2b-price-lists' ),
				$site_name
			)
		);
		$html .= '<br><a href="' . esc_url( $site_url ) . '" style="color:' . $dark . ';">' . esc_html( $site_url ) . '</a>';
		$html .= '</td></tr></table></td></tr></table></body></html>';

		return $html;
	}
}
