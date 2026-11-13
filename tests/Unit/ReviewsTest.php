<?php
/**
 * Tests for access reviews: the snapshot, decisions, finishing, reminders, privacy and the evidence.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Tests\Unit;

use AryanX\Axle\RoleRightSizer\Applier;
use AryanX\Axle\RoleRightSizer\Changes;
use AryanX\Axle\RoleRightSizer\Recommender;
use AryanX\Axle\RoleRightSizer\Report;
use AryanX\Axle\RoleRightSizer\Reviews;
use AryanX\Axle\RoleRightSizer\Settings;
use AryanX\Axle\RoleRightSizer\Trials;
use AryanX\Axle\RoleRightSizer\Usage\Store;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class ReviewsTest extends PluginTestCase {

	/**
	 * The change log.
	 *
	 * @var Changes
	 */
	private $changes;

	/**
	 * Trials.
	 *
	 * @var Trials
	 */
	private $trials;

	/**
	 * Emails sent: each `[ to, subject, message ]`.
	 *
	 * @var array[]
	 */
	private $mails = array();

	private function reviews(): Reviews {
		$settings      = new Settings();
		$store         = new Store();
		$recommender   = new Recommender( $settings, $store );
		$this->changes = new Changes();
		$report        = new Report( $settings, $recommender, $store );
		$applier       = new Applier( $settings, $recommender, $report, $this->changes );
		$this->trials  = new Trials( $settings, $applier, $recommender, $report );

		return new Reviews( $settings, $applier, $report, $store, $this->changes, $this->trials );
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
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'sanitize_textarea_field' )->alias( 'trim' );
		Functions\when( 'wp_timezone' )->alias(
			static function () {
				return new \DateTimeZone( 'UTC' );
			}
		);
		Functions\when( 'number_format_i18n' )->alias( 'strval' );
		Functions\when( 'translate_user_role' )->returnArg();
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
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
		$this->options['admin_email'] = 'owner@example.com';

		$this->add_user( 1, array( 'administrator' ), array( 'user_login' => 'admin', 'display_name' => 'Ada', 'user_email' => 'ada@example.com' ) );
		$this->used( 1, array( 'manage_options' => 1 ) );
		$this->active( 1, 1 );
		$this->add_user( 5, array( 'administrator' ), array( 'user_login' => 'pat', 'display_name' => 'Pat', 'user_email' => 'pat@example.com' ) );
		$this->used( 5, array( 'edit_others_posts' => 1 ) );
		$this->active( 5, 1 );
		$this->add_user( 6, array( 'editor' ), array( 'user_login' => 'ed', 'display_name' => 'Ed', 'user_email' => 'ed@example.com' ) );
		$this->current_user = 1;
	}

	public function test_starting_takes_a_snapshot_of_every_account_and_changes_nothing(): void {
		Actions\expectDone( 'role_right_sizer_review_started' )->once();
		$reviews = $this->reviews();
		$review  = $reviews->start( 'Q4', 0, array( 5, 5, 0 ) );

		$this->assertSame( 'open', $review['status'] );
		$this->assertSame( 'Q4', $review['name'] );
		$this->assertSame( array( 5 ), $review['reviewers'] );
		$this->assertSame( array( 1, 5, 6 ), array_keys( $review['entries'] ) );
		$this->assertSame( array( 'administrator' ), $review['entries'][5]['roles'] );
		$this->assertArrayHasKey( 'edit_others_posts', $review['entries'][5]['used'] );
		$this->assertSame( array( 'administrator' ), $this->users[5]->roles );
		$this->assertNotEmpty( $this->mails );
	}

	public function test_only_one_review_is_open_and_the_due_date_must_be_ahead(): void {
		$reviews = $this->reviews();
		$this->assertSame( 'role_right_sizer_review_due', $reviews->start( '', time() - 10 )->get_error_code() );
		$reviews->start();
		$this->assertSame( 'role_right_sizer_review_open', $reviews->start()->get_error_code() );
	}

	public function test_accounts_the_person_may_not_edit_stay_out_of_the_snapshot(): void {
		$this->no_access = array( 6 );
		$review          = $this->reviews()->start();

		$this->assertSame( array( 1, 5 ), array_keys( $review['entries'] ) );
	}

	public function test_a_review_that_does_not_fit_the_limit_is_refused_not_cut_short(): void {
		Filters\expectApplied( 'role_right_sizer_review_limit' )->andReturn( 2 );

		$this->assertSame( 'role_right_sizer_review_too_big', $this->reviews()->start()->get_error_code() );
		$this->assertSame( array(), $this->options[ Reviews::OPTION ] ?? array() );
	}

	public function test_decisions_are_saved_with_who_and_when_and_reduce_defaults_to_the_recommendation(): void {
		Actions\expectDone( 'role_right_sizer_review_decided' )->twice();
		$reviews = $this->reviews();
		$review  = $reviews->start();
		$entry   = $reviews->decide( $review['id'], 5, 'reduce', 'editor', 'Only edits posts' );

		$this->assertSame( 'reduce', $entry['decision'] );
		$this->assertSame( 'editor', $entry['role'] );
		$this->assertSame( 'Only edits posts', $entry['note'] );
		$this->assertSame( 1, $entry['reviewer'] );

		$kept = $reviews->decide( $review['id'], 6, 'keep', 'editor' );
		$this->assertSame( '', $kept['role'] );
		$this->assertSame( 2, Reviews::progress( $reviews->get( $review['id'] ) )['decided'] );
	}

	public function test_decisions_refuse_a_missing_role_your_own_account_and_accounts_you_may_not_change(): void {
		$reviews = $this->reviews();
		$review  = $reviews->start();

		$this->assertSame( 'role_right_sizer_review_role', $reviews->decide( $review['id'], 5, 'reduce' )->get_error_code() );
		$this->assertSame( 'role_right_sizer_review_role', $reviews->decide( $review['id'], 5, 'reduce', 'nope' )->get_error_code() );
		$this->assertSame( 'role_right_sizer_self', $reviews->decide( $review['id'], 1, 'remove' )->get_error_code() );
		$this->assertSame( 'role_right_sizer_review_decision', $reviews->decide( $review['id'], 5, 'banish' )->get_error_code() );
		$this->assertSame( 'role_right_sizer_review_entry', $reviews->decide( $review['id'], 99, 'keep' )->get_error_code() );

		$this->no_access = array( 6 );
		$this->assertSame( 'role_right_sizer_review_entry', $reviews->decide( $review['id'], 6, 'keep' )->get_error_code() );
	}

	public function test_saving_a_page_skips_rows_that_did_not_change(): void {
		$reviews = $this->reviews();
		$review  = $reviews->start();
		$rows    = array(
			5 => array( 'decision' => 'keep', 'role' => 'editor', 'note' => '' ),
			6 => array( 'decision' => '', 'role' => '', 'note' => '' ),
		);

		$this->assertSame( 1, $reviews->decide_many( $review['id'], $rows )['saved'] );
		$this->assertSame( 0, $reviews->decide_many( $review['id'], $rows )['saved'] );
	}

	public function test_keep_undecided_only_marks_accounts_without_a_decision(): void {
		$reviews = $this->reviews();
		$review  = $reviews->start();
		$reviews->decide( $review['id'], 5, 'reduce', 'editor' );

		$this->assertSame( 2, $reviews->keep_undecided( $review['id'] ) );
		$saved = $reviews->get( $review['id'] );
		$this->assertSame( 'reduce', $saved['entries'][5]['decision'] );
		$this->assertSame( 'keep', $saved['entries'][6]['decision'] );
	}

	public function test_finishing_needs_a_decision_for_everyone(): void {
		$reviews = $this->reviews();
		$review  = $reviews->start();
		$reviews->decide( $review['id'], 5, 'keep' );

		$this->assertSame( 'role_right_sizer_review_undecided', $reviews->finish( $review['id'] )->get_error_code() );
		$this->assertSame( 'open', $reviews->get( $review['id'] )['status'] );
	}

	public function test_finishing_applies_decisions_in_one_batch_that_undo_can_reverse(): void {
		$reviews = $this->reviews();
		$review  = $reviews->start();
		$reviews->decide( $review['id'], 1, 'keep' );
		$reviews->decide( $review['id'], 5, 'reduce', 'editor', 'Writes posts only' );
		$reviews->decide( $review['id'], 6, 'remove' );

		Actions\expectDone( 'role_right_sizer_review_finished' )->once();
		$done = $reviews->finish( $review['id'] );

		$this->assertSame( 'finished', $done['status'] );
		$this->assertSame( array( 'editor' ), $this->users[5]->roles );
		$this->assertSame( array(), $this->users[6]->roles );
		$this->assertSame( 'kept', $done['entries'][1]['result']['status'] );
		$this->assertSame( 'applied', $done['entries'][5]['result']['status'] );
		$this->assertCount( 2, $this->changes->all() );
		$this->assertSame( 'review', $this->changes->all()[0]['kind'] );
		$this->assertCount( 2, $this->changes->batch( $done['batch'] ) );
		$this->assertSame( 'role_right_sizer_review_closed', $reviews->finish( $review['id'] )->get_error_code() );
		$this->assertSame( 'role_right_sizer_review_closed', $reviews->decide( $review['id'], 5, 'keep' )->get_error_code() );
	}

	public function test_a_decision_that_cannot_be_applied_is_recorded_with_the_reason_and_does_not_stop_the_rest(): void {
		$reviews = $this->reviews();
		$review  = $reviews->start();
		$reviews->decide( $review['id'], 1, 'keep' );
		$reviews->decide( $review['id'], 5, 'remove' );
		$reviews->decide( $review['id'], 6, 'reduce', 'subscriber' );
		// Pat is on a trial by the time the review finishes.
		$this->trials->start( 5 );

		$done = $reviews->finish( $review['id'] );

		$this->assertSame( 'failed', $done['entries'][5]['result']['status'] );
		$this->assertStringContainsString( 'trial', $done['entries'][5]['result']['message'] );
		$this->assertSame( 'applied', $done['entries'][6]['result']['status'] );
	}

	public function test_a_role_that_gives_more_access_is_refused_when_it_is_applied(): void {
		$reviews = $this->reviews();
		$review  = $reviews->start();
		$reviews->decide( $review['id'], 1, 'keep' );
		$reviews->decide( $review['id'], 5, 'keep' );
		$reviews->decide( $review['id'], 6, 'reduce', 'administrator' );

		$done = $reviews->finish( $review['id'] );
		$this->assertSame( 'failed', $done['entries'][6]['result']['status'] );
		$this->assertSame( array( 'editor' ), $this->users[6]->roles );
	}

	public function test_the_plan_shows_what_finishing_would_take_away_and_what_would_be_skipped(): void {
		$reviews = $this->reviews();
		$review  = $reviews->start();
		$reviews->decide( $review['id'], 5, 'reduce', 'editor' );
		$reviews->decide( $review['id'], 6, 'reduce', 'administrator' );

		$plan = $reviews->plan( $reviews->get( $review['id'] ) );
		$this->assertSame( '', $plan[0]['problem'] );
		$this->assertContains( 'manage_options', $plan[0]['lost'] );
		$this->assertNotSame( '', $plan[1]['problem'] );
		$this->assertSame( array( 'administrator' ), $this->users[5]->roles );
	}

	public function test_an_open_review_can_be_discarded_but_a_finished_one_cannot(): void {
		$reviews = $this->reviews();
		$review  = $reviews->start();
		Actions\expectDone( 'role_right_sizer_review_discarded' )->once();
		$this->assertTrue( $reviews->discard( $review['id'] ) );
		$this->assertNull( $reviews->get( $review['id'] ) );

		$review = $reviews->start();
		$reviews->keep_undecided( $review['id'] );
		$reviews->finish( $review['id'] );
		$this->assertSame( 'role_right_sizer_review_closed', $reviews->discard( $review['id'] )->get_error_code() );
	}

	public function test_only_the_newest_finished_reviews_are_kept(): void {
		Filters\expectApplied( 'role_right_sizer_review_keep' )->andReturn( 1 );
		$reviews = $this->reviews();
		for ( $i = 0; $i < 3; $i++ ) {
			$review = $reviews->start( 'R' . $i );
			$reviews->keep_undecided( $review['id'] );
			$reviews->finish( $review['id'] );
		}

		$this->assertCount( 1, $reviews->all() );
		$this->assertSame( 'R2', $reviews->all()[0]['name'] );
	}

	public function test_a_due_date_is_the_end_of_that_day_and_junk_is_no_date(): void {
		$this->assertSame( gmmktime( 23, 59, 59, 12, 15, 2030 ), Reviews::due_from_date( '2030-12-15' ) );
		$this->assertSame( 0, Reviews::due_from_date( '' ) );
		$this->assertSame( 0, Reviews::due_from_date( '2030-02-31' ) );
		$this->assertSame( 0, Reviews::due_from_date( 'soon' ) );
	}

	public function test_reminders_go_out_once_before_the_date_and_once_when_overdue(): void {
		$reviews = $this->reviews();
		$reviews->start( 'Q4', time() + 2 * DAY_IN_SECONDS, array( 5 ) );
		$this->mails = array();

		$this->assertSame( 1, $reviews->check()['due_soon'] );
		$this->assertSame( 0, $reviews->check()['due_soon'] );
		$this->assertCount( 1, $this->mails );

		$this->assertSame( 1, $reviews->check( time() + 3 * DAY_IN_SECONDS )['overdue'] );
		$this->assertSame( 0, $reviews->check( time() + 4 * DAY_IN_SECONDS )['overdue'] );
		$this->assertCount( 2, $this->mails );
		$this->assertContains( 'pat@example.com', $this->mails[1][0] );
	}

	public function test_the_next_review_is_due_one_interval_after_the_last_and_is_nudged_once(): void {
		$reviews = $this->reviews();
		$this->assertSame( $this->options[ Settings::STARTED ] + 90 * DAY_IN_SECONDS, $reviews->next_due() );
		$this->assertFalse( $reviews->is_overdue() );
		$this->assertTrue( $reviews->is_overdue( time() + 40 * DAY_IN_SECONDS ) );

		$now = time() + 40 * DAY_IN_SECONDS;
		$this->assertSame( 1, $reviews->check( $now )['next'] );
		$this->assertSame( 0, $reviews->check( $now + DAY_IN_SECONDS )['next'] );
		$this->assertSame( array( 'owner@example.com' ), $this->mails[0][0] );
	}

	public function test_the_interval_takes_a_filter_and_zero_turns_the_reminder_off(): void {
		Filters\expectApplied( 'role_right_sizer_review_interval_days' )->andReturn( 0 );
		$reviews = $this->reviews();

		$this->assertSame( 0, $reviews->next_due() );
		$this->assertSame( 0, $reviews->check( time() + 999 * DAY_IN_SECONDS )['next'] );
	}

	public function test_a_review_that_is_open_is_not_overdue_to_start(): void {
		$reviews = $this->reviews();
		$reviews->start();

		$this->assertFalse( $reviews->is_overdue( time() + 999 * DAY_IN_SECONDS ) );
	}

	public function test_the_csv_has_one_row_per_visible_account_with_decisions_and_defused_formulas(): void {
		$reviews = $this->reviews();
		$review  = $reviews->start();
		$reviews->decide( $review['id'], 5, 'reduce', 'editor', '=HYPERLINK("x")' );
		$reviews->decide( $review['id'], 1, 'keep' );
		$reviews->decide( $review['id'], 6, 'keep' );
		$done = $reviews->finish( $review['id'] );

		$csv   = $reviews->csv( $done );
		$lines = explode( "\r\n", trim( $csv ) );
		$this->assertCount( 4, $lines );
		$this->assertStringContainsString( '"review_id"', $lines[0] );
		$this->assertStringContainsString( "\"'=HYPERLINK", $csv );
		$this->assertStringContainsString( '"reduce","editor"', $csv );
		$this->assertStringContainsString( '"applied"', $csv );

		$this->no_access = array( 6 );
		$this->assertCount( 3, explode( "\r\n", trim( $reviews->csv( $done ) ) ) );
		$this->assertStringNotContainsString( 'user6', $reviews->csv( $done ) );
	}

	public function test_the_report_says_who_reviewed_each_decision_and_what_changed_and_escapes_everything(): void {
		$reviews = $this->reviews();
		$review  = $reviews->start( 'Q4 <b>review</b>' );
		$reviews->decide( $review['id'], 5, 'reduce', 'editor', '<script>alert(1)</script>' );
		$reviews->decide( $review['id'], 1, 'keep' );
		$reviews->decide( $review['id'], 6, 'keep' );
		$done = $reviews->finish( $review['id'] );

		$html = $reviews->html( $done );
		$this->assertStringStartsWith( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'Ada', $html );
		$this->assertStringContainsString( 'Pat', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( '1 applied', $html );
	}

	public function test_the_report_notes_a_change_that_was_undone_afterwards(): void {
		$reviews = $this->reviews();
		$review  = $reviews->start();
		$reviews->decide( $review['id'], 5, 'reduce', 'editor' );
		$reviews->decide( $review['id'], 1, 'keep' );
		$reviews->decide( $review['id'], 6, 'keep' );
		$done = $reviews->finish( $review['id'] );
		$this->changes->mark_undone( (int) $done['entries'][5]['result']['change_id'] );

		$this->assertStringContainsString( 'Undone', $reviews->html( $done ) );
		$this->assertMatchesRegularExpression( '/"\d{4}-\d\d-\d\d \d\d:\d\d:\d\d"\r\n/', $reviews->csv( $done ) );
	}

	public function test_erasing_a_person_keeps_the_decision_but_drops_their_details_and_name(): void {
		$reviews = $this->reviews();
		$review  = $reviews->start( 'Q4', 0, array( 6 ) );
		$reviews->decide( $review['id'], 6, 'keep', '', 'Private note' );

		$this->assertTrue( $reviews->erase( 6 ) );
		$saved = $reviews->get( $review['id'] );
		$this->assertTrue( $saved['entries'][6]['erased'] );
		$this->assertSame( '', $saved['entries'][6]['login'] );
		$this->assertSame( '', $saved['entries'][6]['note'] );
		$this->assertSame( 'keep', $saved['entries'][6]['decision'] );
		$this->assertSame( 1, $saved['entries'][6]['reviewer'] );
		$this->assertSame( array(), $saved['reviewers'] );
		$this->assertFalse( $reviews->erase( 6 ) );

		// Erasing the reviewer removes their name from every decision and from the review.
		$this->assertTrue( $reviews->erase( 1 ) );
		$saved = $reviews->get( $review['id'] );
		$this->assertSame( -1, $saved['entries'][6]['reviewer'] );
		$this->assertSame( -1, $saved['by'] );
	}

	public function test_the_recipients_take_a_filter(): void {
		Filters\expectApplied( 'role_right_sizer_review_recipients' )->once()->andReturn( array( 'audit@example.com', 'junk' ) );

		$this->assertSame( array( 'audit@example.com' ), $this->reviews()->recipients( array(), 'next' ) );
	}

	public function test_an_email_can_be_stopped_with_a_filter(): void {
		Filters\expectApplied( 'role_right_sizer_review_email' )->andReturn( false );
		$this->reviews()->start();

		$this->assertSame( array(), $this->mails );
	}
}
