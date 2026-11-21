<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Cache;

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Clock\FrozenClock;
use Iprodev\EasyCache\Lock\NullLockProvider;
use Iprodev\EasyCache\Storage\StorageInterface;
use Iprodev\EasyCache\Tests\Support\ArrayStorage;
use PHPUnit\Framework\TestCase;

class MultiTierBehaviourTest extends TestCase
{
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
    }

    /**
     * @param list<StorageInterface> $tiers
     */
    private function cache(array $tiers): MultiTierCache
    {
        return new MultiTierCache($tiers, null, null, 3600, null, null, $this->clock, null, new NullLockProvider());
    }

    public function testWritesGoToAllTiers(): void
    {
        $l1 = new ArrayStorage($this->clock);
        $l2 = new ArrayStorage($this->clock);
        $this->cache([$l1, $l2])->set('k', 'v');

        $this->assertNotNull($l1->rawGet('k'));
        $this->assertSame($l1->rawGet('k'), $l2->rawGet('k'));
    }

    public function testBackfillsFasterTiers(): void
    {
        $l1 = new ArrayStorage($this->clock);
        $l2 = new ArrayStorage($this->clock);
        $this->cache([$l2])->set('k', 'v', 100);

        $this->assertSame('v', $this->cache([$l1, $l2])->get('k'));
        $this->assertSame($l2->rawGet('k'), $l1->rawGet('k'));
        $this->assertGreaterThan(0, $l1->data['k']['expires']);
    }

    public function testExpiredEntryInFastTierDoesNotHideFreshEntryBelow(): void
    {
        $l1 = new ArrayStorage($this->clock);
        $l2 = new ArrayStorage($this->clock);

        // Old copy left in L1, e.g. written on this server earlier.
        $this->cache([$l1])->getOrSetSWR('k', fn () => 'old', 10, 3600);
        $this->clock->advance(20);
        // Fresh copy written to the shared tier by another server.
        $this->cache([$l2])->set('k', 'fresh', 100);

        $cache = $this->cache([$l1, $l2]);
        $this->assertSame('fresh', $cache->get('k'));
        $this->assertSame('fresh', $cache->getOrSetSWR('k', fn () => 'computed', 10, 3600));
        $this->assertSame($l2->rawGet('k'), $l1->rawGet('k'), 'the fresh copy is backfilled');
    }

    public function testDeleteRemovesFromAllTiers(): void
    {
        $l1 = new ArrayStorage($this->clock);
        $l2 = new ArrayStorage($this->clock);
        $cache = $this->cache([$l1, $l2]);
        $cache->set('k', 'v');

        $this->assertTrue($cache->delete('k'));
        $this->assertNull($l1->rawGet('k'));
        $this->assertNull($l2->rawGet('k'));
    }

    public function testFailingTierIsSkipped(): void
    {
        $l1 = new ArrayStorage($this->clock);
        $l2 = new ArrayStorage($this->clock);
        $this->cache([$l2])->set('k', 'v');
        $l1->failing = true;

        $this->assertSame('v', $this->cache([$l1, $l2])->get('k'));
    }

    public function testGetMultipleReadsLowerTiersOnlyForMissingKeys(): void
    {
        $l1 = new ArrayStorage($this->clock);
        $l2 = new ArrayStorage($this->clock);
        $this->cache([$l1, $l2])->set('a', 1);
        $this->cache([$l2])->set('b', 2);

        $l2->calls = [];
        $result = $this->cache([$l1, $l2])->getMultiple(['a', 'b', 'c']);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => null], $result);
        $this->assertSame(1, $l2->calls['getMany'] ?? 0);
    }
}
