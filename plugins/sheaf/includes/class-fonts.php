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
			if ( isset( $installed[ $key ] ) ) {
				$css .= self::faces_css( $installed[ $key ]['name'], $installed[ $key ]['faces'] );
			}
		}
		return $css;
	}

	/** @font-face for one installed family (e.g. to inject after embedding). */
	public static function face_css( string $name ): string {
		$installed = self::installed();
		$key       = self::normalize( $name );
		return isset( $installed[ $key ] )
			? self::faces_css( $installed[ $key ]['name'], $installed[ $key ]['faces'] )
			: '';
	}

	/**
	 * @font-face declarations for one family's faces.
	 *
	 * @param array<int,array{src:string,weight:string,style:string}> $faces
	 */
	private static function faces_css( string $name, array $faces ): string {
		$family = str_replace( '"', '', $name );
		$css    = '';
		foreach ( $faces as $face ) {
			$weight = preg_replace( '/[^0-9a-z ]/i', '', (string) $face['weight'] );
			$style  = preg_replace( '/[^a-z]/i', '', (string) $face['style'] );
			$src    = esc_url_raw( (string) $face['src'] );
			if ( '' === $src ) {
				continue;
			}
			$format = self::format_hint( $src );
			$css   .= '@font-face{font-family:"' . $family . '";font-style:' . ( $style ?: 'normal' ) . ';font-weight:' . ( $weight ?: '400' ) . ';font-display:swap;src:url(' . $src . ')'
				. ( '' !== $format ? ' format("' . $format . '")' : '' ) . "}\n";
		}
		return $css;
	}

	/**
	 * The bundled Google Fonts collection as a name-keyed catalog (cached per
	 * request), each entry carrying its remote faces. Empty if unavailable.
	 *
	 * @return array<string,array{name:string,faces:array<int,array{src:string,weight:string,style:string}>}>
	 */
	public static function catalog(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = [];
		if ( ! class_exists( '\WP_Font_Library' ) ) {
			return $cache;
		}
		$library = \WP_Font_Library::get_instance();
		if ( ! method_exists( $library, 'get_font_collection' ) ) {
			return $cache;
		}
		$collection = $library->get_font_collection( 'google-fonts' );
		if ( ! $collection || is_wp_error( $collection ) || ! method_exists( $collection, 'get_data' ) ) {
			return $cache;
		}
		$data = $collection->get_data();
		if ( is_wp_error( $data ) || ! is_array( $data ) ) {
			return $cache;
		}
		foreach ( (array) ( $data['font_families'] ?? [] ) as $entry ) {
			$settings = $entry['font_family_settings'] ?? $entry;
			$name     = (string) ( $settings['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$faces = [];
			foreach ( (array) ( $settings['fontFace'] ?? [] ) as $face ) {
				$src = $face['src'] ?? '';
				if ( is_array( $src ) ) {
					$src = $src[0] ?? '';
				}
				if ( '' === (string) $src ) {
					continue;
				}
				$faces[] = [
					'src'    => (string) $src,
					'weight' => (string) ( $face['fontWeight'] ?? '400' ),
					'style'  => (string) ( $face['fontStyle'] ?? 'normal' ),
				];
			}
			$cache[ self::normalize( $name ) ] = [
				'name'  => $name,
				'faces' => $faces,
			];
		}
		return $cache;
	}

	/** Catalog family names, for the editor's recognition/autocomplete. */
	public static function catalog_names(): array {
		$names = array_map(
			static function ( $f ) {
				return $f['name'];
			},
			self::catalog()
		);
		sort( $names );
		return array_values( $names );
	}

	/**
	 * Install a catalog family into the Font Library (self-hosted): download its
	 * regular (400/normal) face, save it to the font dir, and register the family.
	 * Idempotent. Returns true on success (or if already installed).
	 */
	public static function install_from_catalog( string $name ): bool {
		$key     = self::normalize( $name );
		$catalog = self::catalog();
		if ( ! isset( $catalog[ $key ] ) ) {
			return false;
		}
		if ( isset( self::installed()[ $key ] ) ) {
			return true;
		}
		$entry = $catalog[ $key ];

		$face = null;
		foreach ( $entry['faces'] as $candidate ) {
			if ( '400' === $candidate['weight'] && 'normal' === $candidate['style'] ) {
				$face = $candidate;
				break;
			}
		}
		if ( ! $face && isset( $entry['faces'][0] ) ) {
			$face = $entry['faces'][0];
		}
		if ( ! $face ) {
			return false;
		}

		$dir = wp_get_font_dir();
		if ( ! is_dir( $dir['path'] ) ) {
			wp_mkdir_p( $dir['path'] );
		}

		$response = wp_remote_get( $face['src'], [ 'timeout' => 25 ] );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return false;
		}

		$slug      = sanitize_title( $entry['name'] );
		$filename  = sanitize_file_name( $slug . '-' . $face['weight'] . '-' . $face['style'] . '.woff2' );
		if ( false === file_put_contents( trailingslashit( $dir['path'] ) . $filename, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a downloaded font to the font dir.
			return false;
		}
		$local_url = trailingslashit( $dir['url'] ) . $filename;

		$family_id = wp_insert_post(
			[
				'post_type'    => 'wp_font_family',
				'post_status'  => 'publish',
				'post_title'   => $entry['name'],
				'post_name'    => $slug,
				'post_content' => wp_json_encode(
					[
						'name'       => $entry['name'],
						'slug'       => $slug,
						'fontFamily' => '"' . $entry['name'] . '"',
					]
				),
			],
			true
		);
		if ( is_wp_error( $family_id ) || ! $family_id ) {
			return false;
		}
		wp_insert_post(
			[
				'post_type'    => 'wp_font_face',
				'post_status'  => 'publish',
				'post_parent'  => $family_id,
				'post_title'   => $entry['name'] . ' ' . $face['weight'],
				'post_content' => wp_json_encode(
					[
						'fontFamily' => $entry['name'],
						'fontWeight' => $face['weight'],
						'fontStyle'  => $face['style'],
						'src'        => $local_url,
					]
				),
			]
		);
		return true;
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
