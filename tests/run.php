<?php
/**
 * Dependency-free unit tests for transport, settings, and retry behavior.
 *
 * Run with PHP 8.1+ from the plugin directory: php tests/run.php
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'WEBVOUCH_WC_VERSION', 'test' );

$GLOBALS['wv_test_options'] = array();

final class WP_Error {
	public function __construct( private readonly string $code, private readonly string $message = '' ) {
	}

	public function get_error_code(): string {
		return $this->code;
	}
}

function sanitize_key( string $value ): string {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value ) ?? '' );
}

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function absint( $value ): int {
	return abs( (int) $value );
}

function wp_rand(): int {
	return 123456;
}

/** @param array<string,mixed> $args @return mixed */
function wc_get_orders( array $args ) {
	$handler = $GLOBALS['wv_wc_orders_handler'] ?? null;
	return is_callable( $handler ) ? $handler( $args ) : array();
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

/** @param array<int,string> $protocols */
function esc_url_raw( string $value, array $protocols = array() ): string {
	$scheme = strtolower( (string) parse_url( $value, PHP_URL_SCHEME ) );
	return '' !== $scheme && in_array( $scheme, $protocols, true ) ? $value : '';
}

/** @return array<string,mixed>|false */
function wp_parse_url( string $value ) {
	return parse_url( $value );
}

/** @return mixed */
function get_option( string $key, $default = false ) {
	return $GLOBALS['wv_test_options'][ $key ] ?? $default;
}

/** @param mixed $value */
function add_option( string $key, $value, string $deprecated = '', bool $autoload = false ): bool {
	$GLOBALS['wv_test_options'][ $key ] = $value;
	return true;
}

/** @param mixed $value */
function update_option( string $key, $value, bool $autoload = false ): bool {
	if ( ( $GLOBALS['wv_fail_option_write'] ?? null ) === $key ) {
		return false;
	}
	$GLOBALS['wv_test_options'][ $key ] = $value;
	return true;
}

function delete_option( string $key ): bool {
	unset( $GLOBALS['wv_test_options'][ $key ] );
	return true;
}

function delete_transient( string $key ): bool {
	return true;
}

/** @param mixed ...$args */
function do_action( string $hook, ...$args ): void {
}

/** @return mixed */
function get_site_transient( string $key ) {
	return $GLOBALS['wv_test_site_transients'][ $key ] ?? false;
}

/** @param mixed $value */
function set_site_transient( string $key, $value, int $expiration ): bool {
	$GLOBALS['wv_test_site_transients'][ $key ] = $value;
	return true;
}

/** @param mixed $callback */
function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return true;
}

/** @return array<string,mixed>|WP_Error */
function wp_safe_remote_get( string $url, array $args = array() ) {
	return new WP_Error( 'unexpected_manifest_request' );
}

/** @return string|WP_Error */
function download_url( string $url, int $timeout = 300 ) {
	return $GLOBALS['wv_test_download_file'] ?? new WP_Error( 'missing_download' );
}

function wp_delete_file( string $file ): bool {
	return file_exists( $file ) ? unlink( $file ) : true;
}

function add_settings_error( string $setting, string $code, string $message ): void {
}

/** @param array<string,mixed> $pairs @param mixed $attributes @return array<string,mixed> */
function shortcode_atts( array $pairs, $attributes, string $shortcode = '' ): array {
	return array_merge( $pairs, is_array( $attributes ) ? array_intersect_key( $attributes, $pairs ) : array() );
}

