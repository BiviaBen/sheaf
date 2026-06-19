<?php
/**
 * Word counts and reading time for chapters.
 *
 * Counting is done once on save and cached in the _sheaf_word_count meta, so
 * the admin list, the Books screen, and the TOC can all show it cheaply. Call
 * Words::get() to read it (it computes lazily if the cache is missing).
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Words {

	/** Cached per-chapter word count. */
	public const META = '_sheaf_word_count';

	public static function register(): void {
		// After Admin::save (priority 10) so the book meta is already stored.
		add_action( 'save_post_' . Chapters::POST_TYPE, [ self::class, 'on_save' ], 20 );
	}

	/**
	 * Recompute and cache the word count when a chapter is saved.
	 */
	public static function on_save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::refresh( $post_id );
	}

	/**
	 * The cached word count for a post, computing (and caching) it if absent.
	 */
	public static function get( int $post_id ): int {
		$cached = get_post_meta( $post_id, self::META, true );
		if ( '' !== $cached ) {
			return (int) $cached;
		}
		return self::refresh( $post_id );
	}

	/**
	 * Recompute, store, and return a post's word count.
	 */
	public static function refresh( int $post_id ): int {
		$post  = get_post( $post_id );
		$count = $post instanceof \WP_Post ? self::count_in( $post->post_content ) : 0;
		update_post_meta( $post_id, self::META, $count );
		return $count;
	}

	/**
	 * Word count of raw post content: shortcodes, blocks, tags and entities are
	 * stripped so only the prose is counted.
	 */
	public static function count_in( string $content ): int {
		$text = strip_shortcodes( $content );
		$text = wp_strip_all_tags( $text ); // also drops block delimiter comments
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Collapse all whitespace, including non-breaking spaces (\p{Z}).
		$text = trim( (string) preg_replace( '/[\s\p{Z}]+/u', ' ', $text ) );
		if ( '' === $text ) {
			return 0;
		}
		return (int) preg_match_all( '/\S+/u', $text );
	}

	/**
	 * Estimated reading time in whole minutes (at least 1) for a word count.
	 * Adjust the pace with the sheaf_words_per_minute filter.
	 */
	public static function reading_minutes( int $words ): int {
		$wpm = (int) apply_filters( 'sheaf_words_per_minute', 250 );
		if ( $wpm < 1 ) {
			$wpm = 250;
		}
		return max( 1, (int) ceil( $words / $wpm ) );
	}
}
