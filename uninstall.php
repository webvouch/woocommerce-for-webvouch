<?php
/**
 * WebVouch for WooCommerce uninstall cleanup.
 *
 * @package WebVouchForWooCommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'webvouch_wc_settings_v1' );
delete_option( 'webvouch_wc_access_token_v1' );
delete_option( 'webvouch_wc_widget_access_token_v1' );
delete_option( 'webvouch_wc_widgets_v1' );
delete_option( 'webvouch_wc_installation_id_v1' );
delete_option( 'webvouch_wc_connection_state_v1' );
delete_option( 'webvouch_wc_historical_state_v1' );
delete_transient( 'webvouch_wc_templates_v1' );
delete_transient( 'webvouch_wc_connection_v1' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'webvouch_wc_send_invitation', null, 'webvouch-woocommerce' );
	as_unschedule_all_actions( 'webvouch_wc_connection_heartbeat', null, 'webvouch-woocommerce' );
	as_unschedule_all_actions( 'webvouch_wc_historical_sync', null, 'webvouch-woocommerce' );
}