function esc_attr( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function wp_script_is( string $handle, string $status = 'enqueued' ): bool {
	return 'webvouch-widgets-loader' === $handle && 'registered' === $status;
}

function wp_enqueue_script( string $handle ): void {
	$GLOBALS['wv_enqueued_scripts'][ $handle ] = true;
}

function is_admin(): bool {
	return false;
}

function is_feed(): bool {
	return false;
}

function wp_is_json_request(): bool {
	return false;
}

/** @param mixed $value @param mixed ...$args @return mixed */
function apply_filters( string $hook, $value, ...$args ) {
	return $value;
}

function __( string $message, string $domain = '' ): string {
	return $message;
}

function home_url( string $path = '' ): string {
	return 'https://shop.example.test' . $path;
}

/** @param mixed $value @return string|false */
function wp_json_encode( $value, int $flags = 0 ) {
	return json_encode( $value, $flags );
}

/** @param mixed $value */
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

/** @param array<string,mixed> $response */
function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

/** @param array<string,mixed> $response */
function wp_remote_retrieve_body( array $response ): string {
	return (string) ( $response['body'] ?? '' );
}

/** @param array<string,mixed> $response @return mixed */
function wp_remote_retrieve_header( array $response, string $name ) {
	return $response['headers'][ strtolower( $name ) ] ?? '';
}

require_once dirname( __DIR__ ) . '/includes/class-http-result.php';
require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-widget-state.php';
require_once dirname( __DIR__ ) . '/includes/class-widget-renderer.php';
require_once dirname( __DIR__ ) . '/includes/class-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-retry-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-historical-sync.php';
require_once dirname( __DIR__ ) . '/includes/class-updater.php';

use WebVouch\WooCommerce\API_Client;
use WebVouch\WooCommerce\Historical_Sync;
use WebVouch\WooCommerce\Http_Result;
use WebVouch\WooCommerce\Logger;
use WebVouch\WooCommerce\Retry_Policy;
use WebVouch\WooCommerce\Settings;
use WebVouch\WooCommerce\Updater;
use WebVouch\WooCommerce\Widget_Renderer;
use WebVouch\WooCommerce\Widget_State;

/** @param mixed $actual @param mixed $expected */
function assert_same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException(
			$message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true )
		);
	}
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @param array<string,mixed> $body @param array<string,string> $headers @return array<string,mixed> */
function response( int $status, array $body, array $headers = array() ): array {
	return array(
		'response' => array( 'code' => $status ),
		'body'     => json_encode( $body, JSON_THROW_ON_ERROR ),
		'headers'  => array_change_key_case( $headers, CASE_LOWER ),
	);
}

function configure_settings(): Settings {
	$GLOBALS['wv_test_options'][ Settings::OPTION_KEY ] = array(
		'api_base_url' => 'https://api.example.test/api',
		'client_id' => 'wv_client_' . str_repeat( 'A', 32 ),
		'client_secret' => 'wv_secret_' . str_repeat( 'B', 43 ),
		'template_id' => 'template-1',
		'trigger' => 'order_confirmed',
		'enabled' => true,
	);
	unset( $GLOBALS['wv_test_options'][ Settings::TOKEN_OPTION_KEY ] );
	unset( $GLOBALS['wv_test_options'][ Settings::WIDGET_TOKEN_OPTION_KEY ] );
	unset( $GLOBALS['wv_test_options'][ Settings::WIDGETS_OPTION_KEY ] );
	return new Settings();
}

function test_retry_policy(): void {
	$policy = new Retry_Policy();

	assert_same( $policy->decide( Http_Result::transport_failure( 'http_request_failed' ), 1 )['delay'], 60, 'Network failures start with a one-minute retry.' );
	assert_true( $policy->decide( new Http_Result( 503, array() ), 5 )['retry'], 'The fifth failure still schedules the final retry.' );
	assert_true( ! $policy->decide( new Http_Result( 503, array() ), 6 )['retry'], 'The sixth failure is terminal.' );
	assert_same( $policy->decide( new Http_Result( 429, array( 'error' => array( 'code' => 'rate_limited' ) ), '', 5 ), 1 )['delay'], 60, 'Retry-After is clamped to one minute.' );
	assert_same( $policy->decide( new Http_Result( 429, array( 'error' => array( 'code' => 'rate_limited' ) ), '', 999999 ), 1 )['delay'], DAY_IN_SECONDS, 'Retry-After is clamped to one day.' );
	assert_true( $policy->decide( new Http_Result( 409, array( 'error' => array( 'code' => 'idempotency_in_progress' ) ) ), 1 )['retry'], 'An in-progress idempotency lease is retryable.' );
	assert_true( ! $policy->decide( new Http_Result( 409, array( 'error' => array( 'code' => 'idempotency_key_reused' ) ) ), 1 )['retry'], 'A body/key conflict is terminal.' );
	assert_true( ! $policy->decide( new Http_Result( 409, array( 'error' => array( 'code' => 'template_paused' ) ) ), 1 )['retry'], 'A paused template is terminal.' );
	assert_same( ( new Http_Result( 401, array( 'error' => 'invalid_client' ) ) )->error_code(), 'invalid_client', 'OAuth string errors are normalized.' );
	assert_same( ( new Http_Result( 409, array( 'error' => array( 'code' => 'WOOCOMMERCE_IMPORT_LEASE_INVALID' ) ) ) )->error_code(), 'woocommerce_import_lease_invalid', 'Customer API error codes are normalized to lowercase.' );
}

