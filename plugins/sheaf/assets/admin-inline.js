/**
 * Pre-select the current book when Quick Edit opens on the chapters list.
 *
 * WordPress's inline editor doesn't know about our custom "Book" field, so we
 * wrap inlineEditPost.edit and copy the row's stored book id into the select.
 */
( function ( $ ) {
	if ( typeof inlineEditPost === 'undefined' ) {
		return;
	}

	var $edit = inlineEditPost.edit;

	inlineEditPost.edit = function ( id ) {
		$edit.apply( this, arguments );

		var postId = 0;
		if ( typeof id === 'object' ) {
			postId = parseInt( this.getId( id ), 10 );
		}
		if ( ! postId ) {
			return;
		}

		var book = $( '#sheaf-book-inline-' + postId ).text();
		$( '#edit-' + postId )
			.find( 'select[name="sheaf_book"]' )
			.val( book && '0' !== book ? book : '0' );
	};
} )( jQuery );
