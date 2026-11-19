<?php
/**
 * Runs one request as another user, without signing in as them, and says what stopped it.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Probe;

use AryanX\Axle\RoleRightSizer\Admin\AxleUi;
use AryanX\Axle\RoleRightSizer\Recommender;
use AryanX\Axle\RoleRightSizer\Settings;
use AryanX\Axle\RoleRightSizer\Temporary;
use const AryanX\Axle\RoleRightSizer\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * "Why can't they?": loads an admin screen or a REST route of this site as a
 * chosen person and reports the capability that refused it.
 *
 * The request carries a logged-in cookie made for that one call. Its session
 * token is destroyed right after the call, whatever happens, so the cookie is
 * worthless afterwards. It also carries a signed probe cookie that works once.
 * REST requests are answered after their permission check, so nothing is
 * created, changed or deleted, and no response body ever reaches the person
 * who asked: they only learn what was allowed or refused, and why.
 *
 * Only admin screens and REST routes of this site can be named, and only for
 * a person the asker may edit.
 */
final class Prober {

	public const COOKIE = 'role_right_sizer_probe';

	private const SEEN = 'role_right_sizer_probe_seen_';
	private const USED = 'role_right_sizer_probe_used_';
	private const SAVE = 'role_right_sizer_probe_result_';

