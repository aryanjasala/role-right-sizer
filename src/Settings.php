<?php
/**
 * Settings, tracking start and the numbers every part of the plugin shares.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer;

defined( 'ABSPATH' ) || exit;

/**
 * The saved settings, when tracking began, and the filtered limits.
 */
final class Settings {

	public const OPTION  = 'role_right_sizer_settings';
	public const STARTED = 'role_right_sizer_tracking_started';

	/**
	 * Settings with their defaults.
	 *
	 * @return array{dormant_action: string, fallback_role: string, excluded_users: int[], excluded_roles: string[]}
	 */
	public function all(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'dormant_action' => 'fallback' === ( $saved['dormant_action'] ?? '' ) ? 'fallback' : 'remove',
			'fallback_role'  => sanitize_key( (string) ( $saved['fallback_role'] ?? 'subscriber' ) ),
			'excluded_users' => array_values( array_filter( array_map( 'absint', (array) ( $saved['excluded_users'] ?? array() ) ) ) ),
			'excluded_roles' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $saved['excluded_roles'] ?? array() ) ) ) ),
		);
	}

	/**
	 * Saves the settings from the screen or WP-CLI.
	 *
	 * @param array $values dormant_action, fallback_role, excluded_users, excluded_roles.
	 */
	public function save( array $values ): void {
		$current = $this->all();
		$roles   = array_keys( wp_roles()->roles );

		$settings = array(
			'dormant_action' => 'fallback' === ( $values['dormant_action'] ?? $current['dormant_action'] ) ? 'fallback' : 'remove',
			'fallback_role'  => sanitize_key( (string) ( $values['fallback_role'] ?? $current['fallback_role'] ) ),
			'excluded_users' => array_values( array_unique( array_filter( array_map( 'absint', (array) ( $values['excluded_users'] ?? $current['excluded_users'] ) ) ) ) ),
			'excluded_roles' => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $values['excluded_roles'] ?? $current['excluded_roles'] ) ), $roles ) ),
		);

		if ( ! in_array( $settings['fallback_role'], $roles, true ) ) {
			$settings['fallback_role'] = '';
		}

		update_option( self::OPTION, $settings, false );
	}

	/**
	 * When usage tracking began on this site. Starts it now if it never did.
	 *
	 * @return int Unix time.
	 */
	public function tracking_started(): int {
		$started = (int) get_option( self::STARTED, 0 );
		if ( $started > 0 ) {
			return $started;
		}

		$started = time();
		add_option( self::STARTED, $started );

		return $started;
	}

	/**
	 * Days usage is recorded before recommendations show up.
	 *
	 * @return int
	 */
	public function learning_days(): int {
		/**
		 * Filters how many days usage is recorded before recommendations show up.
		 *
		 * @param int $days Default 30. 0 shows recommendations straight away.
		 */
		return self::clamp( apply_filters( 'role_right_sizer_learning_days', 30 ), 30, 0, 3650 );
	}

	/**
	 * When the learning period ends.
	 *
	 * @return int Unix time.
	 */
	public function learning_ends(): int {
		return $this->tracking_started() + $this->learning_days() * DAY_IN_SECONDS;
	}

	/**
	 * Whether recommendations are still hidden.
	 *
	 * @param int $now Unix time.
	 * @return bool
	 */
	public function is_learning( int $now = 0 ): bool {
		return ( $now ? $now : time() ) < $this->learning_ends();
	}

	/**
	 * Days a capability counts as used after its last use.
	 *
	 * @return int
	 */
	public function window_days(): int {
		/**
		 * Filters how many days back a capability counts as used.
		 *
		 * @param int $days Default 90.
		 */
		return self::clamp( apply_filters( 'role_right_sizer_window_days', 90 ), 90, 1, 3650 );
	}

	/**
	 * Days without activity before an account counts as dormant.
	 *
	 * @return int
	 */
	public function dormant_days(): int {
		/**
		 * Filters how many days without activity make an account dormant.
		 *
		 * @param int $days Default 90.
		 */
		return self::clamp( apply_filters( 'role_right_sizer_dormant_days', 90 ), 90, 1, 3650 );
	}

	/**
	 * Seconds between two writes of the same capability for the same user.
	 *
	 * @return int
	 */
	public function record_interval(): int {
		/**
		 * Filters how often the same capability is written for the same user.
		 *
		 * @param int $seconds Default one hour. 0 writes on every use.
		 */
		return self::clamp( apply_filters( 'role_right_sizer_record_interval', HOUR_IN_SECONDS ), HOUR_IN_SECONDS, 0, WEEK_IN_SECONDS );
	}

	/**
	 * Capabilities never counted as used, lost or unused.
	 *
	 * @return string[]
	 */
	public function ignored_capabilities(): array {
		$ignored = array( 'exist' );
		for ( $level = 0; $level <= 10; $level++ ) {
			$ignored[] = 'level_' . $level;
		}

		/**
		 * Filters the capabilities Role Right-Sizer leaves out of its numbers.
		 *
		 * `read` is not on the list: a role without it cannot open wp-admin, so it
		 * counts. Roles a recommendation picks always keep `read` when the user has it.
		 *
		 * @param string[] $capabilities Default `exist` and the old `level_0` to `level_10`.
		 */
		return self::strings( apply_filters( 'role_right_sizer_ignored_capabilities', $ignored ) );
	}

	/**
	 * Users never to touch.
	 *
	 * @return int[]
	 */
	public function excluded_users(): array {
		/**
		 * Filters the users Role Right-Sizer never recommends a change for.
		 *
		 * @param int[] $user_ids Default the users picked on the settings screen.
		 */
		return self::ids( apply_filters( 'role_right_sizer_excluded_users', $this->all()['excluded_users'] ) );
	}

	/**
	 * Roles never to touch.
	 *
	 * @return string[]
	 */
	public function excluded_roles(): array {
		/**
		 * Filters the roles Role Right-Sizer never touches: users with one of these
		 * roles keep it, and the role keeps all its capabilities.
		 *
		 * @param string[] $roles Default the roles picked on the settings screen.
		 */
		return self::strings( apply_filters( 'role_right_sizer_excluded_roles', $this->all()['excluded_roles'] ) );
	}

	/**
	 * What to do with a dormant account.
	 *
	 * @param \WP_User $user The account.
	 * @return string `remove` (no role on this site) or `fallback`.
	 */
	public function dormant_action( \WP_User $user ): string {
		/**
		 * Filters what Role Right-Sizer recommends for a dormant account.
		 *
		 * @param string   $action `remove` takes every role away on this site, `fallback` moves the account to the fallback role.
		 * @param \WP_User $user   The account.
		 */
		$action = apply_filters( 'role_right_sizer_dormant_action', $this->all()['dormant_action'], $user );

		return 'fallback' === $action ? 'fallback' : 'remove';
	}

	/**
	 * The role dormant accounts move to when the action is `fallback`.
	 *
	 * @param \WP_User $user The account.
	 * @return string Role name, or '' for no role.
	 */
	public function fallback_role( \WP_User $user ): string {
		/**
		 * Filters the role dormant accounts move to.
		 *
		 * @param string   $role Default the role picked on the settings screen, `subscriber` out of the box.
		 * @param \WP_User $user The account.
		 */
		$role = self::text( apply_filters( 'role_right_sizer_fallback_role', $this->all()['fallback_role'], $user ), '' );

		return '' !== $role && null !== get_role( $role ) ? $role : '';
	}

	/**
	 * A filtered number, back inside its range.
	 *
	 * @param mixed $value    Filtered value.
	 * @param int   $fallback Used when the value is not a number.
	 * @param int   $min      Lowest allowed.
	 * @param int   $max      Highest allowed.
	 * @return int
	 */
	public static function clamp( $value, int $fallback, int $min, int $max ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * A filtered string, or the fallback when it is not a non-empty string.
	 *
	 * @param mixed  $value    Filtered value.
	 * @param string $fallback Used otherwise.
	 * @return string
	 */
	public static function text( $value, string $fallback ): string {
		return is_string( $value ) && '' !== $value ? $value : $fallback;
	}

	/**
	 * A filtered list, as unique positive integers.
	 *
	 * @param mixed $value Filtered value.
	 * @return int[]
	 */
	public static function ids( $value ): array {
		return is_array( $value ) ? array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) ) : array();
	}

	/**
	 * A filtered list, as unique non-empty strings.
	 *
	 * @param mixed $value Filtered value.
	 * @return string[]
	 */
	public static function strings( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$strings = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$strings[] = $item;
			}
		}

		return array_values( array_unique( $strings ) );
	}
}
