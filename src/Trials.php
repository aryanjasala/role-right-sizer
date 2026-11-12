<?php
/**
 * Trials: a smaller role for a while, with a record of what the person could not do.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer;

defined( 'ABSPATH' ) || exit;

/**
 * Starts, restores, extends and closes trials, and keeps their denials.
 *
 * A trial is an array:
 *
 * - `id`, `user_id`, `login`, `by` (who started it, 0 for WP-CLI), `started`, `ends`
 * - `change_id`, `batch`: the change in the change log that gave the smaller role, so Undo works
 * - `before`, `after`: roles before and during the trial, `lost`: capabilities taken away
 * - `status`: `active`, `ended` (time is up, waiting for a decision), `kept` or `restored`
 * - `closed`: when it was kept or restored, 0 if not, and `auto` whether Right-Sizer kept it
 * - `requested`: when the person last asked for their access back
 * - `denials`: `key => [ kind, label, cap, count, first, last ]`
 */
final class Trials implements Service {

	public const OPTION = 'role_right_sizer_trials';
	public const FLAG   = 'role_right_sizer_trial';
	public const CRON   = 'role_right_sizer_trial_check';

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
	 * Sets up the trials.
	 *
	 * @param Settings    $settings    Settings and limits.
	 * @param Applier     $applier     Applies and undoes changes.
	 * @param Recommender $recommender Works out recommendations.
	 * @param Report      $report      Saved recommendations.
	 */
	public function __construct( Settings $settings, Applier $applier, Recommender $recommender, Report $report ) {
		$this->settings    = $settings;
		$this->applier     = $applier;
		$this->recommender = $recommender;
		$this->report      = $report;
	}

	/**
	 * Hooks the hourly check, the log's Undo and user deletion.
	 */
	public function register(): void {
		add_action( self::CRON, array( $this, 'run_check' ) );
		add_action( 'admin_init', array( $this, 'schedule' ) );
		add_action( 'role_right_sizer_undone', array( $this, 'undone' ) );
		add_action( 'delete_user', array( $this, 'forget_user' ) );
	}

