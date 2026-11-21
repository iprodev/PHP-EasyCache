<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Psr6;

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Clock\FrozenClock;
use Iprodev\EasyCache\Lock\NullLockProvider;
use Iprodev\EasyCache\Psr6\CacheItemPool;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Tests\Support\ArrayStorage;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\InvalidArgumentException;

class CacheItemPoolTest extends TestCase
{
    private FrozenClock $clock;
    private ArrayStorage $storage;
    private MultiTierCache $cache;
    private CacheItemPool $pool;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->storage = new ArrayStorage($this->clock);
        $this->cache = new MultiTierCache(
            [$this->storage],
            serializer: new NativeSerializer([\stdClass::class]),
            defaultTtl: 3600,
            clock: $this->clock,
            signingKey: str_repeat('k', 32),
            lockProvider: new NullLockProvider()
        );
        $this->pool = new CacheItemPool($this->cache, $this->clock);
    }

    public function testMissAndHit(): void
    {
        $item = $this->pool->getItem('key');
        $this->assertInstanceOf(CacheItemInterface::class, $item);
        $this->assertSame('key', $item->getKey());
        $this->assertFalse($item->isHit());
        $this->assertNull($item->get());

        $this->assertTrue($this->pool->save($item->set('value')));

        $item = $this->pool->getItem('key');
        $this->assertTrue($item->isHit());
        $this->assertSame('value', $item->get());
        $this->assertTrue($this->pool->hasItem('key'));
        $this->assertSame('value', $this->cache->get('key'), 'the pool shares the cache');
    }

    public function testNullAndObjectValuesAreHits(): void
    {
        $this->pool->save($this->pool->getItem('null')->set(null));
        $this->pool->save($this->pool->getItem('object')->set((object) ['a' => 1]));

        $this->assertTrue($this->pool->getItem('null')->isHit());
        $this->assertNull($this->pool->getItem('null')->get());
        $this->assertEquals((object) ['a' => 1], $this->pool->getItem('object')->get());
    }

    public function testExpiresAfter(): void
    {
        $this->pool->save($this->pool->getItem('k')->set('v')->expiresAfter(10));
        $this->clock->advance(9);
        $this->assertTrue($this->pool->getItem('k')->isHit());
        $this->clock->advance(1);
        $this->assertFalse($this->pool->getItem('k')->isHit());

        $this->pool->save($this->pool->getItem('interval')->set('v')->expiresAfter(new \DateInterval('PT1M')));
        $this->clock->advance(59);
        $this->assertTrue($this->pool->hasItem('interval'));
    }

    public function testExpiresAt(): void
    {
        $at = $this->clock->now()->modify('+30 seconds');
        $this->pool->save($this->pool->getItem('k')->set('v')->expiresAt($at));
        $this->clock->advance(29);
        $this->assertTrue($this->pool->hasItem('k'));
        $this->clock->advance(1);
        $this->assertFalse($this->pool->hasItem('k'));
    }

    public function testDefaultTtlWhenNoExpiryIsSet(): void
    {
        $this->pool->save($this->pool->getItem('k')->set('v')->expiresAfter(null));
        $this->clock->advance(3599);
        $this->assertTrue($this->pool->hasItem('k'));
        $this->clock->advance(1);
        $this->assertFalse($this->pool->hasItem('k'));
    }

    public function testSavingAnExpiredItemRemovesTheOldValue(): void
    {
        $this->pool->save($this->pool->getItem('k')->set('old'));
        $this->assertTrue($this->pool->save($this->pool->getItem('k')->set('new')->expiresAfter(-1)));
        $this->assertFalse($this->pool->hasItem('k'));
    }

    public function testDeferredSavesAreVisibleAndCommittedInBatches(): void
    {
        $this->pool->saveDeferred($this->pool->getItem('a')->set(1));
        $this->pool->saveDeferred($this->pool->getItem('b')->set(2)->expiresAfter(60));
        $this->pool->saveDeferred($this->pool->getItem('c')->set(3)->expiresAfter(60));

        $this->assertTrue($this->pool->getItem('a')->isHit(), 'deferred items are visible before commit');
        $this->assertTrue($this->pool->hasItem('b'));
        $this->assertFalse($this->cache->has('a'), 'not written before commit');

        $this->storage->calls = [];
        $this->assertTrue($this->pool->commit());
        $this->assertSame(2, $this->storage->calls['setMany'] ?? 0, 'one batch per expiry');

        $this->assertSame(1, $this->cache->get('a'));
        $this->assertSame(3, $this->cache->get('c'));
    }

    public function testDeferredItemsAreCommittedOnDestruct(): void
    {
        $pool = new CacheItemPool($this->cache, $this->clock);
        $pool->saveDeferred($pool->getItem('late')->set('v'));
        unset($pool);

        $this->assertSame('v', $this->cache->get('late'));
    }

    public function testGetItems(): void
    {
        $this->pool->save($this->pool->getItem('a')->set('A'));
        $this->pool->saveDeferred($this->pool->getItem('d')->set('D'));

        $items = $this->pool->getItems(['a', 'b', 'd']);

        $this->assertSame(['a', 'b', 'd'], array_keys($items));
        $this->assertSame('A', $items['a']->get());
        $this->assertFalse($items['b']->isHit());
        $this->assertSame('D', $items['d']->get());
        $this->assertSame([], $this->pool->getItems());
    }

    public function testDelete(): void
    {
        $this->pool->save($this->pool->getItem('a')->set(1));
        $this->pool->save($this->pool->getItem('b')->set(2));
        $this->pool->saveDeferred($this->pool->getItem('c')->set(3));

        $this->assertTrue($this->pool->deleteItem('a'));
        $this->assertTrue($this->pool->deleteItems(['b', 'c', 'missing']));
        $this->pool->commit();

        $this->assertFalse($this->pool->hasItem('a'));
        $this->assertFalse($this->pool->hasItem('b'));
        $this->assertFalse($this->pool->hasItem('c'));
    }

    public function testClear(): void
    {
        $this->pool->save($this->pool->getItem('a')->set(1));
        $this->pool->saveDeferred($this->pool->getItem('b')->set(2));

        $this->assertTrue($this->pool->clear());
        $this->pool->commit();

        $this->assertFalse($this->pool->hasItem('a'));
        $this->assertFalse($this->pool->hasItem('b'));
    }

    public function testInvalidKeysThrowThePsr6Exception(): void
    {
        foreach (['getItem', 'hasItem', 'deleteItem'] as $method) {
            try {
                $this->pool->{$method}('bad:key');
                $this->fail("{$method}() accepted an invalid key");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->pool->getItems(['ok', 'bad{key}']);
    }

    public function testForeignItemsAreRejected(): void
    {
        $foreign = $this->createStub(CacheItemInterface::class);
        $this->assertFalse($this->pool->save($foreign));
        $this->assertFalse($this->pool->saveDeferred($foreign));
    }
}
