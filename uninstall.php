<?php
/**
 * Removes Role Right-Sizer's settings, report, change log, capability history,
 * background jobs and every user's recorded usage, on every site.
 *
 * Roles and capabilities stay as they are: changes you applied are not undone.
 *
 * @package RoleRightSizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Role Right-Sizer data from the current site.
 */
function role_right_sizer_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'role_right_sizer_settings', 'role_right_sizer_tracking_started', 'role_right_sizer_report', 'role_right_sizer_changes', 'role_right_sizer_capability_history' ) as $role_right_sizer_option ) {
		delete_option( $role_right_sizer_option );
	}

	wp_clear_scheduled_hook( 'role_right_sizer_build' );
	wp_clear_scheduled_hook( 'role_right_sizer_daily' );

	// Usage is kept per site, under this site's table prefix.
	$role_right_sizer_prefix = $wpdb->get_blog_prefix();
	foreach ( array( 'role_right_sizer_usage', 'role_right_sizer_abilities', 'role_right_sizer_last_active' ) as $role_right_sizer_key ) {
		delete_metadata( 'user', 0, $role_right_sizer_prefix . $role_right_sizer_key, '', true );
	}
}

if ( is_multisite() ) {
	$role_right_sizer_offset = 0;
	do {
		$role_right_sizer_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $role_right_sizer_offset,
			)
		);

		foreach ( $role_right_sizer_sites as $role_right_sizer_site ) {
			switch_to_blog( $role_right_sizer_site );
			role_right_sizer_uninstall_site();
			restore_current_blog();
		}

		$role_right_sizer_offset += 100;
	} while ( $role_right_sizer_sites );
} else {
	role_right_sizer_uninstall_site();
}

// The last login is one key for the whole network.
delete_metadata( 'user', 0, 'role_right_sizer_last_login', '', true );
