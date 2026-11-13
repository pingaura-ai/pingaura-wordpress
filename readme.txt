=== PingAura AI: AI Visibility, AI Traffic and Fixes ===
Contributors: pingauraai
Tags: ai visibility, ai traffic, chatgpt, ai crawlers, analytics
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See how AI assistants like ChatGPT, Gemini and Perplexity mention your brand, track the AI visits and crawlers you get, and apply suggested fixes.

== Description ==

PingAura AI connects a WordPress site to [PingAura](https://www.pingaura.ai), which shows how AI assistants such as ChatGPT, Gemini and Perplexity find, read and recommend your content. The plugin was called PingAura Telemetry before version 1.3.0; its settings carry over.

With the plugin active, the AI Traffic section of PingAura shows:

* Visits referred by AI assistants such as ChatGPT, Perplexity and Gemini, by page.
* Requests from AI crawlers such as GPTBot, ClaudeBot and PerplexityBot (optional).

* Human visits, countries and top pages alongside them.

It can also tell Bing and other IndexNow search engines about new and updated pages as soon as they are published (optional). Bing powers the web results in ChatGPT search and Copilot, so this helps new pages reach AI answers sooner.

= PingAura AI menu =

The plugin adds a PingAura AI menu to wp-admin, visible to administrators:

* **Dashboard**: connect the site to PingAura, then see PingAura inside WordPress: how AI assistants such as ChatGPT, Gemini and Perplexity mention your brand, a check of your pages, and your AI traffic.
* **Fixes**: changes PingAura suggests, such as structured data (JSON-LD) for your home page, an llms.txt file and robots.txt rules that let AI answer engines read your pages. Preview each one, apply it with one click, and undo it at any time.
* **Settings**: the telemetry, browser key and IndexNow settings, unchanged.

Connecting is optional. Telemetry, contact clicks and IndexNow work without it, and nothing on your site changes until you apply a fix.

Fixes are added through WordPress hooks; the plugin does not edit your theme or write files. JSON-LD is printed in the page head, llms.txt is served at /llms.txt and robots.txt rules are appended after your site's own rules. If your site already has an llms.txt or robots.txt file on the server, the plugin leaves it alone and tells you to edit it by hand.

= How it works =

* A short script runs after each page has loaded and reports the page, its referrer and its title to your own site. It works on cached pages and is skipped for automated browsers.
* Your site adds the visitor's IP address and user agent and forwards the visit to PingAura after its response has been sent. Your ingest key stays on the server and is never sent to the browser.
* AI crawlers do not run scripts, so they are recorded on the server when the request reaches WordPress.
* Query strings are removed from page addresses, except utm_ campaign tags, and from referrers. Fragments are always removed.
* Logged-in users are not tracked in Live mode.
* The plugin sets no cookies for visitors. Without a browser key it stores nothing in their browser.
* If PingAura cannot be reached, sending pauses for two minutes, so an outage never slows your site.

= Contact clicks (optional) =

Add a browser key to also record clicks on phone, email and WhatsApp links, including the Joinchat button, by page and by the AI assistant or other source that brought the visitor. A click shows interest; it is not a confirmed lead.

Create the key in PingAura under Settings, API & MCP, Create key, Browser, and paste it into the Browser key field. The key is public and safe to show in your page's HTML. The plugin then loads PingAura's browser script on your pages, following the same Off, Test and Live modes as visits. It asks LiteSpeed Cache and WP Rocket not to delay the script, so the first tap is counted.

= Test mode =

New installs start in Test mode: nothing is sent for real visitors until you switch to Live. In Test mode you can turn on test visits for your own browser and send sample events from the settings page to check the connection.

= Requirements =

* For the PingAura AI menu: a PingAura account. You can create a free one while connecting.
* For telemetry: a PingAura account with the domain added and PingAura Telemetry enabled, and an ingest key created in PingAura under Settings → API keys.

== External services ==

This plugin sends data to PingAura, an AI visibility and analytics service operated by PingAura AI. It is needed to show your AI referral and crawler traffic in PingAura.

Nothing is sent until you save an ingest key or a browser key and choose Test or Live mode. From then on:

* In Live mode, for each page view by a visitor who is not logged in: the page URL without query parameters other than utm_ tags, its title, the referring URL without its query string, the visitor's IP address and user agent, and the plugin's name and version. The IP address is used to determine the country and to recognise verified crawlers.
* If AI crawler reporting is on, for each request from a known AI crawler: the requested URL, the response status, and the crawler's IP address and user agent.
* In Test mode, the same data, but only for browsers where test visits are turned on, and for test events you send from the settings page.

Data is sent to `https://telemetry.pingaura.ai/v1/events` with your ingest key.

**Contact clicks (optional, off until you save a browser key).** The visitor's browser loads a script from `https://telemetry.pingaura.ai/t.js`. When the visitor clicks a phone, email or WhatsApp link, the browser sends to `https://telemetry.pingaura.ai/v1/browser`: that a click happened and its type (phone, email or WhatsApp), the page URL without its query string, the site or AI assistant that brought the visitor (the referring site's address and the utm_source tag), and your browser key and domain. The phone number, email address and message are not sent. As with any web request, PingAura's server also receives the visitor's IP address and user agent. The script sets no cookies; it stores the landing source in the visitor's browser (local storage) for 30 minutes so a click on a later page is credited to it. The same modes apply: nothing in Off mode, only browsers with test visits turned on in Test mode, and visitors who are not logged in in Live mode.

