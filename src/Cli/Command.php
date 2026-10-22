<?php
/**
 * `wp role-right-sizer` commands.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer\Cli;

use AryanX\RoleRightSizer\Abilities;
use AryanX\RoleRightSizer\Admin\Screen;
use AryanX\RoleRightSizer\Applier;
use AryanX\RoleRightSizer\Changes;
use AryanX\RoleRightSizer\Recommender;
use AryanX\RoleRightSizer\Report;
use AryanX\RoleRightSizer\Settings;
use AryanX\RoleRightSizer\Usage\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Reports, applies and undoes role recommendations.
 */
final class Command {

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
	 * Sets up the command.
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
	 * Builds the recommendations and lists them.
	 *
	 * ## OPTIONS
	 *
	 * [--all-users]
	 * : List every user on the site, with or without a recommendation, in the columns of the CSV download.
	 *
	 * [--cached]
	 * : Show the saved report instead of building it again.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp role-right-sizer report
	 *     wp role-right-sizer report --all-users --format=csv > users.csv
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function report( array $args, array $assoc_args ): void {
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		if ( $this->settings->is_learning() ) {
			\WP_CLI::warning( sprintf( 'Still learning: recommendations show up on %s.', gmdate( 'Y-m-d', $this->settings->learning_ends() ) ) );
		} elseif ( empty( $assoc_args['cached'] ) ) {
			$this->report->rebuild( 0 );
		}

		$recommendations = $this->settings->is_learning() ? array() : $this->report->get()['users'];

		if ( ! empty( $assoc_args['all-users'] ) ) {
			$rows = array();
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
					$rows[] = Screen::csv_row(
						$user,
						isset( $recommendations[ $user->ID ] ) ? (array) $recommendations[ $user->ID ] : null,
						$this->store->usage( $user->ID ),
						$this->store->last_login( $user->ID ),
						$this->store->last_active( $user->ID )
					);
				}
				++$page;
				$found = count( $users );
			} while ( 100 === $found );

