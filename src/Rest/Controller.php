<?php
/**
 * REST API surface for flytedesk sponsored content.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Rest;

use Flytedesk\SponsoredContent\Capabilities;
use Flytedesk\SponsoredContent\Markdown\Converter;
use Flytedesk\SponsoredContent\PostType;
use Flytedesk\SponsoredContent\Registration\VerificationTracker;
use Flytedesk\SponsoredContent\Seo\Resolver;
use WP_Error;
use WP_HTTP_Response;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes live under the `flytedesk/v1` namespace:
 *
 *   POST   /wp-json/flytedesk/v1/posts        create + publish
 *   GET    /wp-json/flytedesk/v1/posts/{id}   fetch current stored state
 *   PUT    /wp-json/flytedesk/v1/posts/{id}   full replacement update
 *   DELETE /wp-json/flytedesk/v1/posts/{id}   trash
 *
 * Authentication is WordPress Application Passwords (core since 5.6),
 * delivered as `Authorization: Basic base64(username:app_password)`. That
 * header is parsed natively by WP_REST_Server / WP's application-passwords
 * auth handler before permission_callback ever runs - this class only has
 * to check the resulting current user's capability.
 *
 * Error responses are always shaped as:
 *   { "error": { "code": "...", "message": "..." } }
 * via normalize_error_response() below, so every failure path - whether it
 * originates in permission_callback or inside a route callback - gets the
 * same treatment. This relies on `rest_request_after_callbacks` running for
 * permission_callback failures as well as route callback failures; that is
 * WP core's documented behaviour (WP_REST_Server::respond_to_request() runs
 * the permission check, then the route callback, and passes whatever
 * WP_Error/response results - from either step - through
 * `rest_request_after_callbacks` before the response is ever serialized).
 *
 * {@see \Flytedesk\SponsoredContent\Plugin::boot()} registers
 * normalize_error_response() as a `rest_request_after_callbacks` filter
 * once, independently of register_routes() below - see that method's
 * docblock for why the two are deliberately decoupled.
 */
class Controller {

	public const NAMESPACE_NAME = 'flytedesk/v1';
	public const ROUTE_BASE     = '/posts';

	private Resolver $seo_resolver;

	private VerificationTracker $verification_tracker;

	public function __construct( Resolver $seo_resolver, VerificationTracker $verification_tracker ) {
		$this->seo_resolver         = $seo_resolver;
		$this->verification_tracker = $verification_tracker;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_NAME,
			self::ROUTE_BASE,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_post' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_NAME,
			self::ROUTE_BASE . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_post' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_post' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Shared permission gate for every route. Application Passwords
	 * authentication has already run by the time this executes (it's part
	 * of WordPress core's REST auth pipeline); we only need to check the
	 * resulting user's capability.
	 *
	 * @return true|WP_Error
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $request is required by the WP_REST_Server permission_callback signature; this implementation doesn't need the request body, only the authenticated user set up earlier in the request lifecycle.
	public function check_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'unauthorized',
				__( 'Authentication required. Use a WordPress Application Password.', 'flytedesk-sponsored-content' ),
				array( 'status' => 401 )
			);
		}

