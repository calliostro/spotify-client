<?php

declare(strict_types=1);

namespace Calliostro\Spotify\Tests\Integration;

use Calliostro\Spotify\SpotifyClient;
use Calliostro\Spotify\SpotifyClientFactory;
use PHPUnit\Framework\TestCase;

final class SpotifyIntegrationTest extends TestCase
{
    private ?SpotifyClient $client = null;

    protected function setUp(): void
    {
        parent::setUp();

        $clientId = getenv('SPOTIFY_CLIENT_ID');
        $clientSecret = getenv('SPOTIFY_CLIENT_SECRET');

        if (empty($clientId) || empty($clientSecret)) {
            $this->markTestSkipped('SPOTIFY_CLIENT_ID and SPOTIFY_CLIENT_SECRET must be set to run integration tests.');
        }

        $this->client = SpotifyClientFactory::createWithCredentials((string) $clientId, (string) $clientSecret);
    }

    public function testSearchAndGetArtistCatalogData(): void
    {
        $this->assertNotNull($this->client);

        // 1. Search for artist
        $searchResults = $this->client->search('Radiohead', 'artist', limit: 1);
        $this->assertArrayHasKey('artists', $searchResults);
        $this->assertNotEmpty($searchResults['artists']['items']);

        $artistId = (string) $searchResults['artists']['items'][0]['id'];
        $this->assertNotEmpty($artistId);

        // 2. Get artist by ID
        $artist = $this->client->getArtist($artistId);
        $this->assertSame($artistId, $artist['id']);
        $this->assertArrayHasKey('name', $artist);

        // 3. Get artist albums
        $albums = $this->client->getArtistAlbums($artistId, ['album'], limit: 5);
        $this->assertArrayHasKey('items', $albums);

        if (!empty($albums['items'])) {
            $albumId = (string) $albums['items'][0]['id'];

            // 4. Get album details
            $album = $this->client->getAlbum($albumId);
            $this->assertSame($albumId, $album['id']);

            // 5. Get album tracks
            $tracks = $this->client->getAlbumTracks($albumId, limit: 5);
            $this->assertArrayHasKey('items', $tracks);
        }
    }
}
