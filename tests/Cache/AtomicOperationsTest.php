<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Cache;

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Clock\FrozenClock;
use Iprodev\EasyCache\Lock\FileLockProvider;
use Iprodev\EasyCache\Lock\LockInterface;
use Iprodev\EasyCache\Lock\LockProviderInterface;
use Iprodev\EasyCache\Tests\Support\ArrayStorage;
use Iprodev\EasyCache\Tests\Support\MemoryLogger;
use Iprodev\EasyCache\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

class AtomicOperationsTest extends TestCase
{
    use TempDirectory;

    private FrozenClock $clock;
    private ArrayStorage $storage;
    private MultiTierCache $cache;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->storage = new ArrayStorage($this->clock);
        $this->cache = new MultiTierCache(
            [$this->storage],
            defaultTtl: 100,
            clock: $this->clock,
            lockProvider: new FileLockProvider($this->makeTempDir('easycache-atomic-locks'))
        );
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    public function testIncrementCreatesAndUpdatesCounters(): void
    {
        $this->assertSame(1, $this->cache->increment('hits'));
        $this->assertSame(6, $this->cache->increment('hits', 5));
        $this->assertSame(4, $this->cache->decrement('hits', 2));
        $this->assertSame(3, $this->cache->decrement('hits'));
        $this->assertSame(3, $this->cache->get('hits'));
        $this->assertSame(-2, $this->cache->decrement('new-counter', 2));
    }

    public function testNewCounterUsesTtlAndExistingCounterKeepsItsExpiry(): void
    {
        $this->cache->increment('window', 1, 10);
        $this->clock->advance(8);
        $this->cache->increment('window', 1, 10); // must not extend the window
        $this->clock->advance(2);
        $this->assertNull($this->cache->get('window'));

        $this->cache->increment('default');
        $this->clock->advance(99);
        $this->assertSame(1, $this->cache->get('default'));
        $this->clock->advance(1);
        $this->assertNull($this->cache->get('default'), 'null TTL uses the default TTL');

        $this->cache->increment('forever', 1, 0);
        $this->clock->advance(10 * 365 * 86400);
        $this->assertSame(1, $this->cache->get('forever'), 'TTL <= 0 means no expiry');
    }

    public function testIncrementAcceptsIntegerStrings(): void
    {
        $this->cache->set('n', '41');
        $this->assertSame(42, $this->cache->increment('n'));
    }

    public function testIncrementRefusesNonIntegers(): void
    {
        $logger = new MemoryLogger();
        $cache = new MultiTierCache([$this->storage], logger: $logger, clock: $this->clock);
        $cache->set('name', 'alice');

        $this->assertFalse($cache->increment('name'));
        $this->assertSame('alice', $cache->get('name'));
        $this->assertTrue($logger->hasMessageContaining('not an integer'));
    }

    public function testAddOnlyStoresMissingKeys(): void
    {
        $this->assertTrue($this->cache->add('k', 'first'));
        $this->assertFalse($this->cache->add('k', 'second'));
        $this->assertSame('first', $this->cache->get('k'));

        $this->cache->set('expiring', 'old', 5);
        $this->clock->advance(5);
        $this->assertTrue($this->cache->add('expiring', 'new'), 'expired entries can be replaced');
        $this->assertSame('new', $this->cache->get('expiring'));
    }

    public function testAddWithoutExpiry(): void
    {
        $this->assertTrue($this->cache->add('lock-owner', 'abc', 0));
        $this->clock->advance(86400 * 365);
        $this->assertSame('abc', $this->cache->get('lock-owner'));
    }

    public function testOperationsFailWhenTheLockIsUnavailable(): void
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
        $cache = new MultiTierCache([$this->storage], clock: $this->clock, lockProvider: $busy);

        $this->assertFalse($cache->increment('k'));
        $this->assertFalse($cache->add('k', 'v'));
        $this->assertFalse($cache->has('k'));
    }

    public function testTaggedCounters(): void
    {
        $view = $this->cache->tags('limits');
        $this->assertSame(1, $view->increment('api'));
        $this->assertSame(2, $view->increment('api'));
        $this->assertSame(1, $view->decrement('api'));
        $this->assertTrue($view->add('once', true));
        $this->assertFalse($view->add('once', true));

        $this->cache->invalidateTags('limits');
        $this->assertSame(1, $view->increment('api'));
    }
}
