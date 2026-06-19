<?php
/**
 * Development seed data for Sheaf. NOT loaded by the plugin — run it by hand:
 *
 *   wpenv run cli wp eval-file wp-content/plugins/sheaf/tools/seed.php
 *
 * It is idempotent: pages are upserted by (slug, parent) and chapters by
 * (book, slug), so re-running reconciles rather than duplicates. The fixture
 * mirrors the agreed sample URLs and the router torture test — five books, five
 * chapters each (~1200 words of filler), and the slug "prologue" reused across
 * five different books so per-book URL discrimination is exercised.
 *
 * @package Sheaf
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return; // Guard: this is a CLI-only dev tool.
}

if ( ! function_exists( 'sheaf_seed_filler' ) ) {
	/**
	 * ~1200 words of block-wrapped filler, deterministic per $seed.
	 */
	function sheaf_seed_filler( string $seed ): string {
		$sentences = [
			'The ash came down like snow that year, and no one spoke of it.',
			'She kept the lamp trimmed low, because oil was dear and the nights were long.',
			'Below the levee the water turned the colour of old iron and held its breath.',
			'He counted the bells from the far tower and lost the count twice.',
			'They had marched since the cold road forked, and the forking felt like a verdict.',
			'A gull wheeled once over the harbour and did not come back.',
			'In the workshop the clockwork hearts ticked out of time with one another.',
			'Nobody had told the children that the gate would not open again.',
			'The letters arrived weeks late, smelling of smoke and salt and other people.',
			'Skyfire broke along the ridge and for a moment the whole valley was noon.',
			'There is a kind of quiet that is only the held edge of a scream.',
			'He set the last gear and the mechanism shivered, almost alive, almost forgiving.',
			'The thaw uncovered what the winter had been polite enough to bury.',
			'She wrote his name in the margin and then, carefully, crossed it out.',
			'Floodlight swept the wall and found nothing, which was the worst answer.',
			'They said the war would be short; they were right, in the way of grief.',
			'Frost wrote its slow grammar across the glass before first light.',
			'The map was wrong, and being wrong, it had killed three of them already.',
			'In the hollow under the hill the old machines kept their patient appointments.',
			'He learned the city by its smells, and the city, in turn, forgot him.',
		];

		$count   = count( $sentences );
		$offset  = (int) ( crc32( $seed ) % $count );
		$words   = 0;
		$paras   = [];
		$current = [];
		$i       = 0;

		while ( $words < 1200 ) {
			$sentence  = $sentences[ ( $offset + $i ) % $count ];
			$current[] = $sentence;
			$words    += str_word_count( $sentence );
			if ( count( $current ) >= 5 ) {
				$paras[]= implode( ' ', $current );
				$current = [];
			}
			++$i;
		}
		if ( $current ) {
			$paras[] = implode( ' ', $current );
		}

		$blocks = '';
		foreach ( $paras as $p ) {
			$blocks .= "<!-- wp:paragraph -->\n<p>{$p}</p>\n<!-- /wp:paragraph -->\n\n";
		}
		return $blocks;
	}

	/**
	 * Upsert a Page by (slug, parent). Returns its ID.
	 */
	function sheaf_seed_page( string $slug, string $title, int $parent = 0, string $content = '' ): int {
		$existing = get_posts(
			[
				'post_type'   => 'page',
				'name'        => $slug,
				'post_parent' => $parent,
				'post_status' => 'any',
				'numberposts' => 1,
			]
		);
		$data = [
			'post_title'   => $title,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_parent'  => $parent,
			'post_name'    => $slug,
			'post_content' => $content,
		];
		if ( $existing ) {
			$data['ID'] = $existing[0]->ID;
			wp_update_post( $data );
			return (int) $existing[0]->ID;
		}
		return (int) wp_insert_post( $data );
	}

	/**
	 * A short blurb for a section divider (a paragraph or two).
	 */
	function sheaf_seed_section_text( string $seed ): string {
		$lines = [
			'What follows was set down later, when the smoke had cleared enough to see by.',
			'The first part of the war belongs to the living; the rest belongs to the water.',
			'Here the wheels begin to turn in earnest, and nothing turns back.',
			'Of the cold months little was written, and less was meant to last.',
		];
		$line = $lines[ (int) ( crc32( $seed ) % count( $lines ) ) ];
		return "<!-- wp:paragraph -->\n<p>{$line}</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Upsert a chapter by (book, slug). Returns its ID.
	 */
	function sheaf_seed_chapter( int $book_id, string $slug, string $title, int $order, string $content, bool $is_section = false ): int {
		\Sheaf\Books::set_book_context( $book_id );

		$existing = get_posts(
			[
				'post_type'   => \Sheaf\Chapters::POST_TYPE,
				'name'        => $slug,
				'post_status' => 'any',
				'numberposts' => 1,
				'meta_key'    => \Sheaf\Books::BOOK_META,
				'meta_value'  => $book_id,
			]
		);
		$data = [
			'post_title'   => $title,
			'post_type'    => \Sheaf\Chapters::POST_TYPE,
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'menu_order'   => $order,
			'post_content' => $content,
			'meta_input'   => [
				\Sheaf\Books::BOOK_META    => $book_id,
				\Sheaf\Chapters::SECTION_META => $is_section,
			],
		];
		if ( $existing ) {
			$data['ID'] = $existing[0]->ID;
			$id         = (int) $existing[0]->ID;
			wp_update_post( $data );
		} else {
			$id = (int) wp_insert_post( $data );
		}

		\Sheaf\Books::set_book_context( 0 );
		\Sheaf\Words::refresh( $id );
		return $id;
	}
}

