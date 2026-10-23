/**
 * Users > Right-Sizer: the review bar count and the group checkboxes.
 *
 * Without this script the screen still works: the review button sends what is
 * ticked, and a ticked group stands for every capability in it.
 *
 * JS hooks (through wp.hooks, read on each use so later scripts can add them):
 *
 * - filter roleRightSizer.reviewLabel ( label, count, form ): the text of the
 *   review button for this many selected changes.
 * - action roleRightSizer.selectionChanged ( count, form ): what is ticked changed.
 */
( function () {
	'use strict';

	function hooks() {
		return window.wp && window.wp.hooks ? window.wp.hooks : null;
	}

	function i18n() {
		return window.wp && window.wp.i18n ? window.wp.i18n : null;
	}

	/**
	 * How many changes are selected: one per user, one per capability.
	 *
	 * @param {HTMLFormElement} form The recommendations form.
	 * @return {number} Count.
	 */
	function count( form ) {
		let total = form.querySelectorAll( 'input[name="users[]"]:checked' ).length;
		form.querySelectorAll( '.rrs-group' ).forEach( function ( group ) {
			const toggle = group.querySelector( 'input[name="groups[]"]' );
			const caps = group.querySelectorAll( 'input[name="caps[]"]' );
			total += toggle && toggle.checked ? caps.length : group.querySelectorAll( 'input[name="caps[]"]:checked' ).length;
		} );
		return total;
	}

	/**
	 * Puts the count on the review button.
	 *
	 * @param {HTMLFormElement} form The recommendations form.
	 */
	function update( form ) {
		const button = form.querySelector( '.rrs-review-button' );
		if ( ! button ) {
			return;
		}

		const selected = count( form );
		const t = i18n();
		let label = button.getAttribute( 'data-label' ) || button.textContent;
		if ( selected && t ) {
			label = t.sprintf(
				/* translators: %s: number of selected changes. */
				t._n( 'Review %s selected change', 'Review %s selected changes', selected, 'role-right-sizer' ),
				selected.toLocaleString()
			);
		}

		const h = hooks();
		if ( h ) {
			label = h.applyFilters( 'roleRightSizer.reviewLabel', label, selected, form );
		}
		button.textContent = label;
		form.classList.toggle( 'has-selection', selected > 0 );

		if ( h ) {
			h.doAction( 'roleRightSizer.selectionChanged', selected, form );
		}
	}

	/**
	 * Matches a group checkbox to its capabilities.
	 *
	 * @param {Element} group The group row.
	 */
	function syncToggle( group ) {
		const toggle = group.querySelector( 'input[name="groups[]"]' );
		const caps = group.querySelectorAll( 'input[name="caps[]"]' );
		const ticked = group.querySelectorAll( 'input[name="caps[]"]:checked' ).length;
		if ( toggle ) {
			toggle.checked = caps.length > 0 && ticked === caps.length;
			toggle.indeterminate = ticked > 0 && ticked < caps.length;
		}
	}

	document.addEventListener( 'change', function ( event ) {
		const form = event.target.closest( '.rrs-form' );
		if ( ! form ) {
			return;
		}

		const group = event.target.closest( '.rrs-group' );
		if ( group && event.target.matches( 'input[name="groups[]"]' ) ) {
			group.querySelectorAll( 'input[name="caps[]"]' ).forEach( function ( box ) {
				box.checked = event.target.checked;
			} );
		} else if ( group && event.target.matches( 'input[name="caps[]"]' ) ) {
			syncToggle( group );
		}

		update( form );
	} );

	// Core's "select all" sets the row checkboxes without a change event.
	document.addEventListener( 'click', function ( event ) {
		const form = event.target.closest( '.rrs-form' );
		if ( ! form || ! event.target.matches( 'thead .check-column input, tfoot .check-column input' ) ) {
			return;
		}

		window.setTimeout( function () {
			const table = event.target.closest( 'table' );
			if ( table ) {
				table.querySelectorAll( '.rrs-group' ).forEach( function ( group ) {
					const toggle = group.querySelector( 'input[name="groups[]"]' );
					group.querySelectorAll( 'input[name="caps[]"]' ).forEach( function ( box ) {
						box.checked = !! ( toggle && toggle.checked );
					} );
				} );
			}
			update( form );
		}, 0 );
	} );

	function init() {
		document.querySelectorAll( '.rrs-form' ).forEach( function ( form ) {
			form.querySelectorAll( '.rrs-group' ).forEach( syncToggle );
			update( form );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
