# Spotify API Client – Development Guide

This guide is for contributors and developers working on the `calliostro/spotify-client` library.

---

## 🚀 Getting Started

### Prerequisites

- PHP 8.1 or higher
- Composer 2.x

### Installation

```bash
composer install
```

---

## 🧪 Testing

### Running Tests

```bash
# Unit tests (fast, mocked via Guzzle MockHandler, no API credentials required)
composer test

# Integration tests (requires Spotify API credentials)
composer test-integration

# All tests together
composer test-all

# Unit test code coverage report
composer test-coverage

# All tests code coverage report
composer test-coverage-all
```

### Static Analysis & Code Quality

```bash
# Run PHPStan static analysis (Level 8)
composer analyse

# Check code style (PSR-12)
composer cs

# Automatically fix code style
composer cs-fix
```

---

## 🔗 Integration Tests

Integration tests execute real HTTP calls against the live Spotify Web API:

### Environment Variables

Set the following environment variables before running integration tests:

```bash
export SPOTIFY_CLIENT_ID="your-spotify-client-id"
export SPOTIFY_CLIENT_SECRET="your-spotify-client-secret"
```

If these environment variables are not present, integration tests will automatically skip safely without failing the build.

### GitHub Actions Secrets

To enable integration tests in CI when manually triggered via `workflow_dispatch`, configure these repository secrets:

- `SPOTIFY_CLIENT_ID`
- `SPOTIFY_CLIENT_SECRET`

---

## 🏛️ Architecture & Design Principles

Following the sister libraries in `calliostro/`:
- `calliostro/php-discogs-api`
- `calliostro/lastfm-client`
- `calliostro/musicbrainz-client`

### Core Components

1. **`SpotifyClient`** – Primary client implementing the generic HTTP engine (`get`, `post`, `put`, `delete`, `request`) and high-level catalog convenience methods (`search`, `getArtist`, `getAlbum`, `getTrack`, etc.).
2. **`SpotifyClientFactory`** – Clean factory with static creation methods for Client Credentials, pre-existing tokens, and full User Auth.
3. **`AuthHelper`** – Manages OAuth 2.0 handshake, token refresh flows, in-memory caching, and proactive expiration checks.
4. **`ConfigCache`** – Singleton holding cached configuration with lazy loading from `resources/service.php`.
5. **`Exception Hierarchy`** – Specific domain exceptions: `AuthenticationException`, `RateLimitException`, `NotFoundException`, `ValidationException`, and `SpotifyException`.

### Key Resilience Features

- **Proactive Token Refresh**: Automatically refreshes 5 minutes before expiration to avoid mid-operation token expiration in CLI/worker environments.
- **Self-Healing 401 Retry**: Catches unexpected 401 responses, refreshes the token, and retries the request once before failing.
- **HTTP 429 Rate Limit Handling**: Reads `Retry-After` response headers, sleeps, and retries automatically up to `max_retries` (default: 3).

---

## 🤝 Contributing Workflow

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Write clean, strictly-typed PHP 8.1+ code with `declare(strict_types=1);`
4. Add comprehensive unit tests in `tests/Unit/`
5. Ensure all quality gates pass:
   ```bash
   composer cs-fix
   composer analyse
   composer test
   ```
6. Commit your changes and open a pull request.
