<?php
/**
 * Read-only merchant visibility for the invitation state on an order.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Order_Status_Panel {
	public function __construct( private readonly Order_State $state ) {
	}

	public function boot(): void {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
	}

	public function register(): void {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box(
				'webvouch-wc-invitation',
				__( 'WebVouch invitation', 'webvouch-for-woocommerce' ),
				array( $this, 'render' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/** @param mixed $post_or_order */
	public function render( $post_or_order ): void {
		$order = is_a( $post_or_order, 'WC_Order' )
			? $post_or_order
			: wc_get_order( is_object( $post_or_order ) && isset( $post_or_order->ID ) ? (int) $post_or_order->ID : 0 );
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order state is unavailable.', 'webvouch-for-woocommerce' ) . '</p>';
			return;
		}

		$state  = $this->state->load( $order );
		$status = is_string( $state['status'] ?? null ) ? sanitize_key( $state['status'] ) : 'not_scheduled';
		$labels = array(
			'not_scheduled' => __( 'Not scheduled', 'webvouch-for-woocommerce' ),
			'queued'        => __( 'Queued', 'webvouch-for-woocommerce' ),
			'processing'    => __( 'Sending request', 'webvouch-for-woocommerce' ),
			'retrying'      => __( 'Retry scheduled', 'webvouch-for-woocommerce' ),
			'accepted'      => __( 'Accepted by WebVouch', 'webvouch-for-woocommerce' ),
			'skipped'       => __( 'Skipped', 'webvouch-for-woocommerce' ),
			'failed'        => __( 'Failed', 'webvouch-for-woocommerce' ),
		);

		echo '<p><strong>' . esc_html( $labels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) ) ) . '</strong></p>';
		if ( is_numeric( $state['attempt'] ?? null ) ) {
			printf( '<p>%1$s: %2$d</p>', esc_html__( 'Attempt', 'webvouch-for-woocommerce' ), (int) $state['attempt'] );
		}
		if ( is_string( $state['skipped_reason'] ?? null ) && '' !== $state['skipped_reason'] ) {
			printf( '<p>%1$s: <code>%2$s</code></p>', esc_html__( 'Reason', 'webvouch-for-woocommerce' ), esc_html( $state['skipped_reason'] ) );
		}
		if ( is_string( $state['error_code'] ?? null ) && '' !== $state['error_code'] ) {
			printf( '<p>%1$s: <code>%2$s</code></p>', esc_html__( 'Last error', 'webvouch-for-woocommerce' ), esc_html( $state['error_code'] ) );
		}
		if ( is_string( $state['updated_at'] ?? null ) && '' !== $state['updated_at'] ) {
			printf( '<p class="description">%1$s: %2$s</p>', esc_html__( 'Updated', 'webvouch-for-woocommerce' ), esc_html( $state['updated_at'] ) );
		}
	}
}
