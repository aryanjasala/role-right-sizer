<?php
/**
 * Base class for Role Right-Sizer's unit tests: a small site with core's roles,
 * users and user meta kept in memory.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Tests\Unit;

use AryanX\Axle\RoleRightSizer\CapabilityHistory;
use AryanX\Axle\RoleRightSizer\Settings;
use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/Doubles/wordpress.php';

/**
 * Users, roles and user meta in memory.
 */
abstract class PluginTestCase extends TestCase {

	/**
	 * Roles by name.
	 *
	 * @var array<string, \WP_Role>
	 */
	protected $roles = array();

	/**
	 * Users by ID.
	 *
	 * @var array<int, \WP_User>
	 */
	protected $users = array();

	/**
	 * User options and meta: `user_id => [ key => value ]`. Options get a `wp_` prefix, like on a single site.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected $meta = array();

	/**
	 * The logged-in user.
	 *
	 * @var int
	 */
	protected $current_user = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->roles        = array();
		$this->users        = array();
		$this->meta         = array();
		$this->current_user = 0;
		\WP_User_Query::$users = array();
		\WP_User_Query::$made  = array();

		$levels = static function ( int $top ): array {
			$caps = array();
			for ( $level = 0; $level <= $top; $level++ ) {
				$caps[] = 'level_' . $level;
			}
			return $caps;
		};

		$subscriber  = array_merge( array( 'read' ), $levels( 0 ) );
		$contributor = array_merge( array( 'read', 'edit_posts', 'delete_posts' ), $levels( 1 ) );
		$author      = array_merge( array( 'read', 'edit_posts', 'delete_posts', 'upload_files', 'edit_published_posts', 'publish_posts', 'delete_published_posts' ), $levels( 2 ) );
		$editor      = array_merge( array_diff( $author, $levels( 2 ) ), array( 'moderate_comments', 'manage_categories', 'manage_links', 'unfiltered_html', 'edit_others_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_others_posts', 'delete_private_posts', 'edit_private_posts', 'read_private_posts', 'delete_private_pages', 'edit_private_pages', 'read_private_pages' ), $levels( 7 ) );
		$admin       = array_merge( array_diff( $editor, $levels( 7 ) ), array( 'switch_themes', 'edit_themes', 'activate_plugins', 'edit_plugins', 'edit_users', 'edit_files', 'manage_options', 'import', 'export', 'list_users', 'remove_users', 'promote_users', 'create_users', 'delete_users', 'edit_theme_options', 'install_plugins', 'update_plugins', 'delete_plugins', 'install_themes', 'update_themes', 'delete_themes', 'update_core', 'edit_dashboard' ), $levels( 10 ) );

		foreach ( array(
			'administrator' => $admin,
			'editor'        => $editor,
			'author'        => $author,
			'contributor'   => $contributor,
			'subscriber'    => $subscriber,
		) as $name => $caps ) {
			$this->add_role( $name, $caps );
		}

