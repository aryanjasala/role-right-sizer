<?php
/**
 * Users > Right-Sizer.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Admin;

use AryanX\Axle\RoleRightSizer\Access;
use AryanX\Axle\RoleRightSizer\Applier;
use AryanX\Axle\RoleRightSizer\CapabilityOwners;
use AryanX\Axle\RoleRightSizer\Changes;
use AryanX\Axle\RoleRightSizer\Recommender;
use AryanX\Axle\RoleRightSizer\Report;
use AryanX\Axle\RoleRightSizer\Service;
use AryanX\Axle\RoleRightSizer\Settings;
use AryanX\Axle\RoleRightSizer\Trials;
use AryanX\Axle\RoleRightSizer\Usage\Store;
use const AryanX\Axle\RoleRightSizer\PLUGIN_FILE;
use const AryanX\Axle\RoleRightSizer\VERSION;

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
	 * Trials of a smaller role.
	 *
	 * @var Trials
	 */
	private Trials $trials;

	/**
	 * Sets up the screen.
	 *
	 * @param Settings    $settings    Settings and limits.
	 * @param Store       $store       Recorded usage.
	 * @param Recommender $recommender Works out recommendations.
	 * @param Report      $report      Saved recommendations.
	 * @param Changes     $changes     Change log.
	 * @param Applier     $applier     Applies and undoes changes.
	 * @param Trials      $trials      Trials of a smaller role.
	 */
	public function __construct( Settings $settings, Store $store, Recommender $recommender, Report $report, Changes $changes, Applier $applier, Trials $trials ) {
		$this->trials      = $trials;
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
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds a link to the screen in the plugin's row on the Plugins screen.
	 *
	 * @param mixed $links Links shown under the plugin name.
	 * @return mixed
	 */
	public function action_links( $links ) {
		if ( is_array( $links ) && current_user_can( self::capability() ) ) {
			array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open Right-Sizer', 'role-right-sizer' ) . '</a>' );
		}

		return $links;
	}

	/**
	 * Adds the Help tabs to the screen.
	 */
	public function help(): void {
		AxleUi::help_tabs(
			array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'role-right-sizer' ),
					'content' => '<p>' . esc_html__( 'Role Right-Sizer records which capabilities each person actually uses, then recommends the smallest role that covers it. Nothing changes until you apply a recommendation, and every change can be undone from the Changes tab.', 'role-right-sizer' ) . '</p><p>' . esc_html__( 'For the first 30 days it only learns. After that the Recommendations tab shows who could have a smaller role, which accounts are dormant, and which capabilities on roles nobody uses.', 'role-right-sizer' ) . '</p>',
				),
				array(
					'id'      => 'recommendations',
					'title'   => __( 'Recommendations', 'role-right-sizer' ),
					'content' => '<p>' . esc_html__( 'Tick the people or capabilities you want to change and choose Review selected changes. You see every capability that would be lost before anything happens. Rebuild now looks at the accounts again, and Download CSV exports every account you may edit.', 'role-right-sizer' ) . '</p>',
				),
				array(
					'id'      => 'trials',
					'title'   => __( 'Trials', 'role-right-sizer' ),
					'content' => '<p>' . esc_html__( 'Try for 14 days gives someone the smaller role for a while instead of for good. While the trial runs, Right-Sizer records what they were not allowed to do. They see a message with a button to ask for their access back.', 'role-right-sizer' ) . '</p><p>' . esc_html__( 'On the Trials tab you can restore the old role at any time, add more days, or make the smaller role permanent. A trial where the person hit a wall is never made permanent on its own.', 'role-right-sizer' ) . '</p>',
				),
				array(
					'id'      => 'changes',
					'title'   => __( 'Changes and Undo', 'role-right-sizer' ),
					'content' => '<p>' . esc_html__( 'Every change is logged with the roles or capabilities it replaced. Undo puts them back. Changes applied together share a batch, and one click undoes the whole batch.', 'role-right-sizer' ) . '</p>',
				),
				array(
					'id'      => 'settings',
					'title'   => __( 'Settings', 'role-right-sizer' ),
					'content' => '<p>' . esc_html__( 'Choose what happens to dormant accounts, which roles and people are never touched, and what happens when a trial ends with no denials. The last administrator is always left alone.', 'role-right-sizer' ) . '</p>',
				),
			)
		);
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
			add_action( "load-{$hook}", array( $this, 'help' ) );
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue_assets' ) );
		}
	}

	/**
	 * Screen styles, and the script behind the review bar and the group checkboxes.
	 */
	public function enqueue_assets(): void {
		AxleUi::enqueue();
		wp_enqueue_style( 'role-right-sizer-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
		wp_enqueue_script( 'role-right-sizer-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-hooks', 'wp-i18n', 'wp-a11y' ), VERSION, true );
		wp_set_script_translations( 'role-right-sizer-admin', 'role-right-sizer' );
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
	 * Tags a time may contain after AxleUi::relative_time().
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function time_tags(): array {
		return array(
			'time' => array(
				'class'    => true,
				'datetime' => true,
				'title'    => true,
			),
			'span' => array( 'class' => true ),
		);
	}

	/**
	 * A time as "5 minutes ago", for use inside a sentence that is printed with wp_kses().
	 *
	 * @param int    $time     Unix time.
	 * @param string $fallback What to show for no time.
	 * @return string HTML.
	 */
	public static function time_html( int $time, string $fallback = '' ): string {
		return wp_kses( AxleUi::relative_time( $time, $fallback ), self::time_tags() );
	}

	/**
	 * Prints a time as "5 minutes ago", with the exact time on hover.
	 *
	 * @param int    $time     Unix time.
	 * @param string $fallback What to show for no time.
	 */
	public static function print_time( int $time, string $fallback = '' ): void {
		echo wp_kses( AxleUi::relative_time( $time, $fallback ), self::time_tags() );
	}

	/**
	 * Prints a button that runs a task. When it overwrites roles, it opens an in-place
	 * confirmation that says what changes and whether it can be undone.
	 *
	 * @param string $task    Task name.
	 * @param string $label   Button text.
	 * @param array  $fields  Hidden fields.
	 * @param array  $options `style` (primary, secondary, link), `confirm` (what will change, shows the confirmation), `confirm_label` (the button that does it), `danger` (red confirmation).
	 */
	public static function print_action( string $task, string $label, array $fields = array(), array $options = array() ): void {
		static $count = 0;
		++$count;

		$style   = (string) ( $options['style'] ?? 'secondary' );
		$confirm = (string) ( $options['confirm'] ?? '' );
		$class   = 'link' === $style ? 'button-link' : 'button button-' . $style;
		$id      = 'rrs-confirm-' . $count;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rrs-inline-form">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="task" value="<?php echo esc_attr( $task ); ?>">
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<?php if ( '' === $confirm ) : ?>
				<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
			<?php else : ?>
				<details class="rrs-confirm-inline">
					<summary class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></summary>
					<div class="axle-confirm<?php echo ! empty( $options['danger'] ) ? ' axle-confirm--danger' : ''; ?>" role="group" aria-labelledby="<?php echo esc_attr( $id ); ?>">
						<p id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $confirm ); ?></p>
						<div class="axle-actions">
							<button type="submit" class="button button-primary<?php echo ! empty( $options['danger'] ) ? ' axle-button--danger' : ''; ?>"><?php echo esc_html( (string) ( $options['confirm_label'] ?? $label ) ); ?></button>
							<button type="button" class="button rrs-confirm-cancel"><?php esc_html_e( 'Cancel', 'role-right-sizer' ); ?></button>
						</div>
					</div>
				</details>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navigation only; every change goes through handle().
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'recommendations';
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		// phpcs:enable
		$open   = count( $this->trials->open() );
		$tabs   = array(
			'recommendations' => __( 'Recommendations', 'role-right-sizer' ),
			'trials'          => __( 'Trials', 'role-right-sizer' ),
			'changes'         => __( 'Changes', 'role-right-sizer' ),
			'settings'        => __( 'Settings', 'role-right-sizer' ),
		);
		$tab    = isset( $tabs[ $tab ] ) ? $tab : 'recommendations';
		$intros = array(
			'recommendations' => __( 'Role Right-Sizer records which capabilities each person actually uses, then recommends the smallest role that covers it. Nothing changes until you apply a recommendation, and every change can be undone.', 'role-right-sizer' ),
			'trials'          => __( 'Give someone the smaller role for a while. If they hit a wall, you see exactly what they could not do, and one click gives the old role back.', 'role-right-sizer' ),
			'changes'         => __( 'Every change you applied, newest first. Undo puts the earlier roles and capabilities back.', 'role-right-sizer' ),
			'settings'        => __( 'Choose what Role Right-Sizer recommends for dormant accounts, which roles and people it never touches, and what happens when a trial ends.', 'role-right-sizer' ),
		);
		$intro  = 'confirm' === $view ? __( 'Check every change before it is applied. Each one goes into the log, where one click undoes it.', 'role-right-sizer' ) : $intros[ $tab ];
		?>
		<div class="wrap axle-ui rrs-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Role Right-Sizer', 'role-right-sizer' ); ?></h1>
			<?php if ( $this->settings->is_learning() ) : ?>
				<span class="axle-status axle-badge axle-badge--info"><?php esc_html_e( 'Learning', 'role-right-sizer' ); ?></span>
			<?php elseif ( $open ) : ?>
				<span class="axle-status axle-badge axle-badge--info">
					<?php
					/* translators: %s: number of trials. */
					echo esc_html( sprintf( _n( '%s trial running', '%s trials running', $open, 'role-right-sizer' ), number_format_i18n( $open ) ) );
					?>
				</span>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Role Right-Sizer sections', 'role-right-sizer' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( array( 'tab' => $key ) ) ); ?>" class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>>
						<?php
						echo esc_html( $label );
						if ( 'trials' === $key && $open ) {
							echo ' <span class="count">(' . esc_html( number_format_i18n( $open ) ) . ')</span>';
						}
						?>
					</a>
				<?php endforeach; ?>
			</nav>

			<p class="axle-intro"><?php echo esc_html( $intro ); ?></p>

			<?php
			if ( 'confirm' === $view ) {
				$this->render_confirm();
			} elseif ( 'trials' === $tab ) {
				( new TrialsTab( $this->trials ) )->render();
			} elseif ( 'changes' === $tab ) {
				$this->render_changes();
			} elseif ( 'settings' === $tab ) {
				$this->render_settings();
			} else {
				$this->render_steps();
				$this->render_recommendations();
			}
			AxleUi::footer();
			?>
		</div>
		<?php
	}

	/**
	 * Prints the "Get started" card until its steps are done or dismissed.
	 */
	private function render_steps(): void {
		$user_id = get_current_user_id();
		if ( get_user_meta( $user_id, 'role_right_sizer_steps_dismissed', true ) ) {
			return;
		}

		$learning = $this->settings->is_learning();
		$report   = $this->report->get();
		$steps    = array(
			array(
				'title' => __( 'Let it learn', 'role-right-sizer' ),
				'text'  => __( 'Right-Sizer records which capabilities people use for the first 30 days. It changes nothing in that time.', 'role-right-sizer' ),
				'done'  => ! $learning,
				'url'   => '',
			),
			array(
				'title' => __( 'Look at the recommendations', 'role-right-sizer' ),
				'text'  => __( 'See who could have a smaller role, and what each person would lose.', 'role-right-sizer' ),
				'done'  => ! $learning && $report['built'] > 0,
				'url'   => '',
			),
			array(
				'title' => __( 'Try one, or apply one', 'role-right-sizer' ),
				'text'  => __( 'A trial gives the smaller role for 14 days and shows what the person could not do. Applying it is for good, with Undo.', 'role-right-sizer' ),
				'done'  => (bool) $this->changes->all() || (bool) $this->trials->all(),
				'url'   => '',
			),
		);

		$pending = array_filter(
			$steps,
			static function ( array $step ): bool {
				return ! $step['done'];
			}
		);
		if ( ! $pending ) {
			return;
		}
		?>
		<section class="axle-card rrs-steps">
			<h2 class="axle-card__title"><?php esc_html_e( 'Get started', 'role-right-sizer' ); ?></h2>
			<ol class="axle-steps">
				<?php foreach ( $steps as $step ) : ?>
					<li class="axle-step">
						<div class="axle-step__body">
							<strong class="axle-step__title"><?php echo esc_html( $step['title'] ); ?></strong>
							<p><?php echo esc_html( $step['text'] ); ?></p>
						</div>
						<?php if ( $step['done'] ) : ?>
							<span class="axle-badge axle-badge--good"><?php esc_html_e( 'Done', 'role-right-sizer' ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
			<p class="axle-actions"><?php self::print_action( 'dismiss_steps', __( 'Dismiss this card', 'role-right-sizer' ), array(), array( 'style' => 'link' ) ); ?></p>
		</section>
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
		// Only the accounts this person may edit: a capability list says what an account can do.
		foreach ( Access::visible_recommendations( array_values( $report['users'] ) ) as $recommendation ) {
			$users[ $recommendation['type'] ][] = $recommendation;
		}
		$visible = count( $users['downgrade'] ) + count( $users['dormant'] ) + count( $users['never'] );

		// One query for every account on the screen, not one per row.
		$ids = array();
		foreach ( $users as $list ) {
			foreach ( $list as $recommendation ) {
				$ids[] = (int) $recommendation['user_id'];
			}
		}
		if ( $ids ) {
			cache_users( array_values( array_unique( $ids ) ) );
		}
		?>
		<div class="axle-toolbar">
			<p class="rrs-built axle-muted">
				<?php
				if ( $report['built'] ) {
					echo wp_kses(
						sprintf(
							/* translators: 1: time since the report was built, e.g. "5 minutes ago", 2: number of accounts, 3: number of days. */
							_n( 'Built %1$s from %2$s account with more than read access. Usage counts from the last %3$s days.', 'Built %1$s from %2$s accounts with more than read access. Usage counts from the last %3$s days.', $report['scanned'], 'role-right-sizer' ),
							self::time_html( (int) $report['built'] ),
							number_format_i18n( $report['scanned'] ),
							number_format_i18n( $this->settings->window_days() )
						),
						self::time_tags()
					);
				}
				?>
			</p>
			<span class="axle-toolbar__end">
				<?php self::print_action( 'rebuild', __( 'Rebuild now', 'role-right-sizer' ) ); ?>
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
			</span>
		</div>

		<?php $this->render_skipped( $report ); ?>

		<?php if ( $visible || $report['roles'] ) : ?>
			<div class="axle-stats">
				<?php
				$stats = array(
					array( count( $users['downgrade'] ), __( 'could have a smaller role', 'role-right-sizer' ) ),
					array( count( $users['dormant'] ), __( 'dormant accounts', 'role-right-sizer' ) ),
					array( count( $users['never'] ), __( 'never logged in', 'role-right-sizer' ) ),
					array( count( $report['roles'] ), __( 'unused capabilities on roles', 'role-right-sizer' ) ),
				);
				foreach ( $stats as $stat ) :
					?>
					<div class="axle-stat<?php echo $stat[0] > 0 ? ' axle-stat--warning' : ''; ?>">
						<span class="axle-stat__value"><?php echo esc_html( number_format_i18n( $stat[0] ) ); ?></span>
						<span class="axle-stat__label"><?php echo esc_html( $stat[1] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

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

		<?php if ( ! $visible && ! $report['roles'] ) : ?>
			<div class="axle-empty rrs-empty">
				<p><strong><?php esc_html_e( 'Nothing to change.', 'role-right-sizer' ); ?></strong> <?php esc_html_e( 'Every account uses what its role gives it, and every capability on a role is used by someone. New recommendations show up here when that changes. The list is rebuilt every day.', 'role-right-sizer' ); ?></p>
				<?php self::print_action( 'rebuild', __( 'Rebuild now', 'role-right-sizer' ) ); ?>
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
				/* translators: %s: when tracking began, e.g. "3 months ago". */
				sprintf( __( 'No login or activity since tracking began %s.', 'role-right-sizer' ), self::time_html( $this->settings->tracking_started() ) ),
				$users['never'],
				$self
			);
			$this->render_role_table( $report['roles'] );
			?>
			<div class="rrs-review-bar">
				<button type="submit" class="button button-primary rrs-review-button" data-label="<?php esc_attr_e( 'Review selected changes', 'role-right-sizer' ); ?>"><?php esc_html_e( 'Review selected changes', 'role-right-sizer' ); ?></button>
				<span class="axle-muted"><?php esc_html_e( 'Nothing changes until you confirm on the next screen.', 'role-right-sizer' ); ?></span>
			</div>
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
		<section class="axle-card rrs-learning">
			<h2 class="axle-card__title"><?php esc_html_e( 'Learning how people use the site', 'role-right-sizer' ); ?></h2>
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: 1: days left, 2: when, e.g. "in 3 weeks". */
						_n( 'Recommendations show up in %1$s day (%2$s). Until then Role Right-Sizer only records which capabilities people use.', 'Recommendations show up in %1$s days (%2$s). Until then Role Right-Sizer only records which capabilities people use.', $left, 'role-right-sizer' ),
						number_format_i18n( $left ),
						self::time_html( $ends )
					),
					self::time_tags()
				);
				?>
			</p>
			<div class="axle-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>" aria-label="<?php esc_attr_e( 'Learning period', 'role-right-sizer' ); ?>">
				<span class="axle-progress__bar" style="width: <?php echo esc_attr( (string) $percent ); ?>%"></span>
			</div>
			<p class="axle-muted">
				<?php
				echo wp_kses(
					sprintf(
						/* translators: 1: when tracking began, e.g. "3 days ago", 2: number of days. */
						__( 'Tracking began %1$s. The learning period is %2$s days.', 'role-right-sizer' ),
						self::time_html( $started ),
						number_format_i18n( $days )
					),
					self::time_tags()
				);
				?>
			</p>
			<p class="axle-muted"><?php echo esc_html( sprintf( /* translators: %s: percent. */ __( '%s percent of the learning period is over.', 'role-right-sizer' ), number_format_i18n( $percent ) ) ); ?></p>
		</section>
		<?php
	}

	/**
	 * Prints which accounts are not checked, and why.
	 *
	 * @param array $report The saved report.
	 */
	private function render_skipped( array $report ): void {
		$lines = array();
		if ( $report['too_new'] > 0 ) {
			$lines[] = sprintf(
				/* translators: 1: number of accounts, 2: number of days. */
				_n( '%1$s account has not logged in yet but is less than %2$s days old, so it is not flagged yet.', '%1$s accounts have not logged in yet but are less than %2$s days old, so they are not flagged yet.', $report['too_new'], 'role-right-sizer' ),
				number_format_i18n( $report['too_new'] ),
				number_format_i18n( $this->settings->learning_days() )
			);
		}
		if ( $report['read_only'] > 0 ) {
			$lines[] = sprintf(
				/* translators: %s: number of accounts. */
				_n( '%s account has no role beyond reading, so there is nothing to make smaller. It is not checked.', '%s accounts have no role beyond reading, so there is nothing to make smaller. They are not checked.', $report['read_only'], 'role-right-sizer' ),
				number_format_i18n( $report['read_only'] )
			);
		}

		/**
		 * Filters the lines that say which accounts the report did not check, and why.
		 *
		 * @param string[] $lines  Default one line for accounts that never logged in but are younger than the learning period, and one for read-only accounts, when there are any.
		 * @param array    $report The saved report.
		 */
		$lines = Settings::strings( apply_filters( 'role_right_sizer_skipped_lines', $lines, $report ) );
		if ( ! $lines ) {
			return;
		}
		?>
		<p class="rrs-skipped axle-muted"><?php echo esc_html( implode( ' ', $lines ) ); ?></p>
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
		<section class="axle-card rrs-section rrs-section--<?php echo esc_attr( $type ); ?>">
			<h2 class="axle-card__title"><?php echo esc_html( $title ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( count( $recommendations ) ) ); ?>)</span></h2>
			<p class="axle-muted"><?php echo wp_kses( $description, self::time_tags() ); ?></p>
			<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr( $title ); ?>">
			<table class="wp-list-table widefat striped rrs-table">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column"><input type="checkbox" id="rrs-all-<?php echo esc_attr( $type ); ?>" aria-label="<?php esc_attr_e( 'Select all', 'role-right-sizer' ); ?>"></td>
						<th scope="col" class="column-primary"><?php esc_html_e( 'User', 'role-right-sizer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Role now and recommended', 'role-right-sizer' ); ?></th>
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
								<span class="axle-muted"><?php echo esc_html( $user->user_login ); ?></span>
								<?php if ( $this->trials->for_user( $user->ID ) ) : ?>
									<span class="axle-badge axle-badge--info"><?php esc_html_e( 'On trial', 'role-right-sizer' ); ?></span>
								<?php endif; ?>
								<?php if ( $is_self ) : ?>
									<span class="rrs-note axle-muted"><?php esc_html_e( 'This is you. Ask another administrator to change your role.', 'role-right-sizer' ); ?></span>
								<?php else : ?>
									<div class="row-actions">
										<span class="apply"><a href="
										<?php
										echo esc_url(
											self::url(
												array(
													'view' => 'confirm',
													'users' => array( $user->ID ),
												) 
											) 
										);
										?>
																		"><?php esc_html_e( 'Apply', 'role-right-sizer' ); ?></a></span>
										<?php if ( 'downgrade' === $type && in_array( 'downgrade', $this->trials->types(), true ) && ! $this->trials->for_user( $user->ID ) ) : ?>
											<span class="trial"> | <a href="
											<?php
											echo esc_url(
												self::url(
													array(
														'view' => 'confirm',
														'trial' => 1,
														'users' => array( $user->ID ),
													) 
												) 
											);
											?>
																			">
												<?php
												/* translators: %s: number of days. */
												echo esc_html( sprintf( _n( 'Try for %s day', 'Try for %s days', $this->trials->days(), 'role-right-sizer' ), number_format_i18n( $this->trials->days() ) ) );
												?>
											</a></span>
										<?php endif; ?>
									</div>
								<?php endif; ?>
							</td>
							<td class="rrs-role-diff">
								<div class="axle-diff">
									<del><span class="screen-reader-text"><?php esc_html_e( 'Before:', 'role-right-sizer' ); ?></span> <?php echo esc_html( self::roles_label( (array) $recommendation['current'] ) ); ?></del>
									<ins class="rrs-proposed"><span class="screen-reader-text"><?php esc_html_e( 'After:', 'role-right-sizer' ); ?></span> <?php echo esc_html( self::role_label( (string) $recommendation['proposed'] ) ); ?></ins>
								</div>
							</td>
							<td><?php $this->render_caps( (array) $recommendation['lost'] ); ?></td>
							<?php if ( 'downgrade' === $type ) : ?>
								<td><?php $this->render_used( (array) $recommendation['used'] ); ?></td>
							<?php endif; ?>
							<td><?php self::print_time( max( (int) $recommendation['last_active'], (int) $recommendation['last_login'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</section>
		<?php
	}

	/**
	 * Unused capabilities grouped by role and by who they belong to.
	 *
	 * Sorted by role name. Within a role, capabilities nobody can place come
	 * first, then each owner in the order the report found them.
	 *
	 * @param array[] $recommendations `unused_cap` recommendations.
	 * @return array<string, array{key: string, role: string, owner: array|null, users: int, caps: string[]}> By group key.
	 */
	public static function group_role_caps( array $recommendations ): array {
		$groups = array();
		foreach ( $recommendations as $recommendation ) {
			$role  = (string) ( $recommendation['role'] ?? '' );
			$owner = CapabilityOwners::normalize( $recommendation['owner'] ?? null );
			$key   = $role . '|' . CapabilityOwners::key( $owner );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'key'   => $key,
					'role'  => $role,
					'owner' => $owner,
					'users' => (int) ( $recommendation['users'] ?? 0 ),
					'caps'  => array(),
				);
			}
			$groups[ $key ]['caps'][] = (string) ( $recommendation['cap'] ?? '' );
		}

		$order = array_flip( array_keys( $groups ) );
		uksort(
			$groups,
			static function ( $a, $b ) use ( $groups, $order ) {
				return array( $groups[ $a ]['role'], null === $groups[ $a ]['owner'] ? 0 : 1, $order[ $a ] ) <=> array( $groups[ $b ]['role'], null === $groups[ $b ]['owner'] ? 0 : 1, $order[ $b ] );
			}
		);

		return $groups;
	}

	/**
	 * One line about who a capability belongs to.
	 *
	 * @param array|null $owner Owner, see CapabilityOwners.
	 * @return string '' when unknown.
	 */
	public static function owner_label( ?array $owner ): string {
		if ( ! $owner ) {
			return '';
		}

		if ( in_array( $owner['type'], array( 'post_type', 'taxonomy' ), true ) ) {
			/* translators: %s: post type or taxonomy name, e.g. "Products". */
			return sprintf( __( 'Checked by %s, which is registered on this site.', 'role-right-sizer' ), $owner['name'] );
		}

		return $owner['active']
			/* translators: %s: plugin name. */
			? sprintf( __( 'Belongs to %s, which is active.', 'role-right-sizer' ), $owner['name'] )
			/* translators: %s: plugin name or folder. */
			: sprintf( __( 'Added by %s, which is not active.', 'role-right-sizer' ), $owner['name'] );
	}

	/**
	 * Prints the unused capabilities on roles, one row per role and owner.
	 *
	 * @param array[] $recommendations `unused_cap` recommendations.
	 */
	private function render_role_table( array $recommendations ): void {
		if ( ! $recommendations ) {
			return;
		}
		$groups = self::group_role_caps( $recommendations );
		?>
		<section class="axle-card rrs-section rrs-section--roles">
			<h2 class="axle-card__title"><?php esc_html_e( 'Unused capabilities on roles', 'role-right-sizer' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( count( $recommendations ) ) ); ?>)</span></h2>
			<p class="axle-muted"><?php esc_html_e( 'Capabilities a role has had for the whole window that none of its users used. Usually left behind by a plugin. Core capabilities, new ones and the administrator role are not listed here.', 'role-right-sizer' ); ?></p>
			<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Unused capabilities on roles', 'role-right-sizer' ); ?>">
			<table class="wp-list-table widefat striped rrs-table rrs-groups">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column"><input type="checkbox" id="rrs-all-roles" aria-label="<?php esc_attr_e( 'Select all', 'role-right-sizer' ); ?>"></td>
						<th scope="col" class="column-primary"><?php esc_html_e( 'Role', 'role-right-sizer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Unused capabilities', 'role-right-sizer' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Users with the role', 'role-right-sizer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_values( $groups ) as $index => $group ) : ?>
						<?php
						$role_label = self::role_label( $group['role'] );
						$count      = count( $group['caps'] );
						$owner      = self::owner_label( $group['owner'] );
						?>
						<tr class="rrs-group<?php echo $group['owner'] && $group['owner']['active'] ? ' rrs-group--active-owner' : ''; ?>" data-role="<?php echo esc_attr( $group['role'] ); ?>" data-owner="<?php echo esc_attr( CapabilityOwners::key( $group['owner'] ) ); ?>">
							<th scope="row" class="check-column">
								<input type="checkbox" name="groups[]" value="<?php echo esc_attr( $group['key'] ); ?>" id="rrs-group-<?php echo esc_attr( (string) $index ); ?>">
								<label class="screen-reader-text" for="rrs-group-<?php echo esc_attr( (string) $index ); ?>">
									<?php
									/* translators: 1: number of capabilities, 2: role. */
									echo esc_html( sprintf( _n( 'Select the %1$s unused capability on %2$s', 'Select all %1$s unused capabilities on %2$s', $count, 'role-right-sizer' ), number_format_i18n( $count ), $role_label ) );
									?>
								</label>
							</th>
							<td class="column-primary">
								<strong><?php echo esc_html( $role_label ); ?></strong>
								<?php if ( '' !== $owner ) : ?>
									<span class="rrs-owner axle-muted"><?php echo esc_html( $owner ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<details class="rrs-caps">
									<summary>
										<?php
										/* translators: %s: number of capabilities. */
										echo esc_html( sprintf( _n( '%s capability', '%s capabilities', $count, 'role-right-sizer' ), number_format_i18n( $count ) ) );
										?>
									</summary>
									<ul class="rrs-group-caps">
										<?php foreach ( $group['caps'] as $cap_index => $cap ) : ?>
											<?php
											$pair = $group['role'] . '|' . $cap;
											$id   = 'rrs-cap-' . $index . '-' . $cap_index;
											?>
											<li data-role="<?php echo esc_attr( $group['role'] ); ?>" data-cap="<?php echo esc_attr( $cap ); ?>">
												<input type="checkbox" name="caps[]" value="<?php echo esc_attr( $pair ); ?>" id="<?php echo esc_attr( $id ); ?>">
												<label for="<?php echo esc_attr( $id ); ?>"><code><?php echo esc_html( $cap ); ?></code></label>
												<?php
												$link = self::url(
													array(
														'view' => 'confirm',
														'caps' => array( $pair ),
													)
												);
												/* translators: 1: capability, 2: role. */
												$link_label = sprintf( __( 'Remove %1$s from %2$s', 'role-right-sizer' ), $cap, $role_label );
												?>
												<a class="rrs-remove" href="<?php echo esc_url( $link ); ?>" aria-label="<?php echo esc_attr( $link_label ); ?>"><?php esc_html_e( 'Remove from role', 'role-right-sizer' ); ?></a>
											</li>
										<?php endforeach; ?>
									</ul>
								</details>
							</td>
							<td class="num"><?php echo esc_html( number_format_i18n( $group['users'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
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
		$picked   = isset( $_GET['groups'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['groups'] ) ) : array();
		$trial    = ! empty( $_GET['trial'] );
		// phpcs:enable

		// A ticked group stands for every capability in it.
		$groups = self::group_role_caps( $this->report->roles() );
		foreach ( $picked as $key ) {
			foreach ( isset( $groups[ $key ] ) ? $groups[ $key ]['caps'] : array() as $cap ) {
				$pairs[] = $groups[ $key ]['role'] . '|' . $cap;
			}
		}

		$items    = array();
		$trialled = true;
		foreach ( array_unique( array_filter( $user_ids ) ) as $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user || ! Access::can_view_user( $user->ID ) ) {
				continue;
			}
			$recommendation = $this->recommender->for_user( $user, time(), 2 );
			$refused        = $this->applier->check_user( $user, $recommendation ? $recommendation['proposed'] : '' );
			if ( $refused && 'role_right_sizer_self' !== $refused->get_error_code() && ! $recommendation ) {
				$refused = null;
			}
			$problem = $refused ? $refused->get_error_message() : ( $recommendation ? '' : __( 'There is nothing to change for this user.', 'role-right-sizer' ) );
			if ( '' === $problem && $trial ) {
				if ( $this->trials->for_user( $user->ID ) ) {
					$problem = __( 'This person is already on a trial.', 'role-right-sizer' );
				} elseif ( ! in_array( $recommendation['type'], $this->trials->types(), true ) ) {
					$problem = __( 'Only accounts that are still in use can be tried with a smaller role.', 'role-right-sizer' );
				}
			}
			if ( '' === $problem && $recommendation && ! in_array( $recommendation['type'], $this->trials->types(), true ) ) {
				$trialled = false;
			}
			$items[] = array(
				'field'   => 'users[]',
				'value'   => (string) $user->ID,
				'title'   => $user->display_name . ' (' . $user->user_login . ')',
				'before'  => $recommendation ? self::roles_label( $recommendation['current'] ) : '',
				'after'   => $recommendation ? self::role_label( $recommendation['proposed'] ) : '',
				'lost'    => $recommendation ? $recommendation['lost'] : array(),
				'problem' => $problem,
			);
		}

		$known = array();
		foreach ( $this->report->roles() as $recommendation ) {
			$known[ $recommendation['role'] . '|' . $recommendation['cap'] ] = $recommendation;
		}
		$protected = Recommender::protected_roles();
		foreach ( array_unique( $pairs ) as $pair ) {
			$parts   = explode( '|', $pair, 2 );
			$problem = '';
			if ( in_array( $parts[0], $protected, true ) ) {
				$problem = __( 'Capabilities are never removed from the administrator role or a role that can manage options.', 'role-right-sizer' );
			} elseif ( ! isset( $known[ $pair ] ) ) {
				$problem = __( 'That capability is not recommended for removal from this role.', 'role-right-sizer' );
			} elseif ( $trial ) {
				$problem = __( 'Only people can be tried with a smaller role, not capabilities on a role.', 'role-right-sizer' );
			}
			$items[]  = array(
				'field'   => 'caps[]',
				'value'   => $pair,
				/* translators: %s: role name. */
				'title'   => sprintf( __( 'Role: %s', 'role-right-sizer' ), self::role_label( $parts[0] ) ),
				'before'  => isset( $parts[1] ) ? $parts[1] : '',
				'after'   => '',
				'lost'    => isset( $parts[1] ) ? array( $parts[1] ) : array(),
				'owner'   => isset( $known[ $pair ] ) ? self::owner_label( CapabilityOwners::normalize( $known[ $pair ]['owner'] ?? null ) ) : '',
				'problem' => $problem,
			);
			$trialled = false;
		}

		$ready = array_filter(
			$items,
			static function ( array $item ): bool {
				return '' === $item['problem'];
			}
		);
		$days  = $this->trials->days();
		$count = count( $ready );
		?>
		<section class="axle-card rrs-confirm">
			<h2 class="axle-card__title"><?php echo esc_html( $trial ? __( 'Review the trial', 'role-right-sizer' ) : __( 'Review the changes', 'role-right-sizer' ) ); ?></h2>
			<?php if ( ! $items ) : ?>
				<div class="axle-empty"><p><?php esc_html_e( 'Nothing was selected. Go back to the recommendations and tick what to change.', 'role-right-sizer' ); ?></p><a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Back to the recommendations', 'role-right-sizer' ); ?></a></div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="task" value="<?php echo esc_attr( $trial ? 'trial_start' : 'apply' ); ?>">
					<ul class="rrs-confirm-list">
						<?php foreach ( $items as $item ) : ?>
							<li class="<?php echo '' === $item['problem'] ? 'is-ready' : 'is-refused'; ?>">
								<?php if ( '' === $item['problem'] ) : ?>
									<span class="axle-badge axle-badge--info"><?php echo esc_html( $trial ? __( 'Will be tried', 'role-right-sizer' ) : __( 'Will be applied', 'role-right-sizer' ) ); ?></span>
								<?php else : ?>
									<span class="axle-badge axle-badge--error"><?php esc_html_e( 'Will be skipped', 'role-right-sizer' ); ?></span>
								<?php endif; ?>
								<strong><?php echo esc_html( $item['title'] ); ?></strong>
								<?php if ( '' !== ( $item['owner'] ?? '' ) ) : ?>
									<span class="rrs-owner axle-muted"><?php echo esc_html( $item['owner'] ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $item['after'] ) : ?>
									<div class="axle-diff rrs-change">
										<del><span class="screen-reader-text"><?php esc_html_e( 'Before:', 'role-right-sizer' ); ?></span> <?php echo esc_html( $item['before'] ); ?></del>
										<ins><span class="screen-reader-text"><?php esc_html_e( 'After:', 'role-right-sizer' ); ?></span> <?php echo esc_html( $item['after'] ); ?></ins>
									</div>
								<?php elseif ( '' !== $item['before'] ) : ?>
									<div class="axle-diff rrs-change">
										<del><span class="screen-reader-text"><?php esc_html_e( 'Removed:', 'role-right-sizer' ); ?></span> <?php echo esc_html( $item['before'] ); ?></del>
									</div>
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
					<div class="axle-confirm" role="group" aria-labelledby="rrs-confirm-text">
						<p id="rrs-confirm-text">
							<?php
							if ( $trial ) {
								/* translators: %s: number of days. */
								echo esc_html( sprintf( _n( 'These people get the smaller role for %s day. Everything they could not do is recorded, and they can ask for their access back. You can restore the old role at any time from the Trials tab.', 'These people get the smaller role for %s days. Everything they could not do is recorded, and they can ask for their access back. You can restore the old role at any time from the Trials tab.', $days, 'role-right-sizer' ), number_format_i18n( $days ) ) );
							} else {
								esc_html_e( 'These accounts and roles lose the capabilities listed. Every change goes into the log under Changes, where one click undoes it.', 'role-right-sizer' );
							}
							?>
						</p>
						<div class="axle-actions">
							<?php if ( $ready && $trial ) : ?>
								<button type="submit" class="button button-primary">
									<?php
									/* translators: 1: number of days. */
									echo esc_html( sprintf( _n( 'Try for %s day', 'Try for %s days', $days, 'role-right-sizer' ), number_format_i18n( $days ) ) );
									?>
								</button>
							<?php elseif ( $ready ) : ?>
								<button type="submit" class="button button-primary">
									<?php
									/* translators: %s: number of changes. */
									echo esc_html( sprintf( _n( 'Apply %s change', 'Apply %s changes', $count, 'role-right-sizer' ), number_format_i18n( $count ) ) );
									?>
								</button>
								<?php if ( $trialled ) : ?>
									<button type="submit" class="button" name="task" value="trial_start">
										<?php
										/* translators: %s: number of days. */
										echo esc_html( sprintf( _n( 'Try for %s day instead', 'Try for %s days instead', $days, 'role-right-sizer' ), number_format_i18n( $days ) ) );
										?>
									</button>
								<?php endif; ?>
							<?php endif; ?>
							<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'role-right-sizer' ); ?></a>
						</div>
					</div>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Prints the change log.
	 */
	private function render_changes(): void {
		// Changes to accounts this person may not edit would show which capabilities those accounts lost.
		$all = array_values(
			array_filter(
				$this->changes->all(),
				static function ( array $change ): bool {
					return ! isset( $change['user_id'] ) || Access::can_view_user( (int) $change['user_id'] );
				}
			)
		);
		if ( ! $all ) {
			?>
			<div class="axle-empty rrs-empty"><p><?php esc_html_e( 'No changes yet. When you apply a recommendation or start a trial, the change shows up here with an Undo button, and what it took away.', 'role-right-sizer' ); ?></p><a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'See the recommendations', 'role-right-sizer' ); ?></a></div>
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
		?>
		<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Change log', 'role-right-sizer' ); ?>">
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
							<?php self::print_time( (int) $change['time'] ); ?><br>
							<span class="axle-muted">
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
								<div class="axle-diff rrs-change">
									<del><span class="screen-reader-text"><?php esc_html_e( 'Removed:', 'role-right-sizer' ); ?></span> <?php echo esc_html( (string) $change['cap'] ); ?></del>
								</div>
							<?php else : ?>
								<?php $user = get_userdata( (int) $change['user_id'] ); ?>
								<strong><?php echo esc_html( $user ? $user->display_name : (string) $change['login'] ); ?></strong>
								<div class="axle-diff rrs-change">
									<del><span class="screen-reader-text"><?php esc_html_e( 'Before:', 'role-right-sizer' ); ?></span> <?php echo esc_html( self::roles_label( (array) $change['before'] ) ); ?></del>
									<ins><span class="screen-reader-text"><?php esc_html_e( 'After:', 'role-right-sizer' ); ?></span> <?php echo esc_html( self::roles_label( (array) $change['after'] ) ); ?></ins>
								</div>
							<?php endif; ?>
						</td>
						<td><?php $this->render_caps( (array) $change['lost'] ); ?></td>
						<td class="rrs-undo">
							<?php if ( $change['undone'] ) : ?>
								<span class="axle-badge axle-badge--neutral"><?php esc_html_e( 'Undone', 'role-right-sizer' ); ?></span>
								<span class="axle-muted"><?php self::print_time( (int) $change['undone'] ); ?></span>
							<?php else : ?>
								<?php
								self::print_action(
									'undo',
									__( 'Undo', 'role-right-sizer' ),
									array( 'change' => $change['id'] ),
									array(
										'confirm'       => 'unused_cap' === $change['kind']
											/* translators: 1: capability, 2: role. */
											? sprintf( __( 'This gives %1$s back to the %2$s role, for everyone with the role.', 'role-right-sizer' ), (string) $change['cap'], self::role_label( (string) $change['role'] ) )
											/* translators: 1: roles they have now, 2: roles they get back. */
											: sprintf( __( 'This changes the role from %1$s back to %2$s, and gives back the capabilities listed. The change stays in the log as undone.', 'role-right-sizer' ), self::roles_label( (array) $change['after'] ), self::roles_label( (array) $change['before'] ) ),
										'confirm_label' => __( 'Undo this change', 'role-right-sizer' ),
									)
								);
								if ( (int) $change['batch'] === (int) $change['id'] && ( $batch[ (int) $change['batch'] ] ?? 0 ) > 1 ) {
									self::print_action(
										'undo_batch',
										/* translators: %s: number of changes. */
										sprintf( __( 'Undo all %s in this batch', 'role-right-sizer' ), number_format_i18n( $batch[ (int) $change['batch'] ] ) ),
										array( 'batch' => $change['batch'] ),
										array(
											'style'   => 'link',
											'confirm' => __( 'This undoes every change of the batch that is not undone yet. Each one goes back to what it was before.', 'role-right-sizer' ),
											'confirm_label' => __( 'Undo the whole batch', 'role-right-sizer' ),
										)
									);
								}
								?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
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
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rrs-settings">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="task" value="settings">
			<section class="axle-card">
			<h2 class="axle-card__title"><?php esc_html_e( 'What to recommend', 'role-right-sizer' ); ?></h2>
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
			</table>
			</section>
			<section class="axle-card">
				<h2 class="axle-card__title"><?php esc_html_e( 'Trials', 'role-right-sizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'When a trial ends with no denials', 'role-right-sizer' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'When a trial ends with no denials', 'role-right-sizer' ); ?></legend>
								<label><input type="radio" name="trial_end_action" value="ask" <?php checked( 'ask', $settings['trial_end_action'] ); ?>> <?php esc_html_e( 'Ask me: keep the smaller role until I decide', 'role-right-sizer' ); ?></label><br>
								<label><input type="radio" name="trial_end_action" value="keep" <?php checked( 'keep', $settings['trial_end_action'] ); ?>> <?php esc_html_e( 'Make the smaller role permanent', 'role-right-sizer' ); ?></label>
								<p class="description"><?php esc_html_e( 'A trial where the person hit a wall is never made permanent on its own, whatever you choose here. Making it permanent keeps the change in the log, where Undo still works.', 'role-right-sizer' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</table>
			</section>
			<?php submit_button( __( 'Save settings', 'role-right-sizer' ) ); ?>
		</form>

		<section class="axle-card">
			<h2 class="axle-card__title"><?php esc_html_e( 'Timing', 'role-right-sizer' ); ?></h2>
			<dl class="axle-facts rrs-facts">
				<dt><?php esc_html_e( 'Tracking began', 'role-right-sizer' ); ?></dt>
				<dd><?php self::print_time( $this->settings->tracking_started() ); ?></dd>
				<dt><?php esc_html_e( 'Learning period', 'role-right-sizer' ); ?></dt>
				<?php /* translators: %s: number of days. */ ?>
				<dd><?php echo esc_html( sprintf( _n( '%s day', '%s days', $this->settings->learning_days(), 'role-right-sizer' ), number_format_i18n( $this->settings->learning_days() ) ) ); ?></dd>
				<dt><?php esc_html_e( 'A capability counts as used for', 'role-right-sizer' ); ?></dt>
				<?php /* translators: %s: number of days. */ ?>
				<dd><?php echo esc_html( sprintf( _n( '%s day', '%s days', $this->settings->window_days(), 'role-right-sizer' ), number_format_i18n( $this->settings->window_days() ) ) ); ?></dd>
				<dt><?php esc_html_e( 'An account is dormant after', 'role-right-sizer' ); ?></dt>
				<?php /* translators: %s: number of days. */ ?>
				<dd><?php echo esc_html( sprintf( _n( '%s day without activity', '%s days without activity', $this->settings->dormant_days(), 'role-right-sizer' ), number_format_i18n( $this->settings->dormant_days() ) ) ); ?></dd>
			</dl>
			<p class="description"><?php esc_html_e( 'Change these with the filters listed in the plugin reference.', 'role-right-sizer' ); ?></p>
		</section>
		<?php
	}

	/**
	 * Prints a list of capabilities, folded when long.
	 *
	 * @param string[] $caps Capabilities.
	 */
	private function render_caps( array $caps ): void {
		if ( ! $caps ) {
			echo '<span class="axle-muted">' . esc_html__( 'None', 'role-right-sizer' ) . '</span>';
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
			echo '<span class="axle-muted">' . esc_html__( 'Nothing beyond reading', 'role-right-sizer' ) . '</span>';
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
					<li><code><?php echo esc_html( (string) $cap ); ?></code> <span class="axle-muted"><?php self::print_time( (int) $last ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	/**
	 * Messages for the errors an action can return.
	 *
	 * @return array<string, string>
	 */
	private static function error_messages(): array {
		return array(
			'role_right_sizer_self'            => __( 'You cannot change your own role here.', 'role-right-sizer' ),
			'role_right_sizer_excluded'        => __( 'One of the selected accounts or roles is on the exclude list.', 'role-right-sizer' ),
			'role_right_sizer_last_admin'      => __( 'The last administrator keeps their role.', 'role-right-sizer' ),
			'role_right_sizer_forbidden'       => __( 'You are not allowed to change one of the selected accounts.', 'role-right-sizer' ),
			'role_right_sizer_nothing'         => __( 'One of the selected recommendations no longer applies.', 'role-right-sizer' ),
			'role_right_sizer_not_found'       => __( 'That change or account no longer exists.', 'role-right-sizer' ),
			'role_right_sizer_undone'          => __( 'That change was already undone.', 'role-right-sizer' ),
			'role_right_sizer_gone'            => __( 'The account or role of that change no longer exists.', 'role-right-sizer' ),
			'role_right_sizer_protected'       => __( 'Capabilities are never removed from the administrator role or a role that can manage options.', 'role-right-sizer' ),
			'role_right_sizer_trial_exists'    => __( 'One of the selected people is already on a trial.', 'role-right-sizer' ),
			'role_right_sizer_trial_type'      => __( 'Only accounts that are still in use can be tried with a smaller role.', 'role-right-sizer' ),
			'role_right_sizer_trial_not_found' => __( 'That trial does not exist.', 'role-right-sizer' ),
			'role_right_sizer_trial_closed'    => __( 'That trial is already over.', 'role-right-sizer' ),
			'role_right_sizer_trial_denials'   => __( 'This trial has denials, so it needs a decision from a person.', 'role-right-sizer' ),
			'role_right_sizer_nothing_picked'  => __( 'Nothing was selected, so nothing changed.', 'role-right-sizer' ),
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
		if ( in_array( $done, array( 'applied', 'trial_started' ), true ) && ! $count ) {
			$done = 'nothing';
		}

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
			case 'trial_started':
				/* translators: 1: number of trials, 2: number of days. */
				$message = sprintf( _n( 'Started %1$s trial for %2$s days. They have the smaller role until then, and you will see what they could not do.', 'Started %1$s trials for %2$s days. They have the smaller role until then, and you will see what they could not do.', $count, 'role-right-sizer' ), number_format_i18n( $count ), number_format_i18n( $this->trials->days() ) );
				break;
			case 'trial_restored':
				$message = __( 'Gave the old role back and ended the trial.', 'role-right-sizer' );
				break;
			case 'trial_extended':
				$message = __( 'Extended the trial.', 'role-right-sizer' );
				break;
			case 'trial_kept':
				$message = __( 'The smaller role is permanent now. The change is still in the log, where Undo can put the old role back.', 'role-right-sizer' );
				break;
			case 'steps_dismissed':
				$message = __( 'Dismissed. The recommendations stay on this screen.', 'role-right-sizer' );
				break;
		}

		if ( '' !== $message ) {
			?>
			<div class="notice notice-success is-dismissible rrs-notice">
				<p>
					<span class="rrs-notice-text"><?php echo esc_html( $message ); ?></span>
					<?php if ( in_array( $done, array( 'applied', 'trial_started' ), true ) && $batch ) : ?>
						<?php self::print_action( 'undo_batch', $count > 1 ? __( 'Undo all', 'role-right-sizer' ) : __( 'Undo', 'role-right-sizer' ), array( 'batch' => $batch ), array( 'style' => 'link' ) ); ?>
					<?php endif; ?>
				</p>
			</div>
			<?php
		} elseif ( 'nothing' === $done && ! $errors ) {
			printf( '<div class="notice notice-warning is-dismissible rrs-notice"><p><span class="rrs-notice-text">%s</span></p></div>', esc_html__( 'Nothing was selected, so nothing changed.', 'role-right-sizer' ) );
		}

		$messages = self::error_messages();
		foreach ( array_unique( $errors ) as $code ) {
			$code = 0 === strpos( $code, 'role_right_sizer_' ) ? $code : 'role_right_sizer_' . $code;
			if ( isset( $messages[ $code ] ) ) {
				printf( '<div class="notice notice-error is-dismissible rrs-notice"><p><span class="rrs-notice-text">%s</span></p></div>', esc_html( $messages[ $code ] ) );
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

			case 'trial_start':
				$users  = isset( $_POST['users'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['users'] ) ) : array();
				$result = $this->trials->start_many( array_filter( $users ) );
				$tab    = 'trials';
				$args   = array(
					'rrs_done'   => 'trial_started',
					'rrs_count'  => count( $result['trials'] ),
					'rrs_batch'  => $result['batch'],
					'rrs_errors' => self::codes( $result['errors'] ),
				);
				break;

			case 'trial_restore':
			case 'trial_keep':
			case 'trial_extend':
				$id     = isset( $_POST['trial'] ) ? absint( $_POST['trial'] ) : 0;
				$result = 'trial_restore' === $task ? $this->trials->restore( $id ) : ( 'trial_keep' === $task ? $this->trials->keep( $id ) : $this->trials->extend( $id ) );
				$tab    = 'trials';
				$done   = array(
					'trial_restore' => 'trial_restored',
					'trial_keep'    => 'trial_kept',
					'trial_extend'  => 'trial_extended',
				);
				$args   = is_wp_error( $result ) ? array( 'rrs_errors' => self::codes( array( $result ) ) ) : array( 'rrs_done' => $done[ $task ] );
				break;

			case 'dismiss_steps':
				update_user_meta( get_current_user_id(), 'role_right_sizer_steps_dismissed', 1 );
				$tab  = 'recommendations';
				$args = array( 'rrs_done' => 'steps_dismissed' );
				break;

			case 'settings':
				$this->settings->save(
					array(
						'dormant_action'   => isset( $_POST['dormant_action'] ) ? sanitize_key( wp_unslash( $_POST['dormant_action'] ) ) : 'remove',
						'fallback_role'    => isset( $_POST['fallback_role'] ) ? sanitize_key( wp_unslash( $_POST['fallback_role'] ) ) : '',
						'excluded_roles'   => isset( $_POST['excluded_roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['excluded_roles'] ) ) : array(),
						'excluded_users'   => self::user_ids( isset( $_POST['excluded_users'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excluded_users'] ) ) : '' ),
						'trial_end_action' => isset( $_POST['trial_end_action'] ) ? sanitize_key( wp_unslash( $_POST['trial_end_action'] ) ) : 'ask',
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
		$recommendations = array_filter(
			(array) $recommendations,
			static function ( $recommendation ): bool {
				return Access::can_view_user( (int) ( $recommendation['user_id'] ?? 0 ) );
			}
		);

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
				// Capabilities and usage of an account this person may not edit stay out of the file.
				if ( ! Access::can_view_user( $user->ID ) ) {
					continue;
				}
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
