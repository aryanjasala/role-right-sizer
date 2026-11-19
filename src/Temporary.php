<?php
/**
 * Roles and capabilities that are only on loan.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer;

defined( 'ABSPATH' ) || exit;

/**
 * Axle Elevate gives a person a role for a set time. That role is not part of
 * what the person is meant to have, so it must not count as an administrator
 * for the last-administrator guard, and what they did with it must not make
 * their normal role look too small.
 *
 * Elevate keeps its active elevations in the user meta `axle_elevate_active`.
 * This class reads them through Elevate's own class when it is loaded, and
 * from that meta key when it is not, so it works with either, or with neither.
 */
final class Temporary {

	public const META = 'axle_elevate_active';

	/**
	 * The active elevations of a user on this site: roles, bundles and super admin.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array{kind: string, role: string, expires: int}>
	 */
	public static function elevations( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$entries = null;
		if ( class_exists( '\AryanX\Axle\Elevate\Active' ) && is_callable( array( '\AryanX\Axle\Elevate\Active', 'here' ) ) ) {
			$entries = call_user_func( array( '\AryanX\Axle\Elevate\Active', 'here' ), $user_id );
		}
		if ( ! is_array( $entries ) ) {
			$entries = self::stored( $user_id );
		}

		$now  = time();
		$list = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$expires = (int) ( $entry['expires'] ?? 0 );
			if ( $expires > 0 && $expires < $now ) {
				// Waiting for Elevate to take it away.
				continue;
			}
			$kind   = in_array( $entry['kind'] ?? '', array( 'super_admin', 'bundle' ), true ) ? (string) $entry['kind'] : 'role';
			$list[] = array(
				'kind'    => $kind,
				'role'    => (string) ( $entry['role'] ?? '' ),
				'expires' => $expires,
			);
		}

		/**
		 * Filters the temporary elevations Role Right-Sizer knows about for a user.
		 *
		 * Another plugin that lends roles for a time can add its own entries.
		 *
		 * @param array $list    Entries with `kind` (`role`, `bundle` or `super_admin`), `role` and `expires` (Unix time, 0 for no end).
		 * @param int   $user_id User ID.
		 */
		$filtered = apply_filters( 'role_right_sizer_temporary_access', $list, $user_id );

		$filtered = Settings::maybe_array( $filtered );

		return null !== $filtered ? self::clean( $filtered ) : $list;
	}

	/**
	 * Entries of the meta key, for this site, when Elevate itself is not loaded.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function stored( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$list = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
				continue;
			}
			if ( 'super_admin' === ( $entry['kind'] ?? '' ) || (int) ( $entry['blog'] ?? 0 ) === $blog ) {
				$list[] = $entry;
			}
		}

		return $list;
	}

	/**
	 * A filtered list of entries, with anything malformed dropped.
	 *
	 * @param array $entries Filtered entries.
	 * @return array<int, array{kind: string, role: string, expires: int}>
	 */
	private static function clean( array $entries ): array {
		$list = array();
		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) ) {
				$list[] = array(
					'kind'    => in_array( $entry['kind'] ?? '', array( 'super_admin', 'bundle' ), true ) ? (string) $entry['kind'] : 'role',
					'role'    => sanitize_key( (string) ( $entry['role'] ?? '' ) ),
					'expires' => max( 0, (int) ( $entry['expires'] ?? 0 ) ),
				);
			}
		}

		return $list;
	}

	/**
	 * Whether the user has any temporary access right now.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_elevated( int $user_id ): bool {
		return (bool) self::elevations( $user_id );
	}

	/**
	 * The roles the user holds only for now.
	 *
	 * @param int $user_id User ID.
	 * @return string[]
	 */
	public static function roles( int $user_id ): array {
		$roles = array();
		foreach ( self::elevations( $user_id ) as $entry ) {
			if ( 'role' === $entry['kind'] && '' !== $entry['role'] ) {
				$roles[] = $entry['role'];
			}
		}

		return array_values( array_unique( $roles ) );
	}

	/**
	 * The roles a user has for good: every role except the ones on loan.
	 *
	 * @param \WP_User $user The user.
	 * @return string[]
	 */
	public static function permanent_roles( \WP_User $user ): array {
		return array_values( array_diff( array_map( 'strval', (array) $user->roles ), self::roles( (int) $user->ID ) ) );
	}

	/**
	 * Capabilities the user has for good: from permanent roles and from capabilities set on the account.
	 *
	 * @param \WP_User $user The user.
	 * @return string[]
	 */
	public static function permanent_caps( \WP_User $user ): array {
		$roles = Recommender::role_caps();
		$caps  = array();
		foreach ( self::permanent_roles( $user ) as $role ) {
			$caps = array_merge( $caps, $roles[ $role ] ?? array() );
		}
		foreach ( (array) $user->caps as $cap => $grant ) {
			if ( $grant && ! isset( $roles[ (string) $cap ] ) ) {
				$caps[] = (string) $cap;
			}
		}

		return array_values( array_unique( $caps ) );
	}
}
