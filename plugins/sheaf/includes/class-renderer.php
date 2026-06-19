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
	 *
	 * @param int   $book_id Book Page ID (0 = auto-detect from the current view).
	 * @param array $args    { Optional.
	 *     @type bool $reading_time Append each chapter's reading time. Default true.
	 * }
	 */
	public static function toc( int $book_id = 0, array $args = [] ): string {
		$args = array_merge( [ 'reading_time' => true ], $args );

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

			// Section dividers ("Part I") are styled differently and carry no
			// reading time.
			if ( Chapters::is_section( (int) $chapter->ID ) ) {
				$items .= sprintf(
					'<li class="sheaf-toc__item sheaf-toc__item--section%1$s"><a class="sheaf-toc__part" href="%2$s"%3$s>%4$s</a></li>',
					$is_current ? ' is-current' : '',
					esc_url( get_permalink( $chapter ) ),
					$is_current ? ' aria-current="page"' : '',
					esc_html( get_the_title( $chapter ) )
				);
				continue;
			}

			$items .= sprintf(
				'<li class="sheaf-toc__item%1$s"><a href="%2$s"%3$s>%4$s</a>%5$s</li>',
				$is_current ? ' is-current' : '',
				esc_url( get_permalink( $chapter ) ),
				$is_current ? ' aria-current="page"' : '',
				esc_html( get_the_title( $chapter ) ),
				$args['reading_time'] ? self::reading_time_meta( (int) $chapter->ID ) : ''
			);
		}

		return sprintf(
			'<nav class="sheaf-toc" aria-label="%1$s"><ol class="sheaf-toc__list">%2$s</ol></nav>',
			esc_attr__( 'Table of contents', 'sheaf' ),
			$items
		);
	}

	/**
	 * The "5 min" reading-time span for a chapter, with the exact word count in
	 * the title attribute. Returns '' if the chapter has no counted words.
	 */
	private static function reading_time_meta( int $chapter_id ): string {
		$words = Words::get( $chapter_id );
		if ( $words < 1 ) {
			return '';
		}
		$minutes = Words::reading_minutes( $words );

		return sprintf(
			' <span class="sheaf-toc__meta" title="%1$s">%2$s</span>',
			/* translators: %s: number of words. */
			esc_attr( sprintf( _n( '%s word', '%s words', $words, 'sheaf' ), number_format_i18n( $words ) ) ),
			/* translators: %d: reading time in minutes. */
			esc_html( sprintf( _n( '%d min', '%d min', $minutes, 'sheaf' ), $minutes ) )
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
