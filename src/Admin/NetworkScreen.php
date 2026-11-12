<?php
/**
 * Network Admin > Users > Right-Sizer: super admins' activity, read-only.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Admin;

use AryanX\Axle\RoleRightSizer\Service;
use AryanX\Axle\RoleRightSizer\Usage\Store;
use const AryanX\Axle\RoleRightSizer\PLUGIN_FILE;
use const AryanX\Axle\RoleRightSizer\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Super admins have every capability on every site, so there is nothing to
 * recommend for them. This screen shows what they actually did, per site, so
 * a network admin can spot super admin accounts nobody needs any more.
 */
final class NetworkScreen implements Service {

	public const SLUG = 'role-right-sizer-network';

	/**
	 * Recorded usage.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Sets up the screen.
	 *
	 * @param Store $store Recorded usage.
	 */
	public function __construct( Store $store ) {
		$this->store = $store;
	}

	/**
	 * Adds the network menu entry.
	 */
	public function register(): void {
		add_action( 'network_admin_menu', array( $this, 'add_page' ) );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds a link to the screen in the plugin's row on the network Plugins screen.
	 *
	 * @param mixed $links Links shown under the plugin name.
	 * @return mixed
	 */
	public function action_links( $links ) {
		if ( is_array( $links ) && current_user_can( 'manage_network_users' ) ) {
			array_unshift( $links, '<a href="' . esc_url( network_admin_url( 'users.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Open Right-Sizer', 'role-right-sizer' ) . '</a>' );
		}

		return $links;
	}

	/**
	 * Adds the Help tabs.
	 */
	public function help(): void {
		AxleUi::help_tabs(
			array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'role-right-sizer' ),
					'content' => '<p>' . esc_html__( 'Super admins can do everything on every site, so Role Right-Sizer makes no recommendations for them. This screen shows what each super admin actually did, so you can spot accounts that no longer need super admin.', 'role-right-sizer' ) . '</p>',
				),
				array(
					'id'      => 'activity',
					'title'   => __( 'Activity per site', 'role-right-sizer' ),
					'content' => '<p>' . esc_html__( 'Each line is one site where the account did something, with when it was last active there and how many different capabilities it used. A super admin with no line has not been seen on any site since recording began.', 'role-right-sizer' ) . '</p>',
				),
			)
		);
	}

	/**
	 * Registers Network Admin > Users > Right-Sizer.
	 */
	public function add_page(): void {
		$hook = add_submenu_page(
			'users.php',
			__( 'Role Right-Sizer', 'role-right-sizer' ),
			__( 'Right-Sizer', 'role-right-sizer' ),
			'manage_network_users',
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( "load-{$hook}", array( $this, 'help' ) );
			add_action(
				"admin_print_styles-{$hook}",
				static function () {
					AxleUi::enqueue();
					wp_enqueue_style( 'role-right-sizer-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
				}
			);
		}
	}

	/**
	 * Per-site activity of one user, read from their user meta keys.
	 *
	 * @param array<string, mixed> $meta     All the user's meta, as get_user_meta() returns it.
	 * @param string               $base     The network's base table prefix, such as `wp_`.
	 * @return array<int, array{last_active: int, caps: int}> Site ID => activity.
	 */
	public static function sites_from_meta( array $meta, string $base ): array {
		$sites   = array();
		$pattern = '/^' . preg_quote( $base, '/' ) . '(?:(\d+)_)?(' . Store::LAST_ACTIVE . '|' . Store::USAGE . ')$/';
		foreach ( $meta as $key => $values ) {
			if ( ! preg_match( $pattern, (string) $key, $match ) ) {
				continue;
			}
			$site  = '' !== $match[1] ? (int) $match[1] : 1;
			$value = maybe_unserialize( is_array( $values ) ? ( $values[0] ?? '' ) : $values );
			if ( ! isset( $sites[ $site ] ) ) {
				$sites[ $site ] = array(
					'last_active' => 0,
					'caps'        => 0,
				);
			}
			if ( Store::LAST_ACTIVE === $match[2] ) {
				$sites[ $site ]['last_active'] = (int) $value;
			} else {
				$sites[ $site ]['caps'] = count( Store::clean( $value ) );
			}
		}
		ksort( $sites );

		return $sites;
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		global $wpdb;

		if ( ! current_user_can( 'manage_network_users' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see this page.', 'role-right-sizer' ), 403 );
		}

		$admins = array_filter( array_map( 'get_user_by', array_fill( 0, count( get_super_admins() ), 'login' ), get_super_admins() ) );
		?>
		<div class="wrap axle-ui rrs-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Role Right-Sizer', 'role-right-sizer' ); ?></h1>
			<hr class="wp-header-end">
			<p class="axle-intro"><?php esc_html_e( 'Super admins can do everything on every site, so Role Right-Sizer makes no recommendations for them. Here is what each one actually did, so you can spot accounts that no longer need super admin.', 'role-right-sizer' ); ?></p>
			<section class="axle-card">
			<h2 class="axle-card__title"><?php esc_html_e( 'Super admins', 'role-right-sizer' ); ?></h2>
			<?php if ( ! $admins ) : ?>
				<div class="axle-empty"><p><?php esc_html_e( 'No super admins found. When there are some, each one shows up here with where they were last active.', 'role-right-sizer' ); ?></p></div>
			<?php else : ?>
			<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Super admins', 'role-right-sizer' ); ?>">
			<table class="widefat striped rrs-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Super admin', 'role-right-sizer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last login', 'role-right-sizer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Active on', 'role-right-sizer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $admins as $admin ) : ?>
						<?php $sites = self::sites_from_meta( (array) get_user_meta( $admin->ID ), (string) $wpdb->base_prefix ); ?>
						<tr>
							<td><strong><?php echo esc_html( $admin->display_name ); ?></strong> <span class="axle-muted"><?php echo esc_html( $admin->user_login ); ?></span></td>
							<td><?php Screen::print_time( $this->store->last_login( $admin->ID ) ); ?></td>
							<td>
								<?php if ( ! $sites ) : ?>
									<span class="axle-muted"><?php esc_html_e( 'No activity recorded', 'role-right-sizer' ); ?></span>
								<?php else : ?>
									<ul class="rrs-sites">
										<?php foreach ( $sites as $site_id => $site ) : ?>
											<?php $details = get_blog_details( $site_id ); ?>
											<li>
												<?php
												echo wp_kses(
													sprintf(
														/* translators: 1: site name, 2: when, e.g. "3 days ago", 3: number of capabilities. */
														_n( '%1$s: last active %2$s, used %3$s capability', '%1$s: last active %2$s, used %3$s capabilities', $site['caps'], 'role-right-sizer' ),
														esc_html( $details ? $details->blogname : '#' . $site_id ),
														Screen::time_html( $site['last_active'] ),
														number_format_i18n( $site['caps'] )
													),
													array(
														'time' => array(
															'class'    => true,
															'datetime' => true,
															'title'    => true,
														),
														'span' => array( 'class' => true ),
													)
												);
												?>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php endif; ?>
			</section>
			<?php AxleUi::footer(); ?>
		</div>
		<?php
	}
}
