<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Cache;

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Cache\RefreshMode;
use Iprodev\EasyCache\Clock\FrozenClock;
use Iprodev\EasyCache\Lock\FileLockProvider;
use Iprodev\EasyCache\Lock\LockInterface;
use Iprodev\EasyCache\Lock\LockProviderInterface;
use Iprodev\EasyCache\Tests\Support\ArrayStorage;
use Iprodev\EasyCache\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SwrTest extends TestCase
{
    use TempDirectory;

    private FrozenClock $clock;
    private ArrayStorage $storage;
    private MultiTierCache $cache;
    private int $calls = 0;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->storage = new ArrayStorage($this->clock);
        $this->cache = $this->makeCache(3600, new FileLockProvider($this->makeTempDir('easycache-locks')));
        $this->calls = 0;
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    private function makeCache(int $defaultTtl, LockProviderInterface $locks): MultiTierCache
    {
        return new MultiTierCache([$this->storage], defaultTtl: $defaultTtl, clock: $this->clock, lockProvider: $locks);
    }

    private function producer(): callable
    {
        return function (): string {
            return 'v' . (++$this->calls);
        };
    }

    private function failingProducer(): callable
    {
        return function (): never {
            $this->calls++;
            throw new RuntimeException('backend down');
        };
    }

    public function testMissComputesAndCaches(): void
    {
        $this->assertSame('v1', $this->cache->getOrSetSWR('k', $this->producer(), 10, 5, 10));
        $this->assertSame('v1', $this->cache->getOrSetSWR('k', $this->producer(), 10, 5, 10));
        $this->assertSame(1, $this->calls);
    }

    public function testSyncRevalidationReturnsTheFreshValue(): void
    {
        $this->cache->getOrSetSWR('k', $this->producer(), 10, 30);
        $this->clock->advance(15); // expired, inside the SWR window

        $this->assertSame('v2', $this->cache->getOrSetSWR('k', $this->producer(), 10, 30, 0, RefreshMode::Sync));
        $this->assertSame(2, $this->calls);
        $this->assertSame('v2', $this->cache->getOrSetSWR('k', $this->producer(), 10, 30));
        $this->assertSame(2, $this->calls);
    }

    public function testStaleValueIsServedWhileAnotherProcessRevalidates(): void
    {
        $busy = new class () implements LockProviderInterface {
            public function create(string $key): LockInterface
            {
                return new class () implements LockInterface {
                    public function acquire(bool $blocking = true, ?float $timeout = null): bool
                    {
                        return false;
                    }

                    public function release(): void
                    {
                    }
                };
            }
        };

        $this->cache->getOrSetSWR('k', $this->producer(), 10, 30);
        $this->clock->advance(15);

        $cache = $this->makeCache(3600, $busy);
        $this->assertSame('v1', $cache->getOrSetSWR('k', $this->producer(), 10, 30));
        $this->assertSame(1, $this->calls, 'the producer must not run while another process holds the lock');
    }

    public function testFailedRevalidationServesStaleValue(): void
    {
        $this->cache->getOrSetSWR('k', $this->producer(), 10, 30);
        $this->clock->advance(15);
        $this->assertSame('v1', $this->cache->getOrSetSWR('k', $this->failingProducer(), 10, 30));
    }

    public function testAfterSwrWindowTheValueIsRecomputed(): void
    {
        $this->cache->getOrSetSWR('k', $this->producer(), 10, 30);
        $this->clock->advance(41);
        $this->assertSame('v2', $this->cache->getOrSetSWR('k', $this->producer(), 10, 30));
    }

    public function testStaleIfErrorRecomputesInsteadOfServingStale(): void
    {
        $this->cache->getOrSetSWR('k', $this->producer(), 10, 0, 100);
        $this->clock->advance(20); // expired, inside the stale-if-error window only

        $this->assertSame('v2', $this->cache->getOrSetSWR('k', $this->producer(), 10, 0, 100));
        $this->assertSame(2, $this->calls);
    }

    public function testStaleIfErrorServesStaleWhenProducerFails(): void
    {
        $this->cache->getOrSetSWR('k', $this->producer(), 10, 0, 100);
        $this->clock->advance(20);

        $this->assertSame('v1', $this->cache->getOrSetSWR('k', $this->failingProducer(), 10, 0, 100));
        $this->assertSame(2, $this->calls);
        $this->assertSame(1, $this->cache->getStats()['stale_if_error']);
    }

    public function testErrorIsRethrownOutsideStaleIfErrorWindow(): void
    {
        $this->cache->getOrSetSWR('k', $this->producer(), 10, 0, 100);
        $this->clock->advance(200);

        $this->expectException(RuntimeException::class);
        $this->cache->getOrSetSWR('k', $this->failingProducer(), 10, 0, 100);
    }

    public function testErrorIsRethrownOnMiss(): void
    {
        $this->expectException(RuntimeException::class);
        $this->cache->getOrSetSWR('k', $this->failingProducer(), 10, 0, 100);
    }

    public function testNullResultsAreCached(): void
    {
        $producer = function () {
            $this->calls++;
            return null;
        };
        $this->assertNull($this->cache->getOrSetSWR('n', $producer, 10));
        $this->assertNull($this->cache->getOrSetSWR('n', $producer, 10));
        $this->assertSame(1, $this->calls);
    }

    public function testDefaultTtlZeroCachesForever(): void
    {
        $cache = $this->makeCache(0, new FileLockProvider($this->makeTempDir('easycache-locks')));
        $cache->getOrSetSWR('k', $this->producer());
        $this->clock->advance(86400 * 365);
        $this->assertSame('v1', $cache->getOrSetSWR('k', $this->producer()));
        $this->assertSame(1, $this->calls);
    }

    public function testNonPositiveTtlDoesNotCache(): void
    {
        $this->assertSame('v1', $this->cache->getOrSetSWR('k', $this->producer(), 0));
        $this->assertSame('v2', $this->cache->getOrSetSWR('k', $this->producer(), 0));
        $this->assertFalse($this->cache->has('k'));
    }

    public function testDeferModeFallsBackToSyncWithoutFastCgi(): void
    {
        if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
            $this->markTestSkipped('Only meaningful outside PHP-FPM/LiteSpeed');
        }
        $this->cache->getOrSetSWR('k', $this->producer(), 10, 30);
        $this->clock->advance(15);
        $this->assertSame('v2', $this->cache->getOrSetSWR('k', $this->producer(), 10, 30, 0, RefreshMode::Defer));
    }

    public function testUnavailableLockStillCachesTheResult(): void
    {
        $file = $this->makeTempDir('easycache-file');
        file_put_contents($file, 'x');
        $cache = $this->makeCache(3600, new FileLockProvider($file . '/locks')); // cannot be created

        $this->assertSame('v1', $cache->getOrSetSWR('k', $this->producer(), 10));
        $this->assertSame('v1', $cache->getOrSetSWR('k', $this->producer(), 10));
        $this->assertSame(1, $this->calls);
    }
}
