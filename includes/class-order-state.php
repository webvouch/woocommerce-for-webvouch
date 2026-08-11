<?php
/**
 * Non-PII invitation state stored through WooCommerce order meta.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Order_State {
	public const META_KEY = '_webvouch_wc_invitation_v1';

	/** @return array<string,mixed> */
	public function load( $order ): array {
		$value = $order->get_meta( self::META_KEY, true );
		return is_array( $value ) ? $value : array();
	}

	/** @param array<string,mixed> $state */
	public function is_complete( array $state ): bool {
		return in_array( $state['status'] ?? '', array( 'accepted', 'skipped', 'failed' ), true );
	}

	public function begin(
		$order,
		string $trigger,
		string $event_at,
		string $idempotency_key,
		int $attempt,
		int $action_id
	): void {
		$this->save(
			$order,
			array(
				'status'          => 'queued',
				'trigger'         => $trigger,
				'event_at'        => $event_at,
				'idempotency_key' => $idempotency_key,
				'attempt'         => $attempt,
				'action_id'       => $action_id,
			)
		);
	}

	public function mark_processing( $order, int $attempt ): void {
		$state            = $this->load( $order );
		$state['status']   = 'processing';
		$state['attempt']  = $attempt;
		$state['action_id'] = null;
		$this->save( $order, $state );
	}

	public function mark_accepted( $order, string $invitation_id, bool $replayed, int $http_status = 201 ): void {
		$state                  = $this->load( $order );
		$state['status']         = 'accepted';
		$state['outcome']        = 'accepted';
		$state['invitation_id']  = $invitation_id;
		$state['replayed']       = $replayed;
		$state['http_status']    = $http_status;
		$state['error_code']     = null;
		$state['action_id']      = null;
		$this->save( $order, $state );
	}

	public function mark_skipped( $order, string $reason, int $http_status = 0, bool $replayed = false ): void {
		$state                 = $this->load( $order );
		$state['status']        = 'skipped';
		$state['outcome']       = 'skipped';
		$state['skipped_reason'] = sanitize_key( $reason );
		$state['replayed']      = $replayed;
		$state['http_status']   = $http_status;
		$state['error_code']    = null;
		$state['action_id']     = null;
		$this->save( $order, $state );
	}

	public function mark_retrying(
		$order,
		int $attempt,
		int $action_id,
		int $delay,
		string $error_code,
		int $http_status
	): void {
		$state                  = $this->load( $order );
		$state['status']         = 'retrying';
		$state['attempt']        = $attempt;
		$state['action_id']      = $action_id;
		$state['retry_delay']    = $delay;
		$state['error_code']     = sanitize_key( $error_code );
		$state['http_status']    = $http_status;
		$this->save( $order, $state );
	}

	public function mark_failed( $order, string $error_code, int $http_status = 0 ): void {
		$state                = $this->load( $order );
		$state['status']       = 'failed';
		$state['outcome']      = 'failed';
		$state['error_code']   = sanitize_key( $error_code ) ?: 'request_failed';
		$state['http_status']  = $http_status;
		$state['action_id']    = null;
		$this->save( $order, $state );
	}

	public function delete( $order ): void {
		$order->delete_meta_data( self::META_KEY );
		$order->save_meta_data();
	}

	/** @param array<string,mixed> $state */
	private function save( $order, array $state ): void {
		$state['updated_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		$order->update_meta_data( self::META_KEY, $state );
		$order->save_meta_data();
	}
}
