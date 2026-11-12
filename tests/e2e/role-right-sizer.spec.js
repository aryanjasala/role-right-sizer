const { test, expect, wp, wpEval, logInAs } = require( './utils' );

const SCREEN = '/wp-admin/users.php?page=role-right-sizer';
const OPTIONS = [ 'role_right_sizer_settings', 'role_right_sizer_report', 'role_right_sizer_changes', 'role_right_sizer_capability_history', 'role_right_sizer_trials' ];
// Capabilities the tests put on core roles, taken off again in reset().
const TEST_CAPS = [ [ 'administrator', 'rrs_e2e_approve' ] ];
const LOGINS = [ 'pat', 'sam', 'olive', 'gus', 'casey', 'jo' ];

const json = ( php ) => JSON.parse( wpEval( `echo wp_json_encode( ${ php } );` ) );
const userId = ( login ) => Number( wp( 'user', 'get', login, '--field=ID' ) );
const roles = ( login ) => wp( 'user', 'get', login, '--field=roles' );
const usage = ( login ) => json( `get_user_option( 'role_right_sizer_usage', ${ userId( login ) } )` ) || {};

/** Starts tracking the given number of days ago. */
const trackingStarted = ( days ) => wpEval( `update_option( 'role_right_sizer_tracking_started', time() - ${ days } * DAY_IN_SECONDS );` );

/**
 * Says a role has had its capabilities since tracking began, in the capability history.
 * Without this, a capability the test just added counts as new and is never called unused.
 *
 * @param {string}   role Role name.
 * @param {string[]} keep Capabilities to leave as new.
 */
const settle = ( role, keep = [] ) =>
	wpEval( `
		$history = get_option( 'role_right_sizer_capability_history', array() );
		$started = (int) get_option( 'role_right_sizer_tracking_started' );
		foreach ( array_keys( (array) ( $history['${ role }'] ?? array() ) ) as $cap ) {
			if ( ! in_array( $cap, json_decode( '${ JSON.stringify( keep ) }', true ), true ) ) {
				$history['${ role }'][ $cap ][0] = $started;
			}
		}
		update_option( 'role_right_sizer_capability_history', $history, false );
	` );

/** Report rows from WP-CLI, without the unused capability rows other plugins in a shared lab may add. */
const userRows = ( report ) => report.filter( ( row ) => row.type !== 'unused_cap' );

/** Makes an account look registered long ago, and optionally last active a while back. */
const backdate = ( login, { registered = 200, active = 0 } = {} ) => {
	const id = userId( login );
	wpEval( `
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - ${ registered } * DAY_IN_SECONDS ) ), array( 'ID' => ${ id } ) );
		clean_user_cache( ${ id } );
		if ( ${ active } ) { update_user_option( ${ id }, 'role_right_sizer_last_active', time() - ${ active } * DAY_IN_SECONDS ); }
	` );
};

const createUser = ( login, role ) => Number( wp( 'user', 'create', login, `${ login }@example.com`, `--role=${ role }`, '--user_pass=password', `--display_name=${ login[ 0 ].toUpperCase() + login.slice( 1 ) }`, '--porcelain' ) );

const reset = () => {
	for ( const option of OPTIONS ) {
		try {
			wp( 'option', 'delete', option );
		} catch ( error ) {
			// Not set.
		}
	}
	const existing = wp( 'user', 'list', '--field=user_login' ).split( '\n' ).filter( ( login ) => LOGINS.includes( login ) );
	if ( existing.length ) {
		wp( 'user', 'delete', ...existing, '--yes', '--reassign=1' );
	}
	try {
		wp( 'role', 'delete', 'shop_editor' );
	} catch ( error ) {
		// No such role.
	}
	for ( const [ role, cap ] of TEST_CAPS ) {
		wpEval( `get_role( '${ role }' )->remove_cap( '${ cap }' );` );
	}
	trackingStarted( 0 );
	// The test admin has used the screen and settings for a while, like a real one would have.
	wpEval( `foreach ( array( 'role_right_sizer_usage', 'role_right_sizer_abilities', 'role_right_sizer_last_active', 'role_right_sizer_trial' ) as $key ) { delete_user_option( 1, $key ); }
		delete_user_meta( 1, 'role_right_sizer_steps_dismissed' );
		AryanX\\Axle\\RoleRightSizer\\Plugin::instance()->store()->add_usage( 1, array( 'promote_users', 'manage_options', 'activate_plugins', 'list_users' ), time(), 3600 );
		update_user_option( 1, 'role_right_sizer_last_active', time() );` );
};

/**
 * Logs in as another user in a fresh browser context.
 *
 * @return {Promise<{context: import('@playwright/test').BrowserContext, page: import('@playwright/test').Page, rest: Function}>}
 */
