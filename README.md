# ⚡ Spotify API Client for PHP 8.1+ – Lightweight with Maximum Developer Comfort

[![Package Version](https://img.shields.io/packagist/v/calliostro/spotify-client.svg)](https://packagist.org/packages/calliostro/spotify-client)
[![Total Downloads](https://img.shields.io/packagist/dt/calliostro/spotify-client.svg)](https://packagist.org/packages/calliostro/spotify-client)
[![License](https://poser.pugx.org/calliostro/spotify-client/license)](https://packagist.org/packages/calliostro/spotify-client)
[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue.svg)](https://php.net)
[![Guzzle](https://img.shields.io/badge/guzzle-%5E7.0%20%7C%7C%20%5E8.0-orange.svg)](https://docs.guzzlephp.org/)
[![CI](https://github.com/calliostro/spotify-client/actions/workflows/ci.yml/badge.svg)](https://github.com/calliostro/spotify-client/actions/workflows/ci.yml)
[![Code Coverage](https://codecov.io/gh/calliostro/spotify-client/graph/badge.svg)](https://codecov.io/gh/calliostro/spotify-client)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](https://phpstan.org/)
[![Code Style](https://img.shields.io/badge/code%20style-PSR12-brightgreen.svg)](https://github.com/FriendsOfPHP/PHP-CS-Fixer)

> **🚀 MINIMAL YET POWERFUL!** Focused, lightweight Spotify Web API client — as compact as possible while maintaining modern PHP comfort and clean APIs.

## 📦 Installation

```bash
composer require calliostro/spotify-client
```

### 🔑 Do You Need to Register?

Yes, Spotify requires all Web API applications to be registered:

1. Create a free developer account and app in the [Spotify Developer Dashboard](https://developer.spotify.com/dashboard).
2. Obtain your **Client ID** and **Client Secret**.
3. **Configure Redirect URIs** (required for User Authentication / OAuth): In your app settings, add your callback URL (e.g., `https://myapp.example.com/callback.php`). Note: Spotify requires HTTPS unless using loopback IP addresses (`http://127.0.0.1:PORT/callback.php` or `http://[::1]:PORT/callback.php`; `http://localhost` is not permitted).

**Which flow do you need?**

- **Client Credentials Flow** (Server/CLI/Background Workers):
  - Requires: `Client ID` + `Client Secret`.
  - For: Searching the catalog, browsing public artists, albums, tracks, playlists, audio features, and categories.
  - No user login prompt required.
- **Authorization Code Flow** (User Authentication):
  - Requires: `Client ID` + `Client Secret` + user authorization code / tokens.
  - For: Private user playlists, library, player controls, currently playing track, user profile.

---

## 🚀 Quick Start

### 1. Server / CLI / Public Catalog Search (Client Credentials Flow)

```php
use Calliostro\Spotify\SpotifyClientFactory;

$spotify = SpotifyClientFactory::createWithCredentials(
    clientId: 'your-client-id',
    clientSecret: 'your-client-secret'
);

// Get artist details
$artist = $spotify->getArtist('4Z8W4fKeB5YxbusRsdQVPb'); // Radiohead
echo $artist['name']; // "Radiohead"

// Traditional positional parameters
$albums = $spotify->getArtistAlbums('4Z8W4fKeB5YxbusRsdQVPb', ['album'], 'US', 10);

// Modern PHP 8+ named parameters
$searchResults = $spotify->search(
    query: 'OK Computer',
    type: 'album',
    limit: 5,
    market: 'US'
);

$album = $spotify->getAlbum(
    albumId: '6400dqrMfE4FTGMxS5neUM',
    market: 'US'
);
```

> [!NOTE]
> Spotify apps in default *Development Mode* have full access to catalog search, artists, albums, tracks, and user authorization flows. Note that endpoints such as `/artists/{id}/top-tracks` and `/artists/{id}/related-artists` are restricted by Spotify to apps in *Extended Quota Mode*.

### 2. User Authentication (Authorization Code Flow with Refresh Token)

```php
use Calliostro\Spotify\SpotifyClientFactory;

$spotify = SpotifyClientFactory::createWithUserAuth(
    clientId: 'your-client-id',
    clientSecret: 'your-client-secret',
    accessToken: $userAccessToken,
    refreshToken: $userRefreshToken
);

// Access private user data
$me = $spotify->getCurrentUser();
echo "Hello, " . $me['display_name'];
```

### 3. Direct Access with Pre-existing Token

```php
use Calliostro\Spotify\SpotifyClientFactory;

$spotify = SpotifyClientFactory::createWithAccessToken('your-active-access-token');

$album = $spotify->getAlbum('6400dqrMfE4FTGMxS5neUM');
```

---

## 🌐 Accessing ANY Endpoint (The Generic HTTP Engine)

To provide maximum flexibility while keeping the codebase lightweight and focused, `calliostro/spotify-client` employs a **two-tier architecture**:

In addition to convenient catalog methods, the low-level generic HTTP engine gives you direct access to **100% of the Spotify Web API** on day one:

```php
// GET any endpoint (automatic Bearer auth, query string formatting, JSON deserialization)
$queue = $spotify->get('/me/player/queue');
$genres = $spotify->get('/recommendations/available-genre-seeds');

// POST requests
$playlist = $spotify->post("/users/{$userId}/playlists", [
    'name' => 'My New Playlist',
    'public' => false,
    'description' => 'Created via calliostro/spotify-client'
]);

// PUT requests
$spotify->put('/me/player/play', [
    'uris' => ['spotify:track:4cOdK2wGLETKBW3PvgPWqT']
]);

// DELETE requests
$spotify->delete('/me/player/repeat', [
    'state' => 'off'
]);

// Generic request method
$response = $spotify->request('GET', 'me/top/artists', [
    'query' => ['time_range' => 'long_term', 'limit' => 10]
]);
```

---

## 🔄 Automatic Token & Rate Limit Handling

Built specifically for high-reliability CLI commands, background workers, and long-running daemons:

### 1. Proactive Token Refresh & 401 Self-Healing
- Spotify tokens expire after **1 hour** (3,600 seconds).
- The client monitors expiration timestamps and **proactively refreshes** the token 5 minutes (300s) before it expires.
- If a token is revoked or invalidated mid-flight causing an HTTP `401 Unauthorized`, the client automatically refreshes the token and retries the request transparently once before raising an `AuthenticationException`.

### 2. Automatic HTTP 429 Rate Limit Backoff
- When hitting Spotify API rate limits, Spotify provides a `Retry-After` header indicating how many seconds to wait.
- By default (`auto_retry => true`, `max_retries => 3`), the client respects the backoff period, sleeps, and retries automatically without crashing your pipeline.
- When retries are exhausted or disabled, a `RateLimitException` is thrown, exposing `$e->getRetryAfter()`.

```php
use Calliostro\Spotify\SpotifyClientFactory;
use Calliostro\Spotify\Exception\RateLimitException;

$spotify = SpotifyClientFactory::createWithCredentials('client-id', 'client-secret', [
    'auto_retry' => true,   // Automatically wait and retry on 429 (default: true)
    'max_retries' => 5,     // Maximum number of retry attempts (default: 3)
]);

try {
    $data = $spotify->getArtist('4Z8W4fKeB5YxbusRsdQVPb');
} catch (RateLimitException $e) {
    echo "Spotify rate limit exceeded. Retry after {$e->getRetryAfter()} seconds.";
}
```

---

## ✨ Key Features

- **Two-Tier Architecture** – Generic HTTP engine (`get`, `post`, `put`, `delete`) covering 100% of the API, alongside high-level catalog convenience methods.
- **Resilient Token Lifecycle** – Proactive 5-minute pre-refresh and automatic 401 retry for both Client Credentials and Refresh Token flows.
- **Built-in Rate Limiting** – Automatic HTTP 429 retry respecting the `Retry-After` header.
- **Clean Parameter API** – Full support for PHP 8 named parameters and strict types.
- **Lightweight Focus** – Minimal footprint with only essential dependencies (Guzzle 7 or 8).
- **Type-Safe Exceptions** – Distinct exceptions: `AuthenticationException`, `RateLimitException`, `NotFoundException`, `ValidationException`, and `SpotifyException`.
- **Modern PHP Comfort** – Full IDE auto-completion, PHPStan Level 8 static analysis, and PSR-12 compliant.
- **Performance** – In-memory token caching and lazy-loaded configuration singleton.
- **Battle-Tested** – 100% unit test coverage with MockHandler.

---

## 🎵 Catalog Convenience Methods

| Method                                                            | Description                                        |
|-------------------------------------------------------------------|----------------------------------------------------|
| `search(query, type, limit, offset, market, includeExternal)`     | Search artists, albums, tracks, etc.               |
| `getArtist(artistId)`                                             | Get artist profile, genres, popularity, and images |
| `getArtistAlbums(artistId, includeGroups, market, limit, offset)` | Get artist discography and releases                |
| `getArtistTopTracks(artistId, market)`*                           | Get artist top 10 tracks by market                 |
| `getArtistRelatedArtists(artistId)`*                              | Get artists similar to a given artist              |
| `getAlbum(albumId, market)`                                       | Get album details, label, and release date         |
| `getAlbumTracks(albumId, market, limit, offset)`                  | Get tracks for a specific album                    |
| `getTrack(trackId, market)`                                       | Get track details, duration, and popularity        |
| `getTracks(trackIds, market)`                                     | Get multiple tracks in a single request (up to 50) |
| `getCurrentUser()`                                                | Get current authorized user's profile              |
| `getUserProfile(userId)`                                          | Get public profile for any user                    |

*\*Note: Endpoints marked with `*` require Spotify Extended Quota Mode for newly created apps.*  
*Remember: Any other Spotify endpoint can be called instantly using `$spotify->get()`, `$spotify->post()`, `$spotify->put()`, or `$spotify->delete()`!*

---

## 📋 Requirements

- **PHP** `^8.1`
- **guzzlehttp/guzzle** `^7.0 || ^8.0`

---

## ⚙️ Configuration

### Simple (Works out of the box)

```php
use Calliostro\Spotify\SpotifyClientFactory;

$spotify = SpotifyClientFactory::createWithCredentials('client-id', 'client-secret');
```

### Advanced (Custom Guzzle handler, timeouts, headers)

```php
use Calliostro\Spotify\SpotifyClientFactory;

$spotify = SpotifyClientFactory::createWithCredentials('client-id', 'client-secret', [
    'timeout' => 15,
    'headers' => [
        'User-Agent' => 'MyMusicApp/1.0 (+https://myapp.example.com)',
    ],
    'auto_retry' => true,
    'max_retries' => 3,
]);
```

---

## 🔐 Authentication & Complete OAuth Flow Example

### Quick Reference

| What you want to do                          | Method                    | What you need                                |
|----------------------------------------------|---------------------------|----------------------------------------------|
| Public music search, artists, albums, tracks | `createWithCredentials()` | Client ID + Client Secret                    |
| Background worker / CLI tool                 | `createWithCredentials()` | Client ID + Client Secret                    |
| Direct calls with existing token             | `createWithAccessToken()` | Access token                                 |
| User library, playlists, playback            | `createWithUserAuth()`    | Client ID + Secret + Access & Refresh Tokens |

### Complete Authorization Code Flow

> [!IMPORTANT]
> **Registering your Redirect URI in Spotify Dashboard:**
> You **must register the exact Redirect URI** in your app settings in the [Spotify Developer Dashboard](https://developer.spotify.com/dashboard) under *App Settings* → *Redirect URIs*.
> - **Production:** Must use `HTTPS` (e.g., `https://myapp.example.com/callback.php`).
> - **Local Development:** Spotify permits `HTTP` only for explicit loopback IP addresses (e.g., `http://127.0.0.1:8080/callback.php` or `http://[::1]:8080/callback.php`). `http://localhost` is strictly rejected by Spotify.

#### Step 1: `authorize.php` – Redirect user to Spotify

```php
<?php

use Calliostro\Spotify\AuthHelper;

$auth = new AuthHelper('your-client-id', 'your-client-secret');

$authorizeUrl = $auth->getAuthorizationUrl(
    redirectUri: 'https://myapp.example.com/callback.php',
    scopes: ['user-read-private', 'user-read-email', 'playlist-read-private'],
    state: 'secure-random-state',
    showDialog: false
);

header('Location: ' . $authorizeUrl);
exit;
```

#### Step 2: `callback.php` – Exchange code for tokens

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Calliostro\Spotify\AuthHelper;
use Calliostro\Spotify\SpotifyClientFactory;

$code = $_GET['code'] ?? null;
if (!$code) {
    exit('Authorization failed');
}

$auth = new AuthHelper('your-client-id', 'your-client-secret');
$tokens = $auth->requestAccessToken($code, 'https://myapp.example.com/callback.php');

$accessToken = $tokens['access_token'];
$refreshToken = $tokens['refresh_token'];
$expiresIn = $tokens['expires_in'];

// Save $refreshToken in your database for future sessions...

// Create client with user auth
$spotify = SpotifyClientFactory::createWithUserAuth(
    clientId: 'your-client-id',
    clientSecret: 'your-client-secret',
    accessToken: $accessToken,
    refreshToken: $refreshToken
);

$user = $spotify->getCurrentUser();
echo "Welcome, " . htmlspecialchars($user['display_name']);
```

---

## 🧪 Development & Testing Guide

See [DEVELOPMENT.md](DEVELOPMENT.md) for detailed setup instructions, test suite commands, static analysis, and contribution guidelines.

---

## 🤝 Contributing

Contributions are welcome! Please ensure all tests pass and coding standards are maintained:

```bash
composer cs-fix
composer analyse
composer test
```

---

## 📄 License

MIT License – see the [LICENSE](LICENSE) file for details.

---

## ⚖️ Disclaimer

Spotify is a registered trademark of Spotify AB. This project is an independent, unofficial open-source library and is not affiliated with, endorsed by, or sponsored by Spotify AB.

---

## 🙏 Acknowledgments

- [Spotify](https://developer.spotify.com/documentation/web-api) for the comprehensive Web API.
- [Guzzle](https://docs.guzzlephp.org/) for the rock-solid HTTP transport.
- Sister projects: [`calliostro/php-discogs-api`](https://github.com/calliostro/php-discogs-api), [`calliostro/lastfm-client`](https://github.com/calliostro/lastfm-client), and [`calliostro/musicbrainz-client`](https://github.com/calliostro/musicbrainz-client).

---

> ⭐ **Star this repo if you find it useful!**
