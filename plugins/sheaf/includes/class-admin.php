<?php
/**
 * Admin: assign a chapter to a book, and surface the relationship in the list.
 *
 * Reading order uses the core "Order" (menu_order) field exposed by the
 * page-attributes support; this adds the Book selector and list columns.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {

	private const NONCE = 'sheaf_book_meta';

	public static function register(): void {
		add_action( 'add_meta_boxes', [ self::class, 'add_meta_box' ] );
		add_action( 'save_post_' . Chapters::POST_TYPE, [ self::class, 'save' ], 10, 2 );

		add_filter( 'manage_' . Chapters::POST_TYPE . '_posts_columns', [ self::class, 'columns' ] );
		add_action( 'manage_' . Chapters::POST_TYPE . '_posts_custom_column', [ self::class, 'column' ], 10, 2 );
		add_filter( 'manage_edit-' . Chapters::POST_TYPE . '_sortable_columns', [ self::class, 'sortable_columns' ] );
		add_action( 'pre_get_posts', [ self::class, 'apply_sort' ] );

		// "All Chapters" list: filter by book, and group by book + reading order.
		add_action( 'restrict_manage_posts', [ self::class, 'book_filter' ] );
		add_action( 'pre_get_posts', [ self::class, 'apply_book_filter' ] );
		add_filter( 'posts_clauses', [ self::class, 'default_order' ], 10, 2 );

		// Quick Edit / Bulk Edit: assign chapters to a book (Page) from the list.
		add_action( 'quick_edit_custom_box', [ self::class, 'quick_edit_box' ], 10, 2 );
		add_action( 'bulk_edit_custom_box', [ self::class, 'bulk_edit_box' ], 10, 2 );
		add_action( 'save_post_' . Chapters::POST_TYPE, [ self::class, 'save_inline' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_inline' ] );
	}

	/**
	 * Render the "filter by book" dropdown above the chapter list.
	 */
	public static function book_filter( string $post_type ): void {
		if ( Chapters::POST_TYPE !== $post_type ) {
			return;
		}
		$book_ids = Books::all_book_ids();
		if ( ! $book_ids ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$current = isset( $_GET['sheaf_book'] ) ? absint( $_GET['sheaf_book'] ) : 0;

		echo '<label class="screen-reader-text" for="sheaf-book-filter">' . esc_html__( 'Filter by book', 'sheaf' ) . '</label>';
		echo '<select name="sheaf_book" id="sheaf-book-filter">';
		echo '<option value="0">' . esc_html__( 'All books', 'sheaf' ) . '</option>';
		foreach ( $book_ids as $book_id ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				$book_id,
				selected( $current, $book_id, false ),
				esc_html( get_the_title( $book_id ) )
			);
		}
		echo '</select>';
	}

	/**
	 * Narrow the chapter list to a chosen book.
	 */
	public static function apply_book_filter( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( Chapters::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		// "Unassigned" view: chapters with no book at all.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		if ( ! empty( $_GET['sheaf_unassigned'] ) ) {
			$meta_query   = (array) $query->get( 'meta_query' );
			$meta_query[] = [
				'key'     => Books::BOOK_META,
				'compare' => 'NOT EXISTS',
			];
			$query->set( 'meta_query', $meta_query );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$book_id = isset( $_GET['sheaf_book'] ) ? absint( $_GET['sheaf_book'] ) : 0;
		if ( ! $book_id ) {
			return;
		}

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = [
			'key'   => Books::BOOK_META,
			'value' => $book_id,
		];
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Default the chapter list to group by book, then reading order — unless
	 * the user clicked a sortable column header.
	 */
	public static function default_order( array $clauses, \WP_Query $query ): array {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return $clauses;
		}
		if ( Chapters::POST_TYPE !== $query->get( 'post_type' ) ) {
			return $clauses;
		}

		// Group by book + reading order when there is no explicit sort, or when
		// the Book column header itself is clicked. Other column sorts (Order,
		// Words, Title, Date) are left to WordPress.
		$orderby = (string) $query->get( 'orderby' );
		if ( '' !== $orderby && 'sheaf_book' !== $orderby ) {
			return $clauses;
		}

		// Only the book dimension flips with asc/desc; chapters always read in
		// menu_order within their book.
		$dir = ( 'sheaf_book' === $orderby && 'desc' === strtolower( (string) $query->get( 'order' ) ) )
			? 'DESC'
			: 'ASC';

		global $wpdb;
		$meta_key = esc_sql( Books::BOOK_META );

		$clauses['join']   .= " LEFT JOIN {$wpdb->postmeta} sheaf_bk ON {$wpdb->posts}.ID = sheaf_bk.post_id AND sheaf_bk.meta_key = '{$meta_key}'";
		$clauses['join']   .= " LEFT JOIN {$wpdb->posts} sheaf_bp ON sheaf_bp.ID = CAST(sheaf_bk.meta_value AS UNSIGNED)";
		$clauses['orderby'] = "sheaf_bp.post_title {$dir}, {$wpdb->posts}.menu_order ASC, {$wpdb->posts}.post_title ASC";

		return $clauses;
	}

	public static function add_meta_box(): void {
		add_meta_box(
			'sheaf-book',
			__( 'Book', 'sheaf' ),
			[ self::class, 'render_meta_box' ],
			Chapters::POST_TYPE,
			'side',
			'high'
		);
	}

	public static function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );

		$selected = (int) Books::get_book_id( $post->ID );
		// Pre-select when arriving from a book's "add chapter" link.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pre-fill.
		if ( ! $selected && isset( $_GET['sheaf_book'] ) ) {
			$selected = absint( $_GET['sheaf_book'] );
		}

		// Default the selector to existing books; fold in the current selection
		// if it isn't a book yet (so it still appears).
		$book_ids = Books::all_book_ids();
		if ( $selected && ! in_array( $selected, $book_ids, true ) ) {
			$book_ids[] = $selected;
		}

		echo '<p>';
		echo '<select name="sheaf_book" id="sheaf-book-books">';
		printf( '<option value="0">%s</option>', esc_html__( '— Unassigned —', 'sheaf' ) );
		foreach ( $book_ids as $bid ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $bid,
				selected( $selected, (int) $bid, false ),
				esc_html( get_the_title( (int) $bid ) )
			);
		}
		echo '</select>';

		// The full page list, hidden and disabled until "show all pages" is
		// ticked. Disabled controls aren't submitted, so only one value is sent.
		$all = (string) wp_dropdown_pages(
			[
				'name'              => 'sheaf_book',
				'id'                => 'sheaf-book-all',
				'selected'          => $selected,
				'show_option_none'  => __( '— Unassigned —', 'sheaf' ),
				'option_none_value' => 0,
				'echo'              => 0,
			]
		);
		$all = preg_replace( '/<select /', '<select disabled ', $all, 1 );
		echo '<span id="sheaf-book-all-wrap" style="display:none">' . $all . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages output.
		echo '</p>';

		printf(
			'<p><label><input type="checkbox" id="sheaf-book-allpages"> %s</label></p>',
			esc_html__( 'Show all pages', 'sheaf' )
		);
		echo '<p class="description" id="sheaf-book-allpages-note" style="display:none">'
			. esc_html__( 'Adding a Chapter to a Page turns that Page into a Book.', 'sheaf' )
			. '</p>';
		echo '<p class="description">' . esc_html__( 'The book (Page) this chapter belongs to. Set reading order with the Order field.', 'sheaf' ) . '</p>';
		?>
		<script>
		( function () {
			var cb    = document.getElementById( 'sheaf-book-allpages' );
			var books = document.getElementById( 'sheaf-book-books' );
			var wrap  = document.getElementById( 'sheaf-book-all-wrap' );
			var all   = document.getElementById( 'sheaf-book-all' );
			var note  = document.getElementById( 'sheaf-book-allpages-note' );
			if ( ! cb || ! books || ! all ) { return; }
			cb.addEventListener( 'change', function () {
				if ( cb.checked ) {
					all.value = books.value;
					books.disabled = true;  books.style.display = 'none';
					all.disabled = false;   wrap.style.display = '';
					note.style.display = '';
				} else {
					var match = Array.prototype.some.call( books.options, function ( o ) { return o.value === all.value; } );
					books.value = match ? all.value : '0';
					all.disabled = true;    wrap.style.display = 'none';
					books.disabled = false; books.style.display = '';
					note.style.display = 'none';
				}
			} );
		} )();
		</script>
		<?php

		printf(
			'<p><label><input type="checkbox" name="sheaf_is_section" value="1"%s> %s</label></p>',
			checked( Chapters::is_section( $post->ID ), true, false ),
			esc_html__( 'This is a section divider (e.g. “Part I”), not a chapter.', 'sheaf' )
		);
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE ] ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$prev_book = (int) get_post_meta( $post_id, Books::BOOK_META, true );
		$book_id   = isset( $_POST['sheaf_book'] ) ? absint( $_POST['sheaf_book'] ) : 0;
		if ( $book_id ) {
			update_post_meta( $post_id, Books::BOOK_META, $book_id );

			// When a chapter first joins a book and the author hasn't typed an
			// explicit Order, drop it at the end so new chapters append rather
			// than collide at the top. The author can then reorder freely.
			$submitted_order = isset( $_POST['menu_order'] ) ? (int) $_POST['menu_order'] : 0;
			if ( $book_id !== $prev_book && 0 === $submitted_order ) {
				$next = Books::next_menu_order( $book_id, $post_id );
				if ( $next !== (int) $post->menu_order ) {
					// Write menu_order directly to avoid re-entering save_post.
					global $wpdb;
					$wpdb->update( $wpdb->posts, [ 'menu_order' => $next ], [ 'ID' => $post_id ] );
					clean_post_cache( $post_id );
				}
			}
		} else {
			delete_post_meta( $post_id, Books::BOOK_META );
		}

		if ( isset( $_POST['sheaf_is_section'] ) ) {
			update_post_meta( $post_id, Chapters::SECTION_META, true );
		} else {
			delete_post_meta( $post_id, Chapters::SECTION_META );
		}
	}

	public static function columns( array $columns ): array {
		$insert = [
			'sheaf_book'  => __( 'Book', 'sheaf' ),
			'sheaf_order' => __( 'Order', 'sheaf' ),
			'sheaf_words' => __( 'Words', 'sheaf' ),
		];
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out += $insert;
			}
		}
		return $out;
	}

	public static function column( string $column, int $post_id ): void {
		if ( 'sheaf_book' === $column ) {
			$book = Books::get_book( $post_id );
			echo $book ? esc_html( get_the_title( $book ) ) : '<span aria-hidden="true">—</span>';
			// Hidden source for Quick Edit to pre-select the current book.
			printf(
				'<span class="hidden" id="sheaf-book-inline-%1$d">%2$d</span>',
				$post_id,
				(int) Books::get_book_id( $post_id )
			);
		} elseif ( 'sheaf_order' === $column ) {
			echo (int) get_post_field( 'menu_order', $post_id );
		} elseif ( 'sheaf_words' === $column ) {
			$words   = Words::get( $post_id );
			$minutes = Words::reading_minutes( $words );
			printf(
				/* translators: 1: word count, 2: reading time in minutes. */
				'%1$s<br><span class="description">%2$s</span>',
				esc_html( number_format_i18n( $words ) ),
				esc_html( sprintf( _n( '%d min', '%d min', $minutes, 'sheaf' ), $minutes ) )
			);
		}
	}

	/**
	 * Make Order and Words sortable column headers.
	 */
	public static function sortable_columns( array $columns ): array {
		$columns['sheaf_book']  = 'sheaf_book';
		$columns['sheaf_order'] = 'menu_order';
		$columns['sheaf_words'] = 'sheaf_words';
		return $columns;
	}

	/**
	 * Translate a click on the Words header into a meta-value sort.
	 */
	public static function apply_sort( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( Chapters::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( 'sheaf_words' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', Words::META );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	/* ---- Quick Edit / Bulk Edit: assign a chapter to a book ---------------- */

	public static function quick_edit_box( string $column, string $post_type ): void {
		if ( Chapters::POST_TYPE === $post_type && 'sheaf_book' === $column ) {
			self::inline_book_field( false );
		}
	}

	public static function bulk_edit_box( string $column, string $post_type ): void {
		if ( Chapters::POST_TYPE === $post_type && 'sheaf_book' === $column ) {
			self::inline_book_field( true );
		}
	}

	/**
	 * The "Book" selector shown in the Quick/Bulk Edit panels. Lists every Page
	 * (any Page can become a book), plus "Unassigned"; Bulk Edit adds a
	 * "No change" default so untouched chapters keep their book.
	 */
	private static function inline_book_field( bool $bulk ): void {
		$args = [
			'name'              => 'sheaf_book',
			'id'                => $bulk ? 'sheaf-bulk-book' : 'sheaf-quick-book',
			'show_option_none'  => __( '— Unassigned —', 'sheaf' ),
			'option_none_value' => 0,
			'echo'              => 0,
		];
		if ( $bulk ) {
			$args['show_option_no_change'] = __( '— No change —', 'sheaf' );
		}
		$dropdown = (string) wp_dropdown_pages( $args );
		if ( '' === $dropdown ) {
			return; // No pages exist to assign to.
		}

		echo '<fieldset class="inline-edit-col-right"><div class="inline-edit-col"><label class="inline-edit-group">';
		echo '<span class="title">' . esc_html__( 'Book', 'sheaf' ) . '</span>';
		echo '<span class="input-text-wrap">' . $dropdown . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages output.
		echo '</label></div></fieldset>';
	}

	/**
	 * Save the Book selection from Quick Edit or Bulk Edit. Runs on save_post
	 * alongside save(); each bails for the other's flow (distinct markers).
	 */
	public static function save_inline( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified just below / by core for bulk.
		$is_quick = isset( $_POST['_inline_edit'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_inline_edit'] ) ), 'inlineeditnonce' );
		// Bulk edit is authorised by core (check_admin_referer 'bulk-posts')
		// before it updates each post; we still re-check the per-post cap above.
		$is_bulk = isset( $_REQUEST['bulk_edit'] );
		if ( ! $is_quick && ! $is_bulk ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		if ( ! isset( $_REQUEST['sheaf_book'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$value = (int) wp_unslash( $_REQUEST['sheaf_book'] );
		if ( $value < 0 ) {
			return; // -1 = Bulk Edit "No change".
		}

		self::set_chapter_book( $post_id, $value );
	}

	/**
	 * Move a chapter to a book (0 = unassign). Keeps the chapter's slug unique
	 * within its new book and appends it to that book's reading order. Writes
	 * post fields directly to avoid re-entering save_post.
	 */
	private static function set_chapter_book( int $post_id, int $book_id ): void {
		$prev = (int) get_post_meta( $post_id, Books::BOOK_META, true );
		if ( $book_id === $prev ) {
			return;
		}

		if ( ! $book_id ) {
			delete_post_meta( $post_id, Books::BOOK_META );
			return;
		}

		update_post_meta( $post_id, Books::BOOK_META, $book_id );

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$fields = [ 'menu_order' => Books::next_menu_order( $book_id, $post_id ) ];
		$unique = Books::unique_chapter_slug( $post->post_name, $book_id, $post_id );
		if ( $unique !== $post->post_name ) {
			$fields['post_name'] = $unique;
		}

		global $wpdb;
		$wpdb->update( $wpdb->posts, $fields, [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );
	}

	/**
	 * Pre-select the current book when Quick Edit opens on the chapter list.
	 */
	public static function enqueue_inline( string $hook ): void {
		if ( 'edit.php' !== $hook || Chapters::POST_TYPE !== ( $GLOBALS['typenow'] ?? '' ) ) {
			return;
		}
		$asset = SHEAF_DIR . 'assets/admin-inline.js';
		$ver   = file_exists( $asset ) ? (string) filemtime( $asset ) : SHEAF_VERSION;
		wp_enqueue_script(
			'sheaf-inline',
			SHEAF_URL . 'assets/admin-inline.js',
			[ 'jquery', 'inline-edit-post' ],
			$ver,
			true
		);
	}
}
