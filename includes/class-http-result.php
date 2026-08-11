<?php
/**
 * Normalized WebVouch HTTP result.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Http_Result {
	/**
	 * @param int                 $status        HTTP status, or zero for transport failure.
	 * @param array<string,mixed> $body          Decoded response body.
	 * @param string              $transportCode Redacted WordPress transport error code.
	 * @param int|null            $retryAfter    Retry delay in seconds.
	 * @param bool                $replayed      Whether WebVouch replayed an idempotent result.
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $body = array(),
		public readonly string $transportCode = '',
		public readonly ?int $retryAfter = null,
		public readonly bool $replayed = false
	) {
	}

	public static function transport_failure( string $code ): self {
		return new self( 0, array(), sanitize_key( $code ) ?: 'http_error' );
	}

	public function is_transport_failure(): bool {
		return 0 === $this->status;
	}

	public function is_http_success(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	public function error_code(): string {
		if ( '' !== $this->transportCode ) {
			return $this->transportCode;
		}

		$error = $this->body['error'] ?? null;
		if ( is_string( $error ) ) {
			return sanitize_key( $error );
		}
		if ( is_array( $error ) && is_string( $error['code'] ?? null ) ) {
			return sanitize_key( $error['code'] );
		}

		return $this->is_http_success() ? '' : 'http_' . $this->status;
	}
}