	/**
	 * Schedules the hourly check while a trial is open.
	 */
	public function schedule(): void {
		if ( $this->open() && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	/**
	 * Days a trial lasts.
	 *
	 * @return int
	 */
	public function days(): int {
		/**
		 * Filters how many days a trial lasts, and how many an extension adds.
		 *
		 * @param int $days Default 14.
		 */
		return Settings::clamp( apply_filters( 'role_right_sizer_trial_days', 14 ), 14, 1, 365 );
	}

	/**
	 * Recommendation types a trial may start from.
	 *
	 * @return string[]
	 */
	public function types(): array {
		/**
		 * Filters which recommendation types can be tried first.
		 *
		 * A trial only means something for a person who is active, so the default is the smaller role.
		 *
		 * @param string[] $types Default `downgrade`. Others: `dormant`, `never`.
		 */
		$types = Settings::strings( apply_filters( 'role_right_sizer_trial_types', array( 'downgrade' ) ) );

		return array_values( array_intersect( $types, array( 'downgrade', 'dormant', 'never' ) ) );
	}

	/**
	 * Every trial, newest first.
	 *
	 * @return array[]
	 */
	public function all(): array {
		return $this->load()['items'];
	}

	/**
	 * Trials that are not closed: active, or waiting for a decision.
	 *
	 * @return array[]
	 */
	public function open(): array {
		return array_values(
			array_filter(
				$this->all(),
				static function ( array $trial ): bool {
					return in_array( $trial['status'], array( 'active', 'ended' ), true );
				}
			)
		);
	}

	/**
	 * One trial.
	 *
	 * @param int $id Trial ID.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		foreach ( $this->all() as $trial ) {
			if ( (int) $trial['id'] === $id ) {
				return $trial;
			}
		}

		return null;
	}

	/**
	 * The open trial of a user, if any.
	 *
	 * @param int $user_id User ID.
	 * @return array|null
	 */
	public function for_user( int $user_id ): ?array {
		foreach ( $this->open() as $trial ) {
			if ( (int) $trial['user_id'] === $user_id ) {
				return $trial;
			}
		}

		return null;
	}

	/**
	 * How many denials a trial has recorded, counting repeats.
	 *
	 * @param array $trial The trial.
	 * @return int
	 */
	public static function denial_total( array $trial ): int {
		$total = 0;
		foreach ( (array) ( $trial['denials'] ?? array() ) as $denial ) {
			$total += (int) ( $denial['count'] ?? 0 );
		}

		return $total;
	}

	/**
	 * Whole days left, 0 once the time is up.
	 *
	 * @param array $trial The trial.
	 * @param int   $now   Unix time.
	 * @return int
	 */
	public static function days_left( array $trial, int $now = 0 ): int {
		$now = $now ? $now : time();

		return max( 0, (int) ceil( ( (int) $trial['ends'] - $now ) / DAY_IN_SECONDS ) );
	}

	/**
	 * Starts a trial for one user: gives them the recommended role and logs it.
	 *
	 * @param int $user_id User ID.
	 * @param int $days    Length in days, 0 for the default.
	 * @param int $batch   Batch to add the change to, 0 to start one.
	 * @return array|\WP_Error The trial, or why it was refused.
	 */
	public function start( int $user_id, int $days = 0, int $batch = 0 ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'role_right_sizer_not_found', __( 'That user does not exist.', 'role-right-sizer' ) );
		}
		if ( $this->for_user( $user_id ) ) {
			return new \WP_Error( 'role_right_sizer_trial_exists', __( 'This person is already on a trial.', 'role-right-sizer' ) );
		}

		$recommendation = $this->recommender->for_user( $user, time(), 2 );
		if ( ! $recommendation ) {
			return new \WP_Error( 'role_right_sizer_nothing', __( 'There is nothing to change for this user.', 'role-right-sizer' ) );
		}
		if ( ! in_array( $recommendation['type'], $this->types(), true ) ) {
			return new \WP_Error( 'role_right_sizer_trial_type', __( 'Only accounts that are still in use can be tried with a smaller role.', 'role-right-sizer' ) );
		}

		$change = $this->applier->apply_user( $user_id, $batch );
		if ( is_wp_error( $change ) ) {
			return $change;
		}

		$days  = $days > 0 ? Settings::clamp( $days, $this->days(), 1, 365 ) : $this->days();
		$now   = time();
		$trial = $this->add(
			array(
				'user_id'   => $user_id,
				'login'     => $user->user_login,
				'by'        => get_current_user_id(),
				'started'   => $now,
				'ends'      => $now + $days * DAY_IN_SECONDS,
				'change_id' => (int) $change['id'],
				'batch'     => (int) $change['batch'],
				'before'    => (array) $change['before'],
				'after'     => (array) $change['after'],
				'lost'      => (array) $change['lost'],
				'status'    => 'active',
				'closed'    => 0,
				'auto'      => false,
				'requested' => 0,
				'denials'   => array(),
			)
		);

		update_user_option( $user_id, self::FLAG, $trial['id'] );
		$this->schedule();

		/**
		 * Fires after a trial started. The person already has the smaller role.
		 *
		 * @param array $trial The trial, see Trials.
		 */
		do_action( 'role_right_sizer_trial_started', $trial );

		return $trial;
	}

	/**
	 * Starts trials for several users as one batch.
	 *
	 * @param int[] $user_ids Users.
	 * @param int   $days     Length in days, 0 for the default.
	 * @return array{batch: int, trials: array[], errors: array<string, \WP_Error>}
	 */
	public function start_many( array $user_ids, int $days = 0 ): array {
		$batch  = 0;
		$trials = array();
		$errors = array();

		foreach ( array_unique( array_map( 'intval', $user_ids ) ) as $user_id ) {
			$result = $this->start( $user_id, $days, $batch );
			if ( is_wp_error( $result ) ) {
				$errors[ 'user:' . $user_id ] = $result;
				continue;
			}
			$batch    = $batch ? $batch : (int) $result['batch'];
			$trials[] = $result;
		}

		if ( $trials ) {
			$this->report->rebuild();
		}

		return array(
			'batch'  => $batch,
			'trials' => $trials,
			'errors' => $errors,
		);
	}

