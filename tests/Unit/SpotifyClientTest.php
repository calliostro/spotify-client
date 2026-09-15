<?php

declare(strict_types=1);

namespace Calliostro\Spotify\Tests\Unit;

use Calliostro\Spotify\AuthHelper;
use Calliostro\Spotify\Exception\AuthenticationException;
use Calliostro\Spotify\Exception\NotFoundException;
use Calliostro\Spotify\Exception\RateLimitException;
use Calliostro\Spotify\Exception\SpotifyException;
use Calliostro\Spotify\Exception\ValidationException;
use Calliostro\Spotify\SpotifyClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class SpotifyClientTest extends TestCase
{
    private MockHandler $mockHandler;
    private SpotifyClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $this->client = new SpotifyClient(new GuzzleClient(['handler' => $handlerStack]));
    }

    public function testGet(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['id' => '123', 'name' => 'Test Item'], JSON_THROW_ON_ERROR))
        );

        $this->client->setAccessToken('test-token');
        $result = $this->client->get('/v1/items/123', ['filter' => 'active']);

        $this->assertSame(['id' => '123', 'name' => 'Test Item'], $result);

        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('items/123?filter=active', (string) $request->getUri());
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
    }

    public function testPost(): void
    {
        $this->mockHandler->append(
            new Response(201, [], json_encode(['snapshot_id' => 'snap123'], JSON_THROW_ON_ERROR))
        );

        $this->client->setAccessToken('test-token');
        $result = $this->client->post('/playlists/123/tracks', ['uris' => ['spotify:track:abc']]);

        $this->assertSame(['snapshot_id' => 'snap123'], $result);

        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('playlists/123/tracks', (string) $request->getUri());
        $this->assertStringContainsString('spotify:track:abc', (string) $request->getBody());
    }

    public function testPut(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['status' => 'updated'], JSON_THROW_ON_ERROR))
        );

        $result = $this->client->put('/me/player/play', ['uris' => ['spotify:track:xyz']]);

        $this->assertSame(['status' => 'updated'], $result);

        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('me/player/play', (string) $request->getUri());
    }

    public function testDelete(): void
    {
        $this->mockHandler->append(
            new Response(204, [])
        );

        $result = $this->client->delete('/me/player/repeat', ['state' => 'off']);

        $this->assertSame([], $result);

        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame('me/player/repeat?state=off', (string) $request->getUri());
    }

    public function testEndpointNormalization(): void
    {
        $endpoints = [
            'https://api.spotify.com/v1/search',
            '/v1/search',
            '/search',
            'search',
        ];

        foreach ($endpoints as $endpoint) {
            $this->mockHandler->append(new Response(200, [], '{}'));
            $this->client->get($endpoint);

            $request = $this->mockHandler->getLastRequest();
            $this->assertNotNull($request);
            $this->assertSame('search', (string) $request->getUri());
        }
    }

    public function testSearch(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['artists' => ['items' => []]], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['tracks' => ['items' => []]], JSON_THROW_ON_ERROR))
        );

        // String type
        $this->client->search('Radiohead', 'artist', limit: 10, offset: 5, market: 'DE', includeExternal: 'audio');
        $req1 = $this->mockHandler->getLastRequest();
        $this->assertNotNull($req1);
        $req1Uri = (string) $req1->getUri();
        $this->assertStringContainsString('q=Radiohead', $req1Uri);
        $this->assertStringContainsString('type=artist', $req1Uri);
        $this->assertStringContainsString('limit=10', $req1Uri);
        $this->assertStringContainsString('offset=5', $req1Uri);
        $this->assertStringContainsString('market=DE', $req1Uri);
        $this->assertStringContainsString('include_external=audio', $req1Uri);

        // Array type
        $this->client->search('Creep', ['track', 'album']);
        $req2 = $this->mockHandler->getLastRequest();
        $this->assertNotNull($req2);
        $req2Uri = (string) $req2->getUri();
        $this->assertStringContainsString('q=Creep', $req2Uri);
        $this->assertStringContainsString('type=' . urlencode('track,album'), $req2Uri);
    }

    public function testGetArtist(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['id' => 'art123', 'name' => 'Radiohead'], JSON_THROW_ON_ERROR))
        );

        $result = $this->client->getArtist('art123');

        $this->assertSame('Radiohead', $result['name']);
        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('artists/art123', (string) $request->getUri());
    }

    public function testGetArtistAlbums(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['items' => []], JSON_THROW_ON_ERROR))
        );

        $this->client->getArtistAlbums('art123', ['album', 'single'], 'US', 15, 5);

        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $uri = (string) $request->getUri();
        $this->assertStringStartsWith('artists/art123/albums?', $uri);
        $this->assertStringContainsString('include_groups=' . urlencode('album,single'), $uri);
        $this->assertStringContainsString('market=US', $uri);
        $this->assertStringContainsString('limit=15', $uri);
        $this->assertStringContainsString('offset=5', $uri);
    }

    public function testGetArtistTopTracks(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['tracks' => []], JSON_THROW_ON_ERROR))
        );

        $this->client->getArtistTopTracks('art123', 'GB');

        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('artists/art123/top-tracks?market=GB', (string) $request->getUri());
    }

    public function testGetArtistRelatedArtists(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['artists' => []], JSON_THROW_ON_ERROR))
        );

        $this->client->getArtistRelatedArtists('art123');

        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('artists/art123/related-artists', (string) $request->getUri());
    }

    public function testGetAlbum(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['id' => 'alb123', 'name' => 'OK Computer'], JSON_THROW_ON_ERROR))
        );

        $result = $this->client->getAlbum('alb123', 'DE');

        $this->assertSame('OK Computer', $result['name']);
        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('albums/alb123?market=DE', (string) $request->getUri());
    }

    public function testGetAlbumTracks(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['items' => []], JSON_THROW_ON_ERROR))
        );

        $this->client->getAlbumTracks('alb123', 'US', 10, 2);

        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $uri = (string) $request->getUri();
        $this->assertStringStartsWith('albums/alb123/tracks?', $uri);
        $this->assertStringContainsString('market=US', $uri);
        $this->assertStringContainsString('limit=10', $uri);
        $this->assertStringContainsString('offset=2', $uri);
    }

    public function testGetTrack(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['id' => 'trk123', 'name' => 'Paranoid Android'], JSON_THROW_ON_ERROR))
        );

        $result = $this->client->getTrack('trk123');

        $this->assertSame('Paranoid Android', $result['name']);
        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('tracks/trk123', (string) $request->getUri());
    }

    public function testGetTracks(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['tracks' => []], JSON_THROW_ON_ERROR))
        );

        $this->client->getTracks(['trk1', 'trk2'], 'US');

        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $uri = (string) $request->getUri();
        $this->assertSame('tracks?ids=' . urlencode('trk1,trk2') . '&market=US', $uri);
    }

    public function testGetCurrentUser(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['id' => 'user123'], JSON_THROW_ON_ERROR))
        );

        $result = $this->client->getCurrentUser();

        $this->assertSame('user123', $result['id']);
        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('me', (string) $request->getUri());
    }

    public function testGetUserProfile(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['id' => 'user_abc', 'display_name' => 'Alice'], JSON_THROW_ON_ERROR))
        );

        $result = $this->client->getUserProfile('user_abc');

        $this->assertSame('Alice', $result['display_name']);
        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('users/user_abc', (string) $request->getUri());
    }

    public function testAutomatic401RetryWithRefresh(): void
    {
        $this->mockHandler->append(
            // First call fails with 401
            new Response(401, [], json_encode(['error' => ['message' => 'The access token expired']], JSON_THROW_ON_ERROR)),
            // Retry succeeds with 200
            new Response(200, [], json_encode(['id' => 'success-item'], JSON_THROW_ON_ERROR))
        );

        // Auth client mock that responds to refresh
        $authMock = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'brand-new-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR)),
        ]);
        $authGuzzle = new GuzzleClient(['handler' => HandlerStack::create($authMock)]);

        $authHelper = new AuthHelper('client-id', 'client-secret', $authGuzzle);
        $authHelper->setAccessToken('stale-token');
        $authHelper->setRefreshToken('my-refresh-token');

        $this->client->setAuthHelper($authHelper);
        $result = $this->client->get('/items/123');

        $this->assertSame(['id' => 'success-item'], $result);
        $lastRequest = $this->mockHandler->getLastRequest();
        $this->assertNotNull($lastRequest);
        $this->assertSame('Bearer brand-new-token', $lastRequest->getHeaderLine('Authorization'));
    }

    public function test401RetryFailsThrowsAuthenticationException(): void
    {
        $this->mockHandler->append(
            new Response(401, [], json_encode(['error' => ['message' => 'The access token expired']], JSON_THROW_ON_ERROR)),
            new Response(401, [], json_encode(['error' => ['message' => 'Still unauthorized']], JSON_THROW_ON_ERROR))
        );

        $authMock = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'brand-new-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR)),
        ]);
        $authGuzzle = new GuzzleClient(['handler' => HandlerStack::create($authMock)]);

        $authHelper = new AuthHelper('client-id', 'client-secret', $authGuzzle);
        $authHelper->setAccessToken('stale-token');
        $authHelper->setRefreshToken('my-refresh-token');

        $this->client->setAuthHelper($authHelper);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Still unauthorized');
        $this->client->get('/items/123');
    }

    public function testRateLimitRetryWithBackoff(): void
    {
        $mock = new MockHandler([
            // First attempt hits 429
            new Response(429, ['Retry-After' => '2'], json_encode(['error' => ['message' => 'Rate limited']], JSON_THROW_ON_ERROR)),
            // Second attempt succeeds
            new Response(200, [], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ]);

        $sleptSeconds = [];
        $client = new SpotifyClient(new GuzzleClient(['handler' => HandlerStack::create($mock)]), null, [
            'auto_retry' => true,
            'max_retries' => 3,
            'sleep_callback' => function (int $seconds) use (&$sleptSeconds): void {
                $sleptSeconds[] = $seconds;
            },
        ]);

        $result = $client->get('/test');
        $this->assertSame(['ok' => true], $result);
        $this->assertSame([2], $sleptSeconds);
    }

    public function testRateLimitExceededThrowsRateLimitException(): void
    {
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '3'], json_encode(['error' => ['message' => 'Rate limit 1']], JSON_THROW_ON_ERROR)),
            new Response(429, ['Retry-After' => '5'], json_encode(['error' => ['message' => 'Rate limit 2']], JSON_THROW_ON_ERROR)),
        ]);

        $sleptSeconds = [];
        $client = new SpotifyClient(new GuzzleClient(['handler' => HandlerStack::create($mock)]), null, [
            'auto_retry' => true,
            'max_retries' => 1,
            'sleep_callback' => function (int $seconds) use (&$sleptSeconds): void {
                $sleptSeconds[] = $seconds;
            },
        ]);

        try {
            $client->get('/test');
            $this->fail('Expected RateLimitException was not thrown');
        } catch (RateLimitException $e) {
            $this->assertSame(5, $e->getRetryAfter());
            $this->assertSame('Rate limit 2', $e->getMessage());
            $this->assertSame([3], $sleptSeconds);
        }
    }

    public function testRateLimitDisabledThrowsImmediately(): void
    {
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '7'], json_encode(['error' => ['message' => 'Too many requests']], JSON_THROW_ON_ERROR)),
        ]);

        $client = new SpotifyClient(new GuzzleClient(['handler' => HandlerStack::create($mock)]), null, [
            'auto_retry' => false,
        ]);

        try {
            $client->get('/test');
            $this->fail('Expected RateLimitException was not thrown');
        } catch (RateLimitException $e) {
            $this->assertSame(7, $e->getRetryAfter());
            $this->assertSame('Too many requests', $e->getMessage());
        }
    }

    public function testNotFoundThrowsNotFoundException(): void
    {
        $this->mockHandler->append(
            new Response(404, [], json_encode(['error' => ['message' => 'Artist not found']], JSON_THROW_ON_ERROR))
        );

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Artist not found');
        $this->client->getArtist('nonexistent');
    }

    public function testBadRequestThrowsValidationException(): void
    {
        $this->mockHandler->append(
            new Response(400, [], json_encode(['error' => ['message' => 'Invalid limit']], JSON_THROW_ON_ERROR))
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid limit');
        $this->client->search('query', 'artist');
    }

    public function testServerErrorThrowsSpotifyException(): void
    {
        $this->mockHandler->append(
            new Response(500, [], 'Internal Server Error')
        );

        $this->expectException(SpotifyException::class);
        $this->client->get('/status');
    }

    public function testAuthHelperAccessors(): void
    {
        $client = new SpotifyClient();
        $this->assertNull($client->getAuthHelper());

        $authHelper = new AuthHelper('id', 'secret');
        $client->setAuthHelper($authHelper);
        $this->assertSame($authHelper, $client->getAuthHelper());
    }

    public function testPostWithQueryParams(): void
    {
        $this->mockHandler->append(
            new Response(201, [], json_encode(['snapshot_id' => 'snap123'], JSON_THROW_ON_ERROR))
        );

        $this->client->setAccessToken('test-token');
        $result = $this->client->post('/playlists/123/tracks', ['uris' => ['spotify:track:abc']], ['position' => 0]);

        $this->assertSame(['snapshot_id' => 'snap123'], $result);
        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('playlists/123/tracks?position=0', (string) $request->getUri());
    }

    public function testPutWithQueryParams(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['snapshot_id' => 'snap456'], JSON_THROW_ON_ERROR))
        );

        $this->client->setAccessToken('test-token');
        $result = $this->client->put('/playlists/123/tracks', ['range_start' => 1], ['snapshot_id' => 'snap1']);

        $this->assertSame(['snapshot_id' => 'snap456'], $result);
        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('playlists/123/tracks?snapshot_id=snap1', (string) $request->getUri());
    }

    public function test401RetryWhenForceRefreshThrowsAuthenticationException(): void
    {
        $this->mockHandler->append(
            new Response(401, [], json_encode(['error' => ['message' => 'The access token expired']], JSON_THROW_ON_ERROR))
        );

        $authHelper = new AuthHelper();
        $authHelper->setAccessToken('stale-token');
        $this->client->setAuthHelper($authHelper);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('The access token expired');
        $this->client->get('/items/123');
    }

    public function testRequestThrowsSpotifyExceptionOnConnectException(): void
    {
        $this->mockHandler->append(
            new ConnectException('Connection timed out', new Request('GET', 'items/123'))
        );

        $this->expectException(SpotifyException::class);
        $this->expectExceptionMessage('HTTP request failed: Connection timed out');
        $this->client->get('/items/123');
    }

    public function testGetTrackWithMarket(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['id' => '123', 'name' => 'Karma Police'], JSON_THROW_ON_ERROR))
        );

        $result = $this->client->getTrack('123', 'US');
        $this->assertSame(['id' => '123', 'name' => 'Karma Police'], $result);

        $request = $this->mockHandler->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('tracks/123?market=US', (string) $request->getUri());
    }

    public function testGetAccessTokenWhenProactiveRefreshDisabled(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['id' => '123'], JSON_THROW_ON_ERROR)),
        ]);
        $client = new SpotifyClient(new GuzzleClient(['handler' => HandlerStack::create($mock)]), null, [
            'proactive_refresh' => false,
        ]);
        $authHelper = new AuthHelper();
        $authHelper->setAccessToken('manual-token');
        $client->setAuthHelper($authHelper);

        $result = $client->get('/items/123');
        $this->assertSame(['id' => '123'], $result);
        $request = $mock->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('Bearer manual-token', $request->getHeaderLine('Authorization'));
    }

    public function testParseResponseThrowsOnInvalidJson(): void
    {
        $this->mockHandler->append(
            new Response(200, [], 'invalid-json-content')
        );

        $this->expectException(SpotifyException::class);
        $this->expectExceptionMessage('Failed to parse response JSON');
        $this->client->get('/items/123');
    }

    public function testErrorHandlingWithEmptyResponseBody(): void
    {
        $this->mockHandler->append(
            new Response(400, [], '')
        );

        $this->expectException(ValidationException::class);
        $this->client->get('/bad');
    }

    public function testErrorHandlingWithErrorDescription(): void
    {
        $this->mockHandler->append(
            new Response(400, [], json_encode(['error_description' => 'Detailed validation error'], JSON_THROW_ON_ERROR))
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Detailed validation error');
        $this->client->get('/bad');
    }

    public function testErrorHandlingWithStringError(): void
    {
        $this->mockHandler->append(
            new Response(400, [], json_encode(['error' => 'Generic string error'], JSON_THROW_ON_ERROR))
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Generic string error');
        $this->client->get('/bad');
    }

    public function testRateLimitWithoutRetryAfterHeader(): void
    {
        $mock = new MockHandler([
            new Response(429, [], json_encode(['error' => ['message' => 'Rate limited']], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ]);

        $sleptSeconds = [];
        $client = new SpotifyClient(new GuzzleClient(['handler' => HandlerStack::create($mock)]), null, [
            'auto_retry' => true,
            'sleep_callback' => function (int $seconds) use (&$sleptSeconds): void {
                $sleptSeconds[] = $seconds;
            },
        ]);

        $result = $client->get('/test');
        $this->assertSame(['ok' => true], $result);
        $this->assertSame([1], $sleptSeconds);
    }

    public function testRateLimitSleepFallback(): void
    {
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '1'], json_encode(['error' => ['message' => 'Rate limited']], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ]);

        $client = new SpotifyClient(new GuzzleClient(['handler' => HandlerStack::create($mock)]), null, [
            'auto_retry' => true,
            'max_retries' => 1,
        ]);

        $result = $client->get('/test');
        $this->assertSame(['ok' => true], $result);
    }
}
