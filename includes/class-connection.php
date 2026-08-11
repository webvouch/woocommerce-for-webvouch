<?php
/**
 * Registers this installation and keeps the dashboard state current.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Connection {
	public const HEARTBEAT_HOOK = 'webvouch_wc_connection_heartbeat';
	private const GROUP = 'webvouch-woocommerce';

	public function __construct(
		private readonly Settings $settings,
		private readonly API_Client $api,
		private readonly Historical_Sync $historical,
		private readonly Logger $logger
	) {
	}

	public function ensure_heartbeat(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( false === as_has_scheduled_action( self::HEARTBEAT_HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 60, 5 * MINUTE_IN_SECONDS, self::HEARTBEAT_HOOK, array(), self::GROUP );
		}
	}

	public function heartbeat(): Http_Result {
		if ( ! $this->settings->is_connection_configured() ) {
			return new Http_Result( 401, array( 'error' => array( 'code' => 'configuration_missing' ) ) );
		}
		$key = 'wv-wc-connect-v1-' . hash( 'sha256', Settings::installation_id() . '|' . microtime( true ) );
		$result = $this->api->register_connection( $this->payload(), $key );
		if ( 200 !== $result->status ) {
			$this->logger->warning( 'WebVouch connection heartbeat failed.', array( 'http_status' => $result->status, 'error_code' => $result->error_code() ) );
			return $result;
		}
		$generation = $result->body['generation'] ?? null;
		$last_seen  = $result->body['lastSeenAt'] ?? null;
		if ( ! is_string( $generation ) || '' === $generation || ! is_string( $last_seen ) ) {
			return new Http_Result( 502, array( 'error' => array( 'code' => 'invalid_connection_response' ) ) );
		}
		$this->settings->store_connection( $generation, $last_seen );
		set_transient( Settings::CONNECTION_TRANSIENT, array( 'ok' => true, 'checked_at' => time() ), DAY_IN_SECONDS );
		$run = $result->body['historicalRun'] ?? null;
		if ( is_array( $run ) && is_string( $run['runId'] ?? null ) ) {
			$this->historical->schedule( $run['runId'] );
		}
		return $result;
	}

	public function disconnect(): Http_Result {
		$generation = $this->settings->generation();
		if ( '' === $generation ) {
			return new Http_Result( 200, array( 'status' => 'disconnected' ) );
		}
		return $this->api->disconnect_connection(
			array(
				'installationId' => Settings::installation_id(),
				'generation'     => $generation,
			),
			'wv-wc-disconnect-v1-' . hash( 'sha256', Settings::installation_id() . '|' . $generation )
		);
	}

	/** @return array<string,mixed> */
	private function payload(): array {
		$config = $this->settings->get();
		if ( '' !== (string) $config['template_id'] && '' === (string) $config['template_name'] ) {
			$templates = $this->api->list_templates();
			if ( 200 === $templates->status && is_array( $templates->body['data'] ?? null ) ) {
				foreach ( $templates->body['data'] as $template ) {
					if ( is_array( $template ) && (string) $config['template_id'] === ( $template['id'] ?? null ) && is_string( $template['name'] ?? null ) ) {
						$config['template_name'] = $template['name'];
						$this->settings->store_template_name( $template['name'] );
						break;
					}
				}
			}
		}
		$site_name = trim( sanitize_text_field( (string) get_bloginfo( 'name' ) ) );
		return array(
			'installationId'   => Settings::installation_id(),
			'siteUrl'          => home_url( '/' ),
			'siteName'         => substr( '' === $site_name ? 'WooCommerce store' : $site_name, 0, 200 ),
			'pluginVersion'    => WEBVOUCH_WC_VERSION,
			'wpVersion'        => (string) get_bloginfo( 'version' ),
			'wcVersion'        => defined( 'WC_VERSION' ) ? (string) WC_VERSION : 'unknown',
			'trigger'          => (string) $config['trigger'],
			'templateId'       => '' === (string) $config['template_id'] ? null : (string) $config['template_id'],
			'templateName'     => '' === (string) $config['template_name'] ? null : (string) $config['template_name'],
			'automationEnabled' => ! empty( $config['enabled'] ),
		);
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HEARTBEAT_HOOK, null, self::GROUP );
		}
	}
}
