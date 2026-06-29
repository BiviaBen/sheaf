<?php
/**
 * The "Style Sets" admin screen — a CRUD UI over the Style_Sets library.
 *
 * Lives as a submenu under the Sheafs menu (second, right under Books). The top
 * of the screen is a compact list of every set (style counts + which books use
 * it); creating a new set sits below the list; clicking a set's name reveals its
 * detail block (its styles, with previews, plus add/edit/delete). Forms POST to
 * admin-post.php and redirect back (post/redirect/get).
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Style_Sets_Admin {

	private const PAGE       = 'sheaf-style-sets';
	private const CAPABILITY = 'edit_posts';
	private const NONCE      = 'sheaf_style_sets';
	private const ACTION     = 'sheaf_style_sets';

	/** Evocative sentences (from the seed filler) for random preview text. */
	private const FILLER = [
		'The ash came down like snow that year, and no one spoke of it.',
		'She kept the lamp trimmed low, because oil was dear and the nights were long.',
		'Below the levee the water turned the colour of old iron and held its breath.',
		'He counted the bells from the far tower and lost the count twice.',
		'They had marched since the cold road forked, and the forking felt like a verdict.',
		'A gull wheeled once over the harbour and did not come back.',
		'In the workshop the brass mechanisms ticked out of time with one another.',
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

	/** Hook suffix of our screen, for asset scoping. */
	private static string $hook = '';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'wp_ajax_sheaf_bulk_assign', [ self::class, 'ajax_bulk_assign' ] );
		add_action( 'wp_ajax_sheaf_embed_font', [ self::class, 'ajax_embed_font' ] );
	}

	public static function add_page(): void {
		self::$hook = (string) add_submenu_page(
			Books_Admin::MENU_SLUG,
			__( 'Style Sets', 'sheaf' ),
			__( 'Style Sets', 'sheaf' ),
			self::CAPABILITY,
			self::PAGE,
			[ self::class, 'render' ],
			1 // Second item, right under "Books".
		);
	}

	/** Load the live-preview script only on this screen. */
	public static function enqueue( string $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}
		$asset = SHEAF_DIR . 'assets/admin-style-preview.js';
		$ver   = file_exists( $asset ) ? (string) filemtime( $asset ) : SHEAF_VERSION;
		wp_enqueue_script(
			'sheaf-style-preview',
			SHEAF_URL . 'assets/admin-style-preview.js',
			[],
			$ver,
			true
		);
		wp_localize_script(
			'sheaf-style-preview',
			'SheafStyleSets',
			[
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( self::NONCE ),
				'fonts' => [
					'installed' => Fonts::installed_names(),
					'catalog'   => Fonts::catalog_names(),
				],
				'i18n'  => [
					'embedded' => __( '✓ embedded', 'sheaf' ),
					'system'   => __( '(system font)', 'sheaf' ),
					/* translators: %s: font family name. */
					'embed'    => __( 'Embed “%s”', 'sheaf' ),
					'embedding' => __( 'Embedding…', 'sheaf' ),
					'failed'   => __( 'Embed failed', 'sheaf' ),
				],
			]
		);
	}

	/**
	 * Embed (self-host) a catalog font on demand, returning its @font-face so the
	 * editor can show it in the live preview right away.
	 */
	public static function ajax_embed_font(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$family = isset( $_POST['family'] ) ? sanitize_text_field( wp_unslash( $_POST['family'] ) ) : '';
		if ( '' === $family ) {
			wp_send_json_error( 'no-family', 400 );
		}
		if ( ! Fonts::install_from_catalog( $family ) ) {
			wp_send_json_error( 'install-failed', 500 );
		}
		wp_send_json_success(
			[
				'family' => $family,
				'css'    => Fonts::face_css( $family ),
			]
		);
	}

	/**
	 * Bulk-assign a set across books: for every book, add or remove the set to
	 * match the submitted checkbox state. Books not listed (e.g. pages without
	 * chapters) are left untouched.
	 */
	public static function ajax_bulk_assign(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		$set = sanitize_key( wp_unslash( $_POST['set'] ?? '' ) );
		if ( '' === $set || ! Style_Sets::get_set( $set ) ) {
			wp_send_json_error( 'no-set', 400 );
		}

		$checked = isset( $_POST['books'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['books'] ) ) : [];
		$checked = array_flip( $checked );

		foreach ( Books::all_book_ids() as $bid ) {
			$bid = (int) $bid;
			if ( ! current_user_can( 'edit_post', $bid ) ) {
				continue;
			}
			$sets = array_values( array_filter( (array) get_post_meta( $bid, Style_Sets::BOOK_META, true ) ) );
			$has  = in_array( $set, $sets, true );
			$want = isset( $checked[ $bid ] );

			if ( $want && ! $has ) {
				$sets[] = $set;
			} elseif ( ! $want && $has ) {
				$sets = array_values( array_diff( $sets, [ $set ] ) );
			} else {
				continue;
			}

			if ( $sets ) {
				update_post_meta( $bid, Style_Sets::BOOK_META, $sets );
			} else {
				delete_post_meta( $bid, Style_Sets::BOOK_META );
			}
		}

		wp_send_json_success( [ 'count' => count( Style_Sets::books_using( $set ) ) ] );
	}

	/** A URL back to this screen, with optional extra query args. */
	public static function url( array $args = [] ): string {
		return add_query_arg(
			array_merge( [ 'page' => self::PAGE ], $args ),
			admin_url( 'admin.php' )
		);
	}

	// --- Write (POST handler) -------------------------------------------------

	public static function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage style sets.', 'sheaf' ) );
		}
		check_admin_referer( self::NONCE );

		$op    = sanitize_key( wp_unslash( $_POST['op'] ?? '' ) );
		$set   = sanitize_key( wp_unslash( $_POST['set'] ?? '' ) );
		$msg   = '';
		$focus = $set;

		switch ( $op ) {
			case 'save_set':
				$focus = Style_Sets::save_set( (string) wp_unslash( $_POST['label'] ?? '' ), $set );
				$msg   = '' === $set ? 'set-created' : 'set-saved';
				break;

			case 'delete_set':
				Style_Sets::delete_set( $set );
				$focus = '';
				$msg   = 'set-deleted';
				break;

			case 'save_style':
				$style = sanitize_key( wp_unslash( $_POST['style'] ?? '' ) );
				Style_Sets::save_style(
					$set,
					[
						'label' => (string) wp_unslash( $_POST['label'] ?? '' ),
						'kind'  => sanitize_key( wp_unslash( $_POST['kind'] ?? 'inline' ) ),
						'props' => (array) wp_unslash( $_POST['props'] ?? [] ),
						'css'   => (string) wp_unslash( $_POST['css'] ?? '' ),
					],
					$style
				);
				$msg = '' === $style ? 'style-created' : 'style-saved';
				break;

			case 'delete_style':
				Style_Sets::delete_style( $set, sanitize_key( wp_unslash( $_POST['style'] ?? '' ) ) );
				$msg = 'style-deleted';
				break;
		}

		$args = [ 'sheaf_msg' => $msg ];
		if ( '' !== $focus ) {
			$args['set'] = $focus;
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	// --- Render ---------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$all = Style_Sets::all();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$selected   = sanitize_key( wp_unslash( $_GET['set'] ?? '' ) );
		$edit_style = sanitize_key( wp_unslash( $_GET['edit_style'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $all[ $selected ] ) ) {
			$selected = '';
		}

		echo '<div class="wrap sheaf-style-sets">';
		echo '<h1>' . esc_html__( 'Style Sets', 'sheaf' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Named font/formatting styles authors can apply to chapters. Activate a set on a Book to offer its styles when editing that book\'s chapters. Editing a style updates it everywhere it is used.', 'sheaf' ) . '</p>';

		self::notice();
		self::styles();
		self::render_font_datalist();

		self::render_list( $all, $selected );

		if ( '' !== $selected ) {
			self::render_set_detail( $selected, (array) $all[ $selected ], $edit_style );
		}

		echo '</div>';
	}

	/**
	 * The summary list of every set: style counts and which books use it. The
	 * name links to the set's detail block below.
	 *
	 * @param array<string,array> $all
	 */
	private static function render_list( array $all, string $selected ): void {
		echo '<h2>' . esc_html__( 'Style sets', 'sheaf' ) . '</h2>';

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'sheaf' ) . '</th>';
		echo '<th style="width:8em">' . esc_html__( 'Inline styles', 'sheaf' ) . '</th>';
		echo '<th style="width:8em">' . esc_html__( 'Block styles', 'sheaf' ) . '</th>';
		echo '<th>' . esc_html__( 'Available in', 'sheaf' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $all as $slug => $set ) {
			$counts = self::kind_counts( (array) ( $set['styles'] ?? [] ) );
			$label  = '' !== (string) ( $set['label'] ?? '' ) ? (string) $set['label'] : (string) $slug;
			$row    = ( $slug === $selected ) ? ' class="sheaf-set-current"' : '';

			echo '<tr' . $row . '>'; // Class is a fixed literal.
			self::name_cell( (string) $slug, $label );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $counts['inline'] ) ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $counts['block'] ) ) );
			echo '<td>' . self::available_in( (string) $slug ) . '</td>'; // Links built/escaped within.
			echo '</tr>';
		}

		// Final row: create a new set (the only row when none exist yet).
		echo '<tr class="sheaf-add-row"><td colspan="4">';
		self::open_form();
		echo '<input type="hidden" name="op" value="save_set">';
		echo '<input type="text" name="label" required class="regular-text" placeholder="' . esc_attr__( 'e.g. Strange Voices', 'sheaf' ) . '"> ';
		submit_button( __( 'Create new set', 'sheaf' ), 'secondary', '', false );
		echo '</form>';
		echo '</td></tr>';

		echo '</tbody></table>';

		// One bulk-assign modal per set (opened from the "Available in" cell).
		foreach ( $all as $slug => $set ) {
			$label = '' !== (string) ( $set['label'] ?? '' ) ? (string) $set['label'] : (string) $slug;
			self::render_bulk_dialog( (string) $slug, $label );
		}
	}

	/**
	 * The name cell for the list: the set name (linking to its detail), plus
	 * Rename / Delete row actions. "Rename" reveals an inline form below the name.
	 */
	private static function name_cell( string $slug, string $label ): void {
		$rename_id = 'sheaf-rename-' . $slug;

		echo '<td>';
		printf(
			'<strong><a href="%1$s">%2$s</a></strong> <code>%3$s</code>',
			esc_url( self::url( [ 'set' => $slug ] ) . '#sheaf-set-detail' ),
			esc_html( $label ),
			esc_html( $slug )
		);

		echo '<div class="row-actions">';
		printf(
			'<span class="rename"><button type="button" class="button-link sheaf-rename-toggle" aria-expanded="false" data-target="%1$s">%2$s</button> | </span>',
			esc_attr( $rename_id ),
			esc_html__( 'Rename', 'sheaf' )
		);
		echo '<span class="delete">';
		self::open_form( 'display:inline', 'return confirm(\'' . esc_js( __( 'Delete the whole style set? Related styles will become unformatted. This cannot be undone.', 'sheaf' ) ) . '\')' );
		echo '<input type="hidden" name="op" value="delete_set">';
		echo '<input type="hidden" name="set" value="' . esc_attr( $slug ) . '">';
		echo '<button type="submit" class="button-link sheaf-link-danger">' . esc_html__( 'Delete', 'sheaf' ) . '</button>';
		echo '</form>';
		echo '</span>';
		echo '</div>';

		// Inline rename form, hidden until "Rename" is clicked.
		printf( '<div class="sheaf-rename" id="%s" hidden>', esc_attr( $rename_id ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="op" value="save_set">';
		echo '<input type="hidden" name="set" value="' . esc_attr( $slug ) . '">';
		echo '<input type="text" name="label" value="' . esc_attr( $label ) . '" class="regular-text"> ';
		submit_button( __( 'Save name', 'sheaf' ), 'secondary', '', false );
		echo '<p class="description sheaf-rename-note">' . esc_html__( 'Renaming updates the name shown to authors everywhere this set is used. Existing posts keep their formatting.', 'sheaf' ) . '</p>';
		echo '</form></div>';

		echo '</td>';
	}

	/**
	 * The "Available in" cell: the books using a set. Beyond four books, show two
	 * titles plus "+ N more".
	 */
	private static function available_in( string $slug ): string {
		$books = Style_Sets::books_using( $slug );
		$total = count( $books );

		if ( $total ) {
			// Plain text, semicolon-separated (titles can contain commas).
			$shown  = $total > 4 ? array_slice( $books, 0, 2 ) : $books;
			$titles = array_map(
				static function ( $id ) {
					return esc_html( get_the_title( $id ) );
				},
				$shown
			);
			$out = implode( '; ', $titles );
			if ( $total > 4 ) {
				$out .= ' ' . esc_html(
					sprintf(
						/* translators: %s: number of additional books. */
						_n( '+ %s more', '+ %s more', $total - 2, 'sheaf' ),
						number_format_i18n( $total - 2 )
					)
				);
			}
		} else {
			$out = '<span aria-hidden="true">—</span>';
		}

		$out .= '<div class="sheaf-bulk-row"><button type="button" class="button button-small sheaf-bulk-open" data-set="' . esc_attr( $slug ) . '">' . esc_html__( 'Bulk assign', 'sheaf' ) . '</button></div>';
		return $out;
	}

	/**
	 * A modal listing every book with a checkbox (checked where this set is
	 * active), plus a check/uncheck-all toggle. Saved over AJAX (ajax_bulk_assign).
	 */
	private static function render_bulk_dialog( string $slug, string $label ): void {
		$all_books = Books::all_book_ids();
		$active    = Style_Sets::books_using( $slug );

		printf( '<dialog class="sheaf-bulk-dialog" id="%s">', esc_attr( 'sheaf-bulk-' . $slug ) );
		printf(
			'<h2>%s</h2>',
			esc_html(
				sprintf(
					/* translators: %s: style set name. */
					__( 'Books using “%s”', 'sheaf' ),
					$label
				)
			)
		);

		if ( ! $all_books ) {
			echo '<p>' . esc_html__( 'No books yet — add a chapter to a Page to make it a book.', 'sheaf' ) . '</p>';
		} else {
			echo '<p><label><input type="checkbox" class="sheaf-bulk-all"> <strong>' . esc_html__( 'Check / uncheck all', 'sheaf' ) . '</strong></label></p>';
			echo '<ul class="sheaf-bulk-list">';
			foreach ( $all_books as $bid ) {
				printf(
					'<li><label><input type="checkbox" class="sheaf-bulk-book" value="%1$d"%2$s> %3$s</label></li>',
					(int) $bid,
					checked( in_array( (int) $bid, $active, true ), true, false ),
					esc_html( get_the_title( $bid ) )
				);
			}
			echo '</ul>';
		}

		echo '<p class="sheaf-bulk-actions">';
		printf( '<button type="button" class="button button-primary sheaf-bulk-save" data-set="%s">%s</button> ', esc_attr( $slug ), esc_html__( 'Save', 'sheaf' ) );
		printf( '<button type="button" class="button sheaf-bulk-cancel">%s</button>', esc_html__( 'Cancel', 'sheaf' ) );
		echo '</p>';
		echo '</dialog>';
	}

	/** A link to a book's Sheaf management screen (not the Page editor). */
	private static function book_link( int $id ): string {
		$url = add_query_arg(
			[
				'post_type' => Chapters::POST_TYPE,
				'page'      => Books_Admin::MENU_SLUG,
				'book'      => $id,
			],
			admin_url( 'edit.php' )
		);
		return '<a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
	}

	/**
	 * The detail block for one set: its books, its styles (inline and block in
	 * separate tables, each with a live-ish preview), and the management forms.
	 *
	 * @param array<string,mixed> $set
	 */
	private static function render_set_detail( string $slug, array $set, string $edit_style ): void {
		$styles = (array) ( $set['styles'] ?? [] );
		$books  = Style_Sets::books_using( $slug );

		echo '<hr><div class="sheaf-set" id="sheaf-set-detail">';
		echo '<h2>' . esc_html( '' !== (string) ( $set['label'] ?? '' ) ? (string) $set['label'] : $slug ) . ' <code>' . esc_html( $slug ) . '</code></h2>';

		// "Used by N books", linked to each book's Sheaf screen.
		if ( $books ) {
			$links = array_map( [ self::class, 'book_link' ], $books );
			echo '<p class="description">' . sprintf(
				/* translators: %s: number of books. */
				esc_html( _n( 'Used by %s book:', 'Used by %s books:', count( $books ), 'sheaf' ) ),
				'<strong>' . count( $books ) . '</strong>'
			) . ' ' . wp_kses_post( implode( ', ', $links ) ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Not active on any book yet.', 'sheaf' ) . '</p>';
		}

		self::render_styles_table( $slug, $styles, 'inline', $edit_style );
		self::render_styles_table( $slug, $styles, 'block', $edit_style );

		// Add-a-style form (unless we're editing one in this set). Rename/delete
		// live in the list's row actions now.
		if ( '' === $edit_style ) {
			echo '<hr>';
			echo '<h3>' . esc_html__( 'Add a style', 'sheaf' ) . '</h3>';
			self::render_style_form( $slug );
		}

		echo '</div>';
	}

	/**
	 * One table of styles of a single kind. Inline and block live in separate
	 * tables because their previews are shaped differently.
	 *
	 * @param array<string,array> $styles All styles in the set.
	 */
	private static function render_styles_table( string $set, array $styles, string $kind, string $edit_style ): void {
		$of_kind = array_filter(
			$styles,
			static function ( $style ) use ( $kind ) {
				$k = ( $style['kind'] ?? 'inline' );
				return ( 'block' === $kind ) ? ( 'block' === $k ) : ( 'block' !== $k );
			}
		);

		$heading = 'block' === $kind ? __( 'Block styles', 'sheaf' ) : __( 'Inline styles', 'sheaf' );
		echo '<h3>' . esc_html( $heading ) . '</h3>';

		if ( ! $of_kind ) {
			echo '<p class="description">' . esc_html(
				'block' === $kind
					? __( 'No block (whole-paragraph) styles yet.', 'sheaf' )
					: __( 'No inline (phrase) styles yet.', 'sheaf' )
			) . '</p>';
			return;
		}

		echo '<table class="widefat striped sheaf-style-table"><thead><tr>';
		echo '<th style="width:14em">' . esc_html__( 'Style', 'sheaf' ) . '</th>';
		echo '<th>' . esc_html__( 'Preview', 'sheaf' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';
		foreach ( $of_kind as $style_slug => $style ) {
			self::render_style_row( $set, (string) $style_slug, (array) $style, $edit_style === (string) $style_slug );
		}
		echo '</tbody></table>';
	}

	private static function render_style_row( string $set, string $style_slug, array $style, bool $editing ): void {
		if ( $editing ) {
			echo '<tr><td colspan="3">';
			self::render_style_form( $set, $style_slug, $style );
			echo '</td></tr>';
			return;
		}

		$class = Style_Sets::style_class( $set, $style_slug );
		$kind  = ( $style['kind'] ?? 'inline' );

		echo '<tr>';
		echo '<td><strong>' . esc_html( '' !== (string) ( $style['label'] ?? '' ) ? (string) $style['label'] : $style_slug ) . '</strong><br><code>' . esc_html( $class ) . '</code></td>';
		echo '<td>' . self::preview( $style ) . '</td>'; // Inline style is sanitized in Style_Sets.
		echo '<td style="white-space:nowrap">';
		echo '<a class="button button-small" href="' . esc_url( self::url( [ 'set' => $set, 'edit_style' => $style_slug ] ) . '#sheaf-set-detail' ) . '">' . esc_html__( 'Edit', 'sheaf' ) . '</a> ';
		self::open_form( 'display:inline', 'return confirm(\'' . esc_js( __( 'Delete this style?', 'sheaf' ) ) . '\')' );
		echo '<input type="hidden" name="op" value="delete_style">';
		echo '<input type="hidden" name="set" value="' . esc_attr( $set ) . '">';
		echo '<input type="hidden" name="style" value="' . esc_attr( $style_slug ) . '">';
		submit_button( __( 'Delete', 'sheaf' ), 'delete small', '', false );
		echo '</form>';
		echo '</td></tr>';
	}

	/**
	 * A preview of a style. Inline styles render as a <span> in a line of text;
	 * block styles render as a <p> framed by two blank representational
	 * paragraphs, so margins and alignment are visible.
	 *
	 * @param array<string,mixed> $style
	 */
	private static function preview( array $style ): string {
		$decl = Style_Sets::declarations( $style );
		if ( 'block' === ( $style['kind'] ?? 'inline' ) ) {
			return '<div class="sheaf-prev">'
				. '<p class="sheaf-prev-rep"></p>'
				. '<p class="sheaf-prev-actual" style="' . esc_attr( $decl ) . '">' . esc_html( self::filler( true ) ) . '</p>'
				. '<p class="sheaf-prev-rep"></p>'
				. '</div>';
		}
		return '<p class="sheaf-prev"><span style="' . esc_attr( $decl ) . '">' . esc_html( self::filler( false ) ) . '</span></p>';
	}

	/**
	 * Random preview text from the filler pool, regenerated each load: a sentence
	 * (~10–15 words) for inline styles, a paragraph (~30–50 words) for block.
	 */
	private static function filler( bool $block ): string {
		$pool = self::FILLER;
		if ( ! $block ) {
			return $pool[ array_rand( $pool ) ];
		}
		shuffle( $pool );
		$out   = [];
		$words = 0;
		foreach ( $pool as $sentence ) {
			$out[]  = $sentence;
			$words += str_word_count( $sentence );
			if ( $words >= 30 ) {
				break;
			}
		}
		return implode( ' ', $out );
	}

	/**
	 * The add/edit form for a style. $style_slug empty = add.
	 *
	 * @param array<string,mixed> $style
	 */
	private static function render_style_form( string $set, string $style_slug = '', array $style = [] ): void {
		$kind = $style['kind'] ?? 'inline';
		// Only properties that actually carry a value (progressive disclosure).
		$props = array_filter(
			(array) ( $style['props'] ?? [] ),
			static function ( $v ) {
				return '' !== (string) $v;
			}
		);
		$label    = (string) ( $style['label'] ?? '' );
		$sel_slug = '' !== $style_slug ? $style_slug : '…';

		self::open_form();
		echo '<input type="hidden" name="op" value="save_style">';
		echo '<input type="hidden" name="set" value="' . esc_attr( $set ) . '">';
		echo '<input type="hidden" name="style" value="' . esc_attr( $style_slug ) . '">';

		echo '<div class="sheaf-style-form" data-set="' . esc_attr( $set ) . '" data-style="' . esc_attr( $style_slug ) . '">';

		// Name + kind.
		echo '<p class="sheaf-style-meta">';
		echo '<label>' . esc_html__( 'Name', 'sheaf' ) . ' <input type="text" name="label" required value="' . esc_attr( $label ) . '" placeholder="' . esc_attr__( 'e.g. Computer Voice', 'sheaf' ) . '"></label> ';
		echo '<label>' . esc_html__( 'Applies to', 'sheaf' ) . ' <select name="kind">';
		echo '<option value="inline"' . selected( $kind, 'inline', false ) . '>' . esc_html__( 'Inline phrase', 'sheaf' ) . '</option>';
		echo '<option value="block"' . selected( $kind, 'block', false ) . '>' . esc_html__( 'Whole paragraph', 'sheaf' ) . '</option>';
		echo '</select></label>';
		echo '</p>';

		// Editor (CSS-rule block) on the left, live preview on the right.
		echo '<div class="sheaf-style-layout">';

		echo '<div class="sheaf-css-block">';
		echo '<div class="sheaf-css-selector"><span class="sheaf-selector-text">' . esc_html( self::selector_preview( $set, $sel_slug, (string) $kind ) ) . '</span> {</div>';
		echo '<div class="sheaf-css-props">';
		foreach ( $props as $prop => $val ) {
			self::prop_row( (string) $prop, (string) $val );
		}
		echo '</div>';

		// "Add property" lists the remaining whitelisted properties.
		$remaining = array_values( array_diff( Style_Sets::ALLOWED_PROPS, array_keys( $props ) ) );
		echo '<div class="sheaf-css-add"><select class="sheaf-add-prop" aria-label="' . esc_attr__( 'Add a property', 'sheaf' ) . '">';
		echo '<option value="">＋ ' . esc_html__( 'Add property…', 'sheaf' ) . '</option>';
		foreach ( $remaining as $prop ) {
			echo '<option value="' . esc_attr( $prop ) . '">' . esc_html( $prop ) . '</option>';
		}
		echo '</select> <a class="sheaf-browse-fonts" href="https://fonts.google.com/" target="_blank" rel="noopener">' . esc_html__( 'Browse Google Fonts ↗', 'sheaf' ) . '</a></div>';

		// Raw-CSS catch-all for anything outside the whitelist.
		echo '<textarea name="css" class="sheaf-css-raw" rows="2" placeholder="' . esc_attr__( 'other declarations; e.g. text-shadow: 0 0 2px #0f0;', 'sheaf' ) . '">' . esc_textarea( (string) ( $style['css'] ?? '' ) ) . '</textarea>';
		echo '<div class="sheaf-css-close">}</div>';
		echo '</div>'; // .sheaf-css-block

		echo '<div class="sheaf-style-preview"><p class="description">' . esc_html__( 'Live preview', 'sheaf' ) . '</p>'
			. '<div class="sheaf-live-target" data-inline="' . esc_attr( self::filler( false ) ) . '" data-block="' . esc_attr( self::filler( true ) ) . '"></div></div>';

		echo '</div>'; // .sheaf-style-layout
		echo '</div>'; // .sheaf-style-form

		submit_button( '' === $style_slug ? __( 'Add style', 'sheaf' ) : __( 'Save style', 'sheaf' ), 'primary', '', false );
		if ( '' !== $style_slug ) {
			echo ' <a class="button-link" href="' . esc_url( self::url( [ 'set' => $set ] ) . '#sheaf-set-detail' ) . '">' . esc_html__( 'Cancel', 'sheaf' ) . '</a>';
		}
		echo '</form>';
	}

	/** One property row in the CSS-block editor: "name: [value] ×". */
	private static function prop_row( string $prop, string $val ): void {
		// The font-family field suggests installed Font Library families and gets a
		// recognition/embed status slot (filled by the JS).
		$is_font = 'font-family' === $prop;
		$list    = $is_font ? ' list="sheaf-font-list"' : '';
		echo '<div class="sheaf-prop-row">';
		echo '<span class="sheaf-prop-name">' . esc_html( $prop ) . '</span>: ';
		echo '<input type="text" class="sheaf-prop-value"' . $list . ' name="props[' . esc_attr( $prop ) . ']" value="' . esc_attr( $val ) . '">';
		echo '<button type="button" class="sheaf-prop-remove" aria-label="' . esc_attr__( 'Remove this property', 'sheaf' ) . '">&times;</button>';
		if ( $is_font ) {
			echo '<span class="sheaf-font-status"></span>';
		}
		echo '</div>';
	}

	/** Datalist of installed font families, referenced by the font-family field. */
	private static function render_font_datalist(): void {
		echo '<datalist id="sheaf-font-list">';
		foreach ( Fonts::installed_names() as $name ) {
			echo '<option value="' . esc_attr( $name ) . '"></option>';
		}
		echo '</datalist>';
	}

	/** The CSS selector a style's class produces, for the editor's block header. */
	private static function selector_preview( string $set, string $style_slug, string $kind ): string {
		return 'block' === $kind
			? '.is-style-sheaf-' . $set . '-' . $style_slug
			: '.sheaf-style-' . $set . '-' . $style_slug;
	}

	private static function open_form( string $style = '', string $onsubmit = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"';
		if ( '' !== $style ) {
			echo ' style="' . esc_attr( $style ) . '"';
		}
		if ( '' !== $onsubmit ) {
			echo ' onsubmit="' . esc_attr( $onsubmit ) . '"';
		}
		echo '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::NONCE );
	}

	/** Count a set's styles by kind. */
	private static function kind_counts( array $styles ): array {
		$counts = [
			'inline' => 0,
			'block'  => 0,
		];
		foreach ( $styles as $style ) {
			if ( 'block' === ( $style['kind'] ?? 'inline' ) ) {
				++$counts['block'];
			} else {
				++$counts['inline'];
			}
		}
		return $counts;
	}

	private static function notice(): void {
		$msg = sanitize_key( wp_unslash( $_GET['sheaf_msg'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
		$map = [
			'set-created'   => __( 'Style set created.', 'sheaf' ),
			'set-saved'     => __( 'Style set saved.', 'sheaf' ),
			'set-deleted'   => __( 'Style set deleted.', 'sheaf' ),
			'style-created' => __( 'Style added.', 'sheaf' ),
			'style-saved'   => __( 'Style saved.', 'sheaf' ),
			'style-deleted' => __( 'Style deleted.', 'sheaf' ),
		];
		if ( isset( $map[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $msg ] ) . '</p></div>';
		}
	}

	private static function styles(): void {
		echo '<style>
			.sheaf-style-meta label{margin-right:1.6em}
			.sheaf-style-layout{display:flex;gap:1.5em;align-items:flex-start;flex-wrap:wrap;margin:.6em 0 1em}
			.sheaf-css-block{flex:1 1 28em;min-width:24em;font-family:Menlo,Consolas,monospace;font-size:13px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:.6em .9em}
			.sheaf-css-selector,.sheaf-css-close{color:#2271b1}
			.sheaf-css-props{margin:.2em 0}
			.sheaf-prop-row{display:flex;align-items:center;gap:.35em;margin:.15em 0 .15em 1.6em}
			.sheaf-prop-name{color:#646970;white-space:nowrap}
			.sheaf-prop-value{flex:1;font-family:inherit}
			.sheaf-prop-remove{border:0;background:none;color:#b32d2e;cursor:pointer;font-size:16px;line-height:1;padding:0 .25em}
			.sheaf-font-status{margin-left:.5em;font-size:12px;color:#646970}
			.sheaf-font-status.is-installed{color:#1a7f37}
			.sheaf-browse-fonts{font-size:12px;margin-left:.5em}
			.sheaf-css-add{margin:.25em 0 .25em 1.6em}
			.sheaf-css-raw{display:block;width:calc(100% - 1.6em);margin:.35em 0 .35em 1.6em;font-family:inherit}
			.sheaf-style-preview{flex:1 1 18em;min-width:16em;position:sticky;top:2em;padding:.6em .8em;border:1px solid #dcdcde;border-radius:4px;background:#fff}
			.sheaf-style-preview>.description{margin:0 0 .4em}
			.sheaf-rename{margin:.4em 0}
			.sheaf-rename-note{margin:.4em 0 0}
			.sheaf-link-danger{color:#b32d2e}
			.sheaf-set-current td:first-of-type{box-shadow:inset 4px 0 0 #2271b1}
			.sheaf-bulk-dialog{max-width:32em;border:1px solid #c3c4c7;border-radius:4px;padding:1em 1.4em}
			.sheaf-bulk-dialog h2{margin-top:0}
			.sheaf-bulk-dialog::backdrop{background:rgba(0,0,0,.35)}
			.sheaf-bulk-list{max-height:50vh;overflow:auto;margin:.4em 0;border:1px solid #dcdcde;border-radius:3px;padding:.4em .8em}
			.sheaf-bulk-list li{margin:.2em 0}
			.sheaf-bulk-actions{margin:1em 0 0}
			.sheaf-bulk-row{margin-top:.5em}
			.sheaf-style-table{max-width:60em;margin-bottom:1.5em}
			.sheaf-prev{max-width:40em;margin:0}
			.sheaf-prev-actual{margin:0}
			.sheaf-prev-rep{margin:0;height:1.1em;border-radius:2px;background:repeating-linear-gradient(45deg,#f6f7f7,#f6f7f7 6px,#eceef0 6px,#eceef0 12px)}
		</style>';
	}
}
