<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Compression\NullCompressor;

final class MultiTierCacheFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ec-v3-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0770, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function cache(): MultiTierCache
    {
        return new MultiTierCache([ new FileStorage($this->dir) ], new NativeSerializer(), new NullCompressor(), 1);
    }

    public function testSetGet(): void
    {
        $c = $this->cache();
        $this->assertTrue($c->set('foo', 'bar', 5));
        $this->assertSame('bar', $c->get('foo'));
        $this->assertTrue($c->has('foo'));
    }

    public function testExpiry(): void
    {
        $c = $this->cache();
        $c->set('x', 123, 1);
        sleep(2);
        $this->assertFalse($c->has('x'));
        $this->assertNull($c->get('x'));
    }

    public function testSWR(): void
    {
        $c = $this->cache();
        $first = $c->getOrSetSWR('k', fn() => 'v1-'.time(), 1, 5, 10, ['mode' => 'sync']);
        $this->assertNotEmpty($first);
        sleep(2); // ensure expiry
        $stale = $c->getOrSetSWR('k', fn() => 'v2-'.time(), 1, 5, 10, ['mode' => 'sync']);
        $this->assertSame($first, $stale); // within SWR window
        $fresh = $c->getOrSetSWR('k', fn() => 'v3-'.time(), 1, 5, 10, ['mode' => 'sync']);
        $this->assertIsString($fresh);
    }
}
