<?php
/**
 * Tests for working out the smallest role each user needs.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer\Tests\Unit;

use AryanX\RoleRightSizer\Recommender;
use AryanX\RoleRightSizer\Settings;
use AryanX\RoleRightSizer\Usage\Store;
use Brain\Monkey\Filters;

final class RecommenderTest extends PluginTestCase {

	private function recommender(): Recommender {
		return new Recommender( new Settings(), new Store() );
	}

	protected function setUp(): void {
		parent::setUp();
		$this->tracking_started( 120 );
	}

	/**
	 * An administrator who only does an editor's work.
	 */
	private function pat(): \WP_User {
		$pat = $this->add_user( 5, array( 'administrator' ) );
		$this->used(
			5,
			array(
				'edit_posts'        => 1,
				'publish_posts'     => 2,
				'edit_others_posts' => 3,
				'moderate_comments' => 10,
				'read'              => 1,
				'manage_options'    => 200,
			)
		);
		$this->active( 5, 1 );

		return $pat;
	}

	public function test_an_administrator_who_only_edits_gets_editor(): void {
		$recommendation = $this->recommender()->for_user( $this->pat(), time(), 2 );

		$this->assertSame( 'downgrade', $recommendation['type'] );
		$this->assertSame( array( 'administrator' ), $recommendation['current'] );
		$this->assertSame( 'editor', $recommendation['proposed'] );
		$this->assertContains( 'manage_options', $recommendation['lost'] );
		$this->assertContains( 'activate_plugins', $recommendation['lost'] );
		$this->assertNotContains( 'level_10', $recommendation['lost'] );
		// manage_options was last used outside the 90-day window, so it does not count.
		$this->assertArrayNotHasKey( 'manage_options', $recommendation['used'] );
	}

	public function test_the_window_filter_decides_what_counts_as_used(): void {
		Filters\expectApplied( 'role_right_sizer_window_days' )->andReturn( 365 );

		$this->assertNull( $this->recommender()->for_user( $this->pat(), time(), 2 ) );
	}

	public function test_an_author_who_only_writes_drafts_gets_contributor(): void {
		$sam = $this->add_user( 6, array( 'author' ) );
		$this->used( 6, array( 'edit_posts' => 3 ) );
		$this->active( 6, 3 );

		$recommendation = $this->recommender()->for_user( $sam, time(), 2 );
		$this->assertSame( 'contributor', $recommendation['proposed'] );
		$this->assertSame( array( 'delete_published_posts', 'edit_published_posts', 'publish_posts', 'upload_files' ), $recommendation['lost'] );
	}

	public function test_nothing_is_recommended_while_learning_or_when_the_role_fits(): void {
		$this->tracking_started( 5 );
		$this->assertNull( $this->recommender()->for_user( $this->pat(), time(), 2 ) );

		$this->tracking_started( 120 );
		$this->used( 5, array( 'manage_options' => 1 ) );
		$this->assertNull( $this->recommender()->for_user( $this->users[5], time(), 2 ) );

		// Subscribers have nothing beyond reading to right-size.
		$reader = $this->add_user( 7, array( 'subscriber' ) );
		$this->assertNull( $this->recommender()->for_user( $reader, time(), 2 ) );
	}

	public function test_dormant_accounts_lose_their_role_or_move_to_the_fallback(): void {
		$olive = $this->add_user( 8, array( 'editor' ) );
		$this->active( 8, 100 );

		$recommendation = $this->recommender()->for_user( $olive, time(), 2 );
		$this->assertSame( 'dormant', $recommendation['type'] );
		$this->assertSame( '', $recommendation['proposed'] );
		$this->assertContains( 'read', $recommendation['lost'] );

		Filters\expectApplied( 'role_right_sizer_dormant_action' )->andReturn( 'fallback' );
		Filters\expectApplied( 'role_right_sizer_fallback_role' )->andReturn( 'contributor' );
		$this->assertSame( 'contributor', $this->recommender()->for_user( $olive, time(), 2 )['proposed'] );
	}

	public function test_a_fallback_role_that_gives_more_is_not_used(): void {
		$gus = $this->add_user( 9, array( 'contributor' ) );
		$this->active( 9, 100 );

		Filters\expectApplied( 'role_right_sizer_dormant_action' )->andReturn( 'fallback' );
		Filters\expectApplied( 'role_right_sizer_fallback_role' )->andReturn( 'author' );
		$this->assertSame( '', $this->recommender()->for_user( $gus, time(), 2 )['proposed'] );
	}

	public function test_accounts_that_never_logged_in_are_flagged_after_the_learning_period(): void {
		$gus = $this->add_user( 9, array( 'author' ) );
		$this->assertSame( 'never', $this->recommender()->for_user( $gus, time(), 2 )['type'] );

		// Registered last week: too early to tell.
		$new = $this->add_user( 10, array( 'author' ), array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
		$this->assertNull( $this->recommender()->for_user( $new, time(), 2 ) );
	}

	public function test_dormant_days_take_a_filter(): void {
		$olive = $this->add_user( 8, array( 'editor' ) );
		$this->active( 8, 100 );

		Filters\expectApplied( 'role_right_sizer_dormant_days' )->andReturn( 180 );
		$recommendation = $this->recommender()->for_user( $olive, time(), 2 );

		// Not dormant yet, but nothing used in the last 90 days either: the smallest role with read.
		$this->assertSame( 'downgrade', $recommendation['type'] );
		$this->assertSame( 'subscriber', $recommendation['proposed'] );
	}

	public function test_excluded_users_filter(): void {
		$pat = $this->pat();

		Filters\expectApplied( 'role_right_sizer_excluded_users' )->once()->andReturn( array( 5 ) );
		$this->assertNull( $this->recommender()->for_user( $pat, time(), 2 ) );
	}

	public function test_excluded_roles_filter(): void {
		$pat = $this->pat();

		Filters\expectApplied( 'role_right_sizer_excluded_roles' )->andReturn( array( 'administrator' ) );
		$this->assertSame( 'excluded_role', $this->recommender()->skip_reason( $pat ) );
		$this->assertNull( $this->recommender()->for_user( $pat, time(), 2 ) );
	}

	public function test_candidate_roles_filter_limits_the_choice(): void {
		$pat = $this->pat();

		Filters\expectApplied( 'role_right_sizer_candidate_roles' )->once()->with( array_keys( $this->roles ), $pat )->andReturn( array( 'administrator' ) );
		$this->assertNull( $this->recommender()->for_user( $pat, time(), 2 ) );
	}

	public function test_the_recommendation_filter_can_veto_or_change_it(): void {
		$pat = $this->pat();

		Filters\expectApplied( 'role_right_sizer_recommendation' )->once()->andReturn( null );
		$this->assertNull( $this->recommender()->for_user( $pat, time(), 2 ) );
	}

	public function test_a_changed_recommendation_gets_its_lost_capabilities_worked_out_again(): void {
		$pat = $this->pat();

		Filters\expectApplied( 'role_right_sizer_recommendation' )->once()->andReturnUsing(
			static function ( array $recommendation ) {
				$recommendation['proposed'] = 'author';
				$recommendation['lost']     = array( 'nonsense' );
				return $recommendation;
			}
		);
		$recommendation = $this->recommender()->for_user( $pat, time(), 2 );

		$this->assertSame( 'author', $recommendation['proposed'] );
		$this->assertContains( 'edit_others_posts', $recommendation['lost'] );
		$this->assertNotContains( 'nonsense', $recommendation['lost'] );
	}

	public function test_the_last_administrator_keeps_the_role(): void {
		$this->assertNull( $this->recommender()->for_user( $this->pat(), time(), 1 ) );
	}

	public function test_smallest_role_never_gives_more_and_keeps_read(): void {
		$roles = array(
			'big'     => array( 'read', 'a', 'b', 'c' ),
			'small'   => array( 'read', 'a' ),
			'noread'  => array( 'a' ),
			'foreign' => array( 'read', 'a', 'z' ),
		);

		$this->assertSame( 'small', Recommender::smallest_role( array( 'a' ), $roles['big'], array_keys( $roles ), $roles ) );
		$this->assertSame( 'big', Recommender::smallest_role( array( 'c' ), $roles['big'], array_keys( $roles ), $roles ) );
		$this->assertNull( Recommender::smallest_role( array( 'z' ), $roles['big'], array_keys( $roles ), $roles ) );
		$this->assertSame( 'noread', Recommender::smallest_role( array( 'a' ), array( 'a', 'b' ), array_keys( $roles ), $roles ) );
	}

	public function test_unused_capabilities_on_roles_skip_core_used_and_excluded(): void {
		$roles = array(
			'shop_editor' => array( 'read', 'edit_posts', 'view_shop_reports', 'export_orders' ),
			'empty_role'  => array( 'read', 'view_shop_reports' ),
			'locked'      => array( 'read', 'secret' ),
		);
		$found = Recommender::unused_role_caps(
			array( 'shop_editor' => array( 'export_orders' => time() ) ),
			array(
				'shop_editor' => 2,
				'locked'      => 1,
			),
			$roles,
			array_merge( ( new Settings() )->ignored_capabilities(), Recommender::core_capabilities() ),
			array( 'locked' )
		);

		$this->assertSame(
			array(
				array(
					'type'  => 'unused_cap',
					'role'  => 'shop_editor',
					'cap'   => 'view_shop_reports',
					'users' => 2,
					'owner' => null,
				),
			),
			$found
		);
	}

	public function test_capabilities_on_the_administrator_role_or_any_role_with_manage_options_are_never_suggested(): void {
		$this->add_role( 'site_owner', array( 'read', 'manage_options', 'agent_governance_approve' ) );
		$this->roles['administrator']->add_cap( 'agent_governance_approve' );
		$this->roles['administrator']->add_cap( 'manage_woocommerce' );
		$this->assertSame( array( 'administrator', 'site_owner' ), Recommender::protected_roles() );

		$found = Recommender::unused_role_caps(
			array(),
			array(
				'administrator' => 3,
				'site_owner'    => 1,
			),
			Recommender::role_caps(),
			array_merge( ( new Settings() )->ignored_capabilities(), Recommender::core_capabilities() ),
			array(),
			Recommender::protected_roles()
		);
		$this->assertSame( array(), $found );
	}

	public function test_the_protected_roles_take_a_filter(): void {
		$this->roles['administrator']->add_cap( 'agent_governance_approve' );

		Filters\expectApplied( 'role_right_sizer_protected_roles' )->once()->with( array( 'administrator' ) )->andReturn( array( 'editor', 7, '' ) );
		$protected = Recommender::protected_roles();
		$this->assertSame( array( 'editor' ), $protected );

		$found = Recommender::unused_role_caps( array(), array( 'administrator' => 1 ), Recommender::role_caps(), array_merge( ( new Settings() )->ignored_capabilities(), Recommender::core_capabilities() ), array(), $protected );
		$this->assertSame( array( 'agent_governance_approve' ), array_column( $found, 'cap' ) );
	}

	public function test_a_capability_only_counts_as_unused_once_it_was_on_the_role_for_the_whole_window(): void {
		$roles   = array( 'shop_editor' => array( 'read', 'view_shop_reports', 'manage_woocommerce', 'export_orders' ) );
		$since   = time() - 60 * DAY_IN_SECONDS;
		$history = array(
			'shop_editor' => array(
				// There since tracking began.
				'view_shop_reports'  => array( $since - 10, '' ),
				// Added by a plugin a few minutes ago.
				'manage_woocommerce' => array( time() - 300, 'woocommerce' ),
			),
			// export_orders is not in the history at all: too early to tell.
		);

		$found = Recommender::unused_role_caps( array(), array( 'shop_editor' => 1 ), $roles, array( 'read' ), array(), array(), $history, $since );
		$this->assertSame( array( 'view_shop_reports' ), array_column( $found, 'cap' ) );

		// Without a history (null), every capability counts, as before.
		$this->assertCount( 3, Recommender::unused_role_caps( array(), array( 'shop_editor' => 1 ), $roles, array( 'read' ), array() ) );
	}

	public function test_unused_since_is_the_window_start_or_the_tracking_start(): void {
		$recommender = $this->recommender();
		$now         = time();

		// Tracking began 120 days ago, the window is 90 days: the window start.
		$this->assertSame( $now - 90 * DAY_IN_SECONDS, $recommender->unused_since( $now ) );

		$this->tracking_started( 40 );
		$this->assertSame( $this->options[ Settings::STARTED ], $recommender->unused_since( $now ) );

		// A window shorter than the learning period: a capability still needs a whole learning period on the role.
		Filters\expectApplied( 'role_right_sizer_window_days' )->andReturn( 7 );
		$this->assertSame( $now - 30 * DAY_IN_SECONDS, $recommender->unused_since( $now ) );
	}

	public function test_core_capabilities_take_a_filter(): void {
		Filters\expectApplied( 'role_right_sizer_core_capabilities' )->once()->andReturn( array( 'read' ) );

		$this->assertSame( array( 'read' ), Recommender::core_capabilities() );
	}

	public function test_privileged_and_admin_roles(): void {
		$this->assertSame( array( 'administrator', 'editor', 'author', 'contributor' ), $this->recommender()->privileged_roles() );
		$this->assertSame( array( 'administrator' ), Recommender::admin_roles() );

		$this->add_user( 1, array( 'administrator' ) );
		$this->add_user( 2, array( 'administrator' ) );
		$this->add_user( 3, array( 'administrator' ) );
		$this->assertSame( 2, Recommender::admin_count() );
	}
}
