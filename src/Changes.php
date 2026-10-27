<?php
/**
 * The log of changes Role Right-Sizer made, kept for Undo.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer;

defined( 'ABSPATH' ) || exit;

/**
 * Every change with what it replaced, newest first, in one option.
 *
 * A change is an array:
 *
 * - `id`, `batch` (the ID of the first change applied with it), `time`, `by` (user ID, 0 for WP-CLI)
 * - `kind`: `downgrade`, `dormant`, `never` or `unused_cap`
 * - for users: `user_id`, `login`, `before` (roles), `after` (roles)
 * - for roles: `role`, `cap`
 * - `lost`: capabilities taken away
 * - `undone`: when it was undone, 0 if not
 */
final class Changes {

	public const OPTION = 'role_right_sizer_changes';

	/**
	 * Every change, newest first.
	 *
	 * @return array[]
	 */
	public function all(): array {
		return $this->load()['items'];
	}

	/**
	 * One change.
	 *
	 * @param int $id Change ID.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		foreach ( $this->all() as $change ) {
			if ( (int) $change['id'] === $id ) {
				return $change;
			}
		}

		return null;
	}

	/**
	 * The changes applied together with a change, oldest first.
	 *
	 * @param int $batch Batch ID.
	 * @return array[]
	 */
	public function batch( int $batch ): array {
		$items = array_filter(
			$this->all(),
			static function ( array $change ) use ( $batch ): bool {
				return (int) $change['batch'] === $batch;
			}
		);

		return array_reverse( array_values( $items ) );
	}

	/**
	 * The next change ID, without using it.
	 *
	 * @return int
	 */
	public function next_id(): int {
		return $this->load()['next'];
	}

	/**
	 * Adds a change.
	 *
	 * @param array $change The change, without `id` and `time`.
	 * @return array The change as saved.
	 */
	public function add( array $change ): array {
		$log              = $this->load();
		$change['id']     = $log['next'];
		$change['batch']  = (int) ( $change['batch'] ?? 0 ) ? (int) $change['batch'] : $change['id'];
		$change['time']   = time();
		$change['undone'] = 0;
		$log['next']      = $change['id'] + 1;
		$log['items']     = array_slice( array_merge( array( $change ), $log['items'] ), 0, self::size() );
		$this->save( $log );

		return $change;
	}

	/**
	 * Marks a change as undone.
	 *
	 * @param int $id Change ID.
	 */
	public function mark_undone( int $id ): void {
		$log = $this->load();
		foreach ( $log['items'] as $index => $change ) {
			if ( (int) $change['id'] === $id ) {
				$log['items'][ $index ]['undone'] = time();
			}
		}
		$this->save( $log );
	}

	/**
	 * Removes a user from the log: changes to their account go, and changes
	 * they made stay without their name. For the personal data eraser.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether anything changed.
	 */
	public function forget_user( int $user_id ): bool {
		$log     = $this->load();
		$changed = false;
		$items   = array();
		foreach ( $log['items'] as $change ) {
			if ( (int) ( $change['user_id'] ?? 0 ) === $user_id ) {
				$changed = true;
				continue;
			}
			if ( (int) $change['by'] === $user_id ) {
				$change['by'] = -1;
				$changed      = true;
			}
			$items[] = $change;
		}

		if ( $changed ) {
			$log['items'] = $items;
			$this->save( $log );
		}

		return $changed;
	}

	/**
	 * Changes the log keeps.
	 *
	 * @return int
	 */
	public static function size(): int {
		/**
		 * Filters how many changes the log keeps. Older ones can no longer be undone.
		 *
		 * @param int $size Default 500.
		 */
		return Settings::clamp( apply_filters( 'role_right_sizer_log_size', 500 ), 500, 10, 5000 );
	}

	/**
	 * The stored log.
	 *
	 * @return array{next: int, items: array[]}
	 */
	private function load(): array {
		$log = get_option( self::OPTION, array() );
		$log = is_array( $log ) ? $log : array();

		return array(
			'next'  => max( 1, (int) ( $log['next'] ?? 1 ) ),
			'items' => array_values( array_filter( (array) ( $log['items'] ?? array() ), 'is_array' ) ),
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