	/**
	 * Gives the person their old role back and closes the trial.
	 *
	 * @param int $id Trial ID.
	 * @return array|\WP_Error The closed trial, or why it could not be restored.
	 */
	public function restore( int $id ) {
		$trial = $this->open_trial( $id );
		if ( is_wp_error( $trial ) ) {
			return $trial;
		}

		$result = $this->applier->undo( (int) $trial['change_id'] );
		if ( is_wp_error( $result ) && 'role_right_sizer_undone' !== $result->get_error_code() ) {
			return $result;
		}

		// Undoing the change closes the trial through undone(). When it was undone by hand before, close it here.
		$current = $this->get( $id );
		if ( $current && in_array( $current['status'], array( 'active', 'ended' ), true ) ) {
			$this->close( $current, 'restored' );
		}

		return (array) $this->get( $id );
	}

	/**
	 * Makes the smaller role permanent. The change stays in the log, with Undo.
	 *
	 * @param int  $id   Trial ID.
	 * @param bool $auto Whether Right-Sizer did it, because the trial ended with no denials.
	 * @return array|\WP_Error The closed trial, or why it could not be closed.
	 */
	public function keep( int $id, bool $auto = false ) {
		$trial = $this->open_trial( $id );
		if ( is_wp_error( $trial ) ) {
			return $trial;
		}

		if ( $auto && self::denial_total( $trial ) > 0 ) {
			// Never automatic once the person hit a wall.
			return new \WP_Error( 'role_right_sizer_trial_denials', __( 'This trial has denials, so it needs a decision from a person.', 'role-right-sizer' ) );
		}

		$trial['auto'] = $auto;
		$this->close( $trial, 'kept' );

		return (array) $this->get( $id );
	}

	/**
	 * Gives a trial more days, starting from now when its time is up.
	 *
	 * @param int $id   Trial ID.
	 * @param int $days Days to add, 0 for the default.
	 * @return array|\WP_Error The trial, or why it could not be extended.
	 */
	public function extend( int $id, int $days = 0 ) {
		$trial = $this->open_trial( $id );
		if ( is_wp_error( $trial ) ) {
			return $trial;
		}

		$days            = $days > 0 ? Settings::clamp( $days, $this->days(), 1, 365 ) : $this->days();
		$trial['ends']   = max( (int) $trial['ends'], time() ) + $days * DAY_IN_SECONDS;
		$trial['status'] = 'active';
		$this->save_trial( $trial );

		/**
		 * Fires after a trial was extended.
		 *
		 * @param array $trial The trial, with its new `ends`.
		 * @param int   $days  Days added.
		 */
		do_action( 'role_right_sizer_trial_extended', $trial, $days );

		return $trial;
	}

	/**
	 * The hourly job.
	 */
	public function run_check(): void {
		$this->check();
	}

	/**
	 * Ends trials whose time is up, keeps the ones that may be kept on their own,
	 * and removes old closed trials. Runs hourly and when the Trials tab opens.
	 *
	 * @param int $now Unix time.
	 * @return array{ended: int, kept: int, removed: int}
	 */
	public function check( int $now = 0 ): array {
		$now    = $now ? $now : time();
		$result = array(
			'ended'   => 0,
			'kept'    => 0,
			'removed' => 0,
		);

		foreach ( $this->all() as $trial ) {
			if ( 'active' !== $trial['status'] || (int) $trial['ends'] > $now ) {
				continue;
			}

			if ( 0 === self::denial_total( $trial ) && 'keep' === $this->settings->trial_end_action() ) {
				$kept = $this->keep( (int) $trial['id'], true );
				if ( ! is_wp_error( $kept ) ) {
					++$result['kept'];
				}
				continue;
			}

			$trial['status'] = 'ended';
			$this->save_trial( $trial );
			++$result['ended'];

			/**
			 * Fires when a trial's time is up and it waits for a decision.
			 *
			 * @param array $trial The trial. Check the denials to see whether the person hit a wall.
			 */
			do_action( 'role_right_sizer_trial_ended', $trial );
			$this->notify_ended( $trial );
		}

		$result['removed'] = $this->purge( $now );

		if ( ! $this->open() ) {
			wp_clear_scheduled_hook( self::CRON );
		}

		return $result;
	}

