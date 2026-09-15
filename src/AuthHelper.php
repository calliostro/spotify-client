<?php

declare(strict_types=1);

namespace Calliostro\Spotify;

use Calliostro\Spotify\Exception\AuthenticationException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

/**
 * Authentication helper for Spotify Web API
 * Handles OAuth 2.0 flows, token lifecycle, and proactive refresh
 */
final class AuthHelper
{
    private ?string $clientId;
    private ?string $clientSecret;
    private ClientInterface $httpClient;
    private ?string $accessToken = null;
    private ?string $refreshToken = null;
    private ?int $expiresAt = null;

    public function __construct(
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?ClientInterface $httpClient = null
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;

        if ($httpClient === null) {
            $config = ConfigCache::get();
            $this->httpClient = new GuzzleClient([
                'base_uri' => $config['accountUrl'],
                'timeout' => $config['client']['options']['timeout'] ?? 30,
                'headers' => [
                    'User-Agent' => $config['client']['options']['headers']['User-Agent'] ?? 'SpotifyClient/1.0.0',
                    'Accept' => 'application/json',
                ],
            ]);
        } else {
            $this->httpClient = $httpClient;
        }
    }

    /**
     * Get the authorization URL to redirect users for Authorization Code Flow
     *
     * @param string $redirectUri The redirect URI registered in Spotify Dashboard
     * @param list<string> $scopes Array of Spotify permission scopes
     * @param string|null $state Optional state parameter for CSRF protection
     * @param bool $showDialog Whether or not to force the user to approve the app again
     */
    public function getAuthorizationUrl(
        string $redirectUri,
        array $scopes = [],
        ?string $state = null,
        bool $showDialog = false
    ): string {
        if ($this->clientId === null || $this->clientId === '') {
            throw new AuthenticationException('Client ID is required to generate an authorization URL.');
        }

        $params = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
        ];

        if (!empty($scopes)) {
            $params['scope'] = implode(' ', $scopes);
        }

        if ($state !== null && $state !== '') {
            $params['state'] = $state;
        }

        if ($showDialog) {
            $params['show_dialog'] = 'true';
        }

        $config = ConfigCache::get();
        $accountUrl = rtrim((string) $config['accountUrl'], '/');

        return $accountUrl . '/authorize?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for an access token (and refresh token)
     *
     * @param string $code The authorization code received from Spotify callback
     * @param string $redirectUri The same redirect URI used when requesting the code
     * @return array<string, mixed> The token response from Spotify
     * @throws AuthenticationException If token exchange fails
     */
    public function requestAccessToken(string $code, string $redirectUri): array
    {
        return $this->sendTokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * Request an access token using Client Credentials Flow (server-to-server)
     *
     * @return array<string, mixed> The token response from Spotify
     * @throws AuthenticationException If request fails
     */
    public function requestCredentialsToken(): array
    {
        return $this->sendTokenRequest([
            'grant_type' => 'client_credentials',
        ]);
    }

    /**
     * Refresh an expired access token using a refresh token
     *
     * @param string $refreshToken The refresh token
     * @return array<string, mixed> The token response from Spotify
     * @throws AuthenticationException If refresh fails
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $response = $this->sendTokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if (!empty($response['refresh_token']) && is_string($response['refresh_token'])) {
            $this->refreshToken = $response['refresh_token'];
        }

        return $response;
    }

    /**
     * Check if the currently cached access token is expired or about to expire
     *
     * @param int $marginSeconds Safety margin in seconds (default 300 = 5 minutes)
     */
    public function isExpired(int $marginSeconds = 300): bool
    {
        if ($this->accessToken === null) {
            return true;
        }

        if ($this->expiresAt === null) {
            return false;
        }

        return time() >= ($this->expiresAt - $marginSeconds);
    }

    /**
     * Ensure a valid access token exists, proactively refreshing if expired
     *
     * @throws AuthenticationException If unable to obtain a valid token
     */
    public function getValidToken(): string
    {
        if (!$this->isExpired()) {
            return (string) $this->accessToken;
        }

        // Proactive refresh: try refresh token first, then client credentials
        if ($this->refreshToken !== null && $this->refreshToken !== '') {
            $this->refreshAccessToken($this->refreshToken);
            return (string) $this->accessToken;
        }

        if ($this->hasCredentials()) {
            $this->requestCredentialsToken();
            return (string) $this->accessToken;
        }

        if ($this->accessToken !== null && $this->accessToken !== '') {
            // Token might be set without expiration timestamp
            return $this->accessToken;
        }

        throw new AuthenticationException('No valid access token available and no credentials or refresh token to obtain one.');
    }

    /**
     * Force refresh the token (used on 401 retry)
     *
     * @throws AuthenticationException If unable to refresh
     */
    public function forceRefresh(): string
    {
        if ($this->refreshToken !== null && $this->refreshToken !== '') {
            $this->refreshAccessToken($this->refreshToken);
            return (string) $this->accessToken;
        }

        if ($this->hasCredentials()) {
            $this->requestCredentialsToken();
            return (string) $this->accessToken;
        }

        throw new AuthenticationException('Cannot refresh token: neither refresh token nor client credentials are provided.');
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setAccessToken(?string $accessToken, ?int $expiresAt = null): self
    {
        $this->accessToken = $accessToken;
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): self
    {
        $this->refreshToken = $refreshToken;

        return $this;
    }

    public function getExpiresAt(): ?int
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?int $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
    }

    public function hasCredentials(): bool
    {
        return $this->clientId !== null && $this->clientId !== '' &&
               $this->clientSecret !== null && $this->clientSecret !== '';
    }

    /**
     * Execute a token request against Spotify's /api/token endpoint
     *
     * @param array<string, string> $formParams
     * @return array<string, mixed>
     * @throws AuthenticationException
     */
    private function sendTokenRequest(array $formParams): array
    {
        if (!$this->hasCredentials()) {
            throw new AuthenticationException('Client ID and Client Secret are required for token requests.');
        }

        $basicAuth = base64_encode($this->clientId . ':' . $this->clientSecret);

        try {
            $response = $this->httpClient->request('POST', 'api/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . $basicAuth,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => $formParams,
            ]);

            $body = (string) $response->getBody();
            /** @var array<string, mixed> $data */
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            $message = 'Authentication request failed: ' . $e->getMessage();
            throw new AuthenticationException($message, (int) $e->getCode(), $e);
        } catch (JsonException $e) {
            throw new AuthenticationException('Failed to parse authentication response: ' . $e->getMessage(), 0, $e);
        }

        if (isset($data['error'])) {
            $description = $data['error_description'] ?? $data['error'];
            throw new AuthenticationException('Spotify auth error: ' . (string) $description);
        }

        if (!isset($data['access_token']) || !is_string($data['access_token'])) {
            throw new AuthenticationException('Invalid token response: access_token missing.');
        }

        $this->accessToken = $data['access_token'];

        if (isset($data['expires_in']) && is_numeric($data['expires_in'])) {
            $this->expiresAt = time() + (int) $data['expires_in'];
        }

        if (isset($data['refresh_token']) && is_string($data['refresh_token'])) {
            $this->refreshToken = $data['refresh_token'];
        }

        return $data;
    }
}
