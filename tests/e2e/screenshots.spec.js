// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp } = require( './utils' );
const { prepareSite, shot } = require( './utils/media' );
const { seed, clean } = require( './seed' );

const SCREEN = '/wp-admin/users.php?page=role-right-sizer';

test.describe.serial( 'Screenshots', () => {
	test.use( { viewport: { width: 1280, height: 1000 }, deviceScaleFactor: 2 } );

	test.beforeAll( () => {
		prepareSite( 'Kitchen Notes' );
		seed();
		wp( 'role-right-sizer', 'report' );
	} );

	test.afterAll( clean );

	test( '1: the recommendations', async ( { page } ) => {
		await page.goto( SCREEN );
		await expect( page.locator( 'tr[data-user="pat"] .rrs-proposed' ) ).toHaveText( 'Editor' );
		await page.setViewportSize( { width: 1280, height: 1240 } );
		await page.locator( 'tr[data-user="pat"] details' ).nth( 1 ).locator( 'summary' ).click();
		await shot( page, 1 );
	} );

	test( '2: the confirmation step', async ( { page } ) => {
		await page.goto( SCREEN );
		await page.locator( 'tr[data-user="pat"] input[name="users[]"]' ).check();
		await page.locator( 'tr[data-user="olive"] input[name="users[]"]' ).check();
		await page.locator( 'tr.rrs-group[data-role="shop_editor"] input[name="groups[]"]' ).check();
		await page.getByRole( 'button', { name: 'Review 3 selected changes' } ).click();
		await expect( page.getByRole( 'button', { name: 'Apply 3 changes' } ) ).toBeVisible();
		await shot( page, 2 );
	} );

	test( '3: the change log with Undo', async ( { page } ) => {
		await page.goto( SCREEN );
		await page.locator( 'tr[data-user="pat"] input[name="users[]"]' ).check();
		await page.locator( 'tr[data-user="olive"] input[name="users[]"]' ).check();
		await page.locator( 'tr.rrs-group[data-role="shop_editor"] input[name="groups[]"]' ).check();
		await page.getByRole( 'button', { name: 'Review 3 selected changes' } ).click();
		await page.getByRole( 'button', { name: 'Apply 3 changes' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Applied 3 changes.' );
		// Put the role capability back, so the log shows an undone change too.
		await page.locator( '.rrs-changes tbody tr' ).first().getByRole( 'button', { name: 'Undo', exact: true } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Undid 1 change.' );
		await shot( page, 3 );
	} );

	test( '4: the learning period', async ( { page } ) => {
		seed( 12 );
		await page.goto( SCREEN );
		await expect( page.locator( '.rrs-learning' ) ).toBeVisible();
		await shot( page, 4 );
	} );
} );
