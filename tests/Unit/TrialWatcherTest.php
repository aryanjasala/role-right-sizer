<?php
/**
 * Tests for what a person on trial could not do: which refusals count, and which do not.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Tests\Unit;

use AryanX\Axle\RoleRightSizer\Access;
use AryanX\Axle\RoleRightSizer\Applier;
use AryanX\Axle\RoleRightSizer\Changes;
use AryanX\Axle\RoleRightSizer\Recommender;
use AryanX\Axle\RoleRightSizer\Report;
use AryanX\Axle\RoleRightSizer\Settings;
use AryanX\Axle\RoleRightSizer\Trial\Watcher;
use AryanX\Axle\RoleRightSizer\Trials;
use AryanX\Axle\RoleRightSizer\Usage\Store;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * A REST response with a status, for the watcher.
 */
class TrialTestResponse extends \WP_REST_Response {

	/**
	 * Status.
	 *
	 * @var int
	 */
	private $status;

	public function __construct( $data, int $status ) {
		parent::__construct( $data );
		$this->status = $status;
	}

	public function get_status() {
		return $this->status;
	}
}

/**
 * A REST request with a method and a route, for the watcher.
 */
class TrialTestRequest extends \WP_REST_Request {

	/**
	 * Method.
	 *
	 * @var string
	 */
	private $method;

	/**
	 * Route.
	 *
	 * @var string
	 */
	private $route;

	public function __construct( string $method, string $route ) {
		parent::__construct();
		$this->method = $method;
		$this->route  = $route;
	}

	public function get_method() {
		return $this->method;
	}

	public function get_route() {
		return $this->route;
	}
}

final class TrialWatcherTest extends PluginTestCase {

	/**
	 * The trials.
	 *
	 * @var Trials
	 */
	private $trials;

	/**
	 * The trial that was started.
	 *
	 * @var array
	 */
	private $trial;

