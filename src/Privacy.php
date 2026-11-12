<?php
/**
 * Personal data export, erasure and privacy policy text.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer;

use AryanX\Axle\RoleRightSizer\Usage\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Activity records are personal data: they say when someone was active and
 * what they did. They take part in core's privacy tools.
 */
final class Privacy implements Service {

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
	 * Sets up the privacy tools.
	 *
	 * @param Store   $store   Recorded usage.
	 * @param Changes $changes Change log.
	 * @param Trials  $trials  Trials of a smaller role.
	 */
	public function __construct( Store $store, Changes $changes, Trials $trials ) {
		$this->store   = $store;
		$this->changes = $changes;
		$this->trials  = $trials;
	}

	/**
	 * Registers the exporter, the eraser and the policy text.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy' ) );
	}

	/**
	 * Adds the exporter to Tools > Export Personal Data.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return mixed
	 */
	public function add_exporter( $exporters ) {
		if ( is_array( $exporters ) ) {
			$exporters['role-right-sizer'] = array(
				'exporter_friendly_name' => __( 'Role Right-Sizer', 'role-right-sizer' ),
				'callback'               => array( $this, 'export' ),
			);
		}

		return $exporters;
	}

	/**
	 * Adds the eraser to Tools > Erase Personal Data.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return mixed
	 */
	public function add_eraser( $erasers ) {
		if ( is_array( $erasers ) ) {
			$erasers['role-right-sizer'] = array(
				'eraser_friendly_name' => __( 'Role Right-Sizer', 'role-right-sizer' ),
				'callback'             => array( $this, 'erase' ),
			);
		}

		return $erasers;
	}

	/**
	 * Personal data exporter: activity, capabilities used, abilities run and role changes.
	 *
	 * @param mixed $email Email address.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email ): array {
		$user = get_user_by( 'email', (string) $email );
		$data = array();

		if ( $user ) {
			$data[] = array(
				'group_id'    => 'role-right-sizer-activity',
				'group_label' => __( 'Activity recorded by Role Right-Sizer', 'role-right-sizer' ),
				'item_id'     => 'role-right-sizer-activity-' . $user->ID,
				'data'        => array(
					array(
						'name'  => __( 'Last login', 'role-right-sizer' ),
						'value' => self::time( $this->store->last_login( $user->ID ) ),
					),
					array(
						'name'  => __( 'Last active on this site', 'role-right-sizer' ),
						'value' => self::time( $this->store->last_active( $user->ID ) ),
					),
				),
			);

			foreach ( array(
				'usage'     => $this->store->usage( $user->ID ),
				'abilities' => $this->store->abilities( $user->ID ),
			) as $group => $record ) {
				foreach ( $record as $name => $times ) {
					$data[] = array(
						'group_id'    => 'role-right-sizer-' . $group,
						'group_label' => 'usage' === $group ? __( 'Capabilities used', 'role-right-sizer' ) : __( 'Abilities run', 'role-right-sizer' ),
						'item_id'     => 'role-right-sizer-' . $group . '-' . sanitize_key( $name ),
						'data'        => array(
							array(
								'name'  => 'usage' === $group ? __( 'Capability', 'role-right-sizer' ) : __( 'Ability', 'role-right-sizer' ),
								'value' => $name,
							),
							array(
								'name'  => __( 'First used', 'role-right-sizer' ),
								'value' => self::time( $times[0] ),
							),
							array(
								'name'  => __( 'Last used', 'role-right-sizer' ),
								'value' => self::time( $times[1] ),
							),
						),
					);
				}
			}

			foreach ( $this->changes->all() as $change ) {
				if ( (int) ( $change['user_id'] ?? 0 ) !== $user->ID && (int) $change['by'] !== $user->ID ) {
					continue;
				}
				$data[] = array(
					'group_id'    => 'role-right-sizer-changes',
					'group_label' => __( 'Role changes', 'role-right-sizer' ),
					'item_id'     => 'role-right-sizer-change-' . $change['id'],
					'data'        => array(
						array(
							'name'  => __( 'Date', 'role-right-sizer' ),
							'value' => self::time( (int) $change['time'] ),
						),
						array(
							'name'  => __( 'Change', 'role-right-sizer' ),
							'value' => 'unused_cap' === $change['kind']
								? $change['role'] . ': -' . $change['cap']
								: implode( ', ', (array) $change['before'] ) . ' > ' . implode( ', ', (array) $change['after'] ),
						),
					),
				);
			}

			foreach ( $this->trials->all() as $trial ) {
				if ( (int) $trial['user_id'] !== $user->ID ) {
					continue;
				}
				$denials = array();
				foreach ( (array) $trial['denials'] as $denial ) {
					/* translators: 1: what they tried, 2: how many times. */
					$denials[] = sprintf( _n( '%1$s (%2$s time)', '%1$s (%2$s times)', (int) $denial['count'], 'role-right-sizer' ), $denial['label'], number_format_i18n( (int) $denial['count'] ) );
				}
				$data[] = array(
					'group_id'    => 'role-right-sizer-trials',
					'group_label' => __( 'Role trials', 'role-right-sizer' ),
					'item_id'     => 'role-right-sizer-trial-' . $trial['id'],
					'data'        => array(
						array(
							'name'  => __( 'Started', 'role-right-sizer' ),
							'value' => self::time( (int) $trial['started'] ),
						),
						array(
							'name'  => __( 'Status', 'role-right-sizer' ),
							'value' => (string) $trial['status'],
						),
						array(
							'name'  => __( 'Roles before and during the trial', 'role-right-sizer' ),
							'value' => implode( ', ', (array) $trial['before'] ) . ' > ' . implode( ', ', (array) $trial['after'] ),
						),
						array(
							'name'  => __( 'What they could not do', 'role-right-sizer' ),
							'value' => implode( '; ', $denials ),
						),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Personal data eraser: deletes the activity record and the person's place in the change log.
	 *
	 * @param mixed $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email ): array {
		$user    = get_user_by( 'email', (string) $email );
		$removed = false;

		if ( $user ) {
			$removed = $this->store->forget( $user->ID );
			$removed = $this->changes->forget_user( $user->ID ) || $removed;
			$removed = $this->trials->erase( $user->ID ) || $removed;

			/** This action is documented in src/Cli/Command.php */
			do_action( 'role_right_sizer_usage_forgotten', $user->ID );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested text for Settings > Privacy.
	 */
	public function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'Role Right-Sizer', 'role-right-sizer' ),
			'<p>' . esc_html__( 'When you are logged in, this site records which permissions your account used (such as publishing posts or managing plugins), when you last logged in and when you were last active. Each permission is stored with the first and last time it was used. Administrators use this to give every account only the access it needs. The record is kept for as long as the account exists, and changes to your role are kept in a log so they can be undone. If your role is reduced as a trial, the site also records what you could not do during the trial, such as opening a screen you no longer have access to, and keeps that record for a year after the trial ends.', 'role-right-sizer' ) . '</p>'
		);
	}

	/**
	 * A time for the export.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private static function time( int $time ): string {
		return $time ? gmdate( 'Y-m-d H:i:s', $time ) . ' UTC' : __( 'Never', 'role-right-sizer' );
	}
}
