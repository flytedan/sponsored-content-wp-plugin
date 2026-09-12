# Flytedesk Hosted Content

A standalone WordPress plugin that lets the flytedesk platform push hosted/native articles to a publisher-owned WordPress site via a REST API, with SEO metadata written correctly regardless of which SEO plugin the site runs.

## Architecture overview

```
hosted-content-wp-plugin.php   Plugin header + Composer autoload + activation/deactivation hooks + Plugin::instance()->boot()
uninstall.php                     Removes all plugin data on delete (see "Notes on uninstall" below)
src/
├── Plugin.php                    Orchestrator: wires everything below to WP hooks; owns activate()/deactivate()
├── Capabilities.php              The flytedesk_manage_hosted_content capability + flytedesk_api role
├── PostType.php                  Registers the fdhc_hosted_post CPT
├── Rest/Controller.php           flytedesk/v1 REST routes, request validation/sanitization, error envelope
├── Markdown/Converter.php        Dependency-free markdown -> HTML converter
├── Admin/
│   ├── RegistrationSettingsPage.php   wp-admin page: consent gate, then status/timeline/technical/about tabs
│   └── RegistrationAjaxController.php Backs the page's live polling, consent grant, and re-register button
├── Registration/
│   ├── Client.php                     POSTs registration to sponsored.flytedesk.com, tracks status
│   ├── Consent.php                    Records the explicit opt-in that gates ever calling Client::register()
│   ├── ConfirmationController.php     Handles the inbound /flytedesk-registration-confirmation webhook
│   ├── ApiCredential.php              Provisions the "flytebot" user + issues its Application Password
│   ├── ApplicationPasswordIssuer.php  Narrow interface over WP_Application_Passwords (for testability)
│   ├── WordPressApplicationPasswordIssuer.php   Concrete implementation, backed by WP core
│   ├── VerificationTracker.php        Tracks real Create/Update/Delete successes for the "Verified" step
│   └── StatePresenter.php             Assembles the full registration state shown by the settings page/AJAX
└── Seo/
    ├── AdapterInterface.php      is_active() / write() / read() contract every adapter implements
    ├── AbstractMetaAdapter.php   Shared post-meta read/write traversal driven by each adapter's field_map()
    ├── YoastAdapter.php          Maps onto Yoast SEO's meta keys
    ├── RankMathAdapter.php       Maps onto Rank Math's meta keys
    ├── AioseoAdapter.php         Maps onto All in One SEO's meta keys
    ├── FallbackAdapter.php       Own meta keys + wp_head() <meta>/Open Graph output when no SEO plugin is active
    └── Resolver.php              Picks exactly one active adapter, in priority order
```

**Why an adapter layer at all?** Different publisher sites run different SEO plugins, each with its own private meta-key schema for title/description/Open Graph fields. Rather than have the REST controller know about three (and counting) SEO plugins' internals, each plugin gets its own small adapter behind a shared `AdapterInterface`; `Resolver` detects which one (if any) is active on the current site and the controller talks to whichever adapter that is, never more than one. Yoast + Rank Math both active simultaneously is itself a broken, unsupported WordPress configuration - the resolver's priority order guarantees the plugin never tries to reconcile that by writing to both.