	/**
	 * Removes closed trials older than the retention period.
	 *
	 * @param int $now Unix time.
	 * @return int How many were removed.
	 */
	public function purge( int $now = 0 ): int {
		$now = $now ? $now : time();

		/**
		 * Filters how many days a closed trial and its denials are kept.
		 *
		 * @param int $days Default 365.
		 */
		$days = Settings::clamp( apply_filters( 'role_right_sizer_trial_retention_days', 365 ), 365, 1, 3650 );
		$log  = $this->load();
		$keep = array();
		foreach ( $log['items'] as $trial ) {
			if ( ! in_array( $trial['status'], array( 'active', 'ended' ), true ) && (int) $trial['closed'] > 0 && (int) $trial['closed'] < $now - $days * DAY_IN_SECONDS ) {
				continue;
			}
			$keep[] = $trial;
		}

		$removed = count( $log['items'] ) - count( $keep );
		if ( $removed ) {
			$log['items'] = $keep;
			$this->save( $log );
		}

		return $removed;
	}

	/**
	 * Adds denials the watcher saw in one request.
	 *
	 * @param int     $id      Trial ID.
	 * @param array[] $denials Each with `key`, `kind`, `label`, `cap`.
	 */
	public function add_denials( int $id, array $denials ): void {
		$trial = $this->get( $id );
		if ( ! $trial || 'active' !== $trial['status'] || ! $denials ) {
			return;
		}

		$now = time();
		foreach ( $denials as $denial ) {
			$key = (string) $denial['key'];
			if ( isset( $trial['denials'][ $key ] ) ) {
				++$trial['denials'][ $key ]['count'];
				$trial['denials'][ $key ]['last'] = $now;
			} else {
				$trial['denials'][ $key ] = array(
					'kind'  => (string) $denial['kind'],
					'label' => (string) $denial['label'],
					'cap'   => (string) $denial['cap'],
					'count' => 1,
					'first' => $now,
					'last'  => $now,
				);
			}

			/**
			 * Fires each time a person on trial hits a wall.
			 *
			 * @param array $denial The denial: `key`, `kind` (`screen`, `rest` or `action`), `label`, `cap`.
			 * @param array $trial  The trial.
			 */
			do_action( 'role_right_sizer_trial_denied', $denial, $trial );
		}

		/**
		 * Filters how many different denials a trial keeps. The oldest go first.
		 *
		 * @param int $limit Default 50.
		 */
		$limit = Settings::clamp( apply_filters( 'role_right_sizer_trial_denial_limit', 50 ), 50, 5, 500 );
		if ( count( $trial['denials'] ) > $limit ) {
			uasort(
				$trial['denials'],
				static function ( array $a, array $b ): int {
					return $b['last'] <=> $a['last'];
				}
			);
			$trial['denials'] = array_slice( $trial['denials'], 0, $limit, true );
		}

		$this->save_trial( $trial );
	}

	/**
	 * Notes that the person asked for their access back, and tells whoever started the trial.
	 *
	 * @param int  $id        Trial ID.
	 * @param bool $by_person Whether the person on trial asks. They cannot manage their own role, so the manager check is skipped.
	 * @return true|\WP_Error
	 */
	public function request_access( int $id, bool $by_person = false ) {
		$trial = $this->open_trial( $id, ! $by_person );
		if ( is_wp_error( $trial ) ) {
			return $trial;
		}

		/**
		 * Filters how many seconds the person must wait before asking again.
		 *
		 * @param int $seconds Default one day.
		 */
		$wait = Settings::clamp( apply_filters( 'role_right_sizer_trial_request_interval', DAY_IN_SECONDS ), DAY_IN_SECONDS, 0, 30 * DAY_IN_SECONDS );
		if ( $wait > 0 && (int) $trial['requested'] > time() - $wait ) {
			return new \WP_Error( 'role_right_sizer_trial_asked', __( 'You already asked. Whoever started the trial has been told.', 'role-right-sizer' ) );
		}

		$trial['requested'] = time();
		$this->save_trial( $trial );

		/**
		 * Fires when a person on trial asks for their access back.
		 *
		 * @param array $trial The trial.
		 */
		do_action( 'role_right_sizer_trial_access_requested', $trial );

		$this->notify_request( $trial );

		return true;
	}

