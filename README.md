# PingAura AI for WordPress

See how AI assistants such as ChatGPT, Gemini and Perplexity mention your brand, track the visits they send to your WordPress site and the AI crawlers that read it, and apply the fixes [PingAura](https://www.pingaura.ai) suggests.

The plugin was called PingAura Telemetry before version 1.3.0. Its slug, folder (`pingaura-telemetry`), text domain and settings are unchanged.

For Next.js, Node.js and Cloudflare Workers, use [`@pingaura/telemetry`](https://github.com/pingaura-ai/pingaura-telemetry-sdk) instead.

## How it works

- A short script runs after each page has loaded and posts the page URL, referrer and title to a REST route on your own site (`/wp-json/pingaura-telemetry/v1/visit`). Because it runs in the browser, cached pages are counted.
- The site answers immediately, then adds the visitor's IP address and user agent and forwards the event to PingAura after the response has been sent. The ingest key stays on the server.
- AI crawlers do not run scripts. When crawler reporting is on, requests from known AI crawler user agents are reported from PHP after the response has been sent.
- Query strings are stripped from page URLs except `utm_*` tags, and from referrers. Fragments are always stripped.
- Logged-in users are not tracked in Live mode.
- If PingAura is unreachable or rejects an event, sending pauses for two minutes.
- No cookies are set for visitors, except `pa_src` when form leads are on (see below).

## Contact clicks (optional)

Save a browser key (PingAura: Settings, API & MCP, Create key, Browser) in the **Browser key** field to record clicks on phone, email and WhatsApp links, including the Joinchat button, by page and AI source.

- The plugin loads `https://telemetry.pingaura.ai/t.js` with `data-key` and `data-domain` attributes, deferred. `data-domain` is the Domain setting. The browser key is public.
- It follows the same modes as visits. In Test mode a small inline loader adds the script only in browsers with test visits turned on.
- The number, address and message are not sent. The script sets no cookies and keeps the landing source in local storage for 30 minutes.
- The script is excluded from delayed JavaScript in LiteSpeed Cache (`litespeed_optm_js_defer_exc`) and WP Rocket (`rocket_delay_js_exclusions`).
- `pingaura_telemetry_should_track` is called with `$kind = 'click'` before the script is added.

## Form leads (optional)

Turn on **Send form submissions to PingAura Leads** and save a **Leads API key** (write scope) and the **Domain ID** (PingAura: Domain settings) to post Elementor Pro submissions to `https://www.pingaura.ai/api/v1/domains/{domainId}/leads`.

- Hooked to `elementor_pro/forms/new_record`. Fields are mapped by type, not id: first `email`, first `tel` (normalised to E.164 with the **Default country code** for bare 10-digit numbers; a 0 typed after the country code is dropped), first `textarea` as `message`, a `text` field whose id or label is name, full name or your name, and other answers (text, select, radio, checkbox, number, date, further textareas) as `custom_fields`. With no usable `tel` field, a `text` field whose id or label is phone, mobile, whatsapp, contact, contact number or phone number is used as the phone if it reads as one. Without an email or a phone the submission is not sent.
- `consent_state`: `granted` for a ticked acceptance field, `refused` for an unticked one, `unknown` when the form has none.
- Sent on `shutdown`, after the response is flushed where `fastcgi_finish_request()` or `litespeed_finish_request()` exists (blocking, 5 s, status shown on the settings page); otherwise (for example Apache with mod_php, where the visitor's connection stays open until PHP finishes) `ignore_user_abort()` and `flush()` are called and the request is capped at 1 s without reading the answer, so the form's reply can arrive up to about a second later. Errors are swallowed; Elementor's actions are unaffected. Submitted values are never logged.
- Source attribution: an inline script stores the referring host and `utm_source`, `utm_medium`, `ref` and `ct-referrer` in the `pa_src` cookie for 30 minutes. The server classifies it into `ai_source` with the same host and parameter rules as PingAura, and otherwise sends `source_kind`/`source_name` for search, social, email and paid.

## WhatsApp link text (optional)

**Add the page title and link to WhatsApp chat links that have no message** adds a click listener that sets `text` on `wa.me` and `api.whatsapp.com/send` links that have none. Links inside `.joinchat` are skipped. Nothing is sent to PingAura.

## PingAura AI menu (optional)

The **PingAura AI** menu (administrators only) holds a Dashboard, a Fixes page and the telemetry Settings.

- **Connect.** Clicking *Connect to PingAura* stores a random state and a PKCE verifier in a 15-minute transient and opens `https://www.pingaura.ai/wordpress/connect`. PingAura calls `GET /wp-json/pingaura/v1/connect-check?state=…` on the site, which answers `{state, code_challenge}` only for the pending state and 404 otherwise. After approval the browser returns with a one-time code, which the site exchanges with the verifier at `/api/wordpress/connect/token` for a site ID and site secret (stored in options that are not autoloaded).
- **Signed requests.** Requests to the app carry `X-PingAura-Site`, `X-PingAura-Timestamp` and `X-PingAura-Signature`, the hex HMAC-SHA256 of `{timestamp}\n{METHOD}\n{path}\n{sha256(body)}` with the site secret. The timestamp increases with every request; if the app still answers `409 {"error":"replayed"}`, the request is signed again with a later timestamp and sent once more. The list of fixes is used only if its `X-PingAura-Signature` (HMAC of `{timestamp}\n{body}`) checks out.
- **Dashboard.** Each visit requests a one-hour panel address from `/api/wordpress/panel-session` and shows it in a frame. No long-lived secret reaches the browser.
- **Fixes.** JSON-LD is printed in `wp_head` on its target path, `llms.txt` is served at `/llms.txt` when no file exists on disk, and robots.txt rules are appended through the `robots_txt` filter after the site's own rules (never while the site discourages indexing). Applied fixes live in the `pingaura_ai_fixes` option; Undo restores what the slot held before.
- **Page list.** While connected, WP-Cron (`pingaura_ai_sync_pages`) posts the home page and published pages, posts and products (`url`, `title`, `type`; up to 1,000, password-protected ones and addresses with a query string left out, except WordPress's plain permalinks `?p=<id>`, `?page_id=<id>` and `?post_type=<type>&p=<id>`) to `/api/wordpress/pages` in signed requests of up to 100, with `state` (`blog_public`, `maintenance`), the sync's `sync_id` (UUID) and `started_at` (Unix time), and `complete` on the last request when the whole list fit (under 1,000 entries and 10 requests), which lets PingAura remove the pages the list no longer has. It runs a few seconds after connecting, daily, and after `blog_public` changes, so PingAura can audit sites whose sitemap is off. Use the `pingaura_ai_maintenance_mode` filter to report a maintenance plugin WordPress does not detect.
- **Disconnect** happens on the button, on deactivation (without waiting) and on deletion.
- Define `PINGAURA_AI_APP_URL` in `wp-config.php` to send the plugin's requests to a different address.

## Install

1. Download `pingaura-telemetry.zip` from the [latest release](../../releases/latest).
2. In WordPress, go to Plugins → Add New → Upload Plugin, upload the zip and activate it.
3. In PingAura, create an ingest key under Settings → API keys.
4. In WordPress, open PingAura AI → Settings, paste the key and save.
5. Click **Send a ChatGPT visit**. A reply starting with `202` means the connection works.
6. Switch **Mode** to **Live** and save.

Full guide: <https://www.pingaura.ai/docs/ai-traffic/wordpress-setup>

## Modes

| Mode | What is sent |
| --- | --- |
| Off | Nothing. |
| Test | Only visits from browsers where an administrator turned on test visits, plus test events sent from the settings page. The default for new installs. |
| Live | Every visit, and AI crawler requests if crawler reporting is on. |

## Recording AI crawlers

Page caches answer most requests before WordPress runs, so crawler requests never reach the plugin. To record them:

1. Add the AI crawler user agents to your cache plugin's never-cache list, for example `GPTBot`, `ChatGPT-User`, `OAI-SearchBot`, `ClaudeBot`, `PerplexityBot`, `Google-Extended`.
2. Turn on **AI crawlers** in PingAura AI → Settings.

## IndexNow

Turn on **IndexNow** in PingAura AI → Settings to tell Bing and other IndexNow search engines when a page is published or updated. Bing powers the web results in ChatGPT search and Copilot. After turning it on, click **Submit all published pages** once to send existing pages.

- The key is generated by the plugin and served by WordPress at `/{key}.txt`. Nothing is written to disk.
- Submissions go to `https://api.indexnow.org/indexnow` after the response has been sent, so publishing is not slowed down.
- It works without a PingAura ingest key.
- Google does not support IndexNow. Submit your sitemap in Search Console for Google.

## Keeping the key out of the database

Define the key in `wp-config.php` instead of saving it on the settings page:

```php
define( 'PINGAURA_TELEMETRY_KEY', 'pa_...' );
```

## Filters

```php
// Skip tracking for a visitor, for example until they give consent.
add_filter( 'pingaura_telemetry_should_track', function ( $track, $kind ) {
	return $kind === 'crawler' || my_consent_given( 'statistics' );
}, 10, 2 );

// Track logged-in users in Live mode (off by default).
add_filter( 'pingaura_telemetry_track_logged_in_users', '__return_true' );

// Change which user agents count as AI crawlers.
add_filter( 'pingaura_telemetry_crawler_pattern', function () {
	return '/GPTBot|ClaudeBot|PerplexityBot/i';
} );
```

### Visitor IP behind a CDN

The plugin uses `REMOTE_ADDR`, or behind a local proxy the last public address in `X-Forwarded-For`. Behind a CDN that your server does not already unwrap, read the CDN's header. Only do this when every request really comes through that CDN, otherwise visitors can set the header themselves:

```php
// Cloudflare: only trust CF-Connecting-IP if the site is reachable through Cloudflare alone.
add_filter( 'pingaura_telemetry_client_ip', function ( $ip ) {
	$header = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
	return filter_var( $header, FILTER_VALIDATE_IP ) ? $header : $ip;
} );
```

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- A PingAura account with PingAura Telemetry enabled

## Development

```sh
composer install
composer lint      # PHP syntax check
composer phpcs     # WordPress Coding Standards
```

Releases are built by the `Release` workflow when a `v*` tag is pushed.

## Security

Please report vulnerabilities to security@pingaura.ai rather than opening an issue.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
