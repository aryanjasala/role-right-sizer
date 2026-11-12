<?php
/**
 * Records what a person on trial could not do, and tells them how to ask for their access back.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Trial;

use AryanX\Axle\RoleRightSizer\Admin\AxleUi;
use AryanX\Axle\RoleRightSizer\Service;
use AryanX\Axle\RoleRightSizer\Settings;
use AryanX\Axle\RoleRightSizer\Trials;

defined( 'ABSPATH' ) || exit;

/**
 * Watches only the person on trial, and only for refusals that mean something:
 *
 * - an admin screen the menu refused (`admin_page_access_denied`),
 * - a `wp_die()` with a 401 or 403 after a capability check failed, in a page or in an AJAX call,
 * - a REST request that changes something and was answered 401 or 403.
 *
 * Menus that hide entries, background checks and the checks that run to
 * decide what to show are not refusals, so they are left out. A refusal
 * because of an expired nonce is left out too: the role did not cause it.
 * Nothing is written while the request runs. The refusals are saved on `shutdown`.
 */
final class Watcher implements Service {

	public const ACTION = 'role_right_sizer_trial_request';

	/**
	 * The trials.
	 *
	 * @var Trials
	 */
	private Trials $trials;

	/**
	 * The open trial of the current user, when there is one.
	 *
	 * @var array|null
	 */
	private ?array $trial = null;

	/**
	 * Capability checks that failed in this request, newest last.
	 *
	 * @var array<int, array{requested: string, cap: string}>
	 */
	private array $failed = array();

	/**
	 * Refusals of this request, by key.
	 *
	 * @var array<string, array>
	 */
	private array $denials = array();

	/**
	 * Whether a screen refusal was already noted in this request.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * Whether a nonce check failed in this request.
	 *
	 * @var bool
	 */
	private bool $nonce_failed = false;

	/**
	 * Sets up the watcher.
	 *
	 * @param Trials $trials The trials.
	 */
	public function __construct( Trials $trials ) {
		$this->trials = $trials;
	}

	/**
	 * Starts watching when the current user is on trial, and handles the request button.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'arm' ), 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Hooks the watchers for a person on trial. Runs on `init`.
	 */
	public function arm(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$id    = (int) get_user_option( Trials::FLAG, $user_id );
		$trial = $id ? $this->trials->get( $id ) : null;
		if ( ! $trial || (int) $trial['user_id'] !== $user_id || ! in_array( $trial['status'], array( 'active', 'ended' ), true ) ) {
			return;
		}

		$this->trial = $trial;

		add_filter( 'user_has_cap', array( $this, 'checked' ), PHP_INT_MAX, 4 );
		add_action( 'admin_page_access_denied', array( $this, 'screen_denied' ) );
		add_filter( 'wp_die_handler', array( $this, 'page_handler' ), PHP_INT_MAX );
		add_filter( 'wp_die_ajax_handler', array( $this, 'quiet_handler' ), PHP_INT_MAX );
		add_filter( 'wp_die_json_handler', array( $this, 'quiet_handler' ), PHP_INT_MAX );
		add_action( 'check_admin_referer', array( $this, 'nonce_checked' ), 10, 2 );
		add_action( 'check_ajax_referer', array( $this, 'nonce_checked' ), 10, 2 );
		add_filter( 'rest_post_dispatch', array( $this, 'rest_response' ), PHP_INT_MAX, 3 );
		add_action( 'shutdown', array( $this, 'flush' ), 19 );
	}

	/**
	 * Keeps the capability checks that failed for the person on trial.
	 *
	 * @param mixed $allcaps Capabilities the user has.
	 * @param mixed $caps    Primitive capabilities the check needs.
	 * @param mixed $args    Requested capability and its arguments.
	 * @param mixed $user    The user.
	 * @return mixed The capabilities, untouched.
	 */
	public function checked( $allcaps, $caps = array(), $args = array(), $user = null ) {
		if ( ! $this->trial || ! $user instanceof \WP_User || (int) $user->ID !== (int) $this->trial['user_id'] || ! is_array( $allcaps ) ) {
			return $allcaps;
		}

		$requested = is_array( $args ) && isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '';
		foreach ( array_filter( (array) $caps, 'is_string' ) as $cap ) {
			if ( empty( $allcaps[ $cap ] ) && 'do_not_allow' !== $cap ) {
				$this->failed[] = array(
					'requested' => $requested,
					'cap'       => $cap,
				);
				$this->failed   = array_slice( $this->failed, -10 );
				break;
			}
		}

		return $allcaps;
	}

