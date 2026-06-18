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
 *   2. Resolve incoming requests in parse_request: if a path isn't a real Page
 *      but its final segment matches a chapter slug, route to that chapter.
 *      Chapter slugs are globally unique within the CPT, so this is unambiguous.
 *   3. Canonicalise in template_redirect (301 a stale prefix to the real path).
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
		add_action( 'init', [ self::class, 'add_rewrite_rules' ] );
		add_action( 'parse_request', [ self::class, 'route_request' ] );
		add_action( 'template_redirect', [ self::class, 'canonicalise' ] );
	}

	/**
	 * Match any "<book path>/<chapter slug>" and resolve to the chapter.
	 *
	 * Added at the bottom so real Pages (and their verbose page rules, which
	 * apply under a %postname% structure) win first; only paths that don't
	 * resolve to a Page fall through here. Chapter slugs are globally unique,
	 * so the trailing segment alone is enough to find the chapter; a wrong
	 * prefix is then 301'd to the canonical path by canonicalise().
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

		$slug    = $post->post_name;
		$book_id = Books::get_book_id( $post->ID );
		$base    = $book_id ? get_page_uri( $book_id ) : '';
		$path    = $base ? trailingslashit( $base ) . $slug : $slug;

		return home_url( user_trailingslashit( $path ) );
	}

	/**
	 * Route a request to a chapter when its path's last segment is a chapter
	 * slug and the path is not an existing Page.
	 */
	public static function route_request( \WP $wp ): void {
		$vars = $wp->query_vars;

		// A genuine Page (at any depth) wins; leave it alone.
		if ( ! empty( $vars['pagename'] ) && get_page_by_path( $vars['pagename'], OBJECT, 'page' ) ) {
			return;
		}

		// The trailing slug can land in different query vars depending on which
		// rewrite rule matched: the generic page catch-all uses `pagename`,
		// while a path under an existing Page matches that Page's per-page rule
		// and surfaces the trailing segment as `attachment`.
		$candidate = $vars['attachment'] ?? ( $vars['name'] ?? ( $vars['pagename'] ?? '' ) );
		if ( '' === $candidate ) {
			return;
		}

		$slug    = basename( untrailingslashit( $candidate ) );
		$chapter = self::get_chapter_by_slug( $slug );
		if ( ! $chapter ) {
			return;
		}

		$wp->query_vars = [
			'post_type'       => self::POST_TYPE(),
			'name'            => $slug,
			self::POST_TYPE() => $slug,
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
