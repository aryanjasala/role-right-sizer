<?php
/**
 * Users > Right-Sizer > Trials.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Admin;

use AryanX\Axle\RoleRightSizer\Access;
use AryanX\Axle\RoleRightSizer\Trials;

defined( 'ABSPATH' ) || exit;

/**
 * Each trial with its days left, what the person could not do, and what to do next.
 */
final class TrialsTab {

	/**
	 * The trials.
	 *
	 * @var Trials
	 */
	private Trials $trials;

	/**
	 * Sets up the tab.
	 *
	 * @param Trials $trials The trials.
	 */
	public function __construct( Trials $trials ) {
		$this->trials = $trials;
	}

	/**
	 * Prints the tab.
	 */
	public function render(): void {
		// Trials whose time ran out since the last hourly check show their real state.
		$this->trials->check();

		$all = array_values(
			array_filter(
				$this->trials->all(),
				static function ( array $trial ): bool {
					return Access::can_view_user( (int) $trial['user_id'] );
				}
			)
		);

		if ( ! $all ) {
			?>
			<div class="axle-empty rrs-empty">
				<p>
					<strong><?php esc_html_e( 'No trials yet.', 'role-right-sizer' ); ?></strong>
					<?php esc_html_e( 'When you try a smaller role on someone, the trial shows up here with the days left and every wall they hit. Open the recommendations and choose Try for 14 days next to a person.', 'role-right-sizer' ); ?>
				</p>
				<a class="button" href="<?php echo esc_url( Screen::url() ); ?>"><?php esc_html_e( 'See the recommendations', 'role-right-sizer' ); ?></a>
			</div>
			<?php
			return;
		}

		$open   = array();
		$closed = array();
		foreach ( $all as $trial ) {
			if ( in_array( $trial['status'], array( 'active', 'ended' ), true ) ) {
				$open[] = $trial;
			} else {
				$closed[] = $trial;
			}
		}

		$waiting = count(
			array_filter(
				$open,
				static function ( array $trial ): bool {
					return 'ended' === $trial['status'];
				}
			)
		);
		$asked   = count(
			array_filter(
				$open,
				static function ( array $trial ): bool {
					return (int) $trial['requested'] > 0;
				}
			)
		);
		?>
		<div class="axle-stats">
			<div class="axle-stat">
				<span class="axle-stat__value"><?php echo esc_html( number_format_i18n( count( $open ) - $waiting ) ); ?></span>
				<span class="axle-stat__label"><?php esc_html_e( 'trials running', 'role-right-sizer' ); ?></span>
			</div>
			<div class="axle-stat<?php echo $waiting ? ' axle-stat--warning' : ''; ?>">
				<span class="axle-stat__value"><?php echo esc_html( number_format_i18n( $waiting ) ); ?></span>
				<span class="axle-stat__label"><?php esc_html_e( 'over, waiting for a decision', 'role-right-sizer' ); ?></span>
			</div>
			<div class="axle-stat<?php echo $asked ? ' axle-stat--warning' : ''; ?>">
				<span class="axle-stat__value"><?php echo esc_html( number_format_i18n( $asked ) ); ?></span>
				<span class="axle-stat__label"><?php esc_html_e( 'asked for their access back', 'role-right-sizer' ); ?></span>
			</div>
		</div>
		<?php
		if ( $open ) {
			$this->render_table( __( 'Open trials', 'role-right-sizer' ), $open, true );
		}
		if ( $closed ) {
			$this->render_table( __( 'Past trials', 'role-right-sizer' ), array_slice( $closed, 0, 50 ), false );
		}
	}

