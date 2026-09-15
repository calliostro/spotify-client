<?php

declare(strict_types=1);

namespace Calliostro\Spotify\Tests\Unit;

use Calliostro\Spotify\AuthHelper;
use Calliostro\Spotify\Exception\AuthenticationException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class AuthHelperTest extends TestCase
{
    public function testGetAuthorizationUrl(): void
    {
        $authHelper = new AuthHelper('my-client-id', 'my-client-secret');
        $url = $authHelper->getAuthorizationUrl(
            'https://example.com/callback',
            ['user-read-private', 'user-read-email'],
            'random-state-123',
            true
        );

        $this->assertStringStartsWith('https://accounts.spotify.com/authorize?', $url);
        $this->assertStringContainsString('client_id=my-client-id', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('redirect_uri=' . urlencode('https://example.com/callback'), $url);
        $this->assertStringContainsString('scope=' . urlencode('user-read-private user-read-email'), $url);
        $this->assertStringContainsString('state=random-state-123', $url);
        $this->assertStringContainsString('show_dialog=true', $url);
    }

    public function testGetAuthorizationUrlThrowsWithoutClientId(): void
    {
        $authHelper = new AuthHelper();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Client ID is required');
        $authHelper->getAuthorizationUrl('https://example.com/callback');
    }

    public function testRequestAccessToken(): void
    {
        /** @var list<array{request: Request, response: Response}> $container */
        $container = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'mock-access-token',
                'token_type' => 'Bearer',
                'scope' => 'user-read-private',
                'expires_in' => 3600,
                'refresh_token' => 'mock-refresh-token',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new GuzzleClient(['handler' => $handlerStack]);

        $authHelper = new AuthHelper('client-id', 'client-secret', $client);
        $result = $authHelper->requestAccessToken('auth-code', 'https://example.com/callback');

        $this->assertSame('mock-access-token', $result['access_token']);
        $this->assertSame('mock-access-token', $authHelper->getAccessToken());
        $this->assertSame('mock-refresh-token', $authHelper->getRefreshToken());
        $this->assertNotNull($authHelper->getExpiresAt());
        $this->assertGreaterThan(time(), $authHelper->getExpiresAt());
    }

    public function testRequestCredentialsToken(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'credentials-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new GuzzleClient(['handler' => $handlerStack]);

        $authHelper = new AuthHelper('client-id', 'client-secret', $client);
        $result = $authHelper->requestCredentialsToken();

        $this->assertSame('credentials-access-token', $result['access_token']);
        $this->assertSame('credentials-access-token', $authHelper->getAccessToken());
        $this->assertNotNull($authHelper->getExpiresAt());
    }

    public function testRefreshAccessToken(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'new-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'refresh_token' => 'new-refresh-token',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new GuzzleClient(['handler' => $handlerStack]);

        $authHelper = new AuthHelper('client-id', 'client-secret', $client);
        $result = $authHelper->refreshAccessToken('old-refresh-token');

        $this->assertSame('new-access-token', $result['access_token']);
        $this->assertSame('new-access-token', $authHelper->getAccessToken());
        $this->assertSame('new-refresh-token', $authHelper->getRefreshToken());
    }

    public function testIsExpired(): void
    {
        $authHelper = new AuthHelper();
        $this->assertTrue($authHelper->isExpired(), 'Null token should be considered expired');

        $authHelper->setAccessToken('some-token', null);
        $this->assertFalse($authHelper->isExpired(), 'Token without expiration timestamp should not be expired');

        // Expiring in 200 seconds (margin is 300s -> should be considered expired proactively)
        $authHelper->setExpiresAt(time() + 200);
        $this->assertTrue($authHelper->isExpired(300));

        // Expiring in 400 seconds (margin is 300s -> not expired yet)
        $authHelper->setExpiresAt(time() + 400);
        $this->assertFalse($authHelper->isExpired(300));
    }

    public function testGetValidTokenReturnsCurrentIfActive(): void
    {
        $authHelper = new AuthHelper();
        $authHelper->setAccessToken('current-valid-token', time() + 1000);

        $this->assertSame('current-valid-token', $authHelper->getValidToken());
    }

    public function testGetValidTokenProactivelyRefreshesWithRefreshToken(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'refreshed-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new GuzzleClient(['handler' => $handlerStack]);

        $authHelper = new AuthHelper('client-id', 'client-secret', $client);
        $authHelper->setAccessToken('expiring-token', time() + 100); // within 300s margin
        $authHelper->setRefreshToken('my-refresh-token');

        $token = $authHelper->getValidToken();
        $this->assertSame('refreshed-token', $token);
    }

    public function testGetValidTokenProactivelyRefreshesWithCredentials(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'credentials-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new GuzzleClient(['handler' => $handlerStack]);

        $authHelper = new AuthHelper('client-id', 'client-secret', $client);
        $authHelper->setAccessToken('expiring-token', time() + 100); // within 300s margin

        $token = $authHelper->getValidToken();
        $this->assertSame('credentials-token', $token);
    }

    public function testGetValidTokenThrowsWhenNoCredentialsAvailable(): void
    {
        $authHelper = new AuthHelper();
        $authHelper->setExpiresAt(time() - 10);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('No valid access token available');
        $authHelper->getValidToken();
    }

    public function testForceRefreshThrowsWhenNoCredentialsAvailable(): void
    {
        $authHelper = new AuthHelper();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Cannot refresh token');
        $authHelper->forceRefresh();
    }

    public function testSendTokenRequestThrowsOnApiError(): void
    {
        $mock = new MockHandler([
            new Response(400, [], json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'Invalid authorization code',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new GuzzleClient(['handler' => $handlerStack]);

        $authHelper = new AuthHelper('client-id', 'client-secret', $client);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Authentication request failed');
        $authHelper->requestAccessToken('bad-code', 'https://example.com');
    }

    public function testSendTokenRequestThrowsOnInvalidJson(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'not-valid-json'),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new GuzzleClient(['handler' => $handlerStack]);

        $authHelper = new AuthHelper('client-id', 'client-secret', $client);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Failed to parse authentication response');
        $authHelper->requestCredentialsToken();
    }

    public function testGetValidTokenReturnsTokenWhenExpiredWithoutCredentialsOrRefresh(): void
    {
        $authHelper = new AuthHelper();
        $authHelper->setAccessToken('fallback-token');
        $authHelper->setExpiresAt(time() - 600);

        $token = $authHelper->getValidToken();
        $this->assertSame('fallback-token', $token);
    }

    public function testForceRefreshWithClientCredentials(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'refreshed-credentials-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new GuzzleClient(['handler' => $handlerStack]);

        $authHelper = new AuthHelper('client-id', 'client-secret', $client);
        $token = $authHelper->forceRefresh();

        $this->assertSame('refreshed-credentials-token', $token);
        $this->assertSame('refreshed-credentials-token', $authHelper->getAccessToken());
    }

    public function testSendTokenRequestThrowsWithoutCredentials(): void
    {
        $authHelper = new AuthHelper();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Client ID and Client Secret are required for token requests');
        $authHelper->requestAccessToken('auth-code', 'https://example.com/callback');
    }

    public function testSendTokenRequestThrowsOnSpotifyAuthErrorInSuccessfulResponse(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'error' => 'invalid_client',
                'error_description' => 'Client authentication failed',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'error' => 'simple_error',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new GuzzleClient(['handler' => $handlerStack]);

        $authHelper = new AuthHelper('client-id', 'client-secret', $client);

        try {
            $authHelper->requestCredentialsToken();
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame('Spotify auth error: Client authentication failed', $e->getMessage());
        }

        try {
            $authHelper->requestCredentialsToken();
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame('Spotify auth error: simple_error', $e->getMessage());
        }
    }

    public function testSendTokenRequestThrowsWhenAccessTokenMissing(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new GuzzleClient(['handler' => $handlerStack]);

        $authHelper = new AuthHelper('client-id', 'client-secret', $client);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid token response: access_token missing.');
        $authHelper->requestCredentialsToken();
    }

    public function testForceRefreshWithRefreshToken(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'refreshed-user-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new GuzzleClient(['handler' => $handlerStack]);

        $authHelper = new AuthHelper('client-id', 'client-secret', $client);
        $authHelper->setRefreshToken('my-refresh-token');
        $token = $authHelper->forceRefresh();

        $this->assertSame('refreshed-user-token', $token);
        $this->assertSame('refreshed-user-token', $authHelper->getAccessToken());
    }

    public function testGetClientCredentials(): void
    {
        $authHelper = new AuthHelper('custom-id', 'custom-secret');
        $this->assertSame('custom-id', $authHelper->getClientId());
        $this->assertSame('custom-secret', $authHelper->getClientSecret());
    }
}
