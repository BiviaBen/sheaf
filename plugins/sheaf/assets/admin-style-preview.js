/**
 * Behaviors for the Style Sets screen (no build step):
 *
 *  - Live preview: as the author fills in the property grid / raw CSS / kind,
 *    update an inline preview without a round-trip. Mirrors the server preview
 *    (Style_Sets_Admin::preview): inline styles show a <span> in a line of text;
 *    block styles show a <p> framed by blank paragraphs. Sample text comes from
 *    the target's data-inline / data-block attributes (server-generated). The
 *    declarations are applied to the author's own preview element only (never
 *    stored from here); the saved value is sanitized server-side.
 *  - Rename toggle: "Rename" in a set's row actions reveals its inline form.
 */
( function () {
	'use strict';

	var SAMPLE_INLINE = 'The quick brown fox jumps over';
	var SAMPLE_BLOCK =
		'The quick brown fox jumps over the lazy dog, then pauses to watch the curious cat slip past.';

	function declarations( form ) {
		var parts = [];
		form.querySelectorAll( 'input[name^="props["]' ).forEach( function ( input ) {
			var match = input.name.match( /^props\[(.+)\]$/ );
			var value = input.value.trim();
			if ( match && value ) {
				parts.push( match[ 1 ] + ': ' + value );
			}
		} );
		var raw = form.querySelector( '[name="css"]' );
		var rawValue = raw ? raw.value.trim() : '';
		var decls = parts.join( '; ' );
		if ( rawValue ) {
			decls = decls ? decls + '; ' + rawValue : rawValue;
		}
		return decls;
	}

	function kindOf( form ) {
		var select = form.querySelector( '[name="kind"]' );
		return select ? select.value : 'inline';
	}

	function render( form ) {
		var target = form.querySelector( '.sheaf-live-target' );
		if ( ! target ) {
			return;
		}
		var decls = declarations( form );

		if ( 'block' === kindOf( form ) ) {
			target.innerHTML =
				'<div class="sheaf-prev"><p class="sheaf-prev-rep"></p>' +
				'<p class="sheaf-prev-actual"></p><p class="sheaf-prev-rep"></p></div>';
			var p = target.querySelector( '.sheaf-prev-actual' );
			p.setAttribute( 'style', decls );
			p.textContent = target.getAttribute( 'data-block' ) || SAMPLE_BLOCK;
		} else {
			target.innerHTML = '<p class="sheaf-prev"><span></span></p>';
			var span = target.querySelector( 'span' );
			span.setAttribute( 'style', decls );
			span.textContent = target.getAttribute( 'data-inline' ) || SAMPLE_INLINE;
		}
	}

	function slugify( s ) {
		return s.toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' );
	}

	// Update the CSS-block selector header from the name (for new styles) / stored
	// slug (when editing) and the kind.
	function updateSelector( form ) {
		var el = form.querySelector( '.sheaf-selector-text' );
		if ( ! el ) {
			return;
		}
		var set = form.querySelector( '.sheaf-style-form' );
		set = set ? set.getAttribute( 'data-set' ) : '';
		var stored = form.querySelector( '.sheaf-style-form' );
		stored = stored ? stored.getAttribute( 'data-style' ) : '';
		var nameEl = form.querySelector( '[name="label"]' );
		var slug = stored || ( nameEl ? slugify( nameEl.value ) : '' ) || '…';
		var prefix = 'block' === kindOf( form ) ? '.is-style-sheaf-' : '.sheaf-style-';
		el.textContent = prefix + set + '-' + slug;
	}

	// Build a "name: [value] ×" property row for a whitelisted property.
	function propRow( prop ) {
		var row = document.createElement( 'div' );
		row.className = 'sheaf-prop-row';
		row.innerHTML =
			'<span class="sheaf-prop-name"></span>: ' +
			'<input type="text" class="sheaf-prop-value">' +
			'<button type="button" class="sheaf-prop-remove" aria-label="Remove this property">×</button>';
		row.querySelector( '.sheaf-prop-name' ).textContent = prop;
		var input = row.querySelector( '.sheaf-prop-value' );
		input.name = 'props[' + prop + ']';
		if ( 'font-family' === prop ) {
			input.setAttribute( 'list', 'sheaf-font-list' ); // suggest installed fonts
		}
		return row;
	}

	document.querySelectorAll( '.sheaf-style-sets form' ).forEach( function ( form ) {
		if ( ! form.querySelector( '[name="kind"]' ) ) {
			return; // Not a style add/edit form.
		}

		var addSelect = form.querySelector( '.sheaf-add-prop' );
		var propsBox = form.querySelector( '.sheaf-css-props' );

		// "Add property": move the chosen property out of the dropdown into a row.
		if ( addSelect && propsBox ) {
			addSelect.addEventListener( 'change', function () {
				var prop = addSelect.value;
				if ( ! prop ) {
					return;
				}
				propsBox.appendChild( propRow( prop ) );
				var opt = addSelect.querySelector( 'option[value="' + prop + '"]' );
				if ( opt ) {
					opt.remove();
				}
				addSelect.value = '';
				var input = propsBox.lastChild.querySelector( '.sheaf-prop-value' );
				if ( input ) {
					input.focus();
				}
				render( form );
			} );
		}

		// Per-row remove: drop the row and return the property to the dropdown.
		form.addEventListener( 'click', function ( event ) {
			if ( ! event.target || ! event.target.classList.contains( 'sheaf-prop-remove' ) ) {
				return;
			}
			var row = event.target.closest( '.sheaf-prop-row' );
			var prop = row ? row.querySelector( '.sheaf-prop-name' ).textContent : '';
			if ( row ) {
				row.remove();
			}
			if ( prop && addSelect && ! addSelect.querySelector( 'option[value="' + prop + '"]' ) ) {
				var opt = document.createElement( 'option' );
				opt.value = prop;
				opt.textContent = prop;
				addSelect.appendChild( opt );
			}
			render( form );
		} );

		form.addEventListener( 'input', function () {
			render( form );
			updateSelector( form );
		} );
		form.addEventListener( 'change', function () {
			render( form );
			updateSelector( form );
		} );
		render( form );
		updateSelector( form );
	} );

	// Bulk-assign modal: open it, wire the check/uncheck-all box, and save the
	// book checkboxes over AJAX (then reload to refresh the "Available in" cell).
	var cfg = window.SheafStyleSets || {};

	document.querySelectorAll( '.sheaf-bulk-open' ).forEach( function ( opener ) {
		opener.addEventListener( 'click', function () {
			var dialog = document.getElementById( 'sheaf-bulk-' + opener.getAttribute( 'data-set' ) );
			if ( dialog && dialog.showModal ) {
				dialog.showModal();
			}
		} );
	} );

	document.querySelectorAll( '.sheaf-bulk-dialog' ).forEach( function ( dialog ) {
		var all = dialog.querySelector( '.sheaf-bulk-all' );
		if ( all ) {
			all.addEventListener( 'change', function () {
				dialog.querySelectorAll( '.sheaf-bulk-book' ).forEach( function ( box ) {
					box.checked = all.checked;
				} );
			} );
		}

		// Cancelling (or pressing Escape) discards unsaved toggles: restore every
		// checkbox to its server-rendered initial state.
		dialog.addEventListener( 'close', function () {
			dialog.querySelectorAll( '.sheaf-bulk-book, .sheaf-bulk-all' ).forEach( function ( box ) {
				box.checked = box.defaultChecked;
			} );
		} );

		var cancel = dialog.querySelector( '.sheaf-bulk-cancel' );
		if ( cancel ) {
			cancel.addEventListener( 'click', function () {
				dialog.close();
			} );
		}

		var save = dialog.querySelector( '.sheaf-bulk-save' );
		if ( save ) {
			save.addEventListener( 'click', function () {
				var body = new URLSearchParams();
				body.append( 'action', 'sheaf_bulk_assign' );
				body.append( 'nonce', cfg.nonce || '' );
				body.append( 'set', save.getAttribute( 'data-set' ) );
				dialog.querySelectorAll( '.sheaf-bulk-book:checked' ).forEach( function ( box ) {
					body.append( 'books[]', box.value );
				} );
				save.disabled = true;
				fetch( cfg.ajax, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString(),
				} )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( result ) {
						if ( result && result.success ) {
							window.location.reload();
						} else {
							save.disabled = false;
						}
					} )
					.catch( function () {
						save.disabled = false;
					} );
			} );
		}
	} );

	// "Rename" in a set's row actions toggles its inline rename form.
	document.querySelectorAll( '.sheaf-rename-toggle' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var box = document.getElementById( button.getAttribute( 'data-target' ) );
			if ( ! box ) {
				return;
			}
			var open = ! box.hasAttribute( 'hidden' );
			if ( open ) {
				box.setAttribute( 'hidden', '' );
			} else {
				box.removeAttribute( 'hidden' );
				var field = box.querySelector( 'input[name="label"]' );
				if ( field ) {
					field.focus();
					field.select();
				}
			}
			button.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
		} );
	} );
} )();
