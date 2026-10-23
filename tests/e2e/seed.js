// Demo content for the screenshots and the demo GIF: a small team on a food blog,
// with usage recorded over the last few months.
const { wp, wpEval } = require( './utils' );

const PEOPLE = [
	// login, name, role
	[ 'pat', 'Pat Lee', 'administrator' ],
	[ 'olive', 'Olive Grant', 'editor' ],
	[ 'gus', 'Gus Moreno', 'author' ],
	[ 'casey', 'Casey Wu', 'shop_editor' ],
	[ 'nina', 'Nina Shah', 'editor' ],
];

const OPTIONS = [ 'role_right_sizer_settings', 'role_right_sizer_report', 'role_right_sizer_changes', 'role_right_sizer_capability_history' ];

function clean() {
	for ( const option of OPTIONS ) {
		try {
			wp( 'option', 'delete', option );
		} catch ( error ) {
			// Not set.
		}
	}
	const logins = PEOPLE.map( ( person ) => person[ 0 ] );
	const existing = wp( 'user', 'list', '--field=user_login' ).split( '\n' ).filter( ( login ) => logins.includes( login ) );
	if ( existing.length ) {
		wp( 'user', 'delete', ...existing, '--yes', '--reassign=1' );
	}
	try {
		wp( 'role', 'delete', 'shop_editor' );
	} catch ( error ) {
		// No such role.
	}
	wpEval( "update_option( 'role_right_sizer_tracking_started', time() );" );
}

/**
 * Seeds the team and what each person used.
 *
 * @param {number} trackingDays Days since tracking began.
 */
function seed( trackingDays = 120 ) {
	clean();
	wp( 'role', 'create', 'shop_editor', 'Shop Editor' );
	wp( 'cap', 'add', 'shop_editor', 'read', 'edit_posts', 'upload_files', 'view_shop_reports' );
	for ( const [ login, name, role ] of PEOPLE ) {
		wp( 'user', 'create', login, `${ login }@example.com`, `--role=${ role }`, `--display_name=${ name }`, '--user_pass=password' );
	}

	// cap => days since last use, per person; the first use is a few weeks earlier.
	const usage = {
		admin: { read: 0, manage_options: 1, activate_plugins: 6, update_plugins: 6, promote_users: 0, list_users: 2, edit_posts: 3 },
		pat: { read: 0, edit_posts: 0, publish_posts: 1, edit_published_posts: 1, edit_others_posts: 2, manage_categories: 9, moderate_comments: 4, upload_files: 1 },
		casey: { read: 1, edit_posts: 1, upload_files: 3 },
		nina: { read: 0, edit_posts: 0, edit_others_posts: 1, publish_posts: 2, edit_published_posts: 2, moderate_comments: 5, upload_files: 1, manage_categories: 12, edit_pages: 3, publish_pages: 20 },
	};
	const lastActive = { admin: 0, pat: 0, casey: 1, nina: 0, olive: 121 };

	wpEval( `
		update_option( 'role_right_sizer_tracking_started', time() - ${ trackingDays } * DAY_IN_SECONDS );
		// Every role has had its capabilities since tracking began.
		$history = AryanX\\RoleRightSizer\\Plugin::instance()->history()->current();
		foreach ( $history as $role => $caps ) {
			foreach ( array_keys( $caps ) as $cap ) {
				$history[ $role ][ $cap ][0] = time() - ${ trackingDays } * DAY_IN_SECONDS;
			}
		}
		update_option( 'role_right_sizer_capability_history', $history, false );
		$usage  = json_decode( '${ JSON.stringify( usage ) }', true );
		$active = json_decode( '${ JSON.stringify( lastActive ) }', true );
		foreach ( $usage as $login => $caps ) {
			$user   = get_user_by( 'login', $login );
			$record = array();
			foreach ( $caps as $cap => $days ) {
				$last           = time() - $days * DAY_IN_SECONDS - 3 * HOUR_IN_SECONDS;
				$record[ $cap ] = array( $last - 40 * DAY_IN_SECONDS, $last, 12 + $days );
			}
			update_user_option( $user->ID, 'role_right_sizer_usage', $record );
		}
		foreach ( $active as $login => $days ) {
			$user = get_user_by( 'login', $login );
			update_user_option( $user->ID, 'role_right_sizer_last_active', time() - $days * DAY_IN_SECONDS - HOUR_IN_SECONDS );
			update_user_meta( $user->ID, 'role_right_sizer_last_login', time() - $days * DAY_IN_SECONDS - 2 * HOUR_IN_SECONDS );
		}
		global $wpdb;
		foreach ( array( 'olive', 'gus' ) as $login ) {
			$user = get_user_by( 'login', $login );
			$wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ), array( 'ID' => $user->ID ) );
			clean_user_cache( $user->ID );
		}
	` );
}

module.exports = { seed, clean };
