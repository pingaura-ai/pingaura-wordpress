# Changelog

All notable changes to this plugin are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

## [1.4.0] - 2026-11-15

### Added

- Optional sending of Elementor Pro form submissions to PingAura Leads (`POST https://www.pingaura.ai/api/v1/domains/{domainId}/leads`), off by default. Settings: Send form submissions to PingAura Leads, Leads API key (write scope; never shown again after saving, with a Clear checkbox), Domain ID and Default country code (`+91`).
- Fields are mapped by type through `elementor_pro/forms/new_record`: the first email field, the first phone field, the first textarea as the message, a text field whose id or label is name, full name or your name, and other text, select, radio, checkbox, number, date and textarea answers as `custom_fields` (keys from the field id or label, up to 20). With no usable phone field, a text field whose id or label is phone, mobile, whatsapp, contact, contact number or phone number is used as the phone when it reads as one. Hidden, honeypot, reCAPTCHA, step, acceptance and HTML fields are skipped. `consent_state` is `granted` for a ticked acceptance field, `refused` for an unticked one and `unknown` when the form has none. Submissions with neither an email address nor a phone number are not sent.
- Phone numbers are normalised to E.164: separators are removed, `00` becomes `+`, a bare 10-digit number or one with a leading 0 gets the default country code, and a 0 typed right after the country code, as in `+91 (0) 98765 43210`, is dropped. Numbers that still do not match are left out.
- The lead carries `external_id` (`elementor-<form id>-<uuid>`), `source_meta` (form name and page path) and `ai_source`, plus `source_kind` and `source_name` for search engines, social sites, email and paid campaigns.
- `pa_src` first-party cookie (30 minutes, `SameSite=Lax`, `Secure` on HTTPS), set when form leads are on and Elementor Pro is active. It holds the referring host and the `utm_source`, `utm_medium`, `ref` and `ct-referrer` values, at most 200 characters. `pingaura_telemetry_should_track` is called with `$kind = 'source'` before the script sets it.
- Leads are sent on `shutdown`. With PHP-FPM or LiteSpeed the response is flushed first and the request waits up to 5 seconds so the status can be shown on the settings page and, with `WP_DEBUG_LOG`, logged (status codes only). Otherwise the visitor's connection stays open until PHP finishes, so the request is capped at 1 second and its answer is not read; the form's reply can arrive up to about a second later.
- Optional WhatsApp link text, off by default: a click on a `wa.me` or `api.whatsapp.com/send` link without a `text` parameter adds "Hi, I'd like to know more about" with the page title and address. Links inside the Joinchat widget are left alone.
- Saving a malformed Leads API key shows an error and keeps the previous key.
- The front-end script is excluded from delayed JavaScript in LiteSpeed Cache and WP Rocket.
- Unit tests for the field mapper, phone normaliser and source classifier (`php tests/test-lead-mapper.php`), run in CI and left out of the release zip.

## [1.3.0] - 2026-11-14

### Added

