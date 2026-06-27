<?php
/**
 * Unit tests for the style-set library (Sheaf\Style_Sets). CLI-only.
 *
 *   wpenv run cli wp eval-file wp-content/plugins/sheaf/tools/test-style-sets.php
 *
 * Snapshots and restores the real option, so it is safe to run on a live site.
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

// Work on a clean slate, then put the author's real library back.
$snapshot = get_option( \Sheaf\Style_Sets::OPTION, [] );
delete_option( \Sheaf\Style_Sets::OPTION );

try {
	// Create a set; the label slugs into the key.
	$set = \Sheaf\Style_Sets::save_set( 'Talking Monsters' );
	$check( 'talking-monsters' === $set, "save_set slugs the label ($set)" );

	// A second set with the same label gets a distinct key.
	$set2 = \Sheaf\Style_Sets::save_set( 'Talking Monsters' );
	$check( $set2 !== $set, "duplicate label -> unique slug ($set2)" );
	\Sheaf\Style_Sets::delete_set( $set2 );

	// Add an inline style, including a disallowed prop and hostile values.
	$style = \Sheaf\Style_Sets::save_style(
		$set,
		[
			'label' => 'Computer Voice',
			'kind'  => 'inline',
			'props' => [
				'font-family' => "'IBM Plex Mono', monospace",
				'color'       => '#33ff33',
				'position'    => 'absolute',                  // disallowed -> dropped
				'font-weight' => 'bold } body{display:none',  // breakout -> stripped
			],
			'css'   => 'letter-spacing: 1px; <script>alert(1)</script>',
		]
	);
	$check( 'computer-voice' === $style, "save_style slugs the label ($style)" );

	$s = \Sheaf\Style_Sets::get_style( $set, $style );
	$check( 'inline' === ( $s['kind'] ?? '' ), 'kind stored' );
	$check( "'IBM Plex Mono', monospace" === ( $s['props']['font-family'] ?? '' ), 'allowed prop kept' );
	$check( ! isset( $s['props']['position'] ), 'disallowed prop dropped' );
	$check( false === strpos( $s['props']['font-weight'] ?? '', '}' ), 'value breakout stripped' );
	$check( false === strpos( $s['css'] ?? '', '<' ), 'raw css tags stripped' );

	// Class + declarations.
	$check( 'sheaf-style-talking-monsters-computer-voice' === \Sheaf\Style_Sets::style_class( $set, $style ), 'style_class' );
	$decl = \Sheaf\Style_Sets::declarations( $s );
	$check( false !== strpos( $decl, 'font-family:' ), 'declarations include a prop' );
	$check( false === strpos( $decl, '{' ), 'declarations carry no braces' );

	// Reverse lookup + per-book read-back.
	$page = (int) wp_insert_post(
		[
			'post_type'   => 'page',
			'post_title'  => 'Temp Style Book',
			'post_status' => 'publish',
		]
	);
	update_post_meta( $page, \Sheaf\Style_Sets::BOOK_META, [ $set ] );
	$check( in_array( $page, \Sheaf\Style_Sets::books_using( $set ), true ), 'books_using finds the activating book' );
	$check( [ $set ] === \Sheaf\Style_Sets::active_sets( $page ), 'active_sets reads back' );
	wp_delete_post( $page, true );

	// Deletes.
	\Sheaf\Style_Sets::delete_style( $set, $style );
	$check( null === \Sheaf\Style_Sets::get_style( $set, $style ), 'delete_style' );
	\Sheaf\Style_Sets::delete_set( $set );
	$check( null === \Sheaf\Style_Sets::get_set( $set ), 'delete_set' );
} finally {
	if ( empty( $snapshot ) ) {
		delete_option( \Sheaf\Style_Sets::OPTION );
	} else {
		update_option( \Sheaf\Style_Sets::OPTION, $snapshot );
	}
}

WP_CLI::log( '' );
WP_CLI::log( "Passed: $pass   Failed: $fail" );
if ( $fail > 0 ) {
	WP_CLI::error( "$fail style-set check(s) failed." );
}
WP_CLI::success( 'Style-set library checks passed.' );