**IndexNow (optional, off by default).** When you turn on IndexNow, the plugin sends the addresses of published pages, your site's host name and an IndexNow key to `https://api.indexnow.org/indexnow`, which shares them with participating search engines such as Bing, Yandex, Naver and Seznam. This happens when a page is published or updated, and when you click "Submit all published pages". No visitor data is sent. The key is generated by the plugin and served at `/{key}.txt` on your site so search engines can verify it. IndexNow terms: https://www.indexnow.org/terms

**PingAura AI connection (optional, off until you click Connect to PingAura).** Connecting and using the PingAura AI menu sends no visitor data. It makes these requests to the PingAura app at `https://www.pingaura.ai`:

* When you click Connect to PingAura, your browser opens `https://www.pingaura.ai/wordpress/connect` with your site's address, the address of the PingAura AI page in your wp-admin, a random one-time state value, a code challenge and the plugin version. You sign in to PingAura and approve the connection there.
* While you approve, PingAura's server requests `/wp-json/pingaura/v1/connect-check` on your site to confirm the connection was started from it. The route answers only for the pending one-time state, and only for 15 minutes.
* After approval, your site sends the one-time code, the code verifier and your site's address to `https://www.pingaura.ai/api/wordpress/connect/token`, and receives a site ID and a site secret, which are stored in your database and never shown in the browser.
* Each time an administrator opens PingAura AI, Dashboard, your site requests a short-lived panel address from `https://www.pingaura.ai/api/wordpress/panel-session` and shows it in a frame. The panel then loads from `https://www.pingaura.ai` in the administrator's browser.
* Each time an administrator opens PingAura AI, Fixes, or applies a fix, your site requests the suggested fixes from `https://www.pingaura.ai/api/wordpress/fixes`.
* When a fix is applied or undone, your site tells `https://www.pingaura.ai/api/wordpress/fixes/{fix}/applied` or `/reverted`, with a SHA-256 checksum of the applied content.
* When you click Disconnect, deactivate the plugin or delete it, your site tells `https://www.pingaura.ai/api/wordpress/disconnect`, and the site ID and secret are deleted.

These requests carry your site ID and a signature made with the site secret. To change the address, for example to test against another PingAura environment, define `PINGAURA_AI_APP_URL` in wp-config.php.

You are responsible for having a lawful basis, or your visitors' consent where it is required, to send this data. The plugin adds suggested text to Settings → Privacy, and the `pingaura_telemetry_should_track` filter lets a consent tool turn tracking off for a visitor.

