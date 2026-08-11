<?php
/**
 * WooCommerce admin settings and connection actions.
 *
 * @package WebVouchForWooCommerce
 */

declare(strict_types=1);

namespace WebVouch\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings_Page {
	private const GROUP = 'webvouch_wc_settings_group';
	private const SLUG  = 'webvouch-for-woocommerce';

	public function __construct(
		private readonly Settings $settings,
		private readonly API_Client $api,
		private readonly Connection $connection,
		private readonly Widgets_Admin $widgets_admin
	) {
	}

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( $this, 'settings_capability' ) );
		add_action( 'admin_post_webvouch_wc_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_webvouch_wc_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_webvouch_wc_refresh_templates', array( $this, 'handle_refresh_templates' ) );
	}

	public static function page_url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'WebVouch', 'webvouch-for-woocommerce' ),
			__( 'WebVouch', 'webvouch-for-woocommerce' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	public function settings_capability( string $capability ): string {
		return 'manage_woocommerce';
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage WebVouch settings.', 'webvouch-for-woocommerce' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only allowlisted tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$tab = in_array( $tab, array( 'settings', 'widgets' ), true ) ? $tab : 'settings';
		$this->render_query_notice();
		settings_errors( Settings::OPTION_KEY );
		?>
		<div class="wrap webvouch-wc-settings">
			<h1><?php echo esc_html__( 'WebVouch for WooCommerce', 'webvouch-for-woocommerce' ); ?></h1>
			<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'WebVouch settings sections', 'webvouch-for-woocommerce' ); ?>">
				<a class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::page_url() ); ?>"><?php echo esc_html__( 'Automation', 'webvouch-for-woocommerce' ); ?></a>
				<a class="nav-tab <?php echo 'widgets' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'widgets', self::page_url() ) ); ?>"><?php echo esc_html__( 'Widgets', 'webvouch-for-woocommerce' ); ?></a>
			</nav>
			<?php if ( 'widgets' === $tab ) : ?>
				<?php $this->widgets_admin->render(); ?>
			</div>
			<?php $this->render_styles(); ?>
			<?php return; ?>
			<?php endif; ?>
			<?php $config = $this->settings->get(); ?>
			<?php $templates = $this->templates(); ?>
			<p class="description"><?php echo esc_html__( 'Create one service-review invitation when an order reaches your chosen WooCommerce status. Email content and send delay stay managed in WebVouch.', 'webvouch-for-woocommerce' ); ?></p>

			<div class="webvouch-wc-card">
				<h2><?php echo esc_html__( 'Connection', 'webvouch-for-woocommerce' ); ?></h2>
				<p><?php echo $this->settings->is_connection_configured() ? esc_html__( 'Credentials are configured. Save changes before testing a newly entered secret.', 'webvouch-for-woocommerce' ) : esc_html__( 'Add a scoped WebVouch OAuth client with templates:read, invitations:write, widgets:read, and widgets:write.', 'webvouch-for-woocommerce' ); ?></p>
				<div class="webvouch-wc-actions">
					<?php $this->action_form( 'webvouch_wc_test_connection', 'webvouch_wc_test_connection', __( 'Test connection', 'webvouch-for-woocommerce' ), ! $this->settings->is_connection_configured() ); ?>
					<?php $this->action_form( 'webvouch_wc_refresh_templates', 'webvouch_wc_refresh_templates', __( 'Refresh templates', 'webvouch-for-woocommerce' ), ! $this->settings->is_connection_configured() ); ?>
					<?php if ( ! $this->settings->credentials_use_constants() ) : ?>
						<?php $this->action_form( 'webvouch_wc_disconnect', 'webvouch_wc_disconnect', __( 'Disconnect', 'webvouch-for-woocommerce' ), ! $this->settings->is_connection_configured(), true ); ?>
					<?php endif; ?>
				</div>
			</div>

			<form action="options.php" method="post" autocomplete="off">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="webvouch-wc-api-url"><?php echo esc_html__( 'API base URL', 'webvouch-for-woocommerce' ); ?></label></th>
						<td><?php $this->render_text_or_constant( 'api_base_url', 'WEBVOUCH_WC_API_BASE_URL', 'url', (string) $config['api_base_url'], 'webvouch-wc-api-url' ); ?><p class="description"><?php echo esc_html__( 'Production default: https://api.webvouch.com/api. Plain HTTP is accepted only for local test hosts.', 'webvouch-for-woocommerce' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="webvouch-wc-client-id"><?php echo esc_html__( 'Client ID', 'webvouch-for-woocommerce' ); ?></label></th>
						<td><?php $this->render_text_or_constant( 'client_id', 'WEBVOUCH_WC_CLIENT_ID', 'text', (string) $config['client_id'], 'webvouch-wc-client-id' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="webvouch-wc-client-secret"><?php echo esc_html__( 'Client secret', 'webvouch-for-woocommerce' ); ?></label></th>
						<td>
							<?php if ( defined( 'WEBVOUCH_WC_CLIENT_SECRET' ) ) : ?>
								<code><?php echo esc_html__( 'Configured in wp-config.php', 'webvouch-for-woocommerce' ); ?></code>
							<?php else : ?>
								<input id="webvouch-wc-client-secret" class="regular-text" type="password" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[client_secret]" value="" autocomplete="new-password" spellcheck="false" />
								<p class="description"><?php echo esc_html__( 'Leave blank to keep the saved secret. WordPress stores saved secrets as plaintext in the database; wp-config.php constants are recommended for production.', 'webvouch-for-woocommerce' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="webvouch-wc-template"><?php echo esc_html__( 'Invitation template', 'webvouch-for-woocommerce' ); ?></label></th>
						<td>
							<select id="webvouch-wc-template" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[template_id]">
								<option value=""><?php echo esc_html__( 'Select a WebVouch template', 'webvouch-for-woocommerce' ); ?></option>
								<?php $this->render_template_options( $templates, (string) $config['template_id'] ); ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Draft and active templates can be selected. Paused templates are shown but disabled.', 'webvouch-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="webvouch-wc-trigger"><?php echo esc_html__( 'Invitation trigger', 'webvouch-for-woocommerce' ); ?></label></th>
						<td>
							<select id="webvouch-wc-trigger" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[trigger]">
								<option value="order_confirmed" <?php selected( $config['trigger'], 'order_confirmed' ); ?>><?php echo esc_html__( 'Order confirmed (Processing)', 'webvouch-for-woocommerce' ); ?></option>
								<option value="order_completed" <?php selected( $config['trigger'], 'order_completed' ); ?>><?php echo esc_html__( 'Order completed (Completed)', 'webvouch-for-woocommerce' ); ?></option>
							</select>
							<p class="description"><?php echo esc_html__( 'Digital or virtual stores should prefer Order completed because those orders may skip Processing.', 'webvouch-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Automation', 'webvouch-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( ! empty( $config['enabled'] ) ); ?> /> <?php echo esc_html__( 'Enable automatic WebVouch invitations', 'webvouch-for-woocommerce' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save changes', 'webvouch-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php $this->render_styles(); ?>
		<?php
	}

	private function render_styles(): void {
		?>
		<style>
			.webvouch-wc-settings{max-width:1040px}.webvouch-wc-settings>.description{margin-top:16px}.webvouch-wc-card{margin:20px 0;padding:18px 20px;border:1px solid #c3c4c7;border-radius:6px;background:#fff}.webvouch-wc-card h2{margin-top:0}.webvouch-wc-actions{display:flex;gap:8px;flex-wrap:wrap}.webvouch-wc-actions form{margin:0}.webvouch-wc-widget-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-top:20px}.webvouch-wc-widget-card{display:flex;min-height:190px;flex-direction:column;justify-content:space-between;margin:0}.webvouch-wc-widget-card form{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.webvouch-wc-widget-card .submit{margin:0;padding:0}
		</style>
		<?php
	}

	public function handle_test_connection(): void {
		$this->authorize_action( 'webvouch_wc_test_connection' );
		$result = $this->connection->heartbeat();
		if ( 200 === $result->status && is_string( $result->body['generation'] ?? null ) ) {
			delete_transient( Settings::TEMPLATE_TRANSIENT );
			$this->redirect( 'connection_ok' );
		}
		$this->redirect( $this->notice_code( $result ) );
	}

	public function handle_disconnect(): void {
		$this->authorize_action( 'webvouch_wc_disconnect' );
		if ( $this->settings->credentials_use_constants() ) {
			$this->redirect( 'constants_configured' );
		}
		$result = $this->connection->disconnect();
		if ( 200 !== $result->status ) {
			$this->redirect( $this->notice_code( $result ) );
		}
		$this->redirect( $this->settings->disconnect() ? 'disconnected' : 'connection_failed' );
	}

	public function handle_refresh_templates(): void {
		$this->authorize_action( 'webvouch_wc_refresh_templates' );
		delete_transient( Settings::TEMPLATE_TRANSIENT );
		$result = $this->api->list_templates();
		if ( 200 === $result->status && is_array( $result->body['data'] ?? null ) ) {
			set_transient( Settings::TEMPLATE_TRANSIENT, $result->body['data'], 5 * MINUTE_IN_SECONDS );
			$this->redirect( 'templates_refreshed' );
		}
		$this->redirect( $this->notice_code( $result ) );
	}

	/** @return array<int,array<string,mixed>> */
	private function templates(): array {
		$cached = get_transient( Settings::TEMPLATE_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( ! $this->settings->is_connection_configured() ) {
			return array();
		}
		$result = $this->api->list_templates();
		if ( 200 !== $result->status || ! is_array( $result->body['data'] ?? null ) ) {
			add_settings_error( Settings::OPTION_KEY, 'templates_unavailable', $this->notice_message( $this->notice_code( $result ) ), 'warning' );
			return array();
		}
		set_transient( Settings::TEMPLATE_TRANSIENT, $result->body['data'], 5 * MINUTE_IN_SECONDS );
		return $result->body['data'];
	}

	/** @param array<int,array<string,mixed>> $templates */
	private function render_template_options( array $templates, string $selected_id ): void {
		$found = false;
		foreach ( $templates as $template ) {
			$id     = is_string( $template['id'] ?? null ) ? $template['id'] : '';
			$name   = is_string( $template['name'] ?? null ) ? $template['name'] : '';
			$status = is_string( $template['status'] ?? null ) ? $template['status'] : '';
			if ( '' === $id || '' === $name ) {
				continue;
			}
			$found = $found || $id === $selected_id;
			printf(
				'<option value="%1$s" %2$s %3$s>%4$s</option>',
				esc_attr( $id ),
				selected( $selected_id, $id, false ),
				'paused' === $status ? 'disabled' : '',
				esc_html( $name . ( $status ? ' [' . $status . ']' : '' ) )
			);
		}
		if ( '' !== $selected_id && ! $found ) {
			printf( '<option value="%1$s" selected>%2$s</option>', esc_attr( $selected_id ), esc_html__( 'Previously selected template (currently unavailable)', 'webvouch-for-woocommerce' ) );
		}
	}

	private function render_text_or_constant( string $field, string $constant, string $type, string $value, string $id ): void {
		if ( defined( $constant ) ) {
			echo '<code>' . esc_html__( 'Configured in wp-config.php', 'webvouch-for-woocommerce' ) . '</code>';
			return;
		}
		printf(
			'<input id="%1$s" class="regular-text" type="%2$s" name="%3$s[%4$s]" value="%5$s" spellcheck="false" />',
			esc_attr( $id ),
			esc_attr( $type ),
			esc_attr( Settings::OPTION_KEY ),
			esc_attr( $field ),
			esc_attr( $value )
		);
	}

	private function action_form( string $action, string $nonce_action, string $label, bool $disabled, bool $destructive = false ): void {
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<?php wp_nonce_field( $nonce_action ); ?>
			<button class="button <?php echo $destructive ? 'button-link-delete' : ''; ?>" type="submit" <?php disabled( $disabled ); ?> <?php echo $destructive ? 'onclick="return confirm(\'' . esc_js( __( 'Disconnect WebVouch and stop automation?', 'webvouch-for-woocommerce' ) ) . '\')"' : ''; ?>><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function authorize_action( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage WebVouch settings.', 'webvouch-for-woocommerce' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private function redirect( string $notice ): void {
		wp_safe_redirect( add_query_arg( 'webvouch_notice', sanitize_key( $notice ), self::page_url() ) );
		exit;
	}

	private function render_query_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, allowlisted notice display; no state change occurs here.
		$notice = isset( $_GET['webvouch_notice'] ) ? sanitize_key( wp_unslash( $_GET['webvouch_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}
		$type = in_array( $notice, array( 'connection_ok', 'templates_refreshed', 'disconnected', 'widgets_synced', 'widget_activated', 'widget_placement_saved' ), true ) ? 'success' : 'error';
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $this->notice_message( $notice ) ) );
	}

	private function notice_code( Http_Result $result ): string {
		$code = $result->error_code();
		return in_array( $code, array( 'invalid_client', 'invalid_scope', 'unauthorized_client', 'insufficient_scope', 'plan_required', 'plan_limit', 'rate_limited' ), true )
			? $code
			: ( $result->is_transport_failure() ? 'network_error' : 'connection_failed' );
	}

	private function notice_message( string $notice ): string {
		$messages = array(
			'connection_ok'       => __( 'Connection verified. WebVouch account access is working.', 'webvouch-for-woocommerce' ),
			'templates_refreshed' => __( 'Invitation templates refreshed.', 'webvouch-for-woocommerce' ),
			'disconnected'        => __( 'WebVouch disconnected and automation disabled.', 'webvouch-for-woocommerce' ),
			'constants_configured' => __( 'Credentials are defined in wp-config.php and cannot be cleared from this page.', 'webvouch-for-woocommerce' ),
			'invalid_client'      => __( 'WebVouch rejected the client ID or secret. Save the current credentials and try again.', 'webvouch-for-woocommerce' ),
			'invalid_scope'       => __( 'The WebVouch client does not allow the scopes requested by this plugin.', 'webvouch-for-woocommerce' ),
			'unauthorized_client' => __( 'This WebVouch API client is not authorized.', 'webvouch-for-woocommerce' ),
			'insufficient_scope'  => __( 'The WebVouch token is missing templates:read or invitations:write.', 'webvouch-for-woocommerce' ),
			'plan_required'       => __( 'The connected WebVouch account plan does not allow this operation.', 'webvouch-for-woocommerce' ),
			'plan_limit'          => __( 'The WebVouch invitation limit has been reached.', 'webvouch-for-woocommerce' ),
			'rate_limited'        => __( 'WebVouch temporarily rate-limited this request. Wait and try again.', 'webvouch-for-woocommerce' ),
			'network_error'       => __( 'WordPress could not reach WebVouch. Check the API URL and network access.', 'webvouch-for-woocommerce' ),
				'connection_failed'   => __( 'WebVouch connection verification failed. Check WooCommerce logs for the redacted status code.', 'webvouch-for-woocommerce' ),
				'widgets_synced'       => __( 'WebVouch widgets synchronized.', 'webvouch-for-woocommerce' ),
				'widget_activated'     => __( 'The WebVouch widget is active and ready to place.', 'webvouch-for-woocommerce' ),
				'widget_placement_saved' => __( 'The storefront widget placement was saved. Purge any page cache used by this store.', 'webvouch-for-woocommerce' ),
				'widgets_scope_missing' => __( 'These credentials do not include widget permissions. Enable storefront widgets in the WebVouch WooCommerce integration, then re-check permissions.', 'webvouch-for-woocommerce' ),
				'widget_plan_locked'   => __( 'The selected widget requires a higher WebVouch plan.', 'webvouch-for-woocommerce' ),
				'widget_profile_unpublished' => __( 'Publish your WebVouch business profile before activating or placing storefront widgets.', 'webvouch-for-woocommerce' ),
				'widget_not_ready'     => __( 'Activate and synchronize the floating widget before enabling its storefront placement.', 'webvouch-for-woocommerce' ),
				'widgets_invalid_response' => __( 'WebVouch returned invalid widget data. The last known-good widget configuration was preserved.', 'webvouch-for-woocommerce' ),
				'widgets_persistence_failed' => __( 'WordPress could not save the widget configuration. Check database health and try again.', 'webvouch-for-woocommerce' ),
				'widgets_sync_failed'  => __( 'WebVouch widgets could not be synchronized. The last known-good configuration was preserved.', 'webvouch-for-woocommerce' ),
		);
		return $messages[ $notice ] ?? $messages['connection_failed'];
	}
}
