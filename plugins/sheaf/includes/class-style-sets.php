<?php
/**
 * The style-set library.
 *
 * Authors define named "style sets" — e.g. a set "Talking Monsters" holding the
 * styles "Computer Voice" (monospace) and "Telepathy" (cursive). Each style is a
 * small bag of CSS properties (plus an optional raw-CSS escape hatch) and a kind:
 * "inline" (a rich-text span, like bold) or "block" (a whole paragraph).
 *
 * The library is a single global option, shared across the whole site. A Book
 * Page activates zero or more sets (Style_Sets::BOOK_META); that drives which
 * styles the editor offers for the book's chapters and the import mapper — NOT
 * the front-end CSS, which is emitted globally and keyed on each style's class
 * alone, so a class means the same thing everywhere. Authors who want a style to
 * differ in one book override it with their own higher-specificity CSS via the
 * hierarchy body classes (see Frontend::hierarchy_classes()).
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Style_Sets {

	/** Option holding the whole library. */
	public const OPTION = 'sheaf_style_sets';

	/** Book Page meta: the set slugs active on that book. */
	public const BOOK_META = '_sheaf_style_sets';

	/** A style applies to an inline run or to a whole block. */
	public const KINDS = [ 'inline', 'block' ];

	/**
	 * CSS properties an author may set through the constrained form. Anything
	 * else must go through the raw-CSS escape hatch. Whitelisting keeps the
	 * generated CSS predictable and blocks property-name shenanigans.
	 */
	public const ALLOWED_PROPS = [
		'font-family',
		'font-size',
		'font-weight',
		'font-style',
		'font-variant',
		'line-height',
		'letter-spacing',
		'text-transform',
		'text-align',
		'text-indent',
		'color',
		'background-color',
		'margin-top',
		'margin-bottom',
	];

	// --- Read -----------------------------------------------------------------

	/**
	 * The whole library.
	 *
	 * @return array<string,array>
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION, [] );
		return is_array( $raw ) ? $raw : [];
	}

	public static function get_set( string $set ): ?array {
		$all = self::all();
		return isset( $all[ $set ] ) ? $all[ $set ] : null;
	}

	public static function get_style( string $set, string $style ): ?array {
		$s = self::get_set( $set );
		return ( $s && isset( $s['styles'][ $style ] ) ) ? $s['styles'][ $style ] : null;
	}

	// --- Write ----------------------------------------------------------------

	/**
	 * Create a set (empty $set) or rename an existing one. Returns the set key.
	 */
	public static function save_set( string $label, string $set = '' ): string {
		$all = self::all();
		if ( '' === $set ) {
			$set = self::unique_key( sanitize_title( $label ), array_keys( $all ) );
		}
		if ( '' === $set ) {
			$set = self::unique_key( 'style-set', array_keys( $all ) );
		}
		if ( ! isset( $all[ $set ] ) ) {
			$all[ $set ] = [
				'label'  => '',
				'styles' => [],
			];
		}
		$all[ $set ]['label'] = sanitize_text_field( $label );
		self::put( $all );
		return $set;
	}

	public static function delete_set( string $set ): void {
		$all = self::all();
		unset( $all[ $set ] );
		self::put( $all );
	}

	/**
	 * Create (empty $style) or update a style within a set. Returns the style key
	 * ('' if the set does not exist).
	 *
	 * @param array $data { @type string $label; @type string $kind;
	 *                      @type array $props; @type string $css }
	 */
	public static function save_style( string $set, array $data, string $style = '' ): string {
		$all = self::all();
		if ( ! isset( $all[ $set ] ) ) {
			return '';
		}
		$label    = sanitize_text_field( $data['label'] ?? '' );
		$existing = array_keys( $all[ $set ]['styles'] ?? [] );
		if ( '' === $style ) {
			$style = self::unique_key( sanitize_title( $label ), $existing );
		}
		if ( '' === $style ) {
			$style = self::unique_key( 'style', $existing );
		}
		$kind = in_array( $data['kind'] ?? '', self::KINDS, true ) ? $data['kind'] : 'inline';

		$all[ $set ]['styles'][ $style ] = [
			'label' => $label,
			'kind'  => $kind,
			'props' => self::sanitize_props( (array) ( $data['props'] ?? [] ) ),
			'css'   => self::sanitize_raw_css( (string) ( $data['css'] ?? '' ) ),
		];
		self::put( $all );
		return $style;
	}

	public static function delete_style( string $set, string $style ): void {
		$all = self::all();
		unset( $all[ $set ]['styles'][ $style ] );
		self::put( $all );
	}

	// --- Derived --------------------------------------------------------------

	/**
	 * The CSS class marking text that carries this style, e.g.
	 * "sheaf-style-talking-monsters-computer-voice".
	 */
	public static function style_class( string $set, string $style ): string {
		return 'sheaf-style-' . $set . '-' . $style;
	}

	/**
	 * The CSS declaration body for a style (no selector, no braces): the
	 * whitelisted props followed by the raw-CSS escape hatch.
	 */
	public static function declarations( array $style ): string {
		$out = [];
		foreach ( (array) ( $style['props'] ?? [] ) as $prop => $value ) {
			if ( in_array( $prop, self::ALLOWED_PROPS, true ) && '' !== $value ) {
				$out[] = $prop . ': ' . $value;
			}
		}
		$decls = implode( '; ', $out );
		$raw   = trim( (string) ( $style['css'] ?? '' ) );
		if ( '' !== $raw ) {
			$decls = '' !== $decls ? $decls . '; ' . $raw : $raw;
		}
		return $decls;
	}

	/**
	 * Book Page IDs that currently activate a given set.
	 *
	 * @return int[]
	 */
	public static function books_using( string $set ): array {
		global $wpdb;
		// The meta is a serialized array; each slug appears quoted ("slug"), so a
		// LIKE on the quoted form can't partial-match a longer slug.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				self::BOOK_META,
				'%' . $wpdb->esc_like( '"' . $set . '"' ) . '%'
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Set slugs activated on a book Page, filtered to sets that still exist.
	 *
	 * @return string[]
	 */
	public static function active_sets( int $book_id ): array {
		$raw = get_post_meta( $book_id, self::BOOK_META, true );
		$raw = is_array( $raw ) ? $raw : [];
		return array_values( array_intersect( $raw, array_keys( self::all() ) ) );
	}

	// --- Internals ------------------------------------------------------------

	private static function put( array $all ): void {
		update_option( self::OPTION, $all );
	}

	private static function unique_key( string $base, array $taken ): string {
		if ( '' === $base ) {
			return '';
		}
		if ( ! in_array( $base, $taken, true ) ) {
			return $base;
		}
		$i = 2;
		while ( in_array( $base . '-' . $i, $taken, true ) ) {
			++$i;
		}
		return $base . '-' . $i;
	}

	private static function sanitize_props( array $props ): array {
		$clean = [];
		foreach ( $props as $prop => $value ) {
			$prop = strtolower( trim( (string) $prop ) );
			if ( ! in_array( $prop, self::ALLOWED_PROPS, true ) ) {
				continue;
			}
			$value = self::sanitize_css_value( (string) $value );
			if ( '' !== $value ) {
				$clean[ $prop ] = $value;
			}
		}
		return $clean;
	}

	/**
	 * A single CSS value: strip anything that could break out of the declaration
	 * or inject script — tags, braces, angle brackets, semicolons, url(javascript).
	 */
	private static function sanitize_css_value( string $value ): string {
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[<>{};]/', '', (string) $value );
		$value = preg_replace( '/(?:javascript|expression)\s*:?\s*\(?/i', '', (string) $value );
		return trim( (string) $value );
	}

	/**
	 * The raw-CSS escape hatch: a declaration list only (no selectors). Strip
	 * braces/tags so an author can't add rules or close the <style> element;
	 * semicolons stay, since they separate declarations.
	 */
	private static function sanitize_raw_css( string $css ): string {
		$css = wp_strip_all_tags( $css );
		$css = str_replace( [ '{', '}', '<', '>' ], '', $css );
		$css = preg_replace( '/(?:javascript|expression)\s*:?\s*\(?/i', '', (string) $css );
		return trim( (string) $css );
	}
}
