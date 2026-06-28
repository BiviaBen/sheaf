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

	/** Sample text for previews — enough words to show the font (and wrapping). */
	private const SAMPLE_INLINE = 'The quick brown fox jumps over';
	private const SAMPLE_BLOCK  = 'The quick brown fox jumps over the lazy dog, then pauses to watch the curious cat slip past.';

	/** Hook suffix of our screen, for asset scoping. */
	private static string $hook = '';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
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

		self::render_list( $all, $selected );
		self::render_add_set_form();

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

		if ( ! $all ) {
			echo '<p>' . esc_html__( 'No style sets yet — create one below.', 'sheaf' ) . '</p>';
			return;
		}

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
			printf(
				'<td><strong><a href="%1$s">%2$s</a></strong> <code>%3$s</code></td>',
				esc_url( self::url( [ 'set' => $slug ] ) . '#sheaf-set-detail' ),
				esc_html( $label ),
				esc_html( (string) $slug )
			);
			printf( '<td>%s</td>', esc_html( number_format_i18n( $counts['inline'] ) ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $counts['block'] ) ) );
			echo '<td>' . self::available_in( (string) $slug ) . '</td>'; // Links built/escaped within.
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The "Available in" cell: the books using a set. Beyond four books, show two
	 * titles plus "+ N more".
	 */
	private static function available_in( string $slug ): string {
		$books = Style_Sets::books_using( $slug );
		if ( ! $books ) {
			return '<span aria-hidden="true">—</span>';
		}

		$total = count( $books );
		$shown = $total > 4 ? array_slice( $books, 0, 2 ) : $books;
		$links = array_map( [ self::class, 'book_link' ], $shown );
		$out   = implode( ', ', $links );

		if ( $total > 4 ) {
			$out .= ' ' . esc_html(
				sprintf(
					/* translators: %s: number of additional books. */
					_n( '+ %s more', '+ %s more', $total - 2, 'sheaf' ),
					number_format_i18n( $total - 2 )
				)
			);
		}
		return $out;
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

	private static function render_add_set_form(): void {
		echo '<h2>' . esc_html__( 'Add a new style set', 'sheaf' ) . '</h2>';
		self::open_form();
		echo '<input type="hidden" name="op" value="save_set">';
		echo '<input type="text" name="label" required class="regular-text" placeholder="' . esc_attr__( 'e.g. Strange Voices', 'sheaf' ) . '"> ';
		submit_button( __( 'Create set', 'sheaf' ), 'secondary', '', false );
		echo '</form>';
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

		// Rename / delete set.
		echo '<p class="sheaf-set-actions">';
		self::open_form( 'display:inline' );
		echo '<input type="hidden" name="op" value="save_set">';
		echo '<input type="hidden" name="set" value="' . esc_attr( $slug ) . '">';
		echo '<input type="text" name="label" value="' . esc_attr( (string) ( $set['label'] ?? '' ) ) . '" class="regular-text"> ';
		submit_button( __( 'Rename', 'sheaf' ), 'secondary', '', false );
		echo '</form> ';
		self::open_form( 'display:inline', 'return confirm(\'' . esc_js( __( 'Delete this whole style set?', 'sheaf' ) ) . '\')' );
		echo '<input type="hidden" name="op" value="delete_set">';
		echo '<input type="hidden" name="set" value="' . esc_attr( $slug ) . '">';
		submit_button( __( 'Delete set', 'sheaf' ), 'delete', '', false );
		echo '</form></p>';

		// Add-a-style form (unless we're editing one in this set).
		if ( '' === $edit_style ) {
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
				. '<p class="sheaf-prev-actual" style="' . esc_attr( $decl ) . '">' . esc_html( self::SAMPLE_BLOCK ) . '</p>'
				. '<p class="sheaf-prev-rep"></p>'
				. '</div>';
		}
		return '<p class="sheaf-prev"><span style="' . esc_attr( $decl ) . '">' . esc_html( self::SAMPLE_INLINE ) . '</span></p>';
	}

	/**
	 * The add/edit form for a style. $style_slug empty = add.
	 *
	 * @param array<string,mixed> $style
	 */
	private static function render_style_form( string $set, string $style_slug = '', array $style = [] ): void {
		$kind  = $style['kind'] ?? 'inline';
		$props = (array) ( $style['props'] ?? [] );

		self::open_form();
		echo '<input type="hidden" name="op" value="save_style">';
		echo '<input type="hidden" name="set" value="' . esc_attr( $set ) . '">';
		echo '<input type="hidden" name="style" value="' . esc_attr( $style_slug ) . '">';

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th><label>' . esc_html__( 'Name', 'sheaf' ) . '</label></th><td><input type="text" name="label" required class="regular-text" value="' . esc_attr( (string) ( $style['label'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'e.g. Computer Voice', 'sheaf' ) . '"></td></tr>';

		echo '<tr><th><label>' . esc_html__( 'Applies to', 'sheaf' ) . '</label></th><td><select name="kind">';
		echo '<option value="inline"' . selected( $kind, 'inline', false ) . '>' . esc_html__( 'Inline phrase (a span within a paragraph)', 'sheaf' ) . '</option>';
		echo '<option value="block"' . selected( $kind, 'block', false ) . '>' . esc_html__( 'Whole paragraph (a block)', 'sheaf' ) . '</option>';
		echo '</select></td></tr>';

		// Property grid, driven by the whitelist so it stays in sync.
		echo '<tr><th>' . esc_html__( 'Properties', 'sheaf' ) . '</th><td><div class="sheaf-prop-grid">';
		foreach ( Style_Sets::ALLOWED_PROPS as $prop ) {
			$val = isset( $props[ $prop ] ) ? (string) $props[ $prop ] : '';
			echo '<label class="sheaf-prop"><span>' . esc_html( $prop ) . '</span>';
			echo '<input type="text" name="props[' . esc_attr( $prop ) . ']" value="' . esc_attr( $val ) . '"></label>';
		}
		echo '</div></td></tr>';

		echo '<tr><th><label>' . esc_html__( 'Raw CSS', 'sheaf' ) . '</label></th><td><textarea name="css" rows="2" class="large-text code" placeholder="' . esc_attr__( 'extra declarations, e.g. text-shadow: 0 0 2px #0f0;', 'sheaf' ) . '">' . esc_textarea( (string) ( $style['css'] ?? '' ) ) . '</textarea><p class="description">' . esc_html__( 'For properties not in the grid. Declarations only — no selectors or braces.', 'sheaf' ) . '</p></td></tr>';

		echo '</tbody></table>';

		// Live preview target — filled and updated by admin-style-preview.js.
		echo '<div class="sheaf-live-preview"><p class="description">' . esc_html__( 'Live preview', 'sheaf' ) . '</p><div class="sheaf-live-target"></div></div>';

		submit_button( '' === $style_slug ? __( 'Add style', 'sheaf' ) : __( 'Save style', 'sheaf' ), 'primary', '', false );
		if ( '' !== $style_slug ) {
			echo ' <a class="button-link" href="' . esc_url( self::url( [ 'set' => $set ] ) . '#sheaf-set-detail' ) . '">' . esc_html__( 'Cancel', 'sheaf' ) . '</a>';
		}
		echo '</form>';
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
			.sheaf-prop-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(14em,1fr));gap:.5em 1em}
			.sheaf-prop{display:flex;flex-direction:column;font-size:12px}
			.sheaf-prop input{width:100%}
			.sheaf-set-actions{margin:1em 0}
			.sheaf-set-current td{box-shadow:inset 4px 0 0 #2271b1}
			.sheaf-style-table{max-width:60em;margin-bottom:1.5em}
			.sheaf-prev{max-width:40em;margin:0}
			.sheaf-prev-actual{margin:0}
			.sheaf-prev-rep{margin:0;height:1.1em;border-radius:2px;background:repeating-linear-gradient(45deg,#f6f7f7,#f6f7f7 6px,#eceef0 6px,#eceef0 12px)}
			.sheaf-live-preview{margin:0 0 1em;padding:.6em .8em;border:1px solid #dcdcde;border-radius:4px;background:#fff;max-width:42em}
			.sheaf-live-preview>.description{margin:0 0 .4em}
		</style>';
	}
}
