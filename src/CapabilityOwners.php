<?php
/**
 * Who a capability on a role belongs to.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer;

defined( 'ABSPATH' ) || exit;

/**
 * Tells, where it can, which plugin a capability belongs to and whether that
 * plugin is active. In this order:
 *
 * 1. The plugin that added it to the role, from the capability history.
 * 2. A registered post type or taxonomy that checks it (WooCommerce's
 *    `edit_shop_orders`, for example): something active uses it.
 * 3. An active plugin whose folder name is part of the capability name
 *    (`manage_woocommerce`, `agent_governance_approve`).
 *
 * Capabilities of an active plugin are still listed, but marked, and
 * `wp role-right-sizer apply --all` leaves them out unless asked.
 *
 * An owner is an array: `type` (`plugin`, `post_type`, `taxonomy` or anything a
 * filter sets), `id`, `name` (for people) and `active`.
 */
final class CapabilityOwners {

	/**
	 * Installed plugins by folder: `folder => [ basename, name, active ]`.
	 *
	 * @var array<string, array{0: string, 1: string, 2: bool}>|null
	 */
	private ?array $plugins = null;

	/**
	 * Capabilities checked by registered post types and taxonomies: `cap => owner`.
	 *
	 * @var array<string, array>|null
	 */
	private ?array $content = null;

	/**
	 * Who a capability on a role belongs to.
	 *
	 * @param string $role     Role name.
	 * @param string $cap      Capability.
	 * @param string $added_by Plugin folder from the capability history, '' when unknown.
	 * @return array{type: string, id: string, name: string, active: bool}|null Null when it cannot be told.
	 */
	public function owner( string $role, string $cap, string $added_by = '' ): ?array {
		$owner = '' !== $added_by ? $this->plugin( $added_by ) : null;
		if ( null === $owner ) {
			$owner = $this->content_type( $cap );
		}
		if ( null === $owner ) {
			$owner = $this->by_name( $cap );
		}

		/**
		 * Filters who a capability on a role belongs to.
		 *
		 * Return an array with `name` (shown to people), `active` (whether the
		 * owner is in use; active owners are marked and left out of
		 * `apply --all`), and optionally `type` and `id`. Return null when it
		 * cannot be told.
		 *
		 * @param array|null $owner Who Role Right-Sizer thinks it belongs to.
		 * @param string     $cap   Capability.
		 * @param string     $role  Role name.
		 */
		return self::normalize( apply_filters( 'role_right_sizer_capability_owner', $owner, $cap, $role ) );
	}

	/**
	 * A filtered owner, or null.
	 *
	 * @param mixed $owner Filtered value.
	 * @return array{type: string, id: string, name: string, active: bool}|null
	 */
	public static function normalize( $owner ): ?array {
		if ( ! is_array( $owner ) || ! isset( $owner['name'] ) || ! is_string( $owner['name'] ) || '' === $owner['name'] ) {
			return null;
		}

		return array(
			'type'   => is_string( $owner['type'] ?? null ) && '' !== $owner['type'] ? $owner['type'] : 'plugin',
			'id'     => is_scalar( $owner['id'] ?? null ) ? (string) $owner['id'] : '',
			'name'   => $owner['name'],
			'active' => ! empty( $owner['active'] ),
		);
	}

	/**
	 * The key that groups capabilities of the same owner.
	 *
	 * @param array|null $owner Owner.
	 * @return string `type:id`, or '' for unknown.
	 */
	public static function key( ?array $owner ): string {
		return $owner ? $owner['type'] . ':' . ( '' !== $owner['id'] ? $owner['id'] : $owner['name'] ) : '';
	}

	/**
	 * An installed plugin by folder.
	 *
	 * @param string $folder Folder, or file name of a single-file plugin.
	 * @return array
	 */
	private function plugin( string $folder ): array {
		$plugins = $this->plugins();
		if ( isset( $plugins[ $folder ] ) ) {
			return array(
				'type'   => 'plugin',
				'id'     => $plugins[ $folder ][0],
				'name'   => $plugins[ $folder ][1],
				'active' => $plugins[ $folder ][2],
			);
		}

		// The plugin that added it is not installed any more.
		return array(
			'type'   => 'plugin',
			'id'     => $folder,
			'name'   => $folder,
			'active' => false,
		);
	}

	/**
	 * A registered post type or taxonomy that checks the capability.
	 *
	 * @param string $cap Capability.
	 * @return array|null
	 */
	private function content_type( string $cap ): ?array {
		if ( null === $this->content ) {
			$this->content = array();
			foreach ( array(
				'post_type' => function_exists( 'get_post_types' ) ? get_post_types( array(), 'objects' ) : array(),
				'taxonomy'  => function_exists( 'get_taxonomies' ) ? get_taxonomies( array(), 'objects' ) : array(),
			) as $type => $objects ) {
				foreach ( (array) $objects as $name => $object ) {
					$label = '' !== (string) $object->label ? (string) $object->label : (string) $name;
					foreach ( (array) $object->cap as $mapped ) {
						if ( is_string( $mapped ) && ! isset( $this->content[ $mapped ] ) ) {
							$this->content[ $mapped ] = array(
								'type'   => $type,
								'id'     => (string) $name,
								'name'   => $label,
								'active' => true,
							);
						}
					}
				}
			}
		}

		return $this->content[ $cap ] ?? null;
	}

	/**
	 * An active plugin whose folder name is part of the capability name.
	 *
	 * @param string $cap Capability.
	 * @return array|null
	 */
	private function by_name( string $cap ): ?array {
		$cap = '_' . strtolower( $cap ) . '_';
		foreach ( $this->plugins() as $folder => $plugin ) {
			$slug = str_replace( '-', '_', strtolower( preg_replace( '/\.php$/', '', (string) $folder ) ) );
			if ( $plugin[2] && strlen( $slug ) >= 4 && false !== strpos( $cap, '_' . $slug . '_' ) ) {
				return array(
					'type'   => 'plugin',
					'id'     => $plugin[0],
					'name'   => $plugin[1],
					'active' => true,
				);
			}
		}

		return null;
	}

	/**
	 * Installed plugins by folder, with their name and whether they are active.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	private function plugins(): array {
		if ( null !== $this->plugins ) {
			return $this->plugins;
		}

		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$active = array_map( 'strval', $active );

		$this->plugins = array();
		foreach ( function_exists( 'get_plugins' ) ? (array) get_plugins() : array() as $basename => $data ) {
			$basename = (string) $basename;
			$folder   = false === strpos( $basename, '/' ) ? $basename : dirname( $basename );
			$name     = ! empty( $data['Name'] ) ? (string) $data['Name'] : $folder;
			$is_on    = in_array( $basename, $active, true );
			if ( ! isset( $this->plugins[ $folder ] ) || $is_on ) {
				$this->plugins[ $folder ] = array( $basename, $name, $is_on );
			}
		}

		return $this->plugins;
	}
}
