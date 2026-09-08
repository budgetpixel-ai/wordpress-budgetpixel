<?php
/**
 * Bring a generated image into the Media Library and attach it to a post.
 *
 * @package BudgetPixel
 */

defined( 'ABSPATH' ) || exit;

class BudgetPixel_Media {

	/**
	 * Sideload one result of a finished job into the Media Library.
	 *
	 * The job is re-read from the API so the presigned URL is fresh (they are
	 * short-lived). Stores the prompt and model as attachment meta so the
	 * origin of every image stays auditable.
	 *
	 * @param string $job_id   Job id.
	 * @param int    $position Which image of the job (0-based).
	 * @param int    $post_id  Post to attach to (0 = unattached).
	 * @param string $alt      Alt text.
	 * @param bool   $featured Set as the post's featured image.
	 * @return array|WP_Error {attachment_id, url}
	 */
	public static function attach_from_job( $job_id, $position, $post_id, $alt, $featured ) {
		$job = BudgetPixel_API_Client::image_job( $job_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( 'succeeded' !== ( isset( $job['status'] ) ? $job['status'] : '' ) ) {
			return new WP_Error( 'budgetpixel_not_ready', __( 'That image job has not finished.', 'budgetpixel-ai-images' ), array( 'status' => 409 ) );
		}
		$url = '';
		foreach ( isset( $job['images'] ) ? $job['images'] : array() as $img ) {
			if ( (int) ( isset( $img['position'] ) ? $img['position'] : 0 ) === (int) $position && ! empty( $img['url'] ) ) {
				$url = $img['url'];
				break;
			}
		}
		if ( '' === $url && ! empty( $job['images'][0]['url'] ) ) {
			$url = $job['images'][0]['url'];
		}
		if ( '' === $url ) {
			return new WP_Error( 'budgetpixel_no_image', __( 'The job finished without an image.', 'budgetpixel-ai-images' ), array( 'status' => 500 ) );
		}
		$model  = isset( $job['model'] ) ? sanitize_text_field( $job['model'] ) : 'budgetpixel';
		$prompt = get_transient( 'budgetpixel_prompt_' . md5( $job_id ) );
		return self::sideload( $url, $post_id, $alt, $featured, $model, is_string( $prompt ) ? $prompt : '', $job_id );
	}

	/**
	 * Download a URL into the uploads dir and register it as an attachment.
	 */
	protected static function sideload( $url, $post_id, $alt, $featured, $model, $prompt, $job_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Error( 'budgetpixel_download', $tmp->get_error_message(), array( 'status' => 502 ) );
		}
		$type = wp_get_image_mime( $tmp );
		$ext  = 'png';
		if ( 'image/jpeg' === $type ) {
			$ext = 'jpg';
		} elseif ( 'image/webp' === $type ) {
			$ext = 'webp';
		}
		if ( ! $type ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'budgetpixel_not_image', __( 'The downloaded file is not an image.', 'budgetpixel-ai-images' ), array( 'status' => 502 ) );
		}
		$name = sanitize_file_name( 'budgetpixel-' . $model . '-' . gmdate( 'Ymd-His' ) . '.' . $ext );
		$file = array(
			'name'     => $name,
			'type'     => $type,
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => filesize( $tmp ),
		);
		$post_data = array(
			'post_title' => wp_trim_words( '' !== $alt ? $alt : $model, 12, '' ),
		);
		if ( BudgetPixel_Settings::get( 'caption' ) ) {
			// Opt-in only (WordPress.org guideline 10): never on by default.
			$post_data['post_excerpt'] = __( 'Made with BudgetPixel', 'budgetpixel-ai-images' );
		}
		$attachment_id = media_handle_sideload( $file, $post_id ? (int) $post_id : 0, null, $post_data );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'budgetpixel_sideload', $attachment_id->get_error_message(), array( 'status' => 500 ) );
		}
		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags( $alt ) );
		}
		update_post_meta( $attachment_id, '_budgetpixel_model', $model );
		update_post_meta( $attachment_id, '_budgetpixel_job_id', sanitize_text_field( $job_id ) );
		if ( '' !== $prompt ) {
			update_post_meta( $attachment_id, '_budgetpixel_prompt', wp_strip_all_tags( $prompt ) );
		}
		if ( $featured && $post_id ) {
			set_post_thumbnail( (int) $post_id, $attachment_id );
		}
		return array(
			'attachment_id' => (int) $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
		);
	}

	/**
	 * Default prompt for a post: its title plus the excerpt or a slice of content.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function default_prompt( $post ) {
		$title   = wp_strip_all_tags( get_the_title( $post ) );
		$excerpt = has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 40, '' );
		$excerpt = trim( wp_strip_all_tags( $excerpt ) );
		$prompt  = $title;
		if ( '' !== $excerpt ) {
			$prompt .= '. ' . $excerpt;
		}
		// Same style hint as the editor panel: steer models away from painting
		// the headline as lettering. Filterable for sites with their own house style.
		$prompt = rtrim( $prompt, ". \t\n" ) . '. ' . __( 'Editorial illustration for a blog post, no text or lettering.', 'budgetpixel-ai-images' );
		return trim( (string) apply_filters( 'budgetpixel_default_prompt', $prompt, $post ) );
	}
}
