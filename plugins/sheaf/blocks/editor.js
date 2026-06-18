/**
 * Editor representations for Sheaf's dynamic blocks.
 *
 * No build step: this uses the wp.* globals directly. Server rendering (shared
 * with the shortcodes) is done in PHP, so the editor just shows a live
 * ServerSideRender preview. Block metadata/attributes come from each block.json
 * (registered server-side); here we only supply edit/save.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	function preview( name, attributes ) {
		return el(
			'div',
			{ className: 'sheaf-block-preview' },
			el( ServerSideRender, { block: name, attributes: attributes } )
		);
	}

	registerBlockType( 'sheaf/toc', {
		edit: function ( props ) {
			return preview( 'sheaf/toc', props.attributes );
		},
		save: function () {
			return null; // dynamic; rendered by PHP
		},
	} );

	registerBlockType( 'sheaf/breadcrumbs', {
		edit: function ( props ) {
			return preview( 'sheaf/breadcrumbs', props.attributes );
		},
		save: function () {
			return null; // dynamic; rendered by PHP
		},
	} );
} )( window.wp );
