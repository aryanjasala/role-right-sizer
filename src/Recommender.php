<?php
/**
 * Works out the smallest role each user needs.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer;

use AryanX\RoleRightSizer\Usage\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Turns recorded usage into recommendations.
 *
 * A recommendation for a user is an array:
 *
 * - `type`: `downgrade`, `dormant` (no activity for the dormant days) or
 *   `never` (no login since tracking began)
 * - `user_id`, `current` (roles now), `proposed` (role name, '' for no role)
 * - `lost`: capabilities the user would no longer have
 * - `used`: capabilities used in the window, `cap => last used`
 * - `last_active`, `last_login`
 *
 * A recommendation for a role is `type` `unused_cap`, with `role`, `cap` and
 * `users` (how many users have the role).
 */
final class Recommender {

	/**
	 * Settings and limits.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Recorded usage.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Sets up the recommender.
	 *
	 * @param Settings $settings Settings and limits.
	 * @param Store    $store    Recorded usage.
	 */
	public function __construct( Settings $settings, Store $store ) {
		$this->settings = $settings;
		$this->store    = $store;
	}

	/**
	 * Every role with its granted capabilities.
	 *
	 * @return array<string, string[]>
	 */
	public static function role_caps(): array {
		$roles = array();
		foreach ( wp_roles()->roles as $name => $role ) {
			$roles[ (string) $name ] = self::granted( (array) ( $role['capabilities'] ?? array() ) );
		}

		return $roles;
	}

	/**
	 * Names of the capabilities set to true.
	 *
	 * @param array $caps `cap => bool`.
	 * @return string[]
	 */
	public static function granted( array $caps ): array {
		$granted = array();
		foreach ( $caps as $cap => $grant ) {
			if ( $grant ) {
				$granted[] = (string) $cap;
			}
		}

		return $granted;
	}

	/**
	 * Roles that grant something beyond the ignored capabilities and `read`.
	 * Users with only other roles have nothing to right-size.
	 *
	 * @return string[]
	 */
	public function privileged_roles(): array {
		$skip = array_merge( $this->settings->ignored_capabilities(), array( 'read' ) );
		$list = array();
		foreach ( self::role_caps() as $name => $caps ) {
			if ( array_diff( $caps, $skip ) ) {
				$list[] = $name;
			}
		}

		return $list;
	}

	/**
	 * Roles that count as administrators: the ones that grant `manage_options`.
	 *
	 * @return string[]
	 */
	public static function admin_roles(): array {
		$list = array();
		foreach ( self::role_caps() as $name => $caps ) {
			if ( in_array( 'manage_options', $caps, true ) ) {
				$list[] = $name;
			}
		}

		return $list;
	}

	/**
	 * How many users hold an administrator role, counting at most two.
	 *
	 * @return int 0, 1 or 2.
	 */
	public static function admin_count(): int {
		$roles = self::admin_roles();
		if ( ! $roles ) {
			return 0;
		}

		return count(
			get_users(
				array(
					'role__in' => $roles,
					'number'   => 2,
					'fields'   => 'ID',
				)
			)
		);
	}

	/**
	 * Why a user is left alone, or '' when they are not.
	 *
	 * @param \WP_User $user The user.
	 * @return string `excluded`, `excluded_role`, `super_admin` or `no_role`.
	 */
	public function skip_reason( \WP_User $user ): string {
		if ( in_array( $user->ID, $this->settings->excluded_users(), true ) ) {
			return 'excluded';
		}
		if ( array_intersect( (array) $user->roles, $this->settings->excluded_roles() ) ) {
			return 'excluded_role';
		}
		if ( is_multisite() && is_super_admin( $user->ID ) ) {
			return 'super_admin';
		}
		if ( ! $user->roles ) {
			return 'no_role';
		}

		return '';
	}

