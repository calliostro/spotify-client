<?php

declare(strict_types=1);

namespace Calliostro\Spotify;

use Calliostro\Spotify\Exception\AuthenticationException;
use Calliostro\Spotify\Exception\NotFoundException;
use Calliostro\Spotify\Exception\RateLimitException;
use Calliostro\Spotify\Exception\SpotifyException;
use Calliostro\Spotify\Exception\ValidationException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * Ultra-lightweight Spotify Web API client for PHP 8.1+
 * Provides generic HTTP access to all endpoints plus high-level catalog convenience methods
 */
final class SpotifyClient
{
    private ClientInterface $client;
    private ?AuthHelper $authHelper = null;

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param ClientInterface|array<string, mixed> $optionsOrClient Guzzle client or options array
     * @param AuthHelper|null $authHelper Optional authentication helper
     * @param array<string, mixed> $options Additional client configuration options
     */
    public function __construct(
        ClientInterface|array $optionsOrClient = [],
        ?AuthHelper $authHelper = null,
        array $options = []
    ) {
        $this->authHelper = $authHelper;

        $defaultOptions = [
            'auto_retry' => true,
            'max_retries' => 3,
            'proactive_refresh' => true,
            'sleep_callback' => null, // callable(int $seconds): void for rate limiting (tests can override)
        ];

        if ($optionsOrClient instanceof ClientInterface) {
            $this->client = $optionsOrClient;
            $this->options = array_merge($defaultOptions, $options);
        } else {
            $config = ConfigCache::get();
            $clientOptions = array_merge([
                'base_uri' => $config['baseUrl'],
                'timeout' => $config['client']['options']['timeout'] ?? 30,
                'headers' => [
                    'User-Agent' => $config['client']['options']['headers']['User-Agent'] ?? 'SpotifyClient/1.0.0',
                    'Accept' => 'application/json',
                ],
            ], $optionsOrClient);

            $this->client = new GuzzleClient($clientOptions);
            $this->options = array_merge($defaultOptions, $options);
        }
    }

    // =========================================================================
    // GENERIC HTTP ENGINE
    // =========================================================================

    /**
     * Send a GET request to any Spotify endpoint
     *
     * @param string $endpoint The endpoint path (e.g. '/me/player' or 'artists/123')
     * @param array<string, mixed> $query Optional query parameters
     * @return array<string, mixed> Decoded JSON response
     */
    public function get(string $endpoint, array $query = []): array
    {
        $options = [];
        if (!empty($query)) {
            $options['query'] = $query;
        }

        return $this->request('GET', $endpoint, $options);
    }

    /**
     * Send a POST request to any Spotify endpoint
     *
     * @param string $endpoint The endpoint path
     * @param array<string, mixed> $body Optional JSON body payload
     * @param array<string, mixed> $query Optional query parameters
     * @return array<string, mixed> Decoded JSON response
     */
    public function post(string $endpoint, array $body = [], array $query = []): array
    {
        $options = [];
        if (!empty($body)) {
            $options['json'] = $body;
        }
        if (!empty($query)) {
            $options['query'] = $query;
        }

        return $this->request('POST', $endpoint, $options);
    }

    /**
     * Send a PUT request to any Spotify endpoint
     *
     * @param string $endpoint The endpoint path
     * @param array<string, mixed> $body Optional JSON body payload
     * @param array<string, mixed> $query Optional query parameters
     * @return array<string, mixed> Decoded JSON response
     */
    public function put(string $endpoint, array $body = [], array $query = []): array
    {
        $options = [];
        if (!empty($body)) {
            $options['json'] = $body;
        }
        if (!empty($query)) {
            $options['query'] = $query;
        }

        return $this->request('PUT', $endpoint, $options);
    }

    /**
     * Send a DELETE request to any Spotify endpoint
     *
     * @param string $endpoint The endpoint path
     * @param array<string, mixed> $query Optional query parameters
     * @return array<string, mixed> Decoded JSON response
     */
    public function delete(string $endpoint, array $query = []): array
    {
        $options = [];
        if (!empty($query)) {
            $options['query'] = $query;
        }

        return $this->request('DELETE', $endpoint, $options);
    }

