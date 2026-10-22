<?php
/**
 * The saved list of recommendations, built in batches.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer;

use AryanX\RoleRightSizer\Usage\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and keeps the recommendations for the whole site.
 *
 * Only users with a role that grants more than `read` are looked at, 100 at a
 * time. A build runs up to 20 batches in the request that asks for it, and
 * the rest through WP-Cron, so a site with thousands of authors never runs one
 * long loop. A daily event rebuilds it in the background.
 */
final class Report implements Service {

	public const OPTION = 'role_right_sizer_report';
	public const CRON   = 'role_right_sizer_build';
	public const DAILY  = 'role_right_sizer_daily';

	/**
	 * Settings and limits.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Works out recommendations.
	 *
	 * @var Recommender
	 */
	private Recommender $recommender;

	/**
	 * Recorded usage.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Sets up the report.
	 *
	 * @param Settings    $settings    Settings and limits.
	 * @param Recommender $recommender Works out recommendations.
	 * @param Store       $store       Recorded usage.
	 */
	public function __construct( Settings $settings, Recommender $recommender, Store $store ) {
		$this->settings    = $settings;
		$this->recommender = $recommender;
		$this->store       = $store;
	}

	/**
	 * Hooks the background build and the daily rebuild.
	 */
	public function register(): void {
		add_action( self::CRON, array( $this, 'resume' ) );
		add_action( self::DAILY, array( $this, 'daily' ) );
		add_action( 'admin_init', array( $this, 'schedule' ) );
	}