- PingAura AI menu in wp-admin, for users who can manage options. Its icon is the PingAura beacon, coloured to match the admin colour scheme.
- Connect to PingAura: a browser approval with a one-time state and a PKCE code verifier. PingAura checks the pending state through the `pingaura/v1/connect-check` REST route before linking the site.
- Dashboard page that shows the PingAura panel in a frame, using a short-lived address requested from PingAura on each visit.
- Fixes page to preview, apply and undo suggested fixes: JSON-LD in the page head, a served `/llms.txt` and rules appended to the generated `robots.txt`. Undo restores the fix that was applied before, if any.
- Requests to PingAura are signed with a per-site secret, and the list of fixes is accepted only with a valid response signature.
- A request PingAura refuses as a replay (HTTP 409) is signed again with a later timestamp and sent once more.
- The site disconnects from PingAura when you click Disconnect, deactivate the plugin or delete it.
- `PINGAURA_AI_APP_URL` constant to send the plugin's requests to a different address.
- Apply uses exactly the fix you previewed: its kind, target path and content. If PingAura changed any of them in the meantime, nothing is applied and you are asked to review it again.
- The llms.txt and robots.txt fixes need pretty permalinks (Settings → Permalinks). With plain permalinks they are shown but cannot be applied.
- JSON-LD is not added to addresses with a query string, such as `?p=5`, other than tracking parameters such as `utm_` tags, `gclid` and `fbclid`.
- Reload button on the PingAura AI page, to open a fresh panel session after the panel times out.
- While connected, the site sends PingAura the addresses and titles of its published pages, posts and WooCommerce products (up to 1,000, home page first, password-protected ones left out), with whether search engines are discouraged and whether the site is in maintenance mode. PingAura uses the list when it cannot read the sitemap. The list is sent through WP-Cron a few seconds after connecting, once a day, and shortly after Search engine visibility changes, to `/api/wordpress/pages` in signed requests of up to 100 pages. Each sync carries a random ID and its start time, and when it sends the whole list PingAura removes the pages no longer on it. Addresses with a query string are left out, except WordPress's plain permalinks (`?p=<id>`, `?page_id=<id>` and `?post_type=<type>&p=<id>`).
- `pingaura_ai_maintenance_mode` filter, for maintenance plugins that WordPress itself does not detect.
- The PingAura AI page shows when the page list was last sent and how many pages PingAura received.
- The Fixes page explains an empty list when PingAura says why: the site has not been audited yet, an audit is running, PingAura could not read the site's pages, or nothing needs fixing.

### Changed

- Renamed to PingAura AI. The slug, folder, text domain and option names stay `pingaura-telemetry`, so existing settings carry over.
- The settings page moved from Settings to PingAura AI → Settings. The old address redirects.

## [1.2.0] - 2026-11-02

### Added

- Optional contact-click tracking with a PingAura browser key. The browser script follows the same modes as the visit script and sends the Domain setting.
- `click` value for the `$kind` argument of the `pingaura_telemetry_should_track` filter.
- The script is excluded from delayed JavaScript in LiteSpeed Cache and WP Rocket.

## [1.1.0] - 2026-10-29

### Added

- Optional IndexNow submission when a page is published or updated, off by default. The key is generated by the plugin and served at `/{key}.txt`.
- "Submit all published pages" button to send existing pages to IndexNow.

## [1.0.1] - 2026-10-28

### Changed

- Tested up to WordPress 7.1.
- The visit script is built from plain strings instead of a heredoc, as the WordPress.org Plugin Check requires.
- WordPress.org contributor name in readme.txt.

## [1.0.0] - 2026-10-28

### Added

- Visit tracking through a script that runs after the page has loaded, so cached pages are counted.
- REST route that adds the visitor's IP address and user agent and forwards visits to PingAura, keeping the ingest key on the server.
- Optional server-side reporting of AI crawler requests, with a filter for the user-agent pattern.
- Off, Test and Live modes. New installs start in Test mode.
- Settings page with connection tests, a per-browser test switch and the last response from PingAura.
- Cache clearing for LiteSpeed Cache, WP Rocket and WP Super Cache when settings are saved.
- Events are forwarded after the response has been sent, and sending pauses for two minutes after a failed request.
- Query strings other than `utm_*` tags and fragments are stripped from page URLs, and query strings from referrers.
- Logged-in users are skipped in Live mode.
- `PINGAURA_TELEMETRY_KEY` constant for keeping the key in `wp-config.php`.
- `pingaura_telemetry_should_track`, `pingaura_telemetry_track_logged_in_users`, `pingaura_telemetry_crawler_pattern` and `pingaura_telemetry_client_ip` filters.
- Suggested privacy policy text.

[1.4.0]: https://github.com/pingaura-ai/pingaura-wordpress/releases/tag/v1.4.0
[1.3.0]: https://github.com/pingaura-ai/pingaura-wordpress/releases/tag/v1.3.0
[1.2.0]: https://github.com/pingaura-ai/pingaura-wordpress/releases/tag/v1.2.0
[1.1.0]: https://github.com/pingaura-ai/pingaura-wordpress/releases/tag/v1.1.0
[1.0.1]: https://github.com/pingaura-ai/pingaura-wordpress/releases/tag/v1.0.1
[1.0.0]: https://github.com/pingaura-ai/pingaura-wordpress/releases/tag/v1.0.0