	/**
	 * Closes a trial when its change is undone from the change log.
	 *
	 * @param mixed $change The change that was undone.
	 */
	public function undone( $change ): void {
		if ( ! is_array( $change ) ) {
			return;
		}

		$change_id = (int) ( $change['id'] ?? 0 );
		foreach ( $this->open() as $trial ) {
			if ( $change_id === (int) $trial['change_id'] ) {
				$this->close( $trial, 'restored' );
			}
		}
	}

	/**
	 * Drops a deleted user's trials.
	 *
	 * @param mixed $user_id User ID.
	 */
	public function forget_user( $user_id ): void {
		$this->erase( (int) $user_id );
	}

	/**
	 * Removes a user from the trials: their own go, and ones they started stay without their name.
	 * For the personal data eraser and user deletion.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether anything changed.
	 */
	public function erase( int $user_id ): bool {
		$log     = $this->load();
		$changed = false;
		$items   = array();
		foreach ( $log['items'] as $trial ) {
			if ( (int) $trial['user_id'] === $user_id ) {
				$changed = true;
				continue;
			}
			if ( (int) $trial['by'] === $user_id ) {
				$trial['by'] = -1;
				$changed     = true;
			}
			$items[] = $trial;
		}

		if ( $changed ) {
			$log['items'] = $items;
			$this->save( $log );
		}
		delete_user_option( $user_id, self::FLAG );

		return $changed;
	}

	/**
	 * The people to tell about a trial: whoever started it, or the site's admin address.
	 *
	 * @param array $trial The trial.
	 * @return string[] Email addresses.
	 */
	public function recipients( array $trial ): array {
		$to   = array();
		$user = (int) $trial['by'] > 0 ? get_userdata( (int) $trial['by'] ) : false;
		if ( $user instanceof \WP_User && '' !== $user->user_email ) {
			$to[] = $user->user_email;
		} else {
			$to[] = (string) get_option( 'admin_email' );
		}

		/**
		 * Filters who is emailed about a trial.
		 *
		 * @param string[] $to    Default whoever started the trial, or the site's admin email address.
		 * @param array    $trial The trial.
		 */
		return array_values( array_filter( Settings::strings( apply_filters( 'role_right_sizer_trial_recipients', $to, $trial ) ), 'is_email' ) );
	}

	/**
	 * Closes a trial as kept or restored.
	 *
	 * @param array  $trial  The trial.
	 * @param string $status `kept` or `restored`.
	 */
	private function close( array $trial, string $status ): void {
		$trial['status'] = $status;
		$trial['closed'] = time();
		$this->save_trial( $trial );
		delete_user_option( (int) $trial['user_id'], self::FLAG );

		if ( 'kept' === $status ) {
			/**
			 * Fires after a trial was made permanent. The change stays in the log with Undo.
			 *
			 * @param array $trial The trial.
			 * @param bool  $auto  Whether Right-Sizer did it because there were no denials.
			 */
			do_action( 'role_right_sizer_trial_kept', $trial, (bool) $trial['auto'] );
			return;
		}

		/**
		 * Fires after a trial was restored: the person has their old role back.
		 *
		 * @param array $trial The trial.
		 */
		do_action( 'role_right_sizer_trial_restored', $trial );
	}

