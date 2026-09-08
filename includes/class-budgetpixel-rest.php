<?php
/**
 * REST proxy: the editor talks to these routes; these routes talk to the API.
 * The key never leaves the server. Namespace: budgetpixel/v1.
 *
 * @package BudgetPixel
 */

defined( 'ABSPATH' ) || exit;

class BudgetPixel_REST {

	const NS = 'budgetpixel/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** Anyone who can upload media may generate; post-bound actions also need edit rights on that post. */
	public static function can_use() {
		return current_user_can( 'upload_files' );
	}

	public static function can_edit_post( $request ) {
		if ( ! self::can_use() ) {
			return false;
		}
		$post_id = (int) $request->get_param( 'post_id' );
		return 0 === $post_id || current_user_can( 'edit_post', $post_id );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/models',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'callback'            => function ( $req ) {
					return self::out( BudgetPixel_API_Client::image_models( (bool) $req->get_param( 'refresh' ) ) );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/credits',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'callback'            => function () {
					return self::out( BudgetPixel_API_Client::credits() );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/cost',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'args'                => array(
					'model'        => array( 'required' => true, 'sanitize_callback' => array( __CLASS__, 'model_slug' ) ),
					'aspect_ratio' => array( 'default' => '', 'sanitize_callback' => array( __CLASS__, 'aspect' ) ),
					'prompt'       => array( 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
				),
				'callback'            => function ( $req ) {
					return self::out( BudgetPixel_API_Client::estimate( $req['model'], $req['aspect_ratio'], $req['prompt'] ) );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/generate',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
				'args'                => array(
					'prompt'       => array( 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
					'model'        => array( 'required' => true, 'sanitize_callback' => array( __CLASS__, 'model_slug' ) ),
					'aspect_ratio' => array( 'default' => '', 'sanitize_callback' => array( __CLASS__, 'aspect' ) ),
					'post_id'      => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
				),
				'callback'            => function ( $req ) {
					$prompt = trim( $req['prompt'] );
					if ( mb_strlen( $prompt ) < 3 ) {
						return new WP_Error( 'budgetpixel_prompt', __( 'Write a prompt first.', 'budgetpixel-ai-images' ), array( 'status' => 400 ) );
					}
					$res = BudgetPixel_API_Client::create_image( $req['model'], $prompt, $req['aspect_ratio'] );
					if ( is_wp_error( $res ) ) {
						return $res;
					}
					if ( ! empty( $res['id'] ) ) {
						// Remembered so the attachment can record which prompt made it.
						set_transient( 'budgetpixel_prompt_' . md5( $res['id'] ), $prompt, DAY_IN_SECONDS );
					}
					return rest_ensure_response(
						array(
							'job_id' => isset( $res['id'] ) ? $res['id'] : '',
							'status' => isset( $res['status'] ) ? $res['status'] : 'pending',
						)
					);
				},
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/(?P<job_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'callback'            => function ( $req ) {
					$job = BudgetPixel_API_Client::image_job( $req['job_id'] );
					if ( is_wp_error( $job ) ) {
						return $job;
					}
					$images = array();
					foreach ( isset( $job['images'] ) ? $job['images'] : array() as $img ) {
						$images[] = array(
							'position' => isset( $img['position'] ) ? (int) $img['position'] : 0,
							'url'      => isset( $img['url'] ) ? esc_url_raw( $img['url'] ) : '',
						);
					}
					return rest_ensure_response(
						array(
							'status' => isset( $job['status'] ) ? $job['status'] : 'pending',
							'images' => $images,
							'error'  => isset( $job['error'] ) ? (string) $job['error'] : '',
						)
					);
				},
			)
		);
		register_rest_route(
			self::NS,
			'/attach',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
				'args'                => array(
					'job_id'   => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'position' => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
					'post_id'  => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
					'alt'      => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'featured' => array( 'default' => false ),
				),
				'callback'            => function ( $req ) {
					return self::out(
						BudgetPixel_Media::attach_from_job(
							$req['job_id'],
							(int) $req['position'],
							(int) $req['post_id'],
							$req['alt'],
							rest_sanitize_boolean( $req['featured'] )
						)
					);
				},
			)
		);
	}

	public static function model_slug( $v ) {
		return preg_replace( '/[^a-z0-9.\-]/', '', strtolower( (string) $v ) );
	}

	public static function aspect( $v ) {
		$v = trim( (string) $v );
		return preg_match( '/^\d{1,2}:\d{1,2}$/', $v ) ? $v : '';
	}

	protected static function out( $res ) {
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}
}
