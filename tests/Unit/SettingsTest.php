<?php
/**
 * Tests for the settings, the tracking start and the filtered limits.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer\Tests\Unit;

use AryanX\RoleRightSizer\Settings;
use Brain\Monkey\Filters;

final class SettingsTest extends PluginTestCase {

	public function test_tracking_starts_once_and_learning_lasts_thirty_days(): void {
		$settings = new Settings();
		$started  = $settings->tracking_started();

		$this->assertEqualsWithDelta( time(), $started, 2 );
		$this->assertSame( $started, $settings->tracking_started() );
		$this->assertTrue( $settings->is_learning() );
		$this->assertSame( $started + 30 * DAY_IN_SECONDS, $settings->learning_ends() );

		$this->tracking_started( 31 );
		$this->assertFalse( $settings->is_learning() );
	}

	public function test_day_filters_are_clamped_and_fall_back_on_junk(): void {
		$settings = new Settings();

		Filters\expectApplied( 'role_right_sizer_learning_days' )->once()->with( 30 )->andReturn( 0 );
		$this->assertSame( 0, $settings->learning_days() );

		Filters\expectApplied( 'role_right_sizer_window_days' )->once()->with( 90 )->andReturn( 'lots' );
		$this->assertSame( 90, $settings->window_days() );

		Filters\expectApplied( 'role_right_sizer_dormant_days' )->once()->with( 90 )->andReturn( 99999 );
		$this->assertSame( 3650, $settings->dormant_days() );

		Filters\expectApplied( 'role_right_sizer_record_interval' )->once()->with( HOUR_IN_SECONDS )->andReturn( -5 );
		$this->assertSame( 0, $settings->record_interval() );
	}

	public function test_ignored_capabilities_keep_read_and_take_a_filter(): void {
		$settings = new Settings();
		$this->assertContains( 'level_10', $settings->ignored_capabilities() );
		$this->assertNotContains( 'read', $settings->ignored_capabilities() );

		Filters\expectApplied( 'role_right_sizer_ignored_capabilities' )->once()->andReturn( array( 'exist', 'view_stats', '', 42 ) );
		$this->assertSame( array( 'exist', 'view_stats' ), $settings->ignored_capabilities() );
	}

	public function test_exclude_lists_come_from_the_settings_and_their_filters(): void {
		$this->options[ Settings::OPTION ] = array(
			'excluded_users' => array( 4, '7' ),
			'excluded_roles' => array( 'editor' ),
		);
		$settings = new Settings();

		Filters\expectApplied( 'role_right_sizer_excluded_users' )->once()->with( array( 4, 7 ) )->andReturn( array( 4, 7, '9', 0, 'x' ) );
		$this->assertSame( array( 4, 7, 9 ), $settings->excluded_users() );

		Filters\expectApplied( 'role_right_sizer_excluded_roles' )->once()->with( array( 'editor' ) )->andReturn( 'not a list' );
		$this->assertSame( array(), $settings->excluded_roles() );
	}

	public function test_dormant_action_and_fallback_role_take_filters_and_fail_safe(): void {
		$this->options[ Settings::OPTION ] = array(
			'dormant_action' => 'fallback',
			'fallback_role'  => 'subscriber',
		);
		$settings = new Settings();
		$user     = new \WP_User( 3 );

		$this->assertSame( 'fallback', $settings->dormant_action( $user ) );
		$this->assertSame( 'subscriber', $settings->fallback_role( $user ) );

		Filters\expectApplied( 'role_right_sizer_dormant_action' )->once()->with( 'fallback', $user )->andReturn( 'delete everyone' );
		$this->assertSame( 'remove', $settings->dormant_action( $user ) );

		Filters\expectApplied( 'role_right_sizer_fallback_role' )->once()->with( 'subscriber', $user )->andReturn( 'no_such_role' );
		$this->assertSame( '', $settings->fallback_role( $user ) );
	}

	public function test_saving_keeps_only_real_roles_and_known_actions(): void {
		$settings = new Settings();
		$settings->save(
			array(
				'dormant_action' => 'nuke',
				'fallback_role'  => 'ghost',
				'excluded_users' => array( '3', 'x', 3 ),
				'excluded_roles' => array( 'editor', 'ghost' ),
			)
		);

		$this->assertSame(
			array(
				'dormant_action' => 'remove',
				'fallback_role'  => '',
				'excluded_users' => array( 3 ),
				'excluded_roles' => array( 'editor' ),
			),
			$this->options[ Settings::OPTION ]
		);
	}
}
