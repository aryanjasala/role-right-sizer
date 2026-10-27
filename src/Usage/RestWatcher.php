<?php
/**
 * Counts what REST requests and abilities really used.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Usage;

use AryanX\Axle\RoleRightSizer\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to capability checks while a REST route's permission callback runs.
 *
 * The window opens on `rest_request_before_callbacks`, right before the
 * permission callback, and closes on `rest_dispatch_request`, right after it.
 * Every check that passes in that window for the current user is kept. When
 * the request writes something (not GET, HEAD or OPTIONS) and succeeds, the
 * kept capabilities are recorded. Checks made while building the response,
 * such as which actions the editor may offer, are left out on purpose.
 */
final class RestWatcher implements Service {

	/**
	 * Records capability use.
	 *
	 * @var Recorder
	 */
	private Recorder $recorder;

	/**
	 * Open requests, innermost last: `[ id, caps ]`.
	 *
	 * @var array<int, array{id: int, caps: array<string, bool>}>
	 */
	private array $stack = array();

	/**
	 * Whether a permission callback is running.
	 *
	 * @var bool
	 */
	private bool $listening = false;

	/**
	 * Sets up the watcher.
	 *
	 * @param Recorder $recorder Records capability use.
	 */
	public function __construct( Recorder $recorder ) {
		$this->recorder = $recorder;
	}

	/**
	 * Hooks into REST dispatch, capability checks and ability runs.
	 */
	public function register(): void {
		add_filter( 'rest_request_before_callbacks', array( $this, 'open' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_dispatch_request', array( $this, 'stop_listening' ), -9999, 1 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'close' ), 10, 3 );
		add_filter( 'user_has_cap', array( $this, 'checked' ), PHP_INT_MAX, 4 );
		add_action( 'wp_after_execute_ability', array( $this, 'ability_ran' ), 10, 3 );
	}

	/**
	 * Opens the window for a request whose permission callback is about to run.
	 *
	 * @param mixed $response Response so far, or a WP_Error.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  The request.
	 * @return mixed The response, untouched.
	 */
	public function open( $response, $handler = null, $request = null ) {
		if ( is_wp_error( $response ) || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		$this->stack[]   = array(
			'id'   => spl_object_id( $request ),
			'caps' => array(),
		);
		$this->listening = true;

		return $response;
	}

	/**
	 * Closes the window once the permission callback has passed.
	 *
	 * @param mixed $result Dispatch result.
	 * @return mixed The result, untouched.
	 */
	public function stop_listening( $result ) {
		$this->listening = false;

		return $result;
	}

	/**
	 * Keeps the capabilities a passing check needed.
	 *
	 * @param mixed $allcaps Capabilities the user has.
	 * @param mixed $caps    Primitive capabilities the check needs.
	 * @param mixed $args    Requested capability and its arguments.
	 * @param mixed $user    The user.
	 * @return mixed The capabilities, untouched.
	 */
	public function checked( $allcaps, $caps = array(), $args = array(), $user = null ) {
		if ( ! $this->listening || ! $this->stack || ! $user instanceof \WP_User || get_current_user_id() !== $user->ID || ! is_array( $allcaps ) ) {
			return $allcaps;
		}

		$caps = array_filter( (array) $caps, 'is_string' );
		foreach ( $caps as $cap ) {
			if ( empty( $allcaps[ $cap ] ) ) {
				return $allcaps;
			}
		}

		$top = count( $this->stack ) - 1;
		foreach ( $caps as $cap ) {
			$this->stack[ $top ]['caps'][ $cap ] = true;
		}

		return $allcaps;
	}

	/**
	 * Records the kept capabilities once a request that writes has succeeded.
	 *
	 * @param mixed $response Response or WP_Error.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  The request.
	 * @return mixed The response, untouched.
	 */
	public function close( $response, $handler = null, $request = null ) {
		$this->listening = false;
		if ( ! $request instanceof \WP_REST_Request || ! $this->stack ) {
			return $response;
		}

		$top = end( $this->stack );
		if ( spl_object_id( $request ) !== $top['id'] ) {
			return $response;
		}
		array_pop( $this->stack );

		$status = $response instanceof \WP_HTTP_Response ? $response->get_status() : 200;
		if ( is_wp_error( $response ) || $status >= 400 || in_array( strtoupper( (string) $request->get_method() ), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return $response;
		}

		foreach ( array_keys( $top['caps'] ) as $cap ) {
			$this->recorder->record( (string) $cap, 0, 'rest' );
		}

		return $response;
	}

	/**
	 * Records an ability run. WordPress 6.9 and later.
	 *
	 * @param mixed $name   Ability name.
	 * @param mixed $input  Its input.
	 * @param mixed $result What it returned.
	 */
	public function ability_ran( $name = '', $input = null, $result = null ): void {
		if ( is_string( $name ) && ! is_wp_error( $result ) ) {
			$this->recorder->record_ability( $name );
		}
	}
}
