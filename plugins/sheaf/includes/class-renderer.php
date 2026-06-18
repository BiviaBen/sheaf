<?php
/**
 * HTML for the table of contents and breadcrumbs.
 *
 * Shortcodes and blocks both call into here, so the markup lives in one place.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Renderer {

	/**
	 * Resolve which book a TOC should describe, given an explicit id (0 = auto).
	 *
	 * Auto-detection: on a chapter, its book; on a Page, the Page itself.
	 */
	public static function resolve_book_id( int $book_id = 0 ): int {
		if ( $book_id ) {
			return $book_id;
		}
		if ( is_singular( Chapters::POST_TYPE ) ) {
			return Books::get_book_id( (int) get_queried_object_id() );
		}
		if ( is_page() ) {
			return (int) get_queried_object_id();
		}
		return 0;
	}

	/**
	 * Table of contents for a book.
	 */
	public static function toc( int $book_id = 0 ): string {
		$book_id = self::resolve_book_id( $book_id );
		if ( ! $book_id ) {
			return '';
		}

		$chapters = Books::get_chapters( $book_id );
		if ( ! $chapters ) {
			return '';
		}

		$current = (int) get_queried_object_id();

		$items = '';
		foreach ( $chapters as $chapter ) {
			$is_current = ( $chapter->ID === $current );
			$items     .= sprintf(
				'<li class="sheaf-toc__item%1$s"><a href="%2$s"%3$s>%4$s</a></li>',
				$is_current ? ' is-current' : '',
				esc_url( get_permalink( $chapter ) ),
				$is_current ? ' aria-current="page"' : '',
				esc_html( get_the_title( $chapter ) )
			);
		}

		return sprintf(
			'<nav class="sheaf-toc" aria-label="%1$s"><ol class="sheaf-toc__list">%2$s</ol></nav>',
			esc_attr__( 'Table of contents', 'sheaf' ),
			$items
		);
	}

	/**
	 * Breadcrumb trail for a chapter or a Page.
	 */
	public static function breadcrumbs( int $object_id = 0 ): string {
		$object_id = $object_id ?: (int) get_queried_object_id();
		if ( ! $object_id ) {
			return '';
		}

		$post = get_post( $object_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$crumbs = [];

		if ( Chapters::POST_TYPE === $post->post_type ) {
			$book = Books::get_book( $object_id );
			if ( $book ) {
				foreach ( Books::ancestors( $book->ID ) as $ancestor ) {
					$crumbs[] = [ get_permalink( $ancestor ), get_the_title( $ancestor ) ];
				}
				$crumbs[] = [ get_permalink( $book ), get_the_title( $book ) ];
			}
			$crumbs[] = [ '', get_the_title( $post ) ];
		} else {
			foreach ( Books::ancestors( $object_id ) as $ancestor ) {
				$crumbs[] = [ get_permalink( $ancestor ), get_the_title( $ancestor ) ];
			}
			$crumbs[] = [ '', get_the_title( $post ) ];
		}

		if ( count( $crumbs ) < 2 ) {
			return '';
		}

		$parts = [];
		foreach ( $crumbs as $crumb ) {
			[ $url, $label ] = $crumb;
			$parts[]         = $url
				? sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) )
				: sprintf( '<span aria-current="page">%s</span>', esc_html( $label ) );
		}

		return sprintf(
			'<nav class="sheaf-breadcrumbs" aria-label="%1$s">%2$s</nav>',
			esc_attr__( 'Breadcrumb', 'sheaf' ),
			implode( ' <span class="sheaf-breadcrumbs__sep" aria-hidden="true">&rsaquo;</span> ', $parts )
		);
	}
}
