=== Flytedesk Hosted Content ===
Contributors: flytedesk
Tags: seo, yoast, rank-math, aioseo, hosted-content
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publishes flytedesk-managed hosted/native articles via a REST API, with correct SEO metadata regardless of which SEO plugin (if any) is active.

== Description ==

Flytedesk Hosted Content gives the flytedesk platform a REST API for pushing hosted/native content directly into a publisher's own WordPress site. Content lands in a dedicated `fdhc_hosted_post` post type, published immediately, with an archive at `/hosted-content/`.

The plugin's core job is making sure SEO metadata (title, description, keywords, Open Graph tags) lands correctly no matter which SEO plugin the publisher runs:

* Yoast SEO
* Rank Math
* All in One SEO (AIOSEO)
* No SEO plugin at all (a built-in fallback writes its own meta and renders `<meta name="description">` / Open Graph tags directly)

Authentication uses a flytedesk-generated API key, unique to this site and entirely disconnected from WordPress's own authentication system - no WordPress user is created, and no WordPress capability, cookie, or nonce is ever checked. Nothing is sent to sponsored.flytedesk.com automatically: visit the **Registration** page under **Hosted Content** in wp-admin, review exactly what connecting will do, and click **Connect to flytedesk** to opt in - that one click generates the key and sends the registration, so a human never has to generate and hand over credentials manually. The same page shows live status (Pending / Registration Sent / Accepted / Rejected / Verified) and a re-register button.

= Endpoints =

* `POST /wp-json/flytedesk/v1/posts`
* `GET /wp-json/flytedesk/v1/posts/{id}`
* `PUT /wp-json/flytedesk/v1/posts/{id}`
* `DELETE /wp-json/flytedesk/v1/posts/{id}`

See README.md in the plugin directory for the full field reference and curl examples.

== Installation ==

1. Upload the `flytedesk-hosted-content` directory to `/wp-content/plugins/`.
2. From the plugin directory on the server, run `composer install --no-dev` to install its runtime dependencies (there are none beyond the autoloader itself, but this step generates `vendor/autoload.php`, which the plugin requires to boot).
3. Activate the plugin through the "Plugins" screen in WordPress. WordPress itself will refuse activation with an explanatory notice if the site doesn't meet the "Requires at least" / "Requires PHP" versions declared above.
4. Visit **Hosted Content → Registration** in wp-admin, review exactly what connecting will do, and click **Connect to flytedesk**. That single click generates the site's own API key and sends the registration - nothing is sent anywhere before you take that action.

== Frequently Asked Questions ==

= Does this work if the site has no SEO plugin installed? =

Yes. A built-in fallback adapter stores the SEO fields on the post itself and outputs `<meta name="description">` and Open Graph tags directly in `wp_head()`.

= What happens if both Yoast and Rank Math are active? =

Running two SEO plugins at once is an unsupported WordPress configuration in general - the plugin picks the first active adapter in priority order (Yoast, then Rank Math, then AIOSEO, then the fallback) and writes to that one only. It never attempts to write to more than one SEO plugin at a time.

= Is content sanitized? =

Yes. Markdown is converted to HTML and then passed through `wp_kses_post()`, which strips script tags, inline event handlers, and `javascript:` URLs before the content is stored.

= How do I report a security issue? =

See SECURITY.md in the plugin's repository - do not open a public issue for security reports.

== Changelog ==

= 1.0.0 =
* Initial release.
