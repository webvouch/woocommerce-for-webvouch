<?php
/**
 * Bounded, resumable historical-order importer.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Historical_Sync {
	public const HOOK = 'webvouch_wc_historical_sync';
	private const GROUP = 'webvouch-woocommerce';
	private const PAGE_SIZE = 100;
	private const MAX_ORDER_IDS = 25000;
	private const MAX_RETRY_DELAY = HOUR_IN_SECONDS;

	public function __construct(
		private readonly Settings $settings,
		private readonly API_Client $api,
		private readonly Logger $logger
	) {
	}

	public function schedule( string $run_id, ?int $timestamp = null, ?int $checkpoint = null, bool $force = false ): void {
		if ( ! preg_match( '/^wchs_[a-f0-9]{32}$/', $run_id ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( null === $checkpoint ) {
			$state = $this->settings->historical_state();
			$checkpoint = $run_id === ( $state['run_id'] ?? '' ) ? max( 0, (int) ( $state['page'] ?? 0 ) ) : 0;
		}
		$args = array( $run_id, $checkpoint );
		if ( ! $force && function_exists( 'as_has_scheduled_action' ) && false !== as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return;
		}
		as_schedule_single_action( max( time() + 1, $timestamp ?? time() + 1 ), self::HOOK, $args, self::GROUP );
	}

	public function process( string $run_id, int $checkpoint = 0 ): void {
		$generation = $this->settings->generation();
		if ( '' === $generation || ! $this->settings->is_connection_configured() ) {
			return;
		}
		$state = $this->settings->historical_state();
		if ( $run_id !== ( $state['run_id'] ?? '' ) || ! is_string( $state['lease_token'] ?? null ) ) {
			if ( $run_id !== ( $state['run_id'] ?? '' ) || ! is_string( $state['claim_key'] ?? null ) ) {
				$state = array(
					'run_id'    => $run_id,
					'claim_key' => $this->claim_key( $run_id ),
				);
				$this->settings->store_historical_state( $state );
			}
			$claim = $this->api->claim_historical_run( $run_id, $this->fence( $generation ), (string) $state['claim_key'] );
			if ( 200 !== $claim->status ) {
				$this->retry( $run_id, $claim, 'claim' );
				return;
			}
			$state = $this->state_from_claim( $claim->body, $state );
			if ( empty( $state ) ) {
				$this->logger->error( 'WebVouch historical claim response was invalid.', array( 'run_id' => $run_id ) );
				return;
			}
			$state['retry_count'] = 0;
			$this->settings->store_historical_state( $state );
		}
		if ( true === ( $state['ready_to_complete'] ?? false ) ) {
			$this->complete( $run_id, $state, 'completed', null );
			return;
		}
		if ( ! isset( $state['order_ids'] ) || ! is_array( $state['order_ids'] ) ) {
			$order_ids = $this->snapshot_order_ids( $state );
			if ( null === $order_ids ) {
				$this->complete( $run_id, $state, 'failed', 'woocommerce_snapshot_failed' );
				return;
			}
			$state['order_ids'] = $order_ids;
			$this->settings->store_historical_state( $state );
		}

		$page = max( 1, (int) ( $state['page'] ?? 1 ) );
		$sequence = max( 1, (int) ( $state['sequence'] ?? 1 ) );
		$orders = $this->orders_for_page( $state, $page );
		if ( null === $orders ) {
			$this->complete( $run_id, $state, 'failed', 'woocommerce_query_failed' );
			return;
		}

		$payload = array_merge(
			$this->fence( $generation ),
			array(
				'leaseToken'  => (string) $state['lease_token'],
				'sequence'    => $sequence,
				'page'        => $page,
				'scannedCount' => $orders['scanned'],
				'hasMore'     => $orders['has_more'],
				'orders'      => $orders['eligible'],
			)
		);
		$key = 'wv-wc-history-v2-' . hash( 'sha256', $run_id . '|' . $sequence . '|' . $page . '|' . (string) $state['lease_token'] );
		$result = $this->api->upload_historical_batch( $run_id, $payload, $key );
		if ( 200 !== $result->status ) {
			if ( 'woocommerce_import_lease_invalid' === $result->error_code() ) {
				unset( $state['lease_token'], $state['lease_expires_at'] );
				$state['claim_key'] = $this->claim_key( $run_id );
				$state['retry_count'] = 0;
				$this->settings->store_historical_state( $state );
				$this->schedule( $run_id, time() + 5, $page, true );
				return;
			}
			$this->retry( $run_id, $result, 'batch' );
			return;
		}

		$stop = true === ( $result->body['stop'] ?? false );
		if ( $stop ) {
			$outcome = ( $page >= 250 || $page * self::PAGE_SIZE >= 25000 ) ? 'partially_completed' : 'completed';
			$this->complete( $run_id, $state, $outcome, 'completed' === $outcome ? null : 'safety_limit_reached' );
			return;
		}
		$state['page'] = $page + 1;
		$state['sequence'] = $sequence + 1;
		$state['retry_count'] = 0;
		$this->settings->store_historical_state( $state );
		$this->schedule( $run_id, null, $page + 1 );
	}

	/** @return array{scanned:int,has_more:bool,eligible:array<int,array<string,mixed>>}|null */
	private function orders_for_page( array $state, int $page ): ?array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}
		$order_ids = is_array( $state['order_ids'] ?? null ) ? array_values( array_filter( array_map( 'absint', $state['order_ids'] ) ) ) : array();
		$page_ids = array_slice( $order_ids, ( $page - 1 ) * self::PAGE_SIZE, self::PAGE_SIZE );
		if ( empty( $page_ids ) ) {
			return array( 'scanned' => 0, 'has_more' => false, 'eligible' => array() );
		}
		try {
			$result = wc_get_orders(
				array(
					'post__in'     => $page_ids,
					'orderby'      => 'ID',
					'order'        => 'ASC',
					'limit'        => count( $page_ids ),
					'return'       => 'objects',
				)
			);
		} catch ( \Throwable $error ) {
			$this->logger->error( 'WooCommerce historical order query failed.', array( 'run_id' => (string) ( $state['run_id'] ?? '' ), 'page' => $page ) );
			return null;
		}
		$items = is_array( $result ) ? $result : array();
		$eligible = array();
		foreach ( $items as $order ) {
			$mapped = $this->map_order( $order, (string) ( $state['trigger'] ?? '' ) );
			if ( null !== $mapped ) {
				$eligible[] = $mapped;
			}
		}
		return array(
			'scanned'  => count( $items ),
			'has_more' => $page * self::PAGE_SIZE < count( $order_ids ),
			'eligible' => $eligible,
		);
	}

	/** @return array<int,int>|null */
	private function snapshot_order_ids( array $state ): ?array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}
		$from = strtotime( (string) ( $state['window_from'] ?? '' ) );
		$to = strtotime( (string) ( $state['window_to'] ?? '' ) );
		if ( false === $from || false === $to || $from >= $to ) {
			return null;
		}
		try {
			$result = wc_get_orders(
				array(
					'date_created' => $from . '...' . ( $to - 1 ),
					'orderby'      => 'ID',
					'order'        => 'ASC',
					'limit'        => self::MAX_ORDER_IDS,
					'return'       => 'ids',
				)
			);
		} catch ( \Throwable $error ) {
			$this->logger->error( 'WooCommerce historical order snapshot failed.', array( 'run_id' => (string) ( $state['run_id'] ?? '' ) ) );
			return null;
		}
		return is_array( $result ) ? array_values( array_filter( array_map( 'absint', $result ) ) ) : null;
	}

	/** @return array<string,mixed>|null */
	private function map_order( $order, string $trigger ): ?array {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return null;
		}
		$status = (string) $order->get_status();
		$eligible = 'order_completed' === $trigger
			? 'completed' === $status
			: in_array( $status, array( 'processing', 'completed' ), true );
		$email = sanitize_email( (string) $order->get_billing_email() );
		$date = $order->get_date_created();
		if ( ! $eligible || '' === $email || ! is_email( $email ) || ! $date || ! method_exists( $date, 'getTimestamp' ) ) {
			return null;
		}
		$name = trim( sanitize_text_field( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() ) );
		return array(
			'externalOrderId' => (string) $order->get_id(),
			'status'          => $status,
			'occurredAt'      => gmdate( 'Y-m-d\TH:i:s\Z', $date->getTimestamp() ),
			'email'           => $email,
			'name'            => '' === $name ? null : $this->truncate( $name, 120 ),
		);
	}

	/** @return array<string,mixed> */
	private function state_from_claim( array $body, array $previous = array() ): array {
		$run = is_array( $body['run'] ?? null ) ? $body['run'] : array();
		if ( ! is_string( $body['leaseToken'] ?? null ) || ! is_string( $run['windowFrom'] ?? null ) || ! is_string( $run['windowTo'] ?? null ) ) {
			return array();
		}
		$state = array(
			'run_id'      => (string) $run['runId'],
			'lease_token' => (string) $body['leaseToken'],
			'lease_expires_at' => (string) ( $body['leaseExpiresAt'] ?? '' ),
			'page'        => max( 1, (int) ( $body['nextPage'] ?? 1 ) ),
			'sequence'    => max( 1, (int) ( $body['nextSequence'] ?? 1 ) ),
			'ready_to_complete' => true === ( $body['readyToComplete'] ?? false ),
			'trigger'     => (string) ( $run['trigger'] ?? 'order_confirmed' ),
			'window_from' => (string) $run['windowFrom'],
			'window_to'   => (string) $run['windowTo'],
		);
		if ( ( $previous['run_id'] ?? '' ) === $state['run_id'] && is_array( $previous['order_ids'] ?? null ) ) {
			$state['order_ids'] = $previous['order_ids'];
		}
		return $state;
	}

	private function complete( string $run_id, array $state, string $outcome, ?string $outcome_code ): void {
		$payload = array_merge(
			$this->fence( $this->settings->generation() ),
			array( 'leaseToken' => (string) $state['lease_token'], 'outcome' => $outcome )
		);
		if ( null !== $outcome_code ) {
			$payload['outcomeCode'] = $outcome_code;
		}
		$result = $this->api->complete_historical_run( $run_id, $payload, 'wv-wc-complete-v2-' . hash( 'sha256', $run_id . '|' . $outcome . '|' . (string) $state['lease_token'] ) );
		if ( 200 === $result->status ) {
			$this->settings->clear_historical_state();
			$this->logger->info( 'WebVouch historical order sync finished.', array( 'run_id' => $run_id, 'outcome' => $outcome ) );
			return;
		}
		$this->retry( $run_id, $result, 'complete' );
	}

	private function retry( string $run_id, Http_Result $result, string $phase ): void {
		$code = $result->error_code();
		if ( 'woocommerce_import_lease_invalid' === $code ) {
			$state = $this->settings->historical_state();
			unset( $state['lease_token'], $state['lease_expires_at'] );
			$state['claim_key'] = $this->claim_key( $run_id );
			$state['retry_count'] = 0;
			$this->settings->store_historical_state( $state );
			$this->logger->warning( 'WebVouch historical sync lease expired; reclaiming the run.', array( 'run_id' => $run_id, 'phase' => $phase ) );
			$checkpoint = max( 0, (int) ( $state['page'] ?? 0 ) );
			$this->schedule( $run_id, time() + 5, $checkpoint, true );
			return;
		}
		$retryable = $result->is_transport_failure() || 408 === $result->status || 425 === $result->status || 429 === $result->status || $result->status >= 500 || 'woocommerce_import_lease_active' === $code;
		if ( ! $retryable ) {
			$this->logger->error( 'WebVouch historical sync stopped after a non-retryable response.', array( 'run_id' => $run_id, 'phase' => $phase, 'http_status' => $result->status, 'error_code' => $code ) );
			$this->settings->clear_historical_state();
			return;
		}
		$state = $this->settings->historical_state();
		if ( 'claim' === $phase && 'woocommerce_import_lease_active' === $code ) {
			$state['claim_key'] = $this->claim_key( $run_id );
		}
		$attempt = max( 1, (int) ( $state['retry_count'] ?? 0 ) + 1 );
		$state['retry_count'] = $attempt;
		$this->settings->store_historical_state( $state );
		$delay = null !== $result->retryAfter ? max( 5, $result->retryAfter ) : min( self::MAX_RETRY_DELAY, 30 * ( 2 ** min( 7, $attempt - 1 ) ) );
		$this->logger->warning( 'WebVouch historical sync will retry.', array( 'run_id' => $run_id, 'phase' => $phase, 'http_status' => $result->status, 'error_code' => $code, 'retry_in_seconds' => $delay ) );
		$checkpoint = $run_id === ( $state['run_id'] ?? '' ) ? max( 0, (int) ( $state['page'] ?? 0 ) ) : 0;
		$this->schedule( $run_id, time() + $delay, $checkpoint, true );
	}

	private function claim_key( string $run_id ): string {
		return 'wv-wc-claim-v2-' . hash( 'sha256', $run_id . '|' . microtime( true ) . '|' . wp_rand() );
	}

	/** @return array{installationId:string,generation:string} */
	private function fence( string $generation ): array {
		return array( 'installationId' => Settings::installation_id(), 'generation' => $generation );
	}

	private function truncate( string $value, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length, 'UTF-8' ) : substr( $value, 0, $length );
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, null, self::GROUP );
		}
	}
}
