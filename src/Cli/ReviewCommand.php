<?php
/**
 * `wp role-right-sizer review` commands.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Cli;

use AryanX\Axle\RoleRightSizer\Abilities;
use AryanX\Axle\RoleRightSizer\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * Runs access reviews: start one, decide per account, finish it and export the evidence.
 */
final class ReviewCommand {

	/**
	 * The reviews.
	 *
	 * @var Reviews
	 */
	private Reviews $reviews;

	/**
	 * Sets up the command.
	 *
	 * @param Reviews $reviews The reviews.
	 */
	public function __construct( Reviews $reviews ) {
		$this->reviews = $reviews;
	}

	/**
	 * Starts an access review: a snapshot of every account. Nothing changes on any account.
	 *
	 * ## OPTIONS
	 *
	 * [--name=<name>]
	 * : Name of the review. Defaults to the date.
	 *
	 * [--due=<date>]
	 * : Due date as YYYY-MM-DD.
	 *
	 * [--reviewers=<users>]
	 * : Comma separated user IDs, usernames or email addresses to tell about it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp role-right-sizer review start --name="Q4 review" --due=2026-12-15
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function start( array $args, array $assoc_args ): void {
		$due = 0;
		if ( isset( $assoc_args['due'] ) ) {
			$due = Reviews::due_from_date( (string) $assoc_args['due'] );
			if ( ! $due ) {
				\WP_CLI::error( '--due must be a date like 2026-12-15.' );
			}
		}

		$ids = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['reviewers'] ?? '' ) ) ) ) as $key ) {
			$user = Abilities::find_user( $key );
			if ( ! $user ) {
				\WP_CLI::error( sprintf( 'No user found for "%s".', $key ) );
			}
			$ids[] = $user->ID;
		}

		$review = $this->reviews->start( (string) ( $assoc_args['name'] ?? '' ), $due, $ids );
		if ( is_wp_error( $review ) ) {
			\WP_CLI::error( $review->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Started review %d with %d accounts.', $review['id'], count( $review['entries'] ) ) );
	}

	/**
	 * Lists reviews, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : open or finished.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$status = (string) ( $assoc_args['status'] ?? '' );
		$rows   = array();
		foreach ( $this->reviews->all() as $review ) {
			if ( '' !== $status && $review['status'] !== $status ) {
				continue;
			}
			$progress = Reviews::progress( $review );
			$rows[]   = array(
				'id'       => $review['id'],
				'name'     => $review['name'],
				'status'   => $review['status'],
				'started'  => Reviews::utc( (int) $review['started'] ),
				'due'      => Reviews::utc( (int) $review['due'] ),
				'finished' => Reviews::utc( (int) $review['finished'] ),
				'accounts' => $progress['total'],
				'decided'  => $progress['decided'],
				'keep'     => $progress['keep'],
				'reduce'   => $progress['reduce'],
				'remove'   => $progress['remove'],
			);
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'name', 'status', 'started', 'due', 'finished', 'accounts', 'decided', 'keep', 'reduce', 'remove' ) );
	}

	/**
	 * Shows the accounts of a review with their decisions.
	 *
	 * ## OPTIONS
	 *
	 * <review>
	 * : Review ID.
	 *
	 * [--undecided]
	 * : Only accounts without a decision.
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
	public function show( array $args, array $assoc_args ): void {
		$review = $this->find( $args );
		$rows   = array();
		foreach ( $review['entries'] as $entry ) {
			if ( isset( $assoc_args['undecided'] ) && '' !== $entry['decision'] ) {
				continue;
			}
			$rows[] = array(
				'user_id'        => $entry['user_id'],
				'user'           => $entry['login'],
				'roles'          => implode( ', ', (array) $entry['roles'] ),
				'recommendation' => null === $entry['proposed'] ? '' : ( '' === $entry['proposed'] ? 'none' : $entry['proposed'] ),
				'decision'       => $entry['decision'],
				'role'           => $entry['role'],
				'note'           => $entry['note'],
				'outcome'        => (string) ( $entry['result']['status'] ?? '' ),
			);
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'user_id', 'user', 'roles', 'recommendation', 'decision', 'role', 'note', 'outcome' ) );
	}

	/**
	 * Records the decision about one account.
	 *
	 * ## OPTIONS
	 *
	 * <review>
	 * : Review ID.
	 *
	 * <user>
	 * : User ID, username or email address.
	 *
	 * <decision>
	 * : keep, reduce or remove.
	 *
	 * [--role=<role>]
	 * : For reduce: the role to give. Defaults to the recommended role.
	 *
	 * [--note=<note>]
	 * : A note for the evidence.
	 *
	 * ## EXAMPLES
	 *
	 *     wp role-right-sizer review decide 1 sam reduce --role=editor --note="Only writes posts"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function decide( array $args, array $assoc_args ): void {
		$review = $this->find( $args );
		$user   = Abilities::find_user( $args[1] ?? '' );
		if ( ! $user ) {
			\WP_CLI::error( sprintf( 'No user found for "%s".', $args[1] ?? '' ) );
		}

		$result = $this->reviews->decide( (int) $review['id'], $user->ID, (string) ( $args[2] ?? '' ), (string) ( $assoc_args['role'] ?? '' ), (string) ( $assoc_args['note'] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( '%s: %s.', $user->user_login, $this->reviews->decision_text( $result ) ) );
	}

	/**
	 * Marks every account without a decision as Keep.
	 *
	 * ## OPTIONS
	 *
	 * <review>
	 * : Review ID.
	 *
	 * @subcommand keep-rest
	 *
	 * @param array $args Positional arguments.
	 */
	public function keep_rest( array $args ): void {
		$review = $this->find( $args );
		$count  = $this->reviews->keep_undecided( (int) $review['id'] );
		if ( is_wp_error( $count ) ) {
			\WP_CLI::error( $count->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Marked %d accounts Keep.', $count ) );
	}

	/**
	 * Applies the decisions through the change log and closes the review.
	 *
	 * Every Reduce and Remove is one change in one batch, so `wp role-right-sizer undo` can put it back.
	 *
	 * ## OPTIONS
	 *
	 * <review>
	 * : Review ID.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function finish( array $args, array $assoc_args ): void {
		$review = $this->find( $args );
		foreach ( $this->reviews->plan( $review ) as $row ) {
			\WP_CLI::log( sprintf( '%s: %s to %s%s', $row['login'], implode( ', ', $row['from'] ), '' === $row['to'] ? 'no role' : $row['to'], '' === $row['problem'] ? '' : ' (skipped: ' . $row['problem'] . ')' ) );
		}
		\WP_CLI::confirm( 'Apply these changes and close the review?', $assoc_args );

		$result = $this->reviews->finish( (int) $review['id'] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		$counts = array();
		foreach ( $result['entries'] as $entry ) {
			$status            = (string) ( $entry['result']['status'] ?? '' );
			$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + 1;
			if ( in_array( $status, array( 'failed', 'gone' ), true ) ) {
				\WP_CLI::warning( sprintf( '%s: %s', $entry['login'], $entry['result']['message'] ) );
			}
		}
		\WP_CLI::success( sprintf( 'Review finished. %d applied, change batch %d.', $counts['applied'] ?? 0, $result['batch'] ) );
	}

	/**
	 * Throws away an open review. A finished review stays as evidence.
	 *
	 * ## OPTIONS
	 *
	 * <review>
	 * : Review ID.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function discard( array $args, array $assoc_args ): void {
		$review = $this->find( $args );
		\WP_CLI::confirm( 'Delete this open review with all its decisions?', $assoc_args );
		$result = $this->reviews->discard( (int) $review['id'] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( 'Review discarded.' );
	}

	/**
	 * Exports the evidence of a review.
	 *
	 * ## OPTIONS
	 *
	 * <review>
	 * : Review ID.
	 *
	 * [--format=<format>]
	 * : csv or html.
	 * ---
	 * default: csv
	 * ---
	 *
	 * [--file=<path>]
	 * : Write to this file instead of the screen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp role-right-sizer review export 1 --format=html --file=review-1.html
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function export( array $args, array $assoc_args ): void {
		$review = $this->find( $args );
		$format = (string) ( $assoc_args['format'] ?? 'csv' );
		if ( ! in_array( $format, array( 'csv', 'html' ), true ) ) {
			\WP_CLI::error( '--format must be csv or html.' );
		}

		$content = 'html' === $format ? $this->reviews->html( $review ) : $this->reviews->csv( $review );
		if ( isset( $assoc_args['file'] ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP-CLI writes the file the operator asked for.
			if ( false === file_put_contents( (string) $assoc_args['file'], $content ) ) {
				\WP_CLI::error( 'Could not write the file.' );
			}
			\WP_CLI::success( sprintf( 'Wrote %s.', $assoc_args['file'] ) );
			return;
		}

		\WP_CLI::line( $content );
	}

	/**
	 * The review named by the first argument, or an error.
	 *
	 * @param array $args Positional arguments.
	 * @return array
	 */
	private function find( array $args ): array {
		$review = $this->reviews->get( (int) ( $args[0] ?? 0 ) );
		if ( ! $review ) {
			\WP_CLI::error( 'There is no review with that ID.' );
		}

		return $review;
	}
}
