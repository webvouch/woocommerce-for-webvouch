<?php
/**
 * Plugin Name:       WebVouch for WooCommerce
 * Plugin URI:        https://webvouch.com/integrations/woocommerce
 * Description:       Sends WebVouch service-review invitations automatically from WooCommerce orders.
 * Version:           0.3.0
 * Update URI:        https://webvouch.com/integrations/woocommerce
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * WC requires at least: 8.6
 * WC tested up to:   11.0
 * Author:            WebVouch
 * Author URI:        https://webvouch.com/
 * Text Domain:       webvouch-for-woocommerce
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WEBVOUCH_WC_VERSION', '0.3.0' );
define( 'WEBVOUCH_WC_FILE', __FILE__ );
define( 'WEBVOUCH_WC_DIR', plugin_dir_path( __FILE__ ) );

require_once WEBVOUCH_WC_DIR . 'includes/class-http-result.php';
require_once WEBVOUCH_WC_DIR . 'includes/class-settings.php';
require_once WEBVOUCH_WC_DIR . 'includes/class-widget-state.php';
require_once WEBVOUCH_WC_DIR . 'includes/class-logger.php';
require_once WEBVOUCH_WC_DIR . 'includes/class-api-client.php';
require_once WEBVOUCH_WC_DIR . 'includes/class-retry-policy.php';
require_once WEBVOUCH_WC_DIR . 'includes/class-action-scheduler-queue.php';
require_once WEBVOUCH_WC_DIR . 'includes/class-historical-sync.php';
require_once WEBVOUCH_WC_DIR . 'includes/class-connection.php';
require_once WEBVOUCH_WC_DIR . 'includes/class-updater.php';
require_once WEBVOUCH_WC_DIR . 'includes/class-order-state.php';
require_once WEBVOUCH_WC_DIR . 'includes/class-order-status-panel.php';
require_once WEBVOUCH_WC_DIR . 'includes/class-order-coordinator.php';
require_once WEBVOUCH_WC_DIR . 'includes/class-widget-renderer.php';
require_once WEBVOUCH_WC_DIR . 'includes/class-widgets-admin.php';
require_once WEBVOUCH_WC_DIR . 'includes/class-settings-page.php';
require_once WEBVOUCH_WC_DIR . 'includes/class-plugin.php';

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WEBVOUCH_WC_FILE,
				true
			);
		}
	}
);

register_activation_hook( WEBVOUCH_WC_FILE, array( \WebVouch\WooCommerce\Plugin::class, 'activate' ) );
register_deactivation_hook( WEBVOUCH_WC_FILE, array( \WebVouch\WooCommerce\Plugin::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\WebVouch\WooCommerce\Plugin::instance()->boot();
	},
	20
);