		/*
		 * `edit_posts` covers the documented manual setup path (a human
		 * generates their own Application Password from an Author/Editor/
		 * Administrator account). Capabilities::MANAGE_SPONSORED_CONTENT
		 * covers the automatically-provisioned "flytebot" user, which holds
		 * only that one narrow capability and nothing else - either is
		 * sufficient, since both represent "this user is meant to manage
		 * sponsored content", just via different setup paths.
		 */
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( Capabilities::MANAGE_SPONSORED_CONTENT ) ) {
			return new WP_Error(
				'unauthorized',
				__( 'The authenticated user does not have permission to manage sponsored content.', 'flytedesk-sponsored-content' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_post( WP_REST_Request $request ) {
		$data = $this->validate_body( $request );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$html = wp_kses_post( Converter::to_html( $data['content'] ) );

		$postarr = array(
			'post_type'    => PostType::POST_TYPE,
			'post_title'   => $data['title'],
			'post_excerpt' => $this->resolve_excerpt( $data ),
			'post_content' => $html,
			'post_status'  => 'publish',
		);

		if ( '' !== $data['seo']['slug'] ) {
			$postarr['post_name'] = wp_unique_post_slug(
				$data['seo']['slug'],
				0,
				'publish',
				PostType::POST_TYPE,
				0
			);
		}

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'create_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
		}

		$this->seo_resolver->resolve()->write( $post_id, $data['seo'] );
		$this->verification_tracker->mark_create_verified();

		return new WP_REST_Response( $this->present( $post_id ), 201 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_post_item( WP_REST_Request $request ) {
		$post = $this->find_post( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return new WP_REST_Response( $this->present( $post->ID ), 200 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_post( WP_REST_Request $request ) {
		$post = $this->find_post( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$data = $this->validate_body( $request );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$html = wp_kses_post( Converter::to_html( $data['content'] ) );

		$postarr = array(
			'ID'           => $post->ID,
			'post_title'   => $data['title'],
			'post_excerpt' => $this->resolve_excerpt( $data ),
			'post_content' => $html,
		);

		if ( '' !== $data['seo']['slug'] ) {
			$postarr['post_name'] = wp_unique_post_slug(
				$data['seo']['slug'],
				$post->ID,
				$post->post_status,
				PostType::POST_TYPE,
				$post->post_parent
			);
		}

		$result = wp_update_post( $postarr, true );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'update_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}

		$this->seo_resolver->resolve()->write( $post->ID, $data['seo'] );
		$this->verification_tracker->mark_update_verified();

		return new WP_REST_Response( $this->present( $post->ID ), 200 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_post( WP_REST_Request $request ) {
		$post = $this->find_post( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$trashed = wp_trash_post( $post->ID );

		if ( ! $trashed ) {
			return new WP_Error(
				'delete_failed',
				__( 'The post could not be moved to the trash.', 'flytedesk-sponsored-content' ),
				array( 'status' => 500 )
			);
		}

		$this->verification_tracker->mark_delete_verified();

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Look up a fdsc_sponsored_post by ID, returning a 404 WP_Error when it
	 * doesn't exist (or exists but is a different post type).
	 *
	 * @return WP_Post|WP_Error
	 */
	private function find_post( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || PostType::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'not_found',
				__( 'No sponsored-content post exists with that ID.', 'flytedesk-sponsored-content' ),
				array( 'status' => 404 )
			);
		}

		return $post;
	}

	/**
	 * Validate and sanitize a create/update request body.
	 *
	 * @return array|WP_Error
	 */
	private function validate_body( WP_REST_Request $request ) {
		$raw_body = $request->get_body();

		$params = array();
		if ( '' !== trim( (string) $raw_body ) ) {
			$decoded = json_decode( (string) $raw_body, true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				return new WP_Error(
					'invalid_json',
					__( 'Request body must be a valid JSON object.', 'flytedesk-sponsored-content' ),
					array( 'status' => 400 )
				);
			}

			$params = $decoded;
		}

		$errors = array();

		$title = isset( $params['title'] ) ? trim( (string) $params['title'] ) : '';
		if ( '' === $title ) {
			$errors[] = __( 'title is required.', 'flytedesk-sponsored-content' );
		}

		$content = isset( $params['content'] ) ? (string) $params['content'] : '';
		if ( '' === trim( $content ) ) {
			$errors[] = __( 'content is required.', 'flytedesk-sponsored-content' );
		}

		$description = isset( $params['description'] ) ? (string) $params['description'] : '';

		$seo_raw = array();
		if ( isset( $params['seo'] ) ) {
			if ( ! is_array( $params['seo'] ) ) {
				$errors[] = __( 'seo must be an object.', 'flytedesk-sponsored-content' );
			} else {
				$seo_raw = $params['seo'];
			}
		}

		$og_raw = array();
		if ( isset( $seo_raw['og'] ) ) {
			if ( ! is_array( $seo_raw['og'] ) ) {
				$errors[] = __( 'seo.og must be an object.', 'flytedesk-sponsored-content' );
			} else {
				$og_raw = $seo_raw['og'];
			}
		}

		if ( isset( $og_raw['image'] ) && '' !== $og_raw['image'] && false === filter_var( $og_raw['image'], FILTER_VALIDATE_URL ) ) {
			$errors[] = __( 'seo.og.image must be a valid URL.', 'flytedesk-sponsored-content' );
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'validation_failed', implode( ' ', $errors ), array( 'status' => 400 ) );
		}

		$meta_keywords = $seo_raw['meta_keywords'] ?? '';
		if ( is_array( $meta_keywords ) ) {
			$meta_keywords = implode( ',', array_map( 'sanitize_text_field', $meta_keywords ) );
		} else {
			$meta_keywords = sanitize_text_field( (string) $meta_keywords );
		}

		$seo = array(
			'meta_title'       => isset( $seo_raw['meta_title'] ) ? sanitize_text_field( $seo_raw['meta_title'] ) : '',
			'meta_description' => isset( $seo_raw['meta_description'] ) ? sanitize_text_field( $seo_raw['meta_description'] ) : '',
			'slug'             => isset( $seo_raw['slug'] ) ? sanitize_title( $seo_raw['slug'] ) : '',
			'meta_keywords'    => $meta_keywords,
			'og'               => array(
				'title'       => isset( $og_raw['title'] ) ? sanitize_text_field( $og_raw['title'] ) : '',
				'description' => isset( $og_raw['description'] ) ? sanitize_text_field( $og_raw['description'] ) : '',
				'image'       => isset( $og_raw['image'] ) ? esc_url_raw( $og_raw['image'] ) : '',
				'type'        => isset( $og_raw['type'] ) ? sanitize_text_field( $og_raw['type'] ) : '',
			),
		);

		return array(
			'title'       => sanitize_text_field( $title ),
			'description' => sanitize_text_field( $description ),
			'content'     => $content,
			'seo'         => $seo,
		);
	}

	/**
	 * The post excerpt is a plain WordPress field, not something any SEO
	 * adapter owns - it's set here once, for every adapter, rather than
	 * duplicated across adapter implementations. Falls back to the SEO
	 * meta description when no top-level description was supplied, since
	 * that's the only place a description-like value can come from.
	 */
	private function resolve_excerpt( array $data ): string {
		return '' !== $data['description'] ? $data['description'] : $data['seo']['meta_description'];
	}

	/**
	 * Build the response body shared by create/read/update: current stored
	 * title, description, SEO fields (as read back from whichever adapter is
	 * active), and permalink.
	 */
	private function present( int $post_id ): array {
		$post    = get_post( $post_id );
		$adapter = $this->seo_resolver->resolve();
		$seo     = $adapter->read( $post_id );

		$seo['slug'] = $post->post_name;

		return array(
			'id'          => $post_id,
			'title'       => get_the_title( $post_id ),
			'description' => $post->post_excerpt,
			'permalink'   => get_permalink( $post_id ),
			'status'      => $post->post_status,
			'seo'         => $seo,
		);
	}

	/**
	 * Reshape any WP_Error produced by permission_callback or a route
	 * callback under our namespace into the plugin's { "error": {...} }
	 * response contract, using the HTTP status carried in the error's data.
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error $response Result to
	 *                                                              filter.
	 * @param array<string, mixed>                        $handler  Route
	 *                                                              handler
	 *                                                              config.
	 * @return WP_REST_Response|WP_HTTP_Response|WP_Error
	 */
	public function normalize_error_response( $response, $handler, WP_REST_Request $request ) {
		if ( 0 !== strpos( $request->get_route(), '/' . self::NAMESPACE_NAME . '/' ) ) {
			return $response;
		}

		if ( ! is_wp_error( $response ) ) {
			return $response;
		}

		$error_data = $response->get_error_data();
		$status     = ( is_array( $error_data ) && isset( $error_data['status'] ) ) ? (int) $error_data['status'] : 500;

		return new WP_REST_Response(
			array(
				'error' => array(
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
				),
			),
			$status
		);
	}
}
