<?php
/**
 * Applies recommendations and undoes them.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer;

defined( 'ABSPATH' ) || exit;

/**
 * Changes roles through core's own functions, so other plugins see every
 * change (`set_user_role`, `add_user_role`, `remove_user_role`), and logs
 * what was there before so it can be put back.
 */
final class Applier {

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
	 * Sets up the applier.
	 *
	 * @param Settings    $settings    Settings and limits.
	 * @param Recommender $recommender Works out recommendations.
	 * @param Report      $report      Saved recommendations.
	 * @param Changes     $changes     Change log.
	 */
	public function __construct( Settings $settings, Recommender $recommender, Report $report, Changes $changes ) {
		$this->settings    = $settings;
		$this->recommender = $recommender;
		$this->report      = $report;
		$this->changes     = $changes;
	}

	/**
	 * Whether a user's role may be changed by the current user, or why not.
	 *
	 * @param \WP_User $user     The user.
	 * @param string   $proposed Role they would get, '' for none.
	 * @return \WP_Error|null Null when allowed.
	 */
	public function check_user( \WP_User $user, string $proposed ): ?\WP_Error {
		$current = get_current_user_id();

		if ( $current && $current === $user->ID ) {
			return new \WP_Error( 'role_right_sizer_self', __( 'You cannot change your own role here.', 'role-right-sizer' ) );
		}

		$skip = $this->recommender->skip_reason( $user );
		if ( in_array( $skip, array( 'excluded', 'excluded_role', 'super_admin' ), true ) ) {
			return new \WP_Error( 'role_right_sizer_excluded', __( 'This user is on the exclude list.', 'role-right-sizer' ) );
		}

		$keeps = '' === $proposed ? array() : (array) ( Recommender::role_caps()[ $proposed ] ?? array() );
		if ( array_intersect( (array) $user->roles, Recommender::admin_roles() ) && ! in_array( 'manage_options', $keeps, true ) && Recommender::admin_count() <= 1 ) {
			return new \WP_Error( 'role_right_sizer_last_admin', __( 'This is the last administrator, so the role stays.', 'role-right-sizer' ) );
		}

		if ( $current && ! current_user_can( 'promote_user', $user->ID ) ) {
			return new \WP_Error( 'role_right_sizer_forbidden', __( 'Sorry, you are not allowed to change this user\'s role.', 'role-right-sizer' ) );
		}

		return null;
	}

