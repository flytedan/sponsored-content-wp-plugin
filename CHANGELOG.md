# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-10

### Added

- `fdhc_hosted_post` custom post type, with an archive at `/hosted-content/`.
- `flytedesk/v1` REST API: `POST /posts`, `GET /posts/{id}`, `PUT /posts/{id}`, `DELETE /posts/{id}`, authenticated via WordPress Application Passwords.
- Dependency-free Markdown-to-HTML converter for article bodies.
- SEO adapter layer with support for Yoast SEO, Rank Math, and All in One SEO, plus a built-in fallback (own meta storage + `wp_head()` output) when no supported SEO plugin is active.
- Registration with sponsored.flytedesk.com, gated behind an explicit "Connect to flytedesk" opt-in (see Changed below): provisions a dedicated low-privilege `flytebot` user (custom `flytedesk_api` role, holding only the `flytedesk_manage_hosted_content` capability), issues it an Application Password, and sends both in the registration request so flytedesk's platform can start using this site's API immediately once a human accepts the registration. The inbound `/flytedesk-registration-confirmation` webhook is authenticated via a bearer token shared during registration.
- A redesigned **Registration** page under **Hosted Content** in wp-admin: a live status timeline (Pending → Connected → Awaiting Response → Accepted/Rejected, each stage timestamped), a Technical Details tab showing the last registration request/response with its HTTP status, and an About Hosted Content tab explaining the network and how the plugin works to publishers, with an FAQ. The page polls every 3 seconds and updates in place - no reload needed to see a registration get accepted or rejected - and the Re-register button runs over AJAX with a loading state instead of a full-page form post.
- A "Connected" step on that timeline, populated by an immediate `{"status":"ping"}` (or `"Connected"`) callback sponsored.flytedesk.com sends to the confirmation webhook right after registration - separates "did our registration reach them" from "can they reach back to us" instead of only surfacing a broken webhook path once a human tries to accept.
- Composer PSR-4 autoloading, PHPUnit (Brain Monkey unit suite + wp-env-backed integration suite), and WordPress Coding Standards via PHPCS.
- Deactivating now removes the `flytebot` user and its Application Password, revoking flytedesk's access the moment the plugin is turned off; registration status/token are left intact so reactivating re-registers rather than starting over. Deleting the plugin (`uninstall.php`) goes further, additionally removing every `flytedesk_*` option and the `flytedesk_api` role - a clean slate. Neither step touches already-published `fdhc_hosted_post` content.
- The Registration page now warns when no supported SEO plugin (Yoast, Rank Math, or All in One SEO) is active, with install links for each - content still publishes fine either way, but only a real SEO plugin also produces an XML sitemap entry and structured data for it.
- A "Verified" step on the timeline, plus a Create/Update/Delete breakdown, tracking whether flytedesk's platform has ever actually succeeded at each REST operation against this site (`Registration\VerificationTracker`) - Accepted only proves a human reviewed the registration, not that the integration works end to end, so this closes that gap with real evidence instead of an assumption. Verification is scoped to the current registration cycle: `Client::register()` resets it every time a registration is freshly (re-)sent, so a CRUD success from before that attempt - proof of a connection that may no longer even be the one in use - can't count toward it.

### Changed

- Registration is no longer sent automatically on activation. WordPress.org's Plugin Directory guidelines (#7) require explicit, informed consent before a plugin contacts an external server - activating a plugin isn't itself that consent. The Registration page now shows a plain-language explanation of exactly what registering will do first; only clicking "Connect to flytedesk" actually sends anything, and `Registration\Consent` records who clicked it, when, and from what IP as a real audit trail (visible on the Technical Details tab). Once granted, deactivating/reactivating the plugin does not ask again - only a full uninstall clears it.

### Fixed

- `Plugin::activate()` now registers its rewrite rule directly instead of relying on `init` having already fired earlier in the same request - confirmed via real-world testing that `wp plugin activate` (WP-CLI) doesn't reliably guarantee that ordering, which left the compiled rewrite rules missing `/flytedesk-registration-confirmation` entirely after activation.
- Admin page CSS/JS are now cache-busted with the asset file's own modification time instead of the plugin's static version string, so a release that changes the CSS/JS without remembering to bump that string no longer risks leaving browsers on a stale cached copy after an update.
- The Registration page's AJAX endpoints now return a proper JSON error on an expired/invalid nonce instead of WordPress's default bare `-1` response, keeping every failure path in the same response shape.
- The Registration page's polling no longer stops the moment a registration is Accepted - flytedesk can still create/update/delete content against the site afterward, and the Verified step only lights up once that happens, so a page left open on Accepted now keeps checking until verification actually completes instead of requiring a manual reload to see it.
- The Accepted and Verified timeline steps now render green immediately once reached, instead of the blue "still in progress" style meant for steps that are genuinely waiting on something else to happen next - reaching either one means that outcome has already happened.
- The Pending step now shows the timestamp of the most recent registration attempt instead of the site's one-time original registration date - it previously stayed frozen at whenever the site was first ever activated, so every re-registration misleadingly looked stuck on a stale, unrelated date instead of reflecting the attempt that was actually just made.
