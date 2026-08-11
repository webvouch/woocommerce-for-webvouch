<?php
/**
 * Authenticated WordPress management actions and UI for widget placement.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Widgets_Admin {
	public function __construct(
		private readonly Settings $settings,
		private readonly API_Client $api,
		private readonly Widget_State $state
	) {
	}

	public function boot(): void {
		add_action( 'admin_post_webvouch_wc_sync_widgets', array( $this, 'handle_sync' ) );
		add_action( 'admin_post_webvouch_wc_activate_widget', array( $this, 'handle_activate' ) );
		add_action( 'admin_post_webvouch_wc_save_drawer', array( $this, 'handle_save_drawer' ) );
		add_action( 'admin_post_webvouch_wc_recheck_widget_scope', array( $this, 'handle_recheck_scope' ) );
	}

	public function render(): void {
		$current             = $this->state->current();
		$synced_at           = is_string( $current['synced_at'] ?? null ) ? $current['synced_at'] : '';
		$formatted_synced_at = $this->format_synced_at( $synced_at );
		?>
		<div class="webvouch-wc-card">
			<h2><?php echo esc_html__( 'Storefront widgets', 'webvouch-for-woocommerce' ); ?></h2>
			<p><?php echo esc_html__( 'Activate WebVouch widgets, then place them with the block editor or shortcode. Review data and widget design remain managed by WebVouch.', 'webvouch-for-woocommerce' ); ?></p>
			<?php if ( $formatted_synced_at ) : ?>
				<p class="description"><?php echo esc_html( sprintf( __( 'Last synchronized: %s', 'webvouch-for-woocommerce' ), $formatted_synced_at ) ); ?></p>
			<?php endif; ?>
			<div class="webvouch-wc-actions">
				<?php $this->action_form( 'webvouch_wc_sync_widgets', __( 'Sync widgets', 'webvouch-for-woocommerce' ), ! $this->settings->is_connection_configured() ); ?>
				<?php $this->action_form( 'webvouch_wc_recheck_widget_scope', __( 'Re-check widget permissions', 'webvouch-for-woocommerce' ), ! $this->settings->is_connection_configured() ); ?>
			</div>
		</div>

		<div class="webvouch-wc-widget-grid">
			<?php foreach ( Widget_State::types() as $type => $fallback_label ) : ?>
				<?php
				$widget = $this->state->get( $type );
				$label  = is_array( $widget ) && is_string( $widget['title'] ?? null ) && '' !== $widget['title'] ? $widget['title'] : $fallback_label;
				$locked = is_array( $widget ) && ! empty( $widget['locked'] );
				$activated = $this->state->activated( $type );
				$can_publish = $this->state->can_publish( $type );
				$ready  = $this->state->renderable( $type );
				?>
				<div class="webvouch-wc-card webvouch-wc-widget-card">
					<div>
						<h2><?php echo esc_html( $label ); ?></h2>
						<p class="description">
							<?php
							echo esc_html(
								$locked
									? __( 'Requires a higher WebVouch plan.', 'webvouch-for-woocommerce' )
									: ( ! $can_publish
										? __( 'Publish your WebVouch business profile before activating or placing this widget.', 'webvouch-for-woocommerce' )
										: ( $ready
										? __( 'Active and ready to place.', 'webvouch-for-woocommerce' )
										: ( $activated
											? __( 'Activated, but your WebVouch business profile must be published before this widget can be placed.', 'webvouch-for-woocommerce' )
											: __( 'Available. Activate it to create a public widget key.', 'webvouch-for-woocommerce' ) ) ) )
							);
							?>
						</p>
					</div>

					<?php if ( 'side-drawer' === $type ) : ?>
						<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
							<input type="hidden" name="action" value="webvouch_wc_save_drawer" />
							<?php wp_nonce_field( 'webvouch_wc_save_drawer' ); ?>
							<label><input type="checkbox" name="enabled" value="1" <?php checked( $this->state->drawer_enabled() ); ?> <?php disabled( ! $ready ); ?> /> <?php echo esc_html__( 'Show on the entire storefront', 'webvouch-for-woocommerce' ); ?></label>
							<?php submit_button( __( 'Save placement', 'webvouch-for-woocommerce' ), 'secondary', 'submit', false, $ready ? array() : array( 'disabled' => 'disabled' ) ); ?>
						</form>
					<?php elseif ( $ready ) : ?>
						<p><code>[webvouch_widget type="<?php echo esc_attr( $type ); ?>"]</code></p>
						<p class="description"><?php echo esc_html__( 'Or insert its WebVouch variation from the block editor.', 'webvouch-for-woocommerce' ); ?></p>
					<?php endif; ?>

					<?php if ( ! $activated && ! $locked && $can_publish ) : ?>
						<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
							<input type="hidden" name="action" value="webvouch_wc_activate_widget" />
							<input type="hidden" name="widget_type" value="<?php echo esc_attr( $type ); ?>" />
							<?php wp_nonce_field( 'webvouch_wc_activate_widget_' . $type ); ?>
							<?php submit_button( __( 'Activate widget', 'webvouch-for-woocommerce' ), 'primary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<div class="webvouch-wc-card">
			<h2><?php echo esc_html__( 'Page-cache note', 'webvouch-for-woocommerce' ); ?></h2>
			<p><?php echo esc_html__( 'After adding or removing a placement, purge any WordPress, CDN, or reverse-proxy page cache used by this store.', 'webvouch-for-woocommerce' ); ?></p>
		</div>
		<?php
	}

	public function handle_sync(): void {
		$this->authorize( 'webvouch_wc_sync_widgets' );
		$this->redirect( $this->sync() );
	}

	public function handle_recheck_scope(): void {
		$this->authorize( 'webvouch_wc_recheck_widget_scope' );
		$this->settings->clear_token( 'widgets' );
		$this->redirect( $this->sync() );
	}

	public function handle_activate(): void {
		$type = isset( $_POST['widget_type'] ) ? sanitize_key( wp_unslash( $_POST['widget_type'] ) ) : '';
		if ( ! Widget_State::is_type( $type ) ) {
			wp_die( esc_html__( 'Unknown WebVouch widget type.', 'webvouch-for-woocommerce' ) );
		}
		$this->authorize( 'webvouch_wc_activate_widget_' . $type );
		if ( ! $this->state->can_publish( $type ) ) {
			$this->redirect( 'widget_profile_unpublished' );
		}
		$key    = 'wv-wc-widget-v1-' . hash( 'sha256', Settings::installation_id() . '|' . $type . '|' . microtime( true ) . '|' . wp_rand() );
		$result = $this->api->activate_widget( $type, $key );
		if ( 200 !== $result->status ) {
			$this->redirect( $this->notice_code( $result ) );
		}
		$stored = $this->state->update_from_api( $result->body );
		if ( $stored instanceof \WP_Error ) {
			$this->redirect(
				'webvouch_widgets_persistence_failed' === $stored->get_error_code()
					? 'widgets_persistence_failed'
					: 'widgets_invalid_response'
			);
		}
		if ( ! $this->state->renderable( $type ) ) {
			$this->redirect( 'widget_profile_unpublished' );
		}
		$this->redirect( 'widget_activated' );
	}

	public function handle_save_drawer(): void {
		$this->authorize( 'webvouch_wc_save_drawer' );
		$enabled = ! empty( $_POST['enabled'] );
		if ( $enabled && ! $this->state->renderable( 'side-drawer' ) ) {
			$this->redirect( 'widget_not_ready' );
		}
		$stored = $this->state->set_drawer_enabled( $enabled );
		if ( $stored instanceof \WP_Error ) {
			$this->redirect( 'widgets_persistence_failed' );
		}
		$this->redirect( 'widget_placement_saved' );
	}

	private function sync(): string {
		$result = $this->api->list_widgets();
		if ( 200 !== $result->status || ! is_array( $result->body['data'] ?? null ) ) {
			return $this->notice_code( $result );
		}
		$stored = $this->state->replace_from_api( $result->body['data'] );
		if ( $stored instanceof \WP_Error ) {
			return 'webvouch_widgets_persistence_failed' === $stored->get_error_code()
				? 'widgets_persistence_failed'
				: 'widgets_invalid_response';
		}
		return 'widgets_synced';
	}

	private function authorize( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage WebVouch widgets.', 'webvouch-for-woocommerce' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private function notice_code( Http_Result $result ): string {
		$code = $result->error_code();
		if ( in_array( $code, array( 'invalid_scope', 'insufficient_scope' ), true ) ) {
			return 'widgets_scope_missing';
		}
		if ( 'plan_required' === $code ) {
			return 'widget_plan_locked';
		}
		if ( in_array( $code, array( 'invalid_client', 'unauthorized_client', 'rate_limited' ), true ) ) {
			return $code;
		}
		return $result->is_transport_failure() ? 'network_error' : 'widgets_sync_failed';
	}

	private function format_synced_at( string $value ): string {
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return '';
		}

		$date = wp_date( (string) get_option( 'date_format', 'F j, Y' ), $timestamp );
		$time = wp_date( (string) get_option( 'time_format', 'g:i a' ), $timestamp );

		return sprintf(
			/* translators: 1: localized date, 2: localized time. */
			__( '%1$s at %2$s', 'webvouch-for-woocommerce' ),
			$date,
			$time
		);
	}

	private function redirect( string $notice ): void {
		$url = add_query_arg(
			array( 'tab' => 'widgets', 'webvouch_notice' => sanitize_key( $notice ) ),
			Settings_Page::page_url()
		);
		wp_safe_redirect( $url );
		exit;
	}

	private function action_form( string $action, string $label, bool $disabled ): void {
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<?php wp_nonce_field( $action ); ?>
			<button class="button" type="submit" <?php disabled( $disabled ); ?>><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}
