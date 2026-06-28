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

	private const PAGE        = self::MENU_SLUG;
	private const CAPABILITY  = 'edit_posts';
	private const NONCE       = 'sheaf_reorder';
	private const STYLE_NONCE = 'sheaf_book_style_sets';

	/** Hook suffix of our submenu page, for asset scoping. */
	private static string $hook = '';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'wp_ajax_sheaf_reorder', [ self::class, 'ajax_reorder' ] );
		add_action( 'admin_post_sheaf_book_style_sets', [ self::class, 'save_book_sets' ] );

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
		// Version by file mtime so edits bust the browser cache during active
		// development (the asset is mounted live and changes between requests).
		$asset = SHEAF_DIR . 'assets/admin-reorder.js';
		$ver   = file_exists( $asset ) ? (string) filemtime( $asset ) : SHEAF_VERSION;
		wp_enqueue_script(
			'sheaf-reorder',
			SHEAF_URL . 'assets/admin-reorder.js',
			[ 'jquery', 'jquery-ui-sortable' ],
			$ver,
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

		// Surface orphaned chapters (e.g. left behind when a book Page is
		// deleted) with a link to the list, where they can be bulk-assigned.
		$unassigned = Books::unassigned_chapter_count();
		if ( $unassigned ) {
			$url = add_query_arg(
				[
					'post_type'        => Chapters::POST_TYPE,
					'sheaf_unassigned' => 1,
				],
				admin_url( 'edit.php' )
			);
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( $url ),
				esc_html(
					sprintf(
						/* translators: %s: number of unassigned chapters. */
						_n( '%s chapter is not assigned to a book — assign it', '%s chapters are not assigned to a book — assign them', $unassigned, 'sheaf' ),
						number_format_i18n( $unassigned )
					)
				)
			);
		}

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

			// Series / context = the book's ancestor Pages, each linked to the
			// page it names.
			$ancestors = Books::ancestors( $book_id );
			if ( $ancestors ) {
				$links = array_map(
					static function ( \WP_Post $page ): string {
						return sprintf(
							'<a href="%1$s">%2$s</a>',
							esc_url( (string) get_permalink( $page ) ),
							esc_html( get_the_title( $page ) )
						);
					},
					$ancestors
				);
				$context = implode( ' › ', $links );
			} else {
				$context = '<span aria-hidden="true">—</span>';
			}

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
			echo '<td>' . $context . '</td>'; // Links built and escaped above.
			printf(
				'<td><a href="%1$s">%2$s</a></td>',
				esc_url( $chapters_url ),
				esc_html( number_format_i18n( $count ) )
			);
			printf( '<td>%s</td>', esc_html( number_format_i18n( $words ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function render_book( int $book_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only confirmation flag.
		if ( isset( $_GET['sheaf_msg'] ) && 'sets-saved' === $_GET['sheaf_msg'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Style sets updated.', 'sheaf' ) . '</p></div>';
		}

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

		$permalink = (string) get_permalink( $book_id );
		$edit_page = (string) get_edit_post_link( $book_id );

		echo '<style>
			.sheaf-back{display:inline-block;margin:.6em 0 .2em;color:#646970;text-decoration:none;font-size:13px}
			.sheaf-back:hover,.sheaf-back:focus{color:#2271b1}
			.sheaf-book-heading{margin:0 0 .4em}
			.sheaf-book-heading .wp-heading-inline{margin:0}
			.sheaf-book-title{text-decoration:none;color:inherit}
			.sheaf-book-title:hover,.sheaf-book-title:focus{color:#2271b1}
			.sheaf-book-heading .row-actions{left:auto;visibility:hidden}
			.sheaf-book-heading:hover .row-actions,.sheaf-book-heading:focus-within .row-actions{visibility:visible}
		</style>';

		// "Back to the list" link, sitting above the title — muted and unstyled,
		// not a button.
		printf(
			'<a href="%1$s" class="sheaf-back">&larr; %2$s</a>',
			esc_url( $back ),
			esc_html__( 'All Books', 'sheaf' )
		);

		// Title links to the live book page; the management actions reveal on hover.
		echo '<div class="sheaf-book-heading">';
		printf(
			'<h1 class="wp-heading-inline"><a href="%1$s" class="sheaf-book-title">%2$s</a></h1>',
			esc_url( $permalink ),
			esc_html( get_the_title( $book_id ) )
		);

		$actions   = [];
		$actions[] = sprintf( '<span class="view"><a href="%s">%s</a></span>', esc_url( $permalink ), esc_html__( 'View Book', 'sheaf' ) );
		if ( $edit_page ) {
			$actions[] = sprintf( '<span class="edit"><a href="%s">%s</a></span>', esc_url( $edit_page ), esc_html__( 'Edit Book Page', 'sheaf' ) );
		}
		$actions[] = sprintf( '<span><a href="%s">%s</a></span>', esc_url( $add_url ), esc_html__( 'Add New Chapter', 'sheaf' ) );
		$actions[] = sprintf( '<span><a href="%s">%s</a></span>', esc_url( Import::url( $book_id ) ), esc_html__( 'Import Chapters', 'sheaf' ) );
		echo '<div class="row-actions">' . implode( ' | ', $actions ) . '</div>'; // Links built and escaped above.
		echo '</div>';

		echo '<hr class="wp-header-end">';

		echo '<h2>' . esc_html__( 'Chapters', 'sheaf' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Drag a chapter by its handle to set the reading order — changes save automatically.', 'sheaf' ) . '</p>';
		echo '<p id="sheaf-reorder-status" class="description" aria-live="polite"></p>';

		self::reorder_styles();
		self::render_chapters_table( $book_id, $chapters );

		self::render_style_sets( $book_id );

		// Scaffold: room for future per-book settings (chapter-break layout,
		// show-TOC-on-chapters, etc.). Intentionally not yet functional.
		echo '<h2>' . esc_html__( 'Display settings', 'sheaf' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Per-book display settings (chapter breaks, table of contents on chapters, …) will live here.', 'sheaf' ) . '</p>';
	}

	/**
	 * Per-book style-set activation: which sets the chapter editor and importer
	 * offer for this book's chapters. Because the style CSS is global, toggling a
	 * set here neither adds nor removes styling from chapters already written —
	 * it only changes what is offered going forward.
	 */
	private static function render_style_sets( int $book_id ): void {
		$all = Style_Sets::all();

		echo '<h2>' . esc_html__( 'Style sets', 'sheaf' ) . '</h2>';

		if ( ! $all ) {
			printf(
				'<p class="description">%s</p>',
				wp_kses(
					sprintf(
						/* translators: %s: URL of the Style Sets screen. */
						__( 'No style sets exist yet. <a href="%s">Create one</a> to offer named styles to this book.', 'sheaf' ),
						esc_url( Style_Sets_Admin::url() )
					),
					[ 'a' => [ 'href' => [] ] ]
				)
			);
			return;
		}

		$active = Style_Sets::active_sets( $book_id );

		echo '<p class="description">' . esc_html__( 'Choose which style sets this book’s chapters may use. This controls what the editor and importer offer; it does not change styling already applied.', 'sheaf' ) . '</p>';

		echo '<style>.sheaf-style-set-list{margin:.6em 0 1em}.sheaf-style-set-list li{margin:.25em 0}.sheaf-style-set-list .description{margin-left:.4em}</style>';

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::STYLE_NONCE );
		echo '<input type="hidden" name="action" value="sheaf_book_style_sets">';
		printf( '<input type="hidden" name="book" value="%d">', $book_id );

		echo '<ul class="sheaf-style-set-list">';
		foreach ( $all as $set => $data ) {
			$label = '' !== (string) ( $data['label'] ?? '' ) ? (string) $data['label'] : (string) $set;
			$count = count( (array) ( $data['styles'] ?? [] ) );
			printf(
				'<li><label><input type="checkbox" name="sheaf_sets[]" value="%1$s"%2$s> %3$s</label><span class="description">%4$s</span></li>',
				esc_attr( (string) $set ),
				checked( in_array( (string) $set, $active, true ), true, false ),
				esc_html( $label ),
				esc_html( sprintf( /* translators: %s: number of styles in the set. */ _n( '%s style', '%s styles', $count, 'sheaf' ), number_format_i18n( $count ) ) )
			);
		}
		echo '</ul>';

		submit_button( __( 'Save style sets', 'sheaf' ) );
		echo '</form>';
	}

	/**
	 * Save a book's active style sets (post/redirect/get), keeping only sets that
	 * still exist in the library.
	 */
	public static function save_book_sets(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage books.', 'sheaf' ) );
		}
		check_admin_referer( self::STYLE_NONCE );

		$book_id = isset( $_POST['book'] ) ? absint( $_POST['book'] ) : 0;
		$chosen  = isset( $_POST['sheaf_sets'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['sheaf_sets'] ) ) : [];
		$chosen  = array_values( array_intersect( $chosen, array_keys( Style_Sets::all() ) ) );

		if ( $book_id ) {
			if ( $chosen ) {
				update_post_meta( $book_id, Style_Sets::BOOK_META, $chosen );
			} else {
				delete_post_meta( $book_id, Style_Sets::BOOK_META );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'post_type' => Chapters::POST_TYPE,
					'page'      => self::PAGE,
					'book'      => $book_id,
					'sheaf_msg' => 'sets-saved',
				],
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * The book's chapters as one sortable list table: drag a row by its handle
	 * to set the reading order (saved over AJAX), with the overview columns an
	 * author wants in the same rows — reading position, publish state and last
	 * edit, comments, and word count. Always scoped to a single book, so there
	 * is no "Book" column and no per-book filter.
	 *
	 * @param \WP_Post[] $chapters
	 */
	private static function render_chapters_table( int $book_id, array $chapters ): void {
		echo '<table class="wp-list-table widefat fixed striped sheaf-chapters">';
		echo '<thead><tr>';
		echo '<th scope="col" style="width:5.5em">' . esc_html__( 'Order', 'sheaf' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Title', 'sheaf' ) . '</th>';
		echo '<th scope="col" style="width:13em">' . esc_html__( 'Status', 'sheaf' ) . '</th>';
		echo '<th scope="col" style="width:6em">' . esc_html__( 'Comments', 'sheaf' ) . '</th>';
		echo '<th scope="col" style="width:9em">' . esc_html__( 'Words', 'sheaf' ) . '</th>';
		echo '</tr></thead>';

		printf( '<tbody id="sheaf-reorder" data-book="%d">', $book_id );

		if ( ! $chapters ) {
			echo '<tr class="no-items"><td colspan="5">' . esc_html__( 'No chapters yet.', 'sheaf' ) . '</td></tr>';
		}

		$i = 1;
		foreach ( $chapters as $chapter ) {
			$id         = (int) $chapter->ID;
			$is_section = Chapters::is_section( $id );

			printf( '<tr data-id="%1$d" class="%2$s">', $id, $is_section ? 'is-section' : '' );

			// Order: drag handle + reading position (sections are not numbered).
			printf(
				'<td class="sheaf-order-cell"><span class="sheaf-reorder__handle dashicons dashicons-menu" aria-hidden="true"></span> <span class="sheaf-reorder__num">%s</span></td>',
				$is_section ? '·' : esc_html( number_format_i18n( $i ) )
			);

			self::title_cell( $chapter, $is_section );
			self::status_cell( $chapter );
			self::comments_cell( $id );
			self::words_cell( $id, $is_section );

			echo '</tr>';

			if ( ! $is_section ) {
				++$i;
			}
		}

		echo '</tbody></table>';
	}

	/**
	 * Title cell: editable title link, a section tag, and the
	 * Edit / View-or-Preview / Trash row actions.
	 */
	private static function title_cell( \WP_Post $chapter, bool $is_section ): void {
		$id   = (int) $chapter->ID;
		$edit = (string) get_edit_post_link( $id );

		echo '<td>';
		if ( $edit ) {
			printf( '<strong class="sheaf-title"><a class="row-title" href="%1$s">%2$s</a></strong>', esc_url( $edit ), esc_html( get_the_title( $chapter ) ) );
		} else {
			printf( '<strong class="sheaf-title">%s</strong>', esc_html( get_the_title( $chapter ) ) );
		}
		if ( $is_section ) {
			echo ' <span class="post-state">' . esc_html__( 'Section', 'sheaf' ) . '</span>';
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
	}

	/**
	 * Status cell: publish state plus a date — when it went live, or, for
	 * everything else, when it was last edited (so stale drafts stand out).
	 */
	private static function status_cell( \WP_Post $chapter ): void {
		$obj   = get_post_status_object( get_post_status( $chapter ) );
		$label = $obj ? $obj->label : ucfirst( $chapter->post_status );

		if ( 'publish' === $chapter->post_status ) {
			/* translators: %s: date a chapter was published. */
			$when = sprintf( __( 'Published %s', 'sheaf' ), get_the_date( '', $chapter ) );
		} else {
			/* translators: %s: date a chapter was last edited. */
			$when = sprintf( __( 'Edited %s', 'sheaf' ), get_the_modified_date( '', $chapter ) );
		}

		printf(
			'<td><span class="sheaf-status">%1$s</span><br><span class="description">%2$s</span></td>',
			esc_html( $label ),
			esc_html( $when )
		);
	}

	/**
	 * Comments cell: the familiar approved-count bubble, plus a pending bubble
	 * linking to the moderation queue when comments await review. (WordPress
	 * tracks no per-reader "new/unread" state, and stores no view counts.)
	 */
	private static function comments_cell( int $id ): void {
		$approved = (int) get_comments_number( $id );
		$pending  = function_exists( 'get_pending_comments_num' ) ? (int) get_pending_comments_num( $id ) : 0;

		echo '<td class="column-comments">';
		if ( $approved || $pending ) {
			$base = admin_url( 'edit-comments.php?p=' . $id );
			echo '<div class="post-com-count-wrapper">';
			printf(
				'<a href="%1$s" class="post-com-count post-com-count-approved"><span class="comment-count-approved" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></a>',
				esc_url( $base ),
				esc_html( number_format_i18n( $approved ) ),
				/* translators: %s: number of approved comments. */
				esc_html( sprintf( _n( '%s approved comment', '%s approved comments', $approved, 'sheaf' ), number_format_i18n( $approved ) ) )
			);
			if ( $pending ) {
				printf(
					'<a href="%1$s" class="post-com-count post-com-count-pending"><span class="comment-count-pending" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></a>',
					esc_url( add_query_arg( 'comment_status', 'moderated', $base ) ),
					esc_html( number_format_i18n( $pending ) ),
					/* translators: %s: number of comments awaiting moderation. */
					esc_html( sprintf( _n( '%s comment awaiting moderation', '%s comments awaiting moderation', $pending, 'sheaf' ), number_format_i18n( $pending ) ) )
				);
			}
			echo '</div>';
		} else {
			echo '<span aria-hidden="true">—</span>';
		}
		echo '</td>';
	}

	/**
	 * Words cell: word count and reading time (sections carry neither).
	 */
	private static function words_cell( int $id, bool $is_section ): void {
		if ( $is_section ) {
			echo '<td><span aria-hidden="true">—</span></td>';
			return;
		}
		$words   = Words::get( $id );
		$minutes = Words::reading_minutes( $words );
		printf(
			'<td>%1$s<br><span class="description">%2$s</span></td>',
			esc_html( number_format_i18n( $words ) ),
			/* translators: %d: reading time in minutes. */
			esc_html( sprintf( _n( '%d min', '%d min', $minutes, 'sheaf' ), $minutes ) )
		);
	}

	/**
	 * Inline styling for the sortable chapters table — kept inline so there is
	 * no extra stylesheet to ship.
	 */
	private static function reorder_styles(): void {
		echo '<style>
			.sheaf-chapters .sheaf-order-cell{white-space:nowrap}
			.sheaf-chapters .sheaf-reorder__handle{cursor:grab;color:#787c82;vertical-align:middle}
			.sheaf-chapters .sheaf-reorder__num{display:inline-block;min-width:1.6em;text-align:right;color:#50575e}
			.sheaf-chapters tr.is-section td{background:#f0f6fc}
			.sheaf-chapters tr.is-section .sheaf-title{font-weight:600}
			.sheaf-chapters .sheaf-status{font-weight:600}
			.sheaf-reorder__placeholder td{background:#f6f7f7}
			tr.ui-sortable-helper{box-shadow:0 2px 6px rgba(0,0,0,.18);display:table}
		</style>';
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