function test_historical_snapshot_pagination_is_stable(): void {
	$settings = configure_settings();
	$client = new API_Client(
		$settings,
		static fn() => response( 500, array( 'error' => array( 'code' => 'unused' ) ) )
	);
	$sync = new Historical_Sync( $settings, $client, new Logger() );
	$page_queries = array();
	$GLOBALS['wv_wc_orders_handler'] = static function ( array $args ) use ( &$page_queries ) {
		if ( 'ids' === ( $args['return'] ?? '' ) ) {
			return range( 1, 205 );
		}
		$page_queries[] = $args;
		return array();
	};
	$state = array(
		'run_id' => 'wchs_' . str_repeat( 'a', 32 ),
		'window_from' => '2026-07-30T12:00:00.000Z',
		'window_to' => '2026-08-06T12:00:00.000Z',
	);
	$snapshot = new ReflectionMethod( Historical_Sync::class, 'snapshot_order_ids' );
	$order_ids = $snapshot->invoke( $sync, $state );
	assert_same( count( $order_ids ), 205, 'The bounded historical snapshot captures the immutable order-id set.' );
	$state['order_ids'] = $order_ids;
	$page = new ReflectionMethod( Historical_Sync::class, 'orders_for_page' );
	assert_true( $page->invoke( $sync, $state, 2 )['has_more'], 'The second page keeps the final snapshot page queued.' );
	assert_true( ! $page->invoke( $sync, $state, 3 )['has_more'], 'The final page stops at the captured snapshot boundary.' );
	assert_same( $page_queries[0]['post__in'] ?? null, range( 101, 200 ), 'The second order query is fenced to its immutable order-id page.' );
	assert_same( $page_queries[1]['post__in'] ?? null, range( 201, 205 ), 'The final order query uses only the remaining snapshot ids.' );
	foreach ( $page_queries as $page_query ) {
		assert_true( ! isset( $page_query['include'] ) && ! isset( $page_query['id'] ), 'Historical order queries use the HPOS and CPT compatible post__in argument.' );
	}
	$GLOBALS['wv_wc_orders_handler'] = null;
}