	/**
	 * Notes an admin screen the menu refused.
	 */
	public function screen_denied(): void {
		global $pagenow, $plugin_page;

		$page = isset( $plugin_page ) ? (string) $plugin_page : '';
		$file = (string) $pagenow;
		$key  = 'screen:' . ( '' !== $page ? $page : $file );

		$this->note( $key, 'screen', self::screen_label( $file, $page ), $this->last_failed_cap() );
		$this->handled = true;
	}

	/**
	 * Wraps the page handler of `wp_die()`: notes the refusal and adds the message that says why.
	 *
	 * @param mixed $handler Callback that prints the die page.
	 * @return mixed
	 */
	public function page_handler( $handler ) {
		if ( ! is_callable( $handler ) ) {
			return $handler;
		}

		return function ( $message = '', $title = '', $args = array() ) use ( $handler ) {
			$denied = $this->died( $message, is_array( $args ) ? $args : array() );
			if ( $denied && is_string( $message ) ) {
				$message .= $this->wall_html();
			}

			call_user_func( $handler, $message, $title, $args );
		};
	}

	/**
	 * Wraps the AJAX and JSON handlers of `wp_die()`: notes the refusal and changes nothing else.
	 *
	 * @param mixed $handler Callback that prints the die response.
	 * @return mixed
	 */
	public function quiet_handler( $handler ) {
		if ( ! is_callable( $handler ) ) {
			return $handler;
		}

		return function ( $message = '', $title = '', $args = array() ) use ( $handler ) {
			$this->died( $message, is_array( $args ) ? $args : array() );

			call_user_func( $handler, $message, $title, $args );
		};
	}

	/**
	 * Notes a `wp_die()` that was a permission refusal.
	 *
	 * @param mixed $message The die message.
	 * @param array $args    The die arguments.
	 * @return bool Whether the person hit a wall, in this call or earlier in the request.
	 */
	public function died( $message, array $args ): bool {
		if ( $this->handled ) {
			return true;
		}
		if ( $this->nonce_failed || ! in_array( (int) ( $args['response'] ?? 0 ), array( 401, 403 ), true ) ) {
			return false;
		}

		$cap = $this->last_failed_cap();
		if ( '' !== $cap ) {
			$this->note( 'cap:' . $cap, 'action', self::cap_label( $cap ), $cap );
		} else {
			$text = is_string( $message ) ? trim( wp_strip_all_tags( $message ) ) : '';
			$text = '' !== $text ? mb_substr( $text, 0, 140 ) : __( 'Was refused permission', 'role-right-sizer' );
			$this->note( 'action:' . md5( $text ), 'action', $text, '' );
		}
		$this->handled = true;

		return true;
	}

	/**
	 * Remembers that a nonce failed, so the refusal that follows is not blamed on the role.
	 *
	 * @param mixed $action Nonce action.
	 * @param mixed $result False when the nonce failed.
	 */
	public function nonce_checked( $action = '', $result = true ): void {
		if ( false === $result ) {
			$this->nonce_failed = true;
		}
	}

	/**
	 * Notes a REST request that changes something and was refused, and adds the trial message to the error.
	 *
	 * @param mixed $response The response.
	 * @param mixed $server   The server.
	 * @param mixed $request  The request.
	 * @return mixed The response.
	 */
	public function rest_response( $response, $server = null, $request = null ) {
		if ( ! $this->trial || ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( ! in_array( (int) $response->get_status(), array( 401, 403 ), true ) ) {
			return $response;
		}

		$method = strtoupper( (string) $request->get_method() );
		$data   = $response->get_data();
		$code   = is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';
		if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) || 'rest_cookie_invalid_nonce' === $code ) {
			return $response;
		}

		$cap = $this->last_failed_cap();
		if ( '' !== $cap ) {
			$this->note( 'cap:' . $cap, 'rest', self::cap_label( $cap ), $cap );
		} else {
			$route = self::route_parts( $method, (string) $request->get_route() );
			$this->note( 'rest:' . $route['verb'] . ':' . $route['resource'], 'rest', $route['label'], '' );
		}

