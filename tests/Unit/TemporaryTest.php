<?php
/**
 * Tests for roles lent for a time (Axle Elevate): not an administrator for the last-admin guard,
 * not counted as normal use, and kept when a permanent role changes.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Tests\Unit;

use AryanX\Axle\RoleRightSizer\Applier;
use AryanX\Axle\RoleRightSizer\Changes;
use AryanX\Axle\RoleRightSizer\Recommender;
use AryanX\Axle\RoleRightSizer\Report;
use AryanX\Axle\RoleRightSizer\Settings;
use AryanX\Axle\RoleRightSizer\Temporary;
use AryanX\Axle\RoleRightSizer\Usage\HookMap;
use AryanX\Axle\RoleRightSizer\Usage\Recorder;
use AryanX\Axle\RoleRightSizer\Usage\Store;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class TemporaryTest extends PluginTestCase {

	/**
	 * Lends a user a role for an hour, the way Elevate stores it.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role.
	 * @param string $kind    Kind of elevation.
	 */
	private function lend( int $user_id, string $role, string $kind = 'role' ): void {
		$this->users[ $user_id ]->roles[]                    = $role;
		$this->meta[ $user_id ]['axle_elevate_active'] = array(
			array(
				'id'      => 9,
				'kind'    => $kind,
				'role'    => $role,
				'blog'    => 1,
				'expires' => time() + HOUR_IN_SECONDS,
			),
		);
	}

	protected function setUp(): void {
		parent::setUp();
		$this->tracking_started( 60 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	public function test_roles_on_loan_are_read_from_elevates_stored_list(): void {
		$this->add_user( 3, array( 'editor' ) );
		$this->assertSame( array(), Temporary::roles( 3 ) );

		$this->lend( 3, 'administrator' );

		$this->assertSame( array( 'administrator' ), Temporary::roles( 3 ) );
		$this->assertSame( array( 'editor' ), Temporary::permanent_roles( $this->users[3] ) );
		$this->assertTrue( Temporary::is_elevated( 3 ) );
	}

	public function test_an_entry_for_another_site_or_past_its_end_is_ignored(): void {
		$this->add_user( 3, array( 'editor', 'administrator' ) );
		$this->meta[3]['axle_elevate_active'] = array(
			array( 'id' => 1, 'kind' => 'role', 'role' => 'administrator', 'blog' => 2, 'expires' => time() + 600 ),
			array( 'id' => 2, 'kind' => 'role', 'role' => 'author', 'blog' => 1, 'expires' => time() - 5 ),
		);

		$this->assertSame( array(), Temporary::roles( 3 ) );
	}

	public function test_a_bundle_counts_as_elevated_but_lends_no_role(): void {
		$this->add_user( 3, array( 'editor' ) );
		$this->lend( 3, '', 'bundle' );
		$this->users[3]->roles = array( 'editor' );

		$this->assertTrue( Temporary::is_elevated( 3 ) );
		$this->assertSame( array(), Temporary::roles( 3 ) );
	}

	public function test_another_plugin_can_lend_access_through_a_filter(): void {
		$this->add_user( 3, array( 'editor', 'administrator' ) );
		Filters\expectApplied( 'role_right_sizer_temporary_access' )->once()->andReturn( array( array( 'kind' => 'role', 'role' => 'administrator', 'expires' => 0 ), 'junk' ) );

		$this->assertSame( array( 'administrator' ), Temporary::roles( 3 ) );
	}

	public function test_someone_elevated_for_an_hour_is_not_the_second_administrator(): void {
		$this->add_user( 1, array( 'administrator' ) );
		$this->add_user( 3, array( 'editor' ) );
		$this->lend( 3, 'administrator' );

		$this->assertSame( 1, Recommender::admin_count() );

		$this->add_user( 4, array( 'administrator' ) );
		$this->assertSame( 2, Recommender::admin_count() );
	}

	public function test_the_last_permanent_administrator_cannot_be_reduced_while_an_editor_is_elevated(): void {
		$this->add_user( 1, array( 'administrator' ), array( 'user_login' => 'boss' ) );
		$this->add_user( 3, array( 'editor' ), array( 'user_login' => 'ed' ) );
		$this->lend( 3, 'administrator' );
		$this->current_user = 3;

		$settings = new Settings();
		$store    = new Store();
		$rec      = new Recommender( $settings, $store );
		$applier  = new Applier( $settings, $rec, new Report( $settings, $rec, $store ), new Changes() );

		$refused = $applier->check_user( $this->users[1], 'editor' );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'role_right_sizer_last_admin', $refused->get_error_code() );
	}

	public function test_what_an_elevated_editor_did_with_the_lent_role_does_not_grow_their_recommendation(): void {
		$this->add_user( 3, array( 'editor' ) );
		$this->lend( 3, 'administrator' );
		$this->current_user = 3;
		$store              = new Store();
		$recorder           = new Recorder( $store, new Settings(), new HookMap() );

		$recorder->record( 'edit_others_posts', 3, 'screen' );
		$recorder->record( 'activate_plugins', 3, 'screen' );
		$recorder->flush();

		$this->assertArrayHasKey( 'edit_others_posts', $store->usage( 3 ) );
		$this->assertArrayNotHasKey( 'activate_plugins', $store->usage( 3 ), 'Used only through the lent role.' );
		$this->assertSame( array( 'activate_plugins' ), array_keys( $store->elevated_usage( 3 ) ) );
	}

	public function test_a_person_who_is_not_elevated_records_everything_as_before(): void {
		$this->add_user( 3, array( 'administrator' ) );
		$this->current_user = 3;
		$store              = new Store();
		$recorder           = new Recorder( $store, new Settings(), new HookMap() );

		$recorder->record( 'activate_plugins', 3, 'screen' );
		$recorder->flush();

		$this->assertArrayHasKey( 'activate_plugins', $store->usage( 3 ) );
		$this->assertSame( array(), $store->elevated_usage( 3 ) );
	}

	public function test_recommendations_look_at_the_permanent_role_only(): void {
		$this->add_user( 1, array( 'administrator' ) );
		$this->add_user( 3, array( 'editor' ) );
		$this->lend( 3, 'administrator' );
		$this->used( 3, array( 'edit_posts' => 2 ) );
		$this->active( 3, 1 );
		$settings = new Settings();
		$rec      = new Recommender( $settings, new Store() );

		$recommendation = $rec->for_user( $this->users[3], time(), 2 );

		$this->assertIsArray( $recommendation );
		$this->assertSame( array( 'editor' ), $recommendation['current'] );
		$this->assertNotContains( 'manage_options', $recommendation['lost'] );
	}

	public function test_changing_the_permanent_role_keeps_the_lent_one(): void {
		$this->add_user( 1, array( 'administrator' ) );
		$this->add_user( 3, array( 'editor' ) );
		$this->lend( 3, 'administrator' );
		$this->used( 3, array( 'edit_posts' => 2 ) );
		$this->active( 3, 1 );
		$this->current_user = 1;
		$settings           = new Settings();
		$store              = new Store();
		$rec                = new Recommender( $settings, $store );
		$applier            = new Applier( $settings, $rec, new Report( $settings, $rec, $store ), new Changes() );

		$change = $applier->apply_role( 3, 'author' );

		$this->assertIsArray( $change );
		$this->assertSame( array( 'editor' ), $change['before'], 'The log holds the permanent role, so Undo restores it.' );
		$this->assertContains( 'administrator', $this->users[3]->roles, 'The hour of access stays.' );
		$this->assertContains( 'author', $this->users[3]->roles );
		$this->assertNotContains( 'editor', $this->users[3]->roles );
	}
}
