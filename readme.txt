=== WebVouch for WooCommerce ===
Contributors: webvouch
Tags: reviews, invitations, woocommerce, webvouch
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create WebVouch service-review invitations and add WebVouch widgets to WooCommerce.

== Description ==

WebVouch for WooCommerce connects to the WebVouch customer API with a scoped,
server-side OAuth client. It can create one service-review invitation when an
order reaches Processing or Completed.

The plugin uses WooCommerce Action Scheduler, supports High-Performance Order
Storage (HPOS), applies the send delay configured on the selected WebVouch
template, and retries transient network/API failures with a bounded schedule.
After connection, a shop owner can start one past-order import from the
WebVouch dashboard for the 7, 14, 30, 60, or 90 days before connection.

Version 0.3.0 also synchronizes the five canonical WebVouch storefront widgets.
Inline widgets can be placed with the WebVouch block or shortcode, while the
floating reviews drawer is enabled once for the entire storefront.

== Installation ==

1. Upload and activate the plugin.
2. Open WooCommerce > WebVouch.
3. Enter a WebVouch client ID and one-time client secret for a scoped client
   with `templates:read`, `invitations:write`, `widgets:read`, and
   `widgets:write`.
4. Save, test the connection, select a template and order trigger, then enable
   automation.
5. Open the Widgets tab to synchronize, activate, and place storefront widgets.

For production, define `WEBVOUCH_WC_CLIENT_ID`,
`WEBVOUCH_WC_CLIENT_SECRET`, and optionally `WEBVOUCH_WC_API_BASE_URL` in
`wp-config.php` so the secret is not stored in `wp_options`.
Test and staging installations may also pin an exact, structurally valid
widget loader with `WEBVOUCH_WC_WIDGET_LOADER_URL`; production uses the
canonical WebVouch loader automatically.

== Configuration ==

* **Order confirmed** captures the WooCommerce Processing status. It also sends
  safely if the background worker finds the order already Completed.
* **Order completed** captures only the Completed status. This is recommended
  for virtual/downloadable stores whose orders may skip Processing.
* Email content, sender identity, reply-to address, and send delay are owned by
  the selected WebVouch invitation template.
* Paused templates cannot be selected. WebVouch revalidates the template when
  each invitation is created.
* Insert **WebVouch widget** in the block editor or use
  `[webvouch_widget type="badge"]` in a classic editor or page builder.
* Available inline types are `carousel`, `badge`, `text-badge`, and
  `text-combo`. The `side-drawer` widget is enabled globally from the Widgets
  tab instead of being placed inside page content.
* Widget storefront rendering reads only validated WordPress options and makes
  no WebVouch API request during a page request. Purge any page/CDN cache after
  changing placement.

The order-status request only schedules background work. WebVouch HTTP calls
run through WooCommerce Action Scheduler and never block checkout or the order
transition.

== Order status and logs ==

Each WooCommerce order has a read-only **WebVouch invitation** panel showing
Queued, Retry scheduled, Accepted, Skipped, or Failed. Detailed redacted events
are available under WooCommerce > Status > Logs with source
`webvouch-woocommerce`.

Transient network failures, HTTP 5xx responses, rate limits, and an in-progress
idempotency lease retry after 1 minute, 5 minutes, 30 minutes, 2 hours, and 12
hours. The same idempotency key and event timestamp are retained across every
attempt.

WebVouch currently deduplicates invitations by email across the organization.
A later order from an already invited customer is shown as Skipped with reason
`already_invited`.

== Troubleshooting ==

* Use **Test connection** after saving a new client ID or secret.
* Confirm the OAuth client has `templates:read`, `invitations:write`,
  `widgets:read`, and `widgets:write`. Existing 0.2.0 credentials can be
  upgraded from the WebVouch WooCommerce integration without replacing their
  secret.
* Confirm WP-Cron or another Action Scheduler runner is operating on the store.
* Inspect the order's WebVouch panel and the redacted WooCommerce log source.
* A Failed state is terminal in version 0.1.0. Contact WebVouch support before
  clearing order metadata or attempting a manual replay.

Disconnecting fences the installation in WebVouch, revokes its API client,
clears the credentials and token cache from WordPress, and stops new automation. Plugin
uninstall removes settings, cached tokens, transients, and pending actions;
historical non-PII order outcome metadata remains with existing orders.

== Privacy ==

When an eligible order is processed, the plugin sends the billing email,
billing name (when present), a non-PII WooCommerce order reference, and the
order event timestamp to the configured WebVouch API endpoint. Credentials,
tokens, names, and email addresses are excluded from Action Scheduler
arguments, plugin order state, and WooCommerce logs.

== Changelog ==

= 0.3.0 =
* Added one dynamic Gutenberg block with four WebVouch widget variations.
* Added shortcode placement and a site-wide floating reviews drawer toggle.
* Added secure widget catalog synchronization and activation with isolated
  widget OAuth permissions.
* Added explicit legacy credential upgrades in the WebVouch dashboard.

= 0.2.0 =
* Added secure installation registration and five-minute dashboard heartbeat.
* Added a one-time, resumable 7/14/30/60/90-day past-order import.
* Added the WebVouch-hosted update channel with SHA-256 package verification.
* Routed live orders through the fenced WooCommerce Customer API endpoint.

= 0.1.0 =
* Initial customer API connection, background invitations, retries, HPOS
  support, and merchant-visible order status.
