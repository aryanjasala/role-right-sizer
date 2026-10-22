<?php
/**
 * Users > Right-Sizer.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer\Admin;

use AryanX\RoleRightSizer\Applier;
use AryanX\RoleRightSizer\Changes;
use AryanX\RoleRightSizer\Recommender;
use AryanX\RoleRightSizer\Report;
use AryanX\RoleRightSizer\Service;
use AryanX\RoleRightSizer\Settings;
use AryanX\RoleRightSizer\Usage\Store;
use const AryanX\RoleRightSizer\PLUGIN_FILE;
use const AryanX\RoleRightSizer\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Recommendations, the confirmation step, the change log with Undo, settings and the CSV export.
 */
final class Screen implements Service {

	public const SLUG   = 'role-right-sizer';
	public const ACTION = 'role_right_sizer';

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
	 * Works out recommendations.
	 *
	 * @var Recommender
	 */
	private Recommender $recommender;

	/**
	 * Saved recommendations.
	 *
	 * @var Report
	 */
	private Report $report;

	/**
	 * Change log.
	 *
	 * @var Changes
	 */
	private Changes $changes;

	/**
	 * Applies and undoes changes.
	 *
	 * @var Applier
	 */
	private Applier $applier;

	/**
	 * Sets up the screen.
	 *
	 * @param Settings    $settings    Settings and limits.
	 * @param Store       $store       Recorded usage.
	 * @param Recommender $recommender Works out recommendations.
	 * @param Report      $report      Saved recommendations.
	 * @param Changes     $changes     Change log.
	 * @param Applier     $applier     Applies and undoes changes.
	 */
	public function __construct( Settings $settings, Store $store, Recommender $recommender, Report $report, Changes $changes, Applier $applier ) {
		$this->settings    = $settings;
		$this->store       = $store;
		$this->recommender = $recommender;
		$this->report      = $report;
		$this->changes     = $changes;
		$this->applier     = $applier;
	}

