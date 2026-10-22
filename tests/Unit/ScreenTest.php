<?php
/**
 * Tests for the screen's capability, the CSV and the exclude list parsing,
 * the network screen and Site Health.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer\Tests\Unit;

use AryanX\RoleRightSizer\Admin\NetworkScreen;
use AryanX\RoleRightSizer\Admin\Screen;
use AryanX\RoleRightSizer\Health\SiteHealth;
use AryanX\RoleRightSizer\Recommender;
use AryanX\RoleRightSizer\Report;
use AryanX\RoleRightSizer\Settings;
use AryanX\RoleRightSizer\Usage\Store;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class ScreenTest extends PluginTestCase {

	public function test_the_capability_takes_a_filter_and_falls_back(): void {
		$this->assertSame( 'promote_users', Screen::capability() );

		Filters\expectApplied( 'role_right_sizer_capability' )->once()->with( 'promote_users' )->andReturn( 'manage_options' );
		$this->assertSame( 'manage_options', Screen::capability() );

		Filters\expectApplied( 'role_right_sizer_capability' )->once()->andReturn( '' );
		$this->assertSame( 'promote_users', Screen::capability() );
	}

	public function test_csv_lines_quote_every_cell_and_defuse_formulas(): void {
		$this->assertSame( "\"a\",\"say \"\"hi\"\"\",\"'=SUM(A1)\",\"'-1\",\"\"\r\n", Screen::csv_line( array( 'a', 'say "hi"', '=SUM(A1)', '-1', '' ) ) );
	}

	public function test_a_csv_row_has_every_column(): void {
		$user = $this->add_user( 5, array( 'administrator' ), array( 'user_login' => 'pat' ) );
		$row  = Screen::csv_row(
			$user,
			array(
				'type'     => 'downgrade',
				'proposed' => 'editor',
				'lost'     => array( 'manage_options', 'activate_plugins' ),
			),
			array( 'edit_posts' => array( 0, 86400, 1 ) ),
			0,
			86400
		);

		$this->assertSame( Screen::csv_columns(), array_keys( $row ) );
		$this->assertSame( 'editor', $row['recommended_role'] );
		$this->assertSame( 'manage_options activate_plugins', $row['capabilities_lost'] );
		$this->assertSame( 'edit_posts (1970-01-02)', $row['used_capabilities'] );
		$this->assertSame( '', $row['last_login'] );

		$this->assertSame( 'none', Screen::csv_row( $user, array( 'type' => 'dormant', 'proposed' => '', 'lost' => array() ), array(), 0, 0 )['recommended_role'] );
	}

	public function test_the_exclude_list_accepts_logins_emails_and_ids(): void {
		$this->add_user( 5, array( 'editor' ), array( 'user_login' => 'pat' ) );
		$this->add_user( 6, array( 'editor' ), array( 'user_login' => 'sam' ) );
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

		$this->assertSame( array( 5, 6 ), Screen::user_ids( "pat\nsam@example.com, 5\nnobody 99" ) );
	}

	public function test_the_network_screen_reads_each_sites_activity_from_user_meta(): void {
		Functions\when( 'maybe_unserialize' )->returnArg();
		$sites = NetworkScreen::sites_from_meta(
			array(
				'wp_role_right_sizer_last_active'   => array( 500 ),
				'wp_3_role_right_sizer_last_active' => array( 900 ),
				'wp_3_role_right_sizer_usage'       => array( array( 'edit_posts' => array( 1, 2, 3 ) ) ),
				'wp_capabilities'                   => array( 'x' ),
				'role_right_sizer_last_login'       => array( 7 ),
			),
			'wp_'
		);

		$this->assertSame(
			array(
				1 => array(
					'last_active' => 500,
					'caps'        => 0,
				),
				3 => array(
					'last_active' => 900,
					'caps'        => 1,
				),
			),
			$sites
		);
	}

	public function test_site_health_says_learning_then_points_to_the_recommendations(): void {
		Functions\when( 'wp_date' )->justReturn( 'November 21, 2026' );
		Functions\when( 'number_format_i18n' )->alias( 'strval' );
		Functions\when( 'admin_url' )->alias(
			static function ( $path ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		$settings = new Settings();
		$store    = new Store();
		$health   = new SiteHealth( $settings, new Report( $settings, new Recommender( $settings, $store ), $store ) );

		$this->tracking_started( 2 );
		$this->assertSame( 'good', $health->run()['status'] );
		$this->assertStringContainsString( 'still learning', $health->run()['label'] );

		$this->tracking_started( 60 );
		$this->assertSame( 'good', $health->run()['status'] );

		$this->options[ Report::OPTION ] = array(
			'status' => 'done',
			'built'  => time(),
			'users'  => array(
				5 => array( 'type' => 'downgrade' ),
				6 => array( 'type' => 'dormant' ),
			),
		);
		$result = $health->run();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( '2 accounts have more access than they use', $result['label'] );
		$this->assertStringContainsString( 'page=role-right-sizer', $result['actions'] );

		$this->assertArrayHasKey( 'role_right_sizer', $health->add_test( array( 'direct' => array() ) )['direct'] );
	}
}
