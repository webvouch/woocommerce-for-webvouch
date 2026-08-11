<?php
/**
 * Durable, validated widget placement state. Storefront reads never call the API.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Widget_State {
	/** @var array<string,string> */
	private const TYPES = array(
		'carousel'   => 'Reviews carousel',
		'badge'      => 'Rating badge',
		'text-badge' => 'Text badge',
		'text-combo' => 'Text and stars',
		'side-drawer' => 'Floating reviews drawer',
	);

	public function __construct( private readonly Settings $settings ) {
	}

	/** @return array<string,string> */
	public static function types(): array {
		return self::TYPES;
	}

	/** @return array<string,string> */
	public static function inline_types(): array {
		$types = self::TYPES;
		unset( $types['side-drawer'] );
		return $types;
	}

	public static function is_type( string $type ): bool {
		return isset( self::TYPES[ $type ] );
	}

	/** @return array<string,mixed> */
	public function current(): array {
		$stored = get_option( Settings::WIDGETS_OPTION_KEY, array() );
		return is_array( $stored )
			? array_merge( $this->defaults(), $stored )
			: $this->defaults();
	}

	/** @param array<int,mixed> $widgets @return true|\WP_Error */
	public function replace_from_api( array $widgets ) {
		if ( count( $widgets ) !== count( self::TYPES ) ) {
			return new \WP_Error( 'webvouch_widgets_invalid_catalog', __( 'WebVouch returned an incomplete widget catalog.', 'webvouch-for-woocommerce' ) );
		}

		$next_widgets = array();
		$loader_url   = null;
		foreach ( $widgets as $widget ) {
			$normalized = $this->normalize_api_widget( $widget );
			if ( $normalized instanceof \WP_Error ) {
				return $normalized;
			}
			$type = $normalized['type'];
			if ( isset( $next_widgets[ $type ] ) ) {
				return new \WP_Error( 'webvouch_widgets_invalid_catalog', __( 'WebVouch returned a duplicate widget type.', 'webvouch-for-woocommerce' ) );
			}
			if ( null === $loader_url ) {
				$loader_url = $normalized['loader_url'];
			} elseif ( $loader_url !== $normalized['loader_url'] ) {
				return new \WP_Error( 'webvouch_widgets_invalid_loader', __( 'WebVouch returned inconsistent widget loader URLs.', 'webvouch-for-woocommerce' ) );
			}
			unset( $normalized['type'], $normalized['loader_url'] );
			$next_widgets[ $type ] = $normalized;
		}

		if ( array_diff_key( self::TYPES, $next_widgets ) ) {
			return new \WP_Error( 'webvouch_widgets_invalid_catalog', __( 'WebVouch returned an incomplete widget catalog.', 'webvouch-for-woocommerce' ) );
		}

		$current = $this->current();
		$next    = array(
			'version'             => 1,
			'loader_url'          => (string) $loader_url,
			'synced_at'           => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'side_drawer_enabled' => ! empty( $current['side_drawer_enabled'] ),
			'widgets'             => $next_widgets,
		);
		if ( ! $this->persist( $next ) ) {
			return new \WP_Error( 'webvouch_widgets_persistence_failed', __( 'WordPress could not save the WebVouch widget catalog.', 'webvouch-for-woocommerce' ) );
		}
		do_action( 'webvouch_wc_widgets_changed', 'sync' );
		return true;
	}

	/** @param mixed $widget @return true|\WP_Error */
	public function update_from_api( $widget ) {
		$normalized = $this->normalize_api_widget( $widget );
		if ( $normalized instanceof \WP_Error ) {
			return $normalized;
		}
		$current = $this->current();
		$type    = $normalized['type'];
		$current['version']    = 1;
		$current['loader_url'] = $normalized['loader_url'];
		$current['synced_at']  = gmdate( 'Y-m-d\TH:i:s\Z' );
		unset( $normalized['type'], $normalized['loader_url'] );
		$current['widgets'][ $type ] = $normalized;
		if ( ! $this->persist( $current ) ) {
			return new \WP_Error( 'webvouch_widgets_persistence_failed', __( 'WordPress could not save the activated WebVouch widget.', 'webvouch-for-woocommerce' ) );
		}
		do_action( 'webvouch_wc_widgets_changed', 'activation' );
		return true;
	}

	/** @return array<string,mixed>|null */
	public function get( string $type ): ?array {
		if ( ! self::is_type( $type ) ) {
			return null;
		}
		$current = $this->current();
		$widget  = $current['widgets'][ $type ] ?? null;
		return is_array( $widget ) ? $widget : null;
	}

	public function renderable( string $type ): bool {
		$widget = $this->get( $type );
		return is_array( $widget )
			&& empty( $widget['locked'] )
			&& ! empty( $widget['can_publish'] )
			&& ! empty( $widget['is_active'] )
			&& is_string( $widget['public_key'] ?? null )
			&& 1 === preg_match( '/^\d{9}$/', $widget['public_key'] );
	}

	public function activated( string $type ): bool {
		$widget = $this->get( $type );
		return is_array( $widget )
			&& empty( $widget['locked'] )
			&& ! empty( $widget['is_active'] )
			&& is_string( $widget['public_key'] ?? null )
			&& 1 === preg_match( '/^\d{9}$/', $widget['public_key'] );
	}

	public function can_publish( string $type ): bool {
		$widget = $this->get( $type );
		return is_array( $widget ) && ! empty( $widget['can_publish'] );
	}

	public function loader_url(): ?string {
		$url = $this->current()['loader_url'] ?? '';
		return is_string( $url ) && $this->valid_loader_url( $url ) ? $url : null;
	}

	public function drawer_enabled(): bool {
		return ! empty( $this->current()['side_drawer_enabled'] );
	}

	/** @return true|\WP_Error */
	public function set_drawer_enabled( bool $enabled ) {
		$current = $this->current();
		$current['side_drawer_enabled'] = $enabled;
		if ( ! $this->persist( $current ) ) {
			return new \WP_Error( 'webvouch_widgets_persistence_failed', __( 'WordPress could not save the WebVouch widget placement.', 'webvouch-for-woocommerce' ) );
		}
		do_action( 'webvouch_wc_widgets_changed', 'placement' );
		return true;
	}

	/** @return array<string,mixed> */
	private function defaults(): array {
		return array(
			'version'             => 1,
			'loader_url'          => '',
			'synced_at'           => '',
			'side_drawer_enabled' => false,
			'widgets'             => array(),
		);
	}

	/** @param mixed $value @return array<string,mixed>|\WP_Error */
	private function normalize_api_widget( $value ) {
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'webvouch_widgets_invalid_catalog', __( 'WebVouch returned invalid widget data.', 'webvouch-for-woocommerce' ) );
		}
		$type       = is_string( $value['type'] ?? null ) ? $value['type'] : '';
		$loader_url = is_string( $value['loaderUrl'] ?? null ) ? $value['loaderUrl'] : '';
		if ( ! self::is_type( $type ) || ! $this->valid_loader_url( $loader_url ) ) {
			return new \WP_Error( 'webvouch_widgets_invalid_catalog', __( 'WebVouch returned invalid widget identity data.', 'webvouch-for-woocommerce' ) );
		}
		if ( ! is_bool( $value['locked'] ?? null ) || ! is_bool( $value['isActive'] ?? null ) ) {
			return new \WP_Error( 'webvouch_widgets_invalid_catalog', __( 'WebVouch returned invalid widget status data.', 'webvouch-for-woocommerce' ) );
		}
		$display_data = $value['displayData'] ?? null;
		if ( ! is_array( $display_data ) || ! is_bool( $display_data['canPublish'] ?? null ) ) {
			return new \WP_Error( 'webvouch_widgets_invalid_catalog', __( 'WebVouch returned invalid widget publishing data.', 'webvouch-for-woocommerce' ) );
		}
		$locked    = $value['locked'];
		$is_active = $locked ? false : $value['isActive'];
		$public_key = $locked ? null : ( $value['publicKey'] ?? null );
		if ( null !== $public_key && ( ! is_string( $public_key ) || 1 !== preg_match( '/^\d{9}$/', $public_key ) ) ) {
			return new \WP_Error( 'webvouch_widgets_invalid_key', __( 'WebVouch returned an invalid widget key.', 'webvouch-for-woocommerce' ) );
		}
		if ( $is_active && ! is_string( $public_key ) ) {
			return new \WP_Error( 'webvouch_widgets_invalid_key', __( 'An active WebVouch widget did not include its public key.', 'webvouch-for-woocommerce' ) );
		}

		return array(
			'type'       => $type,
			'title'      => sanitize_text_field( is_string( $value['title'] ?? null ) ? $value['title'] : self::TYPES[ $type ] ),
			'locked'     => $locked,
			'can_publish' => $display_data['canPublish'],
			'is_active'  => $is_active,
			'public_key' => $public_key,
			'loader_url' => $loader_url,
		);
	}

	private function valid_loader_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || '/widgets/v1/loader.js' !== ( $parts['path'] ?? '' ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( trim( (string) ( $parts['host'] ?? '' ), '[]' ) );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		if ( '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		$api_parts  = wp_parse_url( (string) $this->settings->get()['api_base_url'] );
		$api_host   = is_array( $api_parts ) ? strtolower( trim( (string) ( $api_parts['host'] ?? '' ), '[]' ) ) : '';
		$local_hosts = array( 'localhost', '127.0.0.1', '::1', 'host.docker.internal', 'vouch.lan' );
		$local_url   = in_array( $api_host, $local_hosts, true )
			&& in_array( $host, $local_hosts, true )
			&& in_array( $scheme, array( 'http', 'https' ), true );

		if ( defined( 'WEBVOUCH_WC_WIDGET_LOADER_URL' ) ) {
			$override = (string) constant( 'WEBVOUCH_WC_WIDGET_LOADER_URL' );
			return hash_equals( $override, $url ) && ( 'https' === $scheme || $local_url );
		}
		if ( 'https' === $scheme && 'webvouch.com' === $host && null === $port ) {
			return true;
		}

		return $local_url;
	}

	/** @param array<string,mixed> $value */
	private function persist( array $value ): bool {
		update_option( Settings::WIDGETS_OPTION_KEY, $value, false );
		return $value === get_option( Settings::WIDGETS_OPTION_KEY, null );
	}
}