    /**
     * Send an arbitrary HTTP request with automatic token lifecycle and rate-limit resilience
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $endpoint API endpoint path
     * @param array<string, mixed> $options Guzzle request options
     * @return array<string, mixed> Decoded JSON response
     * @throws AuthenticationException On authentication failure
     * @throws RateLimitException On rate limiting (429) when retries exhausted or disabled
     * @throws NotFoundException When resource not found (404)
     * @throws ValidationException On bad request (400) or validation error
     * @throws SpotifyException On general API or network error
     */
    public function request(string $method, string $endpoint, array $options = []): array
    {
        $normalizedEndpoint = $this->normalizeEndpoint($endpoint);
        $retryCount = 0;
        $authRetried = false;

        while (true) {
            // Proactive token refresh
            $token = $this->resolveValidToken();
            if ($token !== null && $token !== '') {
                $options['headers']['Authorization'] = 'Bearer ' . $token;
            }

            try {
                $response = $this->client->request($method, $normalizedEndpoint, $options);

                return $this->parseResponse($response);
            } catch (BadResponseException $e) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();

                // 401 Unauthorized: Refresh token and retry request once
                if ($statusCode === 401 && !$authRetried && $this->authHelper !== null) {
                    $authRetried = true;
                    try {
                        $this->authHelper->forceRefresh();
                        continue;
                    } catch (AuthenticationException) {
                        $this->handleErrorResponse($response, $e);
                    }
                }

                // 429 Too Many Requests: Rate limit backoff
                if ($statusCode === 429) {
                    $retryAfter = $this->extractRetryAfter($response);
                    $autoRetry = (bool) ($this->options['auto_retry'] ?? true);
                    $maxRetries = (int) ($this->options['max_retries'] ?? 3);

                    if ($autoRetry && $retryCount < $maxRetries) {
                        $retryCount++;
                        $this->sleep($retryAfter);
                        continue;
                    }

                    $message = $this->extractErrorMessage($response) ?? 'Rate limit exceeded';
                    throw new RateLimitException($message, $retryAfter, 429, $e);
                }

                $this->handleErrorResponse($response, $e);
            } catch (GuzzleException $e) {
                throw new SpotifyException('HTTP request failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
            }
        }
    }

    // =========================================================================
    // HIGH-LEVEL CATALOG CONVENIENCE METHODS (THE 80/20 ESSENTIALS)
    // =========================================================================

    /**
     * Search for artists, albums, tracks, playlists, shows, episodes, or audiobooks
     *
     * @param string $query Search query keywords and optional field filters
     * @param string|list<string> $type Item type(s) to search for (album, artist, playlist, track, show, episode, audiobook)
     * @param int|null $limit Maximum number of results to return (1-50, default 20)
     * @param int|null $offset Index of the first result to return (default 0)
     * @param string|null $market An ISO 3166-1 alpha-2 country code
     * @param string|null $includeExternal If "audio", include any externally hosted audio
     * @return array<string, mixed>
     */
    public function search(
        string $query,
        string|array $type,
        ?int $limit = 20,
        ?int $offset = 0,
        ?string $market = null,
        ?string $includeExternal = null
    ): array {
        $params = [
            'q' => $query,
            'type' => is_array($type) ? implode(',', $type) : $type,
        ];

        if ($limit !== null) {
            $params['limit'] = $limit;
        }
        if ($offset !== null) {
            $params['offset'] = $offset;
        }
        if ($market !== null && $market !== '') {
            $params['market'] = $market;
        }
        if ($includeExternal !== null && $includeExternal !== '') {
            $params['include_external'] = $includeExternal;
        }

        return $this->get('search', $params);
    }

    /**
     * Get Spotify catalog information for a single artist
     *
     * @param string $artistId The Spotify ID for the artist
     * @return array<string, mixed>
     */
    public function getArtist(string $artistId): array
    {
        return $this->get("artists/{$artistId}");
    }

