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

	public function test_unused_capabilities_say_who_they_belong_to_for_agents(): void {
		list( $abilities ) = $this->parts();
		$this->options[ Report::OPTION ] = array(
			'format' => Report::FORMAT,
			'status' => 'done',
			'built'  => time(),
			'roles'  => array(
				array( 'role' => 'shop_editor', 'cap' => 'manage_woocommerce', 'users' => 1, 'owner' => array( 'type' => 'plugin', 'id' => 'woocommerce/woocommerce.php', 'name' => 'WooCommerce', 'active' => true ) ),
				array( 'role' => 'shop_editor', 'cap' => 'old_cap', 'users' => 1, 'owner' => null ),
			),
		);

		$this->assertSame(
			array(
				array( 'role' => 'shop_editor', 'cap' => 'manage_woocommerce', 'users' => 1, 'owner' => 'WooCommerce', 'owner_active' => true ),
				array( 'role' => 'shop_editor', 'cap' => 'old_cap', 'users' => 1, 'owner' => '', 'owner_active' => false ),
			),
			$abilities->get_recommendations()['roles']
		);
	}

	public function test_agent_governance_gets_a_preview_of_a_held_call(): void {
		Functions\when( 'number_format_i18n' )->alias( 'strval' );
		Functions\when( 'get_edit_user_link' )->justReturn( 'https://example.com/wp-admin/user-edit.php?user_id=5' );
		Functions\when( 'translate_user_role' )->returnArg();
		list( $abilities ) = $this->parts();
		$this->current_user = 0;
		$call = static function ( string $name, array $input ): array {
			return array(
				'source' => 'ability',
				'target' => 'role-right-sizer/' . $name,
				'method' => 'POST',
				'kind'   => 'destructive',
				'input'  => $input,
			);
		};

		$preview = $abilities->dry_run( null, $call( 'apply-recommendation', array( 'user_id' => 5 ) ) );
		$this->assertStringStartsWith( 'Moves User 5 (pat) from Administrator to Editor.', $preview['summary'] );
		$this->assertSame( array( 'field' => 'Role', 'before' => 'Administrator', 'after' => 'Editor' ), $preview['changes'][0] );
		$this->assertStringContainsString( 'manage_options', $preview['changes'][1]['after'] );
		$this->assertSame( 5, $preview['object']['id'] );

		$this->assertStringContainsString( 'never removed from the administrator role', $abilities->dry_run( null, $call( 'apply-recommendation', array( 'role' => 'administrator', 'capability' => 'x' ) ) )['summary'] );
		$this->assertSame( 'Removes old_cap from the shop_editor role, for everyone with the role. Undo puts it back.', $abilities->dry_run( null, $call( 'apply-recommendation', array( 'role' => 'shop_editor', 'capability' => 'old_cap' ) ) )['summary'] );

		// Nothing changed while previewing.
		$this->assertSame( array( 'administrator' ), $this->users[5]->roles );

		$this->current_user = 1;
		$applied = $abilities->apply_recommendation( array( 'user_id' => 5 ) );
		$undo    = $abilities->dry_run( null, $call( 'undo-change', array( 'change_id' => $applied['changes'][0]['id'] ) ) );
		$this->assertSame( 'Undoes 1 Role Right-Sizer change and puts back what it took away.', $undo['summary'] );
		$this->assertSame( array( 'field' => 'Role of User 5', 'before' => 'Editor', 'after' => 'Administrator' ), $undo['changes'][0] );
		$this->assertStringContainsString( 'not in the log', $abilities->dry_run( null, $call( 'undo-change', array( 'change_id' => 999 ) ) )['summary'] );

		// Other calls, and previews another plugin already made, are left alone.
		$this->assertNull( $abilities->dry_run( null, $call( 'get-recommendations', array() ) ) );
		$this->assertSame( array( 'summary' => 'Theirs' ), $abilities->dry_run( array( 'summary' => 'Theirs' ), $call( 'undo-change', array( 'change_id' => 1 ) ) ) );
		$this->assertNull( $abilities->dry_run( null, array( 'source' => 'rest', 'target' => 'role-right-sizer/undo-change' ) ) );
	}
}
