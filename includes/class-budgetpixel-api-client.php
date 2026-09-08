<?php
/**
 * Thin client for the BudgetPixel developer API (https://api.budgetpixel.com/v1).
 *
 * All calls happen server-side so the API key never reaches the browser.
 *
 * @package BudgetPixel
 */

defined( 'ABSPATH' ) || exit;

class BudgetPixel_API_Client {

	/** Terminal job states. */
	const DONE_STATES = array( 'succeeded', 'failed', 'timeout' );

	/**
	 * @return string API base without trailing slash.
	 */
	public static function base() {
		$base = apply_filters( 'budgetpixel_api_base', BUDGETPIXEL_API_BASE );
		return rtrim( $base, '/' );
	}

	/**
	 * @return string|WP_Error The saved key, or an error when none is configured.
	 */
	public static function api_key() {
		$key = BudgetPixel_Settings::get( 'api_key' );
		if ( '' === $key ) {
			return new WP_Error(
				'budgetpixel_no_key',
				__( 'Add your BudgetPixel API key under Settings → BudgetPixel first.', 'budgetpixel-ai-images' ),
				array( 'status' => 400 )
			);
		}
		return $key;
	}

	/**
	 * Perform a request against the API.
	 *
	 * @param string     $method  GET|POST.
	 * @param string     $path    Path under the base, e.g. "/models".
	 * @param array|null $body    JSON body for POST.
	 * @param int        $timeout Seconds.
	 * @return array|WP_Error Decoded JSON on 2xx; WP_Error carrying the API's message otherwise.
	 */
	public static function request( $method, $path, $body = null, $timeout = 30 ) {
		$key = self::api_key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
				'User-Agent'    => 'BudgetPixel-WordPress/' . BUDGETPIXEL_VERSION . ' (+' . home_url( '/' ) . ')',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$res = wp_remote_request( self::base() . $path, $args );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'budgetpixel_network', $res->get_error_message(), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 200 && $code < 300 ) {
			return is_array( $json ) ? $json : array();
		}
		// API errors are {"error":{"type","code","message"}}.
		$message = isset( $json['error']['message'] ) ? $json['error']['message'] : '';
		$api_code = isset( $json['error']['code'] ) ? $json['error']['code'] : '';
		if ( '' === $message ) {
			switch ( $code ) {
				case 401:
					$message = __( 'The API key was rejected. Check it under Settings → BudgetPixel.', 'budgetpixel-ai-images' );
					break;
				case 402:
					$message = __( 'Not enough credits on your BudgetPixel account.', 'budgetpixel-ai-images' );
					break;
				case 429:
					$message = __( 'Rate limited by the API — try again in a moment.', 'budgetpixel-ai-images' );
					break;
				default:
					/* translators: %d: HTTP status code */
					$message = sprintf( __( 'The BudgetPixel API returned HTTP %d.', 'budgetpixel-ai-images' ), $code );
			}
		}
		return new WP_Error( 'budgetpixel_api_' . ( $api_code ? $api_code : $code ), $message, array( 'status' => $code ) );
	}

	/**
	 * Image models, cached for an hour. Each entry: name, credits.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|WP_Error
	 */
	public static function image_models( $force = false ) {
		$cached = $force ? false : get_transient( 'budgetpixel_models_image' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}
		$res = self::request( 'GET', '/models?type=image' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$models = array();
		foreach ( isset( $res['data'] ) ? $res['data'] : array() as $m ) {
			if ( empty( $m['name'] ) ) {
				continue;
			}
			$models[] = array(
				'name'    => sanitize_text_field( $m['name'] ),
				'credits' => isset( $m['credits_per_generation'] ) ? (int) $m['credits_per_generation'] : 0,
			);
		}
		usort(
			$models,
			function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);
		set_transient( 'budgetpixel_models_image', $models, HOUR_IN_SECONDS );
		return $models;
	}

	/**
	 * Credit balance.
	 *
	 * @return array|WP_Error {total_available, monthly_remaining, ...}
	 */
	public static function credits() {
		return self::request( 'GET', '/account/credits' );
	}

	/**
	 * Cost estimate for one image. /v1/cost validates the same params as the
	 * generate endpoint, so a prompt is required; the text does not affect price.
	 *
	 * @param string $model        Model slug.
	 * @param string $aspect_ratio Aspect ratio, e.g. "16:9".
	 * @param string $prompt       Prompt (any non-empty text).
	 * @return array|WP_Error {credits, exact}
	 */
	public static function estimate( $model, $aspect_ratio, $prompt = '' ) {
		$body = array(
			'model'      => $model,
			'prompt'     => '' !== trim( (string) $prompt ) ? $prompt : 'cost estimate',
			'num_images' => 1,
		);
		if ( '' !== $aspect_ratio ) {
			$body['aspect_ratio'] = $aspect_ratio;
		}
		return self::request( 'POST', '/cost', $body );
	}

	/**
	 * Start an image job. Returns {id, status, model}.
	 *
	 * @param string $model        Model slug.
	 * @param string $prompt       Prompt.
	 * @param string $aspect_ratio Aspect ratio or ''.
	 * @return array|WP_Error
	 */
	public static function create_image( $model, $prompt, $aspect_ratio ) {
		$body = array(
			'prompt'     => $prompt,
			'num_images' => 1,
		);
		if ( '' !== $aspect_ratio ) {
			$body['aspect_ratio'] = $aspect_ratio;
		}
		return self::request( 'POST', '/images/' . rawurlencode( $model ), $body, 60 );
	}

	/**
	 * Poll an image job. Returns {id, status, images:[{position,url}], error}.
	 *
	 * @param string $job_id Job id.
	 * @return array|WP_Error
	 */
	public static function image_job( $job_id ) {
		return self::request( 'GET', '/images/' . rawurlencode( $job_id ) );
	}

	/**
	 * Block until a job reaches a terminal state (WP-CLI use only).
	 *
	 * @param string $job_id      Job id.
	 * @param int    $max_seconds Give up after this long.
	 * @return array|WP_Error Final job payload.
	 */
	public static function wait_for_image( $job_id, $max_seconds = 240 ) {
		$deadline = time() + $max_seconds;
		do {
			$job = self::image_job( $job_id );
			if ( is_wp_error( $job ) ) {
				return $job;
			}
			if ( in_array( isset( $job['status'] ) ? $job['status'] : '', self::DONE_STATES, true ) ) {
				return $job;
			}
			sleep( 3 );
		} while ( time() < $deadline );
		return new WP_Error( 'budgetpixel_timeout', __( 'Timed out waiting for the image.', 'budgetpixel-ai-images' ), array( 'status' => 504 ) );
	}
}
