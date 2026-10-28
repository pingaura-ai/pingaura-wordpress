=== PingAura Telemetry ===
Contributors: pingauraai
Tags: ai traffic, chatgpt, analytics, ai crawlers, perplexity
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See the visits ChatGPT, Perplexity, Gemini and other AI assistants send to your site, and the AI crawlers that read it, in PingAura.

== Description ==

PingAura Telemetry connects a WordPress site to [PingAura](https://www.pingaura.ai), which shows how AI assistants find, read and recommend your content.

With the plugin active, the AI Traffic section of PingAura shows:

* Visits referred by AI assistants such as ChatGPT, Perplexity and Gemini, by page.
* Requests from AI crawlers such as GPTBot, ClaudeBot and PerplexityBot (optional).
* Human visits, countries and top pages alongside them.

= How it works =

* A short script runs after each page has loaded and reports the page, its referrer and its title to your own site. It works on cached pages and is skipped for automated browsers.
* Your site adds the visitor's IP address and user agent and forwards the visit to PingAura after its response has been sent. Your ingest key stays on the server and is never sent to the browser.
* AI crawlers do not run scripts, so they are recorded on the server when the request reaches WordPress.
* Query strings are removed from page addresses, except utm_ campaign tags, and from referrers. Fragments are always removed.
* Logged-in users are not tracked in Live mode.
* The plugin sets no cookies for visitors and stores nothing in their browser.
* If PingAura cannot be reached, sending pauses for two minutes, so an outage never slows your site.

= Test mode =

New installs start in Test mode: nothing is sent for real visitors until you switch to Live. In Test mode you can turn on test visits for your own browser and send sample events from the settings page to check the connection.

= Requirements =

* A PingAura account with the domain added and PingAura Telemetry enabled.
* An ingest key, created in PingAura under Settings → API keys.

== External services ==

This plugin sends data to PingAura, an AI visibility and analytics service operated by PingAura AI. It is needed to show your AI referral and crawler traffic in PingAura.

Nothing is sent until you save an ingest key and choose Test or Live mode. From then on:

* In Live mode, for each page view by a visitor who is not logged in: the page URL without query parameters other than utm_ tags, its title, the referring URL without its query string, the visitor's IP address and user agent, and the plugin's name and version. The IP address is used to determine the country and to recognise verified crawlers.
* If AI crawler reporting is on, for each request from a known AI crawler: the requested URL, the response status, and the crawler's IP address and user agent.
* In Test mode, the same data, but only for browsers where test visits are turned on, and for test events you send from the settings page.

Data is sent to `https://telemetry.pingaura.ai/v1/events` with your ingest key.

You are responsible for having a lawful basis, or your visitors' consent where it is required, to send this data. The plugin adds suggested text to Settings → Privacy, and the `pingaura_telemetry_should_track` filter lets a consent tool turn tracking off for a visitor.

* Terms of service: https://www.pingaura.ai/terms-of-service
* Privacy policy: https://www.pingaura.ai/privacy-policy

== Installation ==

1. Install and activate the plugin.
2. In PingAura, create an ingest key under Settings → API keys.
3. In WordPress, open Settings → PingAura Telemetry, paste the key and save. Check that Domain matches the domain registered in PingAura.
4. Click "Send a ChatGPT visit". A reply starting with 202 means the connection works.
5. Switch Mode to Live and save.

The full guide is at https://www.pingaura.ai/docs/ai-traffic/wordpress-setup.

== Frequently Asked Questions ==

= Does it slow my site down? =

It does not delay page rendering. The script is a few hundred bytes, runs after the page has loaded, and sends one small background request to your own site, which answers straight away and forwards the visit afterwards. Each tracked visit is one extra, uncached request to WordPress, similar to other self-hosted analytics plugins.

= Does it work with page caching plugins? =

Yes. Visits are recorded by the script, so cached pages are counted. Saving the settings clears LiteSpeed Cache, WP Rocket and WP Super Cache so the script appears straight away. Other caches may need clearing by hand.

= Why are AI crawler requests not showing up? =

A page cache usually answers crawler requests before WordPress runs. Add the crawler user agents (GPTBot, ClaudeBot, PerplexityBot and others) to your cache plugin's list of user agents that are never cached, and turn on AI crawler reporting.

= Can I keep the ingest key out of the database? =

Yes. Add `define( 'PINGAURA_TELEMETRY_KEY', 'pa_...' );` to wp-config.php. It takes precedence over the key saved on the settings page.

= My site is behind Cloudflare. Are countries correct? =

Behind a CDN, WordPress sees the CDN's address unless your server restores the visitor's. If countries look wrong, read the CDN's header with the `pingaura_telemetry_client_ip` filter. See the plugin's README for an example.

= Can visit data be faked? =

Like any analytics that relies on the visitor's browser, anyone can send fake visits to your site. PingAura limits how many events a workspace can send, and the plugin ignores requests from other sites and malformed data.

= Does it set cookies? =

Not for visitors. An administrator who turns on test visits gets a cookie in their own browser for 24 hours.

= What does the reply on the settings page mean? =

202 means PingAura accepted the event. 401 means the key is wrong or revoked. 403 means the domain is not registered on the key's account, or PingAura Telemetry is not enabled for it.

== Changelog ==

= 1.0.1 =
* Tested up to WordPress 7.1.
* Pass the WordPress.org Plugin Check.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.1 =
No functional changes.

= 1.0.0 =
First release.