async function loginAs( browser, login ) {
	const { context, page } = await logInAs( browser, login, 'password' );
	await page.waitForURL( /wp-admin/ );
	const nonce = ( await ( await page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
	const rest = async ( method, route, data ) => {
		const response = await page.request.fetch( `/index.php?rest_route=${ encodeURIComponent( route ) }`, { method, headers: { 'X-WP-Nonce': nonce }, data } );
		return { status: response.status(), body: await response.json().catch( () => null ) };
	};
	return { context, page, rest };
}

/**
 * Pat is an administrator who only does an editor's work: writes and publishes,
 * edits Sam's post, adds a category and approves a comment.
 */
async function patWorksLikeAnEditor( browser ) {
	createUser( 'pat', 'administrator' );
	createUser( 'sam', 'author' );
	const samsPost = Number( wp( 'post', 'create', '--post_title=Sam on sourdough', '--post_status=publish', `--post_author=${ userId( 'sam' ) }`, '--porcelain' ) );
	const comment = Number( wp( 'comment', 'create', `--comment_post_ID=${ samsPost }`, '--comment_content=Lovely loaf', '--comment_approved=0', '--porcelain' ) );

	const pat = await loginAs( browser, 'pat' );
	await pat.page.goto( '/wp-admin/edit.php' );
	expect( ( await pat.rest( 'POST', '/wp/v2/posts', { title: 'Spring menu', status: 'publish' } ) ).status ).toBe( 201 );
	expect( ( await pat.rest( 'POST', `/wp/v2/posts/${ samsPost }`, { title: 'Sam on sourdough, updated' } ) ).status ).toBe( 200 );
	expect( ( await pat.rest( 'POST', `/wp/v2/posts/${ samsPost }`, { title: 'Sam on sourdough, updated again' } ) ).status ).toBe( 200 );
	expect( ( await pat.rest( 'POST', '/wp/v2/categories', { name: `Recipes ${ Date.now() }` } ) ).status ).toBe( 201 );
	expect( ( await pat.rest( 'POST', `/wp/v2/comments/${ comment }`, { status: 'approved' } ) ).status ).toBe( 200 );
	await pat.context.close();
}

test.describe( 'Role Right-Sizer', () => {
	test.beforeEach( reset );
	test.afterAll( reset );

	test( 'records what people use from real requests, once an hour per capability', async ( { browser } ) => {
		await patWorksLikeAnEditor( browser );

		const used = usage( 'pat' );
		for ( const cap of [ 'edit_posts', 'publish_posts', 'edit_others_posts', 'edit_published_posts', 'manage_categories', 'moderate_comments', 'read' ] ) {
			expect( used[ cap ], cap ).toBeTruthy();
		}
		for ( const cap of [ 'manage_options', 'activate_plugins', 'update_core', 'promote_users', 'unfiltered_html' ] ) {
			expect( used[ cap ], cap ).toBeUndefined();
		}
		// Two edits of Sam's post within the hour are written once.
		expect( used.edit_others_posts[ 2 ] ).toBe( 1 );
		expect( Number( wpEval( `echo get_user_meta( ${ userId( 'pat' ) }, 'role_right_sizer_last_login', true );` ) ) ).toBeGreaterThan( 0 );
		expect( Number( wpEval( `echo get_user_option( 'role_right_sizer_last_active', ${ userId( 'pat' ) } );` ) ) ).toBeGreaterThan( 0 );

		// The same through WP-CLI.
		const rows = JSON.parse( wp( 'role-right-sizer', 'usage', 'pat', '--format=json' ) );
		expect( rows.find( ( row ) => row.name === 'publish_posts' ) ).toMatchObject( { kind: 'capability', in_window: 'yes' } );
	} );

	test( 'keeps recommendations hidden while it learns, and says how long is left', async ( { page } ) => {
		trackingStarted( 3 );
		await page.goto( SCREEN );
		await expect( page.locator( '.rrs-learning h2' ) ).toHaveText( 'Learning how people use the site' );
		await expect( page.locator( '.rrs-learning' ) ).toContainText( 'Recommendations show up in 27 days' );
		await expect( page.locator( '.axle-progress' ) ).toHaveAttribute( 'aria-valuenow', '10' );
		await expect( page.locator( '.rrs-table' ) ).toHaveCount( 0 );
		expect( wpEval( "echo false === get_option( 'role_right_sizer_report' ) ? 'none' : 'saved';" ) ).toBe( 'none' );
		expect( () => wp( 'role-right-sizer', 'apply', '--all', '--yes' ) ).toThrow( /Still learning/ );
	} );

	test( 'recommends Editor for an administrator who only edits, applies it after a confirmation, and undoes it', async ( { browser, page } ) => {
		await patWorksLikeAnEditor( browser );
		trackingStarted( 40 );

		await page.goto( SCREEN );
		const row = page.locator( '.rrs-section--downgrade tr[data-user="pat"]' );
		await expect( row ).toContainText( 'Administrator' );
		await expect( row.locator( '.rrs-proposed' ) ).toContainText( 'Editor' );
		await row.locator( 'details' ).first().locator( 'summary' ).click();
		await expect( row.locator( 'details' ).first() ).toContainText( 'manage_options' );
		await expect( row.locator( 'details' ).first() ).toContainText( 'activate_plugins' );
		// The admin is not recommended anything: they use what only an administrator can do.
		await expect( page.locator( 'tr[data-user="admin"]' ) ).toHaveCount( 0 );

		// The CSV lists every user.
		const [ download ] = await Promise.all( [ page.waitForEvent( 'download' ), page.getByRole( 'link', { name: 'Download CSV' } ).click() ] );
		const csv = require( 'fs' ).readFileSync( await download.path(), 'utf8' );
		expect( csv.split( '\r\n' )[ 0 ] ).toBe( '"user_id","user_login","display_name","roles","recommendation","recommended_role","capabilities_lost","last_login","last_active","used_capabilities"' );
		expect( csv ).toMatch( /"pat","Pat","administrator","downgrade","editor","[^"]*manage_options[^"]*"/ );
		expect( csv ).toMatch( /"sam","Sam","author","",""/ );

		// The review bar counts what is ticked, stays in view, and its label takes a wp.hooks filter.
		const review = page.locator( '.rrs-review-button' );
		await expect( review ).toHaveText( 'Review selected changes' );
		expect( await page.locator( '.rrs-review-bar' ).evaluate( ( bar ) => getComputedStyle( bar ).position ) ).toBe( 'sticky' );
		await row.locator( 'input[name="users[]"]' ).check();
		await expect( review ).toHaveText( 'Review 1 selected change' );
		await page.evaluate( () => {
			window.rrsSelected = [];
			window.wp.hooks.addAction( 'roleRightSizer.selectionChanged', 'e2e', ( count ) => window.rrsSelected.push( count ) );
			window.wp.hooks.addFilter( 'roleRightSizer.reviewLabel', 'e2e', ( label, count ) => `${ label } (filtered ${ count })` );
		} );
		await row.locator( 'input[name="users[]"]' ).uncheck();
		await row.locator( 'input[name="users[]"]' ).check();
		await expect( review ).toHaveText( 'Review 1 selected change (filtered 1)' );
		expect( await page.evaluate( () => window.rrsSelected ) ).toEqual( [ 0, 1 ] );
		await review.click();
		const item = page.locator( '.rrs-confirm-list li' );
		await expect( item ).toContainText( 'Pat (pat)' );
		await expect( item.locator( 'del' ) ).toContainText( 'Administrator' );
		await expect( item.locator( 'ins' ) ).toContainText( 'Editor' );
		await expect( item.locator( '.rrs-lost' ) ).toContainText( 'manage_options' );
		expect( roles( 'pat' ) ).toBe( 'administrator' );

		await page.getByRole( 'button', { name: 'Apply 1 change' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Applied 1 change.' );
		expect( roles( 'pat' ) ).toBe( 'editor' );
		const [ change ] = JSON.parse( wp( 'role-right-sizer', 'changes', '--format=json' ) );
		expect( change ).toMatchObject( { kind: 'downgrade', target: 'pat', before: 'administrator', after: 'editor', undone: '', by: 1 } );

		const logged = page.locator( `.rrs-changes tr[data-change="${ change.id }"]` );
		await expect( logged.locator( 'del' ) ).toContainText( 'Administrator' );
		await expect( logged.locator( 'ins' ) ).toContainText( 'Editor' );
		// Undo asks first, in place, and says what changes.
		await logged.locator( 'summary', { hasText: 'Undo' } ).click();
		await expect( logged.locator( '.axle-confirm' ) ).toContainText( 'changes the role from Editor back to Administrator' );
		expect( roles( 'pat' ) ).toBe( 'editor' );
		await logged.getByRole( 'button', { name: 'Undo this change' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Undid 1 change.' );
		expect( roles( 'pat' ) ).toBe( 'administrator' );
		expect( JSON.parse( wp( 'role-right-sizer', 'changes', '--format=json' ) )[ 0 ].undone ).not.toBe( '' );
		await expect( page.locator( `.rrs-changes tr[data-change="${ change.id }"]` ) ).toContainText( 'Undone' );
	} );

	test( 'flags dormant and never-used accounts, moves them to the fallback role, and undoes the batch', async ( { page } ) => {
		trackingStarted( 150 );
		createUser( 'olive', 'editor' );
		createUser( 'gus', 'author' );
		backdate( 'olive', { active: 120 } );
		backdate( 'gus' );

		const report = JSON.parse( wp( 'role-right-sizer', 'report', '--format=json' ) );
		expect( report.find( ( row ) => row.target === 'olive' ) ).toMatchObject( { type: 'dormant', proposed: 'none' } );
		expect( report.find( ( row ) => row.target === 'gus' ) ).toMatchObject( { type: 'never', proposed: 'none' } );

		await page.goto( SCREEN );
		await expect( page.locator( '.rrs-section--dormant tr[data-user="olive"] .rrs-proposed' ) ).toContainText( 'No role' );
		await expect( page.locator( '.rrs-section--never tr[data-user="gus"] .rrs-proposed' ) ).toContainText( 'No role' );

		await page.goto( `${ SCREEN }&tab=settings` );
		await page.locator( 'input[name="dormant_action"][value="fallback"]' ).check();
		await page.locator( '#rrs-fallback-role' ).selectOption( 'subscriber' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved.' );
		expect( json( "get_option( 'role_right_sizer_settings' )" ) ).toMatchObject( { dormant_action: 'fallback', fallback_role: 'subscriber' } );

		await page.goto( SCREEN );
		await expect( page.locator( 'tr[data-user="olive"] .rrs-proposed' ) ).toContainText( 'Subscriber' );
		await page.locator( 'tr[data-user="olive"] input[name="users[]"]' ).check();
		await page.locator( 'tr[data-user="gus"] input[name="users[]"]' ).check();
		await page.getByRole( 'button', { name: 'Review 2 selected changes' } ).click();
		await page.getByRole( 'button', { name: 'Apply 2 changes' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Applied 2 changes.' );
		expect( roles( 'olive' ) ).toBe( 'subscriber' );
		expect( roles( 'gus' ) ).toBe( 'subscriber' );

		await page.locator( '.notice-success' ).getByRole( 'button', { name: 'Undo all' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Undid 2 changes.' );
		expect( roles( 'olive' ) ).toBe( 'editor' );
		expect( roles( 'gus' ) ).toBe( 'author' );
	} );

	test( 'finds a custom capability nobody on a custom role uses, removes it from the role, and puts it back', async ( { browser, page } ) => {
		wp( 'role', 'create', 'shop_editor', 'Shop Editor' );
		wp( 'cap', 'add', 'shop_editor', 'read', 'edit_posts', 'view_shop_reports' );
		createUser( 'casey', 'shop_editor' );
		const casey = await loginAs( browser, 'casey' );
		expect( ( await casey.rest( 'POST', '/wp/v2/posts', { title: 'New mugs in stock', status: 'draft' } ) ).status ).toBe( 201 );
		await casey.context.close();
		trackingStarted( 40 );
		settle( 'shop_editor' );

		await page.goto( SCREEN );
		const group = page.locator( '.rrs-section--roles tr.rrs-group[data-role="shop_editor"]' );
		await expect( group ).toContainText( 'Shop Editor' );
		await group.locator( 'summary' ).click();
		const row = group.locator( 'li[data-cap="view_shop_reports"]' );
		await expect( row ).toBeVisible();
		await expect( page.locator( '.rrs-section--roles [data-cap="edit_posts"]' ) ).toHaveCount( 0 );
		await row.getByRole( 'link', { name: 'Remove view_shop_reports from Shop Editor' } ).focus();
		await Promise.all( [ page.waitForURL( /view=confirm/ ), page.keyboard.press( 'Enter' ) ] );
		await expect( page.locator( '.rrs-confirm-list li' ) ).toContainText( 'Role: Shop Editor' );
		await expect( page.locator( '.rrs-confirm-list li del' ) ).toContainText( 'view_shop_reports' );
		await page.getByRole( 'button', { name: 'Apply 1 change' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Applied 1 change.' );
		const hasCap = () => wpEval( "echo get_role( 'shop_editor' )->has_cap( 'view_shop_reports' ) ? 'yes' : 'no';" );
		expect( hasCap() ).toBe( 'no' );

		const [ change ] = JSON.parse( wp( 'role-right-sizer', 'changes', '--format=json' ) );
		expect( change ).toMatchObject( { kind: 'unused_cap', target: 'shop_editor', before: 'view_shop_reports' } );
		expect( wp( 'role-right-sizer', 'undo', String( change.id ) ) ).toContain( 'Undid change' );
		expect( hasCap() ).toBe( 'yes' );
	} );

	test( 'never changes your own role, and the last administrator keeps theirs', async ( { page } ) => {
		trackingStarted( 150 );
		createUser( 'jo', 'administrator' );
		backdate( 'jo', { active: 120 } );
		// The admin looks dormant too, so both administrators get a recommendation.
		wpEval( "update_user_option( 1, 'role_right_sizer_last_active', time() - 120 * DAY_IN_SECONDS ); delete_user_meta( 1, 'role_right_sizer_last_login' );" );
		const report = userRows( JSON.parse( wp( 'role-right-sizer', 'report', '--format=json' ) ) );
		expect( report.map( ( row ) => row.target ) ).toEqual( expect.arrayContaining( [ 'admin', 'jo' ] ) );
		// Nothing proposed for the administrator role itself, whatever other plugins put on it.
		expect( JSON.parse( wp( 'role-right-sizer', 'report', '--format=json' ) ).filter( ( row ) => row.type === 'unused_cap' && row.target === 'administrator' ) ).toEqual( [] );

		await page.goto( SCREEN );
		const self = page.locator( 'tr[data-user="admin"]' );
		await expect( self ).toContainText( 'This is you.' );
		await expect( self.locator( 'input[type="checkbox"]' ) ).toHaveCount( 0 );
		await page.goto( `${ SCREEN }&view=confirm&users[]=1` );
		await expect( page.locator( '.rrs-problem' ) ).toHaveText( 'You cannot change your own role here.' );
		await expect( page.getByRole( 'button', { name: /^Apply/ } ) ).toHaveCount( 0 );

		// A forged request is refused too.
		await page.goto( SCREEN );
		const nonce = await page.locator( '.axle-toolbar input[name="_wpnonce"]' ).first().inputValue();
		const forged = await page.request.post( '/wp-admin/admin-post.php', { form: { action: 'role_right_sizer', task: 'apply', _wpnonce: nonce, 'users[]': '1' } } );
		expect( forged.url() ).toContain( 'rrs_errors=self' );
		expect( roles( 'admin' ) ).toBe( 'administrator' );

		// Jo goes, so the admin is the last administrator and keeps the role, even from WP-CLI.
		expect( wp( 'role-right-sizer', 'apply', 'jo' ) ).toContain( 'Applied 1 change' );
		expect( roles( 'jo' ) ).toBe( '' );
		wpEval( "update_user_option( 1, 'role_right_sizer_last_active', time() - 120 * DAY_IN_SECONDS );" );
		expect( () => wp( 'role-right-sizer', 'apply', 'admin' ) ).toThrow( /last administrator/ );
		expect( roles( 'admin' ) ).toBe( 'administrator' );
		expect( userRows( JSON.parse( wp( 'role-right-sizer', 'report', '--format=json' ) ) ).map( ( row ) => row.target ) ).not.toContain( 'admin' );

		const [ change ] = JSON.parse( wp( 'role-right-sizer', 'changes', '--format=json' ) );
		wp( 'role-right-sizer', 'undo', String( change.id ), '--batch' );
		expect( roles( 'jo' ) ).toBe( 'administrator' );
	} );

	test( 'WP-CLI applies everything with a dry run first, and forget deletes a user\'s record', async ( { browser } ) => {
		await patWorksLikeAnEditor( browser );
		trackingStarted( 40 );

		const dry = wp( 'role-right-sizer', 'apply', '--all', '--dry-run' );
		expect( dry ).toContain( 'pat: administrator -> editor. Loses:' );
		expect( roles( 'pat' ) ).toBe( 'administrator' );

		// Everything the report lists, except capabilities that belong to an active plugin.
		const expected = JSON.parse( wp( 'role-right-sizer', 'report', '--format=json' ) ).filter( ( row ) => row.type !== 'unused_cap' || row.owner_active !== 'yes' ).length;
		expect( wp( 'role-right-sizer', 'apply', '--all', '--yes' ) ).toContain( `Applied ${ expected } change(s)` );
		expect( roles( 'pat' ) ).toBe( 'editor' );
		const [ change ] = JSON.parse( wp( 'role-right-sizer', 'changes', '--format=json' ) );
		expect( change.by ).toBe( 0 );
		wp( 'role-right-sizer', 'undo', String( change.id ), '--batch' );
		expect( roles( 'pat' ) ).toBe( 'administrator' );

		expect( Object.keys( usage( 'pat' ) ).length ).toBeGreaterThan( 3 );
		wp( 'role-right-sizer', 'forget', 'pat' );
		expect( usage( 'pat' ) ).toEqual( {} );
		expect( wpEval( `echo get_user_meta( ${ userId( 'pat' ) }, 'role_right_sizer_last_login', true );` ) ).toBe( '' );
	} );

	test( 'never suggests taking capabilities from administrators, new ones, or an active plugin\'s without asking', async ( { browser, page } ) => {
		// What QA found: plugin capabilities on Administrator and ones added minutes ago were listed as unused,
		// and apply --all took them away.
		wp( 'cap', 'add', 'administrator', 'rrs_e2e_approve' );
		wp( 'role', 'create', 'shop_editor', 'Shop Editor' );
		wp( 'cap', 'add', 'shop_editor', 'read', 'edit_posts', 'view_shop_reports', 'export_orders', 'role_right_sizer_reports' );
		createUser( 'casey', 'shop_editor' );
		const casey = await loginAs( browser, 'casey' );
		expect( ( await casey.rest( 'POST', '/wp/v2/posts', { title: 'New mugs in stock', status: 'draft' } ) ).status ).toBe( 201 );
		await casey.context.close();
		trackingStarted( 40 );
		settle( 'administrator' );
		settle( 'shop_editor' );
		// A capability a plugin added today.
		wp( 'cap', 'add', 'shop_editor', 'print_labels' );
		const history = json( "get_option( 'role_right_sizer_capability_history' )" );
		expect( history.shop_editor.print_labels[ 0 ] ).toBeGreaterThan( history.shop_editor.view_shop_reports[ 0 ] );

		const caps = JSON.parse( wp( 'role-right-sizer', 'report', '--format=json' ) ).filter( ( row ) => row.type === 'unused_cap' );
		expect( caps.filter( ( row ) => row.target === 'administrator' ) ).toEqual( [] );
		const mine = caps.filter( ( row ) => row.target === 'shop_editor' );
		expect( mine.map( ( row ) => row.lost ).sort() ).toEqual( [ 'export_orders', 'role_right_sizer_reports', 'view_shop_reports' ] );
		// Its name says it belongs to an active plugin (this one, for the test).
		expect( mine.find( ( row ) => row.lost === 'role_right_sizer_reports' ) ).toMatchObject( { owner: 'Role Right-Sizer', owner_active: 'yes' } );
		expect( mine.find( ( row ) => row.lost === 'view_shop_reports' ) ).toMatchObject( { owner: '', owner_active: '' } );

		// The screen groups them by role and owner, and marks the active plugin's.
		await page.goto( SCREEN );
		await expect( page.locator( '.rrs-section--roles' ) ).not.toContainText( 'rrs_e2e_approve' );
		await expect( page.locator( '.rrs-section--roles' ) ).not.toContainText( 'print_labels' );
		const plugin = page.locator( 'tr.rrs-group[data-role="shop_editor"][data-owner^="plugin:"]' );
		await expect( plugin.locator( '.rrs-owner' ) ).toHaveText( 'Belongs to Role Right-Sizer, which is active.' );
		const leftovers = page.locator( 'tr.rrs-group[data-role="shop_editor"][data-owner=""]' );
		await expect( leftovers.locator( 'summary' ) ).toHaveText( '2 capabilities' );
		// One tick selects every capability in the group.
		await leftovers.locator( 'input[name="groups[]"]' ).check();
		await expect( page.locator( '.rrs-review-button' ) ).toHaveText( 'Review 2 selected changes' );
		await page.locator( '.rrs-review-button' ).click();
		await expect( page.locator( '.rrs-confirm-list li' ) ).toHaveCount( 2 );
		await expect( page.getByRole( 'button', { name: 'Apply 2 changes' } ) ).toBeVisible();

		// A forged request for the administrator role is refused.
		await page.goto( `${ SCREEN }&view=confirm&caps[]=administrator|rrs_e2e_approve` );
		await expect( page.locator( '.rrs-problem' ) ).toContainText( 'never removed from the administrator role' );

		// Site Health: leftover capabilities are housekeeping, not a security recommendation,
		// and the active plugin's capability is not counted.
		const health = JSON.parse(
			wpEval( `
				$tests = apply_filters( 'site_status_tests', array() );
				echo wp_json_encode( call_user_func( $tests['direct']['role_right_sizer']['test'] ) );
			` )
		);
		expect( health.status ).toBe( 'good' );
		expect( health.description ).toContain( '2 capabilities on roles were never used' );

		// apply --all leaves the active plugin's capability alone unless asked.
		expect( wp( 'role-right-sizer', 'apply', '--all', '--dry-run' ) ).toContain( 'Role shop_editor: left role_right_sizer_reports alone. It belongs to Role Right-Sizer, which is active.' );
		wp( 'role-right-sizer', 'apply', '--all', '--yes' );
		const has = ( role, cap ) => wpEval( `echo get_role( '${ role }' )->has_cap( '${ cap }' ) ? 'yes' : 'no';` );
		expect( has( 'shop_editor', 'view_shop_reports' ) ).toBe( 'no' );
		expect( has( 'shop_editor', 'role_right_sizer_reports' ) ).toBe( 'yes' );
		expect( has( 'shop_editor', 'print_labels' ) ).toBe( 'yes' );
		expect( has( 'administrator', 'rrs_e2e_approve' ) ).toBe( 'yes' );
		let [ change ] = JSON.parse( wp( 'role-right-sizer', 'changes', '--format=json' ) );
		wp( 'role-right-sizer', 'undo', String( change.id ), '--batch' );
		expect( has( 'shop_editor', 'view_shop_reports' ) ).toBe( 'yes' );

		wp( 'role-right-sizer', 'apply', '--all', '--include-plugin-caps', '--yes' );
		expect( has( 'shop_editor', 'role_right_sizer_reports' ) ).toBe( 'no' );
		[ change ] = JSON.parse( wp( 'role-right-sizer', 'changes', '--format=json' ) );
		wp( 'role-right-sizer', 'undo', String( change.id ), '--batch' );
		expect( has( 'shop_editor', 'role_right_sizer_reports' ) ).toBe( 'yes' );

		// A capability put back with Undo counts as new again, so it is not suggested again for a while.
		expect( JSON.parse( wp( 'role-right-sizer', 'report', '--format=json' ) ).filter( ( row ) => row.target === 'shop_editor' ).map( ( row ) => row.lost ) ).toEqual( [] );
	} );

	test( 'the personal data exporter and eraser cover usage and role changes, and Site Health points to the screen', async ( { browser, page } ) => {
		await patWorksLikeAnEditor( browser );
		trackingStarted( 40 );
		wp( 'role-right-sizer', 'apply', 'pat' );

		const privacy = ( tool ) =>
			JSON.parse(
				wpEval( `
					$tools = apply_filters( 'wp_privacy_personal_data_${ tool }s', array() );
					echo wp_json_encode( call_user_func( $tools['role-right-sizer']['callback'], 'pat@example.com', 1 ) );
				` )
			);
		const exported = privacy( 'exporter' );
		const groups = exported.data.map( ( item ) => item.group_id );
		expect( groups ).toContain( 'role-right-sizer-activity' );
		expect( groups ).toContain( 'role-right-sizer-changes' );
		expect( exported.data.filter( ( item ) => item.group_id === 'role-right-sizer-usage' ).length ).toBeGreaterThan( 3 );

		// Site Health, with a recommendation waiting (Pat is an editor now, Sam has none, so recreate one).
		wp( 'user', 'set-role', 'pat', 'administrator' );
		wp( 'role-right-sizer', 'report' );
		await page.goto( '/wp-admin/site-health.php' );
		await expect( page.getByText( '1 account has more access than it uses' ).first() ).toBeVisible();

		expect( privacy( 'eraser' ) ).toMatchObject( { items_removed: true, done: true } );
		expect( usage( 'pat' ) ).toEqual( {} );
		expect( JSON.parse( wp( 'role-right-sizer', 'changes', '--format=json' ) ) ).toEqual( [] );
	} );

	test( 'abilities: an agent reads the recommendations and a user\'s usage, applies one and undoes it', async ( { browser, rest } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		await patWorksLikeAnEditor( browser );
		trackingStarted( 40 );
		const path = ( name ) => `/wp-abilities/v1/abilities/role-right-sizer/${ name }/run`;

		const listed = await rest( 'GET', '/wp-abilities/v1/abilities?category=role-right-sizer' );
		expect( listed.status ).toBe( 200 );
		expect( listed.body.map( ( ability ) => ability.name ).sort() ).toEqual( [
			'role-right-sizer/apply-recommendation',
			'role-right-sizer/extend-trial',
			'role-right-sizer/get-changes',
			'role-right-sizer/get-recommendations',
			'role-right-sizer/get-user-usage',
			'role-right-sizer/keep-trial',
			'role-right-sizer/list-trials',
			'role-right-sizer/restore-trial',
			'role-right-sizer/start-trial',
			'role-right-sizer/undo-change',
		] );
		// MCP clients only list abilities marked public.
		expect( json( "array_values( array_filter( array_map( function ( $ability ) { $meta = $ability->get_meta(); return ( ! empty( $meta['public'] ) && ! empty( $meta['mcp']['public'] ) ) ? '' : $ability->get_name(); }, wp_get_abilities() ), function ( $name ) { return 0 === strpos( $name, 'role-right-sizer/' ); } ) )" ) ).toEqual( [] );

		const recommendations = await rest( 'GET', path( 'get-recommendations' ) );
		expect( recommendations.status ).toBe( 200 );
		expect( recommendations.body.learning ).toBe( false );
		expect( recommendations.body.users ).toEqual( [ expect.objectContaining( { login: 'pat', type: 'downgrade', current: [ 'administrator' ], proposed: 'editor' } ) ] );

		const used = await rest( 'GET', `${ path( 'get-user-usage' ) }?input[user]=pat` );
		expect( used.status ).toBe( 200 );
		expect( used.body.capabilities.map( ( cap ) => cap.name ) ).toContain( 'publish_posts' );
		expect( used.body.recommendation ).toMatchObject( { proposed: 'editor' } );

		const self = await rest( 'POST', path( 'apply-recommendation' ), { input: { user_id: 1 } } );
		expect( self.status ).toBe( 400 );

		const applied = await rest( 'POST', path( 'apply-recommendation' ), { input: { user_id: userId( 'pat' ) } } );
		expect( applied.status ).toBe( 200 );
		expect( applied.body.changes[ 0 ] ).toMatchObject( { kind: 'downgrade', before: [ 'administrator' ], after: [ 'editor' ] } );
		expect( roles( 'pat' ) ).toBe( 'editor' );

		const undone = await rest( 'POST', path( 'undo-change' ), { input: { change_id: applied.body.changes[ 0 ].id } } );
		expect( undone.status ).toBe( 200 );
		expect( roles( 'pat' ) ).toBe( 'administrator' );
		expect( ( await rest( 'POST', path( 'undo-change' ), { input: { change_id: applied.body.changes[ 0 ].id } } ) ).status ).toBe( 400 );

		// Ability runs are recorded per user.
		expect( Object.keys( json( "get_user_option( 'role_right_sizer_abilities', 1 )" ) ) ).toEqual( expect.arrayContaining( [ 'role-right-sizer/get-recommendations', 'role-right-sizer/apply-recommendation' ] ) );
	} );

	test( 'tries the smaller role, records what the person could not do, and gives the old role back', async ( { browser, page } ) => {
		await patWorksLikeAnEditor( browser );
		trackingStarted( 40 );

		await page.goto( `${ SCREEN }&tab=trials` );
		await expect( page.locator( '.rrs-empty' ) ).toContainText( 'No trials yet.' );

		await page.goto( SCREEN );
		const row = page.locator( '.rrs-section--downgrade tr[data-user="pat"]' );
		await row.hover();
		await row.getByRole( 'link', { name: 'Try for 14 days' } ).click();
		await expect( page.locator( '.rrs-confirm' ) ).toContainText( 'These people get the smaller role for 14 days' );
		expect( roles( 'pat' ) ).toBe( 'administrator' );
		await page.getByRole( 'button', { name: 'Try for 14 days' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Started 1 trial for 14 days' );
		expect( roles( 'pat' ) ).toBe( 'editor' );
		const [ started ] = JSON.parse( wp( 'role-right-sizer', 'trial', 'list', '--format=json' ) );
		expect( started ).toMatchObject( { user: 'pat', status: 'active', days_left: 14, before: 'administrator', during: 'editor', denials: 0 } );
		// The trial is in the change log, so Undo works too.
		expect( JSON.parse( wp( 'role-right-sizer', 'changes', '--format=json' ) )[ 0 ] ).toMatchObject( { kind: 'downgrade', target: 'pat', after: 'editor' } );

		// Pat hits two walls: a screen the menu refuses, and a REST call that creates a user.
		const pat = await loginAs( browser, 'pat' );
		await pat.page.goto( '/wp-admin/plugins.php' );
		await expect( pat.page.locator( '.role-right-sizer-wall' ) ).toContainText( 'Your access was reduced' );
		await expect( pat.page.locator( '.role-right-sizer-wall' ) ).toContainText( 'Ask for it back.' );
		const refused = await pat.rest( 'POST', '/wp/v2/users', { username: 'rrs_new', email: 'rrs_new@example.com', password: 'Passw0rd!x1y2' } );
		expect( refused.status ).toBe( 403 );
		expect( refused.body.role_right_sizer_trial ).toContain( 'Ask for it back.' );
		// Reading a screen they may open, and an expired link, are not walls.
		await pat.page.goto( '/wp-admin/edit.php' );
		const denials = () => JSON.parse( wp( 'role-right-sizer', 'trial', 'denials', String( started.id ), '--format=json' ) );
		expect( denials().map( ( denial ) => denial.what ).sort() ).toEqual( [ 'Could not add users', 'Tried to open Plugins' ] );

		// Asking for the access back tells whoever started the trial.
		await pat.page.goto( '/wp-admin/plugins.php' );
		await pat.page.getByRole( 'button', { name: 'Request my access back' } ).click();
		await expect( pat.page.locator( '.notice-success' ) ).toContainText( 'Your request was sent' );
		await pat.page.goto( '/wp-admin/plugins.php' );
		await expect( pat.page.locator( '.role-right-sizer-wall' ) ).toContainText( 'You already asked' );
		await pat.context.close();

		await page.goto( `${ SCREEN }&tab=trials` );
		const trial = page.locator( `tr[data-trial="${ started.id }"]` );
		await expect( trial ).toContainText( 'Pat' );
		await expect( trial ).toContainText( 'Tried to open Plugins' );
		await expect( trial ).toContainText( 'Could not add users' );
		await expect( trial ).toContainText( 'Asked for access back' );
		await expect( trial ).toContainText( '14 days left' );
		await expect( trial.locator( 'del' ) ).toContainText( 'Administrator' );
		await expect( trial.locator( 'ins' ) ).toContainText( 'Editor' );

		// Extend by a click, restore after a confirmation that says what changes.
		await trial.getByRole( 'button', { name: 'Extend by 14 days' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Extended the trial.' );
		expect( JSON.parse( wp( 'role-right-sizer', 'trial', 'list', '--format=json' ) )[ 0 ].days_left ).toBe( 28 );
		await page.evaluate( () => {
			window.rrsConfirm = [];
			window.wp.hooks.addAction( 'roleRightSizer.confirmOpened', 'e2e', ( details ) => window.rrsConfirm.push( details.querySelector( 'summary' ).textContent.trim() ) );
		} );
		await trial.locator( 'summary', { hasText: 'Restore now' } ).click();
		await expect( trial.locator( '.axle-confirm' ).first() ).toContainText( 'back to Administrator now and ends the trial' );
		expect( roles( 'pat' ) ).toBe( 'editor' );
		expect( await page.evaluate( () => window.rrsConfirm ) ).toEqual( [ 'Restore now' ] );
		await expect( trial.locator( '.axle-confirm' ).first().getByRole( 'button', { name: 'Restore the old role' } ) ).toBeFocused();
		await trial.getByRole( 'button', { name: 'Restore the old role' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Gave the old role back' );
		// The result is announced to screen readers.
		await expect( page.locator( '#a11y-speak-polite' ) ).toContainText( 'Gave the old role back' );
		expect( roles( 'pat' ) ).toBe( 'administrator' );
		await expect( page.locator( `tr[data-trial="${ started.id }"]` ) ).toContainText( 'Old role restored' );
		expect( JSON.parse( wp( 'role-right-sizer', 'trial', 'list', '--format=json' ) )[ 0 ].status ).toBe( 'restored' );
		expect( JSON.parse( wp( 'role-right-sizer', 'changes', '--format=json' ) )[ 0 ].undone ).not.toBe( '' );
	} );

	test( 'a trial that ends waits for a decision, or keeps itself only when nothing stopped the person', async ( { browser } ) => {
		await patWorksLikeAnEditor( browser );
		trackingStarted( 40 );
		const endNow = ( id ) => wpEval( `
			$log = get_option( 'role_right_sizer_trials' );
			foreach ( $log['items'] as $i => $trial ) { if ( ${ id } === (int) $trial['id'] ) { $log['items'][ $i ]['ends'] = time() - 60; } }
			update_option( 'role_right_sizer_trials', $log, false );
			AryanX\\Axle\\RoleRightSizer\\Plugin::instance()->trials()->check();
		` );
		const list = () => JSON.parse( wp( 'role-right-sizer', 'trial', 'list', '--format=json' ) );

		// With the default setting a trial that ends with no denials asks.
		expect( wp( 'role-right-sizer', 'trial', 'start', 'pat' ) ).toContain( 'Started 1 trial(s).' );
		expect( () => wp( 'role-right-sizer', 'trial', 'start', 'pat' ) ).toThrow( /already on a trial/ );
		endNow( 1 );
		expect( list()[ 0 ] ).toMatchObject( { status: 'ended', days_left: 0 } );
		expect( roles( 'pat' ) ).toBe( 'editor' );
		const health = JSON.parse( wpEval( `
			$tests = apply_filters( 'site_status_tests', array() );
			echo wp_json_encode( call_user_func( $tests['direct']['role_right_sizer']['test'] ) );
		` ) );
		expect( health.label ).toContain( 'trial is over and waiting for a decision' );
		wp( 'role-right-sizer', 'trial', 'extend', '1', '--days=2' );
		expect( list()[ 0 ] ).toMatchObject( { status: 'active', days_left: 2 } );
		expect( wp( 'role-right-sizer', 'trial', 'restore', '1' ) ).toContain( 'old role back' );
		expect( roles( 'pat' ) ).toBe( 'administrator' );

		// The setting makes a clean trial permanent, but a trial with denials is never closed on its own.
		wp( 'role-right-sizer', 'settings', '--trial-end-action=keep' );
		wp( 'role-right-sizer', 'trial', 'start', 'pat', '--days=3' );
		const pat = await loginAs( browser, 'pat' );
		await pat.page.goto( '/wp-admin/plugins.php' );
		await pat.context.close();
		endNow( 2 );
		expect( list()[ 0 ] ).toMatchObject( { status: 'ended', denials: 1 } );
		expect( () => wp( 'role-right-sizer', 'trial', 'keep', '99' ) ).toThrow( /does not exist/ );
		wp( 'role-right-sizer', 'trial', 'keep', '2' );
		expect( list()[ 0 ].status ).toBe( 'kept' );
		expect( roles( 'pat' ) ).toBe( 'editor' );
		const change = JSON.parse( wp( 'role-right-sizer', 'changes', '--format=json' ) )[ 0 ];
		wp( 'role-right-sizer', 'undo', String( change.id ) );
		expect( roles( 'pat' ) ).toBe( 'administrator' );

		wp( 'role-right-sizer', 'trial', 'start', 'pat', '--days=3' );
		endNow( 3 );
		expect( list()[ 0 ] ).toMatchObject( { status: 'kept' } );
		expect( roles( 'pat' ) ).toBe( 'editor' );
		wp( 'role-right-sizer', 'settings', '--trial-end-action=ask' );
	} );

	test( 'abilities: an agent starts, lists, extends, restores and cannot keep a closed trial', async ( { browser, rest } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		await patWorksLikeAnEditor( browser );
		trackingStarted( 40 );
		const path = ( name ) => `/wp-abilities/v1/abilities/role-right-sizer/${ name }/run`;

		const started = await rest( 'POST', path( 'start-trial' ), { input: { user_id: userId( 'pat' ), days: 5 } } );
		expect( started.status ).toBe( 200 );
		expect( started.body.trial ).toMatchObject( { login: 'pat', status: 'active', days_left: 5, before: [ 'administrator' ], during: [ 'editor' ] } );
		expect( roles( 'pat' ) ).toBe( 'editor' );

		const open = await rest( 'GET', `${ path( 'list-trials' ) }?input[status]=open` );
		expect( open.body.trials.map( ( trial ) => trial.login ) ).toEqual( [ 'pat' ] );
		const changes = await rest( 'GET', path( 'get-changes' ) );
		expect( changes.body.changes[ 0 ] ).toMatchObject( { kind: 'downgrade', after: [ 'editor' ] } );

		const extended = await rest( 'POST', path( 'extend-trial' ), { input: { trial_id: started.body.trial.id, days: 3 } } );
		expect( extended.body.trial.days_left ).toBe( 8 );
		const restored = await rest( 'POST', path( 'restore-trial' ), { input: { trial_id: started.body.trial.id } } );
		expect( restored.body.trial.status ).toBe( 'restored' );
		expect( roles( 'pat' ) ).toBe( 'administrator' );
		expect( ( await rest( 'POST', path( 'keep-trial' ), { input: { trial_id: started.body.trial.id } } ) ).status ).toBe( 400 );
		expect( ( await rest( 'POST', path( 'start-trial' ), { input: { user_id: 1 } } ) ).status ).toBe( 400 );
	} );

	test( 'a first visit gets a Get started card, a Help tab and a link in the plugin row', async ( { page } ) => {
		trackingStarted( 3 );
		await page.goto( SCREEN );
		const steps = page.locator( '.rrs-steps .axle-step' );
		await expect( steps ).toHaveCount( 3 );
		await expect( page.locator( '.rrs-steps .axle-badge--good' ) ).toHaveCount( 0 );
		await page.getByRole( 'button', { name: 'Dismiss this card' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Dismissed.' );
		await expect( page.locator( '.rrs-steps' ) ).toHaveCount( 0 );
		expect( wpEval( "echo get_user_meta( 1, 'role_right_sizer_steps_dismissed', true );" ) ).toBe( '1' );

		await page.locator( '#contextual-help-link' ).click();
		for ( const tab of [ 'overview', 'recommendations', 'trials', 'changes', 'settings' ] ) {
			await expect( page.locator( `#tab-link-role-right-sizer-${ tab }` ) ).toBeVisible();
		}

		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( 'tr[data-slug="role-right-sizer"] .row-actions, tr[data-plugin^="role-right-sizer/"] .row-actions' ).getByRole( 'link', { name: 'Open Right-Sizer' } ) ).toHaveAttribute( 'href', /page=role-right-sizer/ );
	} );
} );
