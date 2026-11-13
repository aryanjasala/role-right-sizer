<?php
/**
 * Access reviews: go through every account, decide, apply, and keep the evidence.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer;

use AryanX\Axle\RoleRightSizer\Admin\Screen;
use AryanX\Axle\RoleRightSizer\Usage\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Starts, saves, finishes and exports access reviews.
 *
 * A review is an array:
 *
 * - `id`, `name`, `status` (`open` or `finished`), `started`, `by` (who started it, 0 for WP-CLI, -1 for a removed user)
 * - `due`: when it should be done (0 for no date), `reviewers`: user IDs who are asked to work on it, `reminded`: 0, 1 (due soon) or 2 (overdue)
 * - `entries`: `user_id => entry`, a snapshot of each account when the review started
 * - `finished`, `finished_by`, `batch`: when it was finished, by whom, and the change log batch its changes share
 *
 * An entry is an array:
 *
 * - `user_id`, `login`, `name`, `roles`, `last_login`, `last_active`, `used` (`cap => last used`)
 * - `type`, `proposed`, `lost`: the recommendation at that time (`type` is empty for none, `proposed` is null for none)
 * - `decision` (`keep`, `reduce`, `remove` or empty), `role` (for reduce), `note`, `reviewer`, `decided`
 * - `result`: `status` (`kept`, `applied`, `unchanged`, `failed`, `gone`), `message`, `change_id`, `before`, `after`, `lost`
 * - `erased`: the person's details were removed by the privacy eraser
 */
final class Reviews implements Service {

	public const OPTION = 'role_right_sizer_reviews';

	/**
	 * Settings and limits.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Applies and undoes changes.
	 *
	 * @var Applier
	 */
	private Applier $applier;

	/**
	 * Saved recommendations.
	 *
	 * @var Report
	 */
	private Report $report;

	/**
	 * Recorded usage.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Change log.
	 *
	 * @var Changes
	 */
	private Changes $changes;

	/**
	 * Trials of a smaller role.
	 *
	 * @var Trials
	 */
	private Trials $trials;

	/**
	 * Sets up the reviews.
	 *
	 * @param Settings $settings Settings and limits.
	 * @param Applier  $applier  Applies and undoes changes.
	 * @param Report   $report   Saved recommendations.
	 * @param Store    $store    Recorded usage.
	 * @param Changes  $changes  Change log.
	 * @param Trials   $trials   Trials of a smaller role.
	 */
	public function __construct( Settings $settings, Applier $applier, Report $report, Store $store, Changes $changes, Trials $trials ) {
		$this->settings = $settings;
		$this->applier  = $applier;
		$this->report   = $report;
		$this->store    = $store;
		$this->changes  = $changes;
		$this->trials   = $trials;
	}

	/**
	 * Hooks the daily reminders and user deletion.
	 */
	public function register(): void {
		add_action( Report::DAILY, array( $this, 'run_check' ) );
		add_action( 'delete_user', array( $this, 'forget_user' ) );
	}

	/**
	 * Every review, newest first.
	 *
	 * @return array[]
	 */
	public function all(): array {
		return $this->load()['items'];
	}

	/**
	 * One review.
	 *
	 * @param int $id Review ID.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		foreach ( $this->all() as $review ) {
			if ( (int) $review['id'] === $id ) {
				return $review;
			}
		}

		return null;
	}

	/**
	 * The review that is being worked on, if any.
	 *
	 * @return array|null
	 */
	public function open(): ?array {
		foreach ( $this->all() as $review ) {
			if ( 'open' === $review['status'] ) {
				return $review;
			}
		}

		return null;
	}

	/**
	 * When the last review was finished.
	 *
	 * @return int Unix time, 0 if none was.
	 */
	public function last_finished(): int {
		$last = 0;
		foreach ( $this->all() as $review ) {
			if ( 'finished' === $review['status'] ) {
				$last = max( $last, (int) $review['finished'] );
			}
		}

		return $last;
	}

	/**
	 * When the next review is due: one interval after the last finished review,
	 * or after tracking began when there is none.
	 *
	 * @return int Unix time, 0 when reminders are off.
	 */
	public function next_due(): int {
		$interval = $this->settings->review_interval();
		if ( ! $interval ) {
			return 0;
		}

		$last = $this->last_finished();

		return ( $last ? $last : $this->settings->tracking_started() ) + $interval * DAY_IN_SECONDS;
	}

	/**
	 * Whether it is time to start a review and none is running.
	 *
	 * @param int $now Unix time.
	 * @return bool
	 */
	public function is_overdue( int $now = 0 ): bool {
		$due = $this->next_due();

		return $due > 0 && $due <= ( $now ? $now : time() ) && null === $this->open();
	}

	/**
	 * Whether an open review is past its due date.
	 *
	 * @param array $review The review.
	 * @param int   $now    Unix time.
	 * @return bool
	 */
	public static function is_late( array $review, int $now = 0 ): bool {
		return 'open' === $review['status'] && (int) $review['due'] > 0 && (int) $review['due'] <= ( $now ? $now : time() );
	}

	/**
	 * How far a review has got.
	 *
	 * @param array $review The review.
	 * @return array{total: int, decided: int, keep: int, reduce: int, remove: int}
	 */
	public static function progress( array $review ): array {
		$out = array(
			'total'   => 0,
			'decided' => 0,
			'keep'    => 0,
			'reduce'  => 0,
			'remove'  => 0,
		);
		foreach ( (array) $review['entries'] as $entry ) {
			++$out['total'];
			if ( isset( $out[ $entry['decision'] ] ) ) {
				++$out['decided'];
				++$out[ $entry['decision'] ];
			}
		}

		return $out;
	}

	/**
	 * The entries the current user may see, in the order of the snapshot.
	 *
	 * @param array $review The review.
	 * @return array[]
	 */
	public static function visible_entries( array $review ): array {
		return array_values(
			array_filter(
				(array) $review['entries'],
				static function ( array $entry ): bool {
					return Access::can_view_user( (int) $entry['user_id'] );
				}
			)
		);
	}

	/**
	 * Days before the due date a reminder goes out.
	 *
	 * @return int
	 */
	public function reminder_days(): int {
		/**
		 * Filters how many days before the due date of a review the reviewers get a reminder.
		 *
		 * @param int $days Default 3. 0 sends no reminder before the date, only when it is overdue.
		 */
		return Settings::clamp( apply_filters( 'role_right_sizer_review_reminder_days', 3 ), 3, 0, 60 );
	}

	/**
	 * Days from today a new review is due, offered as the default.
	 *
	 * @return int
	 */
	public function default_due_days(): int {
		/**
		 * Filters how many days from now a new review is due by default.
		 *
		 * @param int $days Default 14. 0 offers no due date.
		 */
		return Settings::clamp( apply_filters( 'role_right_sizer_review_due_days', 14 ), 14, 0, 365 );
	}

