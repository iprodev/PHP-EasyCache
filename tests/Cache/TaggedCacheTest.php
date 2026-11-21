<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Cache;

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Clock\FrozenClock;
use Iprodev\EasyCache\Exceptions\InvalidArgument;
use Iprodev\EasyCache\Lock\NullLockProvider;
use Iprodev\EasyCache\Tests\Support\ArrayStorage;
use PHPUnit\Framework\TestCase;

class TaggedCacheTest extends TestCase
{
    private MultiTierCache $cache;

    protected function setUp(): void
    {
        $clock = new FrozenClock();
        $this->cache = new MultiTierCache(
            [new ArrayStorage($clock)],
            clock: $clock,
            lockProvider: new NullLockProvider()
        );
    }

    public function testTaggedEntriesAreInvalidatedTogether(): void
    {
        $users = $this->cache->tags(['users']);
        $posts = $this->cache->tags('posts');

        $users->set('u1', 'alice');
        $users->set('u2', 'bob');
        $posts->set('p1', 'hello');

        $this->assertSame('alice', $users->get('u1'));
        $this->assertTrue($this->cache->invalidateTags('users'));

        $this->assertNull($users->get('u1'));
        $this->assertNull($users->get('u2'));
        $this->assertSame('hello', $posts->get('p1'));
    }

    public function testEntryWithSeveralTagsIsInvalidatedByAnyOfThem(): void
    {
        $this->cache->tags(['users', 'posts'])->set('feed', 'data');
        $this->assertSame('data', $this->cache->tags(['posts', 'users'])->get('feed'), 'tag order does not matter');

        $this->cache->invalidateTags(['posts']);
        $this->assertNull($this->cache->tags(['users', 'posts'])->get('feed'));
    }

    public function testClearOnTaggedViewInvalidatesItsTags(): void
    {
        $view = $this->cache->tags('reports');
        $view->set('r', 1);
        $this->cache->set('untagged', 2);

        $this->assertTrue($view->clear());
        $this->assertNull($view->get('r'));
        $this->assertSame(2, $this->cache->get('untagged'));
    }

    public function testTaggedViewSupportsMultipleAndRemember(): void
    {
        $view = $this->cache->tags('t');
        $this->assertTrue($view->setMultiple(['a' => 1, 'b' => 2]));
        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 'd'], $view->getMultiple(['a', 'b', 'c'], 'd'));
        $this->assertTrue($view->has('a'));
        $this->assertTrue($view->deleteMultiple(['a']));
        $this->assertFalse($view->has('a'));
        $this->assertSame('computed', $view->remember('r', 60, fn () => 'computed'));
        $this->assertSame('computed', $view->getOrSetSWR('r', fn () => 'other', 60));
        $this->assertSame(['t'], $view->getTags());
    }

    public function testCachedNullIsReturnedByGetMultiple(): void
    {
        $view = $this->cache->tags('t');
        $view->set('n', null);
        $this->assertSame(['n' => null, 'm' => 'd'], $view->getMultiple(['n', 'm'], 'd'));
    }

    public function testMultipleOperationsResolveTagVersionsOnce(): void
    {
        $clock = new FrozenClock();
        $storage = new ArrayStorage($clock);
        $cache = new MultiTierCache([$storage], clock: $clock, lockProvider: new NullLockProvider());
        $view = $cache->tags(['a', 'b']);
        $view->set('warm-up', 1); // creates the tag versions

        $storage->calls = [];
        $view->setMultiple(['k1' => 1, 'k2' => 2, 'k3' => 3]);
        $view->getMultiple(['k1', 'k2', 'k3']);

        // One version read per operation plus the data read itself.
        $this->assertSame(3, $storage->calls['getMany'] ?? 0);
        $this->assertSame(['k1' => 1, 'k2' => 2], $view->getMultiple(['k1', 'k2']));
    }

    public function testTagsAreValidated(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->cache->tags(['bad:tag']);
    }

    public function testEmptyTagListIsRejected(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->cache->tags([]);
    }
}