	/**
	 * Applies the current recommendation for a user.
	 *
	 * The recommendation is worked out again now, so a stale report cannot
	 * apply something that no longer fits.
	 *
	 * @param int $user_id User ID.
	 * @param int $batch   Batch to add the change to, 0 to start one.
	 * @return array|\WP_Error The change, or why it was refused.
	 */
	public function apply_user( int $user_id, int $batch = 0 ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'role_right_sizer_not_found', __( 'That user does not exist.', 'role-right-sizer' ) );
		}

		if ( get_current_user_id() === $user->ID ) {
			return new \WP_Error( 'role_right_sizer_self', __( 'You cannot change your own role here.', 'role-right-sizer' ) );
		}

		$recommendation = $this->recommender->for_user( $user, time(), 2 );
		if ( ! $recommendation ) {
			return new \WP_Error( 'role_right_sizer_nothing', __( 'There is nothing to change for this user.', 'role-right-sizer' ) );
		}

		$refused = $this->check_user( $user, $recommendation['proposed'] );
		if ( $refused ) {
			return $refused;
		}

		$change = array(
			'batch'   => $batch,
			'by'      => get_current_user_id(),
			'kind'    => $recommendation['type'],
			'user_id' => $user->ID,
			'login'   => $user->user_login,
			'before'  => array_values( (array) $user->roles ),
			'after'   => '' === $recommendation['proposed'] ? array() : array( $recommendation['proposed'] ),
			'lost'    => $recommendation['lost'],
		);

		$this->before_apply( $change, $user );
		$user->set_role( $recommendation['proposed'] );

		return $this->applied( $this->changes->add( $change ) );
	}

	/**
	 * Removes a capability no user of a role used from that role.
	 *
	 * Only what the saved report recommends can be applied.
	 *
	 * @param string $role  Role name.
	 * @param string $cap   Capability.
	 * @param int    $batch Batch to add the change to, 0 to start one.
	 * @return array|\WP_Error The change, or why it was refused.
	 */
	public function apply_role_cap( string $role, string $cap, int $batch = 0 ) {
		$known = false;
		foreach ( $this->report->roles() as $recommendation ) {
			if ( $recommendation['role'] === $role && $recommendation['cap'] === $cap ) {
				$known = true;
			}
		}

		$object = get_role( $role );
		if ( ! $known || ! $object || empty( $object->capabilities[ $cap ] ) ) {
			return new \WP_Error( 'role_right_sizer_nothing', __( 'That capability is not recommended for removal from this role.', 'role-right-sizer' ) );
		}
		if ( in_array( $role, $this->settings->excluded_roles(), true ) ) {
			return new \WP_Error( 'role_right_sizer_excluded', __( 'This role is on the exclude list.', 'role-right-sizer' ) );
		}

		$change = array(
			'batch' => $batch,
			'by'    => get_current_user_id(),
			'kind'  => 'unused_cap',
			'role'  => $role,
			'cap'   => $cap,
			'lost'  => array( $cap ),
		);

		$this->before_apply( $change, null );
		$object->remove_cap( $cap );

		return $this->applied( $this->changes->add( $change ) );
	}

	/**
	 * Applies several recommendations as one batch, which can be undone together.
	 *
	 * @param int[]    $user_ids  Users.
	 * @param string[] $role_caps `role|capability` pairs.
	 * @return array{batch: int, changes: array[], errors: array<string, \WP_Error>}
	 */
	public function apply_many( array $user_ids, array $role_caps = array() ): array {
		$batch   = 0;
		$changes = array();
		$errors  = array();

		foreach ( array_unique( array_map( 'intval', $user_ids ) ) as $user_id ) {
			$result = $this->apply_user( $user_id, $batch );
			if ( is_wp_error( $result ) ) {
				$errors[ 'user:' . $user_id ] = $result;
				continue;
			}
			$batch     = $batch ? $batch : (int) $result['batch'];
			$changes[] = $result;
		}

		foreach ( array_unique( array_map( 'strval', $role_caps ) ) as $pair ) {
			$parts  = explode( '|', $pair, 2 );
			$result = 2 === count( $parts ) ? $this->apply_role_cap( $parts[0], $parts[1], $batch ) : new \WP_Error( 'role_right_sizer_nothing', __( 'That capability is not recommended for removal from this role.', 'role-right-sizer' ) );
			if ( is_wp_error( $result ) ) {
				$errors[ 'role:' . $pair ] = $result;
				continue;
			}
			$batch     = $batch ? $batch : (int) $result['batch'];
			$changes[] = $result;
		}

		if ( $changes ) {
			$this->report->rebuild();
		}

		return array(
			'batch'   => $batch,
			'changes' => $changes,
			'errors'  => $errors,
		);
	}

	/**
	 * Puts back what one change replaced.
	 *
	 * @param int  $id      Change ID.
	 * @param bool $rebuild Whether to rebuild the report afterwards.
	 * @return array|\WP_Error The change, or why it could not be undone.
	 */
	public function undo( int $id, bool $rebuild = true ) {
		$change = $this->changes->get( $id );
		if ( ! $change ) {
			return new \WP_Error( 'role_right_sizer_not_found', __( 'That change is not in the log.', 'role-right-sizer' ) );
		}
		if ( $change['undone'] ) {
			return new \WP_Error( 'role_right_sizer_undone', __( 'That change was already undone.', 'role-right-sizer' ) );
		}

		if ( 'unused_cap' === $change['kind'] ) {
			$role = get_role( (string) $change['role'] );
			if ( ! $role ) {
				return new \WP_Error( 'role_right_sizer_gone', __( 'That role no longer exists.', 'role-right-sizer' ) );
			}
			$role->add_cap( (string) $change['cap'], true );
		} else {
			$user = get_userdata( (int) $change['user_id'] );
			if ( ! $user instanceof \WP_User ) {
				return new \WP_Error( 'role_right_sizer_gone', __( 'That user no longer exists.', 'role-right-sizer' ) );
			}
			$current = get_current_user_id();
			if ( $current && ( $current === $user->ID || ! current_user_can( 'promote_user', $user->ID ) ) ) {
				return new \WP_Error( 'role_right_sizer_forbidden', __( 'Sorry, you are not allowed to change this user\'s role.', 'role-right-sizer' ) );
			}

			$before = array_values( array_map( 'strval', (array) $change['before'] ) );
			$user->set_role( $before[0] ?? '' );
			foreach ( array_slice( $before, 1 ) as $role ) {
				$user->add_role( $role );
			}
		}

		$this->changes->mark_undone( $id );
		$change['undone'] = time();

		/**
		 * Fires after a change was undone.
		 *
		 * @param array $change The change, see Changes.
		 */
		do_action( 'role_right_sizer_undone', $change );

		if ( $rebuild ) {
			$this->report->rebuild();
		}

		return $change;
	}

	/**
	 * Undoes every change of a batch that is not undone yet, newest first.
	 *
	 * @param int $batch Batch ID.
	 * @return array{undone: array[], errors: array<int, \WP_Error>}
	 */
	public function undo_batch( int $batch ): array {
		$undone = array();
		$errors = array();
		foreach ( array_reverse( $this->changes->batch( $batch ) ) as $change ) {
			if ( $change['undone'] ) {
				continue;
			}
			$result = $this->undo( (int) $change['id'], false );
			if ( is_wp_error( $result ) ) {
				$errors[ (int) $change['id'] ] = $result;
			} else {
				$undone[] = $result;
			}
		}

		if ( $undone ) {
			$this->report->rebuild();
		}

		return array(
			'undone' => $undone,
			'errors' => $errors,
		);
	}

	/**
	 * Fires before a change.
	 *
	 * @param array         $change The change about to be made.
	 * @param \WP_User|null $user   The user, for user changes.
	 */
	private function before_apply( array $change, ?\WP_User $user ): void {
		/**
		 * Fires right before Role Right-Sizer changes a user's role or removes a capability from a role.
		 *
		 * @param array         $change `kind`, `before`, `after`, `lost`, and `user_id` or `role` and `cap`.
		 * @param \WP_User|null $user   The user, or null for a role change.
		 */
		do_action( 'role_right_sizer_before_apply', $change, $user );
	}

	/**
	 * Fires after a change and hands it back.
	 *
	 * @param array $change The change as logged.
	 * @return array
	 */
	private function applied( array $change ): array {
		/**
		 * Fires after Role Right-Sizer changed a user's role or removed a capability from a role.
		 *
		 * @param array $change The change as logged, with its `id` for Undo.
		 */
		do_action( 'role_right_sizer_applied', $change );

		return $change;
	}
}
