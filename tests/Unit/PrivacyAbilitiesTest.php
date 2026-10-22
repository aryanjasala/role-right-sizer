<?php
/**
 * Tests for the privacy tools and the abilities.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer\Tests\Unit;

use AryanX\RoleRightSizer\Abilities;
use AryanX\RoleRightSizer\Applier;
use AryanX\RoleRightSizer\Changes;
use AryanX\RoleRightSizer\Privacy;
use AryanX\RoleRightSizer\Recommender;
use AryanX\RoleRightSizer\Report;
use AryanX\RoleRightSizer\Settings;
use AryanX\RoleRightSizer\Usage\Store;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

final class PrivacyAbilitiesTest extends PluginTestCase {

	/**
	 * Abilities registered in the test: name => arguments.
	 *
	 * @var array<string, array>
	 */
	private $registered = array();

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

		Functions\when( 'get_user_by' )->alias(
			function ( $field, $value ) {
				foreach ( $this->users as $user ) {
					if ( ( 'login' === $field && $user->user_login === $value ) || ( 'email' === $field && $user->user_login . '@example.com' === $value ) ) {
						return $user;
					}
				}
				return false;
			}
		);
		Functions\when( 'sanitize_key' )->returnArg();
		$this->registered = array();
		Functions\when( 'wp_register_ability' )->alias(
			function ( $name, $args ) {
				$this->registered[ $name ] = $args;
				return null;
			}
		);
		Functions\when( 'wp_register_ability_category' )->justReturn( null );
	}

	/**
	 * The plugin's parts, wired like Plugin does.
	 *
	 * @return array{0: Abilities, 1: Privacy, 2: Changes, 3: Report}
	 */
	private function parts(): array {
		$settings    = new Settings();
		$store       = new Store();
		$recommender = new Recommender( $settings, $store );
		$report      = new Report( $settings, $recommender, $store );
		$changes     = new Changes();
		$applier     = new Applier( $settings, $recommender, $report, $changes );

		return array( new Abilities( $settings, $store, $recommender, $report, $changes, $applier ), new Privacy( $store, $changes ), $changes, $report );
	}

	public function test_the_exporter_covers_activity_usage_and_changes(): void {
		list( $abilities, $privacy ) = $this->parts();
		$abilities->apply_recommendation( array( 'user_id' => 5 ) );

		$groups = array_column( $privacy->export( 'pat@example.com' )['data'], 'group_id' );
		$this->assertSame( array( 'role-right-sizer-activity', 'role-right-sizer-usage', 'role-right-sizer-changes' ), array_values( array_unique( $groups ) ) );
		$this->assertSame( array(), $privacy->export( 'nobody@example.com' )['data'] );
	}

	public function test_the_eraser_deletes_usage_and_changes_and_fires_the_action(): void {
		list( $abilities, $privacy, $changes ) = $this->parts();
		$abilities->apply_recommendation( array( 'user_id' => 5 ) );

		Actions\expectDone( 'role_right_sizer_usage_forgotten' )->once()->with( 5 );
		$this->assertSame(
			array(
				'items_removed'  => true,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			$privacy->erase( 'pat@example.com' )
		);
		$this->assertArrayNotHasKey( 'wp_role_right_sizer_usage', $this->meta[5] );
		$this->assertSame( array(), $changes->all() );
	}

	public function test_four_abilities_are_registered_as_public_with_the_right_annotations(): void {
		list( $abilities ) = $this->parts();
		$abilities->register_abilities();

		$this->assertSame( array( 'role-right-sizer/get-recommendations', 'role-right-sizer/get-user-usage', 'role-right-sizer/apply-recommendation', 'role-right-sizer/undo-change' ), array_keys( $this->registered ) );
		foreach ( $this->registered as $args ) {
			$this->assertTrue( $args['meta']['public'] );
			$this->assertTrue( $args['meta']['mcp']['public'] );
			$this->assertTrue( $args['meta']['show_in_rest'] );
		}
		$this->assertTrue( $this->registered['role-right-sizer/get-recommendations']['meta']['annotations']['readonly'] );
		$this->assertTrue( $this->registered['role-right-sizer/apply-recommendation']['meta']['annotations']['destructive'] );
		$this->assertFalse( $this->registered['role-right-sizer/undo-change']['meta']['annotations']['idempotent'] );

		$this->assertTrue( call_user_func( $this->registered['role-right-sizer/undo-change']['permission_callback'] ) );
		$this->current_user = 5;
		$this->users[5]->roles = array( 'editor' );
		$this->assertFalse( call_user_func( $this->registered['role-right-sizer/undo-change']['permission_callback'] ) );
	}

	public function test_an_agent_reads_applies_and_undoes(): void {
		list( $abilities ) = $this->parts();

		$recommendations = $abilities->get_recommendations();
		$this->assertFalse( $recommendations['learning'] );
		$this->assertSame( 'pat', $recommendations['users'][0]['login'] );
		$this->assertSame( 'editor', $recommendations['users'][0]['proposed'] );

		$usage = $abilities->get_user_usage( array( 'user' => 'pat' ) );
		$this->assertSame( 'edit_others_posts', $usage['capabilities'][0]['name'] );
		$this->assertTrue( $usage['capabilities'][0]['in_window'] );
		$this->assertSame( 404, $abilities->get_user_usage( array( 'user' => 'ghost' ) )->get_error_data()['status'] );

		$this->assertSame( 400, $abilities->apply_recommendation( array() )->get_error_data()['status'] );
		$this->assertSame( 'role_right_sizer_self', $abilities->apply_recommendation( array( 'user_id' => 1 ) )->get_error_code() );

		$applied = $abilities->apply_recommendation( array( 'user_id' => 5 ) );
		$this->assertSame( array( 'editor' ), $this->users[5]->roles );

		$undone = $abilities->undo_change(
			array(
				'change_id' => $applied['changes'][0]['id'],
				'batch'     => true,
			)
		);
		$this->assertCount( 1, $undone['undone'] );
		$this->assertSame( array( 'administrator' ), $this->users[5]->roles );
		$this->assertSame( 'role_right_sizer_undone', $abilities->undo_change( array( 'change_id' => $applied['changes'][0]['id'] ) )->get_error_code() );
	}

	public function test_while_learning_the_recommendations_are_empty(): void {
		$this->tracking_started( 1 );
		list( $abilities ) = $this->parts();

		$recommendations = $abilities->get_recommendations();
		$this->assertTrue( $recommendations['learning'] );
		$this->assertSame( array(), $recommendations['users'] );
	}
}