	/**
	 * The recommendation for one user, or null.
	 *
	 * @param \WP_User $user        The user.
	 * @param int      $now         Unix time.
	 * @param int      $admin_count Users with an administrator role, from admin_count().
	 * @return array|null
	 */
	public function for_user( \WP_User $user, int $now, int $admin_count ): ?array {
		if ( $this->settings->is_learning( $now ) || '' !== $this->skip_reason( $user ) ) {
			return null;
		}

		$roles   = self::role_caps();
		$ignored = $this->settings->ignored_capabilities();
		$current = array_values( array_intersect( array_map( 'strval', (array) $user->roles ), array_keys( $roles ) ) );
		if ( ! $current ) {
			return null;
		}

		$have = array();
		foreach ( $current as $role ) {
			$have = array_merge( $have, $roles[ $role ] );
		}
		$have = array_values( array_unique( $have ) );
		if ( ! array_diff( $have, $ignored, array( 'read' ) ) ) {
			return null;
		}

		$last_active = $this->store->last_active( $user->ID );
		$last_login  = $this->store->last_login( $user->ID );
		$seen        = max( $last_active, $last_login );
		$registered  = (int) strtotime( (string) $user->user_registered . ' UTC' );
		$since       = max( $this->settings->tracking_started(), $registered );
		$used        = array_diff_key( $this->store->used_since( $user->ID, $now - $this->settings->window_days() * DAY_IN_SECONDS ), array_flip( $ignored ) );
		$used        = array_intersect_key( $used, array_flip( $have ) );

		$type = '';
		if ( ! $seen && $now - $since >= $this->settings->learning_days() * DAY_IN_SECONDS ) {
			$type = 'never';
		} elseif ( $seen && $now - $seen >= $this->settings->dormant_days() * DAY_IN_SECONDS ) {
			$type = 'dormant';
		}

		if ( $type ) {
			$proposed = 'fallback' === $this->settings->dormant_action( $user ) ? $this->settings->fallback_role( $user ) : '';
			if ( '' !== $proposed && array_diff( $roles[ $proposed ], $have ) ) {
				// The fallback role would give more than the user has now: take every role away instead.
				$proposed = '';
			}
		} elseif ( $seen ) {
			$type     = 'downgrade';
			$proposed = $this->smallest_role( array_keys( $used ), $have, $this->candidates( $user ), $roles );
			if ( null === $proposed || ( 1 === count( $current ) && $current[0] === $proposed ) || count( $roles[ $proposed ] ) >= count( $have ) ) {
				return null;
			}
		} else {
			return null;
		}

		$recommendation = array(
			'type'        => $type,
			'user_id'     => $user->ID,
			'current'     => $current,
			'proposed'    => $proposed,
			'lost'        => array(),
			'used'        => $used,
			'last_active' => $last_active,
			'last_login'  => $last_login,
		);

		/**
		 * Filters the recommendation for one user. Return null to drop it, or change
		 * `proposed` to recommend another role (the lost capabilities are worked out again).
		 *
		 * @param array    $recommendation The recommendation.
		 * @param \WP_User $user           The user.
		 */
		$recommendation = self::maybe_array( apply_filters( 'role_right_sizer_recommendation', $recommendation, $user ) );
		if ( null === $recommendation || ! isset( $recommendation['proposed'] ) || ! is_string( $recommendation['proposed'] ) ) {
			return null;
		}

		$proposed = $recommendation['proposed'];
		if ( '' !== $proposed && ! isset( $roles[ $proposed ] ) ) {
			return null;
		}
		if ( ( 1 === count( $current ) && $current[0] === $proposed ) ) {
			return null;
		}

		$keeps = '' === $proposed ? array() : $roles[ $proposed ];
		if ( $admin_count <= 1 && array_intersect( $current, self::admin_roles() ) && ! in_array( 'manage_options', $keeps, true ) ) {
			// The last administrator keeps their role.
			return null;
		}

		$recommendation['lost'] = self::lost( $have, $keeps, $ignored );
		if ( ! $recommendation['lost'] ) {
			return null;
		}

		return array(
			'type'        => in_array( $recommendation['type'], array( 'downgrade', 'dormant', 'never' ), true ) ? $recommendation['type'] : $type,
			'user_id'     => $user->ID,
			'current'     => $current,
			'proposed'    => $proposed,
			'lost'        => $recommendation['lost'],
			'used'        => array_map( 'intval', (array) $recommendation['used'] ),
			'last_active' => $last_active,
			'last_login'  => $last_login,
		);
	}

