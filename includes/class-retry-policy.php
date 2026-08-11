<?php
/**
 * WebVouch request retry classification.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Retry_Policy {
	private const DELAYS = array( 60, 300, 1800, 7200, 43200 );

	/** @return array{retry:bool,delay:int,error_code:string} */
	public function decide( Http_Result $result, int $attempt ): array {
		$error_code = $result->error_code();
		$retryable  = $result->is_transport_failure()
			|| in_array( $result->status, array( 408, 425 ), true )
			|| $result->status >= 500
			|| ( 429 === $result->status && 'rate_limited' === $error_code )
			|| ( 409 === $result->status && 'idempotency_in_progress' === $error_code );

		$delay_index = $attempt - 1;
		if ( ! $retryable || ! isset( self::DELAYS[ $delay_index ] ) ) {
			return array(
				'retry'      => false,
				'delay'      => 0,
				'error_code' => $error_code ?: 'request_failed',
			);
		}

		$delay = self::DELAYS[ $delay_index ];
		if ( null !== $result->retryAfter ) {
			$delay = max( 60, min( DAY_IN_SECONDS, $result->retryAfter ) );
		}

		return array(
			'retry'      => true,
			'delay'      => $delay,
			'error_code' => $error_code ?: 'request_failed',
		);
	}
}

