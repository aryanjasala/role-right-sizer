<?php
/**
 * Site Health check.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer\Health;

use AryanX\RoleRightSizer\Admin\Screen;
use AryanX\RoleRightSizer\Report;
use AryanX\RoleRightSizer\Service;
use AryanX\RoleRightSizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a Role Right-Sizer test to Tools > Site Health: accounts with more
 * access than they use are a security risk worth a look.
 */
final class SiteHealth implements Service {

	/**
	 * Settings and limits.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Saved recommendations.
	 *
	 * @var Report
	 */
	private Report $report;

	/**
	 * Sets up the check.
	 *
	 * @param Settings $settings Settings and limits.
	 * @param Report   $report   Saved recommendations.
	 */
	public function __construct( Settings $settings, Report $report ) {
		$this->settings = $settings;
		$this->report   = $report;
	}

	/**
	 * Registers the test.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Adds the test to the direct tests.
	 *
	 * @param mixed $tests Site Health tests.
	 * @return mixed
	 */
	public function add_test( $tests ) {
		if ( is_array( $tests ) ) {
			$tests['direct']['role_right_sizer'] = array(
				'label' => __( 'Role Right-Sizer', 'role-right-sizer' ),
				'test'  => array( $this, 'run' ),
			);
		}

		return $tests;
	}

	/**
	 * Runs the test from the saved report, without building one.
	 *
	 * @return array
	 */
	public function run(): array {
		$result = array(
			'label'       => __( 'Every account uses the access its role gives it', 'role-right-sizer' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'role-right-sizer' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Role Right-Sizer found no account with more access than it uses.', 'role-right-sizer' ) . '</p>',
			'actions'     => '',
			'test'        => 'role_right_sizer',
		);

		if ( $this->settings->is_learning() ) {
			$result['label']       = __( 'Role Right-Sizer is still learning how accounts are used', 'role-right-sizer' );
			$result['description'] = '<p>' . esc_html(
				sprintf(
					/* translators: %s: date. */
					__( 'Recommendations show up on %s.', 'role-right-sizer' ),
					Screen::date_label( $this->settings->learning_ends() )
				)
			) . '</p>';

			return $result;
		}

		$users = count( $this->report->users() );
		$roles = count( $this->report->roles() );
		if ( ! $users && ! $roles ) {
			return $result;
		}

		$result['status']         = 'recommended';
		$result['badge']['color'] = 'orange';
		$result['label']          = $users
			/* translators: %s: number of accounts. */
			? sprintf( _n( '%s account has more access than it uses', '%s accounts have more access than they use', $users, 'role-right-sizer' ), number_format_i18n( $users ) )
			/* translators: %s: number of capabilities. */
			: sprintf( _n( '%s capability on a role is never used', '%s capabilities on roles are never used', $roles, 'role-right-sizer' ), number_format_i18n( $roles ) );
		$result['description'] = '<p>' . esc_html__( 'Accounts with more access than they need do more damage when a password leaks. Role Right-Sizer recommends a smaller role for each one, and every change can be undone.', 'role-right-sizer' ) . '</p>';
		$result['actions']     = sprintf( '<p><a href="%s">%s</a></p>', esc_url( Screen::url() ), esc_html__( 'See the recommendations', 'role-right-sizer' ) );

		return $result;
	}
}
