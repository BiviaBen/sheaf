<?php
/**
 * The "Import chapters" screen: upload Word files, preview, create drafts.
 *
 * Most chapters are written outside WordPress, so this imports .docx Word
 * files — one file per chapter. The flow is two-step: upload (with cleaning
 * settings and a target book) parses each file into the IR (Docx_Reader) and
 * stashes it in a per-user transient; the preview step lets the author fix
 * detected titles and adjust settings before creating draft chapters
 * (Import_Serializer → block markup). Drafts append to the end of the book's
 * reading order, ready to edit and publish.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Import {

	private const CAPABILITY  = 'edit_posts';
	private const PAGE        = 'sheaf-import';
	private const NONCE_UP    = 'sheaf_import_upload';
	private const NONCE_CREATE = 'sheaf_import_create';
	private const TRANSIENT   = 'sheaf_import_';
	private const TTL         = HOUR_IN_SECONDS;
	private const MAX_BYTES   = 26214400; // 25 MB per file.

	public static function register(): void {
		// Priority 11 so this lands after Books_Admin's submenus (New Chapter).
		add_action( 'admin_menu', [ self::class, 'add_page' ], 11 );
		add_action( 'admin_post_' . self::NONCE_UP, [ self::class, 'handle_upload' ] );
		add_action( 'admin_post_' . self::NONCE_CREATE, [ self::class, 'handle_create' ] );

		// Keep the Sheafs menu highlighted on our screen, and add an "Import"
		// button to the core chapter list + a post-import success notice.
		add_filter( 'submenu_file', [ self::class, 'highlight_submenu' ] );
		add_action( 'admin_head-edit.php', [ self::class, 'listing_button' ] );
		add_action( 'admin_notices', [ self::class, 'imported_notice' ] );
	}

	/**
	 * URL of the import screen, optionally pre-selecting a book.
	 */
	public static function url( int $book_id = 0 ): string {
		$args = [ 'page' => self::PAGE ];
		if ( $book_id ) {
			$args['sheaf_book'] = $book_id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function add_page(): void {
		add_submenu_page(
			Books_Admin::MENU_SLUG,
			__( 'Import Chapters', 'sheaf' ),
			__( 'Import Chapters', 'sheaf' ),
			self::CAPABILITY,
			self::PAGE,
			[ self::class, 'render' ]
		);
	}

	/**
	 * Highlight the Import submenu while on the import screen.
	 */
	public static function highlight_submenu( ?string $submenu_file ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check.
		if ( isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) {
			return self::PAGE;
		}
		return $submenu_file;
	}

	/**
	 * Add an "Import chapters" button beside "Add New" on the chapter list.
	 */
	public static function listing_button(): void {
		if ( Chapters::POST_TYPE !== ( $GLOBALS['typenow'] ?? '' ) ) {
			return;
		}
		printf(
			'<script>document.addEventListener("DOMContentLoaded",function(){var a=document.querySelector(".wrap a.page-title-action");if(!a){return;}var i=document.createElement("a");i.href=%s;i.className="page-title-action";i.textContent=%s;a.insertAdjacentElement("afterend",i);});</script>',
			wp_json_encode( self::url() ),
			wp_json_encode( __( 'Import chapters', 'sheaf' ) )
		);
	}

	/**
	 * Show how many chapters were imported, back on the chapter list.
	 */
	public static function imported_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice.
		$count = isset( $_GET['sheaf_imported'] ) ? absint( $_GET['sheaf_imported'] ) : 0;
		if ( ! $count || Chapters::POST_TYPE !== ( $GLOBALS['typenow'] ?? '' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: number of chapters. */
					_n( '%s chapter imported as a draft.', '%s chapters imported as drafts.', $count, 'sheaf' ),
					number_format_i18n( $count )
				)
			)
		);
	}

	/**
	 * Screen router: upload form, or the preview of a parsed upload.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import chapters.', 'sheaf' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$data  = $token ? self::load( $token ) : null;

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Import chapters', 'sheaf' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		self::render_errors();

		if ( $data ) {
			self::render_preview( $token, $data );
		} else {
			self::render_upload_form();
		}
		echo '</div>';
	}

	private static function render_errors(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice.
		$error = isset( $_GET['sheaf_error'] ) ? sanitize_key( $_GET['sheaf_error'] ) : '';
		if ( ! $error ) {
			return;
		}
		$messages = [
			'nofiles' => __( 'No readable .docx files were uploaded. Please choose one or more Word files.', 'sheaf' ),
			'expired' => __( 'That import session has expired. Please upload the files again.', 'sheaf' ),
		];
		$message = $messages[ $error ] ?? __( 'Something went wrong with the import.', 'sheaf' );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}

	private static function render_upload_form(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pre-fill.
		$book = isset( $_GET['sheaf_book'] ) ? absint( $_GET['sheaf_book'] ) : 0;

		echo '<p class="description">' . esc_html__( 'Upload one or more Word (.docx) files — each file becomes a draft chapter. Word formatting is cleaned up on import; you can fix titles and order on the next screen.', 'sheaf' ) . '</p>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_UP );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::NONCE_UP ) );

		echo '<table class="form-table" role="presentation"><tbody>';

		// Target book.
		echo '<tr><th scope="row"><label for="sheaf-import-book">' . esc_html__( 'Add to book', 'sheaf' ) . '</label></th><td>';
		self::book_select( $book );
		echo '<p class="description">' . esc_html__( 'Imported chapters are assigned to this book and appended to the end of its reading order. You can change this per chapter later.', 'sheaf' ) . '</p>';
		echo '</td></tr>';

		// Files.
		echo '<tr><th scope="row"><label for="sheaf-import-files">' . esc_html__( 'Word files', 'sheaf' ) . '</label></th><td>';
		echo '<input type="file" id="sheaf-import-files" name="sheaf_files[]" accept=".docx" multiple required>';
		echo '<p class="description">' . esc_html__( 'Select multiple files to import several chapters at once.', 'sheaf' ) . '</p>';
		echo '</td></tr>';

		// Cleaning settings.
		echo '<tr><th scope="row">' . esc_html__( 'Keep formatting', 'sheaf' ) . '</th><td>';
		self::settings_fields( Import_Serializer::default_settings() );
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Upload and preview', 'sheaf' ) );
		echo '</form>';
	}

	/**
	 * The "Add to book" selector: a books-only dropdown (plus "unassigned") with
	 * a "Show all pages" toggle that swaps in the full page list — mirroring the
	 * Book selector on the chapter editor.
	 */
	private static function book_select( int $selected ): void {
		$book_ids = Books::all_book_ids();
		if ( $selected && ! in_array( $selected, $book_ids, true ) ) {
			$book_ids[] = $selected;
		}

		// Books-only selector (the default).
		echo '<select name="sheaf_book" id="sheaf-import-book">';
		printf( '<option value="0">%s</option>', esc_html__( '— Unassigned —', 'sheaf' ) );
		foreach ( $book_ids as $bid ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $bid,
				selected( $selected, (int) $bid, false ),
				esc_html( get_the_title( (int) $bid ) )
			);
		}
		echo '</select>';

		// The full page list, hidden and disabled until "show all pages" is
		// ticked. Disabled controls aren't submitted, so only one value is sent.
		$all = (string) wp_dropdown_pages(
			[
				'name'              => 'sheaf_book',
				'id'                => 'sheaf-import-book-all',
				'selected'          => $selected,
				'show_option_none'  => __( '— Unassigned —', 'sheaf' ),
				'option_none_value' => 0,
				'echo'              => 0,
			]
		);
		$all = preg_replace( '/<select /', '<select disabled ', $all, 1 );
		echo ' <span id="sheaf-import-book-all-wrap" style="display:none">' . $all . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages output.

		printf(
			'<p><label><input type="checkbox" id="sheaf-import-book-allpages"> %s</label></p>',
			esc_html__( 'Show all pages', 'sheaf' )
		);
		echo '<p class="description" id="sheaf-import-book-allpages-note" style="display:none">'
			. esc_html__( 'Adding a Chapter to a Page turns that Page into a Book.', 'sheaf' )
			. '</p>';
		?>
		<script>
		( function () {
			var cb    = document.getElementById( 'sheaf-import-book-allpages' );
			var books = document.getElementById( 'sheaf-import-book' );
			var wrap  = document.getElementById( 'sheaf-import-book-all-wrap' );
			var all   = document.getElementById( 'sheaf-import-book-all' );
			var note  = document.getElementById( 'sheaf-import-book-allpages-note' );
			if ( ! cb || ! books || ! all ) { return; }
			cb.addEventListener( 'change', function () {
				if ( cb.checked ) {
					all.value = books.value;
					books.disabled = true;  books.style.display = 'none';
					all.disabled = false;   wrap.style.display = '';
					note.style.display = '';
				} else {
					var match = Array.prototype.some.call( books.options, function ( o ) { return o.value === all.value; } );
					books.value = match ? all.value : '0';
					all.disabled = true;    wrap.style.display = 'none';
					books.disabled = false; books.style.display = '';
					note.style.display = 'none';
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * The "keep formatting" checkboxes, reflecting the current settings.
	 *
	 * @param array<string,mixed> $settings
	 */
	private static function settings_fields( array $settings ): void {
		$fields = [
			'keep_headings'   => __( 'Headings', 'sheaf' ),
			'keep_emphasis'   => __( 'Bold / italic / underline', 'sheaf' ),
			'keep_lists'      => __( 'Lists', 'sheaf' ),
			'keep_blockquote' => __( 'Block quotes', 'sheaf' ),
			'keep_links'        => __( 'Links', 'sheaf' ),
			'scene_breaks'      => __( 'Scene breaks (e.g. “* * *”) as separators', 'sheaf' ),
			'keep_named_styles'   => __( 'Named custom styles and formatting', 'sheaf' ),
			'keep_unnamed_styles' => __( 'Ad hoc/unnamed custom styles and formatting', 'sheaf' ),
		];
		echo '<fieldset>';
		foreach ( $fields as $key => $label ) {
			printf(
				'<label style="display:block;margin:.2em 0"><input type="checkbox" name="settings[%1$s]" value="1"%2$s> %3$s</label>',
				esc_attr( $key ),
				checked( ! empty( $settings[ $key ] ), true, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">' . esc_html__( 'Anything not kept is converted to plain paragraphs. Images are not imported.', 'sheaf' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Read settings from a submitted form, including the Word-style mappings
	 * (validated against the target book's active style-set styles).
	 *
	 * @return array<string,mixed>
	 */
	private static function settings_from_request( int $book ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by the caller.
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['settings'] ) )
			: [];
		$settings = Import_Serializer::sanitize_settings( $raw );

		// The author's per-Word-style choices: "" (ignore), an existing style's
		// class, or "new:<set>" (create one). Kept so the dropdowns remember the
		// selection; the existing-class ones become the preview maps.
		$options                     = self::style_options( $book );
		$settings['char_choices']    = self::read_choices( 'char_map', $options, 'inline' );
		$settings['para_choices']    = self::read_choices( 'para_map', $options, 'block' );
		$settings['style_map']       = self::existing_class_map( $settings['char_choices'] );
		$settings['block_style_map'] = self::existing_class_map( $settings['para_choices'] );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by the caller.
		$settings['new_set'] = isset( $_POST['new_set'] ) ? sanitize_text_field( wp_unslash( $_POST['new_set'] ) ) : '';

		return $settings;
	}

	/**
	 * Read a Word-style => choice map from the request, validated against the
	 * book's active styles. A choice is "" (ignore), an existing style's class,
	 * or "new:<set-slug>" for a set the book actually activates.
	 *
	 * @param array<string,mixed> $options style_options() output.
	 * @return array<string,string>
	 */
	private static function read_choices( string $field, array $options, string $kind ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by the caller.
		$raw = isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? (array) wp_unslash( $_POST[ $field ] )
			: [];

		$classes = [];
		foreach ( (array) ( $options[ $kind ] ?? [] ) as $opt ) {
			$classes[ $opt['class'] ] = true;
		}
		$sets = [];
		foreach ( (array) ( $options['sets'] ?? [] ) as $set ) {
			$sets[ $set['slug'] ] = true;
		}

		$out = [];
		foreach ( $raw as $word => $choice ) {
			$choice = (string) $choice;
			if ( 0 === strpos( $choice, 'new:' ) ) {
				$set = sanitize_key( substr( $choice, 4 ) );
				if ( isset( $sets[ $set ] ) ) {
					$out[ (string) $word ] = 'new:' . $set;
				}
			} else {
				$class = sanitize_html_class( $choice );
				if ( '' !== $class && isset( $classes[ $class ] ) ) {
					$out[ (string) $word ] = $class;
				}
			}
		}
		return $out;
	}

	/**
	 * The subset of choices that are existing classes (ready to apply now). The
	 * "new:<set>" choices are resolved later, at create time.
	 *
	 * @param array<string,string> $choices
	 * @return array<string,string>
	 */
	private static function existing_class_map( array $choices ): array {
		$map = [];
		foreach ( $choices as $word => $choice ) {
			if ( '' !== $choice && 0 !== strpos( $choice, 'new:' ) ) {
				$map[ $word ] = $choice;
			}
		}
		return $map;
	}

	/**
	 * The style-set styles a book activates, split by kind, as mapping options:
	 * inline styles (for Word character styles) and block styles (for Word
	 * paragraph styles). Each option carries the CSS class the content will
	 * receive plus labels for the dropdown.
	 *
	 * @return array{inline:array<int,array<string,string>>,block:array<int,array<string,string>>}
	 */
	private static function style_options( int $book ): array {
		$out = [
			'inline' => [],
			'block'  => [],
			'sets'   => [],
		];
		foreach ( Style_Sets::active_sets( $book ) as $set ) {
			$set_data = Style_Sets::get_set( $set );
			if ( ! $set_data ) {
				continue;
			}
			$set_label    = '' !== (string) ( $set_data['label'] ?? '' ) ? (string) $set_data['label'] : (string) $set;
			$out['sets'][] = [
				'slug'  => (string) $set,
				'label' => $set_label,
			];
			foreach ( (array) ( $set_data['styles'] ?? [] ) as $style => $def ) {
				$kind  = in_array( $def['kind'] ?? 'inline', Style_Sets::KINDS, true ) ? (string) $def['kind'] : 'inline';
				$label = '' !== (string) ( $def['label'] ?? '' ) ? (string) $def['label'] : (string) $style;
				$out[ 'block' === $kind ? 'block' : 'inline' ][] = [
					'class'    => Style_Sets::css_class( (string) $set, (string) $style, $kind ),
					'label'    => $label,
					'set'      => $set_label,
					'set_slug' => (string) $set,
				];
			}
		}
		return $out;
	}

	/**
	 * Distinct Word styles used across the parsed entries, with occurrence
	 * counts: character styles (on runs) and paragraph styles (on plain
	 * paragraphs). Structural styles already consumed as headings/quotes are
	 * not offered.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 * @return array{char:array<string,int>,para:array<string,int>}
	 */
	private static function collect_styles( array $entries ): array {
		$char = [];
		$para = [];
		foreach ( $entries as $entry ) {
			if ( '' !== (string) ( $entry['error'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $entry['blocks'] ?? [] ) as $block ) {
				if ( 'paragraph' === ( $block['type'] ?? '' ) && '' !== (string) ( $block['style'] ?? '' ) ) {
					$name          = (string) $block['style'];
					$para[ $name ] = ( $para[ $name ] ?? 0 ) + 1;
				}

				$run_groups = [];
				if ( isset( $block['runs'] ) ) {
					$run_groups[] = $block['runs'];
				}
				if ( isset( $block['items'] ) ) {
					foreach ( $block['items'] as $item ) {
						$run_groups[] = $item;
					}
				}
				foreach ( $run_groups as $runs ) {
					foreach ( (array) $runs as $run ) {
						$s = (string) ( $run['style'] ?? '' );
						if ( '' !== $s ) {
							$char[ $s ] = ( $char[ $s ] ?? 0 ) + 1;
						}
					}
				}
			}
		}
		ksort( $char );
		ksort( $para );
		return [
			'char' => $char,
			'para' => $para,
		];
	}

	/**
	 * Cluster ad-hoc/unnamed (direct) run formatting across the parsed entries.
	 * Runs that carry direct formatting but no named character style are grouped
	 * by their canonical signature; each cluster keeps a stable id, the props, an
	 * occurrence count and a sample of the affected text. Ordered by count.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 * @return array<string,array<string,mixed>> id => cluster
	 */
	private static function collect_direct( array $entries ): array {
		$clusters = [];
		foreach ( $entries as $entry ) {
			if ( '' !== (string) ( $entry['error'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $entry['blocks'] ?? [] ) as $block ) {
				$groups = [];
				if ( isset( $block['runs'] ) ) {
					$groups[] = $block['runs'];
				}
				if ( isset( $block['items'] ) ) {
					foreach ( $block['items'] as $item ) {
						$groups[] = $item;
					}
				}
				foreach ( $groups as $runs ) {
					foreach ( (array) $runs as $run ) {
						$direct = (array) ( $run['direct'] ?? [] );
						// Only pure direct formatting — named styles are handled elsewhere.
						if ( ! $direct || '' !== (string) ( $run['style'] ?? '' ) ) {
							continue;
						}
						$signature = Import_Serializer::direct_signature( $direct );
						if ( '' === $signature ) {
							continue;
						}
						$id = substr( md5( $signature ), 0, 12 );
						if ( ! isset( $clusters[ $id ] ) ) {
							$clusters[ $id ] = [
								'id'        => $id,
								'signature' => $signature,
								'props'     => $direct,
								'count'     => 0,
								'sample'    => '',
							];
						}
						++$clusters[ $id ]['count'];
						$text = trim( (string) $run['text'] );
						if ( '' === $clusters[ $id ]['sample'] && '' !== $text ) {
							$clusters[ $id ]['sample'] = mb_substr( $text, 0, 60 );
						}
					}
				}
			}
		}

		uasort(
			$clusters,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);
		return $clusters;
	}

	/**
	 * A human-readable description of a direct-formatting cluster's props, used as
	 * a label and as the name when the cluster becomes a new style.
	 *
	 * @param array<string,string> $props
	 */
	private static function describe_direct( array $props ): string {
		$order = [ 'font-family', 'font-size', 'font-weight', 'font-style', 'color', 'background-color' ];
		$parts = [];
		foreach ( $order as $key ) {
			if ( ! empty( $props[ $key ] ) ) {
				$parts[] = (string) $props[ $key ];
			}
		}
		foreach ( $props as $key => $value ) {
			if ( ! in_array( $key, $order, true ) && '' !== (string) $value ) {
				$parts[] = (string) $value;
			}
		}
		return $parts ? implode( ', ', $parts ) : __( 'Plain text', 'sheaf' );
	}

	/**
	 * Handle the upload: parse each .docx to IR, stash, redirect to preview.
	 */
	public static function handle_upload(): void {
		check_admin_referer( self::NONCE_UP );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import chapters.', 'sheaf' ) );
		}

		$book     = isset( $_POST['sheaf_book'] ) ? absint( $_POST['sheaf_book'] ) : 0;
		$settings = self::settings_from_request( $book );
		$files    = self::normalize_files();

		$entries = [];
		foreach ( $files as $file ) {
			$entry = self::read_file( $file );
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}

		if ( ! $entries ) {
			wp_safe_redirect( add_query_arg( 'sheaf_error', 'nofiles', self::url( $book ) ) );
			exit;
		}

		$token = wp_generate_password( 24, false );
		self::store(
			$token,
			[
				'user'     => get_current_user_id(),
				'book'     => $book,
				'settings' => $settings,
				'entries'  => $entries,
			]
		);

		wp_safe_redirect( add_query_arg( 'token', $token, self::url( $book ) ) );
		exit;
	}

	/**
	 * Normalize the PHP multi-file upload array into a list of single files.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_files(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in handle_upload.
		if ( empty( $_FILES['sheaf_files'] ) || ! is_array( $_FILES['sheaf_files']['name'] ) ) {
			return [];
		}
		$raw   = $_FILES['sheaf_files']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- field-by-field below.
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$files = [];
		$count = count( $raw['name'] );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( (int) $raw['error'][ $i ] !== UPLOAD_ERR_OK ) {
				continue;
			}
			$files[] = [
				'name' => sanitize_file_name( (string) $raw['name'][ $i ] ),
				'tmp'  => (string) $raw['tmp_name'][ $i ],
				'size' => (int) $raw['size'][ $i ],
			];
		}
		return $files;
	}

	/**
	 * Validate and parse one uploaded file into a preview entry.
	 *
	 * @param array<string,mixed> $file
	 * @return array<string,mixed>|null Null when the upload should be ignored.
	 */
	private static function read_file( array $file ): ?array {
		$name = (string) $file['name'];
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		$entry = [
			'name'   => $name,
			'title'  => self::title_from_filename( $name ),
			'blocks' => [],
			'images' => 0,
			'tables' => 0,
			'error'  => '',
		];

		if ( 'docx' !== $ext ) {
			$entry['error'] = __( 'Not a .docx Word file — skipped.', 'sheaf' );
			return $entry;
		}
		if ( $file['size'] > self::MAX_BYTES || ! is_uploaded_file( (string) $file['tmp'] ) ) {
			$entry['error'] = __( 'File is too large or could not be read — skipped.', 'sheaf' );
			return $entry;
		}

		try {
			$ir = Docx_Reader::read( (string) $file['tmp'] );
		} catch ( \Throwable $e ) {
			$entry['error'] = $e->getMessage();
			return $entry;
		}

		$entry['blocks'] = $ir['blocks'];
		$entry['images'] = (int) $ir['images'];
		$entry['tables'] = (int) $ir['tables'];
		if ( '' !== trim( (string) $ir['title'] ) ) {
			$entry['title'] = sanitize_text_field( $ir['title'] );
		}

		return $entry;
	}

	/**
	 * A reasonable chapter title from a filename (sans extension/separators).
	 */
	private static function title_from_filename( string $name ): string {
		$base = pathinfo( $name, PATHINFO_FILENAME );
		$base = str_replace( [ '_', '-' ], ' ', $base );
		$base = trim( (string) preg_replace( '/\s+/', ' ', $base ) );
		return '' === $base ? __( 'Untitled chapter', 'sheaf' ) : $base;
	}

	/**
	 * Render the preview: per-file title, word count, snippet, warnings, plus
	 * the settings to adjust. Two actions: update the preview, or create drafts.
	 *
	 * @param array<string,mixed> $data
	 */
	private static function render_preview( string $token, array $data ): void {
		$settings   = Import_Serializer::sanitize_settings( (array) $data['settings'] );
		$book       = (int) $data['book'];
		$entries    = (array) $data['entries'];
		$importable = 0;

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_CREATE );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::NONCE_CREATE ) );
		printf( '<input type="hidden" name="token" value="%s">', esc_attr( $token ) );

		$book_label = $book ? get_the_title( $book ) : __( 'Unassigned', 'sheaf' );
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: book title. */
					__( 'Review the chapters below, then create them as drafts in: %s', 'sheaf' ),
					$book_label
				)
			)
		);

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Title', 'sheaf' ) . '</th>';
		echo '<th style="width:6em">' . esc_html__( 'Words', 'sheaf' ) . '</th>';
		echo '<th>' . esc_html__( 'Preview', 'sheaf' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $i => $entry ) {
			if ( '' !== $entry['error'] ) {
				printf(
					'<tr><td><strong>%1$s</strong></td><td>—</td><td><span class="description" style="color:#b32d2e">%2$s</span></td></tr>',
					esc_html( $entry['name'] ),
					esc_html( $entry['error'] )
				);
				continue;
			}
			++$importable;

			$content = Import_Serializer::to_blocks( $entry['blocks'], $settings );
			$words   = Words::count_in( $content );
			$snippet = Import_Serializer::to_text( $entry['blocks'], 40 );

			echo '<tr><td>';
			printf(
				'<input type="text" class="large-text" name="titles[%1$d]" value="%2$s">',
				(int) $i,
				esc_attr( $entry['title'] )
			);
			printf( '<div class="row-actions"><span>%s</span></div>', esc_html( $entry['name'] ) );
			echo '</td>';
			printf( '<td>%s</td>', esc_html( number_format_i18n( $words ) ) );
			echo '<td>';
			printf( '<span class="description">%s</span>', esc_html( $snippet ) );
			foreach ( self::warnings( $entry ) as $warning ) {
				printf( '<br><span class="description" style="color:#996800">%s</span>', esc_html( $warning ) );
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Keep formatting', 'sheaf' ) . '</h2>';
		self::settings_fields( $settings );

		// Pass the raw settings (sanitize_settings drops the choice fields the
		// mapping UI needs to remember the author's selections).
		self::render_style_mapping( $book, $entries, (array) $data['settings'] );

		echo '<p class="submit">';
		printf(
			'<button type="submit" name="sheaf_action" value="preview" class="button">%s</button> ',
			esc_html__( 'Update preview', 'sheaf' )
		);
		if ( $importable > 0 ) {
			printf(
				'<button type="submit" name="sheaf_action" value="create" class="button button-primary">%s</button> ',
				esc_html(
					sprintf(
						/* translators: %s: number of chapters. */
						_n( 'Create %s draft', 'Create %s drafts', $importable, 'sheaf' ),
						number_format_i18n( $importable )
					)
				)
			);
		}
		printf(
			'<a href="%s" class="button-link">%s</a>',
			esc_url( self::url( $book ) ),
			esc_html__( 'Start over', 'sheaf' )
		);
		echo '</p>';
		echo '</form>';
	}

	/**
	 * Human-readable warnings for an entry (e.g. dropped images/tables).
	 *
	 * @param array<string,mixed> $entry
	 * @return string[]
	 */
	private static function warnings( array $entry ): array {
		$out = [];
		if ( $entry['images'] > 0 ) {
			$out[] = sprintf(
				/* translators: %s: number of images. */
				_n( '%s image skipped (not imported).', '%s images skipped (not imported).', $entry['images'], 'sheaf' ),
				number_format_i18n( $entry['images'] )
			);
		}
		if ( $entry['tables'] > 0 ) {
			$out[] = sprintf(
				/* translators: %s: number of tables. */
				_n( '%s table skipped.', '%s tables skipped.', $entry['tables'], 'sheaf' ),
				number_format_i18n( $entry['tables'] )
			);
		}
		return $out;
	}

	/**
	 * The "Word styles" section of the preview: map each named Word style found
	 * in the uploaded files to one of the target book's active style-set styles
	 * (or leave it ignored). Character styles map to inline styles, paragraph
	 * styles to block styles.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 * @param array<string,mixed>            $settings
	 */
	private static function render_style_mapping( int $book, array $entries, array $settings ): void {
		if ( empty( $settings['keep_named_styles'] ) ) {
			return; // Named-style mapping is opt-in (the "Keep formatting" checkbox).
		}

		$detected = self::collect_styles( $entries );
		if ( ! $detected['char'] && ! $detected['para'] ) {
			return; // No named Word styles to map.
		}

		echo '<h2>' . esc_html__( 'Word styles', 'sheaf' ) . '</h2>';

		$options = self::style_options( $book );

		// No active sets: offer to create one from the found styles (C2).
		if ( ! $options['sets'] ) {
			self::render_create_set_from_found( $detected, $settings );
			return;
		}

		echo '<p class="description">' . esc_html__( 'Map named Word styles found in these files to your style-set styles. You can add a found style to a set as a new style, map it to an existing one, or ignore it (imported as plain text).', 'sheaf' ) . '</p>';

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Word style', 'sheaf' ) . '</th>';
		echo '<th style="width:7em">' . esc_html__( 'Uses', 'sheaf' ) . '</th>';
		echo '<th>' . esc_html__( 'Maps to', 'sheaf' ) . '</th>';
		echo '</tr></thead><tbody>';

		self::mapping_rows( __( 'Character styles', 'sheaf' ), $detected['char'], 'char_map', $options, 'inline', (array) ( $settings['char_choices'] ?? [] ) );
		self::mapping_rows( __( 'Paragraph styles', 'sheaf' ), $detected['para'], 'para_map', $options, 'block', (array) ( $settings['para_choices'] ?? [] ) );

		echo '</tbody></table>';
	}

	/**
	 * One sub-group of the Word-style mapping table. Each found style can map to
	 * "— Ignore —", a new style in one of the book's sets ("new:<set>"), or an
	 * existing style (its class), grouped by set.
	 *
	 * @param array<string,int>    $detected Word style name => uses.
	 * @param array<string,mixed>  $options  style_options() output.
	 * @param array<string,string> $choices  Word style name => current choice.
	 */
	private static function mapping_rows( string $heading, array $detected, string $field, array $options, string $kind, array $choices ): void {
		if ( ! $detected ) {
			return;
		}
		printf( '<tr><th colspan="3" scope="rowgroup">%s</th></tr>', esc_html( $heading ) );

		foreach ( $detected as $name => $count ) {
			$selected = (string) ( $choices[ (string) $name ] ?? '' );

			echo '<tr>';
			printf( '<td><code>%s</code></td>', esc_html( (string) $name ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $count ) ) );
			echo '<td>';
			printf( '<select name="%1$s[%2$s]">', esc_attr( $field ), esc_attr( (string) $name ) );
			printf( '<option value=""%1$s>%2$s</option>', selected( $selected, '', false ), esc_html__( '— Ignore —', 'sheaf' ) );

			foreach ( $options['sets'] as $set ) {
				// "Add as a new style" in this set.
				$new_val = 'new:' . $set['slug'];
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $new_val ),
					selected( $selected, $new_val, false ),
					esc_html(
						sprintf(
							/* translators: 1: set name, 2: Word style name. */
							__( '%1$s › %2$s [new style]', 'sheaf' ),
							$set['label'],
							(string) $name
						)
					)
				);
				// Existing styles of this set and kind.
				foreach ( $options[ $kind ] as $opt ) {
					if ( ( $opt['set_slug'] ?? '' ) !== $set['slug'] ) {
						continue;
					}
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( $opt['class'] ),
						selected( $selected, $opt['class'], false ),
						esc_html( $set['label'] . ' › ' . $opt['label'] )
					);
				}
			}

			echo '</select></td></tr>';
		}
	}

	/**
	 * When the target book has no style sets but the files carry named styles,
	 * offer to create a set containing all of them (C2). Leaving the name blank
	 * skips the styles (imported as plain text).
	 *
	 * @param array{char:array<string,int>,para:array<string,int>} $detected
	 * @param array<string,mixed>                                  $settings
	 */
	private static function render_create_set_from_found( array $detected, array $settings ): void {
		$total = count( $detected['char'] ) + count( $detected['para'] );

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: number of named styles. */
				_n(
					'%s named Word style was found, but this book has no style sets. Name a new set to create from it:',
					'%s named Word styles were found, but this book has no style sets. Name a new set to create from them:',
					$total,
					'sheaf'
				),
				number_format_i18n( $total )
			)
		) . '</p>';

		printf(
			'<p><label>%1$s <input type="text" name="new_set" value="%2$s" class="regular-text" placeholder="%3$s"></label></p>',
			esc_html__( 'New style set name', 'sheaf' ),
			esc_attr( (string) ( $settings['new_set'] ?? '' ) ),
			esc_attr__( 'e.g. Strange Voices', 'sheaf' )
		);

		echo '<ul style="margin-left:1.4em;list-style:disc">';
		foreach ( array_keys( $detected['char'] ) as $word ) {
			printf( '<li><code>%s</code> — %s</li>', esc_html( (string) $word ), esc_html__( 'inline style', 'sheaf' ) );
		}
		foreach ( array_keys( $detected['para'] ) as $word ) {
			printf( '<li><code>%s</code> — %s</li>', esc_html( (string) $word ), esc_html__( 'paragraph style', 'sheaf' ) );
		}
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'Leave the name blank to import these as plain text.', 'sheaf' ) . '</p>';
	}

	/**
	 * Handle the preview form: update settings/titles, or create the drafts.
	 */
	public static function handle_create(): void {
		check_admin_referer( self::NONCE_CREATE );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import chapters.', 'sheaf' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$data  = $token ? self::load( $token ) : null;
		if ( ! $data ) {
			wp_safe_redirect( add_query_arg( 'sheaf_error', 'expired', self::url() ) );
			exit;
		}

		// Fold the submitted settings and edited titles back into the session.
		$data['settings'] = self::settings_from_request( (int) $data['book'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$titles = isset( $_POST['titles'] ) && is_array( $_POST['titles'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['titles'] ) )
			: [];
		foreach ( $data['entries'] as $i => $entry ) {
			if ( isset( $titles[ $i ] ) && '' !== trim( $titles[ $i ] ) ) {
				$data['entries'][ $i ]['title'] = $titles[ $i ];
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$action = isset( $_POST['sheaf_action'] ) ? sanitize_key( $_POST['sheaf_action'] ) : 'preview';

		if ( 'create' !== $action ) {
			self::store( $token, $data );
			wp_safe_redirect( add_query_arg( 'token', $token, self::url( (int) $data['book'] ) ) );
			exit;
		}

		// Create any new style sets / styles the author chose, then import.
		$data    = self::resolve_style_choices( $data );
		$created = self::create_drafts( $data );
		self::forget( $token );

		$book = (int) $data['book'];
		if ( $book ) {
			// Land on the book's reading-order screen so the author can slot the
			// new drafts into place straight away.
			$redirect = add_query_arg(
				[
					'post_type'      => Chapters::POST_TYPE,
					'page'           => Books_Admin::MENU_SLUG,
					'book'           => $book,
					'sheaf_imported' => $created,
				],
				admin_url( 'edit.php' )
			);
		} else {
			// Unassigned imports have no book page; fall back to the chapter list.
			$redirect = add_query_arg(
				[
					'post_type'      => Chapters::POST_TYPE,
					'sheaf_imported' => $created,
				],
				admin_url( 'edit.php' )
			);
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Turn the author's style choices into real styles just before importing:
	 *  - a "new set" name (C2) creates a set from every found style and activates
	 *    it on the book;
	 *  - "new:<set>" choices (C3) create a style named after the Word style.
	 * The resulting classes are folded into the style maps the serializer uses.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private static function resolve_style_choices( array $data ): array {
		$settings = (array) $data['settings'];
		if ( empty( $settings['keep_named_styles'] ) ) {
			return $data;
		}

		$book      = (int) $data['book'];
		$detected  = self::collect_styles( (array) $data['entries'] );
		$style_map = (array) ( $settings['style_map'] ?? [] );
		$block_map = (array) ( $settings['block_style_map'] ?? [] );

		// C2: build a whole new set from the found styles (only when there are
		// no sets to choose from in the first place).
		$new_set_name = trim( (string) ( $settings['new_set'] ?? '' ) );
		if ( '' !== $new_set_name && ! Style_Sets::active_sets( $book ) ) {
			$set = Style_Sets::save_set( $new_set_name );
			foreach ( array_keys( $detected['char'] ) as $word ) {
				$style                  = Style_Sets::save_style( $set, [ 'label' => (string) $word, 'kind' => 'inline' ] );
				$style_map[ (string) $word ] = Style_Sets::style_class( $set, $style );
			}
			foreach ( array_keys( $detected['para'] ) as $word ) {
				$style                  = Style_Sets::save_style( $set, [ 'label' => (string) $word, 'kind' => 'block' ] );
				$block_map[ (string) $word ] = Style_Sets::css_class( $set, $style, 'block' );
			}
			if ( $book ) {
				$active   = Style_Sets::active_sets( $book );
				$active[] = $set;
				update_post_meta( $book, Style_Sets::BOOK_META, array_values( array_unique( $active ) ) );
			}
		}

		// C3: resolve "new:<set>" choices into freshly-created styles.
		$style_map = array_merge( $style_map, self::create_new_styles( (array) ( $settings['char_choices'] ?? [] ), 'inline', $book ) );
		$block_map = array_merge( $block_map, self::create_new_styles( (array) ( $settings['para_choices'] ?? [] ), 'block', $book ) );

		$settings['style_map']       = $style_map;
		$settings['block_style_map'] = $block_map;
		$data['settings']            = $settings;
		return $data;
	}

	/**
	 * Create a style (named after the Word style) for every "new:<set>" choice,
	 * returning a Word-style => class map. Only sets the book activates are used.
	 *
	 * @param array<string,string> $choices
	 * @return array<string,string>
	 */
	private static function create_new_styles( array $choices, string $kind, int $book ): array {
		$active = Style_Sets::active_sets( $book );
		$map    = [];
		foreach ( $choices as $word => $choice ) {
			if ( 0 !== strpos( (string) $choice, 'new:' ) ) {
				continue;
			}
			$set = sanitize_key( substr( (string) $choice, 4 ) );
			if ( '' === $set || ! in_array( $set, $active, true ) ) {
				continue;
			}
			$style = Style_Sets::save_style( $set, [ 'label' => (string) $word, 'kind' => $kind ] );
			if ( '' === $style ) {
				continue;
			}
			$map[ (string) $word ] = 'block' === $kind
				? Style_Sets::css_class( $set, $style, 'block' )
				: Style_Sets::style_class( $set, $style );
		}
		return $map;
	}

	/**
	 * Create one draft chapter per importable entry, appended to the book.
	 *
	 * @param array<string,mixed> $data
	 * @return int Number of drafts created.
	 */
	private static function create_drafts( array $data ): int {
		$book     = (int) $data['book'];
		$settings = Import_Serializer::sanitize_settings( (array) $data['settings'] );
		$order    = Books::next_menu_order( $book );
		$created  = 0;

		// Scope chapter-slug uniqueness to the target book during insertion.
		Books::set_book_context( $book );

		foreach ( (array) $data['entries'] as $entry ) {
			if ( '' !== $entry['error'] ) {
				continue;
			}

			$postarr = [
				'post_type'    => Chapters::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => $entry['title'],
				'post_content' => Import_Serializer::to_blocks( $entry['blocks'], $settings ),
				'menu_order'   => $order,
			];
			if ( $book ) {
				$postarr['meta_input'] = [ Books::BOOK_META => $book ];
			}

			$id = wp_insert_post( $postarr, true );
			if ( ! is_wp_error( $id ) && $id ) {
				++$created;
				++$order;
			}
		}

		Books::set_book_context( 0 );

		return $created;
	}

	/* ---- Per-user transient session storage -------------------------------- */

	private static function store( string $token, array $data ): void {
		set_transient( self::TRANSIENT . $token, $data, self::TTL );
	}

	/**
	 * Load a session, but only for the user who created it.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function load( string $token ): ?array {
		$data = get_transient( self::TRANSIENT . $token );
		if ( ! is_array( $data ) || (int) ( $data['user'] ?? 0 ) !== get_current_user_id() ) {
			return null;
		}
		return $data;
	}

	private static function forget( string $token ): void {
		delete_transient( self::TRANSIENT . $token );
	}
}
