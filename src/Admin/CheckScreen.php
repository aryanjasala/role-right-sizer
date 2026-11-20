<?php
/**
 * Users > Right-Sizer > Check what a person can do ("Why can't they?").
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Admin;

use AryanX\Axle\RoleRightSizer\Applier;
use AryanX\Axle\RoleRightSizer\Probe\Prober;
use AryanX\Axle\RoleRightSizer\Service;
use AryanX\Axle\RoleRightSizer\Temporary;
use AryanX\Axle\RoleRightSizer\Trials;

defined( 'ABSPATH' ) || exit;

/**
 * The form, the result and the link on a profile and on a Users row.
 *
 * It runs one admin screen or REST route as the chosen person, never signs in
 * as them, and says which capability stopped it, which roles have it, and the
 * smallest fix, which goes through the change log so Undo works.
 */
final class CheckScreen implements Service {

	/**
	 * Runs the checks.
	 *
	 * @var Prober
	 */
	private Prober $prober;

	/**
	 * Gives access found by a check.
	 *
	 * @var Applier
	 */
	private Applier $applier;

	/**
	 * Role trials.
	 *
	 * @var Trials
	 */
	private Trials $trials;

	/**
	 * Sets up the screen.
	 *
	 * @param Prober  $prober  Runs the checks.
	 * @param Applier $applier Gives access.
	 * @param Trials  $trials  Role trials.
	 */
	public function __construct( Prober $prober, Applier $applier, Trials $trials ) {
		$this->prober  = $prober;
		$this->applier = $applier;
		$this->trials  = $trials;
	}

