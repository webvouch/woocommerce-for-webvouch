<?php
/**
 * Captures WooCommerce order events and coordinates one WebVouch operation.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Order_Coordinator {
	private const TERMINAL_ORDER_STATUSES = array( 'cancelled', 'refunded', 'failed', 'trash' );
	private const TERMINAL_SKIP_REASONS   = array(
		'already_invited',
		'suppressed',
		'invalid_email',
		'duplicate_input',
		'organization_not_found',
		'customer_email_missing',
		'integration_plan_required',
		'plan_required',
		'active_template_required',
		'template_paused',
		'recipient_throttled',
		'plan_limit',
		'automation_disabled',
	);

	public function __construct(
		private readonly Settings $settings,
		private readonly API_Client $api,
		private readonly Retry_Policy $retry_policy,
		private readonly Action_Scheduler_Queue $queue,
		private readonly Order_State $state,
		private readonly Logger $logger
	) {
	}

	public function capture_processing( int $order_id, $order = null ): void {
		$this->capture( $order_id, 'order_confirmed', $order );
	}

	public function capture_completed( int $order_id, $order = null ): void {
		$this->capture( $order_id, 'order_completed', $order );
	}

	public function capture( int $order_id, string $trigger, $order = null ): void {
		$config = $this->settings->get();
		if ( empty( $config['enabled'] ) || $config['trigger'] !== $trigger ) {
			return;
		}
		if ( ! $this->settings->is_automation_ready() ) {
			$this->logger->warning( 'WebVouch order capture skipped because configuration is incomplete.', array( 'order_id' => $order_id, 'trigger' => $trigger ) );
			return;
		}

		$order = $order && is_a( $order, 'WC_Order' ) ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			$this->logger->warning( 'WebVouch order capture could not load the order.', array( 'order_id' => $order_id, 'trigger' => $trigger ) );
			return;
		}

		$current_state = $this->state->load( $order );
		if ( $this->state->is_complete( $current_state ) ) {
			return;
		}

		$same_operation = ( $current_state['trigger'] ?? '' ) === $trigger;
		$event_at       = $same_operation && is_string( $current_state['event_at'] ?? null )
			? $current_state['event_at']
			: $this->event_timestamp( $order, $trigger );
		$key            = $same_operation && is_string( $current_state['idempotency_key'] ?? null )
			? $current_state['idempotency_key']
			: $this->idempotency_key( $order_id, $trigger );

		try {
			$action_id = $this->queue->enqueue( $order_id, $trigger, 1 );
			$this->state->begin( $order, $trigger, $event_at, $key, 1, $action_id );
			$this->logger->info(
				'WebVouch invitation action queued.',
				array(
					'order_id'  => $order_id,
					'action_id' => $action_id,
					'attempt'   => 1,
					'trigger'   => $trigger,
				)
			);
		} catch ( \Throwable $error ) {
			$this->state->mark_failed( $order, 'queue_unavailable' );
			$this->logger->error( 'WebVouch invitation action could not be queued.', array( 'order_id' => $order_id, 'trigger' => $trigger, 'error_code' => 'queue_unavailable' ) );
		}
	}

	public function process( int $order_id, string $trigger, int $attempt = 1 ): void {
		$trigger = sanitize_key( $trigger );
		$attempt = max( 1, $attempt );
		$order   = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->logger->warning( 'WebVouch worker could not load the order.', array( 'order_id' => $order_id, 'attempt' => $attempt, 'trigger' => $trigger, 'outcome' => 'missing_order' ) );
			return;
		}

		$current_state = $this->state->load( $order );
		if ( $this->state->is_complete( $current_state ) ) {
			$this->logger->info( 'WebVouch worker found terminal local state.', array( 'order_id' => $order_id, 'attempt' => $attempt, 'trigger' => $trigger, 'outcome' => (string) ( $current_state['status'] ?? 'complete' ) ) );
			return;
		}

		$config = $this->settings->get();
		if ( $config['trigger'] !== $trigger ) {
			$this->logger->info( 'Stale WebVouch action exited after the configured trigger changed.', array( 'order_id' => $order_id, 'attempt' => $attempt, 'trigger' => $trigger, 'outcome' => 'stale_trigger' ) );
			return;
		}

		$status = (string) $order->get_status();
		if ( in_array( $status, self::TERMINAL_ORDER_STATUSES, true ) ) {
			$this->state->mark_skipped( $order, 'order_' . $status );
			$this->logger->info( 'WebVouch invitation skipped for a terminal order.', array( 'order_id' => $order_id, 'attempt' => $attempt, 'trigger' => $trigger, 'outcome' => 'order_' . $status ) );
			return;
		}
		if ( ! $this->is_safe_status( $trigger, $status ) ) {
			$this->logger->info( 'WebVouch action exited for a nonterminal nonmatching order status.', array( 'order_id' => $order_id, 'attempt' => $attempt, 'trigger' => $trigger, 'outcome' => 'status_' . $status ) );
			return;
		}

		if ( ! $this->settings->is_automation_ready() ) {
			$this->state->mark_failed( $order, 'configuration_missing' );
			$this->logger->error( 'WebVouch invitation failed because configuration is incomplete.', array( 'order_id' => $order_id, 'attempt' => $attempt, 'trigger' => $trigger, 'error_code' => 'configuration_missing' ) );
			return;
		}

		$event_at = is_string( $current_state['event_at'] ?? null )
			? $current_state['event_at']
			: $this->event_timestamp( $order, $trigger );
		$key      = is_string( $current_state['idempotency_key'] ?? null )
			? $current_state['idempotency_key']
			: $this->idempotency_key( $order_id, $trigger );
		if ( empty( $current_state ) ) {
			$this->state->begin( $order, $trigger, $event_at, $key, $attempt, 0 );
		}
		$this->state->mark_processing( $order, $attempt );

		$email = sanitize_email( (string) $order->get_billing_email() );
		if ( '' === $email || ! is_email( $email ) ) {
			$this->state->mark_skipped( $order, 'invalid_email' );
			$this->logger->warning( 'WebVouch invitation skipped because the order email is invalid.', array( 'order_id' => $order_id, 'attempt' => $attempt, 'trigger' => $trigger, 'outcome' => 'invalid_email' ) );
			return;
		}

		$payload = array(
			'installationId'  => Settings::installation_id(),
			'generation'      => $this->settings->generation(),
			'externalOrderId' => (string) $order_id,
			'status'          => $status,
			'occurredAt'      => $event_at,
			'email'           => $email,
		);
		$name = $this->customer_name( $order );
		if ( '' !== $name ) {
			$payload['name'] = $name;
		}
		$result = $this->api->create_order_invitation( $payload, $key );

		if ( $result->is_http_success() ) {
			if ( $this->handle_success( $order, $result, $order_id, $attempt, $trigger ) ) {
				return;
			}
			$result = new Http_Result( 502, array( 'error' => array( 'code' => 'invalid_invitation_response' ) ) );
		}

		$decision = $this->retry_policy->decide( $result, $attempt );
		if ( $decision['retry'] ) {
			try {
				$action_id = $this->queue->enqueue( $order_id, $trigger, $attempt + 1, time() + $decision['delay'] );
				$this->state->mark_retrying( $order, $attempt, $action_id, $decision['delay'], $decision['error_code'], $result->status );
				$this->logger->warning(
					'WebVouch invitation retry scheduled.',
					array(
						'order_id'       => $order_id,
						'action_id'      => $action_id,
						'attempt'        => $attempt,
						'trigger'        => $trigger,
						'error_code'     => $decision['error_code'],
						'http_status'    => $result->status,
						'delay_seconds'  => $decision['delay'],
					)
				);
				return;
			} catch ( \Throwable $error ) {
				$decision['error_code'] = 'retry_queue_unavailable';
			}
		}

		$this->state->mark_failed( $order, $decision['error_code'], $result->status );
		$this->logger->error(
			'WebVouch invitation reached a terminal failure.',
			array(
				'order_id'    => $order_id,
				'attempt'     => $attempt,
				'trigger'     => $trigger,
				'error_code'  => $decision['error_code'],
				'http_status' => $result->status,
			)
		);
	}

	private function handle_success( $order, Http_Result $result, int $order_id, int $attempt, string $trigger ): bool {
		$outcome = $result->body['outcome'] ?? null;
		if ( 'scheduled' === $outcome && is_string( $result->body['invitationId'] ?? null ) && '' !== $result->body['invitationId'] ) {
			$this->state->mark_accepted( $order, $result->body['invitationId'], $result->replayed, $result->status );
			$this->logger->info( 'WebVouch invitation accepted.', array( 'order_id' => $order_id, 'attempt' => $attempt, 'trigger' => $trigger, 'outcome' => 'accepted', 'replayed' => $result->replayed, 'http_status' => $result->status ) );
			return true;
		}

		$reason = is_string( $result->body['reason'] ?? null ) ? sanitize_key( $result->body['reason'] ) : '';
		if ( 'duplicate' === $outcome ) {
			$reason = 'already_invited';
		}
		if ( in_array( $outcome, array( 'duplicate', 'ignored' ), true ) && in_array( $reason, self::TERMINAL_SKIP_REASONS, true ) ) {
			$this->state->mark_skipped( $order, $reason, $result->status, $result->replayed );
			$this->logger->info( 'WebVouch invitation reached a terminal skipped result.', array( 'order_id' => $order_id, 'attempt' => $attempt, 'trigger' => $trigger, 'outcome' => $reason, 'replayed' => $result->replayed, 'http_status' => $result->status ) );
			return true;
		}

		return false;
	}

	private function is_safe_status( string $trigger, string $status ): bool {
		return 'order_confirmed' === $trigger
			? in_array( $status, array( 'processing', 'completed' ), true )
			: 'order_completed' === $trigger && 'completed' === $status;
	}

	private function event_timestamp( $order, string $trigger ): string {
		$date = 'order_completed' === $trigger ? $order->get_date_completed() : $order->get_date_paid();
		$timestamp = $date && method_exists( $date, 'getTimestamp' ) ? $date->getTimestamp() : time();
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	private function idempotency_key( int $order_id, string $trigger ): string {
		$identity = strtolower( untrailingslashit( home_url( '/' ) ) ) . '|' . get_current_blog_id() . '|' . $order_id . '|' . $trigger;
		return 'wv-wc-v1-' . hash( 'sha256', $identity );
	}

	private function external_reference( int $order_id ): string {
		return 'wc:' . get_current_blog_id() . ':' . $order_id;
	}

	private function customer_name( $order ): string {
		$name = trim( sanitize_text_field( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $name, 0, 120, 'UTF-8' );
		}
		$characters = preg_split( '//u', $name, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $characters ) ? implode( '', array_slice( $characters, 0, 120 ) ) : substr( $name, 0, 120 );
	}
}