**Why does `AbstractMetaAdapter` exist?** The Yoast/Rank Math/AIOSEO/Fallback adapters all do the literal same thing - read/write a fixed set of SEO fields against a handful of `post_meta` keys, sanitizing on the way in. Only the key names (and, for Yoast's single-phrase focus keyword, a value transform) differ. `AbstractMetaAdapter` owns that read/write traversal once, driven by each concrete adapter's `field_map()`; this is the fix for the duplication present in every field across every adapter in the plugin's original single-file, non-namespaced draft.

**Why no vendored Markdown library?** flytedesk only ever sends a fixed, known subset of Markdown for article bodies (headings, paragraphs, emphasis, links/images, lists, blockquotes, code, horizontal rules) - not arbitrary user-authored Markdown. A ~250-line dependency-free converter covers that subset completely without pulling in a full CommonMark implementation's transitive footprint. It is a formatting convenience only, never the security boundary: `Rest\Controller` always re-sanitizes its output with `wp_kses_post()` before storage.

## Registration with sponsored.flytedesk.com

Beyond the REST API itself, the plugin automates onboarding a new publisher site with flytedesk's platform, so a human never has to manually generate and hand over an Application Password. Nothing is sent to sponsored.flytedesk.com automatically on activation - WordPress.org's Plugin Directory guidelines require explicit, informed consent before a plugin contacts an external server, so activating just seeds local state (the verification token below); registration itself only happens after a human explicitly opts in. The flow:

1. **On activation**, the plugin generates a random verification token (`Registration\Client`, 256 bits of entropy, persisted for the life of the install). Nothing is sent anywhere yet.
2. **A human visits the Registration page** under Hosted Content in wp-admin, reads a plain-language explanation of exactly what registering will do, and clicks **Connect to flytedesk**. `Registration\Consent` records who clicked it, when, and from what IP - a real audit trail, not just a boolean flag - and `Registration\Client::register()` runs immediately in that same click: it provisions (or reuses) a dedicated, low-privilege WordPress user named `flytebot` (see "The flytebot user" below), issues it a fresh Application Password, and `POST`s everything to `https://sponsored.flytedesk.com/wp-plugin-register`:
   ```json
   {
     "site_title": "...",
     "site_domain": "publisher-site.com",
     "verification_token": "<64 hex chars>",
     "api_username": "flytebot",
     "api_key": "<the just-issued Application Password, plaintext>"
   }
   ```
   Status becomes **Registration Sent** only on an HTTP `200` response from that endpoint; anything else (network failure, non-200, or failing to provision the credential) leaves status unchanged and records the failure as "Last error" on the settings page.
3. **A human at flytedesk reviews the registration** and either accepts or rejects it.
4. **sponsored.flytedesk.com confirms the decision** by `POST`ing to `https://{site_domain}/flytedesk-registration-confirmation` with `{"status": "Accepted"}` or `{"status": "Rejected"}`, authenticated via `Authorization: Bearer <the same verification_token from step 2>`. `Registration\ConfirmationController` verifies that token with `hash_equals()` before updating status - **this header is not part of the literal spec's JSON body, and is required**; without it, this endpoint would let anyone who knows a site's domain flip its registration status. `sponsored.flytedesk.com` already has the token from step 2, so sending it back costs nothing on that end.
5. **Once accepted**, flytedesk's platform uses the `api_username`/`api_key` from step 2 to start calling this plugin's own REST API (the routes documented below) immediately - no further manual credential handoff.

A **Registration** page under **Hosted Content** in wp-admin shows a **Connect to flytedesk** consent gate before anything is sent, then the current status (Pending / Registration Sent / Accepted / Rejected), the site domain, verification token, and flytebot username, plus a **Re-register** button to re-send the request on demand once already connected - useful after a rejection, or to retry following a transient failure.

### The flytebot user

`Registration\ApiCredential` provisions a single WordPress user, `flytebot`, holding only the `flytedesk_manage_hosted_content` capability (plus the baseline `read`) via a dedicated `flytedesk_api` role - not `edit_posts` or any other WordPress core capability. This means flytebot can authenticate against *this plugin's* REST routes and nothing else: it cannot log into wp-admin to edit other content, upload media, or use WordPress core's own REST API (`/wp/v2/posts`, etc.). `Rest\Controller::check_permission()` accepts either `edit_posts` (the documented manual-setup path below, for a human-managed Application Password from an Author/Editor/Administrator account) or `flytedesk_manage_hosted_content` - either is sufficient.

Every time a registration attempt runs (the initial "Connect to flytedesk" click or a later "Re-register"), a **fresh** Application Password is issued for flytebot and the previously-issued one is revoked. WordPress only ever shows an Application Password's plaintext once, at creation - there's no way to retrieve a previously-issued one later, which is exactly why the plugin reissues rather than trying to cache and resend the same value.

## Local development setup

Requires PHP 8.1+, [Composer](https://getcomposer.org/), [Node.js](https://nodejs.org/) 18+, and Docker (for `wp-env`).

```bash
composer install
```

This installs both the (empty, at runtime) production dependency set and every dev tool: PHPUnit, Brain Monkey, PHP_CodeSniffer + WordPress Coding Standards + PHPCompatibilityWP.

### Running the linter

```bash
composer run lint       # phpcs - reports violations
composer run lint:fix   # phpcbf - auto-fixes what it safely can
```

### Running the unit test suite (fast, no WordPress required)

```bash
composer run test:unit
```

These tests (`tests/Unit/`) use [Brain Monkey](https://brain-wp.github.io/BrainMonkey/) to mock the WordPress functions each class calls (`update_post_meta()`, `sanitize_text_field()`, etc.) - WordPress itself is never booted, so this suite runs in well under a second and needs no database.

### Running the integration test suite (real WordPress, via wp-env)

```bash
npx wp-env start
npx wp-env run tests-cli --env-cwd=wp-content/plugins/hosted-content-wp-plugin composer run test:integration
```

[`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env) spins up WordPress + MySQL in Docker (config: `.wp-env.json`) and maps this plugin into it. The `tests-cli` container it creates has `WP_TESTS_DIR` pointed at WordPress core's own bundled PHPUnit test suite; `tests/bootstrap.php` detects that environment variable and boots the real thing - `WP_Test_REST_TestCase`, `wp_insert_post()`, actual REST route dispatch - rather than Brain Monkey's mocks. See `tests/Integration/RestControllerTest.php`.

To tear the environment down: `npx wp-env destroy`.

### Running everything CI runs

```bash
composer run test   # unit, then integration (requires wp-env to already be running for integration)
```

## Generating a WordPress Application Password (manual alternative)

The registration flow above handles this automatically via the `flytebot` user - this section only matters if you want to authenticate as a different, human-managed account instead (e.g. for manual testing, or a site that opts out of the registration flow entirely).

Flytedesk authenticates using [WordPress Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/), a core feature since WordPress 5.6. No custom API-key system is used.

1. In WordPress admin, decide which user flytedesk should authenticate as. That user needs the `edit_posts` capability - an Author, Editor, or Administrator account all qualify. A dedicated "flytedesk" user with the Author role is recommended over reusing a personal account.
2. Go to **Users → Profile** (or **Users → All Users → [edit that user]** if you're an administrator editing someone else).
3. Scroll to the **Application Passwords** section near the bottom of the profile screen.
4. Enter a name for the credential (e.g. `flytedesk`) and click **Add New Application Password**.
5. WordPress displays the generated password once. Copy it immediately - it cannot be viewed again after leaving the page.
6. Give flytedesk three things: the site's REST API base URL (`https://example.com/wp-json/`), the WordPress **username** (not email), and the generated application password.

Every request authenticates with an HTTP Basic `Authorization` header:

```
Authorization: Basic base64("wordpress_username:xxxx xxxx xxxx xxxx xxxx xxxx")
```

(Application Passwords are generated with spaces for readability; they work with or without the spaces stripped - WordPress normalizes them.)

If a request is unauthenticated or the authenticated user lacks `edit_posts`, every endpoint returns `401`.

## Markdown conversion

`content` is submitted as Markdown and converted to HTML by `src/Markdown/Converter.php`. See the class doc-comment and the "Why no vendored Markdown library?" note above for the supported subset and rationale. Regardless of what the converter produces, the resulting HTML is always passed through `wp_kses_post()` before being saved - stripping `<script>` tags, inline event handler attributes (`onclick`, etc.), and `javascript:` URLs.

## SEO adapter layer

```php
interface AdapterInterface {
    public function is_active(): bool;
    public function write( int $post_id, array $seo ): void;
    public function read( int $post_id ): array;
}
```

| Adapter | Detects via | Writes |
|---|---|---|
| `YoastAdapter` | `defined('WPSEO_VERSION')` | `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw`, `_yoast_wpseo_opengraph-title`, `_yoast_wpseo_opengraph-description`, `_yoast_wpseo_opengraph-image`, `_yoast_wpseo_opengraph-type` |
| `RankMathAdapter` | `class_exists('RankMath')` | `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword`, `rank_math_facebook_title`, `rank_math_facebook_description`, `rank_math_facebook_image` |
| `AioseoAdapter` | `defined('AIOSEO_VERSION')` | `_aioseo_title`, `_aioseo_description`, `_aioseo_og_title`, `_aioseo_og_description`, `_aioseo_og_image` |
| `FallbackAdapter` | always active (last resort) | its own `_flytedesk_seo_*` meta keys, plus direct `wp_head()` output of `<meta name="description">` and `og:*` tags |

`Seo\Resolver` checks adapters in that order and uses the **first** one whose `is_active()` returns true. Running Yoast and Rank Math simultaneously is itself a known-broken WordPress configuration, so the plugin never writes to more than one adapter - it always picks exactly one.

The post's `post_excerpt` field is set directly by `Rest\Controller` from the request's top-level `description` (falling back to `seo.meta_description` if that's blank) - this happens once, for every adapter, rather than being duplicated per adapter.

## REST API reference

Base URL: `https://<site>/wp-json/flytedesk/v1`

All endpoints require Application Passwords authentication (`Authorization: Basic ...`) and a user with the `edit_posts` capability.

### Request body shape (POST / PUT)

```json
{
  "title": "string, required",
  "description": "string, optional - used as the post excerpt",
  "content": "string, required - markdown",
  "seo": {
    "meta_title": "string, optional",
    "meta_description": "string, optional",
    "slug": "string, optional - used verbatim if unique, otherwise disambiguated",
    "meta_keywords": "string, optional - comma-separated",
    "og": {
      "title": "string, optional",
      "description": "string, optional",
      "image": "string, optional - absolute URL",
      "type": "string, optional - e.g. \"article\""
    }
  }
}
```

### Error shape

Every failure response has this shape:

```json
{ "error": { "code": "validation_failed", "message": "title is required." } }
```

| Status | Meaning |
|---|---|
| 400 | Validation failure (missing/invalid fields, malformed JSON body) |
| 401 | Missing/invalid authentication, or the authenticated user lacks `edit_posts` |
| 404 | No `fdhc_hosted_post` post exists with the given ID |
| 500 | Unexpected server-side failure (e.g. `wp_insert_post()` failed) |

### `POST /posts` - create and publish

Creates a new `fdhc_hosted_post` post, published immediately.

```bash
curl -X POST 'https://example.com/wp-json/flytedesk/v1/posts' \
  -u 'flytedesk:xxxx xxxx xxxx xxxx xxxx xxxx' \
  -H 'Content-Type: application/json' \
  -d '{
        "title": "5 Tips for Back-to-School Advertising",
        "description": "A quick primer on seasonal ad placements.",
        "content": "## Why timing matters\n\nBack-to-school season is one of the **highest-traffic** windows of the year...",
        "seo": {
          "meta_title": "5 Back-to-School Advertising Tips | flytedesk",
          "meta_description": "Seasonal ad placement strategies that convert during back-to-school shopping.",
          "slug": "back-to-school-advertising-tips",
          "meta_keywords": "back to school, advertising, seasonal ads",
          "og": {
            "title": "5 Tips for Back-to-School Advertising",
            "description": "Seasonal ad placement strategies that convert.",
            "image": "https://cdn.flytedesk.com/images/back-to-school.jpg",
            "type": "article"
          }
        }
      }'
```

Response `201 Created`:

```json
{
  "id": 42,
  "title": "5 Tips for Back-to-School Advertising",
  "description": "A quick primer on seasonal ad placements.",
  "permalink": "https://example.com/hosted-content/back-to-school-advertising-tips/",
  "status": "publish",
  "seo": {
    "meta_title": "5 Back-to-School Advertising Tips | flytedesk",
    "meta_description": "Seasonal ad placement strategies that convert during back-to-school shopping.",
    "meta_keywords": "back to school, advertising, seasonal ads",
    "slug": "back-to-school-advertising-tips",
    "og": {
      "title": "5 Tips for Back-to-School Advertising",
      "description": "Seasonal ad placement strategies that convert.",
      "image": "https://cdn.flytedesk.com/images/back-to-school.jpg",
      "type": "article"
    }
  }
}
```

Validation failure, `400 Bad Request`:

```json
{ "error": { "code": "validation_failed", "message": "title is required. content is required." } }
```

### `GET /posts/{id}` - fetch current stored state

```bash
curl -X GET 'https://example.com/wp-json/flytedesk/v1/posts/42' \
  -u 'flytedesk:xxxx xxxx xxxx xxxx xxxx xxxx'
```

Response `200 OK`: same shape as the `POST` response above, reflecting whatever is currently stored (including any edits made directly in wp-admin).

Not found, `404 Not Found`:

```json
{ "error": { "code": "not_found", "message": "No hosted-content post exists with that ID." } }
```

### `PUT /posts/{id}` - full replacement update

Same body shape as `POST /posts`. This is a full replacement, not a partial patch - fields omitted from the body are cleared/reset, not left untouched.

```bash
curl -X PUT 'https://example.com/wp-json/flytedesk/v1/posts/42' \
  -u 'flytedesk:xxxx xxxx xxxx xxxx xxxx xxxx' \
  -H 'Content-Type: application/json' \
  -d '{
        "title": "5 Tips for Back-to-School Advertising (Updated)",
        "description": "Refreshed for the 2026 season.",
        "content": "## Why timing matters\n\nUpdated guidance for this year...",
        "seo": {
          "meta_title": "5 Back-to-School Advertising Tips (2026) | flytedesk",
          "meta_description": "Updated seasonal ad placement strategies for 2026.",
          "meta_keywords": "back to school, advertising, 2026",
          "og": {
            "title": "5 Tips for Back-to-School Advertising (2026)",
            "description": "Updated strategies for 2026.",
            "image": "https://cdn.flytedesk.com/images/back-to-school-2026.jpg",
            "type": "article"
          }
        }
      }'
```

Response `200 OK`: same shape as `GET /posts/{id}`.

### `DELETE /posts/{id}` - trash

Moves the post to the trash (does not permanently delete it).

```bash
curl -X DELETE 'https://example.com/wp-json/flytedesk/v1/posts/42' \
  -u 'flytedesk:xxxx xxxx xxxx xxxx xxxx xxxx'
```

Response: `204 No Content`.

Unauthenticated request, `401 Unauthorized`:

```json
{ "error": { "code": "unauthorized", "message": "Authentication required. Use a WordPress Application Password." } }
```

## Security notes

* Every REST input is sanitized before use: `sanitize_text_field()` (plain text fields), `esc_url_raw()` (URLs), `sanitize_title()` (the requested slug), `wp_kses_post()` (converted Markdown HTML).
* Authentication is exclusively WordPress Application Passwords; `permission_callback` checks `current_user_can( 'edit_posts' )` - there is no custom API-key scheme to misconfigure.
* No raw SQL anywhere - `WP_Query`/`get_post()`, `wp_insert_post()`/`wp_update_post()`, and `get_post_meta()`/`update_post_meta()` are the only data-access surface.
* `FallbackAdapter::output_head_meta()` runs every value through `esc_attr()`/`esc_url()` before printing it into `wp_head()`.
* No `eval()`, no `extract()`, no dynamic function calls built from request input, anywhere in the codebase.

See `SECURITY.md` for how to report a vulnerability.

## File structure

```
hosted-content-wp-plugin/
├── hosted-content-wp-plugin.php   Plugin bootstrap: headers, Composer autoload, Plugin::instance()->boot()
├── composer.json                     PSR-4 autoload + dev tooling (PHPUnit, Brain Monkey, PHPCS/WPCS)
├── src/                               See "Architecture overview" above
├── tests/
│   ├── bootstrap.php                 Dual-mode: Brain Monkey (unit) vs real WP core test suite (integration)
│   ├── Unit/                         Brain Monkey unit tests
│   └── Integration/                  WP_Test_REST_TestCase integration tests (via wp-env)
├── .wp-env.json                      wp-env config: maps this plugin into a Dockerized WP + MySQL instance
├── phpcs.xml.dist                    WordPress-Extra + PHPCompatibilityWP ruleset
├── phpunit.xml.dist                  `unit` and `integration` testsuites
├── .github/workflows/ci.yml          Lint + unit tests on every push/PR; integration tests via wp-env
├── readme.txt                        WordPress.org-format plugin readme
├── README.md                         This file
├── LICENSE                           GPL-2.0-or-later
├── CHANGELOG.md                      Keep a Changelog format
└── SECURITY.md                       Vulnerability reporting
```

## Notes on uninstall

* **Deactivating** the plugin removes the `flytebot` user and its Application Password (`Plugin::deactivate()`, via `Registration\ApiCredential::delete_user()`) - the same way any other integration's access should be pulled the moment it's turned off. Registration status, the verification token, and the rest of the timeline are left alone, so reactivating re-registers using the same token instead of starting the workflow over.
* **Deleting** the plugin (via `uninstall.php`) removes everything above plus every `flytedesk_*` option and the `flytedesk_api` role - a clean slate, as if the plugin had never been installed.
* Neither step removes any `fdhc_hosted_post` posts - content already published to the site stays in place, per standard WordPress plugin convention. Removing a connector shouldn't silently delete a publisher's live articles.
