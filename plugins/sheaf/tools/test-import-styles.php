<?php
/**
 * Unit tests for the Word-style import mapping (Sheaf\Import_Serializer +
 * Sheaf\Import). CLI-only.
 *
 *   wpenv run cli wp eval-file wp-content/plugins/sheaf/tools/test-import-styles.php
 *
 * Snapshots and restores the real style-set library, so it is safe to run on a
 * live site.
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

$private = function ( string $class, string $method ) {
	$m = new ReflectionMethod( $class, $method );
	$m->setAccessible( true );
	return $m;
};

$snapshot   = get_option( \Sheaf\Style_Sets::OPTION, [] );
$post_backup = $_POST;

try {
	/* ---- Serializer: character style -> inline span ----------------------- */

	$blocks = [
		[
			'type'  => 'paragraph',
			'style' => '',
			'runs'  => [
				[ 'text' => 'Hello ', 'style' => '' ],
				[ 'text' => 'BEEP', 'style' => 'ComputerVoice' ],
			],
		],
	];
	$settings = \Sheaf\Import_Serializer::sanitize_settings(
		[
			'keep_emphasis' => true,
			'style_map'     => [ 'ComputerVoice' => 'sheaf-style-talking-monsters-computer-voice' ],
		]
	);
	$html = \Sheaf\Import_Serializer::to_blocks( $blocks, $settings );
	$check( false !== strpos( $html, '<span class="sheaf-style-talking-monsters-computer-voice">BEEP</span>' ), 'character style -> inline span' );

	/* ---- Serializer: paragraph style -> block-style class ----------------- */

	$blocks = [
		[
			'type'  => 'paragraph',
			'style' => 'Verse',
			'runs'  => [ [ 'text' => 'A line of verse', 'style' => '' ] ],
		],
	];
	$settings = \Sheaf\Import_Serializer::sanitize_settings(
		[ 'block_style_map' => [ 'Verse' => 'is-style-sheaf-poetry-verse' ] ]
	);
	$html = \Sheaf\Import_Serializer::to_blocks( $blocks, $settings );
	$check( false !== strpos( $html, '"className":"is-style-sheaf-poetry-verse"' ), 'paragraph style -> block className attr' );
	$check( false !== strpos( $html, '<p class="is-style-sheaf-poetry-verse">' ), 'paragraph style -> block class on <p>' );

	// An unmapped paragraph stays a plain <p>.
	$plain = \Sheaf\Import_Serializer::to_blocks( $blocks, \Sheaf\Import_Serializer::default_settings() );
	$check( false !== strpos( $plain, "<p>A line of verse</p>" ), 'unmapped paragraph stays plain' );

	/* ---- collect_styles: counts + excludes structural styles -------------- */

	$entries = [
		[
			'error'  => '',
			'blocks' => [
				[ 'type' => 'heading', 'level' => 2, 'style' => 'Heading1', 'runs' => [ [ 'text' => 'T', 'style' => '' ] ] ],
				[ 'type' => 'paragraph', 'style' => 'Verse', 'runs' => [ [ 'text' => 'x', 'style' => 'ComputerVoice' ] ] ],
				[ 'type' => 'paragraph', 'style' => 'Verse', 'runs' => [ [ 'text' => 'y', 'style' => '' ] ] ],
				[ 'type' => 'list', 'ordered' => false, 'items' => [ [ [ 'text' => 'i', 'style' => 'ComputerVoice' ] ] ] ],
			],
		],
		[ 'error' => 'skipped', 'blocks' => [ [ 'type' => 'paragraph', 'style' => 'Ignored', 'runs' => [] ] ] ],
	];
	$collect = $private( '\Sheaf\Import', 'collect_styles' );
	$found   = $collect->invoke( null, $entries );
	$check( 2 === ( $found['para']['Verse'] ?? 0 ), 'collect_styles counts paragraph style (2)' );
	$check( ! isset( $found['para']['Heading1'] ), 'collect_styles excludes heading style' );
	$check( ! isset( $found['para']['Ignored'] ), 'collect_styles skips errored entries' );
	$check( 2 === ( $found['char']['ComputerVoice'] ?? 0 ), 'collect_styles counts character style across runs + list items (2)' );

	/* ---- read_style_map: validates against allowed classes ---------------- */

	$_POST['char_map'] = [
		'ComputerVoice' => 'sheaf-style-ok',
		'Forged'        => 'sheaf-style-not-allowed',
		'Empty'         => '',
	];
	$read    = $private( '\Sheaf\Import', 'read_style_map' );
	$options = [ [ 'class' => 'sheaf-style-ok', 'label' => 'OK', 'set' => 'S' ] ];
	$map     = $read->invoke( null, 'char_map', $options );
	$check( 'sheaf-style-ok' === ( $map['ComputerVoice'] ?? '' ), 'read_style_map keeps an allowed class' );
	$check( ! isset( $map['Forged'] ), 'read_style_map drops a non-allowed class' );
	$check( ! isset( $map['Empty'] ), 'read_style_map drops an empty (ignored) mapping' );
	unset( $_POST['char_map'] );

	/* ---- style_options: splits a book's active styles by kind ------------- */

	delete_option( \Sheaf\Style_Sets::OPTION );
	$set = \Sheaf\Style_Sets::save_set( 'Talking Monsters' );
	\Sheaf\Style_Sets::save_style( $set, [ 'label' => 'Computer Voice', 'kind' => 'inline', 'props' => [ 'font-family' => 'monospace' ] ] );
	\Sheaf\Style_Sets::save_style( $set, [ 'label' => 'Verse', 'kind' => 'block', 'props' => [ 'text-align' => 'center' ] ] );

	$book = (int) wp_insert_post(
		[
			'post_type'   => 'page',
			'post_title'  => 'Import Style Book',
			'post_status' => 'publish',
		]
	);
	update_post_meta( $book, \Sheaf\Style_Sets::BOOK_META, [ $set ] );

	$opts = $private( '\Sheaf\Import', 'style_options' )->invoke( null, $book );
	$check( 1 === count( $opts['inline'] ), 'style_options returns one inline option' );
	$check( 1 === count( $opts['block'] ), 'style_options returns one block option' );
	$check( 'sheaf-style-talking-monsters-computer-voice' === ( $opts['inline'][0]['class'] ?? '' ), 'style_options inline class' );
	$check( 'is-style-sheaf-talking-monsters-verse' === ( $opts['block'][0]['class'] ?? '' ), 'style_options block class' );

	wp_delete_post( $book, true );
} finally {
	$_POST = $post_backup;
	update_option( \Sheaf\Style_Sets::OPTION, $snapshot );
}

WP_CLI::log( '' );
WP_CLI::log( "Passed: $pass   Failed: $fail" );
if ( $fail > 0 ) {
	WP_CLI::error( "$fail import-style check(s) failed." );
}
WP_CLI::success( 'Import style-mapping checks passed.' );
