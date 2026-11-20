<?php
/**
 * Applies recommendations and undoes them.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer;

use AryanX\Axle\RoleRightSizer\Probe\Prober;

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
		if ( array_intersect( Temporary::permanent_roles( $user ), Recommender::admin_roles() ) && ! in_array( 'manage_options', $keeps, true ) && Recommender::admin_count() <= 1 ) {
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
			'before'  => Temporary::permanent_roles( $user ),
			'after'   => '' === $recommendation['proposed'] ? array() : array( $recommendation['proposed'] ),
			'lost'    => $recommendation['lost'],
		);

		$this->before_apply( $change, $user );
		$this->assign( $user, $recommendation['proposed'] );

		return $this->applied( $this->changes->add( $change ) );
	}

	/**
	 * Gives a user a smaller role, or none, chosen by a person (an access review).
	 *
	 * Unlike apply_user() this does not need a saved recommendation, but it
	 * never gives more access than the user has now, and it keeps every
	 * protection of check_user().
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role to give, '' to take every role away.
	 * @param int    $batch   Batch to add the change to, 0 to start one.
	 * @param string $kind    Kind to log the change as.
	 * @return array|\WP_Error The change, or why it was refused.
	 */
	public function apply_role( int $user_id, string $role, int $batch = 0, string $kind = 'review' ) {
		$plan = $this->plan_role( $user_id, $role );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		// The plan always holds the user it checked.
		$user   = $plan['user'];
		$change = array(
			'batch'   => $batch,
			'by'      => get_current_user_id(),
			'kind'    => $kind,
			'user_id' => $user->ID,
			'login'   => $user->user_login,
			'before'  => Temporary::permanent_roles( $user ),
			'after'   => '' === $role ? array() : array( $role ),
			'lost'    => $plan['lost'],
		);

		$this->before_apply( $change, $user );
		$this->assign( $user, $role );

		return $this->applied( $this->changes->add( $change ) );
	}

	/**
	 * The roles a user could be reduced to: only roles that grant fewer capabilities,
	 * every one of them a capability the person has now.
	 *
	 * Access a role was lent for a time (Axle Elevate) does not count.
	 *
	 * @param \WP_User $user The user.
	 * @return string[] Role names, smallest first.
	 */
	public static function smaller_roles( \WP_User $user ): array {
		$roles = Recommender::role_caps();
		$have  = Temporary::permanent_caps( $user );
		$fits  = array();
		foreach ( $roles as $name => $caps ) {
			if ( ! array_diff( $caps, $have ) && array_diff( $have, $caps ) ) {
				$fits[ $name ] = count( $caps );
			}
		}
		asort( $fits );
		$names = array_map( 'strval', array_keys( $fits ) );

		/**
		 * Filters the roles an access review offers under "Reduce to" for a person.
		 *
		 * Roles that would give the person a capability they do not have are dropped
		 * from the result, whatever the filter returns.
		 *
		 * @param string[] $names Role names, smallest first. Only roles with fewer capabilities, all of them held now.
		 * @param \WP_User $user  The person.
		 */
		$filtered = apply_filters( 'role_right_sizer_reduce_roles', $names, $user );

		return array_values( array_intersect( Settings::strings( $filtered ), $names ) );
	}

	/**
	 * Gives a person access they were refused, found by "Why can't they?": one capability, or one role on top of what they have.
	 *
	 * Nobody gets more than the person asking could give: a capability needs to be one the asker holds, and a role
	 * one WordPress lets them assign. The change goes in the log, so Undo works.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    `cap` or `role`.
	 * @param string $value   The capability or the role.
	 * @param int    $batch   Batch to add the change to, 0 to start one.
	 * @return array|\WP_Error The change, or why it was refused.
	 */
	public function apply_fix( int $user_id, string $type, string $value, int $batch = 0 ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'role_right_sizer_not_found', __( 'That user does not exist.', 'role-right-sizer' ) );
		}

		$current = get_current_user_id();
		if ( $current && $current === $user->ID ) {
			return new \WP_Error( 'role_right_sizer_self', __( 'You cannot change your own role here.', 'role-right-sizer' ) );
		}
		if ( in_array( $this->recommender->skip_reason( $user ), array( 'excluded', 'excluded_role', 'super_admin' ), true ) ) {
			return new \WP_Error( 'role_right_sizer_excluded', __( 'This user is on the exclude list.', 'role-right-sizer' ) );
		}
		if ( $current && ! current_user_can( 'promote_user', $user->ID ) ) {
			return new \WP_Error( 'role_right_sizer_forbidden', __( 'Sorry, you are not allowed to change this user\'s role.', 'role-right-sizer' ) );
		}
		if ( (int) get_user_option( Trials::FLAG, $user->ID ) ) {
			return new \WP_Error( 'role_right_sizer_trial_exists', __( 'On a role trial, so the role is left alone. Decide on the trial first.', 'role-right-sizer' ) );
		}

		$before = Temporary::permanent_roles( $user );
		$change = array(
			'batch'   => $batch,
			'by'      => $current,
			'user_id' => $user->ID,
			'login'   => $user->user_login,
			'before'  => $before,
			'lost'    => array(),
		);

		if ( 'cap' === $type ) {
			if ( ! Prober::may_hand_out_cap( $value ) ) {
				return new \WP_Error( 'role_right_sizer_fix_cap', __( 'You can only give a capability you have yourself.', 'role-right-sizer' ) );
			}
			if ( in_array( $value, Temporary::permanent_caps( $user ), true ) ) {
				return new \WP_Error( 'role_right_sizer_nothing', __( 'There is nothing to change for this user.', 'role-right-sizer' ) );
			}
			$change['kind']   = 'fix_cap';
			$change['after']  = $before;
			$change['cap']    = $value;
			$change['gained'] = array( $value );

			$this->before_apply( $change, $user );
			$user->add_cap( $value, true );
		} elseif ( 'role' === $type ) {
			$roles = Recommender::role_caps();
			if ( ! isset( $roles[ $value ] ) ) {
				return new \WP_Error( 'role_right_sizer_role_unknown', __( 'That role does not exist.', 'role-right-sizer' ) );
			}
			if ( ! in_array( $value, Prober::editable_roles(), true ) ) {
				return new \WP_Error( 'role_right_sizer_fix_role', __( 'You are not allowed to give that role.', 'role-right-sizer' ) );
			}
			if ( in_array( $value, $before, true ) ) {
				return new \WP_Error( 'role_right_sizer_nothing', __( 'There is nothing to change for this user.', 'role-right-sizer' ) );
			}
			$change['kind']   = 'fix_role';
			$change['after']  = array_merge( $before, array( $value ) );
			$change['gained'] = array_values( array_diff( $roles[ $value ], Temporary::permanent_caps( $user ) ) );

			$this->before_apply( $change, $user );
			$user->add_role( $value );
		} else {
			return new \WP_Error( 'role_right_sizer_fix_type', __( 'Choose to give one capability or one role.', 'role-right-sizer' ) );
		}

		return $this->applied( $this->changes->add( $change ) );
	}

	/**
	 * What giving a user a role would take away, or why it cannot be done. Changes nothing.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role to give, '' for none.
	 * @return array{user: \WP_User, lost: string[]}|\WP_Error
	 */
	public function plan_role( int $user_id, string $role ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'role_right_sizer_not_found', __( 'That user does not exist.', 'role-right-sizer' ) );
		}

		$roles = Recommender::role_caps();
		if ( '' !== $role && ! isset( $roles[ $role ] ) ) {
			return new \WP_Error( 'role_right_sizer_role_unknown', __( 'That role does not exist.', 'role-right-sizer' ) );
		}

		$refused = $this->check_user( $user, $role );
		if ( $refused ) {
			return $refused;
		}

		$current = array_values( array_intersect( Temporary::permanent_roles( $user ), array_keys( $roles ) ) );
		$have    = array();
		foreach ( $current as $name ) {
			$have = array_merge( $have, $roles[ $name ] );
		}
		$have  = array_values( array_unique( $have ) );
		$keeps = '' === $role ? array() : $roles[ $role ];
		if ( array_diff( $keeps, $have ) ) {
			return new \WP_Error( 'role_right_sizer_more_access', __( 'That role would give more access than the person has now.', 'role-right-sizer' ) );
		}

		$lost = Recommender::lost( $have, $keeps, $this->settings->ignored_capabilities() );
		if ( ! $lost || ( 1 === count( $current ) && $current[0] === $role ) ) {
			return new \WP_Error( 'role_right_sizer_nothing', __( 'There is nothing to change for this user.', 'role-right-sizer' ) );
		}

		return array(
			'user' => $user,
			'lost' => $lost,
		);
	}

	/**
	 * Removes a capability no user of a role used from that role.
	 *
	 * Only what the saved report recommends can be applied, and never on a
	 * protected role (see Recommender::protected_roles()).
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

		if ( in_array( $role, Recommender::protected_roles(), true ) ) {
			// Checked again here: a report saved by an older version may still list it.
			return new \WP_Error( 'role_right_sizer_protected', __( 'Capabilities are never removed from the administrator role or a role that can manage options.', 'role-right-sizer' ) );
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

		if ( 'fix_cap' === $change['kind'] ) {
			$user = get_userdata( (int) $change['user_id'] );
			if ( ! $user instanceof \WP_User ) {
				return new \WP_Error( 'role_right_sizer_gone', __( 'That user no longer exists.', 'role-right-sizer' ) );
			}
			$current = get_current_user_id();
			if ( $current && ( $current === $user->ID || ! current_user_can( 'promote_user', $user->ID ) ) ) {
				return new \WP_Error( 'role_right_sizer_forbidden', __( 'Sorry, you are not allowed to change this user\'s role.', 'role-right-sizer' ) );
			}
			$user->remove_cap( (string) $change['cap'] );
		} elseif ( 'unused_cap' === $change['kind'] ) {
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
			$this->assign( $user, $before[0] ?? '' );
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
	 * Sets a person's permanent role and keeps any role Axle Elevate lent them for now.
	 *
	 * @param \WP_User $user The user.
	 * @param string   $role  Role to give, '' for none.
	 */
	private function assign( \WP_User $user, string $role ): void {
		$lent = Temporary::roles( $user->ID );
		$user->set_role( $role );
		foreach ( $lent as $name ) {
			$user->add_role( $name );
		}
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
