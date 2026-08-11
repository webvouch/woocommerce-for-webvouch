<?php
/**
 * Action Scheduler queue adapter.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Action_Scheduler_Queue {
	public const HOOK  = 'webvouch_wc_send_invitation';
	public const GROUP = 'webvouch-woocommerce';

	/**
	 * @return int Existing or newly scheduled action ID.
	 */
	public function enqueue( int $order_id, string $trigger, int $attempt, ?int $timestamp = null ): int {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			throw new \RuntimeException( 'Action Scheduler is unavailable.' );
		}

		$args     = array( $order_id, $trigger, $attempt );
		$existing = as_has_scheduled_action( self::HOOK, $args, self::GROUP );
		if ( false !== $existing ) {
			return (int) $existing;
		}

		$action_id = as_schedule_single_action(
			max( time() + 1, $timestamp ?? time() + 1 ),
			self::HOOK,
			$args,
			self::GROUP
		);
		if ( ! is_int( $action_id ) || $action_id < 1 ) {
			throw new \RuntimeException( 'Action Scheduler rejected the WebVouch action.' );
		}

		return $action_id;
	}

	public static function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, null, self::GROUP );
		}
	}
}