		if ( is_array( $data ) ) {
			$data['role_right_sizer_trial'] = wp_strip_all_tags( $this->wall_text() );
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Saves this request's refusals. Runs on `shutdown`.
	 */
	public function flush(): void {
		if ( $this->trial && $this->denials ) {
			$this->trials->add_denials( (int) $this->trial['id'], array_values( $this->denials ) );
		}
		$this->denials = array();
	}

	/**
	 * What the person sees on the die page: why they are stopped and a button to ask for access back.
	 *
	 * @return string HTML.
	 */
	public function wall_html(): string {
		if ( ! $this->trial ) {
			return '';
		}

		$trial = $this->trials->get( (int) $this->trial['id'] ) ?? $this->trial;
		$html  = '<div class="role-right-sizer-wall"><p>' . $this->wall_text( true ) . '</p>';

		if ( (int) $trial['requested'] > time() - DAY_IN_SECONDS ) {
			$html .= '<p>' . esc_html__( 'You already asked. Whoever started the trial has been told.', 'role-right-sizer' ) . '</p>';
		} else {
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$html .= wp_nonce_field( self::ACTION, '_wpnonce', true, false );
			$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
			$html .= '<button type="submit" class="button button-primary">' . esc_html__( 'Request my access back', 'role-right-sizer' ) . '</button>';
			$html .= '</form>';
		}
		$html .= '</div>';

		/**
		 * Filters the HTML added to the page a person on trial sees when they hit a wall.
		 *
		 * @param string $html  The message and the button that asks for access back.
		 * @param array  $trial The trial.
		 */
		return wp_kses(
			Settings::text( apply_filters( 'role_right_sizer_trial_wall', $html, $trial ), $html ),
			array(
				'div'    => array( 'class' => true ),
				'p'      => array(),
				'time'   => array(
					'class'    => true,
					'datetime' => true,
					'title'    => true,
				),
				'form'   => array(
					'method' => true,
					'action' => true,
				),
				'input'  => array(
					'type'  => true,
					'name'  => true,
					'value' => true,
					'id'    => true,
				),
				'button' => array(
					'type'  => true,
					'class' => true,
				),
				'strong' => array(),
				'a'      => array( 'href' => true ),
			)
		);
	}

	/**
	 * The sentence that says why access is reduced.
	 *
	 * @param bool $html Whether to return HTML with a `<time>` element.
	 * @return string
	 */
	public function wall_text( bool $html = false ): string {
		$started = $this->trial ? (int) $this->trial['started'] : 0;
		$when    = $html ? AxleUi::relative_time( $started ) : human_time_diff( $started );
		$text    = $html
			/* translators: %s: how long ago, e.g. "3 days ago". */
			? sprintf( __( 'Your access was reduced %s as a trial. Ask for it back.', 'role-right-sizer' ), $when )
			/* translators: %s: a time span such as "3 days". */
			: sprintf( __( 'Your access was reduced %s ago as a trial. Ask for it back.', 'role-right-sizer' ), $when );

		/**
		 * Filters the sentence a person on trial sees when they hit a wall.
		 *
		 * @param string     $text  The sentence. HTML when it is for the die page.
		 * @param array|null $trial The trial.
		 * @param bool       $html  Whether the text is for the die page.
		 */
		return Settings::text( apply_filters( 'role_right_sizer_trial_message', $text, $this->trial, $html ), $text );
	}

	/**
	 * The request button: tells whoever started the trial and sends the person back to the dashboard.
	 */
	public function handle_request(): void {
		check_admin_referer( self::ACTION );

		$user_id = get_current_user_id();
		$id      = $user_id ? (int) get_user_option( Trials::FLAG, $user_id ) : 0;
		$trial   = $id ? $this->trials->get( $id ) : null;

		$result = 'none';
		if ( $trial && (int) $trial['user_id'] === $user_id ) {
			$sent   = $this->trials->request_access( $id, true );
			$result = true === $sent ? 'requested' : 'asked';
		}

		wp_safe_redirect( add_query_arg( 'rrs_trial', $result, admin_url( 'index.php' ) ) );
		exit;
	}

	/**
	 * Tells the person what happened to their request.
	 */
	public function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, set by our own redirect.
		$result = isset( $_GET['rrs_trial'] ) ? sanitize_key( wp_unslash( $_GET['rrs_trial'] ) ) : '';
		if ( ! get_current_user_id() || ! in_array( $result, array( 'requested', 'asked', 'none' ), true ) ) {
			return;
		}

		$messages = array(
			'requested' => array( 'success', __( 'Your request was sent. Whoever started the trial has been told.', 'role-right-sizer' ) ),
			'asked'     => array( 'info', __( 'You already asked. Whoever started the trial has been told.', 'role-right-sizer' ) ),
			'none'      => array( 'info', __( 'There is no trial on your account, so there is nothing to ask for.', 'role-right-sizer' ) ),
		);
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $result ][0] ), esc_html( $messages[ $result ][1] ) );
	}

	/**
	 * Notes one refusal for this request.
	 *
	 * @param string $key   Groups the same refusal: `screen:plugins.php`, `cap:edit_others_pages`.
	 * @param string $kind  `screen`, `rest` or `action`.
	 * @param string $label What they tried, in words.
	 * @param string $cap   The capability they lacked, when known.
	 */
	private function note( string $key, string $kind, string $label, string $cap ): void {
		if ( isset( $this->denials[ $key ] ) ) {
			return;
		}

		$denial = array(
			'key'   => $key,
			'kind'  => $kind,
			'label' => $label,
			'cap'   => $cap,
		);

		/**
		 * Filters a refusal before it is recorded. Return null to leave it out.
		 *
		 * @param array|null $denial `key`, `kind` (`screen`, `rest` or `action`), `label` and `cap`.
		 * @param array|null $trial  The trial.
		 */
		$denial = apply_filters( 'role_right_sizer_trial_denial', $denial, $this->trial );
		if ( ! is_array( $denial ) || ! isset( $denial['key'], $denial['label'] ) || ! is_string( $denial['key'] ) || ! is_string( $denial['label'] ) || '' === $denial['key'] || '' === $denial['label'] ) {
			return;
		}

		$this->denials[ $key ] = array(
			'key'   => $denial['key'],
			'kind'  => is_string( $denial['kind'] ?? null ) ? $denial['kind'] : $kind,
			'label' => $denial['label'],
			'cap'   => is_string( $denial['cap'] ?? null ) ? $denial['cap'] : $cap,
		);
	}

	/**
	 * The capability the latest failed check needed.
	 *
	 * @return string '' when none failed.
	 */
	private function last_failed_cap(): string {
		$last = end( $this->failed );

		return is_array( $last ) ? $last['cap'] : '';
	}

	/**
	 * What a missing capability means, in words.
	 *
	 * @param string $cap Capability.
	 * @return string
	 */
	public static function cap_label( string $cap ): string {
		$labels = array(
			'edit_others_posts'    => __( 'Could not edit a post by another author', 'role-right-sizer' ),
			'edit_others_pages'    => __( 'Could not edit a page by another author', 'role-right-sizer' ),
			'delete_others_posts'  => __( 'Could not delete a post by another author', 'role-right-sizer' ),
			'delete_others_pages'  => __( 'Could not delete a page by another author', 'role-right-sizer' ),
			'edit_published_posts' => __( 'Could not edit a published post', 'role-right-sizer' ),
			'edit_published_pages' => __( 'Could not edit a published page', 'role-right-sizer' ),
			'publish_posts'        => __( 'Could not publish a post', 'role-right-sizer' ),
			'publish_pages'        => __( 'Could not publish a page', 'role-right-sizer' ),
			'edit_pages'           => __( 'Could not edit pages', 'role-right-sizer' ),
			'moderate_comments'    => __( 'Could not moderate comments', 'role-right-sizer' ),
			'manage_categories'    => __( 'Could not manage categories and tags', 'role-right-sizer' ),
			'manage_options'       => __( 'Could not change site settings', 'role-right-sizer' ),
			'activate_plugins'     => __( 'Could not manage plugins', 'role-right-sizer' ),
			'install_plugins'      => __( 'Could not install plugins', 'role-right-sizer' ),
			'update_plugins'       => __( 'Could not update plugins', 'role-right-sizer' ),
			'switch_themes'        => __( 'Could not switch themes', 'role-right-sizer' ),
			'edit_theme_options'   => __( 'Could not change the theme options', 'role-right-sizer' ),
			'edit_users'           => __( 'Could not edit users', 'role-right-sizer' ),
			'list_users'           => __( 'Could not list users', 'role-right-sizer' ),
			'create_users'         => __( 'Could not add users', 'role-right-sizer' ),
			'delete_users'         => __( 'Could not delete users', 'role-right-sizer' ),
			'promote_users'        => __( 'Could not change roles', 'role-right-sizer' ),
			'upload_files'         => __( 'Could not upload files', 'role-right-sizer' ),
			'import'               => __( 'Could not import content', 'role-right-sizer' ),
			'export'               => __( 'Could not export content', 'role-right-sizer' ),
			'unfiltered_html'      => __( 'Could not save unfiltered HTML', 'role-right-sizer' ),
			'update_core'          => __( 'Could not update WordPress', 'role-right-sizer' ),
		);

		/**
		 * Filters the words used for a capability the person on trial lacked.
		 *
		 * @param string[] $labels Capability => what they could not do.
		 */
		$labels = Settings::strings_map( apply_filters( 'role_right_sizer_trial_cap_labels', $labels ) );

		/* translators: %s: capability name. */
		return $labels[ $cap ] ?? sprintf( __( 'Could not do something that needs %s', 'role-right-sizer' ), $cap );
	}

	/**
	 * What opening an admin screen means, in words.
	 *
	 * @param string $file Admin file, such as `plugins.php`.
	 * @param string $page The `page` of a plugin screen, if any.
	 * @return string
	 */
	public static function screen_label( string $file, string $page = '' ): string {
		$titles = array(
			'plugins.php'         => __( 'Plugins', 'role-right-sizer' ),
			'plugin-install.php'  => __( 'Add Plugins', 'role-right-sizer' ),
			'plugin-editor.php'   => __( 'the Plugin File Editor', 'role-right-sizer' ),
			'themes.php'          => __( 'Themes', 'role-right-sizer' ),
			'theme-editor.php'    => __( 'the Theme File Editor', 'role-right-sizer' ),
			'theme-install.php'   => __( 'Add Themes', 'role-right-sizer' ),
			'customize.php'       => __( 'the Customizer', 'role-right-sizer' ),
			'nav-menus.php'       => __( 'Menus', 'role-right-sizer' ),
			'options-general.php' => __( 'Settings', 'role-right-sizer' ),
			'options.php'         => __( 'All Settings', 'role-right-sizer' ),
			'users.php'           => __( 'Users', 'role-right-sizer' ),
			'user-new.php'        => __( 'Add User', 'role-right-sizer' ),
			'user-edit.php'       => __( 'Edit User', 'role-right-sizer' ),
			'tools.php'           => __( 'Tools', 'role-right-sizer' ),
			'import.php'          => __( 'Import', 'role-right-sizer' ),
			'export.php'          => __( 'Export', 'role-right-sizer' ),
			'update-core.php'     => __( 'Updates', 'role-right-sizer' ),
			'site-health.php'     => __( 'Site Health', 'role-right-sizer' ),
			'edit-comments.php'   => __( 'Comments', 'role-right-sizer' ),
			'upload.php'          => __( 'Media', 'role-right-sizer' ),
			'edit.php'            => __( 'Posts', 'role-right-sizer' ),
			'edit-tags.php'       => __( 'Categories and tags', 'role-right-sizer' ),
		);

		/**
		 * Filters the names of admin screens used in a trial's denials.
		 *
		 * @param string[] $titles Admin file or plugin page => name.
		 */
		$titles = Settings::strings_map( apply_filters( 'role_right_sizer_trial_screen_titles', $titles ) );

		if ( '' !== $page ) {
			$name = $titles[ $page ] ?? $page;
		} else {
			$name = $titles[ $file ] ?? ucfirst( str_replace( array( '-', '_', '.php' ), array( ' ', ' ', '' ), $file ) );
		}

		/* translators: %s: name of an admin screen, such as "Plugins". */
		return sprintf( __( 'Tried to open %s', 'role-right-sizer' ), $name );
	}

	/**
	 * What a refused REST request was about.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route, such as `/wp/v2/posts/12`.
	 * @return array{verb: string, resource: string, label: string}
	 */
	public static function route_parts( string $method, string $route ): array {
		$parts = array_values(
			array_filter(
				explode( '/', $route ),
				static function ( string $part ): bool {
					return '' !== $part;
				} 
			) 
		);
		$id    = false;
		$name  = '';
		foreach ( array_reverse( $parts ) as $part ) {
			if ( ctype_digit( $part ) ) {
				$id = true;
				continue;
			}
			$name = $part;
			break;
		}
		$name = '' !== $name ? $name : trim( $route, '/' );

		if ( 'DELETE' === $method ) {
			$verb = 'delete';
			/* translators: %s: REST resource, such as "posts". */
			$label = sprintf( __( 'Could not delete %s through the REST API', 'role-right-sizer' ), $name );
		} elseif ( 'POST' === $method && ! $id ) {
			$verb = 'create';
			/* translators: %s: REST resource, such as "posts". */
			$label = sprintf( __( 'Could not create %s through the REST API', 'role-right-sizer' ), $name );
		} else {
			$verb = 'update';
			/* translators: %s: REST resource, such as "posts". */
			$label = sprintf( __( 'Could not update %s through the REST API', 'role-right-sizer' ), $name );
		}

		return array(
			'verb'     => $verb,
			'resource' => $name,
			'label'    => $label,
		);
	}
}
