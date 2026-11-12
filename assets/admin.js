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
 * - filter roleRightSizer.announce ( message, notice ): the text read out to screen
 *   readers when a result notice shows. Return an empty string to say nothing.
 * - action roleRightSizer.confirmOpened ( details ): an in-place confirmation opened.
 * - action roleRightSizer.confirmClosed ( details ): an in-place confirmation closed.
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

	/**
	 * Reads the result of the last action out to screen readers.
	 */
	function announce() {
		const speak = window.wp && window.wp.a11y ? window.wp.a11y.speak : null;
		if ( ! speak ) {
			return;
		}

		document.querySelectorAll( '.rrs-notice' ).forEach( function ( notice ) {
			const text = notice.querySelector( '.rrs-notice-text' );
			if ( ! text ) {
				return;
			}
			let message = text.textContent.trim();
			const h = hooks();
			if ( h ) {
				message = h.applyFilters( 'roleRightSizer.announce', message, notice );
			}
			if ( message ) {
				speak( message, notice.classList.contains( 'notice-error' ) ? 'assertive' : 'polite' );
			}
		} );
	}

	/**
	 * Moves focus into an in-place confirmation when it opens, and back to its button when it closes.
	 *
	 * @param {HTMLDetailsElement} details The confirmation.
	 */
	function confirmToggled( details ) {
		const h = hooks();
		if ( details.open ) {
			// Only one confirmation is open at a time.
			document.querySelectorAll( 'details.rrs-confirm-inline[open]' ).forEach( function ( other ) {
				if ( other !== details ) {
					other.open = false;
				}
			} );
			const first = details.querySelector( '.axle-confirm button' );
			if ( first ) {
				first.focus();
			}
			if ( h ) {
				h.doAction( 'roleRightSizer.confirmOpened', details );
			}
		} else if ( h ) {
			h.doAction( 'roleRightSizer.confirmClosed', details );
		}
	}

	function closeConfirm( details ) {
		details.open = false;
		const summary = details.querySelector( 'summary' );
		if ( summary ) {
			summary.focus();
		}
	}

	document.addEventListener(
		'toggle',
		function ( event ) {
			if ( event.target.matches && event.target.matches( 'details.rrs-confirm-inline' ) ) {
				confirmToggled( event.target );
			}
		},
		true
	);

	document.addEventListener( 'click', function ( event ) {
		if ( event.target.closest && event.target.closest( '.rrs-confirm-cancel' ) ) {
			closeConfirm( event.target.closest( 'details.rrs-confirm-inline' ) );
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && event.target.closest ) {
			const details = event.target.closest( 'details.rrs-confirm-inline[open]' );
			if ( details ) {
				closeConfirm( details );
			}
		}
	} );

	function init() {
		document.querySelectorAll( '.rrs-form' ).forEach( function ( form ) {
			form.querySelectorAll( '.rrs-group' ).forEach( syncToggle );
			update( form );
		} );
		announce();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
