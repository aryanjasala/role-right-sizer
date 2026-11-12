<?php
/**
 * Tests for the privacy tools and the abilities.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Tests\Unit;

use AryanX\Axle\RoleRightSizer\Abilities;
use AryanX\Axle\RoleRightSizer\Applier;
use AryanX\Axle\RoleRightSizer\Changes;
use AryanX\Axle\RoleRightSizer\Privacy;
use AryanX\Axle\RoleRightSizer\Recommender;
use AryanX\Axle\RoleRightSizer\Report;
use AryanX\Axle\RoleRightSizer\Settings;
use AryanX\Axle\RoleRightSizer\Trials;
use AryanX\Axle\RoleRightSizer\Usage\Store;
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
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( 'translate_user_role' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( 'strval' );
	}

	/**
	 * The plugin's parts, wired like Plugin does.
	 *
	 * @return array{0: Abilities, 1: Privacy, 2: Changes, 3: Report, 4: Trials}
	 */
	private function parts(): array {
		$settings    = new Settings();
		$store       = new Store();
		$recommender = new Recommender( $settings, $store );
		$report      = new Report( $settings, $recommender, $store );
		$changes     = new Changes();
		$applier     = new Applier( $settings, $recommender, $report, $changes );

		$trials      = new Trials( $settings, $applier, $recommender, $report );

		return array( new Abilities( $settings, $store, $recommender, $report, $changes, $applier, $trials ), new Privacy( $store, $changes, $trials ), $changes, $report, $trials );
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

	public function test_the_abilities_are_registered_as_public_with_the_right_annotations(): void {
		list( $abilities ) = $this->parts();
		$abilities->register_abilities();

		$this->assertSame( array( 'role-right-sizer/get-recommendations', 'role-right-sizer/get-user-usage', 'role-right-sizer/apply-recommendation', 'role-right-sizer/undo-change', 'role-right-sizer/get-changes', 'role-right-sizer/list-trials', 'role-right-sizer/start-trial', 'role-right-sizer/restore-trial', 'role-right-sizer/extend-trial', 'role-right-sizer/keep-trial' ), array_keys( $this->registered ) );
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

	public function test_someone_who_may_not_edit_an_account_gets_no_details_about_it(): void {
		list( $abilities ) = $this->parts();
		$this->no_access = array( 5 );

		$this->assertSame( array(), $abilities->get_recommendations()['users'] );

		$usage = $abilities->get_user_usage( array( 'user' => 'pat' ) );
		$this->assertSame( 403, $usage->get_error_data()['status'] );

		$abilities->register_abilities();
		$this->assertFalse( call_user_func( $this->registered['role-right-sizer/get-user-usage']['permission_callback'], array( 'user' => 'pat' ) ) );
		$this->assertTrue( call_user_func( $this->registered['role-right-sizer/get-user-usage']['permission_callback'], array( 'user' => 'admin' ) ) );
		$this->assertFalse( call_user_func( $this->registered['role-right-sizer/apply-recommendation']['permission_callback'], array( 'user_id' => 5 ) ) );
	}

	public function test_the_change_log_ability_leaves_out_accounts_the_viewer_may_not_see(): void {
		list( $abilities ) = $this->parts();
		$abilities->apply_recommendation( array( 'user_id' => 5 ) );

		$this->assertCount( 1, $abilities->get_changes( array() )['changes'] );
		$this->assertSame( 'downgrade', $abilities->get_changes( array() )['changes'][0]['kind'] );

		$this->no_access = array( 5 );
		$this->assertSame( array(), $abilities->get_changes( array() )['changes'] );
	}

	public function test_an_agent_runs_a_trial_from_start_to_restore(): void {
		list( $abilities ) = $this->parts();

		$started = $abilities->start_trial( array( 'user_id' => 5, 'days' => 7 ) );
		$id      = $started['trial']['id'];
		$this->assertSame( 'active', $started['trial']['status'] );
		$this->assertSame( array( 'editor' ), $this->users[5]->roles );
		$this->assertSame( 7, $started['trial']['days_left'] );

		$this->assertCount( 1, $abilities->list_trials( array( 'status' => 'open' ) )['trials'] );
		$this->assertSame( array(), $abilities->list_trials( array( 'status' => 'kept' ) )['trials'] );
		$this->assertSame( 'role_right_sizer_trial_exists', $abilities->start_trial( array( 'user_id' => 5 ) )->get_error_code() );

		$extended = $abilities->extend_trial( array( 'trial_id' => $id, 'days' => 3 ) );
		$this->assertGreaterThanOrEqual( 10, $extended['trial']['days_left'] );

		$restored = $abilities->restore_trial( array( 'trial_id' => $id ) );
		$this->assertSame( 'restored', $restored['trial']['status'] );
		$this->assertSame( array( 'administrator' ), $this->users[5]->roles );
		$this->assertSame( 400, $abilities->keep_trial( array( 'trial_id' => $id ) )->get_error_data()['status'] );
	}

	public function test_trials_of_accounts_the_viewer_may_not_manage_stay_out_of_reach(): void {
		list( $abilities, , , , $trials ) = $this->parts();
		$trials->start( 5 );
		$abilities->register_abilities();

		$this->no_access = array( 5 );
		$this->assertSame( array(), $abilities->list_trials( array() )['trials'] );
		$this->assertFalse( call_user_func( $this->registered['role-right-sizer/start-trial']['permission_callback'], array( 'user_id' => 5 ) ) );
		$this->assertFalse( call_user_func( $this->registered['role-right-sizer/restore-trial']['permission_callback'], array( 'trial_id' => 1 ) ) );
		$this->assertFalse( call_user_func( $this->registered['role-right-sizer/keep-trial']['permission_callback'], array( 'trial_id' => 1 ) ) );
	}

	public function test_trial_abilities_have_the_right_annotations(): void {
		list( $abilities ) = $this->parts();
		$abilities->register_abilities();

		$annotations = static function ( array $registered, string $name ): array {
			return $registered[ 'role-right-sizer/' . $name ]['meta']['annotations'];
		};
		$this->assertTrue( $annotations( $this->registered, 'list-trials' )['readonly'] );
		$this->assertTrue( $annotations( $this->registered, 'get-changes' )['readonly'] );
		$this->assertTrue( $annotations( $this->registered, 'start-trial' )['destructive'] );
		$this->assertTrue( $annotations( $this->registered, 'restore-trial' )['destructive'] );
		$this->assertFalse( $annotations( $this->registered, 'extend-trial' )['destructive'] );
		$this->assertFalse( $annotations( $this->registered, 'keep-trial' )['readonly'] );
	}

	public function test_agent_governance_previews_trial_calls(): void {
		list( $abilities ) = $this->parts();
		Functions\when( 'get_edit_user_link' )->justReturn( 'https://example.com/wp-admin/user-edit.php?user_id=5' );
		$this->current_user = 0;
		$call               = static function ( string $name, array $input ): array {
			return array(
				'source' => 'ability',
				'target' => 'role-right-sizer/' . $name,
				'method' => 'POST',
				'kind'   => 'destructive',
				'input'  => $input,
			);
		};

		$start = $abilities->dry_run( null, $call( 'start-trial', array( 'user_id' => 5 ) ) );
		$this->assertStringContainsString( 'for 14 days', $start['summary'] );
		$this->assertSame( array( 'administrator' ), $this->users[5]->roles );

		$none = $abilities->dry_run( null, $call( 'start-trial', array( 'user_id' => 99 ) ) );
		$this->assertStringContainsString( 'will fail', $none['summary'] );

		$abilities->start_trial( array( 'user_id' => 5 ) );
		$restore = $abilities->dry_run( null, $call( 'restore-trial', array( 'trial_id' => 1 ) ) );
		$this->assertStringContainsString( 'old role back', $restore['summary'] );
		$this->assertSame( array( 'editor' ), $this->users[5]->roles );
	}

	public function test_the_exporter_and_eraser_cover_trials(): void {
		list( $abilities, $privacy, , , $trials ) = $this->parts();
		$this->add_user( 1, array( 'administrator' ), array( 'user_login' => 'admin', 'user_email' => 'admin@example.com' ) );
		$this->users[5]->user_email = 'pat@example.com';
		Functions\when( 'get_user_by' )->alias(
			function ( $field, $value ) {
				foreach ( $this->users as $user ) {
					if ( 'email' === $field && $user->user_email === $value ) {
						return $user;
					}
				}
				return false;
			}
		);
		$trial = $trials->start( 5 );
		$trials->add_denials( $trial['id'], array( array( 'key' => 'screen:plugins.php', 'kind' => 'screen', 'label' => 'Tried to open Plugins', 'cap' => 'activate_plugins' ) ) );

		$export = $privacy->export( 'pat@example.com' );
		$groups = array_column( $export['data'], 'group_id' );
		$this->assertContains( 'role-right-sizer-trials', $groups );
		$values = array();
		foreach ( $export['data'] as $item ) {
			foreach ( $item['data'] as $row ) {
				$values[] = $row['value'];
			}
		}
		$this->assertContains( 'Tried to open Plugins (1 time)', $values );

		$privacy->erase( 'pat@example.com' );
		$this->assertSame( array(), $trials->all() );
		$this->assertNotNull( $abilities );
	}
}
