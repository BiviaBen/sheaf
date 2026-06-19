<?php
/**
 * The sheaf_chapter custom post type and its meta.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Chapters {

	public const POST_TYPE = 'sheaf_chapter';

	/** Meta flag: this "chapter" is really a section divider (e.g. "Part I"). */
	public const SECTION_META = '_sheaf_is_section';

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_post_type' ] );
		add_action( 'init', [ self::class, 'register_meta' ] );
	}

	/**
	 * Whether a chapter is a section divider rather than a reading chapter.
	 */
	public static function is_section( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, self::SECTION_META, true );
	}

	public static function register_post_type(): void {
		$labels = [
			'name'               => __( 'Chapters', 'sheaf' ),
			'singular_name'      => __( 'Chapter', 'sheaf' ),
			'add_new_item'       => __( 'Add New Chapter', 'sheaf' ),
			'edit_item'          => __( 'Edit Chapter', 'sheaf' ),
			'new_item'           => __( 'New Chapter', 'sheaf' ),
			'view_item'          => __( 'View Chapter', 'sheaf' ),
			'search_items'       => __( 'Search Chapters', 'sheaf' ),
			'not_found'          => __( 'No chapters found.', 'sheaf' ),
			'all_items'          => __( 'All Chapters', 'sheaf' ),
			'menu_name'          => __( 'Sheaf', 'sheaf' ),
		];

		register_post_type(
			self::POST_TYPE,
			[
				'labels'       => $labels,
				'public'       => true,
				'show_in_rest' => true,
				'hierarchical' => false,
				'has_archive'  => false,
				'menu_icon'    => 'dashicons-book',
				'supports'     => [
					'title',
					'editor',
					'author',
					'excerpt',
					'thumbnail',
					'comments',
					'revisions',
					'custom-fields',
					'page-attributes', // exposes the menu_order "Order" field
				],
				// Routing is handled in Permalinks so chapter URLs can nest
				// under their book Page's path. No default CPT rewrite rules.
				'rewrite'      => false,
				'query_var'    => self::POST_TYPE,
			]
		);
	}

	public static function register_meta(): void {
		register_post_meta(
			self::POST_TYPE,
			Books::BOOK_META,
			[
				'type'              => 'integer',
				'description'       => __( 'ID of the book Page this chapter belongs to.', 'sheaf' ),
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static fn() => current_user_can( 'edit_posts' ),
			]
		);

		register_post_meta(
			self::POST_TYPE,
			self::SECTION_META,
			[
				'type'              => 'boolean',
				'description'       => __( 'Whether this entry is a section divider rather than a chapter.', 'sheaf' ),
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'sanitize_callback' => static fn( $value ) => (bool) $value,
				'auth_callback'     => static fn() => current_user_can( 'edit_posts' ),
			]
		);
	}
}
