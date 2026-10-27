<?php
/**
 * Tests for building the report in batches.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Tests\Unit;

use AryanX\Axle\RoleRightSizer\CapabilityHistory;
use AryanX\Axle\RoleRightSizer\Recommender;
use AryanX\Axle\RoleRightSizer\Report;
use AryanX\Axle\RoleRightSizer\Settings;
use AryanX\Axle\RoleRightSizer\Usage\Store;
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
		$this->had( 'shop_editor', array( 'read', 'edit_posts', 'view_shop_reports' ), 60 );

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
					'owner' => null,
				),
			),
			$report['roles']
		);
		// Subscribers are never queried: they have nothing beyond reading.
		$this->assertNotContains( 'subscriber', \WP_User_Query::$made[0]['role__in'] );
	}

	public function test_plugin_capabilities_on_the_administrator_role_and_new_capabilities_are_not_listed(): void {
		// What QA found: Agent Governance's and WooCommerce's capabilities on Administrator, and WooCommerce's
		// on a role minutes after it was installed, all listed as unused.
		$this->roles['administrator']->add_cap( 'agent_governance_approve' );
		$this->roles['administrator']->add_cap( 'manage_woocommerce' );
		$this->add_role( 'shop_editor', array( 'read', 'view_shop_reports', 'edit_shop_orders' ) );
		$this->add_user( 1, array( 'administrator' ) );
		$this->used( 1, array( 'manage_options' => 1 ) );
		$this->active( 1, 1 );
		$this->add_user( 2, array( 'administrator' ) );
		$this->active( 2, 1 );
		$this->add_user( 6, array( 'shop_editor' ) );
		$this->active( 6, 1 );
		$this->had( 'administrator', array( 'agent_governance_approve', 'manage_woocommerce' ), 60 );
		$this->had( 'shop_editor', array( 'read', 'view_shop_reports' ), 60 );
		$this->had( 'shop_editor', array( 'edit_shop_orders' ), 0, 'woocommerce' );

		$roles = $this->report()->rebuild()['roles'];

		$this->assertSame( array( 'shop_editor|view_shop_reports' ), array_map( static function ( $row ) { return $row['role'] . '|' . $row['cap']; }, $roles ) );
	}

	public function test_a_build_brings_the_capability_history_up_to_date(): void {
		$this->add_role( 'shop_editor', array( 'read', 'view_shop_reports' ) );
		$this->add_user( 6, array( 'shop_editor' ) );
		$this->active( 6, 1 );

		// No history yet, and tracking began 60 days ago: nobody can tell how long the capability was there.
		$this->assertSame( array(), $this->report()->rebuild()['roles'] );
		$history = $this->options[ CapabilityHistory::OPTION ];
		$this->assertEqualsWithDelta( time(), $history['shop_editor']['view_shop_reports'][0], 5 );
		$this->assertArrayHasKey( 'manage_options', $history['administrator'] );
	}

	public function test_unused_capabilities_say_who_they_belong_to(): void {
		$this->add_role( 'shop_editor', array( 'read', 'view_shop_reports', 'manage_woocommerce', 'edit_products', 'old_cap' ) );
		$this->add_user( 6, array( 'shop_editor' ) );
		$this->active( 6, 1 );
		$this->had( 'shop_editor', array( 'read', 'view_shop_reports', 'manage_woocommerce', 'edit_products' ), 60 );
		$this->had( 'shop_editor', array( 'old_cap' ), 60, 'gone-plugin' );
		$this->options['active_plugins'] = array( 'woocommerce/woocommerce.php' );
		Functions\when( 'get_plugins' )->justReturn(
			array(
				'woocommerce/woocommerce.php' => array( 'Name' => 'WooCommerce' ),
				'other/other.php'             => array( 'Name' => 'Other' ),
			)
		);
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'product' => (object) array(
					'label' => 'Products',
					'cap'   => (object) array( 'edit_posts' => 'edit_products' ),
				),
			)
		);

		$owners = array();
		foreach ( $this->report()->rebuild()['roles'] as $row ) {
			$owners[ $row['cap'] ] = $row['owner'];
		}

		$this->assertNull( $owners['view_shop_reports'] );
		$this->assertSame( array( 'type' => 'plugin', 'id' => 'woocommerce/woocommerce.php', 'name' => 'WooCommerce', 'active' => true ), $owners['manage_woocommerce'] );
		$this->assertSame( array( 'type' => 'post_type', 'id' => 'product', 'name' => 'Products', 'active' => true ), $owners['edit_products'] );
		$this->assertSame( array( 'type' => 'plugin', 'id' => 'gone-plugin', 'name' => 'gone-plugin', 'active' => false ), $owners['old_cap'] );
		$this->assertTrue( Report::has_active_owner( array( 'owner' => $owners['manage_woocommerce'] ) ) );
		$this->assertFalse( Report::has_active_owner( array( 'owner' => $owners['old_cap'] ) ) );
		$this->assertFalse( Report::has_active_owner( array( 'owner' => null ) ) );
	}

	public function test_the_report_counts_accounts_too_new_to_judge_and_read_only_ones(): void {
		$this->add_user( 1, array( 'administrator' ) );
		$this->used( 1, array( 'manage_options' => 1 ) );
		$this->active( 1, 1 );
		$this->add_user( 8, array( 'author' ), array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ) );
		// New too, but active: it can be judged on what it did.
		$this->add_user( 12, array( 'author' ), array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ) );
		$this->active( 12, 1 );
		$this->add_user( 9, array( 'subscriber' ) );
		$this->add_user( 10, array( 'subscriber' ) );
		$this->add_user( 11, array() );

		$report = $this->report()->rebuild();

		$this->assertSame( 1, $report['too_new'] );
		$this->assertSame( 3, $report['read_only'] );
		$this->assertSame( array( 'administrator', 'editor', 'author', 'contributor' ), \WP_User_Query::$made[ count( \WP_User_Query::$made ) - 1 ]['role__not_in'] );
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
			'format' => Report::FORMAT,
			'status' => 'done',
			'built'  => time() - 600,
		);
		$this->assertFalse( $report->is_stale() );

		// Saved by 1.0.0, which listed administrator capabilities: built again before use.
		unset( $this->options[ Report::OPTION ]['format'] );
		$this->assertTrue( $report->is_stale() );
		$this->assertSame( Report::FORMAT, $report->rebuild()['format'] );
	}
}