    /**
     * Get Spotify catalog information about an artist's albums
     *
     * @param string $artistId The Spotify ID for the artist
     * @param list<string> $includeGroups Filter by a comma-separated list of keywords: album, single, appears_on, compilation
     * @param string|null $market An ISO 3166-1 alpha-2 country code
     * @param int|null $limit The maximum number of items to return (1-50, default 20)
     * @param int|null $offset The index of the first item to return (default 0)
     * @return array<string, mixed>
     */
    public function getArtistAlbums(
        string $artistId,
        array $includeGroups = [],
        ?string $market = null,
        ?int $limit = 20,
        ?int $offset = 0
    ): array {
        $params = [];

        if (!empty($includeGroups)) {
            $params['include_groups'] = implode(',', $includeGroups);
        }
        if ($market !== null && $market !== '') {
            $params['market'] = $market;
        }
        if ($limit !== null) {
            $params['limit'] = $limit;
        }
        if ($offset !== null) {
            $params['offset'] = $offset;
        }

        return $this->get("artists/{$artistId}/albums", $params);
    }

    /**
     * Get Spotify catalog information about an artist's top tracks by country
     *
     * @param string $artistId The Spotify ID for the artist
     * @param string $market An ISO 3166-1 alpha-2 country code (default 'US')
     * @return array<string, mixed>
     */
    public function getArtistTopTracks(string $artistId, string $market = 'US'): array
    {
        return $this->get("artists/{$artistId}/top-tracks", ['market' => $market]);
    }

    /**
     * Get Spotify catalog information about artists similar to a given artist
     *
     * @param string $artistId The Spotify ID for the artist
     * @return array<string, mixed>
     */
    public function getArtistRelatedArtists(string $artistId): array
    {
        return $this->get("artists/{$artistId}/related-artists");
    }

    /**
     * Get Spotify catalog information for a single album
     *
     * @param string $albumId The Spotify ID for the album
     * @param string|null $market An ISO 3166-1 alpha-2 country code
     * @return array<string, mixed>
     */
    public function getAlbum(string $albumId, ?string $market = null): array
    {
        $params = [];
        if ($market !== null && $market !== '') {
            $params['market'] = $market;
        }

        return $this->get("albums/{$albumId}", $params);
    }

    /**
     * Get Spotify catalog information about an album's tracks
     *
     * @param string $albumId The Spotify ID for the album
     * @param string|null $market An ISO 3166-1 alpha-2 country code
     * @param int|null $limit The maximum number of items to return (1-50, default 20)
     * @param int|null $offset The index of the first item to return (default 0)
     * @return array<string, mixed>
     */
    public function getAlbumTracks(
        string $albumId,
        ?string $market = null,
        ?int $limit = 20,
        ?int $offset = 0
    ): array {
        $params = [];

        if ($market !== null && $market !== '') {
            $params['market'] = $market;
        }
        if ($limit !== null) {
            $params['limit'] = $limit;
        }
        if ($offset !== null) {
            $params['offset'] = $offset;
        }

        return $this->get("albums/{$albumId}/tracks", $params);
    }

    /**
     * Get Spotify catalog information for a single track
     *
     * @param string $trackId The Spotify ID for the track
     * @param string|null $market An ISO 3166-1 alpha-2 country code
     * @return array<string, mixed>
     */
    public function getTrack(string $trackId, ?string $market = null): array
    {
        $params = [];
        if ($market !== null && $market !== '') {
            $params['market'] = $market;
        }

        return $this->get("tracks/{$trackId}", $params);
    }

    /**
     * Get Spotify catalog information for multiple tracks based on their Spotify IDs
     *
     * @param list<string> $trackIds Array of the Spotify IDs for the tracks (maximum: 50)
     * @param string|null $market An ISO 3166-1 alpha-2 country code
     * @return array<string, mixed>
     */
    public function getTracks(array $trackIds, ?string $market = null): array
    {
        $params = [
            'ids' => implode(',', $trackIds),
        ];

        if ($market !== null && $market !== '') {
            $params['market'] = $market;
        }

        return $this->get('tracks', $params);
    }

