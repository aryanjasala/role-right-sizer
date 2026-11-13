<?php
/**
 * Users > Right-Sizer > Reviews.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Admin;

use AryanX\Axle\RoleRightSizer\Access;
use AryanX\Axle\RoleRightSizer\Changes;
use AryanX\Axle\RoleRightSizer\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * The list of access reviews, one review to work through, and its evidence.
 */
final class ReviewsTab {

	private const PER_PAGE = 25;

	/**
	 * The reviews.
	 *
	 * @var Reviews
	 */
	private Reviews $reviews;

	/**
	 * Change log.
	 *
	 * @var Changes
	 */
	private Changes $changes;

	/**
	 * Sets up the tab.
	 *
	 * @param Reviews $reviews The reviews.
	 * @param Changes $changes Change log.
	 */
	public function __construct( Reviews $reviews, Changes $changes ) {
		$this->reviews = $reviews;
		$this->changes = $changes;
	}

	/**
	 * Handles a review form or download. Called after the nonce and capability checks.
	 *
	 * @param string $task Task name starting with `review_`.
	 * @return array Query arguments for the redirect. Downloads exit instead.
	 */
	public function handle( string $task ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Checked in Screen::handle().
		$id   = isset( $_REQUEST['review'] ) ? absint( $_REQUEST['review'] ) : 0;
		$args = array( 'review' => $id );

		switch ( $task ) {
			case 'review_start':
				$result = $this->reviews->start(
					isset( $_POST['review_name'] ) ? sanitize_text_field( wp_unslash( $_POST['review_name'] ) ) : '',
					Reviews::due_from_date( isset( $_POST['review_due'] ) ? sanitize_text_field( wp_unslash( $_POST['review_due'] ) ) : '' ),
					Screen::user_ids( isset( $_POST['review_reviewers'] ) ? sanitize_textarea_field( wp_unslash( $_POST['review_reviewers'] ) ) : '' )
				);
				if ( is_wp_error( $result ) ) {
					return array( 'rrs_errors' => self::code( $result ) );
				}

				return array(
					'rrs_done' => 'review_started',
					'review'   => $result['id'],
				);

			case 'review_save':
				$rows = array();
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every cell is sanitised below.
				$posted = isset( $_POST['decisions'] ) && is_array( $_POST['decisions'] ) ? wp_unslash( $_POST['decisions'] ) : array();
				foreach ( $posted as $user_id => $row ) {
					$row                        = is_array( $row ) ? $row : array();
					$rows[ absint( $user_id ) ] = array(
						'decision' => isset( $row['decision'] ) ? sanitize_key( $row['decision'] ) : '',
						'role'     => isset( $row['role'] ) ? sanitize_key( $row['role'] ) : '',
						'note'     => isset( $row['note'] ) ? sanitize_textarea_field( $row['note'] ) : '',
					);
				}
				$result = $this->reviews->decide_many( $id, $rows );
				if ( is_wp_error( $result ) ) {
					return array( 'rrs_errors' => self::code( $result ) );
				}
				$args['rrs_done']   = 'review_saved';
				$args['rrs_count']  = $result['saved'];
				$args['rrs_errors'] = self::codes( $result['errors'] );
				$args['paged']      = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 0;

				return $args;

			case 'review_keep_rest':
				$result = $this->reviews->keep_undecided( $id );
				if ( is_wp_error( $result ) ) {
					return array( 'rrs_errors' => self::code( $result ) );
				}
				$args['rrs_done']  = 'review_saved';
				$args['rrs_count'] = $result;

				return $args;

			case 'review_finish':
				$result = $this->reviews->finish( $id );
				if ( is_wp_error( $result ) ) {
					return array(
						'rrs_errors' => self::code( $result ),
						'review'     => $id,
					);
				}
				$changed = 0;
				$failed  = 0;
				foreach ( $result['entries'] as $entry ) {
					$status   = (string) ( $entry['result']['status'] ?? '' );
					$changed += 'applied' === $status ? 1 : 0;
					$failed  += in_array( $status, array( 'failed', 'gone' ), true ) ? 1 : 0;
				}
				$args['rrs_done']   = 'review_finished';
				$args['rrs_count']  = $changed;
				$args['rrs_failed'] = $failed;
				$args['rrs_batch']  = (int) $result['batch'];

				return $args;

			case 'review_discard':
				$result = $this->reviews->discard( $id );
				if ( is_wp_error( $result ) ) {
					return array( 'rrs_errors' => self::code( $result ) );
				}

				return array( 'rrs_done' => 'review_discarded' );

			case 'review_csv':
			case 'review_report':
				$review = $this->reviews->get( $id );
				if ( ! $review ) {
					return array( 'rrs_errors' => 'review_not_found' );
				}
				nocache_headers();
				if ( 'review_csv' === $task ) {
					header( 'Content-Type: text/csv; charset=utf-8' );
					header( 'Content-Disposition: attachment; filename=role-right-sizer-review-' . (int) $review['id'] . '-' . gmdate( 'Y-m-d' ) . '.csv' );
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, not HTML. Every cell is quoted.
					echo $this->reviews->csv( $review );
				} else {
					header( 'Content-Type: text/html; charset=utf-8' );
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
					echo $this->reviews->html( $review );
				}
				exit;
		}
		// phpcs:enable

		return array();
	}

