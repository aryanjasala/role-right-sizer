<?php
/**
 * Counts an admin screen visit as use of the capability its menu entry needs.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Usage;

use AryanX\Axle\RoleRightSizer\Service;
use AryanX\Axle\RoleRightSizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Opening Users or Settings > General proves `list_users` or `manage_options`
 * was used, the way core decides who may open the screen.
 */
final class AdminScreens implements Service {

	/**
	 * Records capability use.
	 *
	 * @var Recorder
	 */
	private Recorder $recorder;

	/**
	 * Sets up the watcher.
	 *
	 * @param Recorder $recorder Records capability use.
	 */
	public function __construct( Recorder $recorder ) {
		$this->recorder = $recorder;
	}

	/**
	 * Watches admin screens as they load.
	 */
	public function register(): void {
		add_action( 'current_screen', array( $this, 'visit' ) );
	}

	/**
	 * Records the capability the current screen needs.
	 *
	 * @param mixed $screen The current screen.
	 */
	public function visit( $screen = null ): void {
		global $pagenow, $plugin_page, $menu, $submenu;

		if ( wp_doing_ajax() || ! function_exists( 'get_admin_page_parent' ) ) {
			return;
		}

		$cap = self::required_capability(
			(string) get_admin_page_parent(),
			(string) $pagenow,
			isset( $plugin_page ) ? (string) $plugin_page : null,
			is_array( $menu ) ? $menu : array(),
			is_array( $submenu ) ? $submenu : array()
		);

		/**
		 * Filters the capability an admin screen visit counts as.
		 *
		 * @param string          $cap    Capability from the screen's menu entry, or '' when the screen has none.
		 * @param \WP_Screen|null $screen The screen.
		 */
		$cap = Settings::text( apply_filters( 'role_right_sizer_screen_capability', $cap, $screen instanceof \WP_Screen ? $screen : null ), '' );

		if ( '' !== $cap ) {
			$this->recorder->record( $cap, 0, 'screen' );
		}
	}

	/**
	 * The capability the admin menu asks for on a screen, the way
	 * `user_can_access_admin_page()` looks it up.
	 *
	 * @param string      $parent_page Parent menu file from `get_admin_page_parent()`.
	 * @param string      $pagenow     Current admin file, such as `edit.php`.
	 * @param string|null $plugin_page Plugin page slug, if any.
	 * @param array       $menu        Top-level menu.
	 * @param array       $submenu     Submenus by parent.
	 * @return string Capability, or '' when no menu entry matches.
	 */
	public static function required_capability( string $parent_page, string $pagenow, ?string $plugin_page, array $menu, array $submenu ): string {
		if ( '' === $parent_page ) {
			$parent_page = null !== $plugin_page ? $plugin_page : $pagenow;
		}

		foreach ( (array) ( $submenu[ $parent_page ] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || ! isset( $item[1], $item[2] ) ) {
				continue;
			}
			if ( ( null !== $plugin_page && $item[2] === $plugin_page ) || ( null === $plugin_page && $item[2] === $pagenow ) ) {
				return (string) $item[1];
			}
		}

		foreach ( $menu as $item ) {
			if ( is_array( $item ) && isset( $item[1], $item[2] ) && $item[2] === $parent_page ) {
				return (string) $item[1];
			}
		}

		return '';
	}
}
