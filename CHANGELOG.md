# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-09-18

### Changed

- Updated GitHub Actions workflow to Node 24 compatible action versions (`actions/checkout@v7`, `actions/cache@v6`, `codecov/codecov-action@v7`).
- Expanded CI matrix with explicit compatibility testing for Guzzle 7 and Guzzle 8 across PHP 8.1–8.6.
- Refined documentation and streamlined `composer.json` description.

### Added

- Added unit test covering rate limiting when `max_retries` is configured as zero.

## [1.0.0] - 2026-09-15

### Added

- Initial release of `calliostro/spotify-client`, a lightweight Spotify Web API client for PHP 8.1+.
- Generic low-level HTTP client (`get`, `post`, `put`, `delete`, `request`) covering the complete Spotify Web API.
- High-level catalog convenience methods for common endpoints (search, artists, albums, tracks, and user profiles).
- Automatic OAuth 2.0 authentication handling (Client Credentials flow and Authorization Code flow with refresh tokens).
- Resilient token lifecycle management with proactive token refresh and automatic retry on 401 Unauthorized.
- Built-in rate limit handling (HTTP 429) respecting Spotify's `Retry-After` header.
- Support for `guzzlehttp/guzzle` 7.x and 8.x.

[1.0.1]: https://github.com/calliostro/spotify-client/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/calliostro/spotify-client/releases/tag/v1.0.0