	/**
	 * How long a probe cookie and its session live, in seconds.
	 *
	 * @return int
	 */
	public static function ttl(): int {
		/**
		 * Filters how long, in seconds, the one-request cookie of a "Why can't they?" check is valid.
		 *
		 * @param int $seconds Default 120, between 30 and 300.
		 */
		return Settings::clamp( apply_filters( 'role_right_sizer_probe_ttl', 2 * MINUTE_IN_SECONDS ), 2 * MINUTE_IN_SECONDS, 30, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Admin files that are never loaded as another person: they change things on a plain request, or stream files.
	 *
	 * @return string[]
	 */
	public static function blocked_screens(): array {
		$blocked = array(
			'admin-ajax.php',
			'admin-post.php',
			'async-upload.php',
			'export.php',
			'install.php',
			'load-scripts.php',
			'load-styles.php',
			'update.php',
			'upgrade.php',
			'media-upload.php',
		);

		/**
		 * Filters the admin files "Why can't they?" refuses to load.
		 *
		 * @param string[] $blocked Default the files that act on a plain request or stream files.
		 */
		return Settings::strings( apply_filters( 'role_right_sizer_probe_blocked_screens', $blocked ) );
	}

	/**
	 * Quick picks for the form: what people ask most.
	 *
	 * @return array<int, array{kind: string, target: string, method: string, label: string}>
	 */
	public static function presets(): array {
		$presets = array(
			array(
				'kind'   => 'rest',
				'target' => '/wp/v2/media',
				'method' => 'POST',
				'label'  => __( 'Upload an image', 'role-right-sizer' ),
			),
			array(
				'kind'   => 'screen',
				'target' => 'upload.php',
				'method' => 'GET',
				'label'  => __( 'Open the Media library', 'role-right-sizer' ),
			),
			array(
				'kind'   => 'rest',
				'target' => '/wp/v2/posts',
				'method' => 'POST',
				'label'  => __( 'Write a post', 'role-right-sizer' ),
			),
			array(
				'kind'   => 'rest',
				'target' => '/wp/v2/pages',
				'method' => 'POST',
				'label'  => __( 'Write a page', 'role-right-sizer' ),
			),
			array(
				'kind'   => 'screen',
				'target' => 'edit-comments.php',
				'method' => 'GET',
				'label'  => __( 'Open Comments', 'role-right-sizer' ),
			),
			array(
				'kind'   => 'screen',
				'target' => 'plugins.php',
				'method' => 'GET',
				'label'  => __( 'Open Plugins', 'role-right-sizer' ),
			),
		);

		/**
		 * Filters the quick picks on the "Check what they can do" form.
		 *
		 * @param array $presets Each with `kind` (`screen` or `rest`), `target`, `method` and `label`.
		 */
		$filtered = Settings::maybe_array( apply_filters( 'role_right_sizer_probe_presets', $presets ) );
		$clean    = array();
		foreach ( $filtered ?? array() as $preset ) {
			if ( is_array( $preset ) && isset( $preset['kind'], $preset['target'], $preset['label'] ) && is_string( $preset['target'] ) && is_string( $preset['label'] ) && in_array( $preset['kind'], array( 'screen', 'rest' ), true ) ) {
				$clean[] = array(
					'kind'   => (string) $preset['kind'],
					'target' => $preset['target'],
					'method' => self::method( is_string( $preset['method'] ?? null ) ? $preset['method'] : 'GET' ),
					'label'  => $preset['label'],
				);
			}
		}

		return $clean;
	}

	/**
	 * A request method this feature accepts.
	 *
	 * @param string $method Method.
	 * @return string GET when it is not one of the five.
	 */
	public static function method( string $method ): string {
		$method = strtoupper( $method );

		return in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ? $method : 'GET';
	}

	/**
	 * Whether the current user may check this person.
	 *
	 * WP-CLI has no current user and may check anyone.
	 *
	 * @param int $user_id The person.
	 * @return bool
	 */
	public static function can_check( int $user_id ): bool {
		if ( ! get_current_user_id() ) {
			$allowed = defined( 'WP_CLI' ) && WP_CLI;
		} else {
			$allowed = current_user_can( 'edit_user', $user_id );
		}

		/**
		 * Filters whether the current user may run "Why can't they?" for a person.
		 *
		 * @param bool $allowed Default whether they may edit the person. WP-CLI always may.
		 * @param int  $user_id The person.
		 */
		return (bool) apply_filters( 'role_right_sizer_can_probe', $allowed, $user_id );
	}

	/**
	 * Turns what a person typed into the address on this site, or says why not.
	 *
	 * @param string $kind   `screen` or `rest`.
	 * @param string $target Admin file with its query, such as `upload.php`, or a route, such as `/wp/v2/media`.
	 * @return array{url: string, shown: string}|\WP_Error
	 */
	public static function address( string $kind, string $target ) {
		$target = trim( $target );
		if ( 'screen' === $kind ) {
			$target = ltrim( $target, '/' );
			$target = (string) preg_replace( '#^(?:wp-admin/)#', '', $target );
			if ( 1 !== preg_match( '/^([a-z0-9_-]+\.php)(\?[A-Za-z0-9_=&%.,+\[\]-]*)?$/i', $target, $match ) ) {
				return new \WP_Error( 'role_right_sizer_probe_target', __( 'Name an admin screen of this site, such as upload.php or admin.php?page=my-plugin.', 'role-right-sizer' ) );
			}
			if ( in_array( strtolower( $match[1] ), self::blocked_screens(), true ) ) {
				return new \WP_Error( 'role_right_sizer_probe_blocked', __( 'That file acts on a plain request, so it cannot be loaded as another person. Check the screen that uses it instead.', 'role-right-sizer' ) );
			}

			return array(
				'url'   => admin_url( $target ),
				'shown' => $target,
			);
		}

		if ( 'rest' === $kind ) {
			if ( 1 !== preg_match( '#^/[A-Za-z0-9_\-./]*$#', $target ) || false !== strpos( $target, '..' ) ) {
				return new \WP_Error( 'role_right_sizer_probe_target', __( 'Name a REST route of this site, such as /wp/v2/media.', 'role-right-sizer' ) );
			}

			return array(
				'url'   => rest_url( ltrim( $target, '/' ) ),
				'shown' => $target,
			);
		}

		return new \WP_Error( 'role_right_sizer_probe_target', __( 'Check an admin screen or a REST route.', 'role-right-sizer' ) );
	}

	/**
	 * Runs the check.
	 *
	 * @param int    $user_id The person.
	 * @param string $kind    `screen` or `rest`.
	 * @param string $target  The screen or route.
	 * @param string $method  The method. A screen is always loaded with GET.
	 * @return array<string, mixed>|\WP_Error The result, see summarise().
	 */
	public function run( int $user_id, string $kind, string $target, string $method = 'GET' ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'role_right_sizer_not_found', __( 'That user does not exist.', 'role-right-sizer' ) );
		}
		if ( ! self::can_check( $user_id ) ) {
			return new \WP_Error( 'role_right_sizer_forbidden', __( 'Sorry, you are not allowed to check this user.', 'role-right-sizer' ) );
		}

		$address = self::address( $kind, $target );
		if ( is_wp_error( $address ) ) {
			return $address;
		}
		$method = 'screen' === $kind ? 'GET' : self::method( $method );

		$ttl     = self::ttl();
		$expires = time() + $ttl;
		$id      = bin2hex( random_bytes( 10 ) );
		$tokens  = \WP_Session_Tokens::get_instance( $user_id );
		$token   = $tokens->create( $expires );
		set_transient( self::USED . $id, 1, $ttl );

		try {
			$cookies = self::cookies( $user_id, $expires, $token, self::sign( $id, $user_id, $expires, $kind, $address['shown'] ) );
			$headers = AxleUi::loopback_headers( $address['url'] );
			if ( 'rest' === $kind ) {
				$headers['X-WP-Nonce'] = self::rest_nonce( $user_id, $cookies );
			}

			$args = array(
				'method'      => $method,
				'timeout'     => 30, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- A person waits for a page of their own site, checked on demand.
				'redirection' => 0,
				'headers'     => $headers,
				'cookies'     => $cookies,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter for requests to the site itself.
				'user-agent'  => 'Role Right-Sizer/' . VERSION,
			);

			/**
			 * Filters the address a "Why can't they?" request is sent to. A site whose own address is
			 * not reachable from the server (some Docker setups) can point it somewhere that is.
			 *
			 * @param mixed  $url  The address.
			 * @param string $kind `screen` or `rest`.
			 */
			$fetch = apply_filters( 'role_right_sizer_probe_fetch_url', $address['url'], $kind );
			$fetch = is_string( $fetch ) && '' !== $fetch ? $fetch : $address['url'];

			/**
			 * Filters the arguments of that request, such as a Host header. The cookies cannot be changed.
			 *
			 * @param mixed  $args The wp_remote_request() arguments.
			 * @param string $kind `screen` or `rest`.
			 */
			$filtered        = apply_filters( 'role_right_sizer_probe_request_args', $args, $kind );
			$args            = is_array( $filtered ) ? $filtered : $args;
			$args['method']  = $method;
			$args['cookies'] = $cookies;

			$response = wp_remote_request( $fetch, $args ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- A request to the site itself, on demand, by an administrator.
		} finally {
			$tokens->destroy( $token );
			delete_transient( self::USED . $id );
		}

		$seen = get_transient( self::SEEN . $id );
		delete_transient( self::SEEN . $id );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'role_right_sizer_probe_unreachable',
				sprintf(
					/* translators: %s: the error message. */
					__( 'The check could not load the page: %s. Your site has to be able to reach its own address. Site Health shows whether it can.', 'role-right-sizer' ),
					$response->get_error_message()
				)
			);
		}

