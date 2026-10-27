<?php
/**
 * Tests for collecting capability use during a request and writing it at the end.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Tests\Unit;

use AryanX\Axle\RoleRightSizer\Settings;
use AryanX\Axle\RoleRightSizer\Usage\AdminScreens;
use AryanX\Axle\RoleRightSizer\Usage\HookMap;
use AryanX\Axle\RoleRightSizer\Usage\Recorder;
use AryanX\Axle\RoleRightSizer\Usage\RestWatcher;
use AryanX\Axle\RoleRightSizer\Usage\Store;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class RecorderTest extends PluginTestCase {

	private function recorder(): Recorder {
		return new Recorder( new Store(), new Settings(), new HookMap() );
	}

	protected function setUp(): void {
		parent::setUp();
		$this->add_user( 2, array( 'editor' ) );
		$this->current_user = 2;
	}

	public function test_the_hook_map_filter_decides_which_hooks_are_listened_to(): void {
		Filters\expectApplied( 'role_right_sizer_hook_capability_map' )->once()->andReturn(
			array(
				'woocommerce_new_order' => 'edit_shop_orders',
				''                      => 'nothing',
				'broken'                => 42,
			)
		);
		$recorder = $this->recorder();
		$recorder->listen();

		$this->assertNotFalse( has_filter( 'woocommerce_new_order' ) );
		$this->assertFalse( has_filter( 'broken' ) );
		$this->assertFalse( has_filter( 'save_post' ) );
	}

	public function test_a_mapped_hook_records_what_the_user_has_and_fires_the_action_once(): void {
		$recorder = $this->recorder();

		Actions\expectDone( 'role_right_sizer_capability_used' )->once()->with( 'moderate_comments', 2, 'transition_comment_status' );
		$recorder->from_hook( 'transition_comment_status', 'moderate_comments', array( 'approved', 'unapproved', null ) );
		$recorder->from_hook( 'transition_comment_status', 'moderate_comments', array( 'approved', 'unapproved', null ) );
		// Editors cannot activate plugins, so a hook that fires anyway proves nothing.
		$recorder->from_hook( 'activated_plugin', 'activate_plugins', array( 'x/x.php' ) );
		$recorder->from_hook(
			'custom',
			static function ( $first ) {
				return array( $first, 'level_7' );
			},
			array( 'edit_others_posts' )
		);

		$this->assertSame(
			array(
				2 => array(
					'moderate_comments' => 'transition_comment_status',
					'edit_others_posts' => 'custom',
				),
			),
			$recorder->pending()
		);
	}

	public function test_logged_out_requests_record_nothing(): void {
		$this->current_user = 0;
		$recorder           = $this->recorder();
		$recorder->from_hook( 'switch_theme', 'switch_themes', array() );

		$this->assertFalse( $recorder->record( 'edit_posts' ) );
		$this->assertSame( array(), $recorder->pending() );
	}

	public function test_flush_writes_once_with_the_interval_and_marks_the_user_active(): void {
		Filters\expectApplied( 'role_right_sizer_record_interval' )->once()->andReturn( 60 );
		$recorder = $this->recorder();
		$recorder->record( 'edit_posts', 0, 'screen' );
		$recorder->record_ability( 'site/do-thing' );
		$recorder->flush();

		$this->assertSame( array( 'edit_posts' ), array_keys( $this->meta[2]['wp_role_right_sizer_usage'] ) );
		$this->assertSame( array( 'site/do-thing' ), array_keys( $this->meta[2]['wp_role_right_sizer_abilities'] ) );
		$this->assertEqualsWithDelta( time(), $this->meta[2]['wp_role_right_sizer_last_active'], 2 );
		$this->assertSame( array(), $recorder->pending() );
	}

	public function test_login_is_saved(): void {
		$this->recorder()->login( 'user2', $this->users[2] );

		$this->assertEqualsWithDelta( time(), $this->meta[2]['role_right_sizer_last_login'], 2 );
	}

	public function test_admin_screens_need_the_capability_of_their_menu_entry(): void {
		$menu    = array(
			array( 'Posts', 'edit_posts', 'edit.php' ),
			array( 'Pages', 'edit_pages', 'edit.php?post_type=page' ),
			array( 'Settings', 'manage_options', 'options-general.php' ),
		);
		$submenu = array(
			'edit.php'            => array( array( 'All Posts', 'edit_posts', 'edit.php' ), array( 'Categories', 'manage_categories', 'edit-tags.php?taxonomy=category' ) ),
			'options-general.php' => array( array( 'General', 'manage_options', 'options-general.php' ), array( 'Privacy', 'manage_privacy_options', 'options-privacy.php' ) ),
			'tools.php'           => array( array( 'Tools', 'edit_posts', 'tools.php' ), array( 'Freeze', 'manage_options', 'freeze' ) ),
		);

		$this->assertSame( 'edit_posts', AdminScreens::required_capability( 'edit.php', 'edit.php', null, $menu, $submenu ) );
		$this->assertSame( 'edit_pages', AdminScreens::required_capability( 'edit.php?post_type=page', 'post-new.php', null, $menu, $submenu ) );
		$this->assertSame( 'manage_privacy_options', AdminScreens::required_capability( 'options-general.php', 'options-privacy.php', null, $menu, $submenu ) );
		$this->assertSame( 'manage_options', AdminScreens::required_capability( 'tools.php', 'tools.php', 'freeze', $menu, $submenu ) );
		$this->assertSame( '', AdminScreens::required_capability( '', 'about.php', null, $menu, $submenu ) );
	}

	public function test_the_screen_capability_filter_can_change_what_a_visit_counts_as(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'get_admin_page_parent' )->justReturn( 'edit.php' );
		$GLOBALS['pagenow'] = 'edit.php';
		$GLOBALS['menu']    = array( array( 'Posts', 'edit_posts', 'edit.php' ) );
		$GLOBALS['submenu'] = array();
		$recorder           = $this->recorder();

		Filters\expectApplied( 'role_right_sizer_screen_capability' )->once()->with( 'edit_posts', null )->andReturn( 'moderate_comments' );
		( new AdminScreens( $recorder ) )->visit();

		$this->assertSame( array( 2 => array( 'moderate_comments' => 'screen' ) ), $recorder->pending() );
		unset( $GLOBALS['pagenow'], $GLOBALS['menu'], $GLOBALS['submenu'] );
	}

	public function test_rest_requests_that_write_record_what_their_permission_check_needed(): void {
		$recorder = $this->recorder();
		$watcher  = new RestWatcher( $recorder );
		$user     = $this->users[2];
		$request  = new \Role_Right_Sizer_Test_Request( 'POST' );

		$watcher->open( null, array(), $request );
		$watcher->checked( array( 'edit_others_posts' => true, 'edit_published_posts' => true ), array( 'edit_others_posts', 'edit_published_posts' ), array(), $user );
		// A check that fails keeps nothing.
		$watcher->checked( array( 'manage_options' => false ), array( 'manage_options' ), array(), $user );
		$watcher->stop_listening( null );
		// Checks while the response is built are left out.
		$watcher->checked( array( 'unfiltered_html' => true ), array( 'unfiltered_html' ), array(), $user );
		$watcher->close( new \WP_HTTP_Response( null, 200 ), array(), $request );

		$this->assertSame( array( 'edit_others_posts', 'edit_published_posts' ), array_keys( $recorder->pending()[2] ) );
	}

	public function test_reads_and_failed_requests_record_nothing(): void {
		$recorder = $this->recorder();
		$watcher  = new RestWatcher( $recorder );
		$user     = $this->users[2];

		foreach ( array( array( 'GET', 200 ), array( 'POST', 403 ) ) as $case ) {
			$request = new \Role_Right_Sizer_Test_Request( $case[0] );
			$watcher->open( null, array(), $request );
			$watcher->checked( array( 'edit_posts' => true ), array( 'edit_posts' ), array(), $user );
			$watcher->close( new \WP_HTTP_Response( null, $case[1] ), array(), $request );
		}

		$request = new \Role_Right_Sizer_Test_Request( 'POST' );
		$watcher->open( new \WP_Error( 'nope' ), array(), $request );
		$watcher->checked( array( 'edit_posts' => true ), array( 'edit_posts' ), array(), $user );
		$watcher->close( new \WP_Error( 'nope' ), array(), $request );

		$this->assertSame( array(), $recorder->pending() );
	}

	public function test_ability_runs_are_recorded_unless_they_fail(): void {
		$recorder = $this->recorder();
		$watcher  = new RestWatcher( $recorder );
		$watcher->ability_ran( 'site/ok', null, array() );
		$watcher->ability_ran( 'site/failed', null, new \WP_Error( 'x' ) );
		$recorder->flush();

		$this->assertSame( array( 'site/ok' ), array_keys( $this->meta[2]['wp_role_right_sizer_abilities'] ) );
	}
}
