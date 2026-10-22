<?php
/**
 * Tests for building the report in batches.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer\Tests\Unit;

use AryanX\RoleRightSizer\Recommender;
use AryanX\RoleRightSizer\Report;
use AryanX\RoleRightSizer\Settings;
use AryanX\RoleRightSizer\Usage\Store;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class ReportTest extends PluginTestCase {

	private function report(): Report {
		$settings = new Settings();
		$store    = new Store();

		return new Report( $settings, new Recommender( $settings, $store ), $store );
	}

	protected function setUp(): void {
		parent::setUp();
		$this->tracking_started( 60 );
	}

	public function test_a_build_finds_users_and_unused_role_capabilities_and_fires_the_action(): void {
		$this->add_role( 'shop_editor', array( 'read', 'edit_posts', 'view_shop_reports' ) );
		$this->add_user( 1, array( 'administrator' ) );
		$this->used( 1, array( 'manage_options' => 1 ) );
		$this->active( 1, 1 );
		$this->add_user( 5, array( 'administrator' ) );
		$this->used( 5, array( 'edit_others_posts' => 1 ) );
		$this->active( 5, 1 );
		$this->add_user( 6, array( 'shop_editor' ) );
		$this->used( 6, array( 'edit_posts' => 1 ) );
		$this->active( 6, 1 );
		$this->add_user( 7, array( 'subscriber' ) );

		Actions\expectDone( 'role_right_sizer_recommendations_built' )->once()->with( \Mockery::on( static function ( $report ) {
			return 'done' === $report['status'] && isset( $report['users'][5] );
		} ) );
		$report = $this->report()->rebuild();

		$this->assertSame( 'done', $report['status'] );
		$this->assertSame( array( 5 ), array_keys( $report['users'] ) );
		$this->assertSame( 'editor', $report['users'][5]['proposed'] );
		$this->assertSame( 3, $report['scanned'] );
		$this->assertSame(
			array(
				array(
					'type'  => 'unused_cap',
					'role'  => 'shop_editor',
					'cap'   => 'view_shop_reports',
					'users' => 1,
				),
			),
			$report['roles']
		);
		// Subscribers are never queried: they have nothing beyond reading.
		$this->assertNotContains( 'subscriber', \WP_User_Query::$made[0]['role__in'] );
	}

	public function test_big_sites_build_in_batches_and_carry_on_in_the_background(): void {
		for ( $id = 10; $id < 25; $id++ ) {
			$this->add_user( $id, array( 'editor' ) );
			$this->active( $id, 1 );
		}
		$scheduled = array();
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( $time, $hook ) use ( &$scheduled ) {
				$scheduled[] = $hook;
				return true;
			}
		);

		Filters\expectApplied( 'role_right_sizer_build_batch_size' )->andReturn( 3 );
		$report = $this->report()->rebuild( 1 );

		// The size is clamped to at least 10: one batch looks at 10, cron does the rest.
		$this->assertSame( 'running', $report['status'] );
		$this->assertSame( 10, $report['scanned'] );
		$this->assertSame( 15, $report['total'] );
		$this->assertSame( array( Report::CRON ), $scheduled );
	}

	public function test_a_build_left_running_carries_on_from_cron(): void {
		for ( $id = 10; $id < 35; $id++ ) {
			$this->add_user( $id, array( 'editor' ) );
		}
		Filters\expectApplied( 'role_right_sizer_build_batch_size' )->andReturn( 10 );
		$report = $this->report();

		$this->assertSame( 'running', $report->rebuild( 1 )['status'] );
		$this->assertSame( 10, $report->get()['scanned'] );
		$report->resume();
		$this->assertSame( 'done', $report->get()['status'] );
		$this->assertSame( 25, $report->get()['scanned'] );
	}

	public function test_nothing_is_built_while_learning(): void {
		$this->tracking_started( 2 );
		$this->options[ Report::OPTION ] = array( 'status' => 'done' );

		$this->assertSame( 'none', $this->report()->rebuild()['status'] );
		$this->assertArrayNotHasKey( Report::OPTION, $this->options );
	}

	public function test_a_report_older_than_a_day_is_stale(): void {
		$report = $this->report();
		$this->assertTrue( $report->is_stale() );

		$this->options[ Report::OPTION ] = array(
			'status' => 'done',
			'built'  => time() - 600,
		);
		$this->assertFalse( $report->is_stale() );
	}
}
