// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, wp } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );
const { seed, clean } = require( './seed' );

test( 'demo: review a recommendation, apply it, undo it', async ( { browser } ) => {
	test.setTimeout( 180_000 );
	prepareSite( 'Kitchen Notes' );
	seed();
	wp( 'role-right-sizer', 'report' );

	try {
		await recordDemo( browser, async ( page, demo ) => {
			await page.goto( '/wp-admin/users.php?page=role-right-sizer' );
			await demo.start();
			await demo.pause( 1500 );

			const pat = page.locator( 'tr[data-user="pat"]' );
			await demo.click( pat.locator( 'details' ).first().locator( 'summary' ), 1500 );
			await demo.click( pat.locator( 'details' ).nth( 1 ).locator( 'summary' ), 1800 );
			await demo.click( pat.locator( 'input[name="users[]"]' ), 400 );
			await demo.click( page.locator( 'tr[data-user="olive"] input[name="users[]"]' ), 600 );
			await demo.click( page.getByRole( 'button', { name: 'Review 2 selected changes' } ), 400 );

			await expect( page.getByRole( 'button', { name: 'Apply 2 changes' } ) ).toBeVisible();
			await demo.moveTo( page.locator( '.rrs-confirm-list li' ).first() );
			await demo.pause( 2200 );
			await demo.click( page.getByRole( 'button', { name: 'Apply 2 changes' } ), 400 );

			await expect( page.locator( '.notice-success' ) ).toContainText( 'Applied 2 changes.' );
			expect( wp( 'user', 'get', 'pat', '--field=roles' ) ).toBe( 'editor' );
			await demo.moveTo( page.locator( '.rrs-changes tbody tr' ).first() );
			await demo.pause( 1800 );

			await demo.click( page.locator( '.notice-success' ).getByRole( 'button', { name: 'Undo all' } ), 400 );
			await expect( page.locator( '.notice-success' ) ).toContainText( 'Undid 2 changes.' );
			expect( wp( 'user', 'get', 'pat', '--field=roles' ) ).toBe( 'administrator' );
			await demo.moveTo( page.locator( '.rrs-changes tbody tr' ).first() );
			await demo.pause( 1800 );
		} );
	} finally {
		clean();
	}
} );