	/**
	 * The most accounts a review can hold.
	 *
	 * @return int
	 */
	public function limit(): int {
		/**
		 * Filters the most accounts one review can hold. A review of a bigger site is refused, not cut short.
		 *
		 * @param int $limit Default 5000.
		 */
		return Settings::clamp( apply_filters( 'role_right_sizer_review_limit', 5000 ), 5000, 1, 100000 );
	}

	/**
	 * A due date from a `Y-m-d` string, as the end of that day on the site.
	 *
	 * @param string $date Date, empty for none.
	 * @return int Unix time, 0 for none or an invalid date.
	 */
	public static function due_from_date( string $date ): int {
		$date = trim( $date );
		if ( '' === $date ) {
			return 0;
		}

		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
			return 0;
		}

		return $parsed->setTime( 23, 59, 59 )->getTimestamp();
	}

	/**
	 * Starts a review: a snapshot of every account the current user may see.
	 *
	 * @param string $name      Name, empty for a dated default.
	 * @param int    $due       Due date as Unix time, 0 for none.
	 * @param int[]  $reviewers User IDs asked to work on it.
	 * @return array|\WP_Error The review, or why it was refused.
	 */
	public function start( string $name = '', int $due = 0, array $reviewers = array() ) {
		if ( $this->open() ) {
			return new \WP_Error( 'role_right_sizer_review_open', __( 'A review is already open. Finish it or discard it first.', 'role-right-sizer' ) );
		}
		if ( $due > 0 && $due < time() ) {
			return new \WP_Error( 'role_right_sizer_review_due', __( 'The due date has passed. Pick a day that has not come yet.', 'role-right-sizer' ) );
		}

		$limit   = $this->limit();
		$entries = array();
		$page    = 1;
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
				if ( ! Access::can_view_user( $user->ID ) ) {
					continue;
				}
				if ( count( $entries ) >= $limit ) {
					return new \WP_Error( 'role_right_sizer_review_too_big', __( 'This site has more accounts than one review can hold. Raise the limit with the role_right_sizer_review_limit filter.', 'role-right-sizer' ) );
				}
				$entries[ $user->ID ] = $this->snapshot( $user );
			}
			++$page;
			$found = count( $users );
		} while ( 100 === $found );

		if ( ! $entries ) {
			return new \WP_Error( 'role_right_sizer_review_empty', __( 'There are no accounts you may review.', 'role-right-sizer' ) );
		}

		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			/* translators: %s: date. */
			$name = sprintf( __( 'Access review %s', 'role-right-sizer' ), gmdate( 'Y-m-d' ) );
		}

		$reviewers = array_values( array_unique( array_filter( array_map( 'absint', $reviewers ) ) ) );
		$review    = $this->add(
			array(
				'name'        => mb_substr( $name, 0, 120 ),
				'status'      => 'open',
				'started'     => time(),
				'by'          => get_current_user_id(),
				'due'         => $due,
				'reviewers'   => $reviewers,
				'reminded'    => 0,
				'entries'     => $entries,
				'finished'    => 0,
				'finished_by' => 0,
				'batch'       => 0,
			)
		);

		/**
		 * Fires after an access review started.
		 *
		 * @param array $review The review, see Reviews.
		 */
		do_action( 'role_right_sizer_review_started', $review );
		$this->notify_started( $review );

		return $review;
	}

	/**
	 * Saves a reviewer's decision about one account.
	 *
	 * @param int    $id       Review ID.
	 * @param int    $user_id  The account.
	 * @param string $decision `keep`, `reduce` or `remove`.
	 * @param string $role     Role for `reduce`, empty to use the recommendation.
	 * @param string $note     A note, optional.
	 * @return array|\WP_Error The entry, or why it was refused.
	 */
	public function decide( int $id, int $user_id, string $decision, string $role = '', string $note = '' ) {
		$review = $this->get( $id );
		if ( ! $review ) {
			return new \WP_Error( 'role_right_sizer_review_not_found', __( 'That review does not exist.', 'role-right-sizer' ) );
		}

		$result = $this->set_decision( $review, $user_id, $decision, $role, $note );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->save_review( $result['review'] );

		return $result['entry'];
	}

	/**
	 * Saves several decisions at once, as the screen's form does.
	 *
	 * @param int     $id        Review ID.
	 * @param array[] $decisions `user_id => [ decision, role, note ]`. A row with no decision only saves its note when it had a decision already.
	 * @return array{saved: int, errors: array<int, \WP_Error>}|\WP_Error
	 */
	public function decide_many( int $id, array $decisions ) {
		$review = $this->get( $id );
		if ( ! $review ) {
			return new \WP_Error( 'role_right_sizer_review_not_found', __( 'That review does not exist.', 'role-right-sizer' ) );
		}
		if ( 'open' !== $review['status'] ) {
			return new \WP_Error( 'role_right_sizer_review_closed', __( 'That review is finished, so its decisions cannot change.', 'role-right-sizer' ) );
		}

		$saved  = 0;
		$errors = array();
		foreach ( $decisions as $user_id => $row ) {
			$user_id  = (int) $user_id;
			$row      = (array) $row;
			$decision = (string) ( $row['decision'] ?? '' );
			if ( '' === $decision ) {
				continue;
			}
			$entry = $review['entries'][ $user_id ] ?? null;
			if ( $entry && $decision === $entry['decision'] && ( 'reduce' !== $decision || (string) ( $row['role'] ?? $entry['role'] ) === (string) $entry['role'] ) && (string) ( $row['note'] ?? $entry['note'] ) === (string) $entry['note'] ) {
				continue;
			}
			$result = $this->set_decision( $review, $user_id, $decision, (string) ( $row['role'] ?? '' ), (string) ( $row['note'] ?? '' ) );
			if ( is_wp_error( $result ) ) {
				$errors[ $user_id ] = $result;
				continue;
			}
			$review = $result['review'];
			++$saved;
		}

		if ( $saved ) {
			$this->save_review( $review );
		}

		return array(
			'saved'  => $saved,
			'errors' => $errors,
		);
	}

	/**
	 * Marks every account nobody has decided on yet as Keep.
	 *
	 * Only accounts the current user may see are touched.
	 *
	 * @param int $id Review ID.
	 * @return int|\WP_Error How many were marked, or why not.
	 */
	public function keep_undecided( int $id ) {
		$review = $this->get( $id );
		if ( ! $review ) {
			return new \WP_Error( 'role_right_sizer_review_not_found', __( 'That review does not exist.', 'role-right-sizer' ) );
		}
		if ( 'open' !== $review['status'] ) {
			return new \WP_Error( 'role_right_sizer_review_closed', __( 'That review is finished, so its decisions cannot change.', 'role-right-sizer' ) );
		}

		$count = 0;
		foreach ( $review['entries'] as $user_id => $entry ) {
			if ( '' !== $entry['decision'] || ! Access::can_view_user( (int) $user_id ) ) {
				continue;
			}
			$result = $this->set_decision( $review, (int) $user_id, 'keep', '', '' );
			if ( ! is_wp_error( $result ) ) {
				$review = $result['review'];
				++$count;
			}
		}

		if ( $count ) {
			$this->save_review( $review );
		}

		return $count;
	}

	/**
	 * What finishing would change, without changing anything.
	 *
	 * @param array $review The review.
	 * @return array[] One row per Reduce or Remove: `user_id`, `login`, `name`, `from`, `to`, `lost`, `problem` (empty when it can be applied).
	 */
	public function plan( array $review ): array {
		$rows = array();
		foreach ( (array) $review['entries'] as $entry ) {
			if ( ! in_array( $entry['decision'], array( 'reduce', 'remove' ), true ) ) {
				continue;
			}

			$to   = 'remove' === $entry['decision'] ? '' : (string) $entry['role'];
			$user = get_userdata( (int) $entry['user_id'] );
			$row  = array(
				'user_id' => (int) $entry['user_id'],
				'login'   => (string) $entry['login'],
				'name'    => (string) $entry['name'],
				'from'    => $user instanceof \WP_User ? array_values( (array) $user->roles ) : (array) $entry['roles'],
				'to'      => $to,
				'lost'    => array(),
				'problem' => '',
			);

			$problem = $this->problem_with( $entry, $to );
			if ( is_wp_error( $problem ) ) {
				$row['problem'] = $problem->get_error_message();
			} else {
				$row['lost'] = $problem;
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Applies the decisions through the change log and closes the review.
	 *
	 * Every Reduce and Remove becomes one change in one batch, so Undo can put
	 * all of them back. One that cannot be applied (the person's role changed
	 * since, the last administrator, your own account) is recorded with the
	 * reason and does not stop the others.
	 *
	 * @param int $id Review ID.
	 * @return array|\WP_Error The finished review, or why it was refused.
	 */
	public function finish( int $id ) {
		$review = $this->get( $id );
		if ( ! $review ) {
			return new \WP_Error( 'role_right_sizer_review_not_found', __( 'That review does not exist.', 'role-right-sizer' ) );
		}
		if ( 'open' !== $review['status'] ) {
			return new \WP_Error( 'role_right_sizer_review_closed', __( 'That review is already finished.', 'role-right-sizer' ) );
		}

		$progress = self::progress( $review );
		if ( $progress['decided'] < $progress['total'] ) {
			return new \WP_Error( 'role_right_sizer_review_undecided', __( 'Some accounts have no decision yet. Choose Keep, Reduce or Remove for each one first.', 'role-right-sizer' ) );
		}

		$batch = 0;
		foreach ( $review['entries'] as $user_id => $entry ) {
			if ( 'keep' === $entry['decision'] ) {
				$entry['result'] = array( 'status' => 'kept' );
			} else {
				$entry['result'] = $this->apply_entry( $entry, $batch );
				if ( 'applied' === $entry['result']['status'] ) {
					$batch = $batch ? $batch : (int) ( $this->changes->get( (int) $entry['result']['change_id'] )['batch'] ?? 0 );
				}
			}
			$review['entries'][ $user_id ] = $entry;
		}

		$review['status']      = 'finished';
		$review['finished']    = time();
		$review['finished_by'] = get_current_user_id();
		$review['batch']       = $batch;
		$this->save_review( $review );

		if ( $batch ) {
			$this->report->rebuild();
		}
		$this->prune();

		/**
		 * Fires after an access review was finished and its decisions applied.
		 *
		 * @param array $review The review, with the result of each decision.
		 */
		do_action( 'role_right_sizer_review_finished', $review );

		return $review;
	}

	/**
	 * Throws an open review away. A finished review is evidence and stays.
	 *
	 * @param int $id Review ID.
	 * @return true|\WP_Error
	 */
	public function discard( int $id ) {
		$review = $this->get( $id );
		if ( ! $review ) {
			return new \WP_Error( 'role_right_sizer_review_not_found', __( 'That review does not exist.', 'role-right-sizer' ) );
		}
		if ( 'open' !== $review['status'] ) {
			return new \WP_Error( 'role_right_sizer_review_closed', __( 'A finished review is kept as evidence and cannot be discarded.', 'role-right-sizer' ) );
		}

		$log          = $this->load();
		$log['items'] = array_values(
			array_filter(
				$log['items'],
				static function ( array $item ) use ( $id ): bool {
					return (int) $item['id'] !== $id;
				}
			)
		);
		$this->save( $log );

		/**
		 * Fires after an open review was discarded.
		 *
		 * @param array $review The review that was discarded.
		 */
		do_action( 'role_right_sizer_review_discarded', $review );

		return true;
	}

	/**
	 * The daily job.
	 */
	public function run_check(): void {
		$this->check();
	}

	/**
	 * Sends the reminders that are due: an open review nearly or past its date,
	 * and a nudge to start the next review.
	 *
	 * @param int $now Unix time.
	 * @return array{due_soon: int, overdue: int, next: int} How many reminders went out of each kind.
	 */
	public function check( int $now = 0 ): array {
		$now    = $now ? $now : time();
		$result = array(
			'due_soon' => 0,
			'overdue'  => 0,
			'next'     => 0,
		);

		$review = $this->open();
		if ( $review && (int) $review['due'] > 0 ) {
			$stage = (int) $review['reminded'];
			if ( $stage < 2 && (int) $review['due'] <= $now ) {
				$review['reminded'] = 2;
				$this->save_review( $review );
				$this->notify_reminder( $review, 'overdue' );
				++$result['overdue'];
			} elseif ( $stage < 1 && (int) $review['due'] - $this->reminder_days() * DAY_IN_SECONDS <= $now ) {
				$review['reminded'] = 1;
				$this->save_review( $review );
				$this->notify_reminder( $review, 'due_soon' );
				++$result['due_soon'];
			}
		}

		$due = $this->next_due();
		if ( $due > 0 && $due <= $now && null === $review ) {
			$log = $this->load();
			if ( $log['nudged'] < $due ) {
				$log['nudged'] = $now;
				$this->save( $log );
				$this->notify_next();
				++$result['next'];
			}
		}

		return $result;
	}

	/**
	 * The people to email about a review: who started it and who was asked to work on it.
	 *
	 * @param array  $review The review, or an empty array for the next-review reminder.
	 * @param string $event  `started`, `due_soon`, `overdue` or `next`.
	 * @return string[] Email addresses.
	 */
	public function recipients( array $review, string $event ): array {
		$ids = $review ? array_merge( array( (int) $review['by'] ), array_map( 'intval', (array) $review['reviewers'] ) ) : array();
		$to  = array();
		foreach ( array_unique( $ids ) as $id ) {
			$user = $id > 0 ? get_userdata( $id ) : false;
			if ( $user instanceof \WP_User && '' !== $user->user_email ) {
				$to[] = $user->user_email;
			}
		}
		if ( ! $to ) {
			$to[] = (string) get_option( 'admin_email' );
		}

		/**
		 * Filters who is emailed about an access review.
		 *
		 * @param string[] $to     Default whoever started it and the reviewers, or the site's admin email address.
		 * @param array    $review The review, empty for the reminder to start the next one.
		 * @param string   $event  `started`, `due_soon`, `overdue` or `next`.
		 */
		return array_values( array_unique( array_filter( Settings::strings( apply_filters( 'role_right_sizer_review_recipients', $to, $review, $event ) ), 'is_email' ) ) );
	}

	/**
	 * Anonymises a person in every review: their own entry loses its details
	 * (the decision stays so the counts hold), and their name as a reviewer goes.
	 * For the personal data eraser and user deletion.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether anything changed.
	 */
	public function erase( int $user_id ): bool {
		$log     = $this->load();
		$changed = false;
		foreach ( $log['items'] as $index => $review ) {
			$item = $review;
			if ( isset( $item['entries'][ $user_id ] ) && empty( $item['entries'][ $user_id ]['erased'] ) ) {
				$item['entries'][ $user_id ] = array_merge(
					$item['entries'][ $user_id ],
					array(
						'login'       => '',
						'name'        => '',
						'roles'       => array(),
						'last_login'  => 0,
						'last_active' => 0,
						'used'        => array(),
						'note'        => '',
						'erased'      => true,
					)
				);
				$changed                     = true;
			}
			foreach ( $item['entries'] as $key => $entry ) {
				if ( (int) $entry['reviewer'] === $user_id ) {
					$item['entries'][ $key ]['reviewer'] = -1;
					$changed                             = true;
				}
			}
			foreach ( array( 'by', 'finished_by' ) as $field ) {
				if ( (int) $item[ $field ] === $user_id ) {
					$item[ $field ] = -1;
					$changed        = true;
				}
			}
			if ( in_array( $user_id, array_map( 'intval', (array) $item['reviewers'] ), true ) ) {
				$item['reviewers'] = array_values( array_diff( array_map( 'intval', (array) $item['reviewers'] ), array( $user_id ) ) );
				$changed           = true;
			}
			$log['items'][ $index ] = $item;
		}

		if ( $changed ) {
			$this->save( $log );
		}

		return $changed;
	}

	/**
	 * Anonymises a deleted user.
	 *
	 * @param mixed $user_id User ID.
	 */
	public function forget_user( $user_id ): void {
		$this->erase( (int) $user_id );
	}

	/**
	 * The CSV evidence for a review: one row per account the current user may see.
	 *
	 * @param array $review The review.
	 * @return string CSV, every cell quoted, formula-like values defused.
	 */
	public function csv( array $review ): string {
		$out = Screen::csv_line( self::csv_columns() );
		foreach ( self::visible_entries( $review ) as $entry ) {
			$out .= Screen::csv_line( $this->csv_row( $review, $entry ) );
		}

		return $out;
	}

	/**
	 * Column names of the evidence CSV.
	 *
	 * @return string[]
	 */
	public static function csv_columns(): array {
		return array( 'review_id', 'review_name', 'review_status', 'review_started', 'review_finished', 'user_id', 'user_login', 'display_name', 'roles_at_review', 'last_login', 'last_active', 'used_capabilities', 'recommendation', 'recommended_role', 'decision', 'role_chosen', 'note', 'decided_by', 'decided_at', 'outcome', 'outcome_detail', 'roles_after', 'capabilities_lost', 'change_id', 'change_undone' );
	}

	/**
	 * One evidence row.
	 *
	 * @param array $review The review.
	 * @param array $entry  The entry.
	 * @return array<string, string|int>
	 */
	public function csv_row( array $review, array $entry ): array {
		$used = array();
		foreach ( (array) $entry['used'] as $cap => $last ) {
			$used[] = $cap . ' (' . gmdate( 'Y-m-d', (int) $last ) . ')';
		}
		$result = (array) $entry['result'];
		$change = ! empty( $result['change_id'] ) ? $this->changes->get( (int) $result['change_id'] ) : null;

		return array(
			'review_id'         => (int) $review['id'],
			'review_name'       => (string) $review['name'],
			'review_status'     => (string) $review['status'],
			'review_started'    => self::utc( (int) $review['started'] ),
			'review_finished'   => self::utc( (int) $review['finished'] ),
			'user_id'           => (int) $entry['user_id'],
			'user_login'        => (string) $entry['login'],
			'display_name'      => (string) $entry['name'],
			'roles_at_review'   => implode( ' ', (array) $entry['roles'] ),
			'last_login'        => self::utc( (int) $entry['last_login'] ),
			'last_active'       => self::utc( (int) $entry['last_active'] ),
			'used_capabilities' => implode( '; ', $used ),
			'recommendation'    => (string) $entry['type'],
			'recommended_role'  => null === $entry['proposed'] ? '' : ( '' === $entry['proposed'] ? 'none' : (string) $entry['proposed'] ),
			'decision'          => (string) $entry['decision'],
			'role_chosen'       => 'reduce' === $entry['decision'] ? (string) $entry['role'] : ( 'remove' === $entry['decision'] ? 'none' : '' ),
			'note'              => (string) $entry['note'],
			'decided_by'        => $this->person( (int) $entry['reviewer'] ),
			'decided_at'        => self::utc( (int) $entry['decided'] ),
			'outcome'           => (string) ( $result['status'] ?? '' ),
			'outcome_detail'    => (string) ( $result['message'] ?? '' ),
			'roles_after'       => isset( $result['after'] ) ? ( $result['after'] ? implode( ' ', (array) $result['after'] ) : 'none' ) : '',
			'capabilities_lost' => isset( $result['lost'] ) ? implode( ' ', (array) $result['lost'] ) : '',
			'change_id'         => (int) ( $result['change_id'] ?? 0 ),
			'change_undone'     => $change ? self::utc( (int) $change['undone'] ) : '',
		);
	}

	/**
	 * The printable HTML evidence report: who reviewed, when, each decision and note, and what changed.
	 *
	 * @param array $review The review.
	 * @return string A whole HTML document.
	 */
	public function html( array $review ): string {
		$entries   = self::visible_entries( $review );
		$hidden    = count( (array) $review['entries'] ) - count( $entries );
		$progress  = self::progress( $review );
		$counts    = array(
			'applied'   => 0,
			'unchanged' => 0,
			'failed'    => 0,
		);
		$reviewers = array();
		foreach ( (array) $review['entries'] as $entry ) {
			if ( (int) $entry['reviewer'] > 0 || -1 === (int) $entry['reviewer'] ) {
				$reviewers[ (int) $entry['reviewer'] ] = $this->person( (int) $entry['reviewer'] );
			}
			$status = (string) ( $entry['result']['status'] ?? '' );
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}
		$invited = array();
		foreach ( (array) $review['reviewers'] as $id ) {
			$invited[] = $this->person( (int) $id );
		}

		$title = sprintf(
			/* translators: %s: name of the review. */
			__( 'Access review: %s', 'role-right-sizer' ),
			(string) $review['name']
		);

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', (string) get_locale() ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( $title ); ?></title>
<style>
	body { font: 14px/1.5 -apple-system, "Segoe UI", Roboto, sans-serif; color: #1d2327; background: #fff; margin: 0 auto; max-width: 70rem; padding: 1.5rem 1rem; }
	h1 { font-size: 1.6rem; margin: 0 0 .5rem; }
	h2 { font-size: 1.15rem; margin: 2rem 0 .5rem; }
	dl { display: grid; grid-template-columns: max-content 1fr; gap: .25rem 1rem; margin: 0; }
	dt { font-weight: 600; }
	dd { margin: 0; }
	table { border-collapse: collapse; width: 100%; }
	th, td { border: 1px solid #8c8f94; padding: .35rem .5rem; text-align: left; vertical-align: top; }
	th { background: #f0f0f1; }
	.note { color: #50575e; }
	.hint { background: #f0f6fc; border: 1px solid #72aee6; padding: .5rem .75rem; margin: 1rem 0; }
	@media print { .hint { display: none; } body { max-width: none; padding: 0; } tr { page-break-inside: avoid; } }
</style>
</head>
<body>
<main>
<h1><?php echo esc_html( $title ); ?></h1>
<p class="hint"><?php esc_html_e( 'To keep this report as a PDF, use your browser\'s Print command and choose Save as PDF.', 'role-right-sizer' ); ?></p>

<h2><?php esc_html_e( 'Summary', 'role-right-sizer' ); ?></h2>
<dl>
	<dt><?php esc_html_e( 'Site', 'role-right-sizer' ); ?></dt>
	<dd><?php echo esc_html( get_bloginfo( 'name' ) . ' (' . home_url() . ')' ); ?></dd>
	<dt><?php esc_html_e( 'Status', 'role-right-sizer' ); ?></dt>
	<dd><?php echo esc_html( 'finished' === $review['status'] ? __( 'Finished', 'role-right-sizer' ) : __( 'Open, decisions not applied yet', 'role-right-sizer' ) ); ?></dd>
	<dt><?php esc_html_e( 'Started', 'role-right-sizer' ); ?></dt>
	<dd><?php echo esc_html( self::utc( (int) $review['started'] ) . ' ' . __( 'by', 'role-right-sizer' ) . ' ' . $this->person( (int) $review['by'] ) ); ?></dd>
	<dt><?php esc_html_e( 'Due', 'role-right-sizer' ); ?></dt>
	<dd><?php echo esc_html( (int) $review['due'] ? self::utc( (int) $review['due'] ) : __( 'No due date', 'role-right-sizer' ) ); ?></dd>
	<dt><?php esc_html_e( 'Finished', 'role-right-sizer' ); ?></dt>
	<dd><?php echo esc_html( (int) $review['finished'] ? self::utc( (int) $review['finished'] ) . ' ' . __( 'by', 'role-right-sizer' ) . ' ' . $this->person( (int) $review['finished_by'] ) : __( 'Not finished', 'role-right-sizer' ) ); ?></dd>
	<dt><?php esc_html_e( 'Asked to review', 'role-right-sizer' ); ?></dt>
	<dd><?php echo esc_html( $invited ? implode( ', ', $invited ) : __( 'Nobody in particular', 'role-right-sizer' ) ); ?></dd>
	<dt><?php esc_html_e( 'Decisions made by', 'role-right-sizer' ); ?></dt>
	<dd><?php echo esc_html( $reviewers ? implode( ', ', array_values( $reviewers ) ) : __( 'Nobody yet', 'role-right-sizer' ) ); ?></dd>
	<dt><?php esc_html_e( 'Accounts reviewed', 'role-right-sizer' ); ?></dt>
	<dd>
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: total, 2: keep, 3: reduce, 4: remove. */
				__( '%1$s accounts: %2$s kept, %3$s reduced, %4$s with access removed', 'role-right-sizer' ),
				number_format_i18n( $progress['total'] ),
				number_format_i18n( $progress['keep'] ),
				number_format_i18n( $progress['reduce'] ),
				number_format_i18n( $progress['remove'] )
			)
		);
		?>
	</dd>
		<?php if ( 'finished' === $review['status'] ) : ?>
		<dt><?php esc_html_e( 'Changes made', 'role-right-sizer' ); ?></dt>
		<dd>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: applied, 2: unchanged, 3: failed. */
					__( '%1$s applied, %2$s already in place, %3$s could not be applied', 'role-right-sizer' ),
					number_format_i18n( $counts['applied'] ),
					number_format_i18n( $counts['unchanged'] ),
					number_format_i18n( $counts['failed'] )
				)
			);
			?>
		</dd>
	<?php endif; ?>
</dl>
		<?php if ( $hidden > 0 ) : ?>
<p class="note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of accounts. */
					_n( '%s account is left out of this report because the person who exported it may not edit it.', '%s accounts are left out of this report because the person who exported it may not edit them.', $hidden, 'role-right-sizer' ),
					number_format_i18n( $hidden )
				)
			);
			?>
</p>
		<?php endif; ?>

<h2><?php esc_html_e( 'Decisions', 'role-right-sizer' ); ?></h2>
<table>
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Person', 'role-right-sizer' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Role at review', 'role-right-sizer' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Last active', 'role-right-sizer' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Recommendation', 'role-right-sizer' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Decision', 'role-right-sizer' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Note', 'role-right-sizer' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Decided by', 'role-right-sizer' ); ?></th>
			<th scope="col"><?php esc_html_e( 'What changed', 'role-right-sizer' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $entries as $entry ) : ?>
			<?php $result = (array) $entry['result']; ?>
			<tr>
				<th scope="row"><?php echo esc_html( ! empty( $entry['erased'] ) ? __( '(removed on request)', 'role-right-sizer' ) : $entry['name'] . ' (' . $entry['login'] . ')' ); ?></th>
				<td><?php echo esc_html( self::roles_text( (array) $entry['roles'] ) ); ?></td>
				<td><?php echo esc_html( (int) $entry['last_active'] ? self::utc( (int) $entry['last_active'] ) : __( 'Never', 'role-right-sizer' ) ); ?></td>
				<td><?php echo esc_html( self::recommendation_text( $entry ) ); ?></td>
				<td><?php echo esc_html( $this->decision_text( $entry ) ); ?></td>
				<td><?php echo esc_html( (string) $entry['note'] ); ?></td>
				<td>
					<?php
					echo esc_html( '' !== $entry['decision'] ? $this->person( (int) $entry['reviewer'] ) : '' );
					echo ' ';
					echo esc_html( (int) $entry['decided'] ? self::utc( (int) $entry['decided'] ) : '' );
					?>
				</td>
				<td><?php echo esc_html( $this->outcome_text( $result ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<p class="note">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: date and time. */
				__( 'Report made %s UTC.', 'role-right-sizer' ),
				gmdate( 'Y-m-d H:i' )
			)
		);
		?>
</p>
</main>
</body>
</html>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * A decision in words.
	 *
	 * @param array $entry The entry.
	 * @return string
	 */
	public function decision_text( array $entry ): string {
		switch ( $entry['decision'] ) {
			case 'keep':
				return __( 'Keep', 'role-right-sizer' );
			case 'reduce':
				/* translators: %s: role. */
				return sprintf( __( 'Reduce to %s', 'role-right-sizer' ), Screen::role_label( (string) $entry['role'] ) );
			case 'remove':
				return __( 'Remove access', 'role-right-sizer' );
		}

		return __( 'Not decided', 'role-right-sizer' );
	}

	/**
	 * What happened when the decision was applied, in words.
	 *
	 * @param array $result The entry's result.
	 * @return string
	 */
	public function outcome_text( array $result ): string {
		switch ( $result['status'] ?? '' ) {
			case 'kept':
				return __( 'No change', 'role-right-sizer' );
			case 'applied':
				$change = ! empty( $result['change_id'] ) ? $this->changes->get( (int) $result['change_id'] ) : null;
				$text   = sprintf(
					/* translators: 1: roles before, 2: roles after, 3: change log number. */
					__( '%1$s to %2$s (change %3$s)', 'role-right-sizer' ),
					self::roles_text( (array) ( $result['before'] ?? array() ) ),
					self::roles_text( (array) ( $result['after'] ?? array() ) ),
					(string) (int) ( $result['change_id'] ?? 0 )
				);
				if ( $change && (int) $change['undone'] > 0 ) {
					/* translators: %s: date and time. */
					$text .= ' ' . sprintf( __( 'Undone %s UTC.', 'role-right-sizer' ), gmdate( 'Y-m-d H:i', (int) $change['undone'] ) );
				}

				return $text;
			case 'unchanged':
			case 'failed':
			case 'gone':
				return (string) ( $result['message'] ?? '' );
		}

		return '';
	}

	/**
	 * The recommendation at the time of the snapshot, in words.
	 *
	 * @param array $entry The entry.
	 * @return string
	 */
	public static function recommendation_text( array $entry ): string {
		if ( null === $entry['proposed'] ) {
			return __( 'None', 'role-right-sizer' );
		}

		return '' === $entry['proposed']
			? __( 'Remove access', 'role-right-sizer' )
			/* translators: %s: role. */
			: sprintf( __( 'Reduce to %s', 'role-right-sizer' ), Screen::role_label( (string) $entry['proposed'] ) );
	}

	/**
	 * Role names for the report.
	 *
	 * @param string[] $roles Role names.
	 * @return string
	 */
	public static function roles_text( array $roles ): string {
		return $roles ? Screen::roles_label( $roles ) : __( 'No role', 'role-right-sizer' );
	}

	/**
	 * A person's name for the report.
	 *
	 * @param int $user_id User ID, 0 for WP-CLI, -1 for a removed user.
	 * @return string
	 */
	public function person( int $user_id ): string {
		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );

			return $user instanceof \WP_User ? $user->display_name : __( '(removed)', 'role-right-sizer' );
		}
		if ( -1 === $user_id ) {
			return __( '(removed)', 'role-right-sizer' );
		}

		return __( 'WP-CLI', 'role-right-sizer' );
	}

	/**
	 * A time for the evidence files.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	public static function utc( int $time ): string {
		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : '';
	}

	/**
	 * Takes a snapshot of one account.
	 *
	 * @param \WP_User $user The account.
	 * @return array
	 */
	private function snapshot( \WP_User $user ): array {
		/**
		 * Filters how many used capabilities a review keeps for each account.
		 *
		 * @param int $limit Default 12, most recently used first.
		 */
		$limit = Settings::clamp( apply_filters( 'role_right_sizer_review_used_limit', 12 ), 12, 0, 200 );
		$usage = $this->store->usage( $user->ID );
		uasort(
			$usage,
			static function ( array $a, array $b ): int {
				return (int) $b[1] <=> (int) $a[1];
			}
		);
		$used = array();
		foreach ( array_slice( $usage, 0, $limit, true ) as $cap => $times ) {
			$used[ (string) $cap ] = (int) $times[1];
		}

		$recommendation = $this->report->for_user( $user->ID );

		return array(
			'user_id'     => $user->ID,
			'login'       => (string) $user->user_login,
			'name'        => (string) $user->display_name,
			'roles'       => array_values( array_map( 'strval', (array) $user->roles ) ),
			'last_login'  => $this->store->last_login( $user->ID ),
			'last_active' => $this->store->last_active( $user->ID ),
			'used'        => $used,
			'type'        => $recommendation ? (string) $recommendation['type'] : '',
			'proposed'    => $recommendation ? (string) $recommendation['proposed'] : null,
			'lost'        => $recommendation ? array_values( array_map( 'strval', (array) $recommendation['lost'] ) ) : array(),
			'decision'    => '',
			'role'        => '',
			'note'        => '',
			'reviewer'    => 0,
			'decided'     => 0,
			'result'      => array(),
			'erased'      => false,
		);
	}

	/**
	 * Sets one decision on a review in memory.
	 *
	 * @param array  $review   The review.
	 * @param int    $user_id  The account.
	 * @param string $decision `keep`, `reduce` or `remove`.
	 * @param string $role     Role for `reduce`.
	 * @param string $note     Note.
	 * @return array{review: array, entry: array}|\WP_Error
	 */
	private function set_decision( array $review, int $user_id, string $decision, string $role, string $note ) {
		if ( 'open' !== $review['status'] ) {
			return new \WP_Error( 'role_right_sizer_review_closed', __( 'That review is finished, so its decisions cannot change.', 'role-right-sizer' ) );
		}
		if ( ! isset( $review['entries'][ $user_id ] ) || ! Access::can_view_user( $user_id ) ) {
			return new \WP_Error( 'role_right_sizer_review_entry', __( 'That account is not in this review, or you may not review it.', 'role-right-sizer' ) );
		}
		if ( ! in_array( $decision, array( 'keep', 'reduce', 'remove' ), true ) ) {
			return new \WP_Error( 'role_right_sizer_review_decision', __( 'Choose Keep, Reduce or Remove access.', 'role-right-sizer' ) );
		}

		$entry = $review['entries'][ $user_id ];
		if ( 'keep' !== $decision ) {
			if ( ! Access::can_manage_user( $user_id ) ) {
				return new \WP_Error( 'role_right_sizer_forbidden', __( 'You are not allowed to change one of the selected accounts.', 'role-right-sizer' ) );
			}
			if ( get_current_user_id() === $user_id ) {
				return new \WP_Error( 'role_right_sizer_self', __( 'You cannot change your own role here.', 'role-right-sizer' ) );
			}
		}

		$role = sanitize_key( $role );
		if ( 'reduce' === $decision ) {
			if ( '' === $role && is_string( $entry['proposed'] ) && '' !== $entry['proposed'] ) {
				$role = $entry['proposed'];
			}
			if ( '' === $role || ! isset( Recommender::role_caps()[ $role ] ) ) {
				return new \WP_Error( 'role_right_sizer_review_role', __( 'Choose the role to reduce to.', 'role-right-sizer' ) );
			}
		} else {
			$role = '';
		}

		/**
		 * Filters the longest note a reviewer can leave on an account.
		 *
		 * @param int $length Default 500 characters.
		 */
		$length = Settings::clamp( apply_filters( 'role_right_sizer_review_note_length', 500 ), 500, 0, 5000 );

		$entry['decision'] = $decision;
		$entry['role']     = $role;
		$entry['note']     = mb_substr( sanitize_textarea_field( $note ), 0, $length );
		$entry['reviewer'] = get_current_user_id();
		$entry['decided']  = time();

		$review['entries'][ $user_id ] = $entry;

		/**
		 * Fires after a reviewer decided about an account.
		 *
		 * @param array $entry     The entry with the decision. Nothing has changed on the account yet.
		 * @param int   $review_id Review ID.
		 */
		do_action( 'role_right_sizer_review_decided', $entry, (int) $review['id'] );

		return array(
			'review' => $review,
			'entry'  => $entry,
		);
	}

	/**
	 * What a decision would take away, or why it cannot be applied.
	 *
	 * @param array  $entry The entry.
	 * @param string $to    Role to give, '' for none.
	 * @return string[]|\WP_Error Capabilities lost.
	 */
	private function problem_with( array $entry, string $to ) {
		$user_id = (int) $entry['user_id'];
		if ( ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'role_right_sizer_not_found', __( 'The account no longer exists.', 'role-right-sizer' ) );
		}
		if ( $this->trials->for_user( $user_id ) ) {
			return new \WP_Error( 'role_right_sizer_trial_exists', __( 'On a role trial, so the role is left alone. Decide on the trial first.', 'role-right-sizer' ) );
		}

		$plan = $this->applier->plan_role( $user_id, $to );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		return $plan['lost'];
	}

	/**
	 * Applies one decision and describes the result.
	 *
	 * @param array $entry The entry.
	 * @param int   $batch Batch to add the change to, 0 to start one.
	 * @return array The result for the entry.
	 */
	private function apply_entry( array $entry, int $batch ): array {
		$user_id = (int) $entry['user_id'];
		$to      = 'remove' === $entry['decision'] ? '' : (string) $entry['role'];

		$problem = $this->problem_with( $entry, $to );
		if ( is_wp_error( $problem ) ) {
			$code = $problem->get_error_code();

			return array(
				'status'  => 'role_right_sizer_not_found' === $code ? 'gone' : ( 'role_right_sizer_nothing' === $code ? 'unchanged' : 'failed' ),
				'message' => 'role_right_sizer_nothing' === $code ? __( 'Already at or below that role, nothing to change.', 'role-right-sizer' ) : $problem->get_error_message(),
			);
		}

		$change = $this->applier->apply_role( $user_id, $to, $batch );
		if ( is_wp_error( $change ) ) {
			return array(
				'status'  => 'failed',
				'message' => $change->get_error_message(),
			);
		}

		return array(
			'status'    => 'applied',
			'message'   => '',
			'change_id' => (int) $change['id'],
			'before'    => (array) $change['before'],
			'after'     => (array) $change['after'],
			'lost'      => (array) $change['lost'],
		);
	}

	/**
	 * Removes the oldest finished reviews beyond the number kept.
	 */
	private function prune(): void {
		/**
		 * Filters how many finished reviews are kept for the audit trail.
		 *
		 * @param int $keep Default 20, which is five years of quarterly reviews. The newest are kept.
		 */
		$keep = Settings::clamp( apply_filters( 'role_right_sizer_review_keep', 20 ), 20, 1, 500 );
		$log  = $this->load();
		$seen = 0;
		$kept = array();
		foreach ( $log['items'] as $review ) {
			if ( 'finished' === $review['status'] && ++$seen > $keep ) {
				continue;
			}
			$kept[] = $review;
		}

		if ( count( $kept ) !== count( $log['items'] ) ) {
			$log['items'] = $kept;
			$this->save( $log );
		}
	}

	/**
	 * Emails the people asked to work on a review.
	 *
	 * @param array $review The review.
	 */
	private function notify_started( array $review ): void {
		$progress = self::progress( $review );
		$lines    = array(
			sprintf(
				/* translators: 1: name of the review, 2: number of accounts. */
				__( 'An access review has started: %1$s. It has %2$s accounts to go through.', 'role-right-sizer' ),
				$review['name'],
				number_format_i18n( $progress['total'] )
			),
			'',
			(int) $review['due'] ? sprintf(
				/* translators: %s: date. */
				__( 'Please finish it by %s.', 'role-right-sizer' ),
				gmdate( 'Y-m-d', (int) $review['due'] )
			) : __( 'It has no due date.', 'role-right-sizer' ),
			__( 'For each account choose Keep, Reduce or Remove access. Nothing changes until the review is finished.', 'role-right-sizer' ),
			'',
			$this->review_url( $review ),
		);

		$this->send(
			$review,
			'started',
			/* translators: %s: name of the review. */
			sprintf( __( 'Access review started: %s', 'role-right-sizer' ), $review['name'] ),
			implode( "\n", $lines )
		);
	}

	/**
	 * Emails a reminder about an open review.
	 *
	 * @param array  $review The review.
	 * @param string $event  `due_soon` or `overdue`.
	 */
	private function notify_reminder( array $review, string $event ): void {
		$progress = self::progress( $review );
		$left     = $progress['total'] - $progress['decided'];
		$lines    = array(
			'overdue' === $event
				/* translators: %s: name of the review. */
				? sprintf( __( 'The access review "%s" is past its due date.', 'role-right-sizer' ), $review['name'] )
				/* translators: 1: name of the review, 2: due date. */
				: sprintf( __( 'The access review "%1$s" is due on %2$s.', 'role-right-sizer' ), $review['name'], gmdate( 'Y-m-d', (int) $review['due'] ) ),
			sprintf(
				/* translators: %s: number of accounts. */
				_n( '%s account still needs a decision.', '%s accounts still need a decision.', $left, 'role-right-sizer' ),
				number_format_i18n( $left )
			),
			'',
			$this->review_url( $review ),
		);

		$this->send(
			$review,
			$event,
			'overdue' === $event
				/* translators: %s: name of the review. */
				? sprintf( __( 'Access review overdue: %s', 'role-right-sizer' ), $review['name'] )
				/* translators: %s: name of the review. */
				: sprintf( __( 'Access review due soon: %s', 'role-right-sizer' ), $review['name'] ),
			implode( "\n", $lines )
		);

		/**
		 * Fires after a reminder about an open review went out.
		 *
		 * @param array  $review The review.
		 * @param string $event  `due_soon` or `overdue`.
		 */
		do_action( 'role_right_sizer_review_reminder_sent', $review, $event );
	}

	/**
	 * Emails a reminder to start the next review.
	 */
	private function notify_next(): void {
		$last  = $this->last_finished();
		$lines = array(
			$last
				/* translators: %s: date. */
				? sprintf( __( 'The last access review on this site was finished on %s.', 'role-right-sizer' ), gmdate( 'Y-m-d', $last ) )
				: __( 'This site has never had an access review.', 'role-right-sizer' ),
			__( 'It is time to go through every account and decide who keeps their access.', 'role-right-sizer' ),
			'',
			$this->reviews_url(),
		);

		$this->send( array(), 'next', __( 'Time for the next access review', 'role-right-sizer' ), implode( "\n", $lines ) );

		/** This action is documented in src/Reviews.php */
		do_action( 'role_right_sizer_review_reminder_sent', array(), 'next' );
	}

	/**
	 * Sends one email.
	 *
	 * @param array  $review  The review, empty for the next-review reminder.
	 * @param string $event   `started`, `due_soon`, `overdue` or `next`.
	 * @param string $subject Subject.
	 * @param string $message Plain text body.
	 */
	private function send( array $review, string $event, string $subject, string $message ): void {
		$email = array(
			'to'      => $this->recipients( $review, $event ),
			'subject' => $subject,
			'message' => $message,
		);

		/**
		 * Filters an email about an access review. Return false to send nothing.
		 *
		 * @param array|false $email  `to` (list), `subject` and `message` (plain text).
		 * @param string      $event  `started`, `due_soon`, `overdue` or `next`.
		 * @param array       $review The review, empty for the reminder to start the next one.
		 */
		$email = apply_filters( 'role_right_sizer_review_email', $email, $event, $review );
		if ( ! is_array( $email ) || empty( $email['to'] ) || ! is_string( $email['subject'] ?? null ) || ! is_string( $email['message'] ?? null ) ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One email per review event, to whoever runs the review.
		wp_mail( $email['to'], $email['subject'], $email['message'] );
	}

	/**
	 * URL of a review.
	 *
	 * @param array $review The review.
	 * @return string
	 */
	private function review_url( array $review ): string {
		return add_query_arg(
			array(
				'page'   => 'role-right-sizer',
				'tab'    => 'reviews',
				'review' => (int) $review['id'],
			),
			admin_url( 'users.php' )
		);
	}

	/**
	 * URL of the Reviews tab.
	 *
	 * @return string
	 */
	private function reviews_url(): string {
		return add_query_arg(
			array(
				'page' => 'role-right-sizer',
				'tab'  => 'reviews',
			),
			admin_url( 'users.php' )
		);
	}

	/**
	 * Adds a review to the log.
	 *
	 * @param array $review The review, without `id`.
	 * @return array The review as saved.
	 */
	private function add( array $review ): array {
		$log          = $this->load();
		$review['id'] = $log['next'];
		$log['next']  = $review['id'] + 1;
		$log['items'] = array_merge( array( $review ), $log['items'] );
		$this->save( $log );

		return $review;
	}

	/**
	 * Saves a changed review.
	 *
	 * @param array $review The review.
	 */
	private function save_review( array $review ): void {
		$log = $this->load();
		foreach ( $log['items'] as $index => $item ) {
			if ( (int) $item['id'] === (int) $review['id'] ) {
				$log['items'][ $index ] = $review;
			}
		}
		$this->save( $log );
	}

	/**
	 * The stored reviews, each with every key present.
	 *
	 * @return array{next: int, nudged: int, items: array[]}
	 */
	private function load(): array {
		$log = get_option( self::OPTION, array() );
		$log = is_array( $log ) ? $log : array();

		$items = array();
		foreach ( (array) ( $log['items'] ?? array() ) as $review ) {
			if ( ! is_array( $review ) || ! isset( $review['id'] ) ) {
				continue;
			}
			$review  = array_merge(
				array(
					'name'        => '',
					'status'      => 'open',
					'started'     => 0,
					'by'          => 0,
					'due'         => 0,
					'reviewers'   => array(),
					'reminded'    => 0,
					'entries'     => array(),
					'finished'    => 0,
					'finished_by' => 0,
					'batch'       => 0,
				),
				$review
			);
			$entries = array();
			foreach ( (array) $review['entries'] as $key => $entry ) {
				$entries[ (int) $key ] = array_merge(
					array(
						'user_id'     => (int) $key,
						'login'       => '',
						'name'        => '',
						'roles'       => array(),
						'last_login'  => 0,
						'last_active' => 0,
						'used'        => array(),
						'type'        => '',
						'proposed'    => null,
						'lost'        => array(),
						'decision'    => '',
						'role'        => '',
						'note'        => '',
						'reviewer'    => 0,
						'decided'     => 0,
						'result'      => array(),
						'erased'      => false,
					),
					(array) $entry
				);
			}
			$review['entries'] = $entries;
			$items[]           = $review;
		}

		return array(
			'next'   => max( 1, (int) ( $log['next'] ?? 1 ) ),
			'nudged' => (int) ( $log['nudged'] ?? 0 ),
			'items'  => $items,
		);
	}

	/**
	 * Saves the log.
	 *
	 * @param array $log Log.
	 */
	private function save( array $log ): void {
		update_option( self::OPTION, $log, false );
	}
}