function test_historical_reclaim_completes_a_persisted_stop_without_page_251(): void {
	$settings = configure_settings();
	$run_id = 'wchs_' . str_repeat( 'c', 32 );
	$installation_id = 'wvwc_' . str_repeat( 'd', 32 );
	$GLOBALS['wv_test_options'][ Settings::INSTALLATION_OPTION_KEY ] = $installation_id;
	$GLOBALS['wv_test_options'][ Settings::CONNECTION_OPTION_KEY ] = array( 'generation' => 'generation-final-page', 'last_seen_at' => '2026-08-06T12:00:00.000Z' );
	$settings->cache_token( 'wv_at_' . str_repeat( 'R', 43 ), time() + 604800 );
	$settings->store_historical_state(
		array(
			'run_id' => $run_id,
			'claim_key' => 'wv-wc-old-expired-claim',
			'page' => 251,
			'sequence' => 251,
			'order_ids' => range( 1, 25000 ),
		)
	);
	$calls = array();
	$client = new API_Client(
		$settings,
		static function ( string $method, string $url, array $args ) use ( &$calls, $run_id ) {
			$calls[] = array( $method, $url, $args );
			if ( str_ends_with( $url, '/claim' ) ) {
				return response(
					200,
					array(
						'leaseToken' => str_repeat( 'L', 43 ),
						'leaseExpiresAt' => '2026-08-06T12:30:00.000Z',
						'nextSequence' => 251,
						'nextPage' => 251,
						'readyToComplete' => true,
						'run' => array(
							'runId' => $run_id,
							'lookback' => '90d',
							'trigger' => 'order_confirmed',
							'windowFrom' => '2026-05-08T12:00:00.000Z',
							'windowTo' => '2026-08-06T12:00:00.000Z',
						),
					)
				);
			}
			if ( str_ends_with( $url, '/complete' ) ) {
				return response( 200, array( 'state' => 'partially_completed' ) );
			}
			return response( 500, array( 'error' => array( 'code' => 'unexpected_page_upload' ) ) );
		}
	);

	( new Historical_Sync( $settings, $client, new Logger() ) )->process( $run_id, 251 );

	assert_same( count( $calls ), 2, 'A reclaimed stopped run claims and completes without uploading page 251.' );
	assert_true( str_ends_with( $calls[0][1], '/claim' ), 'The stopped run obtains a fresh lease first.' );
	assert_true( str_ends_with( $calls[1][1], '/complete' ), 'The fresh lease completes the persisted stop decision.' );
	assert_same( $settings->historical_state(), array(), 'Successful completion clears the local recovery checkpoint.' );
}

function test_updater_rejects_checksum_mismatch(): void {
	$package = 'https://webvouch.com/downloads/woocommerce/webvouch-for-woocommerce-9.9.9.zip';
	$GLOBALS['wv_test_site_transients']['webvouch_wc_release_manifest_v1'] = array(
		'version' => '9.9.9',
		'downloadUrl' => $package,
		'sha256' => str_repeat( 'a', 64 ),
	);
	$file = tempnam( sys_get_temp_dir(), 'webvouch-updater-' );
	if ( false === $file ) {
		throw new RuntimeException( 'Could not create updater test fixture.' );
	}
	file_put_contents( $file, 'tampered plugin archive' );
	$GLOBALS['wv_test_download_file'] = $file;

	$result = ( new Updater() )->verify_download( false, $package, null, array() );

	assert_true( $result instanceof WP_Error, 'A checksum mismatch returns a WordPress error.' );
	assert_same( $result->get_error_code(), 'webvouch_checksum_mismatch', 'The updater exposes the stable checksum failure code.' );
	assert_true( ! file_exists( $file ), 'The rejected temporary archive is deleted.' );
}

function test_updater_accepts_matching_checksum(): void {
	$package = 'https://webvouch.com/downloads/woocommerce/webvouch-for-woocommerce-9.9.8.zip';
	$file = tempnam( sys_get_temp_dir(), 'webvouch-updater-' );
	if ( false === $file ) {
		throw new RuntimeException( 'Could not create updater test fixture.' );
	}
	file_put_contents( $file, 'verified plugin archive' );
	$GLOBALS['wv_test_site_transients']['webvouch_wc_release_manifest_v1'] = array(
		'version' => '9.9.8',
		'downloadUrl' => $package,
		'sha256' => hash_file( 'sha256', $file ),
	);
	$GLOBALS['wv_test_download_file'] = $file;
	$updater = new Updater();
	$update = $updater->update_metadata( false, array(), 'webvouch-for-woocommerce/webvouch-for-woocommerce.php', array() );
	assert_true( is_array( $update ), 'Publishing update metadata persists its package checksum.' );
	unset( $GLOBALS['wv_test_site_transients']['webvouch_wc_release_manifest_v1'] );

	$result = $updater->verify_download( false, $package, null, array() );

	assert_same( $result, $file, 'A matching release checksum returns the verified download path.' );
	assert_true( file_exists( $file ), 'The accepted archive remains available to the WordPress upgrader.' );
	wp_delete_file( $file );
}