// --- Structure: containers, series, books, standalone pages -----------------

$novels = sheaf_seed_page( 'novels', 'Novels' );
$fiction = sheaf_seed_page( 'fiction', 'Fiction' );

// The Long War — a series with two books.
$long_war = sheaf_seed_page( 'long-war', 'The Long War', $novels );
$embers   = sheaf_seed_page( 'embers', 'Embers', $long_war );
$ashfall  = sheaf_seed_page( 'ashfall', 'Ashfall', $long_war );

// Clockwork — a second series (trilogy index) with two books here.
$clockwork = sheaf_seed_page( 'clockwork', 'Clockwork', $novels );
$heart     = sheaf_seed_page( 'clockwork-heart', 'Clockwork Heart', $clockwork );
$iron_wind = sheaf_seed_page( 'iron-wind', 'Iron Wind', $clockwork );

// Wintering — a book with chapters that is NOT part of any series.
$wintering = sheaf_seed_page( 'wintering', 'Wintering', $novels );

// Standalone single-page novel (no chapters).
sheaf_seed_page( 'agreement-with-hell', 'Agreement with Hell', $novels, sheaf_seed_filler( 'agreement' ) );

// Novella as a single post, plus a hand-authored child Page (author's note).
$asterism = sheaf_seed_page( 'asterism', 'Asterism', $fiction, sheaf_seed_filler( 'asterism' ) );
sheaf_seed_page( 'ship-design', 'On the Ship Design', $asterism, sheaf_seed_filler( 'ship-design' ) );

// Ordinary site pages.
$about = sheaf_seed_page( 'about', 'About' );
sheaf_seed_page( 'met', 'About the Author', $about );

// --- Chapters: five per book; "prologue" reused across five books -----------

$chapters = [
	$embers    => [
		[ 'prologue', 'Prologue', 0 ],
		[ '1-the-cold-road', 'The Cold Road', 1 ],
		[ '2-smoke-and-salt', 'Smoke and Salt', 2 ],
		[ '13-resignations', 'Resignations', 3 ],
		[ 'interlude-letters', 'Interlude: Letters', 4 ],
	],
	$ashfall   => [
		[ 'prologue', 'Prologue', 0 ],
		[ '1-grey-morning', 'Grey Morning', 1 ],
		[ '2-the-levee', 'The Levee', 2 ],
		[ '3-floodlight', 'Floodlight', 3 ],
		[ 'epilogue', 'Epilogue', 4 ],
	],
	// Clockwork Heart shows section dividers interleaved with chapters.
	$heart     => [
		[ 'part-i-wind-up', 'Part I: Wind-Up', 0, true ],
		[ 'prologue', 'Prologue', 1 ],
		[ '1', 'Chapter One', 2 ],
		[ '2', 'Chapter Two', 3 ],
		[ 'part-ii-mainspring', 'Part II: The Mainspring', 4, true ],
		[ '3', 'Chapter Three', 5 ],
		[ '4', 'Chapter Four', 6 ],
	],
	$iron_wind => [
		[ 'prologue', 'Prologue', 0 ],
		[ '10-ashpath', 'Chapter Ten', 1 ],
		[ '11-the-gate', 'Chapter Eleven', 2 ],
		[ '12-skyfire', 'Skyfire', 3 ],
		[ '13-aftermath', 'Chapter Thirteen', 4 ],
	],
	$wintering => [
		[ 'prologue', 'Prologue', 0 ],
		[ '1-first-frost', 'First Frost', 1 ],
		[ '2-the-hollow', 'The Hollow', 2 ],
		[ '3-thaw', 'Thaw', 3 ],
		[ '4-last-light', 'Last Light', 4 ],
	],
];

foreach ( $chapters as $book_id => $list ) {
	foreach ( $list as $c ) {
		$is_section = isset( $c[3] ) ? (bool) $c[3] : false;
		$content    = $is_section
			? sheaf_seed_section_text( $c[0] )
			: sheaf_seed_filler( $c[0] . '-' . $book_id );
		sheaf_seed_chapter( (int) $book_id, $c[0], $c[1], (int) $c[2], $content, $is_section );
	}
}

// A blog post elsewhere on the site (kept under /%postname%/).
if ( ! get_page_by_path( 'title-text', OBJECT, 'post' ) ) {
	wp_insert_post(
		[
			'post_title'   => 'Title Text',
			'post_name'    => 'title-text',
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_content' => sheaf_seed_filler( 'blog' ),
		]
	);
}

flush_rewrite_rules();

WP_CLI::success( 'Sheaf seed complete. Books: Embers, Ashfall, Clockwork Heart, Iron Wind, Wintering (5 chapters each).' );
