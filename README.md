# WebVouch for WooCommerce

The official open-source WooCommerce plugin for WebVouch. It sends service-review invitations from eligible WooCommerce orders, supports controlled past-order imports, and makes WebVouch widgets available in the WordPress editor and storefront.

## Features

- Automatically create an invitation when an order reaches Processing or Completed.
- Run invitation delivery in WooCommerce Action Scheduler instead of blocking checkout.
- Preserve one idempotency key across bounded retries.
- Import orders from the 7, 14, 30, 60, or 90 days before connection.
- Synchronize WebVouch widgets and place them with a Gutenberg block or shortcode.
- Support High-Performance Order Storage (HPOS).
- Show invitation state on each WooCommerce order and write redacted operational logs.
- Verify update packages with a published SHA-256 checksum.

## Requirements

- WordPress 6.4 or newer
- WooCommerce 8.6 or newer
- PHP 8.1 or newer
- HTTPS access to the WebVouch Customer API
- A WebVouch API client with `templates:read`, `invitations:write`, `widgets:read`, and `widgets:write`

## Installation

1. Download `webvouch-for-woocommerce-<version>.zip` from the repository's Releases page.
2. In WordPress, open **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP, install it, and activate **WebVouch for WooCommerce**.
4. Open **WooCommerce → WebVouch**.
5. Enter the client ID and one-time client secret created in the WebVouch business dashboard.
6. Test the connection, select an invitation template and order trigger, then enable automation.
7. Open the **Widgets** tab to synchronize and place storefront widgets.

For production stores, define credentials in `wp-config.php` so the client secret is not stored in WordPress options:

```php
define( 'WEBVOUCH_WC_CLIENT_ID', 'wv_client_...' );
define( 'WEBVOUCH_WC_CLIENT_SECRET', 'wv_secret_...' );
```

`WEBVOUCH_WC_API_BASE_URL` may also be defined for an approved WebVouch endpoint. Production installations should use the default endpoint.

## Invitation behavior

The **Order confirmed** trigger captures the Processing status and safely handles an order already reaching Completed. The **Order completed** trigger captures only Completed and is recommended for virtual or downloadable stores that may skip Processing.

The selected WebVouch template owns the email content, sender, reply-to address, and delivery delay. Paused templates cannot be selected and are revalidated before an invitation is created.

Transient failures retry after approximately 1 minute, 5 minutes, 30 minutes, 2 hours, and 12 hours. WebVouch deduplicates invitations according to the organization policy, and the plugin displays terminal skipped or failed outcomes on the order.

## Widgets

Insert the **WebVouch widget** block in the WordPress editor or use:

```text
[webvouch_widget type="badge"]
```

Supported inline types are `carousel`, `badge`, `text-badge`, and `text-combo`. The `side-drawer` widget is enabled globally from the Widgets tab.

## Updates

The plugin checks WebVouch's checksum-verified release metadata endpoint and verifies the SHA-256 digest before WordPress installs an update. GitHub Releases provide an independent manual download location; the release ZIP published on GitHub must be byte-for-byte identical to the package mirrored on `webvouch.com`.

Automatic updates are not enabled by default. Store owners retain control through the standard WordPress update screen.

## Privacy and security

For an eligible order, the plugin sends the billing email, billing name when present, a non-personal order reference, and the order event timestamp to WebVouch. Credentials, access tokens, names, and email addresses are excluded from Action Scheduler arguments, persistent plugin order state, and WooCommerce logs.

Disconnecting asks WebVouch to disconnect the installation, clears local credentials and cached tokens, and stops new automation. Actions already scheduled at disconnect time remain queued but exit without calling WebVouch after credentials are cleared. Uninstalling removes plugin settings, cached tokens, transients, and pending actions. Historical non-personal outcome metadata remains attached to existing WooCommerce orders.

Please report vulnerabilities privately as described in `SECURITY.md` and do not open public security issues.

## Development

Run the dependency-free PHP tests:

```bash
php tests/run.php
```

Lint all PHP files:

```bash
find . -type f -name '*.php' -not -path './dist/*' -exec php -l {} \;
```

Build a deterministic WordPress package:

```bash
./scripts/build-plugin.sh
```

The build produces the versioned ZIP, a stable-name ZIP, `latest.json`, and `SHA256SUMS` under `dist/`.

## Community contributions

Community improvements are welcome. You can open an issue to propose a feature or report a reproducible problem, and submit a pull request with fixes, compatibility updates, tests, translations, or documentation improvements.

Please read `CONTRIBUTING.md` before starting a larger change. Pull requests should keep the plugin compatible with its documented minimum WordPress, WooCommerce, and PHP versions, preserve secure credential handling, and include tests for changed behavior. Maintainers may ask for changes before merging so releases remain safe for existing stores.

Security vulnerabilities must be reported privately through the process in `SECURITY.md`, not through a public issue.

## Releasing

1. Update the version in `webvouch-for-woocommerce.php` and the stable tag and changelog in `readme.txt`.
2. Run the tests and deterministic build locally.
3. Commit the release and create a matching tag, for example `v0.3.0`.
4. Push the tag. The release workflow verifies the version, rebuilds the package, and creates the GitHub Release.
5. Mirror the exact versioned ZIP and `latest.json` to the WebVouch download endpoint without rebuilding them.

## Support

- Product website: https://webvouch.com
- Customer API documentation: https://api.webvouch.com/api/public/v1/docs
- General support: support@webvouch.com

## License

WebVouch for WooCommerce is licensed under the GNU General Public License v2.0 or later. See `LICENSE`.
