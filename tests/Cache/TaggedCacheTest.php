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
            null,
            null,
            3600,
            null,
            null,
            $clock,
            null,
            new NullLockProvider()
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