		$result = self::summarise(
			array(
				'user_id' => $user_id,
				'kind'    => $kind,
				'target'  => $address['shown'],
				'method'  => $method,
			),
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_header( $response, 'location' ),
			is_array( $seen ) ? $seen : array(),
			'rest' === $kind ? wp_remote_retrieve_body( $response ) : ''
		);

		/**
		 * Fires after a "Why can't they?" check ran.
		 *
		 * @param array $result  What was found, see Prober::summarise().
		 * @param int   $user_id The person who was checked.
		 */
		do_action( 'role_right_sizer_probe_run', $result, $user_id );

		return $result;
	}

	/**
	 * What a check found, in a fixed shape. Pure.
	 *
	 * @param array<string, mixed> $about    `user_id`, `kind`, `target`, `method`.
	 * @param int                  $status   HTTP status of the request.
	 * @param string               $location The Location header, if any.
	 * @param array<int, mixed>    $failed Failed checks, the latest last.
	 * @param string               $body     The REST response body, used only to tell a dry run from a route that answered.
	 * @return array<string, mixed> `verdict` (`allowed`, `refused`, `redirected`, `missing`, `error`), `status`, `cap` (the blocking capability, or ''), `outright` (WordPress refuses this action for this object), `failed`, `location_path`, plus `about`.
	 */
	public static function summarise( array $about, int $status, string $location, array $failed, string $body = '' ): array {
		$clean = array();
		foreach ( $failed as $check ) {
			if ( is_array( $check ) && isset( $check['cap'] ) && is_string( $check['cap'] ) ) {
				$clean[] = array(
					'requested' => is_string( $check['requested'] ?? null ) ? $check['requested'] : '',
					'cap'       => $check['cap'],
				);
			}
		}

		if ( in_array( $status, array( 401, 403 ), true ) ) {
			$verdict = 'refused';
		} elseif ( 404 === $status ) {
			$verdict = 'missing';
		} elseif ( $status >= 300 && $status < 400 ) {
			$verdict = 'redirected';
		} elseif ( 200 === $status || 201 === $status ) {
			$verdict = 'allowed';
		} else {
			$verdict = 'error';
		}

		if ( 'rest' === ( $about['kind'] ?? '' ) && in_array( $status, array( 200, 201 ), true ) && false === strpos( $body, 'role_right_sizer_probe' ) ) {
			// The route answered with something of its own: the check did not see its permission step.
			$verdict = 'error';
		}

		$cap      = '';
		$outright = false;
		if ( 'refused' === $verdict && $clean ) {
			$last     = end( $clean );
			$cap      = $last['cap'];
			$outright = 'do_not_allow' === $cap;
			if ( $outright ) {
				$cap = '';
			}
		}

		$path = '';
		if ( '' !== $location ) {
			$path = (string) wp_parse_url( $location, PHP_URL_PATH );
			$path = basename( $path );
		}

		return array(
			'about'         => $about,
			'verdict'       => $verdict,
			'status'        => $status,
			'cap'           => $cap,
			'outright'      => $outright,
			'failed'        => $clean,
			'location_path' => $path,
		);
	}

	/**
	 * Roles that grant a capability, smallest first.
	 *
	 * @param string $cap Capability.
	 * @return string[] Role names.
	 */
	public static function roles_with( string $cap ): array {
		$found = array();
		foreach ( Recommender::role_caps() as $name => $caps ) {
			if ( in_array( $cap, $caps, true ) ) {
				$found[ $name ] = count( $caps );
			}
		}
		asort( $found );

		return array_map( 'strval', array_keys( $found ) );
	}

	/**
	 * The smallest ways to give a person a capability: the capability alone, and the smallest role that has it.
	 *
	 * Only what the current user may hand out: a capability they hold themselves, and roles WordPress lets them assign.
	 *
	 * @param \WP_User $user The person.
	 * @param string   $cap  The capability they lacked.
	 * @return array<int, array{type: string, value: string, gained: string[]}> Smallest first.
	 */
	public static function fixes( \WP_User $user, string $cap ): array {
		if ( '' === $cap || get_current_user_id() === $user->ID ) {
			return array();
		}

		$have  = Temporary::permanent_caps( $user );
		$fixes = array();
		if ( ! in_array( $cap, $have, true ) && self::may_hand_out_cap( $cap ) ) {
			$fixes[] = array(
				'type'   => 'cap',
				'value'  => $cap,
				'gained' => array( $cap ),
			);
		}

		$roles    = Recommender::role_caps();
		$editable = self::editable_roles();
		foreach ( self::roles_with( $cap ) as $role ) {
			if ( in_array( $role, Temporary::permanent_roles( $user ), true ) || ! in_array( $role, $editable, true ) ) {
				continue;
			}
			$gained  = array_values( array_diff( $roles[ $role ], $have ) );
			$fixes[] = array(
				'type'   => 'role',
				'value'  => $role,
				'gained' => $gained,
			);
			break;
		}

		return $fixes;
	}

	/**
	 * Whether the current user may give out a capability: they hold it themselves.
	 *
	 * @param string $cap Capability.
	 * @return bool
	 */
	public static function may_hand_out_cap( string $cap ): bool {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\-]+$/', $cap ) || 'do_not_allow' === $cap || isset( Recommender::role_caps()[ $cap ] ) ) {
			return false;
		}

		return ! get_current_user_id() || current_user_can( $cap );
	}

	/**
	 * The roles the current user may give: the ones WordPress lets them assign, and only those whose
	 * capabilities they hold themselves. Core alone lets anyone with `promote_users` hand out the
	 * administrator role; this never does. Every role for WP-CLI.
	 *
	 * @return string[]
	 */
	public static function editable_roles(): array {
		if ( ! get_current_user_id() ) {
			return array_map( 'strval', array_keys( wp_roles()->roles ) );
		}
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$names = array_map( 'strval', array_keys( get_editable_roles() ) );
		if ( is_multisite() && is_super_admin() ) {
			return $names;
		}

		$roles = Recommender::role_caps();
		$names = array_values(
			array_filter(
				$names,
				static function ( string $name ) use ( $roles ): bool {
					foreach ( $roles[ $name ] ?? array() as $cap ) {
						if ( ! current_user_can( $cap ) ) {
							return false;
						}
					}

					return isset( $roles[ $name ] );
				}
			)
		);

		/**
		 * Filters the roles "Why can't they?" offers to give. Roles the current user does not fully hold are dropped from the result whatever it holds.
		 *
		 * @param string[] $names Role names.
		 */
		$filtered = Settings::strings( apply_filters( 'role_right_sizer_fix_roles', $names ) );

		return array_values( array_intersect( $filtered, $names ) );
	}

	/**
	 * Keeps a finished result for the screen to show, for the person who asked.
	 *
	 * @param array<string, mixed> $result The result.
	 * @return string An id for the redirect.
	 */
	public static function keep( array $result ): string {
		$id = bin2hex( random_bytes( 8 ) );
		set_transient(
			self::SAVE . $id,
			array(
				'by'     => get_current_user_id(),
				'result' => $result,
			),
			10 * MINUTE_IN_SECONDS
		);

		return $id;
	}

	/**
	 * A kept result, only for the person who asked and only while they may still check that person.
	 *
	 * @param string $id The id from keep().
	 * @return array<string, mixed>|null
	 */
	public static function kept( string $id ): ?array {
		$saved = '' !== $id ? get_transient( self::SAVE . sanitize_key( $id ) ) : false;
		if ( ! is_array( $saved ) || (int) ( $saved['by'] ?? -1 ) !== get_current_user_id() || ! is_array( $saved['result'] ?? null ) ) {
			return null;
		}

		$result = $saved['result'];

		return self::can_check( (int) ( $result['about']['user_id'] ?? 0 ) ) ? $result : null;
	}

	/**
	 * The signed value of the probe cookie.
	 *
	 * @param string $id      The probe id.
	 * @param int    $user_id The person.
	 * @param int    $expires Unix time it stops working.
	 * @param string $kind    `screen` or `rest`.
	 * @param string $target  What is checked; only a fingerprint of it is signed.
	 * @return string
	 */
	public static function sign( string $id, int $user_id, int $expires, string $kind, string $target ): string {
		$body = implode( '|', array( $id, $user_id, $expires, $kind, sha1( $target ) ) );

		return $body . '|' . hash_hmac( 'sha256', $body, wp_salt( 'auth' ) );
	}

	/**
	 * Reads a probe cookie. Null when the signature, the format or the time is wrong.
	 *
	 * @param string $value Cookie value.
	 * @return array{id: string, user: int, expires: int, kind: string, hash: string}|null
	 */
	public static function read( string $value ): ?array {
		$parts = explode( '|', $value );
		if ( 6 !== count( $parts ) ) {
			return null;
		}

		list( $id, $user, $expires, $kind, $target, $signature ) = $parts;
		$body = implode( '|', array( $id, $user, $expires, $kind, $target ) );
		if ( ! hash_equals( hash_hmac( 'sha256', $body, wp_salt( 'auth' ) ), $signature ) || (int) $expires < time() || ! in_array( $kind, array( 'screen', 'rest' ), true ) || 1 !== preg_match( '/^[A-Za-z0-9]{8,40}$/', $id ) ) {
			return null;
		}

		return array(
			'id'      => $id,
			'user'    => (int) $user,
			'expires' => (int) $expires,
			'kind'    => $kind,
			'hash'    => $target,
		);
	}

	/**
	 * Uses up a probe id. False when it was used already or was never made here.
	 *
	 * @param string $id The probe id.
	 * @return bool
	 */
	public static function claim( string $id ): bool {
		if ( ! get_transient( self::USED . $id ) ) {
			return false;
		}
		delete_transient( self::USED . $id );

		return true;
	}

	/**
	 * Saves what the probed request saw.
	 *
	 * @param string $id     The probe id.
	 * @param array  $failed Failed checks.
	 */
	public static function store_seen( string $id, array $failed ): void {
		set_transient( self::SEEN . $id, $failed, self::ttl() );
	}

	/**
	 * The cookies of the request: a logged-in session for this one call, and the probe cookie.
	 *
	 * @param int    $user_id The person.
	 * @param int    $expires Unix time they stop working.
	 * @param string $token   The session token, destroyed after the call.
	 * @param string $probe   Signed probe value.
	 * @return \WP_Http_Cookie[]
	 */
	private static function cookies( int $user_id, int $expires, string $token, string $probe ): array {
		$secure = 'https' === wp_parse_url( admin_url(), PHP_URL_SCHEME );
		$list   = array();
		foreach (
			array(
				( $secure ? (string) constant( 'SECURE_AUTH_COOKIE' ) : (string) constant( 'AUTH_COOKIE' ) ) => $secure ? 'secure_auth' : 'auth',
				(string) constant( 'LOGGED_IN_COOKIE' ) => 'logged_in',
			) as $name => $scheme
		) {
			$list[] = new \WP_Http_Cookie(
				array(
					'name'  => $name,
					'value' => wp_generate_auth_cookie( $user_id, $expires, $scheme, $token ),
				)
			);
		}
		$list[] = new \WP_Http_Cookie(
			array(
				'name'  => self::COOKIE,
				'value' => $probe,
			)
		);

		return $list;
	}

	/**
	 * The REST nonce the person would hold, made without signing in as them here.
	 *
	 * @param int               $user_id The person.
	 * @param \WP_Http_Cookie[] $cookies The cookies of the request.
	 * @return string
	 */
	private static function rest_nonce( int $user_id, array $cookies ): string {
		$previous = get_current_user_id();
		$logged   = (string) constant( 'LOGGED_IN_COOKIE' );
		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Set for a moment so core reads this session's token, then put back.
		$had = array_key_exists( $logged, $_COOKIE );
		$old = $had ? $_COOKIE[ $logged ] : null;
		foreach ( $cookies as $cookie ) {
			if ( $cookie->name === $logged ) {
				$_COOKIE[ $logged ] = $cookie->value;
			}
		}
		try {
			wp_set_current_user( $user_id );
			$nonce = wp_create_nonce( 'wp_rest' );
		} finally {
			wp_set_current_user( $previous );
			if ( $had ) {
				$_COOKIE[ $logged ] = $old;
			} else {
				unset( $_COOKIE[ $logged ] );
			}
		}
		// phpcs:enable

		return $nonce;
	}
}
