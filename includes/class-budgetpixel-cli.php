<?php
/**
 * WP-CLI: bulk-fill missing featured images.
 *
 *   wp budgetpixel featured --missing --dry-run
 *   wp budgetpixel featured --missing --limit=50 --model=flux-2-pro --aspect=16:9 --yes
 *
 * @package BudgetPixel
 */

defined( 'ABSPATH' ) || exit;

class BudgetPixel_CLI {

	/**
	 * Generate featured images for posts that have none.
	 *
	 * ## OPTIONS
	 *
	 * [--missing]
	 * : Only posts without a featured image. This is the default (and only) mode; the flag exists so the command reads well.
	 *
	 * [--post-type=<type>]
	 * : Post type to scan. Default: post.
	 *
	 * [--limit=<n>]
	 * : Maximum posts to process. Default: 20.
	 *
	 * [--model=<slug>]
	 * : Image model. Default: the plugin's default model.
	 *
	 * [--aspect=<ratio>]
	 * : Aspect ratio, e.g. 16:9. Default: the plugin's default.
	 *
	 * [--status=<status>]
	 * : Post status to include. Default: publish.
	 *
	 * [--dry-run]
	 * : List the posts and the total estimated credits without generating anything.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp budgetpixel featured --dry-run
	 *     wp budgetpixel featured --limit=10 --yes
	 *
	 * @subcommand featured
	 */
	public function featured( $args, $assoc ) {
		$post_type = isset( $assoc['post-type'] ) ? sanitize_key( $assoc['post-type'] ) : 'post';
		$limit     = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 20;
		$model     = isset( $assoc['model'] ) ? BudgetPixel_REST::model_slug( $assoc['model'] ) : BudgetPixel_Settings::get( 'default_model' );
		$aspect    = isset( $assoc['aspect'] ) ? BudgetPixel_REST::aspect( $assoc['aspect'] ) : BudgetPixel_Settings::get( 'default_aspect' );
		$status    = isset( $assoc['status'] ) ? sanitize_key( $assoc['status'] ) : 'publish';
		$dry       = ! empty( $assoc['dry-run'] );

		$key = BudgetPixel_API_Client::api_key();
		if ( is_wp_error( $key ) ) {
			WP_CLI::error( $key->get_error_message() );
		}

		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => $status,
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_thumbnail_id',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		if ( empty( $posts ) ) {
			WP_CLI::success( 'No posts without a featured image.' );
			return;
		}

		$est = BudgetPixel_API_Client::estimate( $model, $aspect, BudgetPixel_Media::default_prompt( $posts[0] ) );
		if ( is_wp_error( $est ) ) {
			WP_CLI::error( $est->get_error_message() );
		}
		$per   = (int) $est['credits'];
		$total = $per * count( $posts );
		WP_CLI::log( sprintf( '%d post(s) without a featured image. Model %s at %s → %s credits per image, %s credits total%s.', count( $posts ), $model, $aspect, number_format( $per ), number_format( $total ), empty( $est['exact'] ) ? ' (estimate)' : '' ) );
		foreach ( $posts as $p ) {
			WP_CLI::log( sprintf( '  #%d  %s', $p->ID, mb_strimwidth( get_the_title( $p ), 0, 70, '…' ) ) );
		}
		$bal = BudgetPixel_API_Client::credits();
		if ( ! is_wp_error( $bal ) ) {
			WP_CLI::log( sprintf( 'Balance: %s credits available.', number_format( (int) $bal['total_available'] ) ) );
			if ( (int) $bal['total_available'] < $total ) {
				WP_CLI::warning( 'Balance is below the total; later posts will fail with insufficient credits.' );
			}
		}
		if ( $dry ) {
			WP_CLI::success( 'Dry run — nothing generated.' );
			return;
		}
		if ( empty( $assoc['yes'] ) ) {
			WP_CLI::confirm( sprintf( 'Spend up to %s credits generating %d image(s)?', number_format( $total ), count( $posts ) ) );
		}

		$done = 0;
		foreach ( $posts as $p ) {
			$prompt = BudgetPixel_Media::default_prompt( $p );
			WP_CLI::log( sprintf( '#%d generating…', $p->ID ) );
			$res = BudgetPixel_API_Client::create_image( $model, $prompt, $aspect );
			if ( is_wp_error( $res ) || empty( $res['id'] ) ) {
				WP_CLI::warning( sprintf( '#%d skipped: %s', $p->ID, is_wp_error( $res ) ? $res->get_error_message() : 'no job id' ) );
				continue;
			}
			set_transient( 'budgetpixel_prompt_' . md5( $res['id'] ), $prompt, DAY_IN_SECONDS );
			$job = BudgetPixel_API_Client::wait_for_image( $res['id'] );
			if ( is_wp_error( $job ) || 'succeeded' !== $job['status'] ) {
				WP_CLI::warning( sprintf( '#%d failed: %s', $p->ID, is_wp_error( $job ) ? $job->get_error_message() : ( ! empty( $job['error'] ) ? $job['error'] : $job['status'] ) ) );
				continue;
			}
			$att = BudgetPixel_Media::attach_from_job( $res['id'], 0, $p->ID, mb_substr( $prompt, 0, 240 ), true );
			if ( is_wp_error( $att ) ) {
				WP_CLI::warning( sprintf( '#%d image made but not attached: %s', $p->ID, $att->get_error_message() ) );
				continue;
			}
			$done++;
			WP_CLI::log( sprintf( '#%d ✓ attachment %d', $p->ID, $att['attachment_id'] ) );
		}
		WP_CLI::success( sprintf( '%d of %d featured image(s) set.', $done, count( $posts ) ) );
	}
}
