<?php
/**
 * Who may see details about which user.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the user an action or a detail is about, not only the capability of the person asking.
 *
 * Someone who may open Right-Sizer can still lack the right to edit a given
 * account (on a multisite network, or through a plugin that limits who can
 * edit whom). They must not read that account's capabilities, usage or trial
 * through the screen, the CSV, REST or an ability.
 */
final class Access {

	/**
	 * Whether the current user may see capability details for an account.
	 *
	 * WP-CLI and cron have no current user and see everything.
	 *
	 * @param int $user_id The account.
	 * @return bool
	 */
	public static function can_view_user( int $user_id ): bool {
		if ( ! get_current_user_id() ) {
			$allowed = defined( 'WP_CLI' ) && WP_CLI;
		} else {
			$allowed = current_user_can( 'edit_user', $user_id );
		}

		/**
		 * Filters whether the current user may see what Role Right-Sizer recorded about an account.
		 *
		 * @param bool $allowed Default whether they may edit the account. WP-CLI always may.
		 * @param int  $user_id The account.
		 */
		return (bool) apply_filters( 'role_right_sizer_can_view_user', $allowed, $user_id );
	}

	/**
	 * Whether the current user may change an account's role.
	 *
	 * @param int $user_id The account.
	 * @return bool
	 */
	public static function can_manage_user( int $user_id ): bool {
		return ! get_current_user_id() || current_user_can( 'promote_user', $user_id );
	}

	/**
	 * Recommendations, kept to the accounts the current user may see.
	 *
	 * @param array[] $recommendations User recommendations, each with `user_id`.
	 * @return array[]
	 */
	public static function visible_recommendations( array $recommendations ): array {
		return array_values(
			array_filter(
				$recommendations,
				static function ( $recommendation ): bool {
					return self::can_view_user( (int) ( $recommendation['user_id'] ?? 0 ) );
				}
			)
		);
	}
}
