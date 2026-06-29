<?php
/**
 * Unit tests for web-font resolution (Sheaf\Fonts). CLI-only.
 *
 *   wpenv run cli wp eval-file wp-content/plugins/sheaf/tools/test-fonts.php
 *
 * Snapshots/restores the style-set library and removes any test font it installs.
 *
 * @package Sheaf
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

$pass  = 0;
$fail  = 0;
$check = function ( bool $cond, string $label ) use ( &$pass, &$fail ) {
	if ( $cond ) {
		++$pass;
		WP_CLI::log( "  ok   $label" );
	} else {
		++$fail;
		WP_CLI::log( "  FAIL $label" );
	}
};

$snapshot = get_option( \Sheaf\Style_Sets::OPTION, [] );
$posts    = [];

try {
	// primary_family extraction.
	$check( 'Spike Serif' === \Sheaf\Fonts::primary_family( 'Spike Serif' ), 'primary_family: bare name' );
	$check( 'Spike Serif' === \Sheaf\Fonts::primary_family( '"Spike Serif", Georgia, serif' ), 'primary_family: quoted stack' );
	$check( 'EB Garamond' === \Sheaf\Fonts::primary_family( "  'EB Garamond' , serif" ), 'primary_family: trims quotes/space' );
	$check( '' === \Sheaf\Fonts::primary_family( '' ), 'primary_family: empty' );

	// Install a fake font into the Font Library.
	$fam = wp_insert_post( [ 'post_type' => 'wp_font_family', 'post_status' => 'publish', 'post_title' => 'Spike Serif', 'post_content' => wp_json_encode( [ 'name' => 'Spike Serif', 'slug' => 'spike-serif', 'fontFamily' => '"Spike Serif", serif' ] ) ] );
	$posts[] = $fam;
	$face = wp_insert_post( [ 'post_type' => 'wp_font_face', 'post_status' => 'publish', 'post_parent' => $fam, 'post_content' => wp_json_encode( [ 'fontFamily' => 'Spike Serif', 'fontWeight' => '400', 'fontStyle' => 'normal', 'src' => 'http://localhost:8888/wp-content/uploads/fonts/spike-serif.woff2' ] ) ] );
	$posts[] = $face;

	$installed = \Sheaf\Fonts::installed();
	$check( isset( $installed['spike serif'] ), 'installed: family keyed by normalized name' );
	$check( 1 === count( $installed['spike serif']['faces'] ?? [] ), 'installed: face parsed' );
	$check( in_array( 'Spike Serif', \Sheaf\Fonts::installed_names(), true ), 'installed_names lists the family' );

	// A style set referencing the installed font + one referencing an absent font.
	delete_option( \Sheaf\Style_Sets::OPTION );
	$set = \Sheaf\Style_Sets::save_set( 'Fonted' );
	\Sheaf\Style_Sets::save_style( $set, [ 'label' => 'Serifed', 'kind' => 'inline', 'props' => [ 'font-family' => '"Spike Serif", serif' ] ] );
	\Sheaf\Style_Sets::save_style( $set, [ 'label' => 'Ghost', 'kind' => 'inline', 'props' => [ 'font-family' => 'Not Installed Font' ] ] );

	$ref = \Sheaf\Fonts::referenced();
	$check( isset( $ref['spike serif'] ) && isset( $ref['not installed font'] ), 'referenced: collects both primary names' );

	$css = \Sheaf\Fonts::font_face_css();
	$check( false !== strpos( $css, '@font-face' ), 'font_face_css emits a rule' );
	$check( false !== strpos( $css, 'font-family:"Spike Serif"' ), 'font_face_css uses the installed family name' );
	$check( false !== strpos( $css, 'spike-serif.woff2' ), 'font_face_css references the self-hosted src' );
	$check( false !== strpos( $css, 'format("woff2")' ), 'font_face_css adds the woff2 format hint' );
	$check( false !== strpos( $css, 'font-display:swap' ), 'font_face_css sets font-display: swap' );
	$check( false === strpos( $css, 'Not Installed' ), 'font_face_css skips referenced-but-not-installed families' );

	// Catalog (bundled Google Fonts collection).
	$catalog_names = \Sheaf\Fonts::catalog_names();
	$check( count( $catalog_names ) > 100, 'catalog_names returns the bundled families' );
	$check( in_array( 'EB Garamond', $catalog_names, true ), 'catalog includes a known family' );

	// Word-font substitution + catalog membership.
	$check( 'Carlito' === \Sheaf\Fonts::substitute( 'Calibri' ), 'substitute: Calibri -> Carlito' );
	$check( 'Caladea' === \Sheaf\Fonts::substitute( 'cambria' ), 'substitute: case-insensitive' );
	$check( '' === \Sheaf\Fonts::substitute( 'Times New Roman' ), 'substitute: web-safe font has no substitute' );
	$check( \Sheaf\Fonts::in_catalog( 'EB Garamond' ), 'in_catalog: known family' );
	$check( ! \Sheaf\Fonts::in_catalog( 'ZZ Made Up' ), 'in_catalog: unknown family' );

	// Drift guard: every substitution target must exist in the catalog.
	$missing = array_filter(
		\Sheaf\Fonts::substitution_targets(),
		static function ( $t ) {
			return ! \Sheaf\Fonts::in_catalog( $t );
		}
	);
	$check( [] === $missing, 'every substitution target exists in the catalog' . ( $missing ? ' (missing: ' . implode( ', ', $missing ) . ')' : '' ) );

	// install_from_catalog: download + self-host a real (small) catalog font.
	$before = get_posts( [ 'post_type' => 'wp_font_family', 'name' => 'abeezee', 'post_status' => 'any', 'numberposts' => 1 ] );
	if ( $before ) {
		foreach ( $before as $p ) {
			wp_delete_post( $p->ID, true );
		}
	}
	$ok = \Sheaf\Fonts::install_from_catalog( 'ABeeZee' );
	$check( true === $ok, 'install_from_catalog installs a catalog font' );
	if ( $ok ) {
		$installed_now = \Sheaf\Fonts::installed();
		$check( isset( $installed_now['abeezee'] ), 'installed() now lists the embedded font' );
		$face_css = \Sheaf\Fonts::face_css( 'ABeeZee' );
		$check( false !== strpos( $face_css, '@font-face' ) && false !== strpos( $face_css, 'ABeeZee' ), 'face_css emits the embedded family' );
		$check( false !== strpos( $face_css, '/wp-content/uploads/fonts/' ), 'embedded src is self-hosted' );
		// Clean up the installed font + its file.
		foreach ( get_posts( [ 'post_type' => 'wp_font_family', 'name' => 'abeezee', 'post_status' => 'any', 'numberposts' => -1 ] ) as $fam ) {
			foreach ( get_posts( [ 'post_type' => 'wp_font_face', 'post_parent' => $fam->ID, 'numberposts' => -1, 'post_status' => 'any' ] ) as $face ) {
				$fd = json_decode( (string) $face->post_content, true );
				$src = is_array( $fd ) ? (string) ( $fd['src'] ?? '' ) : '';
				$path = $src ? ( wp_get_font_dir()['path'] . '/' . basename( wp_parse_url( $src, PHP_URL_PATH ) ) ) : '';
				if ( $path && file_exists( $path ) ) {
					@unlink( $path );
				}
				wp_delete_post( $face->ID, true );
			}
			wp_delete_post( $fam->ID, true );
		}
	}
} finally {
	foreach ( $posts as $p ) {
		wp_delete_post( $p, true );
	}
	update_option( \Sheaf\Style_Sets::OPTION, $snapshot );
}

WP_CLI::log( '' );
WP_CLI::log( "Passed: $pass   Failed: $fail" );
if ( $fail > 0 ) {
	WP_CLI::error( "$fail font check(s) failed." );
}
WP_CLI::success( 'Web-font checks passed.' );
