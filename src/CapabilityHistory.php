<?php
/**
 * When each role gained each capability, and which plugin added it.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer;

defined( 'ABSPATH' ) || exit;

/**
 * A capability only counts as unused once it has been on its role for the
 * whole usage window. A plugin installed last week never had the chance to be
 * used, so its capabilities are not "unused" yet.
 *
 * Kept in one option, `role => [ cap => [ first seen, plugin folder ] ]`. It is
 * updated whenever the roles option changes, which is also when the plugin
 * that added a capability can be told from the call stack, and brought up to
 * date again on every report build in case the roles changed some other way.
 *
 * Capabilities already on a role when tracking starts count from the start of
 * tracking. On a site where tracking began earlier (an older version of the
 * plugin, or the history was deleted), they count from the first time the
 * history saw them, because nobody can tell how long they were there before.
 */
final class CapabilityHistory implements Service {

	public const OPTION = 'role_right_sizer_capability_history';

	/**
	 * Settings and limits.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the history.
	 *
	 * @param Settings $settings Settings and limits.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Watches the roles option.
	 */
	public function register(): void {
		add_action( 'added_option', array( $this, 'option_added' ), 10, 2 );
		add_action( 'updated_option', array( $this, 'option_updated' ), 10, 3 );
	}

	/**
	 * Name of the option core keeps the roles of this site in.
	 *
	 * @return string
	 */
	public static function roles_option(): string {
		global $wpdb;

		return ( is_object( $wpdb ) && method_exists( $wpdb, 'get_blog_prefix' ) ? (string) $wpdb->get_blog_prefix() : 'wp_' ) . 'user_roles';
	}

	/**
	 * The roles option was created.
	 *
	 * @param mixed $option Option name.
	 * @param mixed $value  New value.
	 */
	public function option_added( $option, $value ): void {
		if ( self::roles_option() === $option ) {
			$this->changed( array(), is_array( $value ) ? $value : array() );
		}
	}

	/**
	 * The roles option changed.
	 *
	 * @param mixed $option Option name.
	 * @param mixed $old    Old value.
	 * @param mixed $value  New value.
	 */
	public function option_updated( $option, $old, $value ): void {
		if ( self::roles_option() === $option ) {
			$this->changed( is_array( $old ) ? $old : array(), is_array( $value ) ? $value : array() );
		}
	}

	/**
	 * Records what one change of the roles option added or took away.
	 *
	 * @param array $before Roles before, as core stores them.
	 * @param array $after  Roles after.
	 */
	public function changed( array $before, array $after ): void {
		$saved   = $this->saved();
		$history = null === $saved ? $this->baseline( self::granted_by_role( $before ) ) : $saved;
		$roles   = self::granted_by_role( $after );
		$now     = time();
		$caller  = null;

		foreach ( $roles as $role => $caps ) {
			foreach ( $caps as $cap ) {
				if ( ! isset( $history[ $role ][ $cap ] ) ) {
					// Worked out once per change, and only when something was added.
					$caller                   = $caller ?? self::caller();
					$history[ $role ][ $cap ] = array( $now, $caller );
				}
			}
		}

		$history = self::prune( $history, $roles );
		if ( $history !== $saved ) {
			$this->save( $history );
		}
	}

	/**
	 * The history, brought up to date with the roles as they are now.
	 *
	 * @return array<string, array<string, array{0: int, 1: string}>>
	 */
	public function current(): array {
		$saved   = $this->saved();
		$roles   = self::granted_by_role( (array) wp_roles()->roles );
		$history = null === $saved ? $this->baseline( $roles ) : $saved;
		$now     = time();

		foreach ( $roles as $role => $caps ) {
			foreach ( $caps as $cap ) {
				if ( ! isset( $history[ $role ][ $cap ] ) ) {
					$history[ $role ][ $cap ] = array( $now, '' );
				}
			}
		}

		$history = self::prune( $history, $roles );
		if ( $history !== $saved ) {
			$this->save( $history );
		}

		return $history;
	}

