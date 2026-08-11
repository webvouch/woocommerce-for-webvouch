<?php
/**
 * Plugin settings and credential/token storage.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {
	public const OPTION_KEY          = 'webvouch_wc_settings_v1';
	public const TOKEN_OPTION_KEY    = 'webvouch_wc_access_token_v1';
	public const WIDGET_TOKEN_OPTION_KEY = 'webvouch_wc_widget_access_token_v1';
	public const WIDGETS_OPTION_KEY  = 'webvouch_wc_widgets_v1';
	public const INSTALLATION_OPTION_KEY = 'webvouch_wc_installation_id_v1';
	public const CONNECTION_OPTION_KEY = 'webvouch_wc_connection_state_v1';
	public const HISTORICAL_OPTION_KEY = 'webvouch_wc_historical_state_v1';
	public const TEMPLATE_TRANSIENT  = 'webvouch_wc_templates_v1';
	public const CONNECTION_TRANSIENT = 'webvouch_wc_connection_v1';

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'api_base_url' => 'https://api.webvouch.com/api',
			'client_id'    => '',
			'client_secret' => '',
			'template_id'  => '',
			'template_name' => '',
			'trigger'      => 'order_confirmed',
			'enabled'      => false,
		);
	}

	public static function install_defaults(): void {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::defaults(), '', false );
		}
		self::installation_id();
	}

	/** @return array<string,mixed> */
	public function get(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		$value  = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );

		if ( defined( 'WEBVOUCH_WC_API_BASE_URL' ) ) {
			$value['api_base_url'] = (string) constant( 'WEBVOUCH_WC_API_BASE_URL' );
		}
		if ( defined( 'WEBVOUCH_WC_CLIENT_ID' ) ) {
			$value['client_id'] = (string) constant( 'WEBVOUCH_WC_CLIENT_ID' );
		}
		if ( defined( 'WEBVOUCH_WC_CLIENT_SECRET' ) ) {
			$value['client_secret'] = (string) constant( 'WEBVOUCH_WC_CLIENT_SECRET' );
		}

		return $value;
	}

	/** @return array<string,mixed> */
	public function get_stored(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/** @param mixed $input */
	public function sanitize( $input ): array {
		$current = $this->get_stored();
		$input   = is_array( $input ) ? $input : array();
		$next    = $current;

		if ( ! defined( 'WEBVOUCH_WC_API_BASE_URL' ) ) {
			$url = $this->sanitize_api_base_url( (string) ( $input['api_base_url'] ?? '' ) );
			if ( null === $url ) {
				add_settings_error(
					self::OPTION_KEY,
					'invalid_api_url',
					__( 'Use an HTTPS WebVouch API URL. HTTP is allowed only for explicit local test hosts.', 'webvouch-for-woocommerce' )
				);
			} else {
				$next['api_base_url'] = $url;
			}
		}

		if ( ! defined( 'WEBVOUCH_WC_CLIENT_ID' ) ) {
			$client_id = trim( sanitize_text_field( (string) ( $input['client_id'] ?? '' ) ) );
			if ( '' === $client_id || preg_match( '/^wv_client_[A-Za-z0-9_-]{32}$/', $client_id ) ) {
				$next['client_id'] = $client_id;
			} else {
				add_settings_error( self::OPTION_KEY, 'invalid_client_id', __( 'The WebVouch client ID format is invalid.', 'webvouch-for-woocommerce' ) );
			}
		}

		if ( ! defined( 'WEBVOUCH_WC_CLIENT_SECRET' ) ) {
			$secret = trim( (string) ( $input['client_secret'] ?? '' ) );
			if ( '' !== $secret ) {
				if ( preg_match( '/^wv_secret_[A-Za-z0-9_-]{43}$/', $secret ) ) {
					$next['client_secret'] = $secret;
				} else {
					add_settings_error( self::OPTION_KEY, 'invalid_client_secret', __( 'The WebVouch client secret format is invalid.', 'webvouch-for-woocommerce' ) );
				}
			}
		}

		$template_id = trim( sanitize_text_field( (string) ( $input['template_id'] ?? '' ) ) );
		$next['template_id'] = substr( $template_id, 0, 64 );
		$next['template_name'] = $this->template_name( $next['template_id'] );

		$trigger = sanitize_key( (string) ( $input['trigger'] ?? '' ) );
		$next['trigger'] = in_array( $trigger, array( 'order_confirmed', 'order_completed' ), true )
			? $trigger
			: 'order_confirmed';
		$next['enabled'] = ! empty( $input['enabled'] );

		if ( $this->connection_fields_changed( $current, $next ) ) {
			$this->clear_caches();
			delete_option( self::WIDGETS_OPTION_KEY );
			self::clear_connection_state();
		}

		return $next;
	}

	public function disconnect(): bool {
		if ( $this->credentials_use_constants() ) {
			return false;
		}
		$stored                  = $this->get_stored();
		$stored['client_id']     = '';
		$stored['client_secret'] = '';
		$stored['template_id']   = '';
		$stored['enabled']       = false;
		update_option( self::OPTION_KEY, $stored, false );
		$this->clear_caches();
		delete_option( self::WIDGETS_OPTION_KEY );
		self::clear_connection_state();
		return true;
	}

	public function clear_caches(): void {
		delete_option( self::TOKEN_OPTION_KEY );
		delete_option( self::WIDGET_TOKEN_OPTION_KEY );
		delete_transient( self::TEMPLATE_TRANSIENT );
		delete_transient( self::CONNECTION_TRANSIENT );
	}

	public static function installation_id(): string {
		$current = get_option( self::INSTALLATION_OPTION_KEY, '' );
		if ( is_string( $current ) && preg_match( '/^wvwc_[a-f0-9]{32}$/', $current ) ) {
			return $current;
		}
		try {
			$current = 'wvwc_' . bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			$current = 'wvwc_' . md5( wp_generate_password( 64, true, true ) . microtime( true ) );
		}
		update_option( self::INSTALLATION_OPTION_KEY, $current, false );
		return $current;
	}

	public function generation(): string {
		$state = get_option( self::CONNECTION_OPTION_KEY, array() );
		return is_array( $state ) && is_string( $state['generation'] ?? null )
			? $state['generation']
			: '';
	}

	public function store_connection( string $generation, string $last_seen_at ): void {
		update_option(
			self::CONNECTION_OPTION_KEY,
			array( 'generation' => $generation, 'last_seen_at' => $last_seen_at ),
			false
		);
	}

	public static function clear_connection_state(): void {
		delete_option( self::CONNECTION_OPTION_KEY );
		delete_option( self::HISTORICAL_OPTION_KEY );
	}

	/** @return array<string,mixed> */
	public function historical_state(): array {
		$state = get_option( self::HISTORICAL_OPTION_KEY, array() );
		return is_array( $state ) ? $state : array();
	}

	/** @param array<string,mixed> $state */
	public function store_historical_state( array $state ): void {
		update_option( self::HISTORICAL_OPTION_KEY, $state, false );
	}

	public function clear_historical_state(): void {
		delete_option( self::HISTORICAL_OPTION_KEY );
	}

	public function store_template_name( string $name ): void {
		$stored = $this->get_stored();
		$stored['template_name'] = substr( sanitize_text_field( $name ), 0, 200 );
		update_option( self::OPTION_KEY, $stored, false );
	}

	/** @return array{access_token:string,expires_at:int,scope?:string}|null */
	public function get_cached_token( string $profile = 'automation' ): ?array {
		$cached = get_option( $this->token_option_key( $profile ), null );
		if ( ! is_array( $cached ) || ! is_string( $cached['access_token'] ?? null ) || ! is_numeric( $cached['expires_at'] ?? null ) ) {
			return null;
		}
		$result = array(
			'access_token' => $cached['access_token'],
			'expires_at'   => (int) $cached['expires_at'],
		);
		if ( is_string( $cached['scope'] ?? null ) ) {
			$result['scope'] = $cached['scope'];
		}
		return $result;
	}

	public function cache_token( string $token, int $expires_at, string $profile = 'automation', string $scope = '' ): void {
		$value = array(
			'access_token' => $token,
			'expires_at'   => $expires_at,
		);
		if ( '' !== $scope ) {
			$value['scope'] = $scope;
		}
		update_option(
			$this->token_option_key( $profile ),
			$value,
			false
		);
	}

	public function clear_token( string $profile = 'automation' ): void {
		delete_option( $this->token_option_key( $profile ) );
	}

	public function is_connection_configured(): bool {
		$value = $this->get();
		return '' !== $value['client_id'] && '' !== $value['client_secret'];
	}

	public function is_automation_ready(): bool {
		$value = $this->get();
		return ! empty( $value['enabled'] )
			&& '' !== $value['client_id']
			&& '' !== $value['client_secret']
			&& '' !== $value['template_id']
			&& '' !== $this->generation();
	}

	public function credentials_use_constants(): bool {
		return defined( 'WEBVOUCH_WC_CLIENT_ID' ) || defined( 'WEBVOUCH_WC_CLIENT_SECRET' );
	}

	private function sanitize_api_base_url( string $raw ): ?string {
		$url = untrailingslashit( esc_url_raw( trim( $raw ), array( 'http', 'https' ) ) );
		if ( '' === $url ) {
			return null;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( trim( (string) $parts['host'], '[]' ) );
		if ( 'https' !== $scheme ) {
			$local_hosts = array( 'localhost', '127.0.0.1', '::1', 'host.docker.internal' );
			if ( 'http' !== $scheme || ! in_array( $host, $local_hosts, true ) ) {
				return null;
			}
		}

		return $url;
	}

	/** @param array<string,mixed> $current @param array<string,mixed> $next */
	private function connection_fields_changed( array $current, array $next ): bool {
		foreach ( array( 'api_base_url', 'client_id', 'client_secret' ) as $field ) {
			if ( ( $current[ $field ] ?? '' ) !== ( $next[ $field ] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	private function token_option_key( string $profile ): string {
		return 'widgets' === $profile ? self::WIDGET_TOKEN_OPTION_KEY : self::TOKEN_OPTION_KEY;
	}

	private function template_name( string $template_id ): string {
		if ( '' === $template_id ) {
			return '';
		}
		$templates = get_transient( self::TEMPLATE_TRANSIENT );
		if ( ! is_array( $templates ) ) {
			return '';
		}
		foreach ( $templates as $template ) {
			if ( is_array( $template ) && $template_id === ( $template['id'] ?? null ) ) {
				return substr( sanitize_text_field( (string) ( $template['name'] ?? '' ) ), 0, 200 );
			}
		}
		return '';
	}
}
