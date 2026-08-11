<?php
/**
 * Deep customer API client: OAuth token reuse, fixed endpoints, and normalized results.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class API_Client {
	private const AUTOMATION_SCOPES = 'templates:read invitations:write';
	private const WIDGET_SCOPES     = 'widgets:read widgets:write';
	private const MAX_BODY_BYTES  = 1048576;

	private readonly \Closure $transport;

	/**
	 * @param callable|null $transport Test adapter with signature function(string, string, array): array|\WP_Error.
	 */
	public function __construct(
		private readonly Settings $settings,
		?callable $transport = null
	) {
		$this->transport = $transport
			? \Closure::fromCallable( $transport )
			: static fn( string $method, string $url, array $args ) => wp_remote_request( $url, array_merge( $args, array( 'method' => $method ) ) );
	}

	public function verify_account(): Http_Result {
		return $this->authorized_request( 'GET', '/public/v1/account', null, array(), 15 );
	}

	public function list_templates(): Http_Result {
		return $this->authorized_request(
			'GET',
			'/public/v1/invitation-templates?limit=100&sort=newest',
			null,
			array(),
			15
		);
	}

	public function list_widgets(): Http_Result {
		return $this->authorized_request( 'GET', '/public/v1/widgets', null, array(), 15, 'widgets' );
	}

	public function activate_widget( string $type, string $idempotency_key ): Http_Result {
		return $this->authorized_request(
			'POST',
			'/public/v1/widgets/' . rawurlencode( $type ) . '/activate',
			'{}',
			array( 'Idempotency-Key' => $idempotency_key ),
			30,
			'widgets'
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function create_invitation( array $payload, string $idempotency_key ): Http_Result {
		return $this->json_request( 'POST', '/public/v1/invitations', $payload, array( 'Idempotency-Key' => $idempotency_key ), 30 );
	}

	/** @param array<string,mixed> $payload */
	public function register_connection( array $payload, string $idempotency_key ): Http_Result {
		return $this->json_request( 'PUT', '/public/v1/integrations/woocommerce/connection', $payload, array( 'Idempotency-Key' => $idempotency_key ), 30 );
	}

	/** @param array<string,mixed> $payload */
	public function disconnect_connection( array $payload, string $idempotency_key ): Http_Result {
		return $this->json_request( 'DELETE', '/public/v1/integrations/woocommerce/connection', $payload, array( 'Idempotency-Key' => $idempotency_key ), 30 );
	}

	/** @param array<string,mixed> $payload */
	public function create_order_invitation( array $payload, string $idempotency_key ): Http_Result {
		return $this->json_request( 'POST', '/public/v1/integrations/woocommerce/orders', $payload, array( 'Idempotency-Key' => $idempotency_key ), 30 );
	}

	/** @param array<string,mixed> $payload */
	public function claim_historical_run( string $run_id, array $payload, string $idempotency_key ): Http_Result {
		return $this->json_request( 'POST', '/public/v1/integrations/woocommerce/historical-sync/' . rawurlencode( $run_id ) . '/claim', $payload, array( 'Idempotency-Key' => $idempotency_key ), 30 );
	}

	/** @param array<string,mixed> $payload */
	public function upload_historical_batch( string $run_id, array $payload, string $idempotency_key ): Http_Result {
		return $this->json_request( 'POST', '/public/v1/integrations/woocommerce/historical-sync/' . rawurlencode( $run_id ) . '/batches', $payload, array( 'Idempotency-Key' => $idempotency_key ), 60 );
	}

	/** @param array<string,mixed> $payload */
	public function complete_historical_run( string $run_id, array $payload, string $idempotency_key ): Http_Result {
		return $this->json_request( 'POST', '/public/v1/integrations/woocommerce/historical-sync/' . rawurlencode( $run_id ) . '/complete', $payload, array( 'Idempotency-Key' => $idempotency_key ), 30 );
	}

	/**
	 * @param array<string,mixed>  $payload
	 * @param array<string,string> $headers
	 */
	private function json_request( string $method, string $path, array $payload, array $headers, int $timeout, string $profile = 'automation' ): Http_Result {
		$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $body ) ) {
			return new Http_Result( 0, array(), 'json_encode_failed' );
		}
		return $this->authorized_request( $method, $path, $body, $headers, $timeout, $profile );
	}

	/**
	 * @param string|null         $body
	 * @param array<string,string> $headers
	 */
	private function authorized_request(
		string $method,
		string $path,
		?string $body,
		array $headers,
		int $timeout,
		string $profile = 'automation'
	): Http_Result {
		$token = $this->access_token( $profile );
		if ( $token instanceof Http_Result ) {
			return $token;
		}

		$result = $this->request(
			$method,
			$path,
			$body,
			array_merge(
				$headers,
				array( 'Authorization' => 'Bearer ' . $token )
			),
			$timeout
		);
		$refreshable_scope_error = 403 === $result->status && 'insufficient_scope' === $result->error_code();
		if ( 401 !== $result->status && ! $refreshable_scope_error ) {
			return $result;
		}

		$this->settings->clear_token( $profile );
		$replacement = $this->access_token( $profile, true );
		if ( $replacement instanceof Http_Result ) {
			return $replacement;
		}

		return $this->request(
			$method,
			$path,
			$body,
			array_merge(
				$headers,
				array( 'Authorization' => 'Bearer ' . $replacement )
			),
			$timeout
		);
	}

	/** @return string|Http_Result */
	private function access_token( string $profile = 'automation', bool $force = false ) {
		$required_scopes = 'widgets' === $profile ? self::WIDGET_SCOPES : self::AUTOMATION_SCOPES;
		if ( ! $force ) {
			$cached = $this->settings->get_cached_token( $profile );
			$legacy_automation_cache = 'automation' === $profile && ! isset( $cached['scope'] );
			if ( $cached && $cached['expires_at'] > time() + DAY_IN_SECONDS && ( $legacy_automation_cache || $this->scope_contains( (string) ( $cached['scope'] ?? '' ), $required_scopes ) ) ) {
				return $cached['access_token'];
			}
		}

		$config = $this->settings->get();
		if ( '' === $config['client_id'] || '' === $config['client_secret'] ) {
			return new Http_Result( 401, array( 'error' => 'invalid_client' ) );
		}

		$basic = base64_encode(
			rawurlencode( (string) $config['client_id'] ) . ':' . rawurlencode( (string) $config['client_secret'] )
		);
		$body  = http_build_query(
			array(
				'grant_type' => 'client_credentials',
				'scope'      => $required_scopes,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		$result = $this->request(
			'POST',
			'/public/v1/oauth/token',
			$body,
			array(
				'Authorization' => 'Basic ' . $basic,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			15,
			false
		);
		if ( ! $result->is_http_success() ) {
			return $result;
		}

		$access_token = $result->body['access_token'] ?? null;
		$expires_in   = $result->body['expires_in'] ?? null;
		$scope        = $result->body['scope'] ?? null;
		if ( ! is_string( $access_token ) || ! preg_match( '/^wv_at_[A-Za-z0-9_-]{43}$/', $access_token ) || ! is_numeric( $expires_in ) || ! is_string( $scope ) || ! $this->scope_contains( $scope, $required_scopes ) ) {
			return new Http_Result( 502, array( 'error' => array( 'code' => 'invalid_token_response' ) ) );
		}

		$ttl = max( 60, min( 8 * DAY_IN_SECONDS, (int) $expires_in ) );
		$this->settings->cache_token( $access_token, time() + $ttl, $profile, $scope );
		return $access_token;
	}

	private function scope_contains( string $actual, string $required ): bool {
		$granted = preg_split( '/\s+/', trim( $actual ) ) ?: array();
		foreach ( preg_split( '/\s+/', trim( $required ) ) ?: array() as $scope ) {
			if ( '' !== $scope && ! in_array( $scope, $granted, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string|null          $body
	 * @param array<string,string> $headers
	 */
	private function request(
		string $method,
		string $path,
		?string $body,
		array $headers,
		int $timeout,
		bool $json = true
	): Http_Result {
		$config  = $this->settings->get();
		$url     = untrailingslashit( (string) $config['api_base_url'] ) . $path;
		$headers = array_merge(
			array(
				'Accept'     => 'application/json',
				'User-Agent' => 'WebVouch-WooCommerce/' . WEBVOUCH_WC_VERSION . '; ' . home_url( '/' ),
			),
			$headers
		);
		if ( $json && null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}

		$args = array(
			'headers'             => $headers,
			'timeout'             => $timeout,
			'redirection'         => 0,
			'blocking'            => true,
			'sslverify'           => true,
			'limit_response_size' => self::MAX_BODY_BYTES,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = ( $this->transport )( $method, $url, $args );
		if ( is_wp_error( $response ) ) {
			return Http_Result::transport_failure( (string) $response->get_error_code() );
		}

		$status       = (int) wp_remote_retrieve_response_code( $response );
		$response_raw = (string) wp_remote_retrieve_body( $response );
		$decoded      = array();
		if ( '' !== trim( $response_raw ) ) {
			$value = json_decode( $response_raw, true );
			if ( ! is_array( $value ) ) {
				return new Http_Result( 502, array( 'error' => array( 'code' => 'invalid_json_response' ) ) );
			}
			$decoded = $value;
		}

		$retry_after = $this->parse_retry_after( wp_remote_retrieve_header( $response, 'retry-after' ) );
		if ( null === $retry_after ) {
			$details_retry = $decoded['error']['details']['retryAfter'] ?? null;
			$retry_after   = is_numeric( $details_retry ) ? max( 0, (int) $details_retry ) : null;
		}
		$replayed_header = strtolower( (string) wp_remote_retrieve_header( $response, 'idempotency-replayed' ) );

		return new Http_Result(
			$status,
			$decoded,
			'',
			$retry_after,
			in_array( $replayed_header, array( 'true', '1' ), true )
		);
	}

	/** @param mixed $value */
	private function parse_retry_after( $value ): ?int {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null;
		}
		$text = trim( (string) $value );
		if ( '' === $text ) {
			return null;
		}
		if ( ctype_digit( $text ) ) {
			return (int) $text;
		}
		$timestamp = strtotime( $text );
		return false === $timestamp ? null : max( 0, $timestamp - time() );
	}
}
