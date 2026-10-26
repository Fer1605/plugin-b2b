<?php
/**
 * Shared markup helpers for this plugin's admin screens: the branded page
 * header with its status badge, the pill tab bar, section cards with a tone,
 * the intro box and the status figures.
 *
 * They exist so every screen is built from the same pieces instead of each
 * view repeating (and slowly diverging from) the same markup. Everything they
 * print is scoped under `.b2bpl-admin` and styled in `assets/css/admin.css`.
 *
 * @package B2BPriceLists\Admin
 */

namespace B2BPriceLists\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class UI
 */
class UI {

	/**
	 * Section tones. Only ever tint an icon, a left border or a tag, never a
	 * large surface, so the panel keeps reading as WordPress admin.
	 *
	 * @var string[]
	 */
	const TONES = array( 'blue', 'teal', 'amber', 'purple', 'green' );

	/**
	 * Returns a tone that is safe to interpolate into a class name.
	 *
	 * @param string $tone Requested tone.
	 * @return string
	 */
	public static function tone( $tone ) {
		return in_array( $tone, self::TONES, true ) ? $tone : 'blue';
	}

	/**
	 * Returns the markup of a Dashicon inside a tinted rounded square.
	 *
	 * @param string $icon Dashicons class, e.g. `dashicons-portfolio`.
	 * @param string $tone One of self::TONES.
	 * @return string
	 */
	public static function icon( $icon, $tone = 'blue' ) {
		return sprintf(
			'<span class="b2bpl-icon b2bpl-icon--%1$s dashicons %2$s" aria-hidden="true"></span>',
			esc_attr( self::tone( $tone ) ),
			esc_attr( $icon )
		);
	}

	/**
	 * Prints the page header: plugin mark, title, one-line subtitle and,
	 * optionally, a status badge and page actions.
	 *
	 * Every screen keeps its own `<hr class="wp-header-end">` right after this
	 * call, which is where WordPress moves its own notices to.
	 *
	 * Keys: `title` (printed inside the `<h1>`), `subtitle` (one line saying
	 * what the screen is for), `icon` (Dashicons class for the mark), `version`
	 * (append the plugin version, default true), `badge` (label, tone
	 * off|warn|ok, icon, url) and `actions` (label, url, icon, primary).
	 *
	 * @param array<string,mixed> $args Header arguments, as described above.
	 * @return void
	 */
	public static function screen_header( array $args ) {
		$args = array_merge(
			array(
				'title'    => '',
				'subtitle' => '',
				'icon'     => 'dashicons-portfolio',
				'version'  => true,
				'badge'    => array(),
				'actions'  => array(),
			),
			$args
		);

		$subtitle = (string) $args['subtitle'];

		if ( $args['version'] && defined( 'B2BPL_VERSION' ) ) {
			$subtitle = '' === $subtitle
				? 'v' . B2BPL_VERSION
				: $subtitle . ' · v' . B2BPL_VERSION;
		}

		echo '<header class="b2bpl-header"><div class="b2bpl-header__brand">';

		printf(
			'<span class="b2bpl-header__logo dashicons %s" aria-hidden="true"></span>',
			esc_attr( $args['icon'] )
		);

		echo '<div><h1 class="b2bpl-header__title">' . esc_html( $args['title'] ) . '</h1>';

		if ( '' !== $subtitle ) {
			echo '<p class="b2bpl-header__subtitle">' . esc_html( $subtitle ) . '</p>';
		}

		echo '</div></div>';

		if ( ! empty( $args['badge'] ) || ! empty( $args['actions'] ) ) {
			echo '<div class="b2bpl-header__aside">';

			if ( ! empty( $args['badge'] ) ) {
				self::badge( $args['badge'] );
			}

			foreach ( $args['actions'] as $action ) {
				self::action_button( $action );
			}

			echo '</div>';
		}

		echo '</header>';
	}

	/**
	 * Prints the header status badge. Rendered as a link when it points at the
	 * screen where that status is changed, otherwise as plain text.
	 *
	 * @param array<string,mixed> $badge Label, tone (off|warn|ok), icon, url.
	 * @return void
	 */
	private static function badge( array $badge ) {
		$badge = array_merge(
			array(
				'label' => '',
				'tone'  => 'off',
				'icon'  => '',
				'url'   => '',
			),
			$badge
		);

		$tone = in_array( $badge['tone'], array( 'off', 'warn', 'ok' ), true ) ? $badge['tone'] : 'off';

		$inner = '';

		if ( '' !== $badge['icon'] ) {
			$inner .= sprintf(
				'<span class="dashicons %s" aria-hidden="true"></span>',
				esc_attr( $badge['icon'] )
			);
		}

		$inner .= esc_html( $badge['label'] );

		if ( '' === $badge['url'] ) {
			printf(
				'<span class="b2bpl-header__badge b2bpl-header__badge--%1$s">%2$s</span>',
				esc_attr( $tone ),
				$inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from escaped parts.
			);

			return;
		}

		printf(
			'<a class="b2bpl-header__badge b2bpl-header__badge--%1$s" href="%2$s">%3$s</a>',
			esc_attr( $tone ),
			esc_url( $badge['url'] ),
			$inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from escaped parts.
		);
	}

