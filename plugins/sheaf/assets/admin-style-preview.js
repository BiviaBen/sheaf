/**
 * Live preview for the Style Sets editor: as the author fills in the property
 * grid / raw CSS / kind, update an inline preview without a round-trip. Mirrors
 * the server-rendered preview (Style_Sets_Admin::preview): inline styles show a
 * <span> in a line of text; block styles show a <p> framed by blank paragraphs.
 *
 * The declarations are applied to the author's own preview element only (never
 * stored from here); the saved value is sanitized server-side.
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
			p.textContent = SAMPLE_BLOCK;
		} else {
			target.innerHTML = '<p class="sheaf-prev"><span></span></p>';
			var span = target.querySelector( 'span' );
			span.setAttribute( 'style', decls );
			span.textContent = SAMPLE_INLINE;
		}
	}

	document.querySelectorAll( '.sheaf-style-sets form' ).forEach( function ( form ) {
		if ( ! form.querySelector( '[name="kind"]' ) ) {
			return; // Not a style add/edit form.
		}
		form.addEventListener( 'input', function () {
			render( form );
		} );
		form.addEventListener( 'change', function () {
			render( form );
		} );
		render( form );
	} );
} )();
