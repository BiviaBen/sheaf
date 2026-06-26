/**
 * Drag-and-drop chapter reordering on the Books settings screen.
 *
 * Uses WordPress's bundled jquery-ui-sortable (no build step) to reorder the
 * rows of the chapters table. On drop it renumbers the reading positions and
 * saves the new order over AJAX.
 */
( function ( $ ) {
	$( function () {
		var $body = $( '#sheaf-reorder' ); // The sortable <tbody>.
		if ( ! $body.length || typeof SheafReorder === 'undefined' ) {
			return;
		}

		var $status = $( '#sheaf-reorder-status' );

		function renumber() {
			var n = 0;
			$body.children( 'tr' ).each( function () {
				var $num = $( this ).find( '.sheaf-reorder__num' );
				if ( $( this ).hasClass( 'is-section' ) ) {
					$num.text( '·' ); // Sections are not numbered.
				} else {
					n += 1;
					$num.text( n );
				}
			} );
		}

		function save() {
			var order = $body
				.children( 'tr' )
				.map( function () {
					return $( this ).data( 'id' );
				} )
				.get();

			$status.text( SheafReorder.savingText || 'Saving…' );

			$.post( SheafReorder.ajax, {
				action: 'sheaf_reorder',
				nonce: SheafReorder.nonce,
				book: $body.data( 'book' ),
				order: order
			} )
				.done( function ( res ) {
					$status.text(
						res && res.success
							? SheafReorder.savedText || 'Order saved.'
							: SheafReorder.failedText || 'Save failed.'
					);
				} )
				.fail( function () {
					$status.text( SheafReorder.failedText || 'Save failed.' );
				} );
		}

		$body.sortable( {
			items: '> tr',
			handle: '.sheaf-reorder__handle',
			axis: 'y',
			// Keep the dragged row's column widths instead of collapsing them.
			helper: function ( event, $row ) {
				var $originals = $row.children();
				var $helper = $row.clone();
				$helper.children().each( function ( index ) {
					$( this ).width( $originals.eq( index ).outerWidth() );
				} );
				return $helper;
			},
			placeholder: 'sheaf-reorder__placeholder',
			start: function ( event, ui ) {
				// Give the placeholder a real cell so the row keeps its height.
				ui.placeholder.html( '<td colspan="5">&nbsp;</td>' );
				ui.placeholder.height( ui.item.outerHeight() );
			},
			update: function () {
				renumber();
				save();
			}
		} );
	} );
} )( jQuery );
