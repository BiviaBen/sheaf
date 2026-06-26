<?php
/**
 * The "Sheafs" admin menu and its "Books" screens.
 *
 * Provides the plugin's top-level menu — Books, Chapters, New Chapter — landing
 * on the Books list. Books are any Page with chapters: the list shows each
 * book's series/context, chapter count and total words; a per-book settings
 * page reorders chapters by drag and drop (jquery-ui-sortable + an AJAX save),
 * with room scaffolded for future per-book settings.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Books_Admin {

	/** Top-level menu slug; other Sheaf screens hang their submenus off it. */
	public const MENU_SLUG = 'sheaf-books';

	private const PAGE       = self::MENU_SLUG;
	private const CAPABILITY = 'edit_posts';
	private const NONCE      = 'sheaf_reorder';

	/** Hook suffix of our submenu page, for asset scoping. */
	private static string $hook = '';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'wp_ajax_sheaf_reorder', [ self::class, 'ajax_reorder' ] );

		// Keep the "Sheafs" menu open/highlighted on the (menu-hidden) chapter
		// list and editor screens.
		add_filter( 'parent_file', [ self::class, 'highlight_parent' ] );
		add_filter( 'submenu_file', [ self::class, 'highlight_submenu' ] );
	}

	/**
	 * Register the "Sheafs" top-level menu: Books, Chapters, New Chapter.
	 */
	public static function add_page(): void {
		self::$hook = (string) add_menu_page(
			__( 'Sheafs', 'sheaf' ),
			__( 'Sheafs', 'sheaf' ),
			self::CAPABILITY,
			self::PAGE,
			[ self::class, 'render' ],
			'dashicons-book',
			25
		);

		// First submenu repeats the parent slug, which both labels it "Books" and
		// makes the top-level "Sheafs" link land on the Books list.
		add_submenu_page(
			self::PAGE,
			__( 'Books', 'sheaf' ),
			__( 'Books', 'sheaf' ),
			self::CAPABILITY,
			self::PAGE,
			[ self::class, 'render' ]
		);
		// No standalone "Chapters" link: chapters are only meaningful inside a
		// book, so they are managed from each book's screen instead.
		add_submenu_page(
			self::PAGE,
			__( 'New Chapter', 'sheaf' ),
			__( 'New Chapter', 'sheaf' ),
			self::CAPABILITY,
			'post-new.php?post_type=' . Chapters::POST_TYPE
		);
	}

	/**
	 * Treat the chapter screens as children of the Sheafs menu.
	 */
	public static function highlight_parent( string $parent_file ): string {
		if ( Chapters::POST_TYPE === ( $GLOBALS['typenow'] ?? '' ) ) {
			return self::PAGE;
		}
		return $parent_file;
	}

	/**
	 * Highlight the right Sheafs submenu for the current chapter screen.
	 */
	public static function highlight_submenu( ?string $submenu_file ): ?string {
		if ( Chapters::POST_TYPE !== ( $GLOBALS['typenow'] ?? '' ) ) {
			return $submenu_file;
		}
		if ( 'post-new.php' === ( $GLOBALS['pagenow'] ?? '' ) ) {
			return 'post-new.php?post_type=' . Chapters::POST_TYPE;
		}
		// The chapter list and editor live under "Books" now that there is no
		// standalone Chapters menu item.
		return self::PAGE;
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
			$chapters_url = add_query_arg(
				[
					'post_type'  => Chapters::POST_TYPE,
					'sheaf_book' => $book_id,
				],
				admin_url( 'edit.php' )
			);
			$add_url = add_query_arg(
				[
					'post_type'  => Chapters::POST_TYPE,
					'sheaf_book' => $book_id,
				],
				admin_url( 'post-new.php' )
			);

			echo '<tr>';
			printf(
				'<td><strong><a href="%1$s">%2$s</a></strong><div class="row-actions"><span><a href="%3$s">%4$s</a> | </span><span><a href="%5$s">%6$s</a> | </span><span><a href="%7$s">%8$s</a></span></div></td>',
				esc_url( $manage ),
				esc_html( get_the_title( $book_id ) ),
				esc_url( (string) get_edit_post_link( $book_id ) ),
				esc_html__( 'Edit page', 'sheaf' ),
				esc_url( $add_url ),
				esc_html__( 'Add chapter', 'sheaf' ),
				esc_url( Import::url( $book_id ) ),
				esc_html__( 'Import', 'sheaf' )
			);
			printf( '<td>%s</td>', esc_html( $context ) );
			printf(
				'<td><a href="%1$s">%2$s</a><div class="row-actions"><span><a href="%3$s">%4$s</a></span></div></td>',
				esc_url( $chapters_url ),
				esc_html( number_format_i18n( $count ) ),
				esc_url( $add_url ),
				esc_html__( 'Add chapter', 'sheaf' )
			);
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
		$add_url  = add_query_arg(
			[
				'post_type'  => Chapters::POST_TYPE,
				'sheaf_book' => $book_id,
			],
			admin_url( 'post-new.php' )
		);

		printf(
			'<h1 class="wp-heading-inline">%1$s</h1> <a href="%2$s" class="page-title-action">%3$s</a> <a href="%4$s" class="page-title-action">%5$s</a> <a href="%6$s" class="page-title-action">%7$s</a>',
			esc_html( get_the_title( $book_id ) ),
			esc_url( $add_url ),
			esc_html__( 'Add chapter', 'sheaf' ),
			esc_url( Import::url( $book_id ) ),
			esc_html__( 'Import chapters', 'sheaf' ),
			esc_url( $back ),
			esc_html__( 'All books', 'sheaf' )
		);
		echo '<hr class="wp-header-end">';

		self::render_chapter_list( $chapters );

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
	 * The book's chapters as a list table — title (with edit/view/trash
	 * actions), reading order and word count. This replaces the standalone
	 * Chapters screen; it is always scoped to this one book, so there is no
	 * "Book" column and no per-book filter.
	 *
	 * @param \WP_Post[] $chapters
	 */
	private static function render_chapter_list( array $chapters ): void {
		echo '<h2>' . esc_html__( 'Chapters', 'sheaf' ) . '</h2>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Title', 'sheaf' ) . '</th>';
		echo '<th style="width:6em">' . esc_html__( 'Order', 'sheaf' ) . '</th>';
		echo '<th style="width:9em">' . esc_html__( 'Words', 'sheaf' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $chapters as $chapter ) {
			$id         = (int) $chapter->ID;
			$is_section = Chapters::is_section( $id );
			$edit       = (string) get_edit_post_link( $id );

			// Title cell: editable-link, an optional status/section tag, and the
			// usual Edit / View-or-Preview / Trash row actions.
			echo '<tr><td>';
			printf(
				'<strong><a class="row-title" href="%1$s">%2$s</a></strong>',
				esc_url( $edit ),
				esc_html( get_the_title( $chapter ) )
			);
			if ( $is_section ) {
				echo ' <span class="post-state">' . esc_html__( 'Section', 'sheaf' ) . '</span>';
			} elseif ( 'publish' !== $chapter->post_status ) {
				echo ' <span class="post-state">' . esc_html( ucfirst( $chapter->post_status ) ) . '</span>';
			}

			$actions = [];
			if ( $edit ) {
				$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html__( 'Edit', 'sheaf' ) );
			}
			if ( 'publish' === $chapter->post_status ) {
				$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( (string) get_permalink( $id ) ), esc_html__( 'View', 'sheaf' ) );
			} else {
				$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( get_preview_post_link( $id ) ), esc_html__( 'Preview', 'sheaf' ) );
			}
			$trash = get_delete_post_link( $id );
			if ( $trash ) {
				$actions[] = sprintf( '<a class="submitdelete" href="%s">%s</a>', esc_url( $trash ), esc_html__( 'Trash', 'sheaf' ) );
			}
			printf( '<div class="row-actions"><span>%s</span></div>', implode( ' | </span><span>', $actions ) );
			echo '</td>';

			printf( '<td>%s</td>', (int) $chapter->menu_order );

			if ( $is_section ) {
				echo '<td><span aria-hidden="true">—</span></td>';
			} else {
				$words   = Words::get( $id );
				$minutes = Words::reading_minutes( $words );
				printf(
					'<td>%1$s<br><span class="description">%2$s</span></td>',
					esc_html( number_format_i18n( $words ) ),
					esc_html( sprintf( /* translators: %d: reading time in minutes. */ _n( '%d min', '%d min', $minutes, 'sheaf' ), $minutes ) )
				);
			}
			echo '</tr>';
		}

		echo '</tbody></table>';
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
