<?php
/**
 * Tests for trials: starting, denials, the end of a trial, restoring, extending and keeping.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Tests\Unit;

use AryanX\Axle\RoleRightSizer\Applier;
use AryanX\Axle\RoleRightSizer\Changes;
use AryanX\Axle\RoleRightSizer\Recommender;
use AryanX\Axle\RoleRightSizer\Report;
use AryanX\Axle\RoleRightSizer\Settings;
use AryanX\Axle\RoleRightSizer\Trials;
use AryanX\Axle\RoleRightSizer\Usage\Store;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class TrialsTest extends PluginTestCase {

	/**
	 * The change log.
	 *
	 * @var Changes
	 */
	private $changes;

	/**
	 * Emails sent: each `[ to, subject, message ]`.
	 *
	 * @var array[]
	 */
	private $mails = array();

	private function trials(): Trials {
		$settings      = new Settings();
		$store         = new Store();
		$recommender   = new Recommender( $settings, $store );
		$this->changes = new Changes();
		$report        = new Report( $settings, $recommender, $store );
		$applier       = new Applier( $settings, $recommender, $report, $this->changes );

		return new Trials( $settings, $applier, $recommender, $report );
	}

	protected function setUp(): void {
		parent::setUp();
		$this->tracking_started( 60 );
		$this->mails = array();

		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message ) {
				$this->mails[] = array( $to, $subject, $message );
				return true;
			}
		);
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return false !== strpos( (string) $email, '@' );
			}
		);
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( 'strval' );
		Functions\when( 'translate_user_role' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		$this->options['admin_email'] = 'owner@example.com';

		$this->add_user(
			1,
			array( 'administrator' ),
			array(
				'user_login'   => 'admin',
				'display_name' => 'Ada',
				'user_email'   => 'ada@example.com',
			)
		);
		$this->used( 1, array( 'manage_options' => 1 ) );
		$this->active( 1, 1 );
		$this->add_user(
			5,
			array( 'administrator' ),
			array(
				'user_login'   => 'pat',
				'display_name' => 'Pat',
				'user_email'   => 'pat@example.com',
			)
		);
		$this->used( 5, array( 'edit_others_posts' => 1 ) );
		$this->active( 5, 1 );
		$this->current_user = 1;
	}

	/**
	 * A denial as the watcher hands it over.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @return array
	 */
	private function denial( string $key, string $label ): array {
		return array(
			'key'   => $key,
			'kind'  => 'action',
			'label' => $label,
			'cap'   => '',
		);
	}

	public function test_starting_gives_the_smaller_role_logs_it_and_flags_the_person(): void {
		Actions\expectDone( 'role_right_sizer_trial_started' )->once()->with(
			\Mockery::on(
				static function ( $trial ) {
					return 5 === $trial['user_id'] && 'active' === $trial['status'] && array( 'editor' ) === $trial['after'];
				}
			)
		);

		$trials = $this->trials();
		$trial  = $trials->start( 5 );

		$this->assertSame( array( 'editor' ), $this->users[5]->roles );
		$this->assertSame( 1, $trial['change_id'] );
		$this->assertSame( array( 'administrator' ), $trial['before'] );
		$this->assertSame( 1, $this->meta[5]['wp_role_right_sizer_trial'] );
		$this->assertEqualsWithDelta( time() + 14 * DAY_IN_SECONDS, $trial['ends'], 5 );
		$this->assertEquals( $trial, $trials->for_user( 5 ) );
		$this->assertCount( 1, $this->changes->all() );
	}

	public function test_the_length_takes_a_filter_and_is_clamped(): void {
		Filters\expectApplied( 'role_right_sizer_trial_days' )->once()->andReturn( 9999 );
		$this->assertSame( 365, $this->trials()->days() );

		Filters\expectApplied( 'role_right_sizer_trial_days' )->once()->andReturn( 'soon' );
		$this->assertSame( 14, $this->trials()->days() );
	}

	public function test_a_person_gets_one_trial_at_a_time_and_only_while_in_use(): void {
		$trials = $this->trials();
		$this->assertIsArray( $trials->start( 5 ) );

		$again = $trials->start( 5 );
		$this->assertSame( 'role_right_sizer_trial_exists', $again->get_error_code() );

		// A dormant account has nothing to try.
		$this->add_user( 6, array( 'administrator' ), array( 'user_login' => 'old' ) );
		$this->active( 6, 200 );
		$this->assertSame( 'role_right_sizer_trial_type', $trials->start( 6 )->get_error_code() );
		$this->assertSame( array( 'administrator' ), $this->users[6]->roles );
	}

	public function test_the_types_take_a_filter(): void {
		Filters\expectApplied( 'role_right_sizer_trial_types' )->once()->andReturn( array( 'dormant', 'junk' ) );
		$this->assertSame( array( 'dormant' ), $this->trials()->types() );
	}

	public function test_nobody_starts_a_trial_for_an_account_they_may_not_manage(): void {
		$this->no_access = array( 5 );
		$result          = $this->trials()->start( 5 );

		$this->assertSame( 'role_right_sizer_forbidden', $result->get_error_code() );
		$this->assertSame( array( 'administrator' ), $this->users[5]->roles );
	}

	public function test_denials_are_grouped_counted_and_announced(): void {
		$trials = $this->trials();
		$trial  = $trials->start( 5 );

		Actions\expectDone( 'role_right_sizer_trial_denied' )->times( 3 );

		$plugins = $this->denial( 'screen:plugins.php', 'Tried to open Plugins' );
		$trials->add_denials( $trial['id'], array( $plugins ) );
		$trials->add_denials( $trial['id'], array( $plugins ) );
		$trials->add_denials( $trial['id'], array( $this->denial( 'cap:edit_others_pages', 'Could not edit a page by another author' ) ) );

		$saved = $trials->get( $trial['id'] );
		$this->assertSame( 2, $saved['denials']['screen:plugins.php']['count'] );
		$this->assertSame( 1, $saved['denials']['cap:edit_others_pages']['count'] );
		$this->assertSame( 3, Trials::denial_total( $saved ) );
	}

	public function test_only_the_newest_denials_are_kept_up_to_the_limit(): void {
		Filters\expectApplied( 'role_right_sizer_trial_denial_limit' )->andReturn( 5 );
		$trials = $this->trials();
		$trial  = $trials->start( 5 );

		for ( $i = 1; $i <= 7; $i++ ) {
			$trials->add_denials( $trial['id'], array( $this->denial( 'k' . $i, 'L' . $i ) ) );
		}

		$this->assertCount( 5, $trials->get( $trial['id'] )['denials'] );
	}

	public function test_restoring_puts_the_old_role_back_and_closes_the_trial(): void {
		Actions\expectDone( 'role_right_sizer_trial_restored' )->once();
		$trials = $this->trials();
		$trial  = $trials->start( 5 );
		$trials->register();

		$result = $trials->restore( $trial['id'] );

		$this->assertSame( array( 'administrator' ), $this->users[5]->roles );
		$this->assertSame( 'restored', $result['status'] );
		$this->assertArrayNotHasKey( 'wp_role_right_sizer_trial', $this->meta[5] );
		$this->assertNotSame( 0, $this->changes->get( 1 )['undone'] );
		$this->assertNull( $trials->for_user( 5 ) );
		$this->assertSame( 'role_right_sizer_trial_closed', $trials->restore( $trial['id'] )->get_error_code() );
	}

	public function test_undoing_the_change_from_the_log_closes_the_trial(): void {
		$trials = $this->trials();
		$trial  = $trials->start( 5 );

		// The log's Undo fires this action.
		$trials->undone( $this->changes->get( $trial['change_id'] ) );

		$this->assertSame( 'restored', $trials->get( $trial['id'] )['status'] );
		$this->assertArrayNotHasKey( 'wp_role_right_sizer_trial', $this->meta[5] );
	}

	public function test_keeping_closes_the_trial_and_leaves_the_change_undoable(): void {
		Actions\expectDone( 'role_right_sizer_trial_kept' )->once()->with( \Mockery::type( 'array' ), false );
		$trials = $this->trials();
		$trial  = $trials->start( 5 );

		$result = $trials->keep( $trial['id'] );

		$this->assertSame( 'kept', $result['status'] );
		$this->assertSame( array( 'editor' ), $this->users[5]->roles );
		$this->assertSame( 0, $this->changes->get( $trial['change_id'] )['undone'] );
	}

	public function test_extending_counts_from_now_when_the_time_is_up(): void {
		Actions\expectDone( 'role_right_sizer_trial_extended' )->once();
		$trials = $this->trials();
		$trial  = $trials->start( 5 );
		$this->options[ Trials::OPTION ]['items'][0]['ends'] = time() - DAY_IN_SECONDS;
		$trials->check();
		$this->assertSame( 'ended', $trials->get( $trial['id'] )['status'] );

		$result = $trials->extend( $trial['id'], 7 );

		$this->assertSame( 'active', $result['status'] );
		$this->assertEqualsWithDelta( time() + 7 * DAY_IN_SECONDS, $result['ends'], 5 );
	}

	public function test_a_trial_that_ends_waits_for_a_decision_and_tells_whoever_started_it(): void {
		Actions\expectDone( 'role_right_sizer_trial_ended' )->once();
		$trials = $this->trials();
		$trial  = $trials->start( 5 );

		$this->assertSame(
			array(
				'ended'   => 0,
				'kept'    => 0,
				'removed' => 0,
			),
			$trials->check( time() + DAY_IN_SECONDS )
		);
		$result = $trials->check( time() + 15 * DAY_IN_SECONDS );

		$this->assertSame( 1, $result['ended'] );
		$this->assertSame( 'ended', $trials->get( $trial['id'] )['status'] );
		$this->assertSame( array( 'editor' ), $this->users[5]->roles );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( array( 'ada@example.com' ), $this->mails[0][0] );
		$this->assertStringContainsString( 'Pat', $this->mails[0][1] );
	}

	public function test_with_no_denials_a_trial_is_kept_on_its_own_only_when_the_setting_says_so(): void {
		$this->options[ Settings::OPTION ] = array( 'trial_end_action' => 'keep' );
		Actions\expectDone( 'role_right_sizer_trial_kept' )->once()->with( \Mockery::type( 'array' ), true );
		$trials = $this->trials();
		$trial  = $trials->start( 5 );

		$result = $trials->check( time() + 15 * DAY_IN_SECONDS );

		$this->assertSame( 1, $result['kept'] );
		$this->assertSame( 'kept', $trials->get( $trial['id'] )['status'] );
		$this->assertTrue( $trials->get( $trial['id'] )['auto'] );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_trial_with_denials_is_never_kept_on_its_own(): void {
		$this->options[ Settings::OPTION ] = array( 'trial_end_action' => 'keep' );
		$trials = $this->trials();
		$trial  = $trials->start( 5 );
		$trials->add_denials( $trial['id'], array( $this->denial( 'screen:plugins.php', 'Tried to open Plugins' ) ) );

		$result = $trials->check( time() + 15 * DAY_IN_SECONDS );

		$this->assertSame( 1, $result['ended'] );
		$this->assertSame( 0, $result['kept'] );
		$this->assertSame( 'ended', $trials->get( $trial['id'] )['status'] );
		$this->assertStringContainsString( 'Tried to open Plugins', $this->mails[0][2] );
		$this->assertSame( 'role_right_sizer_trial_denials', $trials->keep( $trial['id'], true )->get_error_code() );
	}

	public function test_the_end_action_takes_a_filter(): void {
		Filters\expectApplied( 'role_right_sizer_trial_end_action' )->once()->andReturn( 'keep' );
		$this->assertSame( 'keep', ( new Settings() )->trial_end_action() );

		Filters\expectApplied( 'role_right_sizer_trial_end_action' )->once()->andReturn( 'nuke' );
		$this->assertSame( 'ask', ( new Settings() )->trial_end_action() );
	}

	public function test_asking_for_access_back_tells_the_starter_once_a_day(): void {
		Actions\expectDone( 'role_right_sizer_trial_access_requested' )->once();
		$trials = $this->trials();
		$trial  = $trials->start( 5 );

		// The person on trial cannot manage their own role, so their request skips that check.
		$this->current_user = 5;
		$this->assertTrue( $trials->request_access( $trial['id'], true ) );
		$this->assertSame( 'role_right_sizer_trial_asked', $trials->request_access( $trial['id'], true )->get_error_code() );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'asks for their access back', $this->mails[0][1] );
		$this->assertGreaterThan( 0, $trials->get( $trial['id'] )['requested'] );
	}

	public function test_emails_take_a_filter_and_can_be_switched_off(): void {
		Filters\expectApplied( 'role_right_sizer_trial_email' )->once()->andReturn( false );
		$trials = $this->trials();
		$trial  = $trials->start( 5 );

		$trials->request_access( $trial['id'], true );

		$this->assertSame( array(), $this->mails );
	}

	public function test_old_closed_trials_are_removed_and_open_ones_stay(): void {
		$trials = $this->trials();
		$one    = $trials->start( 5 );
		$trials->keep( $one['id'] );
		$this->add_user( 7, array( 'administrator' ), array( 'user_login' => 'kim' ) );
		$this->used( 7, array( 'edit_others_posts' => 1 ) );
		$this->active( 7, 1 );
		$two = $trials->start( 7 );

		$this->assertSame( 0, $trials->purge() );
		$this->assertSame( 1, $trials->purge( time() + 400 * DAY_IN_SECONDS ) );
		$this->assertNull( $trials->get( $one['id'] ) );
		$this->assertNotNull( $trials->get( $two['id'] ) );
	}

	public function test_erasing_a_person_removes_their_trials_and_the_name_of_who_started_others(): void {
		$trials = $this->trials();
		$trials->start( 5 );

		$this->assertTrue( $trials->erase( 5 ) );
		$this->assertSame( array(), $trials->all() );

		$this->users[5]->roles = array( 'administrator' );
		$trials->start_many( array( 5 ) );
		$this->assertTrue( $trials->erase( 1 ) );
		$this->assertSame( -1, $trials->all()[0]['by'] );
	}

	public function test_start_many_shares_one_batch(): void {
		$this->add_user( 7, array( 'administrator' ), array( 'user_login' => 'kim' ) );
		$this->used( 7, array( 'edit_others_posts' => 1 ) );
		$this->active( 7, 1 );

		$result = $this->trials()->start_many( array( 5, 7, 99 ) );

		$this->assertCount( 2, $result['trials'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertSame( $result['trials'][0]['batch'], $result['trials'][1]['batch'] );
	}
}
