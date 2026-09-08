<?php
/**
 * Loads the block-editor sidebar panel (assets/js/editor.js — plain JS, no build step).
 *
 * @package BudgetPixel
 */

defined( 'ABSPATH' ) || exit;

class BudgetPixel_Editor {

	public static function init() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		wp_enqueue_script(
			'budgetpixel-editor',
			BUDGETPIXEL_PLUGIN_URL . 'assets/js/editor.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-blocks', 'wp-block-editor' ),
			BUDGETPIXEL_VERSION,
			true
		);
		wp_enqueue_style( 'budgetpixel-editor', BUDGETPIXEL_PLUGIN_URL . 'assets/css/editor.css', array(), BUDGETPIXEL_VERSION );
		$s = BudgetPixel_Settings::all();
		wp_add_inline_script(
			'budgetpixel-editor',
			'window.BudgetPixelEditor = ' . wp_json_encode(
				array(
					'hasKey'        => '' !== $s['api_key'],
					'defaultModel'  => $s['default_model'],
					'defaultAspect' => $s['default_aspect'],
					'aspects'       => BudgetPixel_Settings::ASPECTS,
					'settingsUrl'   => admin_url( 'options-general.php?page=budgetpixel' ),
					'pricingUrl'    => 'https://budgetpixel.com/data/ai-generation-price-index?utm_source=wordpress-plugin',
				)
			) . ';',
			'before'
		);
		wp_set_script_translations( 'budgetpixel-editor', 'budgetpixel-ai-images' );
	}
}
