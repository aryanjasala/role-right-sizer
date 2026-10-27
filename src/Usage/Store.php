<?php
/**
 * Where usage is kept: user meta, per site.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes each user's capability use, ability runs and activity.
 *
 * Roles are per site, so usage is too: the keys are user options, which
 * WordPress prefixes with the site's table prefix (`wp_2_role_right_sizer_usage`).
 * The last login is one network-wide key, because a login is.
 */
final class Store {

	public const USAGE       = 'role_right_sizer_usage';
	public const ABILITIES   = 'role_right_sizer_abilities';
	public const LAST_ACTIVE = 'role_right_sizer_last_active';
	public const LAST_LOGIN  = 'role_right_sizer_last_login';

	/**
	 * Capabilities a user used: `cap => [ first_used, last_used, count ]`.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, int[]>
	 */
	public function usage( int $user_id ): array {
		return self::clean( get_user_option( self::USAGE, $user_id ) );
	}

	/**
	 * Abilities a user ran: `name => [ first_run, last_run, count ]`.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, int[]>
	 */
	public function abilities( int $user_id ): array {
		return self::clean( get_user_option( self::ABILITIES, $user_id ) );
	}

	/**
	 * Last request the user made on this site while logged in.
	 *
	 * @param int $user_id User ID.
	 * @return int Unix time, 0 if never seen.
	 */
	public function last_active( int $user_id ): int {
		return (int) get_user_option( self::LAST_ACTIVE, $user_id );
	}

	/**
	 * Last login, on any site of the network.
	 *
	 * @param int $user_id User ID.
	 * @return int Unix time, 0 if never seen.
	 */
	public function last_login( int $user_id ): int {
		return (int) get_user_meta( $user_id, self::LAST_LOGIN, true );
	}

	/**
	 * The latest sign of life: activity on this site or a login.
	 *
	 * @param int $user_id User ID.
	 * @return int Unix time, 0 if never seen.
	 */
	public function last_seen( int $user_id ): int {
		return max( $this->last_active( $user_id ), $this->last_login( $user_id ) );
	}

	/**
	 * Capabilities used since a moment: `cap => last_used`.
	 *
	 * @param int $user_id User ID.
	 * @param int $since   Unix time.
	 * @return array<string, int>
	 */
	public function used_since( int $user_id, int $since ): array {
		return self::since( $this->usage( $user_id ), $since );
	}

	/**
	 * Adds capabilities to a user's usage, at most once per interval for each.
	 *
	 * @param int      $user_id  User ID.
	 * @param string[] $caps     Capabilities used in this request.
	 * @param int      $now      Unix time.
	 * @param int      $interval Seconds between two writes of the same capability.
	 * @return bool Whether anything was written.
	 */
	public function add_usage( int $user_id, array $caps, int $now, int $interval ): bool {
		list( $usage, $changed ) = self::merge( $this->usage( $user_id ), $caps, $now, $interval );
		if ( $changed ) {
			update_user_option( $user_id, self::USAGE, $usage );
		}

		return $changed;
	}

	/**
	 * Adds ability runs to a user's record, at most once per interval for each.
	 *
	 * @param int      $user_id   User ID.
	 * @param string[] $abilities Ability names run in this request.
	 * @param int      $now       Unix time.
	 * @param int      $interval  Seconds between two writes of the same ability.
	 * @return bool Whether anything was written.
	 */
	public function add_abilities( int $user_id, array $abilities, int $now, int $interval ): bool {
		list( $runs, $changed ) = self::merge( $this->abilities( $user_id ), $abilities, $now, $interval );
		if ( $changed ) {
			update_user_option( $user_id, self::ABILITIES, $runs );
		}

		return $changed;
	}

	/**
	 * Marks the user active on this site, at most once per interval.
	 *
	 * @param int $user_id  User ID.
	 * @param int $now      Unix time.
	 * @param int $interval Seconds between two writes.
	 * @return bool Whether it was written.
	 */
	public function touch( int $user_id, int $now, int $interval ): bool {
		if ( $now - $this->last_active( $user_id ) < max( 1, $interval ) ) {
			return false;
		}

		update_user_option( $user_id, self::LAST_ACTIVE, $now );

		return true;
	}

	/**
	 * Saves a login.
	 *
	 * @param int $user_id User ID.
	 * @param int $now     Unix time.
	 */
	public function login( int $user_id, int $now ): void {
		update_user_meta( $user_id, self::LAST_LOGIN, $now );
	}

	/**
	 * Deletes everything recorded about a user on this site, and their last login.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether there was anything to delete.
	 */
	public function forget( int $user_id ): bool {
		$had = (bool) ( $this->usage( $user_id ) || $this->abilities( $user_id ) || $this->last_seen( $user_id ) );

		delete_user_option( $user_id, self::USAGE );
		delete_user_option( $user_id, self::ABILITIES );
		delete_user_option( $user_id, self::LAST_ACTIVE );
		delete_user_meta( $user_id, self::LAST_LOGIN );

		return $had;
	}

	/**
	 * Merges names used now into a record, throttled per name.
	 *
	 * @param array<string, int[]> $record   `name => [ first, last, count ]`.
	 * @param string[]             $names    Names used now.
	 * @param int                  $now      Unix time.
	 * @param int                  $interval Seconds between two updates of the same name.
	 * @return array{0: array<string, int[]>, 1: bool} The record and whether it changed.
	 */
	public static function merge( array $record, array $names, int $now, int $interval ): array {
		$changed = false;

		foreach ( array_unique( $names ) as $name ) {
			$name = (string) $name;
			if ( '' === $name ) {
				continue;
			}

			if ( ! isset( $record[ $name ] ) ) {
				$record[ $name ] = array( $now, $now, 1 );
				$changed         = true;
			} elseif ( $now - $record[ $name ][1] >= max( 1, $interval ) ) {
				$record[ $name ][1] = $now;
				++$record[ $name ][2];
				$changed = true;
			}
		}

		if ( $changed ) {
			ksort( $record );
		}

		return array( $record, $changed );
	}

	/**
	 * Names used since a moment: `name => last_used`.
	 *
	 * @param array<string, int[]> $record `name => [ first, last, count ]`.
	 * @param int                  $since  Unix time.
	 * @return array<string, int>
	 */
	public static function since( array $record, int $since ): array {
		$used = array();
		foreach ( $record as $name => $times ) {
			if ( $times[1] >= $since ) {
				$used[ $name ] = $times[1];
			}
		}

		return $used;
	}

	/**
	 * A stored record, with anything malformed dropped.
	 *
	 * @param mixed $value Stored value.
	 * @return array<string, int[]>
	 */
	public static function clean( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$record = array();
		foreach ( $value as $name => $times ) {
			if ( is_string( $name ) && '' !== $name && is_array( $times ) && 3 === count( $times ) ) {
				$times           = array_map( 'intval', array_values( $times ) );
				$record[ $name ] = array( $times[0], $times[1], max( 1, $times[2] ) );
			}
		}

		return $record;
	}
}