	/**
	 * Adds the links to Users and to a profile.
	 */
	public function register(): void {
		add_filter( 'user_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'edit_user_profile', array( $this, 'profile_link' ) );
	}

	/**
	 * The address of the check for a person.
	 *
	 * @param int                  $user_id The person.
	 * @param array<string, mixed> $args    More query arguments.
	 * @return string
	 */
	public static function url( int $user_id, array $args = array() ): string {
		return Screen::url(
			array_merge(
				array(
					'view' => 'check',
					'user' => $user_id,
				),
				$args 
			) 
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Short call.
	}

	/**
	 * Whether the current user may open the check for a person.
	 *
	 * @param int $user_id The person.
	 * @return bool
	 */
	private static function allowed( int $user_id ): bool {
		return current_user_can( Screen::capability() ) && Prober::can_check( $user_id );
	}

	/**
	 * Adds "Check what Pat can do" under a name in the Users list.
	 *
	 * @param mixed $actions Row actions.
	 * @param mixed $user    The user of the row.
	 * @return mixed
	 */
	public function row_action( $actions, $user = null ) {
		if ( is_array( $actions ) && $user instanceof \WP_User && get_current_user_id() !== $user->ID && self::allowed( $user->ID ) ) {
			$actions['role_right_sizer_check'] = '<a href="' . esc_url( self::url( $user->ID ) ) . '">' . esc_html( self::link_text( $user ) ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Adds the link under another person's profile.
	 *
	 * @param mixed $user The person whose profile is open.
	 */
	public function profile_link( $user = null ): void {
		if ( ! $user instanceof \WP_User || get_current_user_id() === $user->ID || ! self::allowed( $user->ID ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Role Right-Sizer', 'role-right-sizer' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Access check', 'role-right-sizer' ); ?></th>
				<td>
					<a class="button" href="<?php echo esc_url( self::url( $user->ID ) ); ?>"><?php echo esc_html( self::link_text( $user ) ); ?></a>
					<p class="description"><?php esc_html_e( 'Tries a screen or an action as this person and tells you what stopped it. They are not signed in, and nothing is saved.', 'role-right-sizer' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * The words of the link.
	 *
	 * @param \WP_User $user The person.
	 * @return string
	 */
	private static function link_text( \WP_User $user ): string {
		/* translators: %s: a person's name. */
		return sprintf( __( 'Check what %s can do', 'role-right-sizer' ), $user->display_name );
	}

	/**
	 * Prints the view.
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Shows a form and a kept result; the check itself needs the nonce.
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		$user    = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof \WP_User || ! self::allowed( $user->ID ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'You may not check this person, or they do not exist.', 'role-right-sizer' ) . '</p></div>';
			return;
		}

		$kind   = isset( $_GET['kind'] ) && 'rest' === sanitize_key( wp_unslash( $_GET['kind'] ) ) ? 'rest' : 'screen';
		$target = isset( $_GET['target'] ) ? sanitize_text_field( wp_unslash( $_GET['target'] ) ) : '';
		$method = Prober::method( isset( $_GET['method'] ) ? sanitize_text_field( wp_unslash( $_GET['method'] ) ) : 'GET' );
		$kept   = isset( $_GET['result'] ) ? Prober::kept( sanitize_key( wp_unslash( $_GET['result'] ) ) ) : null;
		// phpcs:enable

		$this->render_form( $user, $kind, $target, $method );
		if ( $kept && (int) $kept['about']['user_id'] === $user->ID ) {
			$this->render_result( $user, $kept );
		}
	}

	/**
	 * Prints the form and the quick picks.
	 *
	 * @param \WP_User $user   The person.
	 * @param string   $kind   `screen` or `rest`.
	 * @param string   $target What to check.
	 * @param string   $method Method.
	 */
	private function render_form( \WP_User $user, string $kind, string $target, string $method ): void {
		?>
		<section class="axle-card rrs-section rrs-check">
			<h2 class="axle-card__title">
				<?php
				/* translators: %s: a person's name. */
				echo esc_html( sprintf( __( 'Why can\'t %s do it?', 'role-right-sizer' ), $user->display_name ) );
				?>
			</h2>
			<p class="axle-muted">
				<?php
				/* translators: %s: a person's name. */
				echo esc_html( sprintf( __( 'Pick what %s says they cannot do. Right-Sizer tries it as them for a moment, without signing in as them, and without saving anything.', 'role-right-sizer' ), $user->display_name ) );
				?>
			</p>
			<p class="rrs-presets">
				<?php esc_html_e( 'Common checks:', 'role-right-sizer' ); ?>
				<?php foreach ( Prober::presets() as $preset ) : ?>
					<a class="button button-small" href="
					<?php
					echo esc_url(
						self::url(
							$user->ID,
							array(
								'kind'   => $preset['kind'],
								'target' => $preset['target'],
								'method' => $preset['method'],
							) 
						) 
					);
					?>
															"><?php echo esc_html( $preset['label'] ); ?></a> <?php // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Short call. ?>
				<?php endforeach; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rrs-check-form">
				<?php wp_nonce_field( Screen::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( Screen::ACTION ); ?>">
				<input type="hidden" name="task" value="check_run">
				<input type="hidden" name="user" value="<?php echo esc_attr( (string) $user->ID ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Try', 'role-right-sizer' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'What to try', 'role-right-sizer' ); ?></legend>
								<label><input type="radio" name="kind" value="screen" <?php checked( 'screen', $kind ); ?>> <?php esc_html_e( 'An admin screen', 'role-right-sizer' ); ?></label><br>
								<label><input type="radio" name="kind" value="rest" <?php checked( 'rest', $kind ); ?>> <?php esc_html_e( 'A REST route', 'role-right-sizer' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rrs-check-target"><?php esc_html_e( 'Screen or route', 'role-right-sizer' ); ?></label></th>
						<td>
							<input type="text" id="rrs-check-target" name="target" class="regular-text code" value="<?php echo esc_attr( $target ); ?>" required aria-describedby="rrs-check-target-help">
							<p class="description" id="rrs-check-target-help"><?php esc_html_e( 'A screen such as upload.php or admin.php?page=my-plugin, or a route such as /wp/v2/media.', 'role-right-sizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rrs-check-method"><?php esc_html_e( 'Method', 'role-right-sizer' ); ?></label></th>
						<td>
							<select id="rrs-check-method" name="method" aria-describedby="rrs-check-method-help">
								<?php foreach ( array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ) as $name ) : ?>
									<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $method, $name ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description" id="rrs-check-method-help"><?php esc_html_e( 'For REST routes only. A write is checked for permission and then stopped: nothing is created, changed or deleted.', 'role-right-sizer' ); ?></p>
						</td>
					</tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php echo esc_html( self::link_text( $user ) ); ?></button></p>
			</form>
		</section>
		<?php
	}

	/**
	 * Prints what the check found.
	 *
	 * @param \WP_User             $user   The person.
	 * @param array<string, mixed> $result The result, see Prober::summarise().
	 */
	private function render_result( \WP_User $user, array $result ): void {
		$verdict = (string) $result['verdict'];
		$cap     = (string) $result['cap'];
		$labels  = array(
			'allowed'    => array( 'success', __( 'Allowed', 'role-right-sizer' ) ),
			'refused'    => array( 'error', __( 'Refused', 'role-right-sizer' ) ),
			'redirected' => array( 'warning', __( 'Sent elsewhere', 'role-right-sizer' ) ),
			'missing'    => array( 'neutral', __( 'Not found', 'role-right-sizer' ) ),
			'error'      => array( 'neutral', __( 'Could not tell', 'role-right-sizer' ) ),
		);
		$badge   = $labels[ $verdict ] ?? $labels['error'];
		$name    = $user->display_name;
		?>
		<section class="axle-card rrs-section rrs-check-result" aria-live="polite">
			<h2 class="axle-card__title">
				<?php esc_html_e( 'Result', 'role-right-sizer' ); ?>
				<span class="axle-badge axle-badge--<?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span>
			</h2>
			<p class="rrs-check-about axle-muted">
				<code><?php echo esc_html( ( 'rest' === $result['about']['kind'] ? $result['about']['method'] . ' ' : '' ) . $result['about']['target'] ); ?></code>
			</p>
			<p class="rrs-check-says">
				<?php
				if ( 'allowed' === $verdict ) {
					/* translators: %s: a person's name. */
					echo esc_html( sprintf( __( 'Nothing stopped %s. They can do this with their access as it is now. If it still fails for them, the cause is not their role: look at the page itself, a plugin, or the browser.', 'role-right-sizer' ), $name ) );
				} elseif ( 'refused' === $verdict && '' !== $cap ) {
					printf(
						/* translators: 1: a person's name, 2: a capability. */
						esc_html__( '%1$s was refused because they do not have the capability %2$s.', 'role-right-sizer' ),
						esc_html( $name ),
						'<code>' . esc_html( $cap ) . '</code>'
					);
				} elseif ( 'refused' === $verdict && $result['outright'] ) {
					esc_html_e( 'WordPress refuses this outright, whatever role the person has. The item may not exist, or a plugin may block this action. Giving more access will not help.', 'role-right-sizer' );
				} elseif ( 'refused' === $verdict ) {
					esc_html_e( 'It was refused, but no capability check failed. The screen has a rule of its own, usually from a plugin. Giving a capability will not help.', 'role-right-sizer' );
				} elseif ( 'redirected' === $verdict ) {
					echo esc_html(
						'' !== $result['location_path']
							/* translators: %s: a file name such as wp-login.php. */
							? sprintf( __( 'The request was sent on to %s instead of loading.', 'role-right-sizer' ), $result['location_path'] )
							: __( 'The request was sent on to another address instead of loading.', 'role-right-sizer' )
					);
				} elseif ( 'missing' === $verdict ) {
					esc_html_e( 'This site has no such screen or route.', 'role-right-sizer' );
				} elseif ( 400 === (int) $result['status'] && 'rest' === $result['about']['kind'] ) {
					esc_html_e( 'This route needs values before it checks permission, so the check could not get that far. Check the admin screen that uses it instead.', 'role-right-sizer' );
				} else {
					/* translators: %d: HTTP status. */
					echo esc_html( sprintf( __( 'The page answered with status %d, so the check could not tell. Try the screen itself.', 'role-right-sizer' ), (int) $result['status'] ) );
				}
				?>
			</p>

			<?php if ( 'refused' === $verdict && '' !== $cap ) : ?>
				<?php $this->render_roles( $user, $cap ); ?>
				<?php $this->render_fixes( $user, $cap, $result ); ?>
			<?php endif; ?>

			<?php if ( 'refused' === $verdict && count( $result['failed'] ) > 1 ) : ?>
				<details class="rrs-caps">
					<summary><?php esc_html_e( 'Every refused check', 'role-right-sizer' ); ?></summary>
					<ul>
						<?php foreach ( $result['failed'] as $check ) : ?>
							<li>
								<code><?php echo esc_html( $check['cap'] ); ?></code>
								<?php if ( '' !== $check['requested'] && $check['requested'] !== $check['cap'] ) : ?>
									<span class="axle-muted">
										<?php
										/* translators: %s: a capability name. */
										echo esc_html( sprintf( __( 'while checking %s', 'role-right-sizer' ), $check['requested'] ) );
										?>
									</span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Prints the roles that have the capability, and what the person has.
	 *
	 * @param \WP_User $user The person.
	 * @param string   $cap  The capability.
	 */
	private function render_roles( \WP_User $user, string $cap ): void {
		$with = Prober::roles_with( $cap );
		?>
		<p class="rrs-check-roles">
			<?php
			if ( $with ) {
				printf(
					/* translators: 1: a capability, 2: list of roles. */
					esc_html__( 'Roles that have %1$s: %2$s.', 'role-right-sizer' ),
					'<code>' . esc_html( $cap ) . '</code>',
					esc_html( implode( ', ', array_map( array( Screen::class, 'role_label' ), $with ) ) )
				);
			} else {
				printf(
					/* translators: %s: a capability. */
					esc_html__( 'No role has %s. A plugin may give it to people some other way.', 'role-right-sizer' ),
					'<code>' . esc_html( $cap ) . '</code>'
				);
			}
			?>
			<br>
			<?php
			/* translators: 1: a person's name, 2: their roles. */
			echo esc_html( sprintf( __( '%1$s has: %2$s.', 'role-right-sizer' ), $user->display_name, Screen::roles_label( Temporary::permanent_roles( $user ) ) ) );
			?>
		</p>
		<?php
	}

	/**
	 * Prints the smallest fixes, each behind a confirmation.
	 *
	 * @param \WP_User             $user   The person.
	 * @param string               $cap    The capability.
	 * @param array<string, mixed> $result The result.
	 */
	private function render_fixes( \WP_User $user, string $cap, array $result ): void {
		if ( $this->trials->for_user( $user->ID ) ) {
			?>
			<p class="rrs-check-fix-none">
				<?php
				printf(
					/* translators: %s: link to the Trials tab. */
					esc_html__( 'They are on a role trial, so this wall is probably the trial. %s.', 'role-right-sizer' ),
					'<a href="' . esc_url( Screen::url( array( 'tab' => 'trials' ) ) ) . '">' . esc_html__( 'Restore the old role from the Trials tab', 'role-right-sizer' ) . '</a>'
				);
				?>
			</p>
			<?php
			return;
		}

		$fixes = Prober::fixes( $user, $cap );
		if ( ! $fixes ) {
			?>
			<p class="rrs-check-fix-none axle-muted"><?php esc_html_e( 'You cannot give this access yourself: you do not have the capability, or no role you may assign has it.', 'role-right-sizer' ); ?></p>
			<?php
			return;
		}
		?>
		<h3><?php esc_html_e( 'The smallest fix', 'role-right-sizer' ); ?></h3>
		<ul class="rrs-check-fixes">
			<?php foreach ( $fixes as $index => $fix ) : ?>
				<li>
					<?php
					if ( 'cap' === $fix['type'] ) {
						$label = sprintf(
							/* translators: 1: capability, 2: a person's name. */
							__( 'Give only %1$s to %2$s', 'role-right-sizer' ),
							$fix['value'],
							$user->display_name
						);
						$sure = sprintf(
							/* translators: 1: a person's name, 2: capability. */
							__( 'This gives %1$s the %2$s capability and nothing else. It goes in the change log, where Undo takes it away.', 'role-right-sizer' ),
							$user->display_name,
							$fix['value']
						);
					} else {
						$label = sprintf(
							/* translators: 1: role, 2: a person's name. */
							__( 'Also give %1$s the %2$s role', 'role-right-sizer' ),
							$user->display_name,
							Screen::role_label( $fix['value'] )
						);
						$sure = sprintf(
							/* translators: 1: a person's name, 2: role, 3: number of capabilities, 4: capabilities. */
							_n(
								'This adds the %2$s role to %1$s. They gain %3$s capability: %4$s. It goes in the change log, where Undo takes the role away.',
								'This adds the %2$s role to %1$s. They gain %3$s capabilities, among them: %4$s. It goes in the change log, where Undo takes the role away.',
								count( $fix['gained'] ),
								'role-right-sizer'
							),
							$user->display_name,
							Screen::role_label( $fix['value'] ),
							number_format_i18n( count( $fix['gained'] ) ),
							implode( ', ', array_slice( $fix['gained'], 0, 8 ) )
						);
					}
					Screen::print_action(
						'check_fix',
						$label,
						array(
							'user'   => $user->ID,
							'type'   => $fix['type'],
							'value'  => $fix['value'],
							'kind'   => $result['about']['kind'],
							'target' => $result['about']['target'],
							'method' => $result['about']['method'],
						),
						array(
							'style'         => 0 === $index ? 'primary' : 'secondary',
							'confirm'       => $sure,
							'confirm_label' => __( 'Give this access', 'role-right-sizer' ),
						)
					);
					?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Handles the two forms of the view.
	 *
	 * @param string $task `check_run` or `check_fix`.
	 * @return array<string, mixed> Arguments for the redirect to the view.
	 */
	public function handle( string $task ): array {
		$user_id = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Screen::handle() checked the nonce.
		$kind    = isset( $_POST['kind'] ) && 'rest' === sanitize_key( wp_unslash( $_POST['kind'] ) ) ? 'rest' : 'screen'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Same.
		$target  = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Same.
		$method  = Prober::method( isset( $_POST['method'] ) ? sanitize_text_field( wp_unslash( $_POST['method'] ) ) : 'GET' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Same.
		$args    = array(
			'view'   => 'check',
			'user'   => $user_id,
			'kind'   => $kind,
			'target' => $target,
			'method' => $method,
		);

		if ( 'check_fix' === $task ) {
			$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Same.
			$value  = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Same.
			$result = Prober::can_check( $user_id ) ? $this->applier->apply_fix( $user_id, $type, 'role' === $type ? sanitize_key( $value ) : $value ) : new \WP_Error( 'role_right_sizer_forbidden', '' );
			if ( is_wp_error( $result ) ) {
				$args['rrs_errors'] = $result->get_error_code();
			} else {
				$args['rrs_done']  = 'fixed';
				$args['rrs_count'] = 1;
				$args['rrs_batch'] = (int) $result['batch'];
			}

			return $args;
		}

		$result = $this->prober->run( $user_id, $kind, $target, $method );
		if ( is_wp_error( $result ) ) {
			$args['rrs_errors'] = $result->get_error_code();
		} else {
			$args['result'] = Prober::keep( $result );
		}

		return $args;
	}
}
