<?php
/**
 * Tests for applying recommendations, the protections, the log and Undo.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer\Tests\Unit;

use AryanX\RoleRightSizer\Applier;
use AryanX\RoleRightSizer\Changes;
use AryanX\RoleRightSizer\Recommender;
use AryanX\RoleRightSizer\Report;
use AryanX\RoleRightSizer\Settings;
use AryanX\RoleRightSizer\Usage\Store;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

final class ApplierTest extends PluginTestCase {

	/**
	 * The change log.
	 *
	 * @var Changes
	 */
	private $changes;

	/**
	 * The saved report.
	 *
	 * @var Report
	 */
	private $report;

	private function applier(): Applier {
		$settings      = new Settings();
		$store         = new Store();
		$recommender   = new Recommender( $settings, $store );
		$this->changes = new Changes();
		$this->report  = new Report( $settings, $recommender, $store );

		return new Applier( $settings, $recommender, $this->report, $this->changes );
	}

	protected function setUp(): void {
		parent::setUp();
		$this->tracking_started( 60 );

		$this->add_user( 1, array( 'administrator' ), array( 'user_login' => 'admin' ) );
		$this->used( 1, array( 'manage_options' => 1 ) );
		$this->active( 1, 1 );
		$this->add_user( 5, array( 'administrator' ), array( 'user_login' => 'pat' ) );
		$this->used( 5, array( 'edit_others_posts' => 1 ) );
		$this->active( 5, 1 );
		$this->current_user = 1;
	}

	public function test_applying_changes_the_role_logs_it_and_fires_the_actions(): void {
		Actions\expectDone( 'role_right_sizer_before_apply' )->once()->with(
			\Mockery::on(
				static function ( $change ) {
					return 'downgrade' === $change['kind'] && array( 'administrator' ) === $change['before'] && array( 'editor' ) === $change['after'];
				}
			),
			$this->users[5]
		);
		Actions\expectDone( 'role_right_sizer_applied' )->once()->with( \Mockery::on( static function ( $change ) {
			return 1 === $change['id'] && 'pat' === $change['login'];
		} ) );

		$change = $this->applier()->apply_user( 5 );

		$this->assertSame( array( 'editor' ), $this->users[5]->roles );
		$this->assertSame( array( array( 'set_role', 'editor' ) ), $this->users[5]->calls );
		$this->assertSame( 1, $change['batch'] );
		$this->assertContains( 'manage_options', $change['lost'] );
		$this->assertSame( $change, $this->changes->get( 1 ) );
	}

	public function test_undo_puts_every_role_back_and_fires_the_action(): void {
		$this->users[5]->roles = array( 'administrator', 'author' );
		$applier               = $this->applier();
		$change                = $applier->apply_user( 5 );

		Actions\expectDone( 'role_right_sizer_undone' )->once();
		$undone = $applier->undo( $change['id'] );

		$this->assertSame( array( 'administrator', 'author' ), $this->users[5]->roles );
		$this->assertGreaterThan( 0, $undone['undone'] );
		$this->assertSame( 'role_right_sizer_undone', $applier->undo( $change['id'] )->get_error_code() );
		$this->assertSame( 'role_right_sizer_not_found', $applier->undo( 99 )->get_error_code() );
	}

	public function test_you_cannot_change_yourself(): void {
		$this->current_user = 5;

		$this->assertSame( 'role_right_sizer_self', $this->applier()->apply_user( 5 )->get_error_code() );
		$this->assertSame( array( 'administrator' ), $this->users[5]->roles );
	}

	public function test_the_last_administrator_keeps_the_role(): void {
		$this->current_user = 0;
		unset( $this->users[1] );

		$this->assertSame( 'role_right_sizer_last_admin', $this->applier()->apply_user( 5 )->get_error_code() );
		$this->assertSame( array( 'administrator' ), $this->users[5]->roles );
	}

	public function test_excluded_users_cannot_be_changed(): void {
		Filters\expectApplied( 'role_right_sizer_excluded_users' )->andReturn( array( 5 ) );

		$this->assertSame( 'role_right_sizer_nothing', $this->applier()->apply_user( 5 )->get_error_code() );
	}

	public function test_without_promote_users_nothing_changes(): void {
		$this->users[1]->roles = array( 'editor' );
		$this->add_user( 2, array( 'administrator' ) );

		$this->assertSame( 'role_right_sizer_forbidden', $this->applier()->apply_user( 5 )->get_error_code() );
	}

	public function test_a_batch_applies_users_and_role_capabilities_and_undoes_together(): void {
		$this->add_role( 'shop_editor', array( 'read', 'edit_posts', 'view_shop_reports' ) );
		$this->add_user( 6, array( 'shop_editor' ) );
		$this->used( 6, array( 'edit_posts' => 1 ) );
		$this->active( 6, 1 );
		$this->had( 'shop_editor', array( 'read', 'edit_posts', 'view_shop_reports' ), 60 );
		$applier = $this->applier();
		$this->report->rebuild();

		$result = $applier->apply_many( array( 5, 77 ), array( 'shop_editor|view_shop_reports', 'shop_editor|edit_posts', 'junk' ) );

		$this->assertCount( 2, $result['changes'] );
		$this->assertSame( array( 'user:77', 'role:shop_editor|edit_posts', 'role:junk' ), array_keys( $result['errors'] ) );
		$this->assertSame( $result['batch'], $result['changes'][1]['batch'] );
		$this->assertFalse( $this->roles['shop_editor']->has_cap( 'view_shop_reports' ) );
		$this->assertSame( array( 'editor' ), $this->users[5]->roles );

		$undone = $applier->undo_batch( $result['batch'] );
		$this->assertCount( 2, $undone['undone'] );
		$this->assertTrue( $this->roles['shop_editor']->has_cap( 'view_shop_reports' ) );
		$this->assertSame( array( 'administrator' ), $this->users[5]->roles );
	}

	public function test_a_capability_is_never_removed_from_a_protected_role_even_when_an_old_report_lists_it(): void {
		$this->roles['administrator']->add_cap( 'agent_governance_approve' );
		$applier = $this->applier();
		// A report saved by version 1.0.0, before administrators were protected.
		$this->options[ Report::OPTION ] = array(
			'status' => 'done',
			'built'  => time(),
			'roles'  => array(
				array(
					'type'  => 'unused_cap',
					'role'  => 'administrator',
					'cap'   => 'agent_governance_approve',
					'users' => 2,
				),
			),
		);

		$result = $applier->apply_role_cap( 'administrator', 'agent_governance_approve' );

		$this->assertSame( 'role_right_sizer_protected', $result->get_error_code() );
		$this->assertTrue( $this->roles['administrator']->has_cap( 'agent_governance_approve' ) );
		$this->assertSame( array(), $this->changes->all() );
	}

	public function test_the_log_keeps_its_size_and_forgets_people(): void {
		Filters\expectApplied( 'role_right_sizer_log_size' )->andReturn( 3 );
		$changes = new Changes();
		for ( $i = 0; $i < 12; $i++ ) {
			$changes->add(
				array(
					'by'      => 1,
					'kind'    => 'downgrade',
					'user_id' => 5 + $i % 2,
				)
			);
		}

		// The size is clamped to at least 10.
		$this->assertCount( 10, $changes->all() );
		$this->assertSame( 12, $changes->all()[0]['id'] );
		$this->assertSame( 13, $changes->next_id() );

		$this->assertTrue( $changes->forget_user( 5 ) );
		$this->assertCount( 5, $changes->all() );
		$this->assertTrue( $changes->forget_user( 1 ) );
		$this->assertSame( -1, $changes->all()[0]['by'] );
		$this->assertFalse( $changes->forget_user( 42 ) );
	}
}
