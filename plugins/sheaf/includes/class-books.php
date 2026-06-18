<?php
/**
 * Book/chapter relationship helpers.
 *
 * A "book" is an ordinary hierarchical WP Page. A chapter (the sheaf_chapter
 * CPT) belongs to exactly one book via the _sheaf_book meta key, which stores
 * the book Page's ID. Ordering within a book uses the chapter's menu_order.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Books {

	/** Meta key on a chapter holding its book Page ID. */
	public const BOOK_META = '_sheaf_book';

	/**
	 * The book Page ID a chapter belongs to (0 if unassigned).
	 */
	public static function get_book_id( int $chapter_id ): int {
		return (int) get_post_meta( $chapter_id, self::BOOK_META, true );
	}

	/**
	 * The book Page a chapter belongs to, or null.
	 */
	public static function get_book( int $chapter_id ): ?\WP_Post {
		$book_id = self::get_book_id( $chapter_id );
		if ( ! $book_id ) {
			return null;
		}
		$book = get_post( $book_id );
		return $book instanceof \WP_Post ? $book : null;
	}

	/**
	 * All published chapters of a book, in reading order.
	 *
	 * @return \WP_Post[]
	 */
	public static function get_chapters( int $book_id ): array {
		if ( ! $book_id ) {
			return [];
		}

		$query = new \WP_Query(
			[
				'post_type'      => Chapters::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => self::BOOK_META,
				'meta_value'     => $book_id,
				'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
				'no_found_rows'  => true,
				'cache_results'  => true,
			]
		);

		return $query->posts;
	}

	/**
	 * The ancestor Pages of a page, root-first (for breadcrumb trails).
	 *
	 * @return \WP_Post[]
	 */
	public static function ancestors( int $page_id ): array {
		$ids = array_reverse( get_post_ancestors( $page_id ) );
		return array_filter( array_map( 'get_post', $ids ) );
	}
}