	/**
	 * An open trial, or why there is none.
	 *
	 * @param int  $id           Trial ID.
	 * @param bool $check_access Whether the current user must be allowed to manage the person's role.
	 * @return array|\WP_Error
	 */
	private function open_trial( int $id, bool $check_access = true ) {
		$trial = $this->get( $id );
		if ( ! $trial ) {
			return new \WP_Error( 'role_right_sizer_trial_not_found', __( 'That trial does not exist.', 'role-right-sizer' ) );
		}
		if ( ! in_array( $trial['status'], array( 'active', 'ended' ), true ) ) {
			return new \WP_Error( 'role_right_sizer_trial_closed', __( 'That trial is already over.', 'role-right-sizer' ) );
		}
		if ( $check_access && ! Access::can_manage_user( (int) $trial['user_id'] ) ) {
			return new \WP_Error( 'role_right_sizer_forbidden', __( 'You are not allowed to change this user\'s role.', 'role-right-sizer' ) );
		}

		return $trial;
	}

	/**
	 * Tells whoever started the trial that the person asked for their access back.
	 *
	 * @param array $trial The trial.
	 */
	private function notify_request( array $trial ): void {
		$name  = $this->display_name( $trial );
		$lines = array(
			/* translators: %s: person's name. */
			sprintf( __( '%s asked for their access back.', 'role-right-sizer' ), $name ),
			'',
			/* translators: %s: roles. */
			sprintf( __( 'Their role was changed to %s as a trial.', 'role-right-sizer' ), $this->roles_text( (array) $trial['after'] ) ),
		);
		$lines = array_merge( $lines, $this->denial_lines( $trial ) );
		$lines = array_merge(
			$lines,
			array(
				'',
				/* translators: %s: URL. */
				sprintf( __( 'Give it back, extend the trial or make it permanent: %s', 'role-right-sizer' ), $this->trials_url() ),
			)
		);

		$this->send(
			$trial,
			'access_requested',
			/* translators: 1: site name, 2: person's name. */
			sprintf( __( '[%1$s] %2$s asks for their access back', 'role-right-sizer' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ), $name ),
			implode( "\n", $lines )
		);
	}

	/**
	 * Tells whoever started the trial that its time is up.
	 *
	 * @param array $trial The trial.
	 */
	private function notify_ended( array $trial ): void {
		$name   = $this->display_name( $trial );
		$denied = self::denial_total( $trial ) > 0;
		$lines  = array(
			$denied
				/* translators: %s: person's name. */
				? sprintf( __( 'The trial for %s is over, and they hit walls while they had the smaller role.', 'role-right-sizer' ), $name )
				/* translators: %s: person's name. */
				: sprintf( __( 'The trial for %s is over, and nothing stopped them.', 'role-right-sizer' ), $name ),
		);
		$lines  = array_merge( $lines, $this->denial_lines( $trial ) );
		$lines  = array_merge(
			$lines,
			array(
				'',
				/* translators: %s: URL. */
				sprintf( __( 'Make it permanent, extend it or give the old role back: %s', 'role-right-sizer' ), $this->trials_url() ),
			)
		);

		$this->send(
			$trial,
			'ended',
			/* translators: 1: site name, 2: person's name. */
			sprintf( __( '[%1$s] Trial for %2$s is over', 'role-right-sizer' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ), $name ),
			implode( "\n", $lines )
		);
	}

