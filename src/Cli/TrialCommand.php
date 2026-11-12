<?php
/**
 * `wp role-right-sizer trial` commands.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Cli;

use AryanX\Axle\RoleRightSizer\Abilities;
use AryanX\Axle\RoleRightSizer\Trials;

defined( 'ABSPATH' ) || exit;

/**
 * Tries a smaller role for a while, and decides what happens at the end.
 */
final class TrialCommand {

	/**
	 * The trials.
	 *
	 * @var Trials
	 */
	private Trials $trials;

	/**
	 * Sets up the command.
	 *
	 * @param Trials $trials The trials.
	 */
	public function __construct( Trials $trials ) {
		$this->trials = $trials;
	}

	/**
	 * Gives users their recommended smaller role for a while and records what they could not do.
	 *
	 * Every trial is logged like any other change, so `wp role-right-sizer undo` works too.
	 *
	 * ## OPTIONS
	 *
	 * <user>...
	 * : User IDs, usernames or email addresses. Only accounts with a "smaller role" recommendation can be tried.
	 *
	 * [--days=<days>]
	 * : How long the trial lasts. Defaults to 14, or what the role_right_sizer_trial_days filter returns.
	 *
	 * ## EXAMPLES
	 *
	 *     wp role-right-sizer trial start sam
	 *     wp role-right-sizer trial start sam pat --days=30
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function start( array $args, array $assoc_args ): void {
		$ids = array();
		foreach ( $args as $key ) {
			$user = Abilities::find_user( $key );
			if ( ! $user ) {
				\WP_CLI::error( sprintf( 'No user found for "%s".', $key ) );
			}
			$ids[] = $user->ID;
		}

		$result = $this->trials->start_many( $ids, isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : 0 );
		foreach ( $result['errors'] as $key => $error ) {
			\WP_CLI::warning( sprintf( '%s: %s', $key, $error->get_error_message() ) );
		}
		foreach ( $result['trials'] as $trial ) {
			\WP_CLI::log( sprintf( 'Trial %d: %s until %s.', $trial['id'], $trial['login'], gmdate( 'Y-m-d H:i', (int) $trial['ends'] ) ) );
		}

		if ( ! $result['trials'] ) {
			\WP_CLI::error( 'No trial started.' );
		}
		\WP_CLI::success( sprintf( 'Started %d trial(s).', count( $result['trials'] ) ) );
	}

	/**
	 * Lists trials, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only trials with this status: active, ended, kept, restored, or open for active and ended.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp role-right-sizer trial list --status=open
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$status = (string) ( $assoc_args['status'] ?? '' );
		$rows   = array();
		foreach ( $this->trials->all() as $trial ) {
			if ( '' !== $status && ( 'open' === $status ? ! in_array( $trial['status'], array( 'active', 'ended' ), true ) : $trial['status'] !== $status ) ) {
				continue;
			}
			$rows[] = array(
				'id'        => $trial['id'],
				'user'      => $trial['login'],
				'status'    => $trial['status'],
				'started'   => gmdate( 'Y-m-d H:i', (int) $trial['started'] ),
				'ends'      => gmdate( 'Y-m-d H:i', (int) $trial['ends'] ),
				'days_left' => Trials::days_left( $trial ),
				'before'    => implode( ', ', (array) $trial['before'] ),
				'during'    => implode( ', ', (array) $trial['after'] ),
				'denials'   => Trials::denial_total( $trial ),
				'asked'     => $trial['requested'] ? gmdate( 'Y-m-d H:i', (int) $trial['requested'] ) : '',
			);
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'user', 'status', 'started', 'ends', 'days_left', 'before', 'during', 'denials', 'asked' ) );
	}

	/**
	 * Shows what a person could not do during a trial.
	 *
	 * ## OPTIONS
	 *
	 * <trial-id>
	 * : Trial ID, from `wp role-right-sizer trial list`.
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
	public function denials( array $args, array $assoc_args ): void {
		$trial = $this->trials->get( (int) $args[0] );
		if ( ! $trial ) {
			\WP_CLI::error( 'That trial does not exist.' );
		}

		$rows = array();
		foreach ( (array) $trial['denials'] as $denial ) {
			$rows[] = array(
				'what'  => $denial['label'],
				'kind'  => $denial['kind'],
				'cap'   => $denial['cap'],
				'count' => $denial['count'],
				'first' => gmdate( 'Y-m-d H:i', (int) $denial['first'] ),
				'last'  => gmdate( 'Y-m-d H:i', (int) $denial['last'] ),
			);
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'what', 'kind', 'cap', 'count', 'first', 'last' ) );
	}

	/**
	 * Gives the person their old role back and ends the trial.
	 *
	 * ## OPTIONS
	 *
	 * <trial-id>
	 * : Trial ID.
	 *
	 * @param array $args Positional arguments.
	 */
	public function restore( array $args ): void {
		$result = $this->trials->restore( (int) $args[0] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Gave %s their old role back.', $result['login'] ) );
	}

	/**
	 * Makes the smaller role permanent. The change stays in the log, where `undo` can still put it back.
	 *
	 * ## OPTIONS
	 *
	 * <trial-id>
	 * : Trial ID.
	 *
	 * @param array $args Positional arguments.
	 */
	public function keep( array $args ): void {
		$result = $this->trials->keep( (int) $args[0] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'The smaller role is permanent for %s.', $result['login'] ) );
	}

	/**
	 * Gives a trial more days.
	 *
	 * ## OPTIONS
	 *
	 * <trial-id>
	 * : Trial ID.
	 *
	 * [--days=<days>]
	 * : Days to add. Defaults to 14, or what the role_right_sizer_trial_days filter returns.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function extend( array $args, array $assoc_args ): void {
		$result = $this->trials->extend( (int) $args[0], isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : 0 );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'The trial for %s now ends on %s.', $result['login'], gmdate( 'Y-m-d H:i', (int) $result['ends'] ) ) );
	}
}
