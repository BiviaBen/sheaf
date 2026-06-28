<?php
/**
 * The "Style Sets" admin screen — a CRUD UI over the Style_Sets library.
 *
 * Lives as a submenu under the Sheafs menu. Authors create named sets, add
 * styles to them (inline or block, via a property form plus a raw-CSS escape
 * hatch), and see which books currently use each set. Forms POST to
 * admin-post.php and redirect back (post/redirect/get), so there is no JS.
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

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	public static function add_page(): void {
		add_submenu_page(
			Books_Admin::MENU_SLUG,
			__( 'Style Sets', 'sheaf' ),
			__( 'Style Sets', 'sheaf' ),
			self::CAPABILITY,
			self::PAGE,
			[ self::class, 'render' ]
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

		$op  = sanitize_key( wp_unslash( $_POST['op'] ?? '' ) );
		$set = sanitize_key( wp_unslash( $_POST['set'] ?? '' ) );
		$msg = '';
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

		echo '<div class="wrap sheaf-style-sets">';
		echo '<h1>' . esc_html__( 'Style Sets', 'sheaf' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Named font/formatting styles authors can apply to chapters. Activate a set on a Book to offer its styles when editing that book\'s chapters. Editing a style updates it everywhere it is used.', 'sheaf' ) . '</p>';

		self::notice();
		self::styles();

		$editing = sanitize_key( wp_unslash( $_GET['edit_set'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$edit_style = sanitize_key( wp_unslash( $_GET['edit_style'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// New-set form.
		echo '<h2>' . esc_html__( 'Add a style set', 'sheaf' ) . '</h2>';
		self::open_form();
		echo '<input type="hidden" name="op" value="save_set">';
		echo '<input type="text" name="label" required class="regular-text" placeholder="' . esc_attr__( 'e.g. Talking Monsters', 'sheaf' ) . '"> ';
		submit_button( __( 'Create set', 'sheaf' ), 'secondary', '', false );
		echo '</form>';

		foreach ( Style_Sets::all() as $slug => $set ) {
			self::render_set( (string) $slug, (array) $set, $editing, $edit_style );
		}

		echo '</div>';
	}

	private static function render_set( string $slug, array $set, string $editing, string $edit_style ): void {
		$styles = (array) ( $set['styles'] ?? [] );
		$books  = Style_Sets::books_using( $slug );

		echo '<hr><div class="sheaf-set">';
		echo '<h2>' . esc_html( $set['label'] ?? $slug ) . ' <code>' . esc_html( $slug ) . '</code></h2>';

		// "Used by N books" with links.
		if ( $books ) {
			$links = array_filter(
				array_map(
					static function ( $id ) {
						$edit = get_edit_post_link( $id );
						return $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( get_the_title( $id ) ) . '</a>' : '';
					},
					$books
				)
			);
			echo '<p class="description">' . sprintf(
				/* translators: %s: comma-separated book links. */
				esc_html( _n( 'Used by %s book:', 'Used by %s books:', count( $books ), 'sheaf' ) ),
				'<strong>' . count( $books ) . '</strong>'
			) . ' ' . wp_kses_post( implode( ', ', $links ) ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Not active on any book yet.', 'sheaf' ) . '</p>';
		}

		// Styles table.
		if ( $styles ) {
			echo '<table class="widefat striped" style="max-width:60em"><thead><tr>';
			echo '<th>' . esc_html__( 'Style', 'sheaf' ) . '</th><th>' . esc_html__( 'Kind', 'sheaf' ) . '</th><th>' . esc_html__( 'Preview', 'sheaf' ) . '</th><th>' . esc_html__( 'CSS class', 'sheaf' ) . '</th><th></th>';
			echo '</tr></thead><tbody>';
			foreach ( $styles as $style_slug => $style ) {
				self::render_style_row( $slug, (string) $style_slug, (array) $style, $editing === $slug && $edit_style === $style_slug );
			}
			echo '</tbody></table>';
		}

		// Rename / delete set.
		echo '<p class="sheaf-set-actions">';
		self::open_form( 'display:inline' );
		echo '<input type="hidden" name="op" value="save_set">';
		echo '<input type="hidden" name="set" value="' . esc_attr( $slug ) . '">';
		echo '<input type="text" name="label" value="' . esc_attr( $set['label'] ?? '' ) . '" class="regular-text"> ';
		submit_button( __( 'Rename', 'sheaf' ), 'secondary', '', false );
		echo '</form> ';
		self::open_form( 'display:inline', 'return confirm(\'' . esc_js( __( 'Delete this whole style set?', 'sheaf' ) ) . '\')' );
		echo '<input type="hidden" name="op" value="delete_set">';
		echo '<input type="hidden" name="set" value="' . esc_attr( $slug ) . '">';
		submit_button( __( 'Delete set', 'sheaf' ), 'delete', '', false );
		echo '</form></p>';

		// Add-a-style form (unless we're editing one in this set).
		if ( ! ( $editing === $slug && $edit_style ) ) {
			echo '<h3>' . esc_html__( 'Add a style', 'sheaf' ) . '</h3>';
			self::render_style_form( $slug );
		}

		echo '</div>';
	}

	private static function render_style_row( string $set, string $style_slug, array $style, bool $editing ): void {
		if ( $editing ) {
			echo '<tr><td colspan="5">';
			self::render_style_form( $set, $style_slug, $style );
			echo '</td></tr>';
			return;
		}

		$class = Style_Sets::style_class( $set, $style_slug );
		$decl  = Style_Sets::declarations( $style );

		echo '<tr>';
		echo '<td><strong>' . esc_html( $style['label'] ?? $style_slug ) . '</strong></td>';
		echo '<td>' . esc_html( $style['kind'] ?? 'inline' ) . '</td>';
		echo '<td><span style="' . esc_attr( $decl ) . '">AaBbCc 0123</span></td>';
		echo '<td><code>' . esc_html( $class ) . '</code></td>';
		echo '<td style="white-space:nowrap">';
		echo '<a class="button button-small" href="' . esc_url( self::url( [ 'edit_set' => $set, 'edit_style' => $style_slug, 'set' => $set ] ) ) . '">' . esc_html__( 'Edit', 'sheaf' ) . '</a> ';
		self::open_form( 'display:inline', 'return confirm(\'' . esc_js( __( 'Delete this style?', 'sheaf' ) ) . '\')' );
		echo '<input type="hidden" name="op" value="delete_style">';
		echo '<input type="hidden" name="set" value="' . esc_attr( $set ) . '">';
		echo '<input type="hidden" name="style" value="' . esc_attr( $style_slug ) . '">';
		submit_button( __( 'Delete', 'sheaf' ), 'delete small', '', false );
		echo '</form>';
		echo '</td></tr>';
	}

	/**
	 * The add/edit form for a style. $style_slug empty = add.
	 */
	private static function render_style_form( string $set, string $style_slug = '', array $style = [] ): void {
		$kind  = $style['kind'] ?? 'inline';
		$props = (array) ( $style['props'] ?? [] );

		self::open_form();
		echo '<input type="hidden" name="op" value="save_style">';
		echo '<input type="hidden" name="set" value="' . esc_attr( $set ) . '">';
		echo '<input type="hidden" name="style" value="' . esc_attr( $style_slug ) . '">';

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th><label>' . esc_html__( 'Name', 'sheaf' ) . '</label></th><td><input type="text" name="label" required class="regular-text" value="' . esc_attr( $style['label'] ?? '' ) . '" placeholder="' . esc_attr__( 'e.g. Computer Voice', 'sheaf' ) . '"></td></tr>';

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

		submit_button( '' === $style_slug ? __( 'Add style', 'sheaf' ) : __( 'Save style', 'sheaf' ), 'primary', '', false );
		if ( '' !== $style_slug ) {
			echo ' <a class="button-link" href="' . esc_url( self::url( [ 'set' => $set ] ) ) . '">' . esc_html__( 'Cancel', 'sheaf' ) . '</a>';
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
		</style>';
	}
}