		Functions\when( 'wp_roles' )->alias(
			function () {
				$roles = array();
				foreach ( $this->roles as $name => $role ) {
					$roles[ $name ] = array(
						'name'         => ucfirst( $name ),
						'capabilities' => $role->capabilities,
					);
				}
				return (object) array( 'roles' => $roles );
			}
		);
		Functions\when( 'get_role' )->alias(
			function ( $name ) {
				return $this->roles[ $name ] ?? null;
			}
		);
		Functions\when( 'get_userdata' )->alias(
			function ( $id ) {
				return $this->users[ (int) $id ] ?? false;
			}
		);
		Functions\when( 'get_current_user_id' )->alias(
			function () {
				return $this->current_user;
			}
		);
		Functions\when( 'user_can' )->alias(
			function ( $user, $cap ) {
				$user = $user instanceof \WP_User ? $user : ( $this->users[ (int) $user ] ?? null );
				return $user && in_array( $cap, $this->caps_of( $user ), true );
			}
		);
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				$user = $this->users[ $this->current_user ] ?? null;
				return $user && ( in_array( $cap, $this->caps_of( $user ), true ) || ( 'promote_user' === $cap && in_array( 'promote_users', $this->caps_of( $user ), true ) ) );
			}
		);
		Functions\when( 'get_users' )->alias(
			function ( array $args ) {
				$roles = (array) ( $args['role__in'] ?? array() );
				$found = array();
				foreach ( $this->users as $user ) {
					if ( ! $roles || array_intersect( $user->roles, $roles ) ) {
						$found[] = 'ID' === ( $args['fields'] ?? '' ) ? $user->ID : $user;
					}
				}
				return array_slice( $found, 0, (int) ( $args['number'] ?? 100 ) );
			}
		);
		Functions\when( 'get_user_option' )->alias(
			function ( $key, $user_id ) {
				return $this->meta[ (int) $user_id ][ 'wp_' . $key ] ?? false;
			}
		);
		Functions\when( 'update_user_option' )->alias(
			function ( $user_id, $key, $value ) {
				$this->meta[ (int) $user_id ][ 'wp_' . $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_user_option' )->alias(
			function ( $user_id, $key ) {
				unset( $this->meta[ (int) $user_id ][ 'wp_' . $key ] );
				return true;
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key = '', $single = false ) {
				return $this->meta[ (int) $user_id ][ $key ] ?? ( $single ? '' : array() );
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( $user_id, $key, $value ) {
				$this->meta[ (int) $user_id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->alias(
			function ( $user_id, $key ) {
				unset( $this->meta[ (int) $user_id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		// Nothing installed or registered unless a test says so.
		Functions\when( 'get_plugins' )->justReturn( array() );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_taxonomies' )->justReturn( array() );
		Functions\when( 'get_site_option' )->justReturn( array() );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
	}

	/**
	 * Adds a role.
	 *
	 * @param string   $name Role name.
	 * @param string[] $caps Granted capabilities.
	 */
	protected function add_role( string $name, array $caps ): void {
		$this->roles[ $name ] = new \WP_Role( $name, array_fill_keys( $caps, true ) );
	}

	/**
	 * Adds a user, registered long ago unless said otherwise.
	 *
	 * @param int      $id     User ID.
	 * @param string[] $roles  Roles.
	 * @param array    $fields Other fields.
	 * @return \WP_User
	 */
	protected function add_user( int $id, array $roles, array $fields = array() ): \WP_User {
		$user                 = new \WP_User(
			$id,
			$fields + array(
				'roles'           => $roles,
				'user_login'      => 'user' . $id,
				'display_name'    => 'User ' . $id,
				'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ),
			)
		);
		$this->users[ $id ]   = $user;
		\WP_User_Query::$users = array_values( $this->users );

		return $user;
	}

	/**
	 * Capabilities a user has through their roles.
	 *
	 * @param \WP_User $user The user.
	 * @return string[]
	 */
	protected function caps_of( \WP_User $user ): array {
		$caps = array();
		foreach ( $user->roles as $role ) {
			if ( isset( $this->roles[ $role ] ) ) {
				$caps = array_merge( $caps, array_keys( array_filter( $this->roles[ $role ]->capabilities ) ) );
			}
		}
		return $caps;
	}

	/**
	 * Starts tracking some days ago.
	 *
	 * @param int $days Days.
	 */
	protected function tracking_started( int $days ): void {
		$this->options[ Settings::STARTED ] = time() - $days * DAY_IN_SECONDS;
	}

	/**
	 * Saves usage for a user: `cap => days ago`.
	 *
	 * @param int                $user_id User ID.
	 * @param array<string, int> $caps    Capability => days since last use.
	 */
	protected function used( int $user_id, array $caps ): void {
		$record = array();
		foreach ( $caps as $cap => $days ) {
			$time           = time() - $days * DAY_IN_SECONDS;
			$record[ $cap ] = array( $time, $time, 1 );
		}
		$this->meta[ $user_id ]['wp_role_right_sizer_usage'] = $record;
	}

	/**
	 * Says a role has had some capabilities for a number of days, in the capability history.
	 *
	 * @param string   $role   Role name.
	 * @param string[] $caps   Capabilities.
	 * @param int      $days   Days on the role.
	 * @param string   $folder Plugin folder that added them.
	 */
	protected function had( string $role, array $caps, int $days, string $folder = '' ): void {
		$history = $this->options[ CapabilityHistory::OPTION ] ?? array();
		foreach ( $caps as $cap ) {
			$history[ $role ][ $cap ] = array( time() - $days * DAY_IN_SECONDS, $folder );
		}
		$this->options[ CapabilityHistory::OPTION ] = $history;
	}

	/**
	 * Marks a user active some days ago.
	 *
	 * @param int $user_id User ID.
	 * @param int $days    Days ago.
	 */
	protected function active( int $user_id, int $days ): void {
		$this->meta[ $user_id ]['wp_role_right_sizer_last_active'] = time() - $days * DAY_IN_SECONDS;
	}
}
