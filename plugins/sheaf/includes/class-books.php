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
	 * Book id for programmatic chapter creation (seeder/import), so slug
	 * uniqueness can be scoped to the right book before the meta is saved.
	 * 0 = none. See resolve_book_for_slug().
	 */
	private static int $book_context = 0;

	public static function set_book_context( int $book_id ): void {
		self::$book_context = $book_id;
	}

	public static function book_context(): int {
		return self::$book_context;
	}

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

	/**
	 * IDs of every Page that has at least one chapter assigned to it — i.e. the
	 * Pages that are "books" — ordered by title.
	 *
	 * @return int[]
	 */
	public static function all_book_ids(): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value > 0",
				self::BOOK_META
			)
		);

		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		if ( ! $ids ) {
			return [];
		}

		// Order by the book's title for a friendly dropdown / listing.
		$titles = [];
		foreach ( $ids as $id ) {
			$titles[ $id ] = get_the_title( $id );
		}
		asort( $titles, SORT_NATURAL | SORT_FLAG_CASE );

		return array_keys( $titles );
	}

	/**
	 * A book Page addressed by its full path (a chapter URL's prefix).
	 */
	public static function get_book_by_path( string $path ): ?\WP_Post {
		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return null;
		}
		$page = get_page_by_path( $path, OBJECT, 'page' );
		return $page instanceof \WP_Post ? $page : null;
	}

	/**
	 * The published chapter with this slug inside a given book, or null.
	 *
	 * Slugs are unique *within a book* (see resolve_book_for_slug), so this is
	 * unambiguous — unlike a slug-only lookup, which two books could both match.
	 */
	public static function get_chapter_in_book( string $slug, int $book_id ): ?\WP_Post {
		if ( '' === $slug || ! $book_id ) {
			return null;
		}
		$posts = get_posts(
			[
				'post_type'   => Chapters::POST_TYPE,
				'name'        => $slug,
				'post_status' => 'publish',
				'meta_key'    => self::BOOK_META,
				'meta_value'  => $book_id,
				'numberposts' => 1,
			]
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Which book a chapter's slug should be unique within, during slug
	 * generation. Sources, most to least authoritative: the saved meta, the
	 * submitted Book selector, then any explicit book context (seeder/import).
	 */
	public static function resolve_book_for_slug( int $chapter_id ): int {
		if ( $chapter_id ) {
			$saved = self::get_book_id( $chapter_id );
			if ( $saved ) {
				return $saved;
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only; the value is validated again when the post is saved.
		if ( isset( $_POST['sheaf_book'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$posted = absint( wp_unslash( $_POST['sheaf_book'] ) );
			if ( $posted ) {
				return $posted;
			}
		}
		return self::book_context();
	}

	/**
	 * Is $slug already used by a different chapter in this book?
	 */
	public static function slug_taken_in_book( string $slug, int $book_id, int $ignore_id = 0 ): bool {
		if ( ! $book_id ) {
			return false;
		}
		$query = new \WP_Query(
			[
				'post_type'      => Chapters::POST_TYPE,
				'name'           => $slug,
				// Any real status (not auto-draft/trash) can claim a slug.
				'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private' ],
				'meta_key'       => self::BOOK_META,
				'meta_value'     => $book_id,
				'post__not_in'   => $ignore_id ? [ $ignore_id ] : [],
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		return ! empty( $query->posts );
	}

	/**
	 * A slug unique *within a book*: $base if free, else base-2, base-3, …
	 * (uniqueness is per book, so a different book may reuse the same base).
	 */
	public static function unique_chapter_slug( string $base, int $book_id, int $ignore_id = 0 ): string {
		if ( ! self::slug_taken_in_book( $base, $book_id, $ignore_id ) ) {
			return $base;
		}
		$suffix = 2;
		do {
			$candidate = $base . '-' . $suffix;
			++$suffix;
		} while ( self::slug_taken_in_book( $candidate, $book_id, $ignore_id ) );
		return $candidate;
	}
}