	/**
	 * Prints one header/toolbar button with its icon.
	 *
	 * @param array<string,mixed> $action Label, url, icon, primary.
	 * @return void
	 */
	public static function action_button( array $action ) {
		$action = array_merge(
			array(
				'label'   => '',
				'url'     => '',
				'icon'    => '',
				'primary' => false,
			),
			$action
		);

		printf(
			'<a class="button%1$s" href="%2$s">%3$s%4$s</a>',
			$action['primary'] ? ' button-primary' : '',
			esc_url( $action['url'] ),
			self::button_icon( $action['icon'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in button_icon().
			esc_html( $action['label'] )
		);
	}

	/**
	 * Returns the icon of a header button, drawn inline instead of set in the
	 * Dashicons font.
	 *
	 * On a real store the plus of "Add New" did not render inside the primary
	 * button, while the very same markup and stylesheet drew it correctly in
	 * isolation: some other plugin's admin stylesheet was reaching the glyph.
	 * A path in the markup cannot be blanked out by a font rule, and it
	 * follows the button's own colour like the rest of its label.
	 *
	 * Icons without a drawing here fall back to the Dashicon, which is fine
	 * everywhere else in the panel -- tabs, cards and figures all render it.
	 *
	 * @param string $icon Dashicons class, e.g. `dashicons-plus-alt2`.
	 * @return string Escaped HTML.
	 */
	private static function button_icon( $icon ) {
		if ( '' === $icon ) {
			return '';
		}

		$paths = array(
			'dashicons-plus-alt2'        => 'M12 5v14M5 12h14',
			'dashicons-plus'             => 'M12 5v14M5 12h14',
			'dashicons-arrow-left-alt2'  => 'M15 5l-7 7 7 7',
			'dashicons-arrow-right-alt2' => 'M9 5l7 7-7 7',
			'dashicons-arrow-left-alt'   => 'M20 12H5M11 6l-6 6 6 6',
			'dashicons-update'           => 'M20 12a8 8 0 1 1-2.3-5.6M20 4v5h-5',
		);

		if ( ! isset( $paths[ $icon ] ) ) {
			return sprintf( '<span class="dashicons %s" aria-hidden="true"></span>', esc_attr( $icon ) );
		}

		return sprintf(
			'<svg class="b2bpl-btn-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="%s"/></svg>',
			esc_attr( $paths[ $icon ] )
		);
	}

	/**
	 * Prints the pill tab bar. Replaces WordPress's `.nav-tab-wrapper`, which
	 * cannot follow the admin colour scheme as cleanly.
	 *
	 * @param array<int,array<string,mixed>> $tabs  Each: label, url, icon, active.
	 * @param string                         $label Accessible name of the nav.
	 * @return void
	 */
	public static function tabs( array $tabs, $label = '' ) {
		if ( empty( $tabs ) ) {
			return;
		}

		printf(
			'<nav class="b2bpl-tabs" aria-label="%s">',
			esc_attr( '' === $label ? __( 'Sections', 'b2b-price-lists' ) : $label )
		);

		foreach ( $tabs as $tab ) {
			$tab = array_merge(
				array(
					'label'  => '',
					'url'    => '',
					'icon'   => '',
					'active' => false,
				),
				$tab
			);

			printf(
				'<a class="b2bpl-tabs__item%1$s" href="%2$s"%3$s>%4$s%5$s</a>',
				$tab['active'] ? ' is-active' : '',
				esc_url( $tab['url'] ),
				$tab['active'] ? ' aria-current="page"' : '',
				'' === $tab['icon']
					? ''
					: sprintf( '<span class="dashicons %s" aria-hidden="true"></span>', esc_attr( $tab['icon'] ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inline.
				esc_html( $tab['label'] )
			);
		}

		echo '</nav>';
	}

	/**
	 * Prints the plugin's own section bar, the same on every top-level screen:
	 * lists, activity, integration, B2B orders, settings and the user guide.
	 *
	 * It replaces the row of buttons each screen used to carry next to its
	 * title, which said nothing about where the user was.
	 *
	 * @param string $current One of: lists, activity, integration, orders, settings, help.
	 * @return void
	 */
	public static function main_nav( $current ) {
		$page = admin_url( 'admin.php?page=' . Menu::PAGE_SLUG );

		$tabs = array(
			array(
				'key'   => 'lists',
				'label' => __( 'Price lists', 'b2b-price-lists' ),
				'url'   => $page,
				'icon'  => 'dashicons-portfolio',
			),
			array(
				'key'   => 'activity',
				'label' => __( 'Activity', 'b2b-price-lists' ),
				'url'   => admin_url( 'admin.php?page=' . ActivityController::PAGE_SLUG ),
				'icon'  => 'dashicons-backup',
			),
			array(
				'key'   => 'integration',
				'label' => __( 'Integration', 'b2b-price-lists' ),
				'url'   => admin_url( 'admin.php?page=' . IntegrationLogController::PAGE_SLUG ),
				'icon'  => 'dashicons-rest-api',
			),
		);

		// Same condition the menu itself uses: the shortcut only exists for
		// somebody who can also open the orders screen it redirects to.
		if ( current_user_can( 'edit_shop_orders' ) ) {
			$tabs[] = array(
				'key'   => 'orders',
				'label' => __( 'B2B orders', 'b2b-price-lists' ),
				'url'   => admin_url( 'admin.php?page=b2bpl-orders' ),
				'icon'  => 'dashicons-cart',
			);
		}

		$tabs[] = array(
			'key'   => 'settings',
			'label' => __( 'Settings', 'b2b-price-lists' ),
			'url'   => add_query_arg( 'action', 'settings', $page ),
			'icon'  => 'dashicons-admin-generic',
		);

		$tabs[] = array(
			'key'   => 'help',
			'label' => __( 'User guide', 'b2b-price-lists' ),
			'url'   => add_query_arg( 'action', 'help', $page ),
			'icon'  => 'dashicons-book',
		);

		foreach ( $tabs as $index => $tab ) {
			$tabs[ $index ]['active'] = ( $tab['key'] === $current );
		}

		self::tabs( $tabs, __( 'B2B Price Lists sections', 'b2b-price-lists' ) );
	}

	/**
	 * Prints the intro box that opens a screen: what it is for, in one or two
	 * sentences, and what the user does not need to configure.
	 *
	 * @param string $html    Lead text. Limited inline HTML is allowed.
	 * @param string $icon    Dashicons class.
	 * @param bool   $compact Tighter padding, for secondary tabs.
	 * @return void
	 */
	public static function hero( $html, $icon = 'dashicons-lightbulb', $compact = false ) {
		printf(
			'<div class="b2bpl-hero%1$s"><span class="b2bpl-hero__icon dashicons %2$s" aria-hidden="true"></span><p class="b2bpl-hero__lead">%3$s</p></div>',
			$compact ? ' b2bpl-hero--compact' : '',
			esc_attr( $icon ),
			wp_kses_post( $html )
		);
	}

	/**
	 * Opens a section card: white surface, tinted icon and a title rule.
	 *
	 * @param string              $title Section title.
	 * @param string              $icon  Dashicons class.
	 * @param string              $tone  One of self::TONES.
	 * @param array<string,mixed> $args  id, accent (left border), class, number (position in a guide).
	 * @return void
	 */
	public static function card_open( $title, $icon, $tone = 'blue', array $args = array() ) {
		$args = array_merge(
			array(
				'id'     => '',
				'accent' => false,
				'class'  => '',
				'number' => 0,
			),
			$args
		);

		$tone    = self::tone( $tone );
		$classes = 'b2bpl-card b2bpl-card--' . $tone;

		if ( $args['accent'] ) {
			$classes .= ' b2bpl-card--accent';
		}

		if ( '' !== $args['class'] ) {
			$classes .= ' ' . $args['class'];
		}

		// A numbered section matches the numbered index of the guides, so the
		// same section can be found by its number or by its icon.
		$number = (int) $args['number'] > 0
			? '<span class="b2bpl-card__num">' . esc_html( (string) (int) $args['number'] ) . '</span>'
			: '';

		printf(
			'<section class="%1$s"%2$s><h2 class="b2bpl-card__title">%3$s%4$s%5$s</h2>',
			esc_attr( $classes ),
			'' === $args['id'] ? '' : ' id="' . esc_attr( $args['id'] ) . '"',
			$number, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from an escaped integer.
			self::icon( $icon, $tone ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			esc_html( $title )
		);
	}

	/**
	 * Closes a section card opened with card_open().
	 *
	 * @return void
	 */
	public static function card_close() {
		echo '</section>';
	}

	/**
	 * Prints one status figure: a big number, what it counts and a hint.
	 *
	 * @param string|int $value Figure.
	 * @param string     $label What it counts.
	 * @param string     $hint  Short clarification. Optional.
	 * @param string     $icon  Dashicons class.
	 * @param string     $tone  One of self::TONES.
	 * @return void
	 */
	public static function stat( $value, $label, $hint, $icon, $tone = 'blue' ) {
		printf(
			'<div class="b2bpl-stat">%1$s<div><strong class="b2bpl-stat__value">%2$s</strong><span class="b2bpl-stat__label">%3$s</span>%4$s</div></div>',
			self::icon( $icon, $tone ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			esc_html( (string) $value ),
			esc_html( $label ),
			'' === $hint ? '' : '<span class="b2bpl-stat__hint">' . esc_html( $hint ) . '</span>'
		);
	}
}
