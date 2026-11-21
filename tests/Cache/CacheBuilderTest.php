<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Cache;

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Clock\FrozenClock;
use Iprodev\EasyCache\Compression\GzipCompressor;
use Iprodev\EasyCache\Lock\NullLockProvider;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Tests\Support\ArrayStorage;
use Iprodev\EasyCache\Tests\Support\MemoryLogger;
use Iprodev\EasyCache\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

class CacheBuilderTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    public function testBuildsAWorkingCache(): void
    {
        $clock = new FrozenClock();
        $l1 = new ArrayStorage($clock);
        $l2 = new ArrayStorage($clock);

        $cache = MultiTierCache::builder()
            ->tier($l1)
            ->tier($l2)
            ->serializer(new NativeSerializer([\ArrayObject::class]))
            ->compressor(new GzipCompressor())
            ->signingKey(str_repeat('s', 32))
            ->defaultTtl(10)
            ->clock($clock)
            ->logger(new MemoryLogger())
            ->lockProvider(new NullLockProvider())
            ->lockTimeout(1.0)
            ->build();

        $cache->set('k', new \ArrayObject([1]));
        $this->assertEquals(new \ArrayObject([1]), $cache->get('k'));
        $this->assertNotNull($l1->rawGet('k'));
        $this->assertNotNull($l2->rawGet('k'));

        $clock->advance(10);
        $this->assertNull($cache->get('k'), 'defaultTtl is applied');
    }

    public function testTiersAndLockPath(): void
    {
        $clock = new FrozenClock();
        $lockDir = $this->makeTempDir('easycache-builder-locks');

        $cache = MultiTierCache::builder()
            ->tiers(new ArrayStorage($clock), new ArrayStorage($clock))
            ->lockPath($lockDir)
            ->build();

        $this->assertSame('computed', $cache->remember('k', 60, fn () => 'computed'));
        $this->assertDirectoryExists($lockDir);
    }

    public function testRequiresATier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MultiTierCache::builder()->build();
    }

    public function testAppliesTheSigningRequirement(): void
    {
        $builder = MultiTierCache::builder()
            ->tier(new ArrayStorage(new FrozenClock()))
            ->serializer(new NativeSerializer(true));

        try {
            $builder->build();
            $this->fail('An object-capable serializer without signing key must be refused');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertInstanceOf(MultiTierCache::class, $builder->allowUnsignedObjects()->build());
    }
}