			\WP_CLI\Utils\format_items( $format, $rows, Screen::csv_columns() );
			return;
		}

		$rows = array();
		foreach ( $recommendations as $recommendation ) {
			$user   = get_userdata( (int) $recommendation['user_id'] );
			$rows[] = array(
				'type'        => $recommendation['type'],
				'target'      => $user ? $user->user_login : '#' . $recommendation['user_id'],
				'current'     => implode( ', ', (array) $recommendation['current'] ),
				'proposed'    => '' === $recommendation['proposed'] ? 'none' : $recommendation['proposed'],
				'lost'        => implode( ', ', (array) $recommendation['lost'] ),
				'last_active' => self::date( max( (int) $recommendation['last_active'], (int) $recommendation['last_login'] ) ),
			);
		}
		foreach ( $this->settings->is_learning() ? array() : $this->report->roles() as $recommendation ) {
			$rows[] = array(
				'type'        => 'unused_cap',
				'target'      => $recommendation['role'],
				'current'     => $recommendation['role'],
				'proposed'    => $recommendation['role'],
				'lost'        => $recommendation['cap'],
				'last_active' => '',
			);
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'type', 'target', 'current', 'proposed', 'lost', 'last_active' ) );
	}

	/**
	 * Shows what a user used.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, username or email address.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function usage( array $args, array $assoc_args ): void {
		$user   = self::user( (string) $args[0] );
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$since  = time() - $this->settings->window_days() * DAY_IN_SECONDS;
		$rows   = array();

		foreach ( array(
			'capability' => $this->store->usage( $user->ID ),
			'ability'    => $this->store->abilities( $user->ID ),
		) as $kind => $record ) {
			foreach ( $record as $name => $times ) {
				$rows[] = array(
					'kind'       => $kind,
					'name'       => $name,
					'first_used' => self::date( $times[0] ),
					'last_used'  => self::date( $times[1] ),
					'count'      => $times[2],
					'in_window'  => $times[1] >= $since ? 'yes' : 'no',
				);
			}
		}

		if ( 'table' === $format ) {
			\WP_CLI::line( sprintf( '%s (%s): %s', $user->display_name, implode( ', ', (array) $user->roles ), 'last login ' . self::date( $this->store->last_login( $user->ID ) ) . ', last active ' . self::date( $this->store->last_active( $user->ID ) ) ) );
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'kind', 'name', 'first_used', 'last_used', 'count', 'in_window' ) );
	}

	/**
	 * Applies recommendations. Every change can be undone with `undo`.
	 *
	 * ## OPTIONS
	 *
	 * [<user>...]
	 * : User IDs, usernames or email addresses.
	 *
	 * [--all]
	 * : Apply every recommendation, for users and roles, as one batch.
	 *
	 * [--dry-run]
	 * : Show what would change without changing anything.
	 *
	 * [--yes]
	 * : Do not ask before applying everything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp role-right-sizer apply pat
	 *     wp role-right-sizer apply --all --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function apply( array $args, array $assoc_args ): void {
		if ( $this->settings->is_learning() ) {
			\WP_CLI::error( sprintf( 'Still learning: recommendations show up on %s.', gmdate( 'Y-m-d', $this->settings->learning_ends() ) ) );
		}

		$all  = ! empty( $assoc_args['all'] );
		$ids  = array();
		$caps = array();
		if ( $all ) {
			$this->report->rebuild( 0 );
			$ids = array_map( 'intval', array_keys( $this->report->get()['users'] ) );
			foreach ( $this->report->roles() as $recommendation ) {
				$caps[] = $recommendation['role'] . '|' . $recommendation['cap'];
			}
		} elseif ( $args ) {
			foreach ( $args as $key ) {
				$ids[] = self::user( (string) $key )->ID;
			}
		} else {
			\WP_CLI::error( 'Name at least one user, or use --all.' );
		}

		if ( ! $ids && ! $caps ) {
			\WP_CLI::success( 'Nothing to change.' );
			return;
		}

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			foreach ( $ids as $id ) {
				$user           = get_userdata( $id );
				$recommendation = $user ? $this->recommender->for_user( $user, time(), 2 ) : null;
				$refused        = $user && $recommendation ? $this->applier->check_user( $user, $recommendation['proposed'] ) : null;
				if ( ! $user || ! $recommendation ) {
					\WP_CLI::line( sprintf( '%s: nothing to change.', $user ? $user->user_login : '#' . $id ) );
				} elseif ( $refused ) {
					\WP_CLI::line( sprintf( '%s: refused. %s', $user->user_login, $refused->get_error_message() ) );
				} else {
					\WP_CLI::line( sprintf( '%s: %s -> %s. Loses: %s', $user->user_login, implode( ', ', $recommendation['current'] ), '' === $recommendation['proposed'] ? 'no role' : $recommendation['proposed'], implode( ', ', $recommendation['lost'] ) ) );
				}
			}
			foreach ( $caps as $pair ) {
				\WP_CLI::line( sprintf( 'Role %s: remove %s', ...explode( '|', $pair, 2 ) ) );
			}
			\WP_CLI::success( 'Dry run: nothing was changed.' );
			return;
		}

		if ( $all ) {
			\WP_CLI::confirm( sprintf( 'Apply %d change(s)?', count( $ids ) + count( $caps ) ), $assoc_args );
		}

		$result = $this->applier->apply_many( $ids, $caps );
		foreach ( $result['changes'] as $change ) {
			if ( 'unused_cap' === $change['kind'] ) {
				\WP_CLI::line( sprintf( 'Change %d: removed %s from %s.', $change['id'], $change['cap'], $change['role'] ) );
			} else {
				\WP_CLI::line( sprintf( 'Change %d: %s, %s -> %s.', $change['id'], $change['login'], implode( ', ', $change['before'] ), $change['after'] ? implode( ', ', $change['after'] ) : 'no role' ) );
			}
		}
		foreach ( $result['errors'] as $key => $error ) {
			\WP_CLI::warning( sprintf( '%s: %s', $key, $error->get_error_message() ) );
		}

		if ( ! $result['changes'] ) {
			\WP_CLI::error( 'Nothing was changed.' );
		}

		\WP_CLI::success( sprintf( 'Applied %d change(s) as batch %d. Undo them with: wp role-right-sizer undo %d --batch', count( $result['changes'] ), $result['batch'], $result['batch'] ) );
	}

	/**
	 * Undoes a change.
	 *
	 * ## OPTIONS
	 *
	 * <change-id>
	 * : Change ID, from `wp role-right-sizer changes`.
	 *
	 * [--batch]
	 * : Undo every change applied together with this one.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function undo( array $args, array $assoc_args ): void {
		$id = (int) $args[0];

		if ( ! empty( $assoc_args['batch'] ) ) {
			$change = $this->changes->get( $id );
			if ( ! $change ) {
				\WP_CLI::error( 'That change is not in the log.' );
			}
			$result = $this->applier->undo_batch( (int) $change['batch'] );
			foreach ( $result['errors'] as $change_id => $error ) {
				\WP_CLI::warning( sprintf( 'Change %d: %s', $change_id, $error->get_error_message() ) );
			}
			\WP_CLI::success( sprintf( 'Undid %d change(s).', count( $result['undone'] ) ) );
			return;
		}

		$result = $this->applier->undo( $id );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Undid change %d.', $id ) );
	}

	/**
	 * Lists the changes Role Right-Sizer made, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function changes( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( $this->changes->all() as $change ) {
			$rows[] = array(
				'id'     => $change['id'],
				'batch'  => $change['batch'],
				'time'   => gmdate( 'Y-m-d H:i:s', (int) $change['time'] ),
				'by'     => (int) $change['by'],
				'kind'   => $change['kind'],
				'target' => 'unused_cap' === $change['kind'] ? $change['role'] : $change['login'],
				'before' => 'unused_cap' === $change['kind'] ? $change['cap'] : implode( ', ', (array) $change['before'] ),
				'after'  => 'unused_cap' === $change['kind'] ? '' : implode( ', ', (array) $change['after'] ),
				'undone' => $change['undone'] ? gmdate( 'Y-m-d H:i:s', (int) $change['undone'] ) : '',
			);
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'batch', 'time', 'by', 'kind', 'target', 'before', 'after', 'undone' ) );
	}

	/**
	 * Deletes everything recorded about a user on this site.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, username or email address.
	 *
	 * @param array $args Positional arguments.
	 */
	public function forget( array $args ): void {
		$user = self::user( (string) $args[0] );
		$this->store->forget( $user->ID );

		/**
		 * Fires after a user's recorded usage was deleted, from WP-CLI or the personal data eraser.
		 *
		 * @param int $user_id User ID.
		 */
		do_action( 'role_right_sizer_usage_forgotten', $user->ID );

		\WP_CLI::success( sprintf( 'Deleted the usage recorded for %s.', $user->user_login ) );
	}

	/**
	 * A user by ID, username or email, or an error.
	 *
	 * @param string $key ID, username or email.
	 * @return \WP_User
	 */
	private static function user( string $key ): \WP_User {
		$user = Abilities::find_user( $key );
		if ( ! $user ) {
			\WP_CLI::error( sprintf( 'No user found for "%s".', $key ) );
		}

		return $user;
	}

	/**
	 * A date for the output.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private static function date( int $time ): string {
		return $time ? gmdate( 'Y-m-d H:i', $time ) : 'never';
	}
}