function test_updater_fails_closed_without_expected_checksum(): void {
	$package = 'https://webvouch.com/downloads/woocommerce/webvouch-for-woocommerce-9.9.7.zip';
	unset( $GLOBALS['wv_test_site_transients']['webvouch_wc_release_manifest_v1'] );
	unset( $GLOBALS['wv_test_site_transients'][ 'webvouch_wc_release_digest_' . hash( 'sha256', $package ) ] );

	$result = ( new Updater() )->verify_download( false, $package, null, array() );

	assert_true( $result instanceof WP_Error, 'An official WebVouch package without a known checksum is rejected.' );
	assert_same( $result->get_error_code(), 'webvouch_checksum_unavailable', 'The updater fails closed with a stable missing-checksum code.' );
}

function test_updater_preserves_an_earlier_pre_download_result(): void {
	$earlier = new WP_Error( 'earlier_updater_failure' );
	$result = ( new Updater() )->verify_download(
		$earlier,
		'https://webvouch.com/downloads/woocommerce/webvouch-for-woocommerce-9.9.6.zip',
		null,
		array()
	);

	assert_same( $result, $earlier, 'A prior upgrader filter result is never replaced by the WebVouch updater.' );
}

function test_api_client_token_reuse(): void {
	$settings = configure_settings();
	$calls    = array();
	$token    = 'wv_at_' . str_repeat( 'T', 43 );
	$client   = new API_Client(
		$settings,
		static function ( string $method, string $url, array $args ) use ( &$calls, $token ) {
			$calls[] = array( $method, $url, $args );
			if ( str_ends_with( $url, '/public/v1/oauth/token' ) ) {
				return response( 200, array( 'access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 604800, 'scope' => 'templates:read invitations:write' ) );
			}
			if ( str_contains( $url, '/invitation-templates' ) ) {
				return response( 200, array( 'data' => array(), 'page' => array( 'nextCursor' => null ) ) );
			}
			return response(
				201,
				array( 'results' => array( array( 'outcome' => 'accepted', 'invitationId' => 'invitation-1' ) ) )
			);
		}
	);

	$created = $client->create_invitation( array( 'templateId' => 'template-1' ), 'wv-wc-v1-key' );
	assert_same( $created->status, 201, 'Invitation create returns the API result.' );
	assert_same( count( $calls ), 2, 'The first request exchanges one token.' );
	assert_true( str_starts_with( $calls[0][2]['headers']['Authorization'], 'Basic ' ), 'Token exchange uses HTTP Basic.' );
	assert_same( $calls[1][2]['headers']['Authorization'], 'Bearer ' . $token, 'Invitation create uses the access token.' );
	assert_same( $calls[1][2]['headers']['Idempotency-Key'], 'wv-wc-v1-key', 'Invitation create forwards the idempotency key.' );

	$client->list_templates();
	assert_same( count( $calls ), 3, 'A subsequent request reuses the cached token.' );
}

function test_api_client_refreshes_once_after_401(): void {
	$settings = configure_settings();
	$settings->cache_token( 'wv_at_' . str_repeat( 'O', 43 ), time() + 604800 );
	$calls     = array();
	$new_token = 'wv_at_' . str_repeat( 'N', 43 );
	$client    = new API_Client(
		$settings,
		static function ( string $method, string $url, array $args ) use ( &$calls, $new_token ) {
			$calls[] = array( $method, $url, $args );
			if ( str_ends_with( $url, '/public/v1/oauth/token' ) ) {
				return response( 200, array( 'access_token' => $new_token, 'expires_in' => 604800, 'scope' => 'templates:read invitations:write' ) );
			}
			if ( 'Bearer ' . $new_token === ( $args['headers']['Authorization'] ?? '' ) ) {
				return response( 200, array( 'organization' => array( 'id' => 'org' ), 'company' => array( 'id' => 'company' ) ) );
			}
			return response( 401, array( 'error' => array( 'code' => 'invalid_token' ) ) );
		}
	);

	assert_same( $client->verify_account()->status, 200, 'A 401 refreshes the cached token once.' );
	assert_same( count( $calls ), 3, 'The 401 path performs request, token refresh, request.' );
}

function test_woocommerce_protocol_uses_fixed_paths_and_fences(): void {
	$settings = configure_settings();
	$settings->cache_token( 'wv_at_' . str_repeat( 'F', 43 ), time() + 604800 );
	$calls = array();
	$client = new API_Client(
		$settings,
		static function ( string $method, string $url, array $args ) use ( &$calls ) {
			$calls[] = array( $method, $url, $args );
			return response( 200, array( 'outcome' => 'ignored', 'invitationId' => null, 'reason' => 'automation_disabled' ) );
		}
	);
	$fence = array(
		'installationId' => 'wvwc_' . str_repeat( 'a', 32 ),
		'generation' => 'generation-123',
	);
	$client->register_connection( $fence, 'wv-wc-register-key' );
	$client->create_order_invitation( $fence, 'wv-wc-live-key' );
	$client->claim_historical_run( 'wchs_' . str_repeat( 'b', 32 ), $fence, 'wv-wc-claim-key' );

	assert_same( $calls[0][0], 'PUT', 'Registration uses PUT.' );
	assert_true( str_ends_with( $calls[0][1], '/public/v1/integrations/woocommerce/connection' ), 'Registration uses the fixed connection endpoint.' );
	assert_same( $calls[1][2]['headers']['Idempotency-Key'], 'wv-wc-live-key', 'Live orders forward their stable idempotency key.' );
	assert_true( str_contains( $calls[2][1], '/integrations/woocommerce/historical-sync/wchs_' ), 'Historical claims encode the bounded run path.' );
}

function test_json_encoding_failure_is_local(): void {
	$settings = configure_settings();
	$calls    = 0;
	$client   = new API_Client(
		$settings,
		static function () use ( &$calls ) {
			++$calls;
			return new WP_Error( 'unexpected_network_call' );
		}
	);

	$result = $client->create_invitation( array( 'invalid' => "\xB1\x31" ), 'wv-wc-v1-key' );
	assert_same( $result->error_code(), 'json_encode_failed', 'Invalid UTF-8 is classified before transport.' );
	assert_same( $calls, 0, 'Encoding failure does not make an HTTP request.' );
}

function test_api_url_policy(): void {
	$settings = configure_settings();
	$method   = new ReflectionMethod( Settings::class, 'sanitize_api_base_url' );

	assert_same( $method->invoke( $settings, 'https://api.webvouch.com/api/' ), 'https://api.webvouch.com/api', 'HTTPS API URLs are normalized.' );
	assert_same( $method->invoke( $settings, 'http://host.docker.internal:4000/api' ), 'http://host.docker.internal:4000/api', 'The local Docker host is allowed over HTTP.' );
	assert_same( $method->invoke( $settings, 'http://example.com/api' ), null, 'Remote plaintext HTTP is rejected.' );
	assert_same( $method->invoke( $settings, 'https://user@example.com/api' ), null, 'Embedded URL credentials are rejected.' );
	assert_same( $method->invoke( $settings, 'https://api.example.com/api?secret=value' ), null, 'Query strings are rejected from the fixed base URL.' );
}

function test_widget_token_is_isolated_from_automation_token(): void {
	$settings = configure_settings();
	$calls    = array();
	$automation_token = 'wv_at_' . str_repeat( 'A', 43 );
	$widget_token     = 'wv_at_' . str_repeat( 'W', 43 );
	$client = new API_Client(
		$settings,
		static function ( string $method, string $url, array $args ) use ( &$calls, $automation_token, $widget_token ) {
			$calls[] = array( $method, $url, $args );
			if ( str_ends_with( $url, '/public/v1/oauth/token' ) ) {
				$scope = str_contains( (string) ( $args['body'] ?? '' ), 'widgets%3Aread' )
					? 'widgets:read widgets:write'
					: 'templates:read invitations:write';
				return response(
					200,
					array(
						'access_token' => str_starts_with( $scope, 'widgets' ) ? $widget_token : $automation_token,
						'expires_in' => 604800,
						'scope' => $scope,
					)
				);
			}
			if ( str_ends_with( $url, '/public/v1/widgets' ) ) {
				return response( 200, array( 'data' => array() ) );
			}
			return response( 200, array( 'data' => array() ) );
		}
	);

	$client->list_templates();
	$client->list_widgets();
	$client->list_templates();
	$client->list_widgets();

	assert_same( count( array_filter( $calls, static fn( array $call ): bool => str_ends_with( $call[1], '/public/v1/oauth/token' ) ) ), 2, 'Automation and widget profiles exchange one isolated token each.' );
	assert_same( $settings->get_cached_token()['access_token'], $automation_token, 'Automation retains its own token.' );
	assert_same( $settings->get_cached_token( 'widgets' )['access_token'], $widget_token, 'Widget operations retain a separate token.' );
}

function test_widget_activation_sends_json_object_body(): void {
	$settings = configure_settings();
	$calls    = array();
	$client   = new API_Client(
		$settings,
		static function ( string $method, string $url, array $args ) use ( &$calls ) {
			$calls[] = array( $method, $url, $args );
			if ( str_ends_with( $url, '/public/v1/oauth/token' ) ) {
				return response(
					200,
					array(
						'access_token' => 'wv_at_' . str_repeat( 'W', 43 ),
						'expires_in'   => 604800,
						'scope'        => 'widgets:read widgets:write',
					)
				);
			}
			return response( 200, array( 'type' => 'carousel' ) );
		}
	);

	$result = $client->activate_widget( 'carousel', 'wv-widget-activate-test' );
	assert_same( $result->status, 200, 'Widget activation returns the Customer API response.' );
	$activation_calls = array_values( array_filter( $calls, static fn( array $call ): bool => str_ends_with( $call[1], '/public/v1/widgets/carousel/activate' ) ) );
	assert_same( count( $activation_calls ), 1, 'Widget activation performs one mutation request.' );
	assert_same( $activation_calls[0][2]['body'] ?? null, '{}', 'An empty Customer API body is encoded as a JSON object instead of PHP array() becoming JSON [].' );
	assert_same( $activation_calls[0][2]['headers']['Content-Type'] ?? null, 'application/json', 'The empty object is sent with the JSON media type expected by the Customer API.' );
}

function test_widget_state_rejects_invalid_snapshot_without_losing_last_good(): void {
	$settings = configure_settings();
	$GLOBALS['wv_test_options'][ Settings::OPTION_KEY ]['api_base_url'] = 'http://host.docker.internal:4000/api';
	$state = new Widget_State( $settings );
	$catalog = array();
	foreach ( Widget_State::types() as $type => $label ) {
		$catalog[] = array(
			'type' => $type,
			'title' => $label,
			'locked' => false,
			'isActive' => 'carousel' === $type,
			'publicKey' => 'carousel' === $type ? '123456789' : null,
			'loaderUrl' => 'http://localhost:3000/widgets/v1/loader.js',
			'displayData' => array( 'canPublish' => true ),
		);
	}
	assert_same( $state->replace_from_api( $catalog ), true, 'A complete validated widget catalog is stored.' );
	assert_true( $state->renderable( 'carousel' ), 'The active widget becomes renderable from local state.' );
	$before = $state->current();
	$catalog[0]['loaderUrl'] = 'https://attacker.example/widget.js?key=secret';
	$result = $state->replace_from_api( $catalog );
	assert_true( $result instanceof WP_Error, 'An untrusted loader URL is rejected.' );
	assert_same( $state->current(), $before, 'A rejected synchronization preserves the last known-good snapshot.' );
	$catalog[0]['loaderUrl'] = 'http://localhost:3000/widgets/v1/loader.js';
	$catalog[0]['title'] = 'Changed title that must be persisted';
	$GLOBALS['wv_fail_option_write'] = Settings::WIDGETS_OPTION_KEY;
	$result = $state->replace_from_api( $catalog );
	unset( $GLOBALS['wv_fail_option_write'] );
	assert_true( $result instanceof WP_Error, 'A failed WordPress option write is reported.' );
	assert_same( $result->get_error_code(), 'webvouch_widgets_persistence_failed', 'Widget persistence failures use a stable error code.' );
	assert_same( $state->current(), $before, 'A failed option write does not report or expose unpersisted widget state.' );
}

function test_widget_renderer_allowlists_inline_output_and_renders_one_drawer(): void {
	$settings = configure_settings();
	$GLOBALS['wv_test_options'][ Settings::OPTION_KEY ]['api_base_url'] = 'http://host.docker.internal:4000/api';
	$state = new Widget_State( $settings );
	$catalog = array();
	foreach ( Widget_State::types() as $type => $label ) {
		$active = in_array( $type, array( 'carousel', 'side-drawer' ), true );
		$catalog[] = array(
			'type' => $type,
			'title' => $label,
			'locked' => false,
			'isActive' => $active,
			'publicKey' => $active ? ( 'carousel' === $type ? '123456789' : '987654321' ) : null,
			'loaderUrl' => 'http://localhost:3000/widgets/v1/loader.js',
			'displayData' => array( 'canPublish' => true ),
		);
	}
	assert_same( $state->replace_from_api( $catalog ), true, 'The renderer fixture stores a valid widget catalog.' );
	$state->set_drawer_enabled( true );
	$renderer = new Widget_Renderer( $state );

	assert_same( $renderer->render_shortcode( array( 'type' => 'carousel' ) ), '<webvouch-carousel data-key="123456789"></webvouch-carousel>', 'An allowed active inline widget renders canonical markup.' );
	assert_same( $renderer->render_shortcode( array( 'type' => 'side-drawer' ) ), '', 'The global drawer cannot be injected through the shortcode.' );
	assert_same( $renderer->render_shortcode( array( 'type' => 'script' ) ), '', 'Unknown shortcode types are rejected.' );
	ob_start();
	$renderer->render_drawer();
	$first = (string) ob_get_clean();
	ob_start();
	$renderer->render_drawer();
	$second = (string) ob_get_clean();
	assert_same( $first, '<webvouch-side-drawer data-key="987654321"></webvouch-side-drawer>', 'The enabled drawer renders canonical markup.' );
	assert_same( $second, '', 'A renderer instance emits the global drawer at most once.' );

	foreach ( $catalog as &$widget ) {
		$widget['displayData']['canPublish'] = false;
	}
	unset( $widget );
	assert_same( $state->replace_from_api( $catalog ), true, 'A valid unpublished-profile catalog is stored.' );
	assert_true( $state->activated( 'carousel' ), 'An unpublished-profile widget retains its activation and public key.' );
	assert_true( ! $state->renderable( 'carousel' ), 'An unpublished business profile cannot emit storefront widget markup.' );
}

$tests = array(
	'test_retry_policy',
	'test_historical_snapshot_pagination_is_stable',
	'test_historical_reclaim_completes_a_persisted_stop_without_page_251',
	'test_updater_rejects_checksum_mismatch',
	'test_updater_accepts_matching_checksum',
	'test_updater_fails_closed_without_expected_checksum',
	'test_updater_preserves_an_earlier_pre_download_result',
	'test_api_client_token_reuse',
	'test_api_client_refreshes_once_after_401',
	'test_woocommerce_protocol_uses_fixed_paths_and_fences',
	'test_json_encoding_failure_is_local',
	'test_api_url_policy',
	'test_widget_token_is_isolated_from_automation_token',
	'test_widget_activation_sends_json_object_body',
	'test_widget_state_rejects_invalid_snapshot_without_losing_last_good',
	'test_widget_renderer_allowlists_inline_output_and_renders_one_drawer',
);

foreach ( $tests as $test ) {
	$test();
}

echo 'PLUGIN_UNIT_TESTS_OK (' . count( $tests ) . ")\n";
