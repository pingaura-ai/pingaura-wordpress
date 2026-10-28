# PingAura Telemetry for WordPress

See the visits ChatGPT, Perplexity, Gemini and other AI assistants send to your WordPress site, and the AI crawlers that read it, in [PingAura](https://www.pingaura.ai).

For Next.js, Node.js and Cloudflare Workers, use [`@pingaura/telemetry`](https://github.com/pingaura-ai/pingaura-telemetry-sdk) instead.

## How it works

- A short script runs after each page has loaded and posts the page URL, referrer and title to a REST route on your own site (`/wp-json/pingaura-telemetry/v1/visit`). Because it runs in the browser, cached pages are counted.
- The site answers immediately, then adds the visitor's IP address and user agent and forwards the event to PingAura after the response has been sent. The ingest key stays on the server.
- AI crawlers do not run scripts. When crawler reporting is on, requests from known AI crawler user agents are reported from PHP after the response has been sent.
- Query strings are stripped from page URLs except `utm_*` tags, and from referrers. Fragments are always stripped.
- Logged-in users are not tracked in Live mode.
- If PingAura is unreachable or rejects an event, sending pauses for two minutes.
- No cookies are set for visitors.

## Install

1. Download `pingaura-telemetry.zip` from the [latest release](../../releases/latest).
2. In WordPress, go to Plugins → Add New → Upload Plugin, upload the zip and activate it.
3. In PingAura, create an ingest key under Settings → API keys.
4. In WordPress, open Settings → PingAura Telemetry, paste the key and save.
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
2. Turn on **AI crawlers** in Settings → PingAura Telemetry.

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
