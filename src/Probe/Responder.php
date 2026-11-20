<?php
/**
 * The side of "Why can't they?" that runs inside the request being checked.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Probe;

use AryanX\Axle\RoleRightSizer\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Does nothing unless the request carries a probe cookie that is signed, still
 * fresh and not used before, and that belongs to the user the request runs as.
 * Then it notes every capability check that failed for that user and, for the
 * REST API, answers right after the permission check so nothing is created,
 * changed or deleted. It saves what it saw for the person who asked, and the
 * cookie stops working.
 */
final class Responder implements Service {

	/**
	 * What this request is probing: id, user, kind, target, method.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $probe = null;

	/**
	 * Capability checks that failed, in order.
	 *
	 * @var array<int, array{requested: string, cap: string}>
	 */
	private array $failed = array();

	/**
	 * What each menu entry asks for: slug => capability.
	 *
	 * @var array<string, string>
	 */
	private array $menu_caps = array();

	/**
	 * Whether the refusal already happened.
	 *
	 * @var bool
	 */
	private bool $frozen = false;

	/**
	 * Registers the cheap check for a probe cookie.
	 */
	public function register(): void {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Only a signed, single-use probe cookie changes anything, and only for the user it names.
		if ( empty( $_COOKIE[ Prober::COOKIE ] ) || ! is_string( $_COOKIE[ Prober::COOKIE ] ) ) {
			return;
		}

		add_action( 'init', array( $this, 'arm' ), 1 );
	}

	/**
	 * Starts watching when the cookie is valid. Runs on `init`.
	 */
	public function arm(): void {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- See register().
		$value = isset( $_COOKIE[ Prober::COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ Prober::COOKIE ] ) ) : '';
		$probe = Prober::read( $value );
		if ( ! $probe || get_current_user_id() !== (int) $probe['user'] ) {
			return;
		}

		// Single use: a second request with the same cookie finds nothing.
		if ( ! Prober::claim( (string) $probe['id'] ) ) {
			return;
		}

