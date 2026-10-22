<?php
/**
 * Network Admin > Users > Right-Sizer: super admins' activity, read-only.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer\Admin;

use AryanX\RoleRightSizer\Service;
use AryanX\RoleRightSizer\Usage\Store;
use const AryanX\RoleRightSizer\PLUGIN_FILE;
use const AryanX\RoleRightSizer\VERSION;

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
			add_action(
				"admin_print_styles-{$hook}",
				static function () {
					wp_enqueue_style( 'role-right-sizer-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
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
		<div class="wrap rrs-page">
			<h1><?php esc_html_e( 'Role Right-Sizer', 'role-right-sizer' ); ?></h1>
			<p class="rrs-intro"><?php esc_html_e( 'Super admins can do everything on every site, so Role Right-Sizer makes no recommendations for them. Here is what each one actually did, so you can spot accounts that no longer need super admin.', 'role-right-sizer' ); ?></p>
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
							<td><strong><?php echo esc_html( $admin->display_name ); ?></strong> <span class="rrs-muted"><?php echo esc_html( $admin->user_login ); ?></span></td>
							<td><?php echo esc_html( Screen::date_label( $this->store->last_login( $admin->ID ) ) ); ?></td>
							<td>
								<?php if ( ! $sites ) : ?>
									<span class="rrs-muted"><?php esc_html_e( 'No activity recorded', 'role-right-sizer' ); ?></span>
								<?php else : ?>
									<ul class="rrs-sites">
										<?php foreach ( $sites as $site_id => $site ) : ?>
											<?php $details = get_blog_details( $site_id ); ?>
											<li>
												<?php
												echo esc_html(
													sprintf(
														/* translators: 1: site name, 2: date, 3: number of capabilities. */
														_n( '%1$s: last active %2$s, used %3$s capability', '%1$s: last active %2$s, used %3$s capabilities', $site['caps'], 'role-right-sizer' ),
														$details ? $details->blogname : '#' . $site_id,
														Screen::date_label( $site['last_active'] ),
														number_format_i18n( $site['caps'] )
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
		<?php
	}
}
