<?php
/**
 * External update channel backed by WebVouch checksum release metadata.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Updater {
	private const MANIFEST_URL = 'https://webvouch.com/downloads/woocommerce/latest.json';
	private const CACHE_KEY = 'webvouch_wc_release_manifest_v1';
	private const DIGEST_CACHE_PREFIX = 'webvouch_wc_release_digest_';
	private const PLUGIN_FILE = 'webvouch-for-woocommerce/webvouch-for-woocommerce.php';

	public function boot(): void {
		add_filter( 'update_plugins_webvouch.com', array( $this, 'update_metadata' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_download' ), 10, 4 );
	}

	/** @param mixed $update @param mixed $plugin_data @return mixed */
	public function update_metadata( $update, array $plugin_data, string $plugin_file, array $locales ) {
		if ( self::PLUGIN_FILE !== $plugin_file ) {
			return $update;
		}
		$manifest = $this->manifest();
		if ( ! $manifest || version_compare( (string) $manifest['version'], WEBVOUCH_WC_VERSION, '<=' ) ) {
			return false;
		}
		$this->remember_digest( (string) $manifest['downloadUrl'], (string) $manifest['sha256'] );
		return array(
			'id'           => 'https://webvouch.com/integrations/woocommerce',
			'slug'         => 'webvouch-for-woocommerce',
			'version'      => (string) $manifest['version'],
			'url'          => 'https://webvouch.com/integrations/woocommerce',
			'package'      => (string) $manifest['downloadUrl'],
			'tested'       => (string) ( $manifest['tested'] ?? '' ),
			'requires_php' => (string) ( $manifest['requiresPhp'] ?? '8.1' ),
			'autoupdate'   => false,
		);
	}

	/** @param mixed $result @param mixed $args @return mixed */
	public function plugin_information( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || 'webvouch-for-woocommerce' !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$manifest = $this->manifest();
		if ( ! $manifest ) {
			return $result;
		}
		return (object) array(
			'name'          => 'WebVouch for WooCommerce',
			'slug'          => 'webvouch-for-woocommerce',
			'version'       => (string) $manifest['version'],
			'author'        => '<a href="https://webvouch.com/">WebVouch</a>',
			'homepage'      => 'https://webvouch.com/integrations/woocommerce',
			'requires'      => (string) ( $manifest['requires'] ?? '6.4' ),
			'tested'        => (string) ( $manifest['tested'] ?? '' ),
			'requires_php'  => (string) ( $manifest['requiresPhp'] ?? '8.1' ),
			'download_link' => (string) $manifest['downloadUrl'],
				'sections'      => array( 'description' => 'Automatic WebVouch service-review invitations, past-order import, and storefront widgets for WooCommerce.' ),
		);
	}

	/** @param mixed $reply @param mixed $upgrader @param mixed $hook_extra @return mixed */
	public function verify_download( $reply, string $package, $upgrader, array $hook_extra ) {
		if ( false !== $reply ) {
			return $reply;
		}
		if ( ! $this->is_webvouch_package( $package ) ) {
			return false;
		}

		$expected = get_site_transient( $this->digest_cache_key( $package ) );
		if ( ! is_string( $expected ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) ) {
			$manifest = $this->manifest();
			if ( $manifest && hash_equals( $package, (string) $manifest['downloadUrl'] ) ) {
				$expected = (string) $manifest['sha256'];
				$this->remember_digest( $package, $expected );
			} else {
				return new \WP_Error( 'webvouch_checksum_unavailable', __( 'The WebVouch update could not be verified because its checksum is unavailable. Try again later.', 'webvouch-for-woocommerce' ) );
			}
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $package, 60 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$actual = hash_file( 'sha256', $file );
		if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'webvouch_checksum_mismatch', __( 'The WebVouch update failed checksum verification.', 'webvouch-for-woocommerce' ) );
		}
		return $file;
	}

	/** @return array<string,mixed>|null */
	private function manifest(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && $this->valid_manifest( $cached ) ) {
			return $cached;
		}
		$response = wp_safe_remote_get( self::MANIFEST_URL, array( 'timeout' => 10, 'redirection' => 0, 'sslverify' => true ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || ! $this->valid_manifest( $decoded ) ) {
			return null;
		}
		set_site_transient( self::CACHE_KEY, $decoded, 6 * HOUR_IN_SECONDS );
		return $decoded;
	}

	/** @param array<string,mixed> $manifest */
	private function valid_manifest( array $manifest ): bool {
		if ( ! is_string( $manifest['version'] ?? null )
			|| 1 !== preg_match( '/^\d+\.\d+\.\d+$/', $manifest['version'] )
			|| ! is_string( $manifest['downloadUrl'] ?? null )
			|| ! is_string( $manifest['sha256'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $manifest['sha256'] ) ) {
			return false;
		}

		$expected_url = 'https://webvouch.com/downloads/woocommerce/webvouch-for-woocommerce-' . $manifest['version'] . '.zip';
		return hash_equals( $expected_url, $manifest['downloadUrl'] );
	}

	private function is_webvouch_package( string $package ): bool {
		$parts = wp_parse_url( $package );
		return is_array( $parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& 'webvouch.com' === strtolower( (string) ( $parts['host'] ?? '' ) )
			&& ! isset( $parts['port'], $parts['user'], $parts['pass'], $parts['query'], $parts['fragment'] )
			&& 1 === preg_match( '#^/downloads/woocommerce/webvouch-for-woocommerce-\d+\.\d+\.\d+\.zip$#', (string) ( $parts['path'] ?? '' ) );
	}

	private function digest_cache_key( string $package ): string {
		return self::DIGEST_CACHE_PREFIX . hash( 'sha256', $package );
	}

	private function remember_digest( string $package, string $digest ): void {
		set_site_transient( $this->digest_cache_key( $package ), $digest, 7 * DAY_IN_SECONDS );
	}
}