	/**
	 * A filtered value as an array, or null.
	 *
	 * @param mixed $value Filtered value.
	 * @return array|null
	 */
	private static function maybe_array( $value ): ?array {
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Roles a user could move to.
	 *
	 * @param \WP_User $user The user.
	 * @return string[]
	 */
	public function candidates( \WP_User $user ): array {
		/**
		 * Filters the roles Role Right-Sizer may recommend for a user.
		 *
		 * @param string[] $roles Role names. Default every role on the site.
		 * @param \WP_User $user  The user.
		 */
		return Settings::strings( apply_filters( 'role_right_sizer_candidate_roles', array_keys( wp_roles()->roles ), $user ) );
	}

	/**
	 * The smallest role that covers what was used and gives nothing new.
	 *
	 * Roles are ordered by how many capabilities they grant. Only roles whose
	 * capabilities are all ones the user already has count, so a recommendation
	 * never gives more access. A role without `read` never replaces one with it.
	 *
	 * @param string[]                $used       Capabilities used.
	 * @param string[]                $have       Capabilities the user's roles grant now.
	 * @param string[]                $candidates Role names allowed.
	 * @param array<string, string[]> $roles      Role name => granted capabilities.
	 * @return string|null Role name, or null when none fits.
	 */
	public static function smallest_role( array $used, array $have, array $candidates, array $roles ): ?string {
		$fits = array();
		foreach ( $candidates as $name ) {
			if ( ! isset( $roles[ $name ] ) ) {
				continue;
			}
			$caps = $roles[ $name ];
			if ( array_diff( $caps, $have ) || array_diff( $used, $caps ) || ( in_array( 'read', $have, true ) && ! in_array( 'read', $caps, true ) ) ) {
				continue;
			}
			$fits[ $name ] = count( $caps );
		}

		if ( ! $fits ) {
			return null;
		}

		// Stable: equal sizes keep the site's role order.
		$order = array_flip( array_keys( $fits ) );
		uksort(
			$fits,
			static function ( $a, $b ) use ( $fits, $order ) {
				return array( $fits[ $a ], $order[ $a ] ) <=> array( $fits[ $b ], $order[ $b ] );
			}
		);

		return (string) array_key_first( $fits );
	}

	/**
	 * Capabilities lost when moving from one set to another.
	 *
	 * @param string[] $have    Capabilities now.
	 * @param string[] $keeps   Capabilities after.
	 * @param string[] $ignored Capabilities left out.
	 * @return string[]
	 */
	public static function lost( array $have, array $keeps, array $ignored ): array {
		$lost = array_values( array_diff( $have, $keeps, $ignored ) );
		sort( $lost );

		return $lost;
	}

	/**
	 * Capabilities on core's own roles. They are never suggested for removal
	 * from a role: the user recommendations deal with too much core access.
	 *
	 * @return string[]
	 */
	public static function core_capabilities(): array {
		$core = array(
			'activate_plugins',
			'create_users',
			'customize',
			'delete_others_pages',
			'delete_others_posts',
			'delete_pages',
			'delete_plugins',
			'delete_posts',
			'delete_private_pages',
			'delete_private_posts',
			'delete_published_pages',
			'delete_published_posts',
			'delete_site',
			'delete_themes',
			'delete_users',
			'edit_dashboard',
			'edit_files',
			'edit_others_pages',
			'edit_others_posts',
			'edit_pages',
			'edit_plugins',
			'edit_posts',
			'edit_private_pages',
			'edit_private_posts',
			'edit_published_pages',
			'edit_published_posts',
			'edit_theme_options',
			'edit_themes',
			'edit_users',
			'export',
			'import',
			'install_languages',
			'install_plugins',
			'install_themes',
			'list_users',
			'manage_categories',
			'manage_links',
			'manage_options',
			'moderate_comments',
			'promote_users',
			'publish_pages',
			'publish_posts',
			'read',
			'read_private_pages',
			'read_private_posts',
			'remove_users',
			'resume_plugins',
			'resume_themes',
			'switch_themes',
			'unfiltered_html',
			'unfiltered_upload',
			'update_core',
			'update_languages',
			'update_plugins',
			'update_themes',
			'upload_files',
			'view_site_health_checks',
		);

		/**
		 * Filters the capabilities never suggested for removal from a role.
		 *
		 * @param string[] $capabilities Default every capability core gives its own roles.
		 */
		return Settings::strings( apply_filters( 'role_right_sizer_core_capabilities', $core ) );
	}

	/**
	 * Capabilities a role grants that none of its users used.
	 *
	 * @param array<string, array<string, int>> $role_usage `role => [ cap => last used ]`, from every user with the role.
	 * @param array<string, int>                $role_users `role => number of users`.
	 * @param array<string, string[]>           $roles      Role name => granted capabilities.
	 * @param string[]                          $skip       Capabilities never suggested: ignored and core ones.
	 * @param string[]                          $excluded   Roles never touched.
	 * @return array[] One `unused_cap` recommendation per role and capability.
	 */
	public static function unused_role_caps( array $role_usage, array $role_users, array $roles, array $skip, array $excluded ): array {
		$found = array();
		foreach ( $role_users as $role => $count ) {
			if ( $count < 1 || ! isset( $roles[ $role ] ) || in_array( $role, $excluded, true ) ) {
				continue;
			}
			foreach ( array_diff( $roles[ $role ], $skip ) as $cap ) {
				if ( ! isset( $role_usage[ $role ][ $cap ] ) ) {
					$found[] = array(
						'type'  => 'unused_cap',
						'role'  => (string) $role,
						'cap'   => (string) $cap,
						'users' => (int) $count,
					);
				}
			}
		}

		return $found;
	}
}