	/**
	 * Adds the menu entry and the form handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Capability needed for the screen, the CSV export and the abilities.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to see and apply recommendations.
		 *
		 * @param string $capability Default promote_users.
		 */
		return Settings::text( apply_filters( 'role_right_sizer_capability', 'promote_users' ), 'promote_users' );
	}

	/**
	 * URL of the screen.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'users.php' ) );
	}

	/**
	 * Registers Users > Right-Sizer.
	 */
	public function add_page(): void {
		$hook = add_users_page(
			__( 'Role Right-Sizer', 'role-right-sizer' ),
			__( 'Right-Sizer', 'role-right-sizer' ),
			self::capability(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue_assets' ) );
		}
	}

	/**
	 * Screen styles.
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style( 'role-right-sizer-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
	}

	/**
	 * Display name of a role, or "No role".
	 *
	 * @param string $role Role name.
	 * @return string
	 */
	public static function role_label( string $role ): string {
		if ( '' === $role ) {
			return __( 'No role', 'role-right-sizer' );
		}

		$roles = wp_roles()->roles;

		return isset( $roles[ $role ]['name'] ) ? translate_user_role( (string) $roles[ $role ]['name'] ) : $role;
	}

	/**
	 * Display names of several roles.
	 *
	 * @param string[] $roles Role names.
	 * @return string
	 */
	public static function roles_label( array $roles ): string {
		return $roles ? implode( ', ', array_map( array( self::class, 'role_label' ), $roles ) ) : self::role_label( '' );
	}

	/**
	 * A date, or "Never".
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	public static function date_label( int $time ): string {
		return $time ? (string) wp_date( (string) get_option( 'date_format' ), $time ) : __( 'Never', 'role-right-sizer' );
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navigation only; every change goes through handle().
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'recommendations';
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		// phpcs:enable
		$tabs = array(
			'recommendations' => __( 'Recommendations', 'role-right-sizer' ),
			'changes'         => __( 'Changes', 'role-right-sizer' ),
			'settings'        => __( 'Settings', 'role-right-sizer' ),
		);
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'recommendations';
		?>
		<div class="wrap rrs-page">
			<h1><?php esc_html_e( 'Role Right-Sizer', 'role-right-sizer' ); ?></h1>
			<p class="rrs-intro"><?php esc_html_e( 'Role Right-Sizer records which capabilities each person actually uses, then recommends the smallest role that covers it. Nothing changes until you apply a recommendation, and every change can be undone.', 'role-right-sizer' ); ?></p>

			<?php $this->render_notices(); ?>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Role Right-Sizer sections', 'role-right-sizer' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( array( 'tab' => $key ) ) ); ?>" class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'confirm' === $view ) {
				$this->render_confirm();
			} elseif ( 'changes' === $tab ) {
				$this->render_changes();
			} elseif ( 'settings' === $tab ) {
				$this->render_settings();
			} else {
				$this->render_recommendations();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Prints the learning progress, or the recommendations.
	 */
	private function render_recommendations(): void {
		if ( $this->settings->is_learning() ) {
			$this->render_learning();
			return;
		}

		if ( $this->report->is_stale() ) {
			$this->report->rebuild();
		}

		$report = $this->report->get();
		$self   = get_current_user_id();
		$users  = array(
			'downgrade' => array(),
			'dormant'   => array(),
			'never'     => array(),
		);
		foreach ( $report['users'] as $recommendation ) {
			$users[ $recommendation['type'] ][] = $recommendation;
		}
		?>
		<div class="rrs-toolbar">
			<p class="rrs-built">
				<?php
				if ( $report['built'] ) {
					echo esc_html(
						sprintf(
							/* translators: 1: time since the report was built, e.g. "5 mins", 2: number of accounts, 3: number of days. */
							_n( 'Built %1$s ago from %2$s account with more than read access. Usage counts from the last %3$s days.', 'Built %1$s ago from %2$s accounts with more than read access. Usage counts from the last %3$s days.', $report['scanned'], 'role-right-sizer' ),
							human_time_diff( $report['built'] ),
							number_format_i18n( $report['scanned'] ),
							number_format_i18n( $this->settings->window_days() )
						)
					);
				}
				?>
			</p>
			<?php $this->render_button( 'rebuild', __( 'Rebuild now', 'role-right-sizer' ), 'secondary' ); ?>
			<a class="button" href="
			<?php
			echo esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action' => self::ACTION,
							'task'   => 'export',
						),
						admin_url( 'admin-post.php' ) 
					),
					self::ACTION 
				) 
			);
			?>
									"><?php esc_html_e( 'Download CSV', 'role-right-sizer' ); ?></a>
		</div>

		<?php if ( 'running' === $report['status'] ) : ?>
			<div class="notice notice-info inline"><p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: accounts looked at, 2: accounts in total. */
						__( 'Still building in the background: %1$s of %2$s accounts looked at. Reload this page in a minute.', 'role-right-sizer' ),
						number_format_i18n( $report['scanned'] ),
						number_format_i18n( $report['total'] )
					)
				);
				?>
			</p></div>
		<?php endif; ?>

		<?php if ( ! $report['users'] && ! $report['roles'] ) : ?>
			<div class="rrs-panel rrs-empty">
				<h2><?php esc_html_e( 'Nothing to change', 'role-right-sizer' ); ?></h2>
				<p><?php esc_html_e( 'Every account uses what its role gives it, and every capability on a role is used by someone.', 'role-right-sizer' ); ?></p>
			</div>
			<?php
			return;
		endif;
		?>

		<form method="get" action="<?php echo esc_url( admin_url( 'users.php' ) ); ?>" class="rrs-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="hidden" name="view" value="confirm">
			<?php
			$this->render_user_table(
				'downgrade',
				__( 'Smaller role', 'role-right-sizer' ),
				__( 'These people only used capabilities a smaller role also has.', 'role-right-sizer' ),
				$users['downgrade'],
				$self
			);
			$this->render_user_table(
				'dormant',
				__( 'Dormant accounts', 'role-right-sizer' ),
				/* translators: %s: number of days. */
				sprintf( __( 'No activity for %s days or more.', 'role-right-sizer' ), number_format_i18n( $this->settings->dormant_days() ) ),
				$users['dormant'],
				$self
			);
			$this->render_user_table(
				'never',
				__( 'Never logged in since tracking began', 'role-right-sizer' ),
				/* translators: %s: date tracking began. */
				sprintf( __( 'No login or activity since %s.', 'role-right-sizer' ), self::date_label( $this->settings->tracking_started() ) ),
				$users['never'],
				$self
			);
			$this->render_role_table( $report['roles'] );
			?>
			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Review selected changes', 'role-right-sizer' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Prints the learning period notice.
	 */
	private function render_learning(): void {
		$started = $this->settings->tracking_started();
		$days    = $this->settings->learning_days();
		$ends    = $this->settings->learning_ends();
		$elapsed = max( 0, time() - $started );
		$percent = $days ? min( 100, (int) floor( $elapsed / ( $days * DAY_IN_SECONDS ) * 100 ) ) : 100;
		$left    = max( 1, (int) ceil( ( $ends - time() ) / DAY_IN_SECONDS ) );
		?>
		<section class="rrs-panel rrs-learning">
			<h2><?php esc_html_e( 'Learning how people use the site', 'role-right-sizer' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: days left, 2: date. */
						_n( 'Recommendations show up in %1$s day, on %2$s. Until then Role Right-Sizer only records which capabilities people use.', 'Recommendations show up in %1$s days, on %2$s. Until then Role Right-Sizer only records which capabilities people use.', $left, 'role-right-sizer' ),
						number_format_i18n( $left ),
						self::date_label( $ends )
					)
				);
				?>
			</p>
			<div class="rrs-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>" aria-label="<?php esc_attr_e( 'Learning period', 'role-right-sizer' ); ?>">
				<span style="width: <?php echo esc_attr( (string) $percent ); ?>%"></span>
			</div>
			<p class="rrs-muted">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: date tracking began, 2: number of days. */
						__( 'Tracking began on %1$s. The learning period is %2$s days.', 'role-right-sizer' ),
						self::date_label( $started ),
						number_format_i18n( $days )
					)
				);
				?>
			</p>
		</section>
		<?php
	}

	/**
	 * Prints one table of user recommendations.
	 *
	 * @param string  $type            downgrade, dormant or never.
	 * @param string  $title           Heading.
	 * @param string  $description     One line under the heading.
	 * @param array[] $recommendations Recommendations of this type.
	 * @param int     $current_id      Current user ID.
	 */
	private function render_user_table( string $type, string $title, string $description, array $recommendations, int $current_id ): void {
		if ( ! $recommendations ) {
			return;
		}
		?>
		<section class="rrs-section rrs-section--<?php echo esc_attr( $type ); ?>">
			<h2><?php echo esc_html( $title ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( count( $recommendations ) ) ); ?>)</span></h2>
			<p class="rrs-muted"><?php echo esc_html( $description ); ?></p>
			<table class="wp-list-table widefat striped rrs-table">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column"><input type="checkbox" id="rrs-all-<?php echo esc_attr( $type ); ?>" aria-label="<?php esc_attr_e( 'Select all', 'role-right-sizer' ); ?>"></td>
						<th scope="col" class="column-primary"><?php esc_html_e( 'User', 'role-right-sizer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Role now', 'role-right-sizer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Recommended', 'role-right-sizer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Would lose', 'role-right-sizer' ); ?></th>
						<?php if ( 'downgrade' === $type ) : ?>
							<th scope="col"><?php esc_html_e( 'Used', 'role-right-sizer' ); ?></th>
						<?php endif; ?>
						<th scope="col"><?php esc_html_e( 'Last active', 'role-right-sizer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recommendations as $recommendation ) : ?>
						<?php
						$user = get_userdata( (int) $recommendation['user_id'] );
						if ( ! $user ) {
							continue;
						}
						$is_self = $user->ID === $current_id;
						?>
						<tr data-user="<?php echo esc_attr( $user->user_login ); ?>">
							<th scope="row" class="check-column">
								<?php if ( ! $is_self ) : ?>
									<input type="checkbox" name="users[]" value="<?php echo esc_attr( (string) $user->ID ); ?>" id="rrs-user-<?php echo esc_attr( (string) $user->ID ); ?>">
									<label class="screen-reader-text" for="rrs-user-<?php echo esc_attr( (string) $user->ID ); ?>">
										<?php
										/* translators: %s: user name. */
										echo esc_html( sprintf( __( 'Select %s', 'role-right-sizer' ), $user->display_name ) );
										?>
									</label>
								<?php endif; ?>
							</th>
							<td class="column-primary">
								<strong><?php echo esc_html( $user->display_name ); ?></strong>
								<span class="rrs-muted"><?php echo esc_html( $user->user_login ); ?></span>
								<?php if ( $is_self ) : ?>
									<span class="rrs-note"><?php esc_html_e( 'This is you. Ask another administrator to change your role.', 'role-right-sizer' ); ?></span>
								<?php else : ?>
									<div class="row-actions"><a href="
									<?php
									echo esc_url(
										self::url(
											array(
												'view'  => 'confirm',
												'users' => array( $user->ID ),
											) 
										) 
									);
									?>
																		"><?php esc_html_e( 'Apply', 'role-right-sizer' ); ?></a></div>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( self::roles_label( (array) $recommendation['current'] ) ); ?></td>
							<td class="rrs-proposed"><strong><?php echo esc_html( self::role_label( (string) $recommendation['proposed'] ) ); ?></strong></td>
							<td><?php $this->render_caps( (array) $recommendation['lost'] ); ?></td>
							<?php if ( 'downgrade' === $type ) : ?>
								<td><?php $this->render_used( (array) $recommendation['used'] ); ?></td>
							<?php endif; ?>
							<td><?php echo esc_html( self::date_label( max( (int) $recommendation['last_active'], (int) $recommendation['last_login'] ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Prints the unused capabilities on roles.
	 *
	 * @param array[] $recommendations `unused_cap` recommendations.
	 */
	private function render_role_table( array $recommendations ): void {
		if ( ! $recommendations ) {
			return;
		}
		?>
		<section class="rrs-section rrs-section--roles">
			<h2><?php esc_html_e( 'Unused capabilities on roles', 'role-right-sizer' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( count( $recommendations ) ) ); ?>)</span></h2>
			<p class="rrs-muted"><?php esc_html_e( 'Capabilities a role grants that none of its users used. Usually left behind by a plugin. Core capabilities are not listed here.', 'role-right-sizer' ); ?></p>
			<table class="wp-list-table widefat striped rrs-table">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column"><input type="checkbox" id="rrs-all-roles" aria-label="<?php esc_attr_e( 'Select all', 'role-right-sizer' ); ?>"></td>
						<th scope="col" class="column-primary"><?php esc_html_e( 'Role', 'role-right-sizer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Unused capability', 'role-right-sizer' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Users with the role', 'role-right-sizer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recommendations as $index => $recommendation ) : ?>
						<?php $pair = $recommendation['role'] . '|' . $recommendation['cap']; ?>
						<tr data-role="<?php echo esc_attr( (string) $recommendation['role'] ); ?>" data-cap="<?php echo esc_attr( (string) $recommendation['cap'] ); ?>">
							<th scope="row" class="check-column">
								<input type="checkbox" name="caps[]" value="<?php echo esc_attr( $pair ); ?>" id="rrs-cap-<?php echo esc_attr( (string) $index ); ?>">
								<label class="screen-reader-text" for="rrs-cap-<?php echo esc_attr( (string) $index ); ?>">
									<?php
									/* translators: 1: capability, 2: role. */
									echo esc_html( sprintf( __( 'Select %1$s on %2$s', 'role-right-sizer' ), $recommendation['cap'], self::role_label( (string) $recommendation['role'] ) ) );
									?>
								</label>
							</th>
							<td class="column-primary">
								<strong><?php echo esc_html( self::role_label( (string) $recommendation['role'] ) ); ?></strong>
								<div class="row-actions"><a href="
								<?php
								echo esc_url(
									self::url(
										array(
											'view' => 'confirm',
											'caps' => array( $pair ),
										) 
									) 
								);
								?>
																	"><?php esc_html_e( 'Remove from role', 'role-right-sizer' ); ?></a></div>
							</td>
							<td><code><?php echo esc_html( (string) $recommendation['cap'] ); ?></code></td>
							<td class="num"><?php echo esc_html( number_format_i18n( (int) $recommendation['users'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Prints the confirmation step: every change and every capability it takes away.
	 */
	private function render_confirm(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Shows a preview only; applying needs the nonce in the form below.
		$user_ids = isset( $_GET['users'] ) ? array_map( 'absint', (array) wp_unslash( $_GET['users'] ) ) : array();
		$pairs    = isset( $_GET['caps'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['caps'] ) ) : array();
		// phpcs:enable

		$items = array();
		foreach ( array_unique( array_filter( $user_ids ) ) as $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}
			$recommendation = $this->recommender->for_user( $user, time(), 2 );
			$refused        = $this->applier->check_user( $user, $recommendation ? $recommendation['proposed'] : '' );
			if ( $refused && 'role_right_sizer_self' !== $refused->get_error_code() && ! $recommendation ) {
				$refused = null;
			}
			$items[] = array(
				'field'   => 'users[]',
				'value'   => (string) $user->ID,
				'title'   => $user->display_name . ' (' . $user->user_login . ')',
				'change'  => $recommendation ? self::roles_label( $recommendation['current'] ) . ' → ' . self::role_label( $recommendation['proposed'] ) : '',
				'lost'    => $recommendation ? $recommendation['lost'] : array(),
				'problem' => $refused ? $refused->get_error_message() : ( $recommendation ? '' : __( 'There is nothing to change for this user.', 'role-right-sizer' ) ),
			);
		}

		$known = array();
		foreach ( $this->report->roles() as $recommendation ) {
			$known[ $recommendation['role'] . '|' . $recommendation['cap'] ] = $recommendation;
		}
		foreach ( array_unique( $pairs ) as $pair ) {
			$parts   = explode( '|', $pair, 2 );
			$items[] = array(
				'field'   => 'caps[]',
				'value'   => $pair,
				/* translators: %s: role name. */
				'title'   => sprintf( __( 'Role: %s', 'role-right-sizer' ), self::role_label( $parts[0] ) ),
				/* translators: %s: capability. */
				'change'  => isset( $parts[1] ) ? sprintf( __( 'Remove %s', 'role-right-sizer' ), $parts[1] ) : '',
				'lost'    => isset( $parts[1] ) ? array( $parts[1] ) : array(),
				'problem' => isset( $known[ $pair ] ) ? '' : __( 'That capability is not recommended for removal from this role.', 'role-right-sizer' ),
			);
		}

		$ready = array_filter(
			$items,
			static function ( array $item ): bool {
				return '' === $item['problem'];
			}
		);
		?>
		<section class="rrs-panel rrs-confirm">
			<h2><?php esc_html_e( 'Review the changes', 'role-right-sizer' ); ?></h2>
			<?php if ( ! $items ) : ?>
				<p><?php esc_html_e( 'Nothing was selected.', 'role-right-sizer' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Back to the recommendations', 'role-right-sizer' ); ?></a></p>
			<?php else : ?>
				<p><?php esc_html_e( 'These accounts and roles lose the capabilities listed. Every change goes into the log under Changes, where one click undoes it.', 'role-right-sizer' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="task" value="apply">
					<ul class="rrs-confirm-list">
						<?php foreach ( $items as $item ) : ?>
							<li class="<?php echo '' === $item['problem'] ? 'is-ready' : 'is-refused'; ?>">
								<strong><?php echo esc_html( $item['title'] ); ?></strong>
								<?php if ( '' !== $item['change'] ) : ?>
									<span class="rrs-change"><?php echo esc_html( $item['change'] ); ?></span>
								<?php endif; ?>
								<?php if ( '' === $item['problem'] ) : ?>
									<input type="hidden" name="<?php echo esc_attr( $item['field'] ); ?>" value="<?php echo esc_attr( $item['value'] ); ?>">
									<div class="rrs-lost">
										<?php esc_html_e( 'Loses:', 'role-right-sizer' ); ?>
										<?php foreach ( $item['lost'] as $cap ) : ?>
											<code><?php echo esc_html( (string) $cap ); ?></code>
										<?php endforeach; ?>
									</div>
								<?php else : ?>
									<p class="rrs-problem"><?php echo esc_html( $item['problem'] ); ?></p>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="submit">
						<?php if ( $ready ) : ?>
							<button type="submit" class="button button-primary">
								<?php
								/* translators: %s: number of changes. */
								echo esc_html( sprintf( _n( 'Apply %s change', 'Apply %s changes', count( $ready ), 'role-right-sizer' ), number_format_i18n( count( $ready ) ) ) );
								?>
							</button>
						<?php endif; ?>
						<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'role-right-sizer' ); ?></a>
					</p>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Prints the change log.
	 */
	private function render_changes(): void {
		$all = $this->changes->all();
		if ( ! $all ) {
			?>
			<div class="rrs-panel rrs-empty"><p><?php esc_html_e( 'No changes yet. Changes you apply from the recommendations show up here, with an Undo button.', 'role-right-sizer' ); ?></p></div>
			<?php
			return;
		}

		$per   = 50;
		$pages = (int) ceil( count( $all ) / $per );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination only.
		$paged = isset( $_GET['paged'] ) ? max( 1, min( $pages, absint( $_GET['paged'] ) ) ) : 1;
		$batch = array();
		foreach ( $all as $change ) {
			if ( ! $change['undone'] ) {
				$batch[ (int) $change['batch'] ] = ( $batch[ (int) $change['batch'] ] ?? 0 ) + 1;
			}
		}
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<table class="widefat striped rrs-table rrs-changes">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'role-right-sizer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Change', 'role-right-sizer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Capabilities taken away', 'role-right-sizer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Undo', 'role-right-sizer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $all, ( $paged - 1 ) * $per, $per ) as $change ) : ?>
					<?php $by = get_userdata( (int) $change['by'] ); ?>
					<tr data-change="<?php echo esc_attr( (string) $change['id'] ); ?>">
						<td>
							<?php echo esc_html( (string) wp_date( (string) $format, (int) $change['time'] ) ); ?><br>
							<span class="rrs-muted">
								<?php
								if ( $by ) {
									/* translators: %s: user name. */
									echo esc_html( sprintf( __( 'by %s', 'role-right-sizer' ), $by->display_name ) );
								} elseif ( 0 === (int) $change['by'] ) {
									esc_html_e( 'by WP-CLI', 'role-right-sizer' );
								} else {
									esc_html_e( 'by (removed)', 'role-right-sizer' );
								}
								?>
							</span>
						</td>
						<td>
							<?php if ( 'unused_cap' === $change['kind'] ) : ?>
								<strong><?php echo esc_html( self::role_label( (string) $change['role'] ) ); ?></strong>
								<span class="rrs-change">
									<?php
									/* translators: %s: capability. */
									echo esc_html( sprintf( __( 'Removed %s from the role', 'role-right-sizer' ), $change['cap'] ) );
									?>
								</span>
							<?php else : ?>
								<?php $user = get_userdata( (int) $change['user_id'] ); ?>
								<strong><?php echo esc_html( $user ? $user->display_name : (string) $change['login'] ); ?></strong>
								<span class="rrs-change"><?php echo esc_html( self::roles_label( (array) $change['before'] ) . ' → ' . self::roles_label( (array) $change['after'] ) ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php $this->render_caps( (array) $change['lost'] ); ?></td>
						<td class="rrs-undo">
							<?php if ( $change['undone'] ) : ?>
								<span class="rrs-muted">
									<?php
									/* translators: %s: date and time. */
									echo esc_html( sprintf( __( 'Undone %s', 'role-right-sizer' ), wp_date( (string) $format, (int) $change['undone'] ) ) );
									?>
								</span>
							<?php else : ?>
								<?php
								$this->render_button( 'undo', __( 'Undo', 'role-right-sizer' ), 'secondary', array( 'change' => $change['id'] ) );
								if ( (int) $change['batch'] === (int) $change['id'] && ( $batch[ (int) $change['batch'] ] ?? 0 ) > 1 ) {
									/* translators: %s: number of changes. */
									$this->render_button( 'undo_batch', sprintf( __( 'Undo all %s in this batch', 'role-right-sizer' ), number_format_i18n( $batch[ (int) $change['batch'] ] ) ), 'link', array( 'batch' => $change['batch'] ) );
								}
								?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					(string) paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%', self::url( array( 'tab' => 'changes' ) ) ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				);
				?>
			</div></div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Prints the settings form.
	 */
	private function render_settings(): void {
		$settings = $this->settings->all();
		$roles    = wp_roles()->roles;
		$logins   = array();
		foreach ( $settings['excluded_users'] as $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$logins[] = $user->user_login;
			}
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rrs-panel rrs-settings">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="task" value="settings">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Dormant accounts', 'role-right-sizer' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Dormant accounts', 'role-right-sizer' ); ?></legend>
							<label><input type="radio" name="dormant_action" value="remove" <?php checked( 'remove', $settings['dormant_action'] ); ?>> <?php esc_html_e( 'Recommend taking every role away on this site (the account stays)', 'role-right-sizer' ); ?></label><br>
							<label><input type="radio" name="dormant_action" value="fallback" <?php checked( 'fallback', $settings['dormant_action'] ); ?>> <?php esc_html_e( 'Recommend moving them to', 'role-right-sizer' ); ?></label>
							<label class="screen-reader-text" for="rrs-fallback-role"><?php esc_html_e( 'Fallback role', 'role-right-sizer' ); ?></label>
							<select id="rrs-fallback-role" name="fallback_role">
								<?php foreach ( $roles as $name => $role ) : ?>
									<option value="<?php echo esc_attr( (string) $name ); ?>" <?php selected( $settings['fallback_role'], $name ); ?>><?php echo esc_html( translate_user_role( (string) $role['name'] ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'A fallback role that gives more than the account has now is skipped.', 'role-right-sizer' ); ?></p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Never touch these roles', 'role-right-sizer' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Never touch these roles', 'role-right-sizer' ); ?></legend>
							<?php foreach ( $roles as $name => $role ) : ?>
								<label class="rrs-role-option"><input type="checkbox" name="excluded_roles[]" value="<?php echo esc_attr( (string) $name ); ?>" <?php checked( in_array( $name, $settings['excluded_roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( (string) $role['name'] ) ); ?></label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'People with one of these roles keep it, and the role keeps all its capabilities.', 'role-right-sizer' ); ?></p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rrs-excluded-users"><?php esc_html_e( 'Never touch these people', 'role-right-sizer' ); ?></label></th>
					<td>
						<textarea id="rrs-excluded-users" name="excluded_users" rows="4" class="regular-text"><?php echo esc_textarea( implode( "\n", $logins ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Usernames or email addresses, one per line. For example a break-glass admin account that is never used.', 'role-right-sizer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Timing', 'role-right-sizer' ); ?></th>
					<td>
						<ul class="rrs-facts">
							<li>
								<?php
								/* translators: %s: date. */
								echo esc_html( sprintf( __( 'Tracking began on %s.', 'role-right-sizer' ), self::date_label( $this->settings->tracking_started() ) ) );
								?>
							</li>
							<li>
								<?php
								/* translators: %s: number of days. */
								echo esc_html( sprintf( __( 'Learning period: %s days.', 'role-right-sizer' ), number_format_i18n( $this->settings->learning_days() ) ) );
								?>
							</li>
							<li>
								<?php
								/* translators: %s: number of days. */
								echo esc_html( sprintf( __( 'A capability counts as used for %s days.', 'role-right-sizer' ), number_format_i18n( $this->settings->window_days() ) ) );
								?>
							</li>
							<li>
								<?php
								/* translators: %s: number of days. */
								echo esc_html( sprintf( __( 'An account is dormant after %s days without activity.', 'role-right-sizer' ), number_format_i18n( $this->settings->dormant_days() ) ) );
								?>
							</li>
						</ul>
						<p class="description"><?php esc_html_e( 'Change these with the filters listed in the plugin reference.', 'role-right-sizer' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'role-right-sizer' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Prints a list of capabilities, folded when long.
	 *
	 * @param string[] $caps Capabilities.
	 */
	private function render_caps( array $caps ): void {
		if ( ! $caps ) {
			echo '<span class="rrs-muted">' . esc_html__( 'None', 'role-right-sizer' ) . '</span>';
			return;
		}
		?>
		<details class="rrs-caps">
			<summary>
				<?php
				/* translators: %s: number of capabilities. */
				echo esc_html( sprintf( _n( '%s capability', '%s capabilities', count( $caps ), 'role-right-sizer' ), number_format_i18n( count( $caps ) ) ) );
				?>
			</summary>
			<ul>
				<?php foreach ( $caps as $cap ) : ?>
					<li><code><?php echo esc_html( (string) $cap ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	/**
	 * Prints the capabilities a user used, with the last date for each.
	 *
	 * @param array<string, int> $used `cap => last used`.
	 */
	private function render_used( array $used ): void {
		if ( ! $used ) {
			echo '<span class="rrs-muted">' . esc_html__( 'Nothing beyond reading', 'role-right-sizer' ) . '</span>';
			return;
		}
		arsort( $used );
		?>
		<details class="rrs-caps">
			<summary>
				<?php
				/* translators: %s: number of capabilities. */
				echo esc_html( sprintf( _n( '%s capability', '%s capabilities', count( $used ), 'role-right-sizer' ), number_format_i18n( count( $used ) ) ) );
				?>
			</summary>
			<ul>
				<?php foreach ( $used as $cap => $last ) : ?>
					<li><code><?php echo esc_html( (string) $cap ); ?></code> <span class="rrs-muted"><?php echo esc_html( self::date_label( (int) $last ) ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	/**
	 * Prints a one-button form that runs a task.
	 *
	 * @param string $task   Task name.
	 * @param string $label  Button text.
	 * @param string $style  primary, secondary or link.
	 * @param array  $fields Extra hidden fields.
	 */
	private function render_button( string $task, string $label, string $style, array $fields = array() ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rrs-inline-form">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="task" value="<?php echo esc_attr( $task ); ?>">
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<button type="submit" class="<?php echo esc_attr( 'link' === $style ? 'button-link' : 'button button-' . $style ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Messages for the errors an action can return.
	 *
	 * @return array<string, string>
	 */
	private static function error_messages(): array {
		return array(
			'role_right_sizer_self'       => __( 'You cannot change your own role here.', 'role-right-sizer' ),
			'role_right_sizer_excluded'   => __( 'One of the selected accounts or roles is on the exclude list.', 'role-right-sizer' ),
			'role_right_sizer_last_admin' => __( 'The last administrator keeps their role.', 'role-right-sizer' ),
			'role_right_sizer_forbidden'  => __( 'You are not allowed to change one of the selected accounts.', 'role-right-sizer' ),
			'role_right_sizer_nothing'    => __( 'One of the selected recommendations no longer applies.', 'role-right-sizer' ),
			'role_right_sizer_not_found'  => __( 'That change or account no longer exists.', 'role-right-sizer' ),
			'role_right_sizer_undone'     => __( 'That change was already undone.', 'role-right-sizer' ),
			'role_right_sizer_gone'       => __( 'The account or role of that change no longer exists.', 'role-right-sizer' ),
		);
	}

	/**
	 * Prints the result of the last action.
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		$done   = isset( $_GET['rrs_done'] ) ? sanitize_key( wp_unslash( $_GET['rrs_done'] ) ) : '';
		$count  = isset( $_GET['rrs_count'] ) ? absint( $_GET['rrs_count'] ) : 0;
		$batch  = isset( $_GET['rrs_batch'] ) ? absint( $_GET['rrs_batch'] ) : 0;
		$errors = isset( $_GET['rrs_errors'] ) ? array_filter( array_map( 'sanitize_key', explode( ',', sanitize_text_field( wp_unslash( $_GET['rrs_errors'] ) ) ) ) ) : array();
		// phpcs:enable
		$message = '';

		switch ( $done ) {
			case 'applied':
				/* translators: %s: number of changes. */
				$message = sprintf( _n( 'Applied %s change.', 'Applied %s changes.', $count, 'role-right-sizer' ), number_format_i18n( $count ) );
				break;
			case 'undone':
				/* translators: %s: number of changes. */
				$message = sprintf( _n( 'Undid %s change.', 'Undid %s changes.', $count, 'role-right-sizer' ), number_format_i18n( $count ) );
				break;
			case 'rebuilt':
				$message = __( 'Recommendations rebuilt.', 'role-right-sizer' );
				break;
			case 'saved':
				$message = __( 'Settings saved. Recommendations rebuilt.', 'role-right-sizer' );
				break;
		}

		if ( '' !== $message ) {
			?>
			<div class="notice notice-success is-dismissible rrs-notice">
				<p>
					<?php echo esc_html( $message ); ?>
					<?php if ( 'applied' === $done && $batch && $count ) : ?>
						<?php $this->render_button( 'undo_batch', $count > 1 ? __( 'Undo all', 'role-right-sizer' ) : __( 'Undo', 'role-right-sizer' ), 'link', array( 'batch' => $batch ) ); ?>
					<?php endif; ?>
				</p>
			</div>
			<?php
		}

		$messages = self::error_messages();
		foreach ( array_unique( $errors ) as $code ) {
			$code = 0 === strpos( $code, 'role_right_sizer_' ) ? $code : 'role_right_sizer_' . $code;
			if ( isset( $messages[ $code ] ) ) {
				printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $messages[ $code ] ) );
			}
		}
	}

	/**
	 * Handles every form on the screen and the CSV download.
	 */
	public function handle(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change roles on this site.', 'role-right-sizer' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$task = isset( $_REQUEST['task'] ) ? sanitize_key( wp_unslash( $_REQUEST['task'] ) ) : '';
		$args = array();
		$tab  = 'changes';

		switch ( $task ) {
			case 'apply':
				$users  = isset( $_POST['users'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['users'] ) ) : array();
				$caps   = isset( $_POST['caps'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['caps'] ) ) : array();
				$result = $this->applier->apply_many( array_filter( $users ), $caps );
				$args   = array(
					'rrs_done'   => 'applied',
					'rrs_count'  => count( $result['changes'] ),
					'rrs_batch'  => $result['batch'],
					'rrs_errors' => self::codes( $result['errors'] ),
				);
				break;

			case 'undo':
				$result = $this->applier->undo( isset( $_POST['change'] ) ? absint( $_POST['change'] ) : 0 );
				$args   = is_wp_error( $result ) ? array( 'rrs_errors' => $result->get_error_code() ) : array(
					'rrs_done'  => 'undone',
					'rrs_count' => 1,
				);
				break;

			case 'undo_batch':
				$result = $this->applier->undo_batch( isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 0 );
				$args   = array(
					'rrs_done'   => 'undone',
					'rrs_count'  => count( $result['undone'] ),
					'rrs_errors' => self::codes( $result['errors'] ),
				);
				break;

			case 'rebuild':
				$this->report->rebuild();
				$tab  = 'recommendations';
				$args = array( 'rrs_done' => 'rebuilt' );
				break;

			case 'settings':
				$this->settings->save(
					array(
						'dormant_action' => isset( $_POST['dormant_action'] ) ? sanitize_key( wp_unslash( $_POST['dormant_action'] ) ) : 'remove',
						'fallback_role'  => isset( $_POST['fallback_role'] ) ? sanitize_key( wp_unslash( $_POST['fallback_role'] ) ) : '',
						'excluded_roles' => isset( $_POST['excluded_roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['excluded_roles'] ) ) : array(),
						'excluded_users' => self::user_ids( isset( $_POST['excluded_users'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excluded_users'] ) ) : '' ),
					)
				);
				$this->report->rebuild();
				$tab  = 'settings';
				$args = array( 'rrs_done' => 'saved' );
				break;

			case 'export':
				$this->export();
				exit;
		}

		$args['tab'] = $tab;
		wp_safe_redirect( self::url( array_filter( $args ) ) );
		exit;
	}

	/**
	 * Error codes for the redirect.
	 *
	 * @param \WP_Error[] $errors Errors.
	 * @return string
	 */
	private static function codes( array $errors ): string {
		$codes = array();
		foreach ( $errors as $error ) {
			$codes[] = str_replace( 'role_right_sizer_', '', (string) $error->get_error_code() );
		}

		return implode( ',', array_unique( $codes ) );
	}

	/**
	 * User IDs from a list of usernames, email addresses or IDs.
	 *
	 * @param string $text One per line, or comma separated.
	 * @return int[]
	 */
	public static function user_ids( string $text ): array {
		$ids = array();
		foreach ( (array) preg_split( '/[\s,]+/', $text ) as $item ) {
			if ( '' === $item ) {
				continue;
			}
			if ( ctype_digit( $item ) ) {
				$user = get_userdata( (int) $item );
			} elseif ( false !== strpos( $item, '@' ) ) {
				$user = get_user_by( 'email', $item );
			} else {
				$user = get_user_by( 'login', $item );
			}
			if ( $user ) {
				$ids[] = (int) $user->ID;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Column names of the CSV.
	 *
	 * @return string[]
	 */
	public static function csv_columns(): array {
		return array( 'user_id', 'user_login', 'display_name', 'roles', 'recommendation', 'recommended_role', 'capabilities_lost', 'last_login', 'last_active', 'used_capabilities' );
	}

	/**
	 * One CSV row for a user.
	 *
	 * @param \WP_User   $user           The user.
	 * @param array|null $recommendation Their recommendation, if any.
	 * @param array      $usage          Their usage, `cap => [ first, last, count ]`.
	 * @param int        $last_login     Last login.
	 * @param int        $last_active    Last activity on this site.
	 * @return array<string, string|int>
	 */
	public static function csv_row( \WP_User $user, ?array $recommendation, array $usage, int $last_login, int $last_active ): array {
		$used = array();
		foreach ( $usage as $cap => $times ) {
			$used[] = $cap . ' (' . gmdate( 'Y-m-d', (int) $times[1] ) . ')';
		}

		return array(
			'user_id'           => $user->ID,
			'user_login'        => $user->user_login,
			'display_name'      => $user->display_name,
			'roles'             => implode( ' ', (array) $user->roles ),
			'recommendation'    => $recommendation ? (string) $recommendation['type'] : '',
			'recommended_role'  => $recommendation ? ( '' === $recommendation['proposed'] ? 'none' : (string) $recommendation['proposed'] ) : '',
			'capabilities_lost' => $recommendation ? implode( ' ', (array) $recommendation['lost'] ) : '',
			'last_login'        => $last_login ? gmdate( 'Y-m-d H:i:s', $last_login ) : '',
			'last_active'       => $last_active ? gmdate( 'Y-m-d H:i:s', $last_active ) : '',
			'used_capabilities' => implode( '; ', $used ),
		);
	}

	/**
	 * One CSV line, with formula-like values defused for spreadsheets.
	 *
	 * @param array $cells Values.
	 * @return string
	 */
	public static function csv_line( array $cells ): string {
		$out = array();
		foreach ( $cells as $cell ) {
			$cell = (string) $cell;
			if ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
				$cell = "'" . $cell;
			}
			$out[] = '"' . str_replace( '"', '""', $cell ) . '"';
		}

		return implode( ',', $out ) . "\r\n";
	}

	/**
	 * Streams every user on the site as CSV, 100 users per query.
	 */
	private function export(): void {
		$recommendations = $this->report->get()['users'];

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=role-right-sizer-' . gmdate( 'Y-m-d' ) . '.csv' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, not HTML. csv_line() quotes every cell.
		echo self::csv_line( self::csv_columns() );

		$page = 1;
		do {
			$users = get_users(
				array(
					'number'  => 100,
					'paged'   => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
			foreach ( $users as $user ) {
				$row = self::csv_row(
					$user,
					isset( $recommendations[ $user->ID ] ) ? (array) $recommendations[ $user->ID ] : null,
					$this->store->usage( $user->ID ),
					$this->store->last_login( $user->ID ),
					$this->store->last_active( $user->ID )
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, not HTML. csv_line() quotes every cell.
				echo self::csv_line( $row );
			}
			++$page;
			$found = count( $users );
		} while ( 100 === $found );
	}
}