	/**
	 * Prints one table of trials.
	 *
	 * @param string  $title  Heading.
	 * @param array[] $trials Trials.
	 * @param bool    $open   Whether they can still be decided.
	 */
	private function render_table( string $title, array $trials, bool $open ): void {
		?>
		<section class="axle-card rrs-section">
			<h2 class="axle-card__title"><?php echo esc_html( $title ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( count( $trials ) ) ); ?>)</span></h2>
			<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr( $title ); ?>">
				<table class="widefat striped rrs-table rrs-trials">
					<thead>
						<tr>
							<th scope="col" class="column-primary"><?php esc_html_e( 'Person', 'role-right-sizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Role', 'role-right-sizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Time', 'role-right-sizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What they could not do', 'role-right-sizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'role-right-sizer' ); ?></th>
							<?php if ( $open ) : ?>
								<th scope="col"><?php esc_html_e( 'Decide', 'role-right-sizer' ); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $trials as $trial ) : ?>
							<?php
							$user  = get_userdata( (int) $trial['user_id'] );
							$total = Trials::denial_total( $trial );
							?>
							<tr data-trial="<?php echo esc_attr( (string) $trial['id'] ); ?>" data-user="<?php echo esc_attr( (string) $trial['login'] ); ?>">
								<td class="column-primary">
									<strong><?php echo esc_html( $user ? $user->display_name : (string) $trial['login'] ); ?></strong>
									<span class="axle-muted"><?php echo esc_html( (string) $trial['login'] ); ?></span>
									<?php if ( $open && (int) $trial['requested'] > 0 ) : ?>
										<span class="rrs-note">
											<span class="axle-badge axle-badge--warning"><?php esc_html_e( 'Asked for access back', 'role-right-sizer' ); ?></span>
											<?php Screen::print_time( (int) $trial['requested'] ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td>
									<div class="axle-diff">
										<del><span class="screen-reader-text"><?php esc_html_e( 'Before:', 'role-right-sizer' ); ?></span> <?php echo esc_html( Screen::roles_label( (array) $trial['before'] ) ); ?></del>
										<ins><span class="screen-reader-text"><?php esc_html_e( 'During the trial:', 'role-right-sizer' ); ?></span> <?php echo esc_html( Screen::roles_label( (array) $trial['after'] ) ); ?></ins>
									</div>
								</td>
								<td>
									<?php
									esc_html_e( 'Started', 'role-right-sizer' );
									echo ' ';
									Screen::print_time( (int) $trial['started'] );
									?>
									<br>
									<span class="axle-muted">
										<?php
										if ( 'active' === $trial['status'] ) {
											$left = Trials::days_left( $trial );
											/* translators: %s: number of days. */
											echo esc_html( sprintf( _n( '%s day left', '%s days left', $left, 'role-right-sizer' ), number_format_i18n( $left ) ) );
										} elseif ( 'ended' === $trial['status'] ) {
											esc_html_e( 'Time is up', 'role-right-sizer' );
										} else {
											esc_html_e( 'Closed', 'role-right-sizer' );
											echo ' ';
											Screen::print_time( (int) $trial['closed'] );
										}
										?>
									</span>
								</td>
								<td><?php $this->render_denials( $trial ); ?></td>
								<td><?php $this->render_status( $trial ); ?></td>
								<?php if ( $open ) : ?>
									<td class="rrs-decide">
										<?php $this->render_actions( $trial, $total ); ?>
									</td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	/**
	 * Prints what the person could not do, grouped, most often first.
	 *
	 * @param array $trial The trial.
	 */
	private function render_denials( array $trial ): void {
		$denials = (array) $trial['denials'];
		if ( ! $denials ) {
			echo '<span class="axle-muted">' . esc_html__( 'Nothing stopped them', 'role-right-sizer' ) . '</span>';
			return;
		}

		uasort(
			$denials,
			static function ( array $a, array $b ): int {
				return array( $b['count'], $b['last'] ) <=> array( $a['count'], $a['last'] );
			}
		);
		?>
		<details class="rrs-caps" open>
			<summary>
				<?php
				$total = Trials::denial_total( $trial );
				/* translators: %s: number of times they were refused. */
				echo esc_html( sprintf( _n( 'Refused %s time', 'Refused %s times', $total, 'role-right-sizer' ), number_format_i18n( $total ) ) );
				?>
			</summary>
			<ul class="rrs-denials">
				<?php foreach ( $denials as $denial ) : ?>
					<li>
						<?php echo esc_html( (string) $denial['label'] ); ?>
						<span class="axle-muted">
							<?php
							/* translators: %s: number of times. */
							echo esc_html( sprintf( _n( '%s time', '%s times', (int) $denial['count'], 'role-right-sizer' ), number_format_i18n( (int) $denial['count'] ) ) );
							echo ', ';
							esc_html_e( 'last', 'role-right-sizer' );
							echo ' ';
							Screen::print_time( (int) $denial['last'] );
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	/**
	 * Prints the status as a badge with a word.
	 *
	 * @param array $trial The trial.
	 */
	private function render_status( array $trial ): void {
		$map = array(
			'active'   => array( 'info', __( 'Running', 'role-right-sizer' ) ),
			'ended'    => array( 'warning', __( 'Over, needs a decision', 'role-right-sizer' ) ),
			'kept'     => array( 'good', ! empty( $trial['auto'] ) ? __( 'Made permanent, no denials', 'role-right-sizer' ) : __( 'Made permanent', 'role-right-sizer' ) ),
			'restored' => array( 'neutral', __( 'Old role restored', 'role-right-sizer' ) ),
		);
		$row = $map[ $trial['status'] ] ?? $map['restored'];
		printf( '<span class="axle-badge axle-badge--%1$s">%2$s</span>', esc_attr( $row[0] ), esc_html( $row[1] ) );
	}

	/**
	 * Prints the actions for an open trial.
	 *
	 * @param array $trial The trial.
	 * @param int   $total How many times the person was refused.
	 */
	private function render_actions( array $trial, int $total ): void {
		$fields = array( 'trial' => $trial['id'] );
		$days   = $this->trials->days();

		Screen::print_action(
			'trial_restore',
			__( 'Restore now', 'role-right-sizer' ),
			$fields,
			array(
				'confirm'       => sprintf(
					/* translators: 1: roles the person has now, 2: roles they get back. */
					__( 'This changes the role from %1$s back to %2$s now and ends the trial. The change is logged as undone.', 'role-right-sizer' ),
					Screen::roles_label( (array) $trial['after'] ),
					Screen::roles_label( (array) $trial['before'] )
				),
				'confirm_label' => __( 'Restore the old role', 'role-right-sizer' ),
				'danger'        => true,
			)
		);

		Screen::print_action(
			'trial_extend',
			/* translators: %s: number of days. */
			sprintf( _n( 'Extend by %s day', 'Extend by %s days', $days, 'role-right-sizer' ), number_format_i18n( $days ) ),
			$fields,
			array( 'style' => 'link' )
		);

		Screen::print_action(
			'trial_keep',
			__( 'Make permanent', 'role-right-sizer' ),
			$fields,
			array(
				'style'         => 'link',
				'confirm'       => $total
					/* translators: %s: number of times. */
					? sprintf( _n( 'They were refused %s time during the trial. Making the smaller role permanent keeps them from doing those things. The change stays in the log, where Undo can still give the old role back.', 'They were refused %s times during the trial. Making the smaller role permanent keeps them from doing those things. The change stays in the log, where Undo can still give the old role back.', $total, 'role-right-sizer' ), number_format_i18n( $total ) )
					: __( 'Nothing stopped them during the trial. They keep the smaller role. The change stays in the log, where Undo can still give the old role back.', 'role-right-sizer' ),
				'confirm_label' => __( 'Keep the smaller role', 'role-right-sizer' ),
			)
		);
	}
}
