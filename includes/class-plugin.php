<?php
/**
 * Plugin composition root and lifecycle.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	private static ?self $instance = null;
	private bool $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activate(): void {
		Settings::install_defaults();
	}

	public static function deactivate(): void {
		Action_Scheduler_Queue::unschedule_all();
		Historical_Sync::unschedule();
		Connection::unschedule();
		delete_transient( Settings::CONNECTION_TRANSIENT );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_filter( 'plugin_action_links_' . plugin_basename( WEBVOUCH_WC_FILE ), array( $this, 'action_links' ) );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_required_notice' ) );
			return;
		}
		if ( defined( 'WC_VERSION' ) && version_compare( (string) WC_VERSION, '8.6', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_version_notice' ) );
			return;
		}

		$settings     = new Settings();
		$logger       = new Logger();
		$api          = new API_Client( $settings );
		$queue        = new Action_Scheduler_Queue();
		$historical   = new Historical_Sync( $settings, $api, $logger );
		$connection   = new Connection( $settings, $api, $historical, $logger );
		$order_state  = new Order_State();
		$coordinator  = new Order_Coordinator( $settings, $api, new Retry_Policy(), $queue, $order_state, $logger );
		$status_panel = new Order_Status_Panel( $order_state );
		$widget_state = new Widget_State( $settings );
		$widgets_admin = new Widgets_Admin( $settings, $api, $widget_state );
		$widget_renderer = new Widget_Renderer( $widget_state );
		$settings_page = new Settings_Page( $settings, $api, $connection, $widgets_admin );
		$updater      = new Updater();

		$settings_page->boot();
		$widgets_admin->boot();
		$widget_renderer->boot();
		$updater->boot();
		$status_panel->boot();
		$connection->ensure_heartbeat();
		add_action( 'woocommerce_order_status_processing', array( $coordinator, 'capture_processing' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( $coordinator, 'capture_completed' ), 10, 2 );
		add_action( Action_Scheduler_Queue::HOOK, array( $coordinator, 'process' ), 10, 3 );
		add_action( Connection::HEARTBEAT_HOOK, array( $connection, 'heartbeat' ) );
		add_action( Historical_Sync::HOOK, array( $historical, 'process' ), 10, 2 );
	}

	/** @param array<int,string> $links @return array<int,string> */
	public function action_links( array $links ): array {
		if ( class_exists( 'WooCommerce' ) ) {
			array_unshift(
				$links,
				'<a href="' . esc_url( Settings_Page::page_url() ) . '">' . esc_html__( 'Settings', 'webvouch-for-woocommerce' ) . '</a>'
			);
		}
		return $links;
	}

	public function woocommerce_required_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'WebVouch for WooCommerce requires WooCommerce to be installed and active.', 'webvouch-for-woocommerce' ) . '</p></div>';
	}

	public function woocommerce_version_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'WebVouch for WooCommerce requires WooCommerce 8.6 or newer.', 'webvouch-for-woocommerce' ) . '</p></div>';
	}
}
