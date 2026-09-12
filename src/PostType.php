<?php
/**
 * Registers the `fdhc_hosted_post` custom post type used to store
 * content pushed from flytedesk.
 *
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PostType {

	public const POST_TYPE = 'fdhc_hosted_post';

	public function register(): void {
		$labels = array(
			'name'                  => __( 'Hosted Content', 'flytedesk-hosted-content' ),
			'singular_name'         => __( 'Hosted Content', 'flytedesk-hosted-content' ),
			'menu_name'             => __( 'Hosted Content', 'flytedesk-hosted-content' ),
			'name_admin_bar'        => __( 'Hosted Content', 'flytedesk-hosted-content' ),
			'add_new'               => __( 'Add New', 'flytedesk-hosted-content' ),
			'add_new_item'          => __( 'Add New Hosted Content', 'flytedesk-hosted-content' ),
			'edit_item'             => __( 'Edit Hosted Content', 'flytedesk-hosted-content' ),
			'new_item'              => __( 'New Hosted Content', 'flytedesk-hosted-content' ),
			'view_item'             => __( 'View Hosted Content', 'flytedesk-hosted-content' ),
			'view_items'            => __( 'View Hosted Content', 'flytedesk-hosted-content' ),
			'search_items'          => __( 'Search Hosted Content', 'flytedesk-hosted-content' ),
			'not_found'             => __( 'No hosted content found.', 'flytedesk-hosted-content' ),
			'not_found_in_trash'    => __( 'No hosted content found in Trash.', 'flytedesk-hosted-content' ),
			'all_items'             => __( 'All Hosted Content', 'flytedesk-hosted-content' ),
			'archives'              => __( 'Hosted Content Archives', 'flytedesk-hosted-content' ),
			'attributes'            => __( 'Hosted Content Attributes', 'flytedesk-hosted-content' ),
			'insert_into_item'      => __( 'Insert into hosted content', 'flytedesk-hosted-content' ),
			'uploaded_to_this_item' => __( 'Uploaded to this hosted content', 'flytedesk-hosted-content' ),
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
				'slug'       => 'hosted-content',
				'with_front' => false,
			),
			'has_archive'        => 'hosted-content',
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