	/**
	 * Makes sure the daily rebuild is scheduled.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY );
		}
	}

	/**
	 * The saved report.
	 *
	 * @return array{status: string, built: int, started: int, page: int, total: int, scanned: int, users: array<int, array>, roles: array[], role_usage: array, role_users: array<string, int>}
	 */
	public function get(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'status'     => in_array( $saved['status'] ?? '', array( 'done', 'running' ), true ) ? (string) $saved['status'] : 'none',
			'built'      => (int) ( $saved['built'] ?? 0 ),
			'started'    => (int) ( $saved['started'] ?? 0 ),
			'page'       => (int) ( $saved['page'] ?? 0 ),
			'total'      => (int) ( $saved['total'] ?? 0 ),
			'scanned'    => (int) ( $saved['scanned'] ?? 0 ),
			'users'      => is_array( $saved['users'] ?? null ) ? $saved['users'] : array(),
			'roles'      => is_array( $saved['roles'] ?? null ) ? array_values( $saved['roles'] ) : array(),
			'role_usage' => is_array( $saved['role_usage'] ?? null ) ? $saved['role_usage'] : array(),
			'role_users' => is_array( $saved['role_users'] ?? null ) ? $saved['role_users'] : array(),
		);
	}

	/**
	 * The user recommendations, in the order they were found.
	 *
	 * @return array[]
	 */
	public function users(): array {
		return array_values( $this->get()['users'] );
	}

	/**
	 * The role recommendations.
	 *
	 * @return array[]
	 */
	public function roles(): array {
		return $this->get()['roles'];
	}

	/**
	 * The saved recommendation for a user, if any.
	 *
	 * @param int $user_id User ID.
	 * @return array|null
	 */
	public function for_user( int $user_id ): ?array {
		$users = $this->get()['users'];

		return isset( $users[ $user_id ] ) ? (array) $users[ $user_id ] : null;
	}

	/**
	 * Whether the report needs building: none yet, or older than a day.
	 *
	 * @return bool
	 */
	public function is_stale(): bool {
		$report = $this->get();

		return 'running' !== $report['status'] && $report['built'] < time() - DAY_IN_SECONDS;
	}

	/**
	 * Users per batch.
	 *
	 * @return int
	 */
	public static function batch_size(): int {
		/**
		 * Filters how many users one batch of the report build looks at.
		 *
		 * @param int $size Default 100.
		 */
		return Settings::clamp( apply_filters( 'role_right_sizer_build_batch_size', 100 ), 100, 10, 500 );
	}

	/**
	 * Starts a new build and runs it, in this request as far as the limit allows.
	 *
	 * @param int $max_batches Batches to run now. 0 runs them all, for WP-CLI.
	 * @return array The report as saved.
	 */
	public function rebuild( int $max_batches = 20 ): array {
		wp_clear_scheduled_hook( self::CRON );

		if ( $this->settings->is_learning() ) {
			delete_option( self::OPTION );
			return $this->get();
		}

		$this->save(
			array(
				'status'     => 'running',
				'built'      => $this->get()['built'],
				'started'    => time(),
				'page'       => 0,
				'total'      => 0,
				'scanned'    => 0,
				'users'      => array(),
				'roles'      => array(),
				'role_usage' => array(),
				'role_users' => array(),
			)
		);

		return $this->run( $max_batches );
	}

	/**
	 * Carries on a build in the background. Runs on WP-Cron.
	 */
	public function resume(): void {
		if ( 'running' === $this->get()['status'] ) {
			$this->run( 20 );
		}
	}

	/**
	 * Daily rebuild. Runs on WP-Cron.
	 */
	public function daily(): void {
		if ( 'running' !== $this->get()['status'] ) {
			$this->rebuild();
		}
	}

	/**
	 * Runs batches of a started build.
	 *
	 * @param int $max_batches Batches to run, 0 for all.
	 * @return array The report as saved.
	 */
	public function run( int $max_batches ): array {
		$batches = 0;
		while ( ! $this->step() ) {
			++$batches;
			if ( $max_batches && $batches >= $max_batches ) {
				if ( ! wp_next_scheduled( self::CRON ) ) {
					wp_schedule_single_event( time(), self::CRON );
				}
				break;
			}
		}

		return $this->get();
	}

	/**
	 * Looks at the next batch of users.
	 *
	 * @return bool Whether the build is finished.
	 */
	public function step(): bool {
		$report = $this->get();
		if ( 'running' !== $report['status'] ) {
			return true;
		}

		$roles = $this->recommender->privileged_roles();
		$size  = self::batch_size();
		$query = new \WP_User_Query(
			array(
				'role__in'    => $roles ? $roles : array( '__none__' ),
				'number'      => $size,
				'paged'       => $report['page'] + 1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'count_total' => 0 === $report['page'],
			)
		);
		$users = (array) $query->get_results();

		if ( 0 === $report['page'] ) {
			$report['total'] = (int) $query->get_total();
		}

		$now    = time();
		$since  = $now - $this->settings->window_days() * DAY_IN_SECONDS;
		$admins = Recommender::admin_count();
		foreach ( $users as $user ) {
			if ( ! $user instanceof \WP_User ) {
				continue;
			}

			++$report['scanned'];
			$used = $this->store->used_since( $user->ID, $since );
			foreach ( (array) $user->roles as $role ) {
				$report['role_users'][ $role ] = (int) ( $report['role_users'][ $role ] ?? 0 ) + 1;
				foreach ( $used as $cap => $last ) {
					$report['role_usage'][ $role ][ $cap ] = max( (int) ( $report['role_usage'][ $role ][ $cap ] ?? 0 ), $last );
				}
			}

			$recommendation = $this->recommender->for_user( $user, $now, $admins );
			if ( $recommendation ) {
				$report['users'][ $user->ID ] = $recommendation;
			}
		}

		++$report['page'];
		$done = count( $users ) < $size;

		if ( $done ) {
			$report['roles']  = Recommender::unused_role_caps(
				$report['role_usage'],
				$report['role_users'],
				Recommender::role_caps(),
				array_merge( $this->settings->ignored_capabilities(), Recommender::core_capabilities() ),
				$this->settings->excluded_roles()
			);
			$report['status'] = 'done';
			$report['built']  = $now;
		}

		$this->save( $report );

		if ( $done ) {
			/**
			 * Fires when the recommendations have been built.
			 *
			 * @param array $report `users` (recommendations by user ID), `roles` (unused capabilities), `built`, `scanned`.
			 */
			do_action( 'role_right_sizer_recommendations_built', $report );
		}

		return $done;
	}

	/**
	 * Drops one user's recommendation, after it was applied.
	 *
	 * @param int $user_id User ID.
	 */
	public function forget_user( int $user_id ): void {
		$report = $this->get();
		if ( isset( $report['users'][ $user_id ] ) ) {
			unset( $report['users'][ $user_id ] );
			$this->save( $report );
		}
	}

	/**
	 * Saves the report.
	 *
	 * @param array $report Report.
	 */
	private function save( array $report ): void {
		update_option( self::OPTION, $report, false );
	}
}
