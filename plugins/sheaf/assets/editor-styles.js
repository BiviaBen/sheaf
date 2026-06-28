/**
 * Editor integration for style sets (no build step; uses the wp.* globals).
 *
 * Turns a chapter's active style sets into editor controls:
 *   - inline styles -> rich-text formats wrapping the selection in a <span> with
 *     the style's class. Rather than scattering one toolbar button per style
 *     through the built-in formatting menu, they are gathered into a single
 *     dedicated "Styles" dropdown in the inline toolbar.
 *   - block styles  -> paragraph block-style variations (WordPress applies an
 *     "is-style-<name>" class to the block).
 *
 * The list is computed server-side from the chapter's book at load time. The
 * book lives in a classic meta box outside the editor store, so when the author
 * changes it we only warn — the styles refresh on the next save + reload.
 */
( function ( wp ) {
	'use strict';

	var data = window.SheafStyles || {};
	var i18n = data.i18n || {};
	var el = wp.element.createElement;
	var registerFormatType = wp.richText.registerFormatType;
	var toggleFormat = wp.richText.toggleFormat;
	var getActiveFormat = wp.richText.getActiveFormat;
	var registerBlockStyle = wp.blocks.registerBlockStyle;
	var BlockControls = wp.blockEditor.BlockControls;
	var ToolbarGroup = wp.components.ToolbarGroup;
	var ToolbarDropdownMenu = wp.components.ToolbarDropdownMenu;

	var all = data.styles || [];
	var inlineStyles = all.filter( function ( s ) {
		return 'block' !== s.kind;
	} );

	// Block styles: paragraph variations.
	all.forEach( function ( s ) {
		if ( 'block' === s.kind ) {
			registerBlockStyle( 'core/paragraph', { name: s.blockName, label: s.title } );
		}
	} );

	// Inline styles: register each as a format (so it applies/detects), but with
	// no edit — no per-style button in the built-in menu.
	inlineStyles.forEach( function ( s ) {
		registerFormatType( s.name, {
			title: s.title,
			tagName: 'span',
			className: s.class,
		} );
	} );

	// One dedicated "Styles" dropdown in the inline toolbar, listing them all and
	// kept separate from the built-in formats. Hosted by a control-only format
	// type (never applied itself).
	if ( inlineStyles.length ) {
		registerFormatType( 'sheaf/styles-menu', {
			title: i18n.stylesLabel || 'Styles',
			tagName: 'span',
			className: 'sheaf-styles-menu',
			edit: function ( props ) {
				var controls = inlineStyles.map( function ( s ) {
					return {
						title: s.title,
						isActive: !! getActiveFormat( props.value, s.name ),
						onClick: function () {
							props.onChange( toggleFormat( props.value, { type: s.name } ) );
						},
					};
				} );
				return el(
					BlockControls,
					{ group: 'inline' },
					el(
						ToolbarGroup,
						null,
						el( ToolbarDropdownMenu, {
							icon: 'editor-textcolor',
							text: i18n.stylesLabel || 'Styles',
							label: i18n.stylesLabel || 'Styles',
							controls: controls,
						} )
					)
				);
			},
		} );
	}

	// Warn (without auto-reloading) when the author changes the chapter's book:
	// the style list above was built from the book at load time.
	wp.domReady( function () {
		var box = document.getElementById( 'sheaf-book' );
		if ( ! box ) {
			return;
		}
		var notices = wp.data && wp.data.dispatch( 'core/notices' );
		var NOTICE_ID = 'sheaf-style-book-changed';
		// wp_localize_script serializes the id as a string; compare as a number
		// so reverting the select back to the original book clears the warning.
		var loadedBook = parseInt( data.bookId, 10 ) || 0;

		function currentBook() {
			var sel = box.querySelector( 'select[name="sheaf_book"]:not([disabled])' );
			return sel ? ( parseInt( sel.value, 10 ) || 0 ) : 0;
		}

		function sync() {
			if ( ! notices ) {
				return;
			}
			if ( currentBook() !== loadedBook ) {
				notices.createWarningNotice( data.i18n.bookChanged, {
					id: NOTICE_ID,
					isDismissible: true,
				} );
			} else {
				notices.removeNotice( NOTICE_ID );
			}
		}

		box.addEventListener( 'change', function ( e ) {
			if ( e.target && 'sheaf_book' === e.target.name ) {
				sync();
			}
		} );
	} );
} )( window.wp );
