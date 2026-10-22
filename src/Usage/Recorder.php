<?php
/**
 * Records which capabilities people use.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer\Usage;

use AryanX\RoleRightSizer\Service;
use AryanX\RoleRightSizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Collects capability use during a request and writes it once, on `shutdown`.
 *
 * Nothing is written while the request runs. At the end, each capability is
 * written only if it is new for that user or its last write is older than the
 * record interval (an hour by default), so a busy editor costs at most a few
 * user meta writes an hour.
 */
final class Recorder implements Service {

	/**
	 * Where usage is kept.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Settings and limits.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Default hooks and their resolvers.
	 *
	 * @var HookMap
	 */
	private HookMap $map;

	/**
	 * Capabilities used in this request: `user_id => [ cap => source ]`.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $pending = array();

	/**
	 * Abilities run in this request: `user_id => [ name => true ]`.
	 *
	 * @var array<int, array<string, bool>>
	 */
	private array $abilities = array();

	/**
	 * Ignored capabilities, read once per request.
	 *
	 * @var string[]|null
	 */
	private ?array $ignored = null;

	/**
	 * Sets up the recorder.
	 *
	 * @param Store    $store    Where usage is kept.
	 * @param Settings $settings Settings and limits.
	 * @param HookMap  $map      Default hooks and their resolvers.
	 */
	public function __construct( Store $store, Settings $settings, HookMap $map ) {
		$this->store    = $store;
		$this->settings = $settings;
		$this->map      = $map;
	}

	/**
	 * Listens for logins now, and for the mapped hooks once themes and plugins can filter the map.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'listen' ), 1 );
		add_action( 'wp_login', array( $this, 'login' ), 10, 2 );
		add_action( 'shutdown', array( $this, 'flush' ), 20 );
	}

	/**
	 * The hooks that prove a capability was used.
	 *
	 * @return array<string, string|string[]|callable>
	 */
	public function hook_map(): array {
		/**
		 * Filters the hooks Role Right-Sizer listens to, and the capability each one proves.
		 *
		 * Each value is a capability, a list of capabilities, or a callable that
		 * gets the hook's arguments and returns the capabilities. Only capabilities
		 * the current user really has are recorded.
		 *
		 * @param array<string, string|string[]|callable> $map Hook name => capability, list or callable.
		 */
		return self::clean_map( apply_filters( 'role_right_sizer_hook_capability_map', $this->map->defaults() ) );
	}

	/**
	 * A filtered hook map, with anything malformed dropped.
	 *
	 * @param mixed $map Filtered map.
	 * @return array<string, string|string[]|callable>
	 */
	public static function clean_map( $map ): array {
		$clean = array();
		foreach ( is_array( $map ) ? $map : array() as $hook => $proof ) {
			if ( is_string( $hook ) && '' !== $hook && ( is_callable( $proof ) || is_string( $proof ) || is_array( $proof ) ) ) {
				$clean[ $hook ] = $proof;
			}
		}

		return $clean;
	}

	/**
	 * Hooks into every mapped hook. Runs on `init`.
	 */
	public function listen(): void {
		foreach ( $this->hook_map() as $hook => $proof ) {
			add_filter(
				$hook,
				function ( ...$args ) use ( $hook, $proof ) {
					$this->from_hook( $hook, $proof, $args );

					// Some mapped hooks are filters: hand their value back untouched.
					return $args[0] ?? null;
				},
				999,
				99
			);
		}
	}

	/**
	 * Records what a mapped hook proves.
	 *
	 * @param string                   $hook  Hook name.
	 * @param string|string[]|callable $proof Capability, list, or resolver.
	 * @param array                    $args  The hook's arguments.
	 */
	public function from_hook( string $hook, $proof, array $args ): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		if ( is_callable( $proof ) ) {
			$caps = call_user_func_array( $proof, $args );
		} else {
			$caps = (array) $proof;
		}

		foreach ( Settings::strings( $caps ) as $cap ) {
			$this->record( $cap, $user_id, $hook );
		}
	}

	/**
	 * Notes that a user used a capability in this request.
	 *
	 * Ignored capabilities, `do_not_allow` and capabilities the user does not
	 * have are dropped, so a plugin hook that fires for everyone cannot make a
	 * role look needed.
	 *
	 * @param string $cap     Capability.
	 * @param int    $user_id User ID, 0 for the current user.
	 * @param string $source  Where it was seen: a hook name, `screen` or `rest`.
	 * @return bool Whether it was noted.
	 */
	public function record( string $cap, int $user_id = 0, string $source = '' ): bool {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id || '' === $cap || 'do_not_allow' === $cap || isset( $this->pending[ $user_id ][ $cap ] ) ) {
			return false;
		}

		if ( null === $this->ignored ) {
			$this->ignored = $this->settings->ignored_capabilities();
		}
		if ( in_array( $cap, $this->ignored, true ) || ! user_can( $user_id, $cap ) ) {
			return false;
		}

		$this->pending[ $user_id ][ $cap ] = $source;

		/**
		 * Fires the first time in a request that a user uses a capability.
		 *
		 * @param string $cap     Capability.
		 * @param int    $user_id User ID.
		 * @param string $source  Where it was seen: the hook name, `screen` for an admin screen, `rest` for a REST request.
		 */
		do_action( 'role_right_sizer_capability_used', $cap, $user_id, $source );

		return true;
	}

	/**
	 * Notes that a user ran an ability in this request.
	 *
	 * @param string $name    Ability name.
	 * @param int    $user_id User ID, 0 for the current user.
	 */
	public function record_ability( string $name, int $user_id = 0 ): void {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( $user_id && '' !== $name ) {
			$this->abilities[ $user_id ][ $name ] = true;
		}
	}

	/**
	 * Capabilities noted so far in this request.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function pending(): array {
		return $this->pending;
	}

	/**
	 * Saves a login.
	 *
	 * @param string $user_login Login name.
	 * @param mixed  $user       The user.
	 */
	public function login( $user_login = '', $user = null ): void {
		if ( $user instanceof \WP_User ) {
			$this->store->login( $user->ID, time() );
		}
	}

	/**
	 * Writes this request's usage. Runs on `shutdown`.
	 */
	public function flush(): void {
		$now      = time();
		$interval = $this->settings->record_interval();
		$current  = get_current_user_id();

		if ( $current ) {
			$this->store->touch( $current, $now, $interval );
		}

		foreach ( $this->pending as $user_id => $caps ) {
			$this->store->add_usage( $user_id, array_keys( $caps ), $now, $interval );
		}

		foreach ( $this->abilities as $user_id => $names ) {
			$this->store->add_abilities( $user_id, array_keys( $names ), $now, $interval );
		}

		$this->pending   = array();
		$this->abilities = array();
	}
}
