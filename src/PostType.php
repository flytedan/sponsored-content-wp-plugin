<?php
/**
 * Registers the `fdsc_sponsored_post` custom post type used to store
 * content pushed from flytedesk.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PostType {

	public const POST_TYPE = 'fdsc_sponsored_post';

	public function register(): void {
		$labels = array(
			'name'                  => __( 'Sponsored Content', 'flytedesk-sponsored-content' ),
			'singular_name'         => __( 'Sponsored Content', 'flytedesk-sponsored-content' ),
			'menu_name'             => __( 'Sponsored Content', 'flytedesk-sponsored-content' ),
			'name_admin_bar'        => __( 'Sponsored Content', 'flytedesk-sponsored-content' ),
			'add_new'               => __( 'Add New', 'flytedesk-sponsored-content' ),
			'add_new_item'          => __( 'Add New Sponsored Content', 'flytedesk-sponsored-content' ),
			'edit_item'             => __( 'Edit Sponsored Content', 'flytedesk-sponsored-content' ),
			'new_item'              => __( 'New Sponsored Content', 'flytedesk-sponsored-content' ),
			'view_item'             => __( 'View Sponsored Content', 'flytedesk-sponsored-content' ),
			'view_items'            => __( 'View Sponsored Content', 'flytedesk-sponsored-content' ),
			'search_items'          => __( 'Search Sponsored Content', 'flytedesk-sponsored-content' ),
			'not_found'             => __( 'No sponsored content found.', 'flytedesk-sponsored-content' ),
			'not_found_in_trash'    => __( 'No sponsored content found in Trash.', 'flytedesk-sponsored-content' ),
			'all_items'             => __( 'All Sponsored Content', 'flytedesk-sponsored-content' ),
			'archives'              => __( 'Sponsored Content Archives', 'flytedesk-sponsored-content' ),
			'attributes'            => __( 'Sponsored Content Attributes', 'flytedesk-sponsored-content' ),
			'insert_into_item'      => __( 'Insert into sponsored content', 'flytedesk-sponsored-content' ),
			'uploaded_to_this_item' => __( 'Uploaded to this sponsored content', 'flytedesk-sponsored-content' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_nav_menus'  => true,
			'show_in_admin_bar'  => true,
			'show_in_rest'       => true,
			'query_var'          => true,
			'rewrite'            => array(
				'slug'       => 'sponsored-content',
				'with_front' => false,
			),
			'has_archive'        => 'sponsored-content',
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
			'hierarchical'       => false,
			'menu_position'      => 20,
			'menu_icon'          => 'dashicons-megaphone',
			'supports'           => array( 'title', 'editor', 'excerpt', 'custom-fields', 'author', 'thumbnail' ),
		);

		register_post_type( self::POST_TYPE, $args );
	}
}
