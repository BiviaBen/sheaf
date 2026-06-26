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

	public static function register(): void {
		// When a book Page is permanently deleted, detach its chapters so they
		// become "Unassigned" rather than pointing at a tombstone. Trashing does
		// not fire this (before_delete_post is delete-only), so restoring a
		// trashed book re-links its chapters.
		add_action( 'before_delete_post', [ self::class, 'unassign_on_delete' ] );
	}

	/**
	 * Detach every chapter from a book Page that is being permanently deleted.
	 */
	public static function unassign_on_delete( int $post_id ): void {
		if ( 'page' !== get_post_type( $post_id ) ) {
			return; // Books are Pages; ignore other deletions cheaply.
		}
		self::unassign_all( $post_id );
	}

	/**
	 * Remove the book assignment from every chapter in a book.
	 *
	 * @return int Number of chapters detached.
	 */
	public static function unassign_all( int $book_id ): int {
		if ( ! $book_id ) {
			return 0;
		}
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d",
				self::BOOK_META,
				$book_id
			)
		);
		foreach ( $ids as $id ) {
			delete_post_meta( (int) $id, self::BOOK_META );
		}
		return count( $ids );
	}

	/**
	 * How many chapters are not assigned to any book.
	 */
	public static function unassigned_chapter_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE p.post_type = %s AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				 AND ( m.meta_id IS NULL OR m.meta_value = '' OR m.meta_value = '0' )",
				self::BOOK_META,
				Chapters::POST_TYPE
			)
		);
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
	 * Every chapter of a book for admin use — all editable statuses (not just
	 * published), in reading order. Used by the Books screen and reorder UI.
	 *
	 * @return \WP_Post[]
	 */
	public static function get_chapters_for_admin( int $book_id ): array {
		if ( ! $book_id ) {
			return [];
		}

		$query = new \WP_Query(
			[
				'post_type'      => Chapters::POST_TYPE,
				'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private' ],
				'posts_per_page' => -1,
				'meta_key'       => self::BOOK_META,
				'meta_value'     => $book_id,
				'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
				'no_found_rows'  => true,
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

		// Order by the book's title for a friendly dropdown / listing. Skip IDs
		// whose Page no longer exists or is trashed, so a deleted book can't
		// linger as a ghost (chapters may still carry stale meta).
		$titles = [];
		foreach ( $ids as $id ) {
			$status = get_post_status( $id );
			if ( ! $status || in_array( $status, [ 'trash', 'auto-draft' ], true ) ) {
				continue;
			}
			$titles[ $id ] = get_the_title( $id );
		}
		asort( $titles, SORT_NATURAL | SORT_FLAG_CASE );

		return array_keys( $titles );
	}

	/**
	 * The menu_order one past the last chapter in a book (0 for an empty book),
	 * so a newly added chapter appends to the end of its reading order.
	 */
	public static function next_menu_order( int $book_id, int $exclude_id = 0 ): int {
		if ( ! $book_id ) {
			return 0;
		}
		global $wpdb;
		$max = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(p.menu_order) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE m.meta_value = %d AND p.post_type = %s AND p.ID <> %d
				 AND p.post_status NOT IN ( 'trash', 'auto-draft' )",
				self::BOOK_META,
				$book_id,
				Chapters::POST_TYPE,
				$exclude_id
			)
		);
		return ( null === $max ) ? 0 : (int) $max + 1;
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
