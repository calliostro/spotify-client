<?php

declare(strict_types=1);

namespace Calliostro\Spotify;

use GuzzleHttp\Client as GuzzleClient;

/**
 * Factory for creating SpotifyClient instances with appropriate authentication strategies
 */
final class SpotifyClientFactory
{
    /**
     * Create a client using Spotify Client Credentials flow (server-to-server / CLI / metadata)
     * Automatically requests and renews bearer tokens on expiration
     *
     * @param string $clientId Spotify application Client ID
     * @param string $clientSecret Spotify application Client Secret
     * @param array<string, mixed> $options Client and Guzzle options
     */
    public static function createWithCredentials(
        string $clientId,
        string $clientSecret,
        array $options = []
    ): SpotifyClient {
        $config = ConfigCache::get();
        $authHelper = new AuthHelper($clientId, $clientSecret);

        $clientOptions = array_merge([
            'base_uri' => $config['baseUrl'],
            'timeout' => $config['client']['options']['timeout'] ?? 30,
            'headers' => [
                'User-Agent' => $config['client']['options']['headers']['User-Agent'] ?? 'SpotifyClient/1.0.0',
                'Accept' => 'application/json',
            ],
        ], $options);

        return new SpotifyClient(new GuzzleClient($clientOptions), $authHelper, $options);
    }

    /**
     * Create a client using a pre-existing Access Token
     *
     * @param string $accessToken Active Spotify Access Token
     * @param array<string, mixed> $options Client and Guzzle options
     */
    public static function createWithAccessToken(
        string $accessToken,
        array $options = []
    ): SpotifyClient {
        $config = ConfigCache::get();
        $authHelper = new AuthHelper();
        $authHelper->setAccessToken($accessToken);

        $clientOptions = array_merge([
            'base_uri' => $config['baseUrl'],
            'timeout' => $config['client']['options']['timeout'] ?? 30,
            'headers' => [
                'User-Agent' => $config['client']['options']['headers']['User-Agent'] ?? 'SpotifyClient/1.0.0',
                'Accept' => 'application/json',
            ],
        ], $options);

        return new SpotifyClient(new GuzzleClient($clientOptions), $authHelper, $options);
    }

    /**
     * Create a client for an authenticated user with automatic token refresh via Refresh Token
     *
     * @param string $clientId Spotify application Client ID
     * @param string $clientSecret Spotify application Client Secret
     * @param string $accessToken Active Spotify user Access Token
     * @param string $refreshToken Spotify user Refresh Token
     * @param int|null $expiresAt UNIX timestamp when the token expires
     * @param array<string, mixed> $options Client and Guzzle options
     */
    public static function createWithUserAuth(
        string $clientId,
        string $clientSecret,
        string $accessToken,
        string $refreshToken,
        ?int $expiresAt = null,
        array $options = []
    ): SpotifyClient {
        $config = ConfigCache::get();
        $authHelper = new AuthHelper($clientId, $clientSecret);
        $authHelper->setAccessToken($accessToken, $expiresAt);
        $authHelper->setRefreshToken($refreshToken);

        $clientOptions = array_merge([
            'base_uri' => $config['baseUrl'],
            'timeout' => $config['client']['options']['timeout'] ?? 30,
            'headers' => [
                'User-Agent' => $config['client']['options']['headers']['User-Agent'] ?? 'SpotifyClient/1.0.0',
                'Accept' => 'application/json',
            ],
        ], $options);

        return new SpotifyClient(new GuzzleClient($clientOptions), $authHelper, $options);
    }

    /**
     * Create a default unauthenticated client (useful when setting token or AuthHelper later)
     *
     * @param array<string, mixed> $options Client and Guzzle options
     */
    public static function create(array $options = []): SpotifyClient
    {
        $config = ConfigCache::get();

        $clientOptions = array_merge([
            'base_uri' => $config['baseUrl'],
            'timeout' => $config['client']['options']['timeout'] ?? 30,
            'headers' => [
                'User-Agent' => $config['client']['options']['headers']['User-Agent'] ?? 'SpotifyClient/1.0.0',
                'Accept' => 'application/json',
            ],
        ], $options);

        return new SpotifyClient(new GuzzleClient($clientOptions), null, $options);
    }
}
