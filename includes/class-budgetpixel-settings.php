<?php
/**
 * Options + the Settings → BudgetPixel screen.
 *
 * @package BudgetPixel
 */

defined( 'ABSPATH' ) || exit;

class BudgetPixel_Settings {

	const OPTION = 'budgetpixel_settings';

	/** Aspect ratios offered in the UI (the API validates per model). */
	const ASPECTS = array( '16:9', '1:1', '4:3', '3:2', '3:4', '9:16' );

	public static function defaults() {
		return array(
			'api_key'       => '',
			'default_model' => 'flux-2-pro',
			'default_aspect'=> '16:9',
			'caption'       => 0, // Opt-in "Made with BudgetPixel" caption (WordPress.org guideline 10: off by default).
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_budgetpixel_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function menu() {
		add_options_page(
			__( 'BudgetPixel AI Images', 'budgetpixel-ai-images' ),
			__( 'BudgetPixel', 'budgetpixel-ai-images' ),
			'manage_options',
			'budgetpixel',
			array( __CLASS__, 'render' )
		);
	}

	public static function register() {
		register_setting(
			'budgetpixel',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize the whole option. An empty key field keeps the saved key so
	 * re-saving other settings never wipes it; "clear" removes it.
	 */
	public static function sanitize( $in ) {
		$old = self::all();
		$out = self::defaults();
		$in  = is_array( $in ) ? $in : array();

		$key = isset( $in['api_key'] ) ? trim( (string) $in['api_key'] ) : '';
		if ( '' === $key ) {
			$out['api_key'] = ! empty( $in['clear_key'] ) ? '' : $old['api_key'];
		} elseif ( preg_match( '/^bpx_[A-Za-z0-9_\-]{8,}$/', $key ) ) {
			$out['api_key'] = $key;
			delete_transient( 'budgetpixel_models_image' );
		} else {
			add_settings_error( 'budgetpixel', 'bad_key', __( 'That does not look like a BudgetPixel API key (they start with bpx_).', 'budgetpixel-ai-images' ) );
			$out['api_key'] = $old['api_key'];
		}

		// Model slugs are lowercase with dashes and dots (flux-1.1-pro); sanitize_key() would strip the dots.
		$out['default_model']  = isset( $in['default_model'] ) ? preg_replace( '/[^a-z0-9.\-]/', '', strtolower( (string) $in['default_model'] ) ) : $old['default_model'];
		if ( '' === $out['default_model'] ) {
			$out['default_model'] = $old['default_model'];
		}
		$out['default_aspect'] = ( isset( $in['default_aspect'] ) && in_array( $in['default_aspect'], self::ASPECTS, true ) ) ? $in['default_aspect'] : $old['default_aspect'];
		$out['caption']        = empty( $in['caption'] ) ? 0 : 1;
		return $out;
	}

	/** "Test connection" button: fetches the balance and reports it. */
	public static function handle_test() {
		check_admin_referer( 'budgetpixel_test' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'budgetpixel-ai-images' ) );
		}
		$res = BudgetPixel_API_Client::credits();
		if ( is_wp_error( $res ) ) {
			set_transient( 'budgetpixel_notice', array( 'type' => 'error', 'text' => $res->get_error_message() ), 60 );
		} else {
			set_transient(
				'budgetpixel_notice',
				array(
					'type' => 'success',
					/* translators: %s: formatted credit balance */
					'text' => sprintf( __( 'Connected. %s credits available.', 'budgetpixel-ai-images' ), number_format_i18n( (int) $res['total_available'] ) ),
				),
				60
			);
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=budgetpixel' ) );
		exit;
	}

	public static function notices() {
		$n = get_transient( 'budgetpixel_notice' );
		if ( ! $n || ! is_array( $n ) ) {
			return;
		}
		delete_transient( 'budgetpixel_notice' );
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $n['type'] ), esc_html( $n['text'] ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s        = self::all();
		$has_key  = '' !== $s['api_key'];
		$models   = $has_key ? BudgetPixel_API_Client::image_models() : array();
		$key_hint = $has_key ? substr( $s['api_key'], 0, 9 ) . '…' . substr( $s['api_key'], -4 ) : '';
		$keys_url = 'https://budgetpixel.com/developers?utm_source=wordpress-plugin&utm_medium=plugin&utm_campaign=settings';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BudgetPixel AI Images', 'budgetpixel-ai-images' ); ?></h1>
			<p>
				<?php esc_html_e( 'Generate featured and inline images inside the editor with 100+ AI models. Images are billed in credits from your BudgetPixel plan; the plugin itself is free.', 'budgetpixel-ai-images' ); ?>
				<?php esc_html_e( 'A paid BudgetPixel plan is required for API access.', 'budgetpixel-ai-images' ); ?>
				<a href="https://budgetpixel.com/data/ai-generation-price-index?utm_source=wordpress-plugin" target="_blank" rel="noopener"><?php esc_html_e( 'See what each model costs per image.', 'budgetpixel-ai-images' ); ?></a>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'budgetpixel' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bp-api-key"><?php esc_html_e( 'API key', 'budgetpixel-ai-images' ); ?></label></th>
						<td>
							<input type="password" id="bp-api-key" name="<?php echo esc_attr( self::OPTION ); ?>[api_key]" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $has_key ? esc_attr( $key_hint ) : 'bpx_live_…'; ?>">
							<?php if ( $has_key ) : ?>
								<label style="margin-left:8px"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[clear_key]" value="1"> <?php esc_html_e( 'Remove saved key', 'budgetpixel-ai-images' ); ?></label>
							<?php endif; ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to the BudgetPixel developer console */
									esc_html__( 'Create a key in the %s (any paid plan). Leave blank to keep the saved key.', 'budgetpixel-ai-images' ),
									'<a href="' . esc_url( $keys_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'BudgetPixel developer console', 'budgetpixel-ai-images' ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bp-model"><?php esc_html_e( 'Default model', 'budgetpixel-ai-images' ); ?></label></th>
						<td>
							<?php if ( is_array( $models ) && ! empty( $models ) ) : ?>
								<select id="bp-model" name="<?php echo esc_attr( self::OPTION ); ?>[default_model]">
									<?php foreach ( $models as $m ) : ?>
										<option value="<?php echo esc_attr( $m['name'] ); ?>" <?php selected( $s['default_model'], $m['name'] ); ?>>
											<?php echo esc_html( $m['name'] ); ?><?php echo $m['credits'] ? esc_html( ' — ' . number_format_i18n( $m['credits'] ) . ' ' . __( 'credits/image', 'budgetpixel-ai-images' ) ) : ''; ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="text" id="bp-model" name="<?php echo esc_attr( self::OPTION ); ?>[default_model]" value="<?php echo esc_attr( $s['default_model'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Save a valid API key to pick from the live model list.', 'budgetpixel-ai-images' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bp-aspect"><?php esc_html_e( 'Default aspect ratio', 'budgetpixel-ai-images' ); ?></label></th>
						<td>
							<select id="bp-aspect" name="<?php echo esc_attr( self::OPTION ); ?>[default_aspect]">
								<?php foreach ( self::ASPECTS as $a ) : ?>
									<option value="<?php echo esc_attr( $a ); ?>" <?php selected( $s['default_aspect'], $a ); ?>><?php echo esc_html( $a ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( '16:9 suits most featured images. Some models support only a subset of ratios; the editor shows the API message if one is rejected.', 'budgetpixel-ai-images' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Image caption', 'budgetpixel-ai-images' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[caption]" value="1" <?php checked( 1, (int) $s['caption'] ); ?>> <?php esc_html_e( 'Add a “Made with BudgetPixel” caption to generated images', 'budgetpixel-ai-images' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off by default. Nothing is ever added to your public site unless you turn this on.', 'budgetpixel-ai-images' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php if ( $has_key ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:-8px">
					<input type="hidden" name="action" value="budgetpixel_test">
					<?php wp_nonce_field( 'budgetpixel_test' ); ?>
					<?php submit_button( __( 'Test connection', 'budgetpixel-ai-images' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
			<hr>
			<h2><?php esc_html_e( 'How to use', 'budgetpixel-ai-images' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Open any post in the block editor and find “BudgetPixel AI Image” in the settings sidebar.', 'budgetpixel-ai-images' ); ?></li>
				<li><?php esc_html_e( 'The prompt is pre-filled from the title and excerpt — edit it, pick a model, and check the credit cost shown before you generate.', 'budgetpixel-ai-images' ); ?></li>
				<li><?php esc_html_e( 'Set the result as the featured image or insert it into the post. Alt text defaults to the prompt.', 'budgetpixel-ai-images' ); ?></li>
				<li><code>wp budgetpixel featured --missing --dry-run</code> — <?php esc_html_e( 'preview the cost of filling every missing featured image; drop --dry-run to run it.', 'budgetpixel-ai-images' ); ?></li>
			</ol>
			<p class="description">
				<?php
				printf(
					/* translators: 1: privacy policy link, 2: developer terms link */
					esc_html__( 'This plugin sends your prompt, model choice and API key to api.budgetpixel.com to generate images. See the %1$s and %2$s.', 'budgetpixel-ai-images' ),
					'<a href="https://budgetpixel.com/privacy" target="_blank" rel="noopener">' . esc_html__( 'privacy policy', 'budgetpixel-ai-images' ) . '</a>',
					'<a href="https://budgetpixel.com/developer-terms" target="_blank" rel="noopener">' . esc_html__( 'developer API terms', 'budgetpixel-ai-images' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