	/**
	 * An error as the code the redirect carries.
	 *
	 * @param \WP_Error $error The error.
	 * @return string
	 */
	private static function code( \WP_Error $error ): string {
		return str_replace( 'role_right_sizer_', '', (string) $error->get_error_code() );
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
			$codes[] = self::code( $error );
		}

		return implode( ',', array_unique( $codes ) );
	}

	/**
	 * Prints the tab: one review when `review` is in the address, the list otherwise.
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$id     = isset( $_GET['review'] ) ? absint( $_GET['review'] ) : 0;
		$review = $id ? $this->reviews->get( $id ) : null;

		if ( $review ) {
			$this->render_review( $review );
		} else {
			$this->render_list();
		}
	}

	/**
	 * Address of a review.
	 *
	 * @param int   $id   Review ID.
	 * @param array $args More query arguments.
	 * @return string
	 */
	private static function url( int $id, array $args = array() ): string {
		return Screen::url( array_merge( array( 'tab' => 'reviews' ), $id ? array( 'review' => $id ) : array(), $args ) );
	}

	/**
	 * Address of a download.
	 *
	 * @param string $task Task.
	 * @param int    $id   Review ID.
	 * @return string
	 */
	private static function download_url( string $task, int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => Screen::ACTION,
					'task'   => $task,
					'review' => $id,
				),
				admin_url( 'admin-post.php' )
			),
			Screen::ACTION
		);
	}

	/**
	 * Prints the list of reviews and the form to start one.
	 */
	private function render_list(): void {
		$all  = $this->reviews->all();
		$open = $this->reviews->open();
		$next = $this->reviews->next_due();
		?>
		<div class="axle-stats">
			<div class="axle-stat<?php echo $this->reviews->is_overdue() ? ' axle-stat--warning' : ''; ?>">
				<span class="axle-stat__value"><?php echo esc_html( $this->reviews->last_finished() ? AxleUi::relative_time( $this->reviews->last_finished() ) : __( 'Never', 'role-right-sizer' ) ); ?></span>
				<span class="axle-stat__label"><?php esc_html_e( 'last review finished', 'role-right-sizer' ); ?></span>
			</div>
			<div class="axle-stat<?php echo $this->reviews->is_overdue() ? ' axle-stat--warning' : ''; ?>">
				<span class="axle-stat__value"><?php echo esc_html( $next ? AxleUi::relative_time( $next ) : __( 'Off', 'role-right-sizer' ) ); ?></span>
				<span class="axle-stat__label"><?php esc_html_e( 'next review due', 'role-right-sizer' ); ?></span>
			</div>
		</div>
		<?php
		if ( ! $open ) {
			$this->render_start_form();
		}

		if ( ! $all ) {
			?>
			<div class="axle-empty rrs-empty rrs-reviews-empty">
				<p>
					<strong><?php esc_html_e( 'No reviews yet.', 'role-right-sizer' ); ?></strong>
					<?php esc_html_e( 'A review is a snapshot of every account. For each one you choose Keep, Reduce or Remove access, then finish it to apply the decisions and download the evidence for your auditor.', 'role-right-sizer' ); ?>
				</p>
			</div>
			<?php
			return;
		}
		?>
		<section class="axle-card rrs-section">
			<h2 class="axle-card__title"><?php esc_html_e( 'Reviews', 'role-right-sizer' ); ?></h2>
			<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Reviews', 'role-right-sizer' ); ?>">
				<table class="widefat striped rrs-table rrs-reviews">
					<thead>
						<tr>
							<th scope="col" class="column-primary"><?php esc_html_e( 'Review', 'role-right-sizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'role-right-sizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Started', 'role-right-sizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Due', 'role-right-sizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Progress', 'role-right-sizer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $all as $review ) : ?>
							<?php $progress = Reviews::progress( $review ); ?>
							<tr data-review="<?php echo esc_attr( (string) $review['id'] ); ?>">
								<td class="column-primary">
									<a href="<?php echo esc_url( self::url( (int) $review['id'] ) ); ?>"><strong><?php echo esc_html( (string) $review['name'] ); ?></strong></a>
								</td>
								<td>
									<?php if ( 'finished' === $review['status'] ) : ?>
										<span class="axle-badge axle-badge--good"><?php esc_html_e( 'Finished', 'role-right-sizer' ); ?></span>
										<span class="axle-muted"><?php Screen::print_time( (int) $review['finished'] ); ?></span>
									<?php elseif ( Reviews::is_late( $review ) ) : ?>
										<span class="axle-badge axle-badge--warning"><?php esc_html_e( 'Overdue', 'role-right-sizer' ); ?></span>
									<?php else : ?>
										<span class="axle-badge axle-badge--info"><?php esc_html_e( 'In progress', 'role-right-sizer' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php Screen::print_time( (int) $review['started'] ); ?></td>
								<td><?php Screen::print_time( (int) $review['due'], __( 'No due date', 'role-right-sizer' ) ); ?></td>
								<td>
									<?php
									/* translators: 1: accounts decided, 2: accounts in the review. */
									echo esc_html( sprintf( __( '%1$s of %2$s decided', 'role-right-sizer' ), number_format_i18n( $progress['decided'] ), number_format_i18n( $progress['total'] ) ) );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	/**
	 * Prints the form that starts a review.
	 */
	private function render_start_form(): void {
		$days    = $this->reviews->default_due_days();
		$default = $days ? wp_date( 'Y-m-d', time() + $days * DAY_IN_SECONDS ) : '';
		?>
		<section class="axle-card">
			<h2 class="axle-card__title"><?php esc_html_e( 'Start a review', 'role-right-sizer' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( Screen::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( Screen::ACTION ); ?>">
				<input type="hidden" name="task" value="review_start">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rrs-review-name"><?php esc_html_e( 'Name', 'role-right-sizer' ); ?></label></th>
						<td>
							<input type="text" id="rrs-review-name" name="review_name" class="regular-text" maxlength="120">
							<p class="description"><?php esc_html_e( 'For example Q4 access review. Leave empty to use the date.', 'role-right-sizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rrs-review-due"><?php esc_html_e( 'Due date', 'role-right-sizer' ); ?></label></th>
						<td>
							<input type="date" id="rrs-review-due" name="review_due" value="<?php echo esc_attr( $default ); ?>">
							<p class="description"><?php esc_html_e( 'Reviewers get an email a few days before, and again when it is overdue. Leave empty for no date.', 'role-right-sizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rrs-review-reviewers"><?php esc_html_e( 'Reviewers', 'role-right-sizer' ); ?></label></th>
						<td>
							<textarea id="rrs-review-reviewers" name="review_reviewers" rows="3" class="regular-text"></textarea>
							<p class="description"><?php esc_html_e( 'Usernames or email addresses, one per line. They get an email, and anyone who can promote users can work on the review. Leave empty to tell only yourself.', 'role-right-sizer' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="description"><?php esc_html_e( 'Starting a review changes nothing. It takes a snapshot of every account you may edit, with what each one used and what Right-Sizer recommends.', 'role-right-sizer' ); ?></p>
				<?php submit_button( __( 'Start review', 'role-right-sizer' ), 'primary', 'submit', false ); ?>
			</form>
		</section>
		<?php
	}

	/**
	 * Prints one review.
	 *
	 * @param array $review The review.
	 */
	private function render_review( array $review ): void {
		$id       = (int) $review['id'];
		$open     = 'open' === $review['status'];
		$progress = Reviews::progress( $review );
		$all      = Reviews::visible_entries( $review );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navigation and filters only.
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$entries = array_values(
			array_filter(
				$all,
				static function ( array $entry ) use ( $filter, $search ): bool {
					if ( 'undecided' === $filter && '' !== $entry['decision'] ) {
						return false;
					}
					if ( 'changes' === $filter && ! in_array( $entry['decision'], array( 'reduce', 'remove' ), true ) ) {
						return false;
					}
					return '' === $search || false !== stripos( $entry['login'] . ' ' . $entry['name'], $search );
				}
			)
		);
		$pages   = max( 1, (int) ceil( count( $entries ) / self::PER_PAGE ) );
		$paged   = min( $paged, $pages );
		$shown   = array_slice( $entries, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$percent = $progress['total'] ? (int) round( 100 * $progress['decided'] / $progress['total'] ) : 0;
		?>
		<p><a href="<?php echo esc_url( self::url( 0 ) ); ?>">&larr; <?php esc_html_e( 'All reviews', 'role-right-sizer' ); ?></a></p>
		<section class="axle-card rrs-review-head">
			<h2 class="axle-card__title"><?php echo esc_html( (string) $review['name'] ); ?></h2>
			<dl class="axle-facts rrs-facts">
				<dt><?php esc_html_e( 'Status', 'role-right-sizer' ); ?></dt>
				<dd>
					<?php if ( ! $open ) : ?>
						<span class="axle-badge axle-badge--good"><?php esc_html_e( 'Finished', 'role-right-sizer' ); ?></span> <?php Screen::print_time( (int) $review['finished'] ); ?>
					<?php elseif ( Reviews::is_late( $review ) ) : ?>
						<span class="axle-badge axle-badge--warning"><?php esc_html_e( 'Overdue', 'role-right-sizer' ); ?></span>
					<?php else : ?>
						<span class="axle-badge axle-badge--info"><?php esc_html_e( 'In progress', 'role-right-sizer' ); ?></span>
					<?php endif; ?>
				</dd>
				<dt><?php esc_html_e( 'Started', 'role-right-sizer' ); ?></dt>
				<dd><?php Screen::print_time( (int) $review['started'] ); ?></dd>
				<dt><?php esc_html_e( 'Due', 'role-right-sizer' ); ?></dt>
				<dd><?php Screen::print_time( (int) $review['due'], __( 'No due date', 'role-right-sizer' ) ); ?></dd>
				<dt><?php esc_html_e( 'Decided', 'role-right-sizer' ); ?></dt>
				<dd>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: decided, 2: total, 3: keep, 4: reduce, 5: remove. */
							__( '%1$s of %2$s (%3$s keep, %4$s reduce, %5$s remove)', 'role-right-sizer' ),
							number_format_i18n( $progress['decided'] ),
							number_format_i18n( $progress['total'] ),
							number_format_i18n( $progress['keep'] ),
							number_format_i18n( $progress['reduce'] ),
							number_format_i18n( $progress['remove'] )
						)
					);
					?>
					<div class="axle-progress" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Accounts decided', 'role-right-sizer' ); ?>"><span class="axle-progress__bar" style="width:<?php echo esc_attr( (string) $percent ); ?>%"></span></div>
				</dd>
			</dl>
			<p class="axle-actions">
				<a class="button" href="<?php echo esc_url( self::download_url( 'review_csv', $id ) ); ?>"><?php esc_html_e( 'Download CSV', 'role-right-sizer' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::download_url( 'review_report', $id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open printable report', 'role-right-sizer' ); ?></a>
			</p>
		</section>

		<div class="axle-toolbar">
			<form method="get" action="<?php echo esc_url( admin_url( 'users.php' ) ); ?>" class="rrs-review-filter">
				<input type="hidden" name="page" value="<?php echo esc_attr( Screen::SLUG ); ?>">
				<input type="hidden" name="tab" value="reviews">
				<input type="hidden" name="review" value="<?php echo esc_attr( (string) $id ); ?>">
				<label for="rrs-review-filter"><?php esc_html_e( 'Show', 'role-right-sizer' ); ?></label>
				<select id="rrs-review-filter" name="filter">
					<option value="all" <?php selected( $filter, 'all' ); ?>><?php esc_html_e( 'Everyone', 'role-right-sizer' ); ?></option>
					<option value="undecided" <?php selected( $filter, 'undecided' ); ?>><?php esc_html_e( 'Not decided yet', 'role-right-sizer' ); ?></option>
					<option value="changes" <?php selected( $filter, 'changes' ); ?>><?php esc_html_e( 'Reduce or remove', 'role-right-sizer' ); ?></option>
				</select>
				<label for="rrs-review-search" class="screen-reader-text"><?php esc_html_e( 'Search people', 'role-right-sizer' ); ?></label>
				<input type="search" id="rrs-review-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search people', 'role-right-sizer' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'role-right-sizer' ); ?></button>
			</form>
			<?php if ( $open && $progress['decided'] < $progress['total'] ) : ?>
				<span class="axle-toolbar__end">
					<?php
					Screen::print_action(
						'review_keep_rest',
						__( 'Keep everyone not decided yet', 'role-right-sizer' ),
						array( 'review' => $id ),
						array(
							'confirm'       => __( 'Every account without a decision is marked Keep. Nothing changes on any account, and you can still change a decision before you finish.', 'role-right-sizer' ),
							'confirm_label' => __( 'Mark them Keep', 'role-right-sizer' ),
						)
					);
					?>
				</span>
			<?php endif; ?>
		</div>

		<?php if ( ! $shown ) : ?>
			<div class="axle-empty rrs-empty">
				<p><?php esc_html_e( 'No one matches this filter.', 'role-right-sizer' ); ?></p>
			</div>
		<?php else : ?>
			<?php $this->render_entries( $review, $shown, $open, $paged, $pages, count( $entries ), $filter, $search ); ?>
		<?php endif; ?>

		<?php
		if ( $open ) {
			$this->render_finish( $review, $progress );
		} else {
			$this->render_undo( $review );
		}
	}

	/**
	 * Prints the table of accounts.
	 *
	 * @param array  $review  The review.
	 * @param array  $entries The entries on this page.
	 * @param bool   $open    Whether decisions can be made.
	 * @param int    $paged   Page number.
	 * @param int    $pages   Number of pages.
	 * @param int    $total   Entries matching the filter.
	 * @param string $filter  Filter.
	 * @param string $search  Search.
	 */
	private function render_entries( array $review, array $entries, bool $open, int $paged, int $pages, int $total, string $filter, string $search ): void {
		$id    = (int) $review['id'];
		$roles = wp_roles()->roles;
		$me    = get_current_user_id();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rrs-review-form">
			<?php wp_nonce_field( Screen::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( Screen::ACTION ); ?>">
			<input type="hidden" name="task" value="review_save">
			<input type="hidden" name="review" value="<?php echo esc_attr( (string) $id ); ?>">
			<input type="hidden" name="paged" value="<?php echo esc_attr( (string) $paged ); ?>">
			<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Accounts in this review', 'role-right-sizer' ); ?>">
				<table class="widefat striped rrs-table rrs-review-table">
					<thead>
						<tr>
							<th scope="col" class="column-primary"><?php esc_html_e( 'Person', 'role-right-sizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Role', 'role-right-sizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last active', 'role-right-sizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Recommendation', 'role-right-sizer' ); ?></th>
							<th scope="col"><?php echo esc_html( $open ? __( 'Decision', 'role-right-sizer' ) : __( 'Decision and result', 'role-right-sizer' ) ); ?></th>
							<th scope="col"><?php esc_html_e( 'Note', 'role-right-sizer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<?php
							$uid     = (int) $entry['user_id'];
							$can     = Access::can_manage_user( $uid ) && $me !== $uid;
							$name    = "decisions[{$uid}]";
							$prefix  = 'rrs-r' . $uid;
							$default = '' !== $entry['role'] ? $entry['role'] : ( is_string( $entry['proposed'] ) ? $entry['proposed'] : '' );
							?>
							<tr data-user="<?php echo esc_attr( (string) $entry['login'] ); ?>">
								<td class="column-primary">
									<strong><?php echo esc_html( ! empty( $entry['erased'] ) ? __( '(removed on request)', 'role-right-sizer' ) : (string) $entry['name'] ); ?></strong>
									<span class="axle-muted"><?php echo esc_html( (string) $entry['login'] ); ?></span>
								</td>
								<td><?php echo esc_html( Reviews::roles_text( (array) $entry['roles'] ) ); ?></td>
								<td><?php Screen::print_time( (int) $entry['last_active'], __( 'Never', 'role-right-sizer' ) ); ?></td>
								<td>
									<?php echo esc_html( Reviews::recommendation_text( $entry ) ); ?>
									<?php if ( $entry['lost'] ) : ?>
										<details class="rrs-caps">
											<summary><?php esc_html_e( 'What it takes away', 'role-right-sizer' ); ?></summary>
											<?php $this->print_caps( (array) $entry['lost'] ); ?>
										</details>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $open ) : ?>
										<fieldset class="rrs-decision">
											<legend class="screen-reader-text">
												<?php
												/* translators: %s: person's name. */
												echo esc_html( sprintf( __( 'Decision for %s', 'role-right-sizer' ), (string) $entry['name'] ) );
												?>
											</legend>
											<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[decision]" value="keep" <?php checked( 'keep', $entry['decision'] ); ?>> <?php esc_html_e( 'Keep', 'role-right-sizer' ); ?></label>
											<?php if ( $can ) : ?>
												<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[decision]" value="reduce" <?php checked( 'reduce', $entry['decision'] ); ?>> <?php esc_html_e( 'Reduce to', 'role-right-sizer' ); ?></label>
												<label class="screen-reader-text" for="<?php echo esc_attr( $prefix ); ?>-role"><?php esc_html_e( 'Role to reduce to', 'role-right-sizer' ); ?></label>
												<select id="<?php echo esc_attr( $prefix ); ?>-role" name="<?php echo esc_attr( $name ); ?>[role]">
													<?php foreach ( $roles as $role_name => $role ) : ?>
														<option value="<?php echo esc_attr( (string) $role_name ); ?>" <?php selected( $default, $role_name ); ?>><?php echo esc_html( translate_user_role( (string) $role['name'] ) ); ?></option>
													<?php endforeach; ?>
												</select>
												<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[decision]" value="remove" <?php checked( 'remove', $entry['decision'] ); ?>> <?php esc_html_e( 'Remove access', 'role-right-sizer' ); ?></label>
											<?php else : ?>
												<span class="axle-muted"><?php echo esc_html( $me === $uid ? __( 'You cannot change your own role here.', 'role-right-sizer' ) : __( 'You may not change this account.', 'role-right-sizer' ) ); ?></span>
											<?php endif; ?>
										</fieldset>
									<?php else : ?>
										<?php echo esc_html( $this->reviews->decision_text( $entry ) ); ?>
										<?php $this->render_result( $entry ); ?>
									<?php endif; ?>
									<?php if ( '' !== $entry['decision'] ) : ?>
										<span class="axle-muted rrs-note">
											<?php
											/* translators: %s: person's name. */
											echo esc_html( sprintf( __( 'by %s', 'role-right-sizer' ), $this->reviews->person( (int) $entry['reviewer'] ) ) );
											echo ' ';
											Screen::print_time( (int) $entry['decided'] );
											?>
										</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $open ) : ?>
										<label class="screen-reader-text" for="<?php echo esc_attr( $prefix ); ?>-note">
											<?php
											/* translators: %s: person's name. */
											echo esc_html( sprintf( __( 'Note for %s', 'role-right-sizer' ), (string) $entry['name'] ) );
											?>
										</label>
										<input type="text" id="<?php echo esc_attr( $prefix ); ?>-note" name="<?php echo esc_attr( $name ); ?>[note]" value="<?php echo esc_attr( (string) $entry['note'] ); ?>" class="regular-text" maxlength="500">
									<?php else : ?>
										<?php echo esc_html( (string) $entry['note'] ); ?>
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
								'base'      => add_query_arg(
									'paged',
									'%#%',
									self::url(
										$id,
										array_filter(
											array(
												'filter' => 'all' === $filter ? '' : $filter,
												's'      => $search,
											) 
										) 
									) 
								),
								'format'    => '',
								'current'   => $paged,
								'total'     => $pages,
								'prev_text' => __( 'Previous page', 'role-right-sizer' ),
								'next_text' => __( 'Next page', 'role-right-sizer' ),
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
			<?php if ( $open ) : ?>
				<p class="axle-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save decisions', 'role-right-sizer' ); ?></button>
					<span class="description">
						<?php
						/* translators: %s: number of accounts matching the filter. */
						echo esc_html( sprintf( _n( 'Saves the decisions on this page. %s account matches.', 'Saves the decisions on this page. %s accounts match.', $total, 'role-right-sizer' ), number_format_i18n( $total ) ) );
						?>
					</span>
				</p>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Prints a list of capabilities, folded when long.
	 *
	 * @param string[] $caps Capabilities.
	 */
	private function print_caps( array $caps ): void {
		echo '<ul class="rrs-lost">';
		foreach ( array_slice( $caps, 0, 30 ) as $cap ) {
			echo '<li><code>' . esc_html( (string) $cap ) . '</code></li>';
		}
		if ( count( $caps ) > 30 ) {
			/* translators: %s: number of capabilities. */
			echo '<li>' . esc_html( sprintf( __( 'and %s more', 'role-right-sizer' ), number_format_i18n( count( $caps ) - 30 ) ) ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Prints what happened to an account when the review was finished.
	 *
	 * @param array $entry The entry.
	 */
	private function render_result( array $entry ): void {
		$result = (array) $entry['result'];
		$status = (string) ( $result['status'] ?? '' );
		if ( '' === $status || 'kept' === $status ) {
			return;
		}
		$map = array(
			'applied'   => array( 'good', __( 'Applied', 'role-right-sizer' ) ),
			'unchanged' => array( 'neutral', __( 'Nothing to change', 'role-right-sizer' ) ),
			'failed'    => array( 'error', __( 'Not applied', 'role-right-sizer' ) ),
			'gone'      => array( 'neutral', __( 'Account gone', 'role-right-sizer' ) ),
		);
		$row = $map[ $status ] ?? $map['failed'];
		printf( '<br><span class="axle-badge axle-badge--%1$s">%2$s</span>', esc_attr( $row[0] ), esc_html( $row[1] ) );
		if ( 'applied' === $status ) {
			?>
			<div class="axle-diff">
				<del><span class="screen-reader-text"><?php esc_html_e( 'Before:', 'role-right-sizer' ); ?></span> <?php echo esc_html( Reviews::roles_text( (array) $result['before'] ) ); ?></del>
				<ins><span class="screen-reader-text"><?php esc_html_e( 'After:', 'role-right-sizer' ); ?></span> <?php echo esc_html( Reviews::roles_text( (array) $result['after'] ) ); ?></ins>
			</div>
			<?php
		} elseif ( ! empty( $result['message'] ) ) {
			echo ' <span class="axle-muted">' . esc_html( (string) $result['message'] ) . '</span>';
		}
	}

	/**
	 * Prints the card that finishes or discards an open review.
	 *
	 * @param array $review   The review.
	 * @param array $progress Progress.
	 */
	private function render_finish( array $review, array $progress ): void {
		$id   = (int) $review['id'];
		$plan = $this->reviews->plan( $review );
		$left = $progress['total'] - $progress['decided'];
		$fine = array_filter(
			$plan,
			static function ( array $row ): bool {
				return '' === $row['problem'];
			}
		);
		?>
		<section class="axle-card rrs-finish">
			<h2 class="axle-card__title"><?php esc_html_e( 'Finish the review', 'role-right-sizer' ); ?></h2>
			<?php if ( $left ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of accounts. */
							_n( '%s account has no decision yet. Every account needs one before you can finish.', '%s accounts have no decision yet. Every account needs one before you can finish.', $left, 'role-right-sizer' ),
							number_format_i18n( $left )
						)
					);
					?>
				</p>
			<?php elseif ( ! $plan ) : ?>
				<p><?php esc_html_e( 'Everyone is kept, so finishing changes no account. It closes the review and makes the evidence final.', 'role-right-sizer' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Finishing applies these changes now. They go into the change log as one batch, where Undo puts all of them back.', 'role-right-sizer' ); ?></p>
				<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Changes finishing will make', 'role-right-sizer' ); ?>">
					<table class="widefat striped rrs-table rrs-plan">
						<thead>
							<tr>
								<th scope="col" class="column-primary"><?php esc_html_e( 'Person', 'role-right-sizer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Role', 'role-right-sizer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'What it takes away', 'role-right-sizer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $plan as $row ) : ?>
								<tr>
									<td class="column-primary"><strong><?php echo esc_html( $row['name'] ); ?></strong> <span class="axle-muted"><?php echo esc_html( $row['login'] ); ?></span></td>
									<td>
										<div class="axle-diff">
											<del><span class="screen-reader-text"><?php esc_html_e( 'Before:', 'role-right-sizer' ); ?></span> <?php echo esc_html( Reviews::roles_text( $row['from'] ) ); ?></del>
											<ins><span class="screen-reader-text"><?php esc_html_e( 'After:', 'role-right-sizer' ); ?></span> <?php echo esc_html( '' === $row['to'] ? __( 'No role', 'role-right-sizer' ) : Screen::role_label( $row['to'] ) ); ?></ins>
										</div>
									</td>
									<td>
										<?php if ( '' !== $row['problem'] ) : ?>
											<span class="axle-badge axle-badge--warning"><?php esc_html_e( 'Will be skipped', 'role-right-sizer' ); ?></span>
											<span class="axle-muted"><?php echo esc_html( $row['problem'] ); ?></span>
										<?php else : ?>
											<?php $this->print_caps( $row['lost'] ); ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
			<p class="axle-actions">
				<?php
				if ( ! $left ) {
					Screen::print_action(
						'review_finish',
						__( 'Finish review', 'role-right-sizer' ),
						array( 'review' => $id ),
						array(
							'style'         => 'primary',
							'confirm'       => $fine
								/* translators: %s: number of accounts. */
								? sprintf( _n( 'This changes the role of %s account now and closes the review. The change goes in the change log, where Undo can put it back. The review itself cannot be reopened.', 'This changes the role of %s accounts now and closes the review. The changes go in the change log, where Undo can put them back. The review itself cannot be reopened.', count( $fine ), 'role-right-sizer' ), number_format_i18n( count( $fine ) ) )
								: __( 'No account changes. This closes the review, and it cannot be reopened.', 'role-right-sizer' ),
							'confirm_label' => __( 'Apply and finish', 'role-right-sizer' ),
							'danger'        => (bool) $fine,
						)
					);
				}
				Screen::print_action(
					'review_discard',
					__( 'Discard review', 'role-right-sizer' ),
					array( 'review' => $id ),
					array(
						'style'         => 'link',
						'confirm'       => __( 'This deletes the review with every decision and note. No account has changed. This cannot be undone.', 'role-right-sizer' ),
						'confirm_label' => __( 'Discard the review', 'role-right-sizer' ),
						'danger'        => true,
					)
				);
				?>
			</p>
		</section>
		<?php
	}

	/**
	 * Prints the Undo for a finished review's changes.
	 *
	 * @param array $review The review.
	 */
	private function render_undo( array $review ): void {
		if ( ! (int) $review['batch'] ) {
			return;
		}
		$open = array_filter(
			$this->reviews_changes( $review ),
			static function ( array $change ): bool {
				return ! $change['undone'];
			}
		);
		if ( ! $open ) {
			return;
		}
		?>
		<section class="axle-card">
			<h2 class="axle-card__title"><?php esc_html_e( 'Undo this review', 'role-right-sizer' ); ?></h2>
			<p><?php esc_html_e( 'Gives every changed account its earlier role back. The review and its evidence stay as they are, and the report notes what was undone.', 'role-right-sizer' ); ?></p>
			<p>
				<?php
				Screen::print_action(
					'undo_batch',
					__( 'Undo all changes', 'role-right-sizer' ),
					array( 'batch' => (int) $review['batch'] ),
					array(
						'confirm'       => __( 'Every account this review changed goes back to the role it had before. The change log shows them as undone.', 'role-right-sizer' ),
						'confirm_label' => __( 'Undo the whole batch', 'role-right-sizer' ),
						'danger'        => true,
					)
				);
				?>
			</p>
		</section>
		<?php
	}

	/**
	 * The change log entries of a review.
	 *
	 * @param array $review The review.
	 * @return array[]
	 */
	private function reviews_changes( array $review ): array {
		$out = array();
		foreach ( (array) $review['entries'] as $entry ) {
			$id = (int) ( $entry['result']['change_id'] ?? 0 );
			if ( $id ) {
				$change = $this->changes->get( $id );
				if ( $change ) {
					$out[] = $change;
				}
			}
		}

		return $out;
	}
}
