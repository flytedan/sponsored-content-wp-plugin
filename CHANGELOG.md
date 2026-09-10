# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-10

### Added

- `fdsc_sponsored_post` custom post type, with an archive at `/sponsored-content/`.
- `flytedesk/v1` REST API: `POST /posts`, `GET /posts/{id}`, `PUT /posts/{id}`, `DELETE /posts/{id}`, authenticated via WordPress Application Passwords.
- Dependency-free Markdown-to-HTML converter for article bodies.
- SEO adapter layer with support for Yoast SEO, Rank Math, and All in One SEO, plus a built-in fallback (own meta storage + `wp_head()` output) when no supported SEO plugin is active.
- Automatic registration with sponsored.flytedesk.com on activation: provisions a dedicated low-privilege `flytebot` user (custom `flytedesk_api` role, holding only the `flytedesk_manage_sponsored_content` capability), issues it an Application Password, and sends both in the registration request so flytedesk's platform can start using this site's API immediately once a human accepts the registration. The inbound `/flytedesk-registration-confirmation` webhook is authenticated via a bearer token shared during registration.
- A redesigned **Registration** page under **Sponsored Content** in wp-admin: a live status timeline (Pending → Awaiting Response → Accepted/Rejected, each stage timestamped), a Technical Details tab showing the last registration request/response with its HTTP status, and an About Sponsored Content tab explaining the network and how the plugin works to publishers, with an FAQ. The page polls every 3 seconds and updates in place - no reload needed to see a registration get accepted or rejected - and the Register Now / Re-register button runs over AJAX with a loading state instead of a full-page form post.
- Composer PSR-4 autoloading, PHPUnit (Brain Monkey unit suite + wp-env-backed integration suite), and WordPress Coding Standards via PHPCS.