	/**
	 * Sends an email through the filter.
	 *
	 * @param array  $trial   The trial.
	 * @param string $event   `access_requested` or `ended`.
	 * @param string $subject Subject.
	 * @param string $message Plain text body.
	 */
	private function send( array $trial, string $event, string $subject, string $message ): void {
		$email = array(
			'to'      => $this->recipients( $trial ),
			'subject' => $subject,
			'message' => $message,
		);

		/**
		 * Filters an email about a trial. Return false to send nothing.
		 *
		 * @param array|false $email   `to` (list), `subject` and `message` (plain text).
		 * @param string      $event   `access_requested` or `ended`.
		 * @param array       $trial   The trial.
		 */
		$email = apply_filters( 'role_right_sizer_trial_email', $email, $event, $trial );
		if ( ! is_array( $email ) || empty( $email['to'] ) || ! is_string( $email['subject'] ?? null ) || ! is_string( $email['message'] ?? null ) ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One email per trial event, to whoever started the trial.
		wp_mail( $email['to'], $email['subject'], $email['message'] );
	}

	/**
	 * One line per denial, for emails.
	 *
	 * @param array $trial The trial.
	 * @return string[]
	 */
	private function denial_lines( array $trial ): array {
		$denials = (array) $trial['denials'];
		if ( ! $denials ) {
			return array();
		}

		uasort(
			$denials,
			static function ( array $a, array $b ): int {
				return $b['count'] <=> $a['count'];
			}
		);

		$lines = array( '', __( 'What they could not do:', 'role-right-sizer' ) );
		foreach ( $denials as $denial ) {
			/* translators: 1: what they tried, 2: how many times. */
			$lines[] = sprintf( _n( '- %1$s (%2$s time)', '- %1$s (%2$s times)', (int) $denial['count'], 'role-right-sizer' ), $denial['label'], number_format_i18n( (int) $denial['count'] ) );
		}

		return $lines;
	}

	/**
	 * URL of the Trials tab.
	 *
	 * @return string
	 */
	private function trials_url(): string {
		return add_query_arg(
			array(
				'page' => 'role-right-sizer',
				'tab'  => 'trials',
			),
			admin_url( 'users.php' )
		);
	}

	/**
	 * A trial's person, by name.
	 *
	 * @param array $trial The trial.
	 * @return string
	 */
	private function display_name( array $trial ): string {
		$user = get_userdata( (int) $trial['user_id'] );

		return $user instanceof \WP_User ? $user->display_name : (string) $trial['login'];
	}

	/**
	 * Role names for an email.
	 *
	 * @param string[] $roles Role names.
	 * @return string
	 */
	private function roles_text( array $roles ): string {
		$names = wp_roles()->roles;
		$out   = array();
		foreach ( $roles as $role ) {
			$out[] = isset( $names[ $role ]['name'] ) ? translate_user_role( (string) $names[ $role ]['name'] ) : (string) $role;
		}

		return $out ? implode( ', ', $out ) : __( 'no role', 'role-right-sizer' );
	}

	/**
	 * Adds a trial to the log.
	 *
	 * @param array $trial The trial, without `id`.
	 * @return array The trial as saved.
	 */
	private function add( array $trial ): array {
		$log          = $this->load();
		$trial['id']  = $log['next'];
		$log['next']  = $trial['id'] + 1;
		$log['items'] = array_merge( array( $trial ), $log['items'] );
		$this->save( $log );

		return $trial;
	}

	/**
	 * Saves a changed trial.
	 *
	 * @param array $trial The trial.
	 */
	private function save_trial( array $trial ): void {
		$log = $this->load();
		foreach ( $log['items'] as $index => $item ) {
			if ( (int) $item['id'] === (int) $trial['id'] ) {
				$log['items'][ $index ] = $trial;
			}
		}
		$this->save( $log );
	}

	/**
	 * The stored trials, each with every key present.
	 *
	 * @return array{next: int, items: array[]}
	 */
	private function load(): array {
		$log = get_option( self::OPTION, array() );
		$log = is_array( $log ) ? $log : array();

		$items = array();
		foreach ( (array) ( $log['items'] ?? array() ) as $trial ) {
			if ( ! is_array( $trial ) || ! isset( $trial['id'], $trial['user_id'] ) ) {
				continue;
			}
			$items[] = array_merge(
				array(
					'login'     => '',
					'by'        => 0,
					'started'   => 0,
					'ends'      => 0,
					'change_id' => 0,
					'batch'     => 0,
					'before'    => array(),
					'after'     => array(),
					'lost'      => array(),
					'status'    => 'active',
					'closed'    => 0,
					'auto'      => false,
					'requested' => 0,
					'denials'   => array(),
				),
				$trial
			);
		}

		return array(
			'next'  => max( 1, (int) ( $log['next'] ?? 1 ) ),
			'items' => $items,
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