* Terms of service: https://www.pingaura.ai/terms-of-service
* Privacy policy: https://www.pingaura.ai/privacy-policy

== Installation ==

1. Install and activate the plugin.
2. In PingAura, create an ingest key under Settings → API keys.
3. In WordPress, open PingAura AI → Settings, paste the key and save. Check that Domain matches the domain registered in PingAura.
4. Click "Send a ChatGPT visit". A reply starting with 202 means the connection works.
5. Switch Mode to Live and save.

The full guide is at https://www.pingaura.ai/docs/ai-traffic/wordpress-setup.

To use the PingAura AI menu, open PingAura AI → Dashboard, click Connect to PingAura and approve the connection. Then open PingAura AI → Fixes to review suggested fixes.

== Frequently Asked Questions ==

= Do I have to connect to PingAura AI? =

No. Telemetry, contact clicks and IndexNow work with only the settings page. Connecting adds the PingAura dashboard and suggested fixes.

= What happens to applied fixes if I disconnect? =

They stay in place, and you can still undo them under PingAura AI → Fixes. Deactivating the plugin turns them off; deleting it removes them.

= Why can I not apply the llms.txt or robots.txt fix? =

WordPress serves /llms.txt and the generated /robots.txt through its rewrite rules, which exist only with pretty permalinks. If Settings → Permalinks is set to Plain, turn on any other structure and the Apply button appears. These fixes also cannot be applied when a real llms.txt or robots.txt file is on the server, because the server sends that file instead.

= Where did the settings page go? =

It moved from Settings → PingAura Telemetry, the plugin's earlier name, to PingAura AI → Settings. The old address redirects there.

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

= What does IndexNow do? =

IndexNow is an open protocol for telling search engines a page has changed. With it turned on, Bing and other participating search engines hear about a new or updated page within seconds instead of waiting to recrawl. Click "Submit all published pages" once after turning it on to send your existing pages. It works without a PingAura account. Google does not support IndexNow; for Google, submit your sitemap in Search Console.

Right after you turn it on, the first submission can be refused with 403 while the search engine verifies the key file. Wait a few minutes and click the button again.

= What does contact-click tracking record? =

Only that a click on a phone, email or WhatsApp link happened, on which page, and which AI assistant or other source brought the visitor. It does not record the phone number, email address or message. It sets no cookies. It stores the landing source in the visitor's browser for 30 minutes, so a click a few pages later is still credited to the AI assistant that sent the visitor.

= Does it set cookies? =

Not for visitors. An administrator who turns on test visits gets a cookie in their own browser for 24 hours.

= What does the reply on the settings page mean? =

202 means PingAura accepted the event. 401 means the key is wrong or revoked. 403 means the domain is not registered on the key's account, or PingAura Telemetry is not enabled for it.

== Changelog ==

= 1.3.0 =
* Renamed to PingAura AI. Settings, data and the plugin's folder are unchanged.
* New PingAura AI menu: connect the site to PingAura and see the PingAura dashboard in wp-admin.
* Fixes page: preview, apply and undo suggested JSON-LD, llms.txt and robots.txt fixes.
* Apply uses exactly the fix you previewed, at the place you previewed it; if the fix changed in the meantime, you are asked to review it again.
* The llms.txt and robots.txt fixes need pretty permalinks.
* The settings page moved to PingAura AI → Settings. The old address redirects.

= 1.2.0 =
* Optional contact-click tracking with a PingAura browser key.

= 1.1.0 =
* Add optional IndexNow submission for new and updated pages, with a one-click submission of all published pages.

= 1.0.1 =
* Tested up to WordPress 7.1.
* Pass the WordPress.org Plugin Check.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.3.0 =
Renamed to PingAura AI. Adds an optional PingAura AI menu with a dashboard and one-click fixes. Telemetry settings move to PingAura AI → Settings and keep working without connecting.

= 1.2.0 =
Adds optional contact-click tracking. Off until you save a browser key.

= 1.1.0 =
Adds optional IndexNow submission. Off until you turn it on.

= 1.0.1 =
No functional changes.

= 1.0.0 =
First release.
