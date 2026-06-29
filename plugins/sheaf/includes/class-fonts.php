<?php
/**
 * Web-font support for style sets, built on the WordPress Font Library.
 *
 * Style sets only ever store a font-family *name*. WordPress loads a registered
 * font automatically only when it is "active" in global styles, so a font named
 * solely in our `.sheaf-style-*` CSS would not load. This service bridges that:
 * it finds the families our styles reference, matches them against the fonts
 * installed in the Font Library (wp_font_family / wp_font_face posts, self-hosted
 * in uploads/fonts), and emits the matching @font-face rules — which Frontend and
 * the editor canvas print alongside the style CSS.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Fonts {

	/**
	 * Fonts installed in the Font Library, keyed by normalized family name.
	 *
	 * @return array<string,array{name:string,faces:array<int,array{src:string,weight:string,style:string}>}>
	 */
	public static function installed(): array {
		$out = [];
		$families = get_posts(
			[
				'post_type'   => 'wp_font_family',
				'post_status' => 'publish',
				'numberposts' => -1,
			]
		);
		foreach ( $families as $family ) {
			$data = json_decode( (string) $family->post_content, true );
			$name = is_array( $data ) ? (string) ( $data['name'] ?? '' ) : '';
			if ( '' === $name ) {
				$name = (string) $family->post_title;
			}
			if ( '' === $name ) {
				continue;
			}

			$faces = [];
			foreach ( get_posts( [ 'post_type' => 'wp_font_face', 'post_parent' => $family->ID, 'post_status' => 'publish', 'numberposts' => -1 ] ) as $face ) {
				$fd  = json_decode( (string) $face->post_content, true );
				$src = is_array( $fd ) ? ( $fd['src'] ?? '' ) : '';
				if ( is_array( $src ) ) {
					$src = $src[0] ?? '';
				}
				if ( '' === (string) $src ) {
					continue;
				}
				$faces[] = [
					'src'    => (string) $src,
					'weight' => (string) ( $fd['fontWeight'] ?? '400' ),
					'style'  => (string) ( $fd['fontStyle'] ?? 'normal' ),
				];
			}
			if ( $faces ) {
				$out[ self::normalize( $name ) ] = [
					'name'  => $name,
					'faces' => $faces,
				];
			}
		}
		return $out;
	}

	/**
	 * The primary family name from a CSS font-family value: the first entry,
	 * unquoted (e.g. '"EB Garamond", Georgia, serif' => 'EB Garamond').
	 */
	public static function primary_family( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$first = explode( ',', $value )[0];
		return trim( $first, " \t\n\r\0\x0B\"'" );
	}

	/**
	 * Family names referenced by any style in the library (structured font-family
	 * prop), keyed by normalized name => display name.
	 *
	 * @return array<string,string>
	 */
	public static function referenced(): array {
		$names = [];
		foreach ( Style_Sets::all() as $set ) {
			foreach ( (array) ( $set['styles'] ?? [] ) as $style ) {
				$value   = (string) ( $style['props']['font-family'] ?? '' );
				$primary = self::primary_family( $value );
				if ( '' !== $primary ) {
					$names[ self::normalize( $primary ) ] = $primary;
				}
			}
		}
		return $names;
	}

	/**
	 * @font-face CSS for the families that are both referenced by a style and
	 * installed in the Font Library. Empty when nothing matches.
	 */
	public static function font_face_css(): string {
		$installed = self::installed();
		if ( ! $installed ) {
			return '';
		}
		$css = '';
		foreach ( self::referenced() as $key => $name ) {
			if ( ! isset( $installed[ $key ] ) ) {
				continue;
			}
			$family = str_replace( '"', '', $installed[ $key ]['name'] );
			foreach ( $installed[ $key ]['faces'] as $face ) {
				$weight = preg_replace( '/[^0-9a-z ]/i', '', $face['weight'] );
				$style  = preg_replace( '/[^a-z]/i', '', $face['style'] );
				$src    = esc_url_raw( $face['src'] );
				if ( '' === $src ) {
					continue;
				}
				$format = self::format_hint( $src );
				$css   .= '@font-face{font-family:"' . $family . '";font-style:' . ( $style ?: 'normal' ) . ';font-weight:' . ( $weight ?: '400' ) . ';font-display:swap;src:url(' . $src . ')'
					. ( '' !== $format ? ' format("' . $format . '")' : '' ) . "}\n";
			}
		}
		return $css;
	}

	/** Installed family display names, for the editor's font-family suggestions. */
	public static function installed_names(): array {
		$names = array_map(
			static function ( $f ) {
				return $f['name'];
			},
			self::installed()
		);
		sort( $names );
		return array_values( $names );
	}

	private static function normalize( string $name ): string {
		return strtolower( trim( $name, " \t\n\r\0\x0B\"'" ) );
	}

	/** A `format()` hint from a font URL's extension (woff2/woff/ttf/otf). */
	private static function format_hint( string $src ): string {
		$path = (string) ( wp_parse_url( $src, PHP_URL_PATH ) ?: $src );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$map  = [
			'woff2' => 'woff2',
			'woff'  => 'woff',
			'ttf'   => 'truetype',
			'otf'   => 'opentype',
		];
		return $map[ $ext ] ?? '';
	}
}