    /**
     * Get detailed profile information about the current user (Requires user authorization)
     *
     * @return array<string, mixed>
     */
    public function getCurrentUser(): array
    {
        return $this->get('me');
    }

    /**
     * Get public profile information about a Spotify user
     *
     * @param string $userId The user's Spotify user ID
     * @return array<string, mixed>
     */
    public function getUserProfile(string $userId): array
    {
        return $this->get("users/{$userId}");
    }

    // =========================================================================
    // AUTHENTICATION & HELPER ACCESSORS
    // =========================================================================

    public function getAuthHelper(): ?AuthHelper
    {
        return $this->authHelper;
    }

    public function setAuthHelper(?AuthHelper $authHelper): self
    {
        $this->authHelper = $authHelper;

        return $this;
    }

    public function setAccessToken(string $accessToken): self
    {
        if ($this->authHelper === null) {
            $this->authHelper = new AuthHelper();
        }

        $this->authHelper->setAccessToken($accessToken);

        return $this;
    }

    // =========================================================================
    // INTERNAL UTILITIES
    // =========================================================================

    /**
     * Resolve currently valid token proactively
     */
    private function resolveValidToken(): ?string
    {
        if ($this->authHelper === null) {
            return null;
        }

        $proactive = (bool) ($this->options['proactive_refresh'] ?? true);

        if ($proactive) {
            return $this->authHelper->getValidToken();
        }

        return $this->authHelper->getAccessToken();
    }

    /**
     * Normalize endpoint string (strips full URLs or redundant v1 prefix)
     */
    private function normalizeEndpoint(string $endpoint): string
    {
        // Strip full base URL if passed
        $endpoint = preg_replace('#^https?://api\.spotify\.com/v1/?#i', '', $endpoint) ?? $endpoint;

        // Strip leading /v1/ or v1/
        $endpoint = preg_replace('#^/?v1/#i', '', $endpoint) ?? $endpoint;

        // Trim leading and trailing slashes
        return trim($endpoint, '/');
    }

    /**
     * Parse and deserialize JSON response
     *
     * @return array<string, mixed>
     */
    private function parseResponse(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            return $data;
        } catch (JsonException $e) {
            throw new SpotifyException('Failed to parse response JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Map HTTP error response to specific SpotifyException subclass
     *
     * @never-returns
     */
    private function handleErrorResponse(ResponseInterface $response, BadResponseException $e): never
    {
        $statusCode = $response->getStatusCode();
        $message = $this->extractErrorMessage($response) ?? $e->getMessage();

        match ($statusCode) {
            400, 422 => throw new ValidationException($message, $statusCode, $e),
            401 => throw new AuthenticationException($message, $statusCode, $e),
            404 => throw new NotFoundException($message, $statusCode, $e),
            default => throw new SpotifyException($message, $statusCode, $e),
        };
    }

    /**
     * Extract human-readable error message from Spotify's error response structure
     */
    private function extractErrorMessage(ResponseInterface $response): ?string
    {
        $body = (string) $response->getBody();
        if ($body === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (isset($data['error']['message']) && is_string($data['error']['message'])) {
                return $data['error']['message'];
            }

            if (isset($data['error_description']) && is_string($data['error_description'])) {
                return $data['error_description'];
            }

            if (isset($data['error']) && is_string($data['error'])) {
                return $data['error'];
            }
        } catch (JsonException) {
            // Not valid JSON, return null to use exception message
        }

        return null;
    }

    /**
     * Extract Retry-After header value in seconds
     */
    private function extractRetryAfter(ResponseInterface $response): int
    {
        $retryAfter = $response->getHeaderLine('Retry-After');

        if ($retryAfter !== '' && is_numeric($retryAfter)) {
            return max(1, (int) $retryAfter);
        }

        return 1;
    }

    /**
     * Sleep for specified duration (supports custom sleep callback for tests)
     */
    private function sleep(int $seconds): void
    {
        $callback = $this->options['sleep_callback'] ?? null;

        if (is_callable($callback)) {
            $callback($seconds);
        } else {
            sleep($seconds);
        }
    }
}
