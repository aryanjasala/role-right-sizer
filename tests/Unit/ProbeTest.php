<?php
/**
 * Tests for "Why can't they?": what may be checked, the one-request cookie, the verdict and the fixes.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Tests\Unit;

use AryanX\Axle\RoleRightSizer\Applier;
use AryanX\Axle\RoleRightSizer\Changes;
use AryanX\Axle\RoleRightSizer\Probe\Prober;
use AryanX\Axle\RoleRightSizer\Probe\Responder;
use AryanX\Axle\RoleRightSizer\Recommender;
use AryanX\Axle\RoleRightSizer\Report;
use AryanX\Axle\RoleRightSizer\Settings;
use AryanX\Axle\RoleRightSizer\Usage\Store;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class ProbeTest extends PluginTestCase {

	/**
	 * Transients, by name.
	 *
	 * @var array<string, mixed>
	 */
	private $transients = array();

	protected function setUp(): void {
		parent::setUp();
		$this->transients = array();
		$this->tracking_started( 60 );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'rest_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-json/' . $path;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( 'get_editable_roles' )->alias(
			function () {
				return $this->roles;
			}
		);
		$this->add_user( 1, array( 'administrator' ), array( 'user_login' => 'boss' ) );
		$this->add_user( 8, array( 'subscriber' ), array( 'user_login' => 'sub', 'display_name' => 'Sam' ) );
		$this->current_user = 1;
	}

	private function applier(): Applier {
		$settings = new Settings();
		$store    = new Store();
		$rec      = new Recommender( $settings, $store );

		return new Applier( $settings, $rec, new Report( $settings, $rec, $store ), new Changes() );
	}

	public function test_only_admin_screens_and_rest_routes_of_this_site_can_be_named(): void {
		$screen = Prober::address( 'screen', 'upload.php' );
		$this->assertSame( 'https://example.com/wp-admin/upload.php', $screen['url'] );
		$this->assertSame( 'https://example.com/wp-admin/admin.php?page=my-plugin', Prober::address( 'screen', '/wp-admin/admin.php?page=my-plugin' )['url'] );
		$this->assertSame( 'https://example.com/wp-json/wp/v2/media', Prober::address( 'rest', '/wp/v2/media' )['url'] );

		foreach ( array(
			array( 'screen', 'https://evil.example/wp-admin/upload.php' ),
			array( 'screen', '//evil.example/x.php' ),
			array( 'screen', '../wp-login.php' ),
			array( 'screen', 'upload.php?x=<script>' ),
			array( 'screen', 'readme.txt' ),
			array( 'rest', 'https://evil.example/wp-json/wp/v2/media' ),
			array( 'rest', '/wp/v2/../../x' ),
			array( 'rest', 'wp/v2/media' ),
			array( 'file', 'upload.php' ),
		) as $case ) {
			$result = Prober::address( $case[0], $case[1] );
			$this->assertInstanceOf( \WP_Error::class, $result, $case[1] );
			$this->assertSame( 'role_right_sizer_probe_target', $result->get_error_code(), $case[1] );
		}
	}

	public function test_files_that_act_on_a_plain_request_are_refused(): void {
		foreach ( array( 'admin-ajax.php', 'admin-post.php?action=x', 'update.php?action=install-plugin', 'export.php?download=true' ) as $file ) {
			$result = Prober::address( 'screen', $file );
			$this->assertSame( 'role_right_sizer_probe_blocked', $result->get_error_code(), $file );
		}

		Filters\expectApplied( 'role_right_sizer_probe_blocked_screens' )->once()->andReturn( array( 'tools.php' ) );
		$this->assertSame( 'role_right_sizer_probe_blocked', Prober::address( 'screen', 'tools.php' )->get_error_code() );
	}

	public function test_only_someone_who_may_edit_the_person_can_check_them(): void {
		$this->assertTrue( Prober::can_check( 8 ) );

		$this->no_access = array( 8 );
		$this->assertFalse( Prober::can_check( 8 ) );

		$this->no_access    = array();
		$this->current_user = 8;
		$this->assertFalse( Prober::can_check( 1 ), 'A subscriber may not check an administrator.' );
	}

	public function test_a_person_who_may_not_be_checked_gets_no_request_at_all(): void {
		$this->no_access = array( 8 );

		$result = ( new Prober() )->run( 8, 'rest', '/wp/v2/media', 'POST' );

		$this->assertSame( 'role_right_sizer_forbidden', $result->get_error_code() );
		$this->assertSame( array(), $this->transients, 'No probe was prepared.' );
	}

	public function test_the_probe_cookie_is_signed_fresh_and_works_once(): void {
		$expires = time() + 120;
		$value   = Prober::sign( 'abc123abc123', 8, $expires, 'rest', '/wp/v2/media' );

		$read = Prober::read( $value );
		$this->assertSame( 8, $read['user'] );
		$this->assertSame( 'rest', $read['kind'] );

		$this->assertNull( Prober::read( str_replace( '|8|', '|1|', $value ) ), 'Swapping the person breaks the signature.' );
		$this->assertNull( Prober::read( substr( $value, 0, -2 ) . 'zz' ) );
		$this->assertNull( Prober::read( Prober::sign( 'abc123abc123', 8, time() - 1, 'rest', 'x' ) ), 'Expired.' );
		$this->assertNull( Prober::read( 'junk' ) );

		$this->transients['role_right_sizer_probe_used_abc123abc123'] = 1;
		$this->assertTrue( Prober::claim( 'abc123abc123' ) );
		$this->assertFalse( Prober::claim( 'abc123abc123' ), 'The second request finds nothing.' );
		$this->assertFalse( Prober::claim( 'neverissued1' ) );
	}

	public function test_the_responder_does_nothing_for_a_cookie_that_belongs_to_someone_else(): void {
		$_COOKIE[ Prober::COOKIE ] = Prober::sign( 'abc123abc123', 8, time() + 120, 'rest', '/x' );
		$this->transients['role_right_sizer_probe_used_abc123abc123'] = 1;
		$this->current_user = 1;

		( new Responder() )->arm();

		$this->assertArrayHasKey( 'role_right_sizer_probe_used_abc123abc123', $this->transients, 'Not used up: the request is not the person it names.' );
		$this->assertFalse( has_filter( 'user_has_cap' ) );
		unset( $_COOKIE[ Prober::COOKIE ] );
	}

	public function test_the_responder_notes_failed_checks_and_the_latest_is_last(): void {
		$_COOKIE[ Prober::COOKIE ] = Prober::sign( 'abc123abc123', 8, time() + 120, 'rest', '/x' );
		$this->transients['role_right_sizer_probe_used_abc123abc123'] = 1;
		$this->current_user = 8;
		$responder          = new Responder();
		$responder->arm();

		$sam = $this->users[8];
		$responder->checked( array( 'read' => true ), array( 'upload_files' ), array( 'upload_files' ), $sam );
		$responder->checked( array( 'read' => true ), array( 'edit_posts' ), array( 'edit_post', 5 ), $sam );
		$responder->checked( array( 'read' => true ), array( 'upload_files' ), array( 'upload_files' ), $sam );
		$responder->checked( array( 'read' => true ), array( 'read' ), array( 'read' ), $sam );
		$responder->checked( array( 'read' => true ), array( 'edit_users' ), array( 'edit_users' ), $this->users[1] );
		$responder->save();

		$seen = $this->transients['role_right_sizer_probe_seen_abc123abc123'];
		$this->assertSame( array( 'edit_posts', 'upload_files' ), array_column( $seen, 'cap' ) );
		$this->assertSame( 'edit_post', $seen[0]['requested'] );
		unset( $_COOKIE[ Prober::COOKIE ] );
	}

	public function test_checks_made_after_the_refusal_are_not_blamed(): void {
		$_COOKIE[ Prober::COOKIE ] = Prober::sign( 'abc123abc123', 8, time() + 120, 'screen', '/x' );
		$this->transients['role_right_sizer_probe_used_abc123abc123'] = 1;
		$this->current_user = 8;
		$responder          = new Responder();
		$responder->arm();
		$sam = $this->users[8];

		$responder->checked( array( 'read' => true ), array( 'activate_plugins' ), array( 'activate_plugins' ), $sam );
		$this->assertSame( 'handler', $responder->freeze( 'handler' ), 'The die handler is handed back untouched.' );
		$responder->checked( array( 'read' => true ), array( 'manage_options' ), array( 'manage_options' ), $sam );
		$responder->save();

		$this->assertSame( array( 'activate_plugins' ), array_column( $this->transients['role_right_sizer_probe_seen_abc123abc123'], 'cap' ) );
		unset( $_COOKIE[ Prober::COOKIE ] );
	}

	public function test_a_rest_route_is_answered_after_its_permission_check_without_running(): void {
		$_COOKIE[ Prober::COOKIE ] = Prober::sign( 'abc123abc123', 8, time() + 120, 'rest', '/x' );
		$this->transients['role_right_sizer_probe_used_abc123abc123'] = 1;
		$this->current_user = 8;
		$responder          = new Responder();
		$responder->arm();

		$answer = $responder->dry_run( null );
		$this->assertInstanceOf( \WP_REST_Response::class, $answer );
		$this->assertSame( array( 'role_right_sizer_probe' => 'permitted' ), $answer->get_data() );
		$this->assertSame( 'already answered', $responder->dry_run( 'already answered' ) );
		unset( $_COOKIE[ Prober::COOKIE ] );
	}

	public function test_the_verdict_names_the_last_refused_capability(): void {
		$about  = array( 'user_id' => 8, 'kind' => 'rest', 'target' => '/wp/v2/media', 'method' => 'POST' );
		$failed = array( array( 'requested' => 'edit_posts', 'cap' => 'edit_posts' ), array( 'requested' => 'upload_files', 'cap' => 'upload_files' ) );

		$refused = Prober::summarise( $about, 403, '', $failed );
		$this->assertSame( 'refused', $refused['verdict'] );
		$this->assertSame( 'upload_files', $refused['cap'] );
		$this->assertFalse( $refused['outright'] );

		$allowed = Prober::summarise( $about, 200, '', array(), '{"role_right_sizer_probe":"permitted"}' );
		$this->assertSame( 'allowed', $allowed['verdict'] );
		$this->assertSame( '', $allowed['cap'] );

		$answered = Prober::summarise( $about, 200, '', array(), '{"id":5}' );
		$this->assertSame( 'error', $answered['verdict'], 'The route ran its own code, so the permission step was not what answered.' );

		$screen = array( 'user_id' => 8, 'kind' => 'screen', 'target' => 'upload.php', 'method' => 'GET' );
		$this->assertSame( 'allowed', Prober::summarise( $screen, 200, '', array() )['verdict'] );
		$this->assertSame( 'missing', Prober::summarise( $screen, 404, '', array() )['verdict'] );
		$this->assertSame( 'wp-login.php', Prober::summarise( $screen, 302, 'https://example.com/wp-login.php?x=1', array() )['location_path'] );
		$this->assertSame( 'error', Prober::summarise( $screen, 500, '', array() )['verdict'] );
	}

	public function test_an_outright_refusal_and_a_refusal_without_a_capability_say_so(): void {
		$about = array( 'user_id' => 8, 'kind' => 'screen', 'target' => 'post.php', 'method' => 'GET' );

		$outright = Prober::summarise( $about, 403, '', array( array( 'requested' => 'edit_post', 'cap' => 'do_not_allow' ) ) );
		$this->assertTrue( $outright['outright'] );
		$this->assertSame( '', $outright['cap'] );

		$plain = Prober::summarise( $about, 403, '', array() );
		$this->assertSame( 'refused', $plain['verdict'] );
		$this->assertSame( '', $plain['cap'] );
		$this->assertFalse( $plain['outright'] );
	}

	public function test_roles_with_a_capability_are_listed_smallest_first(): void {
		$this->assertSame( array( 'author', 'editor', 'administrator' ), Prober::roles_with( 'upload_files' ) );
		$this->assertSame( array(), Prober::roles_with( 'no_such_cap' ) );
	}

	public function test_the_smallest_fixes_are_the_capability_alone_and_the_smallest_role_that_has_it(): void {
		$fixes = Prober::fixes( $this->users[8], 'upload_files' );

		$this->assertSame( 'cap', $fixes[0]['type'] );
		$this->assertSame( 'upload_files', $fixes[0]['value'] );
		$this->assertSame( 'role', $fixes[1]['type'] );
		$this->assertSame( 'author', $fixes[1]['value'] );
		$this->assertContains( 'publish_posts', $fixes[1]['gained'] );
	}

	public function test_nobody_is_offered_a_fix_they_could_not_hand_out_themselves(): void {
		// An editor may assign roles up to editor, and holds upload_files and not install_plugins.
		$this->add_user( 4, array( 'editor' ) );
		$this->current_user = 4;
		Functions\when( 'get_editable_roles' )->alias(
			function () {
				return array_diff_key( $this->roles, array( 'administrator' => 1 ) );
			}
		);

		$fixes = Prober::fixes( $this->users[8], 'install_plugins' );
		$this->assertSame( array(), $fixes, 'They lack the capability and may not give the one role that has it.' );

		$this->current_user = 8;
		$this->assertSame( array(), Prober::fixes( $this->users[8], 'upload_files' ), 'Nothing is offered for your own account.' );
	}

	public function test_core_lets_promote_users_hand_out_administrator_but_this_never_does(): void {
		$this->add_user( 4, array( 'editor' ) );
		$this->current_user = 4;
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_editable_roles' )->alias(
			function () {
				return $this->roles;
			}
		);

		$offered = Prober::editable_roles();

		$this->assertContains( 'author', $offered );
		$this->assertContains( 'editor', $offered );
		$this->assertNotContains( 'administrator', $offered, 'An editor does not hold the administrator capabilities.' );
		$this->assertSame( array(), Prober::fixes( $this->users[8], 'install_plugins' ) );
		$this->assertInstanceOf( \WP_Error::class, $this->applier()->apply_fix( 8, 'role', 'administrator' ) );
		$this->assertSame( array( 'subscriber' ), $this->users[8]->roles );
	}

	public function test_giving_one_capability_is_logged_and_undo_takes_it_away(): void {
		$applier = $this->applier();
		$this->users[8]->caps = array( 'subscriber' => true );
		$calls                = array();
		$this->users[8]->roles = array( 'subscriber' );
		Functions\when( 'get_userdata' )->alias(
			function ( $id ) use ( &$calls ) {
				$user = $this->users[ (int) $id ] ?? false;
				if ( $user && 8 === (int) $id ) {
					$user = new class( $user ) extends \WP_User {
						public $log;
						public function __construct( $base ) {
							foreach ( get_object_vars( $base ) as $k => $v ) {
								$this->$k = $v;
							}
						}
						public function add_cap( $cap, $grant = true ) {
							$GLOBALS['rrs_cap_calls'][] = array( 'add', $cap );
						}
						public function remove_cap( $cap ) {
							$GLOBALS['rrs_cap_calls'][] = array( 'remove', $cap );
						}
					};
				}
				return $user;
			}
		);
		$GLOBALS['rrs_cap_calls'] = array();

		$change = $applier->apply_fix( 8, 'cap', 'upload_files' );

		$this->assertSame( 'fix_cap', $change['kind'] );
		$this->assertSame( 'upload_files', $change['cap'] );
		$this->assertSame( array( array( 'add', 'upload_files' ) ), $GLOBALS['rrs_cap_calls'] );

		$undone = $applier->undo( $change['id'] );
		$this->assertIsArray( $undone );
		$this->assertSame( array( array( 'add', 'upload_files' ), array( 'remove', 'upload_files' ) ), $GLOBALS['rrs_cap_calls'] );
		unset( $GLOBALS['rrs_cap_calls'] );
	}

	public function test_giving_a_role_adds_it_on_top_and_undo_restores_the_old_roles(): void {
		$applier = $this->applier();

		$change = $applier->apply_fix( 8, 'role', 'author' );

		$this->assertSame( 'fix_role', $change['kind'] );
		$this->assertSame( array( 'subscriber' ), $change['before'] );
		$this->assertSame( array( 'subscriber', 'author' ), $change['after'] );
		$this->assertSame( array( 'subscriber', 'author' ), $this->users[8]->roles );
		$this->assertContains( 'publish_posts', $change['gained'] );

		$applier->undo( $change['id'] );
		$this->assertSame( array( 'subscriber' ), $this->users[8]->roles );
	}

	public function test_a_fix_is_refused_for_your_own_account_for_a_role_you_may_not_give_and_for_a_capability_you_lack(): void {
		$applier = $this->applier();

		$this->current_user = 8;
		$this->assertSame( 'role_right_sizer_self', $applier->apply_fix( 8, 'role', 'author' )->get_error_code() );

		$this->add_user( 4, array( 'editor' ), array( 'user_login' => 'ed' ) );
		$this->current_user = 4;
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				return in_array( $cap, array( 'promote_user', 'upload_files' ), true );
			}
		);
		Functions\when( 'get_editable_roles' )->alias(
			function () {
				return array_diff_key( $this->roles, array( 'administrator' => 1 ) );
			}
		);
		$this->assertSame( 'role_right_sizer_fix_role', $applier->apply_fix( 8, 'role', 'administrator' )->get_error_code() );
		$this->assertSame( 'role_right_sizer_fix_cap', $applier->apply_fix( 8, 'cap', 'install_plugins' )->get_error_code() );
		$this->assertSame( 'role_right_sizer_fix_cap', $applier->apply_fix( 8, 'cap', 'author' )->get_error_code(), 'A role name is not a capability.' );
		$this->assertSame( 'role_right_sizer_fix_type', $applier->apply_fix( 8, 'nothing', 'x' )->get_error_code() );
		$this->assertSame( array( 'subscriber' ), $this->users[8]->roles );
	}

	public function test_a_person_on_a_role_trial_is_left_alone(): void {
		$this->meta[8]['wp_role_right_sizer_trial'] = 4;

		$this->assertSame( 'role_right_sizer_trial_exists', $this->applier()->apply_fix( 8, 'role', 'author' )->get_error_code() );
	}
}
