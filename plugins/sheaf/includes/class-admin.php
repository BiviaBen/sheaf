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
