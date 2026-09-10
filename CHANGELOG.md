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
- Composer PSR-4 autoloading, PHPUnit (Brain Monkey unit suite + wp-env-backed integration suite), and WordPress Coding Standards via PHPCS.
