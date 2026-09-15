<?php

declare(strict_types=1);

namespace Calliostro\Spotify\Tests\Unit;

use Calliostro\Spotify\SpotifyClient;
use Calliostro\Spotify\SpotifyClientFactory;
use PHPUnit\Framework\TestCase;

final class SpotifyClientFactoryTest extends TestCase
{
    public function testCreate(): void
    {
        $client = SpotifyClientFactory::create();

        $this->assertInstanceOf(SpotifyClient::class, $client);
        $this->assertNull($client->getAuthHelper());
    }

    public function testCreateWithCredentials(): void
    {
        $client = SpotifyClientFactory::createWithCredentials('test-client-id', 'test-client-secret');

        $this->assertInstanceOf(SpotifyClient::class, $client);
        $authHelper = $client->getAuthHelper();
        $this->assertNotNull($authHelper);
        $this->assertSame('test-client-id', $authHelper->getClientId());
        $this->assertSame('test-client-secret', $authHelper->getClientSecret());
    }

    public function testCreateWithAccessToken(): void
    {
        $client = SpotifyClientFactory::createWithAccessToken('test-access-token');

        $this->assertInstanceOf(SpotifyClient::class, $client);
        $authHelper = $client->getAuthHelper();
        $this->assertNotNull($authHelper);
        $this->assertSame('test-access-token', $authHelper->getAccessToken());
    }

    public function testCreateWithUserAuth(): void
    {
        $expiresAt = time() + 3600;
        $client = SpotifyClientFactory::createWithUserAuth(
            'test-client-id',
            'test-client-secret',
            'test-access-token',
            'test-refresh-token',
            $expiresAt
        );

        $this->assertInstanceOf(SpotifyClient::class, $client);
        $authHelper = $client->getAuthHelper();
        $this->assertNotNull($authHelper);
        $this->assertSame('test-client-id', $authHelper->getClientId());
        $this->assertSame('test-client-secret', $authHelper->getClientSecret());
        $this->assertSame('test-access-token', $authHelper->getAccessToken());
        $this->assertSame('test-refresh-token', $authHelper->getRefreshToken());
        $this->assertSame($expiresAt, $authHelper->getExpiresAt());
    }
}
