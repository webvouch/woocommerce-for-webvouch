<?php
/**
 * Redacted WooCommerce logger adapter.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logger {
	private const SOURCE = 'webvouch-woocommerce';
	private const ALLOWED_CONTEXT_KEYS = array(
		'order_id',
		'action_id',
		'attempt',
		'trigger',
		'outcome',
		'error_code',
		'http_status',
		'replayed',
		'delay_seconds',
	);

	/** @param array<string,mixed> $context */
	public function info( string $message, array $context = array() ): void {
		$this->write( 'info', $message, $context );
	}

	/** @param array<string,mixed> $context */
	public function warning( string $message, array $context = array() ): void {
		$this->write( 'warning', $message, $context );
	}

	/** @param array<string,mixed> $context */
	public function error( string $message, array $context = array() ): void {
		$this->write( 'error', $message, $context );
	}

	/** @param array<string,mixed> $context */
	private function write( string $level, string $message, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$clean = array( 'source' => self::SOURCE );
		foreach ( self::ALLOWED_CONTEXT_KEYS as $key ) {
			if ( ! array_key_exists( $key, $context ) ) {
				continue;
			}
			$value = $context[ $key ];
			if ( is_bool( $value ) || is_int( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = substr( sanitize_text_field( $value ), 0, 120 );
			}
		}

		$logger = wc_get_logger();
		$logger->log( $level, sanitize_text_field( $message ), $clean );
	}
}

