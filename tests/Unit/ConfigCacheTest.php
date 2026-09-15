<?php

declare(strict_types=1);

namespace Calliostro\Spotify\Tests\Unit;

use Calliostro\Spotify\ConfigCache;
use PHPUnit\Framework\TestCase;

final class ConfigCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        ConfigCache::clear();
        parent::tearDown();
    }

    public function testGetReturnsConfigArray(): void
    {
        $config = ConfigCache::get();

        $this->assertArrayHasKey('baseUrl', $config);
        $this->assertArrayHasKey('accountUrl', $config);
        $this->assertArrayHasKey('client', $config);
        $this->assertSame('https://api.spotify.com/v1/', $config['baseUrl']);
        $this->assertSame('https://accounts.spotify.com/', $config['accountUrl']);
    }

    public function testGetReturnsSameInstanceCached(): void
    {
        $config1 = ConfigCache::get();
        $config2 = ConfigCache::get();

        $this->assertSame($config1, $config2);
    }

    public function testClearResetsConfig(): void
    {
        $config1 = ConfigCache::get();
        ConfigCache::clear();
        $config2 = ConfigCache::get();

        $this->assertEquals($config1, $config2);
    }
}
