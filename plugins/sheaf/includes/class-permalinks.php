<?php
/**
 * Nested chapter URLs.
 *
 * A chapter's pretty URL is its book Page's full path plus the chapter slug,
 * e.g. /novels/long-war/embers/13-resignations. Because book Pages can sit at
 * any depth, we don't add a broad rewrite rule (it would collide with WP's
 * page catch-all). Instead we:
 *
 *   1. Build the pretty URL via the post_type_link filter.
 *   2. Resolve incoming requests in parse_request: if a path isn't a real Page,
 *      treat the leading segments as a book Page path and the final segment as
 *      a chapter slug within that book. Chapter slugs are unique *within a
 *      book* (we scope wp_unique_post_slug to the book), so two books may each
 *      have, say, a "prologue" and the path tells them apart.
 *   3. Canonicalise in template_redirect (redirect a stale path to the real one).
 *
 * This is the fiddliest part of the plugin; it is intentionally isolated.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Permalinks {

	public static function register(): void {
		add_filter( 'post_type_link', [ self::class, 'chapter_link' ], 10, 2 );
		add_filter( 'wp_unique_post_slug', [ self::class, 'unique_slug' ], 10, 6 );
		add_action( 'init', [ self::class, 'add_rewrite_rules' ] );
		add_action( 'parse_request', [ self::class, 'route_request' ] );
		add_action( 'template_redirect', [ self::class, 'canonicalise' ] );
	}

	/**
	 * Scope a chapter's slug uniqueness to its book.
	 *
	 * WordPress makes a flat CPT's slugs unique across the whole type, so a
	 * second "prologue" would become "prologue-2". We instead allow duplicate
	 * slugs across books and only de-duplicate within a single book — that is
	 * what makes per-book clean URLs possible. When no book is known (e.g. an
	 * unassigned chapter), we leave WordPress's global-unique slug in place.
	 *
	 * @param string $slug          The slug WordPress settled on (maybe suffixed).
	 * @param int    $post_id       The chapter being saved.
	 * @param string $post_status   Unused.
	 * @param string $post_type     Post type of the slug.
	 * @param int    $post_parent   Unused.
	 * @param string $original_slug The desired slug, before WP's de-duplication.
	 */
	public static function unique_slug( string $slug, int $post_id, string $post_status, string $post_type, int $post_parent, string $original_slug ): string {
		if ( self::POST_TYPE() !== $post_type ) {
			return $slug;
		}

		$book_id = Books::resolve_book_for_slug( $post_id );
		if ( ! $book_id ) {
			return $slug;
		}

		return Books::unique_chapter_slug( $original_slug, $book_id, $post_id );
	}

	/**
	 * Match any "<book path>/<chapter slug>" so the request reaches WordPress
	 * as a real query instead of a 404; route_request then resolves it to the
	 * specific chapter by book + slug.
	 *
	 * Added at the bottom so real Pages (and their verbose page rules, which
	 * apply under a %postname% structure) win first; only paths that don't
	 * resolve to a Page fall through here.
	 */
	public static function add_rewrite_rules(): void {
		add_rewrite_rule(
			'(.+)/([^/]+)/?$',
			'index.php?' . self::POST_TYPE() . '=$matches[2]',
			'bottom'
		);
	}

	/**
	 * Build the nested permalink for a chapter.
	 */
	public static function chapter_link( string $link, \WP_Post $post ): string {
		if ( self::POST_TYPE() !== $post->post_type ) {
			return $link;
		}

		$slug = $post->post_name;

		// Drafts and pending chapters have no slug yet, so there is no canonical
		// nested URL. Core already built a correct ?post_type=…&p=ID permalink
		// (this is what the list table's "Preview" action uses); building the
		// nested path here would collapse to the book's own page, since the
		// trailing chapter segment would be empty.
		if ( '' === $slug ) {
			return $link;
		}

		$book_id = Books::get_book_id( $post->ID );
		$base    = $book_id ? get_page_uri( $book_id ) : '';
		$path    = $base ? trailingslashit( $base ) . $slug : $slug;

		return home_url( user_trailingslashit( $path ) );
	}

	/**
	 * Route a request to a chapter, using the leading path as its book and the
	 * final segment as the chapter slug within that book.
	 *
	 * We resolve to a concrete post ID (not a slug) because two books may share
	 * a chapter slug — a slug-only query would be ambiguous.
	 */
	public static function route_request( \WP $wp ): void {
		// The admin list tables call wp() internally (wp_edit_posts_query()),
		// which fires parse_request with a path of "wp-admin/edit.php". Routing
		// that as a chapter would wipe the list query's vars and 404 the screen,
		// so never touch admin requests.
		if ( is_admin() ) {
			return;
		}

		$path = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';
		if ( '' === $path ) {
			return;
		}

		// A genuine Page at this exact path wins (book pages, child pages, etc.).
		if ( get_page_by_path( $path, OBJECT, 'page' ) ) {
			return;
		}

		$segments = explode( '/', $path );
		$slug     = array_pop( $segments );
		$prefix   = implode( '/', $segments );

		// A bare slug (no book path) can only be an unassigned chapter.
		if ( '' === $prefix ) {
			$chapter = self::get_chapter_by_slug( $slug );
			if ( $chapter ) {
				$wp->query_vars = self::chapter_query( (int) $chapter->ID );
			}
			return;
		}

		// A nested path resolves strictly within the named book.
		$book    = Books::get_book_by_path( $prefix );
		$chapter = $book ? Books::get_chapter_in_book( $slug, (int) $book->ID ) : null;

		if ( $chapter ) {
			$wp->query_vars = self::chapter_query( (int) $chapter->ID );
			return;
		}

		// It looked like a chapter path but resolves to nothing in that book.
		// The catch-all rewrite rule already populated a slug-only chapter guess
		// (post_type + name), which would otherwise match a same-slug chapter in
		// another book. Replace it with a hard 404 so a wrong book path never
		// silently lands on (or redirects to) the wrong chapter.
		$wp->query_vars = [ 'error' => '404' ];
	}

	/**
	 * Query vars that load a specific chapter by ID — unambiguous even when two
	 * books share a chapter slug.
	 */
	private static function chapter_query( int $chapter_id ): array {
		return [
			'post_type' => self::POST_TYPE(),
			'p'         => $chapter_id,
		];
	}

	/**
	 * Send a 301 when a chapter is reached via a path other than its canonical
	 * (book-derived) one.
	 */
	public static function canonicalise(): void {
		if ( ! is_singular( self::POST_TYPE() ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$expected = wp_parse_url( get_permalink( $post ), PHP_URL_PATH );
		$current  = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );

		if ( $expected && $current && untrailingslashit( $expected ) !== untrailingslashit( $current ) ) {
			// Permanent (cacheable) in production; temporary elsewhere so a
			// cached 301 can't spoil dev/staging tests.
			$status = ( 'production' === wp_get_environment_type() ) ? 301 : 302;

			/** Filter: override the canonical chapter redirect status code. */
			$status = (int) apply_filters( 'sheaf_canonical_redirect_status', $status );

			wp_safe_redirect( get_permalink( $post ), $status );
			exit;
		}
	}

	private static function get_chapter_by_slug( string $slug ): ?\WP_Post {
		$posts = get_posts(
			[
				'post_type'   => self::POST_TYPE(),
				'name'        => $slug,
				'post_status' => 'publish',
				'numberposts' => 1,
			]
		);
		return $posts ? $posts[0] : null;
	}

	private static function POST_TYPE(): string {
		return Chapters::POST_TYPE;
	}
}
