<?php
/**
 * Gutenberg, shortcode, and global-drawer placement for canonical widgets.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Widget_Renderer {
	private const LOADER_HANDLE = 'webvouch-widgets-loader';
	private const EDITOR_HANDLE = 'webvouch-wc-widget-editor';
	private bool $drawer_rendered = false;

	/** @var array<string,string> */
	private const ELEMENTS = array(
		'carousel'   => 'webvouch-carousel',
		'badge'      => 'webvouch-badge',
		'text-badge' => 'webvouch-text-badge',
		'text-combo' => 'webvouch-text-combo',
		'side-drawer' => 'webvouch-side-drawer',
	);

	public function __construct( private readonly Widget_State $state ) {
	}

	public function boot(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_drawer_loader' ) );
		add_action( 'wp_footer', array( $this, 'render_drawer' ), 10 );
	}

	public function register(): void {
		$loader_url = $this->state->loader_url();
		if ( $loader_url ) {
			wp_register_script(
				self::LOADER_HANDLE,
				$loader_url,
				array(),
				null,
				array( 'strategy' => 'defer', 'in_footer' => true )
			);
		}

		wp_register_script(
			self::EDITOR_HANDLE,
			plugins_url( 'assets/js/widget-editor.js', WEBVOUCH_WC_FILE ),
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
			WEBVOUCH_WC_VERSION,
			true
		);
		wp_localize_script(
			self::EDITOR_HANDLE,
			'WebVouchWooWidgets',
			array(
				'managerUrl' => add_query_arg( 'tab', 'widgets', Settings_Page::page_url() ),
				'widgets'    => $this->editor_widget_state(),
			)
		);

		register_block_type(
			WEBVOUCH_WC_DIR . 'blocks/widget',
			array(
				'editor_script'   => self::EDITOR_HANDLE,
				'render_callback' => array( $this, 'render_block' ),
			)
		);
		add_shortcode( 'webvouch_widget', array( $this, 'render_shortcode' ) );
	}

	/** @param array<string,mixed> $attributes */
	public function render_block( array $attributes ): string {
		$type = is_string( $attributes['widgetType'] ?? null ) ? $attributes['widgetType'] : 'text-badge';
		$markup = $this->render_inline_widget( $type );
		if ( '' === $markup ) {
			return '';
		}
		$wrapper = function_exists( 'get_block_wrapper_attributes' )
			? get_block_wrapper_attributes( array( 'class' => 'webvouch-widget-placement' ) )
			: 'class="webvouch-widget-placement"';
		return '<div ' . $wrapper . '>' . $markup . '</div>';
	}

	/** @param mixed $attributes */
	public function render_shortcode( $attributes ): string {
		$attributes = shortcode_atts( array( 'type' => 'text-badge' ), is_array( $attributes ) ? $attributes : array(), 'webvouch_widget' );
		$type = is_string( $attributes['type'] ?? null ) ? sanitize_key( $attributes['type'] ) : '';
		return $this->render_inline_widget( $type );
	}

	public function enqueue_drawer_loader(): void {
		if ( $this->should_render_drawer() ) {
			$this->enqueue_loader();
		}
	}

	public function render_drawer(): void {
		if ( $this->drawer_rendered || ! $this->should_render_drawer() ) {
			return;
		}
		$this->drawer_rendered = true;
		echo $this->element_markup( 'side-drawer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed tag and validated numeric key.
	}

	private function render_inline_widget( string $type ): string {
		if ( ! isset( Widget_State::inline_types()[ $type ] ) || ! $this->state->renderable( $type ) ) {
			return '';
		}
		$this->enqueue_loader();
		return $this->element_markup( $type );
	}

	private function enqueue_loader(): void {
		if ( $this->state->loader_url() && wp_script_is( self::LOADER_HANDLE, 'registered' ) ) {
			wp_enqueue_script( self::LOADER_HANDLE );
		}
	}

	private function should_render_drawer(): bool {
		$frontend_request = ! is_admin()
			&& ( ! function_exists( 'is_feed' ) || ! is_feed() )
			&& ( ! function_exists( 'wp_is_json_request' ) || ! wp_is_json_request() );
		return $frontend_request
			&& $this->state->drawer_enabled()
			&& $this->state->renderable( 'side-drawer' )
			&& (bool) apply_filters( 'webvouch_wc_render_side_drawer', true );
	}

	private function element_markup( string $type ): string {
		$widget = $this->state->get( $type );
		$tag    = self::ELEMENTS[ $type ] ?? '';
		$key    = is_array( $widget ) && is_string( $widget['public_key'] ?? null ) ? $widget['public_key'] : '';
		if ( '' === $tag || 1 !== preg_match( '/^\d{9}$/', $key ) ) {
			return '';
		}
		return sprintf( '<%1$s data-key="%2$s"></%1$s>', $tag, esc_attr( $key ) );
	}

	/** @return array<string,array<string,mixed>> */
	private function editor_widget_state(): array {
		$result = array();
		foreach ( Widget_State::inline_types() as $type => $label ) {
			$widget = $this->state->get( $type );
			$result[ $type ] = array(
				'label' => $label,
				'ready' => $this->state->renderable( $type ),
				'locked' => is_array( $widget ) && ! empty( $widget['locked'] ),
			);
		}
		return $result;
	}
}