		$this->probe = $probe;
		add_action( 'admin_menu', array( $this, 'menu' ), PHP_INT_MAX );
		add_action( 'admin_page_access_denied', array( $this, 'denied' ), 0 );
		add_filter( 'user_has_cap', array( $this, 'checked' ), PHP_INT_MAX, 4 );
		add_filter( 'map_meta_cap', array( $this, 'mapped' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_dispatch_request', array( $this, 'dry_run' ), 1, 2 );
		// The refusal is the moment to stop listening: checks made while the error page or response is built are not the cause.
		foreach ( array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_xml_handler', 'wp_die_jsonp_handler' ) as $hook ) {
			add_filter( $hook, array( $this, 'freeze' ), 0 );
		}
		add_filter( 'rest_request_after_callbacks', array( $this, 'freeze' ), 0 );
		add_action( 'shutdown', array( $this, 'save' ), 99 );
	}

	/**
	 * Remembers the capability every menu entry asks for, before WordPress takes away the entries the person may not see.
	 * That is where an admin screen is usually refused: the entry is gone, so the screen is too.
	 */
	public function menu(): void {
		global $menu, $submenu;

		foreach ( array( $menu, $submenu ) as $index => $entries ) {
			foreach ( is_array( $entries ) ? $entries : array() as $group ) {
				// A submenu is a list of lists; the top-level menu is a list of entries.
				foreach ( 1 === $index ? (array) $group : array( $group ) as $entry ) {
					if ( is_array( $entry ) && isset( $entry[1], $entry[2] ) && is_string( $entry[1] ) && is_string( $entry[2] ) && '' !== $entry[1] ) {
						$this->menu_caps[ $entry[2] ] = $entry[1];
					}
				}
			}
		}
	}

	/**
	 * A screen the menu refused: the capability its entry asks for is the one that is missing.
	 */
	public function denied(): void {
		global $pagenow, $plugin_page, $typenow;

		if ( ! $this->probe ) {
			return;
		}

		$file       = (string) $pagenow;
		$candidates = array();
		if ( isset( $plugin_page ) && '' !== (string) $plugin_page ) {
			$candidates[] = (string) $plugin_page;
		}
		if ( ! empty( $typenow ) ) {
			$candidates[] = $file . '?post_type=' . $typenow;
		}
		$candidates[] = $file;

		foreach ( $candidates as $slug ) {
			$cap = $this->menu_caps[ $slug ] ?? '';
			if ( '' !== $cap && ! user_can( (int) $this->probe['user'], $cap ) ) {
				$this->note( $cap, $cap );
				break;
			}
		}
		$this->freeze();
	}

	/**
	 * Stops noting checks. Used as a filter: hands back its value.
	 *
	 * @param mixed $value The filtered value.
	 * @return mixed
	 */
	public function freeze( $value = null ) {
		$this->frozen = true;

		return $value;
	}

	/**
	 * Notes a capability the user lacked.
	 *
	 * @param mixed $allcaps Capabilities the user has.
	 * @param mixed $caps    Primitive capabilities the check needs.
	 * @param mixed $args    Requested capability and its arguments.
	 * @param mixed $user    The user.
	 * @return mixed The capabilities, untouched.
	 */
	public function checked( $allcaps, $caps = array(), $args = array(), $user = null ) {
		if ( ! $this->probe || ! $user instanceof \WP_User || (int) $user->ID !== (int) $this->probe['user'] || ! is_array( $allcaps ) ) {
			return $allcaps;
		}

		$requested = is_array( $args ) && isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '';
		foreach ( array_filter( (array) $caps, 'is_string' ) as $cap ) {
			if ( empty( $allcaps[ $cap ] ) && 'do_not_allow' !== $cap ) {
				$this->note( $requested, $cap );
				break;
			}
		}

		return $allcaps;
	}

	/**
	 * Notes a check WordPress refuses outright (`do_not_allow`), such as editing a post that does not exist.
	 *
	 * @param mixed $caps    Primitive capabilities.
	 * @param mixed $cap     The requested capability.
	 * @param mixed $user_id The user.
	 * @return mixed The capabilities, untouched.
	 */
	public function mapped( $caps, $cap = '', $user_id = 0 ) {
		if ( $this->probe && (int) $user_id === (int) $this->probe['user'] && is_array( $caps ) && in_array( 'do_not_allow', $caps, true ) ) {
			$this->note( is_string( $cap ) ? $cap : '', 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Answers a REST request that passed its permission check without running it.
	 *
	 * @param mixed $result  Response so far, null to run the route.
	 * @param mixed $request The request.
	 * @return mixed
	 */
	public function dry_run( $result, $request = null ) {
		if ( null !== $result || 'rest' !== ( $this->probe['kind'] ?? '' ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'role_right_sizer_probe' => 'permitted',
			),
			200
		);
	}

	/**
	 * Saves what was seen for whoever asked. Runs on `shutdown`.
	 */
	public function save(): void {
		if ( $this->probe ) {
			Prober::store_seen( (string) $this->probe['id'], $this->failed );
		}
	}

	/**
	 * Keeps one failed check.
	 *
	 * @param string $requested The capability that was asked for.
	 * @param string $cap       The capability that was missing.
	 */
	private function note( string $requested, string $cap ): void {
		if ( $this->frozen ) {
			return;
		}

		// A repeated check moves to the end, so the last entry is always the latest refusal.
		$this->failed   = array_values(
			array_filter(
				$this->failed,
				static function ( array $seen ) use ( $requested, $cap ): bool {
					return $seen['requested'] !== $requested || $seen['cap'] !== $cap;
				}
			)
		);
		$this->failed[] = array(
			'requested' => $requested,
			'cap'       => $cap,
		);
		$this->failed   = array_slice( $this->failed, -20 );
	}
}
