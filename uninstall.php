<?php
/**
 * Remove every option and transient the plugin created. Generated images stay
 * in the Media Library — they are the user's content.
 *
 * @package BudgetPixel
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'budgetpixel_settings' );
delete_transient( 'budgetpixel_models_image' );
