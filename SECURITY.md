# Security Policy

## Supported Versions

Security fixes are provided for the latest published release. Users should
upgrade before reporting a vulnerability that affects an older release.

| Version | Supported |
|---|---|
| Latest release | Yes |
| Earlier releases | No |

## Reporting a Vulnerability

Do not report security vulnerabilities through public GitHub issues,
discussions, or pull requests.

Email `support@webvouch.com` with the subject `[SECURITY][WooCommerce]` and
include:

- the affected plugin, WordPress, WooCommerce, and PHP versions;
- a description of the vulnerability and its potential impact;
- minimal reproduction steps or a proof of concept;
- any relevant configuration, with credentials and customer data removed;
- whether the vulnerability is already publicly known.

We aim to acknowledge reports within three business days and to provide a
triage update within seven business days. If you receive no acknowledgement
within seven business days, please resend the report. Remediation and
disclosure timing depend on severity, affected releases, and the availability
of a safe fix; we will coordinate a disclosure date with you before anything
is published.

Test only against systems you own or are authorized to test. Do not access
customer data, disrupt production stores, perform denial-of-service testing,
or publish the vulnerability before coordinated disclosure. We will not
pursue action against good-faith research that respects these boundaries.

## System and Scope

This policy covers the WebVouch for WooCommerce plugin source, the release
build and publishing process, plugin update verification, the plugin's
WordPress administration screens, WooCommerce order-event handling and
background actions, storefront widget rendering, and the plugin's
communication with the WebVouch Customer API.

The WebVouch hosted platform, WordPress core, WooCommerce, hosting providers,
and third-party extensions are outside this repository unless the plugin
introduces or materially increases the vulnerability.

## Threat Model and Trust Boundaries

Treat as untrusted input: order and customer fields, WebVouch API responses,
release manifest data, downloaded update packages, shortcode and block
attributes, and all browser requests.

Important assets: the WebVouch client secret and cached access tokens,
customer names and email addresses, invitation state, widget configuration,
and the integrity of installed plugin updates.

Trust boundaries:

- Every plugin management screen and action is gated on the WooCommerce
  `manage_woocommerce` capability, which WordPress administrators and, by
  default, Shop Managers hold. Anyone with that capability is trusted with
  the plugin's full configuration, including the stored API credentials and
  the endpoint those credentials are sent to. A report that requires
  `manage_woocommerce` must demonstrate impact beyond that role's intended
  authority.
- Constants defined in `wp-config.php` (credentials, API base URL, widget
  loader override) are operator-controlled configuration. They bypass the
  dashboard's input validation and are trusted as entered.
- Ordinary customers, storefront visitors, remote API responses, and
  background job payloads are untrusted.

## Security Invariants

Violations of these are reportable vulnerabilities:

- The client secret and access tokens must not appear in WooCommerce logs,
  Action Scheduler arguments, order metadata, storefront or admin HTML, or
  error messages. (The non-secret client ID is displayed on the admin
  settings screen.)
- Administrative mutations must require the `manage_woocommerce` capability
  and a valid nonce.
- API base URLs entered through the dashboard must be HTTPS; plain HTTP is
  accepted only for a short allowlist of local test hosts. URLs supplied via
  `wp-config.php` constants are used as provided and are the operator's
  responsibility.
- Remote values must be validated before storage and escaped for their
  output context. Storefront widget markup must be generated only from
  validated local state, and the widget loader script must load only from
  the approved origin (or an explicit local-development override).
- Invitation creation and retries must preserve one idempotency key per
  order and trigger.
- Update packages must be fetched only from the approved HTTPS origin and
  must match the SHA-256 digest published in the release manifest before
  WordPress installs them. A missing or mismatched digest must fail closed.
- Failed validation anywhere must fail closed and preserve last-known-good
  state.
- Disconnecting must clear stored credentials, cached tokens, and cached
  connection and widget state, and must disable automation. Background
  actions already scheduled at disconnect time are not removed, but they
  must exit without contacting WebVouch once credentials are cleared.
- Uninstalling must remove plugin options, cached tokens, plugin transients,
  and pending scheduled actions.

## Update-Channel Trust Root

Release metadata and packages are served from the same origin over TLS, and
packages are verified against a SHA-256 digest from that metadata. Releases
are not cryptographically signed. The update channel's trust root is
therefore the TLS identity of the download origin, plus the reproducible
build published on GitHub Releases for independent comparison. A
vulnerability that lets an attacker who does not control that origin deliver
or install a modified package is in scope and treated as high severity.

## Reportable Findings

Reportable issues include authentication or capability bypasses, CSRF, stored
or reflected XSS, SSRF, disclosure of the client secret or access tokens,
customer personal data appearing in logs or scheduled-action arguments,
update verification bypasses, widget loader origin bypasses, arbitrary file
access, code execution, and idempotency failures that could cause
unauthorized or abusive invitations.

A report should demonstrate realistic reachability and impact in a supported
configuration.

## Known Limitations

These are pre-declared and are not new findings unless you can escalate them:

- Credentials saved through the dashboard, and cached access tokens, are
  stored unencrypted in the WordPress options table; anyone with database or
  file-system access can read them. Defining credentials in `wp-config.php`
  is recommended for production.
- Disconnecting deletes locally cached access tokens but cannot revoke them
  server-side; they remain valid until they expire.
- Update-check caches (site transients) may persist after uninstall until
  they expire; they contain no secrets.
- Non-personal invitation outcome metadata remains attached to existing
  WooCommerce orders after uninstall, as documented in the README.
- The plugin depends on WordPress cron and WooCommerce Action Scheduler for
  background processing. Store operators are responsible for securing the
  WordPress host, protecting `wp-config.php`, restricting privileged
  accounts, maintaining backups, and applying WordPress, WooCommerce, PHP,
  and plugin security updates.

## Out of Scope

The following are normally out of scope unless they expose a distinct plugin
vulnerability:

- unsupported WordPress, WooCommerce, PHP, or plugin versions;
- vulnerabilities entirely within WordPress, WooCommerce, WebVouch, hosting
  software, or another extension;
- actions available to users holding `manage_woocommerce` that do not exceed
  that role's intended authority, and compromised administrator or Shop
  Manager accounts;
- social engineering and physical access;
- missing security headers controlled by the hosting platform;
- denial of service requiring unrealistic traffic or resource consumption;
- intentionally configured non-production endpoints in isolated test systems;
- reports containing only automated scanner output without a reproducible
  security impact.
