<?php
/**
 * The "Books" admin screens (a submenu of the Sheaf/Chapters menu).
 *
 * - A listing of every Page that has chapters: its series/context, chapter
 *   count and total words.
 * - A per-book settings page where chapters are reordered by drag and drop
 *   (jquery-ui-sortable + an AJAX save), with room scaffolded for future
 *   per-book settings.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Books_Admin {

	private const PAGE       = 'sheaf-books';
	private const CAPABILITY = 'edit_posts';
	private const NONCE      = 'sheaf_reorder';

	/** Hook suffix of our submenu page, for asset scoping. */
	private static string $hook = '';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'wp_ajax_sheaf_reorder', [ self::class, 'ajax_reorder' ] );
	}

	public static function add_page(): void {
		self::$hook = (string) add_submenu_page(
			'edit.php?post_type=' . Chapters::POST_TYPE,
			__( 'Books', 'sheaf' ),
			__( 'Books', 'sheaf' ),
			self::CAPABILITY,
			self::PAGE,
			[ self::class, 'render' ]
		);
	}

	public static function enqueue( string $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_script(
			'sheaf-reorder',
			SHEAF_URL . 'assets/admin-reorder.js',
			[ 'jquery', 'jquery-ui-sortable' ],
			SHEAF_VERSION,
			true
		);
		wp_localize_script(
			'sheaf-reorder',
			'SheafReorder',
			[
				'ajax'       => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::NONCE ),
				'savingText' => __( 'Saving…', 'sheaf' ),
				'savedText'  => __( 'Order saved.', 'sheaf' ),
				'failedText' => __( 'Save failed.', 'sheaf' ),
			]
		);
	}

	/**
	 * Router for the page: a single book's settings, or the books list.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage books.', 'sheaf' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$book_id = isset( $_GET['book'] ) ? absint( $_GET['book'] ) : 0;

		echo '<div class="wrap">';
		if ( $book_id && Books::get_chapters_for_admin( $book_id ) ) {
			self::render_book( $book_id );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_list(): void {
		$book_ids = Books::all_book_ids();

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Books', 'sheaf' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Every Page that has chapters assigned to it appears here.', 'sheaf' ) . '</p>';

		if ( ! $book_ids ) {
			echo '<p>' . esc_html__( 'No books yet. Assign a chapter to a Page using the Book selector on the chapter editor.', 'sheaf' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Book', 'sheaf' ) . '</th>';
		echo '<th>' . esc_html__( 'Series / context', 'sheaf' ) . '</th>';
		echo '<th>' . esc_html__( 'Chapters', 'sheaf' ) . '</th>';
		echo '<th>' . esc_html__( 'Words', 'sheaf' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $book_ids as $book_id ) {
			$chapters = Books::get_chapters_for_admin( $book_id );
			$words    = 0;
			$count    = 0;
			foreach ( $chapters as $chapter ) {
				$words += Words::get( (int) $chapter->ID );
				if ( ! Chapters::is_section( (int) $chapter->ID ) ) {
					++$count; // Section dividers are not counted as chapters.
				}
			}

			$ancestors = array_map( 'get_the_title', Books::ancestors( $book_id ) );
			$context   = $ancestors ? implode( ' › ', $ancestors ) : '—';

			$manage = add_query_arg(
				[
					'post_type' => Chapters::POST_TYPE,
					'page'      => self::PAGE,
					'book'      => $book_id,
				],
				admin_url( 'edit.php' )
			);

			echo '<tr>';
			printf(
				'<td><strong><a href="%1$s">%2$s</a></strong><div class="row-actions"><span><a href="%3$s">%4$s</a></span></div></td>',
				esc_url( $manage ),
				esc_html( get_the_title( $book_id ) ),
				esc_url( (string) get_edit_post_link( $book_id ) ),
				esc_html__( 'Edit page', 'sheaf' )
			);
			printf( '<td>%s</td>', esc_html( $context ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $count ) ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $words ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function render_book( int $book_id ): void {
		$chapters = Books::get_chapters_for_admin( $book_id );
		$back     = add_query_arg(
			[
				'post_type' => Chapters::POST_TYPE,
				'page'      => self::PAGE,
			],
			admin_url( 'edit.php' )
		);

		printf(
			'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a>',
			esc_html( get_the_title( $book_id ) ),
			esc_url( $back ),
			esc_html__( 'All books', 'sheaf' )
		);
		echo '<hr class="wp-header-end">';

		echo '<h2>' . esc_html__( 'Reading order', 'sheaf' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Drag chapters to set the order they are read in. Changes save automatically.', 'sheaf' ) . '</p>';
		echo '<p id="sheaf-reorder-status" class="description" aria-live="polite"></p>';

		// Minimal styling kept inline so there is no extra stylesheet to ship.
		echo '<style>
			#sheaf-reorder{max-width:640px;margin:0;padding:0;list-style:none}
			#sheaf-reorder li{display:flex;align-items:center;gap:.5em;background:#fff;border:1px solid #dcdcde;padding:.6em .8em;margin:0 0 -1px}
			#sheaf-reorder .sheaf-reorder__handle{cursor:grab;color:#787c82}
			#sheaf-reorder .sheaf-reorder__num{min-width:2em;color:#787c82;text-align:right}
			#sheaf-reorder .sheaf-reorder__status{margin-left:auto;color:#787c82;font-size:.9em}
			#sheaf-reorder .sheaf-reorder__placeholder{height:2.6em;border:1px dashed #c3c4c7;background:#f6f7f7}
			#sheaf-reorder li.is-section{background:#f0f6fc;font-weight:600}
			#sheaf-reorder .sheaf-reorder__badge{margin-left:auto;font-size:.75em;font-weight:400;text-transform:uppercase;letter-spacing:.05em;color:#3858e9}
		</style>';

		printf( '<ul id="sheaf-reorder" data-book="%d">', $book_id );
		$i = 1;
		foreach ( $chapters as $chapter ) {
			$is_section = Chapters::is_section( (int) $chapter->ID );

			$status = ( 'publish' === $chapter->post_status )
				? ''
				: ' <span class="sheaf-reorder__status">' . esc_html( $chapter->post_status ) . '</span>';

			$badge = $is_section
				? ' <span class="sheaf-reorder__badge">' . esc_html__( 'Section', 'sheaf' ) . '</span>'
				: '';

			printf(
				'<li data-id="%1$d" class="%2$s"><span class="sheaf-reorder__handle dashicons dashicons-menu" aria-hidden="true"></span><span class="sheaf-reorder__num">%3$s</span><span class="sheaf-reorder__title">%4$s</span>%5$s%6$s</li>',
				(int) $chapter->ID,
				$is_section ? 'is-section' : '',
				$is_section ? '·' : (string) $i,
				esc_html( get_the_title( $chapter ) ),
				$badge,  // escaped above.
				$status  // escaped above.
			);
			if ( ! $is_section ) {
				++$i;
			}
		}
		echo '</ul>';

		// Scaffold: room for future per-book settings (chapter-break layout,
		// show-TOC-on-chapters, etc.). Intentionally not yet functional.
		echo '<h2>' . esc_html__( 'Display settings', 'sheaf' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Per-book display settings (chapter breaks, table of contents on chapters, …) will live here.', 'sheaf' ) . '</p>';
	}

	/**
	 * Persist a new chapter order for a book.
	 */
	public static function ajax_reorder(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		$book_id = isset( $_POST['book'] ) ? absint( $_POST['book'] ) : 0;
		$order   = isset( $_POST['order'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['order'] ) ) : [];

		if ( ! $book_id || ! $order ) {
			wp_send_json_error( 'bad-request', 400 );
		}

		$position = 0;
		$updated  = 0;
		foreach ( $order as $chapter_id ) {
			// Only reorder chapters that really belong to this book and that the
			// current user may edit.
			if ( (int) get_post_meta( $chapter_id, Books::BOOK_META, true ) !== $book_id ) {
				continue;
			}
			if ( ! current_user_can( 'edit_post', $chapter_id ) ) {
				continue;
			}
			wp_update_post(
				[
					'ID'         => $chapter_id,
					'menu_order' => $position,
				]
			);
			++$position;
			++$updated;
		}

		wp_send_json_success( [ 'updated' => $updated ] );
	}
}