	protected function setUp(): void {
		parent::setUp();
		$this->tracking_started( 60 );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'human_time_diff' )->justReturn( '3 days' );
		Functions\when( 'wp_date' )->justReturn( '3 Nov 2026' );
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'translate_user_role' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( 'strval' );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/' );
		Functions\when( 'wp_nonce_field' )->justReturn( '<input type="hidden" name="_wpnonce" value="abc">' );
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);

		$settings     = new Settings();
		$store        = new Store();
		$recommender  = new Recommender( $settings, $store );
		$report       = new Report( $settings, $recommender, $store );
		$applier      = new Applier( $settings, $recommender, $report, new Changes() );
		$this->trials = new Trials( $settings, $applier, $recommender, $report );

		$this->add_user( 1, array( 'administrator' ), array( 'user_login' => 'admin' ) );
		$this->used( 1, array( 'manage_options' => 1 ) );
		$this->active( 1, 1 );
		$this->add_user( 5, array( 'administrator' ), array( 'user_login' => 'pat' ) );
		$this->used( 5, array( 'edit_others_posts' => 1 ) );
		$this->active( 5, 1 );

		$this->current_user = 1;
		$this->trial        = $this->trials->start( 5 );
		$this->current_user = 5;
	}

	private function watcher(): Watcher {
		$watcher = new Watcher( $this->trials );
		$watcher->arm();

		return $watcher;
	}

	/**
	 * The user_has_cap filter, as WordPress calls it for a failed check.
	 *
	 * @param Watcher $watcher  The watcher.
	 * @param string  $cap      Primitive capability that failed.
	 * @param string  $meta     Capability that was asked for.
	 * @param int     $user_id  User.
	 */
	private function refuse( Watcher $watcher, string $cap, string $meta, int $user_id = 5 ): void {
		$watcher->checked( array( 'read' => true ), array( $cap ), array( $meta, $user_id ), $this->users[ $user_id ] );
	}

	public function test_only_a_person_on_trial_is_watched(): void {
		$this->current_user = 1;
		$watcher            = new Watcher( $this->trials );
		$watcher->arm();
		$this->refuse( $watcher, 'edit_others_pages', 'edit_post', 1 );
		$watcher->died( 'Sorry.', array( 'response' => 403 ) );
		$watcher->flush();

		$this->assertSame( array(), $this->trials->get( $this->trial['id'] )['denials'] );
	}

	public function test_a_refused_screen_is_recorded_with_its_name(): void {
		global $pagenow, $plugin_page;
		$pagenow     = 'plugins.php';
		$plugin_page = null;

		$watcher = $this->watcher();
		$this->refuse( $watcher, 'activate_plugins', 'activate_plugins' );
		$watcher->screen_denied();
		$watcher->screen_denied();
		$watcher->flush();

		$denials = $this->trials->get( $this->trial['id'] )['denials'];
		$this->assertCount( 1, $denials );
		$this->assertSame( 'Tried to open Plugins', $denials['screen:plugins.php']['label'] );
		$this->assertSame( 'activate_plugins', $denials['screen:plugins.php']['cap'] );
		$this->assertSame( 1, $denials['screen:plugins.php']['count'] );
	}

	public function test_a_permission_refusal_is_recorded_with_what_the_person_could_not_do(): void {
		$watcher = $this->watcher();
		$this->refuse( $watcher, 'edit_others_pages', 'edit_post' );

		$this->assertTrue( $watcher->died( 'Sorry, you are not allowed to edit this item.', array( 'response' => 403 ) ) );
		$watcher->flush();

		$denials = $this->trials->get( $this->trial['id'] )['denials'];
		$this->assertSame( 'Could not edit a page by another author', $denials['cap:edit_others_pages']['label'] );
	}

	public function test_a_refusal_without_a_failed_check_uses_the_message(): void {
		$watcher = $this->watcher();

		$watcher->died( '<p>Sorry, you cannot do that.</p>', array( 'response' => 403 ) );
		$watcher->flush();

		$labels = array_column( $this->trials->get( $this->trial['id'] )['denials'], 'label' );
		$this->assertSame( array( 'Sorry, you cannot do that.' ), $labels );
	}

	public function test_a_die_that_is_not_a_permission_refusal_is_left_out(): void {
		$watcher = $this->watcher();
		$this->refuse( $watcher, 'edit_others_pages', 'edit_post' );

		$this->assertFalse( $watcher->died( 'Something broke.', array( 'response' => 500 ) ) );
		$this->assertFalse( $watcher->died( 'Missing.', array() ) );
		$watcher->flush();

		$this->assertSame( array(), $this->trials->get( $this->trial['id'] )['denials'] );
	}

	public function test_an_expired_nonce_is_not_blamed_on_the_role(): void {
		$watcher = $this->watcher();
		$watcher->nonce_checked( 'update-post', false );

		$this->assertFalse( $watcher->died( 'The link you followed has expired.', array( 'response' => 403 ) ) );
		$watcher->flush();

		$this->assertSame( array(), $this->trials->get( $this->trial['id'] )['denials'] );
	}

	public function test_a_refused_rest_write_is_recorded_and_the_error_carries_the_message(): void {
		$watcher  = $this->watcher();
		$response = new TrialTestResponse( array( 'code' => 'rest_cannot_create' ), 403 );

		$returned = $watcher->rest_response( $response, null, new TrialTestRequest( 'POST', '/wp/v2/categories' ) );
		$watcher->flush();

		$denials = $this->trials->get( $this->trial['id'] )['denials'];
		$this->assertSame( 'Could not create categories through the REST API', $denials['rest:create:categories']['label'] );
		$this->assertSame( 'rest', $denials['rest:create:categories']['kind'] );
		$this->assertStringContainsString( 'Ask for it back', $returned->get_data()['role_right_sizer_trial'] );
	}

	public function test_reads_and_nonce_errors_in_rest_are_not_walls(): void {
		$watcher = $this->watcher();

		$watcher->rest_response( new TrialTestResponse( array( 'code' => 'rest_forbidden' ), 403 ), null, new TrialTestRequest( 'GET', '/wp/v2/users' ) );
		$watcher->rest_response( new TrialTestResponse( array( 'code' => 'rest_cookie_invalid_nonce' ), 403 ), null, new TrialTestRequest( 'POST', '/wp/v2/posts' ) );
		$watcher->rest_response( new TrialTestResponse( array( 'code' => 'ok' ), 200 ), null, new TrialTestRequest( 'POST', '/wp/v2/posts' ) );
		$watcher->flush();

		$this->assertSame( array(), $this->trials->get( $this->trial['id'] )['denials'] );
	}

	public function test_a_rest_refusal_names_the_capability_when_a_check_failed(): void {
		$watcher = $this->watcher();
		$this->refuse( $watcher, 'edit_others_posts', 'edit_post' );

		$watcher->rest_response( new TrialTestResponse( array( 'code' => 'rest_cannot_edit' ), 403 ), null, new TrialTestRequest( 'POST', '/wp/v2/posts/12' ) );
		$watcher->flush();

		$labels = array_column( $this->trials->get( $this->trial['id'] )['denials'], 'label' );
		$this->assertSame( array( 'Could not edit a post by another author' ), $labels );
	}

	public function test_a_refusal_can_be_dropped_or_changed_by_a_filter(): void {
		Filters\expectApplied( 'role_right_sizer_trial_denial' )->once()->andReturn( null );
		$watcher = $this->watcher();
		$this->refuse( $watcher, 'edit_others_pages', 'edit_post' );
		$watcher->died( 'x', array( 'response' => 403 ) );
		$watcher->flush();

		$this->assertSame( array(), $this->trials->get( $this->trial['id'] )['denials'] );
	}

	public function test_the_wall_says_why_and_offers_the_request_button(): void {
		$html = $this->watcher()->wall_html();

		$this->assertStringContainsString( 'Your access was reduced', $html );
		$this->assertStringContainsString( 'Ask for it back.', $html );
		$this->assertStringContainsString( 'Request my access back', $html );
		$this->assertStringContainsString( 'name="action" value="role_right_sizer_trial_request"', $html );
	}

	public function test_the_wall_message_takes_a_filter(): void {
		Filters\expectApplied( 'role_right_sizer_trial_message' )->once()->andReturn( 'Talk to Ada.' );

		$this->assertSame( 'Talk to Ada.', $this->watcher()->wall_text() );
	}

	public function test_after_asking_the_button_gives_way_to_a_note(): void {
		$this->trials->request_access( $this->trial['id'], true );

		$html = $this->watcher()->wall_html();

		$this->assertStringNotContainsString( 'Request my access back', $html );
		$this->assertStringContainsString( 'You already asked', $html );
	}

	public function test_words_for_capabilities_screens_and_routes_take_filters(): void {
		Filters\expectApplied( 'role_right_sizer_trial_cap_labels' )->twice()->andReturn( array( 'publish_posts' => 'Could not publish' ) );
		$this->assertSame( 'Could not publish', Watcher::cap_label( 'publish_posts' ) );
		$this->assertSame( 'Could not do something that needs rrs_special', Watcher::cap_label( 'rrs_special' ) );

		Filters\expectApplied( 'role_right_sizer_trial_screen_titles' )->once()->andReturn( array( 'my-page' => 'My report' ) );
		$this->assertSame( 'Tried to open My report', Watcher::screen_label( 'admin.php', 'my-page' ) );

		$this->assertSame( 'update', Watcher::route_parts( 'POST', '/wp/v2/posts/12' )['verb'] );
		$this->assertSame( 'create', Watcher::route_parts( 'POST', '/wp/v2/posts' )['verb'] );
		$this->assertSame( 'delete', Watcher::route_parts( 'DELETE', '/wp/v2/posts/12' )['verb'] );
		$this->assertSame( 'posts', Watcher::route_parts( 'PUT', '/wp/v2/posts/12' )['resource'] );
	}

	public function test_core_screens_have_plain_names(): void {
		$this->assertSame( 'Tried to open the Theme File Editor', Watcher::screen_label( 'theme-editor.php' ) );
		$this->assertSame( 'Tried to open Some page', Watcher::screen_label( 'some-page.php' ) );
	}

	public function test_the_person_may_be_seen_only_by_someone_who_may_edit_them(): void {
		$this->current_user = 1;

		$this->assertTrue( Access::can_view_user( 5 ) );

		$this->no_access = array( 5 );
		$this->assertFalse( Access::can_view_user( 5 ) );
		$this->assertFalse( Access::can_manage_user( 5 ) );
		$this->assertTrue( Access::can_view_user( 1 ) );

		Filters\expectApplied( 'role_right_sizer_can_view_user' )->once()->with( false, 5 )->andReturn( true );
		$this->assertTrue( Access::can_view_user( 5 ) );
	}

	public function test_recommendations_are_cut_down_to_the_accounts_the_viewer_may_see(): void {
		$this->current_user = 1;
		$this->no_access    = array( 5 );

		$visible = Access::visible_recommendations(
			array(
				array( 'user_id' => 5 ),
				array( 'user_id' => 1 ),
			)
		);

		$this->assertSame( array( array( 'user_id' => 1 ) ), $visible );
	}
}