	/**
	 * When a role first had a capability, from a history.
	 *
	 * @param array  $history From current().
	 * @param string $role    Role name.
	 * @param string $cap     Capability.
	 * @return int Unix time, 0 when unknown.
	 */
	public static function first_seen( array $history, string $role, string $cap ): int {
		return (int) ( $history[ $role ][ $cap ][0] ?? 0 );
	}

	/**
	 * The plugin folder that added a capability to a role, from a history.
	 *
	 * @param array  $history From current().
	 * @param string $role    Role name.
	 * @param string $cap     Capability.
	 * @return string Folder name, '' when unknown.
	 */
	public static function added_by( array $history, string $role, string $cap ): string {
		return (string) ( $history[ $role ][ $cap ][1] ?? '' );
	}

	/**
	 * When the capabilities found on a first look count from.
	 *
	 * @return int Unix time: when tracking began, if that was in the last day, else now.
	 */
	public function baseline_time(): int {
		$started = $this->settings->tracking_started();
		$now     = time();

		return $now - $started <= DAY_IN_SECONDS ? $started : $now;
	}

	/**
	 * A first history from the roles as they are.
	 *
	 * @param array<string, string[]> $roles Role => granted capabilities.
	 * @return array<string, array<string, array{0: int, 1: string}>>
	 */
	private function baseline( array $roles ): array {
		$time    = $this->baseline_time();
		$history = array();
		foreach ( $roles as $role => $caps ) {
			foreach ( $caps as $cap ) {
				$history[ $role ][ $cap ] = array( $time, '' );
			}
		}

		return $history;
	}

	/**
	 * Granted capabilities per role, from the roles option.
	 *
	 * @param array $roles `role => [ name, capabilities ]`.
	 * @return array<string, string[]>
	 */
	public static function granted_by_role( array $roles ): array {
		$granted = array();
		foreach ( $roles as $name => $role ) {
			$granted[ (string) $name ] = Recommender::granted( is_array( $role ) ? (array) ( $role['capabilities'] ?? array() ) : array() );
		}

		return $granted;
	}

	/**
	 * Drops roles and capabilities that are gone, so a capability added back later counts as new.
	 *
	 * @param array                   $history History.
	 * @param array<string, string[]> $roles   Role => granted capabilities now.
	 * @return array
	 */
	private static function prune( array $history, array $roles ): array {
		$kept = array();
		foreach ( $roles as $role => $caps ) {
			foreach ( $caps as $cap ) {
				if ( isset( $history[ $role ][ $cap ] ) ) {
					$kept[ $role ][ $cap ] = array( (int) $history[ $role ][ $cap ][0], (string) ( $history[ $role ][ $cap ][1] ?? '' ) );
				}
			}
		}

		return $kept;
	}

	/**
	 * The saved history, or null when there is none yet.
	 *
	 * @return array|null
	 */
	private function saved(): ?array {
		$saved = get_option( self::OPTION, null );

		return is_array( $saved ) ? $saved : null;
	}

	/**
	 * Saves the history. Not autoloaded: only the report and role changes read it.
	 *
	 * @param array $history History.
	 */
	private function save( array $history ): void {
		update_option( self::OPTION, $history, false );
	}

	/**
	 * The plugin folder in the call stack, other than this plugin's own.
	 *
	 * @return string Folder name, or the file name of a single-file plugin. '' when no plugin is in the stack.
	 */
	public static function caller(): string {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return '';
		}

		$base = self::path( WP_PLUGIN_DIR ) . '/';
		$own  = basename( dirname( __DIR__ ) );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Tells which plugin added a capability to a role. Runs only when the roles change, without arguments.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			$file = isset( $frame['file'] ) ? self::path( (string) $frame['file'] ) : '';
			if ( '' === $file || 0 !== strpos( $file, $base ) ) {
				continue;
			}
			$parts  = explode( '/', substr( $file, strlen( $base ) ) );
			$folder = (string) $parts[0];
			if ( '' !== $folder && $own !== $folder ) {
				return $folder;
			}
		}

		return '';
	}

	/**
	 * A path with forward slashes and no trailing slash.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function path( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}
}
