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

		// "All Chapters" list: filter by book, and group by book + reading order.
		add_action( 'restrict_manage_posts', [ self::class, 'book_filter' ] );
		add_action( 'pre_get_posts', [ self::class, 'apply_book_filter' ] );
		add_filter( 'posts_clauses', [ self::class, 'default_order' ], 10, 2 );
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list state.
		if ( isset( $_GET['orderby'] ) ) {
			return $clauses; // Respect an explicit column sort.
		}

		global $wpdb;
		$meta_key = esc_sql( Books::BOOK_META );

		$clauses['join']   .= " LEFT JOIN {$wpdb->postmeta} sheaf_bk ON {$wpdb->posts}.ID = sheaf_bk.post_id AND sheaf_bk.meta_key = '{$meta_key}'";
		$clauses['join']   .= " LEFT JOIN {$wpdb->posts} sheaf_bp ON sheaf_bp.ID = CAST(sheaf_bk.meta_value AS UNSIGNED)";
		$clauses['orderby'] = "sheaf_bp.post_title ASC, {$wpdb->posts}.menu_order ASC, {$wpdb->posts}.post_title ASC";

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
		$selected = Books::get_book_id( $post->ID );

		echo '<p>';
		wp_dropdown_pages(
			[
				'name'             => 'sheaf_book',
				'selected'         => $selected,
				'show_option_none' => __( '— Unassigned —', 'sheaf' ),
				'option_none_value' => 0,
			]
		);
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'The book (Page) this chapter belongs to. Set reading order with the Order field.', 'sheaf' ) . '</p>';
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

		$book_id = isset( $_POST['sheaf_book'] ) ? absint( $_POST['sheaf_book'] ) : 0;
		if ( $book_id ) {
			update_post_meta( $post_id, Books::BOOK_META, $book_id );
		} else {
			delete_post_meta( $post_id, Books::BOOK_META );
		}
	}

	public static function columns( array $columns ): array {
		$insert = [ 'sheaf_book' => __( 'Book', 'sheaf' ), 'sheaf_order' => __( 'Order', 'sheaf' ) ];
		$out    = [];
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
		} elseif ( 'sheaf_order' === $column ) {
			echo (int) get_post_field( 'menu_order', $post_id );
		}
	}
}
